<?php

declare(strict_types=1);

namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The import body runs with the importer account's own server privileges. These tests use only disposable owned
 * servers and canary files/schemas created inside the test; they show what the account can and cannot reach.
 */
final class MysqlImporterBoundaryTest extends TestCase
{
    private ?IsolatedMysql $server = null;
    private string $outside = '';

    protected function tearDown(): void
    {
        if ($this->server !== null) { $this->server->stop(); $this->server = null; }
        Fixture::cleanup();
    }

    /** @dataProvider engines */
    public function testMinimalV4PrivilegesImportAnAuthenticV4StyleBody(string $engine): void
    {
        $this->server = IsolatedMysql::start(null, 'DISABLED', $engine);
        $schema = $this->server->newSchema(['CREATE', 'INSERT', 'SELECT', 'REFERENCES']);
        self::assertSame($engine !== 'mysql', str_contains($this->server->rows($schema, 'SELECT VERSION()')[0][0], 'MariaDB'));
        $sql = $this->sql("SET SESSION SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nSET SESSION time_zone='+00:00';\nCREATE TABLE `parent` (`id` bigint NOT NULL, `name` varchar(20) NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB;\n"
            . "CREATE TABLE `child` (`id` bigint NOT NULL, `parent_id` bigint NOT NULL, PRIMARY KEY (`id`), CONSTRAINT `child_parent` FOREIGN KEY (`parent_id`) REFERENCES `parent` (`id`)) ENGINE=InnoDB;\n"
            . "INSERT INTO `parent` (`id`,`name`) VALUES (1,CONVERT(X'E383A6' USING utf8mb4));\nINSERT INTO `child` (`id`,`parent_id`) VALUES (1,1);\n");
        $this->server->client()->importIntoEmpty($schema, $sql, null);
        self::assertSame([['1', 'ユ']], $this->server->rows($schema, 'SELECT id, name FROM parent'));
        self::assertSame([['1']], $this->server->rows($schema, 'SELECT COUNT(*) FROM child'));
    }

    /** @dataProvider engines */
    public function testAFullSchemaImporterCanStillImportALegacyStyleDump(string $engine): void
    {
        $this->server = IsolatedMysql::start(null, 'DISABLED', $engine);
        $schema = $this->server->newSchema();
        $sql = $this->sql("DROP TABLE IF EXISTS `t`;\nCREATE TABLE `t` (`id` int NOT NULL, `v` text, KEY `v` (`v`(10))) ENGINE=InnoDB;\nLOCK TABLES `t` WRITE;\n/*!40000 ALTER TABLE `t` DISABLE KEYS */;\nINSERT INTO `t` VALUES (1,'a');\n/*!40000 ALTER TABLE `t` ENABLE KEYS */;\nUNLOCK TABLES;\n");
        $this->server->client()->importIntoEmpty($schema, $sql, null);
        self::assertSame([['1', 'a']], $this->server->rows($schema, 'SELECT id, v FROM t'));
    }

    /** @dataProvider engines */
    public function testDangerousBodiesCannotReachOutsideTheImporterSchema(string $engine): void
    {
        $this->server = IsolatedMysql::start(null, 'DISABLED', $engine);
        $this->outside = Fixture::temp();
        file_put_contents($this->outside . '/existing-canary.txt', "synthetic existing canary line\n");
        $bodies = [
            'log-file' => "SET GLOBAL general_log_file='" . $this->outside . "/existing-canary.txt';\nSET GLOBAL general_log='ON';\nSELECT 'a\nSYNTHETIC-APPENDED-LINE\nb';",
            'outfile' => "SELECT 'x' INTO OUTFILE '" . $this->outside . "/outfile-canary';",
            'other-schema' => 'CREATE DATABASE wrb_test_canary_other;',
            'create-user' => "CREATE USER 'wrb_canary_user'@'localhost';",
            'set-global' => 'SET GLOBAL max_connections=77;',
            'grant' => "GRANT ALL ON *.* TO CURRENT_USER;",
            'use-other' => 'USE mysql; CREATE TABLE canary_in_system_schema (id INT);',
        ];
        $limit = $this->scalar('SELECT @@GLOBAL.max_connections');
        foreach ($bodies as $name => $body) {
            $schema = $this->server->newSchema();
            try { $this->server->client()->importIntoEmpty($schema, $this->sql($body), null); $accepted = true; }
            catch (RuntimeException $error) { $accepted = false; }
            self::assertFalse($accepted, $name . ' must be refused by the server for a schema-only importer.');
        }
        self::assertSame(['existing-canary.txt'], array_values(array_diff(scandir($this->outside), ['.', '..'])));
        self::assertSame("synthetic existing canary line\n", file_get_contents($this->outside . '/existing-canary.txt'));
        self::assertSame($limit, $this->scalar('SELECT @@GLOBAL.max_connections'));
        self::assertSame('0', $this->scalar("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='wrb_test_canary_other'"));
        self::assertSame('0', $this->scalar("SELECT COUNT(*) FROM mysql.user WHERE User='wrb_canary_user'"));
        self::assertSame('0', $this->scalar("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_NAME='canary_in_system_schema'"));
    }

    /** @dataProvider engines */
    public function testAccountsBeyondTheNewSchemaAreRefusedBeforeAnythingRuns(string $engine): void
    {
        $this->server = IsolatedMysql::start(null, 'DISABLED', $engine);
        $this->outside = Fixture::temp();
        $other = $this->server->newSchema();
        $cases = [
            'global privilege' => static function (string $user, string $schema, string $other): array { return ["GRANT FILE ON *.* TO '$user'@'localhost'"]; },
            'global select' => static function (string $user, string $schema, string $other): array { return ["GRANT SELECT ON *.* TO '$user'@'localhost'"]; },
            'grant option' => static function (string $user, string $schema, string $other): array { return ['GRANT SELECT ON `' . str_replace('_', '\\_', $schema) . "`.* TO '$user'@'localhost' WITH GRANT OPTION"]; },
            'second schema' => static function (string $user, string $schema, string $other): array { return ['GRANT SELECT ON `' . str_replace('_', '\\_', $other) . "`.* TO '$user'@'localhost'"]; },
            'wildcard schema' => static function (string $user, string $schema, string $other): array { return ["REVOKE ALL ON `" . str_replace('_', '\\_', $schema) . "`.* FROM '$user'@'localhost'", "GRANT SELECT, CREATE, INSERT ON `wrb\\_test\\_%`.* TO '$user'@'localhost'"]; },
            'unescaped underscore pattern' => static function (string $user, string $schema, string $other): array { return ["REVOKE ALL ON `" . str_replace('_', '\\_', $schema) . "`.* FROM '$user'@'localhost'", "GRANT SELECT, CREATE, INSERT ON `$schema`.* TO '$user'@'localhost'"]; },
            'table scope' => static function (string $user, string $schema, string $other): array { return ["GRANT SELECT ON `mysql`.`user` TO '$user'@'localhost'"]; },
            'same-name other-host schema grant' => static function (string $user, string $schema, string $other): array { return ["CREATE USER '$user'@'%' IDENTIFIED BY ''", 'GRANT ALL ON `' . str_replace('_', '\\_', $other) . "`.* TO '$user'@'%'"]; },
            'same-name other-host table grant' => static function (string $user, string $schema, string $other): array { return ["CREATE TABLE `$other`.canary_t (id INT)", "CREATE USER '$user'@'%' IDENTIFIED BY ''", "GRANT SELECT ON `$other`.canary_t TO '$user'@'%'"]; },
            'proxy' => static function (string $user, string $schema, string $other): array { return ["GRANT PROXY ON 'wrb_canary_proxied'@'localhost' TO '$user'@'localhost'"]; },
            'inactive role' => static function (string $user, string $schema, string $other): array { return ['CREATE ROLE wrb_canary_role', 'GRANT FILE ON *.* TO wrb_canary_role', "GRANT wrb_canary_role TO '$user'@'localhost'"]; },
        ];
        foreach ($cases as $name => $extra) {
            $schema = $this->server->newSchema();
            $user = $this->server->importerUser($schema);
            foreach ($extra($user, $schema, $other) as $statement) { $this->server->execute($schema, $statement); }
            $body = ($name === 'inactive role' ? 'SET ROLE ALL; ' : '') . "SELECT 'x' INTO OUTFILE '" . $this->outside . "/" . bin2hex($name) . "'; CREATE TABLE must_not_exist (id INT);";
            try { $this->server->client()->importIntoEmpty($schema, $this->sql($body), null); self::fail($name . ' must be refused.'); }
            catch (RuntimeException $error) { self::assertStringContainsString('not limited to the new schema', $error->getMessage(), $name); }
            self::assertFileDoesNotExist($this->server->workspace() . '/.wrb-import-' . $schema, $name);
            self::assertSame([], $this->server->rows($schema, 'SHOW TABLES'), $name);
            self::assertSame([], array_values(array_diff(scandir($this->outside), ['.', '..'])), $name);
        }
    }

    /** @dataProvider engines */
    public function testTheAdministrativeAccountAndAMissingImporterAreRefused(string $engine): void
    {
        $this->server = IsolatedMysql::start(null, 'DISABLED', $engine);
        $schema = $this->server->newSchema();
        $workspace = $this->server->workspace();
        $file = $workspace . '/importer-' . $schema . '.cnf';
        $original = file_get_contents($file);
        file_put_contents($file, "[client]\nuser=root\nsocket=\"" . $workspace . "/mysql.sock\"\n");
        try { $this->server->client()->importIntoEmpty($schema, $this->sql('CREATE TABLE t (id INT);'), null); self::fail('The administrative account must be refused.'); }
        catch (RuntimeException $error) { self::assertStringContainsString('must differ', $error->getMessage()); }
        unlink($file);
        try { $this->server->client()->importIntoEmpty($schema, $this->sql('CREATE TABLE t (id INT);'), null); self::fail('A missing importer must be refused.'); }
        catch (RuntimeException $error) { self::assertStringContainsString('dedicated importer', $error->getMessage()); }
        self::assertFileDoesNotExist($workspace . '/.wrb-import-' . $schema);
        file_put_contents($file, $original); chmod($file, 0600);
        $this->server->client()->importIntoEmpty($schema, $this->sql('CREATE TABLE t (id INT);'), null);
        self::assertSame([['t']], $this->server->rows($schema, 'SHOW TABLES'));
    }

    /** @dataProvider engines */
    public function testObjectsOwnedByAnotherDefinerFailAsAnExplicitCompatibilityLimit(string $engine): void
    {
        $this->server = IsolatedMysql::start(null, 'DISABLED', $engine);
        $schema = $this->server->newSchema();
        $this->server->client()->importIntoEmpty($schema, $this->sql("CREATE TABLE t (id INT);\nCREATE VIEW v AS SELECT id FROM t;\nCREATE PROCEDURE p() SELECT 1;\n"), null);
        self::assertSame([['t'], ['v']], $this->server->rows($schema, 'SHOW TABLES'));
        foreach (["CREATE DEFINER=`root`@`localhost` VIEW v AS SELECT 1 AS id;", "CREATE TABLE t (id INT);\nCREATE DEFINER=`root`@`localhost` TRIGGER trg BEFORE INSERT ON t FOR EACH ROW SET NEW.id = 1;", "CREATE DEFINER=`root`@`localhost` PROCEDURE p() SELECT 1;"] as $body) {
            $target = $this->server->newSchema();
            $failed = false;
            try { $this->server->client()->importIntoEmpty($target, $this->sql($body), null); }
            catch (RuntimeException $error) { $failed = str_contains($error->getMessage(), 'fresh target'); }
            self::assertTrue($failed, $body);
            self::assertSame([], $this->server->rows($target, 'SHOW PROCEDURE STATUS WHERE Db=DATABASE()'));
        }
    }

    /** @dataProvider engines */
    public function testAnAnonymousAccountMakesTheImporterUnprovableOnThePreparationSide(string $engine): void
    {
        $this->server = IsolatedMysql::start(null, 'DISABLED', $engine);
        $schema = $this->server->newSchema();
        $this->server->execute($schema, "CREATE USER ''@'localhost'");
        $stopped = false;
        try { $this->server->newSchema(); }
        catch (RuntimeException $error) { $stopped = str_contains($error->getMessage(), 'anonymous'); }
        self::assertTrue($stopped, 'Preparation must stop while an anonymous account exists.');
    }

    public function testPartialRevokesTakeTheSchemaNameLiterallyAndNeverAsAPattern(): void
    {
        $this->server = IsolatedMysql::start(null, 'DISABLED', 'mysql', ['--partial-revokes=ON']);
        $schema = $this->server->newSchema();
        self::assertSame([['1']], $this->server->rows($schema, 'SELECT @@GLOBAL.partial_revokes'));
        $this->server->client()->importIntoEmpty($schema, $this->sql('CREATE TABLE t (id INT);'), null);
        self::assertSame([['t']], $this->server->rows($schema, 'SHOW TABLES'));
        $refused = $this->server->newSchema([]);
        $user = $this->server->importerUser($refused);
        $this->server->execute($refused, "GRANT SELECT, CREATE ON `wrb\\_test\\_%`.* TO '$user'@'localhost'");
        $rejected = false;
        try { $this->server->client()->importIntoEmpty($refused, $this->sql('CREATE TABLE t (id INT);'), null); }
        catch (RuntimeException $error) { $rejected = str_contains($error->getMessage(), 'not limited to the new schema'); }
        self::assertTrue($rejected);
    }

    public function engines(): array
    {
        return array_values(array_filter([['mysql'], ['mariadb'], ['mariadb105']], static function (array $engine): bool { return IsolatedMysql::available($engine[0]); }));
    }

    private function sql(string $contents): string
    {
        $path = $this->server->workspace() . '/input-' . bin2hex(random_bytes(5)) . '.sql';
        file_put_contents($path, $contents);
        chmod($path, 0600);
        return $path;
    }

    private function scalar(string $query): string
    {
        $schema = $this->server->newSchema();
        return $this->server->rows($schema, $query)[0][0];
    }
}
