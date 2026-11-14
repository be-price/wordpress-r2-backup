<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\{AgeProcess, ManagedCapture, R2Reader, SourceRebuilder};

final class R2IntegrationTest extends TestCase
{
    private ?IsolatedMysql $mysql = null;
    private ?IsolatedR2Service $service = null;
    protected function tearDown(): void { if ($this->service !== null) { $this->service->stop(); } if ($this->mysql !== null) { $this->mysql->stop(); } Fixture::cleanup(); }

    public function testServiceLossStillAllowsBothAdministratorsToRestoreBothGenerations(): void
    {
        if (!defined('CURLOPT_AWS_SIGV4')) { self::markTestSkipped('Direct R2 recovery requires CURLOPT_AWS_SIGV4.'); }
        $root = Fixture::temp();
        foreach (['service', 'site', 'index', 'jobs', 'new-index', 'new-jobs'] as $name) { mkdir($root . '/' . $name, 0700); }
        Fixture::write($root . '/site', '.wrb-synthetic', 'wordpress-r2-backup-v1');
        $this->service = IsolatedR2Service::start($root . '/service');
        $unit = $this->service->unitId();
        $writer = $this->service->writerProfile($root . '/writer.json');
        $reader = $this->service->readerProfile($root . '/reader.json');
        $admin = $this->service->administratorProfile($root . '/admin.json');
        $head = ManagedCapture::initializeIndex($root . '/index', $unit);
        $this->service->initialize($head);
        $keys = array_map(static fn (array $key): array => ['public' => $key['public'], 'identity' => file_get_contents($key['identity'])], Fixture::keys());
        $recipients = array_column($keys, 'public');
        $age = new AgeProcess(Fixture::runtime()['age']);
        file_put_contents($root . '/site/old.txt', '旧世代'); mkdir($root . '/site/empty', 0700);
        $large = fopen($root . '/site/shared.bin', 'xb');
        $block = str_repeat("\0\xFFcipher-fixture", 65536);
        for ($remaining = 65 * 1024 * 1024 + 17; $remaining > 0; $remaining -= $written) {
            $written = fwrite($large, substr($block, 0, min($remaining, strlen($block))));
            self::assertGreaterThan(0, $written);
        }
        fclose($large); $largeHash = hash_file('sha256', $root . '/site/shared.bin');
        $this->mysql = IsolatedMysql::start(); $schema = $this->mysql->newSchema();
        $this->mysql->execute($schema, "CREATE TABLE wp_posts(id INT PRIMARY KEY,title VARCHAR(100)); CREATE TABLE wp_options(name VARCHAR(40),value TEXT); CREATE TABLE custom_data(id INT,payload VARBINARY(100)); INSERT INTO wp_posts VALUES(1,'旧世代'); INSERT INTO wp_options VALUES('site','合成サイト'); INSERT INTO custom_data VALUES(1,X'000AFF');");
        $capture = ManagedCapture::open($writer, $root . '/index', $root . '/jobs', $age, 120);
        $this->mysql->client()->dump($schema, $root . '/snapshot.sql');
        $first = $capture->run($capture->prepare($root . '/site', $root . '/snapshot.sql', $recipients));
        self::assertSame('completed', $first['state']);
        unlink($root . '/site/old.txt'); file_put_contents($root . '/site/new.txt', '新世代');
        $this->mysql->execute($schema, "UPDATE wp_posts SET title='新世代'; UPDATE custom_data SET payload=X'FF1000';");
        unlink($root . '/snapshot.sql'); $this->mysql->client()->dump($schema, $root . '/snapshot.sql');
        $second = $capture->run($capture->prepare($root . '/site', $root . '/snapshot.sql', $recipients));
        self::assertSame('completed', $second['state']);

        $identity = fopen('php://memory', 'w+b'); fwrite($identity, $keys[0]['identity']); rewind($identity);
        $activation = (new SourceRebuilder($admin, new R2Reader($reader), $age))->switch(1, $identity, $recipients,
            $root . '/new-index', $root . '/new-writer.json');
        fclose($identity); self::assertSame(2, $activation['generation']);
        $thirdCapture = ManagedCapture::open($root . '/new-writer.json', $root . '/new-index', $root . '/new-jobs', $age, 120);
        $third = $thirdCapture->run($thirdCapture->prepare($root . '/site', $root . '/snapshot.sql', $recipients));
        self::assertSame('completed', $third['state']);

        $tools = dirname(__DIR__) . '/recovery';
        $archive = dirname(Fixture::runtime()['age'], 2) . '/age.tar.gz';
        [$exit, $out, $error] = $this->command([PHP_BINARY, $tools . '/build-kit.php', '--output', $root . '/kit', '--age-archive', $archive]);
        self::assertSame(0, $exit, $out . $error);
        $this->service->stop();
        $this->mysql->dropSchema($schema);
        foreach (['site', 'index', 'jobs', 'new-index', 'new-jobs', 'service/do'] as $name) { $this->remove($root . '/' . $name); }
        foreach (['snapshot.sql', 'writer.json', 'admin.json', 'new-writer.json', 'new-writer.json.activation.json'] as $name) { unlink($root . '/' . $name); }
        $this->service = IsolatedR2Service::start($root . '/service', 'reader');
        $this->service->readerProfile($reader);

        $mysqlBin = realpath(rtrim(IsolatedMysql::mysqlBin(), '/'));
        $allowed = implode(PATH_SEPARATOR, [$root, $this->mysql->workspace(), $mysqlBin, '/bin', '/usr/bin', '/dev']);
        $base = [PHP_BINARY, '-d', 'open_basedir=' . $allowed, $root . '/kit/recover.php'];
        $connection = ['--r2-connection-file', $reader, '--unit', $unit, '--age', $root . '/kit/dependencies/age/age'];
        [$exit, $out, $error] = $this->command([...$base, 'list', ...$connection]);
        self::assertSame(0, $exit, $error); self::assertCount(3, json_decode($out, true, 32, JSON_THROW_ON_ERROR));
        foreach ($keys as $keyIndex => $key) {
            foreach (['old' => $first['snapshotId'], 'new' => $second['snapshotId']] as $name => $snapshot) {
                $target = $this->mysql->newSchema(); $destination = $root . '/restored-' . $keyIndex . '-' . $name;
                $database = ['--mysql', $mysqlBin . '/mysql', '--mysqldump', $mysqlBin . '/mysqldump', '--defaults-file', $this->mysql->defaultsFile(),
                    '--mysql-workspace', $this->mysql->workspace(), '--database', $target];
                [$exit, $out, $error] = $this->command([...$base, 'restore', ...$connection, '--snapshot', $snapshot, '--destination', $destination, ...$database], $key['identity']);
                self::assertSame(0, $exit, $error);
                self::assertTrue(json_decode($out, true, 32, JSON_THROW_ON_ERROR)['databaseImported']);
                self::assertSame($largeHash, hash_file('sha256', $destination . '/shared.bin'));
                self::assertSame($name === 'old' ? '旧世代' : '新世代', file_get_contents($destination . '/' . $name . '.txt'));
                self::assertFileDoesNotExist($destination . '/' . ($name === 'old' ? 'new' : 'old') . '.txt');
                self::assertDirectoryExists($destination . '/empty');
                self::assertSame([['custom_data'], ['wp_options'], ['wp_posts']], $this->mysql->rows($target, 'SHOW TABLES'));
                self::assertSame([['1', $name === 'old' ? '旧世代' : '新世代']], $this->mysql->rows($target, 'SELECT * FROM wp_posts'));
                self::assertSame([['site', '合成サイト']], $this->mysql->rows($target, 'SELECT * FROM wp_options'));
                self::assertSame([['1', $name === 'old' ? '000AFF' : 'FF1000']], $this->mysql->rows($target, 'SELECT id,HEX(payload) FROM custom_data'));
            }
        }
    }
    private function command(array $command, string $input = ''): array
    {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fwrite($pipes[0], $input); fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        return [proc_close($process), $out, $error];
    }
    private function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) as $entry) { if ($entry !== '.' && $entry !== '..') { $this->remove($path . '/' . $entry); } }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) { unlink($path); }
    }
}
