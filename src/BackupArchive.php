<?php
declare(strict_types=1);

namespace WordPressR2Backup;

use InvalidArgumentException;
use RuntimeException;

/** Restricted, deterministic POSIX tar. No links, devices, ownership or modes are restored. */
final class BackupArchive
{
    private const MAX_ENTRIES = 500000;
    private const BLOCK = 512;

    public static function chunks(iterable $entries): \Generator
    {
        $seen = []; $components = []; $number = 0;
        foreach ($entries as $entry) {
            if (!is_array($entry) || !isset($entry['path'], $entry['type'])
                || !is_string($entry['path']) || !in_array($entry['type'], ['file', 'directory'], true)) {
                throw new InvalidArgumentException('Invalid archive entry.');
            }
            if (++$number > self::MAX_ENTRIES) { throw new RuntimeException('Archive entry limit exceeded.'); }
            $path = $entry['path']; $type = $entry['type'];
            self::register($path, $type, $seen, $components);
            $size = $type === 'directory' ? 0 : ($entry['size'] ?? null);
            if (!is_int($size) || $size < 0 || $size > 077777777777
                || ($type === 'file' && (!isset($entry['chunks']) || !is_iterable($entry['chunks'])))) {
                throw new InvalidArgumentException('Invalid archive file size or content.');
            }
            $headerPath = $path;
            if (strlen($path) > 100 || preg_match('/[^\x20-\x7e]/', $path)) {
                $payload = self::paxRecord('path', $path);
                yield self::header('PaxHeaders/' . $number, 'x', strlen($payload));
                yield $payload . str_repeat("\0", (512 - strlen($payload) % 512) % 512);
                $headerPath = 'entry-' . $number;
            }
            yield self::header($headerPath, $type === 'directory' ? '5' : '0', $size);
            if ($type === 'file') {
                $remaining = $size;
                foreach ($entry['chunks'] as $chunk) {
                    if (!is_string($chunk) || strlen($chunk) > $remaining) {
                        throw new RuntimeException('Archive content exceeds declared size.');
                    }
                    $remaining -= strlen($chunk);
                    // Do not grow the producer's chunk into a second large allocation.
                    for ($offset = 0, $length = strlen($chunk); $offset < $length; $offset += 65536) {
                        yield substr($chunk, $offset, 65536);
                    }
                }
                if ($remaining !== 0) { throw new RuntimeException('Archive content is truncated.'); }
                if ($size % 512 !== 0) { yield str_repeat("\0", 512 - $size % 512); }
            }
        }
        yield str_repeat("\0", 1024);
    }

    public static function inspect(string $tar): array
    {
        $stream = self::open($tar);
        try { $scan = self::scan($stream); unset($scan['records']); return $scan; }
        finally { fclose($stream); }
    }

    public static function inspectStream($stream, int $maximumEntries = self::MAX_ENTRIES): array
    {
        $scan = self::inspectStreamWithOffsets($stream, $maximumEntries);
        unset($scan['records']);
        return $scan;
    }

    public static function inspectStreamWithOffsets($stream, int $maximumEntries = self::MAX_ENTRIES): array
    {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream' || !stream_get_meta_data($stream)['seekable']
            || $maximumEntries < 1 || $maximumEntries > self::MAX_ENTRIES || !rewind($stream)) { throw new RuntimeException('Expected a bounded seekable archive stream.'); }
        return self::scan($stream, $maximumEntries);
    }

    /** Validate the complete archive before creating anything in the destination. */
    public static function extract(string $verifiedTar, string $newDestination): array
    {
        $stream = self::open($verifiedTar);
        $createdFiles = []; $createdDirs = [];
        try {
            $scan = self::scan($stream);
            FileSafety::assertNoLinks($newDestination);
            clearstatcache(true, $newDestination);
            if (@lstat($newDestination) !== false) {
                FileSafety::privateDirectory($newDestination);
                if (count(scandir($newDestination)) !== 2) { throw new RuntimeException('Extraction destination must be empty.'); }
            } else {
                FileSafety::privateDirectory(dirname($newDestination));
                if (!@mkdir($newDestination, 0700)) { throw new RuntimeException('Cannot create extraction destination.'); }
                $createdDirs[] = $newDestination;
                FileSafety::privateDirectory($newDestination);
            }
            foreach ($scan['records'] as $record) {
                $parts = explode('/', $record['path']);
                $leaf = array_pop($parts); $parent = $newDestination;
                foreach ($parts as $part) {
                    $parent .= '/' . $part;
                    self::directory($parent, $createdDirs);
                }
                FileSafety::privateDirectory($parent);
                $target = $parent . '/' . $leaf;
                if ($record['type'] === 'directory') { self::directory($target, $createdDirs); continue; }
                FileSafety::assertNoLinks($target);
                if (@lstat($target) !== false) { throw new RuntimeException('Extraction never replaces an existing entry.'); }
                $previousMask = umask(0077);
                try { $output = @fopen($target, 'x+b'); } finally { umask($previousMask); }
                if ($output === false) { throw new RuntimeException('Cannot create extracted file.'); }
                $createdFiles[] = $target;
                try {
                    if (!chmod($target, 0600) || fseek($stream, $record['offset']) !== 0) { throw new RuntimeException('Cannot prepare extracted file.'); }
                    $hash = hash_init('sha256'); $remaining = $record['size'];
                    while ($remaining > 0) {
                        $chunk = self::read($stream, min(65536, $remaining));
                        hash_update($hash, $chunk); self::write($output, $chunk); $remaining -= strlen($chunk);
                    }
                    if (!hash_equals($record['sha256'], hash_final($hash)) || !FileSafety::sync($output)) {
                        throw new RuntimeException('Archive changed during extraction or extracted file flush failed.');
                    }
                } finally { fclose($output); }
            }
            return ['files' => $scan['files'], 'directories' => $scan['directories'], 'bytes' => $scan['bytes']];
        } catch (\Throwable $error) {
            // Only entries created by this invocation are eligible for cleanup.
            foreach (array_reverse($createdFiles) as $file) { @unlink($file); }
            foreach (array_reverse($createdDirs) as $directory) { @rmdir($directory); }
            throw $error;
        } finally { fclose($stream); }
    }

    private static function open(string $path)
    {
        FileSafety::assertRegularFile($path);
        if (realpath($path) !== $path) { throw new RuntimeException('Expected canonical archive path.'); }
        $stream = @fopen($path, 'rb');
        if ($stream === false) { throw new RuntimeException('Cannot read archive.'); }
        $actual = fstat($stream); $expected = lstat($path);
        if ($actual === false || $expected === false || $actual['ino'] !== $expected['ino'] || $actual['dev'] !== $expected['dev']
            || ($actual['mode'] & 0170000) !== 0100000) { fclose($stream); throw new RuntimeException('Archive changed while opening.'); }
        return $stream;
    }

    private static function scan($stream, int $maximumEntries = self::MAX_ENTRIES): array
    {
        $records = []; $entries = []; $seen = []; $components = [];
        $files = 0; $directories = 0; $bytes = 0; $pax = null; $headers = 0;
        while (true) {
            $header = self::read($stream, 512);
            if ($header === str_repeat("\0", 512)) {
                if ($pax !== null || self::read($stream, 512) !== str_repeat("\0", 512)) { throw new RuntimeException('Invalid archive terminator.'); }
                while (!feof($stream)) {
                    $tail = fread($stream, 65536);
                    if ($tail === false || trim($tail, "\0") !== '' || strlen($tail) % 512 !== 0) { throw new RuntimeException('Archive contains trailing garbage.'); }
                }
                break;
            }
            if (++$headers > $maximumEntries * 2) { throw new RuntimeException('Archive header limit exceeded.'); }
            $sum = self::number(substr($header, 148, 8));
            $check = substr_replace($header, str_repeat(' ', 8), 148, 8);
            if (array_sum(unpack('C*', $check)) !== $sum
                || !in_array(substr($header, 257, 6), ["ustar\0", 'ustar '], true)) {
                throw new RuntimeException('Invalid tar header or checksum.');
            }
            $type = $header[156]; $size = self::number(substr($header, 124, 12));
            if (!in_array($type, ["\0", '0', '5', 'x'], true) || trim(substr($header, 157, 100), "\0") !== '') {
                throw new RuntimeException('Archive links and special entries are forbidden.');
            }
            $name = self::field(substr($header, 0, 100));
            $prefix = self::field(substr($header, 345, 155));
            $path = $prefix === '' ? $name : $prefix . '/' . $name;
            if ($type === 'x') {
                if ($pax !== null || $size > 8192 || $size === 0) { throw new RuntimeException('Invalid PAX header.'); }
                $pax = self::parsePax(self::read($stream, $size)); self::padding($stream, $size); continue;
            }
            if ($pax !== null) { $path = $pax; $pax = null; }
            $kind = $type === '5' ? 'directory' : 'file';
            if ($kind === 'directory') {
                if (substr($path, -1) === '/') { $path = substr($path, 0, -1); }
                if ($size !== 0) { throw new RuntimeException('Directory contains data.'); }
            }
            if (count($records) >= $maximumEntries) { throw new RuntimeException('Archive entry limit exceeded.'); }
            self::register($path, $kind, $seen, $components);
            $record = ['path' => $path, 'type' => $kind, 'size' => $size, 'offset' => ftell($stream)];
            if ($kind === 'file') {
                $hash = hash_init('sha256'); $remaining = $size;
                while ($remaining > 0) { $chunk = self::read($stream, min(65536, $remaining)); hash_update($hash, $chunk); $remaining -= strlen($chunk); }
                $record['sha256'] = hash_final($hash); ++$files;
                if ($bytes > PHP_INT_MAX - $size) { throw new RuntimeException('Archive size overflow.'); }
                $bytes += $size;
            } else { ++$directories; }
            self::padding($stream, $size);
            $entry = $record; unset($entry['offset']); $entries[] = $entry; $records[] = $record;
        }
        return compact('entries', 'files', 'directories', 'bytes', 'records');
    }

    private static function register(string $path, string $type, array &$seen, array &$components): void
    {
        FileSafety::validatePaths([$path]);
        $prefix = ''; $parts = explode('/', $path); $last = count($parts) - 1;
        foreach ($parts as $index => $part) {
            $prefix = $prefix === '' ? $part : $prefix . '/' . $part;
            $folded = mb_convert_case(\Normalizer::normalize($prefix, \Normalizer::FORM_C), MB_CASE_FOLD, 'UTF-8');
            if (isset($components[$folded]) && $components[$folded] !== $prefix) { throw new InvalidArgumentException('Normalized archive path collision.'); }
            if (($seen[$folded] ?? null) === 'file') { throw new InvalidArgumentException('Duplicate file or file used as directory.'); }
            if ($index === $last) {
                if (isset($seen[$folded]) || ($type === 'file' && isset($components[$folded]))) { throw new InvalidArgumentException('Duplicate archive path.'); }
                $seen[$folded] = $type;
            }
            $components[$folded] = $prefix;
        }
    }

    private static function header(string $path, string $type, int $size): string
    {
        $header = str_pad($path, 100, "\0") . sprintf('%07o', $type === '5' ? 0700 : 0600) . "\0"
            . "0000000\0" . "0000000\0" . sprintf('%011o', $size) . "\0" . "00000000000\0"
            . str_repeat(' ', 8) . $type . str_repeat("\0", 100) . "ustar\0" . '00' . str_repeat("\0", 247);
        return substr_replace($header, sprintf('%06o', array_sum(unpack('C*', $header))) . "\0 ", 148, 8);
    }

    private static function paxRecord(string $key, string $value): string
    {
        $body = ' ' . $key . '=' . $value . "\n"; $length = strlen($body) + 1;
        while (($next = strlen($body) + strlen((string) $length)) !== $length) { $length = $next; }
        return $length . $body;
    }

    private static function parsePax(string $payload): string
    {
        $path = null; $offset = 0;
        while ($offset < strlen($payload)) {
            $space = strpos($payload, ' ', $offset);
            if ($space === false) { throw new RuntimeException('Malformed PAX length.'); }
            $lengthText = substr($payload, $offset, $space - $offset);
            if (!preg_match('/^[1-9][0-9]{0,4}$/D', $lengthText)) { throw new RuntimeException('Malformed PAX length.'); }
            $length = (int) $lengthText;
            if ($length <= $space - $offset + 2 || $offset + $length > strlen($payload) || $payload[$offset + $length - 1] !== "\n") {
                throw new RuntimeException('Malformed PAX record.');
            }
            $body = substr($payload, $space + 1, $offset + $length - $space - 2);
            $equal = strpos($body, '=');
            if ($equal === false) { throw new RuntimeException('Malformed PAX attribute.'); }
            $key = substr($body, 0, $equal); $value = substr($body, $equal + 1);
            if ($key === 'path' && $path === null) { $path = $value; }
            elseif (!in_array($key, ['mtime', 'atime', 'ctime'], true) || !preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D', $value)) {
                throw new RuntimeException('Unsupported or duplicate PAX attribute.');
            }
            $offset += $length;
        }
        if ($path === null) { throw new RuntimeException('PAX path missing.'); }
        return $path;
    }

    private static function number(string $field): int
    {
        $text = trim($field, "\0 ");
        if ($text === '' || !preg_match('/^[0-7]+$/D', $text) || strlen($text) > 11) { throw new RuntimeException('Invalid tar numeric field.'); }
        $number = octdec($text);
        if (!is_int($number) || $number < 0) { throw new RuntimeException('Tar numeric overflow.'); }
        return $number;
    }

    private static function field(string $field): string
    {
        $zero = strpos($field, "\0");
        if ($zero === false) { return $field; }
        if (trim(substr($field, $zero), "\0") !== '') { throw new RuntimeException('Noncanonical tar string.'); }
        return substr($field, 0, $zero);
    }

    private static function padding($stream, int $size): void
    {
        $padding = (512 - $size % 512) % 512;
        if ($padding > 0 && self::read($stream, $padding) !== str_repeat("\0", $padding)) { throw new RuntimeException('Invalid tar padding.'); }
    }

    private static function read($stream, int $length): string
    {
        $result = '';
        while (strlen($result) < $length) {
            $part = fread($stream, $length - strlen($result));
            if ($part === false || $part === '') { throw new RuntimeException('Truncated archive.'); }
            $result .= $part;
        }
        return $result;
    }

    private static function write($stream, string $chunk): void
    {
        $offset = 0;
        while ($offset < strlen($chunk)) {
            $written = fwrite($stream, substr($chunk, $offset));
            if ($written === false || $written === 0) { throw new RuntimeException('Cannot write extracted file.'); }
            $offset += $written;
        }
    }

    private static function directory(string $path, array &$created): void
    {
        FileSafety::assertNoLinks($path);
        if (@lstat($path) === false) {
            if (!@mkdir($path, 0700)) { throw new RuntimeException('Cannot create archive directory.'); }
            $created[] = $path;
        }
        FileSafety::privateDirectory($path);
    }
}
