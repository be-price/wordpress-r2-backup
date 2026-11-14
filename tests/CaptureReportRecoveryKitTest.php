<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\{AgeProcess, CaptureJob, ManagedCapture, SyntheticCapture, SyntheticFileSource};

final class CaptureReportRecoveryKitTest extends TestCase
{
    private ?IsolatedMysql $mysql = null;
    private ?IsolatedObjectServer $objects = null;

    protected function tearDown(): void
    {
        if ($this->objects !== null) { $this->objects->stop(); } if ($this->mysql !== null) { $this->mysql->stop(); } Fixture::cleanup();
    }

    public function testIndependentKitInspectsMixedVersionsAndRestoresWithBothKeysWithoutOriginalInputs(): void
    {
        $this->mysql = IsolatedMysql::start(); $schema = $this->mysql->newSchema();
        $this->mysql->execute($schema, "CREATE TABLE innodb_data(id INT PRIMARY KEY,value VARCHAR(20)) ENGINE=InnoDB; CREATE TABLE myisam_data(id INT PRIMARY KEY,value VARCHAR(20)) ENGINE=MyISAM; INSERT INTO innodb_data VALUES(1,'old'); INSERT INTO myisam_data VALUES(1,'old');");
        $index = Fixture::temp(); $jobs = Fixture::temp(); $staging = Fixture::temp(); $backend = Fixture::temp(); $unit = str_repeat('c', 64);
        $cursor = ManagedCapture::initializeIndex($index, $unit);
        $this->objects = IsolatedObjectServer::startManaged($backend, $unit, str_repeat('d', 64), $cursor);
        $managed = ManagedCapture::open($this->objects->connectionFile('writer'), $index, $jobs, new AgeProcess(Fixture::runtime()['age']));
        $source = SyntheticFileSource::create(Fixture::temp()); $source->write('old.txt', 'old'); $source->write('same.txt', 'same'); $source->mkdir('empty');
        $keys = Fixture::keys(); $recipients = array_column($keys, 'public');
        $sql = Fixture::temp() . '/legacy.sql'; $this->mysql->client()->dump($schema, $sql);
        $legacy = $managed->run($managed->prepare($source->root(), $sql, $recipients));
        $source->remove('old.txt'); $source->write('新規/binary.bin', "\0\xff\x80new");
        $this->mysql->execute($schema, "UPDATE innodb_data SET value='fixed'; UPDATE myisam_data SET value='fixed';");
        $jobId = (new SyntheticCapture($this->mysql->client(), $managed, $staging))->prepare($source, $schema, $recipients);
        $report = CaptureJob::open($jobs, $jobId)->captureReport(); $current = $managed->run($jobId);

        $root = Fixture::temp(); $kit = $root . '/kit'; $tools = dirname(__DIR__) . '/recovery';
        [$exit, $out, $error] = $this->command([PHP_BINARY, $tools . '/build-kit.php', '--output', $kit, '--age-archive', dirname(Fixture::runtime()['age'], 2) . '/age.tar.gz']);
        self::assertSame(0, $exit, $out . $error);
        $this->mysql->dropSchema($schema); unlink($sql); $sourceRoot = $source->root();
        foreach ([dirname($sourceRoot), $index, $jobs, $staging] as $path) { $this->remove($path); self::assertDirectoryDoesNotExist($path); }

        $mysqlBin = rtrim(IsolatedMysql::mysqlBin(), '/');
        $php = [PHP_BINARY, '-d', 'sys_temp_dir=' . $root, '-d', 'open_basedir=' . $kit . ':' . realpath(sys_get_temp_dir()) . ':/private/tmp:' . $mysqlBin . ':/dev/null'];
        [$exit, $out] = $this->command([...$php, '-r', 'echo @file_get_contents(' . var_export(dirname(__DIR__) . '/src/Manifest.php', true) . ') === false ? "blocked" : "accessible";']);
        self::assertSame(0, $exit); self::assertSame('blocked', $out);
        $base = [...$php, $kit . '/recover.php'];
        $options = ['--synthetic-connection-file', $this->objects->connectionFile('reader'), '--unit', $unit, '--age', $kit . '/dependencies/age/age'];
        foreach ($keys as $key => $identity) {
            foreach ([[$legacy['snapshotId'], 1, 'unknown'], [$current['snapshotId'], 2, 'reported']] as [$snapshot, $version, $evidence]) {
                [$exit, $out, $error] = $this->command([...$base, 'inspect', ...$options, '--snapshot', $snapshot], $identity['identity']);
                self::assertSame(0, $exit, $error); self::assertSame('', $error);
                $inspection = json_decode($out, true, 64, JSON_THROW_ON_ERROR);
                self::assertSame($version, $inspection['manifestVersion']); self::assertSame($evidence, $inspection['captureEvidence']);
                self::assertTrue($inspection['verification']['referencedCiphertexts']); self::assertFalse($inspection['verification']['payloadDecrypted']);
                if ($version === 2) { self::assertSame($report['fixedAt'], $inspection['capture']['fixedAt']); }
                else { self::assertNull($inspection['capture']); }
                self::assertStringNotContainsString($schema, $out); self::assertStringNotContainsString($sourceRoot, $out);
            }
            $target = $this->mysql->newSchema(); $destination = $root . '/restored-' . $key;
            $database = ['--mysql', $mysqlBin . '/mysql', '--mysqldump', $mysqlBin . '/mysqldump', '--defaults-file', $this->mysql->defaultsFile(), '--mysql-workspace', $this->mysql->workspace(), '--database', $target];
            [$exit, $out, $error] = $this->command([...$base, 'restore', ...$options, '--snapshot', $current['snapshotId'], '--destination', $destination, ...$database], $identity['identity']);
            self::assertSame(0, $exit, $error); self::assertTrue(json_decode($out, true, 32, JSON_THROW_ON_ERROR)['databaseImported']);
            $summary = json_decode(trim($error), true, 64, JSON_THROW_ON_ERROR);
            self::assertSame('reported', $summary['captureEvidence']); self::assertSame($report['fixedAt'], $summary['capture']['fixedAt']);
            self::assertSame('same', file_get_contents($destination . '/same.txt')); self::assertSame("\0\xff\x80new", file_get_contents($destination . '/新規/binary.bin'));
            self::assertFileDoesNotExist($destination . '/old.txt'); self::assertDirectoryExists($destination . '/empty');
            foreach (['innodb_data', 'myisam_data'] as $table) { self::assertSame([['1', 'fixed']], $this->mysql->rows($target, 'SELECT * FROM ' . $table)); }
        }
        $rebuilt = Fixture::temp(); $recipientOptions = [];
        foreach ($recipients as $recipient) { array_push($recipientOptions, '--recipient', $recipient); }
        [$exit, $out, $error] = $this->command([...$base, 'rebuild-index', ...$options, '--index-directory', $rebuilt, ...$recipientOptions], $keys[0]['identity']);
        self::assertSame(0, $exit, $error); self::assertFileExists($rebuilt . '/index.jsonl');
        self::assertStringNotContainsString($schema, $out . $error);
    }

    private function command(array $command, ?string $identity = null): array
    {
        $process = proc_open($command, [0 => $identity === null ? ['file', '/dev/null', 'r'] : ['file', $identity, 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, []);
        self::assertIsResource($process); $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]); fclose($pipes[2]); return [proc_close($process), $out, $error];
    }

    private function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) as $name) { if ($name !== '.' && $name !== '..') { $this->remove($path . '/' . $name); } }
            rmdir($path);
        } else { unlink($path); }
    }
}
