<?php
declare(strict_types=1);
namespace WordPressR2Backup;

use InvalidArgumentException;
use RuntimeException;

/**
 * 現在WordPressに登録された公開画像の元ファイル・登録済みサイズが uploads に存在するかを、
 * 保存除外ルール適用前の参考として数える。ファイル本文は読まず、DB・ファイルを書き換えず、
 * 外部通信もしない。返すのは件数と次ページの内部カーソルだけで、パス・ID一覧・メタデータ本文は返さない。
 *
 * counts の定義(すべてそのページ分):
 *  imagesChecked                  対象画像の登録数
 *  originalFilePresent / Missing  _wp_attached_file(無ければ metadata['file'])が指す元ファイルの有無
 *  withSavedAlternative           元なしだが、他の登録済みファイルまたはWebP/AVIFが存在する画像
 *  withoutSavedFiles              元なしで、それらも存在しない画像
 *  incompleteMetadata             元ファイルを特定できない/メタデータが壊れている/上限超過/重複行の画像
 *  unsafePaths                    安全でない宣言パスの数(実在確認はしない)
 *  declaredFilesChecked           安全と判定でき、実在を確認した宣言ファイル数(元ファイルを含む)
 *  declaredFilesMissing           そのうち存在しなかったもの
 *  imagesWithMissingDeclaredFiles 宣言ファイルが1つ以上欠けた画像(元ありでサイズ欠落を含む)
 *  imagesWithAllDeclaredFilesPresent  欠落・不安全・不完全がなく、1つ以上確認できた画像
 */
final class WordPressMediaAudit
{
    private const METADATA_LIMIT = 65536;
    private const MAX_PATH = 1024;
    private const KEYS = ['imagesChecked', 'originalFilePresent', 'originalFileMissing', 'withSavedAlternative', 'withoutSavedFiles',
        'incompleteMetadata', 'unsafePaths', 'declaredFilesChecked', 'declaredFilesMissing', 'imagesWithMissingDeclaredFiles', 'imagesWithAllDeclaredFilesPresent'];

    /** @return array{nextId:int,done:bool,counts:array<string,int>} */
    public static function page($wpdb, string $uploadsRoot, int $afterId = 0, int $limit = 100): array
    {
        if ($afterId < 0 || $limit < 1 || $limit > 100) { throw new InvalidArgumentException('The media audit page request is out of range.'); }
        $root = realpath($uploadsRoot);
        if ($root === false || !is_dir($root)) { throw new RuntimeException('The uploads directory is unavailable for the media audit.'); }
        $root = rtrim($root, '/');
        $posts = self::table($wpdb, 'posts'); $postmeta = self::table($wpdb, 'postmeta');
        $rows = self::results($wpdb, 'SELECT p.`ID` AS id FROM ' . $posts . ' p WHERE p.`post_type` = \'attachment\' AND p.`post_status` = \'inherit\''
            . ' AND p.`post_mime_type` LIKE \'image/%\' AND p.`post_password` = \'\' AND p.`ID` > ' . $afterId
            . ' AND (p.`post_parent` = 0 OR EXISTS (SELECT 1 FROM ' . $posts . ' q WHERE q.`ID` = p.`post_parent` AND q.`post_type` <> \'attachment\''
            . ' AND q.`post_status` = \'publish\' AND q.`post_password` = \'\')) ORDER BY p.`ID` ASC LIMIT ' . ($limit + 1));
        $ids = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            if (!is_string($id) && !is_int($id)) { throw new RuntimeException('The media audit read an unexpected row.'); }
            $id = (string) $id;
            if (!preg_match('/\A[1-9][0-9]{0,18}\z/D', $id) || (string) (int) $id !== $id) { throw new RuntimeException('The media audit read an unexpected row.'); }
            $ids[] = (int) $id;
        }
        $done = count($ids) <= $limit;
        $ids = array_slice($ids, 0, $limit);
        $counts = array_fill_keys(self::KEYS, 0);
        if ($ids === []) { return ['nextId' => $afterId, 'done' => true, 'counts' => $counts]; }
        $meta = self::metadata($wpdb, $postmeta, $ids, $limit);
        foreach ($ids as $id) { self::image($root, $meta[$id] ?? [], $counts); }
        return ['nextId' => end($ids), 'done' => $done, 'counts' => $counts];
    }

    /** 巨大な値は先頭 64KiB+1 バイトだけを取り込み、PHP側で上限超過として扱う。 */
    private static function metadata($wpdb, string $postmeta, array $ids, int $limit): array
    {
        $bound = $limit * 4;
        $rows = self::results($wpdb, 'SELECT `post_id` AS post_id, `meta_key` AS meta_key, LEFT(CAST(`meta_value` AS BINARY), ' . (self::METADATA_LIMIT + 1) . ') AS value_prefix FROM ' . $postmeta
            . ' WHERE `post_id` IN (' . implode(',', $ids) . ') AND `meta_key` IN (\'_wp_attached_file\', \'_wp_attachment_metadata\') ORDER BY `post_id` ASC, `meta_id` ASC LIMIT ' . $bound);
        if (count($rows) >= $bound) { throw new RuntimeException('The media audit metadata read was not bounded.'); }
        $byPost = [];
        foreach ($rows as $row) {
            $post = (int) ($row['post_id'] ?? 0); $key = $row['meta_key'] ?? null;
            if (!in_array($post, $ids, true) || ($key !== '_wp_attached_file' && $key !== '_wp_attachment_metadata')) { throw new RuntimeException('The media audit read an unexpected row.'); }
            if (isset($byPost[$post][$key])) { $byPost[$post]['duplicate'] = true; continue; }
            $byPost[$post][$key] = $row['value_prefix'] ?? null;
        }
        return $byPost;
    }

    private static function image(string $root, array $meta, array &$counts): void
    {
        ++$counts['imagesChecked'];
        $incomplete = isset($meta['duplicate']);
        $attached = $meta['_wp_attached_file'] ?? null;
        $serialized = $meta['_wp_attachment_metadata'] ?? null;
        $data = [];
        if ($serialized !== null) {
            if (!is_string($serialized) || strlen($serialized) > self::METADATA_LIMIT) { $incomplete = true; }
            else {
                $decoded = @unserialize($serialized, ['allowed_classes' => false, 'max_depth' => 16]);
                if (is_array($decoded)) { $data = $decoded; } else { $incomplete = true; }
            }
        }
        $unsafe = 0;
        $primary = null;
        if (is_string($attached) && $attached !== '') { $primary = $attached; } // 長すぎる値も宣言元として検査へ回し、安全なmetadata['file']へ置き換えない
        elseif (isset($data['file']) && is_string($data['file']) && $data['file'] !== '') { $primary = $data['file']; }
        $declared = []; // 重複を除いた宣言パス(先頭が元ファイル)
        if ($primary === null) { $incomplete = true; }
        else { $declared[$primary] = true; }
        if ($data !== []) {
            $base = isset($data['file']) && is_string($data['file']) && $data['file'] !== '' ? $data['file'] : $primary;
            if (isset($data['file']) && !is_string($data['file'])) { $incomplete = true; }
            if ($base !== null) {
                $declared[$base] = true;
                $directory = strpos($base, '/') === false ? '' : dirname($base) . '/';
                $names = [];
                if (array_key_exists('original_image', $data)) { $names[] = $data['original_image']; }
                if (isset($data['sizes'])) {
                    if (!is_array($data['sizes'])) { $incomplete = true; }
                    else { foreach ($data['sizes'] as $size) { if (is_array($size) && array_key_exists('file', $size)) { $names[] = $size['file']; } else { $incomplete = true; } } }
                }
                foreach ($names as $name) {
                    if (!is_string($name) || $name === '') { $incomplete = true; continue; }
                    if (strpos($name, '/') !== false || strpos($name, '\\') !== false) { ++$unsafe; continue; }
                    $declared[$directory . $name] = true;
                }
            }
        }
        $present = []; $missing = [];
        foreach (array_keys($declared) as $path) {
            $state = self::state($root, (string) $path);
            if ($state === null) { ++$unsafe; continue; }
            ++$counts['declaredFilesChecked'];
            if ($state) { $present[(string) $path] = true; } else { ++$counts['declaredFilesMissing']; $missing[(string) $path] = true; }
        }
        $counts['unsafePaths'] += $unsafe;
        if ($incomplete) { ++$counts['incompleteMetadata']; }
        if ($missing !== []) { ++$counts['imagesWithMissingDeclaredFiles']; }
        if (!$incomplete && $unsafe === 0 && $missing === [] && $present !== []) { ++$counts['imagesWithAllDeclaredFilesPresent']; }
        if ($primary === null) { return; }
        if (isset($present[$primary])) { ++$counts['originalFilePresent']; return; }
        if (!isset($missing[$primary])) { return; } // 元ファイルのパスが安全でない場合は有無を判定しない
        ++$counts['originalFileMissing'];
        $alternative = array_diff_key($present, [$primary => true]) !== [];
        foreach (array_keys($alternative ? [] : $declared) as $path) {
            foreach (self::alternatives((string) $path) as $variant) { if (self::state($root, $variant) === true) { $alternative = true; break 2; } }
        }
        ++$counts[$alternative ? 'withSavedAlternative' : 'withoutSavedFiles'];
    }

    /** @return string[] */
    private static function alternatives(string $path): array
    {
        $variants = [];
        foreach (['webp', 'avif'] as $extension) {
            $variants[] = $path . '.' . $extension;
            $replaced = preg_replace('/\.[^.\/]+\z/D', '.' . $extension, $path);
            if (is_string($replaced)) { $variants[] = $replaced; }
        }
        return array_values(array_unique($variants));
    }

    /** true=通常ファイルが存在, false=存在しない, null=安全でないパス。ファイル本文は読まない。 */
    private static function state(string $root, string $relative): ?bool
    {
        if ($relative === '' || strlen($relative) > self::MAX_PATH || $relative[0] === '/'
            || preg_match('~[\x00-\x1f\x7f\\\\]|^[A-Za-z][A-Za-z0-9+.\-]*:|^[A-Za-z]:|//|(?:^|/)\.{1,2}(?:/|$)|[?#%]~', $relative)) { return null; }
        $full = $root . '/' . $relative;
        if (@lstat($full) === false) { return false; }
        $real = realpath($full);
        if ($real === false) { return is_link($full) ? null : false; } // 壊れたリンクは安全でない扱い
        if (strpos($real . '/', $root . '/') !== 0) { return null; }
        return is_file($real);
    }

    private static function table($wpdb, string $property): string
    {
        $name = is_object($wpdb) ? ($wpdb->$property ?? null) : null;
        if (!is_string($name) || !preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,63}\z/D', $name)) { throw new RuntimeException('The media audit table name is invalid.'); }
        return '`' . $name . '`';
    }

    private static function results($wpdb, string $sql): array
    {
        $rows = is_object($wpdb) && method_exists($wpdb, 'get_results') ? $wpdb->get_results($sql, 'ARRAY_A') : null;
        if (!is_array($rows) || (string) ($wpdb->last_error ?? '') !== '') { throw new RuntimeException('The media audit could not read the database.'); }
        return $rows;
    }
}
