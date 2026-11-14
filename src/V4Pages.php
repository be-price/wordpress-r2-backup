<?php
declare(strict_types=1);

namespace WordPressR2Backup;

use InvalidArgumentException;
use RuntimeException;

final class V4Pages
{
    private AgeProcess $age;
    private array $recipients;
    private string $directory;
    private string $snapshotId;
    private string $kind;
    private string $state = 'open';
    private array $records = [];
    private int $recordBytes = 0;
    private array $descriptors = [];

    public function __construct(AgeProcess $age, array $recipients, string $privateDirectory, string $snapshotId, string $kind)
    {
        FileSafety::privateDirectory($privateDirectory); Manifest::keySetId($recipients);
        V4Format::pageKey($snapshotId);
        if (!in_array($kind, ['packs', 'filesystem', 'database'], true)) { throw new InvalidArgumentException('Unsupported v4 page kind.'); }
        $this->age = $age; $this->recipients = $recipients; $this->directory = $privateDirectory;
        $this->snapshotId = $snapshotId; $this->kind = $kind;
    }

    public function append(array $record): void
    {
        if ($this->state !== 'open') { throw new RuntimeException('V4 pages are no longer open for records.'); }
        V4Format::validateRecord($this->kind, $record); $encoded = V4Format::json($record);
        $count = count($this->records);
        if ($count > 0 && ($count >= V4Format::MAX_PAGE_RECORDS
            || strlen($this->prefix()) + $this->recordBytes + $count + strlen($encoded) + 2 > V4Format::MAX_PAGE_BYTES)) {
            $this->flush();
        }
        $this->records[] = $encoded; $this->recordBytes += strlen($encoded);
    }

    public function finish(): array
    {
        if ($this->state === 'failed') { throw new RuntimeException('V4 page encryption failed.'); }
        if ($this->state === 'open') { $this->flush(); $this->state = 'finished'; }
        return $this->descriptors;
    }

    private function prefix(): string
    {
        $empty = V4Format::json(['version' => 4, 'snapshotId' => $this->snapshotId, 'kind' => $this->kind, 'sequence' => count($this->descriptors), 'records' => []]);
        return substr($empty, 0, -2);
    }

    private function flush(): void
    {
        if ($this->records === []) { return; }
        if (count($this->descriptors) >= V4Format::MAX_PAGES) { $this->state = 'failed'; throw new RuntimeException('V4 page count limit exceeded.'); }
        $key = V4Format::pageKey($this->snapshotId); $cipherFile = $this->directory . '/page-' . basename($key);
        $prefix = $this->prefix(); $records = $this->records;
        $chunks = (static function () use ($prefix, $records): \Generator {
            yield $prefix;
            foreach ($records as $index => $record) { if ($index > 0) { yield ','; } yield $record; }
            yield ']}';
        })();
        try {
            $plain = $this->age->encryptStream($chunks, $cipherFile, $this->recipients);
            $bytes = filesize($cipherFile); $sha256 = hash_file('sha256', $cipherFile);
            if ($bytes === false || $sha256 === false) { throw new RuntimeException('Cannot inspect encrypted v4 page.'); }
            $descriptor = ['kind' => $this->kind, 'sequence' => count($this->descriptors), 'count' => count($this->records),
                'size' => $plain['size'], 'sha256' => $plain['sha256'], 'object' => ['key' => $key, 'bytes' => $bytes, 'sha256' => $sha256]];
            V4Format::validatePageDescriptor($descriptor);
            $this->descriptors[] = $descriptor + ['cipherFile' => $cipherFile];
            $this->records = []; $this->recordBytes = 0;
        } catch (\Throwable $error) {
            $this->state = 'failed';
            if (isset($bytes)) { @unlink($cipherFile); }
            throw $error;
        }
    }
}
