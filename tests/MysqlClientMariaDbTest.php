<?php
declare(strict_types=1);

namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use WordPressR2Backup\MysqlClient;

final class MysqlClientMariaDbTest extends TestCase
{
    private MysqlClient $client;
    private array $command;
    private string $workspace;
    private string $schema;
    private string $directory;
    private string $importer = '';

    protected function setUp(): void
    {
        $workspace = getenv('WRB_MARIADB_TEST_WORKSPACE');
        $binaryDirectory = getenv('WRB_MARIADB_TEST_BIN');
        if (!$workspace || !$binaryDirectory) { $this->markTestSkipped('An owned MariaDB fixture requires WRB_MARIADB_TEST_WORKSPACE and WRB_MARIADB_TEST_BIN.'); }
        $this->workspace = $workspace;
        $this->client = new MysqlClient($binaryDirectory . '/mariadb', $binaryDirectory . '/mariadb-dump', $workspace . '/client.cnf', $workspace);
        $this->command = [$binaryDirectory . '/mariadb', '--defaults-file=' . $workspace . '/client.cnf', '--protocol=SOCKET', '--socket=' . $workspace . '/mysql.sock', '--batch', '--skip-column-names', '--raw'];
        self::assertStringContainsString('MariaDB', $this->query('SELECT VERSION()'));
        self::assertSame('DISABLED', $this->query('SELECT @@GLOBAL.event_scheduler'));
        $this->schema = 'wrb_test_mdb_' . bin2hex(random_bytes(6));
        $this->query('CREATE DATABASE `' . $this->schema . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->directory = $workspace . '/' . $this->schema;
        self::assertTrue(mkdir($this->directory, 0700));
        // A dedicated importer for this one schema; the preparation side proves no anonymous or same-name account exists.
        self::assertSame('0', $this->query("SELECT COUNT(*) FROM mysql.user WHERE User=''"));
        $this->importer = 'wrbi_' . bin2hex(random_bytes(6));
        $this->query("CREATE USER '" . $this->importer . "'@'localhost' IDENTIFIED BY ''");
        self::assertSame('1', $this->query("SELECT COUNT(*) FROM mysql.user WHERE User='" . $this->importer . "'"));
        $this->query('GRANT ' . implode(', ', IsolatedMysql::IMPORTER_PRIVILEGES) . ' ON `' . str_replace('_', '\\_', $this->schema) . "`.* TO '" . $this->importer . "'@'localhost'");
        file_put_contents($workspace . '/importer-' . $this->schema . '.cnf', "[client]\nuser=" . $this->importer . "\nsocket=\"" . $workspace . "/mysql.sock\"\n");
        chmod($workspace . '/importer-' . $this->schema . '.cnf', 0600);
    }

    protected function tearDown(): void
    {
        if (isset($this->schema)) {
            $this->query("DELETE FROM mysql.event WHERE db='" . $this->schema . "'");
            $this->query('DROP DATABASE `' . $this->schema . '`');
        }
        if (isset($this->directory) && is_dir($this->directory)) {
            foreach (glob($this->directory . '/*') as $file) { unlink($file); }
            rmdir($this->directory);
        }
        if (isset($this->schema)) {
            $marker = $this->workspace . '/.wrb-import-' . $this->schema;
            if (is_file($marker)) { unlink($marker); }
            $importerSettings = $this->workspace . '/importer-' . $this->schema . '.cnf';
            if (is_file($importerSettings)) { unlink($importerSettings); }
            if ($this->importer !== '') { $this->query("DROP USER '" . $this->importer . "'@'localhost'"); }
        }
    }

    public function testImportsIntoEmptyMariaDbWithDisabledScheduler(): void
    {
        $path = $this->sql("CREATE TABLE sample (id INT PRIMARY KEY, payload VARBINARY(16), note TEXT); INSERT INTO sample VALUES (1,X'00FF5C0A',NULL);");
        $this->client->importIntoEmpty($this->schema, $path, []);
        self::assertSame("1\t00FF5C0A\t1", $this->query('SELECT id,HEX(payload),note IS NULL FROM `' . $this->schema . '`.sample'));
        self::assertSame('DISABLED', $this->query('SELECT @@GLOBAL.event_scheduler'));
    }

    public function testRejectsTargetContainingOnlyAnEvent(): void
    {
        $this->query("INSERT INTO mysql.event (db,name,body,definer,originator,status,time_zone) VALUES ('" . $this->schema . "','existing_event','SET @wrb_mdb_probe=1','root@localhost',0,'DISABLED','+00:00')");
        $path = $this->sql('CREATE TABLE must_not_exist (id INT);');
        try {
            $this->client->importIntoEmpty($this->schema, $path, []);
            self::fail('A target containing an event must be rejected.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('new empty synthetic schema', $error->getMessage());
        }
        self::assertSame('0', $this->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='" . $this->schema . "'"));
        self::assertFileDoesNotExist($this->workspace . '/.wrb-import-' . $this->schema);
    }

    public function testFailedEventImportRequiresFreshTarget(): void
    {
        $path = $this->sql('CREATE EVENT unexpected_event ON SCHEDULE AT CURRENT_TIMESTAMP + INTERVAL 1 YEAR DO SET @wrb_mdb_probe=1;');
        try {
            $this->client->importIntoEmpty($this->schema, $path, []);
            self::fail('An unexpected imported event must be rejected.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('fresh target', $error->getMessage());
        }
        self::assertFileExists($this->workspace . '/.wrb-import-' . $this->schema);
        self::assertSame('DISABLED', $this->query('SELECT @@GLOBAL.event_scheduler'));
        try {
            $this->client->importIntoEmpty($this->schema, $path, []);
            self::fail('A failed import must not be repeated into the same target.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('previously attempted import', $error->getMessage());
        }
    }

    public function testRejectsExpectedMariaDbEventsBeforeImport(): void
    {
        $path = $this->sql('CREATE EVENT expected_event ON SCHEDULE AT CURRENT_TIMESTAMP + INTERVAL 1 YEAR DO SET @wrb_mdb_probe=1;');
        try {
            $this->client->importIntoEmpty($this->schema, $path, ['expected_event']);
            self::fail('Unsupported MariaDB event import must fail before importing.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('MariaDB cannot import events', $error->getMessage());
        }
        self::assertFileDoesNotExist($this->workspace . '/.wrb-import-' . $this->schema);
        self::assertSame('DISABLED', $this->query('SELECT @@GLOBAL.event_scheduler'));
    }

    private function sql(string $contents): string
    {
        $path = $this->directory . '/import.sql';
        file_put_contents($path, $contents);
        chmod($path, 0600);
        return $path;
    }

    private function query(string $sql): string
    {
        $process = proc_open(array_merge($this->command, ['-e', $sql]), [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['MYSQL_TEST_LOGIN_FILE' => $this->workspace . '/absent-login.cnf']);
        if (!is_resource($process)) { throw new RuntimeException('Cannot start fixture query.'); }
        $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0) { throw new RuntimeException('Fixture query failed: ' . $errors); }
        return rtrim($output, "\r\n");
    }
}
