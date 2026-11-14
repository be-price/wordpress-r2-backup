<?php
declare(strict_types=1);
namespace WordPressR2Backup;

final class LiveSettings
{
    public const OPTION = 'wrb_live_config';
    public const POLICY_DRAFT = 'wrb_live_policy_draft';

    public static function seal(array $data, string $key): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt(json_encode($data, JSON_THROW_ON_ERROR), 'aes-256-gcm', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv, $tag, 'wordpress-r2-settings-v1');
        if ($cipher === false) { throw new \RuntimeException('接続設定を暗号化できません。'); }
        return 'v1:' . base64_encode($iv . $tag . $cipher);
    }

    public static function open(string $data, string $key): array
    {
        $bytes = str_starts_with($data, 'v1:') ? base64_decode(substr($data, 3), true) : false;
        if ($bytes === false || strlen($bytes) < 29) { throw new \RuntimeException('接続設定を検証できません。'); }
        $plain = openssl_decrypt(substr($bytes, 28), 'aes-256-gcm', hash('sha256', $key, true), OPENSSL_RAW_DATA, substr($bytes, 0, 12), substr($bytes, 12, 16), 'wordpress-r2-settings-v1');
        if ($plain === false) { throw new \RuntimeException('接続設定を復号できません。サイトの認証用設定が変わった場合は再登録してください。'); }
        $result = json_decode($plain, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($result)) { throw new \RuntimeException('接続設定が不正です。'); }
        return $result;
    }

    public static function load(): ?array
    {
        $value = get_option(self::OPTION, '');
        return $value === '' ? null : self::open($value, wp_salt('auth'));
    }

    public static function save(array $value): void
    {
        $sealed = self::seal($value, wp_salt('auth'));
        update_option(self::OPTION, $sealed, false);
        if (get_option(self::OPTION) !== $sealed) { throw new \RuntimeException('接続設定を保存できません。'); }
    }

    public static function validate(array $data, ?array $previous = null): array
    {
        foreach (['accountId', 'bucket', 'accessKeyId', 'secretAccessKey'] as $key) {
            if (!isset($data[$key]) || !is_string($data[$key])) { throw new \InvalidArgumentException('R2接続設定を確認してください。'); }
            $data[$key] = trim($data[$key]);
        }
        if ($data['secretAccessKey'] === '' && $previous !== null) { $data['secretAccessKey'] = $previous['secretAccessKey']; }
        if (!preg_match('/\A[a-f0-9]{32}\z/D', $data['accountId'])
            || !preg_match('/\A[a-z0-9][a-z0-9-]{1,61}[a-z0-9]\z/D', $data['bucket'])
            || !preg_match('/\A[A-Za-z0-9]{16,128}\z/D', $data['accessKeyId'])
            || !preg_match('~\A[A-Za-z0-9/+=]{32,256}\z~D', $data['secretAccessKey'])) {
            throw new \InvalidArgumentException('アカウントID、バケット名、アクセスキーを確認してください。');
        }
        if (!is_array($data['recipients'] ?? null)) { throw new \InvalidArgumentException('公開鍵2本を入力してください。'); }
        $data['recipients'] = array_map('trim', $data['recipients']);
        Manifest::keySetId($data['recipients']);
        $days = filter_var($data['retentionDays'] ?? null, FILTER_VALIDATE_INT);
        if ($days === false || $days < 1 || $days > 3650) { throw new \InvalidArgumentException('保存期間は1〜3650日で指定してください。'); }
        $policy = self::privacyPolicy(array_key_exists('policy', $data) ? $data : $previous);
        $result = array_replace($previous ?? [], ['accountId' => $data['accountId'], 'bucket' => $data['bucket'], 'accessKeyId' => $data['accessKeyId'],
            'secretAccessKey' => $data['secretAccessKey'], 'unitId' => $previous['unitId'] ?? bin2hex(random_bytes(32)),
            'recipients' => $data['recipients'], 'retentionDays' => $days, 'policy' => $policy]);
        if (array_key_exists('sharing', $data)) { $result['sharing'] = self::validateSharing($data['sharing']); }
        $connection = hash('sha256', json_encode(array_intersect_key($result, array_flip(['accountId', 'bucket', 'accessKeyId', 'secretAccessKey'])), JSON_THROW_ON_ERROR));
        $result['connectionVerified'] = isset($previous['connectionDigest']) && hash_equals($previous['connectionDigest'], $connection) && !empty($previous['connectionVerified']);
        $result['connectionDigest'] = $connection;
        if ($previous !== null && $previous['recipients'] === $result['recipients']) { $result['proofs'] = $previous['proofs'] ?? []; }
        else { unset($result['proofs']); }
        return $result;
    }

    public static function validateSharing($sharing): array
    {
        if (!is_array($sharing) || count($sharing) !== 2 || !array_key_exists('enabled', $sharing)
            || !array_key_exists('directory', $sharing) || !is_bool($sharing['enabled']) || !is_string($sharing['directory'])) {
            throw new \InvalidArgumentException('世代間共有の設定が不正です。');
        }
        $directory = $sharing['directory'];
        if (strlen($directory) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $directory)
            || ($directory !== '' && ($directory[0] !== '/' || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $directory)))
            || ($sharing['enabled'] && $directory === '')) {
            throw new \InvalidArgumentException('索引の保存先は、サイト外の永続的な専用ディレクトリを絶対パスで指定してください。');
        }
        return ['enabled' => $sharing['enabled'], 'directory' => $directory];
    }

    /**
     * 利用者がパスを入力しなくてよいよう、サーバー実行ユーザーのホーム直下に索引領域を選ぶ。
     * ホームがウェブ公開領域・WordPress本体・OS一時領域のいずれかに含まれる、保存元ルートと重なる（作成前に検査）、または所有者だけの
     * 領域として作成・検証できない場合は、危険な代替を探さず停止する。
     */
    public static function automaticRestoreDirectory(?string $homeOverride = null): string { return self::privateHomeDirectory('.wordpress-r2-backup-restore', '復元の専用領域', $homeOverride); }

    public static function automaticSharingDirectory(?string $homeOverride = null): string { return self::privateHomeDirectory('.wordpress-r2-backup-index', '索引の保存場所', $homeOverride); }

    private static function privateHomeDirectory(string $name, string $label, ?string $homeOverride): string
    {
        $fail = static function (string $reason) use ($label): \RuntimeException {
            return new \RuntimeException($label . 'を安全に自動選択できませんでした（' . $reason . '）。詳細設定で、サイト外の専用ディレクトリを絶対パスで指定してください。');
        };
        $account = function_exists('posix_getpwuid') && function_exists('posix_geteuid') ? posix_getpwuid(posix_geteuid()) : false;
        $home = $homeOverride !== null ? realpath($homeOverride) : (is_array($account) && is_string($account['dir'] ?? null) ? realpath($account['dir']) : false);
        if ($home === false || $home === '/' || !is_dir($home) || fileowner($home) !== posix_geteuid() || !is_writable($home)) { throw $fail('実行ユーザーのホームを利用できません'); }
        $temporary = realpath(sys_get_temp_dir());
        $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) && is_string($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] !== '' ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
        $abspath = defined('ABSPATH') ? realpath((string) constant('ABSPATH')) : false;
        if ($documentRoot === false || $abspath === false || $temporary === false) { throw $fail('公開領域との位置関係を確認できません'); }
        $path = $home . '/' . $name;
        $inside = static function (string $parent, string $child): bool { return $child === $parent || str_starts_with($child, rtrim($parent, '/') . '/'); };
        foreach ([$documentRoot, $abspath, $temporary] as $unsafe) {
            if ($inside($unsafe, $path)) { throw $fail('ホームが公開領域または一時領域に含まれます'); }
        }
        try { SourceRoots::fromWordPress(rtrim((string) $abspath, '/'), [])->verifyPrivateLocations([$path]); }
        catch (\RuntimeException $error) { throw $fail('保存元のルートと重なります'); }
        try {
            if (@lstat($path) === false && !@mkdir($path, 0700)) { throw $fail('専用領域を作成できません'); }
            FileSafety::privateDirectory($path);
        } catch (\RuntimeException $error) {
            throw str_starts_with($error->getMessage(), $label) ? $error : $fail('所有者だけの専用領域として検証できません');
        }
        return $path;
    }

    public static function validatePolicy($policy): array
    {
        if (!is_array($policy)) { throw new \InvalidArgumentException('保存範囲の設定が不正です。'); }
        if (!array_key_exists('version', $policy) && array_diff(array_keys($policy), ['tables', 'fields', 'paths', 'categories', 'scanPublic', 'publicContentOnly', 'excludedOptions', 'excludedRows']) !== []) { throw new \InvalidArgumentException('保存範囲に不明な設定があります。'); }
        foreach (['tables', 'fields', 'paths', 'categories', 'options', 'rows'] as $kind) {
            if (array_key_exists($kind, $policy) && (!is_array($policy[$kind]) || count($policy[$kind]) > 5000)) { throw new \InvalidArgumentException('対象設定が多すぎるか不正です。'); }
        }
        try {
            return (new PrivacyPolicy(array_key_exists('version', $policy) ? $policy : PrivacyPolicy::migrateLegacy($policy, self::prefix()), self::prefix()))->rules();
        } catch (\RuntimeException $error) { throw new \InvalidArgumentException('保存範囲の設定を確認してください。' . $error->getMessage(), 0, $error); }
    }

    private static function prefix(): string
    {
        global $wpdb;
        return is_object($wpdb) && is_string($wpdb->prefix ?? null) ? $wpdb->prefix : 'wp_';
    }

    public static function privacyPolicy(?array $settings, array $draft = []): array
    {
        if ($settings !== null && array_key_exists('policy', $settings)) { return self::validatePolicy($settings['policy']); }
        if ($draft !== []) { return self::validatePolicy($draft); }
        return (new PrivacyPolicy(['version' => 2, 'preset' => 'full'], self::prefix()))->rules();
    }

    public static function loadPolicy(?array $settings): array
    {
        $draft = $settings === null ? get_option(self::POLICY_DRAFT, []) : [];
        if (!is_array($draft)) { throw new \InvalidArgumentException('保存された除外設定が不正です。'); }
        return self::privacyPolicy($settings, $draft);
    }

    public static function withPolicy(array $settings, array $policy): array
    {
        $settings['policy'] = self::validatePolicy($policy);
        return $settings;
    }

    public static function savePolicy(?array $settings, array $policy): void
    {
        $policy = self::validatePolicy($policy);
        if ($settings !== null) { self::save(self::withPolicy($settings, $policy)); return; }
        update_option(self::POLICY_DRAFT, $policy, false);
        if (get_option(self::POLICY_DRAFT) !== $policy) { throw new \RuntimeException('除外設定を保存できません。'); }
    }

    public static function applyPrivacyInput(array $policy, array $input): array
    {
        $policy = self::validatePolicy($policy);
        if (array_key_exists('preset', $input)) {
            if (!in_array($input['preset'], ['full', 'forms', 'custom'], true)) { throw new \InvalidArgumentException('保存のプリセットを選んでください。'); }
            if ($input['preset'] === 'full') { return self::privacyPolicy(null); }
            $policy['preset'] = $input['preset'];
        } elseif ($policy['preset'] === 'full' && $input !== []) { $policy['preset'] = 'custom'; }
        if (array_key_exists('clearLegacy', $input)) {
            if ($input['clearLegacy'] !== '1') { throw new \InvalidArgumentException('以前の設定の解除指定が不正です。'); }
            unset($policy['legacy']);
        }
        foreach (['scanPublic', 'publicContentOnly'] as $key) {
            if (!array_key_exists($key, $input)) { continue; }
            if (!in_array($input[$key], ['0', '1'], true)) { throw new \InvalidArgumentException('以前の公開情報の設定が不正です。'); }
            if (isset($policy['legacy'])) { $policy['legacy'][$key] = $input[$key] === '1'; }
            elseif ($input[$key] === '1') { throw new \InvalidArgumentException('以前の公開情報の設定は継承設定でのみ変更できます。'); }
        }
        foreach (['forms', 'wordpress_users', 'authentication', 'content', 'media', 'logs', 'backups'] as $category) {
            $key = 'category_' . $category;
            if (!array_key_exists($key, $input)) { continue; }
            $action = $input[$key];
            if (!in_array($action, ['', 'include', 'exclude'], true)) { throw new \InvalidArgumentException('分類の扱いが不正です。'); }
            if ($action === '') { unset($policy['categories'][$category]); }
            else { $policy['categories'][$category] = $action; }
        }
        $decisions = $input['decisions'] ?? [];
        if (!is_array($decisions) || count($decisions) > 500) { throw new \InvalidArgumentException('対象の設定が不正です。'); }
        $changes = [];
        foreach ($decisions as $encoded => $action) {
            $decoded = is_string($encoded) ? base64_decode($encoded, true) : false;
            if ($decoded === false || !in_array($action, ['', 'include', 'exclude'], true)) { throw new \InvalidArgumentException('対象の設定が不正です。'); }
            self::decision($policy, $decoded, $action, $changes);
        }
        if (array_key_exists('rules', $input)) {
            if (!is_string($input['rules'])) { throw new \InvalidArgumentException('個別設定が不正です。'); }
            $rules = self::parseRules($input['rules']);
            foreach (['tables' => 'table', 'fields' => 'field', 'paths' => 'path', 'options' => 'option'] as $kind => $type) {
                foreach ($rules[$kind] as $name => $action) { self::decision($policy, $type . ' ' . $name, $action, $changes); }
            }
            foreach ($rules['rows'] as $table => $fields) {
                foreach ($fields as $field => $values) { foreach ($values as $value => $action) { self::decision($policy, 'row ' . $table . '.' . $field . ' ' . $value, $action, $changes); } }
            }
        }
        if (($input['ruleIntent'] ?? '') === 'add') {
            $type = $input['ruleType'] ?? '';
            $action = $input['ruleAction'] ?? '';
            if (!in_array($type, ['table', 'field', 'path', 'option', 'row'], true) || !in_array($action, ['', 'include', 'exclude'], true)) { throw new \InvalidArgumentException('対象の種類と扱いを選んでください。'); }
            $name = $type === 'option' ? ($input['optionName'] ?? '') : ($type === 'path' ? ($input['path'] ?? '') : ($input['tableName'] ?? ''));
            if (!is_string($name) || (in_array($type, ['field', 'row'], true) && !is_string($input['fieldName'] ?? null))) { throw new \InvalidArgumentException('対象を確認してください。'); }
            if (in_array($type, ['field', 'row'], true)) { $name .= '.' . $input['fieldName']; }
            if ($type === 'row') {
                if (!is_string($input['rowValue'] ?? null)) { throw new \InvalidArgumentException('対象の行を確認してください。'); }
                $name .= ' ' . $input['rowValue'];
            }
            self::decision($policy, $type . ' ' . $name, $action, $changes);
        }
        return self::validatePolicy($policy);
    }

    private static function decision(array &$policy, string $target, string $action, array &$changes): void
    {
        if (array_key_exists($target, $changes) && $changes[$target] !== $action) { throw new \InvalidArgumentException('同じ対象に「含める」と「除外」を同時に指定できません。対象の扱いを統一してください。'); }
        $changes[$target] = $action;
        if (preg_match('/\Arow ([A-Za-z_][A-Za-z0-9_]{0,63})\.([A-Za-z_][A-Za-z0-9_]{0,63}) (.*)\z/D', $target, $match)) {
            if ($action === '') {
                unset($policy['rows'][$match[1]][$match[2]][$match[3]]);
                if (empty($policy['rows'][$match[1]][$match[2]])) { unset($policy['rows'][$match[1]][$match[2]]); }
                if (empty($policy['rows'][$match[1]])) { unset($policy['rows'][$match[1]]); }
            } else { $policy['rows'][$match[1]][$match[2]][$match[3]] = $action; }
            return;
        }
        if (!preg_match('/\A(table|field|path|option) (.+)\z/D', $target, $match)) { throw new \InvalidArgumentException('対象の設定が不正です。'); }
        $kind = ['table' => 'tables', 'field' => 'fields', 'path' => 'paths', 'option' => 'options'][$match[1]];
        self::rule($kind, $match[2]);
        $name = $kind === 'paths' ? rtrim($match[2], '/') : $match[2];
        if ($name !== $match[2]) {
            $canonical = $match[1] . ' ' . $name;
            if (isset($changes[$canonical]) && $changes[$canonical] !== $action) { throw new \InvalidArgumentException('同じパスの扱いが相反しています。'); }
            $changes[$canonical] = $action;
        }
        if ($action === '') { unset($policy[$kind][$name]); }
        else { $policy[$kind][$name] = $action; }
    }

    public static function parseRules(string $text): array
    {
        if (strlen($text) > 262144) { throw new \InvalidArgumentException('対象設定が長すぎます。'); }
        $rules = ['tables' => [], 'fields' => [], 'paths' => [], 'options' => [], 'rows' => []];
        $changes = [];
        foreach (preg_split('/\R/', $text) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') { continue; }
            if (!preg_match('/\A((?:table|field|path|option|row) .+?)\s+(include|exclude)\z/uD', $line, $match)) { throw new \InvalidArgumentException('個別設定は「table テーブル名 exclude」「field テーブル.項目 include」「path 相対パス exclude」「option 設定名 include」「row テーブル.項目 識別子 exclude」の形式で指定してください。'); }
            self::decision($rules, $match[1], $match[2], $changes);
        }
        self::validatePolicy(['version' => 2, 'preset' => 'custom'] + $rules);
        return $rules;
    }

    private static function rule(string $type, string $name): void
    {
        if ($type === 'options') {
            if ($name === '' || strlen($name) > 191 || preg_match('/[\x00-\x1f\x7f]/', $name)) { throw new \InvalidArgumentException('サイト設定の行名を確認してください。'); }
            return;
        }
        if ($type === 'paths') { FileSafety::validatePaths([rtrim($name, '/')]); }
        elseif (!preg_match($type === 'tables' ? '/\A[A-Za-z_][A-Za-z0-9_]{0,63}\z/D' : '/\A[A-Za-z_][A-Za-z0-9_]{0,63}\.[A-Za-z_][A-Za-z0-9_]{0,63}\z/D', $name)) { throw new \InvalidArgumentException('テーブル名・フィールド名を確認してください。SQL文は指定できません。'); }
    }

    public static function rulesText(array $policy): string
    {
        $lines = [];
        foreach (['tables' => 'table', 'fields' => 'field', 'paths' => 'path', 'options' => 'option'] as $kind => $type) {
            foreach ($policy[$kind] ?? [] as $name => $action) { $lines[] = $type . ' ' . $name . ' ' . $action; }
        }
        foreach ($policy['rows'] ?? [] as $table => $fields) {
            foreach ($fields as $field => $values) { foreach ($values as $value => $action) { $lines[] = 'row ' . $table . '.' . $field . ' ' . $value . ' ' . $action; } }
        }
        return implode("\n", $lines);
    }

    public static function workspace(): string
    {
        global $wpdb;
        $table = $wpdb->options ?? null;
        if (!is_string($table) || !preg_match('/\A[A-Za-z0-9_$]+\z/D', $table)) { throw new \RuntimeException('作業領域の識別子を確認できません。'); }
        // Read the authoritative database, not an object-cache copy left by another process.
        $read = static function () use ($wpdb, $table) {
            $value = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM `{$table}` WHERE option_name = %s", 'wrb_live_workspace_token'));
            if (!empty($wpdb->last_error)) { throw new \RuntimeException('作業領域の識別子を確認できません。'); }
            return $value;
        };
        // Suppress database error output while inserting the token; query logging may still capture SQL.
        $suppressed = $wpdb->suppress_errors(true);
        try {
            $token = $read();
            if ($token === null) {
                // add_option() performs a duplicate-key UPDATE and can replace another process's token.
                $sql = $wpdb->prepare("INSERT INTO `{$table}` (option_name, option_value, autoload) VALUES (%s, %s, %s) ON DUPLICATE KEY UPDATE option_name = option_name", 'wrb_live_workspace_token', bin2hex(random_bytes(32)), 'no');
                if ($wpdb->query($sql) === false) { throw new \RuntimeException('作業領域の識別子を保存できません。'); }
                $token = $read();
            }
        } finally { $wpdb->suppress_errors($suppressed); }
        wp_cache_delete('wrb_live_workspace_token', 'options');
        wp_cache_delete('notoptions', 'options');
        wp_cache_delete('alloptions', 'options');
        if (!is_string($token) || !preg_match('/\A[a-f0-9]{64}\z/D', $token)) { throw new \RuntimeException('作業領域の識別子が不正です。'); }
        $base = self::workspaceBase();
        $path = $base . '/wrb-live-' . hash('sha256', home_url('/') . $token);
        FileSafety::assertNoLinks($path);
        if (!is_dir($path) && !@mkdir($path, 0700)) {
            // Another process may have created the same workspace after our first check.
            clearstatcache(true, $path);
            if (!is_dir($path)) { throw new \RuntimeException('非公開作業領域を作成できません。'); }
        }
        return FileSafety::privateDirectory($path);
    }

    /** Explicitly configured shared bases must already be private and outside all known source/public roots. */
    private static function workspaceBase(): string
    {
        if (!defined('WRB_LIVE_WORKSPACE_BASE')) {
            $base = realpath(sys_get_temp_dir());
            if ($base === false) { throw new \RuntimeException('一時領域を利用できません。'); }
            return $base;
        }
        $base = constant('WRB_LIVE_WORKSPACE_BASE');
        $public = defined('WRB_LIVE_PUBLIC_ROOT') ? constant('WRB_LIVE_PUBLIC_ROOT') : null;
        if (!is_string($base) || !is_string($public) || !defined('ABSPATH') || !function_exists('posix_geteuid')) {
            throw new \RuntimeException('共有作業領域と公開領域を安全に確認できません。');
        }
        try {
            FileSafety::privateDirectory($base);
            FileSafety::assertNoLinks($public);
            $root = realpath(rtrim((string) constant('ABSPATH'), '/'));
            if ($public === '/' || realpath($public) !== $public || !is_dir($public) || $root === false || !is_writable($base)) {
                throw new \RuntimeException('Invalid base.');
            }
            $within = static function (string $parent, string $child): bool { return $child === $parent || str_starts_with($child, rtrim($parent, '/') . '/'); };
            $document = $_SERVER['DOCUMENT_ROOT'] ?? '';
            if (!$within($public, $root) || $within($public, $base) || $within($base, $public)
                || ($document !== '' && (!is_string($document) || ($actual = realpath($document)) === false || !$within($public, $actual)))) {
                throw new \RuntimeException('Unsafe base.');
            }
            SourceRoots::fromWordPress($root)->verifyPrivateLocations([$base]);
            return $base;
        } catch (\Throwable $error) {
            throw new \RuntimeException('共有作業領域を非公開の安全な場所として確認できません。');
        }
    }
}
