<?php
declare(strict_types=1);

namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\{AgeProcess, ArchiveRestorer, BackupArchive, LocalRepository, Manifest, ObjectReader};

final class ArchiveRestorerTest extends TestCase
{
    protected function tearDown(): void { Fixture::cleanup(); }

    public function testMultipleArchivesCanBeRecoveredWithEitherIdentity(): void
    {
        [$keys, $reader, $age, $snapshot] = $this->fixture();
        foreach ($keys as $key) {
            $parent = Fixture::temp();
            $result = $this->recover(new ArchiveRestorer($age, $reader), $snapshot, $key['identity'], $parent . '/site');
            self::assertSame(['snapshotId' => $snapshot, 'files' => 2, 'directories' => 1, 'bytes' => 11, 'databaseImported' => false], $result);
            self::assertSame('first', file_get_contents($parent . '/site/folder/a.txt'));
            self::assertSame('second', file_get_contents($parent . '/site/b.txt'));
            self::assertCount(3, scandir($parent));
        }
    }

    public function testExistingDestinationIsNeverReplaced(): void
    {
        [$keys, $reader, $age, $snapshot] = $this->fixture();
        $parent = Fixture::temp(); mkdir($parent . '/site', 0700);
        Fixture::write($parent . '/site', 'sentinel', 'keep');
        $this->reject(fn() => $this->recover(new ArchiveRestorer($age, $reader), $snapshot, $keys[0]['identity'], $parent . '/site'));
        self::assertSame('keep', file_get_contents($parent . '/site/sentinel'));
    }

    public function testAuthenticatedArchiveWithWrongPlaintextHashIsRejected(): void
    {
        [$keys, $reader, $age, $snapshot] = $this->fixture(true);
        $parent = Fixture::temp();
        $this->reject(fn() => $this->recover(new ArchiveRestorer($age, $reader), $snapshot, $keys[0]['identity'], $parent . '/site'));
        self::assertFileDoesNotExist($parent . '/site');
    }

    public function testRetryReusesCompletedArchiveAndPublishesTheSameInode(): void
    {
        [$keys, $reader, $age, $snapshot, $archives] = $this->fixture();
        $parent = Fixture::temp(); $destination = $parent . '/site';
        $failing = $this->failingReader($reader, $archives[1]['object']['key']);
        $restorer = new ArchiveRestorer($age, $failing);
        $this->reject(fn() => $this->recover($restorer, $snapshot, $keys[0]['identity'], $destination));
        $workspace = $this->workspace($reader, $snapshot, $destination);
        $source = $workspace . '/parts/0/files/folder/a.txt';
        $inode = fileinode($source);
        self::assertIsInt($inode);
        $this->recover($restorer, $snapshot, $keys[1]['identity'], $destination, true);
        self::assertSame($inode, fileinode($destination . '/folder/a.txt'));
    }

    public function testOwnedPublicationRefusesUnknownEntriesAndDifferentInodes(): void
    {
        foreach (['unknown', 'different-inode'] as $case) {
            [$keys, $reader, $age, $snapshot, $archives] = $this->fixture();
            $parent = Fixture::temp(); $destination = $parent . '/site';
            $restorer = new ArchiveRestorer($age, $this->failingReader($reader, $archives[1]['object']['key']));
            $this->reject(fn() => $this->recover($restorer, $snapshot, $keys[0]['identity'], $destination));
            $workspace = $this->workspace($reader, $snapshot, $destination);
            $this->seedPublication($workspace, $destination);
            if ($case === 'unknown') { Fixture::write($destination, 'unknown.txt', 'keep'); }
            else { Fixture::write($destination, 'folder/a.txt', 'first'); }
            $this->reject(fn() => $this->recover($restorer, $snapshot, $keys[0]['identity'], $destination, true));
            $sentinel = $case === 'unknown' ? $destination . '/unknown.txt' : $destination . '/folder/a.txt';
            $inode = fileinode($sentinel);
            $restorer->discard($snapshot, $destination);
            self::assertSame($inode, fileinode($sentinel));
            self::assertDirectoryDoesNotExist($workspace);
        }
    }

    public function testResumePreservesPublishedHardlink(): void
    {
        [$keys, $reader, $age, $snapshot, $archives] = $this->fixture();
        $parent = Fixture::temp(); $destination = $parent . '/site';
        $restorer = new ArchiveRestorer($age, $this->failingReader($reader, $archives[1]['object']['key']));
        $this->reject(fn() => $this->recover($restorer, $snapshot, $keys[0]['identity'], $destination));
        $workspace = $this->workspace($reader, $snapshot, $destination);
        $this->seedPublication($workspace, $destination);
        link($workspace . '/parts/0/files/folder/a.txt', $destination . '/folder/a.txt');
        $inode = fileinode($destination . '/folder/a.txt');
        $this->recover($restorer, $snapshot, $keys[0]['identity'], $destination, true);
        self::assertSame($inode, fileinode($destination . '/folder/a.txt'));
    }

    public function testIncompletePartNeedsExplicitDiscardAndCannotBeAdopted(): void
    {
        [$keys, $reader, $age, $snapshot, $archives] = $this->fixture();
        $parent = Fixture::temp(); $destination = $parent . '/site';
        $restorer = new ArchiveRestorer($age, $this->failingReader($reader, $archives[1]['object']['key']));
        $this->reject(fn() => $this->recover($restorer, $snapshot, $keys[0]['identity'], $destination));
        $workspace = $this->workspace($reader, $snapshot, $destination);
        mkdir($workspace . '/parts/1', 0700);
        Fixture::write($workspace . '/parts/1', 'unproven', 'partial');
        $this->reject(fn() => $this->recover($restorer, $snapshot, $keys[0]['identity'], $destination, true));
        self::assertSame('partial', file_get_contents($workspace . '/parts/1/unproven'));
        $restorer->discard($snapshot, $destination);
        self::assertDirectoryDoesNotExist($workspace);
    }

    public function testDiscardRefusesWorkspaceWithoutMatchingOwner(): void
    {
        [$keys, $reader, $age, $snapshot] = $this->fixture();
        $parent = Fixture::temp(); $destination = $parent . '/site';
        $workspace = $this->workspace($reader, $snapshot, $destination);
        mkdir($workspace, 0700); Fixture::write($workspace, 'owner', 'another job');
        $this->reject(fn() => (new ArchiveRestorer($age, $reader))->discard($snapshot, $destination));
        self::assertSame('another job', file_get_contents($workspace . '/owner'));
    }

    public function testCrossArchiveDuplicateCaseFoldAndAncestorCollisionsAreRejected(): void
    {
        foreach ([['same', 'same'], ['Folder/a', 'folder/b'], ['a/b', 'a'], ['a', 'a/b'], ["caf\u{00e9}/a", "cafe\u{0301}/b"]] as $paths) {
            $groups = [];
            foreach ($paths as $path) { $groups[] = [['path' => $path, 'type' => 'file', 'size' => 1, 'chunks' => ['x']]]; }
            [$keys, $reader, $age, $snapshot] = $this->fixture(false, $groups);
            $parent = Fixture::temp();
            $this->reject(fn() => $this->recover(new ArchiveRestorer($age, $reader), $snapshot, $keys[0]['identity'], $parent . '/site'));
            self::assertFileDoesNotExist($parent . '/site');
        }
    }

    public function testLargeRecoveryMemoryBudgetRejectsLowLimitBeforePayloadWork(): void
    {
        [$keys, $reader, $age] = $this->fixture();
        $restorer = new ArchiveRestorer($age, $reader);
        $method = new \ReflectionMethod(ArchiveRestorer::class, 'assertMemoryBudget');
        $method->setAccessible(true);
        $archives = array_fill(0, 46, ['fileCount' => 4999, 'directoryCount' => 1]);
        $original = ini_get('memory_limit');
        try {
            $method->invoke($restorer, $archives, '128M');
            self::fail('A 230000-entry recovery must reject the ordinary 128M limit.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('1009188864 bytes', $error->getMessage());
            self::assertStringContainsString('php -d memory_limit=1G recover.php', $error->getMessage());
        }
        $method->invoke($restorer, $archives, '1G');
        $method->invoke($restorer, $archives, '-1');
        $method->invoke($restorer, [['fileCount' => 2, 'directoryCount' => 1]], '128M');
        self::assertSame($original, ini_get('memory_limit'));
    }

    private function fixture(bool $wrongHash = false, ?array $groups = null): array
    {
        $keys = Fixture::keys(2); $recipients = array_column($keys, 'public');
        $age = new AgeProcess(Fixture::runtime()['age']);
        $repository = Fixture::temp(); $unit = str_repeat('a', 64);
        $writer = LocalRepository::forWriting($repository, $unit);
        $reader = LocalRepository::forRecovery($repository, $unit);
        $root = Fixture::temp(); $snapshot = bin2hex(random_bytes(32)); $keyset = Manifest::keySetId($recipients);
        $groups = $groups ?? [
            [['path' => 'folder', 'type' => 'directory'], ['path' => 'folder/a.txt', 'type' => 'file', 'size' => 5, 'chunks' => ['first']]],
            [['path' => 'b.txt', 'type' => 'file', 'size' => 6, 'chunks' => ['second']]],
        ];
        $archives = [];
        foreach ($groups as $i => $group) {
            $plain = $root . '/part-' . $i; $cipher = $plain . '.age';
            Fixture::write($root, 'part-' . $i, implode('', iterator_to_array(BackupArchive::chunks($group))));
            $scan = BackupArchive::inspect($plain);
            $age->encrypt($plain, $cipher, $recipients);
            $archives[] = ['size' => filesize($plain), 'sha256' => $wrongHash && $i === 1 ? str_repeat('0', 64) : hash_file('sha256', $plain),
                'object' => $writer->putNew('files/' . $keyset . '/' . bin2hex(random_bytes(32)) . '.age', $cipher), 'fileCount' => $scan['files'], 'directoryCount' => $scan['directories']];
        }
        Fixture::write($root, 'db.sql', 'CREATE TABLE synthetic (id INT);');
        $age->encrypt($root . '/db.sql', $root . '/db.age', $recipients);
        $database = ['size' => filesize($root . '/db.sql'), 'sha256' => hash_file('sha256', $root . '/db.sql'),
            'object' => $writer->putNew('databases/' . $snapshot . '.age', $root . '/db.age')];
        $manifest = ['format' => 'wordpress-r2-backup', 'version' => 3, 'dataClass' => 'wordpress', 'unitId' => $unit, 'snapshotId' => $snapshot,
            'keySetId' => $keyset, 'recipients' => $recipients, 'capturedAt' => gmdate('Y-m-d\TH:i:s\Z'), 'files' => [], 'database' => $database,
            'wordpress' => ['siteUrl' => 'https://example.invalid', 'policySha256' => str_repeat('b', 64), 'evidence' => ['events' => []], 'archives' => $archives]];
        Fixture::write($root, 'manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $age->encrypt($root . '/manifest.json', $root . '/manifest.age', $recipients);
        $receipt = $writer->putNew('manifests/' . $snapshot . '.age', $root . '/manifest.age');
        $writer->publish($snapshot, $receipt);
        return [$keys, $reader, $age, $snapshot, $archives];
    }

    private function recover(ArchiveRestorer $restorer, string $snapshot, string $key, string $destination, bool $resume = false): array
    {
        $identity = fopen($key, 'rb');
        try { return $resume ? $restorer->resume($snapshot, $identity, $destination) : $restorer->restore($snapshot, $identity, $destination); }
        finally { fclose($identity); }
    }

    private function reject(callable $operation): void
    {
        try { $operation(); self::fail('Unsafe or interrupted recovery must fail.'); }
        catch (\RuntimeException|\InvalidArgumentException $error) { self::assertNotSame('', $error->getMessage()); }
    }

    private function workspace(ObjectReader $reader, string $snapshot, string $destination): string
    {
        return dirname($destination) . '/.wrb-archive-' . hash('sha256', $reader->unitId() . "\0" . $snapshot . "\0" . $destination);
    }

    private function seedPublication(string $workspace, string $destination): void
    {
        mkdir($destination, 0700); mkdir($destination . '/folder', 0700);
        $stat = lstat($destination); $directory = lstat($destination . '/folder');
        $marker = '.wrb-publication-' . bin2hex(random_bytes(16)); link($workspace . '/owner', $destination . '/' . $marker);
        $state = json_decode(file_get_contents($workspace . '/state.json'), true, 32, JSON_THROW_ON_ERROR);
        $state['publication'] = ['dev' => $stat['dev'], 'ino' => $stat['ino'], 'marker' => $marker, 'ready' => true,
            'directories' => ['folder' => ['dev' => $directory['dev'], 'ino' => $directory['ino']]]];
        file_put_contents($workspace . '/state.json', json_encode($state, JSON_THROW_ON_ERROR));
    }

    private function failingReader(ObjectReader $reader, string $failKey): ObjectReader
    {
        return new class($reader, $failKey) implements ObjectReader {
            private ObjectReader $reader; private string $key; private bool $failed = false;
            public function __construct(ObjectReader $reader, string $key) { $this->reader = $reader; $this->key = $key; }
            public function unitId(): string { return $this->reader->unitId(); }
            public function verify(array $receipt): void { $this->reader->verify($receipt); }
            public function download(array $receipt, string $newCipherFile): void {
                if (!$this->failed && $receipt['key'] === $this->key) { $this->failed = true; throw new \RuntimeException('Injected archive transport interruption.'); }
                $this->reader->download($receipt, $newCipherFile);
            }
            public function completed(string $snapshotId): array { return $this->reader->completed($snapshotId); }
            public function listCompleted(): array { return $this->reader->listCompleted(); }
            public function listStatus(): array { return $this->reader->listStatus(); }
            public function listFileObjects(string $keySetId): array { return $this->reader->listFileObjects($keySetId); }
        };
    }
}
