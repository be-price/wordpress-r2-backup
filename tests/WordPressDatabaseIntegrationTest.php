<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use WordPressR2Backup\PrivacyPolicy;
use WordPressR2Backup\WordPressDatabase;

/** @group integration */
final class WordPressDatabaseIntegrationTest extends TestCase
{
    private ?IsolatedMysql $server = null;
    private array $connections = [];

    protected function setUp(): void
    {
        if (getenv('WRB_RUN_V4_MYSQL_INTEGRATION') !== '1') {
            self::markTestSkipped('Explicit isolated v4 MySQL integration authorization is required.');
        }
        if (!extension_loaded('mysqli')) { self::markTestSkipped('mysqli is required.'); }
        $this->server = IsolatedMysql::start(null, 'DISABLED');
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $connection) { $connection->close(); }
        if ($this->server !== null) { $this->server->stop(); }
        Fixture::cleanup();
    }

    public function testCursorSurvivesSlowConsumerWithoutChangingSessionTimeoutOrBufferingTheWholeResult(): void
    {
        $adapter = $this->adapter($this->server->newSchema());
        $adapter->query('SET SESSION net_write_timeout=1');
        $digits = '(SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9)';
        $rows = 0;
        foreach (\WordPressR2Backup\MysqlRowCursor::rows($adapter->dbh, "SELECT REPEAT(X'00ff27',32768) AS payload FROM $digits a CROSS JOIN $digits b CROSS JOIN $digits c LIMIT 256") as $row) {
            if (++$rows === 1) { sleep(3); }
            self::assertSame(str_repeat("\x00\xff'", 32768), $row['payload']);
        }
        self::assertSame(256, $rows);
        self::assertSame('1', $adapter->get_var('SELECT @@SESSION.net_write_timeout'));
        $large = iterator_to_array(\WordPressR2Backup\MysqlRowCursor::rows($adapter->dbh, "SELECT REPEAT(X'00ff',2621440) AS payload, NULL AS absent"));
        self::assertSame(str_repeat("\x00\xff", 2621440), $large[0]['payload']);
        self::assertNull($large[0]['absent']);
    }

    public function testClosingOrFailingACursorLeavesTheConnectionUsableWithoutLeakingQueryDetails(): void
    {
        $adapter = $this->adapter($this->server->newSchema());
        $cursor = \WordPressR2Backup\MysqlRowCursor::rows($adapter->dbh, 'SELECT 1 AS id UNION ALL SELECT 2');
        self::assertSame(['id' => '1'], $cursor->current());
        unset($cursor);
        self::assertSame('1', $adapter->get_var('SELECT 1'));
        try {
            iterator_to_array(\WordPressR2Backup\MysqlRowCursor::rows($adapter->dbh, 'SELECT secret_missing_field FROM secret_missing_table'));
            self::fail('An invalid cursor query succeeded.');
        } catch (RuntimeException $error) {
            self::assertStringNotContainsString('secret_missing', $error->getMessage());
        }
        self::assertSame('1', $adapter->get_var('SELECT 1'));
    }

    public function testNativePolicyCursorKeepsPublicArticlesAndImagesAndExcludesPrivateContent(): void
    {
        $adapter = $this->adapter($this->server->newSchema());
        $adapter->executeBatch("CREATE TABLE wp_posts(ID BIGINT UNSIGNED PRIMARY KEY,post_type VARCHAR(30),post_status VARCHAR(20),post_password VARCHAR(30),post_parent BIGINT UNSIGNED,post_mime_type VARCHAR(30));
            CREATE TABLE wp_postmeta(meta_id BIGINT UNSIGNED PRIMARY KEY,post_id BIGINT UNSIGNED,meta_key VARCHAR(100),meta_value LONGTEXT);
            INSERT INTO wp_posts VALUES(10,'post','publish','',0,''),(2,'post','draft','',0,''),(3,'attachment','inherit','',10,'image/jpeg'),(11,'attachment','inherit','',2,'image/jpeg');
            INSERT INTO wp_postmeta VALUES(1,3,'_wp_attachment_metadata','a:1:{s:4:\"file\";s:15:\"2026/public.jpg\";}'),(2,3,'_wp_attached_file','2026/public.jpg'),(3,11,'_wp_attached_file','2026/private.jpg');");
        $policy = new PrivacyPolicy(['version' => 2, 'preset' => 'custom', 'legacy' => ['publicContentOnly' => true, 'scanPublic' => false]]);
        $context = new \WordPressR2Backup\WordPressPolicyContext($adapter, $policy, Fixture::temp());
        $digest = $context->prepare();
        self::assertSame($digest, $context->prepare());
        foreach ($adapter->get_results('SELECT * FROM wp_posts', 'ARRAY_A') as $row) {
            self::assertSame(in_array($row['ID'], ['10', '3'], true), $policy->includeRow('wp_posts', $row));
        }
        self::assertSame('include', $policy->pathAction('wp-content/uploads/2026/public.jpg')['action']);
        self::assertSame('exclude', $policy->pathAction('wp-content/uploads/2026/private.jpg')['action']);
    }

    public function testSnapshotKeepsPolicyContextAndRowsAtTheSamePoint(): void
    {
        $schema = $this->server->newSchema(); $reader = $this->adapter($schema); $writer = $this->adapter($schema);
        $reader->executeBatch("CREATE TABLE wp_posts(ID BIGINT UNSIGNED PRIMARY KEY,post_type VARCHAR(30),post_status VARCHAR(20),post_password VARCHAR(30),post_parent BIGINT UNSIGNED,post_mime_type VARCHAR(30)) ENGINE=InnoDB;
            CREATE TABLE wp_postmeta(meta_id BIGINT UNSIGNED PRIMARY KEY,post_id BIGINT UNSIGNED,meta_key VARCHAR(100),meta_value LONGTEXT) ENGINE=InnoDB;
            INSERT INTO wp_posts VALUES(10,'post','publish','',0,'')");
        $policy = new PrivacyPolicy(['version' => 2, 'preset' => 'custom', 'legacy' => ['publicContentOnly' => true, 'scanPublic' => false]]);
        $context = new \WordPressR2Backup\WordPressPolicyContext($reader, $policy, Fixture::temp());
        $source = new WordPressDatabase($reader, $policy);
        $source->withConsistentSnapshot(static function (WordPressDatabase $database) use ($context, $reader, $writer, $policy): void {
            $before = $context->prepare();
            self::assertTrue($writer->query("UPDATE wp_posts SET post_status='private' WHERE ID=10"));
            self::assertSame($before, $context->prepare());
            $row = $reader->get_results('SELECT * FROM wp_posts WHERE ID=10', 'ARRAY_A')[0];
            self::assertTrue($policy->includeRow('wp_posts', $row));
            foreach ($database->records() as $record) { if ($record['type'] === 'sql') { foreach ($record['chunks'] as $ignored) {} } }
        });
        self::assertSame('private', $reader->get_var('SELECT post_status FROM wp_posts WHERE ID=10'));
    }

    private function adapter(string $schema): V4DatabaseMysqliAdapter
    {
        $defaults = parse_ini_file($this->server->defaultsFile(), true, INI_SCANNER_RAW);
        $connection = mysqli_init();
        if ($connection === false || !mysqli_real_connect($connection, 'localhost', 'root', '', $schema, 0, $defaults['client']['socket'])) { throw new RuntimeException('Cannot connect to the isolated fixture database.'); }
        mysqli_set_charset($connection, 'utf8mb4');
        $this->connections[] = $connection;
        return new V4DatabaseMysqliAdapter($connection);
    }

    public function testDatabaseRestoresAfterTheSourceSchemaIsRemovedWithForeignKeysGeneratedColumnsAndExactValues(): void
    {
        $schema = $this->server->newSchema();
        $sourceAdapter = $this->adapter($schema);
        $sourceAdapter->executeBatch("SET SESSION time_zone='+09:00'; SET SESSION sql_mode='NO_AUTO_VALUE_ON_ZERO';
CREATE TABLE parent_record(id BIGINT UNSIGNED PRIMARY KEY,label VARCHAR(40) CHARACTER SET latin1 COLLATE latin1_bin) ENGINE=InnoDB;
CREATE TABLE child_record(id INT PRIMARY KEY,parent_id BIGINT UNSIGNED NULL,payload LONGBLOB NULL,empty_value VARBINARY(8),stamp TIMESTAMP(6) NULL,local_stamp DATETIME(6),zero_date DATE,calculated BIGINT GENERATED ALWAYS AS (id+10) STORED,CONSTRAINT child_parent FOREIGN KEY(parent_id) REFERENCES parent_record(id)) ENGINE=InnoDB;
CREATE TABLE no_key(a VARCHAR(40) COLLATE utf8mb4_unicode_ci,b VARBINARY(40)) ENGINE=MyISAM;
CREATE TABLE unique_key_only(a INT NULL,b VARBINARY(40),UNIQUE KEY uq(a)) ENGINE=MEMORY;
INSERT INTO parent_record VALUES(1,CONVERT(X'636166e9' USING latin1)),(2,'');
INSERT INTO child_record(id,parent_id,payload,empty_value,stamp,local_stamp,zero_date) VALUES(1,1,X'0001ff275c',X'','2020-01-02 03:04:05.123456','2020-01-02 03:04:05.123456','0000-00-00'),(2,NULL,NULL,X'00',NULL,'0000-00-00 00:00:00','2020-02-03');
INSERT INTO no_key VALUES('a',X'ff'),('A',X'00'),(NULL,X''),('a',X'ff');
INSERT INTO unique_key_only VALUES(NULL,X'ff'),(NULL,X'00'),(10,X'27'),(2,X'00');");
        $source = new WordPressDatabase($sourceAdapter, new PrivacyPolicy(['version' => 2, 'preset' => 'full']));
        $captured = $source->withReadLock(static function (WordPressDatabase $database): array {
            $sql = '';
            foreach ($database->records() as $record) { if ($record['type'] === 'sql') { foreach ($record['chunks'] as $chunk) { $sql .= $chunk; } } }
            return ['sql' => $sql, 'tables' => $database->verification(), 'environment' => $database->environment()];
        });
        self::assertSame('+09:00', $sourceAdapter->get_var('SELECT @@SESSION.time_zone'));
        self::assertSame('+09:00', $captured['environment']['timeZone']);
        $targetSchema = $this->server->newSchema();
        $target = $this->adapter($targetSchema);
        $this->server->dropSchema($schema);
        $target->executeBatch($captured['sql']);
        $this->server->client()->verifyV4Database($targetSchema, $captured['tables'], $captured['environment']);
        $target->query("SET SESSION time_zone='+00:00'");
        foreach ($captured['tables'] as $table) {
            $hash = hash_init('sha256');
            $rows = $target->get_results(WordPressDatabase::verificationQuery($table['name'], $table['columns'], $table['order']), 'ARRAY_A');
            foreach ($rows as $row) { hash_update($hash, array_values($row)[0] . "\n"); }
            self::assertSame($table['rows'], count($rows));
            self::assertSame($table['rowSha256'], hash_final($hash));
            $definition = array_values($target->get_results('SHOW CREATE TABLE `' . $table['name'] . '`', 'ARRAY_A')[0])[1];
            self::assertSame($table['definitionSha256'], WordPressDatabase::definitionSha256($definition, $captured['environment']['sqlMode']));
        }
        self::assertSame('0001FF275C', $target->get_var('SELECT HEX(payload) FROM child_record WHERE id=1'));
        self::assertSame('', $target->get_var('SELECT HEX(empty_value) FROM child_record WHERE id=1'));
        self::assertSame('11', $target->get_var('SELECT calculated FROM child_record WHERE id=1'));
        self::assertSame('DISABLED', $target->get_var('SELECT @@GLOBAL.event_scheduler'));
        self::assertSame('0', $target->get_var('SELECT COUNT(*) FROM child_record c LEFT JOIN parent_record p ON c.parent_id=p.id WHERE c.parent_id IS NOT NULL AND p.id IS NULL'));
    }

    public function testRestoredValueChangesAreRejectedEvenWhenCountsAndDefinitionsMatch(): void
    {
        $schema = $this->server->newSchema();
        $adapter = $this->adapter($schema);
        $adapter->executeBatch('CREATE TABLE verified_record(id INT PRIMARY KEY,value VARBINARY(20)); INSERT INTO verified_record VALUES(1,X\'0001FF\');');
        $source = new WordPressDatabase($adapter, new PrivacyPolicy(['version' => 2, 'preset' => 'full']));
        $source->withReadLock(static function (WordPressDatabase $database): void {
            foreach ($database->records() as $record) { if ($record['type'] === 'sql') { foreach ($record['chunks'] as $ignored) {} } }
        });
        $adapter->executeBatch('UPDATE verified_record SET value=X\'0001FE\' WHERE id=1;');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Restored database values differ');
        $this->server->client()->verifyV4Database($schema, $source->verification(), $source->environment());
    }

    public function testAConcurrentWriterRemainsBlockedUntilTheCaptureCallbackReturns(): void
    {
        $schema = $this->server->newSchema();
        $reader = $this->adapter($schema);
        $writer = $this->adapter($schema);
        $reader->executeBatch('CREATE TABLE locked_record(id INT PRIMARY KEY,value INT) ENGINE=InnoDB; INSERT INTO locked_record VALUES(1,0);');
        $source = new WordPressDatabase($reader, new PrivacyPolicy(['version' => 2, 'preset' => 'full']));
        $source->withReadLock(static function (WordPressDatabase $source) use ($writer): void {
            self::assertTrue(mysqli_query($writer->dbh, 'UPDATE locked_record SET value=1 WHERE id=1', MYSQLI_ASYNC));
            $read = [$writer->dbh]; $error = []; $reject = [];
            self::assertSame(0, mysqli_poll($read, $error, $reject, 0, 100000));
            foreach ($source->records() as $record) { if ($record['type'] === 'sql') { foreach ($record['chunks'] as $ignored) {} } }
        });
        $read = [$writer->dbh]; $error = []; $reject = [];
        self::assertSame(1, mysqli_poll($read, $error, $reject, 5));
        self::assertTrue(mysqli_reap_async_query($writer->dbh));
        self::assertSame('1', $reader->get_var('SELECT value FROM locked_record WHERE id=1'));
    }

    public function testConsistentSnapshotDoesNotBlockUpdatesAndReadsTheSameRowsTwice(): void
    {
        $schema = $this->server->newSchema();
        $reader = $this->adapter($schema); $writer = $this->adapter($schema);
        $reader->executeBatch('CREATE TABLE snapshot_record(id INT PRIMARY KEY, value INT) ENGINE=InnoDB; INSERT INTO snapshot_record VALUES(1,0);');
        $source = new WordPressDatabase($reader, new PrivacyPolicy(['version' => 2, 'preset' => 'full']));
        $source->withConsistentSnapshot(static function (WordPressDatabase $database) use ($writer): void {
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $database->snapshotAt());
            self::assertTrue($writer->query('UPDATE snapshot_record SET value=1 WHERE id=1'));
            $first = '';
            foreach ($database->records() as $record) { if ($record['type'] === 'sql') { foreach ($record['chunks'] as $chunk) { $first .= $chunk; } } }
            $second = '';
            foreach ($database->replayRecords() as $record) { if ($record['type'] === 'sql') { foreach ($record['chunks'] as $chunk) { $second .= $chunk; } } }
            self::assertSame($first, $second);
            self::assertStringContainsString('(1,0)', str_replace(' ', '', $first));
        });
        self::assertSame('1', $reader->get_var('SELECT value FROM snapshot_record WHERE id=1'));
    }

    public function testConsistentSnapshotRejectsNonTransactionalTables(): void
    {
        $adapter = $this->adapter($this->server->newSchema());
        $adapter->executeBatch('CREATE TABLE snapshot_myisam(id INT PRIMARY KEY) ENGINE=MyISAM');
        $source = new WordPressDatabase($adapter, new PrivacyPolicy(['version' => 2, 'preset' => 'full']));
        $this->expectException(\WordPressR2Backup\UnsupportedOnlineDatabaseEngine::class);
        $source->withConsistentSnapshot(static function (): void { self::fail('MyISAM must be rejected'); });
    }

    public function testConcurrentAutoIncrementDoesNotLookLikeASchemaChange(): void
    {
        $schema = $this->server->newSchema();
        $reader = $this->adapter($schema); $writer = $this->adapter($schema);
        $reader->executeBatch('CREATE TABLE snapshot_record(id INT AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB; INSERT INTO snapshot_record VALUES(1)');
        $source = new WordPressDatabase($reader, new PrivacyPolicy(['version' => 2, 'preset' => 'full']));
        $source->withConsistentSnapshot(static function (WordPressDatabase $database) use ($writer): void {
            self::assertTrue($writer->query('INSERT INTO snapshot_record VALUES(NULL)'));
            foreach ($database->records() as $record) { if ($record['type'] === 'sql') { foreach ($record['chunks'] as $ignored) {} } }
            foreach ($database->replayRecords() as $record) { if ($record['type'] === 'sql') { foreach ($record['chunks'] as $ignored) {} } }
            self::assertSame(1, $database->verification()[0]['rows']);
        });
        self::assertSame('2', $reader->get_var('SELECT COUNT(*) FROM snapshot_record'));
    }

    public function testSnapshotHoldsMetadataLockUntilItCompletes(): void
    {
        $schema = $this->server->newSchema(); $reader = $this->adapter($schema); $writer = $this->adapter($schema);
        $reader->executeBatch('CREATE TABLE snapshot_record(id INT PRIMARY KEY) ENGINE=InnoDB; INSERT INTO snapshot_record VALUES(1)');
        $source = new WordPressDatabase($reader, new PrivacyPolicy(['version' => 2, 'preset' => 'full']));
        $failure = null;
        try {
            $source->withConsistentSnapshot(static function (WordPressDatabase $database) use ($writer): void {
                self::assertTrue(mysqli_query($writer->dbh, 'ALTER TABLE snapshot_record ADD COLUMN new_value INT', MYSQLI_ASYNC));
                $read = [$writer->dbh]; $error = []; $reject = [];
                self::assertSame(0, mysqli_poll($read, $error, $reject, 0, 100000), 'DDL should wait for the metadata lock');
                foreach ($database->records() as $record) { if ($record['type'] === 'sql') { foreach ($record['chunks'] as $ignored) {} } }
            });
        } catch (RuntimeException $error) { $failure = $error; }
        self::assertInstanceOf(RuntimeException::class, $failure, 'queued DDL must prevent reporting a successful capture');
        $read = [$writer->dbh]; $error = []; $reject = [];
        self::assertSame(1, mysqli_poll($read, $error, $reject, 5));
        self::assertTrue(mysqli_reap_async_query($writer->dbh));
    }

    public function testConsistentSnapshotRejectsAnUnexpectedCommit(): void
    {
        $adapter = $this->adapter($this->server->newSchema());
        $adapter->executeBatch('CREATE TABLE snapshot_record(id INT PRIMARY KEY) ENGINE=InnoDB; INSERT INTO snapshot_record VALUES(1)');
        $source = new WordPressDatabase($adapter, new PrivacyPolicy(['version' => 2, 'preset' => 'full']));
        $this->expectException(RuntimeException::class);
        $source->withConsistentSnapshot(static function (WordPressDatabase $database) use ($adapter): void {
            foreach ($database->records() as $record) { if ($record['type'] === 'sql') { foreach ($record['chunks'] as $ignored) {} } }
            $adapter->query('COMMIT');
        });
    }

    public function testConsistentSnapshotDoesNotCommitAnExistingTransaction(): void
    {
        $schema = $this->server->newSchema();
        $adapter = $this->adapter($schema); $observer = $this->adapter($schema);
        $adapter->executeBatch('CREATE TABLE snapshot_record(id INT PRIMARY KEY) ENGINE=InnoDB; START TRANSACTION; INSERT INTO snapshot_record VALUES(1)');
        try {
            (new WordPressDatabase($adapter, new PrivacyPolicy(['version' => 2, 'preset' => 'full'])))->withConsistentSnapshot(static function (): void {});
            self::fail('An existing transaction must be rejected.');
        } catch (RuntimeException $error) {
            self::assertSame('0', $observer->get_var('SELECT COUNT(*) FROM snapshot_record'));
        } finally { $adapter->query('ROLLBACK'); }
        self::assertSame('0', $observer->get_var('SELECT COUNT(*) FROM snapshot_record'));
    }

    public function testSnapshotClearsVerificationWhenSessionRestoreFails(): void
    {
        $adapter = $this->adapter($this->server->newSchema());
        $adapter->executeBatch('CREATE TABLE snapshot_record(id INT PRIMARY KEY) ENGINE=InnoDB; INSERT INTO snapshot_record VALUES(1)');
        $proxy = new SnapshotFailRestoreAdapter($adapter);
        $source = new WordPressDatabase($proxy, new PrivacyPolicy(['version' => 2, 'preset' => 'full']));
        try {
            $source->withConsistentSnapshot(static function (WordPressDatabase $database) use ($proxy): void {
                foreach ($database->records() as $record) { if ($record['type'] === 'sql') { foreach ($record['chunks'] as $ignored) {} } }
                $proxy->failRestore = true;
            });
            self::fail('Session restoration must fail the capture.');
        } catch (RuntimeException $error) { self::assertNotSame('', $error->getMessage()); }
        $this->expectException(RuntimeException::class);
        $source->verification();
    }

    public function testStoredDefinitionsAreRejectedBeforeReadingTheirData(): void
    {
        $schema = $this->server->newSchema();
        $adapter = $this->adapter($schema);
        $adapter->executeBatch('CREATE TABLE original_record(id INT PRIMARY KEY); CREATE VIEW not_supported AS SELECT id FROM original_record;');
        $this->expectException(RuntimeException::class);
        (new WordPressDatabase($adapter, new PrivacyPolicy(['version' => 2, 'preset' => 'full'])))->preflight();
    }
}

final class V4DatabaseMysqliAdapter
{
    public \mysqli $dbh;
    public string $prefix = 'wp_';
    public string $last_error = '';
    public $reconnect = null;

    public function __construct(\mysqli $connection) { $this->dbh = $connection; }

    public function get_results(string $sql, string $format): array
    {
        try { $result = mysqli_query($this->dbh, $sql); }
        catch (\mysqli_sql_exception $error) {
            if (!is_callable($this->reconnect)) { throw $error; }
            $result = false;
        }
        if ($result === false && in_array(mysqli_errno($this->dbh), [2006, 2013], true) && is_callable($this->reconnect)) {
            $reconnect = $this->reconnect; $this->reconnect = null;
            $this->dbh = $reconnect();
            $result = mysqli_query($this->dbh, $sql);
        }
        if (!($result instanceof \mysqli_result)) { throw new RuntimeException('The isolated fixture metadata query failed.'); }
        try { return mysqli_fetch_all($result, MYSQLI_ASSOC); }
        finally { mysqli_free_result($result); }
    }

    public function get_var(string $sql)
    {
        $rows = $this->get_results($sql, 'ARRAY_A');
        return $rows === [] ? null : array_values($rows[0])[0];
    }

    public function query(string $sql) { return mysqli_query($this->dbh, $sql); }

    public function executeBatch(string $sql): void
    {
        if (!mysqli_multi_query($this->dbh, $sql)) { throw new RuntimeException('The isolated fixture SQL could not be applied.'); }
        do {
            $result = mysqli_store_result($this->dbh);
            if ($result instanceof \mysqli_result) { mysqli_free_result($result); }
            if (!mysqli_more_results($this->dbh)) { break; }
        } while (mysqli_next_result($this->dbh));
        if (mysqli_errno($this->dbh) !== 0) { throw new RuntimeException('The isolated fixture SQL stream failed.'); }
    }
}

final class SnapshotFailRestoreAdapter
{
    public $dbh; public string $prefix = 'wp_'; public string $last_error = ''; public bool $failRestore = false;
    private V4DatabaseMysqliAdapter $inner;
    public function __construct(V4DatabaseMysqliAdapter $inner) { $this->inner = $inner; $this->dbh = $inner->dbh; }
    public function get_results(string $sql, string $format): array { return $this->inner->get_results($sql, $format); }
    public function get_var(string $sql) { return $this->inner->get_var($sql); }
    public function query(string $sql) {
        if ($this->failRestore && strpos($sql, 'SET SESSION time_zone=') === 0) { throw new RuntimeException('fixture restore failure'); }
        return $this->inner->query($sql);
    }
}
