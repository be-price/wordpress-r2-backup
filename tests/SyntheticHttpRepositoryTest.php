<?php
declare(strict_types=1);

namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use WordPressR2Backup\SyntheticHttpRepository;

final class SyntheticHttpRepositoryTest extends TestCase
{
    private ?IsolatedObjectServer $server = null;

    protected function tearDown(): void
    {
        if ($this->server !== null) { $this->server->stop(); }
        Fixture::cleanup();
    }

    public static function invalidProfiles(): array
    {
        return [
            ['endpoint', 'https://127.0.0.1:1234'], ['endpoint', 'http://localhost:1234'],
            ['endpoint', 'http://127.0.0.2:1234'], ['endpoint', 'http://example.com:1234'],
            ['endpoint', 'http://127.0.0.1:1234/'], ['endpoint', 'http://127.0.0.1:1234?x=1'],
            ['endpoint', 'http://user@127.0.0.1:1234'], ['endpoint', 'http://127.0.0.1:1234#x'],
            ['endpoint', 'http://127.0.0.1:65536'], ['endpoint', 'http://127.0.0.1:0'],
            ['endpoint', 'http://127.0.0.1:01234'], ['format', 'production'], ['role', 'admin'],
            ['unitId', str_repeat('A', 64)], ['expiresAt', 1], ['expiresAt', '9999999999'],
            ['token', "secret\r\nInjected: value"], ['unknown', true],
        ];
    }

    /** @dataProvider invalidProfiles */
    public function testInvalidConnectionIsRejectedBeforeAnyRequest(string $field, $value): void
    {
        $profile = $this->profile();
        $profile[$field] = $value;
        $path = $this->connection($profile);
        $this->expectException(RuntimeException::class);
        new SyntheticHttpRepository($path);
    }

    public function testRejectsInsecureLinkedOversizedAndMalformedConnectionFiles(): void
    {
        $path = $this->connection($this->profile());
        chmod($path, 0644);
        $this->rejects(fn () => new SyntheticHttpRepository($path));
        chmod($path, 0600);
        symlink($path, dirname($path) . '/link');
        $this->rejects(fn () => new SyntheticHttpRepository(dirname($path) . '/link'));
        file_put_contents($path, str_repeat('x', 8193));
        $this->rejects(fn () => new SyntheticHttpRepository($path));
        file_put_contents($path, 'false');
        $this->rejects(fn () => new SyntheticHttpRepository($path));
        file_put_contents($path, '{');
        $this->rejects(fn () => new SyntheticHttpRepository($path));
    }

    public function testPutDownloadAndCompletionUseActualLoopbackHttp(): void
    {
        [$writer, $reader, $root] = $this->repositories();
        self::assertFalse($writer->hasObjects());
        $snapshot = str_repeat('b', 64);
        $receipt = $writer->putNew('manifests/' . $snapshot . '.age', $this->cipher('synthetic encrypted manifest'));
        self::assertTrue($writer->hasObjects());
        $writer->verify($receipt);
        $reader->verify($receipt);
        $writer->publish($snapshot, $receipt);
        $writer->publish($snapshot, $receipt);
        $record = ['snapshotId' => $snapshot, 'manifest' => $receipt];
        self::assertSame($record, $reader->completed($snapshot));
        self::assertSame([$record], $reader->listCompleted());
        self::assertSame([$record + ['valid' => true]], $reader->listStatus());
        $output = Fixture::temp() . '/cipher';
        $reader->download($receipt, $output);
        self::assertSame('synthetic encrypted manifest', file_get_contents($output));
        self::assertSame(0600, fileperms($output) & 0777);
        self::assertSame($receipt, $writer->putNew($receipt['key'], $this->cipher('synthetic encrypted manifest')));
        $this->rejects(fn () => $writer->putNew($receipt['key'], $this->cipher('different ciphertext')));
        self::assertSame('synthetic encrypted manifest', file_get_contents($root . '/' . $writer->unitId() . '/' . $receipt['key']));
        $keySet = str_repeat('c', 64);
        $file = $writer->putNew('files/' . $keySet . '/' . str_repeat('d', 64) . '.age', $this->cipher('file ciphertext'));
        self::assertSame([$file], $reader->listFileObjects($keySet));
        self::assertSame([], $reader->listFileObjects(str_repeat('e', 64)));
    }

    public function testRolesAndExistingDownloadDestinationAreProtected(): void
    {
        [$writer, $reader] = $this->repositories();
        $receipt = $writer->putNew('databases/' . str_repeat('b', 64) . '.age', $this->cipher('ciphertext'));
        $output = $this->cipher('existing output');
        $this->rejects(fn () => $reader->download($receipt, $output));
        self::assertSame('existing output', file_get_contents($output));
        $this->rejects(fn () => $writer->download($receipt, Fixture::temp() . '/new'));
        $this->rejects(fn () => $reader->putNew($receipt['key'], $output));
        $this->rejects(fn () => $reader->publish(str_repeat('b', 64), $receipt));
        $this->rejects(fn () => $reader->hasObjects());
        $this->rejects(fn () => $writer->listStatus());
    }

    public function testServerRejectsChangedUnitRoleAndCredentialWithoutLeakingToken(): void
    {
        [$writer] = $this->repositories();
        $receipt = $writer->putNew('databases/' . str_repeat('b', 64) . '.age', $this->cipher('ciphertext'));
        $profile = json_decode(file_get_contents($this->server->connectionFile('writer')), true, 8, JSON_THROW_ON_ERROR);
        $wrongUnit = $profile;
        $wrongUnit['unitId'] = str_repeat('f', 64);
        $this->rejects(fn () => (new SyntheticHttpRepository($this->connection($wrongUnit)))->verify($receipt));
        $wrongRole = $profile;
        $wrongRole['role'] = 'reader';
        $this->rejects(fn () => (new SyntheticHttpRepository($this->connection($wrongRole)))->listStatus());
        $wrongCredential = $profile;
        $wrongCredential['token'] = str_repeat('0', 64);
        $rejected = false;
        try {
            (new SyntheticHttpRepository($this->connection($wrongCredential)))->verify($receipt);
        } catch (RuntimeException $error) {
            $rejected = true;
            self::assertStringNotContainsString($wrongCredential['token'], $error->getMessage());
            self::assertStringNotContainsString($profile['token'], $error->getMessage());
        }
        self::assertTrue($rejected, 'An invalid credential was accepted.');
    }

    public function testEnvironmentProxyDoesNotChangeExplicitLoopbackConnection(): void
    {
        [$writer, $reader] = $this->repositories();
        $original = [];
        foreach (['http_proxy', 'HTTP_PROXY', 'all_proxy', 'ALL_PROXY', 'no_proxy', 'NO_PROXY'] as $name) {
            $original[$name] = getenv($name);
            putenv($name . '=' . (str_contains(strtolower($name), 'no_proxy') ? '' : 'http://127.0.0.1:1'));
        }
        try {
            $receipt = $writer->putNew('databases/' . str_repeat('b', 64) . '.age', $this->cipher('synthetic over loopback'));
            $output = Fixture::temp() . '/output';
            $reader->download($receipt, $output);
            self::assertSame('synthetic over loopback', file_get_contents($output));
        } finally {
            foreach ($original as $name => $value) {
                putenv($value === false ? $name : $name . '=' . $value);
            }
        }
    }

    public function testLostPutResponseReusesVerifiedObject(): void
    {
        [$writer, , $root] = $this->repositories();
        $this->server->fault('drop-put-response-once');
        $receipt = $writer->putNew('databases/' . str_repeat('b', 64) . '.age', $this->cipher('ciphertext after lost reply'));
        $writer->verify($receipt);
        $files = glob($root . '/' . $writer->unitId() . '/databases/*.age');
        self::assertCount(1, $files);
        self::assertSame('ciphertext after lost reply', file_get_contents($files[0]));
    }

    public function testConcurrentDownloadDestinationIsNeverReplaced(): void
    {
        [$writer, $reader] = $this->repositories();
        $receipt = $writer->putNew('databases/' . str_repeat('b', 64) . '.age', $this->cipher(str_repeat('ciphertext', 838861)));
        $directory = Fixture::temp();
        $destination = $directory . '/output';
        $code = <<<'PHP'
            echo "ready\n";
            flush();
            $deadline = microtime(true) + 5;
            do {
                if (glob($argv[1] . '/.download-*') !== []) {
                    $output = fopen($argv[2], 'x+b');
                    if ($output === false) { exit(2); }
                    chmod($argv[2], 0600);
                    fwrite($output, 'concurrent owner');
                    fclose($output);
                    exit(0);
                }
                usleep(100);
            } while (microtime(true) < $deadline);
            exit(3);
            PHP;
        $process = proc_open([PHP_BINARY, '-r', $code, $directory, $destination],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        try {
            stream_set_timeout($pipes[1], 5);
            self::assertSame("ready\n", fgets($pipes[1]));
            $this->rejects(fn () => $reader->download($receipt, $destination));
            self::assertSame('', stream_get_contents($pipes[2]));
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_get_status($process);
            if ($status['running']) { proc_terminate($process); }
            $exitCode = proc_close($process);
            if ($exitCode === -1 && !$status['running']) { $exitCode = $status['exitcode']; }
        }
        self::assertSame(0, $exitCode);
        self::assertSame('concurrent owner', file_get_contents($destination));
        self::assertSame(['.', '..', 'output'], scandir($directory));
    }

    public static function damagedDownloads(): array
    {
        return [['truncate-get-once'], ['corrupt-get-once']];
    }

    /** @dataProvider damagedDownloads */
    public function testInterruptedOrChangedDownloadNeverPublishes(string $fault): void
    {
        [$writer, $reader] = $this->repositories();
        $receipt = $writer->putNew('databases/' . str_repeat('b', 64) . '.age', $this->cipher(str_repeat('encrypted synthetic content', 5000)));
        $directory = Fixture::temp();
        $this->server->fault($fault);
        $this->rejects(fn () => $reader->download($receipt, $directory . '/output'));
        self::assertSame(['.', '..'], scandir($directory));
    }

    public function testCiphertextOver64MiBIsRejectedBeforeRequest(): void
    {
        $repository = new SyntheticHttpRepository($this->connection($this->profile()));
        $file = $this->cipher('x');
        $handle = fopen($file, 'r+b');
        ftruncate($handle, 67108865);
        fclose($handle);
        $rejected = false;
        try {
            $repository->putNew('databases/' . str_repeat('b', 64) . '.age', $file);
        } catch (RuntimeException $error) {
            $rejected = true;
            self::assertStringContainsString('64 MiB', $error->getMessage());
        }
        self::assertTrue($rejected, 'Oversized ciphertext was accepted.');
    }

    public function testCorruptOtherSnapshotDoesNotPreventSelectedSnapshot(): void
    {
        [$writer, $reader, $root] = $this->repositories();
        foreach ([str_repeat('b', 64), str_repeat('c', 64)] as $snapshot) {
            $receipt = $writer->putNew('manifests/' . $snapshot . '.age', $this->cipher('manifest'));
            $writer->publish($snapshot, $receipt);
        }
        unlink($root . '/' . $writer->unitId() . '/manifests/' . str_repeat('c', 64) . '.age');
        self::assertSame(str_repeat('b', 64), $reader->completed(str_repeat('b', 64))['snapshotId']);
        $statuses = $reader->listStatus();
        self::assertSame([true, false], array_column($statuses, 'valid'));
        self::assertSame('Snapshot verification failed.', $statuses[1]['error']);
        $this->rejects(fn () => $reader->listCompleted());
    }

    private function repositories(): array
    {
        $root = Fixture::temp();
        $this->server = IsolatedObjectServer::start($root, str_repeat('a', 64));
        return [new SyntheticHttpRepository($this->server->connectionFile('writer')),
            new SyntheticHttpRepository($this->server->connectionFile('reader')), $root];
    }

    private function profile(): array
    {
        return ['format' => 'wrb-synthetic-connection-v1', 'endpoint' => 'http://127.0.0.1:1',
            'unitId' => str_repeat('a', 64), 'role' => 'writer', 'token' => 'synthetic-private-token', 'expiresAt' => time() + 60];
    }

    private function connection(array $profile): string
    {
        $root = Fixture::temp();
        Fixture::write($root, 'connection.json', json_encode($profile, JSON_THROW_ON_ERROR));
        return $root . '/connection.json';
    }

    private function cipher(string $data): string
    {
        $root = Fixture::temp();
        Fixture::write($root, 'cipher', $data);
        return $root . '/cipher';
    }

    private function rejects(callable $action): void
    {
        $rejected = false;
        try {
            $action();
        } catch (RuntimeException $error) {
            $rejected = true;
            self::assertNotSame('', $error->getMessage());
            self::assertStringNotContainsString('synthetic-private-token', $error->getMessage());
        }
        self::assertTrue($rejected, 'Unsafe operation was accepted.');
    }
}
