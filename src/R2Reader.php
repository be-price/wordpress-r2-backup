<?php
declare(strict_types=1);

namespace WordPressR2Backup;

use RuntimeException;

final class R2Reader implements ObjectReader
{
    private const PART_BYTES = 16777216;
    private const MAX_CIPHER_BYTES = 134217728;
    private const MAX_LIST_BYTES = 1048576;
    private const MAX_LIST_OBJECTS = 10000;
    private array $connection;

    public function __construct(string $privateConnectionFile)
    {
        FileSafety::assertRegularFile($privateConnectionFile);
        $stat = lstat($privateConnectionFile);
        if (realpath($privateConnectionFile) !== $privateConnectionFile || ($stat['mode'] & 0777) !== 0600
            || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid()) || $stat['size'] > 8192) {
            throw new RuntimeException('Expected an owner-only R2 reader profile of at most 8 KiB.');
        }
        $body = @file_get_contents($privateConnectionFile, false, null, 0, 8193);
        if (!is_string($body) || strlen($body) > 8192) { throw new RuntimeException('Cannot read bounded R2 reader profile.'); }
        $profile = self::decode($body);
        self::fields($profile, ['format', 'version', 'unitId', 'endpoint', 'bucket', 'accessKeyId', 'secretAccessKey', 'expiresAt']);
        if ($profile['format'] !== 'wrb-local-r2-reader-v1' || $profile['version'] !== 1
            || !is_string($profile['endpoint']) || !preg_match('~^http://127\.0\.0\.1:([1-9][0-9]{0,4})$~D', $profile['endpoint'], $port)
            || (int) $port[1] > 65535 || !is_string($profile['bucket'])
            || !preg_match('/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/D', $profile['bucket'])
            || !is_string($profile['accessKeyId']) || !preg_match('/^[a-zA-Z0-9]{16,128}$/D', $profile['accessKeyId'])
            || !is_string($profile['secretAccessKey']) || !preg_match('~^[a-zA-Z0-9/+=]{32,256}$~D', $profile['secretAccessKey'])
            || !is_int($profile['expiresAt']) || $profile['expiresAt'] <= time()) {
            throw new RuntimeException('Invalid or expired local R2 reader profile. Production endpoints are unsupported.');
        }
        self::identifier($profile['unitId']);
        if (!function_exists('curl_init') || !defined('CURLOPT_AWS_SIGV4') || !class_exists(\DOMDocument::class)) {
            throw new RuntimeException('R2 reading requires cURL SigV4 support and the DOM extension.');
        }
        $this->connection = $profile;
    }

    public function unitId(): string { return $this->connection['unitId']; }

    public function verify(array $receipt): void
    {
        self::receipt($receipt);
        $this->streamLogical($receipt['key'], $receipt);
    }

    public function download(array $receipt, string $newCipherFile): void
    {
        self::receipt($receipt);
        FileSafety::assertNoLinks($newCipherFile);
        $parent = FileSafety::privateDirectory(dirname($newCipherFile));
        if (@lstat($newCipherFile) !== false) { throw new RuntimeException('The ciphertext output already exists.'); }
        $temporary = $parent . '/.download-' . bin2hex(random_bytes(16));
        $output = @fopen($temporary, 'x+b');
        if ($output === false) { throw new RuntimeException('Cannot create private ciphertext staging file.'); }
        try {
            if (!chmod($temporary, 0600)) { throw new RuntimeException('Cannot protect ciphertext staging file.'); }
            $this->streamLogical($receipt['key'], $receipt, $output);
            if (!fflush($output) || !\WordPressR2Backup\FileSafety::sync($output)) { throw new RuntimeException('Cannot persist verified ciphertext.'); }
            FileSafety::privateDirectory($parent);
            FileSafety::assertNoLinks($newCipherFile);
            if (!@link($temporary, $newCipherFile)) { throw new RuntimeException('Cannot publish ciphertext without replacement.'); }
        } finally {
            fclose($output);
            if (!@unlink($temporary)) { throw new RuntimeException('Cannot remove owned ciphertext staging file.'); }
        }
    }

    public function completed(string $snapshotId): array
    {
        self::identifier($snapshotId);
        $body = $this->metadata('commits/' . $snapshotId . '.json');
        if ($body === null) { throw new RuntimeException('Completion record is missing.'); }
        $record = self::decode($body);
        self::fields($record, ['snapshotId', 'manifest']);
        if ($record['snapshotId'] !== $snapshotId || !is_array($record['manifest'])
            || ($record['manifest']['key'] ?? null) !== 'manifests/' . $snapshotId . '.age') {
            throw new RuntimeException('Invalid completion record.');
        }
        try { $this->verify($record['manifest']); }
        catch (\InvalidArgumentException $error) { throw new RuntimeException('Invalid completion manifest receipt.', 0, $error); }
        return $record;
    }

    public function listCompleted(): array
    {
        return array_map(fn (string $snapshot): array => $this->completed($snapshot), $this->snapshotIds());
    }

    public function listStatus(): array
    {
        $result = [];
        foreach ($this->snapshotIds() as $snapshot) {
            try { $result[] = $this->completed($snapshot) + ['valid' => true]; }
            catch (RuntimeException $ignoredError) { $result[] = ['snapshotId' => $snapshot, 'valid' => false, 'error' => 'Snapshot verification failed.']; }
        }
        return $result;
    }

    public function listFileObjects(string $keySetId): array
    {
        self::identifier($keySetId);
        $keys = [];
        foreach ($this->listKeys('files/' . $keySetId . '/') as $key) {
            if (!preg_match('~^files/' . $keySetId . '/[a-f0-9]{64}\.age$~D', $key)) { throw new RuntimeException('Invalid raw file object name.'); }
            $keys[$key] = true;
        }
        foreach ($this->listKeys('descriptors/files/' . $keySetId . '/') as $key) {
            if (!preg_match('~^descriptors/(files/' . $keySetId . '/[a-f0-9]{64}\.age)\.json$~D', $key, $match)) {
                throw new RuntimeException('Invalid file descriptor name.');
            }
            if (isset($keys[$match[1]])) { throw new RuntimeException('Ambiguous raw and descriptor ciphertext.'); }
            $keys[$match[1]] = true;
        }
        ksort($keys, SORT_STRING);
        $result = [];
        foreach (array_keys($keys) as $key) { $result[] = $this->streamLogical($key); }
        return $result;
    }

    public function hasObjects(): bool
    {
        foreach (['commits/', 'descriptors/', 'files/', 'databases/', 'manifests/', 'packs/', 'pages/'] as $prefix) {
            if ($this->listKeys($prefix) !== []) { return true; }
        }
        return false;
    }

    private function snapshotIds(): array
    {
        $result = [];
        foreach ($this->listKeys('commits/') as $key) {
            if (!preg_match('~^commits/([a-f0-9]{64})\.json$~D', $key, $match)) { throw new RuntimeException('Invalid completion record name.'); }
            $result[] = $match[1];
        }
        sort($result, SORT_STRING);
        return $result;
    }

    private function streamLogical(string $key, ?array $expected = null, $output = null): array
    {
        $descriptorBody = $this->metadata('descriptors/' . $key . '.json');
        $rawExists = $this->request('HEAD', $key, [], static fn (string $chunk): int => strlen($chunk), 0) === 200;
        if ($descriptorBody !== null && $rawExists) { throw new RuntimeException('Ambiguous raw and descriptor ciphertext.'); }
        if ($descriptorBody === null && !$rawExists) { throw new RuntimeException('Ciphertext is missing.'); }
        $parts = null;
        if ($descriptorBody !== null) {
            $descriptor = self::descriptor(self::decode($descriptorBody), $key);
            if ($expected !== null && ($descriptor['receipt']['key'] !== $expected['key']
                || $descriptor['receipt']['bytes'] !== $expected['bytes'] || $descriptor['receipt']['sha256'] !== $expected['sha256'])) {
                throw new RuntimeException('Descriptor receipt does not match.');
            }
            $expected = $descriptor['receipt'];
            $parts = $descriptor['parts'];
        }
        $whole = hash_init('sha256'); $total = 0;
        foreach ($parts ?? [['key' => $key, 'bytes' => $expected['bytes'] ?? self::MAX_CIPHER_BYTES, 'sha256' => null]] as $part) {
            $hash = hash_init('sha256'); $bytes = 0;
            $consume = static function (string $chunk) use ($hash, $whole, &$bytes, &$total, $output): int {
                $length = strlen($chunk);
                if (is_resource($output)) {
                    $offset = 0;
                    while ($offset < $length) {
                        $written = fwrite($output, substr($chunk, $offset));
                        if ($written === false || $written === 0) { return 0; }
                        $offset += $written;
                    }
                }
                hash_update($hash, $chunk); hash_update($whole, $chunk);
                $bytes += $length; $total += $length;
                return $length;
            };
            if ($this->request('GET', $part['key'], [], $consume, $part['bytes']) !== 200) { throw new RuntimeException('Ciphertext part is missing.'); }
            $actualHash = hash_final($hash);
            if (($parts !== null || $expected !== null) && $bytes !== $part['bytes']) { throw new RuntimeException('Ciphertext part length does not match.'); }
            if ($part['sha256'] !== null && !hash_equals($part['sha256'], $actualHash)) { throw new RuntimeException('Ciphertext part hash does not match.'); }
        }
        $receipt = ['key' => $key, 'bytes' => $total, 'sha256' => hash_final($whole)];
        self::receipt($receipt);
        if ($expected !== null && ($total !== $expected['bytes'] || !hash_equals($expected['sha256'], $receipt['sha256']))) {
            throw new RuntimeException('Ciphertext does not match its receipt.');
        }
        return $receipt;
    }

    private static function descriptor(array $descriptor, string $key): array
    {
        self::fields($descriptor, ['format', 'version', 'receipt', 'parts']);
        if ($descriptor['format'] !== 'wordpress-r2-cipher-parts' || $descriptor['version'] !== 1
            || !is_array($descriptor['receipt']) || !is_array($descriptor['parts']) || !array_is_list($descriptor['parts'])
            || count($descriptor['parts']) < 1 || count($descriptor['parts']) > 8) { throw new RuntimeException('Invalid ciphertext descriptor.'); }
        self::receipt($descriptor['receipt']);
        if ($descriptor['receipt']['key'] !== $key) { throw new RuntimeException('Descriptor belongs to another object.'); }
        $total = 0; $upload = null;
        foreach ($descriptor['parts'] as $ordinal => $part) {
            if (!is_array($part)) { throw new RuntimeException('Invalid ciphertext part.'); }
            self::fields($part, ['key', 'bytes', 'sha256']);
            if (!is_string($part['key']) || !preg_match('~^uploads/([a-f0-9]{64})/(0|[1-9][0-9]*)$~D', $part['key'], $match)
                || $match[2] !== (string) $ordinal || ($upload !== null && $upload !== $match[1])
                || !is_int($part['bytes']) || $part['bytes'] < 1 || $part['bytes'] > self::PART_BYTES
                || ($ordinal < count($descriptor['parts']) - 1 && $part['bytes'] !== self::PART_BYTES)) {
                throw new RuntimeException('Invalid ciphertext part order or length.');
            }
            self::identifier($part['sha256']);
            $upload = $match[1]; $total += $part['bytes'];
        }
        if ($total !== $descriptor['receipt']['bytes']) { throw new RuntimeException('Descriptor total length does not match.'); }
        return $descriptor;
    }

    private function metadata(string $key): ?string
    {
        $body = '';
        $status = $this->request('GET', $key, [], static function (string $chunk) use (&$body): int { $body .= $chunk; return strlen($chunk); }, 4096);
        return $status === 404 ? null : $body;
    }

    private function listKeys(string $prefix): array
    {
        $physicalPrefix = 'units/' . $this->unitId() . '/' . $prefix;
        $result = []; $cursor = null; $seenCursors = [];
        for ($page = 0; $page < 100; $page++) {
            $query = ['list-type' => '2', 'prefix' => $physicalPrefix, 'max-keys' => '1000'];
            if ($cursor !== null) { $query['continuation-token'] = $cursor; }
            $body = '';
            if ($this->request('GET', null, $query, static function (string $chunk) use (&$body): int { $body .= $chunk; return strlen($chunk); }, self::MAX_LIST_BYTES) !== 200) {
                throw new RuntimeException('Cannot enumerate R2 objects.');
            }
            [$keys, $cursor] = self::listPage($body, $physicalPrefix);
            foreach ($keys as $key) {
                if (isset($result[$key]) || count($result) >= self::MAX_LIST_OBJECTS) { throw new RuntimeException('Duplicate or excessive R2 object listing.'); }
                $result[$key] = substr($key, strlen('units/' . $this->unitId() . '/'));
            }
            if ($cursor === null) { return array_values($result); }
            if (isset($seenCursors[$cursor])) { throw new RuntimeException('R2 listing cursor repeated.'); }
            $seenCursors[$cursor] = true;
        }
        throw new RuntimeException('R2 listing exceeds its page limit.');
    }

    private static function listPage(string $body, string $prefix): array
    {
        if (preg_match('/<!\s*(?:DOCTYPE|ENTITY)/i', $body)) { throw new RuntimeException('XML document types and entities are forbidden.'); }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument();
            if (!$document->loadXML($body, LIBXML_NONET) || $document->doctype !== null
                || $document->documentElement === null || $document->documentElement->localName !== 'ListBucketResult'
                || !in_array($document->documentElement->namespaceURI, [null, '', 'http://s3.amazonaws.com/doc/2006-03-01/'], true)) {
                throw new RuntimeException('Invalid R2 list XML.');
            }
            $xpath = new \DOMXPath($document);
            foreach ($document->documentElement->childNodes as $child) {
                if ($child instanceof \DOMElement && $child->namespaceURI !== $document->documentElement->namespaceURI) {
                    throw new RuntimeException('Unexpected R2 list XML namespace.');
                }
            }
            $truncated = $xpath->query('/*/*[local-name()="IsTruncated"]');
            $tokens = $xpath->query('/*/*[local-name()="NextContinuationToken"]');
            $prefixes = $xpath->query('/*/*[local-name()="Prefix"]');
            if ($truncated->length !== 1 || $truncated->item(0)->childElementCount !== 0
                || !in_array($truncated->item(0)->textContent, ['true', 'false'], true)
                || $tokens->length > 1 || $prefixes->length > 1 || ($prefixes->length === 1 && $prefixes->item(0)->textContent !== $prefix)) {
                throw new RuntimeException('Invalid R2 list pagination.');
            }
            $contents = $xpath->query('/*/*[local-name()="Contents"]');
            if ($contents->length > 1000) { throw new RuntimeException('R2 list page has too many objects.'); }
            $keys = [];
            foreach ($contents as $entry) {
                $names = $xpath->query('./*[local-name()="Key"]', $entry);
                if ($names->length !== 1 || $names->item(0)->childElementCount !== 0
                    || $names->item(0)->namespaceURI !== $document->documentElement->namespaceURI) { throw new RuntimeException('Invalid R2 list key.'); }
                $key = $names->item(0)->textContent;
                if (!str_starts_with($key, $prefix) || strlen($key) > 1024 || preg_match('/[\x00-\x20\x7f]/', $key)) {
                    throw new RuntimeException('R2 list key escaped its requested prefix.');
                }
                $keys[] = $key;
            }
            $isTruncated = $truncated->item(0)->textContent === 'true';
            $cursor = $tokens->length === 1 ? $tokens->item(0)->textContent : null;
            if (($isTruncated && ($cursor === null || $cursor === '' || strlen($cursor) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $cursor)))
                || (!$isTruncated && $cursor !== null)) { throw new RuntimeException('Invalid R2 list continuation token.'); }
            return [$keys, $cursor];
        } finally {
            libxml_clear_errors(); libxml_use_internal_errors($previous);
        }
    }

    private function request(string $method, ?string $key, array $query, \Closure $consume, int $limit): int
    {
        if ($this->connection['expiresAt'] <= time()) { throw new RuntimeException('Local R2 reader profile has expired.'); }
        $path = '/cdn-cgi/local/r2/s3/' . rawurlencode($this->connection['bucket']);
        if ($key !== null) {
            $physicalKey = 'units/' . $this->unitId() . '/' . $key;
            $path .= '/' . implode('/', array_map('rawurlencode', explode('/', $physicalKey)));
        }
        $url = $this->connection['endpoint'] . $path . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        $curl = curl_init($url);
        if ($curl === false) { throw new RuntimeException('Cannot initialize local R2 reader.'); }
        $received = 0;
        try {
            $configured = curl_setopt_array($curl, [
                CURLOPT_AWS_SIGV4 => 'aws:amz:auto:s3',
                CURLOPT_USERPWD => $this->connection['accessKeyId'] . ':' . $this->connection['secretAccessKey'],
                CURLOPT_CUSTOMREQUEST => $method, CURLOPT_NOBODY => $method === 'HEAD',
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
                CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_NETRC => CURL_NETRC_IGNORED,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP,
                CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 30,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use ($consume, $limit, &$received): int {
                    $length = strlen($chunk);
                    if ($length > $limit - $received) { return 0; }
                    $received += $length;
                    return $consume($chunk);
                },
            ]);
            if (!$configured || curl_exec($curl) === false) { throw new RuntimeException('Local R2 request failed or exceeded its response limit.'); }
            if ($this->connection['expiresAt'] <= time()) { throw new RuntimeException('Local R2 reader profile has expired.'); }
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if (!in_array($status, [200, 404], true)) { throw new RuntimeException('Local R2 request was rejected.'); }
            return $status;
        } finally { curl_close($curl); }
    }

    private static function decode(string $body): array
    {
        try { $value = json_decode($body, true, 16, JSON_THROW_ON_ERROR); }
        catch (\JsonException $ignoredError) { throw new RuntimeException('Invalid bounded R2 JSON record.'); }
        if (!is_array($value)) { throw new RuntimeException('Invalid bounded R2 JSON object.'); }
        return $value;
    }

    private static function fields(array $value, array $expected): void
    {
        $actual = array_keys($value); sort($actual); sort($expected);
        if ($actual !== $expected) { throw new RuntimeException('Unexpected R2 record fields.'); }
    }

    private static function receipt(array $receipt): void
    {
        Manifest::validateReceipt($receipt);
        if ($receipt['bytes'] > self::MAX_CIPHER_BYTES) { throw new RuntimeException('Ciphertext exceeds the local R2 reader limit.'); }
    }

    private static function identifier($identifier): void
    {
        if (!is_string($identifier) || !preg_match('/^[a-f0-9]{64}$/D', $identifier)) { throw new RuntimeException('Invalid lowercase 256-bit identifier.'); }
    }
}
