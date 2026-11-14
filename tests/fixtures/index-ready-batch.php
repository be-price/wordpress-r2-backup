<?php
declare(strict_types=1);
// V4ContentIndex::verifyReadyPacks() through the real DirectR2Repository and synthetic cURL functions. No network.
// Usage: php index-ready-batch.php <success|tls|corrupt|notready>
namespace WordPressR2Backup {
    function curl_init() { return (object) ['opts' => [], 'status' => 0, 'errno' => 0]; }
    function curl_close($h): void {}
    function curl_reset($h): void { $h->opts = []; }
    function curl_setopt_array($h, array $o): bool { $h->opts = $o + $h->opts; return true; }
    function curl_setopt($h, int $option, $value): bool { $h->opts[$option] = $value; return true; }
    function curl_errno($h): int { return $h->errno; }
    function curl_getinfo($h, int $option) { return $h->status; }
    function curl_exec($h) { $h->errno = \batch_perform($h, false); return $h->errno === 0; }
    function curl_multi_init() { return (object) ['handles' => [], 'done' => []]; }
    function curl_multi_add_handle($m, $h): int { $h->finished = false; $m->handles[] = $h; $GLOBALS['b']['parallel'] = max($GLOBALS['b']['parallel'], count($m->handles)); return CURLM_OK; }
    function curl_multi_remove_handle($m, $h): int { $m->handles = array_values(array_filter($m->handles, static fn ($x): bool => $x !== $h)); return CURLM_OK; }
    function curl_multi_exec($m, &$running): int {
        foreach (array_reverse($m->handles) as $h) {
            if (!empty($h->finished)) { continue; }
            $h->finished = true; $m->done[] = ['msg' => CURLMSG_DONE, 'result' => \batch_perform($h, true), 'handle' => $h]; break;
        }
        $running = count(array_filter($m->handles, static fn ($h): bool => empty($h->finished)));
        return CURLM_OK;
    }
    function curl_multi_info_read($m) { return $m->done === [] ? false : array_shift($m->done); }
    function curl_multi_select($m, float $timeout = 1.0): int { return 0; }
    function curl_multi_close($m): void {}
}

namespace {
    require dirname(__DIR__, 2) . '/vendor/autoload.php';
    require dirname(__DIR__) . '/V4ContentIndexReadyBatchTest.php';
    use WordPressR2Backup\{DirectR2Repository, R2TransportException, V4ContentIndex};
    use WordPressR2Backup\Tests\{Fixture, ReadyBatchManyRepository, V4ContentIndexReadyBatchTest};

    function check(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }

    function batch_perform($h, bool $multi): int
    {
        $state = &$GLOBALS['b']; $o = $h->opts; $method = $o[CURLOPT_CUSTOMREQUEST]; $h->status = 0;
        check(($o[CURLOPT_SSL_VERIFYPEER] ?? null) === true && ($o[CURLOPT_SSL_VERIFYHOST] ?? null) === 2 && ($o[CURLOPT_FOLLOWLOCATION] ?? null) === false
            && ($o[CURLOPT_PROTOCOLS] ?? null) === CURLPROTO_HTTPS, 'TLS policy changed');
        check(($method === 'HEAD') === !empty($o[CURLOPT_NOBODY]) && !isset($o[CURLOPT_UPLOAD]), 'Request state leaked');
        $headers = [];
        foreach ($o[CURLOPT_HTTPHEADER] as $line) { [$name, $value] = explode(':', $line, 2); $headers[strtolower($name)] = trim($value); }
        check(($headers['x-amz-date'] ?? '') === gmdate('Ymd\THis\Z', $state['now']), 'Each read must be signed when it starts');
        check(strpos($headers['authorization'] ?? '', 'AWS4-HMAC-SHA256 Credential=') === 0, 'Missing signature');
        $key = substr(parse_url($o[CURLOPT_URL], PHP_URL_PATH), strlen('/synthetic-bucket/wordpress-r2/' . str_repeat('c', 64) . '/'));
        $state['requests'][] = [$method, $key, $multi];
        if ($state['scenario'] === 'tls' && $method === 'GET' && $key === $state['tlsKey']) { return 60; }
        if (!isset($state['bodies'][$key])) { $h->status = 404; return 0; }
        $body = $state['bodies'][$key];
        if ($state['scenario'] === 'corrupt' && $method === 'GET' && $key === $state['corruptKey']) { $body = str_repeat('x', strlen($body)); }
        $h->status = 200;
        ($o[CURLOPT_HEADERFUNCTION])($h, "HTTP/1.1 200 OK\r\n");
        if ($method === 'HEAD') { ($o[CURLOPT_HEADERFUNCTION])($h, 'Content-Length: ' . strlen($body) . "\r\n"); return 0; }
        foreach (str_split($body, 65536) as $chunk) { if (($o[CURLOPT_WRITEFUNCTION])($h, $chunk) !== strlen($chunk)) { return 23; } }
        return 0;
    }

    $scenario = $argv[1] ?? '';
    try {
        // Ready packs are recorded with a synthetic repository, then the same index is opened over the real repository.
        [$index, $synthetic, $keys, $candidate] = V4ContentIndexReadyBatchTest::readyIndex(5, ReadyBatchManyRepository::class, true);
        $directory = (new ReflectionProperty($index, 'directory')); $directory->setAccessible(true); $indexDirectory = $directory->getValue($index);
        $scopeProperty = new ReflectionProperty($index, 'scope'); $scopeProperty->setAccessible(true); $scope = $scopeProperty->getValue($index);
        $GLOBALS['b'] = ['parallel' => 0, 'requests' => [], 'now' => 1800000000, 'scenario' => $scenario, 'bodies' => []];
        foreach ($synthetic->files as $key => $file) { $GLOBALS['b']['bodies'][$key] = file_get_contents($file); }
        $GLOBALS['b']['tlsKey'] = $keys[1]; $GLOBALS['b']['corruptKey'] = $keys[2];
        $repository = new DirectR2Repository(['accountId' => $scope['accountId'], 'bucket' => 'synthetic-bucket', 'accessKeyId' => str_repeat('A', 16), 'secretAccessKey' => str_repeat('b', 32), 'unitId' => $scope['unitId']],
            null, static fn (): int => $GLOBALS['b']['now'], static function (int $seconds): void { $GLOBALS['b']['now'] += $seconds; });
        $real = V4ContentIndex::openOrCreate($indexDirectory, $scope, $repository);
        $batch = array_slice($keys, 0, 4); $failure = null; $descriptors = null;
        if ($scenario === 'notready') { $batch = [$keys[0], $candidate, $keys[1], $keys[2]]; }
        try { $descriptors = $real->withLock(static fn (): array => $real->verifyReadyPacks($batch)); }
        catch (Throwable $error) { $failure = $error; }
        $count = static function (string $method, string $key): int { return count(array_filter($GLOBALS['b']['requests'], static fn (array $r): bool => $r[0] === $method && $r[1] === $key)); };
        if ($scenario === 'success') {
            check($failure === null, 'Batch failed: ' . ($failure ? $failure->getMessage() : ''));
            check(array_column(array_column($descriptors, 'object'), 'key') === $batch, 'Descriptors out of order');
            check($GLOBALS['b']['parallel'] === 4, 'Expected four reads at once, saw ' . $GLOBALS['b']['parallel']);
            foreach ($batch as $key) { check($count('HEAD', $key) === 1 && $count('GET', $key) === 1, 'Each pack must be read in full exactly once'); }
            check($count('GET', $keys[4]) === 0, 'A pack outside the batch was read');
            check(count(array_filter($GLOBALS['b']['requests'], static fn (array $r): bool => !$r[2])) === 0, 'Every read must use the parallel reader');
        } elseif ($scenario === 'tls') {
            check($failure instanceof R2TransportException && $failure->getMessage() === 'R2 transport failed (tls, errno 60, attempt 1 of 4).', 'TLS failure must stop the batch without retry: ' . ($failure ? $failure->getMessage() : 'none'));
            check($count('GET', $keys[1]) === 1, 'A TLS failure must not be retried');
        } elseif ($scenario === 'corrupt') {
            check($failure instanceof RuntimeException && $failure->getMessage() === 'Ciphertext verification failed.', 'A changed pack must fail the batch');
        } elseif ($scenario === 'notready') {
            check($failure instanceof RuntimeException && $failure->getMessage() === 'Pack is not ready for reuse.', 'A candidate pack must stop the batch');
            check($GLOBALS['b']['requests'] === [], 'No pack may be read when one is not ready');
        } else { throw new RuntimeException('Unknown scenario'); }
        echo 'ok ', $scenario, "\n";
    } finally { Fixture::cleanup(); }
}
