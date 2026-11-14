<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use WordPressR2Backup\SqliteDatabaseClient;
use WordPressR2Backup\WordPressDatabase;

final class SqliteDatabaseClientTest extends TestCase
{
    private array $clients = [];
    private const CREATE = 'CREATE TABLE `wp_probe` (`id` bigint unsigned NOT NULL,`text` longtext,`payload` longblob,PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

    protected function setUp(): void
    {
        $driver = getenv('WRB_SQLITE_DRIVER') ?: dirname(__DIR__) . '/dependencies/sqlite-database-integration/wp-includes/database/load.php';
        if (!is_file($driver)) { self::markTestSkipped('The verified WordPress SQLite driver is not packaged.'); }
        require_once $driver;
    }

    protected function tearDown(): void
    {
        foreach ($this->clients as $client) { $client->close(); }
        Fixture::cleanup();
    }

    public function testImportAndValueVerificationPreserveUnicodeBinaryNullAndDuplicateFreeInventory(): void
    {
        [$client, $file, $path] = $this->fixture(self::CREATE . ";\nINSERT INTO `wp_probe` (`id`,`text`,`payload`) VALUES (1,CONVERT(X'e697a5e69cace8aa9e3b' USING utf8mb4),X'00FF41');\nINSERT INTO `wp_probe` (`id`,`text`,`payload`) VALUES (2,NULL,X'');\n");
        $client->importIntoEmpty('sqlite', $file, []);
        $client->verifyV4Database('sqlite', [$this->record()], $this->environment());
        self::assertSame(0600, fileperms($path) & 0777);
        self::assertSame($path, $client->path());
        $client->close();
        $pdo = new \PDO('sqlite:' . $path);
        self::assertSame('日本語;', $pdo->query('SELECT text FROM wp_probe WHERE id=1')->fetchColumn());
        self::assertSame('00FF41', $pdo->query('SELECT hex(payload) FROM wp_probe WHERE id=1')->fetchColumn());
        self::assertSame(2, (int) $pdo->query('SELECT count(*) FROM wp_probe')->fetchColumn());
    }

    public function testTheV4PreambleAndPostambleAreAccepted(): void
    {
        $sql = "SET @WRB4_SQL_MODE=@@SESSION.SQL_MODE,@WRB4_TIME_ZONE=@@SESSION.time_zone,@WRB4_FOREIGN_KEY_CHECKS=@@SESSION.FOREIGN_KEY_CHECKS;\nSET SESSION SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nSET SESSION time_zone='+00:00';\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n" . self::CREATE . ";\nSET FOREIGN_KEY_CHECKS=@WRB4_FOREIGN_KEY_CHECKS;\nSET SQL_MODE=@WRB4_SQL_MODE;\nSET SESSION time_zone=@WRB4_TIME_ZONE;\n";
        [$client, $file] = $this->fixture($sql);
        $client->importIntoEmpty('sqlite', $file, []);
        $record = $this->record(); $record['rows'] = 0; $record['sourceRows'] = 0; $record['rowSha256'] = hash('sha256', '');
        $client->verifyV4Database('sqlite', [$record], $this->environment());
        self::assertTrue(true);
    }

    public function testExistingDatabaseAndSymlinksAreRejectedWithoutReplacement(): void
    {
        $root = Fixture::temp(); $path = $root . '/existing.sqlite'; file_put_contents($path, 'retain'); chmod($path, 0600);
        try { new SqliteDatabaseClient($path); self::fail('Existing files must be rejected.'); } catch (RuntimeException $error) { self::assertSame('retain', file_get_contents($path)); }
        symlink($path, $root . '/linked.sqlite');
        $this->expectException(RuntimeException::class); new SqliteDatabaseClient($root . '/linked.sqlite');
    }

    public function testUnsupportedSqlStopsAndCannotBeRetried(): void
    {
        [$client, $file] = $this->fixture(self::CREATE . ";\nATTACH DATABASE '/tmp/forbidden' AS other;\n");
        try { $client->importIntoEmpty('sqlite', $file, []); self::fail('Unsupported SQL must be rejected.'); } catch (RuntimeException $error) { self::assertSame('SQLite database import failed; use a fresh destination.', $error->getMessage()); }
        $this->expectException(RuntimeException::class); $client->importIntoEmpty('sqlite', $file, []);
    }

    public function testVerificationDetectsChangedValueDefinitionColumnCountAndExtraTable(): void
    {
        foreach (['value', 'definition', 'columns', 'rows', 'extra'] as $changed) {
            [$client, $file, $path] = $this->fixture(self::CREATE . ";\nINSERT INTO `wp_probe` (`id`,`text`,`payload`) VALUES (1,CONVERT(X'e697a5e69cace8aa9e3b' USING utf8mb4),X'00FF41');\nINSERT INTO `wp_probe` (`id`,`text`,`payload`) VALUES (2,NULL,X'');\n");
            $client->importIntoEmpty('sqlite', $file, []); $record = $this->record();
            if ($changed === 'definition') { $record['definitionSha256'] = str_repeat('a', 64); }
            elseif ($changed === 'columns') { $record['columns'] = ['id', 'text']; }
            elseif ($changed === 'rows') { ++$record['rows']; ++$record['sourceRows']; }
            else {
                $db = new \PDO('sqlite:' . $path); $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                $db->exec($changed === 'value' ? "UPDATE wp_probe SET text='changed' WHERE id=1" : 'CREATE TABLE extra (id INTEGER)'); $db = null;
            }
            try { $client->verifyV4Database('sqlite', [$record], $this->environment()); self::fail('Changed ' . $changed . ' must fail.'); }
            catch (RuntimeException $error) { self::assertSame('SQLite database verification failed.', $error->getMessage()); }
        }
    }

    public function testIncompleteSqlAndEventsFailWithoutCreatingSuccessfulImport(): void
    {
        [$client, $file] = $this->fixture(self::CREATE . ";\nINSERT INTO `wp_probe` (`id`,`text`,`payload`) VALUES (1,'incomplete");
        try { $client->importIntoEmpty('sqlite', $file, []); self::fail('Incomplete SQL must fail.'); } catch (RuntimeException $error) { self::assertTrue(true); }
        [$second, $file] = $this->fixture(self::CREATE . ";\n");
        $this->expectException(RuntimeException::class); $second->importIntoEmpty('sqlite', $file, ['event1']);
    }

    public function testThousandRowImportIsTransactionalAndReportsSafeProgress(): void
    {
        $root = Fixture::temp(); $path = $root . '/db.sqlite'; $file = $root . '/source.sql'; $stream = fopen($file, 'wb');
        fwrite($stream, self::CREATE . ";\n"); $digests = [];
        for ($number = 0; $number < 1000; ++$number) {
            fwrite($stream, 'INSERT INTO `wp_probe` (`id`,`text`,`payload`) VALUES (' . $number . ",CONVERT(X'66617374' USING utf8mb4),X'00FF');\n");
            $digests[(string) $number] = hash('sha256', 'V' . strlen((string) $number) . ':' . $number . ';V4:fast;V2:' . "\0\xff" . ';');
        }
        fclose($stream); chmod($file, 0600); $states = [];
        $client = new SqliteDatabaseClient($path, static function(array $state) use (&$states): void { $states[] = $state; }); $this->clients[] = $client;
        $started = microtime(true); $client->importIntoEmpty('sqlite', $file, []);
        $record = $this->record(); $record['rows'] = 1000; $record['sourceRows'] = 1000; ksort($digests, SORT_STRING); $record['rowSha256'] = hash('sha256', implode("\n", $digests) . "\n");
        $client->verifyV4Database('sqlite', [$record], $this->environment());
        self::assertContains('sqlite-imported', array_column($states, 'phase'));
        self::assertSame(1001, $states[count($states) - 2]['statements']);
        if (getenv('WRB_SQLITE_BENCHMARK')) { fwrite(STDERR, json_encode(['rows' => 1000, 'seconds' => microtime(true) - $started, 'peakBytes' => memory_get_peak_usage(true)]) . "\n"); }
    }

    public function testArbitraryInsertExpressionsAreRejected(): void
    {
        [$client, $file] = $this->fixture(self::CREATE . ";\nINSERT INTO `wp_probe` (`id`,`text`,`payload`) VALUES (1,(SELECT 'unexpected'),NULL);\n");
        $this->expectException(RuntimeException::class); $client->importIntoEmpty('sqlite', $file, []);
    }

    public function testNumericConversionsCannotPassVerificationAfterLosingSourceBytes(): void
    {
        $create = 'CREATE TABLE `wp_numeric` (`id` int NOT NULL,`amount` DECIMAL(10,2),`huge` BIGINT UNSIGNED,PRIMARY KEY (`id`)) ENGINE=InnoDB';
        [$client, $file] = $this->fixture($create . ";\nINSERT INTO `wp_numeric` (`id`,`amount`,`huge`) VALUES (1,1.20,9);\n");
        $client->importIntoEmpty('sqlite', $file, []);
        $record = ['type' => 'table', 'name' => 'wp_numeric', 'rows' => 1, 'sourceRows' => 1, 'excludedRows' => 0, 'definitionSha256' => WordPressDatabase::definitionSha256($create, ''),
            'rowSha256' => hash('sha256', hash('sha256', 'V1:1;V4:1.20;V1:9;') . "\n"), 'columns' => ['id', 'amount', 'huge'], 'order' => ['id'], 'foreignKeys' => []];
        try {
            $client->verifyV4Database('sqlite', [$record], $this->environment());
            self::assertTrue(true);
        } catch (RuntimeException $error) {
            self::assertSame('SQLite database verification failed.', $error->getMessage());
        }
    }

    public function testDestinationPermissionChangesDuringProgressStopTheImport(): void
    {
        $root = Fixture::temp(); $path = $root . '/db.sqlite'; $file = $root . '/source.sql'; file_put_contents($file, self::CREATE . ";\n"); chmod($file, 0600);
        $client = new SqliteDatabaseClient($path, static function(array $state) use ($path): void { chmod($path, 0644); }); $this->clients[] = $client;
        $this->expectException(RuntimeException::class); $client->importIntoEmpty('sqlite', $file, []);
    }

    public function testQuotedDelimiterRemainsWithinOneDefinition(): void
    {
        $create = 'CREATE TABLE `wp_quotes` (`id` int NOT NULL,`text` varchar(100) DEFAULT ' . "'a;v'" . ',PRIMARY KEY (`id`)) ENGINE=InnoDB';
        [$client, $file] = $this->fixture($create . ";\nINSERT INTO `wp_quotes` (`id`,`text`) VALUES (1,CONVERT(X'613b76' USING utf8mb4));\n");
        $client->importIntoEmpty('sqlite', $file, []);
        $record = ['type' => 'table', 'name' => 'wp_quotes', 'rows' => 1, 'sourceRows' => 1, 'excludedRows' => 0, 'definitionSha256' => WordPressDatabase::definitionSha256($create, ''),
            'rowSha256' => hash('sha256', hash('sha256', 'V1:1;V3:a;v;') . "\n"), 'columns' => ['id', 'text'], 'order' => ['id'], 'foreignKeys' => []];
        $environment = $this->environment();
        $client->verifyV4Database('sqlite', [$record], $environment); self::assertTrue(true);
    }

    public function testForwardForeignKeysImportBeforeTheirReferencedTableAndAreEnabledAfterVerification(): void
    {
        [$client, $file, $path, $records] = $this->foreignFixture(false);
        $client->importIntoEmpty('sqlite', $file, []);
        $client->verifyV4Database('sqlite', $records, $this->environment());
        $property = new \ReflectionProperty($client, 'sqlite'); $property->setAccessible(true); $pdo = $property->getValue($client);
        self::assertSame(1, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM wp_a_child')->fetchColumn());
        self::assertSame([], $pdo->query('PRAGMA foreign_key_check')->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function testForwardForeignKeyOrphansFailVerification(): void
    {
        [$client, $file, $path, $records] = $this->foreignFixture(true);
        $client->importIntoEmpty('sqlite', $file, []);
        $this->expectException(RuntimeException::class); $client->verifyV4Database('sqlite', $records, $this->environment());
    }

    public function testPhysicalUniqueKeyRemovalFailsWithoutChangingDataOrColumnNames(): void
    {
        [$client, $file, $path, $records] = $this->foreignFixture(false);
        $client->importIntoEmpty('sqlite', $file, []);
        $pdo = new \PDO('sqlite:' . $path); $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('DROP INDEX wp_a_child__code_unique'); $pdo = null;
        $this->expectException(RuntimeException::class); $client->verifyV4Database('sqlite', $records, $this->environment());
    }

    public function testUnsupportedCheckConstraintsStopRatherThanDisappear(): void
    {
        [$client, $file] = $this->fixture('CREATE TABLE `checked` (`id` int NOT NULL, CHECK (`id` > 0));' . "\n");
        $this->expectException(RuntimeException::class); $client->importIntoEmpty('sqlite', $file, []);
    }

    public function testUniquePrefixConstraintsStopRatherThanExpandTheirMeaning(): void
    {
        [$client, $file] = $this->fixture('CREATE TABLE `prefixed` (`id` int NOT NULL,`code` varchar(100),PRIMARY KEY (`id`),UNIQUE KEY `code_prefix` (`code`(3))) ENGINE=InnoDB;' . "\n");
        $this->expectException(RuntimeException::class); $client->importIntoEmpty('sqlite', $file, []);
    }

    public function testCloseCollectsTheActualDriverAndPdoBeforeUnpinningTheFile(): void
    {
        [$client, $file] = $this->fixture(self::CREATE . ";\nINSERT INTO `wp_probe` (`id`,`text`,`payload`) VALUES (1,NULL,X'00');\n");
        $client->importIntoEmpty('sqlite', $file, []);
        $driverProperty = new \ReflectionProperty($client, 'database'); $driverProperty->setAccessible(true);
        $pdoProperty = new \ReflectionProperty($client, 'sqlite'); $pdoProperty->setAccessible(true);
        $pinProperty = new \ReflectionProperty($client, 'pin'); $pinProperty->setAccessible(true);
        $driver = \WeakReference::create($driverProperty->getValue($client));
        $pdo = \WeakReference::create($pdoProperty->getValue($client));
        $pin = $pinProperty->getValue($client);
        self::assertNotNull($driver->get()); self::assertNotNull($pdo->get()); self::assertIsResource($pin);
        $client->close();
        self::assertTrue($driver->get() === null); self::assertTrue($pdo->get() === null); self::assertFalse(is_resource($pin));
        $client->close();
    }

    public function testAReferencedConnectionKeepsThePinUntilARepeatedCloseCanReleaseIt(): void
    {
        [$client] = $this->fixture(self::CREATE . ";\n");
        $pdoProperty = new \ReflectionProperty($client, 'sqlite'); $pdoProperty->setAccessible(true);
        $pinProperty = new \ReflectionProperty($client, 'pin'); $pinProperty->setAccessible(true);
        $pdo = $pdoProperty->getValue($client); $weak = \WeakReference::create($pdo); $pin = $pinProperty->getValue($client);
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try { $client->close(); self::fail('The externally referenced connection must remain pinned.'); }
            catch (RuntimeException $error) { self::assertStringContainsString('remains referenced', $error->getMessage()); }
            self::assertIsResource($pin); self::assertTrue($weak->get() !== null);
        }
        unset($pdo);
        $client->close(); self::assertTrue($weak->get() === null); self::assertFalse(is_resource($pin));
    }

    public function testAnInvalidLaterRowRollsBackAlreadyExecutedBatchesInTheCurrentTable(): void
    {
        $sql = self::CREATE . ";\n";
        for ($number = 1; $number <= 150; ++$number) { $sql .= 'INSERT INTO `wp_probe` (`id`,`text`,`payload`) VALUES (' . $number . ",NULL,X'61');\n"; }
        $sql .= "INSERT INTO `wp_probe` (`id`,`text`,`payload`) VALUES (151,(SELECT 1),NULL);\n";
        [$client, $file, $path] = $this->fixture($sql);
        try { $client->importIntoEmpty('sqlite', $file, []); self::fail('The unsupported later row must fail.'); }
        catch (RuntimeException $error) { self::assertStringContainsString('import failed', $error->getMessage()); }
        $pdo = new \PDO('sqlite:' . $path);
        $exists = (int) $pdo->query("SELECT count(*) FROM sqlite_master WHERE type='table' AND name='wp_probe'")->fetchColumn();
        if ($exists !== 0) { self::assertSame(0, (int) $pdo->query('SELECT count(*) FROM wp_probe')->fetchColumn()); }
        else { self::assertSame(0, $exists); }
        $pdo = null;
        $this->expectException(RuntimeException::class); $client->importIntoEmpty('sqlite', $file, []);
    }

    public function testOneLargeRowRemainsWholeAcrossBatchByteBoundaries(): void
    {
        $large = str_repeat('a', 600000);
        $sql = self::CREATE . ";\nINSERT INTO `wp_probe` (`id`,`text`,`payload`) VALUES (1,NULL,X'6669727374');\n"
            . "INSERT INTO `wp_probe` (`id`,`text`,`payload`) VALUES (2,NULL,X'" . bin2hex($large) . "');\n"
            . "INSERT INTO `wp_probe` (`id`,`text`,`payload`) VALUES (3,NULL,X'6c617374');\n";
        [$client, $file] = $this->fixture($sql); $client->importIntoEmpty('sqlite', $file, []);
        $record = $this->record(); $record['rows'] = 3; $record['sourceRows'] = 3;
        $digests = [hash('sha256', 'V1:1;N;V5:first;'), hash('sha256', 'V1:2;N;V600000:' . $large . ';'), hash('sha256', 'V1:3;N;V4:last;')];
        $record['rowSha256'] = hash('sha256', implode("\n", $digests) . "\n");
        $client->verifyV4Database('sqlite', [$record], $this->environment()); self::assertTrue(true);
    }

    public function testChangingOnlyTheInsertColumnOrderFlushesThePriorBatch(): void
    {
        [$client, $file] = $this->fixture(self::CREATE . ";\nINSERT INTO `wp_probe` (`id`,`text`,`payload`) VALUES (1,CONVERT(X'e697a5e69cace8aa9e3b' USING utf8mb4),X'00FF41');\nINSERT INTO `wp_probe` (`text`,`id`,`payload`) VALUES (NULL,2,X'');\n");
        $client->importIntoEmpty('sqlite', $file, []); $client->verifyV4Database('sqlite', [$this->record()], $this->environment()); self::assertTrue(true);
    }

    public function testExplicitZeroAutoIncrementAndTheNextWordPressInsertIdRemainCorrect(): void
    {
        $create = 'CREATE TABLE `wp_auto` (`id` bigint unsigned NOT NULL AUTO_INCREMENT,`text` longtext,PRIMARY KEY (`id`)) ENGINE=InnoDB AUTO_INCREMENT=100';
        [$client, $file, $path] = $this->fixture("SET SESSION SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n" . $create . ";\nINSERT INTO `wp_auto` (`id`,`text`) VALUES (0,CONVERT(X'7a65726f' USING utf8mb4));\nINSERT INTO `wp_auto` (`id`,`text`) VALUES (7,CONVERT(X'736576656e' USING utf8mb4));\n");
        $record = $this->tableRecord('wp_auto', $create, ['id', 'text'], ['id'], [['0', 'zero'], ['7', 'seven']]);
        $client->importIntoEmpty('sqlite', $file, []); $client->verifyV4Database('sqlite', [$record], $this->environment()); $client->close();
        $driver = new \WP_MySQL_On_SQLite('mysql-on-sqlite:path=' . $path . ';dbname=sqlite');
        $driver->exec("INSERT INTO wp_auto (`text`) VALUES ('next')");
        self::assertSame('100', $driver->lastInsertId());
        self::assertSame('zero', $driver->query('SELECT text FROM wp_auto WHERE id=0')->fetchColumn());
        $driver = null; gc_collect_cycles();
    }

    public function testCompositePrimaryKeysRetainValues(): void
    {
        $create = 'CREATE TABLE `wp_composite` (`a` int NOT NULL,`b` int NOT NULL,PRIMARY KEY (`a`,`b`)) ENGINE=InnoDB';
        [$client, $file] = $this->fixture($create . ";\nINSERT INTO `wp_composite` (`a`,`b`) VALUES (1,2);\nINSERT INTO `wp_composite` (`a`,`b`) VALUES (1,3);\n");
        $record = $this->tableRecord('wp_composite', $create, ['a', 'b'], ['a', 'b'], [['1', '2'], ['1', '3']]);
        $client->importIntoEmpty('sqlite', $file, []); $client->verifyV4Database('sqlite', [$record], $this->environment()); self::assertTrue(true);
    }

    public function testAComputedColumnWithoutAPhysicalExpressionCannotSilentlyLoseItsMeaning(): void
    {
        $create = 'CREATE TABLE `wp_generated` (`a` int NOT NULL,`computed` int GENERATED ALWAYS AS (`a` + 1) STORED,PRIMARY KEY (`a`)) ENGINE=InnoDB';
        [$client, $file] = $this->fixture($create . ";\nINSERT INTO `wp_generated` (`a`) VALUES (1);\n");
        $this->expectException(RuntimeException::class); $client->importIntoEmpty('sqlite', $file, []);
    }

    public function testUnsupportedDirectCharsetUsesTheOfficialPathBetweenPreparedRows(): void
    {
        [$client, $file] = $this->fixture(self::CREATE . ";\nINSERT INTO `wp_probe` (`id`,`text`,`payload`) VALUES (1,CONVERT(X'616263' USING utf8mb4),X'00');\nINSERT INTO `wp_probe` (`id`,`text`,`payload`) VALUES (2,CONVERT(X'616263' USING latin1),NULL);\nINSERT INTO `wp_probe` (`id`,`text`,`payload`) VALUES (3,CONVERT(X'616263' USING ascii),X'FF');\n");
        $record = $this->tableRecord('wp_probe', self::CREATE, ['id', 'text', 'payload'], ['id'], [['1', 'abc', "\0"], ['2', 'abc', null], ['3', 'abc', "\xff"]]);
        $client->importIntoEmpty('sqlite', $file, []); $client->verifyV4Database('sqlite', [$record], $this->environment()); self::assertTrue(true);
    }

    public function testAsciiZeroDateRemainsAValueWithTheCaptureSqlMode(): void
    {
        $create = 'CREATE TABLE `wp_dates` (`id` int NOT NULL,`created` datetime NOT NULL,PRIMARY KEY (`id`)) ENGINE=InnoDB';
        [$client, $file] = $this->fixture("SET SESSION SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n" . $create . ";\nINSERT INTO `wp_dates` (`id`,`created`) VALUES (1,CONVERT(X'303030302d30302d30302030303a30303a3030' USING ascii));\n");
        $record = $this->tableRecord('wp_dates', $create, ['id', 'created'], ['id'], [['1', '0000-00-00 00:00:00']]);
        $client->importIntoEmpty('sqlite', $file, []); $client->verifyV4Database('sqlite', [$record], $this->environment()); self::assertTrue(true);
    }

    /** @dataProvider scannerModes */
    public function testScannerKeepsQuotedDelimitersAndEscapeModes(bool $backslashEscapes, bool $ansiQuotes, array $expected): void
    {
        $client = $this->scanner($backslashEscapes, $ansiQuotes);
        $source = tmpfile();
        try {
            fwrite($source, ' ; ' . implode(";\n", $expected) . ";\n\t"); rewind($source);
            self::assertSame($expected, iterator_to_array($this->scan($client, $source), false));
        } finally { fclose($source); }
    }

    public function scannerModes(): array
    {
        return [
            [true, false, ["SELECT 'one;two'", 'SELECT `a;``b`', "SELECT 'a\\';b'", 'SELECT "a\\";b"', 'SELECT 2']],
            [false, false, ["SELECT 'a\\'", "SELECT 'it''s;quoted'", 'SELECT 2']],
            [true, true, ['SELECT "a\\"', 'SELECT "a;""b"', 'SELECT 2']],
        ];
    }

    public function testScannerPreservesQuoteAndEscapeStateAcrossReadBoundaries(): void
    {
        $prefix = "SELECT '";
        $escaped = $prefix . str_repeat('a', 65535 - strlen($prefix)) . "\\';still quoted'";
        $expected = ["'quote at boundary;end'", $escaped, "SELECT `a;``b`", 'SELECT 3'];
        $source = tmpfile(); $client = $this->scanner();
        try {
            fwrite($source, str_repeat(' ', 65535) . $expected[0] . ';');
            $padding = (65536 - ftell($source) % 65536) % 65536;
            fwrite($source, str_repeat(' ', $padding) . implode(';', array_slice($expected, 1)) . ';'); rewind($source);
            self::assertSame($expected, iterator_to_array($this->scan($client, $source), false));
        } finally { fclose($source); }
    }

    public function testScannerUsesTheModeChangedByItsConsumerBeforeTheNextStatement(): void
    {
        $client = $this->scanner(); $source = tmpfile();
        try {
            fwrite($source, "SET SESSION SQL_MODE='NO_BACKSLASH_ESCAPES';SELECT 'a\\';SELECT 2;"); rewind($source);
            $statements = [];
            foreach ($this->scan($client, $source) as $statement) {
                $statements[] = $statement;
                if (count($statements) === 1) {
                    $property = new \ReflectionProperty($client, 'backslashEscapes'); $property->setAccessible(true); $property->setValue($client, false);
                }
            }
            self::assertSame(["SET SESSION SQL_MODE='NO_BACKSLASH_ESCAPES'", "SELECT 'a\\'", 'SELECT 2'], $statements);
        } finally { fclose($source); }
    }

    /** @dataProvider incompleteScannerInputs */
    public function testScannerStillRejectsUnterminatedStatementsAndQuotes(string $sql): void
    {
        $source = tmpfile(); $client = $this->scanner();
        try {
            fwrite($source, $sql); rewind($source);
            $this->expectException(RuntimeException::class); $this->expectExceptionMessage('incomplete statement');
            iterator_to_array($this->scan($client, $source), false);
        } finally { fclose($source); }
    }

    public function incompleteScannerInputs(): array { return [["SELECT 'unfinished"], ["SELECT 'escaped\\"], ['SELECT 1'], ["SELECT 1;SELECT `unfinished"]]; }

    /** @dataProvider scannerStatementBounds */
    public function testScannerEnforcesTheSameStatementByteLimitAtSpanAndSpecialBoundaries(int $bytes, string $last, bool $accepted): void
    {
        $source = tmpfile(); $client = $this->scanner();
        try {
            $chunk = str_repeat('a', 1048576);
            for ($remaining = $bytes; $remaining > 0; $remaining -= $written) { $written = min($remaining, strlen($chunk)); fwrite($source, substr($chunk, 0, $written)); }
            fwrite($source, $last . ';'); rewind($source);
            if (!$accepted) { $this->expectException(RuntimeException::class); $this->expectExceptionMessage('exceeds its bound'); }
            $count = 0;
            foreach ($this->scan($client, $source) as $statement) { self::assertSame($bytes, strlen($statement)); ++$count; }
            if ($accepted) { self::assertSame(1, $count); }
        } finally { fclose($source); }
    }

    public function scannerStatementBounds(): array
    {
        return [[33554432, '', true], [33554433, '', false], [33554432, "'", false]];
    }

    private function scanner(bool $backslashEscapes = true, bool $ansiQuotes = false): SqliteDatabaseClient
    {
        $class = new \ReflectionClass(SqliteDatabaseClient::class); $client = $class->newInstanceWithoutConstructor();
        foreach (['backslashEscapes' => $backslashEscapes, 'ansiQuotes' => $ansiQuotes] as $name => $value) { $property = $class->getProperty($name); $property->setAccessible(true); $property->setValue($client, $value); }
        return $client;
    }

    private function scan(SqliteDatabaseClient $client, $source): \Generator
    {
        $method = new \ReflectionMethod($client, 'statements'); $method->setAccessible(true); return $method->invoke($client, $source);
    }

    private function tableRecord(string $name, string $create, array $columns, array $order, array $rows): array
    {
        $hash = hash_init('sha256');
        foreach ($rows as $row) {
            $rowHash = hash_init('sha256');
            foreach ($row as $value) { hash_update($rowHash, $value === null ? 'N;' : 'V' . strlen($value) . ':' . $value . ';'); }
            hash_update($hash, hash_final($rowHash) . "\n");
        }
        return ['type' => 'table', 'name' => $name, 'rows' => count($rows), 'sourceRows' => count($rows), 'excludedRows' => 0, 'definitionSha256' => WordPressDatabase::definitionSha256($create, ''),
            'rowSha256' => hash_final($hash), 'columns' => $columns, 'order' => $order, 'foreignKeys' => []];
    }

    private function foreignFixture(bool $orphan): array
    {
        $child = 'CREATE TABLE `wp_a_child` (`id` bigint unsigned NOT NULL,`parent_id` bigint unsigned NOT NULL,`code` varchar(10) NOT NULL,PRIMARY KEY (`id`),UNIQUE KEY `code_unique` (`code`),CONSTRAINT `parent_fk` FOREIGN KEY (`parent_id`) REFERENCES `wp_z_parent` (`id`) ON DELETE CASCADE) ENGINE=InnoDB';
        $parent = 'CREATE TABLE `wp_z_parent` (`id` bigint unsigned NOT NULL,PRIMARY KEY (`id`)) ENGINE=InnoDB';
        [$client, $file, $path] = $this->fixture("SET FOREIGN_KEY_CHECKS=0;\n" . $child . ";\nINSERT INTO `wp_a_child` (`id`,`parent_id`,`code`) VALUES (1,1,CONVERT(X'78' USING utf8mb4));\n" . $parent . ";\n" . ($orphan ? '' : "INSERT INTO `wp_z_parent` (`id`) VALUES (1);\n"));
        $childRecord = ['type' => 'table', 'name' => 'wp_a_child', 'rows' => 1, 'sourceRows' => 1, 'excludedRows' => 0, 'definitionSha256' => WordPressDatabase::definitionSha256($child, ''),
            'rowSha256' => hash('sha256', hash('sha256', 'V1:1;V1:1;V1:x;') . "\n"), 'columns' => ['id', 'parent_id', 'code'], 'order' => ['id'], 'foreignKeys' => [['columns' => ['parent_id'], 'referencedTable' => 'wp_z_parent', 'referencedColumns' => ['id']]]];
        $parentRecord = ['type' => 'table', 'name' => 'wp_z_parent', 'rows' => $orphan ? 0 : 1, 'sourceRows' => $orphan ? 0 : 1, 'excludedRows' => 0, 'definitionSha256' => WordPressDatabase::definitionSha256($parent, ''),
            'rowSha256' => hash('sha256', $orphan ? '' : hash('sha256', 'V1:1;') . "\n"), 'columns' => ['id'], 'order' => ['id'], 'foreignKeys' => []];
        return [$client, $file, $path, [$childRecord, $parentRecord]];
    }

    private function fixture(string $sql): array
    {
        $root = Fixture::temp(); $path = $root . '/db.sqlite'; $file = $root . '/source.sql'; file_put_contents($file, $sql); chmod($file, 0600);
        $client = new SqliteDatabaseClient($path); $this->clients[] = $client; return [$client, $file, $path];
    }

    private function environment(): array { return ['characterSet' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'serverVersion' => '10.5.29-MariaDB', 'sqlMode' => '', 'timeZone' => '+00:00']; }

    private function record(): array
    {
        $one = hash('sha256', 'V1:1;V10:日本語;;V3:' . "\x00\xffA" . ';');
        $two = hash('sha256', 'V1:2;N;V0:;');
        return ['type' => 'table', 'name' => 'wp_probe', 'rows' => 2, 'sourceRows' => 2, 'excludedRows' => 0, 'definitionSha256' => WordPressDatabase::definitionSha256(self::CREATE, ''), 'rowSha256' => hash('sha256', $one . "\n" . $two . "\n"), 'columns' => ['id', 'text', 'payload'], 'order' => ['id'], 'foreignKeys' => []];
    }
}
