<?php
declare(strict_types=1);

namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\{CaptureControlException, SyntheticHttpRepository};

final class ManagedPublicationBoundaryTest extends TestCase
{
    public static function operations(): array { return [['put'], ['complete']]; }

    /** @dataProvider operations */
    public function testExpiredRequestCannotPublishAfterAnotherWorkerAcquires(string $operation): void
    {
        $server = $peer = null;
        $process = null;
        $pipes = [];
        try {
            $unit = str_repeat('a', 64);
            $job = str_repeat('b', 64);
            $cursor = ['indexId' => str_repeat('c', 64), 'sequence' => 0, 'commitment' => str_repeat('d', 64)];
            $server = IsolatedObjectServer::startManaged(Fixture::temp(), $unit, str_repeat('e', 64), $cursor);
            $peer = $server->peer();
            $cipher = Fixture::temp() . '/cipher';
            file_put_contents($cipher, 'synthetic cipher');
            $key = ($operation === 'complete' ? 'manifests/' : 'databases/') . $job . '.age';
            $server->fault('pause-before-' . $operation);
            $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/managed-publication-process.php'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, [], ['bypass_shell' => true]);
            self::assertIsResource($process);
            fwrite($pipes[0], json_encode(['connection' => $server->connectionFile('writer'), 'jobId' => $job,
                'nonce' => str_repeat('f', 64), 'cursor' => $cursor, 'cipher' => $cipher, 'key' => $key, 'operation' => $operation], JSON_THROW_ON_ERROR));
            fclose($pipes[0]);
            unset($pipes[0]);
            $server->waitForPause();
            $writer = new SyntheticHttpRepository($peer->connectionFile('writer'));
            $old = $writer->controlStatus()['lease'];
            $deadline = microtime(true) + max(2, $old['expiresAt'] - time() + 2);
            while (time() < $old['expiresAt'] && microtime(true) < $deadline) { usleep(10000); }
            $new = $writer->acquire($job, str_repeat('1', 64), $cursor, 30);
            self::assertGreaterThan($old['fence'], $new['fence']);
            $server->releasePublication();
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]); $pipes = [];
            self::assertSame(0, proc_close($process), $stderr); $process = null;
            self::assertSame(['rejected' => true], json_decode($stdout, true, 8, JSON_THROW_ON_ERROR));
            $reader = new SyntheticHttpRepository($peer->connectionFile('reader'));
            self::assertSame([], $reader->listStatus());
            if ($operation === 'put') { self::assertFalse($writer->hasObjects()); }
            $receipt = $writer->putNew($key, $cipher);
            if ($operation === 'complete') {
                $writer->publish($job, $receipt);
                self::assertSame($job, $reader->completed($job)['snapshotId']);
            } else { $reader->verify($receipt); }
        } finally {
            foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
            if (is_resource($process)) { if (proc_get_status($process)['running']) { proc_terminate($process); } proc_close($process); }
            if ($peer !== null) { $peer->stop(); } if ($server !== null) { $server->stop(); } Fixture::cleanup();
        }
    }
}
