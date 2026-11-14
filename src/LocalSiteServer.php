<?php
declare(strict_types=1);

namespace WordPressR2Backup;

use RuntimeException;

/**
 * Foreground loopback server for the separate, disposable running copy. A SQLite copy needs no connection at all; a MySQL copy
 * names the one owner-checked UNIX socket it may reach. TCP and every other socket stay denied.
 * $url is only where the server listens; $targetUrl is the value stored in the database, which is never contacted.
 */
final class LocalSiteServer
{
    /** @return array{0:array,1:string,2:string,3:string} URL parts, PHP binary, user directory, runtime directory */
    private static function plan(string $siteRoot, ?string $databaseFile, string $url, string $routerFile, ?string $databaseSocket, ?string $phpBinary, ?string $targetUrl = null): array
    {
        if (!self::platformAvailable() || ($phpBinary === null && (!function_exists('pcntl_async_signals') || !function_exists('pcntl_signal_get_handler')))) { throw new RuntimeException('Isolated local serving currently requires macOS sandbox-exec and CLI signal support.'); }
        FileSafety::assertRegularFile($routerFile);
        if (($databaseFile === null) === ($databaseSocket === null) || ($databaseFile !== null && self::contains($siteRoot, $databaseFile)) || realpath($routerFile) !== $routerFile) { throw new RuntimeException('The running database must be a private file outside the site.'); }
        if ($targetUrl !== null) { SiteUrlRewriter::assertUrl($targetUrl, false); }
        if ($databaseSocket !== null) { self::assertOwnedSocket($databaseSocket); }
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'http' || !in_array($parts['host'] ?? '', ['localhost', '127.0.0.1'], true)
            || !isset($parts['port']) || $parts['port'] < 1024 || $parts['port'] > 65535 || count($parts) !== 3
            || preg_match('/[\x00-\x20\x7f]/', $url)) { throw new RuntimeException('Serve a local site at an explicit HTTP loopback port without a path.'); }
        $binary = realpath($phpBinary ?? PHP_BINARY); $userDirectory = getenv('HOME');
        if ((!is_string($userDirectory) || $userDirectory === '') && function_exists('posix_getpwuid') && function_exists('posix_geteuid')) { $userDirectory = posix_getpwuid(posix_geteuid())['dir'] ?? false; }
        if ($binary === false || !is_string($userDirectory) || realpath($userDirectory) !== $userDirectory || $userDirectory === '/') { throw new RuntimeException('Cannot identify the isolated PHP runtime and user directory.'); }
        $runtime = dirname($binary, 2);
        if (self::contains($runtime, $userDirectory) || self::contains($siteRoot, $userDirectory) || ($databaseFile !== null && self::contains($runtime, dirname($databaseFile)))) { throw new RuntimeException('The runtime and site cannot expose the user or database directory.'); }
        return [$parts, $binary, $userDirectory, $runtime];
    }

    /** Checks the platform, runtime, URL and path relations before any recovery work; run() repeats them against the real files. */
    public static function preflight(string $siteRoot, ?string $databaseFile, string $url, string $routerFile, ?string $databaseSocket = null, ?string $phpBinary = null, ?string $targetUrl = null): void { self::plan($siteRoot, $databaseFile, $url, $routerFile, $databaseSocket, $phpBinary, $targetUrl); }

    /** Whether this operating system offers the OS isolation at all; without it no running copy is served. */
    public static function platformAvailable(): bool { return PHP_OS_FAMILY === 'Darwin' && is_executable('/usr/bin/sandbox-exec'); }

    public static function run(string $siteRoot, ?string $databaseFile, string $url, string $routerFile, ?string $databaseSocket = null, ?string $targetUrl = null): int
    {
        [$parts, $binary, $userDirectory, $runtime] = self::plan($siteRoot, $databaseFile, $url, $routerFile, $databaseSocket, null, $targetUrl);
        FileSafety::privateDirectory($siteRoot); FileSafety::privateDirectory(dirname($siteRoot));
        FileSafety::assertRegularFile($siteRoot . '/index.php');
        if ($databaseFile !== null) {
            FileSafety::privateDirectory(dirname($databaseFile)); FileSafety::assertRegularFile($databaseFile);
            $database = lstat($databaseFile);
            if (($database['mode'] & 0777) !== 0600 || $database['nlink'] !== 1 || (function_exists('posix_geteuid') && $database['uid'] !== posix_geteuid())) { throw new RuntimeException('The running database must be a private file outside the site.'); }
        }
        $temporary = dirname($siteRoot) . '/.wrb-serve-' . bin2hex(random_bytes(12));
        if (!mkdir($temporary, 0700)) { throw new RuntimeException('Cannot create a private local server workspace.'); }
        $profile = "(version 1)\n(allow default)\n(deny process-fork)\n(deny network*)\n"
            . '(allow network-bind (local tcp "localhost:' . $parts['port'] . '"))' . "\n"
            . '(allow network-inbound (local tcp "localhost:' . $parts['port'] . '"))' . "\n"
            . '(deny file-read-data (subpath ' . self::literal($userDirectory) . "))\n"
            . '(deny file-read-data (subpath ' . self::literal(dirname($siteRoot)) . "))\n"
            . ($databaseFile !== null ? '(deny file-read-data (subpath ' . self::literal(dirname($databaseFile)) . "))\n" : '')
            . '(allow file-read-data (subpath ' . self::literal($siteRoot) . '))' . "\n"
            . '(allow file-read-data (subpath ' . self::literal($runtime) . '))' . "\n"
            . '(allow file-read-data (subpath ' . self::literal($temporary) . '))' . "\n"
            . '(allow file-read-data (literal ' . self::literal($routerFile) . '))' . "\n(deny file-write*)\n"
            . '(allow file-write* (subpath ' . self::literal($siteRoot) . '))' . "\n"
            . '(allow file-write* (subpath ' . self::literal($temporary) . '))' . "\n"
            . '(allow file-write* (literal "/dev/null"))' . "\n";
        if ($databaseFile !== null) {
            foreach (['', '-wal', '-shm', '-journal'] as $suffix) {
                $file = $databaseFile . $suffix; FileSafety::assertNoLinks($file);
                $profile .= '(allow file-read-data file-write* (literal ' . self::literal($file) . '))' . "\n";
            }
        } else {
            $profile .= '(allow network-outbound (remote unix-socket (path-literal ' . self::literal((string) $databaseSocket) . ')))' . "\n";
        }
        self::write($temporary . '/sandbox.sb', $profile);
        self::write($temporary . '/php-errors.log', '');
        self::write($temporary . '/http-access.log', ''); self::write($temporary . '/http-errors.log', '');
        $disabled = 'exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec,pcntl_fork,pcntl_rfork,pcntl_signal,pcntl_async_signals,posix_kill,mail,dl';
        $command = ['/usr/bin/sandbox-exec', '-f', $temporary . '/sandbox.sb', $binary, '-n',
            '-d', 'memory_limit=512M', '-d', 'disable_functions=' . $disabled, '-d', 'ffi.enable=false', '-d', 'enable_dl=0',
            '-d', 'allow_url_fopen=0', '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=' . $temporary . '/php-errors.log',
            '-d', 'session.save_path=' . $temporary, '-d', 'upload_tmp_dir=' . $temporary, '-d', 'sys_temp_dir=' . $temporary,
            '-S', '127.0.0.1:' . $parts['port'], '-t', $siteRoot, $routerFile];
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $temporary . '/http-access.log', 'a'], 2 => ['file', $temporary . '/http-errors.log', 'a']], $pipes, $siteRoot,
            ['WRB_SITE_ROOT' => $siteRoot, 'WRB_LOCAL_URL' => $url, 'WRB_DATABASE_MODE' => $databaseFile !== null ? 'sqlite' : 'mysql', 'TMPDIR' => $temporary, 'PHP_INI_SCAN_DIR' => ''] + ($targetUrl !== null ? ['WRB_TARGET_URL' => $targetUrl] : []), ['bypass_shell' => true]);
        if (!is_resource($process)) { throw new RuntimeException('Cannot start the isolated local server.'); }
        $stop = false; $stoppingAt = null; $signals = []; $async = pcntl_async_signals(true);
        foreach ([SIGINT, SIGTERM] as $signal) { $signals[$signal] = pcntl_signal_get_handler($signal); pcntl_signal($signal, static function () use (&$stop): void { $stop = true; }); }
        try {
            do {
                $status = proc_get_status($process); if (!$status['running']) { break; }
                if ($stop) { $stoppingAt = $stoppingAt ?? microtime(true); proc_terminate($process, microtime(true) - $stoppingAt > 3 ? 9 : 15); }
                usleep(100000);
            } while (true);
            $exit = proc_close($process); $process = null;
            return $status['exitcode'] >= 0 ? $status['exitcode'] : $exit;
        } finally {
            if (is_resource($process)) { proc_terminate($process, 15); proc_close($process); }
            foreach ($signals as $signal => $handler) { pcntl_signal($signal, $handler); } pcntl_async_signals($async);
        }
    }

    private static function assertOwnedSocket(string $socket): void
    {
        FileSafety::assertNoLinks($socket); clearstatcache(true, $socket); $stat = @lstat($socket);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0140000 || $stat['nlink'] !== 1 || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())
            || realpath(dirname($socket)) . '/' . basename($socket) !== $socket) { throw new RuntimeException('The running database socket must be owned by this account.'); }
    }

    private static function literal(string $path): string
    {
        if (preg_match('/[\x00-\x1f\x7f]/', $path)) { throw new RuntimeException('Unsupported local server path.'); }
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $path) . '"';
    }

    private static function contains(string $directory, string $path): bool { return $directory === $path || strpos($path, $directory . '/') === 0; }

    private static function write(string $file, string $bytes): void
    {
        $stream = fopen($file, 'x+b');
        if ($stream === false) { throw new RuntimeException('Cannot create a local server configuration.'); }
        try { if (!chmod($file, 0600) || fwrite($stream, $bytes) !== strlen($bytes) || !FileSafety::sync($stream)) { throw new RuntimeException('Cannot persist a local server configuration.'); } }
        finally { fclose($stream); }
    }
}
