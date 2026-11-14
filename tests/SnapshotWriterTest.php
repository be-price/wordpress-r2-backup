<?php
declare(strict_types=1);

namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\{AgeProcess, DedupIndex, LocalRepository, Manifest, SnapshotWriter};

final class SnapshotWriterTest extends TestCase
{
    protected function tearDown(): void { Fixture::cleanup(); }

    public function testPublishesOnlyAnEncryptedCompleteSnapshot(): void
    {
        [$writer, $reader, $index, $keys, $site, $sql] = $this->backup();
        $snapshot = (new SnapshotWriter(new AgeProcess(Fixture::runtime()['age']), $writer, $index))->capture($site, $sql, array_column($keys, 'public'));
        $records = $reader->listCompleted();
        self::assertCount(1, $records);
        self::assertSame($snapshot, $records[0]['snapshotId']);
        $plain = Fixture::temp() . '/manifest';
        $identity = fopen($keys[0]['identity'], 'rb');
        try { (new AgeProcess(Fixture::runtime()['age']))->decrypt($reader->objectPath($records[0]['manifest']['key']), $plain, $identity); }
        finally { fclose($identity); }
        $data = Manifest::fromArray(json_decode(file_get_contents($plain), true, 512, JSON_THROW_ON_ERROR))->toArray();
        self::assertSame(['empty', 'index.php', 'wp-content', 'wp-content/mu-plugins', 'wp-content/mu-plugins/local.php', 'wp-content/plugins', 'wp-content/plugins/custom.php', 'wp-content/themes', 'wp-content/themes/test.css', 'wp-content/uploads', 'wp-content/uploads/日本語.txt', 'wp-settings.json'], array_column($data['files'], 'path'));
        self::assertSame(hash_file('sha256', $sql), $data['database']['sha256']);
        foreach ($data['files'] as $entry) {
            if ($entry['type'] === 'file') { $reader->verify($entry['object']); }
        }
    }

    public function testMissingIndexStopsAlreadyOpenWriter(): void
    {
        [$writer, $reader, $index, $keys, $site, $sql, $indexRoot] = $this->backup();
        $capture = new SnapshotWriter(new AgeProcess(Fixture::runtime()['age']), $writer, $index);
        $capture->capture($site, $sql, array_column($keys, 'public'));
        unlink($indexRoot . '/index.jsonl');
        try {
            $capture->capture($site, $sql, array_column($keys, 'public'));
            self::fail('An already-open writer must stop after index loss');
        } catch (\RuntimeException $ignoredError) { self::assertCount(1, $reader->listCompleted()); }
    }

    public function testMissingIndexBeforeFirstCaptureStopsOpenWriter(): void
    {
        [$writer, $reader, $index, $keys, $site, $sql, $indexRoot] = $this->backup();
        unlink($indexRoot . '/index.jsonl');
        try {
            (new SnapshotWriter(new AgeProcess(Fixture::runtime()['age']),$writer,$index))->capture($site,$sql,array_column($keys,'public'));
            self::fail('An open writer must not lose its index header');
        } catch (\RuntimeException $ignoredError) { self::assertSame([],$reader->listCompleted()); }
    }

    public function testChangedSourceAndWriteFailureDoNotPublish(): void
    {
        [$writer, $reader, $index, $keys, $site, $sql, , $root] = $this->backup();
        $wrapper = Fixture::temp() . '/age-wrapper';
        $mutation = 'file_put_contents(' . var_export($site . '/index.php', true) . ', random_bytes(20));';
        file_put_contents($wrapper, '#!' . Fixture::phpForShebang() . "\n<?php\n" . $mutation . '$args = $argv; $args[0] = ' . var_export(Fixture::runtime()['age'], true) . '; $p = proc_open($args, [0=>STDIN,1=>STDOUT,2=>STDERR], $pipes); exit(proc_close($p));');
        chmod($wrapper, 0700);
        try {
            (new SnapshotWriter(new AgeProcess($wrapper), $writer, $index))->capture($site, $sql, array_column($keys, 'public'));
            self::fail('Changed source was published');
        } catch (\RuntimeException $ignoredError) { self::assertSame([], $reader->listCompleted()); }
        [$writer, $reader, $index, $keys, $site, $sql, , $root] = $this->backup();
        Fixture::write($root . '/' . $writer->unitId(), 'databases', 'blocked');
        try {
            (new SnapshotWriter(new AgeProcess(Fixture::runtime()['age']), $writer, $index))->capture($site, $sql, array_column($keys, 'public'));
            self::fail('Write failure was published');
        } catch (\RuntimeException $ignoredError) { self::assertSame([], $reader->listCompleted()); }
    }

    public function testConcurrentCaptureAndRetryDoNotDuplicateFiles(): void
    {
        [$writer, $reader, $index, $keys, $site, $sql, $indexRoot, $root] = $this->backup();
        $script = Fixture::temp() . '/capture.php';
        $config = [$root, $writer->unitId(), $indexRoot, $site, $sql, array_column($keys, 'public'), Fixture::runtime()['age']];
        file_put_contents($script, '<?php require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . '; [$root,$unit,$idx,$site,$sql,$keys,$age] = json_decode($argv[1],true); $w=WordPressR2Backup\LocalRepository::forWriting($root,$unit); $i=WordPressR2Backup\DedupIndex::openOrCreate($idx,$w); echo (new WordPressR2Backup\SnapshotWriter(new WordPressR2Backup\AgeProcess($age),$w,$i))->capture($site,$sql,$keys);');
        $processes = [];
        for ($i = 0; $i < 2; $i++) {
            $p = proc_open([PHP_BINARY, $script, json_encode($config, JSON_THROW_ON_ERROR)], [0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
            $processes[] = [$p, $pipes];
        }
        foreach ($processes as [$p, $pipes]) {
            $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
            self::assertSame(0, proc_close($p), $err);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $out);
        }
        $set = Manifest::keySetId(array_column($keys, 'public'));
        self::assertCount(6, $reader->listFileObjects($set));
        self::assertCount(2, $reader->listCompleted());
        $index->withLock(function () use ($index, $writer, $keys, $set): void {
            $plain = Fixture::temp() . '/plain'; file_put_contents($plain, 'new interrupted content');
            $entry = $index->findOrReserve(hash_file('sha256', $plain), filesize($plain), $set);
            $cipher = $plain . '.age'; (new AgeProcess(Fixture::runtime()['age']))->encrypt($plain, $cipher, array_column($keys, 'public'));
            $receipt = ['key'=>$entry['key'],'bytes'=>filesize($cipher),'sha256'=>hash_file('sha256', $cipher)];
            $index->recordCandidate($entry['key'], $receipt);
            $writer->putNew($entry['key'], $cipher);
        });
        Fixture::write($site, 'new.txt', 'new interrupted content');
        (new SnapshotWriter(new AgeProcess(Fixture::runtime()['age']), $writer, $index))->capture($site, $sql, array_column($keys, 'public'));
        self::assertCount(7, $reader->listFileObjects($set));
        self::assertCount(3, $reader->listCompleted());
    }

    public function testCorruptAndTruncatedIndexStopsCapture(): void
    {
        foreach (["bad json\n", '{"data":'] as $bad) {
            [$writer, $reader, $index, $keys, $site, $sql, $indexRoot] = $this->backup();
            file_put_contents($indexRoot . '/index.jsonl', $bad, FILE_APPEND);
            try {
                (new SnapshotWriter(new AgeProcess(Fixture::runtime()['age']), $writer, $index))->capture($site, $sql, array_column($keys, 'public'));
                self::fail('Corrupt index was accepted');
            } catch (\RuntimeException|\JsonException $ignoredError) { self::assertSame([], $reader->listCompleted()); }
        }
    }

    public function testInterruptedBeforeCompletionLeavesNoCompletedSnapshot(): void
    {
        [$writer, $reader, $index, $keys, $site, $sql, , $root] = $this->backup();
        $blocker = $root . '/' . $writer->unitId() . '/commits';
        $wrapper = Fixture::temp() . '/age-wrapper';
        $mutation = 'if (basename(end($argv)) === "manifest.json") { file_put_contents(' . var_export($blocker, true) . ', "blocked"); chmod(' . var_export($blocker,true) . ',0600); }';
        file_put_contents($wrapper, '#!' . Fixture::phpForShebang() . "\n<?php\n" . $mutation . '$args=$argv; $args[0]=' . var_export(Fixture::runtime()['age'], true) . '; $p=proc_open($args,[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes); exit(proc_close($p));');
        chmod($wrapper,0700);
        try {
            (new SnapshotWriter(new AgeProcess($wrapper),$writer,$index))->capture($site,$sql,array_column($keys,'public'));
            self::fail('A blocked completion record was published');
        } catch (\RuntimeException $ignoredError) {
            self::assertCount(1,glob($root . '/' . $writer->unitId() . '/manifests/*.age'));
            unlink($blocker);
            self::assertSame([],$reader->listCompleted());
        }
        (new SnapshotWriter(new AgeProcess(Fixture::runtime()['age']),$writer,$index))->capture($site,$sql,array_column($keys,'public'));
        self::assertCount(1,$reader->listCompleted());
        self::assertCount(6,$reader->listFileObjects(Manifest::keySetId(array_column($keys,'public'))));
    }

    private function backup(): array
    {
        $root = Fixture::temp(); $unit = str_repeat('a',64);
        $writer = LocalRepository::forWriting($root, $unit);
        $idx = Fixture::temp();
        $site = Fixture::site(['index.php'=>'synthetic core','wp-content/themes/test.css'=>'theme','wp-content/plugins/custom.php'=>'plugin','wp-content/mu-plugins/local.php'=>'mu','wp-content/uploads/日本語.txt'=>'media','wp-settings.json'=>'synthetic config']);
        mkdir($site . '/empty', 0700);
        $sql = Fixture::temp() . '/db.sql'; file_put_contents($sql, 'synthetic SQL');
        return [$writer, LocalRepository::forRecovery($root,$unit), DedupIndex::openOrCreate($idx,$writer), Fixture::keys(), $site, $sql, $idx, $root];
    }
}
