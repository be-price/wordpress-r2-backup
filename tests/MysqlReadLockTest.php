<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\MysqlClient;
use RuntimeException;

final class MysqlReadLockTest extends TestCase
{
    private ?IsolatedMysql $server = null;
    private string $schema;
    private array $children = [];
    protected function setUp(): void
    {
        $this->server = IsolatedMysql::start(); $definer = $this->server->sharedImporter(); $this->schema = $this->server->newSchema();
        $this->server->execute($this->schema, strtr("CREATE TABLE t_inno(id INT PRIMARY KEY,v INT) ENGINE=InnoDB; CREATE TABLE t_myisam(id INT PRIMARY KEY,v INT) ENGINE=MyISAM; CREATE TABLE t_memory(id INT PRIMARY KEY,v INT) ENGINE=MEMORY; INSERT INTO t_inno VALUES(1,0); INSERT INTO t_myisam VALUES(1,0); INSERT INTO t_memory VALUES(1,0); CREATE VIEW v_probe AS SELECT id,v FROM t_inno; CREATE PROCEDURE p_probe() SELECT v FROM t_inno; CREATE FUNCTION f_probe() RETURNS INT DETERMINISTIC RETURN 1; CREATE EVENT e_probe ON SCHEDULE EVERY 1 HOUR DO SET @probe=1; CREATE TRIGGER tr_probe BEFORE INSERT ON t_inno FOR EACH ROW SET NEW.v=NEW.v;", ['CREATE VIEW' => "CREATE DEFINER=$definer VIEW", 'CREATE PROCEDURE' => "CREATE DEFINER=$definer PROCEDURE", 'CREATE FUNCTION' => "CREATE DEFINER=$definer FUNCTION", 'CREATE EVENT' => "CREATE DEFINER=$definer EVENT", 'CREATE TRIGGER' => "CREATE DEFINER=$definer TRIGGER"]));
    }
    protected function tearDown(): void
    {
        foreach ($this->children as &$child) { $this->stop($child); }
        if ($this->server !== null) { $this->server->stop(); } Fixture::cleanup();
    }
    public function testHeldGlobalLockStopsDirectWritesAndExportsEverySupportedEngineAndDefinition(): void
    {
        $client = $this->server->client(); $sql = Fixture::temp() . '/fixed.sql'; $writers = [];
        $result = $client->withGlobalReadLock($this->schema, function (array $facts) use ($client, $sql, &$writers): string {
            self::assertSame(['InnoDB', 'MEMORY', 'MyISAM'], array_values(array_unique(array_column(array_filter($facts['tables'], static fn ($row) => $row['type'] === 'BASE TABLE'), 'engine'))));
            self::assertCount(1, $facts['definitions']['views']); self::assertCount(1, $facts['definitions']['triggers']);
            self::assertCount(2, $facts['definitions']['routines']); self::assertCount(1, $facts['definitions']['events']);
            foreach (['t_inno', 't_myisam', 't_memory'] as $table) {
                $writers[] = $this->startSql("SELECT 'ATTEMPT'; UPDATE $table SET v=1 WHERE id=1;");
            }
            foreach ($writers as $index) { $this->awaitOutput($index, 'ATTEMPT'); }
            usleep(50000);
            foreach ($writers as $index) { self::assertTrue(proc_get_status($this->children[$index]['process'])['running']); }
            foreach (['t_inno', 't_myisam', 't_memory'] as $table) self::assertSame([['0']], $this->server->rows($this->schema, "SELECT v FROM $table"));
            $client->dump($this->schema, $sql);
            foreach ($writers as $index) { self::assertTrue(proc_get_status($this->children[$index]['process'])['running']); }
            return 'fixed';
        });
        self::assertSame('fixed', $result['result']);
        self::assertNotEmpty($result['database']['lockedAt']); self::assertNotEmpty($result['database']['releasedAt']);
        self::assertLessThanOrEqual($result['database']['releasedAt'], $result['database']['lockedAt']);
        foreach ($writers as $index) { self::assertSame(0, $this->finish($index)[0]); }
        foreach (['t_inno', 't_myisam', 't_memory'] as $table) self::assertSame([['1']], $this->server->rows($this->schema, "SELECT v FROM $table"));
        $contents = file_get_contents($sql);
        foreach (['ENGINE=InnoDB', 'ENGINE=MyISAM', 'ENGINE=MEMORY', 'v_probe', 'tr_probe', 'p_probe', 'f_probe', 'e_probe'] as $definition) self::assertStringContainsString($definition, $contents);
    }
    public function testMissingStoredDefinitionsCannotPublishSuccessfulSql(): void
    {
        $original = Fixture::temp() . '/complete.sql';
        $this->server->client()->withGlobalReadLock($this->schema, function () use ($original): void {
            $this->server->client()->dump($this->schema, $original);
        });
        $sql = file_get_contents($original);
        $patterns = [
            'view' => '~^/\*!50001 VIEW `v_probe`[^\n]*~m',
            'trigger' => '~^/\*!50003 CREATE\*/[^\n]*TRIGGER `tr_probe`[^\n]*~m',
            'procedure' => '~^CREATE DEFINER=[^\n]* PROCEDURE `p_probe`[^\n]*~m',
            'function' => '~^CREATE DEFINER=[^\n]* FUNCTION `f_probe`[^\n]*~m',
            'event' => '~^/\*!50106 CREATE\*/[^\n]*EVENT `e_probe`[^\n]*~m',
        ];
        foreach ($patterns as $kind => $pattern) {
            $incomplete = preg_replace($pattern, '', $sql, -1, $removed);
            self::assertSame(1, $removed, 'The injected omission must remove exactly one real definition.');
            $directory = Fixture::temp();
            $input = $directory . '/incomplete.sql'; file_put_contents($input, $incomplete); chmod($input, 0600);
            $wrapper = $directory . '/dump';
            file_put_contents($wrapper, '#!' . Fixture::phpForShebang() . "\n<?php readfile(" . var_export($input, true) . ");\n"); chmod($wrapper, 0700);
            $client = new MysqlClient(IsolatedMysql::mysqlBin() . 'mysql', $wrapper, $this->server->defaultsFile(), $this->server->workspace());
            $output = $directory . '/published.sql'; $failure = null;
            try {
                $client->withGlobalReadLock($this->schema, function () use ($client, $output): void { $client->dump($this->schema, $output); });
            } catch (RuntimeException $error) { $failure = $error; }
            self::assertInstanceOf(RuntimeException::class, $failure, 'The missing ' . $kind . ' must fail capture.');
            self::assertFileDoesNotExist($output);
        }
    }

    public function testAllDefinitionsRemainUsableAfterRestoringAndRemovingTheOriginalSchema(): void
    {
        $sql = Fixture::temp() . '/complete.sql';
        $this->server->client()->withGlobalReadLock($this->schema, function () use ($sql): void { $this->server->client()->dump($this->schema, $sql); });
        $target = $this->server->newSchema();
        $this->server->client()->importIntoEmpty($target, $sql);
        $this->server->dropSchema($this->schema);
        self::assertSame([['1', '0']], $this->server->rows($target, 'SELECT * FROM v_probe'));
        self::assertSame([['0']], $this->server->rows($target, 'CALL p_probe()'));
        self::assertSame([['1']], $this->server->rows($target, 'SELECT f_probe()'));
        self::assertSame([['tr_probe']], $this->server->rows($target, 'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()'));
        self::assertSame([['e_probe']], $this->server->rows($target, 'SELECT EVENT_NAME FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE()'));
        $this->server->execute($target, 'INSERT INTO t_inno VALUES(2,7)');
        self::assertSame([['7']], $this->server->rows($target, 'SELECT v FROM t_inno WHERE id=2'));
    }

    public function testCallbackFailureReleasesTheActualGlobalLock(): void
    {
        $writer = null; $failure = null;
        try {
            $this->server->client()->withGlobalReadLock($this->schema, function () use (&$writer): void {
                $writer = $this->startSql("SELECT 'ATTEMPT'; UPDATE t_myisam SET v=2 WHERE id=1;");
                $this->awaitOutput($writer, 'ATTEMPT'); throw new \LogicException('injected capture failure');
            });
        } catch (\LogicException $error) { $failure = $error; }
        self::assertInstanceOf(\LogicException::class, $failure);
        self::assertSame(0, $this->finish($writer)[0]);
        self::assertSame([['2']], $this->server->rows($this->schema, 'SELECT v FROM t_myisam'));
    }
    public function testKilledHoldingConnectionCannotPublishDatabaseOutput(): void
    {
        $client = $this->server->client(); $sql = Fixture::temp() . '/unusable.sql'; $failure = null;
        try {
            $client->withGlobalReadLock($this->schema, function () use ($client, $sql): void {
                $connections = $this->server->rows($this->schema, "SELECT ID FROM information_schema.PROCESSLIST WHERE DB=DATABASE() AND COMMAND='Sleep'");
                self::assertCount(1, $connections);
                $this->server->execute($this->schema, 'KILL CONNECTION ' . (int) $connections[0][0]);
                $client->dump($this->schema, $sql);
            });
        } catch (RuntimeException $error) { $failure = $error; }
        self::assertInstanceOf(RuntimeException::class, $failure); self::assertFileDoesNotExist($sql);
    }
    public function testInsufficientMetadataPrivilegesNeverBecomeAnEmptySuccessfulInventory(): void
    {
        $password = bin2hex(random_bytes(24));
        $this->server->execute($this->schema, "CREATE USER 'wrb_limited'@'localhost' IDENTIFIED BY '$password'; GRANT SELECT,SHOW VIEW,TRIGGER,EVENT ON `{$this->schema}`.* TO 'wrb_limited'@'localhost'; GRANT RELOAD ON *.* TO 'wrb_limited'@'localhost';");
        $ini = parse_ini_file($this->server->defaultsFile(), true, INI_SCANNER_RAW);
        $defaults = $this->server->workspace() . '/limited.cnf';
        file_put_contents($defaults, "[client]\nuser=wrb_limited\npassword=$password\nsocket=\"" . $ini['client']['socket'] . "\"\n"); chmod($defaults, 0600);
        $client = new MysqlClient(IsolatedMysql::mysqlBin() . 'mysql', IsolatedMysql::mysqlBin() . 'mysqldump', $defaults, $this->server->workspace());
        $called = false; $failure = null;
        try { $client->withGlobalReadLock($this->schema, function () use (&$called): void { $called = true; }); }
        catch (RuntimeException $error) { $failure = $error; }
        self::assertInstanceOf(RuntimeException::class, $failure); self::assertFalse($called);
        self::assertStringNotContainsString($password, $failure->getMessage());
    }
    public function testNestedCaptureAndImportUnderTheHeldGlobalLockAreRefused(): void
    {
        $client = $this->server->client(); $nested = null;
        $client->withGlobalReadLock($this->schema, function () use ($client, &$nested): void {
            try { $client->withGlobalReadLock($this->schema, static fn () => null); } catch (RuntimeException $error) { $nested = $error; }
        });
        self::assertInstanceOf(RuntimeException::class, $nested);
    }
    public function testLockAcquisitionHasADeadlineAndDoesNotInvokeCaptureWhileWaiting(): void
    {
        $reader = $this->startSql("SELECT 'ATTEMPT'; SELECT SLEEP(3) FROM t_inno;"); $this->awaitOutput($reader, 'ATTEMPT');
        $called = false; $failure = null;
        try { $this->server->client()->withGlobalReadLock($this->schema, function () use (&$called): void { $called = true; }, 1); }
        catch (RuntimeException $error) { $failure = $error; }
        self::assertInstanceOf(RuntimeException::class, $failure); self::assertFalse($called);
    }
    private function startSql(string $sql): int
    {
        $process = proc_open([IsolatedMysql::mysqlBin() . 'mysql', '--defaults-file=' . $this->server->defaultsFile(), '--batch', '--skip-column-names', '--unbuffered', '--skip-reconnect', $this->schema],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->server->workspace(), ['MYSQL_TEST_LOGIN_FILE' => $this->server->workspace() . '/absent-login.cnf']);
        fwrite($pipes[0], $sql . "\n"); fclose($pipes[0]); stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        $this->children[] = ['process' => $process, 'pipes' => $pipes]; return array_key_last($this->children);
    }
    private function awaitOutput(int $index, string $expected): void
    {
        $deadline = microtime(true) + 5; $output = '';
        do { $output .= stream_get_contents($this->children[$index]['pipes'][1]); if (in_array($expected, explode("\n", $output), true)) return; usleep(10000); } while (microtime(true) < $deadline);
        self::fail('The isolated writer did not acknowledge its attempt.');
    }
    private function finish(int $index): array
    {
        $child =& $this->children[$index]; $deadline = microtime(true) + 5; $status = null;
        do { $status = proc_get_status($child['process']); if (!$status['running']) break; usleep(10000); } while (microtime(true) < $deadline);
        self::assertFalse($status['running'], 'The isolated writer remains blocked after release.');
        $error = stream_get_contents($child['pipes'][2]); $exit = $status['exitcode']; $this->stop($child); return [$exit, $error];
    }
    private function stop(array &$child): void
    {
        if (!is_resource($child['process'])) return;
        if (proc_get_status($child['process'])['running']) proc_terminate($child['process']);
        foreach ($child['pipes'] as $pipe) if (is_resource($pipe)) fclose($pipe);
        proc_close($child['process']); $child['process'] = null;
    }
}
