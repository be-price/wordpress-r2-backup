<?php
declare(strict_types=1);
namespace WordPressR2Backup;

final class LiveAdmin
{
    public static function register(string $pluginFile): void
    {
        add_action('admin_post_wrb_live', [self::class, 'handle']);
        add_action('wp_ajax_wrb_live', [self::class, 'ajax']);
        add_action('admin_enqueue_scripts', static function (string $hook) use ($pluginFile): void {
            if (!in_array($hook, ['tools_page_' . WordPressAdmin::PAGE, 'toplevel_page_' . WordPressAdmin::PAGE], true) || defined('WRB_SYNTHETIC_RUNTIME_PROFILE')) { return; }
            wp_enqueue_script('wrb-live', plugins_url('assets/live.js', $pluginFile), [], '0.6.2', true);
            wp_localize_script('wrb-live', 'wrbLive', ['url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('wrb_live')]);
        });
    }

    public static function render(): void
    {
        self::authorize();
        $settings = null;
        try { $settings = LiveSettings::load(); }
        catch (\Throwable $error) { echo '<div class="notice notice-error"><p>' . esc_html($error->getMessage()) . '</p></div>'; }
        $state = get_option('wrb_live_operation', null);
        $state = is_array($state) ? $state : null;
        $busy = $state !== null && in_array($state['status'] ?? '', ['queued', 'capturing', 'uploading', 'failed'], true);
        LiveAdminView::render($settings, $state, $busy);
    }

    public static function view(): string
    {
        $view = $_POST['wrb_view'] ?? $_GET['wrb_view'] ?? 'backup';
        return is_string($view) && in_array($view, ['backup', 'restore', 'storage', 'privacy', 'settings'], true) ? $view : 'backup';
    }

    private static function adminBase(): string
    {
        $posted = $_POST['wrb_admin_base'] ?? '';
        if ($posted === 'tools.php') { return 'tools.php'; }
        $current = basename((string) ($_SERVER['PHP_SELF'] ?? ''));
        return $current === 'tools.php' ? 'tools.php' : 'admin.php';
    }

    public static function url(string $view): string
    {
        if (!in_array($view, ['backup', 'restore', 'storage', 'privacy', 'settings'], true)) { $view = 'backup'; }
        return add_query_arg(['page' => WordPressAdmin::PAGE, 'wrb_view' => $view], admin_url(self::adminBase()));
    }

    public static function handle(): void
    {
        self::authorize();
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { wp_die('POSTで送信してください。', '', ['response' => 405]); }
        check_admin_referer('wrb_live', '_wrb_live_nonce');
        $command = isset($_POST['command']) && is_string($_POST['command']) ? $_POST['command'] : '';
        $query = [];
        try {
            $dispatch = static function () use ($command, &$query): void {
            // The mutable settings and job state must be authoritative after acquiring operation.lock.
            if (function_exists('wp_cache_delete')) { wp_cache_delete(LiveSettings::OPTION, 'options'); wp_cache_delete(LiveSettings::POLICY_DRAFT, 'options'); wp_cache_delete('wrb_live_operation', 'options'); wp_cache_delete('notoptions', 'options'); }
            $settings = LiveSettings::load();
            $state = get_option('wrb_live_operation', null);
            if ($state !== null && !is_array($state)) { throw new \RuntimeException('バックアップの実行状態を確認できません。'); }
            self::assertSettingsEditable($command, is_array($state) ? $state : null);
            if ($command === 'save') {
                $policy = LiveSettings::loadPolicy($settings);
                if (array_key_exists('rules', $_POST)) {
                    $policy = LiveSettings::applyPrivacyInput($policy, self::privacyInput());
                }
                $settings = LiveSettings::validate(['accountId' => self::post('accountId', 32), 'bucket' => self::post('bucket', 63), 'accessKeyId' => self::post('accessKeyId', 128), 'secretAccessKey' => self::post('secretAccessKey', 256),
                    'recipients' => [self::post('recipient_0', 128), self::post('recipient_1', 128)],
                    'retentionDays' => array_key_exists('retentionDays', $_POST) ? self::post('retentionDays', 4) : ($settings['retentionDays'] ?? 30), 'policy' => $policy], $settings);
                LiveSettings::save($settings); self::notice('接続設定を保存しました。接続と復号能力を確認してください。');
            } elseif ($command === 'privacy' || $command === 'rules') {
                $input = self::privacyInput();
                $rescan = $command === 'rules' || self::post('rescan', 1) === '1';
                LiveSettings::savePolicy($settings, LiveSettings::applyPrivacyInput(LiveSettings::loadPolicy($settings), $input));
                if ($rescan) { $query['wrb_scan'] = '1'; }
                self::notice('保存範囲と除外設定を保存しました。対象の深い照合はバックアップ開始時に行います。');
            } elseif ($command === 'scan') { $query['wrb_scan'] = '1'; }
            elseif ($settings === null) { throw new \RuntimeException('先にストレージ設定を保存してください。'); }
            elseif ($command === 'schedule') {
                $enabled = self::post('scheduleEnabled', 1); $weekday = self::post('scheduleWeekday', 1);
                if (!in_array($enabled, ['', '1'], true) || ($weekday !== '' && !preg_match('/\A[1-7]\z/D', $weekday))) { throw new \InvalidArgumentException('定期実行の設定が不正です。'); }
                $frequency = self::post('scheduleFrequency', 6);
                BackupScheduler::save(['enabled' => $enabled === '1', 'frequency' => $frequency,
                    'time' => self::post('scheduleTime', 5), 'weekday' => $frequency === 'weekly' && $weekday !== '' ? (int) $weekday : null,
                    'mode' => self::post('scheduleMode', 11)]);
                self::notice('定期実行の設定を保存しました。自動取得は準備中で、まだ実行されません。');
            }
            elseif ($command === 'retention') {
                $settings['retentionDays'] = self::post('retentionDays', 4);
                LiveSettings::save(LiveSettings::validate($settings, $settings)); self::notice('保存期間を保存しました。');
            } elseif ($command === 'sharing') {
                $settings['sharing'] = self::sharingInput();
                LiveSettings::save(LiveSettings::validate($settings, $settings)); self::notice('保存容量の節約設定を保存しました。次のバックアップから適用します。');
            } elseif ($command === 'test') {
                (new LiveBackup($settings))->connectionTest(); $settings['connectionVerified'] = true; LiveSettings::save($settings); self::notice('R2への保存・読み戻し・確認データの削除が成功しました。');
            } elseif ($command === 'issue') { self::issue($settings); self::notice('2名分の確認用暗号文を作成しました。手元で復号してください。'); }
            elseif ($command === 'cancel') { (new LiveBackup($settings))->cancel(); self::notice('未完了の実行を取り消しました。新しいバックアップを開始できます。'); }
            elseif ($command === 'delete') {
                if (self::post('confirmDelete', 1) !== '1') { throw new \RuntimeException('削除後は復旧できません。確認してから削除してください。'); }
                (new LiveBackup($settings))->delete(self::post('snapshot', 64));
                self::notice('指定した世代の削除を確認しました。他の世代が参照する共有データは保持しています。'); $query['wrb_list'] = '1';
            }
            elseif ($command === 'confirm') { self::confirm($settings); self::notice('公開鍵の復号能力を確認しました。'); }
            elseif ($command === 'download') { self::download($settings); }
            elseif ($command === 'recovery') { self::recovery($settings); }
            else { throw new \RuntimeException('不明な操作です。'); }
            };
            // schedule owns its schedule.lock -> operation.lock ordering; do not acquire in the reverse order.
            if (in_array($command, ['save', 'privacy', 'rules', 'retention', 'sharing', 'test', 'issue', 'confirm'], true)) { LiveBackup::withSettingsLock($dispatch); }
            else { $dispatch(); }
        } catch (\Throwable $error) { self::notice('エラー: ' . mb_substr($error->getMessage(), 0, 600)); }
        wp_safe_redirect(add_query_arg($query, self::url(self::view())), 303); exit;
    }

    public static function ajax(): void
    {
        self::authorize();
        check_ajax_referer('wrb_live', 'nonce');
        try {
            $command = self::post('command', 10);
            if ($command === 'media') {
                wp_send_json_success((new LiveMediaAudit())->step(self::post('run', 32), self::post('restart', 1) === '1'));
                return;
            }
            $settings = LiveSettings::load();
            if ($settings === null) { throw new \RuntimeException('先に設定を保存してください。'); }
            $backend = new LiveBackup($settings);
            if ($command === 'start') {
                $mode = self::startMode(['mode' => self::post('mode', 11), 'writers' => self::post('writers', 1), 'downtime' => self::post('downtime', 1), 'onlineAck' => self::post('onlineAck', 1)]);
                $runner = new LiveRunner($settings);
                $result = $mode === 'online' ? $runner->startOnline() : $runner->start();
            } elseif ($command === 'step') { $result = (new LiveRunner($settings))->resume(); }
            elseif ($command === 'state') { $result = (new LiveRunner($settings))->state(); }
            else { throw new \RuntimeException('不明な操作です。'); }
            wp_send_json_success(LiveAdminView::publicState($result));
        } catch (\Throwable $error) { wp_send_json_error(['message' => mb_substr($error->getMessage(), 0, 600)], 400); }
    }

    /** Missing mode is the pre-existing maintenance behavior. */
    public static function startMode(array $input): string
    {
        $mode = $input['mode'] ?? '';
        if ($mode === '') { $mode = 'maintenance'; }
        if ($mode === 'maintenance') {
            if (($input['writers'] ?? '') !== '1' || ($input['downtime'] ?? '') !== '1') { throw new \RuntimeException('書き手の停止と保守状態を確認してください。'); }
        } elseif ($mode === 'online') {
            if (($input['onlineAck'] ?? '') !== '1') { throw new \RuntimeException('保守なし取得の注意点を確認してください。'); }
        } else { throw new \RuntimeException('取得方式が不正です。'); }
        return $mode;
    }

    private static function issue(array $settings): void
    {
        $age = new AgeProcess(AgeBinary::path()); $directory = LiveSettings::workspace();
        $proofs = [];
        foreach ($settings['recipients'] as $recipient) {
            $code = bin2hex(random_bytes(32)); $file = $directory . '/challenge-' . bin2hex(random_bytes(16)) . '.age';
            $age->encryptBytes(json_encode(['purpose' => 'WordPress R2 Backup key confirmation', 'code' => $code], JSON_THROW_ON_ERROR), $file, [$recipient]);
            $proofs[] = ['file' => $file, 'sha256' => hash('sha256', $code), 'expiresAt' => time() + 900, 'attempts' => 0, 'confirmed' => false];
        }
        $settings['proofs'] = $proofs; LiveSettings::save($settings);
    }

    private static function confirm(array $settings): void
    {
        $slot = self::slot(); $proof = $settings['proofs'][$slot] ?? null;
        if (!is_array($proof) || $proof['expiresAt'] < time() || $proof['attempts'] >= 5 || $proof['confirmed']) { throw new \RuntimeException('確認の期限切れ、使用済み、または失敗上限です。確認用ファイルを作成し直してください。'); }
        $settings['proofs'][$slot]['attempts']++;
        $code = self::post('code', 64);
        $valid = preg_match('/\A[a-f0-9]{64}\z/D', $code) && hash_equals($proof['sha256'], hash('sha256', $code));
        if ($valid) { $settings['proofs'][$slot]['confirmed'] = true; }
        LiveSettings::save($settings);
        if (!$valid) { throw new \RuntimeException('確認コードが一致しません。'); }
    }

    private static function download(array $settings): void
    {
        $slot = self::slot(); $proof = $settings['proofs'][$slot] ?? null;
        if (!is_array($proof) || $proof['expiresAt'] < time()) { throw new \RuntimeException('確認用ファイルの期限が切れました。'); }
        $path = $proof['file'];
        if (!str_starts_with($path, LiveSettings::workspace() . '/challenge-')) { throw new \RuntimeException('確認用ファイルが不正です。'); }
        FileSafety::assertRegularFile($path); nocache_headers();
        header('Content-Type: application/octet-stream'); header('Content-Disposition: attachment; filename="wrb-key-challenge-' . ($slot + 1) . '.age"');
        readfile($path); exit;
    }

    private static function recovery(array $settings): void
    {
        $profile = ['format' => 'wrb-direct-r2-v1', 'version' => 1] + array_intersect_key($settings, array_flip(['accountId', 'bucket', 'accessKeyId', 'secretAccessKey', 'unitId']));
        nocache_headers(); header('Content-Type: application/json'); header('Content-Disposition: attachment; filename="wrb-r2-connection.json"');
        echo json_encode($profile, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); exit;
    }

    private static function slot(): int { $slot = self::post('slot', 1); if (!in_array($slot, ['0', '1'], true)) { throw new \RuntimeException('確認する公開鍵が不正です。'); } return (int) $slot; }
    public static function assertSettingsEditable(string $command, ?array $state): void
    {
        if (in_array($command, ['save', 'privacy', 'rules', 'retention', 'sharing', 'test', 'issue', 'confirm', 'schedule'], true)
            && $state !== null && !in_array($state['status'] ?? '', ['complete', 'cancelled'], true)) { throw new \RuntimeException('未完了のバックアップがあるため設定を変更できません。'); }
    }

    private static function sharingInput(): array
    {
        $enabled = self::post('sharingEnabled', 1);
        if (!in_array($enabled, ['', '1'], true)) { throw new \InvalidArgumentException('世代間共有の有効・無効を確認してください。'); }
        $directory = self::post('sharingDirectory', 4096);
        if ($enabled === '1' && $directory === '') { $directory = LiveSettings::automaticSharingDirectory(); }
        return LiveSettings::validateSharing(['enabled' => $enabled === '1', 'directory' => $directory]);
    }

    private static function privacyInput(): array
    {
        $input = [];
        foreach (['preset' => 6, 'clearLegacy' => 1, 'scanPublic' => 1, 'publicContentOnly' => 1,
            'category_forms' => 7, 'category_wordpress_users' => 7, 'category_authentication' => 7, 'category_content' => 7,
            'category_media' => 7, 'category_logs' => 7, 'category_backups' => 7, 'rules' => 262144,
            'ruleIntent' => 3, 'ruleType' => 6, 'tableName' => 64, 'fieldName' => 64, 'path' => 1024, 'optionName' => 191, 'rowValue' => 191, 'ruleAction' => 7] as $key => $max) {
            if (array_key_exists($key, $_POST)) { $input[$key] = self::post($key, $max); }
        }
        if (isset($_POST['decisions'])) { $input['decisions'] = wp_unslash($_POST['decisions']); }
        return $input;
    }

    private static function post(string $key, int $max): string { $value = $_POST[$key] ?? ''; if (!is_string($value) || strlen($value) > $max * 2) { throw new \RuntimeException('入力を確認してください。'); } $value = wp_unslash($value); if (strlen($value) > $max || str_contains($value, "\0")) { throw new \RuntimeException('入力を確認してください。'); } return $value; }
    private static function authorize(): void { if (!current_user_can('manage_options')) { wp_die('この操作を行う権限がありません。', '', ['response' => 403]); } }
    private static function notice(string $message): void { update_option('wrb_live_notice', $message, false); }
    public static function form(string $command, array $fields = []): void { $fields += ['wrb_view' => self::view(), 'wrb_admin_base' => self::adminBase()]; echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="wrb_live"><input type="hidden" name="command" value="' . esc_attr($command) . '">'; wp_nonce_field('wrb_live', '_wrb_live_nonce', false); foreach ($fields as $name => $value) { echo '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">'; } }
    public static function input(string $name, string $label, string $value, int $length): void { echo '<p><label for="wrb-' . esc_attr($name) . '">' . esc_html($label) . '（必須）</label><br><input id="wrb-' . esc_attr($name) . '" name="' . esc_attr($name) . '" type="text" class="large-text code" required maxlength="' . $length . '" value="' . esc_attr($value) . '" autocomplete="off" spellcheck="false"></p>'; }
}
