<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use WordPressR2Backup\PrivacyPolicy;

final class OptionalPolicyTest extends TestCase
{
    public function testCompleteSavingKeepsConfigurationCredentialsUnknownDataAndOriginalValues(): void
    {
        $policy = new PrivacyPolicy(['version' => 2, 'preset' => 'full']);
        self::assertTrue($policy->isComplete());
        self::assertFalse($policy->needsPublicContentContext());
        foreach (['wp-config.php', '.htaccess', '.env', 'wp-content/uploads/private/person.csv', 'private/key.pem', 'cache/data.bin'] as $path) {
            self::assertSame('include', $policy->pathAction($path)['action'], $path);
        }
        foreach (['wp_users', 'wp_usermeta', 'wp_unknown_forms', 'wp_logs', 'wp_options'] as $table) {
            self::assertSame('include', $policy->tableAction($table)['action']);
            self::assertSame('include', $policy->fieldAction($table, 'password')['action']);
            self::assertFalse($policy->shouldInspectField($table, 'email'));
        }
        foreach (['wrb_live_config', '_transient_session', 'auth_token', 'admin_email'] as $name) {
            self::assertTrue($policy->includeRow('wp_options', ['option_name' => $name]));
        }
        self::assertTrue($policy->includeRow('wp_posts', ['post_type' => 'flamingo_inbound', 'post_status' => 'private', 'post_password' => 'protected']));
        self::assertTrue($policy->includeRow('wp_postmeta', ['meta_key' => 'secret_token']));
        $value = 'a:1:{s:5:"email";s:19:"person@example.test";}';
        self::assertSame($value, $policy->filterValue('wp_users', self::column('user_pass', 'NO', 'PRI'), $value, 1));
        self::assertSame([], $policy->legacyRuleSummary());
    }

    public function testFormsPresetSeparatesSubmissionsFromDefinitionsUsersAndUnknownTables(): void
    {
        $policy = new PrivacyPolicy(['version' => 2, 'preset' => 'forms']);
        self::assertFalse($policy->isComplete());
        self::assertSame('exclude', $policy->tableAction('wp_db7_forms')['action']);
        self::assertSame('exclude', $policy->tableAction('wp_wpforms_entries')['action']);
        self::assertFalse($policy->includeRow('wp_posts', ['post_type' => 'flamingo_inbound']));
        foreach (['wpcf7_contact_form', 'acf-field', 'acf-field-group', 'wpforms', 'post', 'custom_form_template'] as $type) {
            self::assertTrue($policy->includeRow('wp_posts', ['post_type' => $type]), $type);
        }
        foreach (['wp_users', 'wp_usermeta', 'wp_options', 'wp_unknown_forms', 'wp_form_logs', 'wp_flamingo_custom'] as $table) {
            self::assertSame('include', $policy->tableAction($table)['action'], $table);
        }
        self::assertTrue($policy->includeRow('wp_options', ['option_name' => 'auth_token']));
        self::assertSame('retained', $policy->filterValue('wp_options', self::column('option_value', 'NO'), 'retained', 1, ['option_name' => 'auth_token']));
        self::assertSame('include', $policy->pathAction('wp-config.php')['action']);
        $included = new PrivacyPolicy(['version' => 2, 'preset' => 'forms', 'categories' => ['forms' => 'include']]);
        self::assertTrue($included->includeRow('wp_posts', ['post_type' => 'flamingo_inbound']));
    }

    public function testSpecificIncludesRemainReachableAndOverrideLowerPriorityExclusions(): void
    {
        $policy = new PrivacyPolicy(['version' => 2, 'preset' => 'custom',
            'categories' => ['authentication' => 'exclude', 'media' => 'exclude', 'wordpress_users' => 'exclude'],
            'paths' => ['wp-config.php' => 'include', 'wp-content/uploads' => 'exclude', 'wp-content/uploads/keep/a.txt' => 'include'],
            'tables' => ['wp_users' => 'exclude', 'wp_options' => 'exclude'],
            'rows' => ['wp_users' => ['ID' => ['7' => 'include']]], 'options' => ['auth_token' => 'include']]);
        self::assertSame('include', $policy->pathAction('wp-config.php')['action']);
        self::assertTrue($policy->shouldTraversePath('wp-content/uploads'));
        self::assertTrue($policy->shouldTraversePath('wp-content/uploads/keep'));
        self::assertFalse($policy->shouldTraversePath('wp-content/uploads/drop'));
        self::assertSame('include', $policy->pathAction('wp-content/uploads/keep/a.txt')['action']);
        self::assertTrue($policy->tableMayIncludeRows('wp_users'));
        self::assertTrue($policy->tableMayIncludeRows('wp_options'));
        self::assertTrue($policy->includeRow('wp_users', ['ID' => '7']));
        self::assertSame('retained hash', $policy->filterValue('wp_users', self::column('password', 'NO'), 'retained hash', 7, ['ID' => '7']));
        self::assertFalse($policy->includeRow('wp_users', ['ID' => '8']));
        self::assertTrue($policy->includeRow('wp_options', ['option_name' => 'auth_token']));
        self::assertFalse($policy->includeRow('wp_options', ['option_name' => 'blogname']));
        self::assertFalse($policy->shouldInspectField('wp_options', 'option_value', ['option_name' => 'auth_token']));
    }

    public function testTableIncludeLiftsLegacyFixedRowAndFieldDecisions(): void
    {
        $rules = PrivacyPolicy::migrateLegacy(['tables' => ['wp_options' => 'include']]);
        $migrated = new PrivacyPolicy($rules);
        self::assertFalse($migrated->includeRow('wp_options', ['option_name' => 'auth_token']));
        $rules['tables']['wp_options'] = 'include';
        $rules['tables']['wp_posts'] = 'include';
        $rules['paths']['wp-config.php'] = 'include';
        $policy = new PrivacyPolicy($rules);
        self::assertTrue($policy->includeRow('wp_options', ['option_name' => 'auth_token']));
        self::assertTrue($policy->includeRow('wp_posts', ['post_type' => 'flamingo_inbound']));
        self::assertSame('include', $policy->fieldAction('wp_options', 'password')['action']);
        self::assertSame('include', $policy->pathAction('wp-config.php')['action']);
        self::assertSame('secret', $policy->filterValue('wp_options', self::column('password'), 'secret', 1));
    }

    public function testCategoryExceptionsRemainReachableWithinDifferentlyClassifiedParents(): void
    {
        $policy = new PrivacyPolicy(['version' => 2, 'preset' => 'custom',
            'categories' => ['content' => 'exclude', 'media' => 'include', 'system_settings' => 'exclude', 'authentication' => 'include']]);
        self::assertTrue($policy->shouldTraversePath('wp-content'));
        self::assertSame('include', $policy->pathAction('wp-content/uploads/a.jpg')['action']);
        self::assertTrue($policy->tableMayIncludeRows('wp_posts'));
        self::assertTrue($policy->includeRow('wp_posts', ['post_type' => 'flamingo_inbound']));
        self::assertTrue($policy->tableMayIncludeRows('wp_options'));
        self::assertTrue($policy->includeRow('wp_options', ['option_name' => 'auth_token']));
        self::assertSame('retained by category', $policy->filterValue('wp_options', self::column('option_value', 'NO'), 'retained by category', 1, ['option_name' => 'auth_token']));
    }

    public function testMigrationPreservesLegacyEffectiveDecisionsAndPublicContext(): void
    {
        $rules = ['tables' => ['wp_unknown' => 'include'], 'fields' => ['wp_posts.email' => 'exclude'],
            'paths' => ['wp-config.php' => 'include', 'wp-content/uploads/public' => 'include'],
            'categories' => ['members' => 'include', 'media' => 'include'], 'scanPublic' => false, 'publicContentOnly' => true,
            'excludedOptions' => ['blogdescription'], 'excludedRows' => ['wp_posts' => ['ID' => ['3']]]];
        $old = new PrivacyPolicy($rules);
        $new = new PrivacyPolicy(PrivacyPolicy::migrateLegacy($rules));
        self::assertSame($old->rules(), $new->rules()['legacy']);
        self::assertSame([], $new->rules()['paths']);
        self::assertFalse($new->isComplete());
        foreach ([$old, $new] as $policy) { $policy->setPublicContentContext([1 => true, 3 => true], ['wp-content/uploads/public/a.jpg' => true], [2 => true]); }
        self::assertSame($old->needsPublicContentContext(), $new->needsPublicContentContext());
        foreach (['wp_users', 'wp_comments', 'wp_unknown', 'wp_posts', 'wp_options', 'wp_unrecognized'] as $table) {
            self::assertSame($old->tableAction($table), $new->tableAction($table), $table);
            self::assertSame($old->tableMayIncludeRows($table), $new->tableMayIncludeRows($table), $table);
            foreach (['email', 'password', 'title', 'post_password'] as $field) {
                self::assertSame($old->fieldAction($table, $field), $new->fieldAction($table, $field));
                self::assertSame($old->fieldHasExplicitDecision($table, $field), $new->fieldHasExplicitDecision($table, $field));
                self::assertSame($old->shouldInspectField($table, $field, ['ID' => '1']), $new->shouldInspectField($table, $field, ['ID' => '1']));
            }
        }
        foreach (['wp-config.php', '.env', 'wp-admin/a.php', 'unknown.bin', 'wp-content/uploads/public/a.jpg', 'wp-content/uploads/private/b.jpg'] as $path) {
            self::assertSame($old->pathAction($path), $new->pathAction($path), $path);
        }
        $cases = [['wp_options', ['option_name' => 'auth_token']], ['wp_options', ['option_name' => 'blogdescription']],
            ['wp_posts', ['ID' => '1', 'post_type' => 'post', 'post_password' => '', 'post_status' => 'publish']],
            ['wp_posts', ['ID' => '2', 'post_type' => 'wpcf7_contact_form', 'post_password' => '', 'post_status' => 'publish']],
            ['wp_posts', ['ID' => '3']], ['wp_posts', ['ID' => '4', 'post_type' => 'flamingo_inbound']],
            ['wp_postmeta', ['post_id' => '3', 'meta_key' => 'ordinary']], ['wp_postmeta', ['post_id' => '1', 'meta_key' => 'token']]];
        foreach ($cases as [$table, $row]) {
            self::assertSame($old->includeRow($table, $row), $new->includeRow($table, $row));
            self::assertSame($old->isExplicitlyExcludedRow($table, $row), $new->isExplicitlyExcludedRow($table, $row));
        }
        self::assertSame($old->filterValue('wp_posts', self::column('email'), 'person@example.test', 4), $new->filterValue('wp_posts', self::column('email'), 'person@example.test', 4));
        self::assertNotEmpty($new->legacyRuleSummary());
        self::assertStringNotContainsString('person@example.test', json_encode($new->legacyRuleSummary()));
    }

    public function testOptionalFieldExclusionUsesNullOnlyWhenSafe(): void
    {
        $policy = new PrivacyPolicy(['version' => 2, 'preset' => 'custom', 'fields' => ['wp_users.email' => 'exclude']]);
        self::assertNull($policy->filterValue('wp_users', self::column('email'), 'private@example.test', 1));
        self::assertSame('original', $policy->filterValue('wp_users', self::column('user_pass', 'NO'), 'original', 1));
        foreach ([self::column('email', 'NO'), self::column('email', 'YES', 'PRI')] as $column) {
            try { $policy->filterValue('wp_users', $column, 'private@example.test', 1); self::fail('Unsafe field removal was accepted.'); }
            catch (RuntimeException $error) { self::assertStringContainsString('Exclude its row', $error->getMessage()); }
        }
    }

    public function testExplicitFieldExclusionRemainsMoreSpecificThanAnIncludedRow(): void
    {
        $policy = new PrivacyPolicy(['version' => 2, 'preset' => 'custom', 'categories' => ['wordpress_users' => 'exclude'],
            'rows' => ['wp_users' => ['ID' => ['7' => 'include']]], 'fields' => ['wp_users.email' => 'exclude']]);
        self::assertTrue($policy->includeRow('wp_users', ['ID' => '7']));
        self::assertNull($policy->filterValue('wp_users', self::column('email'), 'private@example.test', 7, ['ID' => '7']));
        self::assertSame('retained', $policy->filterValue('wp_users', self::column('user_pass', 'NO'), 'retained', 7, ['ID' => '7']));
    }

    public function testContradictoryRowsAreRejectedBeforeSaving(): void
    {
        $this->expectException(RuntimeException::class);
        new PrivacyPolicy(['version' => 2, 'preset' => 'custom', 'rows' => ['wp_users' => ['ID' => ['1' => 'include'], 'role' => ['editor' => 'exclude']]]]);
    }

    public function testCompletePresetRejectsExclusionsInsteadOfSilentlyIgnoringThem(): void
    {
        $this->expectException(RuntimeException::class);
        new PrivacyPolicy(['version' => 2, 'preset' => 'full', 'paths' => ['wp-config.php' => 'exclude']]);
    }

    public function testOptionalRulesRejectUnknownLegacySwitches(): void
    {
        $this->expectException(RuntimeException::class);
        new PrivacyPolicy(['version' => 2, 'preset' => 'custom', 'scanPublic' => false]);
    }

    private static function column(string $field, string $null = 'YES', string $key = ''): array
    {
        return ['Field' => $field, 'Type' => 'varchar(255)', 'Null' => $null, 'Key' => $key, 'Extra' => ''];
    }
}
