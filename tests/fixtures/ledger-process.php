<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use WordPressR2Backup\UnitLedger;
fgets(STDIN);
try {
    $ledger = UnitLedger::open($argv[1], $argv[2], static fn () => 1000);
    $lease = $ledger->acquire($argv[3], 1, $argv[4], $argv[5], 30, ['indexId' => str_repeat('a', 64), 'sequence' => 0, 'commitment' => str_repeat('b', 64)]);
    if (($argv[6] ?? null) === 'publish-and-exit') {
        $receipt = ['key' => 'files/' . str_repeat('a', 64) . '/' . str_repeat('b', 64) . '.age', 'bytes' => 10, 'sha256' => hash('sha256', 'ciphertext')];
        $session = ['generation' => 1, 'jobId' => $argv[4], 'processNonce' => $argv[5], 'fence' => $lease['fence'], 'cursor' => $lease['cursor']];
        $ledger->publish($argv[3], $session, str_repeat('e', 64), ['kind' => 'object', 'receipt' => $receipt],
            static function (callable $guard) use ($argv): void { $guard(); file_put_contents($argv[7], 'ciphertext'); exit(73); },
            static fn (array $descriptor): ?array => null);
    }
    echo "acquired\n";
} catch (RuntimeException $ignoredError) {
    echo "rejected\n";
}
