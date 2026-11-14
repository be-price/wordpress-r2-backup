<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;

final class ObjectServiceTest extends TestCase
{
    private IsolatedObjectServer $server;
    private array $writer;
    private array $reader;

    protected function setUp(): void
    {
        $this->server = IsolatedObjectServer::start(Fixture::temp(), str_repeat('a', 64));
        $this->writer = json_decode(file_get_contents($this->server->connectionFile('writer')), true, 16, JSON_THROW_ON_ERROR);
        $this->reader = json_decode(file_get_contents($this->server->connectionFile('reader')), true, 16, JSON_THROW_ON_ERROR);
    }

    protected function tearDown(): void
    {
        if (isset($this->server)) { $this->server->stop(); }
        Fixture::cleanup();
    }

    public function testGrantPutVerifyAndReadAreCreateOnly(): void
    {
        $receipt = $this->receipt('synthetic ciphertext');
        [$status, $body] = $this->request('POST', '/upload-grants', $receipt);
        self::assertSame(200, $status);
        self::assertIsInt($body['expiresAt']);
        $grant = array_replace($this->writer, ['token' => $body['token']]);
        self::assertSame([200, $receipt], $this->request('PUT', '/objects/' . $receipt['key'], 'synthetic ciphertext', $grant, ['If-None-Match: *']));
        self::assertSame(412, $this->request('PUT', '/objects/' . $receipt['key'], 'synthetic ciphertext', $grant, ['If-None-Match: *'])[0]);
        self::assertSame([200, ['verified' => true]], $this->request('POST', '/verify', $receipt));
        self::assertSame([200, 'synthetic ciphertext'], $this->request('GET', '/objects/' . $receipt['key'], '', $this->reader));
        self::assertSame([200, ['hasObjects' => true]], $this->request('GET', '/has-objects'));
    }

    public function testRejectsUnboundTokensHeadersHashesAndRoles(): void
    {
        $receipt = $this->receipt('cipher');
        [, $grant] = $this->request('POST', '/upload-grants', $receipt);
        $limited = array_replace($this->writer, ['token' => $grant['token']]);
        self::assertSame(428, $this->request('PUT', '/objects/' . $receipt['key'], 'cipher', $limited)[0]);
        self::assertSame(422, $this->request('PUT', '/objects/' . $receipt['key'], 'wrong!', $limited, ['If-None-Match: *'])[0]);
        self::assertSame(403, $this->request('PUT', '/objects/' . $receipt['key'], 'cipher', $this->writer, ['If-None-Match: *'])[0]);
        self::assertSame(403, $this->request('POST', '/upload-grants', $receipt, $this->reader)[0]);
        self::assertSame(403, $this->request('GET', '/objects/' . $receipt['key'])[0]);
        self::assertSame(403, $this->request('POST', '/upload-grants', $receipt, array_replace($this->writer, ['unitId' => str_repeat('b', 64)]))[0]);
        self::assertSame(400, $this->request('POST', '/upload-grants', $receipt + ['extra' => true])[0]);
        self::assertSame(400, $this->request('POST', '/upload-grants', array_replace($receipt, ['key' => '../escape']))[0]);
        self::assertSame(413, $this->request('POST', '/upload-grants', array_replace($receipt, ['bytes' => 67108865]))[0]);
    }

    public function testExpiredServerGrantIsRejectedBeforeStorage(): void
    {
        $receipt = $this->receipt('cipher');
        [, $grant] = $this->request('POST', '/upload-grants', $receipt);
        $profile = array_replace($this->writer, ['token' => $grant['token']]);
        $configFile = dirname($this->server->connectionFile('writer')) . '/grants.json';
        $grants = json_decode(file_get_contents($configFile), true, 16, JSON_THROW_ON_ERROR);
        $grants[hash('sha256', $grant['token'])]['expiresAt'] = time() - 1;
        file_put_contents($configFile, json_encode($grants, JSON_THROW_ON_ERROR));
        self::assertSame(403, $this->request('PUT', '/objects/' . $receipt['key'], 'cipher', $profile, ['If-None-Match: *'])[0]);
        self::assertSame([200, ['hasObjects' => false]], $this->request('GET', '/has-objects'));
        $replacement = $this->receipt('new randomized cipher');
        [$status, $newGrant] = $this->request('POST', '/upload-grants', $replacement);
        self::assertSame(200, $status, 'An expired grant must not permanently reserve an absent object.');
        $newProfile = array_replace($this->writer, ['token' => $newGrant['token']]);
        self::assertSame([200, $replacement], $this->request('PUT', '/objects/' . $replacement['key'], 'new randomized cipher', $newProfile, ['If-None-Match: *']));
        self::assertSame(409, $this->request('POST', '/upload-grants', $receipt)[0]);
        self::assertSame([200, 'new randomized cipher'], $this->request('GET', '/objects/' . $replacement['key'], '', $this->reader));
    }

    public function testExpiredConnectionAndConflictingContentAreRejected(): void
    {
        $receipt = $this->receipt('cipher');
        self::assertSame(200, $this->request('POST', '/upload-grants', $receipt)[0]);
        self::assertSame(409, $this->request('POST', '/upload-grants', array_replace($receipt, ['sha256' => str_repeat('d', 64)]))[0]);
        $file = dirname($this->server->connectionFile('writer')) . '/config.json';
        $config = json_decode(file_get_contents($file), true, 16, JSON_THROW_ON_ERROR);
        $config['tokens'][hash('sha256', $this->writer['token'])]['expiresAt'] = time() - 1;
        file_put_contents($file, json_encode($config, JSON_THROW_ON_ERROR));
        self::assertSame(403, $this->request('POST', '/upload-grants', $receipt)[0]);
    }

    public function testCompletionIsIdempotentAndPrivateStateDoesNotLeakTokens(): void
    {
        $receipt = $this->receipt('encrypted manifest');
        $snapshot = str_repeat('e', 64);
        $receipt['key'] = 'manifests/' . $snapshot . '.age';
        [, $grant] = $this->request('POST', '/upload-grants', $receipt);
        $profile = array_replace($this->writer, ['token' => $grant['token']]);
        self::assertSame(200, $this->request('PUT', '/objects/' . $receipt['key'], 'encrypted manifest', $profile, ['If-None-Match: *'])[0]);
        $record = ['snapshotId' => $snapshot, 'manifest' => $receipt];
        self::assertSame([200, $record], $this->request('POST', '/complete/' . $snapshot, $receipt));
        self::assertSame([200, $record], $this->request('POST', '/complete/' . $snapshot, array_reverse($receipt, true)));
        self::assertSame([200, $record], $this->request('GET', '/completed/' . $snapshot, '', $this->reader));
        self::assertSame([200, [$record + ['valid' => true]]], $this->request('GET', '/completed', '', $this->reader));
        self::assertSame(409, $this->request('POST', '/complete/' . $snapshot, array_replace($receipt, ['sha256' => str_repeat('f', 64)]))[0]);
        $workspace = dirname($this->server->connectionFile('writer'));
        self::assertSame(0700, fileperms($workspace) & 0777);
        foreach (['writer.json', 'reader.json', 'config.json', 'grants.json', 'control.json', 'stdout.log', 'stderr.log'] as $name) {
            self::assertSame(0600, fileperms($workspace . '/' . $name) & 0777);
            if (!in_array($name, ['writer.json', 'reader.json'], true)) {
                $content = file_get_contents($workspace . '/' . $name);
                self::assertStringNotContainsString($this->writer['token'], $content);
                self::assertStringNotContainsString($this->reader['token'], $content);
                self::assertStringNotContainsString($grant['token'], $content);
            }
        }
    }

    public function testLinkedObjectsAreRejectedWithoutChangingTheirTargets(): void
    {
        $receipt = $this->receipt('cipher');
        [, $grant] = $this->request('POST', '/upload-grants', $receipt);
        $profile = array_replace($this->writer, ['token' => $grant['token']]);
        self::assertSame(200, $this->request('PUT', '/objects/' . $receipt['key'], 'cipher', $profile, ['If-None-Match: *'])[0]);
        $config = json_decode(file_get_contents(dirname($this->server->connectionFile('writer')) . '/config.json'), true, 16, JSON_THROW_ON_ERROR);
        $object = $config['repository'] . '/' . $config['unitId'] . '/' . $receipt['key'];
        $target = $config['repository'] . '/external-cipher';
        rename($object, $target);
        symlink($target, $object);
        self::assertSame([422, ['error' => 'verification_failed']], $this->request('POST', '/verify', $receipt));
        self::assertSame(422, $this->request('GET', '/objects/' . $receipt['key'], '', $this->reader)[0]);
        self::assertSame(422, $this->request('PUT', '/objects/' . $receipt['key'], 'cipher', $profile, ['If-None-Match: *'])[0]);
        self::assertSame('cipher', file_get_contents($target));
    }

    public function testFaultsAreOneShotAndUnavailableToHttpUsers(): void
    {
        $receipt = $this->receipt('cipher');
        [, $grant] = $this->request('POST', '/upload-grants', $receipt);
        $profile = array_replace($this->writer, ['token' => $grant['token']]);
        $this->server->fault('drop-put-response-once');
        self::assertSame(503, $this->request('PUT', '/objects/' . $receipt['key'], 'cipher', $profile, ['If-None-Match: *'])[0]);
        self::assertSame([200, ['verified' => true]], $this->request('POST', '/verify', $receipt));
        self::assertSame(403, $this->request('POST', '/fault', ['fault' => 'corrupt-get-once'])[0]);
        $this->server->fault('corrupt-get-once');
        self::assertSame([200, chr(ord('c') ^ 1) . 'ipher'], $this->request('GET', '/objects/' . $receipt['key'], '', $this->reader));
        self::assertSame([200, 'cipher'], $this->request('GET', '/objects/' . $receipt['key'], '', $this->reader));
        $this->server->fault('truncate-get-once');
        self::assertSame([200, false], $this->request('GET', '/objects/' . $receipt['key'], '', $this->reader));
        self::assertSame([200, 'cipher'], $this->request('GET', '/objects/' . $receipt['key'], '', $this->reader));
    }

    private function receipt(string $content): array
    {
        return ['key' => 'databases/' . str_repeat('c', 64) . '.age', 'bytes' => strlen($content), 'sha256' => hash('sha256', $content)];
    }

    private function request(string $method, string $path,  $body = '', ?array $profile = null, array $headers = []): array
    {
        $profile ??= $this->writer;
        $headers[] = 'Authorization: Bearer ' . $profile['token'];
        $headers[] = 'X-WRB-Unit-Id: ' . $profile['unitId'];
        $curl = curl_init($profile['endpoint'] . $path);
        curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR) : $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_FOLLOWLOCATION => false]);
        $response = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        $json = is_string($response) && (str_starts_with($response, '{') || str_starts_with($response, '['));
        return [$status, $json ? json_decode($response, true, 16, JSON_THROW_ON_ERROR) : $response];
    }
}
