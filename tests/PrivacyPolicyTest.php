<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;
use PHPUnit\Framework\TestCase;
use WordPressR2Backup\PrivacyPolicy;

final class PrivacyPolicyTest extends TestCase
{
    public function testWordPressSimplePieCacheSourceIsNotTreatedAsPrivateCacheData(): void
    {
        $legacy = new PrivacyPolicy([]);
        $optional = new PrivacyPolicy(['version' => 2, 'preset' => 'custom', 'legacy' => []]);
        foreach ([$legacy, $optional] as $policy) {
            $sources = ['wp-includes/SimplePie/src/Cache', 'wp-includes/SimplePie/library/SimplePie/Cache'];
            foreach (['Redis', 'CallableNameFilter', 'NameFilter', 'File', 'DB', 'Psr16', 'Memcache', 'BaseDataCache', 'DataCache', 'Base', 'Memcached', 'MySQL'] as $name) {
                $sources[] = 'wp-includes/SimplePie/src/Cache/' . $name . '.php';
            }
            foreach (['Redis', 'File', 'DB', 'Memcache', 'Memcached', 'Base', 'MySQL'] as $name) {
                $sources[] = 'wp-includes/SimplePie/library/SimplePie/Cache/' . $name . '.php';
            }
            foreach ($sources as $path) {
                self::assertSame('include', $policy->pathAction($path)['action'], $path);
            }
            foreach (['wp-content/cache/customer.json', 'wp-includes/SimplePie/src/Cache/customer.json', 'wp-includes/SimplePie/src/Cache/Secret.php', 'wp-includes/SimplePie/src/Cache/private/Base.php', 'wp-includes/SimplePie/src/Cache/.env', 'wp-config.php'] as $path) {
                self::assertSame('exclude', $policy->pathAction($path)['action'], $path);
            }
        }
        $manual = new PrivacyPolicy(['paths' => ['wp-includes/SimplePie' => 'exclude']]);
        self::assertSame('exclude', $manual->pathAction('wp-includes/SimplePie/src/Cache/BaseDataCache.php')['action']);
    }

    public function testSqlDatetimeDefaultsAreNotMistakenForTelephoneNumbers(): void
    {
        $policy = new \WordPressR2Backup\PrivacyPolicy([]);
        self::assertFalse($policy->containsPersonalData("DEFAULT '0000-00-00 00:00:00'"));
        self::assertTrue($policy->containsPersonalData('contact 090-1234-5678'));
        self::assertTrue($policy->containsPersonalData('contact person@example.test'));
    }

    public function testIndividualTargetsOverrideCategoryDecisions(): void
    {
        $policy = new \WordPressR2Backup\PrivacyPolicy(['categories' => ['members' => 'include', 'content' => 'exclude', 'media' => 'exclude'],
            'tables' => ['wp_comments' => 'exclude', 'wp_posts' => 'include'], 'fields' => ['wp_posts.post_content' => 'exclude'],
            'paths' => ['wp-content/uploads/public' => 'include']]);
        self::assertSame('include', $policy->tableAction('wp_users')['action']);
        self::assertSame('exclude', $policy->tableAction('wp_comments')['action']);
        self::assertSame('include', $policy->tableAction('wp_posts')['action']);
        self::assertSame('exclude', $policy->fieldAction('wp_posts', 'post_content')['action']);
        self::assertSame('exclude', $policy->pathAction('wp-content/uploads/other/photo.jpg')['action']);
        self::assertSame('include', $policy->pathAction('wp-content/uploads/public/photo.jpg')['action']);
    }
    public function testSensitiveDefaultsAndManualRulesHaveExplicitActions(): void
    {
        $p = new PrivacyPolicy(['tables' => ['wp_custom' => 'include'], 'fields' => ['wp_custom.email' => 'exclude'], 'paths' => [], 'scanPublic' => true]);
        self::assertSame('exclude', $p->tableAction('wp_users')['action']);
        self::assertSame('review', $p->tableAction('wp_unknown')['action']);
        self::assertSame('include', $p->tableAction('wp_custom')['action']);
        self::assertSame('exclude', $p->fieldAction('wp_custom', 'email')['action']);
        self::assertSame('exclude', $p->pathAction('wp-config.php')['action']);
        self::assertSame('exclude', $p->pathAction('wp-content/uploads/private/export.csv')['action']);
        self::assertSame('include', $p->pathAction('wp-admin/includes/plugin.php')['action']);
    }
    public function testExcludedValuesPreserveTypeAndCannotSilentlyKeepSensitiveSource(): void
    {
        $p = new PrivacyPolicy(['fields' => ['wp_posts.user_email' => 'exclude']]);
        $nullable = ['Field' => 'user_email', 'Type' => 'varchar(255)', 'Null' => 'YES', 'Key' => '', 'Extra' => ''];
        self::assertNull($p->filterValue('wp_posts', $nullable, 'private@example.test', 1));
        $nullable['Null'] = 'NO';
        self::assertSame('removed-1@example.invalid', $p->filterValue('wp_posts', $nullable, 'private@example.test', 1));
        $nullable['Key'] = 'PRI';
        $this->expectException(\RuntimeException::class);
        $p->filterValue('wp_posts', $nullable, 'private@example.test', 1);
    }
    public function testPluginCredentialsAreNeverExportedAndPublicInspectionDoesNotExcludePublicFiles(): void
    {
        $p = new PrivacyPolicy(['scanPublic' => false]);
        self::assertFalse($p->includeRow('wp_options', ['option_name' => 'wrb_live_config', 'option_value' => 'encrypted secret']));
        self::assertFalse($p->includeRow('wp_options', ['option_name' => '_transient_session', 'option_value' => 'private']));
        self::assertTrue($p->includeRow('wp_options', ['option_name' => 'blogname', 'option_value' => 'public']));
        self::assertSame('include', $p->pathAction('wp-content/uploads/2026/01/image.jpg')['action']);
        self::assertTrue($p->containsPersonalData('contact person@example.test'));
        self::assertTrue($p->containsPersonalData('call 090-1234-5678'));
        self::assertFalse($p->containsPersonalData('plain public text'));
    }
}
