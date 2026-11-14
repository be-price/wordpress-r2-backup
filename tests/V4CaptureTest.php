<?php
declare(strict_types=1);

namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use WordPressR2Backup\AgeProcess;
use WordPressR2Backup\Manifest;
use WordPressR2Backup\LocalRepository;
use WordPressR2Backup\PrivacyPolicy;
use WordPressR2Backup\SourceRoots;
use WordPressR2Backup\V4Capture;
use WordPressR2Backup\V4Format;
use WordPressR2Backup\V4Pack;
use WordPressR2Backup\V4Restorer;

require_once __DIR__ . '/WordPressDatabaseTest.php';

final class V4CaptureTest extends TestCase
{
    private array $keys;
    private AgeProcess $age;

    protected function setUp(): void { $this->keys = Fixture::keys(2); $this->age = new AgeProcess(Fixture::runtime()['age']); }
    protected function tearDown(): void { Fixture::cleanup(); }

    /** @dataProvider excludedFileRootPolicies */
    public function testExcludedAdditionalFileRootCanBeInspectedAndRestored(array $rules): void
    {
        $source = Fixture::temp(); Fixture::write($source, 'index.php', 'application');
        $parent = Fixture::temp(); Fixture::write($parent, 'wp-config.php', 'synthetic authentication');
        $capture = new V4Capture(new SourceRoots($source, [$parent . '/wp-config.php']), new V4DatabaseWpdbFake(), new PrivacyPolicy($rules), Fixture::temp());
        $result = $capture->capture($this->age, $this->recipients(), str_repeat('b', 64), $this->scope());
        self::assertCount(1, $result['manifest']['roots']);
        self::assertSame('r0001', $result['manifest']['roots'][0]['id']);
        $storage = Fixture::temp(); $repository = LocalRepository::forWriting($storage, $this->scope()['unitId']);
        foreach ($result['objects'] as $object) { $repository->putNew($object['object']['key'], $object['cipherFile']); }
        $reader = new V4Restorer($this->age, LocalRepository::forRecovery($storage, $this->scope()['unitId']));
        $identity = fopen($this->keys[0]['identity'], 'rb');
        try { self::assertSame(4, $reader->inspect($result['manifest'], $identity, Fixture::temp())['manifestVersion']); }
        finally { fclose($identity); }
        $identity = fopen($this->keys[0]['identity'], 'rb'); $destination = Fixture::temp() . '/restored';
        try {
            $reader->recover($result['manifest'], ['key' => 'manifests/' . str_repeat('b', 64) . '.age', 'bytes' => 1, 'sha256' => hash('sha256', 'synthetic')], $identity, $destination, null, null, false);
        } finally { fclose($identity); }
        self::assertSame('application', file_get_contents($destination . '/roots/r0001/index.php'));
        self::assertFileDoesNotExist($destination . '/roots/r0002');
    }

    public static function excludedFileRootPolicies(): array
    {
        return [
            [['version' => 2, 'preset' => 'custom', 'categories' => ['authentication' => 'exclude']]],
            [['version' => 2, 'preset' => 'custom', 'paths' => ['wp-config.php' => 'exclude']]],
        ];
    }

    public function testMigratedLegacyPolicyKeepsApplicationDirectoriesAndPreviouslyAcceptedText(): void
    {
        $source = Fixture::temp(); Fixture::write($source, 'proof.txt', 'ordinary application text'); Fixture::write($source, 'wp-content/uploads/proof.txt', 'ordinary public text'); Fixture::write($source, 'wp-admin/assets/style.css', 'body{}');
        $policy = new PrivacyPolicy(PrivacyPolicy::migrateLegacy(['tables' => ['records' => 'include']]));
        $capture = new V4Capture(new SourceRoots($source), new V4DatabaseWpdbFake(), $policy, Fixture::temp());
        $scan = $capture->scan();
        self::assertSame(3, $scan['files']); self::assertSame(4, $scan['directories']);
    }

    public function testLegacyMediaCategoryDoesNotSilentlyApprovePreviouslyUnknownDirectories(): void
    {
        $source = Fixture::temp(); Fixture::write($source, 'wp-content/uploads/year/image.jpg', 'image');
        $rules = PrivacyPolicy::migrateLegacy(['tables' => ['records' => 'include'], 'categories' => ['media' => 'include'], 'scanPublic' => true]);
        $capture = new V4Capture(new SourceRoots($source), new V4DatabaseWpdbFake(), new PrivacyPolicy($rules), Fixture::temp());
        $this->expectException(RuntimeException::class);
        $capture->scan();
    }

    public function testNewMediaCategoryOverridesThePreservedLegacyDirectoryReview(): void
    {
        $source = Fixture::temp(); Fixture::write($source, 'wp-content/uploads/year/image.jpg', 'image');
        $rules = PrivacyPolicy::migrateLegacy(['tables' => ['records' => 'include'], 'categories' => ['media' => 'include'], 'scanPublic' => true]);
        $rules['categories']['media'] = 'include';
        $scan = (new V4Capture(new SourceRoots($source), new V4DatabaseWpdbFake(), new PrivacyPolicy($rules), Fixture::temp()))->scan();
        self::assertSame(1, $scan['files']);
    }

    public function testMigratedLegacyPublicTextStillRequiresAnExplicitPersonalDataDecision(): void
    {
        $source = Fixture::temp(); Fixture::write($source, 'wp-content/uploads/proof.txt', 'person@example.invalid');
        $policy = new PrivacyPolicy(PrivacyPolicy::migrateLegacy(['tables' => ['records' => 'include']]));
        $capture = new V4Capture(new SourceRoots($source), new V4DatabaseWpdbFake(), $policy, Fixture::temp());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('legacy public-text candidate');
        $capture->scan();
    }

    public function testFullCaptureKeepsExactPrivateFilesModesAndSqlAfterAllSourcesDisappear(): void
    {
        $files = ['wp-config.php' => '<?php synthetic-secret', '.htaccess' => 'Deny from all', '.env' => 'SYNTHETIC_TOKEN=private',
            'private/document.pdf' => "%PDF-1.7\0synthetic private PDF", 'logs/debug.log' => "synthetic private log\n",
            'wp-content/uploads/image.bin' => str_repeat('m', 1048576) . 'tail', 'same.bin' => '<?php synthetic-secret', 'empty.txt' => ''];
        $source = Fixture::temp(); foreach ($files as $path => $bytes) { Fixture::write($source, $path, $bytes); }
        mkdir($source . '/empty-directory', 0750); chmod($source, 0750);
        symlink('private/document.pdf', $source . '/document-link');
        $private = Fixture::temp(); $wpdb = new V4DatabaseWpdbFake();
        $capture = new V4Capture(new SourceRoots($source), $wpdb, $this->full(), $private, null, 'https://synthetic.example.test');
        $scan = $capture->scan(); self::assertSame(count($files), $scan['files']); self::assertSame(0, $scan['excluded']);
        self::assertSame([], glob($private . '/capture-*/*.age'));
        $result = $capture->capture($this->age, $this->recipients(), str_repeat('b', 64), $this->scope());
        V4Format::validateRoot($result['manifest']);
        self::assertSame(0750, $result['manifest']['roots'][0]['mode']);
        self::assertSame('https://synthetic.example.test', $result['manifest']['wordpress']['siteUrl']);
        self::assertFalse($wpdb->locked); self::assertSame('+09:00', $wpdb->timeZone);
        self::assertSame(2, $result['evidence']['tables'][0]['rows']);
        self::assertStringNotContainsString('private@example.test', json_encode($result['manifest']));
        $this->assertNoPlainBodies($private, ['synthetic-secret', 'SYNTHETIC_TOKEN', 'synthetic private PDF', 'private@example.test']);
        foreach ($files as $path => $bytes) { unlink($source . '/' . $path); }
        unlink($source . '/document-link'); rmdir($source . '/private'); rmdir($source . '/logs'); rmdir($source . '/wp-content/uploads'); rmdir($source . '/wp-content'); rmdir($source . '/empty-directory'); rmdir($source);
        $wpdb->tables = [];
        [$records, $contents] = $this->decode($result);
        $actual = []; $current = null; $sql = ''; $modes = []; $refs = [];
        foreach ($records['filesystem'] as $record) {
            if ($record['type'] === 'file') { $current = $record['path']; $actual[$current] = ''; $modes[$current] = $record['mode']; }
            elseif ($record['type'] === 'part') { $actual[$current] .= $contents[$record['content']['id']]; $refs[$current][] = $record['content']; }
            elseif ($record['type'] === 'end') { self::assertSame(hash('sha256', $actual[$current]), $record['sha256']); $current = null; }
        }
        foreach ($records['database'] as $record) { if ($record['type'] === 'sql') { $sql .= $contents[$record['content']['id']]; self::assertLessThanOrEqual(1048576, $record['content']['size']); } }
        ksort($actual); ksort($files); self::assertSame($files, $actual);
        self::assertSame(array_fill_keys(array_keys($files), 0600), array_replace(array_fill_keys(array_keys($files), 0600), $modes));
        self::assertSame($refs['same.bin'], $refs['wp-config.php']);
        self::assertSame(strlen($sql), $result['manifest']['database']['size']); self::assertSame(hash('sha256', $sql), $result['manifest']['database']['sha256']);
        self::assertStringContainsString('CREATE TABLE `records`', $sql); self::assertStringContainsString('INSERT INTO `records`', $sql);
        self::assertSame(['preamble', 'definition', 'data', 'postamble'], array_values(array_unique(array_column(array_filter($records['database'], static fn(array $record): bool => $record['type'] === 'sql'), 'stage'))));
        self::assertSame(['empty-directory'], array_column(array_values(array_filter($records['filesystem'], static fn(array $record): bool => $record['type'] === 'directory' && $record['path'] === 'empty-directory')), 'path'));
    }

    public function testAnExcludedParentStillSavesItsExplicitlyIncludedChildAndOnlyRequiredStructure(): void
    {
        $root = Fixture::temp(); Fixture::write($root, 'private/keep/item.bin', 'included'); Fixture::write($root, 'private/drop.bin', 'excluded'); mkdir($root . '/private/unused', 0700);
        $policy = new PrivacyPolicy(['version' => 2, 'preset' => 'custom', 'paths' => ['private' => 'exclude', 'private/keep/item.bin' => 'include']]);
        $capture = new V4Capture(new SourceRoots($root), new V4DatabaseWpdbFake(), $policy, Fixture::temp());
        $result = $capture->capture($this->age, $this->recipients(), str_repeat('b', 64), $this->scope());
        [$records] = $this->decode($result);
        self::assertSame(['private', 'private/keep', 'private/keep/item.bin'], array_column(array_values(array_filter($records['filesystem'], static fn(array $record): bool => isset($record['path']))), 'path'));
    }

    public function testExcludedLinkTargetIsRejectedBeforeEncryption(): void
    {
        $root = Fixture::temp(); Fixture::write($root, 'secret.bin', 'secret'); symlink('secret.bin', $root . '/link'); $private = Fixture::temp();
        $capture = new V4Capture(new SourceRoots($root), new V4DatabaseWpdbFake(), new PrivacyPolicy(['version' => 2, 'preset' => 'custom', 'paths' => ['secret.bin' => 'exclude']]), $private);
        $failure=null;
        try { $capture->scan(); } catch (RuntimeException $error) { $failure=$error; }
        self::assertInstanceOf(RuntimeException::class,$failure); self::assertStringContainsString('excluded',$failure->getMessage()); self::assertSame([],glob($private . '/capture-*/*.age'));
    }

    public function testCaptureSkipsOnlyPreflightReenumerationAndRetainsStandaloneScan(): void
    {
        $root=Fixture::temp();
        for ($i=0;$i<100;++$i) { Fixture::write($root, sprintf('item-%03d.bin',$i), 'synthetic'); }
        $counts=[];
        foreach (['scan','capture'] as $mode) {
            $capture=new V4Capture(new SourceRoots($root),new V4DatabaseWpdbFake(),$this->full(),Fixture::temp());
            $passes=0;
            $progress=static function(array $update) use (&$passes): void {
                if (($update['phase'] ?? '')==='scan' && ($update['entries'] ?? 0)===100 && !isset($update['selected'])) { ++$passes; }
            };
            if ($mode==='scan') { $result=$capture->scan($progress); self::assertSame(100,$result['files']); }
            else { $result=$capture->capture($this->age,$this->recipients(),str_repeat('b',64),$this->scope(),$progress); self::assertSame(100,$result['manifest']['counts']['files']); }
            $counts[$mode]=$passes;
        }
        self::assertSame(['scan'=>2,'capture'=>1],$counts);
    }

    public function testCaptureStillRejectsChangedSelectedEntriesAndExcludedLinkTargets(): void
    {
        foreach (['missing','added','changed','link'] as $kind) {
            $root=Fixture::temp(); Fixture::write($root,'saved.bin','original');
            $policy=$this->full();
            if ($kind==='link') {
                Fixture::write($root,'secret.bin','secret'); symlink('secret.bin',$root.'/link');
                $policy=new PrivacyPolicy(['version'=>2,'preset'=>'custom','paths'=>['secret.bin'=>'exclude']]);
            }
            $capture=new V4Capture(new SourceRoots($root),new V4DatabaseWpdbFake(),$policy,Fixture::temp()); $changed=false;
            $failure=null;
            try {
                $capture->capture($this->age,$this->recipients(),str_repeat('b',64),$this->scope(),static function(array $update) use ($root,$kind,&$changed): void {
                    if ($changed || ($update['phase'] ?? '')!=='scan' || !isset($update['selected'])) { return; }
                    $changed=true;
                    if ($kind==='missing') { unlink($root.'/saved.bin'); }
                    elseif ($kind==='added') { Fixture::write($root,'added.bin','new'); }
                    elseif ($kind==='changed') { file_put_contents($root.'/saved.bin','modified'); }
                });
            } catch (RuntimeException $error) { $failure=$error; }
            self::assertTrue($changed); self::assertInstanceOf(RuntimeException::class,$failure,'Capture must reject '.$kind);
            $reuseFailure=null;
            try { $capture->capture($this->age,$this->recipients(),str_repeat('b',64),$this->scope()); }
            catch (RuntimeException $error) { $reuseFailure=$error; }
            self::assertInstanceOf(RuntimeException::class,$reuseFailure); self::assertStringContainsString('current state',$reuseFailure->getMessage());
        }
    }

    public function testFinalEnumerationRejectsChangesDuringCapture(): void
    {
        foreach (['missing','added','changed'] as $kind) {
            $root=Fixture::temp();
            for ($i=0;$i<100;++$i) { Fixture::write($root,sprintf('item-%03d.bin',$i),'original'); }
            $capture=new V4Capture(new SourceRoots($root),new V4DatabaseWpdbFake(),$this->full(),Fixture::temp()); $changed=false;
            $failure=null;
            try {
                $capture->capture($this->age,$this->recipients(),str_repeat('b',64),$this->scope(),static function(array $update) use ($root,$kind,&$changed): void {
                    if ($changed || ($update['phase'] ?? '')!=='capture' || ($update['files'] ?? 0)!==100) { return; }
                    $changed=true;
                    if ($kind==='missing') { unlink($root.'/item-000.bin'); }
                    elseif ($kind==='added') { Fixture::write($root,'added.bin','new'); }
                    else { file_put_contents($root.'/item-000.bin','modified'); }
                });
            } catch (RuntimeException $error) { $failure=$error; }
            self::assertTrue($changed); self::assertInstanceOf(RuntimeException::class,$failure,'Final verification must reject '.$kind);
        }
    }

    public function testMutationWithPreservedSizeAndMtimeCannotCommit(): void
    {
        $root = Fixture::temp(); Fixture::write($root, 'a.bin', 'initial'); $mtime = filemtime($root . '/a.bin');
        $capture = new V4Capture(new SourceRoots($root), new V4DatabaseWpdbFake(), $this->full(), Fixture::temp());
        $this->expectException(RuntimeException::class);
        $capture->capture($this->age, $this->recipients(), str_repeat('b', 64), $this->scope(), static function (array $progress) use ($root, $mtime): void {
            if ($progress['phase'] === 'capture') { file_put_contents($root . '/a.bin', 'mutated'); touch($root . '/a.bin', $mtime); }
        });
    }

    public function testUnknownAndMissingEntriesCannotCommit(): void
    {
        foreach (['unknown', 'missing'] as $kind) {
            $root = Fixture::temp(); Fixture::write($root, 'a.bin', 'initial');
            $capture = new V4Capture(new SourceRoots($root), new V4DatabaseWpdbFake(), $this->full(), Fixture::temp());
            try {
                $capture->capture($this->age, $this->recipients(), str_repeat('b', 64), $this->scope(), static function (array $progress) use ($root, $kind): void {
                    if ($progress['phase'] === 'capture') { if ($kind === 'unknown') { Fixture::write($root, 'new.bin', 'new'); } else { unlink($root . '/a.bin'); } }
                }); self::fail('An inventory change must fail.');
            } catch (RuntimeException $expected) { self::assertStringContainsString('source', strtolower($expected->getMessage())); }
        }
    }

    public function testUnsafeIncludedEntryAndDatabaseMetadataFailureOccurBeforeAnyEncryption(): void
    {
        foreach (['mode', 'metadata'] as $kind) {
            $root = Fixture::temp(); Fixture::write($root, 'excluded.bin', 'private'); $private = Fixture::temp(); $wpdb = new V4DatabaseWpdbFake();
            if ($kind === 'mode') { chmod($root . '/excluded.bin', 04600); } else { $wpdb->grants = 'GRANT SELECT ON `fixture`.* TO synthetic'; }
            $capture = new V4Capture(new SourceRoots($root), $wpdb, $this->full(), $private);
            $failure=null;
            try { $capture->scan(); } catch (RuntimeException $error) { $failure=$error; }
            self::assertInstanceOf(RuntimeException::class,$failure); self::assertSame([],glob($private . '/capture-*/*.age'));
        }
    }

    public function testLegacyContextWithoutContentTablesPreparesWithoutBroadeningSelection(): void
    {
        $private = Fixture::temp();
        $policy = new PrivacyPolicy(['publicContentOnly' => true, 'tables' => ['records' => 'include']]);
        $capture = new V4Capture(new SourceRoots(Fixture::temp()), new V4DatabaseWpdbFake(), $policy, $private);
        self::assertSame(0, $capture->scan()['selected']);
        self::assertSame([], glob($private . '/capture-*/*.age'));
    }

    public function testChangedPolicyContextFailsUnderTheLockBeforeEncryption(): void
    {
        $wpdb = new PolicyCaptureWpdbFake(); $private = Fixture::temp();
        $capture = new V4Capture(new SourceRoots(Fixture::temp()), $wpdb, new PrivacyPolicy(['version' => 2, 'preset' => 'forms']), $private);
        $capture->scan();
        $wpdb->database->tables['wp_posts']['rows'][0]['post_type'] = 'post';
        try {
            $capture->capture($this->age, $this->recipients(), str_repeat('b', 64), $this->scope());
            self::fail('A changed policy context was accepted.');
        } catch (RuntimeException $error) { self::assertStringContainsString('Policy context changed', $error->getMessage()); }
        self::assertFalse($wpdb->database->locked);
        self::assertSame([], glob($private . '/capture-*/*.age'));
    }

    public function testLargeSqlIsSplitAndCustomRowAndFieldRulesUseTheSameCaptureEngine(): void
    {
        $wpdb = new V4DatabaseWpdbFake();
        $wpdb->tables['records']['rows'][0]['payload'] = str_repeat('z', 700000);
        array_pop($wpdb->tables['records']['columns']);
        foreach ($wpdb->tables['records']['rows'] as &$row) { unset($row['calculated']); } unset($row);
        $wpdb->tables['records']['create'] = 'CREATE TABLE `records` (`id` bigint unsigned NOT NULL PRIMARY KEY,`payload` longblob,`note` varchar(255),`stamp` timestamp(6)) ENGINE=InnoDB';
        $policy = new PrivacyPolicy(['version' => 2, 'preset' => 'custom', 'rows' => ['records' => ['id' => ['2' => 'exclude']]], 'fields' => ['records.note' => 'exclude']]);
        $capture = new V4Capture(new SourceRoots(Fixture::temp()), $wpdb, $policy, Fixture::temp());
        $result = $capture->capture($this->age, $this->recipients(), str_repeat('b', 64), $this->scope());
        [$records, $contents] = $this->decode($result); $sql = ''; $parts = [];
        foreach ($records['database'] as $record) {
            if ($record['type'] !== 'sql') { continue; }
            $sql .= $contents[$record['content']['id']];
            if ($record['stage'] === 'data') { $parts[] = $record['content']; }
        }
        self::assertCount(2, $parts); self::assertSame(1048576, $parts[0]['size']);
        self::assertStringNotContainsString(bin2hex('private@example.test'), $sql);
        self::assertSame(1, $result['evidence']['tables'][0]['rows']); self::assertSame(1, $result['evidence']['tables'][0]['excludedRows']);
    }

    public function testMissingPrivateInventoryFailsInsteadOfSilentlyDroppingAFile(): void
    {
        $root = Fixture::temp(); Fixture::write($root, 'a.bin', 'original'); $private = Fixture::temp();
        $capture = new V4Capture(new SourceRoots($root), new V4DatabaseWpdbFake(), $this->full(), $private); $capture->scan();
        foreach (glob($private . '/capture-*/source-map/*') as $path) { if (is_file($path)) { unlink($path); } }
        $this->expectException(RuntimeException::class);
        $capture->capture($this->age, $this->recipients(), str_repeat('b', 64), $this->scope());
    }

    public function testPrivateInventoryContainsNoOriginalPathsOrLinkTargets(): void
    {
        $root = Fixture::temp(); $path = 'private/user-private@example.test.pdf';
        Fixture::write($root, $path, 'synthetic original document'); symlink($path, $root . '/personal-link'); $private = Fixture::temp();
        $capture = new V4Capture(new SourceRoots($root), new V4DatabaseWpdbFake(), $this->full(), $private);
        $result = $capture->capture($this->age, $this->recipients(), str_repeat('b', 64), $this->scope());
        $this->assertNoPlainBodies($private, [$root, $path, 'user-private@example.test.pdf', 'personal-link', 'synthetic original document']);
        [$records] = $this->decode($result);
        self::assertContains($path, array_column($records['filesystem'], 'path'));
    }

    public function testIncludedAgeIdentityIsRejectedAndUnknownExcludedIdentityIsNotRead(): void
    {
        foreach (['full', 'excluded'] as $kind) {
            $root = Fixture::temp(); Fixture::write($root, 'ordinary-name.txt', file_get_contents($this->keys[0]['identity'])); $private = Fixture::temp();
            $policy = $kind === 'full' ? $this->full() : new PrivacyPolicy(['version' => 2, 'preset' => 'custom', 'paths' => ['ordinary-name.txt' => 'exclude']]);
            $capture = new V4Capture(new SourceRoots($root), new V4DatabaseWpdbFake(), $policy, $private);
            if ($kind === 'excluded') {
                self::assertSame(0, $capture->scan()['selected']);
                self::assertSame([], glob($private . '/capture-*/*.age'));
                continue;
            }
            try { $capture->scan(); self::fail('An age identity inside a source must fail.'); }
            catch (RuntimeException $expected) { self::assertStringContainsString('private identity', $expected->getMessage()); self::assertSame([], glob($private . '/capture-*/*.age')); }
        }
    }

    public function testExcludedFilesMayChangeDisappearAndAppearDuringCapture(): void
    {
        $root = Fixture::temp(); Fixture::write($root, 'data/saved.bin', 'saved');
        Fixture::write($root, 'data/live.log', 'before'); Fixture::write($root, 'data/old.log', 'remove');
        $policy = new PrivacyPolicy(['version' => 2, 'preset' => 'custom', 'paths' => [
            'data/live.log' => 'exclude', 'data/old.log' => 'exclude', 'data/new.log' => 'exclude', 'data/cache' => 'exclude',
        ]]);
        $capture = new V4Capture(new SourceRoots($root), new V4DatabaseWpdbFake(), $policy, Fixture::temp());
        $scan = $capture->scan(); $changed = false;
        $result = $capture->capture($this->age, $this->recipients(), str_repeat('b', 64), $this->scope(), static function (array $progress) use ($root, &$changed): void {
            if ($progress['phase'] !== 'capture' || $changed) { return; }
            $changed = true; $mtime = filemtime($root . '/data/live.log');
            Fixture::write($root, 'data/live.log', 'after!'); touch($root . '/data/live.log', $mtime);
            unlink($root . '/data/old.log'); Fixture::write($root, 'data/new.log', 'created');
            Fixture::write($root, 'data/cache/new.log', 'ignored subtree');
        });
        self::assertTrue($changed); self::assertSame(4, $scan['entries']); self::assertSame(2, $scan['selected']); self::assertSame(2, $scan['excluded']);
        [$records] = $this->decode($result);
        self::assertSame(['data', 'data/saved.bin'], array_values(array_filter(array_column($records['filesystem'], 'path'))));
    }

    public function testExcludedDirectoryIsPrunedAndAnExplicitIncludedChildKeepsItsAncestors(): void
    {
        $root = Fixture::temp(); Fixture::write($root, 'cache/kept/saved.txt', 'saved'); Fixture::write($root, 'cache/ignored/log', 'excluded');
        symlink(Fixture::temp(), $root . '/cache/ignored/outside');
        if (function_exists('posix_mkfifo')) { self::assertTrue(posix_mkfifo($root . '/cache/ignored/fifo', 0600)); }
        $policy = new PrivacyPolicy(['version' => 2, 'preset' => 'custom', 'paths' => ['cache' => 'exclude', 'cache/kept/saved.txt' => 'include']]);
        $capture = new V4Capture(new SourceRoots($root), new V4DatabaseWpdbFake(), $policy, Fixture::temp());
        $scan = $capture->scan();
        self::assertSame(4, $scan['entries']); self::assertSame(3, $scan['selected']); self::assertSame(1, $scan['excluded']);
        $result = $capture->capture($this->age, $this->recipients(), str_repeat('b', 64), $this->scope());
        [$records] = $this->decode($result);
        self::assertSame(['cache', 'cache/kept', 'cache/kept/saved.txt'], array_values(array_filter(array_column($records['filesystem'], 'path'))));
    }

    public function testExcludedUnreadableFilesAndUnsafeEntriesAreNotReadOrSaved(): void
    {
        $root = Fixture::temp(); Fixture::write($root, 'saved.txt', 'saved'); Fixture::write($root, 'log', file_get_contents($this->keys[0]['identity']));
        chmod($root . '/log', 0000); symlink(Fixture::temp(), $root . '/outside');
        Fixture::write($root, 'setuid', 'ignored'); chmod($root . '/setuid', 04600);
        if (function_exists('posix_mkfifo')) { self::assertTrue(posix_mkfifo($root . '/fifo', 0600)); }
        $policy = new PrivacyPolicy(['version' => 2, 'preset' => 'custom', 'paths' => ['log' => 'exclude', 'outside' => 'exclude', 'setuid' => 'exclude', 'fifo' => 'exclude']]);
        $capture = new V4Capture(new SourceRoots($root), new V4DatabaseWpdbFake(), $policy, Fixture::temp());
        self::assertSame(1, $capture->scan()['selected']);
        $result = $capture->capture($this->age, $this->recipients(), str_repeat('b', 64), $this->scope());
        self::assertSame(1, $result['manifest']['counts']['files']);
    }

    public function testKnownPrivatePathOverlapIsRejectedEvenWhenItsPathWouldBeExcluded(): void
    {
        $root = Fixture::temp(); Fixture::write($root, 'cache/private.agekey', 'synthetic private location');
        $this->expectException(RuntimeException::class); $this->expectExceptionMessage('private location overlaps');
        new V4Capture(new SourceRoots($root, [], [$root . '/cache/private.agekey']), new V4DatabaseWpdbFake(),
            new PrivacyPolicy(['version' => 2, 'preset' => 'custom', 'paths' => ['cache' => 'exclude']]), Fixture::temp());
    }

    public function testSecretLikeConfigurationAndInvalidIdentityLookalikesRemainIncluded(): void
    {
        $root = Fixture::temp();
        Fixture::write($root, 'wp-config.php', "<?php // password=synthetic\nAGE-SECRET-KEY-1" . str_repeat('Q', 58) . "\n");
        $capture = new V4Capture(new SourceRoots($root), new V4DatabaseWpdbFake(), $this->full(), Fixture::temp());
        $result = $capture->capture($this->age, $this->recipients(), str_repeat('b', 64), $this->scope());
        [$records, $contents] = $this->decode($result); $actual = '';
        foreach ($records['filesystem'] as $record) { if ($record['type'] === 'part') { $actual .= $contents[$record['content']['id']]; } }
        self::assertSame(file_get_contents($root . '/wp-config.php'), $actual);
    }

    public function testScanReportsProgressBeforeEnumerationCompletesIncludingExcludedFiles(): void
    {
        $root = Fixture::temp();
        for ($index = 0; $index < 205; ++$index) { Fixture::write($root, 'log-' . $index, 'synthetic'); }
        $paths = array_fill_keys(array_map(static function (int $index): string { return 'log-' . $index; }, range(0, 204)), 'exclude');
        $capture = new V4Capture(new SourceRoots($root), new V4DatabaseWpdbFake(), new PrivacyPolicy(['version' => 2, 'preset' => 'custom', 'paths' => $paths]), Fixture::temp());
        $events = [];
        $scan = $capture->scan(static function (array $event) use (&$events): void { $events[] = $event; });
        self::assertSame(205, $scan['entries']); self::assertSame(0, $scan['selected']);
        self::assertSame(['phase' => 'scan', 'entries' => 100], $events[0]);
        self::assertSame(['phase' => 'scan', 'entries' => 200], $events[1]);
        self::assertSame(205, $events[count($events) - 1]['entries']);
    }

    public function testLargeFileAndDatabaseReportByteProgressDuringCaptureAndFinalVerification(): void
    {
        $root = Fixture::temp(); $fileBytes = 33 * 1024 * 1024;
        $stream = fopen($root . '/large.bin', 'wb');
        try { for ($index = 0; $index < 33; ++$index) { fwrite($stream, str_repeat('f', 1024 * 1024)); } }
        finally { fclose($stream); }
        $wpdb = new V4DatabaseWpdbFake(); $row = $wpdb->tables['records']['rows'][0]; $row['payload'] = str_repeat('d', 5 * 1024 * 1024);
        $wpdb->tables['records']['rows'] = [];
        for ($index = 1; $index <= 4; ++$index) { $row['id'] = (string) $index; $row['calculated'] = (string) ($index + 1); $wpdb->tables['records']['rows'][] = $row; }
        $capture = new V4Capture(new SourceRoots($root), $wpdb, $this->full(), Fixture::temp());
        $scanByteEvents = 0; $captureByteEvents = 0; $databaseEvents = 0;
        $result = $capture->capture($this->age, $this->recipients(), str_repeat('b', 64), $this->scope(),
            static function (array $event) use (&$scanByteEvents, &$captureByteEvents, &$databaseEvents, $fileBytes, $wpdb): void {
                if (($event['fileBytes'] ?? 0) === 32 * 1024 * 1024) {
                    self::assertLessThan($fileBytes, $event['fileBytes']);
                    if ($event['phase'] === 'scan') { ++$scanByteEvents; }
                    else { self::assertTrue($wpdb->locked); ++$captureByteEvents; }
                }
                if (isset($event['databaseBytes'])) { self::assertTrue($wpdb->locked); ++$databaseEvents; }
            });
        self::assertSame(1, $scanByteEvents); self::assertSame(2, $captureByteEvents); self::assertSame(1, $databaseEvents);
        self::assertSame($fileBytes, $result['manifest']['counts']['fileBytes']); self::assertFalse($wpdb->locked);
    }

    private function full(): PrivacyPolicy { return new PrivacyPolicy(['version' => 2, 'preset' => 'full']); }
    private function recipients(): array { return array_column($this->keys, 'public'); }
    private function scope(): array { return ['accountId' => str_repeat('a', 32), 'bucket' => 'synthetic-capture', 'unitId' => str_repeat('c', 64), 'keySetId' => Manifest::keySetId($this->recipients()), 'encoding' => V4Format::ENCODING]; }

    private function decrypt(string $cipher): string
    {
        $plain = Fixture::temp() . '/decrypted'; $identity = fopen($this->keys[0]['identity'], 'rb');
        try { $this->age->decrypt($cipher, $plain, $identity); } finally { fclose($identity); }
        return $plain;
    }

    private function decode(array $result): array
    {
        $objects = []; foreach ($result['objects'] as $object) { $objects[$object['object']['key']] = $object['cipherFile']; }
        $records = ['packs' => [], 'filesystem' => [], 'database' => []]; $contents = [];
        foreach ($result['manifest']['pages'] as $descriptor) {
            self::assertArrayNotHasKey('cipherFile', $descriptor);
            $page = json_decode(file_get_contents($this->decrypt($objects[$descriptor['object']['key']])), true, 512, JSON_THROW_ON_ERROR);
            V4Format::validatePage($page, $descriptor);
            foreach ($page['records'] as $record) { $records[$page['kind']][] = $record; }
        }
        foreach ($records['packs'] as $pack) {
            $directory = Fixture::temp() . '/contents'; V4Pack::extract($this->decrypt($objects[$pack['object']['key']]), $directory, $pack);
            foreach ($pack['members'] as $member) { $contents[$member['id']] = file_get_contents($directory . '/' . $member['id']); }
        }
        return [$records, $contents];
    }

    private function assertNoPlainBodies(string $directory, array $secrets): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $entry) {
            if (!$entry->isFile()) { continue; }
            self::assertSame(0600, $entry->getPerms() & 0777);
            self::assertTrue(substr($entry->getFilename(), -4) === '.age' || strpos($entry->getPathname(), '/source-map/') !== false || strpos($entry->getPathname(), '/content-map/') !== false);
            foreach ($secrets as $secret) { self::assertStringNotContainsString($secret, file_get_contents($entry->getPathname())); }
        }
    }
}

final class PolicyCaptureWpdbFake
{
    public string $prefix = 'wp_';
    public string $last_error = '';
    public V4DatabaseWpdbFake $database;
    public function __construct()
    {
        $this->database = new V4DatabaseWpdbFake();
        $row = ['ID' => '1', 'post_type' => 'flamingo_inbound', 'post_status' => 'publish', 'post_parent' => '0', 'post_password' => '', 'post_mime_type' => ''];
        $columns = [];
        foreach ($row as $field => $value) { $columns[] = ['Field' => $field, 'Type' => $field === 'ID' ? 'bigint unsigned' : 'varchar(255)', 'Key' => $field === 'ID' ? 'PRI' : '', 'Extra' => '', 'Null' => 'NO', 'Collation' => $field === 'ID' ? null : 'utf8mb4_unicode_ci']; }
        $this->database->tables = ['wp_posts' => ['columns' => $columns, 'rows' => [$row], 'create' => 'CREATE TABLE `wp_posts` (`ID` bigint unsigned PRIMARY KEY,`post_type` varchar(255),`post_status` varchar(255),`post_parent` varchar(255),`post_password` varchar(255),`post_mime_type` varchar(255)) ENGINE=InnoDB']];
    }
    public function get_results(string $sql, string $format): array
    {
        if ($sql === 'SHOW INDEX FROM `wp_posts`') { return [['Non_unique' => '0', 'Column_name' => 'ID', 'Key_name' => 'PRIMARY', 'Seq_in_index' => '1']]; }
        if (strpos($sql, 'SELECT `ID`,`post_status`') === 0 && preg_match('/ LIMIT ([0-9]+),1\z/D', $sql, $match)) { return array_slice($this->database->tables['wp_posts']['rows'], (int) $match[1], 1); }
        return $this->database->get_results($sql, $format);
    }
    public function get_var(string $sql) { return $this->database->get_var($sql); }
    public function query(string $sql) { return $this->database->query($sql); }
}
