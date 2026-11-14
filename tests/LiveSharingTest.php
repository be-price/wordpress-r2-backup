<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\LiveAdmin;
use WordPressR2Backup\LiveSettings;

final class LiveSharingTest extends TestCase
{
    protected function tearDown(): void { Fixture::cleanup(); }

    public function testSharingIsExplicitAndUnrelatedConfigurationEditsPreserveIt(): void
    {
        $data = ['accountId' => str_repeat('a', 32), 'bucket' => 'site-backups', 'accessKeyId' => str_repeat('b', 32),
            'secretAccessKey' => str_repeat('c', 64), 'recipients' => array_column(Fixture::keys(), 'public'), 'retentionDays' => 30];
        $original = LiveSettings::validate($data);
        self::assertArrayNotHasKey('sharing', $original);
        $original['proofs'] = [];
        self::assertSame($original, LiveSettings::validate($data, $original));
        $data['sharing'] = ['enabled' => true, 'directory' => '/private-owner/backup-index'];
        $enabled = LiveSettings::validate($data, $original);
        self::assertSame($data['sharing'], $enabled['sharing']);
        $changed = array_diff_key($data, ['sharing' => true]);
        $changed['retentionDays'] = 90;
        self::assertSame($enabled['sharing'], LiveSettings::validate($changed, $enabled)['sharing']);
        $data['sharing']['enabled'] = false;
        $disabled = LiveSettings::validate($data, $enabled);
        self::assertSame(['enabled' => false, 'directory' => '/private-owner/backup-index'], $disabled['sharing']);
        unset($original['sharing'], $disabled['sharing']);
        self::assertSame($original, $disabled);
        self::assertSame(['enabled' => false, 'directory' => ''], LiveSettings::validateSharing(['enabled' => false, 'directory' => '']));
    }

    /** @dataProvider invalidSharing */
    public function testInvalidSharingIsRejected($sharing): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LiveSettings::validateSharing($sharing);
    }

    public function invalidSharing(): array
    {
        return [[null], [[]], [['enabled' => true]], [['enabled' => '1', 'directory' => '/private/index']],
            [['enabled' => 1, 'directory' => '/private/index']], [['enabled' => true, 'directory' => ['bad']]],
            [['enabled' => true, 'directory' => '']], [['enabled' => false, 'directory' => 'relative/path']],
            [['enabled' => true, 'directory' => '/private/../index']], [['enabled' => true, 'directory' => '/private/./index']],
            [['enabled' => true, 'directory' => "/private/index\n"]], [['enabled' => true, 'directory' => "/private/\0index"]],
            [['enabled' => true, 'directory' => '/' . str_repeat('x', 4096)]],
            [['enabled' => true, 'directory' => '/private/index', 'unexpected' => true]]];
    }

    /** @dataProvider incompleteStates */
    public function testSharingCannotChangeWhileBackupIsIncomplete(string $status): void
    {
        $this->expectException(\RuntimeException::class);
        LiveAdmin::assertSettingsEditable('sharing', ['status' => $status]);
    }

    /** @return array{0:string,1:bool} message-or-path and whether the index directory exists afterwards */
    private function automatic(string $home, string $contentDir): array
    {
        $script = 'namespace WordPressR2Backup; require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ';
$_SERVER["DOCUMENT_ROOT"] = ' . var_export($home . '/../docroot', true) . '; define("ABSPATH", ' . var_export($home . '/../site/', true) . '); define("WP_CONTENT_DIR", ' . var_export($contentDir, true) . ');
try { echo LiveSettings::automaticSharingDirectory(' . var_export($home, true) . '); } catch (\\RuntimeException $e) { echo $e->getMessage(); }';
        $process = proc_open([PHP_BINARY, '-r', $script], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
        $output = stream_get_contents($pipes[1]); fclose($pipes[1]); proc_close($process);
        return [$output, file_exists($home . '/.wordpress-r2-backup-index')];
    }

    public function testAutomaticDirectoryChecksOverlapBeforeCreatingAndSucceedsWhenSafe(): void
    {
        $base = dirname(__DIR__) . '/.sharing-home-' . bin2hex(random_bytes(4));
        $home = $base . '/home';
        foreach ([$base, $home, $base . '/site', $base . '/docroot', $base . '/content'] as $dir) { mkdir($dir, 0700); }
        $home = realpath($home);
        try {
            [$overlap, $created] = $this->automatic($home, $home);
            self::assertStringContainsString('保存元のルートと重なります', $overlap);
            self::assertFalse($created, 'overlap must be rejected before the directory is created');
            [$inDocroot, $created] = $this->automatic($home, $base . '/content');
            self::assertSame($home . '/.wordpress-r2-backup-index', $inDocroot);
            self::assertTrue($created);
            self::assertSame(0700, fileperms($inDocroot) & 0777);
            rmdir($inDocroot);
            // DOCUMENT_ROOT が home を含む構成では、作成前に停止する。
            $script = 'namespace WordPressR2Backup; require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ';
$_SERVER["DOCUMENT_ROOT"] = ' . var_export($home, true) . '; define("ABSPATH", ' . var_export($base . '/site/', true) . ');
try { echo LiveSettings::automaticSharingDirectory(' . var_export($home, true) . '); } catch (\\RuntimeException $e) { echo $e->getMessage(); }';
            $process = proc_open([PHP_BINARY, '-r', $script], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
            $output = stream_get_contents($pipes[1]); fclose($pipes[1]); proc_close($process);
            self::assertStringContainsString('ホームが公開領域', $output);
            self::assertFalse(file_exists($home . '/.wordpress-r2-backup-index'));
        } finally {
            foreach (['/home/.wordpress-r2-backup-index', '/home', '/site', '/docroot', '/content', ''] as $suffix) { @rmdir($base . $suffix); }
        }
    }

    public function incompleteStates(): array { return [['queued'], ['capturing'], ['uploading'], ['failed']]; }

    public function testKeyConfirmationAndConnectionTestCannotChangeSettingsDuringCapture(): void
    {
        foreach (['test', 'issue', 'confirm'] as $command) {
            $error = null; try { LiveAdmin::assertSettingsEditable($command, ['status'=>'capturing']); } catch (\RuntimeException $caught) { $error=$caught; }
            self::assertInstanceOf(\RuntimeException::class, $error, $command);
        }
    }

    public function testSettingsRenderExplicitOptInPrivatePathAndCurrentSharingTotals(): void
    {
        $script = <<<'SCRIPT'
namespace WordPressR2Backup;
function wp_unslash($value) { return stripslashes($value); }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_attr($value) { return esc_html($value); }
function esc_url($value) { return esc_html($value); }
function admin_url($path) { return '/wp-admin/' . $path; }
function add_query_arg($key, $value, $url = null) { return (is_array($key) ? $value : $url) . '?' . http_build_query(is_array($key) ? $key : [$key => $value]); }
function wp_nonce_field($action, $name, $referer) { echo '<input type="hidden" name="' . esc_attr($name) . '" value="fixture">'; }
function submit_button($label, $type, $name, $wrap) { echo '<button type="submit">' . esc_html($label) . '</button>'; }
function get_option($name, $default = null) { return $default; }
function get_current_user_id() { return 1; }
function get_transient($key) { return false; }
require AUTOLOAD;
$_POST = []; $_GET = [];
$settings = ['retentionDays' => 30, 'secretAccessKey' => 'must-not-render'];
$render = new \ReflectionMethod(LiveAdminView::class, 'settings'); $render->setAccessible(true);
ob_start(); $render->invoke(null, $settings, false); $default = ob_get_clean();
$settings['sharing'] = ['enabled' => true, 'directory' => '/private-owner/a"&b'];
ob_start(); $render->invoke(null, $settings, true); $busy = ob_get_clean();
$render = new \ReflectionMethod(LiveAdminView::class, 'backup'); $render->setAccessible(true);
ob_start(); $render->invoke(null, null, ['status' => 'complete', 'message' => 'complete', 'sharing' => ['newBytes' => 1234, 'newPacks' => 2, 'reusedBytes' => 5678, 'reusedPacks' => 3, 'repackedBytes' => 100, 'repackedPacks' => 1]], false); $backup = ob_get_clean();
$parse = new \ReflectionMethod(LiveAdmin::class, 'sharingInput'); $parse->setAccessible(true);
$_POST = ['sharingDirectory' => '']; $disabled = $parse->invoke(null);
$_POST = ['sharingEnabled' => '1', 'sharingDirectory' => '/private-owner/index']; $enabled = $parse->invoke(null);
echo json_encode(compact('default', 'busy', 'backup', 'disabled', 'enabled'), JSON_THROW_ON_ERROR);
SCRIPT;
        $script = str_replace('AUTOLOAD', var_export(dirname(__DIR__) . '/vendor/autoload.php', true), $script);
        $process = proc_open([PHP_BINARY, '-r', $script], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
        $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
        self::assertSame(0, proc_close($process), $output);
        $result = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
        $document = new \DOMDocument(); @$document->loadHTML('<?xml encoding="UTF-8">' . $result['default']); $xpath = new \DOMXPath($document);
        self::assertSame(1, $xpath->query('//form[input[@name="command" and @value="sharing"]][@method="post"]')->length);
        self::assertSame(1, $xpath->query('//input[@name="sharingEnabled" and @type="checkbox" and not(@checked)]')->length);
        self::assertSame(1, $xpath->query('//label[@for="wrb-sharing-directory"]')->length);
        self::assertSame(1, $xpath->query('//input[@name="sharingDirectory" and @aria-describedby="wrb-sharing-help"]')->length);
        self::assertSame(1, $xpath->query('//details[not(@open)][summary]//input[@name="sharingDirectory"]')->length);
        self::assertSame(1, $xpath->query('//table[contains(@class,"wrb-glossary")]')->length);
        self::assertStringNotContainsString('must-not-render', $result['default'] . $result['busy']);
        @$document->loadHTML('<?xml encoding="UTF-8">' . $result['busy']); $xpath = new \DOMXPath($document);
        self::assertSame(1, $xpath->query('//form[input[@name="command" and @value="sharing"]]/fieldset[@disabled]')->length);
        self::assertSame(1, $xpath->query('//input[@name="sharingEnabled" and @checked]')->length);
        self::assertSame('/private-owner/a"&b', $xpath->query('//input[@name="sharingDirectory"]')->item(0)->getAttribute('value'));
        self::assertStringContainsString('1,234 バイト / 2 パック', $result['backup']);
        self::assertStringContainsString('5,678 バイト / 3 パック', $result['backup']);
        self::assertStringContainsString('100 バイト / 1 パック', $result['backup']);
        self::assertSame(['enabled' => false, 'directory' => ''], $result['disabled']);
        self::assertSame(['enabled' => true, 'directory' => '/private-owner/index'], $result['enabled']);
    }
}
