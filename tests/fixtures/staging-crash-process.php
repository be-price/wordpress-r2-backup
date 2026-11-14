<?php
declare(strict_types=1);
namespace WordPressR2Backup;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
umask(0077);
$input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
if ($input['phase'] === 'after-link') {
    function link(string $from, string $to): bool
    {
        $linked = \link($from, $to);
        if ($linked) { exit(73); }
        return $linked;
    }
}
try {
    $ledger = UnitLedger::open($input['control'], $input['unit']);
    $lease = $ledger->acquire($input['source'], 1, $input['job'], $input['nonce'], 1, $input['cursor']);
    $session = ['generation' => 1, 'jobId' => $input['job'], 'processNonce' => $input['nonce'],
        'fence' => $lease['fence'], 'cursor' => $lease['cursor']];
    $receipt = ['key' => $input['key'], 'bytes' => filesize($input['cipher']), 'sha256' => hash_file('sha256', $input['cipher'])];
    $reader = LocalRepository::forRecovery($input['root'], $input['unit']);
    $ledger->publish($input['source'], $session, $input['publication'], ['kind' => 'object', 'receipt' => $receipt],
        static function (callable $guard) use ($input): array {
            $writer = LocalRepository::forWriting($input['root'], $input['unit'], static function () use ($guard, $input): void {
                $guard();
                if ($input['phase'] === 'before-link') { exit(73); }
            });
            return $writer->putNew($input['key'], $input['cipher']);
        },
        static function (array $descriptor) use ($input, $reader): ?array {
            $path = $input['root'] . '/' . $input['unit'] . '/' . $descriptor['receipt']['key'];
            FileSafety::assertNoLinks($path);
            if (@lstat($path) === false) { return null; }
            $reader->verify($descriptor['receipt']);
            return $descriptor['receipt'];
        });
} catch (\Throwable $ignoredError) {
    fwrite(STDERR, 'Synthetic staging interruption failed.');
    exit(1);
}
exit(2);
