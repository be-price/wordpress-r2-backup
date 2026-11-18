<?php
declare(strict_types=1);

namespace WordPressR2Backup\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use WordPressR2Backup\AgeProcess;
use WordPressR2Backup\Manifest;
use WordPressR2Backup\V4ContentStore;
use WordPressR2Backup\V4Format;
use WordPressR2Backup\V4Pack;

final class V4ContentStoreTest extends TestCase
{
    private string $directory;
    private array $keys;
    private AgeProcess $age;

    protected function setUp(): void
    {
        $this->directory = Fixture::temp(); $this->keys = Fixture::keys(); $this->age = new AgeProcess(Fixture::runtime()['age']);
    }

    protected function tearDown(): void { Fixture::cleanup(); }

    public function testManySmallContentsShareOneEncryptedPackAndRestoreExactly(): void
    {
        $store = $this->store(); $refs = []; $plain = [];
        for ($i = 0; $i < 100; ++$i) {
            $plain[] = 'synthetic private content ' . $i;
            $refs[] = $store->accept($plain[$i], 'code');
        }
        self::assertSame([], glob($this->directory . '/*.age'));
        $result = $store->finish(); self::assertSame($result, $store->finish());
        self::assertSame(100, $result['contentCount']); self::assertSame(1, $result['packCount']);
        self::assertCount(1, $result['pages']); self::assertCount(1, $result['objects']);
        $packs = $this->packDescriptors($result); self::assertSame('code', $packs[0]['lane']);
        self::assertCount(100, $packs[0]['members']);
        $output = Fixture::temp(); $tar = $output . '/pack.tar';
        $this->decrypt($result['objects'][0]['cipherFile'], $tar);
        V4Pack::extract($tar, $output . '/contents', $packs[0]);
        foreach ($refs as $i => $ref) {
            self::assertSame($packs[0]['object']['key'], $ref['pack']);
            self::assertSame($plain[$i], file_get_contents($output . '/contents/' . $ref['id']));
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS)) as $entry) {
            self::assertSame(0600, $entry->getPerms() & 0777);
            self::assertStringNotContainsString('synthetic private content', file_get_contents($entry->getPathname()));
            self::assertTrue(substr($entry->getFilename(), -4) === '.age' || strpos($entry->getPathname(), '/content-map/') !== false);
        }
        $this->expectException(RuntimeException::class); $store->accept('later', 'code');
    }

    public function testEqualContentsShareTheirReferenceAcrossLanesBeforeAndAfterFlush(): void
    {
        $store = $this->store(); $ref = $store->accept('shared value', 'code');
        self::assertSame($ref, $store->accept('shared value', 'media'));
        for ($i = 0; $i < 511; ++$i) { $store->accept('member-' . $i, 'code'); }
        self::assertCount(1, glob($this->directory . '/pack-*.age'));
        self::assertSame($ref, $store->accept('shared value', 'database:table_1'));
        $other = $store->accept('other value', 'media');
        self::assertNotSame($ref['pack'], $other['pack']);
        $result = $store->finish(); self::assertSame(513, $result['contentCount']); self::assertSame(2, $result['packCount']);
        self::assertSame(['code', 'media'], array_column($this->packDescriptors($result), 'lane'));
    }

    public function testPendingContentReferenceSharesItsPackAndStillValidatesTheStoredLeaf(): void
    {
        $store = $this->store();
        $first = $store->accept('first content', 'code');
        $prefix = substr(hash('sha256', $first['id']), 0, 3);
        for ($i = 0; ; ++$i) {
            $body = 'pending content ' . $i;
            $id = V4Format::contentId(strlen($body), hash('sha256', $body));
            if (substr(hash('sha256', $id), 0, 3) === $prefix) { break; }
        }
        $pending = $store->accept($body, 'code');
        $leaf = $this->directory . '/content-map/leaf-' . $prefix . '.json';
        self::assertStringNotContainsString($pending['id'], file_get_contents($leaf));
        self::assertSame($pending, $store->accept($body, 'media'));
        file_put_contents($leaf, 'x', FILE_APPEND);
        $this->expectException(\JsonException::class);
        $store->accept($body, 'media');
    }

    public function testMemberLimitStartsAnotherPackWithTheReservedReference(): void
    {
        $store = $this->store(); $first = $store->accept('member-0', 'other');
        for ($i = 1; $i < 512; ++$i) { self::assertSame($first['pack'], $store->accept('member-' . $i, 'other')['pack']); }
        $next = $store->accept('member-512', 'other'); self::assertNotSame($first['pack'], $next['pack']);
        $result = $store->finish(); $packs = $this->packDescriptors($result);
        self::assertSame([512, 1], array_map(static fn(array $pack): int => count($pack['members']), $packs));
        self::assertSame([$first['pack'], $next['pack']], array_column(array_column($packs, 'object'), 'key'));
    }

    public function testAggregateBodyLimitFlushesLargestLaneBeforeAcceptingMoreContent(): void
    {
        $store = $this->store(); $refs = [];
        for ($i = 0; $i < 32; ++$i) {
            $refs[] = $store->accept(str_pad((string) $i, V4Format::MAX_CONTENT_BYTES, 'x'), 'database:table_' . ($i % 8));
        }
        self::assertCount(1, glob($this->directory . '/pack-*.age'));
        $next = $store->accept(str_repeat('y', V4Format::MAX_CONTENT_BYTES), 'database:table_0');
        self::assertNotSame($refs[0]['pack'], $next['pack']);
        $result = $store->finish(); self::assertSame(33, $result['contentCount']); self::assertSame(9, $result['packCount']);
        $packs = $this->packDescriptors($result); self::assertCount(4, $packs[0]['members']);
        foreach ($packs as $pack) { self::assertLessThanOrEqual(V4Format::MAX_PACK_BYTES, array_sum(array_column($pack['members'], 'size'))); }
    }

    public function testOneLaneFlushesAtTheBodyLimit(): void
    {
        $store = $this->store();
        for ($i = 0; $i < 32; ++$i) { $store->accept(str_pad((string) $i, V4Format::MAX_CONTENT_BYTES, 'z'), 'media'); }
        self::assertCount(1, glob($this->directory . '/pack-*.age'));
        $store->accept('next pack', 'media'); $result = $store->finish();
        self::assertSame(2, $result['packCount']);
        self::assertSame([32, 1], array_map(static fn(array $pack): int => count($pack['members']), $this->packDescriptors($result)));
    }

    public function testManyDatabaseLanesCannotAccumulateUnboundedBuffers(): void
    {
        $store = $this->store(); $first = $store->accept(str_repeat('largest', 10), 'database:table_0');
        for ($i = 1; $i < 8; ++$i) { $store->accept((string) $i, 'database:table_' . $i); }
        self::assertSame([], glob($this->directory . '/pack-*.age'));
        $store->accept('ninth lane', 'database:table_8'); self::assertCount(1, glob($this->directory . '/pack-*.age'));
        $result = $store->finish(); self::assertSame(9, $result['packCount']);
        self::assertSame($first['pack'], $result['objects'][0]['object']['key']);
        self::assertSame('database:table_0', $this->packDescriptors($result)[0]['lane']);
    }

    public function testInvalidInputPoisonsTheStoreAndCannotBeFinished(): void
    {
        $store = $this->store(); $store->accept('accepted', 'code');
        try { $store->accept(str_repeat('x', V4Format::MAX_CONTENT_BYTES + 1), 'code'); self::fail('Oversized content must fail.'); }
        catch (InvalidArgumentException $expected) { self::assertSame([], glob($this->directory . '/*.age')); }
        try { $store->accept('later', 'code'); self::fail('Failed stores cannot accept content.'); }
        catch (RuntimeException $expected) {}
        $this->expectException(RuntimeException::class); $store->finish();
    }

    public function testInvalidLaneAndMismatchedScopeAreRejected(): void
    {
        try { $this->store()->accept('content', 'database:../table'); self::fail('Invalid lanes must fail.'); }
        catch (InvalidArgumentException $expected) {}
        $scope = $this->scope(); $scope['keySetId'] = str_repeat('a', 64);
        $this->expectException(InvalidArgumentException::class);
        new V4ContentStore($this->age, array_column($this->keys, 'public'), Fixture::temp(), $scope, str_repeat('b', 64));
    }

    public function testEncryptionFailureCannotBeAcceptedOrFinishedAgain(): void
    {
        $binary = Fixture::temp() . '/failing-age';
        file_put_contents($binary, '#!' . Fixture::phpForShebang() . "\n<?php fwrite(STDOUT, 'partial'); exit(19);\n"); chmod($binary, 0700);
        $store = new V4ContentStore(new AgeProcess($binary), array_column($this->keys, 'public'), $this->directory, $this->scope(), str_repeat('b', 64));
        $store->accept('private body', 'code');
        try { $store->finish(); self::fail('Encryption failure must fail.'); }
        catch (RuntimeException $expected) { self::assertSame([], glob($this->directory . '/*.age')); }
        try { $store->accept('later', 'code'); self::fail('Failed stores cannot accept content.'); }
        catch (RuntimeException $expected) {}
        $this->expectException(RuntimeException::class); $store->finish();
    }

    public function testEmptyStoreFinishesWithoutCiphertext(): void
    {
        $store = $this->store(); $expected = ['pages' => [], 'objects' => [], 'contentCount' => 0, 'packCount' => 0, 'sharing' => ['newBytes' => 0, 'reusedBytes' => 0, 'repackedBytes' => 0, 'newPacks' => 0, 'reusedPacks' => 0, 'repackedPacks' => 0]];
        self::assertSame($expected, $store->finish()); self::assertSame($expected, $store->finish());
        self::assertSame([], glob($this->directory . '/*.age'));
    }

    private function store(): V4ContentStore
    {
        return new V4ContentStore($this->age, array_column($this->keys, 'public'), $this->directory, $this->scope(), str_repeat('b', 64));
    }

    private function scope(): array
    {
        return ['accountId' => str_repeat('a', 32), 'bucket' => 'synthetic-bucket', 'unitId' => str_repeat('c', 64),
            'keySetId' => Manifest::keySetId(array_column($this->keys, 'public')), 'encoding' => V4Format::ENCODING];
    }

    private function packDescriptors(array $result): array
    {
        $packs = [];
        foreach ($result['pages'] as $index => $descriptor) {
            $plain = Fixture::temp() . '/page-' . $index; $this->decrypt($descriptor['cipherFile'], $plain);
            $page = json_decode(file_get_contents($plain), true, 512, JSON_THROW_ON_ERROR);
            unset($descriptor['cipherFile']); V4Format::validatePage($page, $descriptor);
            foreach ($page['records'] as $pack) { $packs[] = $pack; }
        }
        self::assertCount($result['packCount'], $packs);
        self::assertSame(array_column($result['objects'], 'object'), array_column($packs, 'object'));
        return $packs;
    }

    private function decrypt(string $cipherFile, string $plainFile): void
    {
        $identity = fopen($this->keys[0]['identity'], 'rb');
        try { $this->age->decrypt($cipherFile, $plainFile, $identity); } finally { fclose($identity); }
    }
}
