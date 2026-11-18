<?php
declare(strict_types=1);

namespace WordPressR2Backup;

use RuntimeException;

/** Buffers encoded capture metadata. Pending content references still require a verified read of their on-disk partition. */
final class CaptureMetadataBatch
{
    private const MAX_RECORDS = 32768;
    private const MAX_BYTES = 4194304;
    private MetadataMap $map;
    private array $pending = [];
    private int $bytes = 0;
    private $heartbeat;

    public function __construct(MetadataMap $map, ?callable $heartbeat)
    {
        $this->map = $map;
        $this->heartbeat = $heartbeat;
    }

    public function get(string $key, ?bool &$partitionInitialized): ?array
    {
        $stored = $this->map->get($key, $partitionInitialized);
        if (!isset($this->pending[$key])) { return $stored; }
        if (!$partitionInitialized) { throw new RuntimeException('The capture metadata partition is missing.'); }
        $pending = json_decode($this->pending[$key], true, 16, JSON_THROW_ON_ERROR);
        if ($stored !== null && $stored !== $pending) { throw new RuntimeException('The capture metadata record changed.'); }
        return $pending;
    }

    public function put(string $key, array $value, bool $writeImmediately = false): void
    {
        if ($writeImmediately) { $this->map->put($key, $value); return; }
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (isset($this->pending[$key])) {
            if ($this->pending[$key] !== $json) { throw new RuntimeException('Metadata key is already assigned.'); }
            return;
        }
        $bytes = strlen($key) + strlen($json);
        if ($bytes > self::MAX_BYTES) { throw new RuntimeException('Capture metadata record exceeds its buffer limit.'); }
        // Bound the encoded data and the number of PHP array entries independently.
        if (count($this->pending) >= self::MAX_RECORDS || $this->bytes + $bytes > self::MAX_BYTES) { $this->flush(); }
        $this->pending[$key] = $json;
        $this->bytes += $bytes;
    }

    public function flush(): void
    {
        if ($this->pending === []) { return; }
        $this->map->putProcessLocalBatch($this->pending, $this->heartbeat);
        $this->pending = [];
        $this->bytes = 0;
    }
}
