<?php
declare(strict_types=1);

namespace WordPressR2Backup;

final class SnapshotWriter
{
    private AgeProcess $age;
    private ObjectWriter $writer;
    private DedupIndex $index;

    public function __construct(AgeProcess $age, ObjectWriter $writer, DedupIndex $index)
    {
        $this->age = $age;
        $this->writer = $writer;
        $this->index = $index;
    }

    public function capture(string $frozenSyntheticRoot, string $syntheticSqlFile, array $recipients): string
    {
        $input = self::sourcePlan($frozenSyntheticRoot, $syntheticSqlFile, $recipients, $this->writer->unitId());
        $workspace = realpath(sys_get_temp_dir()) . '/wrb-capture-' . bin2hex(random_bytes(16));
        if (!mkdir($workspace, 0700)) { throw new \RuntimeException('Cannot create capture workspace.'); }
        $job = CaptureJob::create($workspace, $input);
        try {
            return $this->index->withLock(fn (): string => $this->capturePrepared($job)['snapshotId']);
        } finally {
            $job->remove();
            rmdir($workspace);
        }
    }

    public static function sourcePlan(string $frozenSyntheticRoot, string $syntheticSqlFile, array $recipients, string $unitId): array
    {
        $root = FileSafety::privateDirectory($frozenSyntheticRoot);
        $temporaryRoot = realpath(sys_get_temp_dir());
        if (!str_starts_with($root, $temporaryRoot . '/wrb-') || !is_file($root . '/.wrb-synthetic') || is_link($root . '/.wrb-synthetic') || file_get_contents($root . '/.wrb-synthetic') !== 'wordpress-r2-backup-v1') {
            throw new \RuntimeException('Only an explicitly generated synthetic workspace is supported');
        }
        FileSafety::assertRegularFile($syntheticSqlFile);
        sort($recipients, SORT_STRING);
        $keySetId = Manifest::keySetId($recipients);
        return ['root' => $root, 'sql' => $syntheticSqlFile, 'unitId' => $unitId, 'recipients' => $recipients,
            'keySetId' => $keySetId, 'inventory' => self::inventory($root),
            'sqlHash' => hash_file('sha256', $syntheticSqlFile), 'sqlBytes' => filesize($syntheticSqlFile)];
    }

    public function capturePrepared(CaptureJob $job, ?callable $heartbeat = null): array
    {
        $input = $job->input();
        if ($input['unitId'] !== $this->writer->unitId()) { throw new \RuntimeException('Capture job belongs to another unit.'); }
        $this->assertSource($input);
        $files = [];
        foreach ($input['inventory'] as $entry) {
            if ($entry['type'] === 'directory') { $files[] = $entry; continue; }
            if ($heartbeat !== null) { $heartbeat(); }
            $reserved = $this->index->findOrReserve($entry['sha256'], $entry['size'], $input['keySetId']);
            $receipt = $this->verifiedCandidate($reserved);
            if ($receipt === null) {
                $candidate = $job->candidate($reserved['key'], function (string $cipher) use ($job, $input, $entry): void {
                    $plain = $job->directory() . '/plain';
                    $this->stageEncrypt($input['root'] . '/' . $entry['path'], $plain, $cipher, $entry['sha256'], $entry['size'], $input['recipients']);
                }, $this->writer);
                if (!in_array($candidate['receipt'], $reserved['candidates'], true)) { $this->index->recordCandidate($reserved['key'], $candidate['receipt']); }
                $receipt = $this->storeCandidate($candidate, $heartbeat);
                $this->index->markReady($reserved['key'], $receipt);
            }
            $files[] = $entry + ['object' => $receipt];
        }
        $database = $job->candidate('databases/' . $job->snapshotId() . '.age', function (string $cipher) use ($job, $input): void {
            $this->stageEncrypt($input['sql'], $job->directory() . '/database.sql', $cipher, $input['sqlHash'], $input['sqlBytes'], $input['recipients']);
        }, $this->writer);
        $databaseObject = $this->storeCandidate($database, $heartbeat);
        $this->assertSource($input);
        $report = $job->captureReport();
        $data = [
            'format' => 'wordpress-r2-backup', 'version' => $report === null ? 1 : 2, 'dataClass' => 'synthetic',
            'unitId' => $this->writer->unitId(), 'snapshotId' => $job->snapshotId(), 'keySetId' => $input['keySetId'],
            'recipients' => $input['recipients'], 'capturedAt' => $job->capturedAt(), 'files' => $files,
            'database' => ['size' => $input['sqlBytes'], 'sha256' => $input['sqlHash'], 'object' => $databaseObject],
        ];
        if ($report !== null) { $data['captureReport'] = $report; }
        $manifest = Manifest::fromArray($data);
        $json = json_encode($manifest->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > Manifest::MAX_BYTES) { throw new \RuntimeException('Manifest exceeds the supported limit'); }
        $manifestCandidate = $job->candidate('manifests/' . $job->snapshotId() . '.age', function (string $cipher) use ($job, $input, $json): void {
            $plain = $job->directory() . '/manifest.json';
            FileSafety::assertNoLinks($plain);
            if (file_exists($plain)) { FileSafety::assertRegularFile($plain); unlink($plain); }
            $stream = fopen($plain, 'xb');
            try {
                if (!chmod($plain, 0600) || fwrite($stream, $json) !== strlen($json) || !fflush($stream) || !\WordPressR2Backup\FileSafety::sync($stream)) {
                    throw new \RuntimeException('Cannot stage the capture manifest.');
                }
            } finally { fclose($stream); }
            try { $this->age->encrypt($plain, $cipher, $input['recipients']); }
            finally { unlink($plain); }
        }, $this->writer);
        $manifestObject = $this->storeCandidate($manifestCandidate, $heartbeat);
        $this->assertSource($input);
        if ($heartbeat !== null) { $heartbeat(); }
        if ($job->captureReport() !== $report) { throw new \RuntimeException('Fixed capture report changed before publication.'); }
        $this->writer->publish($job->snapshotId(), $manifestObject);
        return $job->finish($manifestObject);
    }

    private function assertSource(array $input): void
    {
        $current = self::sourcePlan($input['root'], $input['sql'], $input['recipients'], $this->writer->unitId());
        if ($current !== $input) { throw new \RuntimeException('Source changed during capture.'); }
    }

    private function stageEncrypt(string $source, string $plain, string $cipher, string $sha256, int $bytes, array $recipients): void
    {
        FileSafety::assertNoLinks($plain);
        if (file_exists($plain)) { FileSafety::assertRegularFile($plain); unlink($plain); }
        try {
            self::copyVerified($source, $plain, $sha256, $bytes);
            $this->age->encrypt($plain, $cipher, $recipients);
        } finally { if (is_file($plain)) { unlink($plain); } }
    }

    private function storeCandidate(array $candidate, ?callable $heartbeat): array
    {
        if ($heartbeat !== null) { $heartbeat(); }
        if ($candidate['file'] === null) { $this->writer->verify($candidate['receipt']); return $candidate['receipt']; }
        $receipt = $this->writer->putNew($candidate['receipt']['key'], $candidate['file']);
        if ($receipt !== $candidate['receipt']) { throw new \RuntimeException('Stored ciphertext differs from the fixed capture candidate.'); }
        $this->writer->verify($receipt);
        return $receipt;
    }

    private function verifiedCandidate(array $entry): ?array
    {
        if ($entry['ready'] !== null) {
            $this->writer->verify($entry['ready']);
            return $entry['ready'];
        }
        foreach ($entry['candidates'] as $candidate) {
            try {
                $this->writer->verify($candidate);
            } catch (CaptureControlException $error) {
                throw $error;
            } catch (\RuntimeException $ignoredError) {
                continue;
            }
            $this->index->markReady($entry['key'], $candidate);
            return $candidate;
        }
        return null;
    }

    private static function inventory(string $root): array
    {
        $entries = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            $path = substr($file->getPathname(), strlen($root) + 1);
            if ($path === '.wrb-synthetic') {
                continue;
            }
            if ($file->isLink() || (!$file->isDir() && !$file->isFile()) || !$file->isReadable()) {
                throw new \RuntimeException('Unsupported or unreadable source entry');
            }
            if ($file->isDir()) {
                $entries[] = ['path' => $path, 'type' => 'directory'];
            } else {
                $entries[] = ['path' => $path, 'type' => 'file', 'size' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getPathname())];
            }
            if (count($entries) > Manifest::MAX_ENTRIES) {
                throw new \RuntimeException('Too many source entries');
            }
        }
        FileSafety::validateEntries($entries);
        usort($entries, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));
        return $entries;
    }

    private static function copyVerified(string $source, string $destination, string $sha256, int $bytes): void
    {
        $input = fopen($source, 'rb');
        $output = fopen($destination, 'xb');
        if ($input === false || $output === false) {
            throw new \RuntimeException('Cannot stage source data');
        }
        try {
            chmod($destination, 0600);
            if (stream_copy_to_stream($input, $output) !== $bytes || !fflush($output) || !\WordPressR2Backup\FileSafety::sync($output)) {
                throw new \RuntimeException('Cannot stage complete source data');
            }
        } finally {
            fclose($input);
            fclose($output);
        }
        if (!hash_equals($sha256, hash_file('sha256', $destination))) {
            throw new \RuntimeException('Source content changed while staging');
        }
    }
}
