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
use WordPressR2Backup\V4ContentStore;
use WordPressR2Backup\V4Format;
use WordPressR2Backup\V4Pack;

require_once __DIR__ . '/WordPressDatabaseTest.php';

/** Deferred shared-pack verification during capture. Synthetic packs and files only; nothing is restored. */
final class V4ContentStoreReuseBatchTest extends TestCase
{
    private const ROOT_SCOPE = 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd';
    private array $keys;
    private AgeProcess $age;
    private array $scope;

    protected function setUp(): void
    {
        $this->keys = Fixture::keys(); $this->age = new AgeProcess(Fixture::runtime()['age']);
        $this->scope = ['accountId' => str_repeat('a', 32), 'bucket' => 'synthetic-bucket', 'unitId' => str_repeat('c', 64),
            'keySetId' => Manifest::keySetId(array_column($this->keys, 'public')), 'encoding' => V4Format::ENCODING];
    }

    protected function tearDown(): void { Fixture::cleanup(); }

    public function testFourPendingPacksAreVerifiedTogetherAndTheRestAtFinishInDiscoveryOrder(): void
    {
        [$index, $repository, $packs] = $this->readyPacks(5);
        $result = $index->withLock(function () use ($index, $packs, $repository): array {
            $store = $this->store($index);
            for ($pack = 0; $pack < 4; ++$pack) { self::assertSame($packs[$pack]['key'], $store->accept($packs[$pack]['members'][0], 'code')['pack']); }
            self::assertSame([array_column(array_slice($packs, 0, 4), 'key')], $repository->manyCalls, 'the fourth pending pack starts one batch');
            self::assertSame($packs[4]['key'], $store->accept($packs[4]['members'][0], 'code')['pack']);
            // A second member of a pending pack and of a verified pack resolve without another read.
            self::assertSame($packs[4]['key'], $store->accept($packs[4]['members'][1], 'code')['pack']);
            self::assertSame($packs[0]['key'], $store->accept($packs[0]['members'][1], 'code')['pack']);
            self::assertCount(1, $repository->manyCalls);
            return $store->finish();
        });
        self::assertSame([array_column(array_slice($packs, 0, 4), 'key'), [$packs[4]['key']]], $repository->manyCalls);
        self::assertSame(array_column($packs, 'key'), $repository->verified, 'each shared pack is read in full exactly once');
        self::assertSame(array_column($packs, 'key'), array_column(array_column($result['objects'], 'object'), 'key'), 'shared packs keep their discovery order');
        self::assertSame([null, null, null, null, null], array_column($result['objects'], 'cipherFile'));
        self::assertSame([5, 0, 0], [$result['sharing']['reusedPacks'], $result['sharing']['newPacks'], $result['sharing']['repackedPacks']]);
    }

    public function testPendingPacksAreVerifiedBeforeANewPackIsWrittenAndWhenTheCaptureAsks(): void
    {
        [$index, $repository, $packs] = $this->readyPacks(3);
        $result = $index->withLock(function () use ($index, $packs, $repository): array {
            $store = $this->store($index);
            $store->accept($packs[0]['members'][0], 'code'); $store->accept($packs[1]['members'][0], 'code');
            self::assertSame([], $repository->manyCalls);
            // The 512th new member fills a pack, and the two pending packs are verified before it is written.
            for ($number = 0; $number < V4Format::MAX_PACK_MEMBERS; ++$number) { $store->accept('synthetic new member ' . $number, 'code'); }
            self::assertSame([[$packs[0]['key'], $packs[1]['key']]], $repository->manyCalls);
            $store->accept($packs[2]['members'][0], 'code');
            $store->verifyPending(); $store->verifyPending();
            self::assertSame([[$packs[0]['key'], $packs[1]['key']], [$packs[2]['key']]], $repository->manyCalls, 'an explicit drain verifies one remaining pack once');
            return $store->finish();
        });
        $objects = $result['objects'];
        self::assertSame([$packs[0]['key'], $packs[1]['key']], array_column(array_column(array_slice($objects, 0, 2), 'object'), 'key'));
        self::assertNotNull($objects[2]['cipherFile'], 'the new pack follows the shared packs found before it');
        self::assertSame($packs[2]['key'], $objects[3]['object']['key']);
        self::assertCount(1, array_filter($objects, static fn (array $object): bool => $object['cipherFile'] !== null));
    }

    public function testAFailedBatchFailsTheStoreSoNothingCanBeFinishedOrPublished(): void
    {
        [$index, $repository, $packs] = $this->readyPacks(4);
        $repository->failKey = $packs[2]['key'];
        $index->withLock(function () use ($index, $packs): void {
            $store = $this->store($index);
            for ($pack = 0; $pack < 3; ++$pack) { $store->accept($packs[$pack]['members'][0], 'code'); }
            $failure = null;
            try { $store->accept($packs[3]['members'][0], 'code'); } catch (RuntimeException $error) { $failure = $error->getMessage(); }
            self::assertSame('Synthetic stored ciphertext mismatch.', $failure);
            foreach ([static fn () => $store->accept('synthetic later member', 'code'), static fn () => $store->verifyPending(), static fn () => $store->finish()] as $operation) {
                $refused = null;
                try { $operation(); } catch (RuntimeException $error) { $refused = $error->getMessage(); }
                self::assertNotNull($refused, 'a failed store must refuse further work');
            }
        });
        // The capture's own drain at the end of the lock: a failure there must close the store even if a later read
        // would succeed.
        [$index, $repository, $packs] = $this->readyPacks(2);
        $repository->failKey = $packs[1]['key'];
        $index->withLock(function () use ($index, $packs, $repository): void {
            $store = $this->store($index);
            $store->accept($packs[0]['members'][0], 'code'); $store->accept($packs[1]['members'][0], 'code');
            $failure = null;
            try { $store->verifyPending(); } catch (RuntimeException $error) { $failure = $error->getMessage(); }
            self::assertSame('Synthetic stored ciphertext mismatch.', $failure);
            $repository->failKey = null;
            $refused = null;
            try { $store->finish(); } catch (RuntimeException $error) { $refused = $error->getMessage(); }
            self::assertSame('V4 content store failed.', $refused, 'a store whose batch failed must not finish after a later successful read');
        });
    }

    public function testAPendingRecordThatNoLongerMatchesTheIndexIsRefused(): void
    {
        foreach (['descriptor at a later member', 'root scope at a later member', 'descriptor at verification'] as $change) {
            [$index, , $packs] = $this->readyPacks(1);
            $index->withLock(function () use ($index, $packs, $change): void {
                $store = $this->store($index);
                $store->accept($packs[0]['members'][0], 'code');
                $pending = new \ReflectionProperty($store, 'pendingPacks'); $pending->setAccessible(true);
                $records = $pending->getValue($store); $key = $packs[0]['key'];
                if ($change === 'root scope at a later member') { $records[$key]['rootScopeSha256'] = str_repeat('e', 64); }
                else { $records[$key]['descriptor']['members'][0]['size'] += 1; }
                $pending->setValue($store, $records);
                $failure = null;
                try { $change === 'descriptor at verification' ? $store->verifyPending() : $store->accept($packs[0]['members'][1], 'code'); }
                catch (RuntimeException $error) { $failure = $error->getMessage(); }
                self::assertSame('V4 indexed pack changed during capture.', $failure, $change);
            });
        }
    }

    public function testAPackWithAnExcludedMemberIsVerifiedButNotReusedAndOnlySelectedContentIsRepacked(): void
    {
        [$index, $repository, $packs] = $this->readyPacks(1);
        $selected = MetadataMap::createProcessLocal(Fixture::temp() . '/selected');
        $kept = $packs[0]['members'][0]; $selected->put(V4Format::contentId(strlen($kept), hash('sha256', $kept)), []);
        [$reference, $result] = $index->withLock(function () use ($index, $kept, $selected): array {
            $store = new V4ContentStore($this->age, array_column($this->keys, 'public'), Fixture::temp(), $this->scope, str_repeat('b', 64), $index, self::ROOT_SCOPE, false, $selected);
            $reference = $store->accept($kept, 'code');
            return [$reference, $store->finish()];
        });
        self::assertNotSame($packs[0]['key'], $reference['pack'], 'the selected member moves to a new pack');
        self::assertSame([[$packs[0]['key']]], $repository->manyCalls, 'the old pack is still verified once');
        self::assertNotContains($packs[0]['key'], array_column(array_column($result['objects'], 'object'), 'key'), 'a pack holding an excluded member is never referenced');
        self::assertSame([0, 1, 1], [$result['sharing']['reusedPacks'], $result['sharing']['newPacks'], $result['sharing']['repackedPacks']]);
        self::assertSame([$reference['pack']], array_column(array_column($result['objects'], 'object'), 'key'));
    }

    public function testCaptureVerifiesSharedPacksOnlyUnderTheDatabaseLockAndFailsWithoutUploadWhenOneIsMissing(): void
    {
        $source = Fixture::temp();
        for ($number = 0; $number < 2600; ++$number) { Fixture::write($source, sprintf('bulk/f%04d.php', $number), '<?php // synthetic shared ' . $number); }
        $repository = new StoreBatchCaptureRepository(Fixture::temp(), $this->scope['unitId']);
        $index = V4ContentIndex::openOrCreate(Fixture::temp(), $this->scope, $repository);
        $wpdb = new V4DatabaseWpdbFake(); $repository->lockState = static fn (): bool => $wpdb->locked;
        $first = $this->captureSource($index, $source, $wpdb);
        $index->withLock(function () use ($index, $first, $repository): void {
            foreach ($first['objects'] as $object) {
                $receipt = $repository->put($object['object']['key'], $object['cipherFile']);
                if (strpos($receipt['key'], 'packs/') === 0) { $index->markReady($receipt); }
            }
        });
        $sharedKeys = array_values(array_filter(array_column(array_column($first['objects'], 'object'), 'key'), static fn (string $key): bool => strpos($key, 'packs/') === 0));
        self::assertGreaterThanOrEqual(6, count($sharedKeys));
        $repository->manyCalls = []; $repository->lockedDuringBatches = [];
        $second = $this->captureSource($index, $source, $wpdb);
        $secondShared = array_values(array_filter(array_column(array_column($second['objects'], 'object'), 'key'), static fn (string $key): bool => strpos($key, 'packs/') === 0));
        self::assertEqualsCanonicalizing($sharedKeys, $secondShared, 'every pack of the first generation is shared');
        self::assertSame(array_fill(0, count($repository->manyCalls), true), $repository->lockedDuringBatches, 'every batch runs under the database read lock');
        self::assertSame($secondShared, array_merge(...$repository->manyCalls), 'each shared pack is read once, and listed in the order it was verified');
        self::assertSame([4], array_values(array_unique(array_map('count', array_slice($repository->manyCalls, 0, -1)))), 'full batches hold four packs');
        $stored = $repository->objects; unset($repository->objects[$sharedKeys[5]]);
        $failure = null;
        try { $this->captureSource($index, $source, $wpdb); } catch (RuntimeException $error) { $failure = $error->getMessage(); }
        self::assertNotNull($failure, 'a missing shared pack must fail the capture');
        self::assertFalse($wpdb->locked, 'the read lock is released after the failure');
        self::assertSame(array_diff_key($stored, [$sharedKeys[5] => true]), $repository->objects, 'a failed capture uploads nothing');
    }

    private function captureSource(V4ContentIndex $index, string $source, V4DatabaseWpdbFake $wpdb): array
    {
        return $index->withLock(function () use ($index, $source, $wpdb): array {
            return (new V4Capture(new SourceRoots($source), $wpdb, new PrivacyPolicy(['version' => 2, 'preset' => 'full']), Fixture::temp()))
                ->capture($this->age, array_column($this->keys, 'public'), bin2hex(random_bytes(32)), $this->scope, null, $index);
        });
    }

    private function store(V4ContentIndex $index): V4ContentStore
    {
        return new V4ContentStore($this->age, array_column($this->keys, 'public'), Fixture::temp(), $this->scope, str_repeat('b', 64), $index, self::ROOT_SCOPE, true);
    }

    /** Ready packs of two members each, recorded under ROOT_SCOPE. Returns [index, repository, [['key', 'members']]]. */
    private function readyPacks(int $count): array
    {
        $repository = new StoreBatchRepository();
        $index = V4ContentIndex::openOrCreate(Fixture::temp(), $this->scope, $repository); $private = Fixture::temp(); $packs = [];
        for ($pack = 0; $pack < $count; ++$pack) {
            $members = ['shared pack ' . $pack . ' member 0', 'shared pack ' . $pack . ' member 1']; $contents = [];
            foreach ($members as $bytes) { $contents[] = ['id' => V4Format::contentId(strlen($bytes), hash('sha256', $bytes)), 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'bytes' => $bytes]; }
            $written = V4Pack::write($this->age, array_column($this->keys, 'public'), $private, V4Format::scopeId($this->scope), 'code', $contents);
            $object = $written['descriptor']['object']; $repository->objects[$object['key']] = $object;
            $index->withLock(static function () use ($index, $written, $object): void { $index->recordCandidate($written['descriptor'], self::ROOT_SCOPE); $index->markReady($object); });
            $packs[] = ['key' => $object['key'], 'members' => $members];
        }
        $repository->verified = []; $repository->manyCalls = [];
        return [$index, $repository, $packs];
    }
}

final class StoreBatchRepository
{
    public array $objects = [];
    public array $verified = [];
    public array $manyCalls = [];
    public ?string $failKey = null;

    public function verify(array $receipt): void
    {
        if (($this->objects[$receipt['key']] ?? null) !== $receipt || $receipt['key'] === $this->failKey) { throw new RuntimeException('Synthetic stored ciphertext mismatch.'); }
        $this->verified[] = $receipt['key'];
    }

    public function verifyMany(array $receipts): void
    {
        $this->manyCalls[] = array_column($receipts, 'key');
        foreach ($receipts as $receipt) { $this->verify($receipt); }
    }

    public function listPackObjects(string $scopeId): iterable { return []; }
}

/** Stores real ciphertext and records whether the database read lock was held at each batch. */
final class StoreBatchCaptureRepository
{
    public array $objects = [];
    public array $manyCalls = [];
    public array $lockedDuringBatches = [];
    /** @var callable|null */
    public $lockState = null;
    private LocalRepository $repository;

    public function __construct(string $directory, string $unitId) { $this->repository = LocalRepository::forWriting($directory, $unitId); }

    public function put(string $key, string $cipher): array { return $this->objects[$key] = $this->repository->putNew($key, $cipher); }

    public function verify(array $receipt): void
    {
        if (($this->objects[$receipt['key']] ?? null) !== $receipt) { throw new RuntimeException('Stored ciphertext missing or corrupt.'); }
        $this->repository->verify($receipt);
    }

    public function verifyMany(array $receipts): void
    {
        $this->manyCalls[] = array_column($receipts, 'key');
        $this->lockedDuringBatches[] = $this->lockState !== null && ($this->lockState)();
        foreach ($receipts as $receipt) { $this->verify($receipt); }
    }

    public function listPackObjects(string $scopeId): iterable
    {
        foreach ($this->objects as $receipt) { if (strpos($receipt['key'], 'packs/' . $scopeId . '/') === 0) { yield $receipt; } }
    }
}
