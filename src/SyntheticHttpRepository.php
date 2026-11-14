<?php
declare(strict_types=1);

namespace WordPressR2Backup;

use RuntimeException;

final class SyntheticHttpRepository implements ObjectWriter, ObjectReader
{
    private const MAX_CIPHER_BYTES = 67108864;
    private const R2_MAX_CIPHER_BYTES = 134217728;
    private const R2_PART_BYTES = 16777216;
    private const MAX_METADATA_BYTES = 16777216;
    private array $connection;
    private ?array $session = null;
    private int $leaseTtl = 30;

    public function __construct(string $privateConnectionFile)
    {
        FileSafety::assertRegularFile($privateConnectionFile);
        $stat = lstat($privateConnectionFile);
        if (realpath($privateConnectionFile) !== $privateConnectionFile || ($stat['mode'] & 0777) !== 0600
            || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid()) || $stat['size'] > 8192) {
            throw new RuntimeException('Expected an owner-only connection file of at most 8 KiB.');
        }
        $json = @file_get_contents($privateConnectionFile, false, null, 0, 8193);
        if (!is_string($json) || strlen($json) > 8192) {
            throw new RuntimeException('Cannot read bounded synthetic connection.');
        }
        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $ignoredError) {
            throw new RuntimeException('Invalid synthetic connection.');
        }
        if (!is_array($data)) {
            throw new RuntimeException('Invalid synthetic connection.');
        }
        $managed = in_array($data['format'] ?? null, ['wrb-synthetic-managed-connection-v1', 'wrb-local-r2-managed-connection-v1'], true);
        $administrator = in_array($data['format'] ?? null, ['wrb-synthetic-administrator-v1', 'wrb-local-r2-administrator-v1'], true);
        self::fields($data, $managed
            ? ['format', 'endpoint', 'unitId', 'role', 'token', 'expiresAt', 'sourceId', 'generation']
            : ['format', 'endpoint', 'unitId', 'role', 'token', 'expiresAt']);
        if ((!$managed && !$administrator && $data['format'] !== 'wrb-synthetic-connection-v1')
            || !is_string($data['endpoint']) || !preg_match('~^http://127\.0\.0\.1:([1-9][0-9]{0,4})$~D', $data['endpoint'], $port)
            || (int) $port[1] > 65535 || !is_string($data['unitId']) || !preg_match('/^[a-f0-9]{64}$/D', $data['unitId'])
            || !in_array($data['role'], $administrator ? ['admin'] : ['writer', 'reader'], true) || !self::validToken($data['token'])
            || !is_int($data['expiresAt']) || $data['expiresAt'] <= time()) {
            throw new RuntimeException('Invalid or expired synthetic connection.');
        }
        if ($managed && ($data['role'] !== 'writer' || !is_string($data['sourceId'])
            || !preg_match('/^[a-f0-9]{64}$/D', $data['sourceId']) || !is_int($data['generation']) || $data['generation'] < 1)) {
            throw new RuntimeException('Invalid synthetic managed connection.');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The cURL extension is required for synthetic HTTP transport.');
        }
        $this->connection = $data;
    }

    public function unitId(): string
    {
        return $this->connection['unitId'];
    }

    public function managed(): bool
    {
        return in_array($this->connection['format'], ['wrb-synthetic-managed-connection-v1', 'wrb-local-r2-managed-connection-v1'], true);
    }

    public function sourceBinding(): array
    {
        $this->requireManaged();
        return ['sourceId' => $this->connection['sourceId'], 'generation' => $this->connection['generation']];
    }

    public function freezeSource(int $expectedGeneration): array
    {
        $this->requireRole('admin');
        if ($expectedGeneration < 1) { throw new RuntimeException('Invalid administrator generation.'); }
        $ticket = $this->json('POST', '/admin/freeze', ['expectedGeneration' => $expectedGeneration]);
        self::fields($ticket, ['freezeId', 'generation', 'readyAt']);
        self::identifier($ticket['freezeId']);
        if ($ticket['generation'] !== $expectedGeneration || !is_int($ticket['readyAt']) || $ticket['readyAt'] < 1) {
            throw new RuntimeException('Invalid source freeze ticket.');
        }
        return $ticket;
    }

    public function sourceBarrier(string $freezeId): array
    {
        $this->requireRole('admin'); self::identifier($freezeId);
        $barrier = $this->json('POST', '/admin/barrier', ['freezeId' => $freezeId]);
        self::fields($barrier, ['freezeId', 'generation', 'readyAt', 'stamp']);
        self::identifier($barrier['stamp']);
        if ($barrier['freezeId'] !== $freezeId || !is_int($barrier['generation']) || $barrier['generation'] < 1
            || !is_int($barrier['readyAt']) || $barrier['readyAt'] < 1) { throw new RuntimeException('Invalid source barrier.'); }
        return $barrier;
    }

    public function activateSource(array $barrier, string $sourceId, array $cursor, string $credentialHash, int $expiresAt): array
    {
        $this->requireRole('admin'); self::identifier($sourceId); self::identifier($credentialHash); $cursor = self::cursor($cursor);
        self::fields($barrier, ['freezeId', 'generation', 'readyAt', 'stamp']);
        self::identifier($barrier['freezeId']); self::identifier($barrier['stamp']);
        if (!is_int($barrier['generation']) || $barrier['generation'] < 1 || $barrier['generation'] === PHP_INT_MAX
            || !is_int($barrier['readyAt']) || $barrier['readyAt'] < 1 || $expiresAt <= time()) { throw new RuntimeException('Invalid source activation.'); }
        $expected = ['sourceId' => $sourceId, 'cursor' => $cursor, 'freezeId' => $barrier['freezeId'], 'stamp' => $barrier['stamp'],
            'expiresAt' => $expiresAt, 'generation' => $barrier['generation'] + 1];
        $result = $this->json('POST', '/admin/activate', ['freezeId' => $barrier['freezeId'], 'stamp' => $barrier['stamp'],
            'sourceId' => $sourceId, 'cursor' => $cursor, 'credentialHash' => $credentialHash, 'expiresAt' => $expiresAt]);
        self::fields($result, array_keys($expected));
        foreach ($expected as $field => $value) {
            if ($result[$field] !== $value) { throw new RuntimeException('The source activation was not confirmed.'); }
        }
        return $result;
    }

    public function newWriterProfile(string $sourceId, int $generation): array
    {
        $this->requireRole('admin'); self::identifier($sourceId);
        if ($generation < 2) { throw new RuntimeException('Invalid new writer generation.'); }
        return ['format' => $this->r2() ? 'wrb-local-r2-managed-connection-v1' : 'wrb-synthetic-managed-connection-v1', 'endpoint' => $this->connection['endpoint'], 'unitId' => $this->unitId(),
            'role' => 'writer', 'token' => bin2hex(random_bytes(32)), 'expiresAt' => $this->connection['expiresAt'],
            'sourceId' => $sourceId, 'generation' => $generation];
    }

    public function administratorStatus(): array
    {
        $this->requireRole('admin');
        return $this->json('GET', '/admin/status');
    }

    public function acquire(string $jobId, string $processNonce, array $cursor, int $ttl = 30): array
    {
        $this->requireManaged();
        self::identifier($jobId); self::identifier($processNonce); $cursor = self::cursor($cursor); self::ttl($ttl);
        $lease = $this->json('POST', '/control/acquire', ['jobId' => $jobId, 'processNonce' => $processNonce, 'ttl' => $ttl, 'cursor' => $cursor]);
        $this->validateLease($lease, $jobId, $cursor);
        $this->session = ['generation' => $lease['generation'], 'jobId' => $jobId, 'processNonce' => $processNonce,
            'fence' => $lease['fence'], 'cursor' => $lease['cursor']];
        $this->leaseTtl = $ttl;
        return $lease;
    }

    public function renew(int $ttl = 30): array
    {
        $this->requireManaged(); $this->requireSession(); self::ttl($ttl);
        $lease = $this->json('POST', '/control/renew', ['ttl' => $ttl]);
        $this->validateLease($lease, $this->session['jobId'], $this->session['cursor']);
        if ($lease['fence'] !== $this->session['fence']) {
            throw new CaptureControlException('The capture lease changed unexpectedly.');
        }
        $this->leaseTtl = $ttl;
        return $lease;
    }

    public function advance(string $eventId, array $from, array $to): array
    {
        $this->requireManaged(); $this->requireSession(); self::identifier($eventId); $from = self::cursor($from); $to = self::cursor($to);
        $accepted = $this->json('POST', '/control/advance', ['eventId' => $eventId, 'from' => $from, 'to' => $to]);
        $accepted = self::cursor($accepted);
        if ($accepted !== $to) {
            throw new CaptureControlException('The capture index position was not confirmed.');
        }
        $this->session['cursor'] = $to;
        return $accepted;
    }

    public function controlStatus(): array
    {
        $this->requireManaged();
        $status = $this->json('GET', '/control/status');
        self::fields($status, ['version', 'unitId', 'sourceId', 'generation', 'head', 'fence', 'frozen', 'activeJob', 'lease', 'lastAdvance', 'publications']);
        if ($status['version'] !== 1 || $status['unitId'] !== $this->unitId() || $status['sourceId'] !== $this->connection['sourceId']
            || $status['generation'] !== $this->connection['generation'] || !is_int($status['fence']) || $status['fence'] < 0
            || !is_bool($status['frozen']) || !is_array($status['head']) || !is_array($status['publications'])) {
            throw new RuntimeException('Invalid synthetic control status.');
        }
        self::cursor($status['head']);
        if ($status['lease'] !== null) {
            if (!is_array($status['lease'])) { throw new RuntimeException('Invalid synthetic control lease.'); }
            self::fields($status['lease'], ['jobId', 'fence', 'expiresAt']);
            if (!is_string($status['lease']['jobId']) || !is_int($status['lease']['fence']) || $status['lease']['fence'] < 1
                || !is_int($status['lease']['expiresAt']) || $status['lease']['expiresAt'] < 1) {
                throw new RuntimeException('Invalid synthetic control lease.');
            }
            self::identifier($status['lease']['jobId']);
        }
        return $status;
    }

    public function putNew(string $objectKey, string $cipherFile): array
    {
        $this->requireRole('writer');
        $this->requireSession();
        if ($this->r2()) { return $this->putR2($objectKey, $cipherFile); }
        FileSafety::assertRegularFile($cipherFile);
        $input = @fopen($cipherFile, 'rb');
        if ($input === false) {
            throw new RuntimeException('Cannot open ciphertext input.');
        }
        try {
            $bytes = fstat($input)['size'];
            if ($bytes > self::MAX_CIPHER_BYTES) {
                throw new RuntimeException('Synthetic HTTP ciphertext exceeds the 64 MiB limit.');
            }
            $hash = hash_init('sha256');
            if (hash_update_stream($hash, $input, $bytes + 1) !== $bytes || !rewind($input)) {
                throw new RuntimeException('Cannot hash stable ciphertext input.');
            }
            $receipt = ['key' => $objectKey, 'bytes' => $bytes, 'sha256' => hash_final($hash)];
            Manifest::validateReceipt($receipt);
            for ($attempt = 0; $attempt < 3; $attempt++) {
                $grant = $this->json('POST', '/upload-grants', $receipt);
                self::fields($grant, ['token', 'expiresAt']);
                if (!self::validToken($grant['token']) || !is_int($grant['expiresAt'])
                    || $grant['expiresAt'] <= time() || $grant['expiresAt'] > $this->connection['expiresAt']) {
                    throw new RuntimeException('Invalid or expired upload grant.');
                }
                if (!rewind($input)) {
                    throw new RuntimeException('Cannot rewind ciphertext input.');
                }
                [$status, $body, $failed] = $this->transfer('PUT', '/objects/' . $objectKey, null, $grant['token'], $input, $bytes);
                if (!$failed && $status >= 200 && $status < 300) {
                    $received = self::decode($body);
                    Manifest::validateReceipt($received);
                    if ($received != $receipt) {
                        throw new RuntimeException('Upload receipt does not match ciphertext.');
                    }
                    return $receipt;
                }
                if (!$failed && $status === 412) {
                    $this->verify($receipt);
                    return $receipt;
                }
                if (!$failed && $status < 500) {
                    throw new RuntimeException('Synthetic HTTP upload was refused.');
                }
                // A missing response can follow a successful create. Keep the same key.
                try {
                    $this->verify($receipt);
                    return $receipt;
                } catch (CaptureControlException $error) {
                    throw $error;
                } catch (RuntimeException $ignoredError) {
                    if ($attempt === 2) {
                        throw new RuntimeException('Synthetic HTTP upload could not be verified.');
                    }
                }
            }
            throw new RuntimeException('Synthetic HTTP upload failed.');
        } finally {
            fclose($input);
        }
    }

    public function verify(array $receipt): void
    {
        $this->receipt($receipt);
        $verified = $this->json('POST', '/verify', $receipt);
        if ($verified !== ['verified' => true]) {
            throw new RuntimeException('Stored ciphertext verification failed.');
        }
    }

    public function publish(string $snapshotId, array $manifestReceipt): void
    {
        $this->requireRole('writer');
        $this->requireSession();
        self::identifier($snapshotId);
        $this->receipt($manifestReceipt);
        if ($manifestReceipt['key'] !== 'manifests/' . $snapshotId . '.age') {
            throw new RuntimeException('Manifest does not belong to snapshot.');
        }
        $record = $this->json('POST', '/complete/' . $snapshotId, $manifestReceipt);
        $this->record($record, $snapshotId);
        if ($record['manifest'] != $manifestReceipt) {
            throw new RuntimeException('Completion receipt does not match manifest.');
        }
    }

    public function hasObjects(): bool
    {
        $this->requireRole('writer');
        $result = $this->json('GET', '/has-objects');
        self::fields($result, ['hasObjects']);
        if (!is_bool($result['hasObjects'])) {
            throw new RuntimeException('Invalid object status.');
        }
        return $result['hasObjects'];
    }

    public function download(array $receipt, string $newCipherFile): void
    {
        $this->requireRole('reader');
        $this->receipt($receipt);
        $parent = FileSafety::privateDirectory(dirname($newCipherFile));
        FileSafety::assertNoLinks($newCipherFile);
        if (@lstat($newCipherFile) !== false) {
            throw new RuntimeException('The ciphertext output already exists.');
        }
        $temporary = $parent . '/.download-' . bin2hex(random_bytes(16));
        $output = @fopen($temporary, 'x+b');
        if ($output === false) {
            throw new RuntimeException('Cannot stage ciphertext download.');
        }
        $bytes = 0;
        $hash = hash_init('sha256');
        try {
            if (!chmod($temporary, 0600)) {
                throw new RuntimeException('Cannot protect ciphertext download.');
            }
            $write = static function ($curl, string $chunk) use ($output, $receipt, $hash, &$bytes): int {
                $length = strlen($chunk);
                if ($bytes + $length > $receipt['bytes'] || fwrite($output, $chunk) !== $length) {
                    return 0;
                }
                hash_update($hash, $chunk);
                $bytes += $length;
                return $length;
            };
            [$status, , $failed] = $this->transfer('GET', '/objects/' . $receipt['key'], null, null, null, 0, $write);
            if ($failed || $status !== 200 || $bytes !== $receipt['bytes']
                || !hash_equals($receipt['sha256'], hash_final($hash)) || !fflush($output) || !\WordPressR2Backup\FileSafety::sync($output)) {
                throw new RuntimeException('Ciphertext download verification failed.');
            }
            FileSafety::privateDirectory($parent);
            FileSafety::assertNoLinks($newCipherFile);
            if (!@link($temporary, $newCipherFile)) {
                throw new RuntimeException('Cannot publish ciphertext without replacement.');
            }
        } finally {
            fclose($output);
            unlink($temporary);
        }
    }

    public function completed(string $snapshotId): array
    {
        if (!$this->managed()) { $this->requireRole('reader'); }
        self::identifier($snapshotId);
        $record = $this->json('GET', '/completed/' . $snapshotId);
        $this->record($record, $snapshotId);
        return $record;
    }

    public function listCompleted(): array
    {
        $result = [];
        foreach ($this->listStatus() as $status) {
            if (!$status['valid']) {
                throw new RuntimeException('A completed snapshot failed verification.');
            }
            unset($status['valid']);
            $result[] = $status;
        }
        return $result;
    }

    public function listStatus(): array
    {
        $this->requireRole('reader');
        $statuses = $this->json('GET', '/completed');
        if (!array_is_list($statuses)) {
            throw new RuntimeException('Invalid snapshot status list.');
        }
        $seen = [];
        foreach ($statuses as $index => $status) {
            if (!is_array($status) || !isset($status['snapshotId']) || !is_string($status['snapshotId']) || !is_bool($status['valid'] ?? null)) {
                throw new RuntimeException('Invalid snapshot status.');
            }
            self::identifier($status['snapshotId']);
            if (isset($seen[$status['snapshotId']])) {
                throw new RuntimeException('Duplicate snapshot status.');
            }
            $seen[$status['snapshotId']] = true;
            if ($status['valid']) {
                $record = $status;
                unset($record['valid']);
                $this->record($record, $status['snapshotId']);
            } else {
                self::fields($status, ['snapshotId', 'valid', 'error']);
                if (!is_string($status['error'])) {
                    throw new RuntimeException('Invalid snapshot failure status.');
                }
                $statuses[$index]['error'] = 'Snapshot verification failed.';
            }
        }
        return $statuses;
    }

    public function listFileObjects(string $keySetId): array
    {
        $this->requireRole('reader');
        self::identifier($keySetId);
        $receipts = $this->json('GET', '/files/' . $keySetId);
        if (!array_is_list($receipts)) {
            throw new RuntimeException('Invalid file object list.');
        }
        $seen = [];
        foreach ($receipts as $receipt) {
            if (!is_array($receipt)) {
                throw new RuntimeException('Invalid file receipt.');
            }
            $this->receipt($receipt);
            if (!str_starts_with($receipt['key'], 'files/' . $keySetId . '/') || isset($seen[$receipt['key']])) {
                throw new RuntimeException('Invalid file object scope.');
            }
            $seen[$receipt['key']] = true;
        }
        return $receipts;
    }

    private function r2(): bool
    {
        return in_array($this->connection['format'], ['wrb-local-r2-managed-connection-v1', 'wrb-local-r2-administrator-v1'], true);
    }

    private function putR2(string $objectKey, string $cipherFile): array
    {
        FileSafety::assertRegularFile($cipherFile);
        $input = @fopen($cipherFile, 'rb');
        if ($input === false) { throw new RuntimeException('Cannot open ciphertext input.'); }
        try {
            $stat = fstat($input);
            $bytes = $stat['size'];
            if ($bytes > self::R2_MAX_CIPHER_BYTES) { throw new RuntimeException('Local R2 ciphertext exceeds the 128 MiB limit.'); }
            $whole = hash_init('sha256');
            $parts = [];
            for ($offset = 0; $offset < $bytes; $offset += self::R2_PART_BYTES) {
                $length = min(self::R2_PART_BYTES, $bytes - $offset);
                $hash = hash_init('sha256');
                for ($remaining = $length; $remaining > 0; $remaining -= strlen($chunk)) {
                    $chunk = fread($input, min(65536, $remaining));
                    if ($chunk === false || $chunk === '') { throw new RuntimeException('Cannot hash stable ciphertext input.'); }
                    hash_update($hash, $chunk); hash_update($whole, $chunk);
                }
                $parts[] = ['bytes' => $length, 'sha256' => hash_final($hash)];
            }
            $receipt = ['key' => $objectKey, 'bytes' => $bytes, 'sha256' => hash_final($whole)];
            $this->receipt($receipt);
            $this->assertR2Input($input, $cipherFile, $stat, $receipt);
            $this->renew($this->leaseTtl);
            $plan = $this->json('POST', '/upload-plans', ['receipt' => $receipt, 'parts' => $parts]);
            self::fields($plan, ['uploadId', 'receipt', 'parts']);
            if (!is_string($plan['uploadId']) || !is_array($plan['parts']) || !array_is_list($plan['parts'])
                || !is_array($plan['receipt']) || !self::sameReceipt($plan['receipt'], $receipt) || count($plan['parts']) !== count($parts)) {
                throw new RuntimeException('Invalid R2 upload plan.');
            }
            self::identifier($plan['uploadId']);
            if (!is_array($plan['receipt'])) { throw new RuntimeException('Invalid R2 upload receipt.'); }
            $this->receipt($plan['receipt']);
            foreach ($parts as $ordinal => $part) {
                $expected = ['key' => 'uploads/' . $plan['uploadId'] . '/' . $ordinal] + $part;
                if (!is_array($plan['parts'][$ordinal])) { throw new RuntimeException('Invalid R2 upload part.'); }
                self::fields($plan['parts'][$ordinal], ['key', 'bytes', 'sha256']);
                if (!self::sameReceipt($plan['parts'][$ordinal], $expected)) { throw new RuntimeException('Invalid R2 upload part.'); }
            }
            foreach ($parts as $ordinal => $part) {
                $confirmed = false;
                for ($attempt = 0; $attempt < 3; $attempt++) {
                    $this->renew($this->leaseTtl);
                    $grant = $this->json('POST', '/part-grants', ['uploadId' => $plan['uploadId'], 'ordinal' => $ordinal]);
                    self::fields($grant, ['token', 'path', 'bytes', 'sha256', 'expiresAt', 'headers']);
                    if (!self::validToken($grant['token']) || $grant['path'] !== '/parts/' . $plan['uploadId'] . '/' . $ordinal
                        || $grant['bytes'] !== $part['bytes'] || $grant['sha256'] !== $part['sha256']
                        || !is_int($grant['expiresAt']) || $grant['expiresAt'] <= time() || $grant['expiresAt'] > $this->connection['expiresAt']
                        || $grant['headers'] !== ['If-None-Match' => '*']) {
                        throw new RuntimeException('Invalid or expired R2 part grant.');
                    }
                    $this->assertR2Input($input, $cipherFile, $stat, $receipt);
                    if (fseek($input, $ordinal * self::R2_PART_BYTES) !== 0) { throw new RuntimeException('Cannot seek ciphertext part.'); }
                    $remaining = $part['bytes'];
                    $hash = hash_init('sha256');
                    $read = static function ($curl, $stream, int $maximum) use ($input, &$remaining, $hash): string {
                        if ($remaining === 0) { return ''; }
                        $chunk = fread($input, min($maximum, $remaining, 65536));
                        if ($chunk === false || $chunk === '') { return ''; }
                        $remaining -= strlen($chunk); hash_update($hash, $chunk);
                        return $chunk;
                    };
                    [$status, $body, $failed] = $this->transfer('PUT', $grant['path'], null, $grant['token'], $input, $part['bytes'], null, $read);
                    $sentHash = hash_final($hash);
                    $this->renew($this->leaseTtl);
                    if (!$failed && $status >= 200 && $status < 300) {
                        $received = self::decode($body);
                        self::fields($received, ['key', 'bytes', 'sha256']);
                        if ($remaining !== 0 || !hash_equals($part['sha256'], $sentHash) || !self::sameReceipt($received, $plan['parts'][$ordinal])) {
                            throw new RuntimeException('R2 part receipt does not match ciphertext.');
                        }
                        $confirmed = true;
                        break;
                    }
                    if (!$failed && $status < 500 && !in_array($status, [403, 408, 429], true)) {
                        throw new RuntimeException('R2 part upload was refused.');
                    }
                }
                if (!$confirmed) { throw new RuntimeException('R2 part upload failed after three attempts.'); }
            }
            $this->assertR2Input($input, $cipherFile, $stat, $receipt);
            $registered = $this->json('POST', '/register', ['uploadId' => $plan['uploadId']]);
            $this->receipt($registered);
            if (!self::sameReceipt($registered, $receipt)) { throw new RuntimeException('R2 registration receipt does not match ciphertext.'); }
            $this->assertR2Input($input, $cipherFile, $stat, $receipt);
            return $receipt;
        } finally {
            fclose($input);
        }
    }

    private static function sameReceipt(array $left, array $right): bool
    {
        return ($left['key'] ?? null) === $right['key'] && ($left['bytes'] ?? null) === $right['bytes']
            && ($left['sha256'] ?? null) === $right['sha256'];
    }

    private function assertR2Input($input, string $path, array $initial, array $receipt): void
    {
        clearstatcache(true, $path);
        FileSafety::assertRegularFile($path);
        $current = lstat($path);
        $opened = fstat($input);
        foreach (['dev', 'ino', 'size', 'mtime', 'ctime'] as $field) {
            if ($current[$field] !== $initial[$field] || $opened[$field] !== $initial[$field]) {
                throw new RuntimeException('Ciphertext input changed during upload.');
            }
        }
        if (!rewind($input)) { throw new RuntimeException('Cannot rewind ciphertext input.'); }
        $hash = hash_init('sha256');
        if (hash_update_stream($hash, $input, $receipt['bytes'] + 1) !== $receipt['bytes']
            || !hash_equals($receipt['sha256'], hash_final($hash))) {
            throw new RuntimeException('Ciphertext input changed during upload.');
        }
    }

    private function json(string $method, string $path, ?array $data = null): array
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            if ($this->r2() && ($path === '/register' || ($attempt === 0 && str_starts_with($path, '/complete/')))) { $this->renew($this->leaseTtl); }
            [$status, $body, $failed] = $this->transfer($method, $path, $data);
            if ($this->r2() && !$failed && $status === 202) { continue; }
            if (!$failed && $status >= 200 && $status < 300) {
                return self::decode($body);
            }
            if (!$failed && $status < 500) {
                throw new RuntimeException('Synthetic HTTP request was refused.');
            }
        }
        throw new RuntimeException('Synthetic HTTP request failed after three attempts.');
    }

    private function transfer(string $method, string $path, ?array $data = null, ?string $token = null, $input = null, int $bytes = 0, ?callable $write = null, ?callable $read = null): array
    {
        if ($this->connection['expiresAt'] <= time()) {
            throw new RuntimeException('Synthetic connection has expired.');
        }
        $curl = curl_init($this->connection['endpoint'] . $path);
        if ($curl === false) {
            throw new RuntimeException('Cannot initialize synthetic HTTP request.');
        }
        $body = '';
        $headers = ['Authorization: Bearer ' . ($token ?? $this->connection['token']),
            'X-WRB-Unit-Id: ' . $this->unitId(), 'Expect:'];
        if ($this->managed()) {
            $headers[] = 'X-WRB-Source-Id: ' . $this->connection['sourceId'];
            $headers[] = 'X-WRB-Source-Generation: ' . $this->connection['generation'];
        }
        if ($this->session !== null && $path !== '/control/acquire') {
            $headers[] = 'X-WRB-Session: ' . json_encode($this->session, JSON_THROW_ON_ERROR);
        }
        $options = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_NETRC => CURL_NETRC_IGNORED,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP,
            CURLOPT_CONNECTTIMEOUT => UnitLedger::MAX_REQUEST_SECONDS, CURLOPT_TIMEOUT => $this->r2() ? 30 : UnitLedger::MAX_REQUEST_SECONDS, CURLOPT_HEADER => false,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1, CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_WRITEFUNCTION => $write ?? static function ($curl, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > self::MAX_METADATA_BYTES) {
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            }];
        if ($data !== null) {
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_POSTFIELDS] = json_encode($data, JSON_THROW_ON_ERROR);
        }
        if (is_resource($input)) {
            $headers[] = 'Content-Type: application/octet-stream';
            $headers[] = 'If-None-Match: *';
            $options[CURLOPT_UPLOAD] = true;
            $options[CURLOPT_INFILE] = $input;
            $options[CURLOPT_INFILESIZE] = $bytes;
            if ($read !== null) { $options[CURLOPT_READFUNCTION] = $read; }
        }
        $options[CURLOPT_HTTPHEADER] = $headers;
        try {
            if (!curl_setopt_array($curl, $options)) {
                throw new RuntimeException('Cannot configure bounded synthetic HTTP request.');
            }
            $result = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($result !== false && in_array($status, [403, 409], true)) {
                try {
                    $error = self::decode($body);
                } catch (RuntimeException $ignoredError) {
                    $error = [];
                }
                if (($error['error'] ?? null) === 'control_rejected') {
                    throw new CaptureControlException('The capture control session was rejected.');
                }
            }
            return [$status, $body, $result === false];
        } finally {
            curl_close($curl);
        }
    }

    private function requireRole(string $role): void
    {
        if ($this->connection['role'] !== $role) {
            throw new RuntimeException('Repository operation is forbidden for this role.');
        }
    }

    private function requireManaged(): void
    {
        if (!$this->managed()) {
            throw new CaptureControlException('Capture control requires a managed writer connection.');
        }
    }

    private function requireSession(): void
    {
        if ($this->managed() && $this->session === null) {
            throw new CaptureControlException('Capture control acquisition is required before writing.');
        }
    }

    private function validateLease(array $lease, string $jobId, array $cursor): void
    {
        self::fields($lease, ['sourceId', 'generation', 'jobId', 'fence', 'expiresAt', 'cursor']);
        if ($lease['sourceId'] !== $this->connection['sourceId'] || $lease['generation'] !== $this->connection['generation']
            || $lease['jobId'] !== $jobId || !is_int($lease['fence']) || $lease['fence'] < 1
            || !is_int($lease['expiresAt']) || $lease['expiresAt'] < 1 || !is_array($lease['cursor'])) {
            throw new CaptureControlException('Invalid capture control lease.');
        }
        if ($this->r2() && ($lease['expiresAt'] <= time() || $lease['expiresAt'] > $this->connection['expiresAt'])) {
            throw new CaptureControlException('Invalid capture lease expiration.');
        }
        if (self::cursor($lease['cursor']) !== self::cursor($cursor)) {
            throw new CaptureControlException('The capture lease index position differs.');
        }
    }

    private static function cursor(array $cursor): array
    {
        self::fields($cursor, ['indexId', 'sequence', 'commitment']);
        if (!is_string($cursor['indexId']) || !is_string($cursor['commitment'])
            || !is_int($cursor['sequence']) || $cursor['sequence'] < 0) {
            throw new RuntimeException('Invalid capture index position.');
        }
        self::identifier($cursor['indexId']); self::identifier($cursor['commitment']);
        return ['indexId' => $cursor['indexId'], 'sequence' => $cursor['sequence'], 'commitment' => $cursor['commitment']];
    }

    private static function ttl(int $ttl): void
    {
        if ($ttl < 1 || $ttl > UnitLedger::MAX_LEASE_SECONDS) {
            throw new RuntimeException('Capture lease duration exceeds the service limit.');
        }
    }

    private function receipt(array $receipt): void
    {
        Manifest::validateReceipt($receipt);
        if ($receipt['bytes'] > ($this->r2() ? self::R2_MAX_CIPHER_BYTES : self::MAX_CIPHER_BYTES)) {
            throw new RuntimeException($this->r2() ? 'Local R2 ciphertext exceeds the 128 MiB limit.' : 'Synthetic HTTP ciphertext exceeds the 64 MiB limit.');
        }
    }

    private function record(array $record, string $snapshotId): void
    {
        self::fields($record, ['snapshotId', 'manifest']);
        if ($record['snapshotId'] !== $snapshotId || !is_array($record['manifest'])
            || ($record['manifest']['key'] ?? null) !== 'manifests/' . $snapshotId . '.age') {
            throw new RuntimeException('Invalid completion record.');
        }
        $this->receipt($record['manifest']);
    }

    private static function decode(string $body): array
    {
        try {
            $data = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $ignoredError) {
            throw new RuntimeException('Invalid synthetic HTTP response.');
        }
        if (!is_array($data)) {
            throw new RuntimeException('Invalid synthetic HTTP response.');
        }
        return $data;
    }

    private static function fields(array $data, array $expected): void
    {
        $actual = array_keys($data);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new RuntimeException('Unexpected synthetic metadata fields.');
        }
    }

    private static function identifier(string $identifier): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $identifier)) {
            throw new RuntimeException('Expected a lowercase 256-bit identifier.');
        }
    }

    private static function validToken($token): bool
    {
        return is_string($token) && (bool) preg_match('/^[\x21-\x7e]{1,1024}$/D', $token);
    }
}
