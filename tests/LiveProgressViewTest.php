<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\LiveAdminView;

/** The backup screen's progress panel: allow-listed fields only, and the hooks the script needs. Rendering only; no restore. */
final class LiveProgressViewTest extends TestCase
{
    private const SECRET_STATE = [
        'id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'snapshotId' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
        'status' => 'uploading', 'message' => '暗号化済みデータをR2へ転送できます。', 'completed' => 12, 'total' => 40, 'startedAt' => '2026-11-10T03:02:44Z',
        'captureGuard' => 'cccccccccccccccccccccccccccccccc', 'launchId' => 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd',
        'maintenanceOwner' => ['dev' => 1, 'ino' => 2, 'token' => 'maintenance-token-must-not-render', 'sha256' => 'eeee'],
        'sharing' => ['newBytes' => 1234, 'newPacks' => 2, 'reusedBytes' => 5678, 'reusedPacks' => 3, 'repackedBytes' => 0, 'repackedPacks' => 0, 'note' => '/secret/path'],
        'progress' => ['phase' => 'capture', 'files' => 10, 'fileBytes' => 2048, 'updatedAt' => '2026-11-10T03:10:00Z', 'id' => 'path-like/value', 'currentPath' => '/var/www/secret.php'],
        'measurements' => ['version' => 1, 'capture' => ['scanMs' => 600000, 'scanFiles' => 5, 'bad' => -1, 'name' => 'wp_secret_table', 'path/x' => 3],
            'transfer' => ['pages' => ['ms' => 1000, 'steps' => 2, 'objects' => 8, 'bytes' => 900], 'unknownKind' => ['ms' => 5]]],
    ];

    public function testPublicStateKeepsOnlyAllowListedFieldsAndIntegers(): void
    {
        $public = LiveAdminView::publicState(self::SECRET_STATE);
        self::assertSame(['status', 'message', 'completed', 'total', 'startedAt', 'snapshotId', 'sharing', 'progress', 'measurements'], array_keys($public));
        self::assertSame(['files' => 10, 'fileBytes' => 2048, 'phase' => 'capture', 'updatedAt' => '2026-11-10T03:10:00Z'], $public['progress']);
        self::assertSame(['scanMs' => 600000, 'scanFiles' => 5], $public['measurements']['capture']);
        self::assertSame(['pages' => ['ms' => 1000, 'steps' => 2, 'objects' => 8, 'bytes' => 900]], $public['measurements']['transfer']);
        self::assertArrayNotHasKey('note', $public['sharing']);
        $text = json_encode($public, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach (['cccc', 'dddd', 'maintenance-token', 'secret', 'path-like', 'wp_secret_table', 'eeee', 'aaaaaaaa'] as $forbidden) { self::assertStringNotContainsString($forbidden, $text); }
        self::assertNull(LiveAdminView::publicState(null));
        self::assertSame('online', LiveAdminView::publicState(['status'=>'capturing','consistencyMode'=>'online'])['consistencyMode']);
        self::assertArrayNotHasKey('consistencyMode', LiveAdminView::publicState(['status'=>'capturing','consistencyMode'=>'other']));
        self::assertNull(LiveAdminView::publicState(['status' => 'other']));
        $failed = LiveAdminView::publicState(['status' => 'failed', 'completed' => -3, 'total' => '9', 'startedAt' => 'yesterday', 'snapshotId' => 'short', 'measurements' => 'x', 'progress' => ['phase' => '/etc']]);
        self::assertSame(['status' => 'failed', 'message' => '', 'completed' => 0, 'total' => 0, 'progress' => []], $failed);
    }

    /** @dataProvider renderedStates */
    public function testBackupPanelRendersTheScriptHooksWithoutSecrets(?array $state, bool $busy, string $label): void
    {
        $html = $this->render($state, $busy);
        self::assertSame(in_array($state['status'] ?? null, ['capturing', 'uploading', 'failed'], true), LiveAdminView::cancellable(LiveAdminView::publicState($state)));
        $document = new \DOMDocument(); @$document->loadHTML('<?xml encoding="UTF-8">' . $html); $xpath = new \DOMXPath($document);
        self::assertSame(1, $xpath->query('//section[@id="wrb-progress"]//div[@id="wrb-live-status" and not(@role) and not(@aria-live)]')->length, 'the summary is redrawn often and is not itself a live region');
        self::assertSame(1, $xpath->query('//section[@id="wrb-progress"]//*[@id="wrb-live-announce" and @role="status" and @aria-live="polite" and @aria-atomic="true" and not(@tabindex)]')->length, 'one polite status region for changes');
        self::assertSame(1, $xpath->query('//section[@id="wrb-progress"]//*[@id="wrb-live-checked"]')->length);
        self::assertSame(1, $xpath->query('//section[@id="wrb-progress"]//*[@id="wrb-live-detail"]')->length);
        self::assertSame(1, $xpath->query('//button[@data-wrb-command="start" and @data-wrb-ready="0" and @disabled]')->length, 'not ready without settings');
        self::assertSame(1, $xpath->query('//fieldset[@id="wrb-manual-mode"]//input[@value="maintenance" and @checked]')->length);
        self::assertSame(1, $xpath->query('//fieldset[@id="wrb-manual-mode"]//input[@value="online" and not(@disabled)]')->length);
        self::assertSame(1, $xpath->query('//*[@data-wrb-online-confirm and @hidden]//input[@id="wrb-online-ack"]')->length);
        self::assertSame(1, $xpath->query('//section[@id="wrb-schedule"]//fieldset[@disabled]')->length, 'settings remain disabled without storage');
        self::assertStringContainsString('定期実行は未準備', $html);
        self::assertSame(1, $xpath->query('//section[@id="wrb-schedule"]//form[input[@name="command" and @value="schedule"]]//input[@name="_wrb_live_nonce"]')->length);
        self::assertStringContainsString('設定保存だけではサーバーの定期実行は登録されません', $html);
        $encoded = $xpath->query('//section[@id="wrb-progress"]')->item(0)->getAttribute('data-wrb-state');
        self::assertSame(LiveAdminView::publicState($state), json_decode($encoded, true, 16, JSON_THROW_ON_ERROR));
        foreach (['maintenance-token', 'cccccccc', 'dddddddd', 'secret'] as $forbidden) { self::assertStringNotContainsString($forbidden, $html); }
        self::assertStringContainsString($label, $html);
        self::assertStringNotContainsString('%', strip_tags($html), 'no percentage is shown');
        $cancellable = LiveAdminView::cancellable(LiveAdminView::publicState($state));
        self::assertSame(1, $xpath->query('//div[@data-wrb-cancel]//form[input[@name="command" and @value="cancel"]]//input[@type="submit"]')->length, 'always rendered for the script');
        self::assertSame($cancellable ? 0 : 1, $xpath->query('//div[@data-wrb-cancel and @hidden]')->length);
        self::assertSame($cancellable ? 0 : 1, $xpath->query('//div[@data-wrb-cancel]//input[@type="submit" and @disabled]')->length);
    }

    public function renderedStates(): array
    {
        return ['no run yet' => [null, false, 'まだ実行していません。'], 'uploading' => [self::SECRET_STATE, true, '状態 転送・照合中　転送 12 / 40 項目'],
            'failed' => [['status' => 'failed', 'message' => '停止しました。', 'completed' => 3, 'total' => 40], false, '状態 失敗'],
            'complete' => [['status' => 'complete', 'message' => '完了しました。', 'completed' => 41, 'total' => 41] + self::SECRET_STATE, false, '状態 完了']];
    }

    public function testScheduleFormDisplaysSavedValuesWithoutClaimingCronIsReady(): void
    {
        $schedule = ['enabled' => true, 'frequency' => 'weekly', 'time' => '04:35', 'weekday' => 3, 'mode' => 'online'];
        $html = $this->render(null, false, $schedule, ['connectionVerified' => true]);
        $document = new \DOMDocument(); @$document->loadHTML('<?xml encoding="UTF-8">' . $html); $xpath = new \DOMXPath($document);
        self::assertSame(1, $xpath->query('//input[@name="scheduleEnabled" and @checked]')->length);
        self::assertSame(1, $xpath->query('//select[@name="scheduleFrequency"]/option[@value="weekly" and @selected]')->length);
        self::assertSame(1, $xpath->query('//select[@name="scheduleWeekday"]/option[@value="3" and @selected]')->length);
        self::assertSame(1, $xpath->query('//input[@name="scheduleTime" and @value="04:35"]')->length);
        self::assertSame(1, $xpath->query('//input[@name="scheduleMode" and @value="online" and @checked]')->length);
        self::assertStringContainsString('設定保存だけではサーバーの定期実行は登録されません', $html);
        self::assertStringContainsString('次回の予定: 未確定', $html);
    }

    public function testRunnerHeartbeatAndUnavailableAttemptAreDescribedWithoutImplyingExecution(): void
    {
        $runner = ['lastSeen' => '2026-11-10T18:50:00Z', 'lastAttempt' => '2026-11-10T18:49:00Z', 'lastResult' => 'unavailable', 'message' => 'sensitive detail'];
        $html = $this->render(null, false, null, ['connectionVerified' => true], $runner);
        self::assertStringContainsString('2026-11-10T18:50:00Z', $html);
        self::assertStringContainsString('2026-11-10T18:49:00Z', $html);
        self::assertStringContainsString('自動取得は実行されませんでした', $html);
        self::assertStringNotContainsString('sensitive detail', $html);
        self::assertStringContainsString('起動確認だけでは定期実行の登録は確認できません', $html);
    }

    public function testStartedScheduleIsNotPresentedAsCompletedBackup(): void
    {
        $runner = ['lastAttempt'=>'2026-11-10T18:49:00Z','lastResult'=>'started'];
        $html = $this->render(null, false, null, ['connectionVerified'=>true], $runner);
        self::assertStringContainsString('起動依頼を受け付けました', $html);
        self::assertStringContainsString('バックアップの完了は別途確認してください', $html);
    }

    public function testHourlyScheduleKeepsItsSelectionAndExplainsMinute(): void
    {
        $schedule = ['enabled' => true, 'frequency' => 'hourly', 'time' => '03:15', 'weekday' => null, 'mode' => 'online'];
        $html = $this->render(null, false, $schedule, ['connectionVerified' => true]);
        $document = new \DOMDocument(); @$document->loadHTML('<?xml encoding="UTF-8">' . $html); $xpath = new \DOMXPath($document);
        self::assertSame(1, $xpath->query('//select[@name="scheduleFrequency"]/option[@value="hourly" and @selected]')->length);
        self::assertStringContainsString('毎時の場合は分だけを使用', $html);
    }

    public function testMissedSlotIsShownOnlyForTheCurrentScheduleRevision(): void
    {
        $revision = str_repeat('a', 64);
        $schedule = ['enabled' => true, 'frequency' => 'daily', 'time' => '03:00', 'weekday' => null, 'mode' => 'online', 'revision' => $revision];
        $runner = ['lastMissedSlot' => '2026-11-10T18:00:00Z', 'lastMissedRevision' => $revision];
        $html = $this->render(null, false, $schedule, ['connectionVerified' => true], $runner);
        self::assertStringContainsString('予定時刻を過ぎましたが実行を確認できません', $html);
        $runner['lastMissedRevision'] = str_repeat('b', 64);
        $html = $this->render(null, false, $schedule, ['connectionVerified' => true], $runner);
        self::assertStringNotContainsString('予定時刻を過ぎましたが実行を確認できません', $html);
    }

    public function testOnlineModeIsVisibleBeforeJavascriptRuns(): void
    {
        $html = $this->render(['status'=>'capturing','consistencyMode'=>'online'], true);
        self::assertStringContainsString('取得方法: 保守なし', $html);
    }

    private function render(?array $state, bool $busy, ?array $schedule = null, ?array $settings = null, array $runner = []): string
    {
        $script = <<<'SCRIPT'
namespace WordPressR2Backup;
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_attr($value) { return esc_html($value); }
function esc_url($value) { return esc_html($value); }
function admin_url($path) { return '/wp-admin/' . $path; }
function add_query_arg($key, $value, $url = null) { return (is_array($key) ? $value : $url) . '?' . http_build_query(is_array($key) ? $key : [$key => $value]); }
function wp_nonce_field($action, $name, $referer) { echo '<input type="hidden" name="' . esc_attr($name) . '" value="fixture">'; }
function submit_button($label, $type, $name, $wrap, $other = []) { $attributes = ''; foreach ((array) $other as $key => $value) { $attributes .= ' ' . $key . '="' . esc_attr($value) . '"'; } echo '<input type="submit" value="' . esc_attr($label) . '"' . $attributes . '>'; }
function get_option($name, $default = null) { return $name === 'wrb_live_schedule' ? SCHEDULE : ($name === 'wrb_live_schedule_runner' ? RUNNER : $default); }
function get_current_user_id() { return 1; }
function get_transient($key) { return false; }
require AUTOLOAD;
$_POST = []; $_GET = [];
$render = new \ReflectionMethod(LiveAdminView::class, 'backup'); $render->setAccessible(true);
$input = json_decode(STATE, true);
ob_start(); $render->invoke(null, SETTINGS, $input, BUSY); echo ob_get_clean();
SCRIPT;
        $script = str_replace(['AUTOLOAD', 'STATE', 'BUSY', 'SCHEDULE', 'SETTINGS', 'RUNNER'], [var_export(dirname(__DIR__) . '/vendor/autoload.php', true), var_export(json_encode($state, JSON_THROW_ON_ERROR), true), $busy ? 'true' : 'false', var_export($schedule, true), var_export($settings, true), var_export($runner, true)], $script);
        $process = proc_open([PHP_BINARY, '-r', $script], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
        $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
        self::assertSame(0, proc_close($process), $output);
        return $output;
    }
}
