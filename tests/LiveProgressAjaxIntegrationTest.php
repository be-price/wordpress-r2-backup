<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;

/** Isolated WordPress authorization and read-only status response; no restore or external service. */
final class LiveProgressAjaxIntegrationTest extends TestCase
{
    private ?IsolatedMysql $database = null;
    private ?IsolatedWordPress $wordpress = null;

    protected function tearDown(): void
    {
        if ($this->wordpress !== null) { $this->wordpress->stop(); }
        if ($this->database !== null) { $this->database->stop(); }
        Fixture::cleanup();
    }

    public function testStatusRequiresAuthorityAndDoesNotExposeInternalGuards(): void
    {
        if (getenv('WRB_RUN_V4_MYSQL_INTEGRATION') !== '1') { self::markTestSkipped('Isolated WordPress integration is not enabled.'); }
        $this->database = IsolatedMysql::start(null, 'DISABLED');
        $this->wordpress = IsolatedWordPress::start($this->database);
        $keys = Fixture::keys(); $script = Fixture::temp() . '/setup.php';
        $code = '<?php require ' . var_export($this->wordpress->root() . '/wp-load.php', true) . ';'
            . '$s=WordPressR2Backup\\LiveSettings::validate(' . var_export(['accountId'=>str_repeat('a',32),'bucket'=>'backup-test','accessKeyId'=>str_repeat('B',32),'secretAccessKey'=>str_repeat('c',64),'recipients'=>array_column($keys, 'public'),'retentionDays'=>30], true) . ');'
            . 'WordPressR2Backup\\LiveSettings::save($s); update_option("wrb_live_operation", ["status"=>"complete","message"=>"done","completed"=>2,"total"=>2,"captureGuard"=>"SECRET_GUARD","launchId"=>"SECRET_LAUNCH","maintenanceOwner"=>["token"=>"SECRET_OWNER"]], false);';
        file_put_contents($script, $code); chmod($script, 0600);
        $process = proc_open([PHP_BINARY, $script], [0=>['file','/dev/null','r'], 1=>['pipe','w'], 2=>['pipe','w']], $pipes);
        self::assertIsResource($process); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $out . $err);
        $page = $this->wordpress->request('/wp-admin/tools.php?page=wrb-synthetic-backup');
        self::assertSame(200, $page['status']);
        self::assertMatchesRegularExpression('/var wrbLive\s*=\s*(\{[^;]+\});/', $page['body']);
        preg_match('/var wrbLive\s*=\s*(\{[^;]+\});/', $page['body'], $match);
        $nonce = json_decode($match[1], true, 16, JSON_THROW_ON_ERROR)['nonce'];
        $fields = ['action'=>'wrb_live','command'=>'state','nonce'=>$nonce];
        self::assertSame(403, $this->wordpress->request('/wp-admin/admin-ajax.php', $fields, 'reader')['status']);
        self::assertSame(403, $this->wordpress->request('/wp-admin/admin-ajax.php', ['action'=>'wrb_live','command'=>'state','nonce'=>'invalid'])['status']);
        $response = $this->wordpress->request('/wp-admin/admin-ajax.php', $fields);
        self::assertSame(200, $response['status']);
        $result = json_decode($response['body'], true, 16, JSON_THROW_ON_ERROR);
        self::assertTrue($result['success']); self::assertSame('complete', $result['data']['status']);
        self::assertStringNotContainsString('SECRET_', $response['body']);
        foreach (['captureGuard','launchId','maintenanceOwner'] as $key) { self::assertArrayNotHasKey($key, $result['data']); }
        $online = ['action'=>'wrb_live','command'=>'start','mode'=>'online','onlineAck'=>'1','nonce'=>$nonce];
        self::assertSame(403, $this->wordpress->request('/wp-admin/admin-ajax.php', $online, 'reader')['status']);
        $invalidNonce = $online; $invalidNonce['nonce'] = 'invalid';
        self::assertSame(403, $this->wordpress->request('/wp-admin/admin-ajax.php', $invalidNonce)['status']);
        $online['onlineAck'] = '0'; $response = $this->wordpress->request('/wp-admin/admin-ajax.php', $online);
        $result = json_decode($response['body'], true, 16, JSON_THROW_ON_ERROR);
        self::assertFalse($result['success']); self::assertStringContainsString('注意点', $result['data']['message']);
    }

    public function testHistoryNoticeAndExactDetailsFollowAuthorizationAndFrozenSettings(): void
    {
        if (getenv('WRB_RUN_V4_MYSQL_INTEGRATION') !== '1') { self::markTestSkipped('Isolated WordPress integration is not enabled.'); }
        $this->database = IsolatedMysql::start(null, 'DISABLED');
        $this->wordpress = IsolatedWordPress::start($this->database);
        $script = Fixture::temp() . '/setup-history.php';
        $successful = str_repeat('a', 64);
        $failed = str_repeat('b', 64);
        $scheduleRevision = str_repeat('c', 64);
        $settings = ['bucket' => 'previous-backup-bucket', 'retentionDays' => 30, 'secretAccessKey' => 'SECRET_R2_KEY',
            'policy' => ['version' => 2, 'preset' => 'custom', 'paths' => ['PRIVATE_EXCLUSION_RULE' => 'exclude']]];
        $schedule = ['enabled' => true, 'frequency' => 'weekly', 'time' => '09:15', 'weekday' => 7,
            'mode' => 'online', 'revision' => $scheduleRevision, 'slot' => '2026-11-17T00:15:00Z', 'timezone' => 'Asia/Tokyo'];
        $scriptBody = <<<'PHP'
update_option('timezone_string', 'Asia/Tokyo');
$history = new WordPressR2Backup\BackupHistory(WordPressR2Backup\LiveSettings::workspace());
$completedState = ['id' => SUCCESSFUL, 'startedAt' => '2026-11-17T00:15:16Z', 'completedAt' => '2026-11-17T01:00:15Z',
    'snapshotId' => str_repeat('d', 64), 'completed' => 2, 'total' => 2];
$history->start($completedState, SETTINGS, 'online', SCHEDULE);
$history->record($completedState, 'complete', 123456);
$failedState = ['id' => FAILED, 'status' => 'failed', 'message' => 'SECRET_FAILURE_DETAILS'];
$history->start($failedState, SETTINGS, 'maintenance');
$history->record($failedState, 'capture_path_review');
$history->scheduleOutcome('2026-11-17T01:15:00Z', SCHEDULE, null, 'missed');
update_option('wrb_live_operation', $failedState, false);
update_option('wrb_live_notice', 'OLD_GLOBAL_OPERATION_MESSAGE', false);
echo WordPressR2Backup\BackupHistory::settingsSnapshot(SETTINGS, 'online', SCHEDULE)['revision'];
PHP;
        $code = '<?php require ' . var_export($this->wordpress->root() . '/wp-load.php', true) . ';';
        foreach (['SUCCESSFUL' => $successful, 'FAILED' => $failed, 'SETTINGS' => $settings, 'SCHEDULE' => $schedule] as $key => $value) {
            $code .= 'define(' . var_export($key, true) . ',' . var_export($value, true) . ');';
        }
        file_put_contents($script, $code . $scriptBody); chmod($script, 0600);
        $process = proc_open([PHP_BINARY, $script], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $revision = trim(stream_get_contents($pipes[1])); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $revision);

        $admin = '/wp-admin/admin.php?page=wrb-synthetic-backup&wrb_view=';
        $page = $this->wordpress->request($admin . 'settings');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('data-run-id="' . $failed . '" data-outcome="failed"', $page['body']);
        self::assertStringNotContainsString('OLD_GLOBAL_OPERATION_MESSAGE', $page['body']);
        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">' . $page['body']);
        $links = (new \DOMXPath($document))->query('//a[@data-wrb-history-link]');
        self::assertSame(1, $links->length);
        parse_str((string) parse_url($links->item(0)->getAttribute('href'), PHP_URL_QUERY), $link);
        self::assertSame('history', $link['wrb_view']);
        self::assertSame($failed, $link['wrb_run']);
        $formDocument = new \DOMDocument();
        @$formDocument->loadHTML('<?xml encoding="UTF-8">' . $this->wordpress->request($admin . 'privacy')['body']);
        $form = (new \DOMXPath($formDocument))->query('//input[@name="_wrb_live_nonce"]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $form);
        $post = $this->wordpress->request('/wp-admin/admin-post.php', ['action' => 'wrb_live', 'command' => 'invalid',
            'wrb_view' => 'settings', '_wrb_live_nonce' => $form->getAttribute('value')]);
        self::assertSame(303, $post['status']);
        $location = $post['headers']['location'];
        self::assertStringNotContainsString('先にストレージ設定を保存してください。', $location);
        $redirect = parse_url($location, PHP_URL_PATH) . '?' . parse_url($location, PHP_URL_QUERY);
        $message = 'エラー: 先にストレージ設定を保存してください。';
        self::assertStringNotContainsString($message, $this->wordpress->request($admin . 'settings')['body']);
        self::assertStringContainsString($message, $this->wordpress->request($redirect)['body']);
        self::assertStringNotContainsString($message, $this->wordpress->request($redirect)['body']);
        self::assertMatchesRegularExpression('/var wrbLive\s*=\s*(\{[^;]+\});/', $page['body']);
        preg_match('/var wrbLive\s*=\s*(\{[^;]+\});/', $page['body'], $match);
        $nonce = json_decode($match[1], true, 16, JSON_THROW_ON_ERROR)['nonce'];

        $ajax = '/wp-admin/admin-ajax.php';
        $fields = ['action' => 'wrb_live', 'command' => 'history', 'nonce' => $nonce];
        self::assertSame(403, $this->wordpress->request($ajax, $fields, 'reader')['status']);
        self::assertSame(403, $this->wordpress->request($ajax, array_replace($fields, ['nonce' => 'invalid']))['status']);
        $result = $this->wordpress->request($ajax, $fields);
        self::assertSame(200, $result['status']);
        $notice = json_decode($result['body'], true, 16, JSON_THROW_ON_ERROR)['data'];
        self::assertSame($failed, $notice['id']);
        self::assertSame('failed', $notice['status']);
        self::assertStringNotContainsString('SECRET_', $result['body']);

        $history = $admin . 'history';
        self::assertSame(403, $this->wordpress->request($history . '&wrb_run=' . $successful, null, 'reader')['status']);
        $page = $this->wordpress->request($history . '&wrb_source=scheduled&wrb_outcome=complete&wrb_revision=' . $revision . '&wrb_run=' . $successful . '&wrb_detail=settings');
        self::assertSame(200, $page['status']);
        $html = $page['body'];
        self::assertStringContainsString('data-wrb-run-link', $html);
        self::assertStringContainsString('datetime="2026-11-17T09:15:00+09:00"', $html);
        self::assertStringContainsString('毎週 日曜日 09:15（Asia/Tokyo）', $html);
        self::assertStringContainsString('previous-backup-bucket', $html);
        self::assertStringContainsString('R2の照合まで完了', $html);
        self::assertStringContainsString('1件の記録', $html);
        self::assertStringNotContainsString('SECRET_', $html);
        self::assertStringNotContainsString('PRIVATE_EXCLUSION_RULE', $html);

        $page = $this->wordpress->request($history . '&wrb_run=' . $failed);
        self::assertStringContainsString('保存範囲と除外設定を開く', $page['body']);
        self::assertStringNotContainsString('R2保存物の総容量', $page['body']);
        $page = $this->wordpress->request($history . '&wrb_outcome=skipped&wrb_schedule_revision=' . $scheduleRevision . '&wrb_detail=settings');
        self::assertStringContainsString('1件の記録', $page['body']);
        self::assertStringContainsString('定期設定の版', $page['body']);
        self::assertStringNotContainsString('previous-backup-bucket', $page['body']);
    }
}
