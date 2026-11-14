<?php
declare(strict_types=1);

namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\{DirectR2Repository, LocalRepository, V4Format};

final class V4RepositoryTest extends TestCase
{
    private array $objects = [];
    private int $now = 1700000000;
    private int $requests = 0;

    protected function tearDown(): void { Fixture::cleanup(); }

    public function testScopedPackListingIsLazyAndAvailableToLocalWriterAndReader(): void
    {
        $root = Fixture::temp(); $unit = str_repeat('a', 64); $scope = str_repeat('b', 64);
        $writer = LocalRepository::forWriting($root, $unit);
        $pack = $writer->putNew($this->packKey(), $this->cipher('pack'));
        $writer->putNew('packs/' . str_repeat('f', 64) . '/' . str_repeat('c', 64) . '.age', $this->cipher('unrelated'));
        self::assertSame([$pack], iterator_to_array($writer->listPackObjects($scope), false));
        self::assertSame([$pack], iterator_to_array(LocalRepository::forRecovery($root, $unit)->listPackObjects($scope), false));
        self::assertSame([], iterator_to_array($writer->listPackObjects(str_repeat('0', 64)), false));
        $direct = $this->direct();
        self::assertSame(['accountId' => str_repeat('a', 32), 'bucket' => 'fixture-backup', 'unitId' => $unit], $direct->storageScope());
        $stored = $direct->putNew($this->packKey(), $this->cipher('pack'));
        $before = $this->requests; $listing = $direct->listPackObjects($scope);
        self::assertSame($before, $this->requests);
        self::assertSame([$stored], iterator_to_array($listing, false));
    }

    public function testDirectPackListingStreamsPagesAndRetainsCursorEscaping(): void
    {
        $scope = str_repeat('b', 64); $unit = str_repeat('a', 64);
        $keys = ['packs/' . $scope . '/' . str_repeat('1', 64) . '.age', 'packs/' . $scope . '/' . str_repeat('2', 64) . '.age'];
        $prefix = 'wordpress-r2/' . $unit . '/'; $cursor = 'safe +/=&?% cursor'; $pages = 0;
        $repo = new DirectR2Repository(['accountId' => str_repeat('a', 32), 'bucket' => 'fixture-backup', 'accessKeyId' => str_repeat('B', 32), 'secretAccessKey' => str_repeat('c', 64), 'unitId' => $unit],
            static function (array $request, callable $consume) use ($keys, $prefix, $cursor, &$pages): array {
                $queryText = parse_url($request['url'], PHP_URL_QUERY);
                if ($queryText !== null) {
                    parse_str($queryText, $query); ++$pages;
                    self::assertSame($prefix . 'packs/' . str_repeat('b', 64) . '/', $query['prefix']);
                    self::assertSame($pages === 1 ? null : $cursor, $query['continuation-token'] ?? null);
                    if ($pages === 2) { self::assertStringContainsString('continuation-token=' . rawurlencode($cursor), $queryText); }
                    $body = '<ListBucketResult><IsTruncated>' . ($pages === 1 ? 'true' : 'false') . '</IsTruncated><Contents><Key>' . $prefix . $keys[$pages - 1] . '</Key></Contents>';
                    if ($pages === 1) { $body .= '<NextContinuationToken>' . htmlspecialchars($cursor, ENT_XML1) . '</NextContinuationToken>'; }
                    $consume($body . '</ListBucketResult>');
                    return ['status' => 200, 'headers' => []];
                }
                if ($request['method'] === 'HEAD') { return ['status' => 200, 'headers' => ['content-length' => '4']]; }
                $consume('pack'); return ['status' => 200, 'headers' => []];
            });
        $listing = $repo->listPackObjects($scope);
        self::assertSame(0, $pages); $listing->rewind();
        self::assertSame($keys[0], $listing->current()['key']); self::assertSame(1, $pages);
        $listing->next(); self::assertSame($keys[1], $listing->current()['key']); self::assertSame(2, $pages);
        $listing->next(); self::assertFalse($listing->valid());
    }

    public function testLocalPublishesVerifiedV4ObjectsWithPersistedReferences(): void
    {
        $root = Fixture::temp(); $snapshot = str_repeat('1', 64);
        $writer = LocalRepository::forWriting($root, str_repeat('a', 64));
        $pack = $writer->putNew($this->packKey(), $this->cipher('pack ciphertext'));
        $page = $writer->putNew($this->pageKey($snapshot), $this->cipher('page ciphertext'));
        $manifest = $writer->putNew('manifests/' . $snapshot . '.age', $this->cipher('root ciphertext'));
        $writer->publishWithObjects($snapshot, $manifest, [$pack, $page]);
        $reader = LocalRepository::forRecovery($root, str_repeat('a', 64));
        self::assertSame(['snapshotId' => $snapshot, 'manifest' => $manifest, 'objects' => [$pack, $page]], $reader->completed($snapshot));
        foreach ([$pack, $page] as $receipt) {
            $target = Fixture::temp() . '/download.age';
            $reader->verify($receipt); $reader->download($receipt, $target);
            self::assertSame($receipt['sha256'], hash_file('sha256', $target));
        }
        $record = json_decode(file_get_contents($reader->objectPath('commits/' . $snapshot . '.json')), true);
        self::assertSame(['snapshotId', 'manifest', 'objects'], array_keys($record));
    }

    public function testMissingCorruptDuplicateAndMalformedV4ReferencesCannotPublishLocally(): void
    {
        foreach (['missing', 'corrupt', 'duplicate', 'malformed'] as $fault) {
            $root = Fixture::temp(); $snapshot = str_repeat('2', 64); $unit = str_repeat('a', 64);
            $writer = LocalRepository::forWriting($root, $unit);
            $manifest = $writer->putNew('manifests/' . $snapshot . '.age', $this->cipher('root'));
            $pack = ['key' => $this->packKey(), 'bytes' => 4, 'sha256' => hash('sha256', 'pack')];
            if ($fault !== 'missing') { $writer->putNew($pack['key'], $this->cipher('pack')); }
            $refs = [$pack];
            if ($fault === 'corrupt') { file_put_contents($root . '/' . $unit . '/' . $pack['key'], 'oops'); }
            if ($fault === 'duplicate') { $refs[] = $pack; }
            if ($fault === 'malformed') { $refs[0]['key'] = 'packs/../private.age'; }
            $this->rejected(fn () => $writer->publishWithObjects($snapshot, $manifest, $refs));
            self::assertSame([], LocalRepository::forRecovery($root, $unit)->listCompleted());
        }
    }

    public function testDirectV4CompletionDownloadAndRetentionPreserveSharedPack(): void
    {
        $repo = $this->direct(); $old = str_repeat('3', 64); $recent = str_repeat('4', 64);
        $pack = $repo->putNew($this->packKey(), $this->cipher('shared pack'));
        $oldPage = $repo->putNew($this->pageKey($old), $this->cipher('old page'));
        $manifest = $repo->putNew('manifests/' . $old . '.age', $this->cipher('old root'));
        $repo->publishWithObjects($old, $manifest, [$pack, $oldPage]);
        $this->now += 31 * 86400;
        $recentPage = $repo->putNew($this->pageKey($recent), $this->cipher('recent page'));
        $manifest = $repo->putNew('manifests/' . $recent . '.age', $this->cipher('recent root'));
        $repo->publishWithObjects($recent, $manifest, [$pack, $recentPage]);
        self::assertSame([$pack, $recentPage], $repo->completed($recent)['objects']);
        $target = Fixture::temp() . '/page.age'; $repo->download($recentPage, $target);
        self::assertSame('recent page', file_get_contents($target));
        $expired = $repo->retention(30);
        self::assertSame([$old], array_column($expired, 'snapshotId'));
        self::assertSame([$pack['key']], $expired[0]['retainedShared']);
        self::assertContains($oldPage['key'], $expired[0]['deleted']);
        $repo->verify($pack); $repo->completed($recent);
        self::assertSame([], $repo->deleteSnapshot($recent)['retainedShared']);
        $this->rejected(fn () => $repo->verify($pack));
    }

    public function testBrokenV4ReferenceCannotPublishDirectCompletion(): void
    {
        $repo = $this->direct(); $snapshot = str_repeat('5', 64);
        $manifest = $repo->putNew('manifests/' . $snapshot . '.age', $this->cipher('root'));
        $pack = $repo->putNew($this->packKey(), $this->cipher('pack'));
        $pack['sha256'] = str_repeat('0', 64);
        $this->rejected(fn () => $repo->publishWithObjects($snapshot, $manifest, [$pack]));
        self::assertSame([], $repo->listCompleted());
    }

    public function testLegacyDirectCompletionProtectsUnlistedSharedV4References(): void
    {
        $repo = $this->direct(); $snapshot = str_repeat('6', 64); $legacy = str_repeat('7', 64);
        $pack = $repo->putNew($this->packKey(), $this->cipher('pack'));
        $manifest = $repo->putNew('manifests/' . $snapshot . '.age', $this->cipher('root'));
        $repo->publishWithObjects($snapshot, $manifest, [$pack]);
        $oldManifest = $repo->putNew('manifests/' . $legacy . '.age', $this->cipher('legacy root'));
        $repo->publish($legacy, $oldManifest);
        self::assertSame(['snapshotId' => $legacy, 'manifest' => $oldManifest], $repo->completed($legacy));
        self::assertSame([$pack['key']], $repo->deleteSnapshot($snapshot)['retainedShared']);
        $repo->verify($pack);
    }

    public function testV4CiphertextSizeLimitsRejectBeforeStorageOrTransport(): void
    {
        $root = Fixture::temp(); $local = LocalRepository::forWriting($root, str_repeat('a', 64));
        $direct = $this->direct();
        foreach ([[$this->packKey(), V4Format::MAX_CIPHER_BYTES], [$this->pageKey(str_repeat('8', 64)), V4Format::MAX_PAGE_BYTES + 65536]] as [$key, $maximum]) {
            $file = $this->cipher(''); $stream = fopen($file, 'wb'); ftruncate($stream, $maximum + 1); fclose($stream);
            $this->rejected(fn () => $local->putNew($key, $file));
            $this->rejected(fn () => $direct->putNew($key, $file));
        }
        self::assertFalse($local->hasObjects());
        self::assertSame(0, $this->requests);
    }

    private function packKey(): string { return 'packs/' . str_repeat('b', 64) . '/' . str_repeat('c', 64) . '.age'; }
    private function pageKey(string $snapshot): string { return 'pages/' . $snapshot . '/' . str_repeat('d', 64) . '.age'; }
    private function cipher(string $content): string { $file = Fixture::temp() . '/cipher.age'; file_put_contents($file, $content); return $file; }

    private function rejected(callable $operation): void
    {
        try { $operation(); }
        catch (\RuntimeException | \InvalidArgumentException $expected) { $this->addToAssertionCount(1); return; }
        self::fail('Invalid ciphertext must be rejected.');
    }

    private function direct(): DirectR2Repository
    {
        return new DirectR2Repository(['accountId' => str_repeat('a', 32), 'bucket' => 'fixture-backup',
            'accessKeyId' => str_repeat('B', 32), 'secretAccessKey' => str_repeat('c', 64), 'unitId' => str_repeat('a', 64)],
            function (array $request, callable $consume): array {
                $this->requests++; $path = parse_url($request['url'], PHP_URL_PATH);
                $status = 200; $headers = []; $body = '';
                if ($request['method'] === 'PUT') {
                    if (isset($this->objects[$path])) { $status = 412; }
                    else { $this->objects[$path] = stream_get_contents($request['body']); }
                } elseif ($request['method'] === 'DELETE') { unset($this->objects[$path]); $status = 204; }
                elseif (parse_url($request['url'], PHP_URL_QUERY) !== null) {
                    parse_str(parse_url($request['url'], PHP_URL_QUERY), $query);
                    $body = '<ListBucketResult><IsTruncated>false</IsTruncated>';
                    foreach (array_keys($this->objects) as $key) {
                        $logical = substr($key, strlen('/fixture-backup/'));
                        if (str_starts_with($logical, $query['prefix'])) { $body .= '<Contents><Key>' . htmlspecialchars($logical, ENT_XML1) . '</Key></Contents>'; }
                    }
                    $body .= '</ListBucketResult>';
                } elseif (!isset($this->objects[$path])) { $status = 404; }
                elseif ($request['method'] === 'HEAD') { $headers['content-length'] = (string) strlen($this->objects[$path]); }
                else { $body = $this->objects[$path]; }
                if ($body !== '') { $consume($body); }
                return ['status' => $status, 'headers' => $headers];
            }, function (): int { return $this->now; });
    }
}
