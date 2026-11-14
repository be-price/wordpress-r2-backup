<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\{LiveSettings, PrivacyPolicy};

final class RowPrivacyTest extends TestCase
{
    public function testExactRowExclusionsCannotBeRevivedByPublicContextOrManualInclusion(): void
    {
        $policy = new PrivacyPolicy(['publicContentOnly' => true, 'tables' => ['wp_posts' => 'include'],
            'excludedRows' => ['wp_posts' => ['ID' => ['10']], 'wp_custom' => ['reference' => ['01', 'AB C']]]]);
        $policy->setPublicContentContext([10 => true, 11 => true], []);
        $post = ['ID' => '10', 'post_type' => 'post', 'post_status' => 'publish', 'post_password' => ''];
        self::assertTrue($policy->isExplicitlyExcludedRow('wp_posts', $post));
        self::assertFalse($policy->includeRow('wp_posts', $post));
        self::assertFalse($policy->includeRow('wp_postmeta', ['post_id' => '10', 'meta_key' => 'configuration']));
        self::assertTrue($policy->includeRow('wp_posts', array_replace($post, ['ID' => '11'])));
        self::assertTrue($policy->includeRow('wp_postmeta', ['post_id' => '11', 'meta_key' => 'configuration']));
        self::assertTrue($policy->isExplicitlyExcludedRow('wp_custom', ['reference' => '01']));
        self::assertFalse($policy->isExplicitlyExcludedRow('wp_custom', ['reference' => '1']));
        self::assertFalse($policy->isExplicitlyExcludedRow('wp_custom', ['other_reference' => '01']));
        self::assertFalse($policy->includeRow('wp_custom', ['reference' => 'AB C']));
        self::assertTrue($policy->includeRow('wp_custom', ['reference' => 'AB C extra']));
    }

    public function testRowRulesSurviveSettingsEditingAlongsideExistingOptionExclusions(): void
    {
        $policy = LiveSettings::validatePolicy(['tables' => [], 'fields' => [], 'paths' => [],
            'excludedOptions' => ['plugin_configuration'], 'excludedRows' => ['wp_posts' => ['ID' => ['10']]]]);
        self::assertFalse($policy['legacy']['publicContentOnly']);
        $settings = ['policy' => $policy, 'secretAccessKey' => 'secret', 'recipients' => ['first', 'second'],
            'proofs' => [['confirmed' => true]], 'connectionVerified' => true, 'retentionDays' => 30];
        self::assertSame($policy, LiveSettings::privacyPolicy(null, $policy));
        $updated = LiveSettings::withPolicy($settings, LiveSettings::applyPrivacyInput($policy, ['scanPublic' => '0']));
        self::assertSame(['wp_posts' => ['ID' => ['10']]], $updated['policy']['legacy']['excludedRows']);
        self::assertSame(['plugin_configuration'], $updated['policy']['legacy']['excludedOptions']);
        $expected = $settings; unset($expected['policy']); $actual = $updated; unset($actual['policy']);
        self::assertSame($expected, $actual);
        self::assertFalse((new PrivacyPolicy($updated['policy']))->includeRow('wp_options', ['option_name' => 'plugin_configuration']));
    }

    public function testFormAddsAndRemovesExactRowsWithExplicitIncludes(): void
    {
        $policy = LiveSettings::validatePolicy(['excludedRows' => ['wp_posts' => ['ID' => ['10']]]]);
        $input = ['ruleIntent' => 'add', 'ruleType' => 'row', 'tableName' => 'wp_posts', 'fieldName' => 'ID', 'rowValue' => '10', 'ruleAction' => 'include'];
        self::assertFalse((new PrivacyPolicy($policy))->includeRow('wp_posts', ['ID' => '10']));
        $policy = LiveSettings::applyPrivacyInput($policy, $input);
        self::assertSame('include', $policy['rows']['wp_posts']['ID']['10']);
        self::assertTrue((new PrivacyPolicy($policy))->includeRow('wp_posts', ['ID' => '10']));
        $policy = LiveSettings::applyPrivacyInput($policy, ['decisions' => [base64_encode('row wp_posts.ID 10') => '']]);
        self::assertFalse((new PrivacyPolicy($policy))->includeRow('wp_posts', ['ID' => '10']));
        $input = array_replace($input, ['tableName' => 'wp_custom', 'fieldName' => 'reference', 'rowValue' => 'AB C', 'ruleAction' => 'exclude']);
        $policy = LiveSettings::applyPrivacyInput($policy, $input);
        self::assertFalse((new PrivacyPolicy($policy))->includeRow('wp_custom', ['reference' => 'AB C']));
        self::assertTrue((new PrivacyPolicy($policy))->includeRow('wp_custom', ['reference' => 'AB C extra']));
        $policy = LiveSettings::applyPrivacyInput($policy, ['decisions' => [base64_encode('row wp_custom.reference AB C') => '']]);
        self::assertArrayNotHasKey('wp_custom', $policy['rows']);
    }

    /** @dataProvider invalidRowRules */
    public function testMalformedRowRulesAreRejected($rows): void
    {
        $this->expectException(\RuntimeException::class);
        new PrivacyPolicy(['excludedRows' => $rows]);
    }

    public function invalidRowRules(): array
    {
        return [[null], ['invalid'], [['0posts' => ['ID' => ['10']]]], [['wp_posts' => null]],
            [['wp_posts' => ['ID OR 1=1' => ['10']]]], [['wp_posts' => ['ID' => ['key' => '10']]]],
            [['wp_posts' => ['ID' => [10]]]], [['wp_posts' => ['ID' => ['']]]],
            [['wp_posts' => ['ID' => ["10\0"]]]], [['wp_posts' => ['ID' => ["10\n"]]]],
            [['wp_posts' => ['ID' => [str_repeat('a', 192)]]]],
            [['wp_posts' => ['ID' => array_fill(0, 2501, '10'), 'post_parent' => array_fill(0, 2500, '11')]]]];
    }
}
