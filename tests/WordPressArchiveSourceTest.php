<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\{AgeProcess, PrivacyPolicy, WordPressSource};

require_once __DIR__ . '/WordPressSourceTest.php';

final class WordPressArchiveSourceTest extends TestCase
{
    protected function tearDown(): void { Fixture::cleanup(); }

    public function testScanBoundsDisplayButCountsEveryEntry(): void
    {
        $root = Fixture::temp();
        for ($i = 0; $i < 550; $i++) { Fixture::write($root, 'unknown-' . $i . '.dat', 'public'); }
        $scan = (new WordPressSource($root, new SourceWpdbFixture(), new PrivacyPolicy(['scanPublic' => false]), Fixture::temp()))->scan();
        self::assertSame(550, $scan['filesTotal']);
        self::assertLessThanOrEqual(500, count($scan['files']));
        self::assertSame(550 - count($scan['files']), $scan['filesOmitted']);
        self::assertSame(550, $scan['unresolved']);
    }

    public function testArchivesSplitAndContainOnlyCiphertextAndIgnoreExcludedChanges(): void
    {
        $root = Fixture::temp();
        for ($i = 0; $i < 5001; $i++) { Fixture::write($root, 'wp-content/plugins/sample/file-' . $i . '.php', 'public'); }
        Fixture::write($root, '.maintenance', 'before');
        $private = Fixture::temp(); $db = new SourceWpdbFixture(); $keys = Fixture::keys(); $phases = [];
        $result = (new WordPressSource($root, $db, new PrivacyPolicy(['scanPublic' => false]), $private))->captureArchives(
            new AgeProcess(Fixture::runtime()['age']), array_column($keys, 'public'), str_repeat('a', 64),
            static function (array $progress) use ($root, &$phases): void { $phases[] = $progress['phase']; file_put_contents($root . '/.maintenance', 'after'); }
        );
        self::assertSame([], $result['files']);
        self::assertCount(2, $result['archives']);
        self::assertSame(5001, array_sum(array_column($result['archives'], 'fileCount')));
        self::assertSame(3, array_sum(array_column($result['archives'], 'directoryCount')));
        self::assertSame(5001, $result['evidence']['fileCount']);
        self::assertTrue($db->unlocked);
        self::assertContains('encrypting_database', $phases);
        self::assertContains('verifying_files', $phases);
        foreach ($result['archives'] as $archive) {
            self::assertFileExists($archive['cipherFile']);
            $plain = Fixture::temp() . '/archive.tar'; $identity = fopen($keys[0]['identity'], 'rb');
            try { (new AgeProcess(Fixture::runtime()['age']))->decrypt($archive['cipherFile'], $plain, $identity); }
            finally { fclose($identity); }
            self::assertSame($archive['sha256'], hash_file('sha256', $plain));
            self::assertSame($archive['size'], filesize($plain));
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($private, \FilesystemIterator::SKIP_DOTS)) as $file) {
            self::assertSame('age', pathinfo($file->getPathname(), PATHINFO_EXTENSION));
        }
    }

    public function testArchiveCaptureRejectsSameSizeSameMtimeContentChanges(): void
    {
        $root = Fixture::temp(); Fixture::write($root, 'index.php', 'before'); $mtime = filemtime($root . '/index.php');
        $db = new SourceWpdbFixture(); $keys = Fixture::keys(); $caught = null;
        try {
            (new WordPressSource($root, $db, new PrivacyPolicy(['scanPublic' => false]), Fixture::temp()))->captureArchives(
                new AgeProcess(Fixture::runtime()['age']), array_column($keys, 'public'), str_repeat('b', 64),
                static function (array $progress) use ($root, $mtime): void {
                    if ($progress['phase'] === 'archive_complete') { file_put_contents($root . '/index.php', 'after!'); touch($root . '/index.php', $mtime); }
                }
            );
        } catch (\RuntimeException $error) { $caught = $error; }
        self::assertInstanceOf(\RuntimeException::class, $caught);
        self::assertTrue($db->unlocked);
    }

    /** @dataProvider inventoryChanges */
    public function testArchiveCaptureRejectsIncludedInventoryChanges(string $change): void
    {
        $root = Fixture::temp(); Fixture::write($root, 'index.php', 'before');
        $db = new SourceWpdbFixture(); $keys = Fixture::keys(); $caught = null;
        try {
            (new WordPressSource($root, $db, new PrivacyPolicy(['scanPublic' => false]), Fixture::temp()))->captureArchives(
                new AgeProcess(Fixture::runtime()['age']), array_column($keys, 'public'), str_repeat('c', 64),
                static function (array $progress) use ($root, $change): void {
                    if ($progress['phase'] !== 'archive_complete') { return; }
                    if ($change === 'added') { Fixture::write($root, 'wp-content/plugins/new.php', 'new'); }
                    elseif ($change === 'deleted') { unlink($root . '/index.php'); }
                    else { rename($root . '/index.php', Fixture::temp() . '/old.php'); Fixture::write($root, 'index.php', 'before'); }
                }
            );
        } catch (\RuntimeException $error) { $caught = $error; }
        self::assertInstanceOf(\RuntimeException::class, $caught);
        self::assertTrue($db->unlocked);
    }

    public function inventoryChanges(): array { return [['added'], ['deleted'], ['replaced']]; }

    public function testOversizedSourceIsRejectedBeforeDatabaseLock(): void
    {
        $root = Fixture::temp(); $handle = fopen($root . '/index.php', 'wb');
        try { self::assertTrue(ftruncate($handle, 5 * 1024 * 1024 * 1024)); } finally { fclose($handle); }
        $db = new SourceWpdbFixture(); $caught = null;
        try {
            (new WordPressSource($root, $db, new PrivacyPolicy(['scanPublic' => false]), Fixture::temp()))->captureArchives(new AgeProcess(Fixture::runtime()['age']), array_column(Fixture::keys(), 'public'), str_repeat('d', 64));
        } catch (\RuntimeException $error) { $caught = $error; }
        self::assertInstanceOf(\RuntimeException::class, $caught);
        self::assertFalse($db->locked);
    }
}
