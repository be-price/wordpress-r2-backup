<?php
declare(strict_types=1);

namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\SourceRoots;

final class SourceRootsTest extends TestCase
{
    protected function tearDown(): void { Fixture::cleanup(); }

    public function testNestedConsumerExceptionIsNotLost(): void
    {
        $site = Fixture::temp(); Fixture::write($site, 'one/two/file', 'synthetic');
        $expected = new \RuntimeException('synthetic consumer failure'); $caught = null;
        try {
            foreach ((new SourceRoots($site))->entries() as $entry) {
                if ($entry['path'] === 'one/two/file') { throw $expected; }
            }
        } catch (\Throwable $error) { $caught = $error; }
        self::assertSame($expected, $caught);
    }

    /** @runInSeparateProcess */
    public function testExternalContentRootUsesCanonicalPolicyPathsWithoutChangingDescriptors(): void
    {
        $site = Fixture::temp(); $content = Fixture::temp();
        Fixture::write($site, 'index.php', 'synthetic');
        Fixture::write($content, 'uploads/private.bin', 'synthetic');
        define('WP_CONTENT_DIR', $content);
        $roots = SourceRoots::fromWordPress($site);
        self::assertSame($content, $roots->descriptors()[1]['sourcePath']);
        foreach ($roots->entries() as $entry) {
            if ($entry['rootId'] === 'r0002' && $entry['path'] === 'uploads/private.bin') {
                self::assertSame('wp-content/uploads/private.bin', $roots->policyPath($entry));
                self::assertSame(['id', 'name', 'sourcePath', 'type', 'mode'], array_keys($roots->descriptors()[1]));
                return;
            }
        }
        self::fail('The external content file was not enumerated.');
    }

    public function testDescriptorsAndEntriesPreserveModesAndSeparateSingleFileRoots(): void
    {
        $site = Fixture::temp(); $external = Fixture::temp();
        Fixture::write($site, 'empty.txt', ''); Fixture::write($site, 'nested/code.php', 'code');
        Fixture::write($site, '.maintenance', 'another operation'); Fixture::write($external, 'wp-config.php', 'synthetic config');
        Fixture::write($site, 'zero-mode', ''); chmod($site . '/zero-mode', 0000);
        chmod($site, 0750); chmod($site . '/empty.txt', 0640); chmod($site . '/nested', 0755); chmod($external . '/wp-config.php', 0400);
        $source = new SourceRoots($site, [$external . '/wp-config.php']);
        self::assertSame([
            ['id' => 'r0001', 'name' => 'r0001', 'sourcePath' => $site, 'type' => 'directory', 'mode' => 0750],
            ['id' => 'r0002', 'name' => 'r0002', 'sourcePath' => $external . '/wp-config.php', 'type' => 'file', 'mode' => 0400],
        ], $source->descriptors());
        $generator = $source->entries(); self::assertInstanceOf(\Generator::class, $generator);
        $entries = $this->byPath($generator);
        self::assertCount(6, $entries);
        self::assertSame(0640, $entries['r0001/empty.txt']['mode']);
        self::assertSame(0000, $entries['r0001/zero-mode']['mode']);
        self::assertSame('directory', $entries['r0001/nested']['type']);
        self::assertSame(0755, $entries['r0001/nested']['mode']);
        self::assertSame('file', $entries['r0001/.maintenance']['type']);
        self::assertSame($external . '/wp-config.php', $entries['r0002/wp-config.php']['absolute']);
        self::assertSame(0400, $entries['r0002/wp-config.php']['mode']);
        $stat = $entries['r0001/empty.txt']['stat'];
        foreach (['dev', 'ino', 'size', 'mtime', 'ctime', 'mode', 'nlink'] as $field) { self::assertArrayHasKey($field, $stat); }
        self::assertSame(0, $stat['size']);
    }

    public function testAliasesAndContainingRootsAreReadOnlyOnce(): void
    {
        $parent = Fixture::temp(); $aliases = Fixture::temp();
        Fixture::write($parent, 'site/content/file', 'one');
        symlink($parent . '/site', $aliases . '/site');
        $source = new SourceRoots($parent . '/site', [$aliases . '/site', $parent . '/site/content', $parent . '/site/content/file', $parent]);
        self::assertCount(1, $source->descriptors());
        self::assertSame($parent, $source->descriptors()[0]['sourcePath']);
        self::assertSame(['r0001/site', 'r0001/site/content', 'r0001/site/content/file'], array_keys($this->byPath($source->entries())));
    }

    public function testPrivateLocationsRejectDescendantsAncestorsAliasesAndUncreatedPaths(): void
    {
        $parent = Fixture::temp(); $outside = Fixture::temp();
        Fixture::write($parent, 'site/file', 'one');
        symlink($parent . '/site', $outside . '/alias');
        $source = new SourceRoots($parent . '/site');
        foreach ([$parent, $parent . '/site', $parent . '/site/not-created/metadata', $outside . '/alias/private'] as $private) {
            try { $source->verifyPrivateLocations([$private]); self::fail('An intersecting private location was accepted.'); }
            catch (\RuntimeException $error) { self::assertStringContainsString('overlaps', $error->getMessage()); }
        }
        $source->verifyPrivateLocations([$outside . '/safe/not-created']);
        $this->expectException(\RuntimeException::class);
        new SourceRoots($parent . '/site', [], [$outside . '/alias/private']);
    }

    public function testPrivateLocationCannotContainAnExternalSingleFileRoot(): void
    {
        $site = Fixture::temp(); $external = Fixture::temp(); Fixture::write($external, 'config', 'one');
        $this->expectException(\RuntimeException::class);
        new SourceRoots($site, [$external . '/config'], [$external]);
    }

    public function testSafeLinksAreRecordedWithoutTraversingThem(): void
    {
        $site = Fixture::temp(); Fixture::write($site, 'directory/file', 'one');
        symlink('directory/file', $site . '/file-link'); symlink('directory', $site . '/directory-link');
        symlink('../directory/file', $site . '/directory/relative-link'); symlink('.', $site . '/root-link');
        symlink('directory//file', $site . '/repeated-slash-link'); symlink('directory/', $site . '/trailing-slash-link');
        $entries = $this->byPath((new SourceRoots($site))->entries());
        self::assertCount(8, $entries);
        self::assertSame('symlink', $entries['r0001/file-link']['type']);
        self::assertSame('directory/file', $entries['r0001/file-link']['target']);
        self::assertSame('../directory/file', $entries['r0001/directory/relative-link']['target']);
        self::assertSame('.', $entries['r0001/root-link']['target']);
        self::assertSame('directory//file', $entries['r0001/repeated-slash-link']['target']);
        self::assertSame('directory/', $entries['r0001/trailing-slash-link']['target']);
        self::assertArrayNotHasKey('r0001/directory-link/file', $entries);
    }

    /** @dataProvider unsafeLinkTargets */
    public function testUnsafeLinkTargetsAreRejected(string $target): void
    {
        $site = Fixture::temp(); Fixture::write($site, 'directory/file', 'one');
        symlink($target, $site . '/unsafe-link');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unsafe-link');
        iterator_to_array((new SourceRoots($site))->entries());
    }

    public static function unsafeLinkTargets(): array
    {
        return [['../outside'], ['/absolute'], ['missing'], ['unsafe-link'], ["directory/fi\nle"]];
    }

    public function testLinkTargetCannotPassThroughAnotherLinkBeforeDotDot(): void
    {
        $site = Fixture::temp(); Fixture::write($site, 'directory/file', 'one'); Fixture::write($site, 'file', 'two');
        symlink('directory', $site . '/alias'); symlink('alias/../file', $site . '/unsafe-link');
        $this->expectException(\RuntimeException::class); $this->expectExceptionMessage('unsafe-link');
        iterator_to_array((new SourceRoots($site))->entries());
    }

    public function testOnlyTheOwnedMaintenanceFileIsExcludedAndTokenSurvivesRefresh(): void
    {
        $site = Fixture::temp(); $token = str_repeat('a', 32);
        $body = '<?php $upgrading = 1; /* wrb-' . $token . ' */';
        Fixture::write($site, '.maintenance', $body); Fixture::write($site, 'nested/.maintenance', $body);
        $stat = lstat($site . '/.maintenance');
        $owner = ['dev' => $stat['dev'], 'ino' => $stat['ino'], 'token' => $token, 'sha256' => hash('sha256', $body)];
        file_put_contents($site . '/.maintenance', '<?php $upgrading = 1234567890; /* wrb-' . $token . ' */');
        $source = new SourceRoots($site);
        $entries = $this->byPath($source->entries($owner));
        self::assertArrayNotHasKey('r0001/.maintenance', $entries);
        self::assertArrayHasKey('r0001/nested/.maintenance', $entries);
        self::assertArrayHasKey('r0001/.maintenance', $this->byPath($source->entries()));
        $owner['token'] = str_repeat('b', 32);
        self::assertArrayHasKey('r0001/.maintenance', $this->byPath($source->entries($owner)));
        $owner['token'] = $token; $owner['ino']++;
        self::assertArrayHasKey('r0001/.maintenance', $this->byPath($source->entries($owner)));
    }

    public function testLegacyMaintenanceOwnershipRequiresAnExactDigest(): void
    {
        $site = Fixture::temp(); Fixture::write($site, '.maintenance', '<?php $upgrading = 1;');
        $stat = lstat($site . '/.maintenance');
        $owner = ['dev' => $stat['dev'], 'ino' => $stat['ino'], 'sha256' => hash_file('sha256', $site . '/.maintenance')];
        $source = new SourceRoots($site);
        self::assertSame([], $this->byPath($source->entries($owner)));
        file_put_contents($site . '/.maintenance', '<?php $upgrading = 2;');
        self::assertArrayHasKey('r0001/.maintenance', $this->byPath($source->entries($owner)));
    }

    public function testLinkToAnExcludedMaintenanceFileIsRejected(): void
    {
        $site = Fixture::temp(); Fixture::write($site, '.maintenance', '<?php $upgrading = 1;'); symlink('.maintenance', $site . '/marker-link');
        $stat = lstat($site . '/.maintenance');
        $owner = ['dev' => $stat['dev'], 'ino' => $stat['ino'], 'sha256' => hash_file('sha256', $site . '/.maintenance')];
        $this->expectException(\RuntimeException::class); $this->expectExceptionMessage('marker-link');
        iterator_to_array((new SourceRoots($site))->entries($owner));
    }

    public function testDangerousModesReportTheEntryAndReason(): void
    {
        $site = Fixture::temp(); Fixture::write($site, 'dangerous', 'one'); chmod($site . '/dangerous', 04700);
        $this->expectException(\RuntimeException::class); $this->expectExceptionMessageMatches('/Setuid, setgid and sticky.*dangerous/');
        iterator_to_array((new SourceRoots($site))->entries());
    }

    public function testHardLinksAreRejectedEvenWhenTheirOtherNameIsOutsideTheRoot(): void
    {
        $site = Fixture::temp(); $outside = Fixture::temp(); Fixture::write($site, 'shared', 'one'); link($site . '/shared', $outside . '/other');
        $this->expectException(\RuntimeException::class); $this->expectExceptionMessageMatches('/Hard-linked.*shared/');
        iterator_to_array((new SourceRoots($site))->entries());
    }

    public function testSpecialFilesAreRejected(): void
    {
        if (!function_exists('posix_mkfifo')) { self::markTestSkipped('POSIX FIFO creation is unavailable.'); }
        $site = Fixture::temp(); self::assertTrue(posix_mkfifo($site . '/pipe', 0600));
        $this->expectException(\RuntimeException::class); $this->expectExceptionMessageMatches('/Special filesystem.*pipe/');
        iterator_to_array((new SourceRoots($site))->entries());
    }

    public function testUnsafeNamesAreRejected(): void
    {
        $site = Fixture::temp(); Fixture::write($site, 'safe:unsafe', 'one');
        $this->expectException(\RuntimeException::class); $this->expectExceptionMessage('safe:unsafe');
        iterator_to_array((new SourceRoots($site))->entries());
    }

    public function testUnicodeCaseFoldCollisionsAreRejected(): void
    {
        $site = Fixture::temp(); Fixture::write($site, 'straße', 'one'); Fixture::write($site, 'strasse', 'two');
        if (lstat($site . '/straße')['ino'] === lstat($site . '/strasse')['ino']) { self::markTestSkipped('The filesystem already merges these names.'); }
        $this->expectException(\RuntimeException::class); $this->expectExceptionMessage('Normalized source path collision');
        iterator_to_array((new SourceRoots($site))->entries());
    }

    public function testMoreThanTenThousandSiblingsAreStillStreamed(): void
    {
        $site = Fixture::temp();
        for ($index = 0; $index < 10001; $index++) { touch($site . '/file-' . $index); }
        $count = 0; $onlyFiles = true;
        foreach ((new SourceRoots($site))->entries() as $entry) { $onlyFiles = $onlyFiles && $entry['type'] === 'file'; $count++; }
        self::assertTrue($onlyFiles);
        self::assertSame(10001, $count);
    }

    public function testRootLimitIsAppliedAfterOverlapNormalization(): void
    {
        $site = Fixture::temp(); $external = Fixture::temp(); $paths = [];
        for ($index = 0; $index < 64; $index++) { $paths[] = $external . '/root-' . $index; mkdir($paths[$index], 0700); }
        self::assertCount(64, (new SourceRoots($site, array_slice($paths, 0, 63)))->descriptors());
        $this->expectException(\RuntimeException::class); $this->expectExceptionMessage('64 roots');
        new SourceRoots($site, $paths);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testWordPressFactoryAddsOnlyConfiguredExternalRootsAndTheUsedParentConfig(): void
    {
        $parent = Fixture::temp(); $content = Fixture::temp(); $uploads = Fixture::temp();
        mkdir($parent . '/wordpress', 0700); Fixture::write($parent, 'wp-config.php', 'synthetic config'); Fixture::write($parent, 'unrelated', 'excluded');
        define('WP_CONTENT_DIR', $content);
        $GLOBALS['wrb_source_roots_uploads'] = $uploads;
        eval('function wp_upload_dir($time = null, $create = true) { if ($create !== false) { throw new \\RuntimeException("The factory must not create directories."); } return ["basedir" => $GLOBALS["wrb_source_roots_uploads"], "error" => false]; }');
        $roots = SourceRoots::fromWordPress($parent . '/wordpress')->descriptors();
        self::assertSame([$parent . '/wordpress', $content, $uploads, $parent . '/wp-config.php'], array_column($roots, 'sourcePath'));
        Fixture::write($parent, 'wordpress/wp-config.php', 'local config');
        self::assertCount(3, SourceRoots::fromWordPress($parent . '/wordpress')->descriptors());
        unlink($parent . '/wordpress/wp-config.php'); Fixture::write($parent, 'wp-settings.php', 'another WordPress installation');
        self::assertCount(3, SourceRoots::fromWordPress($parent . '/wordpress')->descriptors());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testWordPressFactoryDoesNotRequireAnUnusedInternalUploadsDirectory(): void
    {
        $site = Fixture::temp(); mkdir($site . '/wp-content', 0700);
        define('WP_CONTENT_DIR', $site . '/wp-content');
        $GLOBALS['wrb_source_roots_uploads'] = $site . '/wp-content/uploads';
        eval('function wp_upload_dir($time = null, $create = true) { return ["basedir" => $GLOBALS["wrb_source_roots_uploads"], "error" => false]; }');
        $roots = SourceRoots::fromWordPress($site);
        self::assertSame([$site], array_column($roots->descriptors(), 'sourcePath'));
        self::assertDirectoryDoesNotExist($site . '/wp-content/uploads');
    }

    public function testSelectionPrunesExcludedUnsafeEntriesWithoutFollowingLinks(): void
    {
        $site = Fixture::temp(); $outside = Fixture::temp();
        Fixture::write($site, 'saved.txt', 'saved'); Fixture::write($site, 'cache/log', 'ignored');
        symlink($outside, $site . '/cache/outside'); symlink($outside, $site . '/outside-link');
        Fixture::write($site, 'setuid', 'ignored'); chmod($site . '/setuid', 04600);
        link($site . '/cache/log', $site . '/hardlink');
        if (function_exists('posix_mkfifo')) { self::assertTrue(posix_mkfifo($site . '/fifo', 0600)); }
        $observed = [];
        $entries = $this->byPath((new SourceRoots($site))->entries(null, static function (array $entry) use (&$observed): array {
            $observed[] = $entry['path'];
            return ['include' => $entry['path'] === 'saved.txt', 'descend' => false];
        }));
        self::assertSame(['r0001/saved.txt'], array_keys($entries));
        self::assertNotContains('cache/log', $observed);
        self::assertContains('outside-link', $observed);
        $this->expectException(\RuntimeException::class);
        iterator_to_array((new SourceRoots($site))->entries());
    }

    public function testSelectedUnsupportedEntriesRemainRejected(): void
    {
        foreach (['mode', 'link', 'hardlink', 'fifo'] as $kind) {
            if ($kind === 'fifo' && !function_exists('posix_mkfifo')) { continue; }
            $site = Fixture::temp(); Fixture::write($site, 'original', 'synthetic');
            if ($kind === 'mode') { chmod($site . '/original', 04600); }
            elseif ($kind === 'link') { symlink(Fixture::temp(), $site . '/link'); }
            elseif ($kind === 'hardlink') { link($site . '/original', $site . '/hardlink'); }
            else { self::assertTrue(posix_mkfifo($site . '/fifo', 0600)); }
            try {
                iterator_to_array((new SourceRoots($site))->entries(null, static function (array $entry): array {
                    return ['include' => true, 'descend' => $entry['type'] === 'directory'];
                }));
                self::fail('Selected unsafe ' . $kind . ' entry was accepted.');
            } catch (\RuntimeException $expected) { self::assertStringContainsString('Source:', $expected->getMessage()); }
        }
    }

    private function byPath(iterable $entries): array
    {
        $result = [];
        foreach ($entries as $entry) { $result[$entry['rootId'] . '/' . $entry['path']] = $entry; }
        ksort($result);
        return $result;
    }
}
