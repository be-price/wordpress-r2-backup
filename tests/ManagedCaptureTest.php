<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\{AgeProcess, CaptureControlException, CaptureJob, DedupIndex, ManagedCapture, Manifest, SyntheticHttpRepository};

final class ManagedCaptureTest extends TestCase
{
    private ?IsolatedObjectServer $server = null;
    protected function tearDown(): void { if ($this->server !== null) { $this->server->stop(); } Fixture::cleanup(); }

    public function testTwoGenerationsAndLostAcknowledgementsConverge(): void
    {
        [$capture, $idx, $jobs, $site, $sql, $keys] = $this->setupCapture();
        $job = $capture->prepare($site, $sql, array_column($keys, 'public'));
        $this->server->fault('drop-advance-response-once');
        $first = $capture->run($job);
        $recordPath = $jobs . '/' . $job . '/job.json';
        $record = json_decode(file_get_contents($recordPath), true, 64, JSON_THROW_ON_ERROR);
        $record['data']['result'] = null;
        $record['checksum'] = hash('sha256', json_encode($record['data'], JSON_THROW_ON_ERROR));
        file_put_contents($recordPath, json_encode($record, JSON_THROW_ON_ERROR));
        $afterCompletionInterruption = ManagedCapture::open($this->server->connectionFile('writer'), $idx, $jobs, new AgeProcess(Fixture::runtime()['age']));
        self::assertSame($first, $afterCompletionInterruption->run($job));
        self::assertSame($first, $capture->run($job));
        $status = json_encode((new SyntheticHttpRepository($this->server->connectionFile('writer')))->controlStatus(), JSON_THROW_ON_ERROR);
        $identity = json_decode(file_get_contents($idx . '/managed.json'), true, 8, JSON_THROW_ON_ERROR);
        foreach ([$identity['secret'], $site, hash('sha256', 'same content'), 'a.txt'] as $privateValue) { self::assertStringNotContainsString($privateValue, $status); }
        $reader = new SyntheticHttpRepository($this->server->connectionFile('reader'));
        self::assertSame(['snapshotId' => $first['snapshotId'], 'manifest' => $first['manifest']], $reader->completed($first['snapshotId']));
        Fixture::write($site, 'new.txt', 'generation two');
        $next = $capture->prepare($site, $sql, array_column($keys, 'public'));
        $this->server->fault('drop-complete-response-once');
        $second = $capture->run($next);
        self::assertNotSame($first['snapshotId'], $second['snapshotId']);
        self::assertCount(2, $reader->listCompleted());
        self::assertCount(2, $reader->listFileObjects(Manifest::keySetId(array_column($keys, 'public'))));
        $reopened = ManagedCapture::open($this->server->connectionFile('writer'), $idx, $jobs, new AgeProcess(Fixture::runtime()['age']));
        self::assertSame($second, $reopened->run($next));
    }

    public function testNormalButStaleIndexCannotAcquireOrPublish(): void
    {
        [$capture, $idx, $jobs, $site, $sql, $keys] = $this->setupCapture();
        $old = file_get_contents($idx . '/index.jsonl');
        $job = $capture->prepare($site, $sql, array_column($keys, 'public'));
        $capture->run($job);
        file_put_contents($idx . '/index.jsonl', $old);
        $new = $capture->prepare($site, $sql, array_column($keys, 'public'));
        $this->expectException(CaptureControlException::class);
        $capture->run($new);
    }

    public function testChangedSourceStopsPreparedJobWithoutCompletion(): void
    {
        [$capture, , , $site, $sql, $keys] = $this->setupCapture();
        $job = $capture->prepare($site, $sql, array_column($keys, 'public'));
        Fixture::write($site, 'a.txt', 'changed after prepare');
        try { $capture->run($job); self::fail('Changed prepared source was accepted.'); }
        catch (\RuntimeException $error) { self::assertStringContainsString('Source', $error->getMessage()); }
        self::assertSame([], (new SyntheticHttpRepository($this->server->connectionFile('reader')))->listCompleted());
    }

    public function testResumeAfterDatabaseUploadKeepsCiphertextAndAdoptsPublishedCandidate(): void
    {
        [$capture, $idx, $jobs, $site, $sql, $keys] = $this->setupCapture();
        $markerRoot = Fixture::temp();
        $wrapper = $markerRoot . '/age-wrapper'; $marker = $markerRoot . '/failed';
        $code = 'if (basename(end($argv)) === "manifest.json" && !file_exists(' . var_export($marker, true) . ')) { file_put_contents(' . var_export($marker, true) . ',"failed"); exit(1); }';
        file_put_contents($wrapper, '#!' . Fixture::phpForShebang() . "\n<?php\n" . $code . '$args=$argv; $args[0]=' . var_export(Fixture::runtime()['age'], true) . '; $p=proc_open($args,[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes); exit(proc_close($p));');
        chmod($wrapper, 0700);
        $capture = ManagedCapture::open($this->server->connectionFile('writer'), $idx, $jobs, new AgeProcess($wrapper));
        $jobId = $capture->prepare($site, $sql, array_column($keys, 'public'));
        try { $capture->run($jobId); self::fail('The deliberate encryption interruption did not stop capture.'); }
        catch (\RuntimeException $ignoredError) { self::assertSame([], (new SyntheticHttpRepository($this->server->connectionFile('reader')))->listCompleted()); }
        $job = CaptureJob::open($jobs, $jobId);
        $databaseKey = 'databases/' . $job->snapshotId() . '.age';
        $database = $job->candidateReceipt($databaseKey);
        self::assertNotNull($database);
        $recordFile = $jobs . '/' . $jobId . '/job.json';
        $record = json_decode(file_get_contents($recordFile), true, 64, JSON_THROW_ON_ERROR);
        unset($record['data']['candidates'][$databaseKey]);
        $record['checksum'] = hash('sha256', json_encode($record['data'], JSON_THROW_ON_ERROR));
        file_put_contents($recordFile, json_encode($record, JSON_THROW_ON_ERROR));
        $result = $capture->run($jobId);
        self::assertSame($job->snapshotId(), $result['snapshotId']);
        self::assertSame($database, CaptureJob::open($jobs, $jobId)->candidateReceipt($databaseKey));
        (new SyntheticHttpRepository($this->server->connectionFile('reader')))->verify($database);
    }

    public function testUnconfirmedTailReplaysExactlyOneEventBeforeFurtherCapture(): void
    {
        [$capture, $idx, $jobs, $site, $sql, $keys] = $this->setupCapture();
        $repository = new SyntheticHttpRepository($this->server->connectionFile('writer'));
        $jobId = $capture->prepare($site, $sql, array_column($keys, 'public'));
        $index = DedupIndex::openManaged($idx, $repository);
        $index->withLock(function () use ($index, $keys): void {
            $index->synchronize($index->cursor(), static function (): void { throw new \RuntimeException('Interrupted after index save.'); });
            try { $index->findOrReserve(hash('sha256', 'same content'), strlen('same content'), Manifest::keySetId(array_column($keys, 'public'))); }
            catch (\RuntimeException $error) { self::assertSame('Interrupted after index save.', $error->getMessage()); }
            try { $index->findOrReserve(hash('sha256', 'forbidden second append'), 23, Manifest::keySetId(array_column($keys, 'public'))); self::fail('A second unconfirmed record was appended.'); }
            catch (CaptureControlException $ignoredError) { self::assertTrue(true); }
        });
        $result = $capture->run($jobId);
        self::assertSame($jobId, $result['jobId']);
        $index->withLock(function () use ($index, $repository): void { self::assertSame($index->cursor(), $repository->controlStatus()['head']); });
    }

    public function testDifferentProcessNonceWaitsForLiveLeaseAndThenResumesSameJob(): void
    {
        [$capture, $idx, $jobs, $site, $sql, $keys] = $this->setupCapture();
        $jobId = $capture->prepare($site, $sql, array_column($keys, 'public'));
        $repository = new SyntheticHttpRepository($this->server->connectionFile('writer'));
        $first = $repository->acquire($jobId, str_repeat('f', 64), $repository->controlStatus()['head'], 1);
        $restarted = ManagedCapture::open($this->server->connectionFile('writer'), $idx, $jobs, new AgeProcess(Fixture::runtime()['age']), 30);
        try { $restarted->run($jobId); self::fail('A new nonce reclaimed the live lease.'); }
        catch (CaptureControlException $error) { self::assertStringContainsString('lease', $error->getMessage()); self::assertSame($first['fence'], $repository->controlStatus()['fence']); }
        sleep(1);
        $result = $restarted->run($jobId);
        self::assertSame($jobId, $result['jobId']);
        self::assertSame($first['fence'] + 1, $repository->controlStatus()['fence']);
    }

    public function testLostLocalCandidateUsesMatchingRemoteReceiptAndMissingBothStops(): void
    {
        [$capture, , $jobs, $site, $sql, $keys] = $this->setupCapture();
        $jobId = $capture->prepare($site, $sql, array_column($keys, 'public'));
        $job = CaptureJob::open($jobs, $jobId);
        $writer = new SyntheticHttpRepository($this->server->connectionFile('writer'));
        $writer->acquire($jobId, str_repeat('e', 64), $writer->controlStatus()['head']);
        $key = 'databases/' . $job->snapshotId() . '.age';
        $candidate = $job->candidate($key, static function (string $file): void { file_put_contents($file, 'fixed ciphertext'); }, $writer);
        $writer->putNew($key, $candidate['file']);
        unlink($candidate['file']);
        $recovered = $job->candidate($key, static function (): void { self::fail('A recorded missing candidate must not be encrypted again.'); }, $writer);
        self::assertNull($recovered['file']);
        self::assertSame($candidate['receipt'], $recovered['receipt']);
        $missing = $job->candidate('manifests/' . $job->snapshotId() . '.age', static function (string $file): void { file_put_contents($file, 'missing ciphertext'); }, $writer);
        unlink($missing['file']);
        $this->expectException(\RuntimeException::class);
        $job->candidate($missing['receipt']['key'], static function (): void { self::fail('Missing local and remote candidates cannot be replaced.'); }, $writer);
    }

    public function testResumeAfterManifestUploadRetainsDatabaseAndManifestReceipts(): void
    {
        [$capture, , $jobs, $site, $sql, $keys, $backend] = $this->setupCapture();
        $jobId = $capture->prepare($site, $sql, array_column($keys, 'public'));
        $blocker = $backend . '/' . str_repeat('a', 64) . '/commits';
        file_put_contents($blocker, 'blocked'); chmod($blocker, 0600);
        try { $capture->run($jobId); self::fail('Blocked completion should interrupt capture.'); }
        catch (\RuntimeException $ignoredError) { self::assertFileExists($blocker); }
        $job = CaptureJob::open($jobs, $jobId);
        $database = $job->candidateReceipt('databases/' . $job->snapshotId() . '.age');
        $manifest = $job->candidateReceipt('manifests/' . $job->snapshotId() . '.age');
        self::assertNotNull($database); self::assertNotNull($manifest);
        unlink($blocker);
        $result = $capture->run($jobId);
        self::assertSame($manifest, $result['manifest']);
        $after = CaptureJob::open($jobs, $jobId);
        self::assertSame($database, $after->candidateReceipt($database['key']));
        self::assertSame($manifest, $after->candidateReceipt($manifest['key']));
        self::assertCount(1, (new SyntheticHttpRepository($this->server->connectionFile('reader')))->listCompleted());
    }

    public function testPreparedJobCannotMoveToAnotherSourceBinding(): void
    {
        [$capture, $idx, $jobs, $site, $sql, $keys] = $this->setupCapture();
        $jobId = $capture->prepare($site, $sql, array_column($keys, 'public'));
        $repository = new SyntheticHttpRepository($this->server->connectionFile('writer'));
        $other = IsolatedObjectServer::startManaged(Fixture::temp(), $repository->unitId(), str_repeat('c', 64), $repository->controlStatus()['head']);
        try {
            $moved = ManagedCapture::open($other->connectionFile('writer'), $idx, $jobs, new AgeProcess(Fixture::runtime()['age']));
            try { $moved->run($jobId); self::fail('A prepared job moved to a different source.'); }
            catch (CaptureControlException $ignoredError) {
                self::assertFalse((new SyntheticHttpRepository($other->connectionFile('writer')))->hasObjects());
            }
        } finally { $other->stop(); }
    }

    public function testManagedIdentityCannotBeReinitializedOrLostDuringAnOpenSession(): void
    {
        [$capture, $idx, , $site, $sql, $keys] = $this->setupCapture();
        $identity = file_get_contents($idx . '/managed.json');
        try { ManagedCapture::initializeIndex($idx, str_repeat('a', 64)); self::fail('An existing index was reinitialized.'); }
        catch (\RuntimeException $ignoredError) { self::assertSame($identity, file_get_contents($idx . '/managed.json')); }
        $jobId = $capture->prepare($site, $sql, array_column($keys, 'public'));
        unlink($idx . '/managed.json');
        try { $capture->run($jobId); self::fail('A lost identity was regenerated.'); }
        catch (\RuntimeException $ignoredError) { self::assertFileDoesNotExist($idx . '/managed.json'); }
        self::assertFalse((new SyntheticHttpRepository($this->server->connectionFile('writer')))->hasObjects());
    }

    private function setupCapture(): array
    {
        $unit = str_repeat('a', 64); $idx = Fixture::temp(); $jobs = Fixture::temp();
        $cursor = ManagedCapture::initializeIndex($idx, $unit);
        $backend = Fixture::temp();
        $this->server = IsolatedObjectServer::startManaged($backend, $unit, str_repeat('b', 64), $cursor);
        $capture = ManagedCapture::open($this->server->connectionFile('writer'), $idx, $jobs, new AgeProcess(Fixture::runtime()['age']));
        $site = Fixture::site(['a.txt' => 'same content']);
        $sql = Fixture::temp() . '/db.sql'; file_put_contents($sql, 'synthetic SQL'); chmod($sql, 0600);
        return [$capture, $idx, $jobs, $site, $sql, Fixture::keys(), $backend];
    }
}
