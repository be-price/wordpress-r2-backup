<?php
declare(strict_types=1);
namespace WordPressR2Backup;

final class SyntheticAdmin
{
    private const ERRORS = ['invalid_keys', 'invalid_request', 'keys_required', 'challenge_invalid', 'challenge_expired', 'challenge_locked', 'challenge_used', 'keys_unconfirmed', 'operation_active', 'operation_missing', 'busy', 'state_invalid', 'capture_failed', 'listing_failed', 'runtime_invalid'];
    private SyntheticAdminRuntime $runtime;

    public function __construct(SyntheticAdminRuntime $runtime)
    {
        $this->runtime = $runtime;
    }
    public static function publicError(\Throwable $error): string
    {
        return $error instanceof \DomainException && in_array($error->getMessage(), self::ERRORS, true) ? $error->getMessage() : 'capture_failed';
    }
    public function registerKeys(array $recipients): void
    {
        try { $keySetId = Manifest::keySetId($recipients); } catch (\Throwable $ignoredError) { self::fail('invalid_keys'); }
        $this->locked(function (array &$state) use ($recipients, $keySetId): void {
            $this->idle($state); $state['revision']++;
            $state['keySetId'] = $keySetId;
            $state['keys'] = array_map(static fn (string $recipient): array => ['recipient' => $recipient, 'confirmed' => false, 'challenge' => null], $recipients);
            $this->save($state);
        });
    }
    public function issueChallenge(): void
    {
        $this->locked(function (array &$state): void {
            $this->idle($state);
            if ($state['keys'] === []) { self::fail('keys_required'); }
            $state['revision']++;
            foreach ($state['keys'] as $index => &$key) {
                $slot = $index + 1; $id = self::identifier(); $code = self::identifier(); $expiresAt = time() + 900;
                $plain = json_encode(['unitId' => $this->runtime->unitId(), 'keySetId' => $state['keySetId'], 'revision' => $state['revision'],
                    'slot' => $slot, 'challengeId' => $id, 'expiresAt' => $expiresAt, 'code' => $code], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                $this->runtime->age()->encryptBytes($plain, $this->challengeFile($id), [$key['recipient']]);
                $key['confirmed'] = false;
                $key['challenge'] = ['id' => $id, 'expiresAt' => $expiresAt, 'hash' => hash('sha256', $code), 'attempts' => 0, 'used' => false];
            }
            unset($key);
            $this->save($state);
        });
    }
    public function challenge(int $slot): string
    {
        $this->slot($slot);
        return $this->locked(function (array &$state) use ($slot): string {
            $challenge = $this->availableChallenge($state, $slot);
            $file = $this->challengeFile($challenge['id']); $this->privateFile($file, 65536);
            return file_get_contents($file);
        });
    }
    public function confirmChallenge(int $slot, string $challengeId, string $code): void
    {
        $this->slot($slot);
        $this->locked(function (array &$state) use ($slot, $challengeId, $code): void {
            $this->idle($state); $challenge = $this->availableChallenge($state, $slot);
            if (!self::validId($challengeId) || !hash_equals($challenge['id'], $challengeId) || !self::validId($code) || !hash_equals($challenge['hash'], hash('sha256', $code))) {
                $state['keys'][$slot - 1]['challenge']['attempts']++;
                $this->save($state); self::fail('challenge_invalid');
            }
            $state['keys'][$slot - 1]['confirmed'] = true;
            $state['keys'][$slot - 1]['challenge']['used'] = true;
            $state['keys'][$slot - 1]['challenge']['hash'] = null;
            $this->save($state);
        });
    }
    public function start(string $requestId): array
    {
        if (!self::validId($requestId)) { self::fail('invalid_request'); }
        return $this->locked(function (array &$state) use ($requestId): array {
            if ($state['operation'] !== null && hash_equals($state['operation']['requestId'], $requestId)) { return $this->run($state); }
            $this->idle($state);
            if (!hash_equals($state['requestId'], $requestId)) { self::fail('invalid_request'); }
            if (count($state['keys']) !== 2 || !$state['keys'][0]['confirmed'] || !$state['keys'][1]['confirmed']) { self::fail('keys_unconfirmed'); }
            $state['operation'] = ['operationId' => self::identifier(), 'requestId' => $requestId, 'revision' => $state['revision'], 'keySetId' => $state['keySetId'],
                'binding' => $this->runtime->binding(), 'status' => 'pending', 'jobId' => null, 'snapshotId' => null];
            $this->save($state);
            return $this->run($state);
        });
    }
    public function resume(string $operationId): array
    {
        if (!self::validId($operationId)) { self::fail('invalid_request'); }
        return $this->locked(function (array &$state) use ($operationId): array {
            if ($state['operation'] === null || !hash_equals($state['operation']['operationId'], $operationId)) { self::fail('operation_missing'); }
            return $this->run($state);
        });
    }
    public function view(): array
    {
        return $this->locked(function (array &$state): array {
            $keys = [];
            foreach ($state['keys'] as $index => $key) {
                $challenge = $key['challenge'];
                $keys[] = ['slot' => $index + 1, 'recipient' => $key['recipient'], 'confirmed' => $key['confirmed'],
                    'challengeId' => $challenge['id'] ?? null, 'expiresAt' => $challenge['expiresAt'] ?? null,
                    'attemptsRemaining' => $challenge === null ? 5 : max(0, 5 - $challenge['attempts']),
                    'downloadable' => $challenge !== null && !$challenge['used'] && $challenge['attempts'] < 5 && $challenge['expiresAt'] > time()];
            }
            $generations = []; $listingError = null;
            try { foreach ($this->runtime->reader()->listStatus() as $row) { $generations[] = ['snapshotId' => $row['snapshotId'], 'valid' => $row['valid']]; } }
            catch (\Throwable $ignoredError) { $listingError = 'listing_failed'; }
            return ['unitId' => $this->runtime->unitId(), 'revision' => $state['revision'], 'keySetId' => $state['keySetId'], 'keys' => $keys,
                'requestId' => $state['requestId'], 'canStart' => count($keys) === 2 && $keys[0]['confirmed'] && $keys[1]['confirmed'] && ($state['operation']['status'] ?? '') !== 'pending',
                'operation' => $this->publicOperation($state['operation']), 'generations' => $generations, 'listingError' => $listingError];
        });
    }
    private function run(array &$state): array
    {
        $operation = &$state['operation'];
        if ($operation['revision'] !== $state['revision'] || $operation['keySetId'] !== $state['keySetId'] || $operation['binding'] !== $this->runtime->binding()) { self::fail('state_invalid'); }
        $id = $operation['operationId'];
        $this->runtime->createOperation($id);
        $jobs = $this->runtime->jobsDirectory($id);
        $names = array_values(array_diff(scandir($jobs), ['.', '..']));
        if (count($names) > 1 || ($names !== [] && !self::validId($names[0]))) { self::fail('state_invalid'); }
        if ($names === []) {
            if ($operation['jobId'] !== null || $operation['status'] !== 'pending') { self::fail('state_invalid'); }
            $jobId = $this->runtime->captureFor($id)->prepare($this->runtime->source(), $this->runtime->schema(), array_column($state['keys'], 'recipient'));
            $names = [$jobId];
        }
        $job = CaptureJob::open($jobs, $names[0]);
        if ($job->binding() !== $this->runtime->binding()['writer'] || ($job->input()['unitId'] ?? null) !== $this->runtime->unitId()
            || ($job->input()['keySetId'] ?? null) !== $state['keySetId'] || ($operation['jobId'] !== null && $operation['jobId'] !== $job->id())
            || ($operation['snapshotId'] !== null && $operation['snapshotId'] !== $job->snapshotId())) { self::fail('state_invalid'); }
        $operation['jobId'] = $job->id(); $operation['snapshotId'] = $job->snapshotId(); $this->save($state);
        $this->runtime->managedFor($id)->run($job->id());
        if ($operation['status'] !== 'complete') { $operation['status'] = 'complete'; $state['requestId'] = self::identifier(); $this->save($state); }
        return $this->publicOperation($operation);
    }
    private function publicOperation(?array $operation): ?array
    {
        return $operation === null ? null : array_intersect_key($operation, array_flip(['operationId', 'status', 'jobId', 'snapshotId']));
    }
    private function availableChallenge(array $state, int $slot): array
    {
        $challenge = $state['keys'][$slot - 1]['challenge'] ?? null;
        if ($challenge === null) { self::fail('challenge_invalid'); }
        if ($challenge['used']) { self::fail('challenge_used'); }
        if ($challenge['attempts'] >= 5) { self::fail('challenge_locked'); }
        if ($challenge['expiresAt'] <= time()) { self::fail('challenge_expired'); }
        return $challenge;
    }
    private function idle(array $state): void { if (($state['operation']['status'] ?? '') === 'pending') { self::fail('operation_active'); } }
    private function slot(int $slot): void { if (!in_array($slot, [1, 2], true)) { self::fail('invalid_request'); } }
    private function challengeFile(string $id): string { return $this->runtime->privateRoot() . '/admin-challenge-' . $id . '.age'; }
    private function locked(callable $action)
    {
        $root = FileSafety::privateDirectory($this->runtime->privateRoot()); $lock = $root . '/admin.lock';
        if (@lstat($lock) === false) {
            $created = @fopen($lock, 'xb');
            if ($created !== false) { chmod($lock, 0600); fclose($created); }
        }
        $this->privateFile($lock, 0); $stream = fopen($lock, 'r+b');
        if ($stream === false) { self::fail('state_invalid'); }
        try {
            if (!flock($stream, LOCK_EX | LOCK_NB)) { self::fail('busy'); }
            $state = $this->load();
            return $action($state);
        } finally { flock($stream, LOCK_UN); fclose($stream); }
    }
    private function load(): array
    {
        $file = $this->runtime->privateRoot() . '/admin-state.json';
        if (@lstat($file) === false) {
            if (array_values(array_diff(scandir($this->runtime->operationsDirectory()), ['.', '..'])) !== []
                || glob($this->runtime->privateRoot() . '/admin-challenge-*.age') !== []) { self::fail('state_invalid'); }
            $state = ['version' => 1, 'binding' => $this->runtime->binding(), 'revision' => 0, 'keySetId' => null, 'keys' => [], 'requestId' => self::identifier(), 'operation' => null];
            $this->save($state); return $state;
        }
        try {
            $this->privateFile($file, 65536);
            $record = json_decode(file_get_contents($file), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($record) || array_keys($record) !== ['data', 'checksum'] || !is_array($record['data']) || !is_string($record['checksum'])
                || !hash_equals(hash('sha256', json_encode($record['data'], JSON_THROW_ON_ERROR)), $record['checksum'])) { self::fail('state_invalid'); }
            $state = $record['data']; $this->validate($state); return $state;
        } catch (\Throwable $ignoredError) { self::fail('state_invalid'); }
    }
    private function validate(array $state): void
    {
        if (array_keys($state) !== ['version', 'binding', 'revision', 'keySetId', 'keys', 'requestId', 'operation'] || $state['version'] !== 1
            || $state['binding'] !== $this->runtime->binding() || !is_int($state['revision']) || $state['revision'] < 0 || !self::validId($state['requestId'])
            || !is_array($state['keys']) || !array_is_list($state['keys']) || !in_array(count($state['keys']), [0, 2], true)) { self::fail('state_invalid'); }
        if ($state['keys'] === []) { if ($state['keySetId'] !== null || $state['revision'] !== 0) { self::fail('state_invalid'); } }
        elseif ($state['revision'] < 1 || Manifest::keySetId(array_column($state['keys'], 'recipient')) !== $state['keySetId']) { self::fail('state_invalid'); }
        foreach ($state['keys'] as $key) {
            if (!is_array($key) || array_keys($key) !== ['recipient', 'confirmed', 'challenge'] || !is_bool($key['confirmed'])) { self::fail('state_invalid'); }
            $c = $key['challenge'];
            if ($c === null) { if ($key['confirmed']) { self::fail('state_invalid'); } continue; }
            if (!is_array($c) || array_keys($c) !== ['id', 'expiresAt', 'hash', 'attempts', 'used'] || !self::validId($c['id']) || !is_int($c['expiresAt']) || $c['expiresAt'] < 1
                || !is_int($c['attempts']) || $c['attempts'] < 0 || $c['attempts'] > 5 || !is_bool($c['used'])
                || ($c['used'] ? $c['hash'] !== null : !self::validId($c['hash'])) || ($key['confirmed'] && !$c['used'])) { self::fail('state_invalid'); }
        }
        $op = $state['operation'];
        if ($op !== null && (!is_array($op) || array_keys($op) !== ['operationId', 'requestId', 'revision', 'keySetId', 'binding', 'status', 'jobId', 'snapshotId']
            || !self::validId($op['operationId']) || !self::validId($op['requestId']) || !is_int($op['revision']) || $op['revision'] < 1 || !self::validId($op['keySetId'])
            || !is_array($op['binding']) || !in_array($op['status'], ['pending', 'complete'], true)
            || ($op['jobId'] !== null && !self::validId($op['jobId'])) || ($op['snapshotId'] !== null && !self::validId($op['snapshotId']))
            || (($op['jobId'] === null) !== ($op['snapshotId'] === null)) || ($op['status'] === 'complete' && $op['jobId'] === null))) { self::fail('state_invalid'); }
        $names = array_values(array_diff(scandir($this->runtime->operationsDirectory()), ['.', '..']));
        foreach ($names as $name) { if (!self::validId($name)) { self::fail('state_invalid'); } FileSafety::privateDirectory($this->runtime->operationsDirectory() . '/' . $name); }
        if ($op === null && $names !== []) { self::fail('state_invalid'); }
    }
    private function save(array $state): void
    {
        $file = $this->runtime->privateRoot() . '/admin-state.json';
        if (@lstat($file) !== false) { $this->privateFile($file, 65536); }
        $temporary = $this->runtime->privateRoot() . '/.admin-' . bin2hex(random_bytes(16));
        $bytes = json_encode(['data' => $state, 'checksum' => hash('sha256', json_encode($state, JSON_THROW_ON_ERROR))], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $stream = fopen($temporary, 'xb');
        if ($stream === false) { self::fail('state_invalid'); }
        try { if (!chmod($temporary, 0600) || fwrite($stream, $bytes) !== strlen($bytes) || !fflush($stream) || !\WordPressR2Backup\FileSafety::sync($stream) || !rename($temporary, $file)) { self::fail('state_invalid'); } }
        finally { fclose($stream); if (is_file($temporary)) { unlink($temporary); } }
    }
    private function privateFile(string $path, int $limit): void
    {
        FileSafety::assertRegularFile($path); $stat = lstat($path);
        if (($stat['mode'] & 0777) !== 0600 || $stat['nlink'] !== 1 || $stat['size'] > $limit
            || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())) { self::fail('state_invalid'); }
    }
    private static function identifier(): string { return bin2hex(random_bytes(32)); }
    private static function validId($value): bool { return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1; }
    private static function fail(string $code): void { throw new \DomainException($code); }
}
