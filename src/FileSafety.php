<?php
declare(strict_types=1);

namespace WordPressR2Backup;

use InvalidArgumentException;
use RuntimeException;

final class FileSafety
{
    /**
     * Flush a regular file and use native fsync when available, on the same open stream.
     * A true result confirms the available operations, not host power-loss durability.
     */
    public static function sync($stream): bool
    {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream'
            || (stream_get_meta_data($stream)['wrapper_type'] ?? null) !== 'plainfile'
            || (($stat = fstat($stream)) === false) || ($stat['mode'] & 0170000) !== 0100000) {
            throw new RuntimeException('File flushing requires an open local regular file.');
        }
        if (!fflush($stream)) { return false; }
        return function_exists('fsync') ? fsync($stream) : true;
    }

    /** Native directory sync: null means unavailable, false means failure. */
    public static function syncDirectory($stream): ?bool
    {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream'
            || (stream_get_meta_data($stream)['wrapper_type'] ?? null) !== 'plainfile'
            || (($stat = fstat($stream)) === false) || ($stat['mode'] & 0170000) !== 0040000) {
            throw new RuntimeException('Directory synchronization requires an open local directory.');
        }
        return function_exists('fsync') ? fsync($stream) : null;
    }

    public static function validatePaths(array $relativePaths): void
    {
        self::validateEntries(array_map(static fn ($path) => ['path' => $path, 'type' => 'file'], $relativePaths));
    }

    public static function validateEntries(array $entries): void
    {
        $seen = [];
        $components = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || !isset($entry['path'], $entry['type']) || !is_string($entry['path'])
                || !in_array($entry['type'], ['file', 'directory'], true)) {
                throw new InvalidArgumentException('Invalid path entry.');
            }
            $path = $entry['path'];
            if ($path === '' || strlen($path) > 4096 || !mb_check_encoding($path, 'UTF-8')
                || preg_match('/[\p{Cc}\\\\:]/u', $path) || str_starts_with($path, '/')) {
                throw new InvalidArgumentException('Unsafe relative path.');
            }
            $parts = explode('/', $path);
            $prefix = '';
            foreach ($parts as $part) {
                if ($part === '' || $part === '.' || $part === '..' || strlen($part) > 255) {
                    throw new InvalidArgumentException('Unsafe path component.');
                }
                $prefix = $prefix === '' ? $part : $prefix . '/' . $part;
                $folded = self::fold($prefix);
                if (isset($components[$folded]) && $components[$folded] !== $prefix) {
                    throw new InvalidArgumentException('Normalized path collision.');
                }
                $components[$folded] = $prefix;
            }
            $folded = self::fold($path);
            if (isset($seen[$folded])) {
                throw new InvalidArgumentException('Duplicate path.');
            }
            $seen[$folded] = $entry['type'];
        }
        foreach ($seen as $path => $type) {
            while (($separator = strrpos($path, '/')) !== false) {
                $path = substr($path, 0, $separator);
                if (($seen[$path] ?? null) === 'file') {
                    throw new InvalidArgumentException('A file cannot contain another entry.');
                }
            }
        }
    }

    public static function privateDirectory(string $path): string
    {
        self::assertNoLinks($path);
        $stat = @lstat($path);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0040000 || realpath($path) !== $path
            || ($stat['mode'] & 0777) !== 0700 || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())) {
            throw new RuntimeException('Expected a canonical owner-only directory.');
        }
        return $path;
    }

    public static function createPrivateDirectory(string $path): string
    {
        self::privateDirectory(dirname($path));
        if (!@mkdir($path, 0700) && @lstat($path) === false) {
            throw new RuntimeException('Cannot create private directory.');
        }
        clearstatcache(true, $path);
        return self::privateDirectory($path);
    }

    public static function assertRegularFile(string $path): void
    {
        self::assertNoLinks($path);
        $stat = @lstat($path);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || !is_readable($path)) {
            throw new RuntimeException('Expected a readable regular file.');
        }
    }

    public static function assertNoLinks(string $path): void
    {
        if ($path === '' || !str_starts_with($path, '/') || str_contains($path, "\0") || str_contains($path, '\\')) {
            throw new RuntimeException('Expected an absolute filesystem path.');
        }
        $current = '';
        foreach (explode('/', substr($path, 1)) as $component) {
            if ($component === '' || $component === '.' || $component === '..') {
                throw new RuntimeException('Noncanonical filesystem path.');
            }
            $current .= '/' . $component;
            clearstatcache(true, $current);
            $stat = @lstat($current);
            if ($stat !== false && ($stat['mode'] & 0170000) === 0120000) {
                throw new RuntimeException('Symbolic links are not permitted.');
            }
        }
    }

    private static function fold(string $path): string
    {
        return mb_convert_case(\Normalizer::normalize($path, \Normalizer::FORM_C), MB_CASE_FOLD, 'UTF-8');
    }
}
