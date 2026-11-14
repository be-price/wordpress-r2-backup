<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\LiveSettings;
use WordPressR2Backup\LiveAdmin;
use WordPressR2Backup\PrivacyPolicy;

final class LivePrivacyUiTest extends TestCase
{
    public function testViewRoutingAcceptsOnlyTheFiveScreens(): void
    {
        $previousGet = $_GET; $previousPost = $_POST;
        try {
            $_POST = [];
            foreach (['backup', 'restore', 'storage', 'privacy', 'settings'] as $view) {
                $_GET = ['wrb_view' => $view];
                self::assertSame($view, LiveAdmin::view());
            }
            foreach (['other', ['privacy'], ''] as $view) {
                $_GET = ['wrb_view' => $view];
                self::assertSame('backup', LiveAdmin::view());
            }
        } finally { $_GET = $previousGet; $_POST = $previousPost; }
    }

    public function testPrivacyCanBeConfiguredBeforeStorageAndKeepsTheExistingDefaults(): void
    {
        $policy = LiveSettings::privacyPolicy(null);
        self::assertSame('full', $policy['preset']);
        self::assertSame(2, $policy['version']);
        $draft = LiveSettings::applyPrivacyInput($policy, ['category_forms' => 'exclude']);
        self::assertSame($draft, LiveSettings::privacyPolicy(null, $draft));
        self::assertSame('custom', $draft['preset']);
        self::assertSame('exclude', $draft['categories']['forms']);
    }

    public function testEditingPrivacyPreservesCredentialsVerificationKeysAndRetention(): void
    {
        $settings = ['accountId' => 'account', 'secretAccessKey' => 'secret', 'recipients' => ['first', 'second'],
            'proofs' => [['confirmed' => true], ['confirmed' => true]], 'connectionVerified' => true,
            'connectionDigest' => 'digest', 'unitId' => 'unit', 'retentionDays' => 90,
            'policy' => LiveSettings::privacyPolicy(null)];
        $policy = LiveSettings::applyPrivacyInput($settings['policy'], ['category_wordpress_users' => 'exclude']);
        $updated = LiveSettings::withPolicy($settings, $policy);
        self::assertSame($policy, $updated['policy']);
        unset($settings['policy'], $updated['policy']);
        self::assertSame($settings, $updated);
    }

    public function testTypedRulesAreAddedAndCanBeReturnedToTheDefault(): void
    {
        $policy = LiveSettings::privacyPolicy(null);
        foreach ([
            ['ruleType' => 'table', 'tableName' => 'wp_contact_forms', 'ruleAction' => 'exclude'],
            ['ruleType' => 'field', 'tableName' => 'wp_posts', 'fieldName' => 'post_content', 'ruleAction' => 'exclude'],
            ['ruleType' => 'path', 'path' => 'wp-content/uploads/private', 'ruleAction' => 'exclude'],
        ] as $input) { $policy = LiveSettings::applyPrivacyInput($policy, $input + ['ruleIntent' => 'add']); }
        self::assertSame(['wp_contact_forms' => 'exclude'], $policy['tables']);
        self::assertSame(['wp_posts.post_content' => 'exclude'], $policy['fields']);
        self::assertSame(['wp-content/uploads/private' => 'exclude'], $policy['paths']);
        $policy = LiveSettings::applyPrivacyInput($policy, ['decisions' => [base64_encode('field wp_posts.post_content') => '']]);
        self::assertSame([], $policy['fields']);
        self::assertSame('exclude', (new PrivacyPolicy($policy))->pathAction('wp-content/uploads/private/export.json')['action']);
    }

    /** @dataProvider invalidTypedRules */
    public function testTypedRulesRejectInvalidTargets(array $input): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LiveSettings::applyPrivacyInput(LiveSettings::privacyPolicy(null), $input + ['ruleIntent' => 'add', 'ruleAction' => 'exclude']);
    }

    public function invalidTypedRules(): array
    {
        return [[['ruleType' => 'table', 'tableName' => 'wp_posts; DROP TABLE wp_posts']],
            [['ruleType' => 'field', 'tableName' => 'wp_posts', 'fieldName' => 'email address']],
            [['ruleType' => 'path', 'path' => '../outside']], [['ruleType' => 'other', 'path' => 'safe']]];
    }

    public function testExplicitFileInclusionIsHonored(): void
    {
        $policy = LiveSettings::applyPrivacyInput(LiveSettings::privacyPolicy(null),
            ['ruleIntent' => 'add', 'ruleType' => 'path', 'path' => 'wp-config.php', 'ruleAction' => 'include']);
        self::assertSame('include', (new PrivacyPolicy($policy))->pathAction('wp-config.php')['action']);
    }

    public function testFullPresetClearsLegacyAndIgnoresOtherInputs(): void
    {
        $legacy = LiveSettings::validatePolicy(['excludedOptions' => ['old_option']]);
        $policy = LiveSettings::applyPrivacyInput($legacy, ['preset' => 'full', 'category_forms' => 'exclude',
            'rules' => 'table wp_posts exclude']);
        self::assertSame(LiveSettings::privacyPolicy(null), $policy);
        self::assertTrue((new PrivacyPolicy($policy))->includeRow('wp_options', ['option_name' => 'old_option']));
    }

    public function testConflictingDecisionsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LiveSettings::applyPrivacyInput(LiveSettings::privacyPolicy(null), ['preset' => 'custom',
            'decisions' => [base64_encode('table wp_posts') => 'include'], 'rules' => 'table wp_posts exclude']);
    }

    public function testPrivacyControllerAndFormConnectLegacyAndExactRules(): void
    {
        $script = <<<'SCRIPT'
    namespace WordPressR2Backup;
    function wp_unslash($value) { return is_array($value) ? array_map(__NAMESPACE__ . '\\wp_unslash', $value) : stripslashes($value); }
    function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
    function esc_attr($value) { return esc_html($value); }
    function esc_url($value) { return esc_html($value); }
    function checked($value, $expected = true, $echo = true) { return $value === $expected ? ' checked="checked"' : ''; }
    function selected($value, $expected, $echo = true) { return $value === $expected ? ' selected="selected"' : ''; }
    function admin_url($path) { return '/wp-admin/' . $path; }
    function add_query_arg($key, $value, $url = null) { return (is_array($key) ? $value : $url) . '?' . http_build_query(is_array($key) ? $key : [$key => $value]); }
    function wp_nonce_field($action, $name, $referer) { echo '<input type="hidden" name="' . esc_attr($name) . '" value="fixture">'; }
    function submit_button($label, $type, $name, $wrap) { echo '<button type="submit">' . esc_html($label) . '</button>'; }
    require AUTOLOAD;
    $_GET = []; $_POST = ['publicContentOnly' => '1', 'ruleIntent' => 'add', 'ruleType' => 'option', 'optionName' => 'plugin_configuration', 'ruleAction' => 'include', 'secretAccessKey' => 'ignored'];
    $parse = new \ReflectionMethod(LiveAdmin::class, 'privacyInput'); $parse->setAccessible(true); $input = $parse->invoke(null);
    $policy = LiveSettings::applyPrivacyInput(LiveSettings::validatePolicy(['excludedOptions' => ['plugin_configuration'], 'excludedRows' => ['wp_posts' => ['ID' => ['fixture_secret_row']]]]), $input);
    $render = new \ReflectionMethod(LiveAdminView::class, 'privacy'); $render->setAccessible(true);
    ob_start(); $render->invoke(null, ['policy' => $policy], false); $detail = ob_get_clean();
    $_GET = ['wrb_mode' => 'simple']; ob_start(); $render->invoke(null, ['policy' => $policy], true); $simple = ob_get_clean();
    echo json_encode(['input' => $input, 'policy' => $policy, 'detail' => $detail, 'simple' => $simple], JSON_THROW_ON_ERROR);
    SCRIPT;
        $script = str_replace('AUTOLOAD', var_export(dirname(__DIR__) . '/vendor/autoload.php', true), $script);
        $process = proc_open([PHP_BINARY, '-r', $script], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
        $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
        self::assertSame(0, proc_close($process), $output);
        $result = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('secretAccessKey', $result['input']);
        self::assertTrue($result['policy']['legacy']['publicContentOnly']);
        self::assertSame('include', $result['policy']['options']['plugin_configuration']);
        $document = new \DOMDocument(); @$document->loadHTML('<?xml encoding="UTF-8">' . $result['detail']); $xpath = new \DOMXPath($document);
        self::assertSame(3, $xpath->query('//input[@name="preset" and @type="radio"]')->length);
        self::assertSame(1, $xpath->query('//input[@name="clearLegacy" and @type="checkbox"]')->length);
        self::assertSame(1, $xpath->query('//input[@name="rowValue" and @maxlength="191"]')->length);
        self::assertSame(1, $xpath->query('//select[@name="decisions[' . base64_encode('row wp_posts.ID fixture_secret_row') . ']"]')->length);
        self::assertStringNotContainsString('fixture_secret_row', $document->textContent);
        self::assertStringContainsString('以前の設定を継承中', $document->textContent);
        self::assertStringContainsString('r0001/', $document->textContent);
        self::assertStringContainsString('<fieldset disabled>', $result['simple']);
        self::assertStringNotContainsString('name="optionName"', $result['simple']);
    }
}
