<?php
declare(strict_types=1);

namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\{AgeProcess, DedupIndex, LocalRepository, Manifest, SnapshotWriter};

final class DeduplicationTest extends TestCase
{
    protected function tearDown(): void
    {
        Fixture::cleanup();
    }

    public function testSameContentAcrossNamesAndGenerationsUsesOneObject(): void
    {
        [$writer, $reader, $index, $keys, $root] = $this->setupBackup();
        $site = Fixture::site(['a.txt' => 'shared', 'b.txt' => 'shared', 'c.txt' => 'old']);
        $sql = Fixture::temp() . '/database.sql';
        file_put_contents($sql, 'synthetic SQL');
        $capture = new SnapshotWriter(new AgeProcess(Fixture::runtime()['age']), $writer, $index);
        $first = $capture->capture($site, $sql, array_column($keys, 'public'));
        $keySet = Manifest::keySetId(array_column($keys, 'public'));
        self::assertCount(2, $reader->listFileObjects($keySet));
        rename($site . '/a.txt', $site . '/renamed.txt');
        unlink($site . '/b.txt');
        $second = $capture->capture($site, $sql, array_column($keys, 'public'));
        self::assertNotSame($first, $second);
        self::assertCount(2, $reader->listFileObjects($keySet));
        Fixture::write($site, 'c.txt', 'new');
        $capture->capture($site, $sql, array_column($keys, 'public'));
        self::assertCount(3, $reader->listFileObjects($keySet));
        self::assertCount(3, $reader->listCompleted());
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $bytes = file_get_contents($file->getPathname());
                self::assertStringNotContainsString(hash('sha256', 'shared'), $bytes);
                self::assertStringNotContainsString('renamed.txt', $bytes);
            }
        }
    }

    public function testKeyChangesDoNotReuseOldObjectsAndMissingIndexStopsCapture(): void
    {
        [$writer, $reader, $index, $keys] = $this->setupBackup();
        $site = Fixture::site(['a' => 'shared']);
        $sql = Fixture::temp() . '/database.sql';
        file_put_contents($sql, 'synthetic SQL');
        $capture = new SnapshotWriter(new AgeProcess(Fixture::runtime()['age']), $writer, $index);
        $capture->capture($site, $sql, array_column($keys, 'public'));
        $newKeys = [$keys[0], Fixture::keys(1)[0]];
        $capture->capture($site, $sql, array_column($newKeys, 'public'));
        self::assertCount(1, $reader->listFileObjects(Manifest::keySetId(array_column($keys, 'public'))));
        self::assertCount(1, $reader->listFileObjects(Manifest::keySetId(array_column($newKeys, 'public'))));
        $this->expectException(\RuntimeException::class);
        DedupIndex::openOrCreate(Fixture::temp(), $writer);
    }

    private function setupBackup(): array
    {
        $root = Fixture::temp();
        $unit = str_repeat('a', 64);
        $writer = LocalRepository::forWriting($root, $unit);
        $reader = LocalRepository::forRecovery($root, $unit);
        return [$writer, $reader, DedupIndex::openOrCreate(Fixture::temp(), $writer), Fixture::keys(), $root];
    }
}
