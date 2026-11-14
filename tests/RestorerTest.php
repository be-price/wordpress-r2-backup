<?php
declare(strict_types=1);

namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\{AgeProcess, DedupIndex, LocalRepository, Manifest, Restorer, SnapshotWriter};

final class RestorerTest extends TestCase
{
    protected function tearDown(): void
    {
        Fixture::cleanup();
    }

    public function testRestoresOnlyTheSelectedSnapshotIncludingDeletedPaths(): void
    {
        [$keys, $site, $repository, $writer, $age, $sql] = $this->backup(['old.txt' => 'old', 'same.txt' => 'same']);
        mkdir($site . '/empty', 0700);
        $recipients = [$keys[0]['public'], $keys[1]['public']];
        $first = $writer->capture($site, $sql, $recipients);
        unlink($site . '/old.txt');
        Fixture::write($site, 'new.txt', 'new');
        Fixture::write($site, 'same.txt', 'changed');
        $second = $writer->capture($site, $sql, $recipients);
        $restorer = new Restorer($age, LocalRepository::forRecovery($repository, str_repeat('a', 64)));
        $parent = Fixture::temp();
        foreach ([[$first, 0, 'old'], [$second, 1, 'new']] as [$snapshot, $key, $name]) {
            $identity = fopen($keys[$key]['identity'], 'rb');
            try {
                $result = $restorer->restore($snapshot, $identity, $parent . '/' . $name);
            } finally {
                fclose($identity);
            }
            self::assertSame(['files' => 2, 'bytes' => $name === 'old' ? 7 : 10, 'databaseImported' => false], $result);
            self::assertDirectoryExists($parent . '/' . $name . '/empty');
        }
        self::assertSame('old', file_get_contents($parent . '/old/old.txt'));
        self::assertSame('same', file_get_contents($parent . '/old/same.txt'));
        self::assertFileDoesNotExist($parent . '/old/new.txt');
        self::assertSame('new', file_get_contents($parent . '/new/new.txt'));
        self::assertSame('changed', file_get_contents($parent . '/new/same.txt'));
        self::assertFileDoesNotExist($parent . '/new/old.txt');
        self::assertCount(4, scandir($parent));
    }

    public function testRejectsInvalidSnapshotWithoutChangingExistingTarget(): void
    {
        [$keys, $site, $repository, $writer, $age, $sql] = $this->backup(['value.txt' => 'retained content']);
        $snapshot = $writer->capture($site, $sql, [$keys[0]['public'], $keys[1]['public']]);
        $reader = LocalRepository::forRecovery($repository, str_repeat('a', 64));
        $restorer = new Restorer($age, $reader);
        $parent = Fixture::temp();
        Fixture::write($parent, 'existing/sentinel', 'do not change');
        $sentinel = $parent . '/existing/sentinel';
        $hash = hash_file('sha256', $sentinel);
        $this->rejectRestore($restorer, $snapshot, $keys[0]['identity'], $parent . '/existing');
        $this->rejectRestore($restorer, $snapshot, $keys[2]['identity'], $parent . '/wrong-key');
        $this->rejectRestore($restorer, str_repeat('f', 64), $keys[0]['identity'], $parent . '/incomplete');
        symlink($parent . '/existing', $parent . '/link');
        $this->rejectRestore($restorer, $snapshot, $keys[0]['identity'], $parent . '/link/child');

        $manifest = $this->readManifest($reader, $age, $snapshot, $keys[0]['identity']);
        $filePath = $reader->objectPath($manifest['files'][0]['object']['key']);
        $ciphertext = file_get_contents($filePath);
        file_put_contents($filePath, substr($ciphertext, 0, -1) . (substr($ciphertext, -1) ^ "\x01"));
        $this->rejectRestore($restorer, $snapshot, $keys[0]['identity'], $parent . '/tampered');
        file_put_contents($filePath, $ciphertext);
        unlink($filePath);
        $this->rejectRestore($restorer, $snapshot, $keys[0]['identity'], $parent . '/missing');
        self::assertSame($hash, hash_file('sha256', $sentinel));
        self::assertSame(['.', '..', 'existing', 'link'], scandir($parent));
    }

    public function testRejectsPlaintextMismatchAndUnsafeManifestPaths(): void
    {
        [$keys, $site, $repository, $writer, $age, $sql] = $this->backup(['value.txt' => 'actual']);
        $snapshot = $writer->capture($site, $sql, [$keys[0]['public'], $keys[1]['public']]);
        $reader = LocalRepository::forRecovery($repository, str_repeat('a', 64));
        $manifest = $this->readManifest($reader, $age, $snapshot, $keys[0]['identity']);
        $restorer = new Restorer($age, $reader);
        $parent = Fixture::temp();
        Fixture::write($parent, 'sentinel', 'retained');
        $hash = hash_file('sha256', $parent . '/sentinel');
        $invalid = $manifest;
        $invalid['files'][0]['sha256'] = str_repeat('0', 64);
        $mismatch = $this->publishManifest($invalid, $repository, $age);
        $this->rejectRestore($restorer, $mismatch, $keys[0]['identity'], $parent . '/mismatch');
        $invalid = $manifest;
        $invalid['files'][0]['path'] = '../outside';
        $unsafe = $this->publishManifest($invalid, $repository, $age);
        $this->rejectRestore($restorer, $unsafe, $keys[0]['identity'], $parent . '/unsafe');
        self::assertSame($hash, hash_file('sha256', $parent . '/sentinel'));
        self::assertSame(['.', '..', 'sentinel'], scandir($parent));
    }

    public function testRestoresAndRebuildsIndexAfterSourceDatabaseAndIndexLoss(): void
    {
        [$keys, $site, $repository, $writer, $age, $sql] = $this->backup(['shared.txt' => 'shared']);
        $recipients = [$keys[0]['public'], $keys[1]['public']];
        $snapshot = $writer->capture($site, $sql, $recipients);
        $reader = LocalRepository::forRecovery($repository, str_repeat('a', 64));
        $writeRepository = LocalRepository::forWriting($repository, str_repeat('a', 64));
        $orphanRoot = Fixture::temp();
        Fixture::write($orphanRoot, 'orphan', 'unreferenced but valid');
        $age->encrypt($orphanRoot . '/orphan', $orphanRoot . '/orphan.age', $recipients);
        $orphanKey = 'files/' . Manifest::keySetId($recipients) . '/' . str_repeat('e', 64) . '.age';
        $writeRepository->putNew($orphanKey, $orphanRoot . '/orphan.age');
        unlink($site . '/shared.txt');
        unlink($sql);
        $restorer = new Restorer($age, $reader);
        $destination = Fixture::temp() . '/restored';
        $identity = fopen($keys[1]['identity'], 'rb');
        try {
            $restorer->restore($snapshot, $identity, $destination);
        } finally {
            fclose($identity);
        }
        self::assertSame('shared', file_get_contents($destination . '/shared.txt'));
        $newIndexRoot = Fixture::temp();
        $identity = fopen($keys[1]['identity'], 'rb');
        try {
            $restorer->rebuildIndex($identity, $recipients, $newIndexRoot);
        } finally {
            fclose($identity);
        }
        $index = DedupIndex::openOrCreate($newIndexRoot, $writeRepository);
        $entry = $index->withLock(fn () => $index->findOrReserve(hash('sha256', 'unreferenced but valid'), strlen('unreferenced but valid'), Manifest::keySetId($recipients)));
        self::assertSame($orphanKey, $entry['ready']['key']);
        $site = Fixture::site(['renamed.txt' => 'shared', 'orphan.txt' => 'unreferenced but valid']);
        Fixture::write($orphanRoot, 'new.sql', 'CREATE TABLE synthetic (id INT);');
        (new SnapshotWriter($age, $writeRepository, $index))->capture($site, $orphanRoot . '/new.sql', $recipients);
        self::assertCount(2, $reader->listFileObjects(Manifest::keySetId($recipients)));

        $manifest = $this->readManifest($reader, $age, $snapshot, $keys[0]['identity']);
        unlink($reader->objectPath($manifest['files'][0]['object']['key']));
        $identity = fopen($keys[1]['identity'], 'rb');
        $failedIndex = Fixture::temp();
        try {
            $restorer->rebuildIndex($identity, $recipients, $failedIndex);
            self::fail('Missing retained reference must reject index rebuild.');
        } catch (\RuntimeException $ignoredError) {
            self::assertFileDoesNotExist($failedIndex . '/index.jsonl');
        } finally {
            fclose($identity);
        }
    }

    public function testResumeRechecksInterruptedUnitsAndDiscardOnlyRemovesItsJob(): void
    {
        [$keys, $site, $repository, $writer, $age, $sql] = $this->backup(['value.txt' => 'verified content']);
        $snapshot = $writer->capture($site, $sql, [$keys[0]['public'], $keys[1]['public']]);
        $restorer = new Restorer($age, LocalRepository::forRecovery($repository, str_repeat('a', 64)));
        $parent = Fixture::temp();
        Fixture::write($parent, 'sentinel', 'keep');
        foreach (['resume', 'content', 'journal', 'discard'] as $case) {
            $destination = $parent . '/' . $case;
            $wrapper = $this->ageHook('crash', $destination);
            $this->interruptedRestore($wrapper, $repository, $snapshot, $keys[0]['identity'], $destination);
            self::assertDirectoryDoesNotExist($destination);
            $jobs = glob($parent . '/.wrb-restore-*');
            self::assertCount(1, $jobs);
            $job = $jobs[0];
            $progress = file_get_contents($job . '/progress.json');
            self::assertStringNotContainsString('AGE-SECRET-KEY', $progress);
            self::assertStringContainsString('file:value.txt', $progress);
            if ($case === 'discard') {
                $restorer->discard($snapshot, $destination);
            } else {
                if ($case === 'content') {
                    file_put_contents($job . '/files/value.txt', 'changed');
                } elseif ($case === 'journal') {
                    $record = json_decode($progress, true, 32, JSON_THROW_ON_ERROR);
                    $record['data']['completed']['file:value.txt']['sha256'] = str_repeat('0', 64);
                    file_put_contents($job . '/progress.json', json_encode($record, JSON_THROW_ON_ERROR));
                }
                $identity = fopen($keys[1]['identity'], 'rb');
                try {
                        $notices = [];
                        $result = $restorer->resume($snapshot, $identity, $destination, null, null, static function (array $summary) use (&$notices): void { $notices[] = $summary; });
                    self::assertSame('resume', $case);
                        self::assertCount(1, $notices);
                        self::assertSame('unknown', $notices[0]['captureEvidence']);
                    self::assertFalse($result['databaseImported']);
                    self::assertSame('verified content', file_get_contents($destination . '/value.txt'));
                } catch (\RuntimeException $error) {
                    self::assertContains($case, ['content', 'journal']);
                    self::assertStringContainsString($case === 'journal' ? 'checksum' : 'match', $error->getMessage());
                    self::assertDirectoryDoesNotExist($destination);
                    self::assertDirectoryExists($job);
                    self::assertSame($case === 'content' ? 'changed' : 'verified content', file_get_contents($job . '/files/value.txt'));
                    $restorer->discard($snapshot, $destination);
                } finally {
                    fclose($identity);
                }
            }
            clearstatcache(true, $job);
            self::assertDirectoryDoesNotExist($job, $case);
        }
        self::assertSame('keep', file_get_contents($parent . '/sentinel'));
    }

    public function testDestinationCreatedDuringVerificationIsNeverReplaced(): void
    {
        [$keys, $site, $repository, $writer, $age, $sql] = $this->backup(['value.txt' => 'backup']);
        $snapshot = $writer->capture($site, $sql, [$keys[0]['public'], $keys[1]['public']]);
        $parent = Fixture::temp();
        $destination = $parent . '/restore';
        $wrapper = $this->ageHook('collision', $destination);
        $restorer = new Restorer(new AgeProcess($wrapper), LocalRepository::forRecovery($repository, str_repeat('a', 64)));
        $this->rejectRestore($restorer, $snapshot, $keys[0]['identity'], $destination);
        self::assertSame('created by another operation', file_get_contents($destination . '/sentinel'));
        self::assertFileDoesNotExist($destination . '/value.txt');
        self::assertSame(['.', '..', 'restore'], scandir($parent));
    }

    public function testResumesAfterSigkillDuringFileOrDatabaseDecryptionWithoutRepeatingVerifiedFiles(): void
    {
        [$keys, $site, $repository, $writer, $age, $sql] = $this->backup(['a.txt' => 'first verified', 'b.txt' => 'second verified']);
        $snapshot = $writer->capture($site, $sql, [$keys[0]['public'], $keys[1]['public']]);
        $restorer = new Restorer($age, LocalRepository::forRecovery($repository, str_repeat('a', 64)));
        foreach ([3, 4] as $stopAt) {
            $root = Fixture::temp();
            $destination = $root . '/restored';
            $job = $this->interruptDecryption($repository, $snapshot, $keys[0]['identity'], $destination, $stopAt);
            self::assertSame('first verified', file_get_contents($job . '/files/a.txt'));
            $first = lstat($job . '/files/a.txt');
            $identity = fopen($keys[1]['identity'], 'rb');
            try {
                $result = $restorer->resume($snapshot, $identity, $destination);
            } finally {
                fclose($identity);
            }
            self::assertSame(2, $result['files']);
            self::assertSame('first verified', file_get_contents($destination . '/a.txt'));
            self::assertSame('second verified', file_get_contents($destination . '/b.txt'));
            $published = lstat($destination . '/a.txt');
            self::assertSame([$first['dev'], $first['ino']], [$published['dev'], $published['ino']]);
            self::assertDirectoryDoesNotExist($job);
        }
    }

    public function testPendingUnitReconcilesPublicationBoundariesAndRejectsUnownedContent(): void
    {
        [$keys, $site, $repository, $writer, $age, $sql] = $this->backup(['a.txt' => 'first verified', 'b.txt' => 'second verified']);
        $snapshot = $writer->capture($site, $sql, [$keys[0]['public'], $keys[1]['public']]);
        $restorer = new Restorer($age, LocalRepository::forRecovery($repository, str_repeat('a', 64)));
        foreach (['payload', 'linked', 'recorded', 'cleaned', 'download', 'foreign', 'tampered', 'symlink', 'unknown', 'legacy', 'legacy-unrecorded'] as $case) {
            $destination = Fixture::temp() . '/restored';
            $job = $this->interruptDecryption($repository, $snapshot, $keys[0]['identity'], $destination, 3);
            $stage = $job . '/.pending';
            foreach (array_diff(scandir($stage), ['.', '..']) as $name) {
                unlink($stage . '/' . $name);
            }
            $record = json_decode(file_get_contents($job . '/progress.json'), true, 32, JSON_THROW_ON_ERROR);
            Fixture::write($stage, 'payload', 'second verified');
            if ($case === 'download') {
                unlink($stage . '/payload');
                Fixture::write($stage, '.download-' . str_repeat('b', 32), 'partial ciphertext');
            }
            $first = lstat($job . '/files/a.txt');
            if (in_array($case, ['linked', 'recorded', 'cleaned'], true)) {
                link($stage . '/payload', $job . '/files/b.txt');
            } elseif ($case === 'foreign') {
                Fixture::write($job, 'files/b.txt', 'second verified');
            } elseif ($case === 'tampered') {
                file_put_contents($stage . '/payload', 'changed');
            } elseif ($case === 'symlink') {
                symlink($job . '/files/a.txt', $stage . '/.wrb-age-' . str_repeat('a', 32));
            } elseif ($case === 'unknown') {
                Fixture::write($stage, 'unknown', 'keep');
            }
            if (in_array($case, ['recorded', 'cleaned'], true)) {
                $record['data']['completed']['file:b.txt'] = ['size' => 15, 'sha256' => hash('sha256', 'second verified')];
            }
            if (in_array($case, ['cleaned', 'legacy', 'legacy-unrecorded'], true)) {
                unlink($stage . '/payload');
                rmdir($stage);
            }
            if (str_starts_with($case, 'legacy')) {
                unset($record['data']['pending']);
                if ($case === 'legacy-unrecorded') {
                    Fixture::write($job, 'files/b.txt', 'keep unknown');
                }
            }
            $record['checksum'] = hash('sha256', json_encode($record['data'], JSON_THROW_ON_ERROR));
            file_put_contents($job . '/progress.json', json_encode($record, JSON_THROW_ON_ERROR));
            $prepared = @lstat($job . '/files/b.txt') ?: @lstat($stage . '/payload');
            $identity = fopen($keys[1]['identity'], 'rb');
            try {
                $restorer->resume($snapshot, $identity, $destination);
                self::assertContains($case, ['payload', 'linked', 'recorded', 'cleaned', 'download', 'legacy']);
                $publishedFirst = lstat($destination . '/a.txt');
                self::assertSame([$first['dev'], $first['ino']], [$publishedFirst['dev'], $publishedFirst['ino']]);
                self::assertSame('second verified', file_get_contents($destination . '/b.txt'));
                if ($prepared !== false) {
                    $published = lstat($destination . '/b.txt');
                    self::assertSame([$prepared['dev'], $prepared['ino']], [$published['dev'], $published['ino']]);
                }
                self::assertDirectoryDoesNotExist($job);
            } catch (\RuntimeException $error) {
                self::assertContains($case, ['foreign', 'tampered', 'symlink', 'unknown', 'legacy-unrecorded'], $error->getMessage());
                self::assertDirectoryExists($job);
                self::assertDirectoryDoesNotExist($destination);
                if ($case === 'foreign' || $case === 'legacy-unrecorded') {
                    self::assertSame($case === 'foreign' ? 'second verified' : 'keep unknown', file_get_contents($job . '/files/b.txt'));
                } elseif ($case === 'tampered') {
                    self::assertSame('changed', file_get_contents($stage . '/payload'));
                } elseif ($case === 'unknown') {
                    self::assertSame('keep', file_get_contents($stage . '/unknown'));
                } else {
                    self::assertTrue(is_link($stage . '/.wrb-age-' . str_repeat('a', 32)));
                }
            } finally {
                fclose($identity);
            }
        }
    }

    public function testAnotherDamagedManifestDoesNotBlockTheSelectedHealthySnapshot(): void
    {
        [$keys, $site, $repository, $writer, $age, $sql] = $this->backup(['value.txt' => 'healthy']);
        $recipients = [$keys[0]['public'], $keys[1]['public']];
        $healthy = $writer->capture($site, $sql, $recipients);
        Fixture::write($site, 'value.txt', 'other generation');
        $damaged = $writer->capture($site, $sql, $recipients);
        $reader = LocalRepository::forRecovery($repository, str_repeat('a', 64));
        $path = $reader->objectPath('manifests/' . $damaged . '.age');
        $ciphertext = file_get_contents($path);
        $restorer = new Restorer($age, $reader);
        $parent = Fixture::temp();
        foreach (['tampered', 'missing'] as $case) {
            if ($case === 'tampered') {
                file_put_contents($path, substr($ciphertext, 0, -1) . (substr($ciphertext, -1) ^ "\x01"));
            } else {
                unlink($path);
            }
            $identity = fopen($keys[0]['identity'], 'rb');
            try {
                $restorer->restore($healthy, $identity, $parent . '/' . $case);
            } finally {
                fclose($identity);
            }
            self::assertSame('healthy', file_get_contents($parent . '/' . $case . '/value.txt'));
            $this->rejectRestore($restorer, $damaged, $keys[0]['identity'], $parent . '/damaged-' . $case);
            self::assertDirectoryDoesNotExist($parent . '/damaged-' . $case);
        }
    }

    public function testPublicationInterruptedBySigkillRetainsStagingAndResumesSafely(): void
    {
        [$keys, $site, $repository, $writer, $age, $sql] = $this->backup(['000-file' => 'verified content', 'zzz-file' => 'last content']);
        for ($i = 0; $i < 512; $i++) {
            mkdir($site . '/directory-' . $i, 0700);
        }
        $snapshot = $writer->capture($site, $sql, [$keys[0]['public'], $keys[1]['public']]);
        $restorer = new Restorer($age, LocalRepository::forRecovery($repository, str_repeat('a', 64)));
        foreach (['resume', 'modified', 'unknown', 'replaced', 'marker'] as $case) {
            $destination = Fixture::temp() . '/restore';
            self::interruptPublication($repository, str_repeat('a', 64), $snapshot, $keys[0]['identity'], $destination);
            $job = dirname($destination) . '/.wrb-restore-' . hash('sha256', $snapshot . "\0" . $destination);
            self::assertFileExists($job . '/files/000-file');
            self::assertSame('verified content', file_get_contents($job . '/files/000-file'));
            $published = lstat($destination . '/000-file');
            $staged = lstat($job . '/files/000-file');
            self::assertSame([$staged['dev'], $staged['ino']], [$published['dev'], $published['ino']]);
            $record = json_decode(file_get_contents($job . '/progress.json'), true, 32, JSON_THROW_ON_ERROR);
            $marker = $record['data']['publication']['marker'];
            if ($case === 'modified') {
                file_put_contents($destination . '/000-file', 'changed');
            } elseif ($case === 'unknown') {
                Fixture::write($destination, 'unknown', 'keep');
            } elseif ($case === 'replaced') {
                rename($destination, $destination . '-original');
                mkdir($destination, 0700);
            } elseif ($case === 'marker') {
                unlink($destination . '/' . $marker);
            }
            $identity = fopen($keys[1]['identity'], 'rb');
            try {
                $restorer->resume($snapshot, $identity, $destination);
                self::assertSame('resume', $case);
                self::assertSame('verified content', file_get_contents($destination . '/000-file'));
                self::assertSame('last content', file_get_contents($destination . '/zzz-file'));
                self::assertSame(array_values(array_diff(scandir($site), ['.wrb-synthetic'])), scandir($destination));
                self::assertDirectoryDoesNotExist($job);
            } catch (\RuntimeException $error) {
                self::assertNotSame('resume', $case, $error->getMessage());
                self::assertDirectoryExists($job);
                self::assertDirectoryExists($destination);
                if ($case === 'unknown') {
                    self::assertSame('keep', file_get_contents($destination . '/unknown'));
                }
            } finally {
                fclose($identity);
            }
        }
    }

    public static function interruptPublication(string $repository, string $unit, string $snapshot, string $key, string $destination, array $database = []): void
    {
        $root = Fixture::temp();
        $code = '<?php require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . '; '
            . '$identity = fopen($argv[5], "rb"); $database = json_decode($argv[7], true); '
            . '$client = $database ? new WordPressR2Backup\\MysqlClient(...array_slice($database, 0, 4)) : null; '
            . '(new WordPressR2Backup\\Restorer(new WordPressR2Backup\\AgeProcess($argv[1]), '
            . 'WordPressR2Backup\\LocalRepository::forRecovery($argv[2], $argv[3])))->restore($argv[4], $identity, $argv[6], $client, $database[4] ?? null);';
        Fixture::write($root, 'publication.php', $code);
        $process = proc_open([PHP_BINARY, $root . '/publication.php', Fixture::runtime()['age'], $repository, $unit, $snapshot, $key, $destination, json_encode($database, JSON_THROW_ON_ERROR)], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $pid = proc_get_status($process)['pid'];
        $deadline = microtime(true) + 15;
        $interrupted = false;
        try {
            do {
                clearstatcache(true, $destination . '/000-file');
                if (@lstat($destination . '/000-file') !== false) {
                    posix_kill($pid, SIGSTOP);
                    $interrupted = true;
                    break;
                }
                if (!proc_get_status($process)['running']) {
                    break;
                }
                usleep(100);
            } while (microtime(true) < $deadline);
        } finally {
            posix_kill($pid, SIGKILL);
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
        self::assertTrue($interrupted, 'The child must be killed after publishing its first file. ' . $output);
        self::assertFileExists($destination . '/000-file');
        self::assertFileDoesNotExist($destination . '/zzz-file');
    }

    private function ageHook(string $action, string $destination): string
    {
        $root = Fixture::temp();
        $code = <<<'PHP'
#!/usr/bin/env php
<?php
$workspace = dirname(end($argv));
if (basename($workspace) === '.pending') {
    $workspace = dirname($workspace);
}
if (is_file($workspace . '/files/value.txt')) {
    if (ACTION === 'crash') {
        posix_kill(posix_getppid(), 9);
        exit(1);
    }
    mkdir(DESTINATION, 0700);
    file_put_contents(DESTINATION . '/sentinel', 'created by another operation');
}
$process = proc_open(array_merge([AGE], array_slice($argv, 1)), [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
exit(proc_close($process));
PHP;
        $code = str_replace('#!/usr/bin/env php', '#!' . Fixture::phpForShebang(), $code);
        $code = str_replace(['ACTION', 'DESTINATION', 'AGE'], [var_export($action, true), var_export($destination, true), var_export(Fixture::runtime()['age'], true)], $code);
        Fixture::write($root, 'age-hook', $code);
        chmod($root . '/age-hook', 0700);
        return $root . '/age-hook';
    }

    private function interruptDecryption(string $repository, string $snapshot, string $key, string $destination, int $stopAt): string
    {
        $root = Fixture::temp();
        $wrapper = <<<'PHP'
#!/usr/bin/env php
<?php
$count = is_file(COUNTER) ? (int) file_get_contents(COUNTER) : 0;
file_put_contents(COUNTER, (string) ++$count);
if ($count === STOP_AT) {
    posix_kill(posix_getppid(), SIGKILL);
    exit(1);
}
$process = proc_open(array_merge([AGE], array_slice($argv, 1)), [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
exit(proc_close($process));
PHP;
        $wrapper = str_replace(['#!/usr/bin/env php', 'COUNTER', 'STOP_AT', 'AGE'],
            ['#!' . Fixture::phpForShebang(), var_export($root . '/count', true), (string) $stopAt, var_export(Fixture::runtime()['age'], true)], $wrapper);
        Fixture::write($root, 'age-hook', $wrapper);
        chmod($root . '/age-hook', 0700);
        $this->interruptedRestore($root . '/age-hook', $repository, $snapshot, $key, $destination);
        return dirname($destination) . '/.wrb-restore-' . hash('sha256', $snapshot . "\0" . $destination);
    }

    private function interruptedRestore(string $age, string $repository, string $snapshot, string $key, string $destination): void
    {
        $root = Fixture::temp();
        $code = '<?php require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . '; '
            . '$identity = fopen($argv[4], "rb"); '
            . '(new WordPressR2Backup\\Restorer(new WordPressR2Backup\\AgeProcess($argv[1]), '
            . 'WordPressR2Backup\\LocalRepository::forRecovery($argv[2], str_repeat("a", 64))))->restore($argv[3], $identity, $argv[5]);';
        Fixture::write($root, 'interrupt.php', $code);
        $process = proc_open([PHP_BINARY, $root . '/interrupt.php', $age, $repository, $snapshot, $key, $destination], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertNotSame(0, proc_close($process), $output);
        $workspace = dirname($destination) . '/.wrb-restore-' . hash('sha256', $snapshot . "\0" . $destination);
        $lock = fopen($workspace . '/job.lock', 'rb');
        $deadline = microtime(true) + 2;
        while (!flock($lock, LOCK_EX | LOCK_NB)) {
            if (microtime(true) > $deadline) {
                self::fail('Interrupted child did not release its inherited job lock.');
            }
            usleep(10000);
        }
        fclose($lock);
    }

    private function rejectRestore(Restorer $restorer, string $snapshot, string $key, string $destination): void
    {
        $identity = fopen($key, 'rb');
        try {
            $restorer->restore($snapshot, $identity, $destination);
            self::fail('Invalid recovery must fail.');
        } catch (\RuntimeException|\InvalidArgumentException $error) {
            self::assertNotSame('', $error->getMessage());
        } finally {
            fclose($identity);
        }
    }

    private function readManifest(LocalRepository $reader, AgeProcess $age, string $snapshot, string $key): array
    {
        $root = Fixture::temp();
        $identity = fopen($key, 'rb');
        try {
            $age->decrypt($reader->objectPath('manifests/' . $snapshot . '.age'), $root . '/manifest', $identity);
        } finally {
            fclose($identity);
        }
        return json_decode(file_get_contents($root . '/manifest'), true, 32, JSON_THROW_ON_ERROR);
    }

    private function publishManifest(array $manifest, string $repository, AgeProcess $age): string
    {
        $snapshot = bin2hex(random_bytes(32));
        $manifest['snapshotId'] = $snapshot;
        $writeRepository = LocalRepository::forWriting($repository, str_repeat('a', 64));
        $reader = LocalRepository::forRecovery($repository, str_repeat('a', 64));
        $database = $writeRepository->putNew('databases/' . $snapshot . '.age', $reader->objectPath($manifest['database']['object']['key']));
        $manifest['database']['object'] = $database;
        $root = Fixture::temp();
        Fixture::write($root, 'manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $age->encrypt($root . '/manifest.json', $root . '/manifest.age', $manifest['recipients']);
        $receipt = $writeRepository->putNew('manifests/' . $snapshot . '.age', $root . '/manifest.age');
        $writeRepository->publish($snapshot, $receipt);
        return $snapshot;
    }

    private function backup(array $files): array
    {
        $keys = Fixture::keys(3);
        $repository = Fixture::temp();
        $unit = str_repeat('a', 64);
        $writeRepository = LocalRepository::forWriting($repository, $unit);
        $age = new AgeProcess(Fixture::runtime()['age']);
        $index = DedupIndex::openOrCreate(Fixture::temp(), $writeRepository);
        $writer = new SnapshotWriter($age, $writeRepository, $index);
        $site = Fixture::site($files);
        $sqlRoot = Fixture::temp();
        Fixture::write($sqlRoot, 'database.sql', 'CREATE TABLE synthetic (id INT);');
        return [$keys, $site, $repository, $writer, $age, $sqlRoot . '/database.sql'];
    }
}
