<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$job = json_decode(file_get_contents($argv[1]), true, 16, JSON_THROW_ON_ERROR);
$reader = new WordPressR2Backup\R2Reader($job['profile']);
$reader->download($job['receipt'], $job['target']);
echo json_encode(['bytes' => filesize($job['target']), 'sha256' => hash_file('sha256', $job['target']),
    'peakBytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR), PHP_EOL;
