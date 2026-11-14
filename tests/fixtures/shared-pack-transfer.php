<?php
declare(strict_types=1);
// Two synthetic generations with one sharing index, then the transfer steps of the second through an in-memory R2.
// Synthetic capture, transfer and publication only; no restore, no network. Usage: php shared-pack-transfer.php <plugin> <scenario>
function update_option($name, $value, $autoload = false) { $GLOBALS['fixtureState'] = $value; return true; }
function get_option($name, $default = null) { return $GLOBALS['fixtureState'] ?? $default; }
require $argv[1] . '/vendor/autoload.php';
require $argv[1] . '/tests/Fixture.php';
require $argv[1] . '/tests/WordPressDatabaseTest.php';
require $argv[1] . '/tests/MemoryR2Transport.php';
use WordPressR2Backup\{AgeProcess, DirectR2Repository, LiveBackup, Manifest, PrivacyPolicy, SourceRoots, V4Capture, V4ContentIndex, V4Format};
use WordPressR2Backup\Tests\{Fixture, MemoryR2Transport, V4DatabaseWpdbFake};

function check(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
function rejected(callable $action): string
{
    try { $action(); } catch (Throwable $expected) { return $expected->getMessage(); }
    throw new RuntimeException('Expected a rejected transfer step');
}
function privateWrite(string $file, string $body): void { check(file_put_contents($file, $body) === strlen($body), 'Fixture write failed'); chmod($file, 0600); }

$scenario = $argv[2] ?? '';
check(in_array($scenario, ['success', 'mismatch', 'not-ready', 'corrupt', 'deleted', 'legacy', 'guard-missing', 'guard-mismatch', 'guard-half'], true), 'Unknown scenario');
$root = Fixture::temp(); $workspace = Fixture::temp(); $objectDirectory = Fixture::temp();
// Several lanes of small files, so the first generation writes several packs and the second reuses all of them.
for ($number = 0; $number < 600; ++$number) { Fixture::write($root, sprintf('code/f%03d.php', $number), '<?php // synthetic shared file ' . $number . "\n"); }
for ($number = 0; $number < 20; ++$number) { Fixture::write($root, sprintf('uploads/m%02d.txt', $number), 'synthetic media ' . $number); }
for ($number = 0; $number < 20; ++$number) { Fixture::write($root, sprintf('notes/n%02d.txt', $number), 'synthetic note ' . $number); }
$recipients = array_column(Fixture::keys(), 'public');
$connection = ['accountId' => str_repeat('a', 32), 'bucket' => 'backup-test', 'accessKeyId' => str_repeat('b', 32), 'secretAccessKey' => str_repeat('c', 64), 'unitId' => str_repeat('d', 64)];
$settings = $connection + ['recipients' => $recipients, 'retentionDays' => 30, 'connectionDigest' => hash('sha256', 'synthetic'), 'policy' => ['version' => 2, 'preset' => 'full']];
$memory = new MemoryR2Transport($objectDirectory); $reads = []; $writes = 0;
$transport = static function (array $request, callable $consume) use ($memory, &$reads, &$writes): array {
    $path = (string) parse_url($request['url'], PHP_URL_PATH);
    if (in_array($request['method'], ['GET', 'HEAD'], true) && strpos($request['url'], '?') === false) { $reads[$request['method'] . ' ' . $path] = ($reads[$request['method'] . ' ' . $path] ?? 0) + 1; }
    if ($request['method'] === 'PUT') { ++$writes; }
    return $memory($request, $consume);
};
$repository = new DirectR2Repository($connection, $transport, null, static function (int $seconds): void {});
$scope = $repository->storageScope() + ['keySetId' => Manifest::keySetId($recipients), 'encoding' => V4Format::ENCODING];
$index = V4ContentIndex::openOrCreate(Fixture::temp(), $scope, $repository);
$class = new ReflectionClass(LiveBackup::class); $backup = $class->newInstanceWithoutConstructor();
foreach (['repository' => $repository, 'settings' => $settings, 'workspace' => $workspace] as $name => $value) { $property = $class->getProperty($name); $property->setAccessible(true); $property->setValue($backup, $value); }
$method = static function (string $name) use ($class): ReflectionMethod { $method = $class->getMethod($name); $method->setAccessible(true); return $method; };
$stepV4 = $method('stepV4'); $saveJob = $method('saveJob'); $readJob = $method('readJob');
$pathOf = static fn (string $key): string => '/backup-test/wordpress-r2/' . $connection['unitId'] . '/' . $key;

/**
 * Captures one generation under the index lock and saves its job as LiveBackup::begin() does, with its capture guard and
 * capture-ready file unless it imitates a legacy job from before the guard. Returns the job directory.
 */
$capture = static function (string $id, string $snapshot, bool $guarded = true) use ($root, $workspace, $recipients, $scope, $index, $backup, $saveJob, $method): string {
    $directory = $workspace . '/job-' . $id; mkdir($directory, 0700);
    $result = $index->withLock(static fn (): array => (new V4Capture(new SourceRoots($root), new V4DatabaseWpdbFake(), new PrivacyPolicy(['version' => 2, 'preset' => 'full']), $directory))
        ->capture(new AgeProcess(Fixture::runtime()['age']), $recipients, $snapshot, $scope, null, $index));
    $GLOBALS['captureMeasurements'] = $result['measurements'];
    $state = ['id' => $id, 'snapshotId' => $snapshot, 'status' => 'uploading', 'completed' => 0, 'total' => count($result['objects']) + 1, 'message' => ''];
    if ($guarded) { $state['captureGuard'] = bin2hex(random_bytes(16)); }
    $job = ['version' => 4, 'state' => $state, 'manifest' => $result['manifest'], 'objects' => $result['objects'], 'position' => 0,
        'settingsDigest' => $method('settingsDigest')->invoke($backup), 'indexIdentity' => $index->identity()];
    $saveJob->invoke($backup, $directory, $job); update_option('wrb_live_operation', $state);
    if ($guarded) { $method('writeCaptureGuard')->invoke($backup, $directory, $state); }
    return $directory;
};
/** One transfer step as LiveBackup::step() runs it for a v4 job with a sharing index: inside the index lock. */
$step = static function (string $directory) use ($index, $backup, $stepV4, $readJob): array {
    $job = $readJob->invoke($backup, $directory); $state = get_option('wrb_live_operation');
    return $index->withLock(static fn (): array => $stepV4->invoke($backup, $directory, $job, $state, $index));
};

// Generation 1 uploads new packs and marks them ready in the index.
$first = $capture(str_repeat('1', 64), str_repeat('e', 64));
check(count(array_filter($readJob->invoke($backup, $first)['objects'], static fn (array $entry): bool => $entry['cipherFile'] !== null && str_starts_with($entry['object']['key'], 'packs/'))) >= 3, 'The first generation must write several packs');
do { $state = $step($first); } while ($state['status'] === 'uploading');
check($state['status'] === 'complete', 'The first generation must publish');

// Generation 2 reuses every pack. The capture reads each of them in full once, under its lock.
$reads = [];
$directory = $capture(str_repeat('2', 64), str_repeat('f', 64), $scenario !== 'legacy');
$objects = $readJob->invoke($backup, $directory)['objects'];
$start = null; $end = null;
foreach ($objects as $position => $entry) {
    if ($entry['cipherFile'] !== null) { if ($start !== null && $end === null) { $end = $position; } continue; }
    if ($start === null) { $start = $position; }
    elseif ($end !== null) { throw new RuntimeException('The fixture expects one run of shared packs'); }
}
$end = $end ?? count($objects);
check($start !== null && $end - $start >= 3, 'Expected at least three consecutive shared packs');
$shared = array_map(static fn (array $entry): string => $entry['object']['key'], array_slice($objects, $start, $end - $start));
$fullReads = static function () use (&$reads, $shared, $pathOf): array { return array_map(static fn (string $key): int => $reads['GET ' . $pathOf($key)] ?? 0, $shared); };
$heads = static function () use (&$reads, $shared, $pathOf): array { return array_map(static fn (string $key): int => $reads['HEAD ' . $pathOf($key)] ?? 0, $shared); };
check(array_unique($fullReads()) === [1], 'The capture must read every shared pack in full once');
while ($readJob->invoke($backup, $directory)['position'] < $start) { $step($directory); }
check($readJob->invoke($backup, $directory)['position'] === $start, 'Objects before the run move one at a time');
$saved = file_get_contents($directory . '/job.json'); $savedState = get_option('wrb_live_operation');
$unchanged = static function (string $message) use ($directory, $saved, $savedState): void {
    check(file_get_contents($directory . '/job.json') === $saved, 'A failed step must not rewrite the job: ' . $message);
    check(get_option('wrb_live_operation') === $savedState, 'A failed step must not change the state: ' . $message);
};
$result = ['scenario' => $scenario, 'shared' => count($shared), 'start' => $start];

if ($scenario === 'legacy') {
    // A legacy job without a capture guard cannot prove its capture: its shared packs are still read in full, four per
    // step. A failed read keeps the saved position and job; resuming repeats that batch, then publication reads each again.
    $before = $fullReads();
    $step($directory);
    check($readJob->invoke($backup, $directory)['position'] === $start + 4, 'A legacy batch must advance by four');
    $expected = $before; for ($offset = 0; $offset < 4; ++$offset) { ++$expected[$offset]; }
    check($fullReads() === $expected, 'A legacy batch must read its four packs in full');
    $saved = file_get_contents($directory . '/job.json'); $savedState = get_option('wrb_live_operation');
    $file = $objectDirectory . '/' . hash('sha256', $pathOf($shared[5])) . '.cipher'; $original = file_get_contents($file);
    $bytes = $original; $bytes[strlen($bytes) - 1] = $bytes[strlen($bytes) - 1] === 'x' ? 'y' : 'x'; privateWrite($file, $bytes);
    $message = rejected(static function () use ($step, $directory): void { $step($directory); });
    check(file_get_contents($directory . '/job.json') === $saved && get_option('wrb_live_operation') === $savedState, 'A failed legacy batch must keep the position: ' . $message);
    check($readJob->invoke($backup, $directory)['position'] === $start + 4, 'A failed legacy batch must not move');
    privateWrite($file, $original);
    $step($directory);
    check($readJob->invoke($backup, $directory)['position'] === min($start + 8, $end), 'The resumed legacy batch must advance');
    $afterTransfer = $fullReads();
    do { $state = $step($directory); } while ($state['status'] === 'uploading');
    check($state['status'] === 'complete', 'The legacy generation must publish');
    check(array_map(static fn (int $a, int $b): int => $a - $b, $fullReads(), $afterTransfer) === array_fill(0, count($shared), 2), 'Publication must read every shared pack in full twice');
    $result['legacyFullReads'] = $fullReads();
    $result['stateMeasurements'] = get_option('wrb_live_operation')['measurements'] ?? null;
} elseif (in_array($scenario, ['guard-missing', 'guard-mismatch', 'guard-half'], true)) {
    // A guard that does not prove a complete capture stops the run before any read and keeps the position.
    if ($scenario === 'guard-missing') { unlink($directory . '/capture-ready'); }
    elseif ($scenario === 'guard-mismatch') { $state = get_option('wrb_live_operation'); $state['captureGuard'] = str_repeat('0', 32); update_option('wrb_live_operation', $state); }
    else { $state = get_option('wrb_live_operation'); unset($state['captureGuard']); update_option('wrb_live_operation', $state); }
    $saved = file_get_contents($directory . '/job.json'); $savedState = get_option('wrb_live_operation'); $before = $fullReads();
    $message = rejected(static function () use ($step, $directory): void { $step($directory); });
    check(file_get_contents($directory . '/job.json') === $saved && get_option('wrb_live_operation') === $savedState, 'A refused guard must save nothing: ' . $message);
    check($readJob->invoke($backup, $directory)['position'] === $start && $fullReads() === $before, 'A refused guard must not move or read');
    $result['guardRejected'] = $message;
} elseif ($scenario === 'mismatch') {
    // A receipt that differs from the index stops the run and saves nothing. The original job then passes.
    foreach (['sha256' => str_repeat('0', 64), 'bytes' => 1] as $field => $value) {
        $tampered = json_decode($saved, true, 64, JSON_THROW_ON_ERROR); $tampered['objects'][$start + 1]['object'][$field] = $value;
        privateWrite($directory . '/job.json', json_encode($tampered, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)); $changed = file_get_contents($directory . '/job.json');
        $message = rejected(static function () use ($step, $directory): void { $step($directory); });
        check($message === '共有パックを照合できません。', 'Unexpected mismatch message: ' . $message);
        check(file_get_contents($directory . '/job.json') === $changed && get_option('wrb_live_operation') === $savedState, 'A mismatch must save nothing');
    }
    privateWrite($directory . '/job.json', $saved);
    $step($directory);
    check($readJob->invoke($backup, $directory)['position'] === $end, 'The repaired run must move past every shared pack at once');
    $result['mismatchRejected'] = true;
} elseif ($scenario === 'not-ready') {
    // A pack the index no longer holds as ready stops the run before any read, and the position stays where it was.
    $index->withLock(static function () use ($index, $shared): void { $index->markMissing([$shared[1]]); });
    $before = $fullReads();
    $message = rejected(static function () use ($step, $directory): void { $step($directory); });
    check($message === 'Pack is not ready for reuse.', 'Unexpected not-ready message: ' . $message);
    $unchanged($message);
    check($fullReads() === $before, 'A refused run must not read R2');
    $result['notReadyRejected'] = true;
} else {
    $before = ['full' => $fullReads(), 'head' => $heads(), 'writes' => $writes];
    $state = $step($directory);
    $after = $readJob->invoke($backup, $directory);
    check($after['position'] === $end && $state['completed'] === $end && get_option('wrb_live_operation')['completed'] === $end, 'One step must move past the whole run and save it');
    check($fullReads() === $before['full'] && $heads() === $before['head'] && $writes === $before['writes'], 'The transfer of shared packs must not read or write R2');
    while ($readJob->invoke($backup, $directory)['position'] < count($objects)) { $step($directory); }
    $commit = $pathOf('commits/' . str_repeat('f', 64) . '.json');
    if ($scenario !== 'success') {
        // A shared pack corrupted (same length) or deleted after the capture must fail publication before any completion
        // record exists. The position stays at the publication step, and the job remains resumable.
        $target = $shared[1]; $file = $objectDirectory . '/' . hash('sha256', $pathOf($target)) . '.cipher'; $original = file_get_contents($file);
        if ($scenario === 'corrupt') { $bytes = $original; $bytes[strlen($bytes) - 1] = $bytes[strlen($bytes) - 1] === 'x' ? 'y' : 'x'; privateWrite($file, $bytes); }
        else { $memory(['method' => 'DELETE', 'url' => 'https://synthetic' . $pathOf($target), 'headers' => [], 'body' => null, 'bodyBytes' => 0], static fn (string $chunk): int => strlen($chunk)); }
        $publishPosition = $readJob->invoke($backup, $directory)['position'];
        $stateBefore = get_option('wrb_live_operation');
        $message = rejected(static function () use ($step, $directory): void { $step($directory); });
        $job = $readJob->invoke($backup, $directory);
        check($publishPosition === count($objects) && $job['position'] === $publishPosition, 'A failed publication must keep the position: ' . $message);
        check(get_option('wrb_live_operation') === $stateBefore && $stateBefore['status'] === 'uploading', 'A failed publication must stay resumable: ' . $message);
        $map = json_decode(file_get_contents($objectDirectory . '/objects.json'), true, 32, JSON_THROW_ON_ERROR);
        check(!isset($map[$commit]), 'No completion record may exist after a failed publication');
        if ($scenario === 'corrupt') { privateWrite($file, $original); }
        else {
            $stream = fopen('php://temp', 'w+b'); fwrite($stream, $original); rewind($stream);
            $memory(['method' => 'PUT', 'url' => 'https://synthetic' . $pathOf($target), 'headers' => [], 'body' => $stream, 'bodyBytes' => strlen($original)], static fn (string $chunk): int => strlen($chunk));
            fclose($stream);
        }
        $result['publicationRejected'] = $message;
    }
    $reads = [];
    $state = $step($directory);
    check($state['status'] === 'complete', 'The generation must publish');
    $map = json_decode(file_get_contents($objectDirectory . '/objects.json'), true, 32, JSON_THROW_ON_ERROR);
    check(isset($map[$commit]), 'The completion record must exist');
    // Publication reads every shared pack in full before the completion record and again after it.
    check(array_unique($fullReads()) === [2] && array_unique($heads()) === [2], 'Publication must read every shared pack in full twice');
    $result['publicationFullReads'] = 2;
    $result['captureMeasurements'] = $GLOBALS['captureMeasurements'];
    $result['stateMeasurements'] = get_option('wrb_live_operation')['measurements'] ?? null;
    $result['objects'] = count($objects); $result['sharedBytes'] = array_sum(array_map(static fn (array $entry): int => $entry['object']['bytes'], array_slice($objects, $start, $end - $start)));
}
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), "\n";
Fixture::cleanup();
