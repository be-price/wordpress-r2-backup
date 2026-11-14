<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use WordPressR2Backup\MysqlRowCursor;

/**
 * The streamed row reader against isolated MySQL and MariaDB servers with synthetic rows. No capture or restore runs.
 * @group integration
 */
final class MysqlRowCursorTest extends TestCase
{
    private static array $servers = [];
    private array $connections = [];
    /** The report mode under test; helpers that open their own sessions set it back afterwards. */
    private int $report = MYSQLI_REPORT_OFF;

    public static function tearDownAfterClass(): void
    {
        foreach (self::$servers as $server) { $server->stop(); }
        self::$servers = [];
        Fixture::cleanup();
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $connection) { try { $connection->close(); } catch (\Throwable $ignored) {} }
        $this->connections = [];
    }

    /** @dataProvider servers */
    public function testRowsMatchTheServerCursorForEveryKindOfValue(string $engine, int $report): void
    {
        $connection = $this->connect($engine, $report);
        $this->createKinds($connection);
        $queries = ['SELECT * FROM kinds ORDER BY id',
            "SELECT 1, 1 AS dup, 2 AS dup, CAST(x AS BINARY) AS bin, SHA2(CONCAT_WS(0x00, IFNULL(HEX(l), 'N'), IFNULL(HEX(v), 'N')), 256) AS digest FROM kinds ORDER BY (id IS NOT NULL), CAST(id AS BINARY)",
            'SELECT id FROM kinds WHERE id > 99'];
        $cursor = new \ReflectionMethod(MysqlRowCursor::class, 'cursorRows'); $cursor->setAccessible(true);
        foreach ($queries as $sql) {
            $streamed = iterator_to_array(MysqlRowCursor::rows($connection, $sql), false);
            $reference = iterator_to_array($cursor->invoke(null, $connection, $sql), false);
            foreach ($streamed as $row) { foreach ($row as $value) { self::assertTrue($value === null || is_string($value)); } }
            if (strpos($sql, 'SELECT *') === 0) {
                // The binary protocol renders FLOAT and DOUBLE through PHP's precision (0.30000000000000004 became 0.3);
                // the text protocol gives the server's own text, checked below.
                foreach (array_keys($streamed) as $index) { unset($streamed[$index]['f'], $streamed[$index]['r'], $reference[$index]['f'], $reference[$index]['r']); }
            }
            self::assertSame($reference, $streamed, $sql);
        }
        self::assertSame([['f' => '1e20'], ['f' => '0.30000000000000004']], iterator_to_array(MysqlRowCursor::rows($connection, 'SELECT f FROM kinds WHERE id IN (1, 4) ORDER BY id'), false), 'floating-point values arrive as the server writes them');
    }

    /** @dataProvider servers */
    public function testAPauseLongerThanTheServerWriteTimeoutKeepsTheConnectionLockAndSessionValue(string $engine, int $report): void
    {
        $connection = $this->connect($engine, $report);
        $this->exec($connection, 'CREATE TABLE big(id INT PRIMARY KEY, payload LONGBLOB)');
        for ($start = 1; $start <= 256; $start += 32) {
            $this->exec($connection, 'INSERT INTO big SELECT n, REPEAT(X\'00FF27\', 32768) FROM (' . implode(' UNION ALL ', array_map(static fn (int $n): string => 'SELECT ' . $n . ' AS n', range($start, $start + 31))) . ') AS numbers');
        }
        $schema = $this->value($connection, 'SELECT DATABASE()');
        $this->exec($connection, 'SET SESSION net_write_timeout=1');
        $this->exec($connection, 'LOCK TABLES big READ');
        $id = $this->value($connection, 'SELECT CONNECTION_ID()');
        $rows = 0; $heldDuringPause = null;
        foreach (MysqlRowCursor::rows($connection, 'SELECT id, payload FROM big ORDER BY id') as $row) {
            if (++$rows === 1) { sleep(3); $heldDuringPause = $this->writeBlocked($engine, $schema); }
            self::assertSame(str_repeat("\x00\xff'", 32768), $row['payload']);
        }
        self::assertSame(256, $rows, 'every row arrives after a pause three times the server write timeout');
        self::assertTrue($heldDuringPause, 'the read lock holds while the consumer pauses');
        self::assertSame($id, $this->value($connection, 'SELECT CONNECTION_ID()'));
        self::assertSame('1', $this->value($connection, 'SELECT @@SESSION.net_write_timeout'), 'the session value is set back');
        self::assertTrue($this->writeBlocked($engine, $schema), 'the read lock still holds after the rows');
        $this->exec($connection, 'UNLOCK TABLES');
    }

    /** @dataProvider servers */
    public function testStoppingEarlyOrFailingSetsTheSessionBackAndLeavesTheConnectionUsable(string $engine, int $report): void
    {
        $connection = $this->connect($engine, $report);
        $this->exec($connection, 'SET SESSION net_write_timeout=7');
        $this->exec($connection, 'CREATE TABLE many(id INT PRIMARY KEY)');
        $this->exec($connection, 'INSERT INTO many VALUES ' . implode(',', array_map(static fn (int $n): string => '(' . $n . ')', range(1, 5000))));
        $cursor = MysqlRowCursor::rows($connection, 'SELECT id FROM many ORDER BY id');
        self::assertSame(['id' => '1'], $cursor->current());
        $whileStreaming = $this->otherSessionTimeout($engine, $connection);
        unset($cursor);
        self::assertSame('7', $this->value($connection, 'SELECT @@SESSION.net_write_timeout'));
        self::assertSame('5000', $this->value($connection, 'SELECT COUNT(*) FROM many'));
        try {
            iterator_to_array(MysqlRowCursor::rows($connection, 'SELECT secret_missing_field FROM secret_missing_table'));
            self::fail('An invalid statement produced rows.');
        } catch (RuntimeException $error) { self::assertStringNotContainsString('secret_missing', $error->getMessage()); }
        self::assertSame('7', $this->value($connection, 'SELECT @@SESSION.net_write_timeout'));
        $consumer = static function () use ($connection): void {
            foreach (MysqlRowCursor::rows($connection, 'SELECT id FROM many ORDER BY id') as $row) { if ($row['id'] === '10') { throw new \LogicException('consumer failed'); } }
        };
        try { $consumer(); self::fail('The consumer failure was lost.'); } catch (\LogicException $expected) { self::assertSame('consumer failed', $expected->getMessage()); }
        self::assertSame('7', $this->value($connection, 'SELECT @@SESSION.net_write_timeout'));
        self::assertSame(['id' => '5000'], iterator_to_array(MysqlRowCursor::rows($connection, 'SELECT MAX(id) AS id FROM many'), false)[0]);
        if ($whileStreaming === null) { self::markTestIncomplete('Not verified: this server does not report another session\'s net_write_timeout, so the raised value while rows stream was not observed.'); }
        self::assertSame((string) MysqlRowCursor::PAUSE_SECONDS, $whileStreaming, 'raised only while rows stream');
    }

    /** @dataProvider servers */
    public function testAStatementSentWhileRowsStreamDoesNotRun(string $engine, int $report): void
    {
        $connection = $this->connect($engine, $report);
        $this->exec($connection, 'CREATE TABLE source(id INT PRIMARY KEY)');
        $this->exec($connection, 'CREATE TABLE marks(id INT PRIMARY KEY)');
        $this->exec($connection, 'INSERT INTO source VALUES (1),(2),(3)');
        $refused = false; $seen = []; $finished = false; $stopped = null;
        try {
            foreach (MysqlRowCursor::rows($connection, 'SELECT id FROM source ORDER BY id') as $row) {
                $seen[] = $row['id'];
                if ($row['id'] === '1') {
                    try { $refused = mysqli_query($connection, 'INSERT INTO marks VALUES (1)') === false; }
                    catch (\mysqli_sql_exception $error) { $refused = true; }
                }
            }
            $finished = true;
        } catch (RuntimeException $error) { $stopped = $error; }
        self::assertTrue($refused, 'the interleaved statement must fail visibly');
        // Under PHP 7.4 the refused statement's error 2014 stays on the connection, so the end-of-rows check stops the reader;
        // PHP 8 clears it on the next fetch and the reader finishes. Either way a reader that ends without an exception has
        // every row, and a reader that stops raises an exception, which the capture treats as a failure.
        if ($finished) { self::assertSame(['1', '2', '3'], $seen); self::assertNull($stopped); }
        else {
            // With mysqli exceptions the same stop surfaces from the fetch instead of the end-of-rows check.
            self::assertInstanceOf(RuntimeException::class, $stopped);
            self::assertContains($stopped->getMessage(), ['The database row cursor ended with an error.', 'Cannot read database row cursor.']);
        }
        self::assertSame('0', $this->value($connection, 'SELECT COUNT(*) FROM marks'), 'the interleaved statement must not run');
    }

    /** @dataProvider servers */
    public function testAStreamCutOffByTheServerNeverEndsAsASuccess(string $engine, int $report): void
    {
        $connection = $this->connect($engine, $report);
        $schema = $this->value($connection, 'SELECT DATABASE()');
        $this->exec($connection, 'CREATE TABLE cut(id INT PRIMARY KEY, payload LONGBLOB)');
        for ($start = 1; $start <= 512; $start += 64) {
            $this->exec($connection, 'INSERT INTO cut SELECT n, REPEAT(X\'5A\', 65536) FROM (' . implode(' UNION ALL ', array_map(static fn (int $n): string => 'SELECT ' . $n . ' AS n', range($start, $start + 63))) . ') AS numbers');
        }
        $id = (int) mysqli_thread_id($connection); $rows = 0; $finished = false; $stopped = null;
        try {
            foreach (MysqlRowCursor::rows($connection, 'SELECT id, payload FROM cut ORDER BY id') as $row) {
                if (++$rows === 1) { $this->killQuery($engine, $schema, $id); }
            }
            $finished = true;
        } catch (RuntimeException $error) { $stopped = $error; }
        self::assertFalse($finished, 'a statement killed after its first of 512 rows must not end as complete (' . $rows . ' rows read)');
        self::assertLessThan(512, $rows);
        self::assertInstanceOf(RuntimeException::class, $stopped);
        self::assertStringNotContainsString('cut', $stopped->getMessage());
    }

    /** @dataProvider servers */
    public function testRowsLargerInTotalThanTheMemoryLimitStreamWithinIt(string $engine, int $report): void
    {
        $connection = $this->connect($engine, $report);
        $schema = $this->value($connection, 'SELECT DATABASE()');
        $this->exec($connection, 'CREATE TABLE wide(id INT PRIMARY KEY, payload LONGBLOB)');
        for ($start = 1; $start <= 1600; $start += 100) {
            $this->exec($connection, 'INSERT INTO wide SELECT n, REPEAT(X\'5A\', 65536) FROM (' . implode(' UNION ALL ', array_map(static fn (int $n): string => 'SELECT ' . $n . ' AS n', range($start, $start + 99))) . ') AS numbers');
        }
        $script = Fixture::temp() . '/low-memory.php';
        file_put_contents($script, '<?php require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ';
            mysqli_report(' . $report . '); $c = new mysqli("localhost", "root", "", ' . var_export($schema, true) . ', 0, ' . var_export(self::socket($engine), true) . ');
            $rows = 0; $bytes = 0;
            foreach (WordPressR2Backup\MysqlRowCursor::rows($c, "SELECT id, payload FROM wide ORDER BY id") as $row) { ++$rows; $bytes += strlen($row["payload"]); }
            echo $rows, " ", $bytes, " ", memory_get_peak_usage(true), "\n";');
        $process = proc_open([PHP_BINARY, '-d', 'memory_limit=24M', $script], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);
        [$rows, $bytes, $peak] = array_map('intval', explode(' ', trim($output)));
        self::assertSame([1600, 1600 * 65536], [$rows, $bytes], '100 MiB of rows stream under a 24 MiB limit');
        self::assertLessThanOrEqual(24 << 20, $peak);
    }

    /** @dataProvider fallbackCases */
    public function testRefusedSessionSettingFallsBackToTheServerCursorWithTheSameRowsAndFailures(string $engine, int $report, string $refusal): void
    {
        $connection = $this->connect($engine, $report);
        $this->createKinds($connection);
        $this->exec($connection, 'CREATE TABLE many(id INT PRIMARY KEY)');
        $this->exec($connection, 'INSERT INTO many VALUES ' . implode(',', array_map(static fn (int $n): string => '(' . $n . ')', range(1, 2000))));
        $cursor = new \ReflectionMethod(MysqlRowCursor::class, 'cursorRows'); $cursor->setAccessible(true);
        $reference = iterator_to_array($cursor->invoke(null, $connection, 'SELECT * FROM kinds ORDER BY id'), false);
        $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/mysql-row-cursor-fallback.php', self::socket($engine), $this->value($connection, 'SELECT DATABASE()'), (string) $report, $refusal],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $output . $error);
        $outcome = json_decode(trim($output), true, 16, JSON_THROW_ON_ERROR);
        $rows = array_map(static function (array $row): array {
            return array_map(static function ($value) { return $value === null ? null : base64_decode($value, true); }, $row);
        }, $outcome['rows']);
        self::assertGreaterThanOrEqual(1, $outcome['refusals'], 'the session statement was refused or changed');
        self::assertSame(0, $outcome['streamed'], 'no unbuffered statement is sent once the setting is refused');
        self::assertSame(1, $outcome['prepared'], 'the rows come from the server cursor');
        self::assertSame($reference, $rows, 'every row and value matches the server cursor');
        self::assertSame(['1.0E+20', '0.3'], [$rows[0]['f'], $rows[3]['f']], 'floating-point values carry the server cursor\'s formatting, so the fallback ran');
        self::assertSame('7', $outcome['sessionAfter'], 'the session value is untouched');
        self::assertContains($outcome['invalid'], ['Cannot open database row cursor.', 'Cannot read database row cursor.']);
        self::assertSame('1', $outcome['usable']);
        self::assertNotSame('completed', $outcome['killed'], 'a lost connection must not end the fallback as complete');
        self::assertContains($outcome['killed'], ['Cannot read database row cursor.', 'The database row cursor ended with an error.']);
        self::assertLessThan(2000, $outcome['killedRows']);
    }

    /** @dataProvider unrestorableCases */
    public function testAPreviousSessionValueThatCannotBeRestoredFailsTheReadInsteadOfContinuing(string $engine, int $report, string $interference, int $seen, int $streamed): void
    {
        $connection = $this->connect($engine, $report);
        $this->createKinds($connection);
        $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/mysql-row-cursor-fallback.php', self::socket($engine), $this->value($connection, 'SELECT DATABASE()'), (string) $report, $interference],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $output . $error);
        $outcome = json_decode(trim($output), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('Cannot restore the database session write timeout.', $outcome['result'], 'the read must fail');
        self::assertSame($seen, $outcome['seen'], 'rows delivered before the failure');
        self::assertSame($streamed, $outcome['streamed'], 'unbuffered statements sent');
    }

    public function unrestorableCases(): array
    {
        $cases = [];
        // Clamped and unrestorable: fails before any row and before any unbuffered statement. After streaming: all four rows,
        // then the failure. After an early stop: the failure where the reader is destroyed.
        $kinds = ['restore-fails' => [0, 0], 'restore-wrong' => [0, 0], 'final-restore-fails' => [4, 1], 'final-restore-wrong' => [4, 1], 'abandon-restore-fails' => [1, 1]];
        foreach ($this->servers() as $name => [$engine, $report]) {
            foreach ($kinds as $kind => [$seen, $streamed]) { $cases[$name . ', ' . $kind] = [$engine, $report, $kind, $seen, $streamed]; }
        }
        return $cases;
    }

    public function fallbackCases(): array
    {
        $cases = [];
        foreach ($this->servers() as $name => [$engine, $report]) {
            foreach (['set-false', 'set-throws', 'select-false', 'clamp', 'readback-false'] as $refusal) { $cases[$name . ', ' . $refusal] = [$engine, $report, $refusal]; }
        }
        return $cases;
    }

    public function servers(): array
    {
        $cases = [];
        foreach (['mysql', 'mariadb105'] as $engine) {
            foreach (['exceptions' => MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT, 'return values' => MYSQLI_REPORT_OFF] as $mode => $report) { $cases[$engine . ', ' . $mode] = [$engine, $report]; }
        }
        return $cases;
    }

    private function connect(string $engine, int $report): \mysqli
    {
        if (getenv('WRB_RUN_V4_MYSQL_INTEGRATION') !== '1') { self::markTestSkipped('Explicit isolated v4 MySQL integration authorization is required.'); }
        if (!extension_loaded('mysqli')) { self::markTestSkipped('mysqli is required.'); }
        if (!IsolatedMysql::available($engine)) { self::markTestSkipped($engine . ' is not installed.'); }
        self::$servers[$engine] = self::$servers[$engine] ?? IsolatedMysql::start(null, 'DISABLED', $engine);
        $schema = self::$servers[$engine]->newSchema();
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $connection = new \mysqli('localhost', 'root', '', $schema, 0, self::socket($engine));
        mysqli_report($report); $this->report = $report;
        $this->connections[] = $connection;
        return $connection;
    }

    private function createKinds(\mysqli $connection): void
    {
        $this->exec($connection, "CREATE TABLE kinds(id INT PRIMARY KEY, i BIGINT, u BIGINT UNSIGNED, d DECIMAL(30,10), f DOUBLE, r FLOAT, b BIT(8), t DATETIME(6), y YEAR, e ENUM('a','b'),
            s SET('x','y'), v VARCHAR(40), x LONGTEXT, l LONGBLOB) DEFAULT CHARSET=utf8mb4");
        $this->exec($connection, "INSERT INTO kinds VALUES
            (1, -9223372036854775808, 18446744073709551615, -12345678901234567890.0123456789, 1e20, 0.1, b'10100101', '2026-11-09 12:34:56.000001', 2026, 'b', 'x,y', '😀 日本語', REPEAT('t', 70000), X'00FF0027'),
            (2, 0, 0, 0, -0.0, -1.5e-30, b'0', '1000-01-01 00:00:00', 1901, 'a', '', '', '', ''),
            (3, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
            (4, 7, 7, 1.5, 0.30000000000000004, 3.4e38, b'11111111', '9999-12-31 23:59:59.999999', 2155, 'a', 'y', 'ok', 'x', X'00')");
    }

    private static function socket(string $engine): string
    {
        return parse_ini_file(self::$servers[$engine]->defaultsFile(), true, INI_SCANNER_RAW)['client']['socket'];
    }

    private function exec(\mysqli $connection, string $sql): void
    {
        self::assertTrue(mysqli_query($connection, $sql) === true, 'fixture statement failed');
    }

    private function value(\mysqli $connection, string $sql): string
    {
        $result = mysqli_query($connection, $sql); self::assertInstanceOf(\mysqli_result::class, $result);
        $value = (string) mysqli_fetch_row($result)[0]; mysqli_free_result($result);
        return $value;
    }

    /** True when another session cannot write to big within one second. */
    private function writeBlocked(string $engine, string $schema): bool
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $other = new \mysqli('localhost', 'root', '', $schema, 0, self::socket($engine));
        try {
            mysqli_query($other, 'SET SESSION lock_wait_timeout=1');
            try { mysqli_query($other, 'INSERT INTO big VALUES (100000, NULL)'); return false; }
            catch (\mysqli_sql_exception $blocked) { return true; }
        } finally { $other->close(); mysqli_report($this->report); }
    }

    private function killQuery(string $engine, string $schema, int $id): void
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $other = new \mysqli('localhost', 'root', '', $schema, 0, self::socket($engine));
        try { mysqli_query($other, 'KILL QUERY ' . $id); } finally { $other->close(); mysqli_report($this->report); }
    }

    /** The streaming session's own value, read from another session while the stream is open; null when it cannot be observed. */
    private function otherSessionTimeout(string $engine, \mysqli $streaming): ?string
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $other = new \mysqli('localhost', 'root', '', '', 0, self::socket($engine));
        try {
            // MySQL reports another session's value through performance_schema; where it is not collected the check is skipped.
            try { $result = mysqli_query($other, 'SELECT v.VARIABLE_VALUE FROM performance_schema.variables_by_thread v JOIN performance_schema.threads t ON t.THREAD_ID = v.THREAD_ID WHERE t.PROCESSLIST_ID = ' . (int) mysqli_thread_id($streaming) . " AND v.VARIABLE_NAME = 'net_write_timeout'"); }
            catch (\mysqli_sql_exception $unavailable) { return null; }
            $row = mysqli_fetch_row($result); mysqli_free_result($result);
            return is_array($row) ? (string) $row[0] : null;
        } finally { $other->close(); mysqli_report($this->report); }
    }
}
