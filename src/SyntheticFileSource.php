<?php
declare(strict_types=1);
namespace WordPressR2Backup;

final class SyntheticFileSource
{
    private bool $paused = false;
    private string $directory;

    private function __construct(string $directory)
    {
        $this->directory = $directory;
    }

    public static function create(string $privateParent): self
    {
        $directory = FileSafety::createPrivateDirectory(FileSafety::privateDirectory($privateParent) . '/source-' . bin2hex(random_bytes(16)));
        $self = new self($directory);
        FileSafety::createPrivateDirectory($directory . '/content');
        self::writeNew($directory . '/source.json', "wordpress-r2-backup owned file source v1\n");
        self::writeNew($directory . '/gate.lock', '');
        self::writeNew($directory . '/content/.wrb-synthetic', 'wordpress-r2-backup-v1');
        return $self;
    }

    public static function open(string $privateDirectory): self
    {
        $directory = FileSafety::privateDirectory($privateDirectory);
        $temporary = realpath(sys_get_temp_dir());
        $relative = substr($directory, strlen($temporary) + 1);
        $root = explode('/', $relative)[0];
        if (!str_starts_with($directory, $temporary . '/') || !str_starts_with($root, 'wrb-')) { throw new \RuntimeException('Synthetic source requires an owned temporary root.'); }
        FileSafety::privateDirectory($temporary . '/' . $root);
        $self = new self($directory);
        $self->assertOwned();
        $names = array_values(array_diff(scandir($directory), ['.', '..']));
        if ($names !== ['content', 'gate.lock', 'source.json']) { throw new \RuntimeException('Unexpected owned source contents.'); }
        $self->inventory();
        return $self;
    }

    public function root(): string { $this->assertOwned(); return $this->directory . '/content'; }

    public function withWriter(callable $writer, int $timeoutSeconds = 30)
    {
        if ($this->paused) { throw new \RuntimeException('Cannot write while this source is paused.'); }
        return $this->locked(LOCK_SH, $writer, $timeoutSeconds);
    }

    public function write(string $path, string $contents): void
    {
        $this->withWriter(function () use ($path, $contents): void {
            $absolute = $this->destination($path);
            if (@lstat($absolute) !== false) { self::privateFile($absolute); }
            $temporary = dirname($absolute) . '/.write-' . bin2hex(random_bytes(12));
            try { self::writeNew($temporary, $contents); if (!rename($temporary, $absolute)) { throw new \RuntimeException('Cannot replace synthetic content.'); } }
            finally { if (is_file($temporary)) { unlink($temporary); } }
        });
    }

    public function mkdir(string $path): void
    {
        $this->withWriter(function () use ($path): void { FileSafety::createPrivateDirectory($this->destination($path)); });
    }

    public function remove(string $path): void
    {
        $this->withWriter(function () use ($path): void {
            $absolute = $this->destination($path);
            if (is_dir($absolute)) {
                FileSafety::privateDirectory($absolute);
                if (!rmdir($absolute)) { throw new \RuntimeException('Only empty synthetic directories may be removed.'); }
            } else { self::privateFile($absolute); if (!unlink($absolute)) { throw new \RuntimeException('Cannot remove synthetic content.'); } }
        });
    }

    public function whilePaused(callable $capture, int $timeoutSeconds = 30): array
    {
        if ($this->paused) { throw new \RuntimeException('Nested source pauses are not supported.'); }
        $result = $this->locked(LOCK_EX, function () use ($capture): array {
            $this->paused = true;
            try { $pausedAt = self::now(); return ['result' => $capture(), 'file' => ['pausedAt' => $pausedAt]]; }
            finally { $this->paused = false; }
        }, $timeoutSeconds);
        $result['file']['releasedAt'] = self::now();
        return $result;
    }

    public function inventory(): array
    {
        $root = $this->root(); $entries = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            $path = substr($file->getPathname(), strlen($root) + 1);
            if ($path === '.wrb-synthetic') { continue; }
            if ($file->isDir() && !$file->isLink()) { FileSafety::privateDirectory($file->getPathname()); $entries[] = ['path' => $path, 'type' => 'directory']; }
            else { self::privateFile($file->getPathname()); $entries[] = ['path' => $path, 'type' => 'file', 'size' => filesize($file->getPathname()), 'sha256' => hash_file('sha256', $file->getPathname())]; }
            if (count($entries) > Manifest::MAX_ENTRIES) { throw new \RuntimeException('Too many synthetic source entries.'); }
        }
        FileSafety::validateEntries($entries);
        usort($entries, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));
        return $entries;
    }

    private function locked(int $operation, callable $callback, int $timeoutSeconds)
    {
        if ($timeoutSeconds < 1 || $timeoutSeconds > 300) { throw new \InvalidArgumentException('Invalid source lock timeout.'); }
        $this->assertOwned();
        $stream = fopen($this->directory . '/gate.lock', 'r+b');
        if ($stream === false) { throw new \RuntimeException('Cannot open the synthetic file gate.'); }
        try {
            $deadline = microtime(true) + $timeoutSeconds;
            while (!flock($stream, $operation | LOCK_NB)) {
                if (microtime(true) >= $deadline) { throw new \RuntimeException('Synthetic source lock timed out.'); }
                usleep(10000);
            }
            $this->assertOwned();
            return $callback();
        } finally {
            $released = flock($stream, LOCK_UN); fclose($stream);
            if (!$released) { throw new \RuntimeException('Cannot release the synthetic file gate.'); }
        }
    }

    private function destination(string $path): string
    {
        FileSafety::validatePaths([$path]);
        if ($path === '.wrb-synthetic' || str_starts_with($path, '.wrb-synthetic/')) { throw new \InvalidArgumentException('The synthetic marker is reserved.'); }
        $absolute = $this->root() . '/' . $path;
        FileSafety::assertNoLinks($absolute);
        $parent = dirname($absolute);
        if ($parent !== $this->root() && !is_dir($parent)) {
            $current = $this->root();
            foreach (explode('/', dirname($path)) as $part) { $current = FileSafety::createPrivateDirectory($current . '/' . $part); }
        }
        FileSafety::privateDirectory($parent);
        return $absolute;
    }

    private function assertOwned(): void
    {
        FileSafety::privateDirectory($this->directory);
        FileSafety::privateDirectory($this->directory . '/content');
        foreach (['source.json', 'gate.lock', 'content/.wrb-synthetic'] as $name) { self::privateFile($this->directory . '/' . $name); }
        if (file_get_contents($this->directory . '/source.json') !== "wordpress-r2-backup owned file source v1\n"
            || file_get_contents($this->directory . '/content/.wrb-synthetic') !== 'wordpress-r2-backup-v1') { throw new \RuntimeException('Invalid owned synthetic file source.'); }
    }

    private static function privateFile(string $path): void
    {
        FileSafety::assertRegularFile($path); clearstatcache(true, $path); $stat = lstat($path);
        if (($stat['mode'] & 0777) !== 0600 || $stat['nlink'] !== 1 || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())) { throw new \RuntimeException('A private unlinked owner file is required.'); }
    }

    private static function writeNew(string $path, string $contents): void
    {
        $stream = fopen($path, 'xb');
        if ($stream === false) { throw new \RuntimeException('Cannot create private synthetic content.'); }
        try { if (!chmod($path, 0600) || fwrite($stream, $contents) !== strlen($contents) || !fflush($stream) || !\WordPressR2Backup\FileSafety::sync($stream)) { throw new \RuntimeException('Cannot persist private synthetic content.'); } }
        finally { fclose($stream); }
    }

    private static function now(): string { return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'); }
}
