<?php
declare(strict_types=1);

namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use WordPressR2Backup\CaptureControlException;
use WordPressR2Backup\SyntheticHttpRepository;

final class ManagedHttpRepositoryTest extends TestCase
{
    private ?IsolatedObjectServer $server = null;

    protected function tearDown(): void
    {
        if ($this->server !== null) { $this->server->stop(); }
        Fixture::cleanup();
    }

    public function testManagedProfileAcceptsOnlyBoundWriter(): void
    {
        $repository = new SyntheticHttpRepository($this->connection($this->profile()));
        self::assertSame(str_repeat('a', 64), $repository->unitId());
    }

    public static function invalidProfiles(): array
    {
        return [['sourceId', str_repeat('A', 64)], ['sourceId', null], ['generation', 0],
            ['generation', '1'], ['generation', 1.0], ['role', 'reader'], ['unknown', true]];
    }

    /** @dataProvider invalidProfiles */
    public function testManagedProfileRejectsInvalidBinding(string $field, $value): void
    {
        $profile = $this->profile();
        $profile[$field] = $value;
        $this->expectException(RuntimeException::class);
        new SyntheticHttpRepository($this->connection($profile));
    }

    public function testManagedWriteRequiresAcquisitionBeforeNetwork(): void
    {
        $repository = new SyntheticHttpRepository($this->connection($this->profile()));
        $this->expectException(CaptureControlException::class);
        $repository->putNew('databases/' . str_repeat('b', 64) . '.age', $this->cipher('ciphertext'));
    }

    public function testManagedCompletionRequiresAcquisitionBeforeNetwork(): void
    {
        $repository = new SyntheticHttpRepository($this->connection($this->profile()));
        $snapshot = str_repeat('c', 64);
        $this->expectException(CaptureControlException::class);
        $repository->publish($snapshot, ['key' => 'manifests/' . $snapshot . '.age', 'bytes' => 1, 'sha256' => hash('sha256', 'x')]);
    }

    public function testAcquireRenewAdvanceAndPublicationUseActualLoopbackHttp(): void
    {
        [$writer, $reader] = $this->repositories();
        self::assertTrue($writer->managed());
        self::assertFalse($reader->managed());
        self::assertFalse($writer->hasObjects());
        $before = $writer->controlStatus();
        self::assertNull($before['lease']);
        self::assertSame($this->cursor(), $before['head']);
        $connectionBefore = file_get_contents($this->server->connectionFile('writer'));
        $job = str_repeat('c', 64); $nonce = str_repeat('d', 64);
        $lease = $writer->acquire($job, $nonce, $this->cursor());
        self::assertSame($connectionBefore, file_get_contents($this->server->connectionFile('writer')));
        self::assertSame(['sourceId', 'generation', 'jobId', 'fence', 'expiresAt', 'cursor'], array_keys($lease));
        self::assertSame(str_repeat('b', 64), $lease['sourceId']);
        self::assertSame($job, $lease['jobId']);
        self::assertSame(1, $lease['generation']);
        self::assertSame(1, $lease['fence']);
        self::assertSame($lease, $writer->acquire($job, $nonce, $this->cursor()));
        self::assertGreaterThanOrEqual($lease['expiresAt'], $writer->renew()['expiresAt']);
        $to = $this->cursor(1);
        $this->server->fault('drop-advance-response-once');
        self::assertSame($to, $writer->advance(str_repeat('e', 64), $this->cursor(), $to));
        self::assertSame($to, $writer->advance(str_repeat('e', 64), $this->cursor(), $to));
        self::assertSame($to, $writer->renew()['cursor']);
        $snapshot = str_repeat('f', 64);
        $database = $writer->putNew('databases/' . $snapshot . '.age', $this->cipher('managed database ciphertext'));
        $file = $writer->putNew('files/' . str_repeat('1', 64) . '/' . str_repeat('2', 64) . '.age', $this->cipher('managed file ciphertext'));
        $manifest = $writer->putNew('manifests/' . $snapshot . '.age', $this->cipher('managed manifest ciphertext'));
        foreach ([$database, $file, $manifest] as $receipt) {
            $writer->verify($receipt);
            $reader->verify($receipt);
        }
        $observer = new SyntheticHttpRepository($this->server->connectionFile('writer'));
        $observer->verify($database);
        self::assertTrue($observer->hasObjects());
        self::assertSame($to, $observer->controlStatus()['head']);
        $this->server->fault('drop-complete-response-once');
        $writer->publish($snapshot, $manifest);
        self::assertSame(['snapshotId' => $snapshot, 'manifest' => $manifest], $reader->completed($snapshot));
        $status = $writer->controlStatus();
        self::assertSame(['jobId' => $job, 'status' => 'completed'], $status['activeJob']);
        self::assertNull($status['lease']);
        self::assertStringNotContainsString($nonce, json_encode($status, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('processNonceHash', json_encode($status, JSON_THROW_ON_ERROR));
    }

    public function testLiveNonceAndCursorConflictsFailWithoutChangingClientCursor(): void
    {
        [$writer] = $this->repositories();
        $job = str_repeat('c', 64); $nonce = str_repeat('d', 64);
        $writer->acquire($job, $nonce, $this->cursor());
        $other = new SyntheticHttpRepository($this->server->connectionFile('writer'));
        $this->controlRejected(fn () => $other->acquire($job, str_repeat('e', 64), $this->cursor()));
        $this->controlRejected(fn () => $other->acquire(str_repeat('f', 64), $nonce, $this->cursor()));
        $this->controlRejected(fn () => $writer->advance(str_repeat('e', 64), $this->cursor(), $this->cursor(2)));
        self::assertSame($this->cursor(), $writer->renew()['cursor']);
        $writer->advance(str_repeat('f', 64), $this->cursor(), $this->cursor(1));
        self::assertSame($this->cursor(1), $writer->renew()['cursor']);
    }

    public function testExpiredLeaseCannotRenewOrWriteAndSameJobReacquiresWithNewFence(): void
    {
        [$writer] = $this->repositories();
        $job = str_repeat('c', 64); $nonce = str_repeat('d', 64);
        $first = $writer->acquire($job, $nonce, $this->cursor(), 1);
        sleep(1);
        $this->controlRejected(fn () => $writer->renew());
        $this->controlRejected(fn () => $writer->putNew('databases/' . str_repeat('e', 64) . '.age', $this->cipher('expired ciphertext')));
        self::assertFalse($writer->hasObjects());
        $next = $writer->acquire($job, str_repeat('f', 64), $this->cursor());
        self::assertSame($first['fence'] + 1, $next['fence']);
        $receipt = $writer->putNew('databases/' . str_repeat('e', 64) . '.age', $this->cipher('current ciphertext'));
        $writer->verify($receipt);
    }

    public function testConnectionBindingCannotBeReplacedByProfileSelfDeclaration(): void
    {
        [$valid] = $this->repositories();
        $profile = json_decode(file_get_contents($this->server->connectionFile('writer')), true, 8, JSON_THROW_ON_ERROR);
        foreach (['sourceId' => str_repeat('e', 64), 'generation' => 2] as $field => $value) {
            $changed = $profile;
            $changed[$field] = $value;
            $writer = new SyntheticHttpRepository($this->connection($changed));
            $this->controlRejected(fn () => $writer->acquire(str_repeat('c', 64), str_repeat('d', 64), $this->cursor()));
            self::assertNull($valid->controlStatus()['lease']);
        }
    }

    private function repositories(): array
    {
        $root = Fixture::temp();
        $this->server = IsolatedObjectServer::startManaged($root, str_repeat('a', 64), str_repeat('b', 64), $this->cursor());
        return [new SyntheticHttpRepository($this->server->connectionFile('writer')),
            new SyntheticHttpRepository($this->server->connectionFile('reader'))];
    }

    private function cursor(int $sequence = 0): array
    {
        return ['indexId' => str_repeat('1', 64), 'sequence' => $sequence,
            'commitment' => str_repeat((string) ($sequence + 2), 64)];
    }

    private function controlRejected(callable $action): void
    {
        $rejected = false;
        try {
            $action();
        } catch (CaptureControlException $error) {
            $rejected = true;
            self::assertNotSame('', $error->getMessage());
            self::assertStringNotContainsString('synthetic-private-token', $error->getMessage());
        }
        self::assertTrue($rejected, 'A rejected capture operation was accepted.');
    }

    private function profile(): array
    {
        return ['format' => 'wrb-synthetic-managed-connection-v1', 'endpoint' => 'http://127.0.0.1:1',
            'unitId' => str_repeat('a', 64), 'role' => 'writer', 'token' => 'synthetic-private-token',
            'expiresAt' => time() + 60, 'sourceId' => str_repeat('b', 64), 'generation' => 1];
    }

    private function connection(array $profile): string
    {
        $root = Fixture::temp();
        Fixture::write($root, 'connection.json', json_encode($profile, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        return $root . '/connection.json';
    }

    private function cipher(string $data): string
    {
        $root = Fixture::temp();
        Fixture::write($root, 'cipher', $data);
        return $root . '/cipher';
    }
}
