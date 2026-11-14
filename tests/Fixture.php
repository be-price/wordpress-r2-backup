<?php
declare(strict_types=1);

namespace WordPressR2Backup\Tests;

final class Fixture
{
    private static array $roots = [];

    public static function phpForShebang(): string
    {
        if (!preg_match('/\s/', PHP_BINARY)) { return PHP_BINARY; }
        $interpreter = self::temp() . '/php';
        if (!symlink(PHP_BINARY, $interpreter)) {
            throw new \RuntimeException('Cannot create a space-free test interpreter path.');
        }
        return $interpreter;
    }

    public static function temp(): string
    {
        $root = realpath(sys_get_temp_dir()) . '/wrb-fixture-' . bin2hex(random_bytes(8));
        if (!mkdir($root, 0700)) {
            throw new \RuntimeException('Cannot create synthetic workspace');
        }
        self::$roots[] = $root;
        return $root;
    }

    /** Composer is invoked by the active PHP executable, so resolve its script without a developer-specific path. */
    public static function composer(): string
    {
        $configured = getenv('WRB_COMPOSER_BIN');
        $candidates = is_string($configured) && $configured !== '' ? [$configured] : array_map(static fn (string $directory): string => $directory . '/composer', explode(PATH_SEPARATOR, (string) getenv('PATH')));
        foreach ($candidates as $candidate) {
            $real = realpath($candidate);
            if ($real !== false && is_file($real) && is_readable($real)) { return $real; }
        }
        throw new \RuntimeException('Set WRB_COMPOSER_BIN to the Composer PHP script or add composer to PATH.');
    }

    public static function runtime(): array
    {
        if (is_file(__DIR__ . '/runtime.php')) {
            return require __DIR__ . '/runtime.php';
        }
        $age = getenv('WRB_AGE_BIN');
        if (!$age) {
            throw new \RuntimeException('WRB_AGE_BIN must name the verified age executable');
        }
        return ['age' => $age, 'keygen' => getenv('WRB_AGE_KEYGEN_BIN') ?: dirname($age) . '/age-keygen'];
    }

    public static function keys(int $count = 2): array
    {
        $root = self::temp();
        $keys = [];
        for ($i = 0; $i < $count; $i++) {
            $file = $root . '/identity-' . $i;
            $process = proc_open([self::runtime()['keygen'], '-o', $file], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            fclose($pipes[0]);
            stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            if (proc_close($process) !== 0) {
                throw new \RuntimeException('Synthetic key generation failed');
            }
            $process = proc_open([self::runtime()['keygen'], '-y', $file], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            fclose($pipes[0]);
            $public = trim(stream_get_contents($pipes[1]));
            fclose($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            if (proc_close($process) !== 0) {
                throw new \RuntimeException('Synthetic public key generation failed');
            }
            $keys[] = ['public' => $public, 'identity' => $file];
        }
        return $keys;
    }

    public static function site(array $files): string
    {
        $root = self::temp();
        self::write($root, '.wrb-synthetic', 'wordpress-r2-backup-v1');
        foreach ($files as $path => $contents) {
            self::write($root, $path, $contents);
        }
        return $root;
    }

    public static function write(string $root, string $path, string $contents): void
    {
        $parent = dirname($root . '/' . $path);
        if (!is_dir($parent)) {
            mkdir($parent, 0700, true);
        }
        file_put_contents($root . '/' . $path, $contents);
        chmod($root . '/' . $path, 0600);
    }

    public static function cleanup(): void
    {
        foreach (array_reverse(self::$roots) as $root) {
            self::remove($root);
        }
        self::$roots = [];
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove($path . '/' . $entry);
                }
            }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }
}
