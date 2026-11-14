<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;
use PHPUnit\Framework\TestCase;
use WordPressR2Backup\{FileSafety, SyntheticAdminRuntime, SyntheticFileSource};
final class SyntheticAdminRuntimeTest extends TestCase
{
    protected function tearDown(): void { Fixture::cleanup(); }
    public static function profile(): array
    {
        $root = Fixture::temp(); $source = SyntheticFileSource::create($root);
        foreach (['staging', 'operations', 'index', 'mysql', 'web'] as $name) { FileSafety::createPrivateDirectory($root . '/' . $name); }
        $socket = stream_socket_server('unix://' . $root . '/mysql/socket', $errno, $error);
        if ($socket === false) { throw new \RuntimeException('Cannot create inert owned socket fixture.'); }
        Fixture::write($root, 'mysql/.wrb-synthetic-mysql', "wordpress-r2-backup synthetic mysql v1\n");
        Fixture::write($root, 'mysql/client.cnf', "[client]\nuser=synthetic\nsocket=" . $root . "/mysql/socket\n");
        $connection = ['format' => 'wrb-synthetic-managed-connection-v1', 'endpoint' => 'http://127.0.0.1:1', 'unitId' => str_repeat('a', 64), 'role' => 'writer', 'token' => 'unit-test-token', 'expiresAt' => time() + 3600, 'sourceId' => str_repeat('b', 64), 'generation' => 1];
        Fixture::write($root, 'writer.json', json_encode($connection));
        unset($connection['sourceId'], $connection['generation']); $connection['format'] = 'wrb-synthetic-connection-v1'; $connection['role'] = 'reader';
        Fixture::write($root, 'reader.json', json_encode($connection));
        $profile = ['format' => 'wrb-synthetic-admin-runtime-v1', 'privateRoot' => $root, 'sourceDirectory' => dirname($source->root()),
            'stagingDirectory' => $root . '/staging', 'operationsDirectory' => $root . '/operations', 'indexDirectory' => $root . '/index',
            'writerConnectionFile' => $root . '/writer.json', 'readerConnectionFile' => $root . '/reader.json', 'ageBinary' => Fixture::runtime()['age'],
            'mysqlBinary' => '/usr/bin/false', 'dumpBinary' => '/usr/bin/false', 'mysqlDefaultsFile' => $root . '/mysql/client.cnf', 'mysqlWorkspace' => $root . '/mysql', 'syntheticSchema' => 'wrb_test_c3'];
        Fixture::write($root, 'runtime.json', json_encode($profile));
        return [$root . '/runtime.json', $profile, $socket];
    }
    public function testReopensOwnedProfileAndOperationWithoutStartingDatabase(): void
    {
        [$file, $profile, $socket] = self::profile();
        $runtime = SyntheticAdminRuntime::open($file, $profile['privateRoot'] . '/web');
        self::assertSame(str_repeat('a', 64), $runtime->unitId());
        self::assertSame($profile['sourceDirectory'] . '/content', $runtime->source()->root());
        $id = str_repeat('c', 64); $runtime->createOperation($id);
        self::assertSame($profile['operationsDirectory'] . '/' . $id . '/jobs', $runtime->jobsDirectory($id));
        self::assertSame($runtime->binding(), SyntheticAdminRuntime::open($file, $profile['privateRoot'] . '/web')->binding());
        fclose($socket);
    }
    public function testRejectsUnknownProfileFieldsOverlapDifferentUnitsAndUnsafeFile(): void
    {
        [$file, $profile, $socket] = self::profile();
        foreach (['unknown', 'overlap', 'unit', 'web', 'permissions', 'hardlink'] as $case) {
            $bad = $profile;
            if ($case === 'unknown') { $bad['token'] = 'forbidden'; }
            if ($case === 'overlap') { $bad['stagingDirectory'] = $bad['sourceDirectory']; }
            $reader = json_decode(file_get_contents($bad['readerConnectionFile']), true); $reader['unitId'] = str_repeat($case === 'unit' ? 'f' : 'a', 64); Fixture::write($bad['privateRoot'], 'reader.json', json_encode($reader));
            Fixture::write($bad['privateRoot'], 'runtime.json', json_encode($bad));
            if ($case === 'permissions') { chmod($file, 0644); }
            if ($case === 'hardlink') { link($file, $bad['privateRoot'] . '/profile-link'); }
            $failure = null;
            try { SyntheticAdminRuntime::open($file, $case === 'web' ? $profile['privateRoot'] : $profile['privateRoot'] . '/web'); }
            catch (\Throwable $error) { $failure = $error; }
            self::assertInstanceOf(\Throwable::class, $failure, $case); chmod($file, 0600);
        }
        fclose($socket);
    }
}
