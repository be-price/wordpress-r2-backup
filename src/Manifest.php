<?php
declare(strict_types=1);

namespace WordPressR2Backup;

use InvalidArgumentException;

final class Manifest
{
    public const MAX_BYTES = 16777216;
    public const MAX_ENTRIES = 100000;
    public const MAX_RESTORED_BYTES = 1099511627776;

    private array $data;

    private function __construct(array $data)
    {
        $this->data = $data;
    }

    public static function fromArray(array $data): self
    {
        if (($data['version'] ?? null) === 4) { V4Format::validateRoot($data); return new self($data); }
        try {
            $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $error) {
            throw new InvalidArgumentException('Manifest cannot be encoded.', 0, $error);
        }
        if (strlen($json) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Manifest exceeds the size limit.');
        }
        $fields = ['format', 'version', 'dataClass', 'unitId', 'snapshotId', 'keySetId', 'recipients', 'capturedAt', 'files', 'database'];
        if (($data['version'] ?? null) === 2) { $fields[] = 'captureReport'; }
        if (($data['version'] ?? null) === 3) { $fields[] = 'wordpress'; }
        self::fields($data, $fields);
        if ($data['format'] !== 'wordpress-r2-backup' || !in_array($data['version'], [1, 2, 3], true)
            || $data['dataClass'] !== ($data['version'] === 3 ? 'wordpress' : 'synthetic')) {
            throw new InvalidArgumentException('Unsupported backup format.');
        }
        foreach (['unitId', 'snapshotId', 'keySetId'] as $field) {
            self::hex($data[$field]);
        }
        if (!is_array($data['recipients']) || self::keySetId($data['recipients']) !== $data['keySetId']) {
            throw new InvalidArgumentException('Recipient set does not match its identifier.');
        }
        self::timestamp($data['capturedAt']);
        if (!is_array($data['files']) || !array_is_list($data['files']) || count($data['files']) > self::MAX_ENTRIES) {
            throw new InvalidArgumentException('Invalid manifest entry count.');
        }
        $total = 0;
        foreach ($data['files'] as $entry) {
            if (!is_array($entry)) {
                throw new InvalidArgumentException('Invalid manifest entry.');
            }
            if (($entry['type'] ?? null) === 'directory') {
                self::fields($entry, ['path', 'type']);
            } elseif (($entry['type'] ?? null) === 'file') {
                self::fields($entry, ['path', 'type', 'size', 'sha256', 'object']);
                self::content($entry);
                if (!preg_match('~^files/' . $data['keySetId'] . '/[a-f0-9]{64}\.age$~D', $entry['object']['key'])) {
                    throw new InvalidArgumentException('File object belongs to a different recipient set.');
                }
                $total += $entry['size'];
                if ($total > self::MAX_RESTORED_BYTES) {
                    throw new InvalidArgumentException('Restored data exceeds the size limit.');
                }
            } else {
                throw new InvalidArgumentException('Unsupported entry type.');
            }
        }
        FileSafety::validateEntries($data['files']);
        if (!is_array($data['database'])) {
            throw new InvalidArgumentException('Invalid database entry.');
        }
        self::fields($data['database'], ['size', 'sha256', 'object']);
        self::content($data['database']);
        if ($data['database']['object']['key'] !== 'databases/' . $data['snapshotId'] . '.age'
            || $total + $data['database']['size'] > self::MAX_RESTORED_BYTES) {
            throw new InvalidArgumentException('Invalid database reference or restored size.');
        }
        if ($data['version'] === 2) {
            if (!is_array($data['captureReport'])) { throw new InvalidArgumentException('Invalid capture report.'); }
            CaptureReport::validate($data['captureReport'], $data['files'], $data['database']);
        }
        if ($data['version'] === 3) {
            if (!is_array($data['wordpress'])) { throw new InvalidArgumentException('Invalid WordPress capture metadata.'); }
            $wordpressFields = ['siteUrl', 'policySha256', 'evidence'];
            if (isset($data['wordpress']['archives'])) { $wordpressFields[] = 'archives'; }
            self::fields($data['wordpress'], $wordpressFields);
            if (isset($data['wordpress']['archives'])) {
                $archives = $data['wordpress']['archives'];
                if ($data['files'] !== [] || !is_array($archives) || !array_is_list($archives) || count($archives) > 10000) {
                    throw new InvalidArgumentException('Invalid WordPress archive inventory.');
                }
                $keys = [];
                foreach ($archives as $archive) {
                    if (!is_array($archive)) { throw new InvalidArgumentException('Invalid WordPress archive.'); }
                    self::fields($archive, ['size', 'sha256', 'object', 'fileCount', 'directoryCount']);
                    self::content($archive);
                    $key = $archive['object']['key'];
                    if (!preg_match('~^files/' . $data['keySetId'] . '/[a-f0-9]{64}\.age$~D', $key) || isset($keys[$key])) {
                        throw new InvalidArgumentException('Invalid or repeated archive object.');
                    }
                    $keys[$key] = true;
                    foreach (['fileCount', 'directoryCount'] as $count) {
                        if (!is_int($archive[$count]) || $archive[$count] < 0 || $archive[$count] > 500000) {
                            throw new InvalidArgumentException('Invalid archive entry count.');
                        }
                    }
                    $total += $archive['size'];
                    if ($total + $data['database']['size'] > self::MAX_RESTORED_BYTES) { throw new InvalidArgumentException('Restored data exceeds the size limit.'); }
                }
            }
            if (!is_string($data['wordpress']['siteUrl']) || strlen($data['wordpress']['siteUrl']) > 2048
                || !filter_var($data['wordpress']['siteUrl'], FILTER_VALIDATE_URL) || !is_array($data['wordpress']['evidence'])) {
                throw new InvalidArgumentException('Invalid WordPress capture metadata.');
            }
            self::hex($data['wordpress']['policySha256']);
            $events = $data['wordpress']['evidence']['events'] ?? null;
            if (!is_array($events) || !array_is_list($events) || count($events) > 10000) { throw new InvalidArgumentException('Invalid WordPress event inventory.'); }
            foreach ($events as $event) {
                if (!is_string($event) || $event === '' || strlen($event) > 256 || str_contains($event, "\0")) { throw new InvalidArgumentException('Invalid WordPress event name.'); }
            }
        }
        return new self($data);
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public static function keySetId(array $recipients): string
    {
        if (!array_is_list($recipients) || count($recipients) !== 2 || $recipients[0] === $recipients[1]) {
            throw new InvalidArgumentException('Exactly two distinct age recipients are required.');
        }
        foreach ($recipients as $recipient) {
            self::recipient($recipient);
        }
        sort($recipients, SORT_STRING);
        return hash('sha256', json_encode($recipients, JSON_THROW_ON_ERROR));
    }

    public static function validateReceipt(array $receipt): void
    {
        if (is_string($receipt['key'] ?? null) && (strpos($receipt['key'], 'packs/') === 0 || strpos($receipt['key'], 'pages/') === 0)) {
            V4Format::validateReceipt($receipt); return;
        }
        self::fields($receipt, ['key', 'bytes', 'sha256']);
        if (!is_string($receipt['key']) || !preg_match('~^(?:files/[a-f0-9]{64}/[a-f0-9]{64}\.age|(?:databases|manifests)/[a-f0-9]{64}\.age)$~D', $receipt['key'])
            || !is_int($receipt['bytes']) || $receipt['bytes'] < 1 || $receipt['bytes'] > self::MAX_RESTORED_BYTES + 1073741824) {
            throw new InvalidArgumentException('Invalid ciphertext receipt.');
        }
        self::hex($receipt['sha256']);
    }

    private static function content(array $entry): void
    {
        if (!isset($entry['size']) || !is_int($entry['size']) || $entry['size'] < 0 || $entry['size'] > self::MAX_RESTORED_BYTES) {
            throw new InvalidArgumentException('Invalid plaintext size.');
        }
        self::hex($entry['sha256']);
        if (!is_array($entry['object'])) {
            throw new InvalidArgumentException('Invalid ciphertext reference.');
        }
        self::validateReceipt($entry['object']);
    }

    private static function fields(array $data, array $fields): void
    {
        $actual = array_keys($data);
        sort($actual);
        sort($fields);
        if ($actual !== $fields) {
            throw new InvalidArgumentException('Unexpected or missing manifest fields.');
        }
    }

    private static function hex($value): void
    {
        if (!is_string($value) || !preg_match('/^[a-f0-9]{64}$/D', $value)) {
            throw new InvalidArgumentException('Expected a lowercase 256-bit identifier.');
        }
    }

    private static function timestamp($value): void
    {
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d+)?(?:Z|[+-](\d{2}):(\d{2}))$/D', $value, $parts)
            || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) || (int) $parts[4] > 23
            || (int) $parts[5] > 59 || (int) $parts[6] > 59 || (isset($parts[7]) && (int) $parts[7] > 23)
            || (isset($parts[8]) && (int) $parts[8] > 59)) {
            throw new InvalidArgumentException('Expected an RFC3339 capture timestamp.');
        }
    }

    private static function recipient($recipient): void
    {
        if (!is_string($recipient) || !preg_match('/^age1[qpzry9x8gf2tvdw0s3jn54khce6mua7l]{58}$/D', $recipient)) {
            throw new InvalidArgumentException('Expected an age X25519 recipient.');
        }
        $alphabet = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
        $values = [3, 3, 3, 0, 1, 7, 5];
        foreach (str_split(substr($recipient, 4)) as $character) {
            $values[] = strpos($alphabet, $character);
        }
        $checksum = 1;
        foreach ($values as $value) {
            $top = $checksum >> 25;
            $checksum = (($checksum & 0x1ffffff) << 5) ^ $value;
            foreach ([0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3] as $i => $generator) {
                if (($top >> $i) & 1) {
                    $checksum ^= $generator;
                }
            }
        }
        if ($checksum !== 1 || (strpos($alphabet, $recipient[55]) & 15) !== 0) {
            throw new InvalidArgumentException('Invalid age recipient checksum or padding.');
        }
    }
}
