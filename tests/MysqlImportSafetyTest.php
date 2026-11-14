<?php

declare(strict_types=1);

namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use WordPressR2Backup\MysqlClient;

final class MysqlImportSafetyTest extends TestCase
{
    private ?IsolatedMysql $server = null;

    protected function tearDown(): void
    {
        if ($this->server !== null) { $this->server->stop(); }
    }

    public function testMutableSchedulerStatesAreRejectedBeforeImport(): void
    {
        foreach (['OFF', 'ON'] as $scheduler) {
            $this->server = IsolatedMysql::start(null, $scheduler);
            $target = $this->server->newSchema();
            $sql = $this->sql('CREATE TABLE must_not_exist(id INT);');
            $error = $this->failure(fn () => $this->server->client()->importIntoEmpty($target, $sql));
            self::assertStringContainsString('DISABLED', $error->getMessage());
            self::assertSame([], $this->server->rows($target, 'SHOW TABLES'));
            self::assertFileDoesNotExist($this->server->workspace() . '/.wrb-import-' . $target);
            self::assertSame([[$scheduler]], $this->server->rows($target, 'SELECT @@GLOBAL.event_scheduler'));
            $this->server->stop();
            $this->server = null;
        }
    }

    public function testDisabledSchedulerPreservesPeriodicEventWithoutExecutingIt(): void
    {
        $this->server = IsolatedMysql::start();
        $target = $this->server->newSchema();
        $sql = $this->sql(<<<'SQL'
CREATE TABLE event_effects(id INT);
CREATE EVENT `周期イベント` ON SCHEDULE EVERY 1 SECOND STARTS CURRENT_TIMESTAMP DO INSERT INTO event_effects VALUES(1);
SQL);
        $before = hash_file('sha256', $sql);
        $this->server->client()->importIntoEmpty($target, $sql, ['周期イベント']);
        self::assertSame([['DISABLED']], $this->server->rows($target, 'SELECT @@GLOBAL.event_scheduler'));
        self::assertSame([['周期イベント']], $this->server->rows($target, 'SELECT EVENT_NAME FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE()'));
        $this->server->rows($target, 'SELECT SLEEP(1.1)');
        self::assertSame([['0']], $this->server->rows($target, 'SELECT COUNT(*) FROM event_effects'));
        self::assertSame($before, hash_file('sha256', $sql));
    }

    public function testLegacyPastEventWithoutSqlNotesCannotReturnSuccessOrRetry(): void
    {
        $this->server = IsolatedMysql::start();
        $target = $this->server->newSchema();
        // Owned by the importer itself, so the server accepts it and the post-import event audit is what must refuse the result.
        $definer = '`' . $this->server->importerUser($target) . '`@`localhost`';
        $sql = $this->sql(strtr(<<<'SQL'
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DELIMITER ;;
/*!50106 CREATE*/ /*!50117 DEFINER=@DEFINER@*/ /*!50106 EVENT `expired_event` ON SCHEDULE AT '2000-01-01 00:00:00' ON COMPLETION NOT PRESERVE ENABLE DO SELECT 1 */;;
DELIMITER ;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;
SQL, ['@DEFINER@' => $definer]));
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $error = $this->failure(fn () => $this->server->client()->importIntoEmpty($target, $sql));
            self::assertStringContainsString($attempt === 0 ? 'event' : 'fresh', strtolower($error->getMessage()));
            self::assertFileExists($this->server->workspace() . '/.wrb-import-' . $target);
            self::assertSame([], $this->server->rows($target, 'SHOW TABLES'));
            self::assertSame([], $this->server->rows($target, 'SELECT EVENT_NAME FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE()'));
        }
    }

    public function testPlainLegacySqlWithNoEventsStillImports(): void
    {
        $this->server = IsolatedMysql::start();
        foreach ([';', ''] as $terminator) {
            $target = $this->server->newSchema();
            $sql = $this->sql("CREATE TABLE legacy_data(id INT); INSERT INTO legacy_data VALUES(7)" . $terminator);
            $before = hash_file('sha256', $sql);
            $this->server->client()->importIntoEmpty($target, $sql);
            self::assertSame([['7']], $this->server->rows($target, 'SELECT * FROM legacy_data'));
            self::assertSame($before, hash_file('sha256', $sql));
        }
    }

    public function testExpectedEventNamesMustMatchAndInvalidNamesDoNotStartAnImport(): void
    {
        $this->server = IsolatedMysql::start();
        $sql = $this->sql('CREATE EVENT actual_event ON SCHEDULE EVERY 1 DAY DO SELECT 1;');
        foreach ([[''], ["bad\0name"], ['same', 'same'], [1], ['key' => 'name']] as $invalid) {
            $target = $this->server->newSchema();
            $this->failure(fn () => $this->server->client()->importIntoEmpty($target, $sql, $invalid));
            self::assertFileDoesNotExist($this->server->workspace() . '/.wrb-import-' . $target);
            self::assertSame([], $this->server->rows($target, 'SELECT EVENT_NAME FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE()'));
        }
        $target = $this->server->newSchema();
        $error = $this->failure(fn () => $this->server->client()->importIntoEmpty($target, $sql, ['different_event']));
        self::assertStringContainsString('event', strtolower($error->getMessage()));
        self::assertFileExists($this->server->workspace() . '/.wrb-import-' . $target);
        self::assertSame([['actual_event']], $this->server->rows($target, 'SELECT EVENT_NAME FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE()'));
    }

    public function testSuccessfulClientExitWithoutAuditOutputIsRefused(): void
    {
        $this->server = IsolatedMysql::start();
        $target = $this->server->newSchema();
        $wrapper = $this->server->workspace() . '/mysql-no-audit';
        file_put_contents($wrapper, "#!/bin/sh\nfor arg in \"\$@\"; do\n  if [ \"\$arg\" = '--raw' ]; then\n    cat >/dev/null\n    exit 0\n  fi\ndone\nexec " . escapeshellarg(IsolatedMysql::mysqlBin() . 'mysql') . " \"\$@\"\n");
        chmod($wrapper, 0700);
        $client = new MysqlClient($wrapper, IsolatedMysql::mysqlBin() . 'mysqldump', $this->server->defaultsFile(), $this->server->workspace());
        $error = $this->failure(fn () => $client->importIntoEmpty($target, $this->sql('CREATE TABLE missing_audit(id INT);')));
        self::assertStringContainsString('audit', strtolower($error->getMessage()));
        self::assertFileExists($this->server->workspace() . '/.wrb-import-' . $target);
    }

    private function failure(callable $operation): RuntimeException
    {
        $failure = null;
        try { $operation(); } catch (RuntimeException $error) { $failure = $error; }
        self::assertInstanceOf(RuntimeException::class, $failure, 'The import must be refused.');
        return $failure;
    }

    private function sql(string $contents): string
    {
        $path = $this->server->workspace() . '/input-' . bin2hex(random_bytes(5)) . '.sql';
        file_put_contents($path, $contents);
        chmod($path, 0600);
        return $path;
    }
}
