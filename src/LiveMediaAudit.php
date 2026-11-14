<?php
declare(strict_types=1);
namespace WordPressR2Backup;

use RuntimeException;

final class LiveMediaAudit
{
    private const COUNTS = ['imagesChecked', 'originalFilePresent', 'originalFileMissing', 'withSavedAlternative',
        'withoutSavedFiles', 'incompleteMetadata', 'unsafePaths', 'declaredFilesChecked', 'declaredFilesMissing',
        'imagesWithMissingDeclaredFiles', 'imagesWithAllDeclaredFilesPresent'];
    private $page;

    public function __construct(?callable $page = null) { $this->page = $page; }

    public static function current(): ?array
    {
        $state = get_transient(self::key());
        return is_array($state) ? self::publicState(self::validate($state)) : null;
    }

    public function step(string $runId, bool $restart): array
    {
        $operation = get_option('wrb_live_operation', null);
        if (is_array($operation) && in_array($operation['status'] ?? '', ['queued', 'capturing', 'uploading'], true)) {
            throw new RuntimeException('バックアップ処理中は画像ファイルの確認を開始できません。');
        }
        $key = self::key();
        if ($restart) {
            $state = ['runId' => bin2hex(random_bytes(16)), 'cursor' => 0, 'done' => false,
                'counts' => array_fill_keys(self::COUNTS, 0), 'startedAt' => time(), 'checkedAt' => time()];
            if (!set_transient($key, $state, 3600)) { throw new RuntimeException('画像確認の進行状況を保存できません。'); }
        } else {
            $state = get_transient($key);
            if (!is_array($state)) { throw new RuntimeException('画像確認の有効期限が切れました。もう一度確認してください。'); }
            $state = self::validate($state);
            if (!hash_equals($state['runId'], $runId)) { throw new RuntimeException('別の画像確認が開始されています。画面を開き直してください。'); }
            if ($state['done']) { return self::publicState($state); }
        }
        global $wpdb;
        $uploads = wp_upload_dir(null, false);
        if (!is_array($uploads) || !empty($uploads['error']) || !is_string($uploads['basedir'] ?? null)) {
            throw new RuntimeException('アップロード保存先を確認できません。');
        }
        $started = microtime(true);
        for ($batch = 0; $batch < 5; ++$batch) {
            try {
                $page = $this->page !== null ? ($this->page)($wpdb, $uploads['basedir'], $state['cursor'], 100)
                    : WordPressMediaAudit::page($wpdb, $uploads['basedir'], $state['cursor'], 100);
            } catch (\Throwable $error) {
                throw new RuntimeException('公開画像の登録情報またはアップロード保存先を確認できません。検査は未完了です。', 0, $error);
            }
            if (!is_array($page) || !is_int($page['nextId'] ?? null) || !is_bool($page['done'] ?? null)
                || $page['nextId'] < $state['cursor'] || (!$page['done'] && $page['nextId'] === $state['cursor'])) {
                throw new RuntimeException('画像確認の進行状況が不正です。完了とは扱いません。');
            }
            $counts = self::counts($page['counts'] ?? null);
            foreach (self::COUNTS as $name) {
                if ($state['counts'][$name] > PHP_INT_MAX - $counts[$name]) { throw new RuntimeException('画像確認の件数が上限を超えました。'); }
                $state['counts'][$name] += $counts[$name];
            }
            $state['cursor'] = $page['nextId']; $state['done'] = $page['done']; $state['checkedAt'] = time();
            $latest = get_transient($key);
            if (!is_array($latest) || ($latest['runId'] ?? null) !== $state['runId']) {
                throw new RuntimeException('別の画像確認が開始されています。画面を開き直してください。');
            }
            if (!set_transient($key, $state, 3600)) { throw new RuntimeException('画像確認の進行状況を保存できません。'); }
            if ($state['done'] || microtime(true) - $started >= 1.0) { break; }
        }
        return self::publicState($state);
    }

    public static function lines(?array $state): array
    {
        if ($state === null) { return ['未確認です。画像の登録があっても、保存元に実ファイルがなければ復元できません。']; }
        $counts = self::counts($state['counts'] ?? null);
        $number = static function (int $value): string { return number_format($value); };
        if (empty($state['done'])) { return ['公開画像 ' . $number($counts['imagesChecked']) . ' 件を確認中です。この画面を閉じると確認は停止します。']; }
        $lines = ['公開画像 ' . $number($counts['imagesChecked']) . ' 件の登録を確認しました。'];
        $lines[] = $counts['imagesWithMissingDeclaredFiles'] > 0
            ? '画像ファイルが不足する登録が ' . $number($counts['imagesWithMissingDeclaredFiles']) . ' 件あります。画像を含むサイト全体の復元は保証できません。'
            : '確認できた登録済みファイルに不足はありません。外部URLや記事内だけで参照する画像は未確認です。';
        $lines[] = '元画像あり ' . $number($counts['originalFilePresent']) . ' 件 ／ 元画像なし ' . $number($counts['originalFileMissing']) . ' 件。';
        $lines[] = '元画像なしのうち、代わりの画像あり ' . $number($counts['withSavedAlternative']) . ' 件 ／ 確認範囲に画像なし ' . $number($counts['withoutSavedFiles']) . ' 件。';
        if ($counts['incompleteMetadata'] + $counts['unsafePaths'] > 0) {
            $lines[] = '登録情報の不足や安全なパスを確認できないため、検査できない登録が含まれます。結果を完全な確認として扱わないでください。';
        }
        return $lines;
    }

    private static function key(): string
    {
        $user = get_current_user_id();
        if (!is_int($user) || $user < 1) { throw new RuntimeException('管理者としてログインしてください。'); }
        return 'wrb_media_audit_' . $user;
    }

    private static function counts($counts): array
    {
        if (!is_array($counts)) { throw new RuntimeException('画像確認の件数を読み取れません。'); }
        $result = [];
        foreach (self::COUNTS as $name) {
            if (!is_int($counts[$name] ?? null) || $counts[$name] < 0) { throw new RuntimeException('画像確認の件数が不正です。'); }
            $result[$name] = $counts[$name];
        }
        return $result;
    }

    private static function validate(array $state): array
    {
        if (!is_string($state['runId'] ?? null) || !preg_match('/\A[a-f0-9]{32}\z/D', $state['runId'])
            || !is_int($state['cursor'] ?? null) || $state['cursor'] < 0 || !is_bool($state['done'] ?? null)
            || !is_int($state['startedAt'] ?? null) || !is_int($state['checkedAt'] ?? null)) {
            throw new RuntimeException('画像確認の記録が不正です。もう一度確認してください。');
        }
        $state['counts'] = self::counts($state['counts'] ?? null);
        return $state;
    }

    private static function publicState(array $state): array
    {
        return ['runId' => $state['runId'], 'done' => $state['done'], 'counts' => $state['counts'],
            'startedAt' => $state['startedAt'], 'checkedAt' => $state['checkedAt'], 'lines' => self::lines($state),
            'warning' => $state['counts']['imagesWithMissingDeclaredFiles'] > 0 || $state['counts']['incompleteMetadata'] > 0 || $state['counts']['unsafePaths'] > 0];
    }
}
