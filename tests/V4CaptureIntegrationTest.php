<?php
declare(strict_types=1);

namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use WordPressR2Backup\AgeProcess;
use WordPressR2Backup\Manifest;
use WordPressR2Backup\PrivacyPolicy;
use WordPressR2Backup\SourceRoots;
use WordPressR2Backup\V4Capture;
use WordPressR2Backup\V4Format;
use WordPressR2Backup\V4Pack;
use WordPressR2Backup\WordPressDatabase;
use WordPressR2Backup\LocalRepository;
use WordPressR2Backup\Restorer;

require_once __DIR__ . '/WordPressDatabaseIntegrationTest.php';

/** @group integration */
final class V4CaptureIntegrationTest extends TestCase
{
    private ?IsolatedMysql $server = null;
    private array $connections = [];

    protected function setUp(): void
    {
        if (getenv('WRB_RUN_V4_MYSQL_INTEGRATION') !== '1') { self::markTestSkipped('Explicit isolated v4 MySQL integration authorization is required.'); }
        if (!extension_loaded('mysqli')) { self::markTestSkipped('mysqli is required.'); }
        $this->server = IsolatedMysql::start(null, 'DISABLED');
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $connection) { $connection->close(); }
        if ($this->server !== null) { $this->server->stop(); }
        Fixture::cleanup();
    }

    public function testFileProcessingKeepsTheLockedConnectionWhenWpdbWouldOtherwiseReconnect(): void
    {
        $schema = $this->server->newSchema(); $source = $this->adapter($schema);
        $source->executeBatch('CREATE TABLE idle_record(id INT PRIMARY KEY); INSERT INTO idle_record VALUES(1);');
        $source->query('SET SESSION wait_timeout=35');
        $connection = $source->get_var('SELECT CONNECTION_ID()');
        $source->reconnect = function () use ($schema): \mysqli { return $this->adapter($schema)->dbh; };
        $root = Fixture::temp();
        for ($index = 0; $index < 100; ++$index) { Fixture::write($root, sprintf('file-%03d.bin', $index), 'synthetic-' . $index); }
        $keys = Fixture::keys(); $recipients = array_column($keys, 'public');
        $scope = ['accountId' => str_repeat('a', 32), 'bucket' => 'isolated-idle-capture', 'unitId' => str_repeat('c', 64), 'keySetId' => Manifest::keySetId($recipients), 'encoding' => V4Format::ENCODING];
        $capture = new V4Capture(new SourceRoots($root), $source, new PrivacyPolicy(['version' => 2, 'preset' => 'full']), Fixture::temp());
        $result = $capture->capture(new AgeProcess(Fixture::runtime()['age']), $recipients, str_repeat('b', 64), $scope,
            static function (array $progress): void {
                if (($progress['phase'] ?? null) === 'capture' && ($progress['files'] ?? 0) > 0) { usleep(400000); }
            });
        self::assertSame(100, $result['manifest']['counts']['files']);
        self::assertSame($connection, $source->get_var('SELECT CONNECTION_ID()'));
        self::assertSame('35', $source->get_var('SELECT @@SESSION.wait_timeout'));
    }

    public function testFullCapturePacksRestoreAnIsolatedDatabaseAfterOriginalSchemaAndFilesAreRemoved(): void
    {
        $sourceSchema = $this->server->newSchema(); $source = $this->adapter($sourceSchema);
        $source->executeBatch("SET SESSION time_zone='+09:00'; SET SESSION sql_mode='NO_AUTO_VALUE_ON_ZERO';
CREATE TABLE parent_record(id BIGINT UNSIGNED PRIMARY KEY,label VARCHAR(40) CHARACTER SET latin1 COLLATE latin1_bin) ENGINE=InnoDB;
CREATE TABLE child_record(id INT PRIMARY KEY,parent_id BIGINT UNSIGNED NULL,payload LONGBLOB NULL,stamp TIMESTAMP(6) NULL,calculated BIGINT GENERATED ALWAYS AS (id+10) STORED,CONSTRAINT child_parent FOREIGN KEY(parent_id) REFERENCES parent_record(id)) ENGINE=InnoDB;
CREATE TABLE no_key(a VARCHAR(40) COLLATE utf8mb4_unicode_ci,b VARBINARY(40)) ENGINE=MyISAM;
INSERT INTO parent_record VALUES(1,CONVERT(X'636166e9' USING latin1)),(2,'');
INSERT INTO child_record(id,parent_id,payload,stamp) VALUES(1,1,X'0001ff275c','2020-01-02 03:04:05.123456'),(2,NULL,NULL,NULL);
INSERT INTO no_key VALUES('a',X'ff'),('A',X'00'),(NULL,X''),('a',X'ff');");
        $root = Fixture::temp(); $files = ['wp-config.php' => '<?php synthetic private configuration', '.env' => 'SYNTHETIC_PASSWORD=private', 'private.pdf' => "%PDF\0private", '.htaccess' => 'Deny from all', 'debug.log' => 'private debug log'];
        foreach ($files as $path => $bytes) { Fixture::write($root, $path, $bytes); }
        mkdir($root . '/empty', 0750);
        $keys = Fixture::keys(); $age = new AgeProcess(Fixture::runtime()['age']); $recipients = array_column($keys, 'public');
        $scope = ['accountId' => str_repeat('a', 32), 'bucket' => 'isolated-capture', 'unitId' => str_repeat('c', 64), 'keySetId' => Manifest::keySetId($recipients), 'encoding' => V4Format::ENCODING];
        $capture = new V4Capture(new SourceRoots($root), $source, new PrivacyPolicy(['version' => 2, 'preset' => 'full']), Fixture::temp());
        $result = $capture->capture($age, $recipients, str_repeat('b', 64), $scope);
        V4Format::validateRoot($result['manifest']); self::assertSame('+09:00', $source->get_var('SELECT @@SESSION.time_zone'));
        foreach ($files as $path => $bytes) { unlink($root . '/' . $path); } rmdir($root . '/empty'); rmdir($root);
        $this->server->dropSchema($sourceSchema);
        $objects = []; foreach ($result['objects'] as $object) { $objects[$object['object']['key']] = $object['cipherFile']; }
        $records = ['packs' => [], 'database' => [], 'filesystem' => []]; $contents = [];
        foreach ($result['manifest']['pages'] as $descriptor) {
            $page = json_decode(file_get_contents($this->decrypt($age, $keys[0]['identity'], $objects[$descriptor['object']['key']])), true, 512, JSON_THROW_ON_ERROR);
            V4Format::validatePage($page, $descriptor); foreach ($page['records'] as $record) { $records[$page['kind']][] = $record; }
        }
        foreach ($records['packs'] as $pack) {
            $destination = Fixture::temp() . '/contents';
            V4Pack::extract($this->decrypt($age, $keys[0]['identity'], $objects[$pack['object']['key']]), $destination, $pack);
            foreach ($pack['members'] as $member) { $contents[$member['id']] = file_get_contents($destination . '/' . $member['id']); }
        }
        $sql = ''; $seals = [];
        foreach ($records['database'] as $record) { if ($record['type'] === 'sql') { $sql .= $contents[$record['content']['id']]; } else { $seals[] = $record; } }
        self::assertSame(hash('sha256', $sql), $result['manifest']['database']['sha256']);
        $target = $this->adapter($this->server->newSchema()); $target->executeBatch($sql); $target->query("SET SESSION time_zone='+00:00'");
        foreach ($seals as $table) {
            $hash = hash_init('sha256');
            $rows = $target->get_results(WordPressDatabase::verificationQuery($table['name'], $table['columns'], $table['order']), 'ARRAY_A');
            foreach ($rows as $row) { hash_update($hash, array_values($row)[0] . "\n"); }
            self::assertSame($table['rows'], count($rows)); self::assertSame($table['rowSha256'], hash_final($hash));
            $definition = array_values($target->get_results('SHOW CREATE TABLE `' . $table['name'] . '`', 'ARRAY_A')[0])[1];
            self::assertSame($table['definitionSha256'], WordPressDatabase::definitionSha256($definition, $result['manifest']['database']['environment']['sqlMode']));
        }
        self::assertSame('0001FF275C', $target->get_var('SELECT HEX(payload) FROM child_record WHERE id=1'));
        self::assertSame('11', $target->get_var('SELECT calculated FROM child_record WHERE id=1'));
        self::assertSame('0', $target->get_var('SELECT COUNT(*) FROM child_record c LEFT JOIN parent_record p ON c.parent_id=p.id WHERE c.parent_id IS NOT NULL AND p.id IS NULL'));
        self::assertSame('DISABLED', $target->get_var('SELECT @@GLOBAL.event_scheduler'));
        $actual = []; $path = '';
        foreach ($records['filesystem'] as $record) {
            if ($record['type'] === 'file') { $path = $record['path']; $actual[$path] = ''; self::assertSame(0600, $record['mode']); }
            if ($record['type'] === 'part') { $actual[$path] .= $contents[$record['content']['id']]; }
        }
        ksort($files); ksort($actual); self::assertSame($files, $actual);
    }

    public function testStandaloneKitRestoresFullFilesAndDatabaseForBothKeysAfterSourceAndIndexLoss(): void
    {
        $base = Fixture::temp(); $site = $base . '/site'; $work = $base . '/work';
        mkdir($site, 0700); mkdir($work, 0700); mkdir($base . '/tmp', 0700);
        $files = ['wp-config.php' => '<?php /* synthetic database password and salts */', '.htaccess' => 'Deny from all', '.env' => 'SYNTHETIC_PASSWORD=fixture', 'private.pdf' => "%PDF\0private bytes", 'same.txt' => 'identical'];
        foreach ($files as $path => $bytes) { file_put_contents($site . '/' . $path, $bytes); chmod($site . '/' . $path, 0600); }
        file_put_contents($site . '/different-mode.txt', 'identical'); chmod($site . '/different-mode.txt', 0644);
        mkdir($site . '/empty', 0750);
        $schema = $this->server->newSchema(); $source = $this->adapter($schema);
        $source->executeBatch("CREATE TABLE wp_users(ID INT PRIMARY KEY,user_login VARCHAR(40),user_pass VARCHAR(100),user_email VARCHAR(100));
CREATE TABLE wp_posts(ID INT PRIMARY KEY,author_id INT,payload LONGBLOB,computed INT GENERATED ALWAYS AS (ID+10) STORED,FOREIGN KEY(author_id) REFERENCES wp_users(ID));
CREATE TABLE wp_options(option_id INT PRIMARY KEY,option_name VARCHAR(100),option_value LONGBLOB);
INSERT INTO wp_users VALUES(1,'administrator','synthetic-password-hash','admin@example.invalid');
INSERT INTO wp_posts(ID,author_id,payload) VALUES(1,1,X'0001ff275c');
INSERT INTO wp_options VALUES(1,'admin_email','admin@example.invalid'),(2,'auth_token','synthetic-token'),(3,'serialized',X'613a313a7b733a313a2278223b733a333a2276616c223b7d');");
        $keys = Fixture::keys(); $recipients = array_column($keys, 'public'); $age = new AgeProcess(Fixture::runtime()['age']);
        $unit = str_repeat('9', 64); $snapshot = bin2hex(random_bytes(32));
        $scope = ['accountId' => str_repeat('a', 32), 'bucket' => 'isolated-full-backup', 'unitId' => $unit, 'keySetId' => Manifest::keySetId($recipients), 'encoding' => V4Format::ENCODING];
        $capture = (new V4Capture(new SourceRoots($site), $source, new PrivacyPolicy(['version' => 2, 'preset' => 'full']), $work))->capture($age, $recipients, $snapshot, $scope);
        $repositoryPath = $base . '/repository'; mkdir($repositoryPath, 0700);
        $repository = LocalRepository::forWriting($repositoryPath, $unit);
        foreach ($capture['objects'] as $object) { self::assertSame($object['object'], $repository->putNew($object['object']['key'], $object['cipherFile'])); }
        $rootCipher = $base . '/root.age'; $age->encryptStream([V4Format::json($capture['manifest'])], $rootCipher, $recipients);
        $receipt = $repository->putNew('manifests/' . $snapshot . '.age', $rootCipher);
        $repository->publishWithObjects($snapshot, $receipt, array_column($capture['objects'], 'object'));
        $this->server->dropSchema($schema); $this->removeFixtureTree($site); $this->removeFixtureTree($work); unlink($rootCipher);
        $identity = fopen($keys[0]['identity'], 'rb');
        $directSchema = $this->server->newSchema();
        try { $direct = (new Restorer($age, LocalRepository::forRecovery($repositoryPath, $unit)))->restore($snapshot, $identity, $base . '/direct', $this->server->client(), $directSchema); }
        finally { fclose($identity); }
        self::assertTrue($direct['databaseImported']);
        $kit = $base . '/kit'; $tools = dirname(__DIR__) . '/recovery';
        $archive = dirname(Fixture::runtime()['age'], 2) . '/age.tar.gz';
        [$exit, $out, $error] = $this->command([PHP_BINARY, $tools . '/build-kit.php', '--output', $kit, '--age-archive', $archive]);
        self::assertSame(0, $exit, $out . $error);
        $versions = json_decode(file_get_contents($kit . '/dependencies/versions.json'), true);
        self::assertSame([1, 2, 3, 4], $versions['readableManifestVersions']);
        $mysqlBin = rtrim(IsolatedMysql::mysqlBin(), '/');
        $allowed = $base . PATH_SEPARATOR . $this->server->workspace() . PATH_SEPARATOR . $mysqlBin;
        foreach ($keys as $index => $key) {
            $target = $this->server->newSchema(); $destination = $base . '/restored-' . $index;
            $command = [PHP_BINARY, '-d', 'open_basedir=' . $allowed, '-d', 'sys_temp_dir=' . $base . '/tmp', $kit . '/recover.php', 'restore', '--repository', $repositoryPath, '--unit', $unit, '--age', $kit . '/dependencies/age/age', '--snapshot', $snapshot, '--destination', $destination,
                '--mysql', $mysqlBin . '/mysql', '--mysqldump', $mysqlBin . '/mysqldump', '--defaults-file', $this->server->defaultsFile(), '--mysql-workspace', $this->server->workspace(), '--database', $target];
            [$exit, $out, $error] = $this->command($command, $key['identity']);
            self::assertSame(0, $exit, $error); self::assertTrue(json_decode($out, true)['databaseImported']);
            foreach ($files as $path => $bytes) { self::assertSame($bytes, file_get_contents($destination . '/roots/r0001/' . $path)); self::assertSame(0600, fileperms($destination . '/roots/r0001/' . $path) & 0777); }
            self::assertSame(0644, fileperms($destination . '/roots/r0001/different-mode.txt') & 0777);
            self::assertNotSame(fileinode($destination . '/roots/r0001/same.txt'), fileinode($destination . '/roots/r0001/different-mode.txt'));
            self::assertSame(0750, fileperms($destination . '/roots/r0001/empty') & 0777);
            self::assertSame([['administrator', 'synthetic-password-hash', 'admin@example.invalid']], $this->server->rows($target, 'SELECT user_login,user_pass,user_email FROM wp_users'));
            self::assertSame([['0001FF275C', '11']], $this->server->rows($target, 'SELECT HEX(payload),computed FROM wp_posts'));
            self::assertSame([['auth_token', 'synthetic-token']], $this->server->rows($target, "SELECT option_name,option_value FROM wp_options WHERE option_id=2"));
        }
    }

    private function command(array $command, ?string $identity = null): array
    {
        $input = $identity === null ? null : fopen($identity, 'rb');
        $process = proc_open($command, [0 => $input ?? ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('Cannot run isolated recovery fixture.'); }
        $out = ''; $error = ''; $deadline = microtime(true) + 120;
        stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        try {
            do {
                $out .= stream_get_contents($pipes[1]); $error .= stream_get_contents($pipes[2]);
                if (strlen($out) + strlen($error) > 1048576) { throw new RuntimeException('Isolated fixture diagnostics exceed their limit.'); }
                $status = proc_get_status($process);
                if (!$status['running']) { break; }
                if (microtime(true) > $deadline) { throw new RuntimeException('Isolated recovery fixture timed out.'); }
                usleep(10000);
            } while (true);
            $out .= stream_get_contents($pipes[1]); $error .= stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]); $pipes = [];
            $closed = proc_close($process); $process = null;
            return [$status['exitcode'] >= 0 ? $status['exitcode'] : $closed, $out, $error];
        } finally {
            if (is_resource($process)) { proc_terminate($process); foreach ($pipes as $pipe) { fclose($pipe); } proc_close($process); }
            if (is_resource($input)) { fclose($input); }
        }
    }

    private function removeFixtureTree(string $path): void
    {
        foreach (new \FilesystemIterator($path) as $entry) { if ($entry->isDir() && !$entry->isLink()) { $this->removeFixtureTree($entry->getPathname()); } else { unlink($entry->getPathname()); } }
        rmdir($path);
    }

    private function adapter(string $schema): V4DatabaseMysqliAdapter
    {
        $defaults = parse_ini_file($this->server->defaultsFile(), true, INI_SCANNER_RAW); $connection = mysqli_init();
        if ($connection === false || !mysqli_real_connect($connection, 'localhost', 'root', '', $schema, 0, $defaults['client']['socket'])) { throw new RuntimeException('Cannot connect to the isolated fixture database.'); }
        mysqli_set_charset($connection, 'utf8mb4'); $this->connections[] = $connection; return new V4DatabaseMysqliAdapter($connection);
    }

    private function decrypt(AgeProcess $age, string $identityPath, string $cipher): string
    {
        $plain = Fixture::temp() . '/decrypted'; $identity = fopen($identityPath, 'rb');
        try { $age->decrypt($cipher, $plain, $identity); } finally { fclose($identity); }
        return $plain;
    }
}
