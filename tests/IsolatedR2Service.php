<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use RuntimeException;

final class IsolatedR2Service
{
    private $process;
    private array $pipes = [];
    private string $endpoint;
    private array $settings;

    private string $workspace;
    private string $mode;

    private function __construct(string $workspace, string $mode)
    {
        $this->workspace = $workspace;
        $this->mode = $mode;

        $settingsFile = $workspace . '/settings.json';
        if (is_file($settingsFile)) {
            $this->settings = json_decode(file_get_contents($settingsFile), true, 16, JSON_THROW_ON_ERROR);
        } else {
            $this->settings = ['workspace' => $workspace, 'unitId' => bin2hex(random_bytes(32)), 'sourceId' => bin2hex(random_bytes(32)),
                'writerToken' => bin2hex(random_bytes(32)), 'adminToken' => bin2hex(random_bytes(32)), 'serverId' => bin2hex(random_bytes(32)),
                'expiresAt' => time() + 7200, 'accessKeyId' => bin2hex(random_bytes(16)), 'secretAccessKey' => bin2hex(random_bytes(32))];
            file_put_contents($settingsFile, json_encode($this->settings, JSON_THROW_ON_ERROR)); chmod($settingsFile, 0600);
        }
        $command = ['node', __DIR__ . '/fixtures/b3-r2-service.mjs', $settingsFile, $mode];
        $this->process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $workspace . '/node.log', 'a']], $this->pipes,
            __DIR__ . '/support/r2-connector');
        if (!is_resource($this->process)) { throw new RuntimeException('Cannot start isolated R2 service.'); }
        stream_set_blocking($this->pipes[1], false);
        $buffer = ''; $deadline = microtime(true) + 30;
        while (microtime(true) < $deadline) {
            $buffer .= stream_get_contents($this->pipes[1]);
            if (str_contains($buffer, "\n")) {
                $ready = json_decode(explode("\n", $buffer, 2)[0], true, 8, JSON_THROW_ON_ERROR);
                $this->endpoint = $ready['endpoint']; return;
            }
            if (!proc_get_status($this->process)['running']) { break; }
            usleep(20000);
        }
        $log = file_get_contents($workspace . '/node.log');
        $this->stop(); throw new RuntimeException('Isolated R2 service did not become ready: ' . substr($log, -3000));
    }
    public static function start(string $workspace, string $mode = 'writer'): self { return new self($workspace, $mode); }
    public function unitId(): string { return $this->settings['unitId']; }
    public function writerProfile(string $file): string
    {
        return $this->profile($file, ['format' => 'wrb-local-r2-managed-connection-v1', 'endpoint' => $this->endpoint,
            'unitId' => $this->unitId(), 'role' => 'writer', 'token' => $this->settings['writerToken'], 'expiresAt' => $this->settings['expiresAt'],
            'sourceId' => $this->settings['sourceId'], 'generation' => 1]);
    }
    public function readerProfile(string $file): string
    {
        return $this->profile($file, ['format' => 'wrb-local-r2-reader-v1', 'version' => 1, 'endpoint' => $this->endpoint,
            'unitId' => $this->unitId(), 'bucket' => 'wrb-test-bucket', 'accessKeyId' => $this->settings['accessKeyId'],
            'secretAccessKey' => $this->settings['secretAccessKey'], 'expiresAt' => $this->settings['expiresAt']]);
    }
    public function administratorProfile(string $file): string
    {
        return $this->profile($file, ['format' => 'wrb-local-r2-administrator-v1', 'endpoint' => $this->endpoint,
            'unitId' => $this->unitId(), 'role' => 'admin', 'token' => $this->settings['adminToken'], 'expiresAt' => $this->settings['expiresAt']]);
    }
    private function profile(string $file, array $profile): string
    {
        file_put_contents($file, json_encode($profile, JSON_THROW_ON_ERROR)); chmod($file, 0600); return $file;
    }
    public function initialize(array $cursor): void
    {
        $curl = curl_init($this->endpoint . '/admin/initialize');
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['cursor' => $cursor], JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->settings['adminToken'], 'X-WRB-Unit-Id: ' . $this->unitId()],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_TIMEOUT => 10]);
        $result = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if ($status !== 200) { throw new RuntimeException('Synthetic R2 initialization failed: ' . $status . ' ' . $result); }
    }
    public function stop(): void
    {
        if (!is_resource($this->process)) { return; }
        if (isset($this->pipes[0]) && is_resource($this->pipes[0])) { if (proc_get_status($this->process)['running']) { fwrite($this->pipes[0], "stop\n"); } fclose($this->pipes[0]); }
        $deadline = microtime(true) + 10;
        while (proc_get_status($this->process)['running'] && microtime(true) < $deadline) { usleep(20000); }
        if (proc_get_status($this->process)['running']) { proc_terminate($this->process); }
        if (isset($this->pipes[1]) && is_resource($this->pipes[1])) { fclose($this->pipes[1]); }
        proc_close($this->process); $this->process = null;
    }
    public function __destruct() { $this->stop(); }
}
