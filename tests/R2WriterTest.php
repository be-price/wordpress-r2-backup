<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use WordPressR2Backup\SyntheticHttpRepository;

final class R2WriterTest extends TestCase
{
    private $server = null;
    private string $root;
    private string $endpoint;

    protected function setUp(): void
    {
        $this->root = Fixture::temp();
        $socket = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        $this->endpoint = 'http://127.0.0.1:' . $port;
        $this->server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/fixtures/r2-writer-http.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->root . '/server.log', 'a'], 2 => ['file', $this->root . '/server.log', 'a']], $pipes,
            null, ['WRB_WRITER_FIXTURE_ROOT' => $this->root]);
        for ($i = 0; $i < 100; $i++) {
            $ready = @fsockopen('127.0.0.1', $port);
            if ($ready) { fclose($ready); return; }
            usleep(10000);
        }
        self::fail('The dedicated HTTP boundary fixture did not start.');
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) { proc_terminate($this->server); proc_close($this->server); }
        Fixture::cleanup();
    }

    public function testLocalR2ManagedAndAdministratorProfilesPreserveBinding(): void
    {
        $writer = $this->writer();
        self::assertTrue($writer->managed());
        self::assertSame(['sourceId' => str_repeat('b', 64), 'generation' => 1], $writer->sourceBinding());
        $profile = $this->profile();
        unset($profile['sourceId'], $profile['generation']);
        $profile['format'] = 'wrb-local-r2-administrator-v1';
        $profile['role'] = 'admin';
        $admin = new SyntheticHttpRepository($this->connection($profile));
        self::assertSame('wrb-local-r2-managed-connection-v1', $admin->newWriterProfile(str_repeat('c', 64), 2)['format']);
    }

    public function testLargeFixedCiphertextStreamsPartsAndRecoversLostReplyAndPendingRegistration(): void
    {
        file_put_contents($this->root . '/mode', 'lost-pending');
        $writer = $this->writer();
        $this->acquire($writer);
        $cipher = $this->cipher(67108865);
        $start = memory_get_peak_usage(true);
        $receipt = $writer->putNew('databases/' . str_repeat('c', 64) . '.age', $cipher);
        self::assertSame(67108865, $receipt['bytes']);
        self::assertSame(hash_file('sha256', $cipher), $receipt['sha256']);
        self::assertLessThan(16777216, memory_get_peak_usage(true) - $start);
        $state = json_decode(file_get_contents($this->root . '/state.json'), true);
        self::assertCount(5, $state['parts']);
        self::assertSame([16777216, 16777216, 16777216, 16777216, 1], array_column($state['parts'], 'bytes'));
        self::assertSame(6, $state['puts']);
        self::assertSame(2, $state['registers']);
        self::assertGreaterThanOrEqual(10, $state['renews']);
        self::assertSame([120], array_values(array_unique($state['ttls'])));
        self::assertSame($receipt, $writer->putNew($receipt['key'], $cipher));
    }

    public static function corruptGrants(): array
    {
        return array_map(static fn ($mode) => [$mode], ['absolute', 'traversal', 'wrong-path', 'extra', 'headers', 'hash', 'bytes', 'expiry', 'plan-key', 'plan-extra', 'pending-forever', 'mutate-file']);
    }

    /** @dataProvider corruptGrants */
    public function testUntrustedGrantOrPlanNeverReportsSuccessfulUpload(string $mode): void
    {
        file_put_contents($this->root . '/mode', $mode);
        $writer = $this->writer();
        $this->acquire($writer);
        $cipher = $this->cipher(64);
        file_put_contents($this->root . '/cipher-path', $cipher);
        try {
            $writer->putNew('databases/' . str_repeat('c', 64) . '.age', $cipher);
            self::fail('Untrusted response was accepted.');
        } catch (RuntimeException $ignoredError) {
            $state = json_decode(file_get_contents($this->root . '/state.json'), true);
            self::assertSame($mode === 'pending-forever' ? 1 : 0, $state['puts']);
            if ($mode === 'pending-forever') { self::assertSame(3, $state['registers']); }
        }
    }

    public static function rejectedProfiles(): array
    {
        return [['endpoint', 'http://example.com:1234'], ['endpoint', 'http://127.0.0.1:1234/path'],
            ['role', 'reader'], ['sourceId', str_repeat('A', 64)], ['generation', 0], ['generation', '1'], ['extra', true]];
    }

    /** @dataProvider rejectedProfiles */
    public function testR2ProfileRemainsStrict(string $field, $value): void
    {
        $profile = $this->profile(); $profile[$field] = $value;
        $this->expectException(RuntimeException::class);
        new SyntheticHttpRepository($this->connection($profile));
    }

    public function testExpiredPutPermissionIsRetriedWithSamePartAndResponsesMayReorderFields(): void
    {
        file_put_contents($this->root . '/mode', 'expired-put-once');
        $writer = $this->writer(); $this->acquire($writer);
        $receipt = $writer->putNew('databases/' . str_repeat('c', 64) . '.age', $this->cipher(10));
        self::assertSame(10, $receipt['bytes']);
        $state = json_decode(file_get_contents($this->root . '/state.json'), true);
        self::assertSame(2, $state['puts']);
    }

    public function testPartFailureStopsAfterThreeAttemptsWithoutRegistration(): void
    {
        file_put_contents($this->root . '/mode', 'part-unavailable');
        $writer = $this->writer(); $this->acquire($writer);
        try {
            $writer->putNew('databases/' . str_repeat('c', 64) . '.age', $this->cipher(10));
            self::fail('Unconfirmed part was registered.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('three attempts', $error->getMessage());
        }
        $state = json_decode(file_get_contents($this->root . '/state.json'), true);
        self::assertSame(3, $state['puts']);
        self::assertSame(0, $state['registers']);
    }

    public function testOver128MiBIsRejectedWithoutUploadPlan(): void
    {
        $writer = $this->writer();
        $this->acquire($writer);
        try { $writer->putNew('databases/' . str_repeat('c', 64) . '.age', $this->cipher(134217729)); self::fail('Oversized input accepted.'); }
        catch (RuntimeException $error) { self::assertStringContainsString('128 MiB', $error->getMessage()); }
        self::assertFileDoesNotExist($this->root . '/state.json');
    }

    public function testCompletionReplyLossRetriesTheSameSessionAfterTheLeaseIsCompleted(): void
    {
        file_put_contents($this->root . '/mode', 'lost-complete');
        $snapshot = str_repeat('c', 64);
        $receipt = ['key' => 'manifests/' . $snapshot . '.age', 'bytes' => 9, 'sha256' => hash('sha256', 'encrypted')];
        file_put_contents($this->root . '/state.json', json_encode(['puts' => 0, 'registers' => 0, 'renews' => 0,
            'ttls' => [], 'receipt' => $receipt, 'completes' => 0]));
        $writer = $this->writer(); $this->acquire($writer);
        $writer->publish($snapshot, $receipt);
        $state = json_decode(file_get_contents($this->root . '/state.json'), true);
        self::assertSame(2, $state['completes']);
        self::assertSame(1, $state['renews']);
    }

    private function writer(): SyntheticHttpRepository { return new SyntheticHttpRepository($this->connection($this->profile())); }
    private function profile(): array
    {
        return ['format' => 'wrb-local-r2-managed-connection-v1', 'endpoint' => $this->endpoint,
            'unitId' => str_repeat('a', 64), 'sourceId' => str_repeat('b', 64), 'generation' => 1,
            'role' => 'writer', 'token' => 'primary-private-token', 'expiresAt' => time() + 300];
    }
    private function connection(array $profile): string
    {
        $path = $this->root . '/connection-' . bin2hex(random_bytes(4));
        file_put_contents($path, json_encode($profile)); chmod($path, 0600); return $path;
    }
    private function acquire(SyntheticHttpRepository $writer): void
    {
        $writer->acquire(str_repeat('d', 64), str_repeat('e', 64), ['indexId' => str_repeat('f', 64), 'sequence' => 0, 'commitment' => str_repeat('0', 64)], 120);
    }
    private function cipher(int $bytes): string
    {
        $path = $this->root . '/cipher'; $file = fopen($path, 'w+b'); ftruncate($file, $bytes); fclose($file); chmod($path, 0600); return $path;
    }
}
