<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use WordPressR2Backup\RecoveryMetadataMap;

/** 合成の鍵・値だけを使い、実データ・実暗号文・復元原本には触れない。 */
final class RecoveryMetadataMapTest extends TestCase
{
    /** @var RecoveryMetadataMap[] */
    private array $maps = [];

    protected function tearDown(): void
    {
        foreach ($this->maps as $map) { $map->close(); }
        $this->maps = [];
        Fixture::cleanup();
    }

    public function backends(): array { return [['sqlite'], ['files'], ['legacy'], ['auto']]; }

    /** @dataProvider backends */
    public function testEveryBackendKeepsTheSameMapContract(string $backend): void
    {
        $map = $this->map($backend);
        $expected = [];
        for ($number = 0; $number < 600; ++$number) {
            $key = ($number % 3 === 0 ? '内容/' : 'entry:roots/wp-content/') . $number;
            $expected[$key] = ['type' => 'file', 'size' => $number, 'sha256' => hash('sha256', (string) $number), 'nested' => ['ok' => true, 'ratio' => 0.5]];
            $map->put($key, $expected[$key]);
        }
        foreach ($expected as $key => $value) { self::assertSame($value, $map->get($key)); }
        self::assertNull($map->get('absent'));
        $actual = iterator_to_array($map->entries()); ksort($actual); ksort($expected);
        self::assertSame($expected, $actual);
        $map->put('entry:roots/wp-content/1', $expected['entry:roots/wp-content/1']);
        try { $map->put('entry:roots/wp-content/1', ['type' => 'other']); self::fail('A different value must be rejected.'); }
        catch (RuntimeException $error) { self::assertSame($expected['entry:roots/wp-content/1'], $map->get('entry:roots/wp-content/1')); }
        try { $map->put('entry:roots/wp-content/1', ['size' => '1']); self::fail('A loosely equal value must be rejected.'); }
        catch (RuntimeException $error) { self::assertTrue(true); }
        $map->put('entry:roots/wp-content/1', ['type' => 'replaced'], true);
        self::assertSame(['type' => 'replaced'], $map->get('entry:roots/wp-content/1'));
    }

    /** @dataProvider backends */
    public function testInvalidKeysAndOversizedValuesAreRejectedAndStrictlyBounded(string $backend): void
    {
        $map = $this->map($backend);
        foreach (['', "nul\0key", str_repeat('k', 8193), "\xff\xfe"] as $key) {
            try { $map->put($key, ['x' => 1]); self::fail('An invalid key must be rejected.'); }
            catch (RuntimeException $error) { self::assertTrue(true); }
            try { $map->get($key); self::fail('An invalid key must be rejected.'); }
            catch (RuntimeException $error) { self::assertTrue(true); }
        }
        $limit = 262144 - strlen(json_encode(['v' => ''], JSON_UNESCAPED_SLASHES));
        $map->put('largest', ['v' => str_repeat('x', $limit)]);
        self::assertSame($limit, strlen($map->get('largest')['v']));
        $this->expectException(RuntimeException::class);
        $map->put('too-large', ['v' => str_repeat('x', $limit + 1)]);
    }

    public function testBackendSelectionIsExplicitAndReported(): void
    {
        self::assertSame('files', $this->map('files')->backend());
        self::assertSame('legacy', $this->map('legacy')->backend());
        self::assertSame('sqlite', $this->map('sqlite')->backend());
        self::assertSame('sqlite', $this->map('auto')->backend());
        $this->expectException(RuntimeException::class);
        RecoveryMetadataMap::create(Fixture::temp() . '/unknown', 'automatic');
    }

    public function testTheSqliteFileIsAloneOwnerOnlyAndNewDirectoriesAreRequired(): void
    {
        $directory = Fixture::temp() . '/index';
        $map = $this->track(RecoveryMetadataMap::create($directory, 'sqlite'));
        $map->put('a', ['x' => 1]);
        self::assertSame(0700, fileperms($directory) & 0777);
        self::assertSame(['index.sqlite'], array_values(array_diff(scandir($directory), ['.', '..'])));
        self::assertSame(0600, fileperms($directory . '/index.sqlite') & 0777);
        $map->seal();
        self::assertSame(['index.sqlite'], array_values(array_diff(scandir($directory), ['.', '..'])));
        $this->expectException(RuntimeException::class);
        RecoveryMetadataMap::create($directory, 'sqlite');
    }

    public function testEntriesArePagedInKeyOrderAndAbandonedEnumerationsCanBeClosed(): void
    {
        $map = $this->map('sqlite');
        $ids = [];
        for ($number = 0; $number < 700; ++$number) { $key = 'page-' . $number; $map->put($key, ['n' => $number]); $ids[hash('sha256', $key, true)] = $key; }
        ksort($ids, SORT_STRING);
        self::assertSame(array_values($ids), array_keys(iterator_to_array($map->entries())));
        foreach ($map->entries() as $ignoredKey => $ignoredValue) { break; }
        $map->put('after-break', ['ok' => true]);
        foreach ($map->entries() as $ignoredKey => $ignoredValue) {
            try { $map->put('during', ['no' => true]); self::fail('Writes during enumeration must be rejected.'); }
            catch (RuntimeException $error) { self::assertNull($map->get('during')); }
            break;
        }
        $generator = $map->entries(); $generator->current();
        $map->close();
        $map->close();
        $this->expectException(RuntimeException::class);
        $map->get('page-1');
    }

    /** @dataProvider backends */
    public function testSealMakesTheMapReadOnlyAndMayBeRepeated(string $backend): void
    {
        $map = $this->map($backend);
        for ($number = 0; $number < 300; ++$number) { $map->put('s' . $number, ['n' => $number]); }
        $map->seal();
        $map->seal();
        self::assertSame(['n' => 17], $map->get('s17'));
        self::assertCount(300, iterator_to_array($map->entries()));
        try { $map->put('late', ['x' => 1]); self::fail('A sealed map must reject writes.'); }
        catch (RuntimeException $error) { self::assertNull($map->get('late')); }
    }

    public function testSealDetectsSameInodeSameLengthRawChangesAfterTheFirstSeal(): void
    {
        $directory = Fixture::temp() . '/tamper';
        $map = $this->track(RecoveryMetadataMap::create($directory, 'sqlite'));
        for ($number = 0; $number < 50; ++$number) { $map->put('t' . $number, ['n' => $number, 'marker' => $number === 7 ? 'SENTINEL-AAAAAAAA' : 'plain']); }
        $map->seal();
        $path = $directory . '/index.sqlite';
        $inode = fileinode($path);
        $contents = file_get_contents($path);
        // 分割で古いページに同じ文字列が残り得るため、現れる全ての位置を同じ長さのまま書き換える
        $offsets = [];
        for ($offset = strpos($contents, 'SENTINEL-AAAAAAAA'); $offset !== false; $offset = strpos($contents, 'SENTINEL-AAAAAAAA', $offset + 1)) { $offsets[] = $offset; }
        self::assertNotSame([], $offsets);
        $stream = fopen($path, 'r+b');
        foreach ($offsets as $offset) { fseek($stream, $offset + 9); fwrite($stream, 'B'); }
        fclose($stream);
        self::assertSame($inode, fileinode($path));
        try { $map->seal(); self::fail('A same-length raw change must be detected by the fresh reopen.'); }
        catch (RuntimeException $error) { self::assertStringNotContainsString($directory, $error->getMessage()); }
        $this->expectException(RuntimeException::class);
        $map->get('t7');
    }

    /** @dataProvider rawChanges */
    public function testSealDetectsDeletedInsertedSwappedAndRewrittenRows(string $change): void
    {
        $directory = Fixture::temp() . '/raw-' . $change;
        $map = $this->track(RecoveryMetadataMap::create($directory, 'sqlite'));
        for ($number = 0; $number < 20; ++$number) { $map->put('r' . $number, ['n' => $number]); }
        $map->seal();
        $raw = new \SQLite3($directory . '/index.sqlite', SQLITE3_OPEN_READWRITE);
        $raw->enableExceptions(true);
        $rows = []; $result = $raw->query('SELECT id,k,v,mac FROM records ORDER BY id');
        while (($row = $result->fetchArray(SQLITE3_ASSOC)) !== false) { $rows[] = $row; }
        $result->finalize();
        if ($change === 'delete') { $raw->exec('DELETE FROM records WHERE id=x\'' . bin2hex($rows[3]['id']) . '\''); }
        elseif ($change === 'insert') {
            $statement = $raw->prepare('INSERT INTO records(id,k,v,mac) VALUES(:id,:k,:v,:mac)');
            $statement->bindValue(':id', hash('sha256', 'extra', true), SQLITE3_BLOB); $statement->bindValue(':k', 'extra', SQLITE3_BLOB);
            $statement->bindValue(':v', '{"n":1}', SQLITE3_BLOB); $statement->bindValue(':mac', str_repeat("\1", 32), SQLITE3_BLOB);
            $statement->execute()->finalize(); $statement->close();
        } elseif ($change === 'swap') {
            $statement = $raw->prepare('UPDATE records SET mac=:mac WHERE id=:id');
            $statement->bindValue(':mac', $rows[1]['mac'], SQLITE3_BLOB); $statement->bindValue(':id', $rows[2]['id'], SQLITE3_BLOB);
            $statement->execute()->finalize(); $statement->close();
        } else {
            $raw->exec('UPDATE records SET v=\'{"n":999}\' WHERE id=x\'' . bin2hex($rows[4]['id']) . '\'');
        }
        $raw->close();
        $this->expectException(RuntimeException::class);
        $map->seal();
    }

    public function rawChanges(): array { return [['delete'], ['insert'], ['swap'], ['rewrite']]; }

    public function testReplacingTheDatabasePathIsRejectedAndTheMapStillCloses(): void
    {
        $directory = Fixture::temp() . '/identity';
        $map = $this->track(RecoveryMetadataMap::create($directory, 'sqlite'));
        $map->put('a', ['x' => 1]);
        rename($directory . '/index.sqlite', $directory . '/moved.sqlite');
        try { $map->get('a'); self::fail('A moved database file must be rejected.'); }
        catch (RuntimeException $error) { self::assertTrue(true); }
        $map->close();
        self::assertFileExists($directory . '/moved.sqlite');
    }

    public function testTheMapDirectoryMustBeNewAndLinkFree(): void
    {
        $base = Fixture::temp();
        mkdir($base . '/real', 0700);
        symlink($base . '/real', $base . '/link');
        try { RecoveryMetadataMap::create($base . '/link/index', 'sqlite'); self::fail('A symbolic link ancestor must be rejected.'); }
        catch (RuntimeException $error) { self::assertTrue(true); }
        $this->expectException(RuntimeException::class);
        RecoveryMetadataMap::create($base . '/real', 'sqlite');
    }

    public function testFailedSqliteCreationNeverFallsBackToAnotherBackend(): void
    {
        $directory = Fixture::temp() . '/occupied';
        mkdir($directory, 0700);
        foreach (['sqlite', 'auto'] as $backend) {
            try { RecoveryMetadataMap::create($directory, $backend); self::fail('An existing directory must not be accepted.'); }
            catch (RuntimeException $error) { self::assertSame([], array_values(array_diff(scandir($directory), ['.', '..']))); }
        }
    }

    public function testSqliteErrorsBecomeAFixedFailureAndStopTheMapWhileInputErrorsDoNot(): void
    {
        $directory = Fixture::temp() . '/failure';
        $map = $this->track(RecoveryMetadataMap::create($directory, 'sqlite'));
        $map->put('a', ['x' => 1]);
        $deep = ['v' => 1];
        for ($level = 0; $level < 20; ++$level) { $deep = ['n' => $deep]; }
        try { $map->put('deep', $deep); self::fail('A value deeper than the limit must be rejected.'); }
        catch (RuntimeException $error) { self::assertNotInstanceOf(\JsonException::class, $error); }
        self::assertSame(['x' => 1], $map->get('a'));
        $map->seal();
        $raw = new \SQLite3($directory . '/index.sqlite', SQLITE3_OPEN_READWRITE);
        $raw->exec('DROP TABLE records');
        $raw->close();
        try { $map->get('a'); self::fail('A SQL error must be reported.'); }
        catch (RuntimeException $error) {
            self::assertSame('The recovery metadata index failed.', $error->getMessage());
            self::assertNotNull($error->getPrevious());
        }
        try { $map->get('a'); self::fail('A failed map must stay stopped.'); }
        catch (RuntimeException $error) { self::assertSame('The recovery metadata map failed verification.', $error->getMessage()); }
    }

    public function testEveryReopenedConnectionAppliesAndReadsBackItsSettings(): void
    {
        $map = $this->map('sqlite');
        $map->put('a', ['x' => 1]);
        $map->seal();
        $database = (new \ReflectionProperty($map, 'db')); $database->setAccessible(true);
        $db = $database->getValue($map);
        self::assertSame(2, $db->querySingle('PRAGMA temp_store'));
        self::assertSame(0, $db->querySingle('PRAGMA mmap_size'));
        self::assertSame(-2048, $db->querySingle('PRAGMA cache_size'));
        self::assertSame(1, $db->querySingle('PRAGMA query_only'));
        self::assertSame(1, $db->querySingle('PRAGMA cell_size_check'));
        if (\SQLite3::version()['versionNumber'] >= 3031000) { self::assertSame(0, $db->querySingle('PRAGMA trusted_schema')); }
        $map->seal();
        $db = $database->getValue($map);
        self::assertSame(2, $db->querySingle('PRAGMA temp_store'));
        self::assertSame(0, $db->querySingle('PRAGMA mmap_size'));
    }

    public function testManyLargeValuesAreEnumeratedAndSealedWithinTheMemoryLimitAndPageBudget(): void
    {
        $previous = ini_get('memory_limit');
        $directory = Fixture::temp() . '/large';
        try {
            ini_set('memory_limit', '128M');
            $map = $this->track(RecoveryMetadataMap::create($directory, 'sqlite'));
            $total = 0;
            for ($number = 0; $number < 700; ++$number) { $value = ['n' => $number, 'v' => str_repeat(chr(97 + $number % 26), 200000)]; $map->put('large-' . $number, $value); $total += 200000; }
            self::assertGreaterThan(128 * 1048576, $total);
            $baseline = memory_get_usage(); $peak = 0; $seen = 0; $numbers = [];
            foreach ($map->entries() as $key => $value) { $peak = max($peak, memory_get_usage() - $baseline); ++$seen; $numbers[$value['n']] = true; }
            self::assertSame(700, $seen);
            self::assertCount(700, $numbers);
            self::assertLessThan(8 * 1048576, $peak);
            $map->seal();
            self::assertSame(['n' => 5, 'v' => str_repeat('f', 200000)], $map->get('large-5'));
        } finally { ini_set('memory_limit', (string) $previous); }
    }

    public function testShortBatchesAreNotMistakenForTheEndAndTheEmptyBatchEndsEnumeration(): void
    {
        $map = $this->map('sqlite');
        $expected = [];
        for ($number = 0; $number < 40; ++$number) { $key = 'mixed-' . $number; $expected[$key] = ['v' => str_repeat('m', $number % 5 === 0 ? 250000 : 10)]; $map->put($key, $expected[$key]); }
        $actual = iterator_to_array($map->entries());
        ksort($actual); ksort($expected);
        self::assertSame($expected, $actual);
        $map->seal();
        self::assertCount(40, iterator_to_array($map->entries()));
    }

    public function testCloseFinishesEveryStatementAndTheDatabaseBeforeTheFileIsUnpinned(): void
    {
        $map = $this->map('sqlite');
        $map->put('a', ['x' => 1]);
        $read = static function (string $name) use ($map) { $property = new \ReflectionProperty($map, $name); $property->setAccessible(true); return $property; };
        $statements = $read('statements');
        $statements->setValue($map, [
            'one' => new class { public function close() { throw new \RuntimeException('simulated statement close failure'); } },
            'two' => new class { public $closed = false; public function close() { $this->closed = true; return true; } },
        ]);
        $db = $read('db')->getValue($map);
        $pin = $read('pin')->getValue($map);
        try { $map->close(); self::fail('A statement close failure must be reported.'); }
        catch (RuntimeException $error) { self::assertStringContainsString('could not be closed', $error->getMessage()); }
        self::assertNull($read('db')->getValue($map));
        self::assertSame([], $read('statements')->getValue($map));
        self::assertFalse(is_resource($pin));
        $this->expectException(\Throwable::class);
        $db->querySingle('SELECT 1');
    }

    public function testADatabaseCloseFailureKeepsTheHandleAndPinUntilALaterCloseSucceeds(): void
    {
        $map = $this->map('sqlite');
        $map->put('a', ['x' => 1]);
        $db = new \ReflectionProperty($map, 'db'); $db->setAccessible(true);
        $pin = new \ReflectionProperty($map, 'pin'); $pin->setAccessible(true);
        $real = $db->getValue($map);
        $failing = new class(':memory:') extends \SQLite3 {
            public $fail = true;
            public function close() { return $this->fail ? false : parent::close(); }
        };
        $db->setValue($map, $failing);
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try { $map->close(); self::fail('A database close failure must be reported.'); }
            catch (RuntimeException $error) { self::assertStringContainsString('could not be closed', $error->getMessage()); }
            self::assertSame($failing, $db->getValue($map));
            self::assertTrue(is_resource($pin->getValue($map)));
        }
        try { $map->get('a'); self::fail('A map whose database could not be closed must reject normal operations.'); }
        catch (RuntimeException $error) { self::assertSame('The recovery metadata map failed verification.', $error->getMessage()); }
        $failing->fail = false;
        $map->close();
        self::assertNull($db->getValue($map));
        self::assertNull($pin->getValue($map));
        $map->close();
        $real->close();
    }

    /** @dataProvider extraRowIds */
    public function testSealRejectsExtraRowsWhoseIdsTheOrderedScanCannotReach(string $idSql): void
    {
        $directory = Fixture::temp() . '/extra-' . bin2hex(random_bytes(3));
        $map = $this->track(RecoveryMetadataMap::create($directory, 'sqlite'));
        for ($number = 0; $number < 30; ++$number) { $map->put('e' . $number, ['n' => $number]); }
        $map->seal();
        $script = '$d = new SQLite3($argv[1]); if (!$d->exec("INSERT INTO records(id,k,v,mac) VALUES(' . $idSql . ', x\'6b\', x\'7b7d\', x\'00\')")) { exit(3); } $d->close();';
        $process = proc_open([PHP_BINARY, '-r', $script, $directory . '/index.sqlite'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $output);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('outside the sealed set');
        $map->seal();
    }

    public function extraRowIds(): array { return [["x''"], ["'text-id'"], ['5']]; }

    public function testOversizedStoredRowsAreRejectedBeforeTheirDataIsSelected(): void
    {
        foreach (['v' => 'v=zeroblob(300000)', 'k' => 'k=zeroblob(9000)', 'mac' => 'mac=zeroblob(31)'] as $column => $assignment) {
            $directory = Fixture::temp() . '/oversize-' . $column;
            $map = $this->track(RecoveryMetadataMap::create($directory, 'sqlite'));
            $map->put('victim', ['x' => 1]); $map->put('other', ['x' => 2]);
            $map->seal();
            $raw = new \SQLite3($directory . '/index.sqlite', SQLITE3_OPEN_READWRITE);
            $raw->exec('UPDATE records SET ' . $assignment . ' WHERE id=x\'' . hash('sha256', 'victim') . '\'');
            $raw->close();
            try { $map->get('victim'); self::fail('An oversized stored ' . $column . ' must be rejected on read.'); }
            catch (RuntimeException $error) { self::assertSame('Invalid recovery metadata record.', $error->getMessage()); }
            try { iterator_to_array($map->entries()); self::fail('An oversized stored ' . $column . ' must be rejected on enumeration.'); }
            catch (RuntimeException $error) { self::assertTrue(true); }
            try { $map->seal(); self::fail('A sealed set with an oversized ' . $column . ' must fail verification.'); }
            catch (RuntimeException $error) { self::assertTrue(true); }
        }
    }

    private function map(string $backend): RecoveryMetadataMap
    {
        return $this->track(RecoveryMetadataMap::create(Fixture::temp() . '/index', $backend));
    }

    private function track(RecoveryMetadataMap $map): RecoveryMetadataMap
    {
        $this->maps[] = $map;
        return $map;
    }
}
