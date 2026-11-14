<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\SyntheticFileSource;

final class SyntheticFileSourceTest extends TestCase
{
    protected function tearDown(): void { Fixture::cleanup(); }

    public function testOpenReusesContentAndRejectsMissingMarkerOrLinksWithoutRepair(): void
    {
        $source = SyntheticFileSource::create(Fixture::temp());
        $source->write('kept.txt', 'kept'); $directory = dirname($source->root());
        self::assertSame($source->inventory(), SyntheticFileSource::open($directory)->inventory());
        unlink($directory . '/content/.wrb-synthetic');
        $failure = null;
        try { SyntheticFileSource::open($directory); } catch (\Throwable $error) { $failure = $error; }
        self::assertInstanceOf(\Throwable::class, $failure);
        self::assertFileDoesNotExist($directory . '/content/.wrb-synthetic');
        Fixture::write($directory, 'content/.wrb-synthetic', 'wordpress-r2-backup-v1');
        symlink($directory . '/content/kept.txt', $directory . '/content/link');
        $this->expectException(\RuntimeException::class); SyntheticFileSource::open($directory);
    }

    public function testOwnedWritersAndCaptureActuallyExcludeEachOther(): void
    {
        $source = SyntheticFileSource::create(Fixture::temp());
        $source->write('value.txt', 'old');
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($parent);
            try {
                $source->withWriter(function () use ($source, $child): void {
                    fwrite($child, "holding\n");
                    fgets($child);
                    $source->write('value.txt', 'one');
                });
                fwrite($child, "released\n");
                fgets($child);
                fwrite($child, "attempting\n");
                $source->write('value.txt', 'two');
                fwrite($child, "written\n");
                fclose($child); exit(0);
            } catch (\Throwable $ignoredError) { exit(1); }
        }
        fclose($child);
        stream_set_timeout($parent, 5);
        try {
            self::assertSame("holding\n", fgets($parent));
            $failure = null;
            try { $source->whilePaused(static fn () => null, 1); }
            catch (\RuntimeException $error) { $failure = $error; }
            self::assertInstanceOf(\RuntimeException::class, $failure);
            self::assertStringContainsString('timed out', $failure->getMessage());
            fwrite($parent, "finish\n");
            self::assertSame("released\n", fgets($parent));
            $result = $source->whilePaused(function () use ($source, $parent): string {
                self::assertSame('one', file_get_contents($source->root() . '/value.txt'));
                fwrite($parent, "start\n");
                self::assertSame("attempting\n", fgets($parent));
                $read = [$parent]; $write = $except = [];
                self::assertSame(0, stream_select($read, $write, $except, 0, 150000));
                self::assertSame('one', file_get_contents($source->root() . '/value.txt'));
                return 'fixed';
            });
            self::assertSame('fixed', $result['result']);
            self::assertSame("written\n", fgets($parent));
            self::assertSame('two', file_get_contents($source->root() . '/value.txt'));
            pcntl_waitpid($pid, $status);
            self::assertSame(0, pcntl_wexitstatus($status));
        } finally {
            fclose($parent);
            if (pcntl_waitpid($pid, $status, WNOHANG) === 0) { posix_kill($pid, SIGTERM); pcntl_waitpid($pid, $status); }
        }
    }

    public function testInventoryDetectsSameLengthChangesAndEmptyDirectoryTypeChanges(): void
    {
        $source = SyntheticFileSource::create(Fixture::temp());
        $source->write('a.txt', 'aaaa'); $source->mkdir('empty');
        $first = $source->inventory();
        $mtime = filemtime($source->root() . '/a.txt');
        $source->write('a.txt', 'bbbb'); touch($source->root() . '/a.txt', $mtime);
        $source->remove('empty'); $source->write('empty', ''); $source->write('added', 'new');
        self::assertNotSame($first, $source->inventory());
        self::assertSame(['a.txt', 'added', 'empty'], array_column($source->inventory(), 'path'));
        $source->remove('added');
        self::assertCount(2, $source->inventory());
    }

    public function testFailureReleasesGateAndUnsafePathsAndUnknownEntriesAreRejected(): void
    {
        $source = SyntheticFileSource::create(Fixture::temp());
        $failure = null;
        try { $source->whilePaused(static function (): void { throw new \RuntimeException('failure'); }); }
        catch (\RuntimeException $error) { $failure = $error; }
        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertSame('failure', $failure->getMessage());
        $source->write('safe', 'ok');
        foreach (['../escape', '/absolute', '.wrb-synthetic', 'a/../b'] as $path) {
            $failure = null;
            try { $source->write($path, 'bad'); }
            catch (\InvalidArgumentException|\RuntimeException $error) { $failure = $error; }
            self::assertTrue($failure instanceof \InvalidArgumentException || $failure instanceof \RuntimeException);
        }
        symlink($source->root() . '/safe', $source->root() . '/link');
        $failure = null;
        try { $source->inventory(); }
        catch (\RuntimeException $error) { $failure = $error; }
        self::assertInstanceOf(\RuntimeException::class, $failure);
        unlink($source->root() . '/link');
        link($source->root() . '/safe', $source->root() . '/hard');
        $this->expectException(\RuntimeException::class);
        $source->inventory();
    }
}
