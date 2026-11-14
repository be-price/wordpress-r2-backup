<?php
declare(strict_types=1);

namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\{LocalRepository, UnitLedger};

final class UnitLedgerTest extends TestCase
{
    private int $now = 1000;
    private const UNIT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const SOURCE = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const JOB = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    private const NONCE = 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd';

    protected function tearDown(): void { Fixture::cleanup(); }

    public function testExplicitInitializationAndOpenNeverRepairMissingState(): void
    {
        $directory = Fixture::temp();
        $this->reject(fn () => UnitLedger::open($directory, self::UNIT));
        $ledger = $this->initialize($directory);
        self::assertSame(1, $ledger->status(self::SOURCE)['generation']);
        $this->reject(fn () => $this->initialize($directory));
        unlink($directory . '/ledger.json');
        $this->reject(fn () => UnitLedger::open($directory, self::UNIT));
        $this->reject(fn () => $this->initialize($directory));
    }

    public function testInitializationRefusesExistingBackendAndNonemptyControl(): void
    {
        $directory = Fixture::temp();
        $this->reject(fn () => UnitLedger::initialize($directory, self::UNIT, self::SOURCE, $this->cursor(), static fn () => true));
        $directory = Fixture::temp();
        Fixture::write($directory, 'unrelated', 'retained');
        $this->reject(fn () => $this->initialize($directory));
        self::assertSame('retained', file_get_contents($directory . '/unrelated'));
    }

    public function testOpenRejectsCorruptAndInvalidStateRatherThanResetting(): void
    {
        foreach (['invalid json', '{"version":1}', null] as $replacement) {
            $directory = Fixture::temp();
            $this->initialize($directory);
            if ($replacement === null) {
                $state = json_decode(file_get_contents($directory . '/ledger.json'), true, 512, JSON_THROW_ON_ERROR);
                $state['state']['head']['sequence'] = -1;
                $state['sha256'] = hash('sha256', json_encode($state['state'], JSON_THROW_ON_ERROR));
                $replacement = json_encode($state, JSON_THROW_ON_ERROR);
            }
            file_put_contents($directory . '/ledger.json', $replacement);
            $this->reject(fn () => UnitLedger::open($directory, self::UNIT));
        }
    }

    public function testWellFormedStateCorruptionAndLostLockStopWrites(): void
    {
        $directory = Fixture::temp(); $ledger = $this->initialize($directory);
        $state = json_decode(file_get_contents($directory . '/ledger.json'), true, 512, JSON_THROW_ON_ERROR);
        $state['state']['generation'] = 2;
        file_put_contents($directory . '/ledger.json', json_encode($state, JSON_THROW_ON_ERROR));
        $this->reject(fn () => UnitLedger::open($directory, self::UNIT));
        $directory = Fixture::temp(); $ledger = $this->initialize($directory);
        unlink($directory . '/ledger.lock');
        $this->reject(fn () => $this->acquire($ledger));
        self::assertFileDoesNotExist($directory . '/ledger.lock');
    }

    public function testStatusOnlyReconcilesExistingObjectsAndRejectsConflictingBytes(): void
    {
        [$ledger, $lease, $receipt, $path, $lookup] = $this->publication(); $id = str_repeat('e', 64);
        $this->reject(fn () => $ledger->publish(self::SOURCE, $this->session($lease), $id,
            ['kind' => 'object', 'receipt' => $receipt], static function (callable $guard): void {
                $guard(); throw new \RuntimeException('Synthetic interruption before publication.');
            }, $lookup));
        self::assertNull($ledger->status(self::SOURCE, $lookup)['publications'][$id]['result']);
        self::assertFileDoesNotExist($path);
        file_put_contents($path, 'conflicting ciphertext');
        $this->reject(fn () => $ledger->status(self::SOURCE, $lookup));
        self::assertSame('conflicting ciphertext', file_get_contents($path));
        self::assertNull($ledger->status(self::SOURCE)['publications'][$id]['result']);
    }

    public function testOnlySameLiveProcessCanRecoverAcquireAcknowledgement(): void
    {
        $directory = Fixture::temp(); $ledger = $this->initialize($directory);
        $first = $this->acquire($ledger);
        self::assertSame($first, $this->acquire($ledger));
        foreach ([['jobId', str_repeat('e', 64)], ['processNonce', str_repeat('e', 64)], ['source', str_repeat('e', 64)]] as [$field, $value]) {
            $this->reject(fn () => $this->acquire($ledger, [$field => $value]));
        }
        self::assertStringNotContainsString(self::NONCE, file_get_contents($directory . '/ledger.json'));
        self::assertArrayNotHasKey('processNonceHash', $ledger->status(self::SOURCE)['lease']);
    }

    public function testExpiryEqualityRejectsRenewAndOldFenceButAllowsSameJobNewFence(): void
    {
        $ledger = $this->initialize(Fixture::temp()); $first = $this->acquire($ledger);
        $this->now = $first['expiresAt'];
        $this->reject(fn () => $ledger->renew(self::SOURCE, $this->session($first), 30));
        $this->reject(fn () => $ledger->authorize(self::SOURCE, $this->session($first)));
        $second = $this->acquire($ledger, ['processNonce' => str_repeat('e', 64)]);
        self::assertGreaterThan($first['fence'], $second['fence']);
        $this->reject(fn () => $ledger->authorize(self::SOURCE, $this->session($first)));
        $this->reject(fn () => $this->acquire($ledger, ['jobId' => str_repeat('f', 64)]));
    }

    public function testRenewUsesServiceClockAndKeepsTheFence(): void
    {
        $ledger = $this->initialize(Fixture::temp()); $first = $this->acquire($ledger);
        $this->now += 10;
        $renewed = $ledger->renew(self::SOURCE, $this->session($first), 60);
        self::assertSame($first['fence'], $renewed['fence']);
        self::assertSame(1070, $renewed['expiresAt']);
    }

    public function testAdvanceAcceptsOnlyTheCurrentNextEventAndExactRetry(): void
    {
        $ledger = $this->initialize(Fixture::temp()); $lease = $this->acquire($ledger);
        $from = $this->cursor(); $to = $this->cursor(1, 'e'); $event = str_repeat('f', 64);
        self::assertSame($to, $ledger->advance(self::SOURCE, $this->session($lease), $event, $from, $to));
        self::assertSame($to, $ledger->advance(self::SOURCE, $this->session($lease), $event, $from, $to));
        foreach ([[$event, $from, $this->cursor(1, 'a')], [str_repeat('a', 64), $from, $to], [$event, $to, $from], [$event, $to, $this->cursor(3, 'b')]] as $args) {
            $this->reject(fn () => $ledger->advance(self::SOURCE, $this->session($lease, $to), ...$args));
        }
        $this->reject(fn () => $ledger->authorize(self::SOURCE, $this->session($lease)));
        self::assertSame($to, $ledger->authorize(self::SOURCE, $this->session($lease, $to))['cursor']);
    }

    public function testFrozenStateSurvivesReopenAndRejectsWriterOperations(): void
    {
        $directory = Fixture::temp(); $ledger = $this->initialize($directory); $lease = $this->acquire($ledger);
        $ledger->freeze(1);
        $reopened = UnitLedger::open($directory, self::UNIT, fn () => $this->now);
        self::assertTrue($reopened->status(self::SOURCE)['frozen']);
        self::assertNull($reopened->status(self::SOURCE)['lease']);
        $this->reject(fn () => $this->acquire($reopened));
        $this->reject(fn () => $reopened->authorize(self::SOURCE, $this->session($lease)));
        $this->reject(fn () => $reopened->freeze(2));
    }

    public function testPublicationPersistsIntentAndRecoversAfterProcessExitBeforeAcknowledgement(): void
    {
        $directory = Fixture::temp(); $ledger = $this->initialize($directory); $lease = $this->acquire($ledger);
        $path = Fixture::temp() . '/cipher.age'; $id = str_repeat('e', 64);
        $receipt = ['key' => 'files/' . str_repeat('a', 64) . '/' . str_repeat('b', 64) . '.age', 'bytes' => 10, 'sha256' => hash('sha256', 'ciphertext')];
        $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/ledger-process.php', $directory, self::UNIT, self::SOURCE, self::JOB, self::NONCE, 'publish-and-exit', $path],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fwrite($pipes[0], "go\n"); fclose($pipes[0]); stream_get_contents($pipes[1]); fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]); fclose($pipes[2]); self::assertSame(73, proc_close($process), $error);
        $this->now = $lease['expiresAt'];
        $lookup = static fn (array $descriptor): ?array => is_file($path) ? ['key' => $descriptor['receipt']['key'], 'bytes' => filesize($path), 'sha256' => hash_file('sha256', $path)] : null;
        $reopened = UnitLedger::open($directory, self::UNIT, fn () => $this->now);
        self::assertNull($reopened->status(self::SOURCE)['publications'][$id]['result']);
        self::assertSame($receipt, $reopened->status(self::SOURCE, $lookup)['publications'][$id]['result']);
        self::assertSame($receipt, $reopened->status(self::SOURCE, $lookup)['publications'][$id]['result']);
    }

    public function testPublicationGuardExpiresInsideLockAndCannotEscapeOperation(): void
    {
        [$ledger, $lease, $receipt, $path, $lookup] = $this->publication(); $escaped = null;
        $this->reject(function () use ($ledger, $lease, $receipt, $path, $lookup, &$escaped): void {
            $ledger->publish(self::SOURCE, $this->session($lease), str_repeat('e', 64), ['kind' => 'object', 'receipt' => $receipt], function (callable $guard) use ($lease, $path, &$escaped): array {
                $escaped = $guard; $this->now = $lease['expiresAt']; $guard(); file_put_contents($path, 'ciphertext'); return [];
            }, $lookup);
        });
        self::assertFileDoesNotExist($path);
        self::assertIsCallable($escaped);
        $this->reject($escaped);
        self::assertNull($ledger->status(self::SOURCE, $lookup)['publications'][str_repeat('e', 64)]['result']);
    }

    public function testPublicationRetryDoesNotDuplicateAndRejectsDifferentContent(): void
    {
        [$ledger, $lease, $receipt, $path, $lookup] = $this->publication(); $id = str_repeat('e', 64); $calls = 0;
        $operation = function (callable $guard) use ($path, $receipt, &$calls): array { $guard(); $calls++; file_put_contents($path, 'ciphertext'); return $receipt; };
        self::assertSame($receipt, $ledger->publish(self::SOURCE, $this->session($lease), $id, ['kind' => 'object', 'receipt' => $receipt], $operation, $lookup));
        self::assertSame($receipt, $ledger->publish(self::SOURCE, $this->session($lease), $id, ['kind' => 'object', 'receipt' => $receipt], $operation, $lookup));
        self::assertSame(1, $calls);
        $receipt['sha256'] = str_repeat('a', 64);
        $this->reject(fn () => $ledger->publish(self::SOURCE, $this->session($lease), $id, ['kind' => 'object', 'receipt' => $receipt], $operation, $lookup));
    }

    public function testCompletionUsesVersionOneRecordAndStatusRecoversLostAcknowledgement(): void
    {
        $root = Fixture::temp(); $writer = LocalRepository::forWriting($root, self::UNIT); $reader = LocalRepository::forRecovery($root, self::UNIT);
        $snapshot = str_repeat('e', 64); $manifest = Fixture::temp() . '/manifest.age'; file_put_contents($manifest, 'synthetic encrypted manifest');
        $manifestReceipt = $writer->putNew('manifests/' . $snapshot . '.age', $manifest);
        $record = ['snapshotId' => $snapshot, 'manifest' => $manifestReceipt];
        $descriptor = ['kind' => 'complete', 'snapshotId' => $snapshot, 'manifest' => $manifestReceipt];
        $ledger = $this->initialize(Fixture::temp()); $lease = $this->acquire($ledger);
        $lookup = static fn (array $descriptor): ?array => is_file($root . '/' . self::UNIT . '/commits/' . $descriptor['snapshotId'] . '.json') ? $reader->completed($descriptor['snapshotId']) : null;
        $this->reject(fn () => $ledger->publish(self::SOURCE, $this->session($lease), $snapshot, $descriptor, function (callable $guard) use ($writer, $snapshot, $manifestReceipt): void {
            $guard(); $writer->publish($snapshot, $manifestReceipt); throw new \RuntimeException('Lost completion acknowledgement.');
        }, $lookup));
        $status = $ledger->status(self::SOURCE, $lookup);
        self::assertSame($record, $reader->completed($snapshot));
        self::assertSame('completed', $status['activeJob']['status']);
        self::assertNull($status['lease']);
        self::assertSame($record, $status['publications'][$snapshot]['result']);
        $this->reject(fn () => $ledger->authorize(self::SOURCE, $this->session($lease)));
    }

    public function testCompletedPublicationRetriesOnlyReturnTheSameLiveProcessResult(): void
    {
        $directory = Fixture::temp(); $ledger = $this->initialize($directory); $lease = $this->acquire($ledger);
        $snapshot = str_repeat('e', 64); $path = Fixture::temp() . '/completion.json';
        $manifest = ['key' => 'manifests/' . $snapshot . '.age', 'bytes' => 10, 'sha256' => hash('sha256', 'ciphertext')];
        $descriptor = ['kind' => 'complete', 'snapshotId' => $snapshot, 'manifest' => $manifest];
        $record = ['snapshotId' => $snapshot, 'manifest' => $manifest];
        $lookup = static fn (array $intent): ?array => is_file($path) ? json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : null;
        $operation = static function (callable $guard) use ($path, $record): array { $guard(); file_put_contents($path, json_encode($record, JSON_THROW_ON_ERROR)); return $record; };
        self::assertSame($record, $ledger->publish(self::SOURCE, $this->session($lease), $snapshot, $descriptor, $operation, $lookup));
        $ledger = UnitLedger::open($directory, self::UNIT, fn () => $this->now);
        $calls = 0;
        $mustNotPublish = static function (callable $guard) use (&$calls): void { $calls++; throw new \RuntimeException('A completed retry must never publish.'); };
        self::assertSame($record, $ledger->publish(self::SOURCE, $this->session($lease), $snapshot, $descriptor, $mustNotPublish, $lookup));
        self::assertSame(0, $calls);
        foreach (['generation' => 2, 'fence' => $lease['fence'] + 1, 'jobId' => str_repeat('f', 64), 'processNonce' => str_repeat('f', 64), 'cursor' => $this->cursor(1, 'f')] as $field => $value) {
            $session = $this->session($lease); $session[$field] = $value;
            $this->reject(fn () => $ledger->publish(self::SOURCE, $session, $snapshot, $descriptor, $mustNotPublish, $lookup));
        }
        $changed = $descriptor; $changed['manifest']['sha256'] = str_repeat('f', 64);
        $this->reject(fn () => $ledger->publish(self::SOURCE, $this->session($lease), $snapshot, $changed, $mustNotPublish, $lookup));
        $this->reject(fn () => $ledger->publish(self::SOURCE, $this->session($lease), str_repeat('f', 64), $descriptor, $mustNotPublish, $lookup));
        $this->reject(fn () => $ledger->publish(str_repeat('f', 64), $this->session($lease), $snapshot, $descriptor, $mustNotPublish, $lookup));
        self::assertSame(0, $calls);
        self::assertStringNotContainsString('processNonceHash', json_encode($ledger->status(self::SOURCE), JSON_THROW_ON_ERROR));
        $this->now = $lease['expiresAt'];
        $this->reject(fn () => $ledger->publish(self::SOURCE, $this->session($lease), $snapshot, $descriptor, $mustNotPublish, $lookup));
        self::assertSame($record, $ledger->status(self::SOURCE, $lookup)['publications'][$snapshot]['result']);
        $this->now--;
        $ledger->freeze(1);
        $this->reject(fn () => $ledger->publish(self::SOURCE, $this->session($lease), $snapshot, $descriptor, $mustNotPublish, $lookup));
        self::assertSame($record, $ledger->status(self::SOURCE, $lookup)['publications'][$snapshot]['result']);
        self::assertSame(0, $calls);
    }

    public function testDifferentProcessesCannotAcquireTheSameLiveUnit(): void
    {
        $directory = Fixture::temp(); $this->initialize($directory);
        $processes = [];
        foreach (['d', 'e'] as $nonce) {
            $processes[] = [$process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/ledger-process.php', $directory, self::UNIT, self::SOURCE, self::JOB, str_repeat($nonce, 64)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes), $pipes];
        }
        foreach ($processes as [, $pipes]) { fwrite($pipes[0], "go\n"); fclose($pipes[0]); }
        $outcomes = [];
        foreach ($processes as [$process, $pipes]) {
            $outcomes[] = trim(stream_get_contents($pipes[1])); fclose($pipes[1]);
            $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $error);
        }
        sort($outcomes);
        self::assertSame(['acquired', 'rejected'], $outcomes);
        self::assertSame(1, UnitLedger::open($directory, self::UNIT)->status(self::SOURCE)['fence']);
    }

    private function initialize(string $directory): UnitLedger
    {
        return UnitLedger::initialize($directory, self::UNIT, self::SOURCE, $this->cursor(), static fn () => false, fn () => $this->now);
    }

    private function acquire(UnitLedger $ledger, array $overrides = []): array
    {
        return $ledger->acquire($overrides['source'] ?? self::SOURCE, 1, $overrides['jobId'] ?? self::JOB, $overrides['processNonce'] ?? self::NONCE, 30, $this->cursor());
    }

    private function session(array $lease, ?array $cursor = null): array
    {
        return ['generation' => $lease['generation'], 'jobId' => $lease['jobId'], 'processNonce' => self::NONCE, 'fence' => $lease['fence'], 'cursor' => $cursor ?? $lease['cursor']];
    }

    private function cursor(int $sequence = 0, string $commitment = 'b'): array
    {
        return ['indexId' => str_repeat('a', 64), 'sequence' => $sequence, 'commitment' => str_repeat($commitment, 64)];
    }

    private function publication(): array
    {
        $ledger = $this->initialize(Fixture::temp()); $lease = $this->acquire($ledger); $path = Fixture::temp() . '/cipher.age';
        $receipt = ['key' => 'files/' . str_repeat('a', 64) . '/' . str_repeat('b', 64) . '.age', 'bytes' => 10, 'sha256' => hash('sha256', 'ciphertext')];
        $lookup = static fn (array $descriptor): ?array => is_file($path) ? ['key' => $descriptor['receipt']['key'], 'bytes' => filesize($path), 'sha256' => hash_file('sha256', $path)] : null;
        return [$ledger, $lease, $receipt, $path, $lookup];
    }

    private function reject(callable $operation): void
    {
        try { $operation(); }
        catch (\RuntimeException|\InvalidArgumentException $ignoredError) { self::assertTrue(true); return; }
        self::fail('Operation must be rejected.');
    }
}
