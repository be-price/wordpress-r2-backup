<?php
declare(strict_types=1);

use WordPressR2Backup\{AgeProcess, DirectR2Repository, LocalRepository, MysqlClient, R2Reader, Restorer, SqliteDatabaseClient, SqliteSiteSetup, LocalSiteServer, FileSafety, SyntheticHttpRepository};

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
umask(0077);
set_error_handler(static function (int $severity, string $message): bool {
    if (!(error_reporting() & $severity)) { return false; }
    throw new ErrorException('A filesystem or runtime operation failed.', 0, $severity);
});
$stage = '起動';
try {
    $help = <<<'TEXT'
WordPress R2 Backup 独立復旧CLI。直接R2と従来の合成保存先に対応します。
php recover.php <list|inspect|restore|resume|discard|rebuild-index|export-database> [options]
保存先を次のいずれか一つだけ指定:
--repository <private-root> または --synthetic-connection-file <private-file>
または --r2-connection-file <private-file>
実Cloudflare R2は --direct-r2-connection-file <private-file> を指定します。
共通必須: --unit <64-hex> --age <verified-binary>
模擬HTTPは127.0.0.1専用。実R2接続ではありません。暗号文は1個64MiB以下。
R2読取も公式ローカルSDKの127.0.0.1専用。暗号文は1個128MiB以下。
inspect: --snapshot <64-hex>
restore/resume/discard: --snapshot <64-hex> --destination <new-directory>
rebuild-index: --recipient <age1...> (複数可) --index-directory <new-private-directory>
v4の索引再構築は接続中のアカウント・バケットと指定公開鍵の保存範囲に限定します。
ローカル保存先は目録から保存範囲を一意に特定できる場合に対応します。
export-database: --snapshot <64-hex> --sql-output <new-file-in-private-directory>
restore/resume の DB取込は次の5引数をすべて指定します。
--mysql <binary> --mysqldump <binary> --defaults-file <private-file>
--mysql-workspace <owned-synthetic-workspace> --database <wrb_test_name>
大きいDBでは --mysql-timeout <1〜86400秒> で操作ごとの期限を指定できます（既定300秒）。
簡易SQLite復元は restore に --sqlite-file <new-private-database-file> を指定します。
MySQL引数とは併用できません。v4専用で、中断したDBへのresumeはしません。
標準MySQL/MariaDBの取込は、取込先schema専用に新規作成した限定アカウントだけで行います。接続ファイルは --mysql-workspace 内の importer-<database>.cnf を使い、
管理用アカウントと別で、グローバル権限・ロール・他schemaの権限を持たないことを取込前に確認し、満たさなければ取込を始めません。元の別人DEFINERは取り込めません。
--sqlite-site-config <0600-json> を併せると別コピーへローカル起動設定を行います。
設定JSONの必須項目は root, siteDirectory, databaseFile, sourceUrl, localUrl, tablePrefix, administrator です。任意の sourceAliases は、同じサイトの別名URLを最大8個の一覧で指定し、sourceUrl と同様に localUrl へ置換します。
URLの置換はホスト・ポート・パスの境界で止まり、別のホスト名の一部は書き換えません。絶対ファイルパスやcustom uploadsは移行しません。
起動コピーの要求（tablePrefix・URL・出力先の未使用と重なり・管理者設定など）は、復元を始める前の引数検査で確認します。
--local-media-dry-run yes は同じローカル起動設定と併用し、選択したWordPressルートの標準uploadsを全内容検証だけ行い配置しません。
起動設定を行わずシステムとDBだけを復元する場合は、代わりに --local-system-root <rNNNN> と --local-media-dry-run yes を併用します。
この入口は新規MySQL/MariaDBとSQLiteの両方に対応します。MySQLのresumeも同じ2引数と元のDB接続を指定します。SQLite起動設定とは併用しません。
この場合、結果のfiles/directories/links/bytesは配置分、mediaDryRunは配置しなかったメディア分です。
--serve-local yes を併せると、設定後に通信を隔離したPHPサーバーを起動します（現在macOS専用）。
秘密鍵は inspect/restore/resume/rebuild-index/export-database の標準入力へ渡します。
inspect は目録と参照暗号文を検査し取得要約を返します。本文は未復号、DBは未取込です。
restore/resume は復旧前の取得要約を標準エラーへ、最終結果を標準出力へ返します。
v1の取得証拠は不明です。capturedAtをDB固定時点として扱いません。
取得元DB版の表示は互換性の保証ではありません。v4のDB取込は、互換性を確認した専用の新しい空DBへ行い、定義・件数・値・外部キーを照合します。
旧形式の実WordPressのSQLはexport-databaseで出力し、互換性を確認した新しい空DBへ明示的に取り込みます。
list は世代IDと保存物の検証状態を返し、目録の内容を復号検証しません。
discard は指定ジョブが所有する一時領域だけを破棄します。
TEXT;
    $arguments = $argv;
    array_shift($arguments);
    if ($arguments === [] || $arguments === ['--help'] || $arguments === ['help']) {
        fwrite(STDOUT, $help . "\n");
        exit(0);
    }
    $command = array_shift($arguments);
    $specific = [
        'list' => [],
        'inspect' => ['snapshot'],
        'restore' => ['snapshot', 'destination', 'mysql', 'mysqldump', 'defaults-file', 'mysql-workspace', 'database', 'mysql-timeout', 'sqlite-file', 'sqlite-site-config', 'serve-local', 'local-media-dry-run', 'local-system-root'],
        'resume' => ['snapshot', 'destination', 'mysql', 'mysqldump', 'defaults-file', 'mysql-workspace', 'database', 'mysql-timeout', 'local-media-dry-run', 'local-system-root'],
        'discard' => ['snapshot', 'destination'],
        'rebuild-index' => ['recipient', 'index-directory'],
        'export-database' => ['snapshot', 'sql-output'],
    ];
    $autoload = is_file(__DIR__ . '/library/vendor/autoload.php')
        ? __DIR__ . '/library/vendor/autoload.php'
        : dirname(__DIR__) . '/vendor/autoload.php';
    require $autoload;
    $stage = '引数検査';
    if (!isset($specific[$command])) {
        throw new InvalidArgumentException('Unknown command.');
    }
    $allowed = array_merge(['repository', 'synthetic-connection-file', 'r2-connection-file', 'direct-r2-connection-file', 'unit', 'age'], $specific[$command]);
    $options = [];
    while ($arguments !== []) {
        $flag = array_shift($arguments);
        if (!str_starts_with($flag, '--') || !in_array(substr($flag, 2), $allowed, true)) {
            throw new InvalidArgumentException('Unknown option.');
        }
        $name = substr($flag, 2);
        $value = array_shift($arguments);
        if (!is_string($value) || $value === '' || str_contains($value, "\0") || str_starts_with($value, '--')) {
            throw new InvalidArgumentException('Missing option value.');
        }
        if ($name === 'recipient') {
            $options[$name][] = $value;
        } elseif (isset($options[$name])) {
            throw new InvalidArgumentException('Repeated option.');
        } else {
            $options[$name] = $value;
        }
    }
    if (count(array_intersect(['repository', 'synthetic-connection-file', 'r2-connection-file', 'direct-r2-connection-file'], array_keys($options))) !== 1) {
        throw new InvalidArgumentException('Choose exactly one repository connection.');
    }
    $required = ['unit', 'age'];
    if (in_array($command, ['restore', 'resume', 'discard'], true)) {
        $required = [...$required, 'snapshot', 'destination'];
    } elseif ($command === 'inspect') {
        $required = [...$required, 'snapshot'];
    } elseif ($command === 'export-database') {
        $required = [...$required, 'snapshot', 'sql-output'];
    } elseif ($command === 'rebuild-index') {
        $required = [...$required, 'recipient', 'index-directory'];
    }
    foreach ($required as $name) {
        if (!isset($options[$name])) {
            throw new InvalidArgumentException('Required option missing.');
        }
    }
    $dbOptions = ['mysql', 'mysqldump', 'defaults-file', 'mysql-workspace', 'database'];
    $dbCount = count(array_intersect($dbOptions, array_keys($options)));
    $mysqlTimeout = $options['mysql-timeout'] ?? '300';
    if (!preg_match('/\A[1-9][0-9]{0,4}\z/D', $mysqlTimeout) || (int) $mysqlTimeout > 86400 || (isset($options['mysql-timeout']) && $dbCount === 0)) { throw new InvalidArgumentException('Invalid MySQL operation time limit.'); }
    $sqlite = isset($options['sqlite-file']);
    if (($sqlite && $dbCount !== 0) || (isset($options['sqlite-site-config']) && !$sqlite)) { throw new InvalidArgumentException('Choose a single database recovery mode.'); }
    $siteConfig = null;
    $localMediaDryRunPrefix = null;
    $systemRoot = $options['local-system-root'] ?? null;
    if ($systemRoot !== null && (!preg_match('/\Ar[0-9]{4}\z/D', $systemRoot) || isset($options['sqlite-site-config'])
        || ($options['local-media-dry-run'] ?? null) !== 'yes' || (!$sqlite && $dbCount !== count($dbOptions)))) {
        throw new InvalidArgumentException('System-only recovery requires one saved root, media dry run and one complete database mode without startup settings.');
    }
    if (isset($options['local-media-dry-run']) && ($options['local-media-dry-run'] !== 'yes' || (!isset($options['sqlite-site-config']) && $systemRoot === null))) { throw new InvalidArgumentException('Local media dry run requires yes and an explicit site configuration or system root.'); }
    if ($systemRoot !== null) { $localMediaDryRunPrefix = 'roots/' . $systemRoot . '/wp-content/uploads'; }
    if (isset($options['serve-local']) && ($options['serve-local'] !== 'yes' || !isset($options['sqlite-site-config']))) { throw new InvalidArgumentException('Local startup requires explicit SQLite site configuration.'); }
    if (isset($options['sqlite-site-config'])) {
        FileSafety::assertRegularFile($options['sqlite-site-config']);
        $stat = lstat($options['sqlite-site-config']);
        if (($stat['mode'] & 0777) !== 0600 || $stat['size'] > 16384 || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())) { throw new RuntimeException('A private site configuration is required.'); }
        $siteConfig = json_decode(file_get_contents($options['sqlite-site-config']), true, 16, JSON_THROW_ON_ERROR);
        $fields = ['root', 'siteDirectory', 'databaseFile', 'sourceUrl', 'localUrl', 'tablePrefix', 'administrator'];
        if (!is_array($siteConfig) || array_diff(array_keys($siteConfig), array_merge($fields, ['sourceAliases'])) !== [] || array_diff($fields, array_keys($siteConfig)) !== []) { throw new RuntimeException('Invalid site configuration fields.'); }
        foreach (array_diff($fields, ['administrator']) as $field) { if (!is_string($siteConfig[$field]) || $siteConfig[$field] === '') { throw new RuntimeException('Invalid site configuration value.'); } }
        if (!preg_match('/\Ar[0-9]{4}\z/D', $siteConfig['root']) || !is_array($siteConfig['administrator'])) { throw new RuntimeException('Invalid restored source root.'); }
        if (isset($siteConfig['sourceAliases']) && !is_array($siteConfig['sourceAliases'])) { throw new RuntimeException('Invalid source URL aliases.'); }
        if (isset($options['local-media-dry-run'])) {
            $local = parse_url($siteConfig['localUrl']);
            if (!is_array($local) || ($local['scheme'] ?? null) !== 'http' || !in_array($local['host'] ?? '', ['localhost', '127.0.0.1'], true)
                || !isset($local['port']) || $local['port'] < 1024 || $local['port'] > 65535 || !in_array($local['path'] ?? '', ['', '/'], true)
                || array_diff(array_keys($local), ['scheme', 'host', 'port', 'path']) !== []) { throw new RuntimeException('Local media dry run requires an explicit HTTP loopback startup port.'); }
            $localMediaDryRunPrefix = 'roots/' . $siteConfig['root'] . '/wp-content/uploads';
        }
        if (isset($options['serve-local'])) {
            $local = parse_url($siteConfig['localUrl']);
            if (PHP_OS_FAMILY !== 'Darwin' || !is_array($local) || count($local) !== 3 || ($local['scheme'] ?? null) !== 'http'
                || !in_array($local['host'] ?? '', ['localhost', '127.0.0.1'], true) || !isset($local['port']) || $local['port'] < 1024 || $local['port'] > 65535) { throw new RuntimeException('Isolated local startup requires macOS and an explicit HTTP loopback port.'); }
        }
        // Everything that depends only on the request fails here, before any download, decryption or database work.
        $sitePlugin = dirname($autoload, 2) . '/dependencies/sqlite-database-integration';
        (new SqliteSiteSetup($sitePlugin))->preflight($siteConfig['siteDirectory'], $siteConfig['databaseFile'], $siteConfig['sourceUrl'], $siteConfig['localUrl'], $siteConfig['tablePrefix'],
            $siteConfig['administrator'], $siteConfig['sourceAliases'] ?? [], $options['destination'] ?? '', $options['sqlite-file']);
        if (isset($options['serve-local'])) { LocalSiteServer::preflight($siteConfig['siteDirectory'], $siteConfig['databaseFile'], rtrim($siteConfig['localUrl'], '/'), (is_file(__DIR__ . '/local-router.php') ? __DIR__ . '/local-router.php' : dirname($autoload, 2) . '/local-router.php')); }
    }
    if ($dbCount !== 0 && $dbCount !== count($dbOptions)) {
        $stage = 'DB引数は5項目をまとめて指定';
        throw new InvalidArgumentException('Incomplete database arguments.');
    }
    $stage = '保存先とageの検査';
    $repository = isset($options['repository'])
        ? LocalRepository::forRecovery($options['repository'], $options['unit'])
        : (isset($options['synthetic-connection-file']) ? new SyntheticHttpRepository($options['synthetic-connection-file'])
            : (isset($options['direct-r2-connection-file']) ? DirectR2Repository::fromFile($options['direct-r2-connection-file']) : new R2Reader($options['r2-connection-file'])));
    if ($repository->unitId() !== $options['unit']) {
        throw new InvalidArgumentException('The explicit unit does not match the repository.');
    }
    $age = new AgeProcess($options['age']);
    if ($command === 'list') {
        $stage = '世代一覧の検証';
        $result = $repository->listStatus();
    } else {
        $restorer = new Restorer($age, $repository);
        $stage = '指定ジョブの破棄';
        if ($command === 'discard') {
            $restorer->discard($options['snapshot'], $options['destination']);
            $result = ['discarded' => true];
        } elseif ($command === 'export-database') {
            $stage = 'DB暗号文の復号と新規SQLファイルへの照合済み出力';
            $result = $restorer->exportDatabase($options['snapshot'], STDIN, $options['sql-output']);
        } elseif ($command === 'inspect') {
            $stage = '選択世代の目録と参照暗号文の検査';
            $result = $restorer->inspect($options['snapshot'], STDIN);
        } elseif ($command === 'rebuild-index') {
            $stage = '索引再構築の復号と検証';
            $restorer->rebuildIndex(STDIN, $options['recipient'], $options['index-directory']);
            $result = ['indexRebuilt' => true];
        } else {
            $stage = '合成DB接続先の検査';
            $database = $dbCount === 0 ? null : new MysqlClient($options['mysql'], $options['mysqldump'], $options['defaults-file'], $options['mysql-workspace'], (int) $mysqlTimeout);
            if ($sqlite) {
                $sqlitePlugin = dirname($autoload, 2) . '/dependencies/sqlite-database-integration';
                require_once $sqlitePlugin . '/wp-includes/database/load.php';
                $lastProgress = 0.0;
                $database = new SqliteDatabaseClient($options['sqlite-file'], static function (array $progress) use (&$lastProgress): void {
                    $now = microtime(true);
                    if (($progress['phase'] ?? '') === 'sqlite-import' && $lastProgress > 0 && $now - $lastProgress < 3) { return; }
                    $lastProgress = $now;
                    $json = json_encode($progress, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
                    if (fwrite(STDERR, $json) !== strlen($json)) { throw new RuntimeException('Cannot display the SQLite recovery progress.'); }
                });
            }
            $stage = '選択世代の復号と検証・復旧';
            $result = $restorer->{$command}($options['snapshot'], STDIN, $options['destination'], $database, $sqlite ? 'sqlite' : ($options['database'] ?? null),
                static function (array $summary): void {
                    $json = json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
                    if (fwrite(STDERR, $json) !== strlen($json)) {
                        throw new RuntimeException('Cannot display the pre-recovery capture summary.');
                    }
                }, $localMediaDryRunPrefix);
            if ($siteConfig !== null) {
                $stage = '照合済み原本から別コピーへのSQLite起動準備';
                $database->close();
                $result['site'] = (new SqliteSiteSetup($sqlitePlugin))->prepare($options['destination'] . '/roots/' . $siteConfig['root'], $siteConfig['siteDirectory'], $options['sqlite-file'], $siteConfig['databaseFile'], $siteConfig['sourceUrl'], $siteConfig['localUrl'], $siteConfig['tablePrefix'], $siteConfig['administrator'], $siteConfig['sourceAliases'] ?? []);
            }
            if (!$result['databaseImported']) {
                fwrite(STDERR, "DB未取込。ファイルを復旧しました。DBはexport-databaseで別途書き出せます。\n");
            }
        }
    }
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    if (isset($options['serve-local'])) { exit(LocalSiteServer::run($result['site']['siteRoot'], $result['site']['databaseFile'], $result['site']['url'], (is_file(__DIR__ . '/local-router.php') ? __DIR__ . '/local-router.php' : dirname($autoload, 2) . '/local-router.php'))); }
} catch (Throwable $error) {
    fwrite(STDERR, $stage . "に失敗しました。入力・鍵・保存物・新規復旧先を確認してください。詳細には秘密情報を表示しません。\n");
    exit(1);
}
