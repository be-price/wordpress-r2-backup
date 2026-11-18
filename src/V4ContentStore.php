<?php
declare(strict_types=1);

namespace WordPressR2Backup;

use InvalidArgumentException;
use RuntimeException;

final class V4ContentStore
{
    private const MAX_LANES = 8;
    private AgeProcess $age;
    private array $recipients;
    private string $directory;
    private string $scopeId;
    private CaptureMetadataBatch $contents;
    private V4Pages $pages;
    private string $state = 'open';
    private array $buffers = [];
    private int $bufferBytes = 0;
    private int $contentCount = 0;
    private array $objects = [];
    private ?array $result = null;
    private array $sharing = ['newBytes' => 0, 'reusedBytes' => 0, 'repackedBytes' => 0, 'newPacks' => 0, 'reusedPacks' => 0, 'repackedPacks' => 0];
    private ?V4ContentIndex $index;
    private ?MetadataMap $selectedContents;
    private ?MetadataMap $checkedPacks = null;
    private array $pendingPacks = [];
    /** Elapsed time, calls, packs and ciphertext bytes of shared-pack verification. Measurement only. */
    private array $verification = ['nanoseconds' => 0, 'calls' => 0, 'packs' => 0, 'bytes' => 0];
    private int $indexNanoseconds = 0;
    private ?string $rootScopeSha256;
    private bool $full;
    private $heartbeat;
    private int $lastHeartbeat;

    public function __construct(AgeProcess $age, array $recipients, string $privateDirectory, array $scope, string $snapshotId, ?V4ContentIndex $index = null, ?string $rootScopeSha256 = null, bool $full = false, ?MetadataMap $selectedContents = null, ?callable $heartbeat = null)
    {
        $this->heartbeat = $heartbeat; $this->lastHeartbeat = time();
        $this->directory = FileSafety::privateDirectory($privateDirectory);
        $this->scopeId = V4Format::scopeId($scope);
        if (Manifest::keySetId($recipients) !== $scope['keySetId']) {
            throw new InvalidArgumentException('V4 content recipients differ from the storage scope.');
        }
        $this->pages = new V4Pages($age, $recipients, $this->directory, $snapshotId, 'packs');
        $this->contents = new CaptureMetadataBatch(MetadataMap::createProcessLocal($this->directory . '/content-map'), $heartbeat);
        $this->age = $age; $this->recipients = $recipients;
        $this->index = $index; $this->rootScopeSha256 = $rootScopeSha256;
        $this->full = $full; $this->selectedContents = $selectedContents;
        if ($index !== null) {
            if ($index->identity()['scopeId'] !== $this->scopeId || !is_string($rootScopeSha256) || !preg_match('/^[a-f0-9]{64}$/D', $rootScopeSha256)) {
                throw new InvalidArgumentException('V4 sharing index differs from the capture scope.');
            }
            $this->checkedPacks = MetadataMap::createProcessLocal($this->directory . '/reuse-map');
        }
    }

    public function accept(string $bytes, string $lane): array
    {
        if ($this->state !== 'open') { throw new RuntimeException('V4 content store is no longer open.'); }
        try {
            if (strlen($bytes) > V4Format::MAX_CONTENT_BYTES) { throw new InvalidArgumentException('V4 content exceeds its size limit.'); }
            if (!preg_match('/^(?:code|media|authentication|forms|other|database:[A-Za-z0-9_][A-Za-z0-9_.-]{0,255})$/D', $lane)) {
                throw new InvalidArgumentException('Invalid v4 content lane.');
            }
            $size = strlen($bytes); $sha256 = hash('sha256', $bytes); $id = V4Format::contentId($size, $sha256);
            $partitionInitialized = null;
            $started = hrtime(true);
            $existing = $this->contents->get($id, $partitionInitialized);
            $this->indexNanoseconds += hrtime(true) - $started;
            if ($existing !== null) { V4Format::validateContent($existing); return $existing; }
            if ($this->contentCount >= V4Format::MAX_PAGES * V4Format::MAX_PAGE_RECORDS) {
                throw new RuntimeException('V4 content count limit exceeded.');
            }
            $reused = $this->reuse($id, $size, $sha256);
            if ($reused['reference'] !== null) {
                $this->rememberContent($id, $reused['reference'], $partitionInitialized); ++$this->contentCount;
                $this->sharing['reusedBytes'] += $size;
                return $reused['reference'];
            }
            if (isset($this->buffers[$lane]) && ($this->buffers[$lane]['size'] + $size > V4Format::MAX_PACK_BYTES
                || count($this->buffers[$lane]['contents']) >= V4Format::MAX_PACK_MEMBERS)) { $this->flush($lane); }
            while ($this->bufferBytes + $size > V4Format::MAX_PACK_BYTES
                || (!isset($this->buffers[$lane]) && count($this->buffers) >= self::MAX_LANES)) {
                $this->flush($this->largestLane());
            }
            if (!isset($this->buffers[$lane])) {
                $this->buffers[$lane] = ['key' => V4Format::packKey($this->scopeId), 'size' => 0, 'contents' => [], 'repacked' => false];
            }
            $ref = ['id' => $id, 'size' => $size, 'sha256' => $sha256, 'pack' => $this->buffers[$lane]['key']];
            $this->sharing['newBytes'] += $size;
            if ($reused['repacked']) { $this->sharing['repackedBytes'] += $size; $this->buffers[$lane]['repacked'] = true; }
            $this->buffers[$lane]['contents'][] = ['id' => $id, 'size' => $size, 'sha256' => $sha256, 'bytes' => $bytes];
            $this->buffers[$lane]['size'] += $size; $this->bufferBytes += $size;
            $this->rememberContent($id, $ref, $partitionInitialized); ++$this->contentCount;
            if ($this->buffers[$lane]['size'] >= V4Format::MAX_PACK_BYTES
                || count($this->buffers[$lane]['contents']) >= V4Format::MAX_PACK_MEMBERS) { $this->flush($lane); }
            elseif ($this->bufferBytes >= V4Format::MAX_PACK_BYTES) { $this->flush($this->largestLane()); }
            return $ref;
        } catch (\Throwable $error) { $this->fail(); throw $error; }
    }

    /** Totals of shared-pack verification so far: nanoseconds, calls, packs and bytes. Measurement only. */
    public function verificationTotals(): array { return $this->verification; }

    /** Time spent accessing capture maps and the sharing index, excluding shared-pack verification. */
    public function indexNanoseconds(): int { return $this->indexNanoseconds; }

    private function rememberContent(string $key, array $value, bool $partitionInitialized): void
    {
        $started = hrtime(true);
        // Write the first record immediately so every later lookup, including a pending hit, still verifies the on-disk leaf.
        $this->contents->put($key, $value, !$partitionInitialized);
        $this->indexNanoseconds += hrtime(true) - $started;
    }

    public function flushContentReferences(): void
    {
        if ($this->state !== 'open') { throw new RuntimeException('V4 content store is no longer open.'); }
        $started = hrtime(true);
        try { $this->contents->flush(); }
        catch (\Throwable $error) { $this->fail(); throw $error; }
        finally { $this->indexNanoseconds += hrtime(true) - $started; }
    }

    private function mapGet(MetadataMap $map, string $key): ?array
    {
        $started = hrtime(true);
        $value = $map->get($key);
        $this->indexNanoseconds += hrtime(true) - $started;
        return $value;
    }

    private function mapPut(MetadataMap $map, string $key, array $value): void
    {
        $started = hrtime(true);
        $map->put($key, $value);
        $this->indexNanoseconds += hrtime(true) - $started;
    }

    public function finish(): array
    {
        if ($this->state === 'failed') { throw new RuntimeException('V4 content store failed.'); }
        if ($this->state === 'finished') { return $this->result; }
        try {
            $this->flushContentReferences();
            $this->verifyPending();
            foreach (array_keys($this->buffers) as $lane) { $this->pulseHeartbeat(); $this->flush($lane); $this->pulseHeartbeat(); }
            $pages = $this->pages->finish();
            $this->pulseHeartbeat();
            if (count($this->objects) + count($pages) > V4Format::MAX_OBJECTS) { throw new RuntimeException('V4 ciphertext count limit exceeded.'); }
            $this->result = ['pages' => $pages, 'objects' => $this->objects, 'contentCount' => $this->contentCount, 'packCount' => count($this->objects), 'sharing' => $this->sharing];
            $this->state = 'finished';
            return $this->result;
        } catch (\Throwable $error) { $this->fail(); throw $error; }
    }

    private function largestLane(): string
    {
        $largest = null;
        foreach ($this->buffers as $lane => $buffer) {
            if ($largest === null || $buffer['size'] > $this->buffers[$largest]['size']) { $largest = $lane; }
        }
        if ($largest === null) { throw new RuntimeException('No v4 content buffer can be flushed.'); }
        return $largest;
    }

    private function flush(string $lane): void
    {
        $this->verifyPending();
        $this->pulseHeartbeat();
        if (count($this->objects) >= V4Format::MAX_OBJECTS - 1) { throw new RuntimeException('V4 ciphertext count limit exceeded.'); }
        $buffer = $this->buffers[$lane];
        $pack = V4Pack::write($this->age, $this->recipients, $this->directory, $this->scopeId, $lane, $buffer['contents'], $buffer['key']);
        $this->pulseHeartbeat();
        if ($this->index !== null) {
            $started = hrtime(true);
            $this->index->recordCandidate($pack['descriptor'], $this->rootScopeSha256);
            $this->indexNanoseconds += hrtime(true) - $started;
        }
        $this->pulseHeartbeat();
        $this->pages->append($pack['descriptor']);
        $this->objects[] = ['object' => $pack['descriptor']['object'], 'cipherFile' => $pack['cipherFile']];
        ++$this->sharing['newPacks'];
        if ($buffer['repacked']) { ++$this->sharing['repackedPacks']; }
        $this->bufferBytes -= $buffer['size']; unset($this->buffers[$lane]);
    }

    private function reuse(string $id, int $size, string $sha256): array
    {
        if ($this->index === null) { return ['reference' => null, 'repacked' => false]; }
        $started = hrtime(true);
        $candidate = $this->index->lookup($id);
        $this->indexNanoseconds += hrtime(true) - $started;
        if ($candidate === null) { return ['reference' => null, 'repacked' => false]; }
        $descriptor = $candidate['descriptor']; $key = $descriptor['object']['key'];
        $checked = $this->mapGet($this->checkedPacks, $key);
        if ($checked === null && isset($this->pendingPacks[$key])) {
            $pending = $this->pendingPacks[$key];
            if ($pending['descriptor'] !== $descriptor || $pending['rootScopeSha256'] !== $candidate['rootScopeSha256']) { throw new RuntimeException('V4 indexed pack changed during capture.'); }
            $checked = ['eligible' => $pending['eligible']];
        }
        if ($checked === null) {
            $eligible = $this->full && $candidate['rootScopeSha256'] === $this->rootScopeSha256;
            if (!$eligible && $this->selectedContents !== null) {
                $eligible = true;
                foreach ($descriptor['members'] as $member) {
                    if ($this->mapGet($this->selectedContents, $member['id']) === null) { $eligible = false; break; }
                }
            }
            $checked = ['eligible' => $eligible];
            $this->pendingPacks[$key] = ['descriptor' => $descriptor, 'eligible' => $eligible, 'rootScopeSha256' => $candidate['rootScopeSha256']];
            if (count($this->pendingPacks) >= 4) { $this->verifyPending(); }
        }
        if (!$checked['eligible']) { return ['reference' => null, 'repacked' => true]; }
        foreach ($descriptor['members'] as $member) {
            if ($member['id'] === $id) {
                if ($member['size'] !== $size || $member['sha256'] !== $sha256) { throw new RuntimeException('V4 indexed content differs from the capture.'); }
                return ['reference' => ['id' => $id, 'size' => $size, 'sha256' => $sha256, 'pack' => $key], 'repacked' => false];
            }
        }
        throw new RuntimeException('V4 indexed pack has lost its content.');
    }

    /** Verify all deferred packs while the database and sharing-index locks are still held. */
    public function verifyPending(): void
    {
        if ($this->state !== 'open') { throw new RuntimeException('V4 content store is no longer open.'); }
        if ($this->pendingPacks === []) { return; }
        try {
            $this->pulseHeartbeat();
            $started = hrtime(true);
            try { $verified = $this->index->verifyReadyPacks(array_keys($this->pendingPacks)); }
            finally { $this->verification['nanoseconds'] += hrtime(true) - $started; ++$this->verification['calls']; }
            foreach ($verified as $descriptor) { ++$this->verification['packs']; $this->verification['bytes'] += $descriptor['object']['bytes']; }
            $this->pulseHeartbeat();
            if (count($verified) !== count($this->pendingPacks)) { throw new RuntimeException('V4 indexed pack verification is incomplete.'); }
            $position = 0;
            foreach ($this->pendingPacks as $key => $pending) {
                if (($verified[$position] ?? null) !== $pending['descriptor']) { throw new RuntimeException('V4 indexed pack changed during capture.'); }
                ++$position;
            }
            foreach ($this->pendingPacks as $key => $pending) {
                $this->mapPut($this->checkedPacks, $key, ['eligible' => $pending['eligible']]);
                if ($pending['eligible']) {
                    if (count($this->objects) >= V4Format::MAX_OBJECTS - 1) { throw new RuntimeException('V4 ciphertext count limit exceeded.'); }
                    $this->pages->append($pending['descriptor']);
                    $this->objects[] = ['object' => $pending['descriptor']['object'], 'cipherFile' => null];
                    ++$this->sharing['reusedPacks'];
                }
            }
            $this->pendingPacks = [];
        } catch (\Throwable $error) { $this->fail(); throw $error; }
    }

    private function fail(): void
    {
        $this->state = 'failed'; $this->buffers = []; $this->pendingPacks = []; $this->bufferBytes = 0;
    }

    private function pulseHeartbeat(): void
    {
        if ($this->heartbeat === null) { return; }
        $now = time();
        if ($now - $this->lastHeartbeat >= 15) {
            ($this->heartbeat)();
            $this->lastHeartbeat = $now;
        }
    }
}
