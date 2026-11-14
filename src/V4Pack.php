<?php
declare(strict_types=1);

namespace WordPressR2Backup;

use InvalidArgumentException;
use RuntimeException;

final class V4Pack
{
    public static function write(AgeProcess $age, array $recipients, string $privateDirectory, string $scopeId, string $lane, array $contents, ?string $reservedKey = null): array
    {
        FileSafety::privateDirectory($privateDirectory);
        Manifest::keySetId($recipients);
        if (!array_is_list($contents) || $contents === [] || count($contents) > V4Format::MAX_PACK_MEMBERS) {
            throw new InvalidArgumentException('Invalid v4 pack contents.');
        }
        $key = $reservedKey ?? V4Format::packKey($scopeId); $members = [];
        if (!preg_match('~\Apacks/[a-f0-9]{64}/[a-f0-9]{64}\.age\z~D', $key) || explode('/', $key)[1] !== $scopeId) {
            throw new InvalidArgumentException('Reserved v4 pack belongs to another scope.');
        }
        foreach ($contents as $content) {
            if (!is_array($content) || array_diff(array_keys($content), ['id', 'size', 'sha256', 'bytes']) !== []
                || count($content) !== 4 || !is_string($content['bytes'] ?? null)
                || !is_int($content['size'] ?? null) || $content['size'] !== strlen($content['bytes'])
                || !is_string($content['sha256'] ?? null) || !hash_equals($content['sha256'], hash('sha256', $content['bytes']))) {
                throw new InvalidArgumentException('V4 pack content differs from its declared size or digest.');
            }
            $members[] = ['id' => $content['id'] ?? null, 'size' => $content['size'], 'sha256' => $content['sha256']];
        }
        $descriptor = ['object' => ['key' => $key, 'bytes' => 1, 'sha256' => str_repeat('0', 64)], 'lane' => $lane, 'members' => $members];
        V4Format::validatePack($descriptor);
        $cipherFile = $privateDirectory . '/pack-' . basename($key);
        $entries = (static function () use ($contents): \Generator {
            foreach ($contents as $content) {
                yield ['path' => $content['id'], 'type' => 'file', 'size' => $content['size'], 'chunks' => [$content['bytes']]];
            }
        })();
        try {
            $age->encryptStream(BackupArchive::chunks($entries), $cipherFile, $recipients);
            $size = filesize($cipherFile); $sha256 = hash_file('sha256', $cipherFile);
            if ($size === false || $sha256 === false) { throw new RuntimeException('Cannot inspect encrypted v4 pack.'); }
            $descriptor['object']['bytes'] = $size; $descriptor['object']['sha256'] = $sha256;
            V4Format::validatePack($descriptor);
            return ['descriptor' => $descriptor, 'cipherFile' => $cipherFile];
        } catch (\Throwable $error) {
            if (isset($size)) { @unlink($cipherFile); }
            throw $error;
        }
    }

    public static function extract(string $verifiedTar, string $newDestination, array $descriptor): void
    {
        V4Format::validatePack($descriptor);
        FileSafety::assertRegularFile($verifiedTar);
        $size = filesize($verifiedTar);
        if ($size === false || $size > V4Format::MAX_CIPHER_BYTES) { throw new RuntimeException('V4 pack archive exceeds its size limit.'); }
        $scan = BackupArchive::inspect($verifiedTar);
        self::validateScan($scan, $descriptor);
        FileSafety::assertNoLinks($newDestination);
        if (@lstat($newDestination) !== false) { throw new RuntimeException('V4 pack extraction requires a new destination.'); }
        FileSafety::privateDirectory(dirname($newDestination));
        if (!@mkdir($newDestination, 0700)) { throw new RuntimeException('Cannot create v4 pack destination.'); }
        try {
            $result = BackupArchive::extract($verifiedTar, $newDestination);
            if ($result['directories'] !== 0 || $result['files'] !== count($descriptor['members']) || $result['bytes'] !== $scan['bytes']) {
                throw new RuntimeException('V4 pack changed during extraction.');
            }
            foreach ($descriptor['members'] as $member) {
                $file = $newDestination . '/' . $member['id']; FileSafety::assertRegularFile($file);
                if (filesize($file) !== $member['size'] || !hash_equals($member['sha256'], hash_file('sha256', $file))) {
                    throw new RuntimeException('Extracted v4 content differs from its descriptor.');
                }
            }
        } catch (\Throwable $error) { self::removeCreatedDirectory($newDestination); throw $error; }
    }

    /** Validate every member while retaining only the bounded archive and its offsets. */
    public static function index(string $verifiedTar, array $descriptor): array
    {
        V4Format::validatePack($descriptor);
        $identity = self::archiveIdentity($verifiedTar);
        $stream = self::openIndexedArchive($verifiedTar, $identity);
        try {
            $scan = BackupArchive::inspectStreamWithOffsets($stream, V4Format::MAX_PACK_MEMBERS);
            self::validateScan($scan, $descriptor);
            if (self::archiveIdentity($verifiedTar) !== $identity || self::statIdentity(fstat($stream)) !== $identity) { throw new RuntimeException('V4 archive changed while indexing.'); }
            $members = [];
            foreach ($scan['records'] as $record) { $members[] = ['id' => $record['path'], 'offset' => $record['offset'], 'size' => $record['size'], 'sha256' => $record['sha256']]; }
            return ['identity' => $identity, 'members' => $members];
        } finally { fclose($stream); }
    }

    public static function readIndexed(string $verifiedTar, array $identity, array $member): string
    {
        if (count($member) !== 4 || array_diff(array_keys($member), ['id', 'offset', 'size', 'sha256']) !== []
            || !is_string($member['id'] ?? null) || !is_string($member['sha256'] ?? null)
            || !is_int($member['offset'] ?? null) || !is_int($member['size'] ?? null)
            || $member['size'] < 0 || $member['size'] > V4Format::MAX_CONTENT_BYTES
            || $member['id'] !== V4Format::contentId($member['size'], $member['sha256'])
            || $member['offset'] < 512 || $member['offset'] % 512 !== 0
            || !is_int($identity['size'] ?? null) || $identity['size'] < 1024 || $member['size'] > $identity['size'] - 1024
            || $member['offset'] > $identity['size'] - 1024 - $member['size']) { throw new RuntimeException('Invalid indexed v4 content range.'); }
        $stream = self::openIndexedArchive($verifiedTar, $identity);
        try {
            if (fseek($stream, $member['offset']) !== 0) { throw new RuntimeException('Cannot seek indexed v4 content.'); }
            $bytes = $member['size'] === 0 ? '' : stream_get_contents($stream, $member['size']);
            if (!is_string($bytes) || strlen($bytes) !== $member['size'] || !hash_equals($member['sha256'], hash('sha256', $bytes))
                || self::archiveIdentity($verifiedTar) !== $identity || self::statIdentity(fstat($stream)) !== $identity) { throw new RuntimeException('Indexed v4 content changed.'); }
            return $bytes;
        } finally { fclose($stream); }
    }

    private static function archiveIdentity(string $path): array
    {
        // One fresh ancestor walk covers both the file and its containing directory.
        FileSafety::assertNoLinks($path);
        $parent = lstat(dirname($path));
        if ($parent === false || ($parent['mode'] & 0170000) !== 0040000 || ($parent['mode'] & 0777) !== 0700
            || (function_exists('posix_geteuid') && $parent['uid'] !== posix_geteuid()) || !is_readable($path)) {
            throw new RuntimeException('Expected a readable archive in an owner-only directory.');
        }
        if (realpath($path) !== $path) { throw new RuntimeException('Expected a canonical indexed v4 archive.'); }
        clearstatcache(true, $path);
        $stat = lstat($path);
        if ($stat === false || ($stat['mode'] & 0177777) !== 0100600 || $stat['nlink'] !== 1 || $stat['size'] > V4Format::MAX_CIPHER_BYTES
            || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())) { throw new RuntimeException('Expected a private indexed v4 archive.'); }
        return self::statIdentity($stat);
    }

    private static function statIdentity($stat): array
    {
        if (!is_array($stat) || ($stat['mode'] & 0177777) !== 0100600 || $stat['nlink'] !== 1) { throw new RuntimeException('Invalid indexed v4 archive identity.'); }
        return ['dev' => $stat['dev'], 'ino' => $stat['ino'], 'uid' => $stat['uid'], 'mode' => $stat['mode'] & 0177777, 'nlink' => $stat['nlink'], 'size' => $stat['size']];
    }

    private static function openIndexedArchive(string $path, array $identity)
    {
        if (self::archiveIdentity($path) !== $identity) { throw new RuntimeException('Indexed v4 archive was replaced.'); }
        $stream = fopen($path, 'rb');
        if ($stream === false) { throw new RuntimeException('Cannot read indexed v4 archive.'); }
        try {
            if (self::statIdentity(fstat($stream)) !== $identity) { throw new RuntimeException('Indexed v4 archive changed while opening.'); }
            return $stream;
        } catch (\Throwable $error) { fclose($stream); throw $error; }
    }

    private static function validateScan(array $scan, array $descriptor): void
    {
        $expected = [];
        foreach ($descriptor['members'] as $member) { $expected[$member['id']] = $member; }
        if ($scan['directories'] !== 0 || $scan['files'] !== count($expected)) { throw new RuntimeException('V4 pack member inventory differs.'); }
        foreach ($scan['entries'] as $entry) {
            $member = $expected[$entry['path']] ?? null;
            if ($entry['type'] !== 'file' || $member === null || $entry['size'] !== $member['size']
                || !hash_equals($member['sha256'], $entry['sha256'])) { throw new RuntimeException('V4 pack member differs from its descriptor.'); }
            unset($expected[$entry['path']]);
        }
        if ($expected !== []) { throw new RuntimeException('V4 pack has missing members.'); }
    }

    public static function verifyStream($stream, array $receipt, ?array $expected = null): array
    {
        V4Format::validateReceipt($receipt);
        $stat = is_resource($stream) ? fstat($stream) : false;
        if ($stat === false || $stat['size'] > V4Format::MAX_CIPHER_BYTES) { throw new RuntimeException('V4 pack archive exceeds its size limit.'); }
        $scan = BackupArchive::inspectStream($stream, V4Format::MAX_PACK_MEMBERS);
        if ($scan['directories'] !== 0 || $scan['files'] < 1) { throw new RuntimeException('V4 pack must contain only content files.'); }
        $members = [];
        foreach ($scan['entries'] as $entry) {
            if ($entry['type'] !== 'file' || $entry['path'] !== V4Format::contentId($entry['size'], $entry['sha256'])) { throw new RuntimeException('V4 pack content ID differs from its body.'); }
            $members[] = ['id' => $entry['path'], 'size' => $entry['size'], 'sha256' => $entry['sha256']];
        }
        $descriptor = ['object' => $receipt, 'lane' => $expected['lane'] ?? 'other', 'members' => $members];
        V4Format::validatePack($descriptor);
        if ($expected !== null) {
            V4Format::validatePack($expected);
            $actualMembers = array_column($members, null, 'id'); $expectedMembers = array_column($expected['members'], null, 'id');
            ksort($actualMembers); ksort($expectedMembers);
            if ($receipt !== $expected['object'] || $actualMembers !== $expectedMembers) { throw new RuntimeException('V4 pack members differ from its descriptor.'); }
            return $expected;
        }
        return $descriptor;
    }

    private static function removeCreatedDirectory(string $path): void
    {
        foreach (new \FilesystemIterator($path) as $entry) {
            if ($entry->isDir() && !$entry->isLink()) { self::removeCreatedDirectory($entry->getPathname()); }
            else { @unlink($entry->getPathname()); }
        }
        @rmdir($path);
    }
}
