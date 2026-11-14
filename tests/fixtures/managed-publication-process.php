<?php
declare(strict_types=1);

use WordPressR2Backup\{CaptureControlException, SyntheticHttpRepository};

require dirname(__DIR__, 2) . '/vendor/autoload.php';
$input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
try {
    $repository = new SyntheticHttpRepository($input['connection']);
    // Leave time for state publication before the deliberate pause and lease expiry.
    $repository->acquire($input['jobId'], $input['nonce'], $input['cursor'], 3);
    $receipt = $repository->putNew($input['key'], $input['cipher']);
    if ($input['operation'] === 'complete') { $repository->publish($input['jobId'], $receipt); }
    fwrite(STDOUT, json_encode(['published' => true], JSON_THROW_ON_ERROR));
} catch (CaptureControlException $ignoredError) {
    fwrite(STDOUT, json_encode(['rejected' => true], JSON_THROW_ON_ERROR));
} catch (Throwable $ignoredError) {
    fwrite(STDERR, 'Synthetic publication failed.');
    exit(1);
}
