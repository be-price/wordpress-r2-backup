<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use WordPressR2Backup\PrivacyPolicy;
use WordPressR2Backup\WordPressDatabase;

final class WordPressDatabaseTest extends TestCase
{
    private function full(): PrivacyPolicy { return new PrivacyPolicy(['version' => 2, 'preset' => 'full']); }

    private function capture(WordPressDatabase $source): array
    {
        return $source->withReadLock(static function (WordPressDatabase $source): array {
            $sql = ''; $metadata = [];
            foreach ($source->records() as $record) {
                if ($record['type'] === 'sql') { foreach ($record['chunks'] as $chunk) { $sql .= $chunk; } }
                else { $metadata[] = $record; }
            }
            return ['sql' => $sql, 'tables' => $metadata, 'verification' => $source->verification()];
        });
    }

    public function testCompleteCapturePreservesBytesGeneratedDefinitionsAndStableVerification(): void
    {
        $wpdb = new V4DatabaseWpdbFake();
        $source = new WordPressDatabase($wpdb, $this->full());
        $summary = json_encode($source->preflight());
        self::assertStringNotContainsString('private@example.test', $summary);
        self::assertStringNotContainsString('CREATE TABLE', $summary);
        $first = $this->capture($source);
        $second = $this->capture($source);
        self::assertSame($first, $second);
        self::assertSame($first['tables'], $source->verification());
        self::assertSame('+09:00', $wpdb->timeZone);
        self::assertFalse($wpdb->locked);
        self::assertStringContainsString('GENERATED ALWAYS AS', $first['sql']);
        self::assertStringNotContainsString('`stamp`,`calculated`) VALUES', $first['sql']);
        self::assertStringContainsString('`note`,`stamp`) VALUES', $first['sql']);
        self::assertStringContainsString("X'00ff27", $first['sql']);
        self::assertStringContainsString("CONVERT(X'" . bin2hex('private@example.test') . "' USING latin1)", $first['sql']);
        self::assertStringContainsString("SET SESSION time_zone='+00:00'", $first['sql']);
        self::assertSame(2, $first['tables'][0]['rows']);
        self::assertSame(hash('sha256', V4DatabaseWpdbFake::digest($wpdb->tables['records']['rows'][0]) . "\n" . V4DatabaseWpdbFake::digest($wpdb->tables['records']['rows'][1]) . "\n"), $first['tables'][0]['rowSha256']);
        self::assertSame(hash('sha256', $wpdb->tables['records']['create'] . ";\n"), $first['tables'][0]['definitionSha256']);
        self::assertSame('+09:00', $source->environment()['timeZone']);
    }

    public function testReadLockMaintenanceRequiresConsumedRecordsAndRejectsAReplacementConnection(): void
    {
        $wpdb = new V4DatabaseWpdbFake(); $source = new WordPressDatabase($wpdb, $this->full());
        try { $source->maintainReadLock(); self::fail('An unlocked connection was maintained.'); }
        catch (RuntimeException $error) { self::assertStringContainsString('fully consumed', $error->getMessage()); }
        $source->withReadLock(static function (WordPressDatabase $source) use ($wpdb): void {
            $records = $source->records(); $records->current();
            try { $source->maintainReadLock(); self::fail('An active SQL stream accepted another database query.'); }
            catch (RuntimeException $error) { self::assertStringContainsString('fully consumed', $error->getMessage()); }
            foreach ($records as $record) { if ($record['type'] === 'sql') { foreach ($record['chunks'] as $chunk) {} } }
            $source->maintainReadLock();
            $connection = $wpdb->connection; $wpdb->connection = 'replacement';
            try { $source->maintainReadLock(); self::fail('A reconnected session was accepted.'); }
            catch (RuntimeException $error) { self::assertStringContainsString('connection changed', $error->getMessage()); }
            finally { $wpdb->connection = $connection; }
        });
        self::assertFalse($wpdb->locked);
    }

    public function testRowScanningSendsHeartbeatsForMaintenanceRefresh(): void
    {
        $wpdb = new V4DatabaseWpdbFake();
        for ($i = 0; $i < 2600; ++$i) { $wpdb->tables['records']['rows'][] = $wpdb->tables['records']['rows'][0]; }
        foreach ([$this->full(), new PrivacyPolicy(['version' => 2, 'preset' => 'custom', 'rows' => ['records' => ['id' => ['1' => 'exclude', '2' => 'exclude']]]])] as $index => $policy) {
            $source = new WordPressDatabase($wpdb, $policy);
            $beats = 0;
            $source->onHeartbeat(static function () use (&$beats): void { ++$beats; });
            $result = $this->capture($source);
            self::assertGreaterThanOrEqual(2, $beats);
            if ($index === 1) {
                self::assertSame(0, $result['tables'][0]['rows']);
                self::assertSame(2602, $result['tables'][0]['excludedRows']);
            }
        }
    }

    public function testLegacyDetectedValueRequiresADecisionWithoutExposingItsContents(): void
    {
        $wpdb = new V4DatabaseWpdbFake();
        $wpdb->tables['wp_posts'] = $wpdb->tables['records'];
        $wpdb->tables['wp_posts']['create'] = str_replace('`records`', '`wp_posts`', $wpdb->tables['records']['create']);
        unset($wpdb->tables['records']);
        try {
            $this->capture(new WordPressDatabase($wpdb, new PrivacyPolicy([])));
            self::fail('A detected legacy personal value was accepted.');
        } catch (RuntimeException $error) {
            self::assertSame('A database value needs a privacy decision.', $error->getMessage());
            self::assertStringNotContainsString('private@example.test', $error->getMessage());
        }
        self::assertFalse($wpdb->locked);
    }

    public function testLargeValuesHaveBoundedHexChunksWithoutAnInventedFourMiBLimit(): void
    {
        $wpdb = new V4DatabaseWpdbFake();
        $wpdb->tables['records']['rows'] = [['id' => '1', 'payload' => str_repeat('z', 5 * 1024 * 1024), 'note' => '', 'stamp' => '2020-01-02 03:04:05.123456', 'calculated' => '2']];
        $source = new WordPressDatabase($wpdb, $this->full());
        $result = $source->withReadLock(static function (WordPressDatabase $source): array {
            $maximum = 0; $bytes = 0;
            foreach ($source->records() as $record) {
                if ($record['type'] !== 'sql') { continue; }
                foreach ($record['chunks'] as $chunk) { $maximum = max($maximum, strlen($chunk)); $bytes += strlen($chunk); }
            }
            return [$maximum, $bytes, $source->verification()];
        });
        self::assertLessThanOrEqual(131072, $result[0]);
        self::assertGreaterThan(10 * 1024 * 1024, $result[1]);
        self::assertSame(1, $result[2][0]['rows']);
    }

    public function testFailureReleasesTheLockAndRestoresTheOriginalTimezone(): void
    {
        $wpdb = new V4DatabaseWpdbFake();
        $source = new WordPressDatabase($wpdb, $this->full());
        try {
            $source->withReadLock(static function (): void { throw new \LogicException('synthetic failure'); });
            self::fail('Capture failure was swallowed.');
        } catch (\LogicException $error) { self::assertSame('synthetic failure', $error->getMessage()); }
        self::assertFalse($wpdb->locked);
        self::assertSame('+09:00', $wpdb->timeZone);
        self::assertSame(1, $wpdb->unlocks);
    }

    public function testFailedRecaptureCannotReturnThePreviousSuccessfulVerification(): void
    {
        $wpdb = new V4DatabaseWpdbFake();
        $source = new WordPressDatabase($wpdb, $this->full());
        $this->capture($source);
        try { $source->withReadLock(static function (): void { throw new \LogicException('synthetic failure'); }); }
        catch (\LogicException $expected) {}
        $this->expectException(RuntimeException::class);
        $source->verification();
    }

    public function testFailedLockNeverInvokesTheOperation(): void
    {
        $wpdb = new V4DatabaseWpdbFake(); $wpdb->failLock = true;
        $called = false;
        try { (new WordPressDatabase($wpdb, $this->full()))->withReadLock(static function () use (&$called): void { $called = true; }); self::fail('Failed lock was accepted.'); }
        catch (RuntimeException $error) { self::assertFalse($called); }
        self::assertSame(0, $wpdb->unlocks);
        self::assertSame('+09:00', $wpdb->timeZone);
    }

    public function testConnectionReplacementCannotPublishOrUnlockTheReplacementConnection(): void
    {
        $wpdb = new V4DatabaseWpdbFake();
        try {
            (new WordPressDatabase($wpdb, $this->full()))->withReadLock(static function () use ($wpdb): void { $wpdb->connection = '2'; });
            self::fail('A changed connection was accepted.');
        } catch (RuntimeException $error) { self::assertStringContainsString('connection changed', $error->getMessage()); }
        self::assertSame(0, $wpdb->unlocks);
    }

    public function testDefinitionRaceStopsBeforeTheOperationAndReleasesTheLock(): void
    {
        $wpdb = new V4DatabaseWpdbFake(); $wpdb->changeDefinitionOnLock = true;
        $called = false;
        try { (new WordPressDatabase($wpdb, $this->full()))->withReadLock(static function () use (&$called): void { $called = true; }); self::fail('A definition race was accepted.'); }
        catch (RuntimeException $error) { self::assertFalse($called); }
        self::assertFalse($wpdb->locked);
    }

    public function testSkippedDataGeneratorCannotBecomeSuccessfulVerification(): void
    {
        $wpdb = new V4DatabaseWpdbFake();
        try {
            (new WordPressDatabase($wpdb, $this->full()))->withReadLock(static function (WordPressDatabase $source): void { foreach ($source->records() as $record) { /* deliberately skip SQL chunks */ } });
            self::fail('Unconsumed SQL was accepted.');
        } catch (RuntimeException $error) { self::assertStringContainsString('not fully consumed', $error->getMessage()); }
        self::assertFalse($wpdb->locked);
    }

    public function testRecordsCannotReadWithoutTheLock(): void
    {
        $this->expectException(RuntimeException::class);
        (new WordPressDatabase(new V4DatabaseWpdbFake(), $this->full()))->records()->current();
    }

    public function testVerifiedReplayRetainsTheSameLockAndExactSql(): void
    {
        $wpdb = new V4DatabaseWpdbFake(); $source = new WordPressDatabase($wpdb, $this->full());
        $source->withReadLock(function (WordPressDatabase $source) use ($wpdb): void {
            $first = $this->consumeRecords($source->records()); $verification = $source->verification();
            self::assertTrue($wpdb->locked); self::assertSame(0, $wpdb->unlocks);
            self::assertSame($first, $this->consumeRecords($source->replayRecords()));
            self::assertSame($verification, $source->verification());
            try { $this->consumeRecords($source->replayRecords()); self::fail('A second replay was accepted.'); }
            catch (RuntimeException $expected) { self::assertStringContainsString('one complete first pass', $expected->getMessage()); }
        });
        self::assertFalse($wpdb->locked); self::assertSame(1, $wpdb->unlocks);
    }

    public function testReplayRejectsValueChangesUnderTheSameConnection(): void
    {
        $wpdb = new V4DatabaseWpdbFake(); $source = new WordPressDatabase($wpdb, $this->full());
        try {
            $source->withReadLock(function (WordPressDatabase $source) use ($wpdb): void {
                $this->consumeRecords($source->records());
                $wpdb->tables['records']['rows'][0]['payload'] = 'changed';
                $this->consumeRecords($source->replayRecords());
            });
            self::fail('Changed replay rows were accepted.');
        } catch (RuntimeException $expected) { self::assertStringContainsString('replay differs', $expected->getMessage()); }
        self::assertFalse($wpdb->locked);
        $this->expectException(RuntimeException::class); $source->verification();
    }

    public function testReplayRequiresACompleteInitialPassAndTheHeldConnection(): void
    {
        $source = new WordPressDatabase(new V4DatabaseWpdbFake(), $this->full());
        $this->expectException(RuntimeException::class);
        $source->withReadLock(static function (WordPressDatabase $source): void { $source->replayRecords()->current(); });
    }

    private function consumeRecords(iterable $records): array
    {
        $sql = ''; $metadata = [];
        foreach ($records as $record) {
            if ($record['type'] === 'sql') { foreach ($record['chunks'] as $chunk) { $sql .= $chunk; } }
            else { $metadata[] = $record; }
        }
        return ['sql' => $sql, 'metadata' => $metadata];
    }

    /** @dataProvider unsupportedInventory */
    public function testUnsupportedOrInvisibleDefinitionsAreRefusedBeforeCapture(string $condition): void
    {
        $wpdb = new V4DatabaseWpdbFake();
        if ($condition === 'view') { $wpdb->view = true; }
        elseif ($condition === 'privileges') { $wpdb->grants = 'GRANT SELECT, LOCK TABLES ON `fixture`.* TO synthetic'; }
        else { $wpdb->unsupported = $condition; }
        $called = false;
        try { (new WordPressDatabase($wpdb, $this->full()))->withReadLock(static function () use (&$called): void { $called = true; }); self::fail('Unsupported metadata was accepted.'); }
        catch (RuntimeException $error) { self::assertFalse($called); }
        self::assertSame(0, $wpdb->unlocks);
    }

    public function unsupportedInventory(): array { return [['view'], ['TRIGGERS'], ['ROUTINES'], ['EVENTS'], ['privileges']]; }

    public function testForeignKeysArePreservedButCrossDatabaseOrOrphanedDependenciesFail(): void
    {
        $wpdb = new V4DatabaseWpdbFake();
        $wpdb->foreignKeys = [['CONSTRAINT_NAME' => 'synthetic_fk', 'COLUMN_NAME' => 'id', 'REFERENCED_TABLE_SCHEMA' => 'fixture', 'REFERENCED_TABLE_NAME' => 'records', 'REFERENCED_COLUMN_NAME' => 'id', 'ORDINAL_POSITION' => '1']];
        $capture = $this->capture(new WordPressDatabase($wpdb, $this->full()));
        self::assertSame([['columns' => ['id'], 'referencedTable' => 'records', 'referencedColumns' => ['id']]], $capture['tables'][0]['foreignKeys']);
        $wpdb->foreignKeys[0]['REFERENCED_TABLE_SCHEMA'] = 'elsewhere';
        try { (new WordPressDatabase($wpdb, $this->full()))->preflight(); self::fail('A cross-database dependency was accepted.'); }
        catch (RuntimeException $error) { self::assertStringContainsString('another database', $error->getMessage()); }
        $wpdb->foreignKeys[0]['REFERENCED_TABLE_SCHEMA'] = 'fixture'; $wpdb->orphans = 1;
        $this->expectException(RuntimeException::class);
        (new WordPressDatabase($wpdb, $this->full()))->preflight();
    }

    public function testTableExclusionKeepsTheDefinitionAndZeroRowHash(): void
    {
        $wpdb = new V4DatabaseWpdbFake();
        $policy = new PrivacyPolicy(['version' => 2, 'preset' => 'custom', 'tables' => ['records' => 'exclude']]);
        $capture = $this->capture(new WordPressDatabase($wpdb, $policy));
        self::assertStringContainsString('CREATE TABLE `records`', $capture['sql']);
        self::assertStringNotContainsString('INSERT INTO `records`', $capture['sql']);
        self::assertSame(0, $capture['tables'][0]['rows']);
        self::assertSame(2, $capture['tables'][0]['excludedRows']);
        self::assertSame(hash('sha256', ''), $capture['tables'][0]['rowSha256']);
    }

    public function testExplicitRowIncludeOverridesTheTableExclusion(): void
    {
        $wpdb = new V4DatabaseWpdbFake();
        $policy = new PrivacyPolicy(['version' => 2, 'preset' => 'custom', 'tables' => ['records' => 'exclude'], 'rows' => ['records' => ['id' => ['2' => 'include']]]]);
        $capture = $this->capture(new WordPressDatabase($wpdb, $policy));
        self::assertSame(1, $capture['tables'][0]['rows']);
        self::assertSame(1, $capture['tables'][0]['excludedRows']);
        self::assertStringNotContainsString(bin2hex('private@example.test'), $capture['sql']);
        self::assertSame(hash('sha256', V4DatabaseWpdbFake::digest($wpdb->tables['records']['rows'][1]) . "\n"), $capture['tables'][0]['rowSha256']);
    }

    public function testExplicitRowIncludeAlsoOverridesTheCategoryFieldExclusion(): void
    {
        $wpdb = new V4DatabaseWpdbFake();
        $wpdb->tables['wp_users'] = $wpdb->tables['records']; unset($wpdb->tables['records']);
        $policy = new PrivacyPolicy(['version' => 2, 'preset' => 'custom', 'categories' => ['wordpress_users' => 'exclude'], 'rows' => ['wp_users' => ['id' => ['1' => 'include']]]]);
        $capture = $this->capture(new WordPressDatabase($wpdb, $policy));
        self::assertSame(1, $capture['tables'][0]['rows']);
        self::assertStringContainsString(bin2hex('private@example.test'), $capture['sql']);
        self::assertSame(hash('sha256', V4DatabaseWpdbFake::digest($wpdb->tables['wp_users']['rows'][0]) . "\n"), $capture['tables'][0]['rowSha256']);
    }

    public function testRowExclusionIsCountedWithoutHashingTheExcludedValue(): void
    {
        $wpdb = new V4DatabaseWpdbFake();
        $policy = new PrivacyPolicy(['version' => 2, 'preset' => 'custom', 'rows' => ['records' => ['id' => ['1' => 'exclude']]]]);
        $capture = $this->capture(new WordPressDatabase($wpdb, $policy));
        self::assertSame(1, $capture['tables'][0]['rows']);
        self::assertSame(1, $capture['tables'][0]['excludedRows']);
        self::assertStringNotContainsString(bin2hex('private@example.test'), $capture['sql']);
    }

    public function testNullableFieldExclusionPreservesOtherValuesAndHashesTheReplacement(): void
    {
        $wpdb = new V4DatabaseWpdbFake();
        array_pop($wpdb->tables['records']['columns']);
        foreach ($wpdb->tables['records']['rows'] as &$row) { unset($row['calculated']); } unset($row);
        $policy = new PrivacyPolicy(['version' => 2, 'preset' => 'custom', 'fields' => ['records.note' => 'exclude']]);
        $capture = $this->capture(new WordPressDatabase($wpdb, $policy));
        $expected = '';
        foreach ($wpdb->tables['records']['rows'] as $row) { $row['note'] = null; $expected .= V4DatabaseWpdbFake::digest($row) . "\n"; }
        self::assertSame(hash('sha256', $expected), $capture['tables'][0]['rowSha256']);
        self::assertStringNotContainsString(bin2hex('private@example.test'), $capture['sql']);
        self::assertStringContainsString("X'00ff275c',NULL", $capture['sql']);
    }

    public function testForeignKeyTablesWithAnExclusionFailDuringPreflight(): void
    {
        $wpdb = new V4DatabaseWpdbFake();
        $wpdb->foreignKeys = [['CONSTRAINT_NAME' => 'synthetic_fk', 'COLUMN_NAME' => 'id', 'REFERENCED_TABLE_SCHEMA' => 'fixture', 'REFERENCED_TABLE_NAME' => 'records', 'REFERENCED_COLUMN_NAME' => 'id', 'ORDINAL_POSITION' => '1']];
        $policy = new PrivacyPolicy(['version' => 2, 'preset' => 'custom', 'rows' => ['records' => ['id' => ['1' => 'exclude']]]]);
        $this->expectException(RuntimeException::class);
        (new WordPressDatabase($wpdb, $policy))->preflight();
    }

    public function testPathExclusionDoesNotInvalidateAnUnchangedForeignKeyDatabase(): void
    {
        $wpdb = new V4DatabaseWpdbFake();
        $wpdb->foreignKeys = [['CONSTRAINT_NAME' => 'synthetic_fk', 'COLUMN_NAME' => 'id', 'REFERENCED_TABLE_SCHEMA' => 'fixture', 'REFERENCED_TABLE_NAME' => 'records', 'REFERENCED_COLUMN_NAME' => 'id', 'ORDINAL_POSITION' => '1']];
        $expected = $this->capture(new WordPressDatabase($wpdb, $this->full()));
        $policy = new PrivacyPolicy(['version' => 2, 'preset' => 'custom', 'paths' => ['cache' => 'exclude']]);
        $actual = $this->capture(new WordPressDatabase($wpdb, $policy));
        self::assertSame($expected, $actual);
        self::assertSame('+09:00', $wpdb->timeZone);
    }

    public function testExcludingAnUnrelatedTableDoesNotInvalidateForeignKeyRows(): void
    {
        $wpdb = new V4DatabaseWpdbFake();
        $wpdb->foreignKeys = [['CONSTRAINT_NAME' => 'synthetic_fk', 'COLUMN_NAME' => 'id', 'REFERENCED_TABLE_SCHEMA' => 'fixture', 'REFERENCED_TABLE_NAME' => 'records', 'REFERENCED_COLUMN_NAME' => 'id', 'ORDINAL_POSITION' => '1']];
        $wpdb->tables['unrelated'] = $wpdb->tables['records'];
        $policy = new PrivacyPolicy(['version' => 2, 'preset' => 'custom', 'tables' => ['unrelated' => 'exclude']]);
        $capture = $this->capture(new WordPressDatabase($wpdb, $policy));
        self::assertSame(2, $capture['tables'][0]['rows']);
        self::assertSame(0, $capture['tables'][1]['rows']);
    }

    public function testLegacyExclusionOfAllRelatedRowsKeepsTheForeignKeyDefinition(): void
    {
        $wpdb = new V4DatabaseWpdbFake();
        $wpdb->foreignKeys = [['CONSTRAINT_NAME' => 'synthetic_fk', 'COLUMN_NAME' => 'id', 'REFERENCED_TABLE_SCHEMA' => 'fixture', 'REFERENCED_TABLE_NAME' => 'records', 'REFERENCED_COLUMN_NAME' => 'id', 'ORDINAL_POSITION' => '1']];
        $policy = new PrivacyPolicy(['tables' => ['records' => 'exclude']]);
        $capture = $this->capture(new WordPressDatabase($wpdb, $policy));
        self::assertSame(0, $capture['tables'][0]['rows']);
        self::assertCount(1, $capture['tables'][0]['foreignKeys']);
        self::assertStringContainsString('CREATE TABLE', $capture['sql']);
        self::assertStringNotContainsString('INSERT INTO', $capture['sql']);
    }

    public function testVerificationQueryUsesOnlyValidatedIdentifiersAndBinaryNullOrdering(): void
    {
        $sql = WordPressDatabase::verificationQuery('odd`table', ['id', 'bytes'], ['id']);
        self::assertStringContainsString('FROM `odd``table`', $sql);
        self::assertStringContainsString("IF(`bytes` IS NULL,'N;'", $sql);
        self::assertStringContainsString('OCTET_LENGTH(CAST(`bytes` AS BINARY))', $sql);
        self::assertStringContainsString('ORDER BY (`id` IS NOT NULL),CAST(`id` AS BINARY),(`bytes` IS NOT NULL),CAST(`bytes` AS BINARY)', $sql);
        $this->expectException(RuntimeException::class);
        WordPressDatabase::verificationQuery('records', ['id'], ['absent']);
    }
}

/** In-memory adapter. It has no network, SQL process, credentials, or disk data. */
final class V4DatabaseWpdbFake
{
    public string $prefix = 'wp_';
    public string $last_error = '';
    public string $connection = '1';
    public string $timeZone = '+09:00';
    public bool $locked = false;
    public bool $failLock = false;
    public bool $changeDefinitionOnLock = false;
    public bool $view = false;
    public int $unlocks = 0;
    public string $unsupported = '';
    public string $grants = 'GRANT ALL PRIVILEGES ON `fixture`.* TO synthetic';
    public array $foreignKeys = [];
    public array $foreignKeyTables = ['records'];
    public int $orphans = 0;
    public array $tables;

    public function __construct()
    {
        $this->tables = ['records' => ['columns' => [
            ['Field' => 'id', 'Type' => 'bigint unsigned', 'Key' => 'PRI', 'Extra' => '', 'Null' => 'NO', 'Collation' => null],
            ['Field' => 'payload', 'Type' => 'longblob', 'Key' => '', 'Extra' => '', 'Null' => 'YES', 'Collation' => null],
            ['Field' => 'note', 'Type' => 'varchar(255)', 'Key' => '', 'Extra' => '', 'Null' => 'YES', 'Collation' => 'latin1_swedish_ci'],
            ['Field' => 'stamp', 'Type' => 'timestamp(6)', 'Key' => '', 'Extra' => 'DEFAULT_GENERATED', 'Null' => 'NO', 'Collation' => null],
            ['Field' => 'calculated', 'Type' => 'int', 'Key' => '', 'Extra' => 'STORED GENERATED', 'Null' => 'YES', 'Collation' => null],
        ], 'create' => 'CREATE TABLE `records` (`id` bigint unsigned NOT NULL PRIMARY KEY,`payload` longblob,`note` varchar(255) CHARACTER SET latin1,`stamp` timestamp(6),`calculated` int GENERATED ALWAYS AS (`id` + 1) STORED) ENGINE=InnoDB',
            'rows' => [
                ['id' => '1', 'payload' => "\0\xff'\\", 'note' => 'private@example.test', 'stamp' => '2020-01-02 03:04:05.123456', 'calculated' => '2'],
                ['id' => '2', 'payload' => '', 'note' => null, 'stamp' => '0000-00-00 00:00:00.000000', 'calculated' => '3'],
            ]]];
    }

    public function get_results(string $sql, string $format): array
    {
        if (strpos($sql, 'SELECT VERSION()') === 0) { return [['serverVersion' => '8.0.synthetic', 'sqlMode' => 'STRICT_TRANS_TABLES,NO_ZERO_DATE', 'timeZone' => $this->timeZone, 'characterSet' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci']]; }
        if ($sql === 'SHOW GRANTS') { return [[$this->grants]]; }
        if ($sql === 'SHOW FULL TABLES') {
            $result = [];
            foreach ($this->tables as $name => $table) { $result[] = [$name, 'BASE TABLE']; }
            if ($this->view) { $result[] = ['synthetic_view', 'VIEW']; }
            return $result;
        }
        if (strpos($sql, 'information_schema.KEY_COLUMN_USAGE') !== false) {
            preg_match('/TABLE_NAME=X\'([a-f0-9]*)\'/D', $sql, $name);
            return in_array(hex2bin($name[1]), $this->foreignKeyTables, true) ? $this->foreignKeys : [];
        }
        if (preg_match('/\ASHOW (FULL COLUMNS FROM|CREATE TABLE|INDEX FROM) `([^`]+)`\z/D', $sql, $m)) {
            if ($m[1] === 'FULL COLUMNS FROM') { return $this->tables[$m[2]]['columns']; }
            if ($m[1] === 'CREATE TABLE') { return [[$m[2], $this->tables[$m[2]]['create']]]; }
            return [['Non_unique' => '0', 'Column_name' => 'id', 'Key_name' => 'PRIMARY', 'Seq_in_index' => '1']];
        }
        if (preg_match('/ AS `(__wrb4_row_sha256(?:_\d+)?)` FROM `([^`]+)`.* LIMIT (\d+),1\z/D', $sql, $m)) {
            $row = $this->tables[$m[2]]['rows'][(int) $m[3]] ?? null;
            if ($row === null) { return []; }
            $row[$m[1]] = self::digest($row);
            return [$row];
        }
        throw new RuntimeException('Unexpected synthetic metadata request.');
    }

    public function get_var(string $sql)
    {
        if ($sql === 'SELECT @@SESSION.lock_wait_timeout') { return '31536000'; }
        if ($sql === 'SELECT DATABASE()') { return 'fixture'; }
        if ($sql === 'SELECT CONNECTION_ID()') { return $this->connection; }
        if (preg_match('/information_schema\.(TRIGGERS|ROUTINES|EVENTS)/', $sql, $m)) { return $this->unsupported === $m[1] ? '1' : '0'; }
        if (strpos($sql, ' LEFT JOIN ') !== false) { return (string) $this->orphans; }
        if (preg_match('/\ASELECT COUNT\(\*\) FROM `([^`]+)`\z/D', $sql, $m)) { return (string) count($this->tables[$m[1]]['rows']); }
        if (strpos($sql, 'SELECT COALESCE(MAX(') === 0 && preg_match('/ FROM `([^`]+)`\z/D', $sql, $m)) {
            $maximum = 0;
            foreach ($this->tables[$m[1]]['rows'] as $row) { $maximum = max($maximum, array_sum(array_map(static function ($value): int { return strlen((string) $value); }, $row))); }
            return (string) $maximum;
        }
        throw new RuntimeException('Unexpected synthetic scalar request.');
    }

    public function query(string $sql)
    {
        if (preg_match('/\ASET SESSION lock_wait_timeout=[0-9]+\z/D', $sql)) { return 0; }
        if (strpos($sql, 'LOCK TABLES ') === 0) {
            if ($this->failLock) { return false; }
            $this->locked = true;
            if ($this->changeDefinitionOnLock) { $this->tables['records']['create'] .= ' COMMENT=\'changed\''; }
            return 0;
        }
        if ($sql === 'UNLOCK TABLES') { $this->locked = false; $this->unlocks++; return 0; }
        if (preg_match('/\ASET SESSION time_zone=\'([^\']+)\'\z/D', $sql, $m)) { $this->timeZone = $m[1]; return 0; }
        throw new RuntimeException('Unexpected synthetic state request.');
    }

    public static function digest(array $row): string
    {
        $hash = hash_init('sha256');
        foreach ($row as $value) { hash_update($hash, $value === null ? 'N;' : 'V' . strlen((string) $value) . ':' . (string) $value . ';'); }
        return hash_final($hash);
    }
}
