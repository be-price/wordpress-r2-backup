<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\LiveSettings;

final class LiveSettingsTest extends TestCase
{
    protected function tearDown(): void { Fixture::cleanup(); }
    public function testCredentialsAreAuthenticatedEncryptedAndCannotBeOpenedWithAnotherSiteKey(): void
    {
        $data = ['secretAccessKey' => 'private-credential', 'bucket' => 'backup'];
        $sealed = LiveSettings::seal($data, 'first-site-key');
        self::assertStringNotContainsString('private-credential', $sealed);
        self::assertSame($data, LiveSettings::open($sealed, 'first-site-key'));
        $this->expectException(\RuntimeException::class);
        LiveSettings::open($sealed, 'other-site-key');
    }

    public function testModifiedSettingsAreRejected(): void
    {
        $sealed = LiveSettings::seal(['secret' => 'credential'], 'key');
        $bytes = base64_decode(substr($sealed, 3), true);
        $bytes[30] = chr(ord($bytes[30]) ^ 1);
        $this->expectException(\RuntimeException::class);
        LiveSettings::open('v1:' . base64_encode($bytes), 'key');
    }

    public function testConfigurationKeepsSavedSecretOnlyWhenTheInputIsBlank(): void
    {
        $data = ['accountId' => str_repeat('a', 32), 'bucket' => 'site-backups', 'accessKeyId' => str_repeat('b', 32),
            'secretAccessKey' => str_repeat('c', 64), 'recipients' => array_column(Fixture::keys(), 'public'),
            'retentionDays' => 30, 'policy' => ['tables' => [], 'fields' => [], 'paths' => [], 'scanPublic' => true]];
        $saved = LiveSettings::validate($data);
        $data['secretAccessKey'] = '';
        unset($data['policy']);
        $saved['otherSetting'] = 'preserved';
        $data['retentionDays'] = 90;
        $updated = LiveSettings::validate($data, $saved);
        self::assertSame($saved['secretAccessKey'], $updated['secretAccessKey']);
        self::assertSame($saved['policy'], $updated['policy']);
        self::assertSame('preserved', $updated['otherSetting']);
        self::assertSame(90, $updated['retentionDays']);
        $data['accountId'] = 'https://127.0.0.1';
        $this->expectException(\InvalidArgumentException::class);
        LiveSettings::validate($data, $saved);
    }

    public function testRulePathsCannotEscapeTheSite(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LiveSettings::parseRules("path ../outside exclude");
    }
}
