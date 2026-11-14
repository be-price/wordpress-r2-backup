<?php
declare(strict_types=1);

namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\{AgeProcess, DedupIndex, LocalRepository, SnapshotWriter};

final class RecoveryKitTest extends TestCase
{
    private ?IsolatedMysql $server = null;

    protected function tearDown(): void
    {
        if ($this->server !== null) { $this->server->stop(); }
        Fixture::cleanup();
    }

    public function testStandaloneKitRestoresForEitherAdministrator(): void
    {
        $root = Fixture::temp();
        $kit = $root . '/kit';
        $tools = dirname(__DIR__) . '/recovery';
        $archive = dirname(Fixture::runtime()['age'], 2) . '/age.tar.gz';
        [$exit, $out, $error] = $this->command([PHP_BINARY, $tools . '/build-kit.php', '--output', $kit, '--age-archive', $archive]);
        self::assertSame(0, $exit, $out . $error);
        self::assertDirectoryDoesNotExist($kit . '/library/vendor/phpunit');
        self::assertFileDoesNotExist($kit . '/library/tests/Fixture.php');
        self::assertFileExists($kit . '/dependencies/age/LICENSE');
        $versions = json_decode(file_get_contents($kit . '/dependencies/versions.json'), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame([1, 2, 3, 4], $versions['readableManifestVersions']);
        self::assertFileExists($kit . '/docs/v4-format.md');
        self::assertFileExists($kit . '/library/src/V4Restorer.php');
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
        $writerRepository = LocalRepository::forWriting($repository, $unit);
        $writer = new SnapshotWriter(new AgeProcess(Fixture::runtime()['age']), $writerRepository, DedupIndex::openOrCreate($index, $writerRepository));
        $recipients = array_column($keys, 'public');
        $first = $writer->capture($site, $root . '/synthetic.sql', $recipients);
        unlink($site . '/old.txt');
        Fixture::write($site, 'new.txt', 'new');
        Fixture::write($site, 'same.txt', 'changed');
        $this->server->execute($schema, "UPDATE wp_posts SET title='新世代'; UPDATE custom_data SET payload=X'FF1000';");
        unlink($root . '/synthetic.sql');
        $this->server->client()->dump($schema, $root . '/synthetic.sql');
        $second = $writer->capture($site, $root . '/synthetic.sql', $recipients);
        $this->server->dropSchema($schema);
        $this->remove($site);
        $this->remove($index);
        unlink($root . '/synthetic.sql');
        $mysqlBin = realpath(rtrim(IsolatedMysql::mysqlBin(), '/'));
        $allowed = implode(PATH_SEPARATOR, [$kit, $repository, $root, realpath(sys_get_temp_dir()), $mysqlBin, '/dev']);
        $php = [PHP_BINARY, '-d', 'open_basedir=' . $allowed];
        [$exit, $probe] = $this->command([...$php, '-r', 'echo @is_readable($argv[1]) ? "accessible" : "blocked";', __DIR__ . '/../src/Manifest.php']);
        self::assertSame(0, $exit);
        self::assertSame('blocked', $probe);
        $base = [...$php, $kit . '/recover.php'];
        $options = ['--repository', $repository, '--unit', $unit, '--age', $kit . '/dependencies/age/age'];
        [$exit, $out, $error] = $this->command([...$base, 'list', ...$options]);
        self::assertSame(0, $exit, $error);
        self::assertEqualsCanonicalizing([$first, $second], array_column(json_decode($out, true, 32, JSON_THROW_ON_ERROR), 'snapshotId'));
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
    }

    public function testBuildRejectsExistingOutputAndWrongArchiveWithoutReplacement(): void
    {
        $root = Fixture::temp();
        Fixture::write($root, 'sentinel', 'unchanged');
        $tools = dirname(__DIR__) . '/recovery';
        $archive = dirname(Fixture::runtime()['age'], 2) . '/age.tar.gz';
        [$exit] = $this->command([PHP_BINARY, $tools . '/build-kit.php', '--output', $root, '--age-archive', $archive]);
        self::assertNotSame(0, $exit);
        self::assertSame('unchanged', file_get_contents($root . '/sentinel'));
        Fixture::write($root, 'wrong.tar.gz', 'invalid');
        [$exit, $out, $error] = $this->command([PHP_BINARY, $tools . '/build-kit.php', '--output', $root . '/kit', '--age-archive', $root . '/wrong.tar.gz']);
        self::assertNotSame(0, $exit);
        self::assertStringContainsString('SHA-256', $error);
        self::assertDirectoryDoesNotExist($root . '/kit');
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
