<?php
declare(strict_types=1);
namespace WordPressR2Backup;

final class CaptureJob
{
    private const MAX_BYTES = 16777216;
    private string $directory;
    private array $state;

    private function __construct(string $directory, array $state)
    {
        $this->directory = $directory;
        $this->state = $state;
    }

    public static function create(string $privateJobsDirectory, array $input, ?array $binding = null, ?array $capture = null): self
    {
        FileSafety::privateDirectory($privateJobsDirectory);
        $jobId = bin2hex(random_bytes(32));
        $directory = FileSafety::createPrivateDirectory($privateJobsDirectory . '/' . $jobId);
        $job = new self($directory, ['version' => 1, 'jobId' => $jobId, 'snapshotId' => bin2hex(random_bytes(32)),
            'capturedAt' => gmdate('Y-m-d\TH:i:s\Z'), 'input' => $input, 'binding' => $binding, 'candidates' => [], 'result' => null]);
        if ($capture !== null) { $job->state['capture'] = $capture; }
        $job->save();
        return $job;
    }

    public static function open(string $privateJobsDirectory, string $jobId): self
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $jobId)) { throw new \RuntimeException('Invalid capture job identifier.'); }
        FileSafety::privateDirectory($privateJobsDirectory);
        $directory = FileSafety::privateDirectory($privateJobsDirectory . '/' . $jobId);
        $file = $directory . '/job.json';
        FileSafety::assertRegularFile($file);
        if ((fileperms($file) & 0777) !== 0600 || filesize($file) > self::MAX_BYTES) { throw new \RuntimeException('Unsafe capture job.'); }
        $record = json_decode(file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
        $state = $record['data'] ?? null;
        if (!is_array($state) || !is_string($record['checksum'] ?? null)
            || !hash_equals(hash('sha256', json_encode($state, JSON_THROW_ON_ERROR)), $record['checksum'])
            || !in_array(array_keys($state), [['version', 'jobId', 'snapshotId', 'capturedAt', 'input', 'binding', 'candidates', 'result'], ['version', 'jobId', 'snapshotId', 'capturedAt', 'input', 'binding', 'candidates', 'result', 'capture']], true)
            || $state['version'] !== 1 || $state['jobId'] !== $jobId || !is_string($state['snapshotId'])
            || !preg_match('/^[a-f0-9]{64}$/D', $state['snapshotId']) || !is_string($state['capturedAt'])
            || !is_array($state['input']) || !is_array($state['candidates'])
            || ($state['result'] !== null && !is_array($state['result']))) {
            throw new \RuntimeException('Invalid capture job record.');
        }
        if ($state['binding'] !== null && (!is_array($state['binding']) || array_keys($state['binding']) !== ['sourceId', 'generation']
            || !is_string($state['binding']['sourceId']) || !preg_match('/^[a-f0-9]{64}$/D', $state['binding']['sourceId'])
            || !is_int($state['binding']['generation']) || $state['binding']['generation'] < 1)) {
            throw new \RuntimeException('Invalid capture source binding.');
        }
        if (array_key_exists('capture', $state) && (!is_array($state['capture']) || !in_array(array_keys($state['capture']), [['record', 'sha256'], ['record', 'sha256', 'report']], true)
            || !is_string($state['capture']['record']) || !is_string($state['capture']['sha256']) || !preg_match('/^[a-f0-9]{64}$/D', $state['capture']['sha256']))) {
            throw new \RuntimeException('Invalid frozen capture reference.');
        }
        if (array_key_exists('capture', $state)) {
            $captureFile = $state['capture']['record'];
            FileSafety::assertRegularFile($captureFile);
            if ($captureFile !== dirname($state['input']['root'] ?? '') . '/capture.json'
                || (fileperms($captureFile) & 0777) !== 0600 || !hash_equals($state['capture']['sha256'], hash_file('sha256', $captureFile))) {
                throw new \RuntimeException('Frozen capture evidence changed.');
            }
        }
        foreach ($state['candidates'] as $key => $candidate) {
            if (!is_array($candidate) || array_keys($candidate) !== ['file', 'receipt'] || $candidate['file'] !== hash('sha256', $key) . '.age') {
                throw new \RuntimeException('Invalid capture candidate record.');
            }
            Manifest::validateReceipt($candidate['receipt']);
            if ($candidate['receipt']['key'] !== $key) { throw new \RuntimeException('Capture candidate key mismatch.'); }
        }
        $job = new self($directory, $state);
        $job->captureReport();
        return $job;
    }

    public function id(): string { return $this->state['jobId']; }
    public function snapshotId(): string { return $this->state['snapshotId']; }
    public function capturedAt(): string { return $this->state['capturedAt']; }
    public function input(): array { return $this->state['input']; }
    public function binding(): ?array { return $this->state['binding']; }
    public function directory(): string { return $this->directory; }
    public function result(): ?array { return $this->state['result']; }

    public function captureReport(): ?array
    {
        $capture = $this->state['capture'] ?? null;
        if ($capture === null) { return null; }
        $file = $capture['record'];
        FileSafety::assertRegularFile($file);
        clearstatcache(true, $file);
        $stat = lstat($file);
        if ($file !== dirname($this->state['input']['root']) . '/capture.json'
            || ($stat['mode'] & 0777) !== 0600 || $stat['nlink'] !== 1 || $stat['size'] > self::MAX_BYTES
            || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())) {
            throw new \RuntimeException('Unsafe fixed capture evidence.');
        }
        $bytes = file_get_contents($file);
        if ($bytes === false || !hash_equals($capture['sha256'], hash('sha256', $bytes))) {
            throw new \RuntimeException('Fixed capture evidence changed.');
        }
        if (!array_key_exists('report', $capture)) { return null; }
        $record = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($record) || CaptureReport::fromRecord($record, $this->state['input']) !== $capture['report']) {
            throw new \RuntimeException('Fixed capture report differs from its evidence.');
        }
        return $capture['report'];
    }
    public function candidateReceipt(string $key): ?array { return $this->state['candidates'][$key]['receipt'] ?? null; }

    public function candidate(string $key, callable $encrypt, ObjectWriter $writer): array
    {
        $name = hash('sha256', $key) . '.age';
        $path = $this->directory . '/' . $name;
        $existing = $this->state['candidates'][$key]['receipt'] ?? null;
        FileSafety::assertNoLinks($path);
        if ($existing !== null && !file_exists($path)) {
            $writer->verify($existing);
            return ['receipt' => $existing, 'file' => null];
        }
        if (!file_exists($path)) {
            $temporary = $this->directory . '/.encrypt-' . bin2hex(random_bytes(16));
            try {
                $encrypt($temporary);
                FileSafety::assertRegularFile($temporary);
                $stream = fopen($temporary, 'r+b');
                try {
                    if (!chmod($temporary, 0600) || !fflush($stream) || !\WordPressR2Backup\FileSafety::sync($stream) || !link($temporary, $path)) {
                        throw new \RuntimeException('Cannot publish a fixed capture candidate.');
                    }
                } finally { fclose($stream); }
            } finally {
                if (is_file($temporary)) { unlink($temporary); }
            }
        }
        FileSafety::assertRegularFile($path);
        $receipt = ['key' => $key, 'bytes' => filesize($path), 'sha256' => hash_file('sha256', $path)];
        Manifest::validateReceipt($receipt);
        if ($existing !== null && $existing !== $receipt) { throw new \RuntimeException('Capture candidate ciphertext changed.'); }
        if ($existing === null) {
            $this->state['candidates'][$key] = ['file' => $name, 'receipt' => $receipt];
            $this->save();
        }
        return ['receipt' => $receipt, 'file' => $path];
    }

    public function finish(array $manifest): array
    {
        Manifest::validateReceipt($manifest);
        $result = ['jobId' => $this->id(), 'snapshotId' => $this->snapshotId(), 'state' => 'completed', 'manifest' => $manifest];
        if ($this->state['result'] !== null && $this->state['result'] !== $result) { throw new \RuntimeException('Capture completion differs from the saved result.'); }
        $this->state['result'] = $result;
        $this->save();
        return $result;
    }

    public function remove(): void
    {
        foreach (scandir($this->directory) as $entry) {
            if ($entry !== '.' && $entry !== '..') { FileSafety::assertRegularFile($this->directory . '/' . $entry); unlink($this->directory . '/' . $entry); }
        }
        rmdir($this->directory);
    }

    private function save(): void
    {
        FileSafety::privateDirectory($this->directory);
        $data = json_encode(['data' => $this->state, 'checksum' => hash('sha256', json_encode($this->state, JSON_THROW_ON_ERROR))], JSON_THROW_ON_ERROR);
        if (strlen($data) > self::MAX_BYTES) { throw new \RuntimeException('Capture job exceeds its supported limit.'); }
        $path = $this->directory . '/job.json';
        FileSafety::assertNoLinks($path);
        $temporary = $this->directory . '/.job-' . bin2hex(random_bytes(12));
        $stream = fopen($temporary, 'xb');
        try {
            if (!chmod($temporary, 0600) || fwrite($stream, $data) !== strlen($data) || !fflush($stream) || !\WordPressR2Backup\FileSafety::sync($stream) || !rename($temporary, $path)) {
                throw new \RuntimeException('Cannot persist capture job.');
            }
        } finally { fclose($stream); if (is_file($temporary)) { unlink($temporary); } }
    }
}
