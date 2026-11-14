<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use WordPressR2Backup\AgeProcess;
use WordPressR2Backup\Manifest;
use WordPressR2Backup\V4ContentIndex;
use WordPressR2Backup\V4Format;
use WordPressR2Backup\V4Pack;

/** verifyReadyPacks(): synthetic packs only. No capture, publication or restore runs. */
final class V4ContentIndexReadyBatchTest extends TestCase
{
    protected function tearDown(): void { Fixture::cleanup(); }

    public function testReadyPacksAreCheckedFirstThenEachIsVerifiedOnceInOrder(): void
    {
        [$index, $repository, $keys] = self::readyIndex(6, ReadyBatchManyRepository::class);
        $descriptors = $index->withLock(static fn (): array => $index->verifyReadyPacks(array_slice($keys, 0, 4)));
        self::assertSame(array_slice($keys, 0, 4), array_column(array_column($descriptors, 'object'), 'key'));
        self::assertSame([array_slice($keys, 0, 4)], $repository->manyCalls, 'one batch call with the packs in order');
        self::assertSame(array_slice($keys, 0, 4), $repository->verified, 'each pack verified exactly once');
        $index->withLock(static fn (): array => $index->verifyReadyPacks([$keys[5]]));
        self::assertSame([$keys[5]], end($repository->manyCalls));
    }

    public function testAPackThatIsNotReadyStopsTheBatchBeforeAnyRead(): void
    {
        [$index, $repository, $keys, $candidate] = self::readyIndex(3, ReadyBatchManyRepository::class, true);
        foreach ([[$keys[0], $candidate, $keys[1]], [$keys[0], $keys[0]], [], ['packs/' . str_repeat('f', 64) . '/' . str_repeat('e', 64) . '.age'], [1 => $keys[0]]] as $list) {
            $rejected = null;
            try { $index->withLock(static fn (): array => $index->verifyReadyPacks($list)); } catch (RuntimeException $error) { $rejected = $error->getMessage(); }
            self::assertNotNull($rejected, json_encode($list));
            self::assertSame([], $repository->verified, 'no pack is read when the list is refused');
        }
        $index->withLock(static function () use ($index, $keys): void { $index->markMissing([$keys[1]]); });
        $rejected = null;
        try { $index->withLock(static fn (): array => $index->verifyReadyPacks([$keys[0], $keys[1]])); } catch (RuntimeException $error) { $rejected = $error->getMessage(); }
        self::assertSame('Pack is not ready for reuse.', $rejected);
        self::assertSame([], $repository->verified);
        $this->expectException(\LogicException::class);
        $index->verifyReadyPacks([$keys[0]]);
    }

    public function testAFailedReadFailsTheWholeBatchAndARepositoryWithoutBatchReadsEachPack(): void
    {
        [$index, $repository, $keys] = self::readyIndex(4, ReadyBatchManyRepository::class);
        $repository->failKey = $keys[2];
        $rejected = null;
        try { $index->withLock(static fn (): array => $index->verifyReadyPacks($keys)); } catch (RuntimeException $error) { $rejected = $error->getMessage(); }
        self::assertSame('Synthetic stored ciphertext mismatch.', $rejected);
        [$plainIndex, $plain, $plainKeys] = self::readyIndex(4, ReadyBatchPlainRepository::class);
        $plainIndex->withLock(static fn (): array => $plainIndex->verifyReadyPacks($plainKeys));
        self::assertSame($plainKeys, $plain->verified, 'without verifyMany every pack is verified once, in order');
    }

    public function testReadyDescriptorsReturnIndexedDescriptorsInOrderWithoutReadingR2(): void
    {
        [$index, $repository, $keys, $candidate] = self::readyIndex(4, ReadyBatchManyRepository::class, true);
        $order = [$keys[2], $keys[0], $keys[3]];
        $descriptors = $index->withLock(static fn (): array => $index->readyDescriptors($order));
        self::assertSame($order, array_column(array_column($descriptors, 'object'), 'key'));
        self::assertSame($repository->objects[$keys[2]], $descriptors[0]['object'], 'the exact indexed receipt');
        self::assertSame([], $repository->verified, 'no pack is read');
        self::assertSame([], $repository->manyCalls, 'no batch read is made');
        // The descriptors are the ones verifyReadyPacks() returns after its reads.
        self::assertSame($descriptors, $index->withLock(static fn (): array => $index->verifyReadyPacks($order)));
        $repository->verified = []; $repository->manyCalls = [];
        foreach ([[$keys[0], $candidate], [$keys[1], $keys[1]], [], ['packs/' . str_repeat('f', 64) . '/' . str_repeat('e', 64) . '.age'], [1 => $keys[0]], [7]] as $list) {
            $rejected = null;
            try { $index->withLock(static fn (): array => $index->readyDescriptors($list)); } catch (RuntimeException $error) { $rejected = $error->getMessage(); }
            self::assertNotNull($rejected, json_encode($list));
        }
        $index->withLock(static function () use ($index, $keys): void { $index->markMissing([$keys[3]]); });
        $rejected = null;
        try { $index->withLock(static fn (): array => $index->readyDescriptors([$keys[0], $keys[3]])); } catch (RuntimeException $error) { $rejected = $error->getMessage(); }
        self::assertSame('Pack is not ready for reuse.', $rejected, 'a pack marked missing is refused');
        self::assertSame([], $repository->verified);
        $this->expectException(\LogicException::class);
        $index->readyDescriptors([$keys[0]]);
    }

    /** @dataProvider curlScenarios */
    public function testRealRepositoryReadsFourPacksAtOnceWithTlsAndFreshSignatures(string $scenario): void
    {
        $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/index-ready-batch.php', $scenario], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $output . $error);
        self::assertSame('ok ' . $scenario . "\n", $output);
    }

    public function curlScenarios(): array
    {
        return ['success' => ['success'], 'tls' => ['tls'], 'corrupt' => ['corrupt'], 'not ready' => ['notready']];
    }

    /** Returns [index, repository, ready pack keys in creation order, candidate key or null]. */
    public static function readyIndex(int $count, string $repositoryClass, bool $withCandidate = false): array
    {
        $keys = Fixture::keys(); $recipients = array_column($keys, 'public'); $age = new AgeProcess(Fixture::runtime()['age']);
        $scope = ['accountId' => str_repeat('a', 32), 'bucket' => 'synthetic-bucket', 'unitId' => str_repeat('c', 64), 'keySetId' => Manifest::keySetId($recipients), 'encoding' => V4Format::ENCODING];
        $repository = new $repositoryClass();
        $index = V4ContentIndex::openOrCreate(Fixture::temp(), $scope, $repository);
        $private = Fixture::temp(); $ready = []; $candidate = null;
        for ($number = 0; $number < $count + ($withCandidate ? 1 : 0); ++$number) {
            $bytes = 'synthetic pack member ' . $number;
            $pack = V4Pack::write($age, $recipients, $private, V4Format::scopeId($scope), 'code', [['id' => V4Format::contentId(strlen($bytes), hash('sha256', $bytes)), 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'bytes' => $bytes]]);
            $object = $pack['descriptor']['object']; $repository->objects[$object['key']] = $object; $repository->files[$object['key']] = $pack['cipherFile'];
            $index->withLock(static function () use ($index, $pack, $object, $number, $count): void {
                $index->recordCandidate($pack['descriptor'], str_repeat('d', 64));
                if ($number < $count) { $index->markReady($object); }
            });
            if ($number < $count) { $ready[] = $object['key']; } else { $candidate = $object['key']; }
        }
        $repository->verified = []; $repository->manyCalls = [];
        return [$index, $repository, $ready, $candidate];
    }
}

/** Accepts a receipt only when it equals the stored one; records every verification. */
class ReadyBatchPlainRepository
{
    public array $objects = [];
    public array $files = [];
    public array $verified = [];
    public array $manyCalls = [];
    public ?string $failKey = null;

    public function verify(array $receipt): void
    {
        if (($this->objects[$receipt['key']] ?? null) !== $receipt || $receipt['key'] === $this->failKey) { throw new RuntimeException('Synthetic stored ciphertext mismatch.'); }
        $this->verified[] = $receipt['key'];
    }

    public function listPackObjects(string $scopeId): iterable { return []; }
}

final class ReadyBatchManyRepository extends ReadyBatchPlainRepository
{
    public function verifyMany(array $receipts): void
    {
        $this->manyCalls[] = array_column($receipts, 'key');
        foreach ($receipts as $receipt) { $this->verify($receipt); }
    }
}
