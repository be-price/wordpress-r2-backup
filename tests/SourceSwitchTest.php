<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;

use RuntimeException;
use WordPressR2Backup\{AgeProcess, ManagedCapture, Manifest, SourceRebuilder, SyntheticHttpRepository, UnitLedger};

final class SourceSwitchTest extends TestCase
{
    private ?IsolatedObjectServer $server = null;
    protected function tearDown(): void { if ($this->server !== null) { $this->server->stop(); } Fixture::cleanup(); }

    public function testFreezeBarrierAndActivationArePersistentAndBoundToTheInventory(): void
    {
        $now = 1000; $unit = str_repeat('a', 64); $source = str_repeat('b', 64); $root = Fixture::temp();
        $old = $this->cursor('1'); $new = $this->cursor('2');
        $ledger = UnitLedger::initialize($root, $unit, $source, $old, static fn () => false, static function () use (&$now): int { return $now; });
        $lease = $ledger->acquire($source, 1, str_repeat('c', 64), str_repeat('d', 64), 10, $old);
        $ticket = $ledger->freeze(1);
        self::assertSame(1015, $ticket['readyAt']);
        self::assertSame($ticket, $ledger->freeze(1));
        $this->rejected(fn () => $ledger->acquire($source, 1, str_repeat('c', 64), str_repeat('d', 64), 10, $old));
        $stamp = str_repeat('e', 64);
        $this->rejected(fn () => $ledger->barrier($ticket['freezeId'], static fn () => $stamp));
        $now = 1015;
        $barrier = $ledger->barrier($ticket['freezeId'], static fn () => $stamp);
        self::assertSame($stamp, $barrier['stamp']);
        $hash = str_repeat('f', 64); $newSource = str_repeat('3', 64);
        $this->rejected(fn () => $ledger->activate($ticket['freezeId'], $stamp, $newSource, $new, $hash, 2000, static fn () => str_repeat('4', 64)));
        self::assertTrue($ledger->status($source)['frozen']);
        $result = $ledger->activate($ticket['freezeId'], $stamp, $newSource, $new, $hash, 2000, static fn () => $stamp);
        self::assertSame(2, $result['generation']);
        self::assertSame($result, $ledger->activate($ticket['freezeId'], $stamp, $newSource, $new, $hash, 2000, static fn () => $stamp));
        $this->rejected(fn () => $ledger->activate($ticket['freezeId'], $stamp, $newSource, $new, str_repeat('5', 64), 2000, static fn () => $stamp));
        self::assertSame($newSource, $ledger->activatedPrincipal($hash)['sourceId']);
        self::assertNull($ledger->activatedPrincipal(str_repeat('6', 64)));
        $this->rejected(fn () => $ledger->acquire($source, 1, str_repeat('c', 64), str_repeat('d', 64), 10, $old));
        $this->rejected(fn () => $ledger->acquire($newSource, 2, str_repeat('c', 64), str_repeat('d', 64), 10, $old));
        self::assertStringNotContainsString($hash, json_encode($ledger->status($newSource), JSON_THROW_ON_ERROR));
        $this->rejected(fn () => $ledger->freeze(1));
    }

    public function testAdministratorRebuildSwitchesWriterAndRecoversLostActivationResponse(): void
    {
        [$capture, $oldIndex, $site, $sql, $keys, $root] = $this->setupCapture();
        $recipients = array_column($keys, 'public');
        $first = $capture->run($capture->prepare($site, $sql, $recipients));
        $oldWriter = new SyntheticHttpRepository($this->server->connectionFile('writer'));
        $oldHead = $oldWriter->controlStatus()['head'];
        $replacement = Fixture::keys(1)[0]; $nextRecipients = [$keys[0]['public'], $replacement['public']];
        $orphanPlain = Fixture::temp() . '/plain'; file_put_contents($orphanPlain, 'generation two'); chmod($orphanPlain, 0600);
        $orphanCipher = Fixture::temp() . '/cipher';
        (new AgeProcess(Fixture::runtime()['age']))->encrypt($orphanPlain, $orphanCipher, $nextRecipients);
        $oldWriter->acquire(str_repeat('e', 64), str_repeat('f', 64), $oldHead, 1);
        $orphanKey = 'files/' . Manifest::keySetId($nextRecipients) . '/' . str_repeat('4', 64) . '.age';
        $orphan = $oldWriter->putNew($orphanKey, $orphanCipher);
        $newIndex = Fixture::temp(); $profile = Fixture::temp() . '/writer.json';
        $this->server->fault('drop-activate-response-once');
        $rebuilder = $this->rebuilder(); $identity = fopen($keys[0]['identity'], 'rb');
        try { $activation = $rebuilder->switch(1, $identity, $nextRecipients, $newIndex, $profile); }
        finally { fclose($identity); }
        self::assertSame(2, $activation['generation']);
        self::assertSame($activation, $rebuilder->recoverActivation($profile));
        self::assertSame(0600, fileperms($profile) & 0777);
        $this->rejected(fn () => $oldWriter->acquire(str_repeat('c', 64), str_repeat('d', 64), $oldHead));
        $newWriter = new SyntheticHttpRepository($profile);
        $this->rejected(fn () => $newWriter->acquire(str_repeat('c', 64), str_repeat('d', 64), $oldHead));
        Fixture::write($site, 'a.txt', 'generation two');
        $next = ManagedCapture::open($profile, $newIndex, Fixture::temp(), new AgeProcess(Fixture::runtime()['age']));
        $second = $next->run($next->prepare($site, $sql, $nextRecipients));
        self::assertNotSame($first['snapshotId'], $second['snapshotId']);
        $reader = new SyntheticHttpRepository($this->server->connectionFile('reader'));
        self::assertCount(2, $reader->listCompleted());
        $state = json_decode(file_get_contents(dirname($this->server->connectionFile('writer')) . '/unit-control/ledger.json'), true);
        self::assertSame($activation['sourceId'], $state['state']['sourceId']);
        self::assertArrayNotHasKey('token', $state['state']['lastActivation']);
        self::assertSame([$orphanKey], array_column($reader->listFileObjects(Manifest::keySetId($nextRecipients)), 'key'));
        $identity = fopen($keys[0]['identity'], 'rb');
        try { $this->rejected(fn () => $rebuilder->switch(1, $identity, $nextRecipients, Fixture::temp(), Fixture::temp() . '/writer.json')); }
        finally { fclose($identity); }
        unlink(dirname($this->server->connectionFile('writer')) . '/unit-control/ledger.json');
        $this->rejected(fn () => $newWriter->acquire(str_repeat('c', 64), str_repeat('d', 64), $activation['cursor']));
        self::assertCount(2, $reader->listCompleted());
        $reader->verify($orphan);
    }

    public function testNormalWriterCannotUseAdministratorOperations(): void
    {
        $cursor = $this->cursor('1');
        $this->server = IsolatedObjectServer::startManaged(Fixture::temp(), str_repeat('a', 64), str_repeat('b', 64), $cursor);
        $writer = new SyntheticHttpRepository($this->server->connectionFile('writer'));
        $this->rejected(fn () => $writer->freezeSource(1));
        self::assertFalse($writer->controlStatus()['frozen']);
    }

    public static function stagingInterruptions(): array { return [['before-link'], ['after-link']]; }

    /** @dataProvider stagingInterruptions */
    public function testStageFromAnExitedWriterDoesNotBlockActualSourceRebuild(string $phase): void
    {
        [$capture, , $site, $sql, $keys, $root] = $this->setupCapture();
        $recipients = array_column($keys, 'public');
        $first = $capture->run($capture->prepare($site, $sql, $recipients));
        $writer = new SyntheticHttpRepository($this->server->connectionFile('writer'));
        $head = $writer->controlStatus()['head'];
        $plain = Fixture::temp() . '/plain'; file_put_contents($plain, 'unpublished encrypted file'); chmod($plain, 0600);
        $cipher = Fixture::temp() . '/cipher';
        (new AgeProcess(Fixture::runtime()['age']))->encrypt($plain, $cipher, $recipients);
        $key = 'files/' . Manifest::keySetId($recipients) . '/' . str_repeat('8', 64) . '.age';
        $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/staging-crash-process.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, [], ['bypass_shell' => true]);
        self::assertIsResource($process);
        try {
            fwrite($pipes[0], json_encode(['control' => dirname($this->server->connectionFile('writer')) . '/unit-control',
                'root' => $root, 'unit' => str_repeat('a', 64), 'source' => str_repeat('b', 64), 'cursor' => $head,
                'job' => str_repeat('c', 64), 'nonce' => bin2hex(random_bytes(32)), 'publication' => str_repeat('d', 64),
                'key' => $key, 'cipher' => $cipher, 'phase' => $phase], JSON_THROW_ON_ERROR));
            fclose($pipes[0]); unset($pipes[0]);
            $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
            foreach ($pipes as $pipe) { fclose($pipe); } $pipes = [];
            $exit = proc_close($process); $process = null;
            self::assertSame(73, $exit, $stderr); self::assertSame('', $stdout); self::assertSame('', $stderr);
        } finally {
            foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
            if (is_resource($process)) { proc_terminate($process); proc_close($process); }
        }
        $destination = $root . '/' . str_repeat('a', 64) . '/' . $key;
        $stages = glob(dirname($destination) . '/.stage-*');
        self::assertCount(1, $stages); self::assertMatchesRegularExpression('/^\.stage-[a-f0-9]{32}$/D', basename($stages[0]));
        self::assertSame(hash_file('sha256', $cipher), hash_file('sha256', $stages[0]));
        self::assertSame(0600, fileperms($stages[0]) & 0777);
        self::assertSame($phase === 'before-link' ? 1 : 2, lstat($stages[0])['nlink']);
        self::assertSame($phase === 'after-link', file_exists($destination));
        $reader = new SyntheticHttpRepository($this->server->connectionFile('reader'));
        $published = $reader->listFileObjects(Manifest::keySetId($recipients));
        self::assertSame($phase === 'after-link', in_array($key, array_column($published, 'key'), true));
        $profile = Fixture::temp() . '/writer.json'; $index = Fixture::temp(); $identity = fopen($keys[0]['identity'], 'rb');
        try { $activation = $this->rebuilder()->switch(1, $identity, $recipients, $index, $profile); }
        finally { fclose($identity); }
        self::assertSame(2, $activation['generation']);
        self::assertSame([$first['snapshotId']], array_column($reader->listCompleted(), 'snapshotId'));
        self::assertSame($published, $reader->listFileObjects(Manifest::keySetId($recipients)));
        self::assertFileExists($stages[0]); self::assertSame($phase === 'after-link', file_exists($destination));
        self::assertSame(hash_file('sha256', $cipher), hash_file('sha256', $stages[0]));
        $next = new SyntheticHttpRepository($profile);
        self::assertSame($activation['cursor'], $next->controlStatus()['head']);
        self::assertFalse($next->controlStatus()['frozen']);
        $next->acquire(str_repeat('e', 64), str_repeat('f', 64), $activation['cursor'], 1);
        $this->rejected(fn () => $writer->acquire(str_repeat('e', 64), str_repeat('f', 64), $head, 1));
    }

    public function testInventoryRejectsUnknownNamesAndUnsafeStages(): void
    {
        $unit = str_repeat('a', 64); $root = Fixture::temp();
        $this->server = IsolatedObjectServer::startManaged($root, $unit, str_repeat('b', 64), $this->cursor('1'));
        $administrator = new SyntheticHttpRepository($this->server->connectionFile('admin'));
        $ticket = $administrator->freezeSource(1);
        while (time() < $ticket['readyAt']) { usleep(100000); }
        $empty = $administrator->sourceBarrier($ticket['freezeId']);
        $base = $root . '/' . $unit;
        foreach (['files/' . str_repeat('1', 64), 'databases', 'manifests', 'commits'] as $directory) {
            Fixture::write($base, $directory . '/.stage-' . str_repeat('2', 32), 'unpublished ciphertext');
        }
        self::assertSame($empty, $administrator->sourceBarrier($ticket['freezeId']));
        foreach (['unknown.age', 'databases/.stage-short', 'databases/.stage-' . str_repeat('A', 32),
            '.stage-' . str_repeat('3', 32), 'files/.stage-' . str_repeat('3', 32)] as $name) {
            Fixture::write($base, $name, 'unknown entry');
            try { $this->rejected(fn () => $administrator->sourceBarrier($ticket['freezeId'])); }
            finally { unlink($base . '/' . $name); }
        }
        $unsafe = $base . '/databases/.stage-' . str_repeat('4', 32);
        $target = Fixture::temp() . '/target'; file_put_contents($target, 'private ciphertext'); chmod($target, 0600);
        foreach (['symlink', 'hardlink', 'permissions', 'directory', 'fifo'] as $kind) {
            if ($kind === 'symlink') { self::assertTrue(symlink($target, $unsafe)); }
            elseif ($kind === 'hardlink') { self::assertTrue(link($target, $unsafe)); }
            elseif ($kind === 'permissions') { file_put_contents($unsafe, 'public ciphertext'); chmod($unsafe, 0644); }
            elseif ($kind === 'directory') { self::assertTrue(mkdir($unsafe, 0700)); }
            else { self::assertTrue(posix_mkfifo($unsafe, 0600)); }
            try { $this->rejected(fn () => $administrator->sourceBarrier($ticket['freezeId'])); }
            finally { if ($kind === 'directory') { rmdir($unsafe); } else { unlink($unsafe); } }
        }
        $elsewhere = $base . '/manifests/' . str_repeat('5', 64) . '.age';
        file_put_contents($elsewhere, 'cross-directory ciphertext'); chmod($elsewhere, 0600);
        self::assertTrue(link($elsewhere, $unsafe));
        try { $this->rejected(fn () => $administrator->sourceBarrier($ticket['freezeId'])); }
        finally { unlink($unsafe); unlink($elsewhere); }
        self::assertSame($empty, $administrator->sourceBarrier($ticket['freezeId']));
        self::assertTrue($administrator->administratorStatus()['frozen']);
    }

    public static function corruptions(): array { return [['manifest'], ['reference'], ['unreferenced']]; }

    /** @dataProvider corruptions */
    public function testInvalidStoredDataNeverActivatesANewSource(string $kind): void
    {
        [$capture, , $site, $sql, $keys, $root] = $this->setupCapture();
        $recipients = array_column($keys, 'public'); $result = $capture->run($capture->prepare($site, $sql, $recipients));
        $base = $root . '/' . str_repeat('a', 64);
        if ($kind === 'manifest') { file_put_contents($base . '/manifests/' . $result['snapshotId'] . '.age', 'corrupt ciphertext'); }
        elseif ($kind === 'reference') { unlink(glob($base . '/files/*/*.age')[0]); }
        else { Fixture::write($base, 'files/' . Manifest::keySetId($recipients) . '/' . str_repeat('7', 64) . '.age', 'unreadable orphan ciphertext'); }
        $profile = Fixture::temp() . '/writer.json'; $identity = fopen($keys[0]['identity'], 'rb');
        try { $this->rejected(fn () => $this->rebuilder()->switch(1, $identity, $recipients, Fixture::temp(), $profile)); }
        finally { fclose($identity); }
        $status = (new SyntheticHttpRepository($this->server->connectionFile('admin')))->administratorStatus();
        self::assertTrue($status['frozen']); self::assertSame(1, $status['generation']); self::assertNull($status['activation']);
        self::assertFileDoesNotExist($profile);
    }

    public function testBackendMutationDuringActualRebuildRetainsFrozenState(): void
    {
        [$capture, , $site, $sql, $keys, $root] = $this->setupCapture();
        $recipients = array_column($keys, 'public'); $capture->run($capture->prepare($site, $sql, $recipients));
        $extra = $root . '/' . str_repeat('a', 64) . '/databases/' . str_repeat('8', 64) . '.age';
        $wrapper = Fixture::temp() . '/age-wrapper';
        $code = '#!' . Fixture::phpForShebang() . "\n<?php\n" . '$args=$argv; $args[0]=' . var_export(Fixture::runtime()['age'], true)
            . '; $p=proc_open($args,[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes); $status=proc_close($p); if($status===0){file_put_contents('
            . var_export($extra, true) . ',"inventory changed after freeze"); chmod(' . var_export($extra, true) . ',0600);} exit($status);';
        file_put_contents($wrapper, $code); chmod($wrapper, 0700);
        $rebuilder = new SourceRebuilder($this->server->connectionFile('admin'), $this->server->connectionFile('reader'), new AgeProcess($wrapper));
        $identity = fopen($keys[0]['identity'], 'rb'); $profile = Fixture::temp() . '/writer.json';
        try { $this->rejected(fn () => $rebuilder->switch(1, $identity, $recipients, Fixture::temp(), $profile)); }
        finally { fclose($identity); }
        self::assertFileExists($extra); self::assertFileDoesNotExist($profile);
        self::assertTrue((new SyntheticHttpRepository($this->server->connectionFile('admin')))->administratorStatus()['frozen']);
    }

    public function testWriterCannotPromoteItselfAndOldGrantStopsImmediatelyAtFreeze(): void
    {
        $cursor = $this->cursor('1'); $root = Fixture::temp(); $unit = str_repeat('a', 64);
        $this->server = IsolatedObjectServer::startManaged($root, $unit, str_repeat('b', 64), $cursor);
        $writer = new SyntheticHttpRepository($this->server->connectionFile('writer'));
        $job = str_repeat('c', 64); $nonce = str_repeat('d', 64); $lease = $writer->acquire($job, $nonce, $cursor, 30);
        $session = ['generation' => 1, 'jobId' => $job, 'processNonce' => $nonce, 'fence' => $lease['fence'], 'cursor' => $cursor];
        $data = 'old grant ciphertext'; $key = 'databases/' . str_repeat('e', 64) . '.age';
        [$status, $grant] = $this->raw('writer', 'POST', '/upload-grants', ['key' => $key, 'bytes' => strlen($data), 'sha256' => hash('sha256', $data)], $session);
        self::assertSame(200, $status);
        self::assertSame(403, $this->raw('writer', 'POST', '/admin/freeze', ['expectedGeneration' => 1])[0]);
        self::assertFalse($writer->controlStatus()['frozen']);
        (new SyntheticHttpRepository($this->server->connectionFile('admin')))->freezeSource(1);
        self::assertGreaterThan(time(), $grant['expiresAt']);
        self::assertSame(409, $this->raw('writer', 'PUT', '/objects/' . $key, $data, $session, $grant['token'])[0]);
        self::assertFileDoesNotExist($root . '/' . $unit . '/' . $key);
    }

    public function testActualAdministratorProcessInterruptionKeepsPersistentFreeze(): void
    {
        [$capture, , $site, $sql, $keys] = $this->setupCapture();
        $recipients = array_column($keys, 'public'); $capture->run($capture->prepare($site, $sql, $recipients));
        $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/source-switch-process.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, [], ['bypass_shell' => true]);
        self::assertIsResource($process);
        try {
            fwrite($pipes[0], json_encode(['admin' => $this->server->connectionFile('admin'), 'reader' => $this->server->connectionFile('reader'),
                'age' => Fixture::runtime()['age'], 'identity' => $keys[0]['identity'], 'recipients' => $recipients,
                'index' => Fixture::temp(), 'profile' => Fixture::temp() . '/writer.json'], JSON_THROW_ON_ERROR));
            fclose($pipes[0]); unset($pipes[0]);
            $administrator = new SyntheticHttpRepository($this->server->connectionFile('admin'));
            $deadline = microtime(true) + 3;
            do { $status = $administrator->administratorStatus(); if ($status['frozen']) { break; } usleep(10000); } while (microtime(true) < $deadline);
            self::assertTrue($status['frozen']); proc_terminate($process);
            foreach ($pipes as $pipe) { fclose($pipe); } $pipes = []; proc_close($process); $process = null;
            self::assertTrue($administrator->administratorStatus()['frozen']);
            self::assertNull($administrator->administratorStatus()['activation']);
        } finally {
            foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
            if (is_resource($process)) { proc_terminate($process); proc_close($process); }
        }
    }

    private function raw(string $role, string $method, string $path,  $body, ?array $session = null, ?string $token = null): array
    {
        $profile = json_decode(file_get_contents($this->server->connectionFile($role)), true, 8, JSON_THROW_ON_ERROR);
        $headers = ['Authorization: Bearer ' . ($token ?? $profile['token']), 'X-WRB-Unit-Id: ' . $profile['unitId'], 'Expect:'];
        if (isset($profile['sourceId'])) { $headers[] = 'X-WRB-Source-Id: ' . $profile['sourceId']; $headers[] = 'X-WRB-Source-Generation: ' . $profile['generation']; }
        if ($session !== null) { $headers[] = 'X-WRB-Session: ' . json_encode($session, JSON_THROW_ON_ERROR); }
        $headers[] = 'Content-Type: ' . (is_array($body) ? 'application/json' : 'application/octet-stream');
        if ($method === 'PUT') { $headers[] = 'If-None-Match: *'; }
        $curl = curl_init($profile['endpoint'] . $path);
        try {
            curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_POSTFIELDS => is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR) : $body,
                CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false]);
            $response = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            return [$status, json_decode($response, true, 16, JSON_THROW_ON_ERROR)];
        } finally { curl_close($curl); }
    }

    private function setupCapture(): array
    {
        $unit = str_repeat('a', 64); $index = Fixture::temp(); $root = Fixture::temp();
        $cursor = ManagedCapture::initializeIndex($index, $unit);
        $this->server = IsolatedObjectServer::startManaged($root, $unit, str_repeat('b', 64), $cursor);
        $capture = ManagedCapture::open($this->server->connectionFile('writer'), $index, Fixture::temp(), new AgeProcess(Fixture::runtime()['age']), 3);
        $site = Fixture::site(['a.txt' => 'generation one']); $sql = Fixture::temp() . '/db.sql';
        file_put_contents($sql, 'synthetic SQL'); chmod($sql, 0600);
        return [$capture, $index, $site, $sql, Fixture::keys(), $root];
    }
    private function rebuilder(): SourceRebuilder
    {
        return new SourceRebuilder($this->server->connectionFile('admin'), $this->server->connectionFile('reader'), new AgeProcess(Fixture::runtime()['age']));
    }
    private function cursor(string $digit): array { return ['indexId' => str_repeat($digit, 64), 'sequence' => 0, 'commitment' => str_repeat('9', 64)]; }
    private function rejected(callable $action): void
    {
        $rejected = false;
        try { $action(); } catch (RuntimeException $error) { $rejected = true; self::assertNotSame('', $error->getMessage()); }
        self::assertTrue($rejected, 'The operation was unexpectedly accepted.');
    }
}
