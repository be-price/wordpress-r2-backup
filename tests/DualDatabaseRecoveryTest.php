<?php
declare(strict_types=1);

namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use WordPressR2Backup\{AgeProcess, DedupIndex, LocalRepository, Manifest, PrivacyPolicy, Restorer, SnapshotWriter, SourceRoots, SqliteDatabaseClient, V4Capture, V4Format};

require_once __DIR__ . '/WordPressDatabaseIntegrationTest.php';

final class DualDatabaseRecoveryTest extends TestCase
{
    private ?IsolatedMysql $server = null;
    private ?\mysqli $connection = null;
    private ?SqliteDatabaseClient $client = null;

    protected function tearDown(): void
    {
        if ($this->client !== null) { $this->client->close(); }
        if ($this->connection !== null) { $this->connection->close(); }
        if ($this->server !== null) { $this->server->stop(); }
        Fixture::cleanup();
    }

    /** @group integration */
    public function testMysqlCaptureRestoresThroughTheApiAndCliIntoSqliteAfterAllSourceInputsAreRemoved(): void
    {
        if (getenv('WRB_RUN_V4_MYSQL_INTEGRATION') !== '1') { self::markTestSkipped('Explicit isolated MySQL integration authorization is required.'); }
        if (!extension_loaded('mysqli') || !extension_loaded('pdo_sqlite')) { self::markTestSkipped('The isolated source and SQLite extensions are required.'); }
        require_once dirname(__DIR__) . '/dependencies/sqlite-database-integration/wp-includes/database/load.php';
        $this->server = IsolatedMysql::start(null, 'DISABLED');
        $schema = $this->server->newSchema();
        $defaults = parse_ini_file($this->server->defaultsFile(), true, INI_SCANNER_RAW);
        $this->connection = mysqli_init();
        if (!$this->connection || !mysqli_real_connect($this->connection, 'localhost', 'root', '', $schema, 0, $defaults['client']['socket'])) { throw new RuntimeException('Cannot connect to the isolated source.'); }
        mysqli_set_charset($this->connection, 'utf8mb4');
        $source = new V4DatabaseMysqliAdapter($this->connection);
        $source->executeBatch("SET SESSION sql_mode='';
CREATE TABLE wp_users(ID BIGINT UNSIGNED PRIMARY KEY,user_login VARCHAR(60),user_pass VARCHAR(100),user_email VARCHAR(100));
CREATE TABLE wp_posts(ID BIGINT UNSIGNED PRIMARY KEY,post_author BIGINT UNSIGNED,post_title TEXT,post_content LONGTEXT,payload LONGBLOB NULL,FOREIGN KEY(post_author) REFERENCES wp_users(ID));
CREATE TABLE wp_usermeta(umeta_id BIGINT UNSIGNED PRIMARY KEY,user_id BIGINT UNSIGNED,meta_key VARCHAR(100),meta_value LONGTEXT);
CREATE TABLE wp_options(option_id BIGINT UNSIGNED PRIMARY KEY,option_name VARCHAR(100),option_value LONGTEXT);
INSERT INTO wp_users VALUES(7,'synthetic-admin','synthetic-hash','admin@example.invalid');
INSERT INTO wp_posts VALUES(12,7,'復元テスト','<p>公開記事</p>',X'0001FF275C'),(13,7,'NULLの確認','',NULL);
INSERT INTO wp_usermeta VALUES(1,7,'wp_capabilities','a:1:{s:13:\"administrator\";b:1;}');
INSERT INTO wp_options VALUES(1,'siteurl','https://example.invalid'),(2,'home','https://example.invalid'),(3,'serialized','a:1:{s:3:\"url\";s:24:\"https://example.invalid/\";}');");
        $base = Fixture::temp();
        $site = $base . '/source-site'; $work = $base . '/source-work'; $repositoryPath = $base . '/repository';
        foreach ([$site, $work, $repositoryPath] as $directory) { mkdir($directory, 0700); }
        $files = ['index.php' => '<?php echo "synthetic";', 'wp-content/uploads/画像.bin' => "\x00\xffimage", 'wp-content/themes/synthetic/style.css' => 'body{color:#222}'];
        foreach ($files as $path => $bytes) { Fixture::write($site, $path, $bytes); chmod($site . '/' . $path, 0640); }
        $keys = Fixture::keys(); $recipients = array_column($keys, 'public'); $age = new AgeProcess(Fixture::runtime()['age']);
        $unit = str_repeat('d', 64); $snapshot = bin2hex(random_bytes(32));
        $scope = ['accountId' => str_repeat('a', 32), 'bucket' => 'dual-recovery-test', 'unitId' => $unit, 'keySetId' => Manifest::keySetId($recipients), 'encoding' => V4Format::ENCODING];
        $capture = (new V4Capture(new SourceRoots($site), $source, new PrivacyPolicy(['version' => 2, 'preset' => 'full']), $work))->capture($age, $recipients, $snapshot, $scope);
        $repository = LocalRepository::forWriting($repositoryPath, $unit);
        foreach ($capture['objects'] as $object) { $repository->putNew($object['object']['key'], $object['cipherFile']); }
        $rootCipher = $base . '/root.age'; $age->encryptStream([V4Format::json($capture['manifest'])], $rootCipher, $recipients);
        $receipt = $repository->putNew('manifests/' . $snapshot . '.age', $rootCipher);
        $repository->publishWithObjects($snapshot, $receipt, array_column($capture['objects'], 'object'));
        // Both database modes must expose the same system-only CLI without requiring SQLite startup settings.
        $mysqlTarget = $this->server->newSchema();
        $mysqlBin = IsolatedMysql::mysqlBin();
        $systemCommand = [PHP_BINARY, dirname(__DIR__) . '/recovery/recover.php', 'restore', '--repository', $repositoryPath,
            '--unit', $unit, '--age', Fixture::runtime()['age'], '--snapshot', $snapshot, '--destination', $base . '/mysql-system',
            '--mysql', $mysqlBin . 'mysql', '--mysqldump', $mysqlBin . 'mysqldump', '--defaults-file', $this->server->defaultsFile(),
            '--mysql-workspace', $this->server->workspace(), '--database', $mysqlTarget, '--local-media-dry-run', 'yes', '--local-system-root', 'r0001'];
        [$exit, $out, $error] = $this->command($systemCommand, $keys[0]['identity']);
        self::assertSame(0, $exit, $error);
        $mysqlResult = json_decode($out, true, 32, JSON_THROW_ON_ERROR);
        self::assertTrue($mysqlResult['databaseImported']); self::assertSame(2, $mysqlResult['files']); self::assertSame(1, $mysqlResult['mediaDryRun']['files']);
        self::assertTrue($mysqlResult['mediaDryRun']['contentsVerified']); self::assertArrayNotHasKey('site', $mysqlResult);
        self::assertDirectoryDoesNotExist($base . '/mysql-system/roots/r0001/wp-content/uploads');
        self::assertSame($files['index.php'], file_get_contents($base . '/mysql-system/roots/r0001/index.php'));
        self::assertSame([['12', '復元テスト'], ['13', 'NULLの確認']], $this->server->rows($mysqlTarget, 'SELECT ID,post_title FROM wp_posts ORDER BY ID'));
        $socket = $defaults['client']['socket'];
        $this->connection->close(); $this->connection = null;
        $this->server->dropSchema($schema); $this->server->stop(); $this->server = null;
        $this->removeTree($site); $this->removeTree($work); unlink($rootCipher);
        unset($source, $capture, $repository);
        self::assertFileDoesNotExist($socket); self::assertDirectoryDoesNotExist($site); self::assertDirectoryDoesNotExist($work);

        $this->client = new SqliteDatabaseClient($base . '/api.sqlite');
        $identity = fopen($keys[0]['identity'], 'rb');
        try { $result = (new Restorer($age, LocalRepository::forRecovery($repositoryPath, $unit)))->restore($snapshot, $identity, $base . '/api-restored', $this->client, 'sqlite'); }
        finally { fclose($identity); $this->client->close(); }
        self::assertTrue($result['databaseImported']);
        $this->assertRestored($base . '/api-restored', $base . '/api.sqlite', $files);

        $this->client = new SqliteDatabaseClient($base . '/system.sqlite'); $identity = fopen($keys[0]['identity'], 'rb');
        try { $system = (new Restorer($age, LocalRepository::forRecovery($repositoryPath, $unit)))->restore($snapshot, $identity, $base . '/system-restored', $this->client, 'sqlite', null, 'roots/r0001/wp-content/uploads'); }
        finally { fclose($identity); $this->client->close(); }
        self::assertTrue($system['databaseImported']); self::assertSame('local-system-media-dry-run', $system['restoreMode']);
        self::assertSame(2, $system['files']); self::assertSame(1, $system['mediaDryRun']['files']); self::assertTrue($system['mediaDryRun']['contentsVerified']);
        self::assertDirectoryDoesNotExist($base . '/system-restored/roots/r0001/wp-content/uploads');
        self::assertSame($files['index.php'], file_get_contents($base . '/system-restored/roots/r0001/index.php'));
        self::assertSame($files['wp-content/themes/synthetic/style.css'], file_get_contents($base . '/system-restored/roots/r0001/wp-content/themes/synthetic/style.css'));
        $db = new \WP_MySQL_On_SQLite('mysql-on-sqlite:path=' . $base . '/system.sqlite;dbname=sqlite');
        self::assertSame('復元テスト', $db->query('SELECT post_title FROM wp_posts WHERE ID=12')->fetchColumn()); $db = null;

        $command = [PHP_BINARY, dirname(__DIR__) . '/recovery/recover.php', 'restore', '--repository', $repositoryPath, '--unit', $unit, '--age', Fixture::runtime()['age'], '--snapshot', $snapshot, '--destination', $base . '/cli-restored', '--sqlite-file', $base . '/cli.sqlite'];
        [$exit, $out, $error] = $this->command($command, $keys[1]['identity']);
        self::assertSame(0, $exit, $error); self::assertTrue(json_decode($out, true, 32, JSON_THROW_ON_ERROR)['databaseImported']);
        $this->assertRestored($base . '/cli-restored', $base . '/cli.sqlite', $files);
        $headless = array_replace($command, [count($command) - 3 => $base . '/sqlite-system-cli', count($command) - 1 => $base . '/sqlite-system-cli.sqlite']);
        [$exit, $out, $error] = $this->command([...$headless, '--local-media-dry-run', 'yes', '--local-system-root', 'r0001'], $keys[1]['identity']);
        self::assertSame(0, $exit, $error); $headlessResult = json_decode($out, true, 32, JSON_THROW_ON_ERROR);
        self::assertTrue($headlessResult['databaseImported']); self::assertSame(2, $headlessResult['files']);
        self::assertSame(1, $headlessResult['mediaDryRun']['files']); self::assertArrayNotHasKey('site', $headlessResult);
        self::assertDirectoryDoesNotExist($base . '/sqlite-system-cli/roots/r0001/wp-content/uploads');
        self::assertSame($files['wp-content/themes/synthetic/style.css'], file_get_contents($base . '/sqlite-system-cli/roots/r0001/wp-content/themes/synthetic/style.css'));
        [$exit] = $this->command(array_replace($command, [count($command) - 3 => $base . '/existing-file-attempt']), $keys[1]['identity']);
        self::assertSame(1, $exit); self::assertDirectoryDoesNotExist($base . '/existing-file-attempt');
        $this->assertRestored($base . '/cli-restored', $base . '/cli.sqlite', $files);
    }

    public function testCliRejectsMixedDatabaseModesBeforeCreatingAnyDestination(): void
    {
        $base = Fixture::temp(); $destination = $base . '/destination'; $database = $base . '/new.sqlite';
        $command = [PHP_BINARY, dirname(__DIR__) . '/recovery/recover.php', 'restore', '--repository', $base . '/missing', '--unit', str_repeat('a', 64), '--age', '/missing-age', '--snapshot', str_repeat('b', 64), '--destination', $destination,
            '--sqlite-file', $database, '--mysql', '/missing-mysql', '--mysqldump', '/missing-mysqldump', '--defaults-file', '/missing-defaults', '--mysql-workspace', '/missing-workspace', '--database', 'wrb_test_mix'];
        [$exit, $out, $error] = $this->command($command);
        self::assertSame(1, $exit); self::assertSame('', $out); self::assertStringContainsString('引数検査', $error);
        self::assertFileDoesNotExist($database); self::assertDirectoryDoesNotExist($destination);
    }

    /** @dataProvider invalidSystemOnlyRequests */
    public function testInvalidSystemOnlyRequestsFailBeforeOpeningTheRepositoryOrCreatingTheDatabase(array $flags, string $mode): void
    {
        $base = Fixture::temp(); $destination = $base . '/destination'; $database = $base . '/new.sqlite';
        $db = $mode === 'sqlite' ? ['--sqlite-file', $database] : ($mode === 'mysql'
            ? ['--mysql', '/missing-mysql', '--mysqldump', '/missing-dump', '--defaults-file', '/missing-cnf', '--mysql-workspace', '/missing-workspace', '--database', 'wrb_test_new'] : []);
        $command = [PHP_BINARY, dirname(__DIR__) . '/recovery/recover.php', 'restore', '--repository', $base . '/missing',
            '--unit', str_repeat('a', 64), '--age', '/missing-age', '--snapshot', str_repeat('b', 64), '--destination', $destination, ...$db, ...$flags];
        [$exit, $out, $error] = $this->command($command);
        self::assertSame(1, $exit); self::assertSame('', $out); self::assertStringContainsString('引数検査', $error);
        self::assertFileDoesNotExist($database); self::assertDirectoryDoesNotExist($destination);
    }

    public static function invalidSystemOnlyRequests(): array
    {
        return [
            'no dry run' => [['--local-system-root', 'r0001'], 'sqlite'],
            'wrong dry run value' => [['--local-system-root', 'r0001', '--local-media-dry-run', 'no'], 'sqlite'],
            'traversal root' => [['--local-system-root', '../r0001', '--local-media-dry-run', 'yes'], 'mysql'],
            'path instead of root name' => [['--local-system-root', 'roots/r0001', '--local-media-dry-run', 'yes'], 'sqlite'],
            'wrong root name' => [['--local-system-root', 'wordpress', '--local-media-dry-run', 'yes'], 'mysql'],
            'no database mode' => [['--local-system-root', 'r0001', '--local-media-dry-run', 'yes'], 'none'],
            'partial mysql arguments' => [['--local-system-root', 'r0001', '--local-media-dry-run', 'yes', '--mysql', '/missing'], 'none'],
            'two root sources' => [['--local-system-root', 'r0001', '--local-media-dry-run', 'yes', '--sqlite-site-config', '/missing-config'], 'sqlite'],
            'no startup configuration' => [['--local-system-root', 'r0001', '--local-media-dry-run', 'yes', '--serve-local', 'yes'], 'sqlite'],
            'mysql without root' => [['--local-media-dry-run', 'yes'], 'mysql'],
            'duplicate root' => [['--local-system-root', 'r0001', '--local-system-root', 'r0002', '--local-media-dry-run', 'yes'], 'sqlite'],
        ];
    }

    public function testSqliteRejectsALegacyGenerationBeforePublishingFiles(): void
    {
        require_once dirname(__DIR__) . '/dependencies/sqlite-database-integration/wp-includes/database/load.php';
        $base = Fixture::temp(); $repositoryPath = Fixture::temp(); $site = Fixture::site(['old.txt' => 'legacy']);
        $sql = $base . '/source.sql'; file_put_contents($sql, "SELECT 1;\n"); chmod($sql, 0600);
        $keys = Fixture::keys(); $age = new AgeProcess(Fixture::runtime()['age']); $unit = str_repeat('a', 64);
        $repository = LocalRepository::forWriting($repositoryPath, $unit);
        $snapshot = (new SnapshotWriter($age, $repository, DedupIndex::openOrCreate(Fixture::temp(), $repository)))->capture($site, $sql, array_column($keys, 'public'));
        $this->client = new SqliteDatabaseClient($base . '/legacy.sqlite');
        $identity = fopen($keys[0]['identity'], 'rb');
        try {
            (new Restorer($age, LocalRepository::forRecovery($repositoryPath, $unit)))->restore($snapshot, $identity, $base . '/legacy-restored', $this->client, 'sqlite');
            self::fail('A legacy generation was accepted for SQLite.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('version 4', $error->getMessage());
            self::assertDirectoryDoesNotExist($base . '/legacy-restored');
        } finally { fclose($identity); }

        $identity = fopen($keys[0]['identity'], 'rb');
        try {
            (new Restorer($age, LocalRepository::forRecovery($repositoryPath, $unit)))->restore($snapshot, $identity, $base . '/legacy-media', null, null, null, 'roots/r0001/wp-content/uploads');
            self::fail('A legacy generation was accepted for local media dry run.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('Local media dry run requires a version 4', $error->getMessage()); self::assertDirectoryDoesNotExist($base . '/legacy-media');
        } finally { fclose($identity); }
    }

    /** @dataProvider invalidLocalMediaOptions */
    public function testLocalMediaCliRejectsImplicitOrNonLoopbackScopeBeforeCreatingAnyDestination(string $value, bool $withConfig, string $localUrl): void
    {
        $base = Fixture::temp(); $destination = $base . '/destination'; $database = $base . '/new.sqlite'; $config = $base . '/site.json';
        file_put_contents($config, json_encode(['root' => 'r0001', 'siteDirectory' => $base . '/site', 'databaseFile' => $base . '/running.sqlite', 'sourceUrl' => 'https://example.invalid', 'localUrl' => $localUrl, 'tablePrefix' => 'wp_', 'administrator' => []], JSON_THROW_ON_ERROR)); chmod($config, 0600);
        $command = [PHP_BINARY, dirname(__DIR__) . '/recovery/recover.php', 'restore', '--repository', $base . '/missing', '--unit', str_repeat('a', 64), '--age', '/missing-age', '--snapshot', str_repeat('b', 64), '--destination', $destination, '--sqlite-file', $database, '--local-media-dry-run', $value];
        if ($withConfig) { $command[] = '--sqlite-site-config'; $command[] = $config; }
        [$exit, $out, $error] = $this->command($command);
        self::assertSame(1, $exit); self::assertSame('', $out); self::assertStringContainsString('引数検査', $error);
        self::assertFileDoesNotExist($database); self::assertDirectoryDoesNotExist($destination); self::assertDirectoryDoesNotExist($base . '/site');
    }

    public static function invalidLocalMediaOptions(): array
    {
        return [
            ['yes', false, 'http://127.0.0.1:18826'], ['no', true, 'http://127.0.0.1:18826'],
            ['yes', true, 'http://example.invalid:18826'], ['yes', true, 'http://user@127.0.0.1:18826'],
            ['yes', true, 'http://127.0.0.1:18826/subdirectory'], ['yes', true, 'http://127.0.0.1:18826?query=1'],
            ['yes', true, 'http://127.0.0.1'], ['yes', true, 'https://127.0.0.1:18826'],
        ];
    }

    /** @dataProvider invalidRunningCopyRequests */
    public function testRunningCopyRequestMistakesFailBeforeAnyRecoveryWork(array $changes): void
    {
        $base = Fixture::temp(); $destination = $base . '/destination'; $database = $base . '/new.sqlite'; $config = $base . '/site.json';
        mkdir($base . '/existing-parent', 0700); file_put_contents($base . '/taken.sqlite', 'taken'); chmod($base . '/taken.sqlite', 0600);
        $replace = static function ($value) use ($base, $destination, $database) { return is_string($value) ? str_replace(['{base}', '{destination}', '{database}'], [$base, $destination, $database], $value) : $value; };
        $request = array_map($replace, array_replace(['root' => 'r0001', 'siteDirectory' => $base . '/site', 'databaseFile' => $base . '/running.sqlite', 'sourceUrl' => 'https://example.invalid', 'localUrl' => 'http://127.0.0.1:18826',
            'tablePrefix' => 'wp_', 'administrator' => []], $changes));
        file_put_contents($config, json_encode($request, JSON_THROW_ON_ERROR)); chmod($config, 0600);
        $command = [PHP_BINARY, dirname(__DIR__) . '/recovery/recover.php', 'restore', '--repository', $base . '/missing', '--unit', str_repeat('a', 64), '--age', '/missing-age', '--snapshot', str_repeat('b', 64), '--destination', $destination, '--sqlite-file', $database, '--sqlite-site-config', $config];
        [$exit, $out, $error] = $this->command($command);
        self::assertSame(1, $exit); self::assertSame('', $out); self::assertStringContainsString('引数検査', $error);
        self::assertFileDoesNotExist($database); self::assertDirectoryDoesNotExist($destination); self::assertDirectoryDoesNotExist($base . '/site'); self::assertFileDoesNotExist($base . '/running.sqlite');
        self::assertSame('taken', file_get_contents($base . '/taken.sqlite'));
    }

    public static function invalidRunningCopyRequests(): array
    {
        return [
            'table prefix' => [['tablePrefix' => 'wp-']], 'source URL scheme' => [['sourceUrl' => 'ftp://example.invalid']], 'source URL query' => [['sourceUrl' => 'https://example.invalid/?x=1']],
            'duplicate aliases' => [['sourceAliases' => ['https://example.invalid/']]], 'administrator' => [['administrator' => ['login' => 'x']]],
            'missing site parent' => [['siteDirectory' => '{base}/missing-parent/site']], 'missing database parent' => [['databaseFile' => '{base}/missing-parent/running.sqlite']],
            'database already exists' => [['databaseFile' => '{base}/taken.sqlite']], 'site equals database' => [['siteDirectory' => '{base}/same', 'databaseFile' => '{base}/same']],
            'database is the restore database' => [['databaseFile' => '{database}']], 'site inside destination' => [['siteDirectory' => '{destination}/site']],
            'database inside destination' => [['databaseFile' => '{destination}/running.sqlite']], 'site contains destination' => [['siteDirectory' => '{base}']],
        ];
    }

    private function assertRestored(string $destination, string $database, array $files): void
    {
        foreach ($files as $path => $bytes) { self::assertSame($bytes, file_get_contents($destination . '/roots/r0001/' . $path)); self::assertSame(0640, fileperms($destination . '/roots/r0001/' . $path) & 0777); }
        self::assertSame(0600, fileperms($database) & 0777);
        $db = new \WP_MySQL_On_SQLite('mysql-on-sqlite:path=' . $database . ';dbname=sqlite');
        self::assertSame('復元テスト', $db->query('SELECT post_title FROM wp_posts WHERE ID=12')->fetchColumn());
        self::assertSame('0001FF275C', $db->query('SELECT HEX(payload) FROM wp_posts WHERE ID=12')->fetchColumn());
        self::assertNull($db->query('SELECT payload FROM wp_posts WHERE ID=13')->fetchColumn());
        self::assertSame('a:1:{s:13:"administrator";b:1;}', $db->query('SELECT meta_value FROM wp_usermeta')->fetchColumn());
        self::assertSame('https://example.invalid', $db->query("SELECT option_value FROM wp_options WHERE option_name='siteurl'")->fetchColumn());
    }

    private function command(array $command, ?string $identity = null): array
    {
        $input = $identity === null ? null : fopen($identity, 'rb');
        $process = proc_open($command, [0 => $input ?? ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('Cannot start the synthetic recovery command.'); }
        $out = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        $exit = proc_close($process); if (is_resource($input)) { fclose($input); }
        return [$exit, $out, $error];
    }

    private function removeTree(string $directory): void
    {
        foreach (new \FilesystemIterator($directory) as $entry) { if ($entry->isDir() && !$entry->isLink()) { $this->removeTree($entry->getPathname()); } else { unlink($entry->getPathname()); } }
        rmdir($directory);
    }
}
