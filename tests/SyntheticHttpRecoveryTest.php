<?php
declare(strict_types=1);

namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;

use WordPressR2Backup\{AgeProcess, DedupIndex, ManagedCapture, SyntheticHttpRepository, SnapshotWriter};

final class SyntheticHttpRecoveryTest extends TestCase
{
    private ?IsolatedMysql $server = null;
    private ?IsolatedObjectServer $objectServer = null;

    protected function tearDown(): void
    {
        if ($this->objectServer !== null) { $this->objectServer->stop(); }
        if ($this->server !== null) { $this->server->stop(); }
        Fixture::cleanup();
    }

    public static function transports(): array { return [['legacyB1'], ['managedB2']]; }

    /** @dataProvider transports */
    public function testStandaloneKitRestoresSelectedSnapshotOverLoopback(string $mode): void
    {
        $root = Fixture::temp();
        $kit = $root . '/kit';
        $tools = dirname(__DIR__) . '/recovery';
        $archive = dirname(Fixture::runtime()['age'], 2) . '/age.tar.gz';
        [$exit, $out, $error] = $this->command([PHP_BINARY, $tools . '/build-kit.php', '--output', $kit, '--age-archive', $archive]);
        self::assertSame(0, $exit, $out . $error);
        self::assertDirectoryDoesNotExist($kit . '/library/vendor/phpunit');
        self::assertDirectoryDoesNotExist($kit . '/library/tests');
        self::assertFileExists($kit . '/library/src/ObjectReader.php');
        self::assertFileExists($kit . '/library/src/ObjectWriter.php');
        self::assertFileExists($kit . '/library/src/SyntheticHttpRepository.php');
        $versions = json_decode(file_get_contents($kit . '/dependencies/versions.json'), true, 32, JSON_THROW_ON_ERROR);
        self::assertContains('curl', $versions['extensions']);
        self::assertSame(67108864, $versions['syntheticHttpCiphertextMaximumBytes']);
        self::assertSame([1, 2, 3, 4], $versions['readableManifestVersions']);
        self::assertFileExists($kit . '/dependencies/age/LICENSE');
        self::assertSame(0700, fileperms($kit) & 0777);
        self::assertFileDoesNotExist($kit . '/library/vendor/composer/autoload_dev.php');
        $hashes = json_decode(file_get_contents($kit . '/SHA256SUMS.json'), true, 32, JSON_THROW_ON_ERROR);
        foreach ($hashes as $relative => $hash) {
            self::assertSame($hash, hash_file('sha256', $kit . '/' . $relative));
        }
        $keys = Fixture::keys();
        $site = Fixture::site(['old.txt' => 'old', 'same.txt' => 'same']);
        mkdir($site . '/empty', 0700);
        $repository = Fixture::temp();
        $index = Fixture::temp();
        $this->server = IsolatedMysql::start();
        $schema = $this->server->newSchema();
        $this->server->execute($schema, "CREATE TABLE wp_posts(id INT PRIMARY KEY,title VARCHAR(100)); CREATE TABLE wp_options(name VARCHAR(40),value TEXT); CREATE TABLE custom_data(id INT,payload VARBINARY(100)); INSERT INTO wp_posts VALUES(1,'旧世代'); INSERT INTO wp_options VALUES('site','合成サイト'); INSERT INTO custom_data VALUES(1,X'000AFF');");
        $this->server->client()->dump($schema, $root . '/synthetic.sql');
        $unit = str_repeat('a', 64);
        $managed = $mode === 'managedB2';
        $this->objectServer = $managed
            ? IsolatedObjectServer::startManaged($repository, $unit, str_repeat('e', 64), ManagedCapture::initializeIndex($index, $unit))
            : IsolatedObjectServer::start($repository, $unit);
        $profile = $root . "/reader.json";
        Fixture::write($root, "reader.json", file_get_contents($this->objectServer->connectionFile("reader")));
        $writerRepository = new SyntheticHttpRepository($this->objectServer->connectionFile("writer"));
        $jobs = Fixture::temp();
        $writer = $managed
            ? ManagedCapture::open($this->objectServer->connectionFile('writer'), $index, $jobs, new AgeProcess(Fixture::runtime()['age']))
            : new SnapshotWriter(new AgeProcess(Fixture::runtime()['age']), $writerRepository, DedupIndex::openOrCreate($index, $writerRepository));
        $recipients = array_column($keys, 'public');
        $capture = static function () use ($managed, $writer, $site, $root, $recipients): string {
            return $managed ? $writer->run($writer->prepare($site, $root . '/synthetic.sql', $recipients))['snapshotId']
                : $writer->capture($site, $root . '/synthetic.sql', $recipients);
        };
        $first = $capture();
        unlink($site . '/old.txt');
        Fixture::write($site, 'new.txt', 'new');
        Fixture::write($site, 'same.txt', 'changed');
        $this->server->execute($schema, "UPDATE wp_posts SET title='新世代'; UPDATE custom_data SET payload=X'FF1000';");
        unlink($root . '/synthetic.sql');
        $this->server->client()->dump($schema, $root . '/synthetic.sql');
        $second = $capture();
        $broken = str_repeat('c', 64);
        Fixture::write($repository, $unit . '/commits/' . $broken . '.json', '{broken');
        $this->server->dropSchema($schema);
        $this->remove($site);
        $this->remove($index);
        $this->remove($jobs);
        if ($managed) { $this->remove(dirname($this->objectServer->connectionFile('writer')) . '/unit-control'); }
        unlink($root . '/synthetic.sql');
        $mysqlBin = realpath(rtrim(IsolatedMysql::mysqlBin(), '/'));
        $allowed = implode(PATH_SEPARATOR, [$kit, $root, $this->server->workspace(), $mysqlBin, '/dev']);
        $php = [PHP_BINARY, '-d', 'open_basedir=' . $allowed];
        [$exit, $probe] = $this->command([...$php, '-r', 'echo @is_readable($argv[1]) ? "accessible" : "blocked";', __DIR__ . '/../src/Manifest.php']);
        self::assertSame(0, $exit);
        self::assertSame('blocked', $probe);
        [$exit, $probe] = $this->command([...$php, '-r', 'echo @is_readable($argv[1]) ? "accessible" : "blocked";', $repository . '/' . $unit . '/commits/' . $first . '.json']);
        self::assertSame(0, $exit);
        self::assertSame('blocked', $probe);
        $base = [...$php, $kit . '/recover.php'];
        $options = ['--synthetic-connection-file', $profile, '--unit', $unit, '--age', $kit . '/dependencies/age/age'];
        [$exit, $out, $error] = $this->command([...$base, 'list', ...$options]);
        self::assertSame(0, $exit, $error);
        $statuses = array_column(json_decode($out, true, 32, JSON_THROW_ON_ERROR), null, 'snapshotId');
        self::assertEqualsCanonicalizing([$first, $second, $broken], array_keys($statuses));
        self::assertTrue($statuses[$first]['valid']);
        self::assertTrue($statuses[$second]['valid']);
        self::assertFalse($statuses[$broken]['valid']);
        foreach ([[$first, 0, 'old', 'same'], [$second, 0, 'new', 'changed'], [$first, 1, 'old', 'same'], [$second, 1, 'new', 'changed']] as [$snapshot, $key, $name, $same]) {
            $destination = $root . '/restored-' . $name . '-' . $key;
            $target = $this->server->newSchema();
            $database = ['--mysql', $mysqlBin . '/mysql', '--mysqldump', $mysqlBin . '/mysqldump', '--defaults-file', $this->server->defaultsFile(), '--mysql-workspace', $this->server->workspace(), '--database', $target];
            [$exit, $out, $error] = $this->command([...$base, 'restore', ...$options, '--snapshot', $snapshot, '--destination', $destination, ...$database], $keys[$key]['identity']);
            self::assertSame(0, $exit, $error);
            self::assertTrue(json_decode($out, true, 32, JSON_THROW_ON_ERROR)['databaseImported']);
            self::assertSame([['custom_data'], ['wp_options'], ['wp_posts']], $this->server->rows($target, 'SHOW TABLES'));
            self::assertSame([['1', $name === 'old' ? '旧世代' : '新世代']], $this->server->rows($target, 'SELECT * FROM wp_posts'));
            self::assertSame([['site', '合成サイト']], $this->server->rows($target, 'SELECT * FROM wp_options'));
            self::assertSame([['1', $name === 'old' ? '000AFF' : 'FF1000']], $this->server->rows($target, 'SELECT id,HEX(payload) FROM custom_data'));
            self::assertSame($name, file_get_contents($destination . '/' . $name . '.txt'));
            self::assertSame($same, file_get_contents($destination . '/same.txt'));
            self::assertFileDoesNotExist($destination . '/' . ($name === 'old' ? 'new' : 'old') . '.txt');
            self::assertDirectoryExists($destination . '/empty');
            self::assertSame(['empty', $name . '.txt', 'same.txt'], array_values(array_diff(scandir($destination), ['.', '..'])));
        }
        [$exit, $out, $error] = $this->command([...$base, 'restore', ...$options, '--snapshot', $first, '--destination', $root . '/files-only'], $keys[0]['identity']);
        self::assertSame(0, $exit, $error);
        self::assertFalse(json_decode($out, true, 32, JSON_THROW_ON_ERROR)['databaseImported']);
        self::assertStringContainsString('DB未取込', $error);
        self::assertSame(['empty', 'old.txt', 'same.txt'], array_values(array_diff(scandir($root . '/files-only'), ['.', '..'])));
        [$exit, $out, $error] = $this->command([...$base, 'restore', ...$options, '--snapshot', $first, '--destination', $root . '/partial-db', '--database', 'wrb_test_missing']);
        self::assertNotSame(0, $exit);
        self::assertStringContainsString('DB引数', $error);
        self::assertDirectoryDoesNotExist($root . '/partial-db');
        $protected = $root . '/protected';
        mkdir($protected, 0700);
        Fixture::write($protected, 'sentinel', 'unchanged');
        $data = json_decode(file_get_contents($profile), true, 8, JSON_THROW_ON_ERROR);
        self::assertSame(0600, fileperms($profile) & 0777);
        self::assertSame(0700, fileperms(dirname($profile)) & 0777);
        $badProfiles = [
            'malformed' => '{broken',
            'external' => json_encode(array_replace($data, ['endpoint' => 'https://example.invalid']), JSON_THROW_ON_ERROR),
            'expired' => json_encode(array_replace($data, ['expiresAt' => time() - 1]), JSON_THROW_ON_ERROR),
        ];
        foreach ($badProfiles as $name => $contents) {
            Fixture::write($root, $name . '.json', $contents);
        }
        $routes = [
            [],
            ['--repository', $repository, '--synthetic-connection-file', $profile],
            ['--synthetic-connection-file', $root . '/malformed.json'],
            ['--synthetic-connection-file', $root . '/external.json'],
            ['--synthetic-connection-file', $root . '/expired.json'],
        ];
        foreach ($routes as $route) {
            [$exit, $out, $error] = $this->command([...$base, 'restore', ...$route, '--unit', $unit, '--age', $kit . '/dependencies/age/age', '--snapshot', $first, '--destination', $protected], $keys[0]['identity']);
            self::assertNotSame(0, $exit);
            self::assertSame('', $out);
            self::assertFalse(str_contains($error, $data['token']), 'An error must not expose connection credentials.');
            self::assertSame(['sentinel'], array_values(array_diff(scandir($protected), ['.', '..'])));
            self::assertSame('unchanged', file_get_contents($protected . '/sentinel'));
        }
        [$exit, $out, $error] = $this->command([...$base, 'restore', '--synthetic-connection-file', $profile, '--unit', str_repeat('b', 64), '--age', $kit . '/dependencies/age/age', '--snapshot', $first, '--destination', $protected], $keys[0]['identity']);
        self::assertNotSame(0, $exit);
        self::assertSame('', $out);
        self::assertStringContainsString('保存先とageの検査', $error);
        self::assertSame('unchanged', file_get_contents($protected . '/sentinel'));
        $this->objectServer->fault('truncate-get-once');
        [$exit, $out, $error] = $this->command([...$base, 'restore', ...$options, '--snapshot', $first, '--destination', $root . '/interrupted'], $keys[0]['identity']);
        self::assertNotSame(0, $exit);
        self::assertSame('', $out);
        self::assertDirectoryDoesNotExist($root . '/interrupted');
        self::assertSame('unchanged', file_get_contents($protected . '/sentinel'));
        foreach ($hashes as $relative => $hash) {
            $contents = file_get_contents($kit . '/' . $relative);
            self::assertFalse(str_contains($contents, $data['token']), 'Kit files must not contain connection credentials.');
            foreach ($keys as $key) {
                self::assertFalse(str_contains($contents, file_get_contents($key['identity'])), 'Kit files must not contain private identities.');
            }
        }

    }

    private function command(array $command, ?string $identity = null): array
    {
        $process = proc_open($command, [0 => $identity === null ? ['file', '/dev/null', 'r'] : ['file', $identity, 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, []);
        self::assertIsResource($process);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        return [proc_close($process), $out, $error];
    }

    private function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) as $entry) {
                if ($entry !== '.' && $entry !== '..') { $this->remove($path . '/' . $entry); }
            }
            rmdir($path);
        } else { unlink($path); }
    }
}
