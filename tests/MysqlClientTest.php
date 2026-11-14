<?php

declare(strict_types=1);

namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use WordPressR2Backup\MysqlClient;

final class MysqlClientTest extends TestCase
{
    private IsolatedMysql $server;

    protected function setUp(): void
    {
        $this->server = IsolatedMysql::start();
    }

    protected function tearDown(): void
    {
        if (isset($this->server)) {
            $this->server->stop();
        }
    }

    public function testAllTablesBinaryJapaneseAndDatabaseObjectsRoundTrip(): void
    {
        $definer = $this->server->sharedImporter(); // objects owned by the importer itself; a foreign DEFINER is an explicit compatibility limit
        $source = $this->server->newSchema();
        $this->server->execute($source, strtr(<<<'SQL'
CREATE TABLE wp_posts (id INT PRIMARY KEY, title TEXT NOT NULL);
CREATE TABLE wp_options (name VARCHAR(50) PRIMARY KEY, value TEXT NOT NULL);
CREATE TABLE custom_records (id INT PRIMARY KEY, payload VARBINARY(100));
INSERT INTO wp_posts VALUES (1, '日本語の投稿');
INSERT INTO wp_options VALUES ('site', '合成サイト');
INSERT INTO custom_records VALUES (1, X'000A0D5CFF');
CREATE TRIGGER record_post AFTER INSERT ON wp_posts FOR EACH ROW INSERT INTO custom_records VALUES (NEW.id, X'FF00');
CREATE PROCEDURE synthetic_value() SELECT '手続き';
CREATE EVENT synthetic_event ON SCHEDULE AT CURRENT_TIMESTAMP + INTERVAL 1 YEAR DO INSERT INTO wp_options VALUES ('event', 'disabled');
SQL, ['CREATE TRIGGER' => "CREATE DEFINER=$definer TRIGGER", 'CREATE PROCEDURE' => "CREATE DEFINER=$definer PROCEDURE", 'CREATE EVENT' => "CREATE DEFINER=$definer EVENT"]));
        $dump = $this->server->workspace() . '/database.sql';
        $this->server->client()->dump($source, $dump);
        $this->server->dropSchema($source);
        $target = $this->server->newSchema();
        $this->server->client()->importIntoEmpty($target, $dump);

        self::assertSame([['custom_records'], ['wp_options'], ['wp_posts']], $this->server->rows($target, 'SHOW TABLES'));
        self::assertSame([['1', '日本語の投稿']], $this->server->rows($target, 'SELECT * FROM wp_posts'));
        self::assertSame([['site', '合成サイト']], $this->server->rows($target, 'SELECT * FROM wp_options'));
        self::assertSame([['000A0D5CFF']], $this->server->rows($target, 'SELECT HEX(payload) FROM custom_records'));
        $this->server->execute($target, "INSERT INTO wp_posts VALUES (2, 'トリガー確認')");
        self::assertSame([['FF00']], $this->server->rows($target, 'SELECT HEX(payload) FROM custom_records WHERE id=2'));
        self::assertSame([['手続き']], $this->server->rows($target, 'CALL synthetic_value()'));
        self::assertSame([['synthetic_event']], $this->server->rows($target, 'SELECT EVENT_NAME FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE()'));
    }

    public function testServerHasNoNetworkListenerAndCannotRunScheduledEvents(): void
    {
        $schema = $this->server->newSchema();
        self::assertSame([['1', 'DISABLED']], $this->server->rows($schema, 'SELECT @@skip_networking, @@event_scheduler'));
        self::assertSame([], $this->server->rows($schema, "SELECT PLUGIN_NAME FROM information_schema.PLUGINS WHERE PLUGIN_NAME='mysqlx' AND PLUGIN_STATUS='ACTIVE'"));
        self::assertSame($this->server->workspace(), realpath($this->server->workspace()));
        self::assertSame(0700, fileperms($this->server->workspace()) & 0777);
        self::assertSame(0600, fileperms($this->server->defaultsFile()) & 0777);
    }

    public function testAnExplicitOperationLimitAllowsASlowerImportOnlyIntoAFreshTarget(): void
    {
        $bin = IsolatedMysql::mysqlBin();
        $client = function (int $seconds) use ($bin): MysqlClient {
            return new MysqlClient($bin . 'mysql', $bin . 'mysqldump', $this->server->defaultsFile(), $this->server->workspace(), $seconds);
        };
        $file = $this->server->workspace() . '/slow-import.sql';
        file_put_contents($file, 'CREATE TABLE slow_probe(id INT); DO SLEEP(2); INSERT INTO slow_probe VALUES(7);');
        $failed = $this->server->newSchema();
        try { $client(1)->importIntoEmpty($failed, $file, []); self::fail('The short operation deadline must stop the import.'); }
        catch (RuntimeException $error) { self::assertStringContainsString('timed out', $error->getMessage()); }
        try { $client(5)->importIntoEmpty($failed, $file, []); self::fail('Changing the deadline must not allow a failed target to be reused.'); }
        catch (RuntimeException $error) { self::assertTrue(true); }
        $fresh = $this->server->newSchema(); $client(5)->importIntoEmpty($fresh, $file, []);
        self::assertSame([['7']], $this->server->rows($fresh, 'SELECT id FROM slow_probe'));
    }

    public function testRefusesNonemptyTargetAndLeavesExistingData(): void
    {
        $source = $this->server->newSchema();
        $target = $this->server->newSchema();
        $this->server->execute($source, 'CREATE TABLE source_data (id INT)');
        $this->server->execute($target, 'CREATE TABLE existing_data (id INT); INSERT INTO existing_data VALUES (7)');
        $dump = $this->server->workspace() . '/database.sql';
        $this->server->client()->dump($source, $dump);
        try {
            $this->server->client()->importIntoEmpty($target, $dump);
            self::fail('A nonempty target must be refused.');
        } catch (RuntimeException $ignoredError) {
            self::assertSame([['7']], $this->server->rows($target, 'SELECT * FROM existing_data'));
        }
    }

    public function testRefusesUnsafeDefaultsAndSyntheticSchemaEscape(): void
    {
        $defaults = $this->server->defaultsFile();
        $original = file_get_contents($defaults);
        foreach ([$original . "!include /tmp/other.cnf\n", $original . "host=127.0.0.1\n", $original . "port=3306\n", "[client]\nuser=root\nsocket=/tmp/unowned.sock\n", $original . "[mysqldump]\nuser=root\n"] as $unsafe) {
            file_put_contents($defaults, $unsafe);
            try {
                $this->server->client()->dump('wrb_test_does_not_exist', $this->server->workspace() . '/rejected.sql');
                self::fail('Unsafe connection settings must be refused.');
            } catch (RuntimeException $ignoredError) {
                self::assertFileDoesNotExist($this->server->workspace() . '/rejected.sql');
            }
        }
        file_put_contents($defaults, $original);
        $this->expectException(RuntimeException::class);
        $this->server->client()->dump('mysql', $this->server->workspace() . '/rejected.sql');
    }

    public function testRevalidatesDefaultsAndRejectsSocketLink(): void
    {
        $client = $this->server->client();
        file_put_contents($this->server->defaultsFile(), "!include /tmp/unowned.cnf\n");
        try {
            $client->dump('wrb_test_missing', $this->server->workspace() . '/rejected.sql');
            self::fail('Changed defaults must be checked before execution.');
        } catch (RuntimeException $ignoredError) {
            self::assertFileDoesNotExist($this->server->workspace() . '/rejected.sql');
        }
        $link = $this->server->workspace() . '/linked.sock';
        symlink($this->server->workspace() . '/mysql.sock', $link);
        file_put_contents($this->server->defaultsFile(), "[client]\nuser=root\nsocket=\"$link\"\n");
        $this->expectException(RuntimeException::class);
        $this->server->client();
    }

    public function testDumpFailurePublishesNoPartialFileAndImportFailureIsReported(): void
    {
        $output = $this->server->workspace() . '/failed.sql';
        try {
            $this->server->client()->dump('wrb_test_missing', $output);
            self::fail('A missing database must fail.');
        } catch (RuntimeException $ignoredError) {
            self::assertFileDoesNotExist($output);
        }
        file_put_contents($output, 'CREATE TABLE partial_data (id INT); INVALID SQL;');
        $target = $this->server->newSchema();
        $this->expectException(RuntimeException::class);
        $this->server->client()->importIntoEmpty($target, $output);
    }

    public function testIncompleteDumpCannotBePublishedEvenIfTheCommandReportsSuccess(): void
    {
        $source = $this->server->newSchema();
        $this->server->execute($source, 'CREATE TABLE expected_table (id INT)');
        $script = $this->server->workspace() . '/dump command.php';
        foreach ([0, 7] as $exit) {
            file_put_contents($script, '#!' . Fixture::phpForShebang() . "\n<?php fwrite(STDOUT, 'partial output'); exit(" . $exit . ');');
            chmod($script, 0700);
            $client = new MysqlClient(
                IsolatedMysql::mysqlBin() . 'mysql',
                $script,
                $this->server->defaultsFile(),
                $this->server->workspace(),
            );
            $destination = $this->server->workspace() . '/partial.sql';
            try {
                $client->dump($source, $destination);
                self::fail('An incomplete dump must not be published.');
            } catch (RuntimeException $ignoredError) {
                self::assertFileDoesNotExist($destination);
                self::assertSame([], glob($this->server->workspace() . '/.wrb-sql-*'));
            }
        }
    }

    public function testImportFailureRequiresAFreshTargetEvenWhenNoTableWasCreated(): void
    {
        $target = $this->server->newSchema();
        $input = $this->server->workspace() . '/invalid.sql';
        file_put_contents($input, 'INVALID SQL;');
        try {
            $this->server->client()->importIntoEmpty($target, $input);
            self::fail('Invalid SQL must fail.');
        } catch (RuntimeException $ignoredError) {
            self::assertSame([], $this->server->rows($target, 'SHOW TABLES'));
        }
        file_put_contents($input, 'CREATE TABLE fresh (id INT);');
        try {
            $this->server->client()->importIntoEmpty($target, $input);
            self::fail('A previously attempted schema must be refused by a new client.');
        } catch (RuntimeException $ignoredError) {
            self::assertSame([], $this->server->rows($target, 'SHOW TABLES'));
        }
        $fresh = $this->server->newSchema();
        $this->server->client()->importIntoEmpty($fresh, $input);
        self::assertSame([['fresh']], $this->server->rows($fresh, 'SHOW TABLES'));
    }
}
