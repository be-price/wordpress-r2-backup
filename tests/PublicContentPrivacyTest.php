<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\{LiveSettings, PrivacyPolicy};

final class PublicContentPrivacyTest extends TestCase
{
    public function testInspectionExemptsOnlyRowsKnownToBePublic(): void
    {
        $policy = new PrivacyPolicy(['scanPublic' => false]);
        self::assertTrue($policy->needsPublicContentContext());
        self::assertTrue($policy->shouldInspectField('wp_posts', 'post_content', ['ID' => '10']));
        $policy->setPublicContentContext([10 => true, 20 => true], [], [20 => true]);
        self::assertFalse($policy->shouldInspectField('wp_posts', 'post_content', ['ID' => '10']));
        self::assertFalse($policy->shouldInspectField('wp_postmeta', 'meta_value', ['post_id' => '10']));
        self::assertTrue($policy->shouldInspectField('wp_posts', 'post_content', ['ID' => '20']));
        self::assertTrue($policy->shouldInspectField('wp_postmeta', 'meta_value', ['post_id' => '30']));
        self::assertTrue($policy->shouldInspectField('wp_posts', 'post_content'));
        foreach (['terms', 'termmeta', 'links'] as $table) {
            self::assertTrue($policy->shouldInspectField('wp_' . $table, 'description'));
        }
    }

    public function testPublicOnlyRetentionRequiresContextAndNeverRestoresPrivateRowsOrFiles(): void
    {
        $policy = new PrivacyPolicy(['publicContentOnly' => true, 'scanPublic' => false,
            'tables' => ['wp_posts' => 'include'], 'paths' => ['wp-content/uploads' => 'include']]);
        $public = ['ID' => '10', 'post_type' => 'post', 'post_status' => 'publish', 'post_password' => ''];
        self::assertFalse($policy->includeRow('wp_posts', $public));
        self::assertSame('exclude', $policy->pathAction('wp-content/uploads/2026/public.jpg')['action']);
        $policy->setPublicContentContext([10 => true], ['wp-content/uploads/2026/public.jpg' => true], [20 => true]);
        self::assertTrue($policy->includeRow('wp_posts', $public));
        self::assertFalse($policy->includeRow('wp_posts', array_replace($public, ['ID' => '30', 'post_status' => 'private'])));
        self::assertFalse($policy->includeRow('wp_posts', array_replace($public, ['post_password' => 'protected'])));
        self::assertTrue($policy->includeRow('wp_posts', array_replace($public, ['ID' => '20', 'post_type' => 'acf-field'])));
        self::assertTrue($policy->includeRow('wp_postmeta', ['post_id' => '10', 'meta_key' => '_thumbnail_id']));
        self::assertTrue($policy->includeRow('wp_postmeta', ['post_id' => '20', 'meta_key' => 'configuration']));
        self::assertFalse($policy->includeRow('wp_postmeta', ['post_id' => '30', 'meta_key' => 'note']));
        self::assertFalse($policy->includeRow('wp_postmeta', ['post_id' => '10', 'meta_key' => 'customer_email']));
        self::assertSame('include', $policy->pathAction('wp-content/uploads/2026')['action']);
        self::assertSame('include', $policy->pathAction('wp-content/uploads/2026/public.jpg')['action']);
        self::assertSame('exclude', $policy->pathAction('wp-content/uploads/2026/unpublished.jpg')['action']);
        self::assertSame('include', $policy->pathAction('wp-content/themes/theme/style.css')['action']);
    }

    public function testPublicOnlyPasswordKeepsEmptyValueAndRejectsNonemptyEvenWithManualExclusion(): void
    {
        $policy = new PrivacyPolicy(['publicContentOnly' => true, 'fields' => ['wp_posts.post_password' => 'exclude']]);
        $column = ['Field' => 'post_password', 'Type' => 'varchar(255)', 'Null' => 'NO'];
        self::assertSame('include', $policy->fieldAction('wp_posts', 'post_password')['action']);
        self::assertSame('', $policy->filterValue('wp_posts', $column, '', 1));
        $this->expectException(\RuntimeException::class);
        $policy->filterValue('wp_posts', $column, 'protected', 1);
    }

    public function testRefreshingPublicContextDropsEarlierPublicFilesAndHonorsMandatoryExclusions(): void
    {
        $policy = new PrivacyPolicy(['publicContentOnly' => true, 'scanPublic' => false,
            'paths' => ['wp-content/uploads/2026/excluded.jpg' => 'exclude']]);
        $policy->setPublicContentContext([10 => true], ['wp-content/uploads/2026/first.jpg' => true,
            'wp-content/uploads/2026/excluded.jpg' => true, 'wp-content/uploads/private/secret.jpg' => true]);
        self::assertSame('exclude', $policy->pathAction('wp-content/uploads/2026/excluded.jpg')['action']);
        self::assertSame('exclude', $policy->pathAction('wp-content/uploads/private/secret.jpg')['action']);
        $policy->setPublicContentContext([20 => true], ['wp-content/uploads/2027/second.jpg' => true]);
        self::assertTrue($policy->shouldInspectField('wp_posts', 'post_content', ['ID' => '10']));
        self::assertSame('exclude', $policy->pathAction('wp-content/uploads/2026/first.jpg')['action']);
        self::assertSame('exclude', $policy->pathAction('wp-content/uploads/2026')['action']);
        self::assertSame('include', $policy->pathAction('wp-content/uploads/2027/second.jpg')['action']);
    }

    public function testExactOptionExclusionsAndNewSettingsSurvivePolicyEditing(): void
    {
        $policy = LiveSettings::validatePolicy(['tables' => [], 'fields' => [], 'paths' => [],
            'publicContentOnly' => true, 'excludedOptions' => ['plugin_configuration']]);
        $settings = ['policy' => $policy, 'secretAccessKey' => 'secret', 'recipients' => ['first', 'second'],
            'proofs' => [['confirmed' => true]], 'connectionVerified' => true, 'retentionDays' => 30];
        self::assertSame($policy, LiveSettings::privacyPolicy(null, $policy));
        $updated = LiveSettings::withPolicy($settings, LiveSettings::applyPrivacyInput($policy, ['scanPublic' => '0']));
        self::assertTrue($updated['policy']['legacy']['publicContentOnly']);
        self::assertSame(['plugin_configuration'], $updated['policy']['legacy']['excludedOptions']);
        $expected = $settings; unset($expected['policy']); $actual = $updated; unset($actual['policy']);
        self::assertSame($expected, $actual);
        $privacy = new PrivacyPolicy($updated['policy']);
        self::assertFalse($privacy->includeRow('wp_options', ['option_name' => 'plugin_configuration']));
        self::assertTrue($privacy->includeRow('wp_options', ['option_name' => 'plugin_configuration_extra']));
        self::assertTrue($privacy->includeRow('wp_options', ['option_name' => 'blogname']));
        self::assertFalse($privacy->includeRow('wp_options', ['option_name' => 'wrb_live_config']));
    }

    public function testPrivacyFormEditsPublicSelectionAndExactOptionRows(): void
    {
        $policy = LiveSettings::validatePolicy(['publicContentOnly' => false, 'scanPublic' => true, 'excludedOptions' => ['plugin_configuration']]);
        $policy = LiveSettings::applyPrivacyInput($policy, ['publicContentOnly' => '1', 'scanPublic' => '0', 'ruleIntent' => 'add', 'ruleType' => 'option', 'optionName' => 'plugin_configuration', 'ruleAction' => 'include']);
        self::assertTrue($policy['legacy']['publicContentOnly']);
        self::assertFalse($policy['legacy']['scanPublic']);
        self::assertSame('include', $policy['options']['plugin_configuration']);
        self::assertTrue((new PrivacyPolicy($policy))->includeRow('wp_options', ['option_name' => 'plugin_configuration']));
        $policy = LiveSettings::applyPrivacyInput($policy, ['decisions' => [base64_encode('option plugin_configuration') => '']]);
        self::assertFalse((new PrivacyPolicy($policy))->includeRow('wp_options', ['option_name' => 'plugin_configuration']));
        $policy = LiveSettings::applyPrivacyInput($policy, ['publicContentOnly' => '0', 'ruleIntent' => 'add', 'ruleType' => 'option', 'optionName' => 'wrb_live_config', 'ruleAction' => 'include']);
        self::assertFalse($policy['legacy']['publicContentOnly']);
        self::assertTrue((new PrivacyPolicy($policy))->includeRow('wp_options', ['option_name' => 'wrb_live_config']));
    }

    /** @dataProvider invalidPolicySettings */
    public function testInvalidNewSettingsAreRejected(array $change): void
    {
        $this->expectException(\Exception::class);
        LiveSettings::validatePolicy($change + ['tables' => [], 'fields' => [], 'paths' => []]);
    }

    public function invalidPolicySettings(): array
    {
        return [[['publicContentOnly' => 'false']], [['publicContentOnly' => null]], [['excludedOptions' => null]], [['excludedOptions' => 'plugin_configuration']],
            [['excludedOptions' => ['plugin' => true]]], [['excludedOptions' => ['']]],
            [['excludedOptions' => ["private\0option"]]]];
    }
}
