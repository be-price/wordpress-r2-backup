<?php
declare(strict_types=1);

namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\BackupArchive;

final class BackupArchiveTest extends TestCase
{
    private $root;

    protected function setUp(): void
    {
        $this->root = realpath(sys_get_temp_dir()) . '/r2-archive-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
    }

    protected function tearDown(): void
    {
        $walk = function ($path) use (&$walk) {
            if (is_dir($path) && !is_link($path)) {
                foreach (scandir($path) as $name) { if ($name !== '.' && $name !== '..') { $walk($path . '/' . $name); } }
                rmdir($path);
            } else { unlink($path); }
        };
        $walk($this->root);
    }

    private function archive(array $entries): string
    {
        $path = $this->root . '/input.tar';
        file_put_contents($path, implode('', iterator_to_array(BackupArchive::chunks($entries))));
        return $path;
    }

    public function testUnicodeLongPathsAndTarInteroperability(): void
    {
        $name = '写真/' . str_repeat('長', 60) . '.txt';
        $tar = $this->archive([
            ['path' => '写真', 'type' => 'directory'],
            ['path' => $name, 'type' => 'file', 'size' => 5, 'chunks' => ['he', 'llo']],
            ['path' => 'empty', 'type' => 'file', 'size' => 0, 'chunks' => []],
        ]);
        $result = BackupArchive::extract($tar, $this->root . '/out');
        self::assertSame(['files' => 2, 'directories' => 1, 'bytes' => 5], $result);
        self::assertSame('hello', file_get_contents($this->root . '/out/' . $name));
        self::assertSame(0600, fileperms($this->root . '/out/' . $name) & 0777);
        mkdir($this->root . '/native', 0700);
        exec('tar -xf ' . escapeshellarg($tar) . ' -C ' . escapeshellarg($this->root . '/native') . ' 2>&1', $lines, $status);
        self::assertSame(0, $status, implode("\n", $lines));
        self::assertSame('hello', file_get_contents($this->root . '/native/' . $name));
    }

    public function testRejectsWrongSizeDuringGeneration(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->archive([['path' => 'a', 'type' => 'file', 'size' => 3, 'chunks' => ['xx']]]);
    }

    public function testRejectsNormalizedCollisions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->archive([
            ['path' => 'A', 'type' => 'directory'],
            ['path' => 'a/file', 'type' => 'file', 'size' => 0, 'chunks' => []],
        ]);
    }

    public function testMalformedArchiveDoesNotCreateDestination(): void
    {
        $tar = $this->archive([['path' => 'a', 'type' => 'file', 'size' => 1, 'chunks' => ['x']]]);
        file_put_contents($tar, 'garbage', FILE_APPEND);
        try { BackupArchive::extract($tar, $this->root . '/out'); self::fail('Accepted trailing garbage'); }
        catch (\RuntimeException $error) { self::assertDirectoryDoesNotExist($this->root . '/out'); }
    }

    public function testNeverOverwritesExistingFiles(): void
    {
        $tar = $this->archive([['path' => 'a', 'type' => 'file', 'size' => 1, 'chunks' => ['x']]]);
        mkdir($this->root . '/out', 0700);
        file_put_contents($this->root . '/out/a', 'keep');
        try { BackupArchive::extract($tar, $this->root . '/out'); self::fail('Accepted existing contents'); }
        catch (\RuntimeException $error) { self::assertSame('keep', file_get_contents($this->root . '/out/a')); }
    }

    public function testReadsNativeUstarAndReportsHashes(): void
    {
        mkdir($this->root . '/source', 0700);
        file_put_contents($this->root . '/source/a', 'native');
        $tar = $this->root . '/native.tar';
        exec('tar --format=ustar -cf ' . escapeshellarg($tar) . ' -C ' . escapeshellarg($this->root . '/source') . ' a 2>&1', $lines, $status);
        self::assertSame(0, $status, implode("\n", $lines));
        self::assertSame([
            'entries' => [['path' => 'a', 'type' => 'file', 'size' => 6, 'sha256' => hash('sha256', 'native')]],
            'files' => 1, 'directories' => 0, 'bytes' => 6,
        ], BackupArchive::inspect($tar));
        BackupArchive::extract($tar, $this->root . '/native-out');
        self::assertSame('native', file_get_contents($this->root . '/native-out/a'));
    }

    /** @dataProvider invalidTarCases */
    public function testRejectsHostileTarBeforeWriting(string $case): void
    {
        $tar = $this->archive([['path' => 'safe', 'type' => 'file', 'size' => 1, 'chunks' => ['x']]]);
        $data = file_get_contents($tar);
        if ($case === 'checksum') { $data[0] = 'X'; }
        elseif ($case === 'truncate') { $data = substr($data, 0, 512); }
        elseif ($case === 'single-terminator') { $data = substr($data, 0, -512); }
        elseif ($case === 'padding') { $data[514] = 'x'; }
        elseif ($case === 'duplicate') { $data = substr($data, 0, 1024) . $data; }
        else {
            $header = substr($data, 0, 512);
            if ($case === 'traversal') { $header = substr_replace($header, str_pad('../evil', 100, "\0"), 0, 100); }
            if ($case === 'absolute') { $header = substr_replace($header, str_pad('/evil', 100, "\0"), 0, 100); }
            if ($case === 'link') { $header[156] = '2'; }
            if ($case === 'hardlink') { $header[156] = '1'; }
            if ($case === 'device') { $header[156] = '3'; }
            $header = substr_replace($header, str_repeat(' ', 8), 148, 8);
            $header = substr_replace($header, sprintf('%06o', array_sum(unpack('C*', $header))) . "\0 ", 148, 8);
            $data = $header . substr($data, 512);
        }
        file_put_contents($tar, $data);
        try { BackupArchive::extract($tar, $this->root . '/out'); self::fail('Accepted ' . $case); }
        catch (\RuntimeException | \InvalidArgumentException $error) { self::assertDirectoryDoesNotExist($this->root . '/out'); }
    }

    public function invalidTarCases(): array
    {
        return array_map(static function ($case) { return [$case]; }, [
            'checksum', 'truncate', 'single-terminator', 'padding', 'duplicate', 'traversal', 'absolute', 'link', 'hardlink', 'device',
        ]);
    }

    public function testRejectsDestinationSymlinkAndPreservesTarget(): void
    {
        $tar = $this->archive([['path' => 'a', 'type' => 'file', 'size' => 1, 'chunks' => ['x']]]);
        mkdir($this->root . '/real', 0700);
        symlink($this->root . '/real', $this->root . '/link');
        try { BackupArchive::extract($tar, $this->root . '/link'); self::fail('Accepted symlink'); }
        catch (\RuntimeException $error) { self::assertSame(['.', '..'], scandir($this->root . '/real')); }
    }

    public function testManyChunksStayBoundedAndImplicitDirectoriesWork(): void
    {
        $start = memory_get_usage(true);
        $chunks = static function () { for ($i = 0; $i < 256; ++$i) { yield str_repeat('a', 65536); } };
        $tar = $this->root . '/large.tar'; $stream = fopen($tar, 'wb');
        foreach (BackupArchive::chunks([['path' => 'deep/implicit/file', 'type' => 'file', 'size' => 16777216, 'chunks' => $chunks()]]) as $chunk) { fwrite($stream, $chunk); }
        fclose($stream);
        self::assertSame(16777216, BackupArchive::extract($tar, $this->root . '/out')['bytes']);
        self::assertLessThan(8 * 1024 * 1024, memory_get_usage(true) - $start);
        self::assertSame(0700, fileperms($this->root . '/out/deep/implicit') & 0777);
    }
}
