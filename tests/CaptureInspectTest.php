<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\{AgeProcess, LocalRepository, Manifest, ObjectReader, Restorer};

final class CaptureInspectTest extends TestCase
{
    protected function tearDown(): void { Fixture::cleanup(); }

    public function testInspectKeepsV1EvidenceUnknownAndDoesNotDecryptPayloads(): void
    {
        [$restorer, $reader, $snapshot, $keys] = $this->snapshot(1);
        foreach ($keys as $key) {
            $summary = $this->inspect($restorer, $snapshot, $key['identity']);
            self::assertSame(1, $summary['manifestVersion']);
            self::assertSame('unknown', $summary['captureEvidence']);
            self::assertNull($summary['capture']);
            self::assertSame(['manifest' => true, 'referencedCiphertexts' => true, 'payloadDecrypted' => false, 'databaseImported' => false], $summary['verification']);
        }
        self::assertCount(2, $reader->downloaded);
        foreach ($reader->downloaded as $key) { self::assertStringStartsWith('manifests/', $key); }
    }

    public function testV2SummaryAndPreRestoreCallbackDoNotRevealPathsOrRawEvidence(): void
    {
        [$restorer, $reader, $snapshot, $keys, $repository] = $this->snapshot(2);
        $summary = $this->inspect($restorer, $snapshot, $keys[0]['identity']);
        self::assertSame(2, $summary['manifestVersion']);
        self::assertSame('reported', $summary['captureEvidence']);
        self::assertSame('2026-10-19T00:00:03.000000Z', $summary['capture']['fixedAt']);
        self::assertSame('8.0.35', $summary['capture']['database']['serverVersion']);
        self::assertSame(['InnoDB', 'MyISAM'], $summary['capture']['database']['storageEngines']);
        self::assertSame(2, $summary['capture']['database']['tableCount']);
        self::assertSame(0, $summary['capture']['database']['definitionCount']);
        foreach (['secret-relative-path', 'private_table', 'sqlHash', 'inventorySha256', 'AGE-SECRET-KEY', 'capturedAt'] as $secret) {
            self::assertStringNotContainsString($secret, json_encode($summary, JSON_THROW_ON_ERROR));
        }
        $destination = Fixture::temp() . '/restored';
        $reader->downloaded = [];
        $called = 0;
        $identity = fopen($keys[1]['identity'], 'rb');
        try {
            $result = $restorer->restore($snapshot, $identity, $destination, null, null,
                static function (array $before) use (&$called, $summary, $reader, $destination): void {
                    $called++;
                    self::assertSame($summary, $before);
                    self::assertDirectoryDoesNotExist($destination);
                    self::assertCount(1, $reader->downloaded);
                    self::assertStringStartsWith('manifests/', $reader->downloaded[0]);
                });
        } finally { fclose($identity); }
        self::assertSame(1, $called);
        self::assertSame(['files' => 1, 'bytes' => 7, 'databaseImported' => false], $result);
        self::assertSame('payload', file_get_contents($destination . '/secret-relative-path'));
        $tools = dirname(__DIR__) . '/recovery/recover.php';
        $command = [PHP_BINARY, $tools, 'inspect', '--repository', $repository, '--unit', str_repeat('a', 64), '--age', Fixture::runtime()['age'], '--snapshot', $snapshot];
        [$exit, $out, $error] = $this->command($command, $keys[1]['identity']);
        self::assertSame(0, $exit, $error);
        self::assertSame('', $error);
        self::assertSame($summary, json_decode($out, true, 32, JSON_THROW_ON_ERROR));
        $command[2] = 'restore';
        [$exit, $out, $error] = $this->command([...$command, '--destination', Fixture::temp() . '/cli-restored'], $keys[0]['identity']);
        self::assertSame(0, $exit, $error);
        self::assertSame($result, json_decode($out, true, 32, JSON_THROW_ON_ERROR));
        self::assertSame($summary, json_decode(strtok($error, "\n"), true, 32, JSON_THROW_ON_ERROR));
        self::assertStringContainsString('DB未取込', $error);
    }

    public function testInspectRejectsMissingCiphertext(): void
    {
        [$restorer, $reader, $snapshot, $keys, $repository, $fileKey] = $this->snapshot(1);
        unlink(LocalRepository::forRecovery($repository, str_repeat('a', 64))->objectPath($fileKey));
        $this->expectException(\RuntimeException::class);
        $this->inspect($restorer, $snapshot, $keys[0]['identity']);
    }

    private function inspect(Restorer $restorer, string $snapshot, string $identityFile): array
    {
        $identity = fopen($identityFile, 'rb');
        try { return $restorer->inspect($snapshot, $identity); }
        finally { fclose($identity); }
    }

    private function command(array $command, string $identity): array
    {
        $process = proc_open($command, [0 => ['file', $identity, 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, []);
        self::assertIsResource($process);
        stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        $out = ''; $error = ''; $deadline = microtime(true) + 60;
        try {
            do {
                $out .= stream_get_contents($pipes[1]); $error .= stream_get_contents($pipes[2]);
                $status = proc_get_status($process);
                if (!$status['running']) { break; }
                if (microtime(true) > $deadline) { throw new \RuntimeException('Recovery CLI fixture timed out.'); }
                usleep(10000);
            } while (true);
            $out .= stream_get_contents($pipes[1]); $error .= stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]); $pipes = [];
            $exit = proc_close($process); $process = null;
            return [$status['exitcode'] >= 0 ? $status['exitcode'] : $exit, $out, $error];
        } finally {
            if (is_resource($process)) { proc_terminate($process); foreach ($pipes as $pipe) { fclose($pipe); } proc_close($process); }
        }
    }

    private function snapshot(int $version): array
    {
        $root = Fixture::temp(); $keys = Fixture::keys(); $recipients = array_column($keys, 'public');
        $age = new AgeProcess(Fixture::runtime()['age']); $repository = Fixture::temp();
        $writer = LocalRepository::forWriting($repository, str_repeat('a', 64));
        $snapshot = bin2hex(random_bytes(32)); $keySet = Manifest::keySetId($recipients);
        $fileKey = 'files/' . $keySet . '/' . bin2hex(random_bytes(32)) . '.age';
        Fixture::write($root, 'file', 'payload');
        $age->encrypt($root . '/file', $root . '/file.age', $recipients);
        $file = ['path' => 'secret-relative-path', 'type' => 'file', 'size' => 7, 'sha256' => hash('sha256', 'payload'), 'object' => $writer->putNew($fileKey, $root . '/file.age')];
        Fixture::write($root, 'database', 'SELECT 1;');
        $age->encrypt($root . '/database', $root . '/database.age', $recipients);
        $database = ['size' => 9, 'sha256' => hash('sha256', 'SELECT 1;'), 'object' => $writer->putNew('databases/' . $snapshot . '.age', $root . '/database.age')];
        $manifest = ['format' => 'wordpress-r2-backup', 'version' => $version, 'dataClass' => 'synthetic', 'unitId' => str_repeat('a', 64), 'snapshotId' => $snapshot,
            'keySetId' => $keySet, 'recipients' => $recipients, 'capturedAt' => '2026-10-19T00:00:00Z', 'files' => [$file], 'database' => $database];
        if ($version === 2) {
            $tables = [['name' => 'private_table_a', 'type' => 'BASE TABLE', 'engine' => 'InnoDB'], ['name' => 'private_table_b', 'type' => 'BASE TABLE', 'engine' => 'MyISAM']];
            $definitions = ['views' => [], 'triggers' => [], 'routines' => [], 'events' => []];
            $inventoryFile = $file; unset($inventoryFile['object']);
            $encode = static fn (array $value): string => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $manifest['captureReport'] = ['method' => 'synthetic-files-mysql-global-read-lock-v1', 'startedAt' => '2026-10-19T00:00:00.000000Z',
                'fixedAt' => '2026-10-19T00:00:03.000000Z', 'sealedAt' => '2026-10-19T00:00:06.000000Z',
                'file' => ['pausedAt' => '2026-10-19T00:00:01.000000Z', 'releasedAt' => '2026-10-19T00:00:05.000000Z', 'inventorySha256' => hash('sha256', $encode([$inventoryFile]))],
                'database' => ['lockedAt' => '2026-10-19T00:00:02.000000Z', 'releasedAt' => '2026-10-19T00:00:04.000000Z', 'serverVersion' => '8.0.35',
                    'tables' => $tables, 'definitions' => $definitions, 'inventorySha256' => hash('sha256', $encode(['tables' => $tables, 'definitions' => $definitions])), 'sqlHash' => $database['sha256'], 'sqlBytes' => $database['size']]];
        }
        Fixture::write($root, 'manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $age->encrypt($root . '/manifest.json', $root . '/manifest.age', $recipients);
        $writer->publish($snapshot, $writer->putNew('manifests/' . $snapshot . '.age', $root . '/manifest.age'));
        $reader = new class(LocalRepository::forRecovery($repository, str_repeat('a', 64))) implements ObjectReader {
            public array $downloaded = [];
                private LocalRepository $reader;

    public function __construct(LocalRepository $reader)
    {
        $this->reader = $reader;
    }
            public function unitId(): string { return $this->reader->unitId(); }
            public function verify(array $receipt): void { $this->reader->verify($receipt); }
            public function download(array $receipt, string $newCipherFile): void { $this->downloaded[] = $receipt['key']; $this->reader->download($receipt, $newCipherFile); }
            public function completed(string $snapshotId): array { return $this->reader->completed($snapshotId); }
            public function listCompleted(): array { return $this->reader->listCompleted(); }
            public function listStatus(): array { return $this->reader->listStatus(); }
            public function listFileObjects(string $keySetId): array { return $this->reader->listFileObjects($keySetId); }
        };
        return [new Restorer($age, $reader), $reader, $snapshot, $keys, $repository, $fileKey];
    }
}
