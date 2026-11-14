<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\PrivacyPolicy;
use WordPressR2Backup\WordPressDatabase;

require_once __DIR__ . '/WordPressDatabaseIntegrationTest.php';

/** Capture-only integration test against the source-compatible MariaDB version. */
final class OnlineSnapshotMariaDb105Test extends TestCase
{
    public function testSnapshotAllowsConcurrentUpdate(): void
    {
        if (getenv('WRB_RUN_V4_MYSQL_INTEGRATION') !== '1' || !IsolatedMysql::mariadb105Available()) { self::markTestSkipped('Isolated MariaDB 10.5 is not available.'); }
        // MariaDB 10.5 cannot inspect information_schema.EVENTS with DISABLED; OFF keeps scheduling inactive and permits inspection.
        $server = IsolatedMysql::start(null, 'OFF', 'mariadb105'); $connections = [];
        try {
            $schema = $server->newSchema(); $options = parse_ini_file($server->defaultsFile(), true, INI_SCANNER_RAW);
            foreach ([0, 1] as $i) {
                $db = mysqli_init();
                self::assertTrue(mysqli_real_connect($db, 'localhost', 'root', '', $schema, 0, $options['client']['socket']));
                mysqli_set_charset($db, 'utf8mb4'); $connections[] = $db;
            }
            $reader = new V4DatabaseMysqliAdapter($connections[0]); $writer = new V4DatabaseMysqliAdapter($connections[1]);
            $reader->executeBatch('CREATE TABLE sample(id INT PRIMARY KEY, value INT) ENGINE=InnoDB; INSERT INTO sample VALUES(1,0)');
            foreach (['TRIGGERS' => 'TRIGGER_SCHEMA', 'ROUTINES' => 'ROUTINE_SCHEMA', 'EVENTS' => 'EVENT_SCHEMA'] as $kind => $column) {
                try { $reader->get_var('SELECT COUNT(*) FROM information_schema.' . $kind . ' WHERE ' . $column . '=DATABASE()'); }
                catch (\Throwable $error) { self::fail('Metadata ' . $kind . ' failed: ' . mysqli_error($reader->dbh)); }
            }
            $source = new WordPressDatabase($reader, new PrivacyPolicy(['version' => 2, 'preset' => 'full']));
            $source->withConsistentSnapshot(static function (WordPressDatabase $database) use ($writer): void {
                self::assertTrue($writer->query('UPDATE sample SET value=1 WHERE id=1'));
                $sql = '';
                foreach ($database->records() as $record) { if ($record['type'] === 'sql') { foreach ($record['chunks'] as $chunk) { $sql .= $chunk; } } }
                self::assertStringContainsString('(1,0)', str_replace(' ', '', $sql));
            });
            self::assertSame('1', $reader->get_var('SELECT value FROM sample WHERE id=1'));
        } finally {
            foreach ($connections as $connection) { $connection->close(); }
            $server->stop(); Fixture::cleanup();
        }
    }
}
