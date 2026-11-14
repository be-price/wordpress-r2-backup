<?php
declare(strict_types=1);

namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use WordPressR2Backup\{AgeProcess, LocalRepository, Manifest, ObjectReader, PrivacyPolicy, Restorer, SourceRoots, V4Capture, V4ContentIndex, V4Format, V4Pack};

require_once __DIR__ . '/WordPressDatabaseTest.php';

final class V4IndexRebuilderTest extends TestCase
{
    private array $keys;
    private AgeProcess $age;
    private string $storage;
    private LocalRepository $writer;
    private LocalRepository $reader;

    protected function setUp(): void
    {
        $this->keys = Fixture::keys(2); $this->age = new AgeProcess(Fixture::runtime()['age']);
        $this->storage = Fixture::temp();
        $this->writer = LocalRepository::forWriting($this->storage, str_repeat('c', 64));
        $this->reader = LocalRepository::forRecovery($this->storage, str_repeat('c', 64));
    }

    protected function tearDown(): void { Fixture::cleanup(); }

    public function testRebuildRecoversReferencedAndUnreferencedPacksAfterSourceAndIndexLossForBothKeys(): void
    {
        $source = Fixture::temp(); Fixture::write($source, 'secret.bin', 'synthetic private body');
        $scope = $this->scope(); $manifest = $this->capture($source, $scope, str_repeat('1', 64));
        $content = $this->content('unreferenced synthetic body');
        $orphan = V4Pack::write($this->age, $this->recipients(), Fixture::temp(), V4Format::scopeId($scope), 'code', [$content]);
        $this->writer->putNew($orphan['descriptor']['object']['key'], $orphan['cipherFile']);
        unlink($source . '/secret.bin'); rmdir($source);
        foreach ([0, 1] as $key) {
            $directory = Fixture::temp();
            $identity = fopen($this->keys[$key]['identity'], 'rb');
            try { (new Restorer($this->age, $this->reader))->rebuildIndex($identity, $this->recipients(), $directory); }
            finally { fclose($identity); }
            $index = V4ContentIndex::openOrCreate($directory, $scope, $this->writer);
            $index->withLock(function () use ($index, $content, $orphan, $manifest): void {
                $found = $index->lookup($content['id']);
                self::assertSame($orphan['descriptor']['object'], $found['descriptor']['object']);
                self::assertNull($found['rootScopeSha256']);
                $saved = $this->content('synthetic private body');
                self::assertSame(V4ContentIndex::rootScopeSha256($manifest['roots']), $index->lookup($saved['id'])['rootScopeSha256']);
                self::assertTrue($index->requiresMembershipProof(true, V4ContentIndex::rootScopeSha256($manifest['roots'])));
            });
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
                $bytes = file_get_contents($file->getPathname());
                self::assertStringNotContainsString('synthetic private body', $bytes);
                self::assertStringNotContainsString('unreferenced synthetic body', $bytes);
                self::assertStringNotContainsString('INSERT INTO', $bytes);
                self::assertStringNotContainsString('AGE-SECRET-KEY', $bytes);
            }
        }
    }

    public function testCurrentKeyIndexCanBeRebuiltWhileOlderKeyGenerationsRemain(): void
    {
        $olderRecipients = $this->recipients();
        $source = Fixture::temp(); Fixture::write($source, 'proof.txt', 'old key generation');
        $this->capture($source, $this->scope(), str_repeat('1', 64));
        $this->keys = Fixture::keys(2);
        Fixture::write($source, 'proof.txt', 'current key generation');
        $scope = $this->scope(); $this->capture($source, $scope, str_repeat('2', 64));
        foreach ([$this->reader, new V4ScopedRecoveryReader($this->reader, array_intersect_key($scope, array_flip(['accountId', 'bucket', 'unitId'])))] as $reader) {
            $directory = Fixture::temp(); $identity = fopen($this->keys[0]['identity'], 'rb');
            try { (new Restorer($this->age, $reader))->rebuildIndex($identity, $this->recipients(), $directory); }
            finally { fclose($identity); }
            $index = V4ContentIndex::openOrCreate($directory, $scope, $this->writer);
            $index->withLock(function () use ($index): void {
                self::assertNotNull($index->lookup($this->content('current key generation')['id']));
                self::assertNull($index->lookup($this->content('old key generation')['id']));
            });
        }
        $wrongRoot = Fixture::temp() . '/wrong-root.age';
        $this->age->encryptStream(['synthetic inaccessible root'], $wrongRoot, $olderRecipients);
        $receipt = $this->writer->putNew('manifests/' . str_repeat('3', 64) . '.age', $wrongRoot);
        $this->writer->publishWithObjects(str_repeat('3', 64), $receipt, iterator_to_array($this->reader->listPackObjects(V4Format::scopeId($scope))));
        $identity = fopen($this->keys[0]['identity'], 'rb');
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('selected v4 scope');
            (new Restorer($this->age, $this->reader))->rebuildIndex($identity, $this->recipients(), Fixture::temp());
        } finally { fclose($identity); }
    }

    public function testMissingRetainedPackAndCorruptOrphanNeverProduceAReadyIndex(): void
    {
        foreach (['missing', 'orphan'] as $fault) {
            $source = Fixture::site(['saved.txt' => 'saved']);
            $scope = $this->scope(); $manifest = $this->capture($source, $scope, hash('sha256', $fault));
            if ($fault === 'missing') {
                $packs = iterator_to_array($this->reader->listPackObjects(V4Format::scopeId($scope)), false);
                unlink($this->reader->objectPath($packs[0]['key']));
            } else {
                $cipher = Fixture::temp() . '/invalid.age';
                $this->age->encryptStream(['invalid tar body'], $cipher, $this->recipients());
                $this->writer->putNew(V4Format::packKey(V4Format::scopeId($scope)), $cipher);
            }
            $directory = Fixture::temp(); $identity = fopen($this->keys[0]['identity'], 'rb');
            try { (new Restorer($this->age, $this->reader))->rebuildIndex($identity, $this->recipients(), $directory); self::fail('Invalid retained contents must stop rebuild.'); }
            catch (RuntimeException $expected) {
                if (is_file($directory . '/scope.json')) { self::assertNotSame('ready', json_decode(file_get_contents($directory . '/scope.json'), true)['state']); }
                else { self::assertFileDoesNotExist($directory . '/scope.json'); }
            } finally { fclose($identity); }
            $this->storage = Fixture::temp();
            $this->writer = LocalRepository::forWriting($this->storage, str_repeat('c', 64));
            $this->reader = LocalRepository::forRecovery($this->storage, str_repeat('c', 64));
        }
    }

    public function testActualDirectScopeRejectsCopiedRootFromAnotherAccountBeforeCreatingIndex(): void
    {
        $this->capture(Fixture::site(['saved.txt' => 'saved']), $this->scope(), str_repeat('2', 64));
        $reader = new V4ScopedRecoveryReader($this->reader, ['accountId' => str_repeat('b', 32), 'bucket' => $this->scope()['bucket'], 'unitId' => $this->reader->unitId()]);
        $directory = Fixture::temp(); $identity = fopen($this->keys[0]['identity'], 'rb');
        try { (new Restorer($this->age, $reader))->rebuildIndex($identity, $this->recipients(), $directory); self::fail('Copied cross-account roots must not rebuild reusable packs.'); }
        catch (RuntimeException $expected) { self::assertSame(['.', '..'], scandir($directory)); }
        finally { fclose($identity); }
    }

    public function testAuthoritativeRepositoryScopeCanRecoverPacksWithoutRetainedRoots(): void
    {
        $scope = $this->scope(); $content = $this->content('orphan without a retained root');
        $pack = V4Pack::write($this->age, $this->recipients(), Fixture::temp(), V4Format::scopeId($scope), 'code', [$content]);
        $this->writer->putNew($pack['descriptor']['object']['key'], $pack['cipherFile']);
        $reader = new V4ScopedRecoveryReader($this->reader, array_intersect_key($scope, array_flip(['accountId', 'bucket', 'unitId'])));
        $directory = Fixture::temp(); $identity = fopen($this->keys[0]['identity'], 'rb');
        try { (new Restorer($this->age, $reader))->rebuildIndex($identity, $this->recipients(), $directory); }
        finally { fclose($identity); }
        $index = V4ContentIndex::openOrCreate($directory, $scope, $this->writer);
        $index->withLock(function () use ($index, $content, $pack): void {
            $found = $index->lookup($content['id']);
            self::assertSame($pack['descriptor']['object'], $found['descriptor']['object']);
            self::assertNull($found['rootScopeSha256']);
        });
    }

    public function testLocalRebuildRequiresOneUnambiguousRootScope(): void
    {
        $source = Fixture::site(['saved.txt' => 'saved']);
        $this->capture($source, $this->scope(), str_repeat('3', 64));
        $scope = $this->scope(); $scope['accountId'] = str_repeat('b', 32);
        $this->capture($source, $scope, str_repeat('4', 64));
        $directory = Fixture::temp(); $identity = fopen($this->keys[0]['identity'], 'rb');
        try { (new Restorer($this->age, $this->reader))->rebuildIndex($identity, $this->recipients(), $directory); self::fail('Ambiguous local scope must require a separate repository.'); }
        catch (RuntimeException $expected) { self::assertSame(['.', '..'], scandir($directory)); }
        finally { fclose($identity); }
    }

    private function capture(string $source, array $scope, string $snapshot): array
    {
        $result = (new V4Capture(new SourceRoots($source), new V4DatabaseWpdbFake(), new PrivacyPolicy(['version' => 2, 'preset' => 'full']), Fixture::temp()))
            ->capture($this->age, $this->recipients(), $snapshot, $scope);
        $objects = [];
        foreach ($result['objects'] as $object) { $objects[] = $this->writer->putNew($object['object']['key'], $object['cipherFile']); }
        $file = Fixture::temp() . '/root.age'; $this->age->encryptStream([V4Format::json($result['manifest'])], $file, $this->recipients());
        $receipt = $this->writer->putNew('manifests/' . $snapshot . '.age', $file);
        $this->writer->publishWithObjects($snapshot, $receipt, $objects);
        return $result['manifest'];
    }

    private function scope(): array { return ['accountId' => str_repeat('a', 32), 'bucket' => 'rebuild-test', 'unitId' => $this->reader->unitId(), 'keySetId' => Manifest::keySetId($this->recipients()), 'encoding' => V4Format::ENCODING]; }
    private function recipients(): array { return array_column($this->keys, 'public'); }
    private function content(string $bytes): array { $hash = hash('sha256', $bytes); return ['id' => V4Format::contentId(strlen($bytes), $hash), 'size' => strlen($bytes), 'sha256' => $hash, 'bytes' => $bytes]; }
}

final class V4ScopedRecoveryReader implements ObjectReader
{
    private LocalRepository $reader;
    private array $scope;
    public function __construct(LocalRepository $reader, array $scope) { $this->reader = $reader; $this->scope = $scope; }
    public function storageScope(): array { return $this->scope; }
    public function unitId(): string { return $this->reader->unitId(); }
    public function verify(array $receipt): void { $this->reader->verify($receipt); }
    public function download(array $receipt, string $file): void { $this->reader->download($receipt, $file); }
    public function completed(string $id): array { return $this->reader->completed($id); }
    public function listCompleted(): array { return $this->reader->listCompleted(); }
    public function listStatus(): array { return $this->reader->listStatus(); }
    public function listFileObjects(string $id): array { return $this->reader->listFileObjects($id); }
    public function listPackObjects(string $id): iterable { return $this->reader->listPackObjects($id); }
}
