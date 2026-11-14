<?php
declare(strict_types=1);

namespace WordPressR2Backup\Tests;

use InvalidArgumentException;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use WordPressR2Backup\FileSafety;
use WordPressR2Backup\LocalRepository;
use WordPressR2Backup\Manifest;

final class ContractTest extends TestCase
{
    private string $temporary;

    protected function setUp(): void
    {
        $this->temporary = realpath(sys_get_temp_dir()) . '/wrb-contract-' . bin2hex(random_bytes(12));
        mkdir($this->temporary, 0700);
    }

    protected function tearDown(): void
    {
        $this->remove($this->temporary);
    }

    public static function invalidPaths(): array
    {
        return [
            [['../x']], [['/x']], [['a\\b']], [["a\0b"]], [["a\nb"]], [['a', 'a']],
            [['a', 'a/b']], [['A.txt', 'a.txt']], [["\u{00e9}.txt", "e\u{0301}.txt"]],
            [['A/x', 'a/y']], [['a//b']], [['a/./b']], [['C:/x']], [["bad\xff"]], [["a\u{0085}b"]],
        ];
    }

    /** @dataProvider invalidPaths */
    public function testRejectsUnsafeAndCollidingPaths(array $paths): void
    {
        $this->expectException(InvalidArgumentException::class);
        FileSafety::validatePaths($paths);
    }

    public function testAcceptsJapaneseAndTypedDirectoryParents(): void
    {
        FileSafety::validatePaths(['uploads/日本語.txt']);
        $data = $this->manifest();
        self::assertSame($data, Manifest::fromArray($data)->toArray());
    }

    public static function malformedManifests(): array
    {
        return [
            ['version', 2], ['version', 3], ['dataClass', 'production'], ['unitId', str_repeat('A', 64)],
            ['snapshotId', '../bad'], ['keySetId', str_repeat('0', 64)], ['capturedAt', 'yesterday'],
            ['capturedAt', '2026-02-30T12:00:00Z'], ['recipients', ['age1invalid', 'age1invalid']],
            ['files', [['path' => 'a', 'type' => 'symlink']]],
            ['files', [['path' => 'a', 'type' => 'directory'], ['path' => 'a', 'type' => 'directory']]],
        ];
    }

    /** @dataProvider malformedManifests */
    public function testRejectsInvalidManifestBoundaries(string $field, $value): void
    {
        $data = $this->manifest();
        $data[$field] = $value;
        $this->expectException(InvalidArgumentException::class);
        Manifest::fromArray($data);
    }

    public function testV2RequiresBoundCaptureReportAndV1RetainsExactFields(): void
    {
        $data = $this->manifest();
        $data['version'] = 2;
        $inventory = [
            ['path' => 'empty', 'type' => 'directory'], ['path' => 'uploads', 'type' => 'directory'],
            ['path' => 'uploads/日本語.txt', 'type' => 'file', 'size' => 3, 'sha256' => hash('sha256', 'abc')],
        ];
        $definitions = ['views' => [], 'triggers' => [], 'routines' => [], 'events' => []];
        $digest = static fn (array $value): string => hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $data['captureReport'] = ['method' => 'synthetic-files-mysql-global-read-lock-v1',
            'startedAt' => '2026-10-19T00:00:00.000000Z', 'fixedAt' => '2026-10-19T00:00:03.000000Z', 'sealedAt' => '2026-10-19T00:00:06.000000Z',
            'file' => ['pausedAt' => '2026-10-19T00:00:01.000000Z', 'releasedAt' => '2026-10-19T00:00:05.000000Z', 'inventorySha256' => $digest($inventory)],
            'database' => ['lockedAt' => '2026-10-19T00:00:02.000000Z', 'releasedAt' => '2026-10-19T00:00:04.000000Z',
                'serverVersion' => '8.0.35', 'tables' => [], 'definitions' => $definitions,
                'inventorySha256' => $digest(['tables' => [], 'definitions' => $definitions]), 'sqlHash' => $data['database']['sha256'], 'sqlBytes' => 3]];
        self::assertSame($data, Manifest::fromArray($data)->toArray());
        $invalid = $data; unset($invalid['captureReport']);
        $this->rejects(fn () => Manifest::fromArray($invalid));
        $invalid = $data; $invalid['version'] = 1;
        $this->rejects(fn () => Manifest::fromArray($invalid));
        $invalid = $data; $invalid['version'] = 3;
        $this->rejects(fn () => Manifest::fromArray($invalid));
        $invalid = $data; $invalid['captureReport'] = null;
        $this->rejects(fn () => Manifest::fromArray($invalid));
        $invalid = $data; $invalid['captureReport']['database']['sqlBytes'] = 4;
        $this->rejects(fn () => Manifest::fromArray($invalid));
        $invalid = $data; $invalid['captureReport']['file']['inventorySha256'] = str_repeat('0', 64);
        $this->rejects(fn () => Manifest::fromArray($invalid));
        $invalid = $data; $invalid['extra'] = true;
        $this->rejects(fn () => Manifest::fromArray($invalid));
    }

    public function testRejectsFileParentAndSizeAndEntryLimits(): void
    {
        $data = $this->manifest();
        $data['files'][0] = $data['files'][1];
        $data['files'][0]['path'] = 'uploads';
        $this->rejects(fn () => Manifest::fromArray($data));
        $data = $this->manifest();
        $data['database']['size'] = 1099511627777;
        $this->rejects(fn () => Manifest::fromArray($data));
        $data = $this->manifest();
        $data['files'] = array_fill(0, 100001, ['path' => 'a', 'type' => 'directory']);
        $this->rejects(fn () => Manifest::fromArray($data));
        $data = $this->manifest();
        $data['extra'] = str_repeat('x', 16777216);
        $this->rejects(fn () => Manifest::fromArray($data));
        $data = $this->manifest();
        $data['files'][1]['size'] = '3';
        $this->rejects(fn () => Manifest::fromArray($data));
        $data = $this->manifest();
        $data['files'][1]['object']['key'] = 'files/' . str_repeat('9', 64) . '/' . str_repeat('a', 64) . '.age';
        $this->rejects(fn () => Manifest::fromArray($data));
    }

    public function testRejectsOversizedManifestAndCombinedRestorationSize(): void
    {
        $data = $this->manifest();
        $prefix = str_repeat(str_repeat('a', 250) . '/', 15);
        $data['files'] = [];
        for ($i = 0; $i < 4500; $i++) {
            $data['files'][] = ['path' => $prefix . $i, 'type' => 'directory'];
        }
        self::assertGreaterThan(Manifest::MAX_BYTES, strlen(json_encode($data, JSON_UNESCAPED_UNICODE)));
        $this->rejects(fn () => Manifest::fromArray($data));

        $data = $this->manifest();
        $data['files'][1]['size'] = Manifest::MAX_RESTORED_BYTES;
        $data['database']['size'] = 0;
        self::assertSame($data, Manifest::fromArray($data)->toArray());
        $data['database']['size'] = 1;
        $this->rejects(fn () => Manifest::fromArray($data));
    }

    public function testRejectsUntrustedRepositoryDirectoriesAndStoredLinks(): void
    {
        mkdir($this->temporary . '/public', 0755);
        $this->rejects(fn () => LocalRepository::forWriting($this->temporary . '/public', str_repeat('a', 64)), RuntimeException::class);
        symlink($this->temporary . '/public', $this->temporary . '/link');
        $this->rejects(fn () => LocalRepository::forWriting($this->temporary . '/link', str_repeat('a', 64)), RuntimeException::class);
        [$writer, $reader] = $this->repository();
        $snapshot = str_repeat('9', 64);
        $receipt = $writer->putNew('manifests/' . $snapshot . '.age', $this->cipher('encrypted manifest'));
        $path = $reader->objectPath($receipt['key']);
        unlink($path);
        symlink($this->cipher('elsewhere'), $path);
        $this->rejects(fn () => $writer->verify($receipt), RuntimeException::class);
        $this->rejects(fn () => $reader->objectPath($receipt['key']), RuntimeException::class);
        $this->rejects(fn () => $writer->publish($snapshot, $receipt), RuntimeException::class);
        self::assertSame([], $reader->listCompleted());
    }

    public function testMalformedCompletionRecordStopsEnumeration(): void
    {
        [$writer, $reader] = $this->repository();
        $snapshot = str_repeat('8', 64);
        $receipt = $writer->putNew('manifests/' . $snapshot . '.age', $this->cipher('encrypted manifest'));
        $writer->publish($snapshot, $receipt);
        $path = $reader->objectPath('commits/' . $snapshot . '.json');
        file_put_contents($path, '{"snapshotId":');
        $this->rejects(fn () => $reader->listCompleted(), RuntimeException::class);
        file_put_contents($path, json_encode(['snapshotId' => str_repeat('7', 64), 'manifest' => $receipt]));
        $this->rejects(fn () => $reader->listCompleted(), RuntimeException::class);
    }

    public function testCanonicalRecipientSetHasStableIdentifier(): void
    {
        $recipients = $this->manifest()['recipients'];
        $sorted = $recipients;
        sort($sorted, SORT_STRING);
        self::assertSame(hash('sha256', json_encode($sorted, JSON_THROW_ON_ERROR)), Manifest::keySetId($recipients));
        self::assertSame(Manifest::keySetId($recipients), Manifest::keySetId(array_reverse($recipients)));
        $this->rejects(fn () => Manifest::keySetId([$recipients[0], $recipients[0]]));
        $recipients[0][10] = $recipients[0][10] === 'q' ? 'p' : 'q';
        $this->rejects(fn () => Manifest::keySetId($recipients));
    }

    public function testCorruptOtherSnapshotDoesNotPreventSelectingHealthyOne(): void
    {
        [$writer, $reader] = $this->repository();
        $a = str_repeat('3', 64); $b = str_repeat('4', 64);
        foreach ([$a, $b] as $snapshot) {
            $receipt = $writer->putNew('manifests/' . $snapshot . '.age', $this->cipher('encrypted manifest'));
            $writer->publish($snapshot, $receipt);
        }
        unlink($reader->objectPath('manifests/' . $b . '.age'));
        self::assertSame($a, $reader->completed($a)['snapshotId']);
        $this->rejects(fn () => $reader->completed($b), RuntimeException::class);
        $status = $reader->listStatus();
        self::assertSame([$a, $b], array_column($status, 'snapshotId'));
        self::assertSame([true, false], array_column($status, 'valid'));
        self::assertArrayHasKey('error', $status[1]);
        $this->rejects(fn () => $reader->listCompleted(), RuntimeException::class);
    }

    public function testRejectsOverwriteDeleteAndConcurrentCreate(): void
    {
        [$writer, $reader] = $this->repository();
        $key = 'files/' . str_repeat('b', 64) . '/' . str_repeat('c', 64) . '.age';
        $source = $this->cipher('ciphertext');
        $receipt = $writer->putNew($key, $source);
        self::assertSame(['key' => $key, 'bytes' => 10, 'sha256' => hash('sha256', 'ciphertext')], $receipt);
        $writer->verify($receipt);
        $reader->verify($receipt);
        $this->rejects(fn () => $writer->putNew($key, $source), RuntimeException::class);
        file_put_contents($source, 'different');
        $this->rejects(fn () => $writer->putNew($key, $source), RuntimeException::class);
        $this->rejects(fn () => $writer->delete($key), RuntimeException::class);
        $this->rejects(fn () => $reader->delete($key), RuntimeException::class);
        self::assertSame('ciphertext', file_get_contents($reader->objectPath($key)));

        $raceKey = 'databases/' . str_repeat('d', 64) . '.age';
        $script = <<<'CHILD'
require $argv[1];
while (!file_exists($argv[5])) { usleep(1000); }
try {
    \WordPressR2Backup\LocalRepository::forWriting($argv[2], $argv[3])->putNew($argv[4], $argv[6]);
    exit(0);
} catch (\RuntimeException $error) { exit(23); }
CHILD;
        $children = [];
        for ($i = 0; $i < 2; $i++) {
            $children[] = proc_open([PHP_BINARY, '-r', $script, dirname(__DIR__) . '/vendor/autoload.php',
                $this->temporary, str_repeat('a', 64), $raceKey, $this->temporary . '/go', $source],
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        }
        touch($this->temporary . '/go');
        $results = array_map(static fn ($child) => proc_close($child), $children);
        sort($results);
        self::assertSame([0, 23], $results);
    }

    public function testRoleBoundariesAndPathEscapeAndLinksAreRejected(): void
    {
        [$writer, $reader] = $this->repository();
        $key = 'databases/' . str_repeat('c', 64) . '.age';
        $source = $this->cipher('ciphertext');
        $this->rejects(fn () => $writer->objectPath($key), RuntimeException::class);
        $this->rejects(fn () => $writer->listCompleted(), RuntimeException::class);
        $this->rejects(fn () => $reader->putNew($key, $source), RuntimeException::class);
        $this->rejects(fn () => $reader->publish(str_repeat('c', 64), []), RuntimeException::class);
        $this->rejects(fn () => $writer->putNew('../escape.age', $source));
        $this->rejects(fn () => $reader->objectPath('../escape.age'));
        mkdir($this->temporary . '/outside', 0700);
        symlink($this->temporary . '/outside', $this->temporary . '/' . str_repeat('a', 64) . '/databases');
        $this->rejects(fn () => $writer->putNew($key, $source), RuntimeException::class);
        self::assertSame([], scandir($this->temporary . '/outside') === false ? [] : array_values(array_diff(scandir($this->temporary . '/outside'), ['.', '..'])));
    }

    public function testOnlyPublishedAndVerifiedManifestIsListed(): void
    {
        [$writer, $reader] = $this->repository();
        self::assertFalse($writer->hasObjects());
        $snapshot = str_repeat('c', 64);
        $receipt = $writer->putNew('manifests/' . $snapshot . '.age', $this->cipher('opaque encrypted manifest'));
        self::assertTrue($writer->hasObjects());
        self::assertSame([], $reader->listCompleted());
        $wrong = $receipt;
        $wrong['sha256'] = str_repeat('0', 64);
        $this->rejects(fn () => $writer->verify($wrong), RuntimeException::class);
        $this->rejects(fn () => $writer->publish($snapshot, $wrong), RuntimeException::class);
        $writer->publish($snapshot, $receipt);
        self::assertSame([['snapshotId' => $snapshot, 'manifest' => $receipt]], $reader->listCompleted());
        $this->rejects(fn () => $writer->publish($snapshot, $receipt), RuntimeException::class);
        file_put_contents($reader->objectPath($receipt['key']), 'tampered');
        $this->rejects(fn () => $reader->listCompleted(), RuntimeException::class);
        unlink($reader->objectPath($receipt['key']));
        $this->rejects(fn () => $reader->listCompleted(), RuntimeException::class);
    }

    public function testOldReferencedObjectCannotBeExpired(): void
    {
        [$writer, $reader] = $this->repository();
        $data = $this->manifest();
        $key = $data['files'][1]['object']['key'];
        $old = $writer->putNew($key, $this->cipher('old shared encrypted file'));
        touch($reader->objectPath($key), 946684800);
        foreach ([str_repeat('1', 64), str_repeat('2', 64)] as $snapshot) {
            $data['snapshotId'] = $snapshot;
            $data['database']['object']['key'] = 'databases/' . $snapshot . '.age';
            $data['files'][1]['object'] = $old;
            Manifest::fromArray($data);
            $manifest = $writer->putNew('manifests/' . $snapshot . '.age', $this->cipher('opaque manifest referencing old object'));
            $writer->publish($snapshot, $manifest);
        }
        $this->rejects(fn () => $writer->delete($key), RuntimeException::class);
        self::assertCount(2, $reader->listCompleted());
        self::assertSame([$old], $reader->listFileObjects($data['keySetId']));
        self::assertSame('old shared encrypted file', file_get_contents($reader->objectPath($key)));
    }

    private function manifest(): array
    {
        $recipients = [$this->recipient(1), $this->recipient(2)];
        $keySet = hash('sha256', json_encode($recipients, JSON_THROW_ON_ERROR));
        $snapshot = str_repeat('c', 64);
        return [
            'format' => 'wordpress-r2-backup', 'version' => 1, 'dataClass' => 'synthetic',
            'unitId' => str_repeat('a', 64), 'snapshotId' => $snapshot, 'keySetId' => $keySet,
            'recipients' => $recipients, 'capturedAt' => '2026-10-19T12:00:00+09:00',
            'files' => [
                ['path' => 'uploads', 'type' => 'directory'],
                ['path' => 'uploads/日本語.txt', 'type' => 'file', 'size' => 3, 'sha256' => hash('sha256', 'abc'),
                    'object' => ['key' => 'files/' . $keySet . '/' . str_repeat('b', 64) . '.age', 'bytes' => 200, 'sha256' => str_repeat('e', 64)]],
                ['path' => 'empty', 'type' => 'directory'],
            ],
            'database' => ['size' => 3, 'sha256' => hash('sha256', 'sql'),
                'object' => ['key' => 'databases/' . $snapshot . '.age', 'bytes' => 201, 'sha256' => str_repeat('f', 64)]],
        ];
    }

    private function recipient(int $seed): string
    {
        $alphabet = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
        $data = array_fill(0, 52, 0);
        $data[0] = $seed;
        $values = array_merge([3, 3, 3, 0, 1, 7, 5], $data, array_fill(0, 6, 0));
        $checksum = 1;
        foreach ($values as $value) {
            $top = $checksum >> 25;
            $checksum = (($checksum & 0x1ffffff) << 5) ^ $value;
            foreach ([0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3] as $i => $generator) {
                if (($top >> $i) & 1) { $checksum ^= $generator; }
            }
        }
        $checksum ^= 1;
        $result = 'age1';
        foreach ($data as $value) { $result .= $alphabet[$value]; }
        for ($i = 5; $i >= 0; $i--) { $result .= $alphabet[($checksum >> (5 * $i)) & 31]; }
        return $result;
    }

    private function repository(): array
    {
        $writer = LocalRepository::forWriting($this->temporary, str_repeat('a', 64));
        return [$writer, LocalRepository::forRecovery($this->temporary, str_repeat('a', 64))];
    }

    private function cipher(string $content): string
    {
        $path = $this->temporary . '/cipher-' . bin2hex(random_bytes(6));
        file_put_contents($path, $content);
        chmod($path, 0600);
        return $path;
    }

    private function rejects(callable $operation, string $type = InvalidArgumentException::class): void
    {
        try { $operation(); } catch (\Throwable $error) { self::assertInstanceOf($type, $error); return; }
        self::fail('Invalid operation was accepted.');
    }

    private function remove(string $path): void
    {
        if (is_link($path) || !is_dir($path)) { unlink($path); return; }
        foreach (array_diff(scandir($path), ['.', '..']) as $entry) { $this->remove($path . '/' . $entry); }
        rmdir($path);
    }
}
