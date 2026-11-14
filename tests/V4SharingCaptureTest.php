<?php
declare(strict_types=1);

namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use WordPressR2Backup\AgeProcess;
use WordPressR2Backup\LocalRepository;
use WordPressR2Backup\Manifest;
use WordPressR2Backup\MetadataMap;
use WordPressR2Backup\PrivacyPolicy;
use WordPressR2Backup\SourceRoots;
use WordPressR2Backup\V4Capture;
use WordPressR2Backup\V4ContentIndex;
use WordPressR2Backup\V4Format;
use WordPressR2Backup\V4Restorer;

require_once __DIR__ . '/WordPressDatabaseTest.php';

final class V4SharingCaptureTest extends TestCase
{
    private array $keys;
    private AgeProcess $age;
    private array $scope;
    private V4SharingRepository $repository;
    private V4ContentIndex $index;
    private string $indexDirectory;

    protected function setUp(): void
    {
        $this->keys = Fixture::keys(); $this->age = new AgeProcess(Fixture::runtime()['age']);
        $this->scope = ['accountId' => str_repeat('a', 32), 'bucket' => 'synthetic-sharing', 'unitId' => str_repeat('c', 64),
            'keySetId' => Manifest::keySetId(array_column($this->keys, 'public')), 'encoding' => V4Format::ENCODING];
        $this->repository = new V4SharingRepository(Fixture::temp(), $this->scope['unitId']);
        $this->indexDirectory = Fixture::temp();
        $this->index = V4ContentIndex::openOrCreate($this->indexDirectory, $this->scope, $this->repository);
    }

    protected function tearDown(): void { Fixture::cleanup(); }

    public function testIdenticalAndRenamedContentsReuseEveryPayloadAndRestoreWithoutTheIndex(): void
    {
        $source = Fixture::temp(); Fixture::write($source, 'original.php', 'synthetic application');
        Fixture::write($source, 'copy.php', 'synthetic application');
        $wpdb = new V4DatabaseWpdbFake();
        $first = $this->capture($source, $wpdb); $this->upload($first);
        self::assertNotEmpty($this->packs($first));
        $this->repository->verified = [];
        $second = $this->capture($source, $wpdb);
        self::assertSame(0, $second['sharing']['newBytes']);
        self::assertSame(0, $second['sharing']['newPacks']);
        self::assertSame($first['sharing']['newBytes'], $second['sharing']['reusedBytes']);
        self::assertSame(0, $second['sharing']['repackedBytes']);
        self::assertSame($this->packKeys($first), $this->packKeys($second));
        self::assertSame(0, count(array_filter($this->packs($second), static fn(array $object): bool => $object['cipherFile'] !== null)));
        self::assertSame(count($this->packs($first)), count($this->repository->verified));
        self::assertSame($first['manifest']['counts']['contents'], $second['manifest']['counts']['contents']);
        $this->upload($second);
        rename($source . '/original.php', $source . '/renamed.php');
        $third = $this->capture($source, $wpdb); $this->upload($third);
        self::assertSame($this->packKeys($first), $this->packKeys($third));
        unlink($source . '/renamed.php'); unlink($source . '/copy.php'); rmdir($source); $wpdb->tables = [];
        unset($this->index);
        $destination = Fixture::temp() . '/restored'; $identity = fopen($this->keys[1]['identity'], 'rb');
        try {
            (new V4Restorer($this->age, LocalRepository::forRecovery($this->repository->directory, $this->scope['unitId'])))
                ->recover($third['manifest'], ['key' => 'manifests/' . $third['manifest']['snapshotId'] . '.age', 'bytes' => 1, 'sha256' => hash('sha256', 'synthetic')], $identity, $destination, null, null, false);
        } finally { fclose($identity); }
        self::assertSame('synthetic application', file_get_contents($destination . '/roots/r0001/renamed.php'));
        self::assertSame('synthetic application', file_get_contents($destination . '/roots/r0001/copy.php'));
        self::assertFileDoesNotExist($destination . '/roots/r0001/original.php');
        self::assertNotSame(lstat($destination . '/roots/r0001/renamed.php')['ino'], lstat($destination . '/roots/r0001/copy.php')['ino']);
    }

    public function testChangingOneFileAndTableRetainsUnchangedPayloadReferences(): void
    {
        $source = Fixture::temp(); Fixture::write($source, 'first.php', 'first body'); Fixture::write($source, 'stable.php', 'stable body');
        $wpdb = new V4DatabaseWpdbFake();
        $other = $wpdb->tables['records']; $other['create'] = str_replace('`records`', '`stable_records`', $other['create']);
        $wpdb->tables['stable_records'] = $other;
        $first = $this->capture($source, $wpdb); $this->upload($first); $firstRecords = $this->records($first);
        file_put_contents($source . '/first.php', 'changed first body');
        $wpdb->tables['records']['rows'][0]['payload'] = 'changed row';
        $second = $this->capture($source, $wpdb); $this->upload($second); $secondRecords = $this->records($second);
        self::assertSame($this->fileRef($firstRecords, 'stable.php'), $this->fileRef($secondRecords, 'stable.php'));
        self::assertNotSame($this->fileRef($firstRecords, 'first.php'), $this->fileRef($secondRecords, 'first.php'));
        self::assertSame($this->sqlRefs($firstRecords, 'stable_records'), $this->sqlRefs($secondRecords, 'stable_records'));
        self::assertNotSame($this->sqlRefs($firstRecords, 'records'), $this->sqlRefs($secondRecords, 'records'));
        self::assertNotEmpty(array_filter($this->packs($second), static fn(array $object): bool => $object['cipherFile'] === null));
        self::assertNotEmpty(array_filter($this->packs($second), static fn(array $object): bool => $object['cipherFile'] !== null));
    }

    public function testFilteredGenerationRepacksMixedOldPackWithoutTheExcludedMember(): void
    {
        $source = Fixture::temp(); Fixture::write($source, 'public.php', 'allowed body'); Fixture::write($source, 'private.php', 'excluded body');
        $wpdb = new V4DatabaseWpdbFake(); $first = $this->capture($source, $wpdb); $this->upload($first);
        $rules = ['version' => 2, 'preset' => 'custom', 'paths' => ['private.php' => 'exclude']];
        $filtered = $this->capture($source, $wpdb, $rules); $this->upload($filtered);
        $records = $this->records($filtered); $firstRecords = $this->records($first);
        self::assertNotSame($this->fileRef($firstRecords, 'public.php')['pack'], $this->fileRef($records, 'public.php')['pack']);
        $excluded = V4Format::contentId(strlen('excluded body'), hash('sha256', 'excluded body'));
        foreach ($records['packs'] as $pack) { self::assertNotContains($excluded, array_column($pack['members'], 'id')); }
        self::assertSame($this->sqlRefs($firstRecords, 'records'), $this->sqlRefs($records, 'records'));
        self::assertSame(1, $filtered['manifest']['counts']['files']);
        $again = $this->capture($source, $wpdb, $rules);
        self::assertSame($this->packKeys($filtered), $this->packKeys($again));
        self::assertSame(0, count(array_filter($this->packs($again), static fn(array $object): bool => $object['cipherFile'] !== null)));
    }

    public function testChangedRootRequiresAllPackMembersEvenForACompletePolicy(): void
    {
        $source = Fixture::temp(); Fixture::write($source, 'public.php', 'allowed body'); Fixture::write($source, 'private.php', 'excluded body');
        $wpdb = new V4DatabaseWpdbFake(); $first = $this->capture($source, $wpdb); $this->upload($first);
        $other = Fixture::temp(); Fixture::write($other, 'public.php', 'allowed body');
        $next = $this->capture($other, $wpdb); $nextRecords = $this->records($next);
        $firstRecords = $this->records($first);
        self::assertNotSame($this->fileRef($firstRecords, 'public.php')['pack'], $this->fileRef($nextRecords, 'public.php')['pack']);
        $excluded = V4Format::contentId(strlen('excluded body'), hash('sha256', 'excluded body'));
        foreach ($nextRecords['packs'] as $pack) { self::assertNotContains($excluded, array_column($pack['members'], 'id')); }
    }

    public function testFullGenerationCountsOnlyReferencedIdsWhenAnOldPackHasUnusedMembers(): void
    {
        $source = Fixture::temp(); Fixture::write($source, 'kept.php', 'kept body'); Fixture::write($source, 'removed.php', 'old body');
        $wpdb = new V4DatabaseWpdbFake(); $first = $this->capture($source, $wpdb); $this->upload($first);
        unlink($source . '/removed.php');
        $next = $this->capture($source, $wpdb); $this->upload($next);
        self::assertSame($this->packKeys($first), $this->packKeys($next));
        self::assertSame($first['manifest']['counts']['contents'] - 1, $next['manifest']['counts']['contents']);
        $identity = fopen($this->keys[0]['identity'], 'rb');
        try {
            $inspection = (new V4Restorer($this->age, LocalRepository::forRecovery($this->repository->directory, $this->scope['unitId'])))
                ->inspect($next['manifest'], $identity, Fixture::temp());
        } finally { fclose($identity); }
        self::assertSame(1, $inspection['capture']['counts']['files']);
        self::assertSame($next['manifest']['counts']['contents'], $inspection['capture']['counts']['contents']);
    }

    public function testUnknownPackProvenanceRequiresMembershipProofWithTheSameRoot(): void
    {
        $source = Fixture::temp(); Fixture::write($source, 'kept.php', 'kept body'); Fixture::write($source, 'removed.php', 'old body');
        $wpdb = new V4DatabaseWpdbFake(); $first = $this->capture($source, $wpdb); $this->upload($first);
        $records = $this->records($first); $verified = [];
        foreach ($records['packs'] as $pack) { $verified[] = ['descriptor' => $pack, 'rootScopeSha256' => null]; }
        $this->index = V4ContentIndex::rebuild(Fixture::temp(), $this->scope, $this->repository, $verified);
        unlink($source . '/removed.php');
        $next = $this->capture($source, $wpdb); $nextRecords = $this->records($next);
        self::assertNotSame($this->fileRef($records, 'kept.php')['pack'], $this->fileRef($nextRecords, 'kept.php')['pack']);
        $removed = V4Format::contentId(strlen('old body'), hash('sha256', 'old body'));
        foreach ($nextRecords['packs'] as $pack) { self::assertNotContains($removed, array_column($pack['members'], 'id')); }
    }

    public function testMembershipPrepassAndCaptureUseIdenticalLargeSqlAndFileChunks(): void
    {
        $source = Fixture::temp(); Fixture::write($source, 'large.php', str_repeat('p', V4Format::MAX_CONTENT_BYTES) . 'tail');
        Fixture::write($source, 'excluded.txt', 'private body');
        $wpdb = new V4DatabaseWpdbFake(); $wpdb->tables['records']['rows'][0]['payload'] = str_repeat('d', V4Format::MAX_CONTENT_BYTES + 101);
        $first = $this->capture($source, $wpdb); $this->upload($first);
        $rules = ['version' => 2, 'preset' => 'custom', 'paths' => ['excluded.txt' => 'exclude']];
        $filtered = $this->capture($source, $wpdb, $rules); $this->upload($filtered);
        $firstRecords = $this->records($first); $filteredRecords = $this->records($filtered);
        self::assertSame($this->sqlRefs($firstRecords, 'records'), $this->sqlRefs($filteredRecords, 'records'));
        self::assertSame($first['manifest']['database'], $filtered['manifest']['database']);
        $next = $this->capture($source, $wpdb, $rules);
        self::assertSame($this->packKeys($filtered), $this->packKeys($next));
        self::assertSame(0, count(array_filter($this->packs($next), static fn(array $object): bool => $object['cipherFile'] !== null)));
    }

    public function testFilteredCaptureTakesFileIdsFromTheScanAndMatchesTheLockedFilePass(): void
    {
        $source = Fixture::temp(); Fixture::write($source, 'large.php', str_repeat('p', V4Format::MAX_CONTENT_BYTES) . 'tail');
        Fixture::write($source, 'stable.php', 'stable body'); Fixture::write($source, 'copy.php', 'stable body');
        Fixture::write($source, 'private.php', 'private body');
        $wpdb = new V4DatabaseWpdbFake(); $first = $this->capture($source, $wpdb); $this->upload($first);
        $rules = ['version' => 2, 'preset' => 'custom', 'paths' => ['private.php' => 'exclude']];
        $events = [];
        $scanned = $this->captureWith($source, $wpdb, $rules, static function (array $event) use (&$events): void { $events[] = $event; });
        $locked = $this->captureWith($source, $wpdb, $rules, null, true);
        self::assertNotContains(['phase' => 'sharing-preflight', 'heartbeat' => true], $events);
        self::assertContains(['phase' => 'sharing-preflight', 'heartbeat' => true], $this->lockedEvents);
        foreach (['newBytes', 'reusedBytes', 'repackedBytes', 'newPacks', 'reusedPacks', 'repackedPacks'] as $field) {
            self::assertSame($locked['sharing'][$field], $scanned['sharing'][$field], $field);
        }
        self::assertSame($locked['manifest']['counts'], $scanned['manifest']['counts']);
        self::assertSame($locked['manifest']['database'], $scanned['manifest']['database']);
        self::assertSame($this->contentIds($this->records($locked)), $this->contentIds($this->records($scanned)));
        self::assertGreaterThan(0, $scanned['sharing']['repackedBytes']);
        $excluded = V4Format::contentId(strlen('private body'), hash('sha256', 'private body'));
        foreach ($this->records($scanned)['packs'] as $pack) { self::assertNotContains($excluded, array_column($pack['members'], 'id')); }
    }

    /** @dataProvider mutationPoints */
    public function testFileChangedAfterTheScanOrDuringCaptureFailsWithoutPublishingOrReadyPacks(string $point): void
    {
        $source = Fixture::temp(); Fixture::write($source, 'a.php', 'initial body'); Fixture::write($source, 'b.php', 'other body');
        Fixture::write($source, 'excluded.txt', 'private body');
        $wpdb = new V4DatabaseWpdbFake(); $first = $this->capture($source, $wpdb); $this->upload($first);
        $stored = array_keys($this->repository->objects); $rules = ['version' => 2, 'preset' => 'custom', 'paths' => ['excluded.txt' => 'exclude']];
        $mtime = filemtime($source . '/a.php'); $changed = false;
        try {
            $this->captureWith($source, $wpdb, $rules, static function (array $event) use ($point, $source, $mtime, &$changed): void {
                $atScanEnd = $event['phase'] === 'scan' && isset($event['tableCount']);
                if ($changed || ($point === 'scan-end' ? !$atScanEnd : $event['phase'] !== 'capture')) { return; }
                $changed = true; file_put_contents($source . '/a.php', 'mutated body'); touch($source . '/a.php', $mtime);
            });
            self::fail('A changed source file was captured.');
        } catch (RuntimeException $error) { self::assertStringContainsString('changed', $error->getMessage()); }
        self::assertTrue($changed);
        self::assertSame($stored, array_keys($this->repository->objects));
        $mutated = V4Format::contentId(strlen('mutated body'), hash('sha256', 'mutated body'));
        $this->index->withLock(function () use ($mutated): void { self::assertNull($this->index->lookup($mutated)); });
        $next = $this->captureWith($source, $wpdb, $rules);
        foreach ($this->packs($next) as $object) {
            if ($object['cipherFile'] === null) { self::assertContains($object['object']['key'], $stored); }
        }
    }

    public static function mutationPoints(): array { return ['after the scan, before the lock' => ['scan-end'], 'during the locked capture' => ['capture']]; }

    public function testLockedMembershipPrepassRejectsChangedFileWithoutPublishing(): void
    {
        $source = Fixture::temp(); Fixture::write($source, 'a.php', 'initial body'); Fixture::write($source, 'excluded.txt', 'private body');
        $wpdb = new V4DatabaseWpdbFake(); $first = $this->capture($source, $wpdb); $this->upload($first);
        $stored = array_keys($this->repository->objects); $rules = ['version' => 2, 'preset' => 'custom', 'paths' => ['excluded.txt' => 'exclude']];
        $mtime = filemtime($source . '/a.php'); $changed = false;
        try {
            $this->index->withLock(function () use ($source, $wpdb, $rules, $mtime, &$changed): void {
                $capture = new V4Capture(new SourceRoots($source), $wpdb, new PrivacyPolicy($rules), Fixture::temp());
                $capture->scan();
                $capture->capture($this->age, array_column($this->keys, 'public'), bin2hex(random_bytes(32)), $this->scope, static function (array $event) use ($source, $mtime, &$changed): void {
                    if (!$changed && ($event['phase'] ?? '') === 'sharing-preflight' && ($event['heartbeat'] ?? false)) {
                        $changed = true; file_put_contents($source . '/a.php', 'mutated body'); touch($source . '/a.php', $mtime);
                    }
                }, $this->index);
            });
            self::fail('A changed source file was captured.');
        } catch (RuntimeException $error) { self::assertStringContainsString('changed', strtolower($error->getMessage())); }
        self::assertTrue($changed);
        self::assertSame($stored, array_keys($this->repository->objects));
    }

    public function testCaptureMapsCannotBeReopenedWhileTheSharedIndexStaysDurable(): void
    {
        // The excluded file shares the code lane, so its old pack must be repacked and the reuse map is consulted.
        $source = Fixture::temp(); Fixture::write($source, 'a.php', 'shared body'); Fixture::write($source, 'excluded.php', 'private body');
        $wpdb = new V4DatabaseWpdbFake(); $this->upload($this->capture($source, $wpdb));
        $private = Fixture::temp();
        $result = $this->captureIn($private, $source, $wpdb, ['version' => 2, 'preset' => 'custom', 'paths' => ['excluded.php' => 'exclude']]);
        self::assertGreaterThan(0, $result['sharing']['repackedPacks']);
        $maps = [];
        foreach (['source-map', 'selected-content-map', 'content-map', 'reuse-map', 'policy-*'] as $name) {
            $found = glob($private . '/capture-*/' . $name, GLOB_ONLYDIR) ?: [];
            if ($name !== 'policy-*') { self::assertCount(1, $found, $name); }
            $maps = array_merge($maps, $found);
        }
        foreach ($maps as $map) {
            self::assertFileExists($map . '/owner');
            try { MetadataMap::open($map); self::fail('A capture map was opened again: ' . basename($map)); }
            catch (RuntimeException $expected) { self::assertSame('Invalid metadata map identity.', $expected->getMessage()); }
        }
        foreach (['packs', 'contents'] as $name) { self::assertSame(array_values(iterator_to_array(MetadataMap::open($this->indexDirectory . '/' . $name)->entries(), false)) !== [], true, $name); }
    }

    /** @dataProvider corruptedCaptureMaps */
    public function testCaptureMapChangedDuringCaptureFailsWithoutPublishingOrReadyPacks(string $map, string $change, string $point): void
    {
        $source = Fixture::temp();
        Fixture::write($source, 'a.php', 'duplicated body'); Fixture::write($source, 'b.php', 'duplicated body');
        Fixture::write($source, 'c.php', 'reused body'); Fixture::write($source, 'd.php', 'last body');
        Fixture::write($source, 'excluded.txt', 'private body');
        $wpdb = new V4DatabaseWpdbFake(); $this->upload($this->capture($source, $wpdb));
        $stored = $this->repository->objects; $private = Fixture::temp(); $changed = 0;
        $rules = ['version' => 2, 'preset' => 'custom', 'paths' => ['excluded.txt' => 'exclude']];
        $corrupt = static function () use ($private, $map, $change, &$changed): void {
            $directory = glob($private . '/capture-*/' . $map, GLOB_ONLYDIR)[0] ?? null;
            if ($directory === null) { return; }
            $files = $change === 'registry' ? [$directory . '/partitions.json'] : (glob($directory . '/leaf-*.json') ?: []);
            foreach ($files as $file) {
                $bytes = (string) file_get_contents($file);
                if (substr($bytes, -1) !== 'x') { file_put_contents($file, $bytes . 'x'); ++$changed; }
            }
        };
        try {
            $this->captureIn($private, $source, $wpdb, $rules, static function (array $event) use ($point, $corrupt): void {
                // Leaves rewritten after a change are changed again at the next event, so every later read meets a changed partition.
                $scanEnd = $event['phase'] === 'scan' && isset($event['tableCount']);
                $afterFirstFile = $event['phase'] === 'capture' && ($event['files'] ?? 0) >= 1;
                if ($point === 'scan-end' ? ($scanEnd || $event['phase'] !== 'scan') : $afterFirstFile) { $corrupt(); }
            });
            self::fail('A capture with a changed private map completed.');
        } catch (RuntimeException | \JsonException $expected) { self::assertMatchesRegularExpression('/metadata|Syntax error/i', $expected->getMessage()); }
        self::assertGreaterThan(0, $changed);
        self::assertSame($stored, $this->repository->objects);
        $repacked = V4Format::contentId(strlen('reused body'), hash('sha256', 'reused body'));
        $this->index->withLock(function () use ($repacked, $stored): void {
            self::assertContains($this->index->lookup($repacked)['descriptor']['object']['key'] ?? null, array_keys($stored));
        });
        $next = $this->captureIn(Fixture::temp(), $source, $wpdb, $rules);
        foreach ($this->packs($next) as $object) { if ($object['cipherFile'] === null) { self::assertArrayHasKey($object['object']['key'], $stored); } }
    }

    public static function corruptedCaptureMaps(): array
    {
        return ['source-map partitions' => ['source-map', 'leaf', 'capture'], 'source-map registry' => ['source-map', 'registry', 'capture'],
            'content-map partitions' => ['content-map', 'leaf', 'capture'], 'reuse-map registry' => ['reuse-map', 'registry', 'capture'],
            'selected-content-map partitions' => ['selected-content-map', 'leaf', 'scan-end'], 'selected-content-map registry' => ['selected-content-map', 'registry', 'scan-end']];
    }

    public function testUploadAndReadyMarkingNeedNoCaptureMapAfterCapture(): void
    {
        $source = Fixture::temp(); Fixture::write($source, 'a.php', 'shared body'); Fixture::write($source, 'new.php', 'new body');
        Fixture::write($source, 'excluded.txt', 'private body');
        $wpdb = new V4DatabaseWpdbFake(); $this->upload($this->capture($source, $wpdb));
        $private = Fixture::temp(); $rules = ['version' => 2, 'preset' => 'custom', 'paths' => ['excluded.txt' => 'exclude']];
        $result = $this->captureIn($private, $source, $wpdb, $rules);
        $removed = 0;
        foreach (['source-map', 'selected-content-map', 'content-map', 'reuse-map', 'policy-*'] as $name) {
            foreach (glob($private . '/capture-*/' . $name, GLOB_ONLYDIR) ?: [] as $directory) { self::removeTree($directory); ++$removed; }
        }
        self::assertGreaterThanOrEqual(4, $removed);
        foreach ($result['objects'] as $object) {
            if ($object['cipherFile'] !== null) { self::assertSame($object['object']['bytes'], filesize($object['cipherFile'])); self::assertSame($object['object']['sha256'], hash_file('sha256', $object['cipherFile'])); }
        }
        $this->upload($result);
        foreach ($result['objects'] as $object) { $this->repository->verify($object['object'] + []); }
        $next = $this->captureIn(Fixture::temp(), $source, $wpdb, $rules);
        self::assertSame(0, $next['sharing']['newPacks']);
        self::assertSame($this->packKeys($result), $this->packKeys($next));
    }

    public function testUnexpectedMissingReadyPackStopsInsteadOfRecapturingItsContents(): void
    {
        $source = Fixture::temp(); Fixture::write($source, 'index.php', 'application'); $wpdb = new V4DatabaseWpdbFake();
        $first = $this->capture($source, $wpdb); $this->upload($first);
        unset($this->repository->objects[$this->packs($first)[0]['object']['key']]);
        $this->expectException(RuntimeException::class); $this->expectExceptionMessage('missing');
        $this->capture($source, $wpdb);
    }

    public function testDifferentAccountCannotUseTheSameIndex(): void
    {
        $source = Fixture::temp(); Fixture::write($source, 'index.php', 'application');
        $this->scope['accountId'] = str_repeat('b', 32);
        $this->expectException(\InvalidArgumentException::class);
        $this->capture($source, new V4DatabaseWpdbFake());
    }

    private function capture(string $source, V4DatabaseWpdbFake $wpdb, array $rules = ['version' => 2, 'preset' => 'full']): array
    {
        return $this->index->withLock(function () use ($source, $wpdb, $rules): array {
            return (new V4Capture(new SourceRoots($source), $wpdb, new PrivacyPolicy($rules), Fixture::temp()))
                ->capture($this->age, array_column($this->keys, 'public'), bin2hex(random_bytes(32)), $this->scope, null, $this->index);
        });
    }

    private array $lockedEvents = [];

    /** $scanFirst runs scan() separately, which keeps the previous locked file pass for membership proof. */
    private function captureWith(string $source, V4DatabaseWpdbFake $wpdb, array $rules, ?callable $progress = null, bool $scanFirst = false): array
    {
        return $this->index->withLock(function () use ($source, $wpdb, $rules, $progress, $scanFirst): array {
            $capture = new V4Capture(new SourceRoots($source), $wpdb, new PrivacyPolicy($rules), Fixture::temp());
            if ($scanFirst) {
                $this->lockedEvents = []; $capture->scan();
                $progress = function (array $event): void { $this->lockedEvents[] = $event; };
            }
            return $capture->capture($this->age, array_column($this->keys, 'public'), bin2hex(random_bytes(32)), $this->scope, $progress, $this->index);
        });
    }

    private function captureIn(string $private, string $source, V4DatabaseWpdbFake $wpdb, array $rules, ?callable $progress = null): array
    {
        return $this->index->withLock(function () use ($private, $source, $wpdb, $rules, $progress): array {
            return (new V4Capture(new SourceRoots($source), $wpdb, new PrivacyPolicy($rules), $private))
                ->capture($this->age, array_column($this->keys, 'public'), bin2hex(random_bytes(32)), $this->scope, $progress, $this->index);
        });
    }

    private static function removeTree(string $path): void
    {
        foreach (new \FilesystemIterator($path) as $entry) {
            if ($entry->isDir() && !$entry->isLink()) { self::removeTree($entry->getPathname()); } else { unlink($entry->getPathname()); }
        }
        rmdir($path);
    }

    private function contentIds(array $records): array
    {
        $ids = [];
        foreach (array_merge($records['filesystem'], $records['database']) as $record) {
            if (isset($record['content']['id'])) { $ids[] = ($record['path'] ?? $record['name'] ?? 'part') . ':' . $record['content']['id']; }
        }
        return $ids;
    }

    private function upload(array $capture): void
    {
        $this->index->withLock(function () use ($capture): void {
            foreach ($capture['objects'] as $object) {
                if ($object['cipherFile'] === null) { continue; }
                $receipt = $this->repository->put($object['object']['key'], $object['cipherFile']);
                self::assertSame($object['object'], $receipt);
                if (strpos($receipt['key'], 'packs/') === 0) { $this->index->markReady($receipt); }
            }
        });
    }

    private function packs(array $capture): array
    {
        return array_values(array_filter($capture['objects'], static fn(array $object): bool => strpos($object['object']['key'], 'packs/') === 0));
    }

    private function packKeys(array $capture): array
    {
        $keys = array_column(array_column($this->packs($capture), 'object'), 'key'); sort($keys); return $keys;
    }

    private function records(array $capture): array
    {
        $objects = []; foreach ($capture['objects'] as $object) { $objects[$object['object']['key']] = $object; }
        $records = ['packs' => [], 'filesystem' => [], 'database' => []];
        foreach ($capture['manifest']['pages'] as $descriptor) {
            $cipher = $objects[$descriptor['object']['key']]['cipherFile'];
            $plain = Fixture::temp() . '/metadata'; $identity = fopen($this->keys[0]['identity'], 'rb');
            try { $this->age->decrypt($cipher, $plain, $identity); } finally { fclose($identity); }
            $page = json_decode(file_get_contents($plain), true, 512, JSON_THROW_ON_ERROR); V4Format::validatePage($page, $descriptor);
            foreach ($page['records'] as $record) { $records[$descriptor['kind']][] = $record; }
        }
        return $records;
    }

    private function fileRef(array $records, string $path): array
    {
        $selected = false;
        foreach ($records['filesystem'] as $record) {
            if ($record['type'] === 'file') { $selected = $record['path'] === $path; }
            elseif ($record['type'] === 'part' && $selected) { return $record['content']; }
        }
        throw new RuntimeException('Missing synthetic file reference.');
    }

    private function sqlRefs(array $records, string $table): array
    {
        return array_values(array_filter($records['database'], static fn(array $record): bool => $record['type'] === 'sql' && $record['name'] === $table));
    }
}

final class V4SharingRepository
{
    public string $directory;
    public array $objects = [];
    public array $verified = [];
    private LocalRepository $repository;

    public function __construct(string $directory, string $unitId)
    {
        $this->directory = $directory; $this->repository = LocalRepository::forWriting($directory, $unitId);
    }

    public function put(string $key, string $cipher): array
    {
        return $this->objects[$key] = $this->repository->putNew($key, $cipher);
    }

    public function verify(array $receipt): void
    {
        if (($this->objects[$receipt['key']] ?? null) !== $receipt) { throw new RuntimeException('Stored ciphertext missing or corrupt.'); }
        $this->verified[] = $receipt['key']; $this->repository->verify($receipt);
    }

    public function listPackObjects(string $scopeId): iterable
    {
        foreach ($this->objects as $receipt) { if (strpos($receipt['key'], 'packs/' . $scopeId . '/') === 0) { yield $receipt; } }
    }
}
