<?php
declare(strict_types=1);

use WordPressR2Backup\FileSafety;
use WordPressR2Backup\LocalRepository;
use WordPressR2Backup\Manifest;
use WordPressR2Backup\UnitLedger;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
umask(0077);

final class WrbControlRejection extends RuntimeException {}

function wrbJson(array $value, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($value, JSON_THROW_ON_ERROR);
    exit;
}

function wrbReject(int $status, string $code): void { wrbJson(['error' => $code], $status); }

function wrbPrivateJson(string $path): array
{
    FileSafety::assertRegularFile($path);
    $stat = lstat($path);
    if (($stat['mode'] & 0777) !== 0600 || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())) { throw new RuntimeException('Invalid private state.'); }
    $data = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($data)) { throw new RuntimeException('Invalid private state.'); }
    return $data;
}

function wrbState(string $path, array $data): void
{
    FileSafety::assertRegularFile($path);
    $temporary = $path . '.new-' . bin2hex(random_bytes(12));
    $stream = fopen($temporary, 'xb');
    try {
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        if (fwrite($stream, $json) !== strlen($json) || !fflush($stream) || !\WordPressR2Backup\FileSafety::sync($stream)) { throw new RuntimeException('Cannot persist private state.'); }
        if (!rename($temporary, $path)) { throw new RuntimeException('Cannot publish private state.'); }
    } finally {
        fclose($stream);
        if (@lstat($temporary) !== false) { unlink($temporary); }
    }
}

function wrbBody(): array
{
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 8192) { wrbReject(413, 'body_too_large'); }
    $input = fopen('php://input', 'rb');
    $body = stream_get_contents($input, 8193);
    fclose($input);
    if (!is_string($body) || strlen($body) > 8192) { wrbReject(413, 'body_too_large'); }
    $data = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($data) || array_is_list($data)) { wrbReject(400, 'invalid_body'); }
    return $data;
}

function wrbFields(array $data, array $expected): void
{
    $fields = array_keys($data); sort($fields); sort($expected);
    if ($fields !== $expected) { wrbReject(400, 'invalid_fields'); }
}

function wrbInventory(array $config): string
{
    $base = $config['repository'] . '/' . $config['unitId'];
    FileSafety::privateDirectory($base);
    $receipts = []; $publishedInodes = []; $linkedStages = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $entry) {
        $file = $entry->getPathname(); FileSafety::assertNoLinks($file);
        $key = substr($file, strlen($base) + 1);
        // A terminated writer can leave unpublished ciphertext; the freeze barrier prevents later publication.
        if (preg_match('~^(?:files/[a-f0-9]{64}|databases|manifests|commits)/\.stage-[a-f0-9]{32}$~D', $key)) {
            FileSafety::assertRegularFile($file);
            $stat = lstat($file);
            if (($stat['mode'] & 0777) !== 0600 || !in_array($stat['nlink'], [1, 2], true)
                || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())) {
                throw new RuntimeException('Invalid private staging file.');
            }
            if ($stat['nlink'] === 2) { $linkedStages[] = dirname($key) . ':' . $stat['dev'] . ':' . $stat['ino']; }
            continue;
        }
        if ($entry->isDir()) { continue; }
        FileSafety::assertRegularFile($file);
        if (!preg_match('~^(?:files/[a-f0-9]{64}/[a-f0-9]{64}\.age|(?:databases|manifests)/[a-f0-9]{64}\.age|commits/[a-f0-9]{64}\.json)$~D', $key)
            || $entry->getSize() < 1 || $entry->getSize() > 67108864) { throw new RuntimeException('Invalid backend inventory entry.'); }
        $digest = hash_file('sha256', $file);
        if ($digest === false) { throw new RuntimeException('Cannot hash backend inventory.'); }
        $stat = lstat($file); $publishedInodes[dirname($key) . ':' . $stat['dev'] . ':' . $stat['ino']] = true;
        $receipts[$key] = ['key' => $key, 'bytes' => $entry->getSize(), 'sha256' => $digest];
    }
    foreach ($linkedStages as $inode) {
        if (!isset($publishedInodes[$inode])) { throw new RuntimeException('Staging hardlink has no published backend object.'); }
    }
    ksort($receipts, SORT_STRING);
    return hash('sha256', json_encode(array_values($receipts), JSON_THROW_ON_ERROR));
}

function wrbReceipt(array $data): void
{
    Manifest::validateReceipt($data);
    if ($data['bytes'] > 67108864) { wrbReject(413, 'object_too_large'); }
}

function wrbFault(string $workspace, string $name): bool
{
    $control = wrbPrivateJson($workspace . '/control.json');
    if (($control['fault'] ?? null) !== $name) { return false; }
    wrbState($workspace . '/control.json', []);
    return true;
}

function wrbPause(string $workspace, string $name): void
{
    if (!wrbFault($workspace, $name)) { return; }
    wrbState($workspace . '/control.json', ['paused' => true]);
    $deadline = microtime(true) + 4;
    do {
        $control = wrbPrivateJson($workspace . '/control.json');
        if (($control['release'] ?? false) === true) { wrbState($workspace . '/control.json', []); return; }
        usleep(10000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Synthetic publication pause expired.');
}

function wrbSession(): array
{
    $header = $_SERVER['HTTP_X_WRB_SESSION'] ?? '';
    if (!is_string($header) || strlen($header) > 2048) { wrbReject(403, 'control_rejected'); }
    try { $session = json_decode($header, true, 8, JSON_THROW_ON_ERROR); }
    catch (Throwable $ignoredError) { wrbReject(403, 'control_rejected'); }
    if (!is_array($session) || array_is_list($session)) { wrbReject(403, 'control_rejected'); }
    return $session;
}

function wrbControlled(callable $operation)
{
    try { return $operation(); }
    catch (Throwable $error) { throw new WrbControlRejection('Capture control rejected.', 0, $error); }
}

function wrbLedger(array $config, string $workspace): UnitLedger
{
    return wrbControlled(fn () => UnitLedger::open($workspace . '/unit-control', $config['unitId']));
}

function wrbSource(?array $connection): string
{
    if (($connection['role'] ?? null) !== 'writer' || !is_string($connection['sourceId'] ?? null)
        || !preg_match('/^[a-f0-9]{64}$/D', $connection['sourceId']) || !is_int($connection['generation'] ?? null)) {
        wrbReject(403, 'control_rejected');
    }
    return $connection['sourceId'];
}

function wrbExpectedSource(array $principal): void
{
    if (($_SERVER['HTTP_X_WRB_SOURCE_ID'] ?? null) !== $principal['sourceId']
        || ($_SERVER['HTTP_X_WRB_SOURCE_GENERATION'] ?? null) !== (string) $principal['generation']) {
        wrbReject(403, 'control_rejected');
    }
}

function wrbLookup(LocalRepository $reader, array $config, array $descriptor): ?array
{
    $key = $descriptor['kind'] === 'object' ? $descriptor['receipt']['key'] : 'commits/' . $descriptor['snapshotId'] . '.json';
    $path = $config['repository'] . '/' . $config['unitId'] . '/' . $key;
    FileSafety::assertNoLinks($path);
    if (@lstat($path) === false) { return null; }
    if ($descriptor['kind'] === 'object') {
        $reader->verify($descriptor['receipt']);
        return $descriptor['receipt'];
    }
    return $reader->completed($descriptor['snapshotId']);
}

function wrbGrant(array $config, string $workspace, array $connection, array $receipt, ?UnitLedger $ledger, ?array $session): array
{
    $lease = $ledger === null ? null : wrbControlled(fn () => $ledger->authorize(wrbSource($connection), $session));
    $lockPath = $workspace . '/grants.lock';
    FileSafety::assertRegularFile($lockPath);
    $lock = fopen($lockPath, 'r+b');
    if ($lock === false || !flock($lock, LOCK_EX)) { throw new RuntimeException('Cannot lock upload grants.'); }
    try {
        $grants = wrbPrivateJson($workspace . '/grants.json');
        foreach ($grants as $existing) {
            if ($existing['expiresAt'] > time() && $existing['receipt']['key'] === $receipt['key'] && $existing['receipt'] != $receipt) {
                throw new RuntimeException('Conflicting upload grant.');
            }
        }
        $reader = LocalRepository::forRecovery($config['repository'], $config['unitId']);
        $target = $config['repository'] . '/' . $config['unitId'] . '/' . $receipt['key'];
        FileSafety::assertNoLinks($target);
        if (@lstat($target) !== false) { $reader->verify($receipt); }
        $token = bin2hex(random_bytes(32));
        $expiresAt = min(time() + 60, $connection['expiresAt'], $lease['expiresAt'] ?? PHP_INT_MAX);
        $grant = ['unitId' => $config['unitId'], 'method' => 'PUT', 'createOnly' => true, 'expiresAt' => $expiresAt, 'receipt' => $receipt];
        if ($session !== null) {
            $grant += ['sourceId' => $connection['sourceId'], 'generation' => $connection['generation'], 'fence' => $session['fence'],
                'jobId' => $session['jobId'], 'cursor' => $session['cursor'], 'nonceHash' => hash('sha256', $session['processNonce'])];
        }
        $grants[hash('sha256', $token)] = $grant;
        wrbState($workspace . '/grants.json', $grants);
        return ['token' => $token, 'expiresAt' => $expiresAt];
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

try {
    $configFile = getenv('WRB_SYNTHETIC_SERVER_CONFIG');
    if (!is_string($configFile) || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1'], true)) { wrbReject(403, 'forbidden'); }
    $config = wrbPrivateJson($configFile);
    $workspace = dirname($configFile);
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    $path = $_SERVER['REQUEST_URI'] ?? '';
    if ($method === 'GET' && $path === '/health') { wrbJson(['serverId' => $config['serverId']]); }
    if (($_SERVER['HTTP_X_WRB_UNIT_ID'] ?? '') !== $config['unitId']) { wrbReject(403, 'wrong_unit'); }
    if (!preg_match('/^Bearer ([a-f0-9]{64})$/D', $_SERVER['HTTP_AUTHORIZATION'] ?? '', $auth)) { wrbReject(403, 'invalid_token'); }
    $tokenHash = hash('sha256', $auth[1]);
    $managed = ($config['mode'] ?? 'legacyB1') === 'managedB2';
    $connection = $config['tokens'][$tokenHash] ?? null;
    $grantsFile = $workspace . '/grants.json';
    $grants = wrbPrivateJson($grantsFile);
    $grant = $grants[$tokenHash] ?? null;
    if ($managed && $connection === null && $grant === null) { $connection = wrbLedger($config, $workspace)->activatedPrincipal($tokenHash); }
    if ($connection !== null && ($connection['expiresAt'] <= time() || $connection['unitId'] !== $config['unitId'])) { wrbReject(403, 'expired_token'); }
    $writer = LocalRepository::forWriting($config['repository'], $config['unitId']);
    $reader = LocalRepository::forRecovery($config['repository'], $config['unitId']);
    if (str_starts_with($path, '/admin/')) {
        if (!$managed || ($connection['role'] ?? null) !== 'admin') { wrbReject(403, 'control_rejected'); }
        $ledger = wrbLedger($config, $workspace);
        if ($method === 'GET' && $path === '/admin/status') { wrbJson($ledger->administratorStatus()); }
        if ($method !== 'POST') { wrbReject(403, 'control_rejected'); }
        $body = wrbBody();
        if ($path === '/admin/freeze') {
            wrbFields($body, ['expectedGeneration']);
            wrbJson(wrbControlled(fn () => $ledger->freeze($body['expectedGeneration'])));
        }
        if ($path === '/admin/barrier') {
            wrbFields($body, ['freezeId']);
            wrbJson(wrbControlled(fn () => $ledger->barrier($body['freezeId'], fn () => wrbInventory($config))));
        }
        if ($path === '/admin/activate') {
            wrbFields($body, ['freezeId', 'stamp', 'sourceId', 'cursor', 'credentialHash', 'expiresAt']);
            if (!is_int($body['expiresAt']) || $body['expiresAt'] > $connection['expiresAt']
                || !is_string($body['credentialHash']) || isset($config['tokens'][$body['credentialHash']]) || isset($grants[$body['credentialHash']])) {
                wrbReject(403, 'control_rejected');
            }
            $result = wrbControlled(fn () => $ledger->activate($body['freezeId'], $body['stamp'], $body['sourceId'], $body['cursor'],
                $body['credentialHash'], $body['expiresAt'], fn () => wrbInventory($config)));
            if (wrbFault($workspace, 'drop-activate-response-once')) { http_response_code(503); exit; }
            wrbJson($result);
        }
        wrbReject(404, 'unknown_admin_route');
    }
    if (str_starts_with($path, '/control/')) {
        if (!$managed) { wrbReject(403, 'control_rejected'); }
        $source = wrbSource($connection);
        wrbExpectedSource($connection);
        $ledger = wrbLedger($config, $workspace);
        if ($method === 'GET' && $path === '/control/status') {
            wrbJson(wrbControlled(fn () => $ledger->status($source, fn ($descriptor) => wrbLookup($reader, $config, $descriptor))));
        }
        if ($method !== 'POST') { wrbReject(403, 'control_rejected'); }
        $body = wrbBody();
        $result = wrbControlled(function () use ($path, $body, $connection, $source, $ledger): array {
            switch ($path) {
                case '/control/acquire': return $ledger->acquire($source, $connection['generation'], $body['jobId'], $body['processNonce'], $body['ttl'], $body['cursor']);
                case '/control/renew': return $ledger->renew($source, wrbSession(), $body['ttl']);
                case '/control/advance': return $ledger->advance($source, wrbSession(), $body['eventId'], $body['from'], $body['to']);
                default: throw new RuntimeException('Invalid control route.');
            }
        });
        if ($path === '/control/advance' && wrbFault($workspace, 'drop-advance-response-once')) { http_response_code(503); exit; }
        wrbJson($result);
    }
    if ($method === 'PUT' && str_starts_with($path, '/objects/')) {
        if (!is_array($grant) || $grant['expiresAt'] <= time() || $grant['unitId'] !== $config['unitId'] || $grant['method'] !== 'PUT' || $grant['createOnly'] !== true) { wrbReject(403, $managed ? 'control_rejected' : 'invalid_grant'); }
        $receipt = $grant['receipt'];
        $ledger = null;
        $session = null;
        if ($managed) {
            $session = wrbSession();
            $source = wrbSource(['role' => 'writer', 'sourceId' => $grant['sourceId'] ?? null, 'generation' => $grant['generation'] ?? null]);
            wrbExpectedSource($grant);
            if (($grant['generation'] ?? null) !== ($session['generation'] ?? null) || ($grant['fence'] ?? null) !== ($session['fence'] ?? null)
                || ($grant['jobId'] ?? null) !== ($session['jobId'] ?? null) || ($grant['cursor'] ?? null) != ($session['cursor'] ?? null)
                || !is_string($session['processNonce'] ?? null) || !hash_equals($grant['nonceHash'] ?? '', hash('sha256', $session['processNonce']))) { wrbReject(403, 'control_rejected'); }
            $ledger = wrbLedger($config, $workspace);
            wrbControlled(fn () => $ledger->authorize($source, $session));
        }
        if ($path !== '/objects/' . $receipt['key']) { wrbReject(403, 'wrong_object'); }
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') !== '*') { wrbReject(428, 'create_only_required'); }
        if (!isset($_SERVER['CONTENT_LENGTH']) || !ctype_digit($_SERVER['CONTENT_LENGTH']) || (int) $_SERVER['CONTENT_LENGTH'] > 67108864) { wrbReject(413, 'object_too_large'); }
        if ((int) $_SERVER['CONTENT_LENGTH'] !== $receipt['bytes']) { wrbReject(422, 'content_mismatch'); }
        $target = $config['repository'] . '/' . $config['unitId'] . '/' . $receipt['key'];
        FileSafety::assertNoLinks($target);
        if (@lstat($target) !== false) { wrbReject(412, 'object_exists'); }
        $temporary = $workspace . '/.upload-' . bin2hex(random_bytes(16));
        $output = fopen($temporary, 'x+b');
        $input = fopen('php://input', 'rb');
        try {
            $bytes = stream_copy_to_stream($input, $output, 67108865);
            if ($bytes === false || $bytes > 67108864) { throw new RuntimeException('Object exceeds input limit.'); }
            fflush($output);
            if ($bytes !== $receipt['bytes'] || !hash_equals($receipt['sha256'], hash_file('sha256', $temporary))) { throw new RuntimeException('Content does not match its grant.'); }
            wrbPause($workspace, 'pause-before-put');
            $stored = $ledger === null ? $writer->putNew($receipt['key'], $temporary)
                : wrbControlled(fn () => $ledger->publish($source, $session, hash('sha256', 'object:' . $receipt['key']), ['kind' => 'object', 'receipt' => $receipt],
                    fn ($guard) => LocalRepository::forWriting($config['repository'], $config['unitId'], $guard)->putNew($receipt['key'], $temporary),
                    fn ($descriptor) => wrbLookup($reader, $config, $descriptor)));
        } finally {
            fclose($input);
            fclose($output);
            unlink($temporary);
        }
        if (wrbFault($workspace, 'drop-put-response-once')) { http_response_code(503); header('Content-Length: 0'); exit; }
        wrbJson($stored);
    }
    if (!is_array($connection)) { wrbReject(403, 'invalid_connection'); }
    $role = $connection['role'];
    if ($managed && $role === 'writer') { wrbSource($connection); wrbExpectedSource($connection); }
    if ($method === 'POST' && $path === '/upload-grants') {
        if ($role !== 'writer') { wrbReject(403, 'wrong_role'); }
        $receipt = wrbBody();
        wrbReceipt($receipt);
        $ledger = $managed ? wrbLedger($config, $workspace) : null;
        try { $result = wrbGrant($config, $workspace, $connection, $receipt, $ledger, $managed ? wrbSession() : null); }
        catch (WrbControlRejection $error) { throw $error; }
        catch (Throwable $ignoredError) { wrbReject(409, 'object_conflict'); }
        wrbJson($result);
    }
    if ($method === 'POST' && $path === '/verify') {
        $receipt = wrbBody();
        wrbReceipt($receipt);
        $reader->verify($receipt);
        wrbJson(['verified' => true]);
    }
    if ($method === 'POST' && preg_match('~^/complete/([a-f0-9]{64})$~D', $path, $parts)) {
        if ($role !== 'writer') { wrbReject(403, 'wrong_role'); }
        $receipt = wrbBody();
        wrbReceipt($receipt);
        if ($managed) {
            $source = wrbSource($connection);
            $session = wrbSession();
            $ledger = wrbLedger($config, $workspace);
            $snapshot = $parts[1];
            wrbPause($workspace, 'pause-before-complete');
            $result = wrbControlled(fn () => $ledger->publish($source, $session, hash('sha256', 'complete:' . $snapshot),
                ['kind' => 'complete', 'snapshotId' => $snapshot, 'manifest' => $receipt],
                function ($guard) use ($config, $reader, $snapshot, $receipt): array {
                    LocalRepository::forWriting($config['repository'], $config['unitId'], $guard)->publish($snapshot, $receipt);
                    return $reader->completed($snapshot);
                }, fn ($descriptor) => wrbLookup($reader, $config, $descriptor)));
            if (wrbFault($workspace, 'drop-complete-response-once')) { http_response_code(503); exit; }
            wrbJson($result);
        }
        $commit = $config['repository'] . '/' . $config['unitId'] . '/commits/' . $parts[1] . '.json';
        FileSafety::assertNoLinks($commit);
        if (@lstat($commit) !== false) {
            $record = $reader->completed($parts[1]);
            if ($record['manifest'] != $receipt) { wrbReject(409, 'completion_conflict'); }
        } else { $writer->publish($parts[1], $receipt); }
        wrbJson($reader->completed($parts[1]));
    }
    if ($method === 'GET' && $path === '/has-objects') {
        if ($role !== 'writer') { wrbReject(403, 'wrong_role'); }
        wrbJson(['hasObjects' => $writer->hasObjects()]);
    }
    if ($managed && $role === 'writer' && $method === 'GET' && preg_match('~^/completed/([a-f0-9]{64})$~D', $path, $parts)) {
        $ledger = wrbLedger($config, $workspace);
        $state = wrbControlled(fn () => $ledger->status(wrbSource($connection)));
        if ($state['generation'] !== $connection['generation']) { wrbReject(403, 'control_rejected'); }
        wrbJson($reader->completed($parts[1]));
    }
    if ($role !== 'reader') { wrbReject(403, 'wrong_role'); }
    if ($method === 'GET' && $path === '/completed') { wrbJson($reader->listStatus()); }
    if ($method === 'GET' && preg_match('~^/completed/([a-f0-9]{64})$~D', $path, $parts)) { wrbJson($reader->completed($parts[1])); }
    if ($method === 'GET' && preg_match('~^/files/([a-f0-9]{64})$~D', $path, $parts)) { wrbJson($reader->listFileObjects($parts[1])); }
    if ($method === 'GET' && str_starts_with($path, '/objects/')) {
        $key = substr($path, 9);
        if (!preg_match('~^(?:files/[a-f0-9]{64}/[a-f0-9]{64}\.age|(?:databases|manifests)/[a-f0-9]{64}\.age)$~D', $key)) { wrbReject(400, 'invalid_key'); }
        $file = $reader->objectPath($key);
        $size = filesize($file);
        if ($size < 1 || $size > 67108864) { wrbReject(413, 'object_too_large'); }
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . $size);
        $input = fopen($file, 'rb');
        try {
            if (wrbFault($workspace, 'truncate-get-once')) { if ($size > 1) { echo fread($input, $size - 1); } }
            elseif (wrbFault($workspace, 'corrupt-get-once')) { $first = fread($input, 1); echo chr(ord($first) ^ 1); fpassthru($input); }
            else { fpassthru($input); }
        } finally { fclose($input); }
        exit;
    }
    wrbReject(404, 'unknown_route');
} catch (InvalidArgumentException|JsonException $ignoredError) { wrbReject(400, 'invalid_request'); }
catch (WrbControlRejection $ignoredError) { wrbReject(409, 'control_rejected'); }
catch (Throwable $ignoredError) { wrbReject(422, 'verification_failed'); }
