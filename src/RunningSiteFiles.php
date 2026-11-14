<?php
declare(strict_types=1);

namespace WordPressR2Backup;

use RuntimeException;

/** Files of a separate running copy, shared by both database modes. The restored original is never written. */
final class RunningSiteFiles
{
    public const REPLACED_FILES = ['wp-config.php', 'wp-content/db.php', 'wp-content/advanced-cache.php', 'wp-content/object-cache.php'];

    public static function copyTree(string $source, string $destination, string $relative, bool $wordpress): void
    {
        $entries = scandir($source);
        if ($entries === false) { throw new RuntimeException('Cannot enumerate the restored files.'); }
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') { continue; }
            $path = $relative === '' ? $name : $relative . '/' . $name;
            if ($wordpress && in_array($path, self::REPLACED_FILES, true)) { continue; }
            $from = $source . '/' . $name; $to = $destination . '/' . $name; $stat = lstat($from);
            if ($stat === false) { throw new RuntimeException('A restored source file disappeared.'); }
            if (($stat['mode'] & 0170000) === 0040000) {
                if (!mkdir($to, 0700)) { throw new RuntimeException('Cannot create a running directory.'); }
                self::copyTree($from, $to, $path, $wordpress);
            } elseif (($stat['mode'] & 0170000) === 0100000) { self::copyFile($from, $to); }
            else { throw new RuntimeException('Local setup does not follow source links or special files.'); }
        }
    }

    public static function writeNew(string $path, string $bytes): void
    {
        $stream = fopen($path, 'x+b'); if ($stream === false) { throw new RuntimeException('Cannot create a local startup file.'); }
        try { if (!chmod($path, 0600) || fwrite($stream, $bytes) !== strlen($bytes) || !FileSafety::sync($stream)) { throw new RuntimeException('Cannot persist a local startup file.'); } }
        finally { fclose($stream); }
    }

    /** The guard every running copy carries against mail, cron and outbound HTTP. */
    public static function writeIsolationPlugin(string $contentDirectory): void
    {
        $mu = $contentDirectory . '/mu-plugins';
        if (!is_dir($mu) && !mkdir($mu, 0700)) { throw new RuntimeException('Cannot create the isolation plugin directory.'); }
        self::writeNew($mu . '/000-wrb-local-isolation.php', <<<'GUARD'
<?php
if (defined('DOING_CRON') && DOING_CRON) { http_response_code(403); exit; }
add_filter('pre_http_request', static function () { return new WP_Error('wrb_local_network_disabled', 'External requests are disabled in this restored local site.'); }, PHP_INT_MAX);
add_filter('pre_wp_mail', static function () { return false; }, PHP_INT_MAX);
add_filter('pre_schedule_event', static function () { return false; }, PHP_INT_MAX);
add_filter('pre_reschedule_event', static function () { return false; }, PHP_INT_MAX);
GUARD
        );
    }

    /**
     * wp-config.php body after the database constants. $previewUrl is where the isolated server listens; $targetUrl, when given,
     * is the value stored in the database. The server's environment selects the preview, so the file itself keeps the target.
     */
    public static function configuration(string $databaseConstants, string $previewUrl, ?string $targetUrl, string $tablePrefix): string
    {
        $configuration = "<?php\n" . $databaseConstants . "define('DB_CHARSET', 'utf8mb4');\ndefine('DB_COLLATE', '');\n";
        if ($targetUrl === null) {
            $configuration .= "define('WP_HOME', " . var_export($previewUrl, true) . ");\ndefine('WP_SITEURL', " . var_export($previewUrl, true) . ");\n";
        } else {
            $configuration .= '$wrbPreview = getenv(\'WRB_TARGET_URL\') === ' . var_export($targetUrl, true) . ' ? getenv(\'WRB_LOCAL_URL\') : false;' . "\n"
                . '$wrbUrl = is_string($wrbPreview) && $wrbPreview !== \'\' ? $wrbPreview : ' . var_export($targetUrl, true) . ";\ndefine('WP_HOME', \$wrbUrl);\ndefine('WP_SITEURL', \$wrbUrl);\nunset(\$wrbPreview, \$wrbUrl);\n";
        }
        foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'] as $name) { $configuration .= "define('$name', '" . bin2hex(random_bytes(32)) . "');\n"; }
        return $configuration . "define('DISABLE_WP_CRON', true);\ndefine('WP_HTTP_BLOCK_EXTERNAL', true);\ndefine('AUTOMATIC_UPDATER_DISABLED', true);\ndefine('DISALLOW_FILE_MODS', true);\ndefine('WP_ENVIRONMENT_TYPE', 'local');\ndefine('WP_CACHE', false);\ndefine('WP_DEBUG', false);\n\$table_prefix = " . var_export($tablePrefix, true) . ";\ndefine('ABSPATH', __DIR__ . '/');\nrequire_once ABSPATH . 'wp-settings.php';\n";
    }

    private static function copyFile(string $source, string $destination): void
    {
        $input = fopen($source, 'rb'); $output = fopen($destination, 'x+b');
        if ($input === false || $output === false) { if (is_resource($input)) { fclose($input); } if (is_resource($output)) { fclose($output); } throw new RuntimeException('Cannot create a separate running file.'); }
        try {
            if (!chmod($destination, 0600) || stream_copy_to_stream($input, $output) === false || !FileSafety::sync($output)) { throw new RuntimeException('Cannot persist a separate running file.'); }
            $from = fstat($input); $to = fstat($output);
            if ($from === false || $to === false || $from['size'] !== $to['size'] || ($from['dev'] === $to['dev'] && $from['ino'] === $to['ino'])) { throw new RuntimeException('The running file is not an independent copy.'); }
        } finally { fclose($input); fclose($output); }
    }
}
