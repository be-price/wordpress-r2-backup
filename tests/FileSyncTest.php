<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\FileSafety;

final class FileSyncTest extends TestCase
{
    protected function tearDown(): void { Fixture::cleanup(); }

    public function testWritesWithoutAnExternalSynchronizationProcess(): void
    {
        $root = Fixture::temp();
        $code = 'require $argv[1]; $file = fopen($argv[2], "xb");'
            . 'fwrite($file, "buffered content");'
            . 'echo json_encode(\WordPressR2Backup\FileSafety::sync($file)); fclose($file);';
        [$exit, $out, $error] = $this->command($code, [$root . '/data'], 'proc_open,exec,shell_exec,system,passthru,popen');
        self::assertSame(0, $exit, $error);
        self::assertSame('true', $out);
        self::assertSame('buffered content', file_get_contents($root . '/data'));
    }

    public function testDirectorySyncDistinguishesUnavailableFromSuccess(): void
    {
        $directory = fopen(Fixture::temp(), 'r');
        try {
            self::assertSame(function_exists('fsync') ? true : null, FileSafety::syncDirectory($directory));
        } finally { fclose($directory); }
    }

    public function testRejectsMemoryStreams(): void
    {
        $stream = fopen('php://memory', 'w+');
        try {
            $this->expectException(\RuntimeException::class);
            FileSafety::sync($stream);
        } finally { fclose($stream); }
    }

    public function testFileFlushRejectsDirectories(): void
    {
        $stream = fopen(Fixture::temp(), 'r');
        try {
            $this->expectException(\RuntimeException::class);
            FileSafety::sync($stream);
        } finally { fclose($stream); }
    }

    public function testDirectorySyncRejectsFiles(): void
    {
        $stream = tmpfile();
        try {
            $this->expectException(\RuntimeException::class);
            FileSafety::syncDirectory($stream);
        } finally { fclose($stream); }
    }

    public function testNativeSyncFailureIsNeverDowngradedToFlushSuccess(): void
    {
        if (!function_exists('fsync')) { self::markTestSkipped('Requires native fsync.'); }
        // Replace only the OS-sync boundary in a separate process; exercise real file handling.
        $code = 'namespace WordPressR2Backup; function fsync($stream) { return false; }'
            . 'require $argv[1]; $file = tmpfile(); $directory = fopen($argv[2], "r");'
            . 'fwrite($file, "content"); echo json_encode([FileSafety::sync($file), FileSafety::syncDirectory($directory)]);'
            . 'fclose($file); fclose($directory);';
        [$exit, $out, $error] = $this->command($code, [Fixture::temp()]);
        self::assertSame(0, $exit, $error);
        self::assertSame('[false,false]', $out);
    }

    public function testFlushFailureStopsBeforeNativeSync(): void
    {
        $code = 'namespace WordPressR2Backup; function fflush($stream) { return false; }'
            . 'function fsync($stream) { throw new \\RuntimeException("Sync after failed flush"); }'
            . 'require $argv[1]; $file = tmpfile(); fwrite($file, "content");'
            . 'echo json_encode(FileSafety::sync($file)); fclose($file);';
        [$exit, $out, $error] = $this->command($code, []);
        self::assertSame(0, $exit, $error);
        self::assertSame('false', $out);
    }

    private function command(string $code, array $arguments, string $disabled = ''): array
    {
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'disable_functions=' . $disabled,
            '-r', $code, dirname(__DIR__) . '/vendor/autoload.php', ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
        return [proc_close($process), $out, $error];
    }
}
