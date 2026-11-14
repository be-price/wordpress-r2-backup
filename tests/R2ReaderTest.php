<?php
declare(strict_types=1);

namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\{ObjectReader, R2Reader};

final class R2ReaderTest extends TestCase
{
    private static $process;
    private static array $pipes = [];
    private static array $service;
    private static array $credentials;
    private static string $serviceRoot;
    private string $unit;

    public static function setUpBeforeClass(): void
    {
        self::$serviceRoot = realpath(sys_get_temp_dir()) . '/wrb-r2-reader-' . bin2hex(random_bytes(8));
        mkdir(self::$serviceRoot, 0700);
        self::$credentials = ['accessKeyId' => bin2hex(random_bytes(16)), 'secretAccessKey' => bin2hex(random_bytes(32))];
        $config = self::$serviceRoot . '/config.json';
        file_put_contents($config, json_encode(['bucket' => 'reader-test', 'credentials' => self::$credentials], JSON_THROW_ON_ERROR));
        chmod($config, 0600);
        self::$process = proc_open(['node', __DIR__ . '/fixtures/r2-reader-service.mjs', $config],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', self::$serviceRoot . '/stderr.log', 'a']], self::$pipes);
        if (!is_resource(self::$process)) { throw new \RuntimeException('Cannot start R2 reader fixture.'); }
        stream_set_timeout(self::$pipes[1], 45);
        self::$service = self::readReply();
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$process)) {
            fwrite(self::$pipes[0], "{\"action\":\"stop\"}\n");
            fclose(self::$pipes[0]); fclose(self::$pipes[1]);
            proc_close(self::$process);
        }
        foreach (glob(self::$serviceRoot . '/*') ?: [] as $path) { unlink($path); }
        rmdir(self::$serviceRoot);
    }

    protected function setUp(): void { $this->unit = bin2hex(random_bytes(32)); }
    protected function tearDown(): void { Fixture::cleanup(); }

    public function testDirectS3ReaderRestoresPartsAndListsWithoutConnectorOrDo(): void
    {
        [$receipt] = $this->parts('files/' . str_repeat('b', 64) . '/' . str_repeat('c', 64) . '.age', ['synthetic cipher']);
        $reader = $this->reader();
        self::assertInstanceOf(ObjectReader::class, $reader);
        self::assertSame($this->unit, $reader->unitId());
        $reader->verify($receipt);
        $target = Fixture::temp() . '/cipher.age';
        $reader->download($receipt, $target);
        self::assertSame('synthetic cipher', file_get_contents($target));
        self::assertSame(0600, fileperms($target) & 0777);
        self::assertSame([$receipt], $reader->listFileObjects(str_repeat('b', 64)));
        self::assertTrue($reader->hasObjects());
        $this->reject(fn () => $reader->download($receipt, $target));
        self::assertSame(['.', '..', 'cipher.age'], scandir(dirname($target)));
    }

    public function testMultiplePartsAreOneBoundedOrderedCiphertext(): void
    {
        $first = str_repeat('x', 16777216);
        [$receipt] = $this->parts('databases/' . str_repeat('d', 64) . '.age', [$first, 'tail']);
        $reader = $this->reader();
        $target = Fixture::temp() . '/cipher.age';
        unset($first);
        $root = Fixture::temp();
        Fixture::write($root, 'job.json', json_encode(['profile' => $this->profileFile($this->profile()), 'receipt' => $receipt, 'target' => $target], JSON_THROW_ON_ERROR));
        $process = proc_open([PHP_BINARY, '-d', 'memory_limit=16M', __DIR__ . '/fixtures/r2-reader-process.php', $root . '/job.json'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]); $result = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $errors);
        $observed = json_decode($result, true, 8, JSON_THROW_ON_ERROR);
        self::assertLessThan(16777216, $observed['peakBytes']);
        self::assertSame($receipt['sha256'], $observed['sha256']);
        self::assertSame($receipt['sha256'], hash_file('sha256', $target));
        self::assertSame($receipt['bytes'], filesize($target));
    }

    public function testV4PackAndPageCiphertextRemainReadableWithoutTheirSource(): void
    {
        $reader = $this->reader();
        $key = 'packs/' . str_repeat('1', 64) . '/' . str_repeat('2', 64) . '.age';
        $pack = $this->receipt($key, 'raw pack ciphertext');
        $this->put($key, 'raw pack ciphertext');
        self::assertTrue($reader->hasObjects());
        $reader->verify($pack);
        [$page] = $this->parts('pages/' . str_repeat('3', 64) . '/' . str_repeat('4', 64) . '.age', ['page ciphertext']);
        $target = Fixture::temp() . '/page.age';
        $reader->download($page, $target);
        self::assertSame('page ciphertext', file_get_contents($target));
        $tooLarge = $pack; $tooLarge['bytes'] = 35651585;
        $this->reject(fn () => $reader->verify($tooLarge));
    }

    public function testOldRawRemainsReadableAndAmbiguousRepresentationFails(): void
    {
        $key = 'files/' . str_repeat('b', 64) . '/' . str_repeat('e', 64) . '.age';
        $receipt = $this->receipt($key, 'old raw');
        $this->put($key, 'old raw');
        $reader = $this->reader();
        $reader->verify($receipt);
        self::assertSame([$receipt], $reader->listFileObjects(str_repeat('b', 64)));
        $this->parts($key, ['old raw']);
        $this->reject(fn () => $reader->verify($receipt));
        $this->reject(fn () => $reader->listFileObjects(str_repeat('b', 64)));
    }

    public function testUnregisteredUploadDoesNotAppearAsRegisteredObject(): void
    {
        $this->put('uploads/' . str_repeat('a', 64) . '/0', 'unregistered');
        $reader = $this->reader();
        self::assertFalse($reader->hasObjects());
        self::assertSame([], $reader->listFileObjects(str_repeat('b', 64)));
        self::assertSame([], $reader->listCompleted());
    }

    public function testCompletedRequiresVerifiedManifestAndStatusMarksCorruption(): void
    {
        $snapshot = str_repeat('a', 64);
        [$receipt, $descriptor] = $this->parts('manifests/' . $snapshot . '.age', ['manifest cipher']);
        $record = ['snapshotId' => $snapshot, 'manifest' => $receipt];
        $this->put('commits/' . $snapshot . '.json', json_encode($record, JSON_THROW_ON_ERROR));
        $reader = $this->reader();
        self::assertSame($record, $reader->completed($snapshot));
        self::assertSame([$record], $reader->listCompleted());
        self::assertSame([$record + ['valid' => true]], $reader->listStatus());
        $this->put($descriptor['parts'][0]['key'], 'wrong');
        $this->reject(fn () => $reader->completed($snapshot));
        self::assertSame([['snapshotId' => $snapshot, 'valid' => false, 'error' => 'Snapshot verification failed.']], $reader->listStatus());
    }

    public function testMissingAndModifiedPartNeverPublishAndCleanStaging(): void
    {
        [$receipt, $descriptor] = $this->parts('databases/' . str_repeat('d', 64) . '.age', ['cipher']);
        $reader = $this->reader(); $parent = Fixture::temp();
        $this->put($descriptor['parts'][0]['key'], 'tamper');
        $this->reject(fn () => $reader->download($receipt, $parent . '/cipher'));
        self::assertSame(['.', '..'], scandir($parent));
        self::command(['action' => 'delete', 'key' => $this->physical($descriptor['parts'][0]['key'])]);
        $this->reject(fn () => $reader->download($receipt, $parent . '/cipher'));
        self::assertSame(['.', '..'], scandir($parent));
    }

    /** @dataProvider descriptorFaults */
    public function testStrictDescriptorRejectsInvalidShapes(string $fault): void
    {
        [$receipt, $descriptor] = $this->parts('databases/' . str_repeat('d', 64) . '.age', ['cipher']);
        switch ($fault) {
            case 'unknown': $descriptor['extra'] = true; break;
            case 'version': $descriptor['version'] = 2; break;
            case 'part-field': $descriptor['parts'][0]['extra'] = true; break;
            case 'ordinal': $descriptor['parts'][0]['key'] = 'uploads/' . str_repeat('a', 64) . '/00'; break;
            case 'prefix': $descriptor['parts'][0]['key'] = '../outside'; break;
            case 'hash': $descriptor['parts'][0]['sha256'] = str_repeat('0', 64); break;
            case 'extra-part': $descriptor['parts'][] = $descriptor['parts'][0]; break;
            case 'empty': $descriptor['parts'] = []; break;
            case 'too-many': $descriptor['parts'] = array_fill(0, 9, $descriptor['parts'][0]); break;
            case 'wrong-receipt': $descriptor['receipt']['sha256'] = str_repeat('0', 64); break;
            case 'whole-hash': $receipt['sha256'] = str_repeat('0', 64); $descriptor['receipt'] = $receipt; break;
            case 'length': $descriptor['parts'][0]['bytes']++; break;
        }
        $this->put('descriptors/' . $receipt['key'] . '.json', json_encode($descriptor, JSON_THROW_ON_ERROR));
        $parent = Fixture::temp();
        $this->reject(fn () => $this->reader()->download($receipt, $parent . '/cipher'));
        self::assertSame(['.', '..'], scandir($parent));
    }

    public static function descriptorFaults(): array
    {
        return array_map(static fn ($fault) => [$fault], ['unknown', 'version', 'part-field', 'ordinal', 'prefix', 'hash', 'extra-part', 'empty', 'too-many', 'wrong-receipt', 'whole-hash', 'length']);
    }

    public function testPartReorderingAndDifferentUploadsFail(): void
    {
        $key = 'databases/' . str_repeat('d', 64) . '.age';
        $receipt = ['key' => $key, 'bytes' => 16777217, 'sha256' => str_repeat('0', 64)];
        $part = ['key' => 'uploads/' . str_repeat('a', 64) . '/0', 'bytes' => 16777216, 'sha256' => str_repeat('0', 64)];
        foreach (['uploads/' . str_repeat('b', 64) . '/1', 'uploads/' . str_repeat('a', 64) . '/2'] as $second) {
            $descriptor = ['format' => 'wordpress-r2-cipher-parts', 'version' => 1, 'receipt' => $receipt, 'parts' => [$part, ['key' => $second, 'bytes' => 1, 'sha256' => str_repeat('0', 64)]]];
            $this->put('descriptors/' . $key . '.json', json_encode($descriptor, JSON_THROW_ON_ERROR));
            $this->reject(fn () => $this->reader()->verify($receipt));
        }
    }

    /** @dataProvider profileFaults */
    public function testStrictPrivateLocalProfile(string $fault): void
    {
        $profile = $this->profile();
        switch ($fault) {
            case 'extra': $profile['role'] = 'reader'; break;
            case 'format': $profile['format'] = 'wrb-synthetic-connection-v1'; break;
            case 'version': $profile['version'] = 2; break;
            case 'expired': $profile['expiresAt'] = time(); break;
            case 'external': $profile['endpoint'] = 'https://example.com'; break;
            case 'path': $profile['endpoint'] .= '/path'; break;
            case 'query': $profile['endpoint'] .= '?query'; break;
            case 'bucket': $profile['bucket'] = '../other'; break;
            case 'secret': $profile['secretAccessKey'] .= "\n"; break;
            case 'access': $profile['accessKeyId'] .= ':'; break;
        }
        $path = $this->profileFile($profile);
        $this->reject(fn () => new R2Reader($path));
    }

    public static function profileFaults(): array
    {
        return array_map(static fn ($fault) => [$fault], ['extra', 'format', 'version', 'expired', 'external', 'path', 'query', 'bucket', 'secret', 'access']);
    }

    public function testProfilePermissionsLinksAndWrongCredentialsAreRejected(): void
    {
        $path = $this->profileFile($this->profile()); chmod($path, 0644);
        $this->reject(fn () => new R2Reader($path));
        chmod($path, 0600); symlink($path, $path . '-link');
        $this->reject(fn () => new R2Reader($path . '-link'));
        if (!defined('CURLOPT_AWS_SIGV4')) { self::markTestSkipped('Direct R2 reading requires CURLOPT_AWS_SIGV4.'); }
        $reader = new R2Reader($this->profileFile(array_replace($this->profile(), ['secretAccessKey' => bin2hex(random_bytes(32))])));
        $this->reject(fn () => $reader->listCompleted());
    }

    public function testProfileExpiryIsCheckedAgainAtRequestTime(): void
    {
        if (!defined('CURLOPT_AWS_SIGV4')) { self::markTestSkipped('Direct R2 reading requires CURLOPT_AWS_SIGV4.'); }
        $reader = new R2Reader($this->profileFile(array_replace($this->profile(), ['expiresAt' => time() + 1])));
        usleep(1100000);
        $this->reject(fn () => $reader->listCompleted());
    }

    public function testMissingSigV4IsReportedBeforeAnyNetworkRequest(): void
    {
        if (defined('CURLOPT_AWS_SIGV4')) { self::markTestSkipped('Exercises a runtime without cURL SigV4.'); }
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('R2 reading requires cURL SigV4 support');
        new R2Reader($this->profileFile($this->profile()));
    }

    public function testListPaginationEncodesCursorAndRetainsPrefix(): void
    {
        if (!defined('CURLOPT_AWS_SIGV4')) { self::markTestSkipped('Direct R2 reading requires CURLOPT_AWS_SIGV4.'); }
        $prefix = $this->physical('commits/');
        $cursor = 'safe +/=&?% token';
        self::command(['action' => 'fault', 'pages' => [
            '<ListBucketResult><Prefix>' . $prefix . '</Prefix><IsTruncated>true</IsTruncated><NextContinuationToken>safe +/=&amp;?% token</NextContinuationToken></ListBucketResult>',
            '<ListBucketResult><Prefix>' . $prefix . '</Prefix><IsTruncated>false</IsTruncated><Contents><Key>' . $prefix . str_repeat('a', 64) . '.json</Key></Contents></ListBucketResult>',
        ]]);
        $reader = new R2Reader($this->profileFile(array_replace($this->profile(), ['endpoint' => self::$service['faultEndpoint']])));
        self::assertTrue($reader->hasObjects());
        $requests = self::command(['action' => 'requests']);
        self::assertSame(2, $requests['requests']);
        parse_str(parse_url($requests['urls'][1], PHP_URL_QUERY), $query);
        self::assertSame($cursor, $query['continuation-token']);
        self::assertSame($prefix, $query['prefix']);
        self::assertStringContainsString('continuation-token=' . rawurlencode($cursor), $requests['urls'][1]);
    }

    public function testEquivalentReceiptFieldOrderDoesNotChangeItsMeaning(): void
    {
        [$receipt] = $this->parts('databases/' . str_repeat('d', 64) . '.age', ['cipher']);
        $this->reader()->verify(['sha256' => $receipt['sha256'], 'bytes' => $receipt['bytes'], 'key' => $receipt['key']]);
        $this->addToAssertionCount(1);
    }

    /** @dataProvider xmlFaults */
    public function testUnsafeMalformedAndCyclingListsAreRejected(string $body): void
    {
        if (!defined('CURLOPT_AWS_SIGV4')) { self::markTestSkipped('Direct R2 reading requires CURLOPT_AWS_SIGV4.'); }
        self::command(['action' => 'fault', 'body' => str_replace('UNIT', $this->physical('commits/'), $body)]);
        $reader = new R2Reader($this->profileFile(array_replace($this->profile(), ['endpoint' => self::$service['faultEndpoint']])));
        $this->reject(fn () => $reader->listCompleted());
    }

    public static function xmlFaults(): array
    {
        return [
            ['<!DOCTYPE ListBucketResult [<!ENTITY x SYSTEM "file:///etc/passwd">]><ListBucketResult><IsTruncated>false</IsTruncated></ListBucketResult>'],
            ['<ListBucketResult><IsTruncated>false</IsTruncated><Contents><Key>outside</Key></Contents></ListBucketResult>'],
            ['<ListBucketResult><IsTruncated>true</IsTruncated><NextContinuationToken>same +/?&amp;=</NextContinuationToken></ListBucketResult>'],
            ['<ListBucketResult><IsTruncated>false</IsTruncated><Contents><Key>UNITinvalid</Key></Contents></ListBucketResult>'],
            ['<ListBucketResult><IsTruncated>maybe</IsTruncated></ListBucketResult>'],
            ['<ListBucketResult><IsTruncated><Nested>false</Nested></IsTruncated></ListBucketResult>'],
            ['<ListBucketResult><x:IsTruncated xmlns:x="urn:other">false</x:IsTruncated></ListBucketResult>'],
            ['<ListBucketResult><IsTruncated>false</IsTruncated>' . str_repeat('<Contents><Key>UNITsame</Key></Contents>', 1001) . '</ListBucketResult>'],
            ['<ListBucketResult><IsTruncated>false</IsTruncated><Contents><Key>UNITsame</Key></Contents><Contents><Key>UNITsame</Key></Contents></ListBucketResult>'],
            ['<ListBucketResult>'],
            [str_repeat('x', 1048577)],
        ];
    }

    public function testRedirectIsNeverFollowedAndCutResponseNeverPublished(): void
    {
        if (!defined('CURLOPT_AWS_SIGV4')) { self::markTestSkipped('Direct R2 reading requires CURLOPT_AWS_SIGV4.'); }
        $reader = new R2Reader($this->profileFile(array_replace($this->profile(), ['endpoint' => self::$service['faultEndpoint']])));
        self::command(['action' => 'fault', 'mode' => 'redirect']);
        $this->reject(fn () => $reader->listCompleted());
        self::assertSame(1, self::command(['action' => 'requests'])['requests']);
        self::command(['action' => 'fault', 'mode' => 'cut']);
        $receipt = $this->receipt('databases/' . str_repeat('a', 64) . '.age', str_repeat('x', 100));
        $parent = Fixture::temp();
        $this->reject(fn () => $reader->download($receipt, $parent . '/cipher'));
        self::assertSame(['.', '..'], scandir($parent));
    }

    private function reader(): R2Reader
    {
        if (!defined('CURLOPT_AWS_SIGV4')) { self::markTestSkipped('Direct R2 reading requires CURLOPT_AWS_SIGV4.'); }
        return new R2Reader($this->profileFile($this->profile()));
    }
    private function profile(): array
    {
        return array_merge(['format' => 'wrb-local-r2-reader-v1', 'version' => 1, 'unitId' => $this->unit,
            'endpoint' => self::$service['endpoint'], 'bucket' => 'reader-test',
            'expiresAt' => time() + 3600], self::$credentials);
    }
    private function profileFile(array $profile): string
    {
        $root = Fixture::temp(); Fixture::write($root, 'reader.json', json_encode($profile, JSON_THROW_ON_ERROR));
        return $root . '/reader.json';
    }
    private function physical(string $key): string { return 'units/' . $this->unit . '/' . $key; }
    private function put(string $key, string $data): void
    {
        self::command(['action' => 'put', 'key' => $this->physical($key), 'body' => base64_encode($data)]);
    }
    private function receipt(string $key, string $body): array { return ['key' => $key, 'bytes' => strlen($body), 'sha256' => hash('sha256', $body)]; }
    private function parts(string $key, array $bodies): array
    {
        $upload = bin2hex(random_bytes(32)); $hash = hash_init('sha256'); $bytes = 0; $parts = [];
        foreach ($bodies as $ordinal => $body) {
            $partKey = 'uploads/' . $upload . '/' . $ordinal;
            $this->put($partKey, $body); hash_update($hash, $body); $bytes += strlen($body);
            $parts[] = $this->receipt($partKey, $body);
        }
        $receipt = ['key' => $key, 'bytes' => $bytes, 'sha256' => hash_final($hash)];
        $descriptor = ['format' => 'wordpress-r2-cipher-parts', 'version' => 1, 'receipt' => $receipt, 'parts' => $parts];
        $this->put('descriptors/' . $key . '.json', json_encode($descriptor, JSON_THROW_ON_ERROR));
        return [$receipt, $descriptor];
    }
    private function reject(callable $action): void
    {
        try { $action(); } catch (\RuntimeException | \InvalidArgumentException $error) {
            self::assertStringNotContainsString(self::$credentials['secretAccessKey'], $error->getMessage());
            self::assertStringNotContainsString(self::$credentials['accessKeyId'], $error->getMessage());
            return;
        }
        self::fail('The invalid reader operation must fail.');
    }
    private static function command(array $command): array
    {
        $line = json_encode($command, JSON_THROW_ON_ERROR) . "\n";
        $offset = 0;
        while ($offset < strlen($line)) {
            $written = fwrite(self::$pipes[0], substr($line, $offset));
            if ($written === false || $written === 0) { throw new \RuntimeException('Fixture command failed.'); }
            $offset += $written;
        }
        fflush(self::$pipes[0]);
        return self::readReply();
    }
    private static function readReply(): array
    {
        $line = fgets(self::$pipes[1]);
        if ($line === false) { throw new \RuntimeException('R2 fixture did not respond. ' . file_get_contents(self::$serviceRoot . '/stderr.log')); }
        return json_decode($line, true, 8, JSON_THROW_ON_ERROR);
    }
}
