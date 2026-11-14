<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
// Dedicated PHP HTTP boundary fixture. This does not emulate or prove R2 storage.
$root = getenv('WRB_WRITER_FIXTURE_ROOT');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$mode = is_file($root . '/mode') ? trim(file_get_contents($root . '/mode')) : '';
$state = is_file($root . '/state.json') ? json_decode(file_get_contents($root . '/state.json'), true) : ['puts' => 0, 'registers' => 0, 'renews' => 0, 'ttls' => []];
$data = $_SERVER['REQUEST_METHOD'] === 'POST' ? json_decode(file_get_contents('php://input'), true) : null;
$send = static function (array $body, int $status = 200) use ($root, &$state): void {
    if (isset($state['receipt'])) { file_put_contents($root . '/state.json', json_encode($state)); }
    http_response_code($status); header('Content-Type: application/json'); echo json_encode($body); exit;
};
$session = json_decode($_SERVER['HTTP_X_WRB_SESSION'] ?? 'null', true);
if (!str_starts_with($path, '/parts/') && ($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer primary-private-token') { $send(['error' => 'credential'], 403); }
if ($path === '/control/acquire' || $path === '/control/renew') {
    if ($path === '/control/renew' && $mode === 'lost-complete' && ($state['completed'] ?? false)) { $send(['error' => 'completed_lease'], 409); }
    if ($path === '/control/acquire') {
        file_put_contents($root . '/lease.json', json_encode($data));
    } else {
        $state['renews']++; $state['ttls'][] = $data['ttl'];
    }
    $acquired = json_decode(file_get_contents($root . '/lease.json'), true);
    $send(['sourceId' => str_repeat('b', 64), 'generation' => 1, 'jobId' => $acquired['jobId'], 'fence' => 1,
        'expiresAt' => time() + $data['ttl'], 'cursor' => $acquired['cursor']]);
}
if ($path === '/upload-plans') {
    if (!isset($state['receipt'])) {
        $state['receipt'] = $data['receipt']; $state['parts'] = [];
        foreach ($data['parts'] as $n => $part) { $state['parts'][] = ['key' => 'uploads/' . str_repeat('1', 64) . '/' . $n] + $part; }
    }
    $plan = ['uploadId' => str_repeat('1', 64), 'receipt' => $state['receipt'], 'parts' => $state['parts']];
    if ($mode === 'plan-key') { $plan['parts'][0]['key'] = 'uploads/' . str_repeat('2', 64) . '/0'; }
    if ($mode === 'plan-extra') { $plan['parts'][0]['unknown'] = true; }
    if ($mode === 'mutate-file') { file_put_contents(trim(file_get_contents($root . '/cipher-path')), str_repeat('x', $data['receipt']['bytes'])); }
    if ($mode === 'expired-put-once') { $plan['parts'] = array_map(static fn ($p) => ['sha256' => $p['sha256'], 'bytes' => $p['bytes'], 'key' => $p['key']], $plan['parts']); }
    $send($plan);
}
if ($path === '/part-grants') {
    $n = $data['ordinal'];
    $grant = ['token' => 'part-private-token-' . $n, 'path' => '/parts/' . str_repeat('1', 64) . '/' . $n,
        'bytes' => $state['parts'][$n]['bytes'], 'sha256' => $state['parts'][$n]['sha256'], 'expiresAt' => time() + 60,
        'headers' => ['If-None-Match' => '*']];
    if ($mode === 'absolute') { $grant['path'] = 'http://example.com/parts'; }
    if ($mode === 'traversal') { $grant['path'] .= '/../0'; }
    if ($mode === 'wrong-path') { $grant['path'] = '/parts/' . str_repeat('2', 64) . '/0'; }
    if ($mode === 'extra') { $grant['url'] = 'http://example.com'; }
    if ($mode === 'headers') { $grant['headers']['Authorization'] = 'Bearer primary-private-token'; }
    if ($mode === 'hash') { $grant['sha256'] = str_repeat('0', 64); }
    if ($mode === 'bytes') { $grant['bytes']++; }
    if ($mode === 'expiry') { $grant['expiresAt'] = time() + 10000; }
    $send($grant);
}
if (preg_match('~^/parts/([a-f0-9]{64})/([0-7])$~D', $path, $match)) {
    $n = (int) $match[2]; $state['puts']++;
    if ($mode === 'part-unavailable') { $send(['error' => 'unavailable'], 503); }
    if ($mode === 'expired-put-once' && $state['puts'] === 1) { $send(['error' => 'expired_grant'], 403); }
    if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer part-private-token-' . $n || ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') !== '*') { $send(['error' => 'grant'], 403); }
    $input = fopen('php://input', 'rb'); $hash = hash_init('sha256'); $bytes = hash_update_stream($hash, $input); fclose($input);
    if ($bytes !== $state['parts'][$n]['bytes'] || hash_final($hash) !== $state['parts'][$n]['sha256']) { $send(['error' => 'changed'], 400); }
    if ($mode === 'lost-pending' && $state['puts'] === 1) {
        file_put_contents($root . '/state.json', json_encode($state));
        header('Content-Length: 999'); echo '{'; exit;
    }
    $part = $state['parts'][$n];
    $send(['sha256' => $part['sha256'], 'bytes' => $part['bytes'], 'key' => $part['key']]);
}
if ($mode === 'lost-complete' && preg_match('~^/complete/([a-f0-9]{64})$~D', $path, $complete)) {
    $state['completes']++; $state['completed'] = true;
    $send(['snapshotId' => $complete[1], 'manifest' => $data], $state['completes'] === 1 ? 503 : 200);
}
if ($path === '/register') {
    $state['registers']++;
    if ($mode === 'pending-forever' || ($mode === 'lost-pending' && $state['registers'] === 1)) { $send(['pending' => true], 202); }
    $send($state['receipt']);
}
$send(['error' => 'unknown'], 404);
