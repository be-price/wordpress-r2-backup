<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use RuntimeException;
use WordPressR2Backup\FileSafety;
use WordPressR2Backup\LocalRepository;
use WordPressR2Backup\UnitLedger;

final class IsolatedObjectServer
{
    private string $workspace;
    private $process = null;
    private array $connectionNames = [];

    private function __construct() {}

    public static function start(string $privateRepositoryRoot, string $unitId): self
    {
        return self::launch($privateRepositoryRoot, $unitId);
    }

    public static function startManaged(string $privateRepositoryRoot, string $unitId, string $sourceId, array $cursor): self
    {
        return self::launch($privateRepositoryRoot, $unitId, $sourceId, $cursor);
    }

    private static function launch(string $privateRepositoryRoot, string $unitId, ?string $sourceId = null, ?array $cursor = null): self
    {
        LocalRepository::forWriting($privateRepositoryRoot, $unitId);
        $self = new self();
        $self->workspace = FileSafety::createPrivateDirectory($privateRepositoryRoot . '/.http-' . bin2hex(random_bytes(12)));
        if ($sourceId !== null) {
            $control = FileSafety::createPrivateDirectory($self->workspace . '/unit-control');
            UnitLedger::initialize($control, $unitId, $sourceId, $cursor, fn () => LocalRepository::forWriting($privateRepositoryRoot, $unitId)->hasObjects());
        }
        $serverId = bin2hex(random_bytes(32));
        $listener = @stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        if ($listener === false) { throw new RuntimeException('Cannot reserve a loopback port.'); }
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        if (!is_string($address)) { throw new RuntimeException('Cannot identify the loopback port.'); }
        $endpoint = 'http://' . $address;
        $expires = time() + 3600;
        $tokens = [];
        foreach ($sourceId === null ? ['writer', 'reader'] : ['writer', 'reader', 'admin'] as $role) {
            $token = bin2hex(random_bytes(32));
            $profile = ['format' => 'wrb-synthetic-connection-v1', 'endpoint' => $endpoint, 'unitId' => $unitId, 'role' => $role, 'token' => $token, 'expiresAt' => $expires];
            if ($role === 'admin') { $profile['format'] = 'wrb-synthetic-administrator-v1'; }
            $principal = ['role' => $role, 'unitId' => $unitId, 'expiresAt' => $expires];
            if ($sourceId !== null && $role === 'writer') {
                $profile['format'] = 'wrb-synthetic-managed-connection-v1';
                $profile['sourceId'] = $principal['sourceId'] = $sourceId;
                $profile['generation'] = $principal['generation'] = 1;
            }
            $self->writePrivate($role . '.json', $profile);
            $tokens[hash('sha256', $token)] = $principal;
        }
        $self->writePrivate('config.json', ['repository' => $privateRepositoryRoot, 'unitId' => $unitId, 'serverId' => $serverId, 'tokens' => $tokens, 'mode' => $sourceId === null ? 'legacyB1' : 'managedB2']);
        $self->writePrivate('grants.json', []);
        $self->writePrivate('grants.lock', '');
        $self->writePrivate('control.json', []);
        foreach (['stdout.log', 'stderr.log'] as $log) { $self->writePrivate($log, ''); }
        try {
            $self->process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', '-d', 'post_max_size=65M', '-S', $address, '-t', $self->workspace, __DIR__ . '/fixtures/object-service.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $self->workspace . '/stdout.log', 'a'], 2 => ['file', $self->workspace . '/stderr.log', 'a']], $pipes, $self->workspace, ['WRB_SYNTHETIC_SERVER_CONFIG' => $self->workspace . '/config.json'], ['bypass_shell' => true]);
            if (!is_resource($self->process)) { throw new RuntimeException('Cannot start the isolated object service.'); }
            $deadline = microtime(true) + 5;
            do {
                if (!proc_get_status($self->process)['running']) { throw new RuntimeException('Object service stopped before readiness.'); }
                $curl = curl_init($endpoint . '/health');
                curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => '', CURLOPT_TIMEOUT_MS => 200, CURLOPT_CONNECTTIMEOUT_MS => 200, CURLOPT_FOLLOWLOCATION => false]);
                $response = curl_exec($curl);
                curl_close($curl);
                if (is_string($response) && json_decode($response, true) === ['serverId' => $serverId]) { return $self; }
                usleep(25000);
            } while (microtime(true) < $deadline);
            throw new RuntimeException('Object service did not become ready.');
        } catch (\Throwable $error) {
            $self->stop();
            throw $error;
        }
    }

    public function connectionFile(string $role): string
    {
        if (!in_array($role, ['writer', 'reader', 'admin'], true)) { throw new RuntimeException('Unknown connection role.'); }
        return $this->workspace . '/' . ($this->connectionNames[$role] ?? $role . '.json');
    }

    public function peer(): self
    {
        $peer = new self();
        $peer->workspace = $this->workspace;
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        if ($listener === false) { throw new RuntimeException('Cannot reserve a peer port.'); }
        $address = stream_socket_get_name($listener, false); fclose($listener);
        $config = json_decode(file_get_contents($this->workspace . '/config.json'), true, 16, JSON_THROW_ON_ERROR);
        $suffix = bin2hex(random_bytes(12));
        foreach (($config['mode'] ?? 'legacyB1') === 'managedB2' ? ['reader', 'writer', 'admin'] : ['reader', 'writer'] as $role) {
            $profile = json_decode(file_get_contents($this->connectionFile($role)), true, 16, JSON_THROW_ON_ERROR);
            $profile['endpoint'] = 'http://' . $address;
            $peer->connectionNames[$role] = $suffix . '-' . $role . '.json';
            $peer->writePrivate($peer->connectionNames[$role], $profile);
        }
        $log = $suffix . '.log'; $peer->writePrivate($log, '');
        try {
            $peer->process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', '-d', 'post_max_size=65M', '-S', $address, '-t', $peer->workspace, __DIR__ . '/fixtures/object-service.php'],
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', $peer->workspace . '/' . $log, 'a'], 2 => ['file', $peer->workspace . '/' . $log, 'a']],
                $pipes, $peer->workspace, ['WRB_SYNTHETIC_SERVER_CONFIG' => $peer->workspace . '/config.json'], ['bypass_shell' => true]);
            if (!is_resource($peer->process)) { throw new RuntimeException('Cannot start a peer worker.'); }
            $deadline = microtime(true) + 5;
            do {
                if (!proc_get_status($peer->process)['running']) { throw new RuntimeException('Peer worker stopped.'); }
                $curl = curl_init('http://' . $address . '/health');
                curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => '', CURLOPT_TIMEOUT_MS => 200, CURLOPT_CONNECTTIMEOUT_MS => 200]);
                $response = curl_exec($curl); curl_close($curl);
                if (is_string($response) && json_decode($response, true) === ['serverId' => $config['serverId']]) { return $peer; }
                usleep(25000);
            } while (microtime(true) < $deadline);
            throw new RuntimeException('Peer worker did not become ready.');
        } catch (\Throwable $error) { $peer->stop(); throw $error; }
    }

    public function waitForPause(): void
    {
        $deadline = microtime(true) + 3;
        do {
            $state = json_decode(file_get_contents($this->workspace . '/control.json'), true, 8, JSON_THROW_ON_ERROR);
            if (($state['paused'] ?? false) === true) { return; }
            usleep(10000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('The publication did not pause.');
    }

    public function releasePublication(): void { $this->writePrivate('control.json', ['release' => true]); }

    public function fault(string $name): void
    {
        if (!in_array($name, ['drop-put-response-once', 'truncate-get-once', 'corrupt-get-once', 'drop-advance-response-once', 'drop-complete-response-once', 'drop-activate-response-once', 'pause-before-put', 'pause-before-complete'], true)) { throw new RuntimeException('Unknown service fault.'); }
        $this->writePrivate('control.json', ['fault' => $name]);
    }

    public function stop(): void
    {
        if (!is_resource($this->process)) { return; }
        if (proc_get_status($this->process)['running']) { proc_terminate($this->process); }
        proc_close($this->process);
        $this->process = null;
    }

    public function __destruct() { $this->stop(); }

    private function writePrivate(string $name,  $value): void
    {
        $file = $this->workspace . '/' . $name;
        FileSafety::assertNoLinks($file);
        $temporary = $file . '.new-' . bin2hex(random_bytes(12));
        $stream = @fopen($temporary, 'xb');
        if ($stream === false) { throw new RuntimeException('Cannot create a private service file.'); }
        try {
            $data = is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : $value;
            if (!chmod($temporary, 0600) || fwrite($stream, $data) !== strlen($data) || !fflush($stream) || !\WordPressR2Backup\FileSafety::sync($stream)
                || !rename($temporary, $file)) { throw new RuntimeException('Cannot write a private service file.'); }
        } finally {
            fclose($stream);
            if (@lstat($temporary) !== false) { unlink($temporary); }
        }
    }
}
