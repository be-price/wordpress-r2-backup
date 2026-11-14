<?php
declare(strict_types=1);

namespace WordPressR2Backup;

final class DedupIndex
{
    private array $entries = [];
    private bool $locked = false;
    private bool $initialized = false;
    private ?array $managed = null;
    private array $positions = [];
    private ?\Closure $advance = null;
    private bool $unconfirmed = false;

    private string $directory;
    /** @var ObjectWriter|ObjectReader */
    private $writer;
    private bool $rebuilding;

    /** @param ObjectWriter|ObjectReader $writer */
    private function __construct(string $directory, $writer, bool $rebuilding = false)
    {
        $this->directory = $directory;
        $this->writer = $writer;
        $this->rebuilding = $rebuilding;

    }

    public static function openOrCreate(string $privateDirectory, ObjectWriter $writer): self
    {
        $directory = FileSafety::privateDirectory($privateDirectory);
        if (file_exists($directory . '/managed.json') || is_link($directory . '/managed.json')) {
            throw new CaptureControlException('A managed index requires a managed capture session.');
        }
        $index = new self($directory, $writer);
        $index->withLock(function () use ($index): void {
            $file = $index->directory . '/index.jsonl';
            if (!is_file($file)) {
                if ($index->writer->hasObjects()) {
                    throw new \RuntimeException('Index missing. Rebuild it on the administrator device.');
                }
                $index->append(['type' => 'header', 'unitId' => $index->writer->unitId()]);
            }
            $index->replay();
        });
        $index->initialized = true;
        return $index;
    }

    public static function initializeManaged(string $privateDirectory, string $unitId): array
    {
        FileSafety::privateDirectory($privateDirectory);
        if (!preg_match('/^[a-f0-9]{64}$/D', $unitId) || scandir($privateDirectory) !== ['.', '..']) {
            throw new \RuntimeException('Managed initialization requires a new empty index directory.');
        }
        $data = ['type' => 'header', 'unitId' => $unitId];
        self::writeNew($privateDirectory . '/index.jsonl', self::line($data));
        return self::seal($privateDirectory, $unitId);
    }

    public static function sealRebuilt(string $privateDirectory, ObjectReader $reader): array
    {
        FileSafety::privateDirectory($privateDirectory);
        $index = new self($privateDirectory, $reader);
        return $index->withLock(function () use ($index, $privateDirectory, $reader): array {
            FileSafety::assertRegularFile($privateDirectory . '/rebuild-proof');
            if (!hash_equals(trim(file_get_contents($privateDirectory . '/rebuild-proof')), hash_file('sha256', $privateDirectory . '/index.jsonl'))) {
                throw new \RuntimeException('Only a freshly verified rebuilt index can be sealed.');
            }
            foreach ($index->entries as $entry) {
                if ($entry['ready'] === null) { throw new \RuntimeException('Rebuilt index contains an unverified entry.'); }
                $reader->verify($entry['ready']);
            }
            return self::seal($privateDirectory, $reader->unitId());
        });
    }

    public static function openManaged(string $privateDirectory, ObjectWriter $writer): self
    {
        FileSafety::privateDirectory($privateDirectory);
        $file = $privateDirectory . '/managed.json';
        FileSafety::assertRegularFile($file);
        if ((fileperms($file) & 0777) !== 0600 || filesize($file) > 1024) {
            throw new \RuntimeException('Unsafe managed index identity.');
        }
        $metadata = json_decode(file_get_contents($file), true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($metadata) || array_keys($metadata) !== ['version', 'unitId', 'indexId', 'secret']
            || $metadata['version'] !== 1 || $metadata['unitId'] !== $writer->unitId()
            || !is_string($metadata['indexId']) || !preg_match('/^[a-f0-9]{64}$/D', $metadata['indexId'])
            || !is_string($metadata['secret']) || !preg_match('/^[a-f0-9]{64}$/D', $metadata['secret'])) {
            throw new \RuntimeException('Invalid managed index identity.');
        }
        $index = new self($privateDirectory, $writer);
        $index->managed = $metadata;
        $index->initialized = true;
        $index->withLock(static fn () => null);
        return $index;
    }

    public function cursor(): array
    {
        $this->assertLocked();
        if ($this->managed === null || $this->positions === []) { throw new \LogicException('A managed index is required.'); }
        return $this->positions[array_key_last($this->positions)];
    }

    public function reconcile(array $head): ?array
    {
        $this->assertLocked();
        $local = $this->cursor();
        $sequence = $head['sequence'] ?? -1;
        if (!is_int($sequence) || !isset($this->positions[$sequence]) || $this->positions[$sequence] !== $head
            || $local['sequence'] < $sequence || $local['sequence'] > $sequence + 1) {
            throw new CaptureControlException('The local index is stale, branched, or has multiple unconfirmed records.');
        }
        return $local === $head ? null : ['eventId' => $this->eventId($head, $local), 'from' => $head, 'to' => $local];
    }

    public function synchronize(array $head, callable $advance): void
    {
        $pending = $this->reconcile($head);
        $this->advance = \Closure::fromCallable($advance);
        if ($pending !== null) {
            $this->unconfirmed = true;
            ($this->advance)($pending['eventId'], $pending['from'], $pending['to']);
            $this->unconfirmed = false;
        }
    }

    private static function seal(string $directory, string $unitId): array
    {
        $metadata = ['version' => 1, 'unitId' => $unitId, 'indexId' => bin2hex(random_bytes(32)), 'secret' => bin2hex(random_bytes(32))];
        self::writeNew($directory . '/managed.json', json_encode($metadata, JSON_THROW_ON_ERROR));
        $position = self::initialPosition($metadata);
        foreach (file($directory . '/index.jsonl') as $line) { $position = self::nextPosition($metadata, $position, $line); }
        return $position;
    }

    private static function initialPosition(array $metadata): array
    {
        return ['indexId' => $metadata['indexId'], 'sequence' => 0,
            'commitment' => hash_hmac('sha256', 'wrb-index-v1:' . $metadata['unitId'] . ':' . $metadata['indexId'], hex2bin($metadata['secret']))];
    }

    private static function nextPosition(array $metadata, array $from, string $line): array
    {
        return ['indexId' => $metadata['indexId'], 'sequence' => $from['sequence'] + 1,
            'commitment' => hash_hmac('sha256', json_encode($from, JSON_THROW_ON_ERROR) . "\n" . $line, hex2bin($metadata['secret']))];
    }

    private function eventId(array $from, array $to): string
    {
        return hash_hmac('sha256', json_encode([$from, $to], JSON_THROW_ON_ERROR), hex2bin($this->managed['secret']));
    }

    private static function writeNew(string $path, string $contents): void
    {
        FileSafety::assertNoLinks($path);
        $stream = @fopen($path, 'xb');
        if ($stream === false) { throw new \RuntimeException('Index initialization cannot replace existing files.'); }
        try {
            if (!chmod($path, 0600) || fwrite($stream, $contents) !== strlen($contents) || !fflush($stream) || !\WordPressR2Backup\FileSafety::sync($stream)) {
                throw new \RuntimeException('Cannot persist index initialization.');
            }
        } finally { fclose($stream); }
    }

    private static function line(array $data): string
    {
        return json_encode(['data' => $data, 'checksum' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR))], JSON_THROW_ON_ERROR) . "\n";
    }

    public function withLock(callable $operation)
    {
        if ($this->locked) {
            throw new \LogicException('Nested index lock');
        }
        $path = $this->directory . '/unit.lock';
        if (is_link($path)) {
            throw new \RuntimeException('Unsafe index lock');
        }
        $lock = fopen($path, 'c+b');
        if ($lock === false || !chmod($path, 0600) || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException('Cannot lock the backup unit');
        }
        $this->locked = true;
        try {
            $this->replay();
            return $operation();
        } finally {
            $this->advance = null;
            $this->unconfirmed = false;
            $this->locked = false;
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function findOrReserve(string $plainSha256, int $plainBytes, string $keySetId): array
    {
        $this->assertLocked();
        if (!preg_match('/^[a-f0-9]{64}$/D', $plainSha256) || !preg_match('/^[a-f0-9]{64}$/D', $keySetId) || $plainBytes < 0) {
            throw new \InvalidArgumentException('Invalid content identity');
        }
        $identity = $keySetId . ':' . $plainBytes . ':' . $plainSha256;
        if (!isset($this->entries[$identity])) {
            $entry = ['plainSha256' => $plainSha256, 'plainBytes' => $plainBytes, 'keySetId' => $keySetId, 'key' => 'files/' . $keySetId . '/' . bin2hex(random_bytes(32)) . '.age', 'candidates' => [], 'ready' => null];
            $this->append(['type' => 'reserve', 'identity' => $identity, 'entry' => $entry]);
            $this->entries[$identity] = $entry;
        }
        return $this->entries[$identity];
    }

    public function recordCandidate(string $objectKey, array $cipherReceipt): void
    {
        $this->assertLocked();
        $identity = $this->identityFor($objectKey);
        self::validateReceipt($cipherReceipt, $objectKey);
        $this->append(['type' => 'candidate', 'identity' => $identity, 'receipt' => $cipherReceipt]);
        $this->entries[$identity]['candidates'][] = $cipherReceipt;
    }

    public function markReady(string $objectKey, array $verifiedReceipt): void
    {
        $this->assertLocked();
        $identity = $this->identityFor($objectKey);
        self::validateReceipt($verifiedReceipt, $objectKey);
        if (!in_array($verifiedReceipt, $this->entries[$identity]['candidates'], true)) {
            throw new \RuntimeException('Unrecorded ciphertext cannot be reused');
        }
        $this->writer->verify($verifiedReceipt);
        $this->append(['type' => 'ready', 'identity' => $identity, 'receipt' => $verifiedReceipt]);
        $this->entries[$identity]['ready'] = $verifiedReceipt;
    }

    public static function rebuild(string $privateDirectory, ObjectReader $writer, array $verifiedEntries): self
    {
        FileSafety::privateDirectory($privateDirectory);
        if (file_exists($privateDirectory . '/index.jsonl')) {
            throw new \RuntimeException('Rebuild requires a new index directory');
        }
        $index = new self($privateDirectory, $writer, true);
        $index->withLock(function () use ($index, $verifiedEntries): void {
            if (file_exists($index->directory . '/index.jsonl')) {
                throw new \RuntimeException('Rebuild requires a new index directory');
            }
            $index->append(['type' => 'header', 'unitId' => $index->writer->unitId()]);
            $index->rebuilding = false;
            foreach ($verifiedEntries as $verified) {
                $identity = $verified['keySetId'] . ':' . $verified['plainBytes'] . ':' . $verified['plainSha256'];
                if (isset($index->entries[$identity])) {
                    continue;
                }
                $entry = ['plainSha256' => $verified['plainSha256'], 'plainBytes' => $verified['plainBytes'], 'keySetId' => $verified['keySetId'], 'key' => $verified['receipt']['key'], 'candidates' => [], 'ready' => null];
                $index->append(['type' => 'reserve', 'identity' => $identity, 'entry' => $entry]);
                $index->entries[$identity] = $entry;
                $index->recordCandidate($entry['key'], $verified['receipt']);
                $index->markReady($entry['key'], $verified['receipt']);
            }
            self::writeNew($index->directory . '/rebuild-proof', hash_file('sha256', $index->directory . '/index.jsonl'));
        });
        $index->initialized = true;
        return $index;
    }

    private function replay(): void
    {
        if ($this->managed !== null) {
            $identityPath = $this->directory . '/managed.json';
            FileSafety::assertRegularFile($identityPath);
            if ((fileperms($identityPath) & 0777) !== 0600 || filesize($identityPath) > 1024
                || json_decode(file_get_contents($identityPath), true, 8, JSON_THROW_ON_ERROR) !== $this->managed) {
                throw new CaptureControlException('The managed index identity changed or was lost.');
            }
        }
        $this->entries = [];
        $this->positions = $this->managed === null ? [] : [self::initialPosition($this->managed)];
        $path = $this->directory . '/index.jsonl';
        if (!file_exists($path)) {
            if ($this->initialized || (!$this->rebuilding && (!($this->writer instanceof ObjectWriter) || $this->writer->hasObjects()))) {
                throw new \RuntimeException('Index missing. Rebuild it on the administrator device.');
            }
            return;
        }
        FileSafety::assertRegularFile($path);
        $stream = fopen($path, 'rb');
        $lineNumber = 0;
        try {
            while (($line = fgets($stream, 65537)) !== false) {
                if (!str_ends_with($line, "\n") || strlen($line) > 65536) {
                    throw new \RuntimeException('Truncated index record');
                }
                if ($this->managed !== null) { $this->positions[] = self::nextPosition($this->managed, $this->cursor(), $line); }
                $record = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
                $data = $record['data'] ?? null;
                if (!is_array($data) || !hash_equals(hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)), $record['checksum'] ?? '')) {
                    throw new \RuntimeException('Index checksum mismatch');
                }
                if ($lineNumber++ === 0) {
                    if (($data['type'] ?? '') !== 'header' || ($data['unitId'] ?? '') !== $this->writer->unitId()) {
                        throw new \RuntimeException('Index belongs to another backup unit');
                    }
                    continue;
                }
                $identity = $data['identity'] ?? '';
                if ($data['type'] === 'reserve' && !isset($this->entries[$identity])) {
                    $this->entries[$identity] = $data['entry'];
                } elseif ($data['type'] === 'candidate' && isset($this->entries[$identity])) {
                    self::validateReceipt($data['receipt'], $this->entries[$identity]['key']);
                    $this->entries[$identity]['candidates'][] = $data['receipt'];
                } elseif ($data['type'] === 'ready' && isset($this->entries[$identity]) && in_array($data['receipt'], $this->entries[$identity]['candidates'], true)) {
                    $this->entries[$identity]['ready'] = $data['receipt'];
                } else {
                    throw new \RuntimeException('Invalid index state transition');
                }
            }
            if ($lineNumber === 0) {
                throw new \RuntimeException('Empty index requires administrator recovery');
            }
        } finally {
            fclose($stream);
        }
    }

    private function append(array $data): void
    {
        $this->assertLocked();
        $path = $this->directory . '/index.jsonl';
        if (is_link($path)) {
            throw new \RuntimeException('Unsafe index path');
        }
        if ($this->managed !== null && ($this->advance === null || $this->unconfirmed)) { throw new CaptureControlException('Managed index append requires confirmation of the previous record.'); }
        $line = self::line($data);
        $stream = fopen($path, 'ab');
        if ($stream === false) {
            throw new \RuntimeException('Cannot open index');
        }
        try {
            if (!chmod($path, 0600) || fwrite($stream, $line) !== strlen($line) || !fflush($stream) || !\WordPressR2Backup\FileSafety::sync($stream)) {
                throw new \RuntimeException('Cannot persist index record');
            }
        } finally {
            fclose($stream);
        }
        if ($this->managed !== null) {
            $from = $this->cursor();
            $to = self::nextPosition($this->managed, $from, $line);
            $this->positions[] = $to;
            $this->unconfirmed = true;
            ($this->advance)($this->eventId($from, $to), $from, $to);
            $this->unconfirmed = false;
        }
    }

    private function identityFor(string $objectKey): string
    {
        foreach ($this->entries as $identity => $entry) {
            if ($entry['key'] === $objectKey) {
                return $identity;
            }
        }
        throw new \RuntimeException('Unknown object reservation');
    }

    private static function validateReceipt(array $receipt, string $key): void
    {
        if (($receipt['key'] ?? '') !== $key || !is_int($receipt['bytes'] ?? null) || $receipt['bytes'] <= 0 || !is_string($receipt['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $receipt['sha256'])) {
            throw new \RuntimeException('Invalid ciphertext receipt');
        }
    }

    private function assertLocked(): void
    {
        if (!$this->locked) {
            throw new \LogicException('Index operation requires the unit lock');
        }
    }
}
