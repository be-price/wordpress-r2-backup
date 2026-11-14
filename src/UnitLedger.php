<?php
declare(strict_types=1);

namespace WordPressR2Backup;

use Closure;
use InvalidArgumentException;
use RuntimeException;

final class UnitLedger
{
    public const MAX_LEASE_SECONDS = 3600;
    public const MAX_REQUEST_SECONDS = 5;
    private const MAX_STATE_BYTES = 16777216;
    private Closure $clock;

    private string $directory;
    private string $unitId;

    private function __construct(string $directory, string $unitId, ?callable $clock)
    {
        $this->directory = $directory;
        $this->unitId = $unitId;

        $this->clock = $clock === null ? static fn (): int => time() : Closure::fromCallable($clock);
    }

    public static function initialize(string $controlDirectory, string $unitId, string $sourceId, array $cursor, callable $backendHasObjects, ?callable $clock = null): self
    {
        self::identifier($unitId); self::identifier($sourceId); $cursor = self::cursor($cursor);
        FileSafety::privateDirectory($controlDirectory);
        if (scandir($controlDirectory) !== ['.', '..']) {
            throw new RuntimeException('Initialization requires a new empty control directory.');
        }
        $lock = @fopen($controlDirectory . '/ledger.lock', 'x+b');
        if ($lock === false) {
            throw new RuntimeException('The unit control area is already initialized or interrupted.');
        }
        try {
            if (!chmod($controlDirectory . '/ledger.lock', 0600) || !fflush($lock) || !\WordPressR2Backup\FileSafety::sync($lock) || !flock($lock, LOCK_EX)) {
                throw new RuntimeException('Cannot persist the unit lock.');
            }
            if ($backendHasObjects() !== false) {
                throw new RuntimeException('Initialization requires an empty backend.');
            }
            $ledger = new self($controlDirectory, $unitId, $clock);
            $ledger->save(['version' => 1, 'unitId' => $unitId, 'sourceId' => $sourceId, 'generation' => 1,
                'head' => $cursor, 'fence' => 0, 'frozen' => false, 'activeJob' => null, 'lease' => null,
                'lastAdvance' => null, 'publications' => [], 'freeze' => null, 'lastActivation' => null]);
            return $ledger;
        } finally {
            flock($lock, LOCK_UN); fclose($lock);
        }
    }

    public static function open(string $controlDirectory, string $unitId, ?callable $clock = null): self
    {
        self::identifier($unitId); FileSafety::privateDirectory($controlDirectory);
        $ledger = new self($controlDirectory, $unitId, $clock);
        $ledger->locked(static fn (array &$state) => null);
        return $ledger;
    }

    /** $sourcePrincipal must come from the service's credential binding, never the request body. */
    public function acquire(string $sourcePrincipal, int $generation, string $jobId, string $processNonce, int $ttl, array $cursor): array
    {
        self::identifier($jobId); self::identifier($processNonce); self::ttl($ttl); $cursor = self::cursor($cursor);
        return $this->locked(function (array &$state) use ($sourcePrincipal, $generation, $jobId, $processNonce, $ttl, $cursor): array {
            $this->writer($state, $sourcePrincipal, $generation);
            if ($cursor !== $state['head']) {
                throw new RuntimeException('The index cursor differs from the unit head.');
            }
            $job = $state['activeJob'];
            if ($job !== null && $job['status'] === 'active' && $job['jobId'] !== $jobId) {
                throw new RuntimeException('Another job is still active.');
            }
            foreach ($state['publications'] as $publication) {
                if ($publication['jobId'] === $jobId && $publication['descriptor']['kind'] === 'complete' && $publication['result'] !== null) {
                    throw new RuntimeException('A completed job cannot be reacquired.');
                }
            }
            $now = $this->now(); $lease = $state['lease'];
            if ($lease !== null && $now < $lease['expiresAt']) {
                if ($lease['jobId'] !== $jobId || !hash_equals($lease['processNonceHash'], hash('sha256', $processNonce))) {
                    throw new RuntimeException('A live lease belongs to another process.');
                }
                return $this->leaseView($state);
            }
            if ($state['fence'] === PHP_INT_MAX || $now > PHP_INT_MAX - $ttl) {
                throw new RuntimeException('Lease counter or service time is exhausted.');
            }
            $state['fence']++;
            $state['activeJob'] = ['jobId' => $jobId, 'status' => 'active'];
            $state['lease'] = ['jobId' => $jobId, 'processNonceHash' => hash('sha256', $processNonce), 'fence' => $state['fence'], 'expiresAt' => $now + $ttl];
            $this->save($state);
            return $this->leaseView($state);
        });
    }

    public function renew(string $sourcePrincipal, array $session, int $ttl): array
    {
        self::ttl($ttl); $session = self::session($session);
        return $this->locked(function (array &$state) use ($sourcePrincipal, $session, $ttl): array {
            $this->authorized($state, $sourcePrincipal, $session);
            $now = $this->now();
            if ($now >= $state['lease']['expiresAt'] || $now > PHP_INT_MAX - $ttl) {
                throw new RuntimeException('The lease expired before renewal.');
            }
            $state['lease']['expiresAt'] = max($state['lease']['expiresAt'], $now + $ttl);
            $this->save($state);
            return $this->leaseView($state);
        });
    }

    public function authorize(string $sourcePrincipal, array $session): array
    {
        $session = self::session($session);
        return $this->locked(function (array &$state) use ($sourcePrincipal, $session): array {
            $this->authorized($state, $sourcePrincipal, $session);
            return $this->leaseView($state);
        });
    }

    public function advance(string $sourcePrincipal, array $session, string $eventId, array $from, array $to): array
    {
        $session = self::session($session); self::identifier($eventId); $from = self::cursor($from); $to = self::cursor($to);
        return $this->locked(function (array &$state) use ($sourcePrincipal, $session, $eventId, $from, $to): array {
            $this->authorized($state, $sourcePrincipal, $session, false);
            $event = ['eventId' => $eventId, 'jobId' => $session['jobId'], 'from' => $from, 'to' => $to];
            if ($state['lastAdvance'] === $event && $state['head'] === $to && in_array($session['cursor'], [$from, $to], true)) {
                return $to;
            }
            if ($from !== $state['head'] || $session['cursor'] !== $from || $from['indexId'] !== $to['indexId']
                || $from['sequence'] === PHP_INT_MAX || $to['sequence'] !== $from['sequence'] + 1
                || $from['commitment'] === $to['commitment'] || ($state['lastAdvance']['eventId'] ?? null) === $eventId) {
                throw new RuntimeException('The index event does not extend the current head.');
            }
            $state['head'] = $to; $state['lastAdvance'] = $event;
            $this->save($state);
            return $to;
        });
    }

    /** operation must invoke guard immediately before its create-only backend publication. */
    public function publish(string $sourcePrincipal, array $session, string $publicationId, array $descriptor, callable $operation, callable $lookup): array
    {
        $session = self::session($session); self::identifier($publicationId); $descriptor = self::descriptor($descriptor);
        return $this->locked(function (array &$state) use ($sourcePrincipal, $session, $publicationId, $descriptor, $operation, $lookup): array {
            $publication = $state['publications'][$publicationId] ?? null;
            $this->writer($state, $sourcePrincipal, $session['generation']);
            if ($publication !== null && $publication['descriptor']['kind'] === 'complete' && $publication['result'] !== null
                && $publication['generation'] === $session['generation'] && $publication['jobId'] === $session['jobId']
                && $publication['cursor'] === $session['cursor'] && $state['head'] === $session['cursor']
                && $publication['descriptor'] === $descriptor && $state['fence'] === $session['fence']
                && $state['activeJob'] === ['jobId' => $session['jobId'], 'status' => 'completed']
                && $publication['completionLease']['fence'] === $session['fence']
                && hash_equals($publication['completionLease']['processNonceHash'], hash('sha256', $session['processNonce']))
                && $this->now() < $publication['completionLease']['expiresAt']) {
                $actual = $lookup($descriptor);
                if ($actual === null || self::result($descriptor, $actual) !== $publication['result']) {
                    throw new RuntimeException('The completed publication cannot be verified in the backend.');
                }
                if ($this->now() >= $publication['completionLease']['expiresAt']) {
                    throw new RuntimeException('The completed request expired during result recovery.');
                }
                return $publication['result'];
            }
            $this->authorized($state, $sourcePrincipal, $session);
            if ($publication !== null && ($publication['jobId'] !== $session['jobId'] || $publication['generation'] !== $session['generation']
                || $publication['descriptor'] !== $descriptor)) {
                throw new RuntimeException('The publication identifier belongs to a different request.');
            }
            if ($publication === null) {
                $state['publications'][$publicationId] = ['generation' => $state['generation'], 'jobId' => $session['jobId'],
                    'cursor' => $session['cursor'], 'descriptor' => $descriptor, 'result' => null,
                    'completionLease' => $descriptor['kind'] === 'complete' ? $state['lease'] : null];
                $this->save($state);
            }
            $existing = $lookup($descriptor);
            if ($existing !== null) {
                $result = self::result($descriptor, $existing);
                $this->recordResult($state, $publicationId, $result, $state['lease']);
                return $result;
            }
            if ($state['publications'][$publicationId]['result'] !== null) {
                throw new RuntimeException('A previously confirmed backend object is missing.');
            }
            $active = true; $guardUsed = false;
            $guard = function () use (&$active, &$guardUsed, &$state, $sourcePrincipal, $session): void {
                if (!$active) {
                    throw new RuntimeException('The publication guard is no longer inside its locked operation.');
                }
                $this->authorized($state, $sourcePrincipal, $session); $guardUsed = true;
            };
            try {
                $guard(); $guardUsed = false;
                $returned = $operation($guard);
                if (!$guardUsed) {
                    throw new RuntimeException('The backend publication did not use its authorization guard.');
                }
                $result = self::result($descriptor, $returned);
                $actual = $lookup($descriptor);
                if ($actual === null || self::result($descriptor, $actual) !== $result) {
                    throw new RuntimeException('The published result cannot be verified in the backend.');
                }
                $this->recordResult($state, $publicationId, $result, $state['lease']);
                return $result;
            } finally {
                $active = false;
            }
        });
    }

    public function status(string $sourcePrincipal, ?callable $lookup = null): array
    {
        return $this->locked(function (array &$state) use ($sourcePrincipal, $lookup): array {
            $this->principal($state, $sourcePrincipal);
            if ($lookup !== null) {
                foreach ($state['publications'] as $id => $publication) {
                    if ($publication['result'] === null) {
                        $actual = $lookup($publication['descriptor']);
                        if ($actual !== null) {
                            $this->recordResult($state, $id, self::result($publication['descriptor'], $actual));
                        }
                    }
                }
            }
            $view = $state;
            unset($view['freeze'], $view['lastActivation']);
            if ($view['lease'] !== null) {
                unset($view['lease']['processNonceHash']);
            }
            foreach ($view['publications'] as &$publication) {
                if ($publication['completionLease'] !== null) { unset($publication['completionLease']['processNonceHash']); }
            }
            unset($publication);
            return $view;
        });
    }

    public function freeze(int $expectedGeneration): array
    {
        return $this->locked(function (array &$state) use ($expectedGeneration): array {
            if ($expectedGeneration !== $state['generation']) {
                throw new RuntimeException('The administrator generation is stale.');
            }
            if ($state['freeze'] !== null) { return $this->freezeView($state['freeze']); }
            if ($state['fence'] === PHP_INT_MAX) { throw new RuntimeException('The source fence is exhausted.'); }
            $lastPermission = max($this->now(), $state['lease']['expiresAt'] ?? 0);
            foreach ($state['publications'] as $publication) {
                if ($publication['generation'] === $state['generation']) {
                    $lastPermission = max($lastPermission, $publication['completionLease']['expiresAt'] ?? 0);
                }
            }
            if ($lastPermission > PHP_INT_MAX - self::MAX_REQUEST_SECONDS) { throw new RuntimeException('The service time is exhausted.'); }
            $state['frozen'] = true; $state['lease'] = null; $state['fence']++;
            $state['freeze'] = ['freezeId' => bin2hex(random_bytes(32)), 'generation' => $state['generation'],
                'readyAt' => $lastPermission + self::MAX_REQUEST_SECONDS, 'stamp' => null];
            $this->save($state);
            return $this->freezeView($state['freeze']);
        });
    }

    public function barrier(string $freezeId, callable $inventoryStamp): array
    {
        self::identifier($freezeId);
        return $this->locked(function (array &$state) use ($freezeId, $inventoryStamp): array {
            $this->frozenBarrier($state, $freezeId);
            $stamp = $inventoryStamp(); self::identifier($stamp);
            if ($state['freeze']['stamp'] !== null && $state['freeze']['stamp'] !== $stamp) {
                throw new RuntimeException('The frozen backend inventory changed.');
            }
            $state['freeze']['stamp'] = $stamp; $this->save($state);
            return $state['freeze'];
        });
    }

    public function activate(string $freezeId, string $stamp, string $newSourceId, array $cursor, string $credentialHash, int $expiresAt, callable $inventoryStamp): array
    {
        self::identifier($freezeId); self::identifier($stamp); self::identifier($newSourceId); self::identifier($credentialHash);
        self::integer($expiresAt, 1); $cursor = self::cursor($cursor);
        return $this->locked(function (array &$state) use ($freezeId, $stamp, $newSourceId, $cursor, $credentialHash, $expiresAt, $inventoryStamp): array {
            $request = ['sourceId' => $newSourceId, 'cursor' => $cursor, 'freezeId' => $freezeId, 'stamp' => $stamp,
                'credentialHash' => $credentialHash, 'expiresAt' => $expiresAt];
            $previous = $state['lastActivation'];
            if ($previous !== null) {
                $replay = $previous; unset($replay['generation']);
                if ($request === $replay && !$state['frozen']) { return $this->activationView($previous); }
            }
            $this->frozenBarrier($state, $freezeId);
            if ($state['freeze']['stamp'] !== $stamp || $inventoryStamp() !== $stamp || $newSourceId === $state['sourceId']
                || $cursor['indexId'] === $state['head']['indexId'] || $expiresAt <= $this->now()
                || $state['generation'] === PHP_INT_MAX) {
                throw new RuntimeException('The administrator activation differs from the verified frozen inventory.');
            }
            $state['generation']++; $state['sourceId'] = $newSourceId; $state['head'] = $cursor;
            $state['activeJob'] = null; $state['lastAdvance'] = null; $state['lease'] = null; $state['frozen'] = false; $state['freeze'] = null;
            $state['lastActivation'] = $request + ['generation' => $state['generation']];
            $this->save($state);
            return $this->activationView($state['lastActivation']);
        });
    }

    public function activatedPrincipal(string $credentialHash): ?array
    {
        self::identifier($credentialHash);
        return $this->locked(function (array &$state) use ($credentialHash): ?array {
            $activation = $state['lastActivation'];
            if ($activation === null || !hash_equals($activation['credentialHash'], $credentialHash) || $activation['expiresAt'] <= $this->now()) { return null; }
            return ['role' => 'writer', 'unitId' => $this->unitId, 'sourceId' => $activation['sourceId'],
                'generation' => $activation['generation'], 'expiresAt' => $activation['expiresAt']];
        });
    }

    public function administratorStatus(): array
    {
        return $this->locked(fn (array &$state): array => ['unitId' => $state['unitId'], 'sourceId' => $state['sourceId'],
            'generation' => $state['generation'], 'frozen' => $state['frozen'], 'freeze' => $state['freeze'],
            'activation' => $state['lastActivation'] === null ? null : $this->activationView($state['lastActivation'])]);
    }

    private function frozenBarrier(array $state, string $freezeId): void
    {
        if (!$state['frozen'] || $state['freeze'] === null || $state['freeze']['freezeId'] !== $freezeId
            || $this->now() < $state['freeze']['readyAt']) {
            throw new RuntimeException('The frozen source has not reached its service-side barrier.');
        }
    }

    private function freezeView(array $freeze): array { unset($freeze['stamp']); return $freeze; }
    private function activationView(array $activation): array { unset($activation['credentialHash']); return $activation; }

    private function recordResult(array &$state, string $publicationId, array $result, ?array $completionLease = null): void
    {
        $publication = $state['publications'][$publicationId];
        $state['publications'][$publicationId]['result'] = $result;
        if ($publication['descriptor']['kind'] === 'complete' && $completionLease !== null) {
            $state['publications'][$publicationId]['completionLease'] = $completionLease;
        }
        if ($publication['descriptor']['kind'] === 'complete' && $publication['generation'] === $state['generation'] && $state['activeJob'] !== null && $state['activeJob']['jobId'] === $publication['jobId']) {
            $state['activeJob']['status'] = 'completed'; $state['lease'] = null;
        }
        $this->save($state);
    }

    private function principal(array $state, string $sourcePrincipal): void
    {
        self::identifier($sourcePrincipal);
        if (!hash_equals($state['sourceId'], $sourcePrincipal)) {
            throw new RuntimeException('The credential is not bound to the current source.');
        }
    }

    private function writer(array $state, string $sourcePrincipal, int $generation): void
    {
        $this->principal($state, $sourcePrincipal);
        if ($state['frozen'] || $generation !== $state['generation']) {
            throw new RuntimeException('The unit is frozen or the source generation is stale.');
        }
    }

    private function authorized(array $state, string $sourcePrincipal, array $session, bool $checkCursor = true): void
    {
        $this->writer($state, $sourcePrincipal, $session['generation']); $lease = $state['lease'];
        if ($lease === null || $state['activeJob'] !== ['jobId' => $session['jobId'], 'status' => 'active']
            || $lease['jobId'] !== $session['jobId'] || $lease['fence'] !== $session['fence']
            || !hash_equals($lease['processNonceHash'], hash('sha256', $session['processNonce']))
            || $this->now() >= $lease['expiresAt'] || ($checkCursor && $session['cursor'] !== $state['head'])) {
            throw new RuntimeException('The lease, job, process, or index cursor is no longer current.');
        }
    }

    private function leaseView(array $state): array
    {
        return ['sourceId' => $state['sourceId'], 'generation' => $state['generation'], 'jobId' => $state['lease']['jobId'],
            'fence' => $state['lease']['fence'], 'expiresAt' => $state['lease']['expiresAt'], 'cursor' => $state['head']];
    }

    private function now(): int
    {
        $now = ($this->clock)();
        if (!is_int($now) || $now < 0) {
            throw new RuntimeException('The service clock did not return a valid timestamp.');
        }
        return $now;
    }

    private function locked(callable $operation)
    {
        FileSafety::privateDirectory($this->directory); $path = $this->directory . '/ledger.lock'; self::privateFile($path);
        $lock = @fopen($path, 'r+b');
        if ($lock === false) {
            throw new RuntimeException('Cannot open the persistent unit lock.');
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Cannot lock the unit.');
            }
            $opened = fstat($lock); $named = lstat($path);
            if ($opened === false || $named === false || $opened['ino'] !== $named['ino'] || $opened['dev'] !== $named['dev']) {
                throw new RuntimeException('The persistent unit lock changed.');
            }
            $state = $this->load();
            return $operation($state);
        } finally {
            flock($lock, LOCK_UN); fclose($lock);
        }
    }

    private function load(): array
    {
        $path = $this->directory . '/ledger.json'; self::privateFile($path);
        $size = filesize($path);
        if ($size === false || $size < 1 || $size > self::MAX_STATE_BYTES) {
            throw new RuntimeException('The unit ledger has an invalid size.');
        }
        try {
            $bytes = file_get_contents($path);
            $record = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
            self::shape($record, ['state', 'sha256']); self::identifier($record['sha256']);
            if (json_encode($record, JSON_THROW_ON_ERROR) !== $bytes
                || !hash_equals($record['sha256'], hash('sha256', json_encode($record['state'], JSON_THROW_ON_ERROR)))) {
                throw new InvalidArgumentException('The unit ledger checksum or encoding is invalid.');
            }
            return $this->validateState($record['state']);
        } catch (\JsonException|InvalidArgumentException|\TypeError $error) {
            throw new RuntimeException('The unit ledger is corrupt; administrator recovery is required.', 0, $error);
        }
    }

    private function save(array $state): void
    {
        $state = $this->validateState($state);
        $data = json_encode(['state' => $state, 'sha256' => hash('sha256', json_encode($state, JSON_THROW_ON_ERROR))], JSON_THROW_ON_ERROR);
        if (strlen($data) > self::MAX_STATE_BYTES) {
            throw new RuntimeException('The unit ledger exceeds its size limit.');
        }
        $temporary = $this->directory . '/.ledger-' . bin2hex(random_bytes(16));
        $output = @fopen($temporary, 'x+b');
        if ($output === false) {
            throw new RuntimeException('Cannot create the unit ledger staging file.');
        }
        try {
            if (!chmod($temporary, 0600) || fwrite($output, $data) !== strlen($data) || !fflush($output) || !\WordPressR2Backup\FileSafety::sync($output)
                || !@rename($temporary, $this->directory . '/ledger.json')) {
                throw new RuntimeException('Cannot persist the unit ledger.');
            }
            $directory = @fopen($this->directory, 'r');
            if ($directory === false) {
                throw new RuntimeException('Cannot open the control directory for persistence.');
            }
            try {
                if (\WordPressR2Backup\FileSafety::syncDirectory($directory) === false) {
                    throw new RuntimeException('Cannot persist the unit ledger directory.');
                }
            } finally {
                fclose($directory);
            }
        } finally {
            fclose($output);
            if (is_file($temporary)) { unlink($temporary); }
        }
    }

    private function validateState($state): array
    {
        self::shape($state, ['version', 'unitId', 'sourceId', 'generation', 'head', 'fence', 'frozen', 'activeJob', 'lease', 'lastAdvance', 'publications', 'freeze', 'lastActivation']);
        if ($state['version'] !== 1 || $state['unitId'] !== $this->unitId || !is_bool($state['frozen'])) {
            throw new InvalidArgumentException('Invalid unit ledger identity or version.');
        }
        self::identifier($state['sourceId']); self::integer($state['generation'], 1); self::integer($state['fence'], 0);
        $state['head'] = self::cursor($state['head']);
        if ($state['freeze'] !== null) {
            $freeze = $state['freeze']; self::shape($freeze, ['freezeId', 'generation', 'readyAt', 'stamp']);
            self::identifier($freeze['freezeId']); self::integer($freeze['generation'], 1); self::integer($freeze['readyAt'], 1);
            if ($freeze['stamp'] !== null) { self::identifier($freeze['stamp']); }
            if (!$state['frozen'] || $freeze['generation'] !== $state['generation']) { throw new InvalidArgumentException('Invalid freeze generation.'); }
        } elseif ($state['frozen']) { throw new InvalidArgumentException('Frozen state requires a persistent ticket.'); }
        if ($state['lastActivation'] !== null) {
            $activation = $state['lastActivation'];
            self::shape($activation, ['sourceId', 'generation', 'cursor', 'freezeId', 'stamp', 'credentialHash', 'expiresAt']);
            foreach (['sourceId', 'freezeId', 'stamp', 'credentialHash'] as $field) { self::identifier($activation[$field]); }
            self::integer($activation['generation'], 2); self::integer($activation['expiresAt'], 1); self::cursor($activation['cursor']);
            if ($activation['generation'] !== $state['generation'] || $activation['sourceId'] !== $state['sourceId']
                || $activation['cursor']['indexId'] !== $state['head']['indexId'] || $activation['cursor']['sequence'] > $state['head']['sequence']) {
                throw new InvalidArgumentException('Activation is inconsistent with the current source.');
            }
        }

        if ($state['activeJob'] !== null) {
            self::shape($state['activeJob'], ['jobId', 'status']); self::identifier($state['activeJob']['jobId']);
            if (!in_array($state['activeJob']['status'], ['active', 'completed'], true)) {
                throw new InvalidArgumentException('Invalid job status.');
            }
        }
        if ($state['lease'] !== null) {
            $lease = $state['lease']; self::shape($lease, ['jobId', 'processNonceHash', 'fence', 'expiresAt']);
            self::identifier($lease['jobId']); self::identifier($lease['processNonceHash']); self::integer($lease['fence'], 1); self::integer($lease['expiresAt'], 1);
            if ($state['frozen'] || $state['activeJob'] !== ['jobId' => $lease['jobId'], 'status' => 'active'] || $lease['fence'] !== $state['fence']) {
                throw new InvalidArgumentException('The lease is inconsistent with the unit state.');
            }
        }
        if ($state['lastAdvance'] !== null) {
            $event = $state['lastAdvance']; self::shape($event, ['eventId', 'jobId', 'from', 'to']);
            self::identifier($event['eventId']); self::identifier($event['jobId']); $event['from'] = self::cursor($event['from']); $event['to'] = self::cursor($event['to']);
            if ($event['to'] !== $state['head'] || $event['from']['indexId'] !== $event['to']['indexId']
                || $event['from']['sequence'] === PHP_INT_MAX || $event['to']['sequence'] !== $event['from']['sequence'] + 1
                || $event['from']['commitment'] === $event['to']['commitment']) {
                throw new InvalidArgumentException('The last index event is inconsistent with the head.');
            }
            $state['lastAdvance'] = $event;
        }
        if (!is_array($state['publications'])) {
            throw new InvalidArgumentException('Invalid publication map.');
        }
        foreach ($state['publications'] as $id => &$publication) {
            self::identifier($id); self::shape($publication, ['generation', 'jobId', 'cursor', 'descriptor', 'result', 'completionLease']);
            self::integer($publication['generation'], 1); self::identifier($publication['jobId']);
            $publication['cursor'] = self::cursor($publication['cursor']); $publication['descriptor'] = self::descriptor($publication['descriptor']);
            if ($publication['generation'] > $state['generation'] || ($publication['generation'] === $state['generation']
                && ($publication['cursor']['indexId'] !== $state['head']['indexId'] || $publication['cursor']['sequence'] > $state['head']['sequence']))) {
                throw new InvalidArgumentException('The publication cursor is ahead of the unit head.');
            }
            if ($publication['descriptor']['kind'] === 'complete') {
                $lease = $publication['completionLease']; self::shape($lease, ['jobId', 'processNonceHash', 'fence', 'expiresAt']);
                self::identifier($lease['processNonceHash']); self::integer($lease['fence'], 1); self::integer($lease['expiresAt'], 1);
                if ($lease['jobId'] !== $publication['jobId'] || $lease['fence'] > $state['fence']) {
                    throw new InvalidArgumentException('The completion lease does not belong to the publication.');
                }
            } elseif ($publication['completionLease'] !== null) {
                throw new InvalidArgumentException('Only completion publications retain a completion lease.');
            }
            if ($publication['result'] !== null) { $publication['result'] = self::result($publication['descriptor'], $publication['result']); }
        }
        unset($publication);
        return $state;
    }

    private static function session(array $session): array
    {
        self::shape($session, ['generation', 'jobId', 'processNonce', 'fence', 'cursor']);
        self::integer($session['generation'], 1); self::integer($session['fence'], 1); self::identifier($session['jobId']); self::identifier($session['processNonce']);
        return ['generation' => $session['generation'], 'jobId' => $session['jobId'], 'processNonce' => $session['processNonce'], 'fence' => $session['fence'], 'cursor' => self::cursor($session['cursor'])];
    }

    private static function descriptor(array $descriptor): array
    {
        if (($descriptor['kind'] ?? null) === 'object') {
            self::shape($descriptor, ['kind', 'receipt']); $receipt = self::receipt($descriptor['receipt']);
            if (str_starts_with($receipt['key'], 'commits/')) { throw new InvalidArgumentException('Completion requires a complete publication descriptor.'); }
            return ['kind' => 'object', 'receipt' => $receipt];
        }
        self::shape($descriptor, ['kind', 'snapshotId', 'manifest']);
        if ($descriptor['kind'] !== 'complete') { throw new InvalidArgumentException('Invalid publication kind.'); }
        self::identifier($descriptor['snapshotId']); $manifest = self::receipt($descriptor['manifest']);
        if ($manifest['key'] !== 'manifests/' . $descriptor['snapshotId'] . '.age') { throw new InvalidArgumentException('The completion manifest belongs to another snapshot.'); }
        return ['kind' => 'complete', 'snapshotId' => $descriptor['snapshotId'], 'manifest' => $manifest];
    }

    private static function result(array $descriptor, $actual): array
    {
        if ($descriptor['kind'] === 'object') {
            $actual = self::receipt($actual); $expected = $descriptor['receipt'];
        } else {
            self::shape($actual, ['snapshotId', 'manifest']); self::identifier($actual['snapshotId']);
            $actual = ['snapshotId' => $actual['snapshotId'], 'manifest' => self::receipt($actual['manifest'])];
            $expected = ['snapshotId' => $descriptor['snapshotId'], 'manifest' => $descriptor['manifest']];
        }
        if ($actual !== $expected) { throw new RuntimeException('The backend result differs from the publication intent.'); }
        return $actual;
    }

    private static function receipt($receipt): array
    {
        self::shape($receipt, ['key', 'bytes', 'sha256']); Manifest::validateReceipt($receipt);
        return ['key' => $receipt['key'], 'bytes' => $receipt['bytes'], 'sha256' => $receipt['sha256']];
    }

    private static function cursor(array $cursor): array
    {
        self::shape($cursor, ['indexId', 'sequence', 'commitment']); self::identifier($cursor['indexId']); self::integer($cursor['sequence'], 0); self::identifier($cursor['commitment']);
        return ['indexId' => $cursor['indexId'], 'sequence' => $cursor['sequence'], 'commitment' => $cursor['commitment']];
    }

    private static function shape($value, array $keys): void
    {
        if (!is_array($value) || count($value) !== count($keys) || array_diff($keys, array_keys($value)) !== []) {
            throw new InvalidArgumentException('Unexpected fields or missing fields.');
        }
    }

    private static function integer($value, int $minimum): void
    {
        if (!is_int($value) || $value < $minimum) { throw new InvalidArgumentException('Invalid integer.'); }
    }

    private static function identifier($value): void
    {
        if (!is_string($value) || !preg_match('/^[a-f0-9]{64}$/D', $value)) { throw new InvalidArgumentException('Expected a lowercase 256-bit identifier.'); }
    }

    private static function ttl(int $ttl): void
    {
        if ($ttl < 1 || $ttl > self::MAX_LEASE_SECONDS) { throw new InvalidArgumentException('Lease duration exceeds the service limit.'); }
    }

    private static function privateFile(string $path): void
    {
        FileSafety::assertRegularFile($path); $stat = lstat($path);
        if ($stat === false || ($stat['mode'] & 0777) !== 0600 || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())) {
            throw new RuntimeException('Expected an owner-only control file.');
        }
    }
}
