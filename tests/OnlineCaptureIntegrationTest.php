<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\AgeProcess;
use WordPressR2Backup\Manifest;
use WordPressR2Backup\PrivacyPolicy;
use WordPressR2Backup\SourceRoots;
use WordPressR2Backup\V4Capture;
use WordPressR2Backup\V4ContentIndex;
use WordPressR2Backup\V4Format;

require_once __DIR__ . '/WordPressDatabaseIntegrationTest.php';
require_once __DIR__ . '/V4SharingCaptureTest.php';

/** Isolated capture-only tests. No R2 or restore operations. */
final class OnlineCaptureIntegrationTest extends TestCase
{
    private ?IsolatedMysql $server = null;
    private array $connections = [];

    protected function setUp(): void
    {
        if (getenv('WRB_RUN_V4_MYSQL_INTEGRATION') !== '1' || !extension_loaded('mysqli')) { self::markTestSkipped('Explicit isolated MySQL integration authorization and mysqli are required.'); }
        $engine = getenv('WRB_TEST_MYSQL_ENGINE') ?: 'mysql';
        if (!in_array($engine, ['mysql', 'mariadb105'], true)) { throw new \RuntimeException('Invalid isolated test engine.'); }
        $this->server = IsolatedMysql::start(null, $engine === 'mariadb105' ? 'OFF' : 'DISABLED', $engine);
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $connection) { $connection->close(); }
        if ($this->server !== null) { $this->server->stop(); }
        Fixture::cleanup();
    }

    public function testOnlineCaptureEmitsExplicitEvidenceAndRetainsFileChecks(): void
    {
        $schema = $this->server->newSchema();
        $defaults = parse_ini_file($this->server->defaultsFile(), true, INI_SCANNER_RAW);
        $connection = mysqli_init();
        self::assertTrue(mysqli_real_connect($connection, 'localhost', 'root', '', $schema, 0, $defaults['client']['socket']));
        mysqli_set_charset($connection, 'utf8mb4'); $this->connections[] = $connection;
        $adapter = new V4DatabaseMysqliAdapter($connection);
        $adapter->executeBatch("CREATE TABLE records(id INT PRIMARY KEY, contents VARCHAR(100)) ENGINE=InnoDB; INSERT INTO records VALUES (1,'synthetic'),(2,'excluded');");
        $root = Fixture::temp(); Fixture::write($root, 'application.php', 'synthetic file');
        $keys = Fixture::keys(2); $recipients = array_column($keys, 'public');
        $scope = ['accountId' => str_repeat('a', 32), 'bucket' => 'synthetic-capture', 'unitId' => str_repeat('c', 64),
            'keySetId' => Manifest::keySetId($recipients), 'encoding' => V4Format::ENCODING];
        $policy = new PrivacyPolicy(['version' => 2, 'preset' => 'custom', 'rows' => ['records' => ['id' => ['2' => 'exclude']]]]);
        $capture = new V4Capture(new SourceRoots($root), $adapter, $policy, Fixture::temp());
        $result = $capture->capture(new AgeProcess(Fixture::runtime()['age']), $recipients, str_repeat('b', 64), $scope, null, null, 'online');
        self::assertSame('online', $result['manifest']['wordpress']['consistencyMode'] ?? null);
        self::assertSame(1, $result['manifest']['counts']['files']);
        self::assertSame(1, $result['evidence']['tables'][0]['rows']);
        self::assertSame(1, $result['evidence']['tables'][0]['excludedRows']);
        self::assertArrayHasKey('snapshotAt', $result['evidence']);
        self::assertArrayHasKey('databaseVerifiedAt', $result['evidence']);
        self::assertArrayHasKey('filesVerifiedAt', $result['evidence']);
        V4Format::validateRoot($result['manifest']);
    }

    public function testOnlineReleasesDatabaseBeforeFileVerificationAndRejectsChangedFile(): void
    {
        $schema = $this->server->newSchema();
        $defaults = parse_ini_file($this->server->defaultsFile(), true, INI_SCANNER_RAW);
        $source = mysqli_init(); $writer = mysqli_init();
        foreach ([$source, $writer] as $connection) {
            self::assertTrue(mysqli_real_connect($connection, 'localhost', 'root', '', $schema, 0, $defaults['client']['socket']));
            mysqli_set_charset($connection, 'utf8mb4'); $this->connections[] = $connection;
        }
        $adapter = new V4DatabaseMysqliAdapter($source);
        $adapter->executeBatch("CREATE TABLE records(id INT PRIMARY KEY, contents VARCHAR(100)) ENGINE=InnoDB; INSERT INTO records VALUES (1,'synthetic');");
        mysqli_query($writer, 'SET SESSION lock_wait_timeout=1');
        $root = Fixture::temp(); Fixture::write($root, 'application.php', 'before');
        $keys = Fixture::keys(2); $recipients = array_column($keys, 'public');
        $scope = ['accountId' => str_repeat('a', 32), 'bucket' => 'synthetic-capture', 'unitId' => str_repeat('c', 64),
            'keySetId' => Manifest::keySetId($recipients), 'encoding' => V4Format::ENCODING];
        $capture = new V4Capture(new SourceRoots($root), $adapter, new PrivacyPolicy(['version' => 2, 'preset' => 'full']), Fixture::temp());
        $changed = false; $ddlSucceeded = false; $error = null;
        try {
            $capture->capture(new AgeProcess(Fixture::runtime()['age']), $recipients, str_repeat('b', 64), $scope,
                static function (array $update) use ($writer, $root, &$changed, &$ddlSucceeded): void {
                    if ($changed || ($update['phase'] ?? '') !== 'capture' || ($update['files'] ?? 0) !== 1) { return; }
                    $changed = true;
                    $ddlSucceeded = mysqli_query($writer, 'ALTER TABLE records ADD COLUMN added_after_snapshot INT NULL');
                    file_put_contents($root . '/application.php', 'changed');
                }, null, 'online');
        } catch (\RuntimeException $failure) { $error = $failure; }
        self::assertTrue($changed, 'file publication began');
        self::assertTrue($ddlSucceeded, 'the database snapshot and its metadata locks must be released before file processing');
        self::assertInstanceOf(\RuntimeException::class, $error, 'a changed source file must fail the capture');
        self::assertStringContainsString('changed', $error->getMessage());
    }

    public function testFilteredOnlineCaptureCanReuseOnlyEligibleSharedContentsAfterPriorScan(): void
    {
        $schema = $this->server->newSchema(); $defaults = parse_ini_file($this->server->defaultsFile(), true, INI_SCANNER_RAW);
        $connection = mysqli_init();
        self::assertTrue(mysqli_real_connect($connection, 'localhost', 'root', '', $schema, 0, $defaults['client']['socket']));
        mysqli_set_charset($connection, 'utf8mb4'); $this->connections[] = $connection;
        $adapter = new V4DatabaseMysqliAdapter($connection);
        $adapter->executeBatch("CREATE TABLE records(id INT PRIMARY KEY, contents VARCHAR(100)) ENGINE=InnoDB; INSERT INTO records VALUES (1,'synthetic');");
        $root = Fixture::temp(); Fixture::write($root, 'public.php', 'public'); Fixture::write($root, 'private.php', 'private');
        $keys = Fixture::keys(2); $recipients = array_column($keys, 'public');
        $scope = ['accountId' => str_repeat('a', 32), 'bucket' => 'synthetic-capture', 'unitId' => str_repeat('c', 64),
            'keySetId' => Manifest::keySetId($recipients), 'encoding' => V4Format::ENCODING];
        $repo = new V4SharingRepository(Fixture::temp(), $scope['unitId']);
        $index = V4ContentIndex::openOrCreate(Fixture::temp(), $scope, $repo);
        $age = new AgeProcess(Fixture::runtime()['age']);
        $first = $index->withLock(function () use ($root, $adapter, $age, $recipients, $scope, $index): array {
            return (new V4Capture(new SourceRoots($root), $adapter, new PrivacyPolicy(['version' => 2, 'preset' => 'full']), Fixture::temp()))
                ->capture($age, $recipients, str_repeat('a', 64), $scope, null, $index, 'online');
        });
        $index->withLock(function () use ($repo, $index, $first): void {
            foreach ($first['objects'] as $object) {
                if ($object['cipherFile'] === null) { continue; }
                $receipt = $repo->put($object['object']['key'], $object['cipherFile']);
                if (strpos($receipt['key'], 'packs/') === 0) { $index->markReady($receipt); }
            }
        });
        $policy = new PrivacyPolicy(['version' => 2, 'preset' => 'custom', 'paths' => ['private.php' => 'exclude']]);
        $capture = new V4Capture(new SourceRoots($root), $adapter, $policy, Fixture::temp());
        $capture->scan(); // Also exercises the membership prepass when file chunks were not collected by the scan.
        $result = $index->withLock(static function () use ($capture, $age, $recipients, $scope, $index): array {
            return $capture->capture($age, $recipients, str_repeat('b', 64), $scope, null, $index, 'online');
        });
        self::assertSame(1, $result['manifest']['counts']['files']);
        self::assertSame('online', $result['manifest']['wordpress']['consistencyMode']);
        self::assertGreaterThan(0, $result['sharing']['repackedBytes'], 'mixed packs must be rebuilt when a file becomes excluded');
        V4Format::validateRoot($result['manifest']);
        $objects = []; foreach ($result['objects'] as $object) { $objects[$object['object']['key']] = $object['cipherFile']; }
        $excluded = V4Format::contentId(strlen('private'), hash('sha256', 'private'));
        foreach ($result['manifest']['pages'] as $descriptor) {
            if ($descriptor['kind'] !== 'packs') { continue; }
            $plain = Fixture::temp() . '/page'; $identity = fopen($keys[0]['identity'], 'rb');
            try { $age->decrypt($objects[$descriptor['object']['key']], $plain, $identity); } finally { fclose($identity); }
            $page = json_decode(file_get_contents($plain), true, 512, JSON_THROW_ON_ERROR); V4Format::validatePage($page, $descriptor);
            foreach ($page['records'] as $pack) { self::assertNotContains($excluded, array_column($pack['members'], 'id')); }
        }
    }

    public function testPublicContentDecisionRemainsBoundToDatabaseSnapshotWhenPostIsMadePrivateLater(): void
    {
        $schema = $this->server->newSchema(); $defaults = parse_ini_file($this->server->defaultsFile(), true, INI_SCANNER_RAW);
        $source = mysqli_init(); $writer = mysqli_init();
        foreach ([$source, $writer] as $connection) {
            self::assertTrue(mysqli_real_connect($connection, 'localhost', 'root', '', $schema, 0, $defaults['client']['socket']));
            mysqli_set_charset($connection, 'utf8mb4'); $this->connections[] = $connection;
        }
        $adapter = new V4DatabaseMysqliAdapter($source);
        $adapter->executeBatch("CREATE TABLE wp_posts(ID BIGINT UNSIGNED PRIMARY KEY,post_type VARCHAR(30),post_status VARCHAR(20),post_password VARCHAR(30),post_parent BIGINT UNSIGNED,post_mime_type VARCHAR(30)) ENGINE=InnoDB;
            CREATE TABLE wp_postmeta(meta_id BIGINT UNSIGNED PRIMARY KEY,post_id BIGINT UNSIGNED,meta_key VARCHAR(100),meta_value LONGTEXT) ENGINE=InnoDB;
            INSERT INTO wp_posts VALUES(10,'post','publish','',0,''),(2,'post','draft','',0,''),(3,'attachment','inherit','',10,'image/jpeg'),(11,'attachment','inherit','',2,'image/jpeg');
            INSERT INTO wp_postmeta VALUES(1,3,'_wp_attached_file','2026/public.jpg'),(2,11,'_wp_attached_file','2026/private.jpg');");
        $root = Fixture::temp(); Fixture::write($root, 'wp-content/uploads/2026/public.jpg', 'synthetic public'); Fixture::write($root, 'wp-content/uploads/2026/private.jpg', 'synthetic private');
        $keys = Fixture::keys(2); $recipients = array_column($keys, 'public');
        $scope = ['accountId' => str_repeat('a', 32), 'bucket' => 'synthetic-capture', 'unitId' => str_repeat('c', 64),
            'keySetId' => Manifest::keySetId($recipients), 'encoding' => V4Format::ENCODING];
        $rules = ['version' => 2, 'preset' => 'custom', 'legacy' => ['publicContentOnly' => true, 'scanPublic' => false]];
        $capture = new V4Capture(new SourceRoots($root), $adapter, new PrivacyPolicy($rules), Fixture::temp());
        $changed = false;
        $result = $capture->capture(new AgeProcess(Fixture::runtime()['age']), $recipients, str_repeat('b', 64), $scope,
            static function (array $update) use ($writer, &$changed): void {
                if ($changed || ($update['phase'] ?? '') !== 'capture' || ($update['files'] ?? 0) !== 1) { return; }
                $changed = true;
                if (!mysqli_query($writer, "UPDATE wp_posts SET post_status='draft' WHERE ID=10")) { throw new \RuntimeException('Could not update synthetic post'); }
            }, null, 'online');
        self::assertTrue($changed);
        self::assertSame('draft', $adapter->get_var('SELECT post_status FROM wp_posts WHERE ID=10'));
        self::assertSame(1, $result['manifest']['counts']['files'], 'only the snapshot-public image is saved');
        $tables = array_column($result['evidence']['tables'], null, 'name');
        self::assertSame(2, $tables['wp_posts']['rows']); self::assertSame(2, $tables['wp_posts']['excludedRows']);
        self::assertSame(1, $tables['wp_postmeta']['rows']); self::assertSame(1, $tables['wp_postmeta']['excludedRows']);
        self::assertSame('online', $result['manifest']['wordpress']['consistencyMode']);
    }

    public function testPublicToPrivateChangeBetweenScanAndSnapshotFails(): void
    {
        $schema = $this->server->newSchema(); $defaults = parse_ini_file($this->server->defaultsFile(), true, INI_SCANNER_RAW);
        $source = mysqli_init(); $writer = mysqli_init();
        foreach ([$source, $writer] as $connection) {
            self::assertTrue(mysqli_real_connect($connection, 'localhost', 'root', '', $schema, 0, $defaults['client']['socket']));
            mysqli_set_charset($connection, 'utf8mb4'); $this->connections[] = $connection;
        }
        $adapter = new V4DatabaseMysqliAdapter($source);
        $adapter->executeBatch("CREATE TABLE wp_posts(ID BIGINT UNSIGNED PRIMARY KEY,post_type VARCHAR(30),post_status VARCHAR(20),post_password VARCHAR(30),post_parent BIGINT UNSIGNED,post_mime_type VARCHAR(30)) ENGINE=InnoDB;
            CREATE TABLE wp_postmeta(meta_id BIGINT UNSIGNED PRIMARY KEY,post_id BIGINT UNSIGNED,meta_key VARCHAR(100),meta_value LONGTEXT) ENGINE=InnoDB;
            INSERT INTO wp_posts VALUES(10,'post','publish','',0,''),(3,'attachment','inherit','',10,'image/jpeg');
            INSERT INTO wp_postmeta VALUES(1,3,'_wp_attached_file','2026/public.jpg');");
        $root = Fixture::temp(); Fixture::write($root, 'wp-content/uploads/2026/public.jpg', 'synthetic public');
        $keys = Fixture::keys(2); $recipients = array_column($keys, 'public');
        $scope = ['accountId' => str_repeat('a', 32), 'bucket' => 'synthetic-capture', 'unitId' => str_repeat('c', 64),
            'keySetId' => Manifest::keySetId($recipients), 'encoding' => V4Format::ENCODING];
        $rules = ['version' => 2, 'preset' => 'custom', 'legacy' => ['publicContentOnly' => true, 'scanPublic' => false]];
        $capture = new V4Capture(new SourceRoots($root), $adapter, new PrivacyPolicy($rules), Fixture::temp());
        $changed = false; $failure = null;
        try {
            $capture->capture(new AgeProcess(Fixture::runtime()['age']), $recipients, str_repeat('b', 64), $scope,
                static function (array $update) use ($writer, &$changed): void {
                    if ($changed || ($update['phase'] ?? '') !== 'scan' || !isset($update['tableCount'])) { return; }
                    $changed = true;
                    if (!mysqli_query($writer, "UPDATE wp_posts SET post_status='draft' WHERE ID=10")) { throw new \RuntimeException('Could not update synthetic post'); }
                }, null, 'online');
        } catch (\RuntimeException $error) { $failure = $error; }
        self::assertTrue($changed);
        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertStringContainsString('Policy context changed', $failure->getMessage());
    }
}
