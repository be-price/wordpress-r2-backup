<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;
use PHPUnit\Framework\TestCase;
use WordPressR2Backup\{SyntheticAdmin, SyntheticAdminRuntime};
final class SyntheticAdminTest extends TestCase
{
    private $socket;
    private array $profile;
    private SyntheticAdmin $admin;
    protected function setUp(): void
    {
        [$file, $this->profile, $this->socket] = SyntheticAdminRuntimeTest::profile();
        $this->admin = new SyntheticAdmin(SyntheticAdminRuntime::open($file, $this->profile['privateRoot'] . '/web'));
    }
    protected function tearDown(): void { fclose($this->socket); Fixture::cleanup(); }
    private function rejects(string $code, callable $action): void
    {
        $failure = null; try { $action(); } catch (\Throwable $error) { $failure = $error; }
        self::assertInstanceOf(\Throwable::class, $failure);
        self::assertSame($code, SyntheticAdmin::publicError($failure));
    }
    private function decrypted(int $slot, string $identity): array
    {
        $file = $this->profile['privateRoot'] . '/test-challenge.age';
        Fixture::write($this->profile['privateRoot'], 'test-challenge.age', $this->admin->challenge($slot));
        $process = proc_open([Fixture::runtime()['age'], '--decrypt', '--identity', $identity, $file], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $bytes = stream_get_contents($pipes[1]); fclose($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[2]);
        if (proc_close($process) !== 0) { throw new \RuntimeException('Test challenge decryption rejected.'); }
        return json_decode($bytes, true, 8, JSON_THROW_ON_ERROR);
    }
    private function registerAndIssue(): array
    {
        $keys = Fixture::keys(); $this->admin->registerKeys(array_column($keys, 'public')); $this->admin->issueChallenge(); return $keys;
    }
    private function rewrite(callable $change): void
    {
        $file = $this->profile['privateRoot'] . '/admin-state.json';
        $record = json_decode(file_get_contents($file), true); $change($record['data']);
        $record['checksum'] = hash('sha256', json_encode($record['data'], JSON_THROW_ON_ERROR));
        Fixture::write($this->profile['privateRoot'], 'admin-state.json', json_encode($record));
    }
    public function testEachKeyAloneDecryptsItsChallengeAndStateHasOnlyHash(): void
    {
        $keys = $this->registerAndIssue();
        foreach ([1, 2] as $slot) {
            $c = $this->decrypted($slot, $keys[$slot - 1]['identity']);
            self::assertSame($slot, $c['slot']); self::assertSame(str_repeat('a', 64), $c['unitId']);
            $failure = null;
            try { $this->decrypted($slot, $keys[2 - $slot]['identity']); } catch (\Throwable $error) { $failure = $error; }
            self::assertInstanceOf(\Throwable::class, $failure);
            foreach (glob($this->profile['privateRoot'] . '/*') as $file) {
                if (is_file($file)) { self::assertStringNotContainsString($c['code'], file_get_contents($file)); }
            }
            $this->admin->confirmChallenge($slot, $c['challengeId'], $c['code']);
            $this->rejects('challenge_used', fn () => $this->admin->confirmChallenge($slot, $c['challengeId'], $c['code']));
        }
        $view = $this->admin->view(); self::assertTrue($view['canStart']);
        self::assertSame(['unitId', 'revision', 'keySetId', 'keys', 'requestId', 'canStart', 'operation', 'generations', 'listingError'], array_keys($view));
        self::assertStringNotContainsString($this->profile['privateRoot'], json_encode($view));
        self::assertSame('listing_failed', $view['listingError']);
    }
    public function testWrongSlotExpiryAttemptsReissueAndReregistrationRejectOldCode(): void
    {
        $keys = $this->registerAndIssue(); $old = $this->decrypted(1, $keys[0]['identity']);
        $this->rejects('challenge_invalid', fn () => $this->admin->confirmChallenge(2, $old['challengeId'], $old['code']));
        for ($i = 1; $i < 5; $i++) { $this->rejects('challenge_invalid', fn () => $this->admin->confirmChallenge(2, $old['challengeId'], $old['code'])); }
        $this->rejects('challenge_locked', fn () => $this->admin->challenge(2));
        $this->rewrite(static function (array &$s): void { $s['keys'][0]['challenge']['expiresAt'] = time() - 1; });
        $this->rejects('challenge_expired', fn () => $this->admin->confirmChallenge(1, $old['challengeId'], $old['code']));
        $this->admin->issueChallenge();
        $this->rejects('challenge_invalid', fn () => $this->admin->confirmChallenge(1, $old['challengeId'], $old['code']));
        $fresh = $this->decrypted(1, $keys[0]['identity']);
        $this->admin->confirmChallenge(1, $fresh['challengeId'], $fresh['code']);
        $revision = $this->admin->view()['revision']; $this->admin->issueChallenge();
        self::assertGreaterThan($revision, $this->admin->view()['revision']); self::assertFalse($this->admin->view()['keys'][0]['confirmed']);
        $this->admin->registerKeys(array_reverse(array_column($keys, 'public')));
        self::assertFalse($this->admin->view()['keys'][0]['confirmed']);
        $this->rejects('challenge_invalid', fn () => $this->admin->confirmChallenge(1, $fresh['challengeId'], $fresh['code']));
    }
    public function testStartPersistsIntentBeforePrepareAndReplayKeepsOneOperation(): void
    {
        $keys = $this->registerAndIssue();
        foreach ([1, 2] as $slot) { $c = $this->decrypted($slot, $keys[$slot - 1]['identity']); $this->admin->confirmChallenge($slot, $c['challengeId'], $c['code']); }
        $request = $this->admin->view()['requestId'];
        $this->rejects('capture_failed', fn () => $this->admin->start($request));
        $op = $this->admin->view()['operation']; self::assertSame('pending', $op['status']); self::assertNull($op['jobId']);
        self::assertDirectoryExists($this->profile['operationsDirectory'] . '/' . $op['operationId'] . '/jobs');
        $this->rejects('capture_failed', fn () => $this->admin->start($request));
        self::assertSame($op, $this->admin->view()['operation']);
        $this->rejects('operation_active', fn () => $this->admin->registerKeys(array_column($keys, 'public')));
        $this->rejects('operation_active', fn () => $this->admin->issueChallenge());
        $this->rejects('operation_active', fn () => $this->admin->start(str_repeat('d', 64)));
        Fixture::write($this->profile['operationsDirectory'] . '/' . $op['operationId'] . '/jobs', 'partial', 'partial');
        $this->rejects('state_invalid', fn () => $this->admin->resume($op['operationId']));
        unlink($this->profile['privateRoot'] . '/admin-state.json');
        $this->rejects('state_invalid', fn () => $this->admin->view());
        self::assertFileDoesNotExist($this->profile['privateRoot'] . '/admin-state.json');
    }
    public function testUnconfirmedKeysAndProfileReplacementCannotStart(): void
    {
        $view = $this->admin->view();
        $this->rejects('keys_unconfirmed', fn () => $this->admin->start($view['requestId']));
        self::assertSame([], array_values(array_diff(scandir($this->profile['operationsDirectory']), ['.', '..'])));
        $this->rejects('invalid_keys', fn () => $this->admin->registerKeys(['invalid', 'invalid']));
        $this->rejects('keys_required', fn () => $this->admin->issueChallenge());
        $this->rejects('invalid_request', fn () => $this->admin->challenge(0));
        $this->rejects('operation_missing', fn () => $this->admin->resume(str_repeat('c', 64)));
        $file = $this->profile['privateRoot'] . '/runtime.json';
        file_put_contents($file, file_get_contents($file) . "\n");
        $replacement = new SyntheticAdmin(SyntheticAdminRuntime::open($file, $this->profile['privateRoot'] . '/web'));
        $this->rejects('state_invalid', fn () => $replacement->view());
    }

    public function testNonWaitingLockCorruptionAndSafeError(): void
    {
        $this->admin->view(); $stream = fopen($this->profile['privateRoot'] . '/admin.lock', 'r+b'); flock($stream, LOCK_EX);
        $started = microtime(true); $this->rejects('busy', fn () => $this->admin->view()); self::assertLessThan(0.5, microtime(true) - $started);
        flock($stream, LOCK_UN); fclose($stream);
        Fixture::write($this->profile['privateRoot'], 'admin-state.json', '{}');
        $this->rejects('state_invalid', fn () => $this->admin->view()); self::assertSame('{}', file_get_contents($this->profile['privateRoot'] . '/admin-state.json'));
        self::assertSame('capture_failed', SyntheticAdmin::publicError(new \RuntimeException('token and password here')));
    }
}
