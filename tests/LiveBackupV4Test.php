<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\LiveBackup;
use WordPressR2Backup\Manifest;
use WordPressR2Backup\V4Format;

final class LiveBackupV4Test extends TestCase
{
    protected function tearDown(): void { Fixture::cleanup(); }

    public function testJobBindsObjectsToScopeSnapshotAndPrivateFiles(): void
    {
        $dir = Fixture::temp(); $file = $dir . '/page.age'; file_put_contents($file, 'cipher');
        $recipients = ['age1' . str_repeat('q', 52) . '5cu47z', 'age1' . str_repeat('p', 51) . 'qkewewh'];
        $id = str_repeat('b', 64); $scope = ['accountId' => str_repeat('a', 32), 'bucket' => 'backup-test', 'unitId' => str_repeat('c', 64), 'keySetId' => Manifest::keySetId($recipients), 'encoding' => V4Format::ENCODING];
        $receipt = ['key' => V4Format::pageKey($id), 'bytes' => 6, 'sha256' => hash('sha256', 'cipher')];
        $page = ['kind' => 'filesystem', 'sequence' => 0, 'count' => 1, 'size' => 1, 'sha256' => hash('sha256', 'x'), 'object' => $receipt];
        $root = ['format' => 'wordpress-r2-backup', 'version' => 4, 'dataClass' => 'wordpress', 'unitId' => $scope['unitId'], 'snapshotId' => $id, 'keySetId' => $scope['keySetId'], 'recipients' => $recipients, 'capturedAt' => '2026-10-30T00:00:00Z', 'encoding' => V4Format::ENCODING, 'scope' => $scope,
            'roots' => [['id' => 'r0001', 'name' => 'r0001', 'sourcePath' => '/source', 'type' => 'directory', 'mode' => 0755]], 'pages' => [$page],
            'counts' => ['files' => 0, 'directories' => 0, 'links' => 0, 'contents' => 0, 'packs' => 0, 'databaseBytes' => 0, 'fileBytes' => 0],
            'database' => ['size' => 0, 'sha256' => hash('sha256', ''), 'tableCount' => 0, 'events' => [], 'environment' => ['serverVersion' => '8', 'sqlMode' => '', 'timeZone' => '+00:00', 'characterSet' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci']],
            'wordpress' => ['siteUrl' => 'https://example.test/', 'policySha256' => hash('sha256', V4Format::json([])), 'policy' => [], 'evidence' => []]];
        $job = ['version' => 4, 'manifest' => $root, 'objects' => [['object' => $receipt, 'cipherFile' => $file]], 'state' => ['snapshotId' => $id], 'position' => 0];
        $class = new \ReflectionClass(LiveBackup::class); $backup = $class->newInstanceWithoutConstructor();
        $settings = $class->getProperty('settings'); $settings->setAccessible(true); $settings->setValue($backup, ['accountId' => $scope['accountId'], 'bucket' => $scope['bucket'], 'unitId' => $scope['unitId'], 'recipients' => $recipients]);
        $repository = $class->getProperty('repository'); $repository->setAccessible(true);
        $repository->setValue($backup, new \WordPressR2Backup\DirectR2Repository(['accountId' => $scope['accountId'], 'bucket' => $scope['bucket'], 'unitId' => $scope['unitId'], 'accessKeyId' => str_repeat('B', 32), 'secretAccessKey' => str_repeat('c', 64)]));
        $validate = $class->getMethod('validateV4Job'); $validate->setAccessible(true); $validate->invoke($backup, $job, $dir);
        $bad = $job; $bad['objects'][0]['object']['key'] = V4Format::pageKey(str_repeat('f', 64));
        try { $validate->invoke($backup, $bad, $dir); self::fail('A page from another snapshot must fail.'); } catch (\RuntimeException $expected) { self::assertNotSame('', $expected->getMessage()); }
        $bad = $job; $bad['objects'][0]['cipherFile'] = __FILE__;
        try { $validate->invoke($backup, $bad, $dir); self::fail('A file outside the job must fail.'); } catch (\RuntimeException $expected) { self::assertNotSame('', $expected->getMessage()); }
    }
}
