<?php
declare(strict_types=1);

namespace WordPressR2Backup;

/**
 * Whether a verified original fits the one-URL running copy this screen makes. A shape it cannot represent stops the startup
 * stage with its reason, rather than being silently flattened. The restored wp-config.php is read as text and never executed.
 */
final class RestoredSiteCheck
{
    private const LAYOUT_CONSTANTS = ['WP_CONTENT_URL', 'WP_CONTENT_DIR', 'WP_PLUGIN_URL', 'WP_PLUGIN_DIR', 'WPMU_PLUGIN_URL', 'WPMU_PLUGIN_DIR', 'UPLOADS', 'COOKIE_DOMAIN', 'COOKIEPATH', 'SITECOOKIEPATH'];

    /**
     * @param list<string> $tables table names of the original database
     * @param array{home:?string,siteurl:?string} $options
     * @return list<string> constants of the old configuration a running copy does not carry over
     */
    public static function assertSupported(array $tables, array $options, string $tablePrefix, string $restoredRoot): array
    {
        $config = self::configuration($restoredRoot);
        if (array_intersect([$tablePrefix . 'blogs', $tablePrefix . 'site'], $tables) !== [] || preg_match('/define\s*\(\s*[\'"](?:MULTISITE|WP_ALLOW_MULTISITE)[\'"]\s*,\s*(?:true|1)\s*\)/i', $config) === 1) {
            throw new RestoreError('マルチサイト構成には対応していないため、起動準備を停止しました。原本の復元と照合は完了し、原本は保持しています。');
        }
        $defined = static function (string $name) use ($config): ?string { return preg_match('/define\s*\(\s*[\'"]' . $name . '[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]\s*\)/', $config, $match) === 1 ? rtrim($match[1], '/') : null; };
        $home = $defined('WP_HOME') ?? (isset($options['home']) ? rtrim($options['home'], '/') : null);
        $siteUrl = $defined('WP_SITEURL') ?? (isset($options['siteurl']) ? rtrim($options['siteurl'], '/') : null);
        if ($home !== null && $siteUrl !== null && $home !== $siteUrl) {
            throw new RestoreError('公開URL（home）とWordPress本体のURL（siteurl）が異なる構成には対応していないため、起動準備を停止しました。1つのURLへ統合すると動作が変わるためです。原本の復元と照合は完了し、原本は保持しています。');
        }
        $custom = [];
        foreach (self::LAYOUT_CONSTANTS as $name) { if (preg_match('/define\s*\(\s*[\'"]' . $name . '[\'"]\s*,/', $config) === 1) { $custom[] = $name; } }
        return $custom;
    }

    private static function configuration(string $root): string
    {
        foreach ([$root . '/wp-config.php', dirname($root) . '/wp-config.php'] as $path) {
            if (@is_file($path) && !is_link($path)) { $bytes = @file_get_contents($path, false, null, 0, 1048576); if (is_string($bytes)) { return preg_replace('~/\*[\s\S]*?\*/|^\s*(?://|#)[^\n]*~m', '', $bytes) ?? $bytes; } }
        }
        return '';
    }
}
