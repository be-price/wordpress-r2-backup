<?php
declare(strict_types=1);

namespace WordPressR2Backup;

/** Validates the restore form into one canonical request. Paths are never accepted from a browser; the service derives them from the name. */
final class RestorePlan
{
    public const TEXT_FIELDS = ['snapshot' => 64, 'name' => 40, 'purpose' => 16, 'database' => 8, 'connection' => 32, 'mysqlDatabase' => 64, 'targetUrl' => 2048, 'aliases' => 16384, 'previewPort' => 5, 'tablePrefix' => 64, 'adminLogin' => 60, 'root' => 5];
    public const SECRET_FIELDS = ['identity' => 65536, 'adminPassword' => 4096, 'inspectorUser' => 80, 'inspectorPassword' => 512, 'importerUser' => 80, 'importerPassword' => 512, 'runnerUser' => 80, 'runnerPassword' => 512];

    /** @param array<string,array> $connections registered connection sets by id */
    public static function normalize(array $input, array $connections): array
    {
        $text = static function (string $field) use ($input): string { $value = $input[$field] ?? ''; return is_string($value) ? trim($value) : ''; };
        $snapshot = $text('snapshot');
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $snapshot)) { throw new RestoreError('復元するバックアップを選んでください。', 'snapshot'); }
        $name = $text('name');
        if (!preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?\z/D', $name)) { throw new RestoreError('復元の名前は英小文字・数字・ハイフンで、40文字以内にしてください。', 'name'); }
        $purpose = $text('purpose');
        if (!in_array($purpose, ['startup-check', 'full'], true)) { throw new RestoreError('メディアの扱いを選んでください。', 'purpose'); }
        $database = $text('database');
        if (!in_array($database, ['sqlite', 'mysql'], true)) { throw new RestoreError('データベースの種類を選んでください。', 'database'); }
        $plan = ['snapshot' => $snapshot, 'name' => $name, 'purpose' => $purpose, 'database' => $database];
        if ($database === 'mysql') {
            $id = $text('connection');
            if (!isset($connections[$id])) { throw new RestoreError('登録済みの接続先を選んでください。', 'connection'); }
            $schema = $connections[$id]['database'] ?? $text('mysqlDatabase');
            if (!preg_match('/\Awrb_test_[a-z0-9_]{1,51}\z/D', $schema)) { throw new RestoreError('データベース名は wrb_test_ で始まる英小文字・数字・下線にしてください。', 'mysqlDatabase'); }
            $plan['mysql'] = ['connection' => $id, 'database' => $schema, 'runningDatabase' => $schema . '_run'];
        }
        $port = $text('previewPort');
        if (!preg_match('/\A[1-9][0-9]{3,4}\z/D', $port) || (int) $port < 1024 || (int) $port > 65535) { throw new RestoreError('確認用のポート番号は1024〜65535で指定してください。', 'previewPort'); }
        $plan['previewUrl'] = 'http://127.0.0.1:' . $port;
        $target = rtrim($text('targetUrl'), '/');
        if ($target !== '') {
            try { SiteUrlRewriter::assertUrl($target, false); } catch (\RuntimeException $ignored) { throw new RestoreError('新しいアドレスは https://example.com の形で入力してください。', 'targetUrl'); }
        }
        $plan['targetUrl'] = $target === '' ? null : $target;
        $aliases = [];
        foreach (preg_split('/\R/', $text('aliases')) ?: [] as $alias) {
            $alias = rtrim(trim($alias), '/');
            if ($alias === '') { continue; }
            try { SiteUrlRewriter::assertUrl($alias, false); } catch (\RuntimeException $ignored) { throw new RestoreError('別のアドレスは1行に1つ、https://example.com の形で入力してください。', 'aliases'); }
            $aliases[] = $alias;
        }
        if (count($aliases) > 8) { throw new RestoreError('別のアドレスは8個までです。', 'aliases'); }
        $plan['aliases'] = $aliases;
        $prefix = $text('tablePrefix') === '' ? 'wp_' : $text('tablePrefix');
        if (!preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $prefix)) { throw new RestoreError('表の接頭辞は英数字と下線で指定してください。', 'tablePrefix'); }
        $plan['tablePrefix'] = $prefix;
        $login = $text('adminLogin');
        if (!preg_match('/\A[A-Za-z0-9_.-]{1,60}\z/D', $login)) { throw new RestoreError('確認用ログインの名前は英数字と . _ - で指定してください。', 'adminLogin'); }
        $plan['adminLogin'] = $login;
        $root = $text('root');
        if ($root !== '' && !preg_match('/\Ar[0-9]{4}\z/D', $root)) { throw new RestoreError('WordPressの保存ルートは r0001 の形式で指定してください。', 'root'); }
        $plan['root'] = $root === '' ? null : $root;
        return $plan;
    }

    /** The bound value for a canonical request and the facts checked for it. */
    public static function hash(array $plan, array $facts): string
    {
        $canonical = static function ($value) use (&$canonical) {
            if (!is_array($value)) { return $value; }
            if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) { ksort($value, SORT_STRING); }
            return array_map($canonical, $value);
        };
        return hash('sha256', json_encode($canonical(['plan' => $plan, 'facts' => $facts]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** WordPress portable hash for the running copy's administrator; the restored site's own code is never loaded to compute it. */
    public static function portableHash(string $password): string
    {
        $alphabet = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $encode = static function (string $bytes) use ($alphabet): string {
            $output = ''; $length = strlen($bytes);
            for ($i = 0; $i < $length;) {
                $value = ord($bytes[$i++]); $output .= $alphabet[$value & 63];
                if ($i < $length) { $value |= ord($bytes[$i]) << 8; } $output .= $alphabet[($value >> 6) & 63];
                if ($i++ >= $length) { break; }
                if ($i < $length) { $value |= ord($bytes[$i]) << 16; } $output .= $alphabet[($value >> 12) & 63];
                if ($i++ >= $length) { break; }
                $output .= $alphabet[($value >> 18) & 63];
            }
            return $output;
        };
        $salt = substr($encode(random_bytes(6)), 0, 8);
        $hash = md5($salt . $password, true);
        for ($round = 0; $round < (1 << 13); ++$round) { $hash = md5($hash . $password, true); }
        return '$P$' . $alphabet[13] . $salt . $encode($hash);
    }
}
