<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\{AgeProcess, CaptureJob, ManagedCapture, MysqlClient, Restorer, SyntheticCapture, SyntheticFileSource, SyntheticHttpRepository};

final class SyntheticCaptureTest extends TestCase
{
    private ?IsolatedMysql $mysql = null;
    private ?IsolatedObjectServer $objects = null;
    protected function tearDown(): void { if ($this->objects !== null) { $this->objects->stop(); } if ($this->mysql !== null) { $this->mysql->stop(); } Fixture::cleanup(); }

    public function testFixedFilesAndMixedDatabaseSurviveSourceChangesAndInterruptedTransferWithEitherKey(): void
    {
        [$capture, $managed, $source, $schema, $jobs, $keys, $staging, $backend] = $this->setupCapture();
        $jobId = $capture->prepare($source, $schema, array_column($keys, 'public'));
        $reader = new SyntheticHttpRepository($this->objects->connectionFile('reader'));
        self::assertFalse((new SyntheticHttpRepository($this->objects->connectionFile('writer')))->hasObjects());
        $job = CaptureJob::open($jobs, $jobId);
        self::assertNotSame($source->root(), $job->input()['root']);
        self::assertSame(['empty', 'same.txt', 'tree', 'tree/old.txt'], array_column($job->input()['inventory'], 'path'));
        $recordPath = dirname($job->input()['root']) . '/capture.json';
        $record = json_decode(file_get_contents($recordPath), true, 64, JSON_THROW_ON_ERROR);
        $report = $job->captureReport();
        self::assertSame($record['fixedAt'], $report['fixedAt']);
        self::assertSame($record['sqlHash'], $report['database']['sqlHash']);
        self::assertArrayNotHasKey('sourceRoot', $report);
        self::assertArrayNotHasKey('schema', $report['database']);
        self::assertSame(['InnoDB', 'MyISAM'], array_column($record['database']['tables'], 'engine'));
        self::assertNotEmpty($record['database']['definitions']['triggers']);
        self::assertNotEmpty($record['database']['definitions']['routines']);
        self::assertNotEmpty($record['database']['definitions']['events']);
        self::assertFileExists($recordPath);
        $source->write('same.txt', 'new!'); $source->remove('tree/old.txt'); $source->write('new.txt', 'new file');
        $this->mysql->execute($schema, "UPDATE innodb_data SET value='new'; UPDATE myisam_data SET value='new';");
        $blocker = $backend . '/' . str_repeat('a', 64) . '/commits';
        file_put_contents($blocker, 'interrupted'); chmod($blocker, 0600);
        $failure = null;
        try { $managed->run($jobId); }
        catch (\RuntimeException $error) { $failure = $error; }
        self::assertInstanceOf(\RuntimeException::class, $failure);
        $before = CaptureJob::open($jobs, $jobId)->candidateReceipt('databases/' . $job->snapshotId() . '.age');
        self::assertNotNull($before); unlink($blocker);
        self::assertSame([], $reader->listCompleted());
        $result = $managed->run($jobId);
        self::assertSame($before, CaptureJob::open($jobs, $jobId)->candidateReceipt($before['key']));
        foreach ($keys as $key => $identity) {
            $inspectKey = fopen($identity['identity'], 'rb');
            try { $inspection = (new Restorer(new AgeProcess(Fixture::runtime()['age']), $reader))->inspect($result['snapshotId'], $inspectKey); }
            finally { fclose($inspectKey); }
            self::assertSame(2, $inspection['manifestVersion']);
            self::assertSame('reported', $inspection['captureEvidence']);
            self::assertSame($record['fixedAt'], $inspection['capture']['fixedAt']);
            self::assertTrue($inspection['verification']['referencedCiphertexts']);
            self::assertFalse($inspection['verification']['payloadDecrypted']);
            $destination = Fixture::temp() . '/restored'; $target = $this->mysql->newSchema();
            $stream = fopen($identity['identity'], 'rb');
            try { $restored = (new Restorer(new AgeProcess(Fixture::runtime()['age']), $reader))->restore($result['snapshotId'], $stream, $destination, $this->mysql->client(), $target); }
            finally { fclose($stream); }
            self::assertTrue($restored['databaseImported']);
            self::assertSame('old!', file_get_contents($destination . '/same.txt'));
            self::assertSame('old file', file_get_contents($destination . '/tree/old.txt'));
            self::assertDirectoryExists($destination . '/empty');
            self::assertFileDoesNotExist($destination . '/new.txt');
            self::assertSame([['1', 'old']], $this->mysql->rows($target, 'SELECT * FROM innodb_data'));
            self::assertSame([['1', 'old']], $this->mysql->rows($target, 'SELECT * FROM myisam_data'));
        }
        self::assertCount(1, $reader->listCompleted());
        self::assertCount(1, glob($staging . '/capture-*'));
    }

    public function testFrozenEntryRejectsMissingContentSqlAndReleaseEvidenceWithoutCreatingAnotherJob(): void
    {
        [$capture, $managed, $source, $schema, $jobs, $keys] = $this->setupCapture();
        $recipients = array_column($keys, 'public');
        $id = $capture->prepare($source, $schema, $recipients);
        $job = CaptureJob::open($jobs, $id); $directory = dirname($job->input()['root']);
        $recordFile = $directory . '/capture.json'; $original = file_get_contents($recordFile);
        $record = json_decode($original, true, 64, JSON_THROW_ON_ERROR);
        unset($record['database']['releasedAt']);
        file_put_contents($recordFile, json_encode($record, JSON_THROW_ON_ERROR));
        $failure = null;
        try { $managed->prepareFrozen($directory, $recipients); }
        catch (\RuntimeException $error) { $failure = $error; }
        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertCount(1, glob($jobs . '/*'));
        file_put_contents($recordFile, $original);
        file_put_contents($job->input()['root'] . '/same.txt', 'same');
        $failure = null;
        try { $managed->prepareFrozen($directory, $recipients); }
        catch (\RuntimeException $error) { $failure = $error; }
        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertCount(1, glob($jobs . '/*'));
        file_put_contents($job->input()['root'] . '/same.txt', 'old!'); unlink($job->input()['sql']);
        $failure = null;
        try { $managed->prepareFrozen($directory, $recipients); }
        catch (\RuntimeException $error) { $failure = $error; }
        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertCount(1, glob($jobs . '/*'));
        self::assertSame([], (new SyntheticHttpRepository($this->objects->connectionFile('reader')))->listCompleted());
    }

    public function testUnsafeSourceFailureLeavesNoJobAndReleasesFileGate(): void
    {
        [$capture, , $source, $schema, $jobs, $keys] = $this->setupCapture();
        symlink($source->root() . '/same.txt', $source->root() . '/link');
        $failure = null;
        try { $capture->prepare($source, $schema, array_column($keys, 'public')); }
        catch (\RuntimeException $error) { $failure = $error; }
        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertSame([], glob($jobs . '/*'));
        unlink($source->root() . '/link'); $source->write('after-failure', 'writable');
        self::assertSame('writable', file_get_contents($source->root() . '/after-failure'));
        self::assertFalse((new SyntheticHttpRepository($this->objects->connectionFile('writer')))->hasObjects());
    }

    public function testFinalDifferenceRecoversConcurrentAdditionDeletionTypeAndSameLengthChanges(): void
    {
        [$capture, , $source, $schema, $jobs, $keys, $staging] = $this->setupCapture();
        $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/source-delta-writer.php', $staging], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fwrite($pipes[0], base64_encode(serialize($source)) . "\n"); fclose($pipes[0]);
        stream_set_timeout($pipes[1], 15);
        try {
            self::assertSame("holding\n", fgets($pipes[1]));
            $id = $capture->prepare($source, $schema, array_column($keys, 'public'));
            self::assertSame("changed\n", fgets($pipes[1]));
            $job = CaptureJob::open($jobs, $id);
            self::assertSame(['added-empty', 'empty', 'new.txt', 'same.txt'], array_column($job->input()['inventory'], 'path'));
            self::assertSame('next', file_get_contents($job->input()['root'] . '/same.txt'));
            self::assertSame('formerly a directory', file_get_contents($job->input()['root'] . '/empty'));
            self::assertDirectoryExists($job->input()['root'] . '/added-empty');
            fclose($pipes[1]); fclose($pipes[2]); $pipes = [];
            self::assertSame(0, proc_close($process)); $process = null;
        } finally {
            if (is_resource($process)) { proc_terminate($process); foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } } proc_close($process); }
        }
    }

    public function testMissingDumpDefinitionsReleaseBothGatesAndPublishNoJobOrGeneration(): void
    {
        [, $managed, $source, $schema, $jobs, $keys, $staging] = $this->setupCapture();
        $bin = IsolatedMysql::mysqlBin();
        $wrapper = Fixture::temp() . '/incomplete-dump';
        file_put_contents($wrapper, '#!' . Fixture::phpForShebang() . "\n<?php fwrite(STDOUT, 'incomplete database output');\n"); chmod($wrapper, 0700);
        $client = new MysqlClient($bin . 'mysql', $wrapper, $this->mysql->defaultsFile(), $this->mysql->workspace());
        $capture = new SyntheticCapture($client, $managed, $staging);
        $failure = null;
        try { $capture->prepare($source, $schema, array_column($keys, 'public')); }
        catch (\RuntimeException $error) { $failure = $error; }
        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertSame([], glob($jobs . '/*'));
        $source->write('after-failure', 'writable');
        $this->mysql->execute($schema, "UPDATE myisam_data SET value='after failure';");
        self::assertSame([['after failure']], $this->mysql->rows($schema, 'SELECT value FROM myisam_data'));
        self::assertFalse((new SyntheticHttpRepository($this->objects->connectionFile('writer')))->hasObjects());
        foreach (glob($staging . '/capture-*') as $directory) { self::assertFileDoesNotExist($directory . '/capture.json'); }
    }

    public function testLegacyFrozenJobKeepsItsVersionOneCandidateAndUnknownEvidence(): void
    {
        [$capture, $managed, $source, $schema, $jobs, $keys] = $this->setupCapture();
        $id = $capture->prepare($source, $schema, array_column($keys, 'public'));
        $jobFile = $jobs . '/' . $id . '/job.json';
        $record = json_decode(file_get_contents($jobFile), true, 64, JSON_THROW_ON_ERROR);
        unset($record['data']['capture']['report']);
        $record['checksum'] = hash('sha256', json_encode($record['data'], JSON_THROW_ON_ERROR));
        file_put_contents($jobFile, json_encode($record, JSON_THROW_ON_ERROR));
        self::assertNull(CaptureJob::open($jobs, $id)->captureReport());
        $result = $managed->run($id);
        $receipt = CaptureJob::open($jobs, $id)->candidateReceipt('manifests/' . $result['snapshotId'] . '.age');
        self::assertSame($result, $managed->run($id));
        self::assertSame($receipt, CaptureJob::open($jobs, $id)->candidateReceipt($receipt['key']));
        $identity = fopen($keys[0]['identity'], 'rb');
        try { $inspection = (new Restorer(new AgeProcess(Fixture::runtime()['age']), new SyntheticHttpRepository($this->objects->connectionFile('reader'))))->inspect($result['snapshotId'], $identity); }
        finally { fclose($identity); }
        self::assertSame(1, $inspection['manifestVersion']);
        self::assertSame('unknown', $inspection['captureEvidence']);
        self::assertNull($inspection['capture']);
    }

    public function testEvidenceChangedDuringTransferCannotPublishACompletedGeneration(): void
    {
        [$capture, , $source, $schema, $jobs, $keys] = $this->setupCapture();
        $id = $capture->prepare($source, $schema, array_column($keys, 'public'));
        $job = CaptureJob::open($jobs, $id);
        $store = Fixture::temp();
        $writer = \WordPressR2Backup\LocalRepository::forWriting($store, $job->input()['unitId']);
        $index = \WordPressR2Backup\DedupIndex::openOrCreate(Fixture::temp(), $writer);
        $snapshot = new \WordPressR2Backup\SnapshotWriter(new AgeProcess(Fixture::runtime()['age']), $writer, $index);
        $changed = false; $failure = null;
        try {
            $index->withLock(function () use ($snapshot, $job, &$changed): void {
                $snapshot->capturePrepared($job, function () use ($job, &$changed): void {
                    if (!$changed && $job->candidateReceipt('manifests/' . $job->snapshotId() . '.age') !== null) {
                        file_put_contents(dirname($job->input()['root']) . '/capture.json', "\n", FILE_APPEND);
                        $changed = true;
                    }
                });
            });
        } catch (\RuntimeException $error) { $failure = $error; }
        self::assertTrue($changed);
        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertSame([], \WordPressR2Backup\LocalRepository::forRecovery($store, $job->input()['unitId'])->listCompleted());
    }

    private function setupCapture(): array
    {
        $this->mysql = IsolatedMysql::start(); $definer = $this->mysql->sharedImporter(); $schema = $this->mysql->newSchema();
        $this->mysql->execute($schema, strtr("CREATE TABLE innodb_data(id INT PRIMARY KEY,value VARCHAR(20)) ENGINE=InnoDB; CREATE TABLE myisam_data(id INT PRIMARY KEY,value VARCHAR(20)) ENGINE=MyISAM; INSERT INTO innodb_data VALUES(1,'old'); INSERT INTO myisam_data VALUES(1,'old'); CREATE TRIGGER keep_insert BEFORE INSERT ON innodb_data FOR EACH ROW SET NEW.value=NEW.value; CREATE PROCEDURE noop() SELECT 1; CREATE EVENT future_noop ON SCHEDULE AT CURRENT_TIMESTAMP + INTERVAL 1 DAY DO SELECT 1;", ['CREATE TRIGGER' => "CREATE DEFINER=$definer TRIGGER", 'CREATE PROCEDURE' => "CREATE DEFINER=$definer PROCEDURE", 'CREATE EVENT' => "CREATE DEFINER=$definer EVENT"]));
        $index = Fixture::temp(); $jobs = Fixture::temp(); $backend = Fixture::temp(); $staging = Fixture::temp();
        $unit = str_repeat('a', 64); $cursor = ManagedCapture::initializeIndex($index, $unit);
        $this->objects = IsolatedObjectServer::startManaged($backend, $unit, str_repeat('b', 64), $cursor);
        $managed = ManagedCapture::open($this->objects->connectionFile('writer'), $index, $jobs, new AgeProcess(Fixture::runtime()['age']));
        $source = SyntheticFileSource::create(Fixture::temp());
        $source->write('same.txt', 'old!'); $source->write('tree/old.txt', 'old file'); $source->mkdir('empty');
        return [new SyntheticCapture($this->mysql->client(), $managed, $staging), $managed, $source, $schema, $jobs, Fixture::keys(), $staging, $backend];
    }
}
