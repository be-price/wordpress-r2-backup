<?php

declare(strict_types=1);

use WordPressR2Backup\AgeProcess;

require dirname(__DIR__) . '/vendor/autoload.php';

function runCommand(array $command): string
{
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start measurement subprocess.');
    }
    $result = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('Measurement subprocess failed.');
    }
    return $result;
}

function worker(array $arguments): array
{
    [$binary, $directory, $recipientA, $recipientB, $identityFile] = $arguments;
    $plain = $directory . '/plain';
    $cipher = $directory . '/cipher';
    $restored = $directory . '/restored';
    $output = fopen($plain, 'xb');
    for ($i = 0; $i < 256; $i++) {
        $chunk = random_bytes(1024 * 1024);
        if (fwrite($output, $chunk) !== strlen($chunk)) {
            throw new RuntimeException('Cannot generate the complete synthetic fixture.');
        }
    }
    fclose($output);
    unset($chunk);
    $identity = fopen('php://memory', 'w+b');
    $identitySource = fopen($identityFile, 'rb');
    $identityBytes = stream_copy_to_stream($identitySource, $identity, 65537);
    fclose($identitySource);
    if ($identityBytes === false || $identityBytes > 65536) {
        throw new RuntimeException('The fixture identity exceeds the allowed memory bound.');
    }
    rewind($identity);
    $age = new AgeProcess($binary);
    $baseline = memory_get_usage(true);
    $baselineUsed = memory_get_usage(false);
    memory_reset_peak_usage();
    $started = hrtime(true);
    $age->encrypt($plain, $cipher, [$recipientA, $recipientB]);
    $encrypted = hrtime(true);
    $age->decrypt($cipher, $restored, $identity);
    $finished = hrtime(true);
    fclose($identity);
    $increment = memory_get_peak_usage(true) - $baseline;
    $incrementUsed = memory_get_peak_usage(false) - $baselineUsed;
    $matches = hash_equals(hash_file('sha256', $plain), hash_file('sha256', $restored));
    if (!$matches || filesize($plain) !== 268435456 || filesize($restored) !== 268435456 || $increment >= 33554432) {
        throw new RuntimeException('The complete 256 MiB round trip or PHP memory acceptance failed.');
    }
    $usage = getrusage(1);
    $allocated = 0;
    $apparent = 0;
    foreach (new FilesystemIterator($directory) as $entry) {
        $stat = stat($entry->getPathname());
        $allocated += $stat['blocks'] * 512;
        $apparent += $stat['size'];
    }
    return [
        'plain_bytes' => filesize($plain),
        'cipher_bytes' => filesize($cipher),
        'restored_bytes' => filesize($restored),
        'sha256_equal' => $matches,
        'failures' => 0,
        'encrypt_seconds' => ($encrypted - $started) / 1e9,
        'decrypt_seconds' => ($finished - $encrypted) / 1e9,
        'round_trip_seconds' => ($finished - $started) / 1e9,
        'php_peak_increment_allocated_bytes' => $increment,
        'php_peak_increment_used_bytes' => $incrementUsed,
        'age_child_max_rss_bytes' => $usage['ru_maxrss'] * (PHP_OS_FAMILY === 'Darwin' ? 1 : 1024),
        'age_child_cpu_seconds' => $usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1e6 + $usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1e6,
        'workspace_peak_apparent_bytes' => $apparent,
        'workspace_peak_allocated_bytes' => $allocated,
        'age_temporary_output_peak_bytes' => max(filesize($cipher), filesize($restored)),
    ];
}

if (($argv[1] ?? '') === '--worker') {
    echo json_encode(worker(array_slice($argv, 2)), JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(0);
}

$binary = getenv('WRB_AGE_BIN') ?: (require __DIR__ . '/runtime.php')['age'];
$runs = 5;
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/\A--runs=([1-9][0-9]?)\z/', $argument, $match) !== 1) {
        throw new RuntimeException('Usage: php tests/measure-age.php [--runs=1..99]');
    }
    $runs = (int) $match[1];
}
$directory = realpath(sys_get_temp_dir()) . '/wrb-age-measure-' . bin2hex(random_bytes(12));
if (!mkdir($directory, 0700)) {
    throw new RuntimeException('Cannot create the private fixture workspace.');
}
try {
    $recipients = [];
    for ($i = 0; $i < 2; $i++) {
        $identityFile = $directory . '/identity-' . $i;
        runCommand([dirname($binary) . '/age-keygen', '-o', $identityFile]);
        $recipients[] = trim(runCommand([dirname($binary) . '/age-keygen', '-y', $identityFile]));
    }
    $results = [];
    for ($i = 0; $i < $runs; $i++) {
        $runDirectory = $directory . '/run-' . $i;
        mkdir($runDirectory, 0700);
        $result = json_decode(runCommand([PHP_BINARY, __FILE__, '--worker', $binary, $runDirectory, $recipients[0], $recipients[1], $directory . '/identity-0']), true, 512, JSON_THROW_ON_ERROR);
        $results[] = $result;
        echo json_encode(['run' => $i + 1] + $result, JSON_THROW_ON_ERROR) . PHP_EOL;
        foreach (new FilesystemIterator($runDirectory) as $entry) {
            unlink($entry->getPathname());
        }
        rmdir($runDirectory);
    }
    $times = array_column($results, 'round_trip_seconds');
    sort($times);
    $middle = intdiv(count($times), 2);
    $median = count($times) % 2 === 0 ? ($times[$middle - 1] + $times[$middle]) / 2 : $times[$middle];
    echo json_encode([
        'runs' => $runs,
        'round_trip_median_seconds' => $median,
        'round_trip_min_seconds' => min($times),
        'round_trip_max_seconds' => max($times),
        'max_php_peak_increment_allocated_bytes' => max(array_column($results, 'php_peak_increment_allocated_bytes')),
        'max_php_peak_increment_used_bytes' => max(array_column($results, 'php_peak_increment_used_bytes')),
        'max_age_child_rss_bytes' => max(array_column($results, 'age_child_max_rss_bytes')),
        'max_workspace_peak_allocated_bytes' => max(array_column($results, 'workspace_peak_allocated_bytes')),
        'max_age_temporary_output_bytes' => max(array_column($results, 'age_temporary_output_peak_bytes')),
        'all_hashes_equal' => !in_array(false, array_column($results, 'sha256_equal'), true),
        'total_failures' => array_sum(array_column($results, 'failures')),
        'load_average_at_completion' => sys_getloadavg(),
        'scope' => 'Synthetic local age round trip only. Fixture generation and hash checking are outside timed regions. Workspace peaks use final allocated and apparent sizes; age output is create-only and grows monotonically. Child RSS is the maximum across completed age children, not combined PHP and child RSS. This is not a production-host adoption result.',
    ], JSON_THROW_ON_ERROR) . PHP_EOL;
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($directory);
}
