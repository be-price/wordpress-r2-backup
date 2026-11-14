<?php
declare(strict_types=1);

namespace WordPressR2Backup;

use RuntimeException;

final class V4IndexRebuilder
{
    private AgeProcess $age;
    private ObjectReader $reader;

    public function __construct(AgeProcess $age, ObjectReader $reader)
    {
        $this->age = $age;
        $this->reader = $reader;
    }

    public function rebuild($identity, array $recipients, string $directory): bool
    {
        FileSafety::privateDirectory($directory);
        if (scandir($directory) !== ['.', '..']) { throw new RuntimeException('V4 index rebuild requires an empty private directory.'); }
        $keySetId = Manifest::keySetId($recipients);
        $workspace = dirname($directory) . '/.wrb-index-check-' . bin2hex(random_bytes(16));
        if (!mkdir($workspace, 0700)) { throw new RuntimeException('Cannot create index verification workspace.'); }
        try {
            $actual = method_exists($this->reader, 'storageScope') ? $this->reader->storageScope() : null;
            $scope = $actual === null ? null : $actual + ['keySetId' => $keySetId, 'encoding' => V4Format::ENCODING];
            $hasV4 = false;
            $unreadableRoots = MetadataMap::create($workspace . '/unreadable-roots');
            foreach ($this->reader->listCompleted() as $record) {
                try { $manifest = $this->root($record, $identity, $workspace); }
                catch (RuntimeException $error) {
                    $packScopes = self::packScopes($record);
                    if ($packScopes === []) { throw $error; }
                    $unreadableRoots->put($record['snapshotId'], ['scopes' => $packScopes]);
                    $hasV4 = true;
                    continue;
                }
                if ($manifest['version'] !== 4) { continue; }
                $hasV4 = true;
                if ($manifest['keySetId'] !== $keySetId) { continue; }
                if ($scope === null) { $scope = $manifest['scope']; }
                elseif (V4Format::scopeId($scope) !== V4Format::scopeId($manifest['scope'])) { throw new RuntimeException('V4 roots do not identify one current repository scope.'); }
            }
            if (!$hasV4 && $scope !== null && method_exists($this->reader, 'listPackObjects')) {
                foreach ($this->reader->listPackObjects(V4Format::scopeId($scope)) as $receipt) { $hasV4 = true; break; }
            }
            if (!$hasV4) { return false; }
            if ($scope === null || !method_exists($this->reader, 'listPackObjects')) { throw new RuntimeException('Cannot determine a unique scoped v4 pack repository.'); }
            V4Format::scopeId($scope);
            foreach ($unreadableRoots->entries() as $failure) {
                if (in_array(V4Format::scopeId($scope), $failure['scopes'], true)) { throw new RuntimeException('Cannot verify a retained root in the selected v4 scope.'); }
            }
            $seen = MetadataMap::create($workspace . '/verified-packs');
            $restorer = new V4Restorer($this->age, $this->reader);
            $verified = (function () use ($scope, $identity, $workspace, $seen, $restorer, $unreadableRoots): \Generator {
                foreach ($this->reader->listCompleted() as $record) {
                    if ($unreadableRoots->get($record['snapshotId']) !== null) { continue; }
                    $manifest = $this->root($record, $identity, $workspace);
                    if ($manifest['version'] !== 4 || V4Format::scopeId($manifest['scope']) !== V4Format::scopeId($scope)) { continue; }
                    rewind($identity);
                    foreach ($restorer->verifiedPacks($manifest, $identity, $workspace, $seen) as $descriptor) {
                        yield ['descriptor' => $descriptor, 'rootScopeSha256' => V4ContentIndex::rootScopeSha256($manifest['roots'])];
                    }
                }
                foreach ($this->reader->listPackObjects(V4Format::scopeId($scope)) as $receipt) {
                    $cached = $seen->get($receipt['key']);
                    if ($cached !== null) {
                        if ($cached['object'] !== $receipt) { throw new RuntimeException('Listed v4 pack differs from its retained receipt.'); }
                        continue;
                    }
                    rewind($identity);
                    $descriptor = $restorer->verifyPack($receipt, $identity, $workspace);
                    $seen->put($receipt['key'], $descriptor);
                    yield ['descriptor' => $descriptor, 'rootScopeSha256' => null];
                }
            })();
            V4ContentIndex::rebuild($directory, $scope, $this->reader, $verified);
            return true;
        } finally { $this->removeTree($workspace); }
    }

    private function root(array $record, $identity, string $workspace): array
    {
        $receipt = $record['manifest'] ?? null;
        if (!is_array($receipt)) { throw new RuntimeException('Missing completion root receipt.'); }
        Manifest::validateReceipt($receipt);
        if ($receipt['key'] !== 'manifests/' . ($record['snapshotId'] ?? '') . '.age') { throw new RuntimeException('Completion root belongs to another snapshot.'); }
        $cipher = $workspace . '/root-' . bin2hex(random_bytes(12)) . '.age'; $plain = null;
        try {
            $this->reader->download($receipt, $cipher);
            FileSafety::assertRegularFile($cipher);
            if (filesize($cipher) !== $receipt['bytes'] || !hash_equals($receipt['sha256'], hash_file('sha256', $cipher))) { throw new RuntimeException('Downloaded root differs from its receipt.'); }
            rewind($identity);
            $plain = $this->age->decryptToMemory($cipher, $identity, Manifest::MAX_BYTES);
            $manifest = Manifest::fromArray(json_decode(stream_get_contents($plain), true, 32, JSON_THROW_ON_ERROR))->toArray();
            if ($manifest['unitId'] !== $this->reader->unitId() || $manifest['snapshotId'] !== $record['snapshotId']) { throw new RuntimeException('Root belongs to another backup unit or snapshot.'); }
            return $manifest;
        } finally { if (is_resource($plain)) { fclose($plain); } if (@lstat($cipher) !== false) { unlink($cipher); } }
    }

    private static function packScopes(array $record): array
    {
        $scopes = [];
        foreach ($record['objects'] ?? [] as $receipt) {
            Manifest::validateReceipt($receipt);
            if (preg_match('~^packs/([a-f0-9]{64})/[a-f0-9]{64}\.age$~D', $receipt['key'], $match)) { $scopes[$match[1]] = true; }
        }
        if (count($scopes) > 1) { throw new RuntimeException('Completion references multiple v4 storage scopes.'); }
        return array_keys($scopes);
    }

    private function removeTree(string $directory): void
    {
        FileSafety::privateDirectory($directory);
        foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $entry) {
            $path = $entry->getPathname();
            FileSafety::assertNoLinks($path);
            if ($entry->isDir()) { $this->removeTree($path); }
            else { FileSafety::assertRegularFile($path); if (!unlink($path)) { throw new RuntimeException('Cannot remove index verification metadata.'); } }
        }
        if (!rmdir($directory)) { throw new RuntimeException('Cannot remove index verification workspace.'); }
    }
}
