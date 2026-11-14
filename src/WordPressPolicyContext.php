<?php
declare(strict_types=1);
namespace WordPressR2Backup;

use Generator;
use RuntimeException;

final class WordPressPolicyContext
{
    private $wpdb;
    private PrivacyPolicy $policy;
    private string $directory;
    private array $summary = [];
    private ?string $uploadsDirectory = null;
    /** Media directories that hold public files. Few, so they are kept here instead of in the file-backed map. */
    private array $directories = [];

    private const PUBLIC = 1;
    private const CONFIG = 2;
    private const FORM = 4;
    private const ATTACHMENT = 8;
    private const IMAGE = 16;
    /** Measured on PHP 7.4: about 70 bytes per post, so the table stays near 35 MiB. Larger sites use the checked map as before. */
    private const MAX_POSTS = 500000;
    /** Room kept per post for the next doubling of the table (measured near 70 bytes per post plus the new bucket array). */
    private const GROWTH_BYTES = 96;
    /** Directories past this count are recorded in the checked map instead. */
    private const MAX_DIRECTORIES = 10000;
    /** Measured on PHP 7.4 and 8.4: about 138 bytes per media path, so the table stays near 34 MiB. */
    private const MAX_PATHS = 250000;
    /** Room kept per media path for the next doubling of the table (138 bytes plus the new bucket array, rounded up). */
    private const PATH_GROWTH_BYTES = 256;
    /**
     * Media paths kept in memory per partition of the path map (the first three hex digits of sha256(key)). Path records
     * all have one encoded length (PATH_RECORD_BYTES), so this many fill about 650 KiB, far below the 4 MiB at which a map
     * partition splits. Reaching it moves every path to the map first, so paths held in memory always form partitions the
     * map would never split, whatever the keys, and their map order is known exactly.
     */
    private const PATH_PARTITION_RECORDS = 4096;
    /** Encoded length of one path record in a map partition: "<sha256(key)>":{"key":"<key>","value":{"flags":N}}, plus a separator. */
    public const PATH_RECORD_BYTES = 162;
    private const PATH_PUBLIC = 1;
    private const PATH_FORM = 2;
    /**
     * Media path flags of the current preparation, in the order they were first written, while they fit. Null once they
     * have moved to $pathMap, which then answers every question with its checked reads as before.
     */
    private ?array $paths = null;
    /** Path records per map partition while they are in memory. */
    private array $pathPartitions = [];
    /** Media paths only. Post records never share it, so its partitions hold records of one fixed length. */
    private ?MetadataMap $pathMap = null;
    private int $pathLimit;
    private int $partitionLimit;
    private ?int $pathBudget = null;

    /** $pathLimit and $partitionLimit only lower the media paths kept in memory; tests use them to move paths part way. */
    public function __construct($wpdb, PrivacyPolicy $policy, string $privateDirectory, int $pathLimit = self::MAX_PATHS, int $partitionLimit = self::PATH_PARTITION_RECORDS)
    {
        if ($pathLimit < 0 || $pathLimit > self::MAX_PATHS || $partitionLimit < 1 || $partitionLimit > self::PATH_PARTITION_RECORDS) { throw new RuntimeException('Invalid media path memory limit.'); }
        $this->wpdb = $wpdb;
        $this->policy = $policy;
        $this->directory = FileSafety::privateDirectory($privateDirectory);
        $this->pathLimit = $pathLimit;
        $this->partitionLimit = $partitionLimit;
    }

    public function summary(): array { return $this->summary; }

    /** Long row and media processing reports progress by time as well as by row count. */
    public function prepare(?callable $progress = null): string
    {
        $ticks = 0; $lastProgress = time();
        $pulse = static function () use ($progress, &$ticks, &$lastProgress): void {
            if ($progress !== null && time() - $lastProgress >= 15) {
                $progress(['phase' => 'prepare', 'entries' => $ticks]); $lastProgress = time();
            }
        };
        $tick = static function () use ($progress, $pulse, &$ticks, &$lastProgress): void {
            ++$ticks;
            if ($progress !== null && $ticks % 500 === 0) {
                $progress(['phase' => 'prepare', 'entries' => $ticks]); $lastProgress = time();
            } else { $pulse(); }
        };
        $public = $this->policy->needsPublicContentContext();
        $forms = $this->policy->needsFormSubmissionContext();
        $this->summary = ['unresolvedAttachments' => 0];
        if (!$public && !$forms) { return hash('sha256', 'no-policy-context'); }
        $prefix = $this->wpdb->prefix ?? 'wp_';
        if (!is_string($prefix)) { throw new RuntimeException('Cannot identify the WordPress content tables.'); }
        $posts = $prefix . 'posts'; $meta = $prefix . 'postmeta';
        self::identifier($posts); self::identifier($meta);
        if (function_exists('wp_upload_dir')) {
            $uploads = \wp_upload_dir(null, false);
            if (is_array($uploads) && is_string($uploads['basedir'] ?? null) && empty($uploads['error'])) { $this->uploadsDirectory = rtrim($uploads['basedir'], '/'); }
        } elseif (defined('ABSPATH')) { $this->uploadsDirectory = rtrim((string) constant('ABSPATH'), '/') . '/wp-content/uploads'; }
        // Post decisions are built from the rows read under the same lock. While they fit, they stay in this process's table: every row
        // asks about its post several times, and the file-backed map made each question a file read. Past MAX_POSTS, or when memory
        // runs short, they move to the checked map and are read from it on every question, as before. Both give the same digest.
        $rebuilding = static function (): bool { throw new RuntimeException('The policy context is being rebuilt.'); };
        $this->policy->setPublicContentLookups($rebuilding, $rebuilding, $rebuilding);
        $this->policy->setFormSubmissionLookups($rebuilding, $rebuilding);
        $map = MetadataMap::createProcessLocal($this->directory . '/policy-' . bin2hex(random_bytes(16)));
        $this->directories = [];
        $postBits = []; $attachments = []; $inMemory = true; $budget = self::memoryBudget();
        $this->paths = []; $this->pathPartitions = []; $this->pathMap = null; $this->pathBudget = $budget;
        $postDigest = hash_init('sha256'); $postCount = 0;
        $hasPosts = false; $hasMeta = false;
        foreach ($this->results('SHOW FULL TABLES') as $table) {
            $values = array_values($table);
            if (($values[1] ?? '') !== 'BASE TABLE') { continue; }
            $hasPosts = $hasPosts || ($values[0] ?? '') === $posts;
            $hasMeta = $hasMeta || ($values[0] ?? '') === $meta;
        }
        $public = $public && $hasPosts && $this->policy->tableAction($posts)['action'] === 'include';
        $types = $public && function_exists('get_post_types') ? \get_post_types(['public' => true], 'names') : ['post', 'page'];
        if (!is_array($types)) { throw new RuntimeException('Cannot inspect the registered public content types.'); }
        $publicTypes = [];
        foreach ($types as $type) { if (is_string($type) && $type !== '' && strlen($type) <= 32) { $publicTypes[$type] = true; } }
        $configTypes = array_fill_keys(['acf-field', 'acf-field-group', 'wp_block', 'wp_global_styles', 'wp_navigation', 'wpcf7_contact_form', 'lazyblocks', 'scs_search_group'], true);
        if ($hasPosts && ($public || $forms)) {
            $fields = $this->fields($posts, ['ID', 'post_status', 'post_type', 'post_parent', 'post_password', 'post_mime_type']);
            $lastId = '';
            foreach ($this->rows($posts, $fields, '', ' ORDER BY `ID`') as $row) {
                $tick();
                $id = (string) ($row['ID'] ?? ''); $parent = (string) ($row['post_parent'] ?? '');
                if (!self::id($id)) { continue; }
                if (!self::after($id, $lastId)) { throw new RuntimeException('Posts were not read once each in ID order for the policy context.'); }
                $lastId = $id; ++$postCount;
                $type = $row['post_type'] ?? ''; $status = $row['post_status'] ?? '';
                $bits = ($forms && $type === 'flamingo_inbound' ? self::FORM : 0) | ($type === 'attachment' ? self::ATTACHMENT : 0);
                if ($public && !$this->policy->isExplicitlyExcludedRow($posts, $row) && ($row['post_password'] ?? null) === '' && is_string($type)
                    && !preg_match('/(?:flamingo|submission|inbound|customer|session|order)/i', $type)) {
                    if ($type === 'attachment') {
                        $hasParent = self::id($parent) || $parent === '0';
                        if ($status === 'inherit' && $hasParent && is_string($row['post_mime_type'] ?? null) && strpos($row['post_mime_type'], 'image/') === 0) { $bits |= self::IMAGE; }
                    } elseif ($status === 'publish') {
                        $bits |= isset($configTypes[$type]) ? self::CONFIG : (isset($publicTypes[$type]) ? self::PUBLIC : 0);
                    }
                }
                $attachmentParent = $type === 'attachment' ? (self::id($parent) || $parent === '0' ? $parent : '') : null;
                if ($inMemory && ($postCount > self::MAX_POSTS || ($postCount % 1024 === 1 && $budget !== null && memory_get_usage() + self::GROWTH_BYTES * $postCount > $budget))) {
                    foreach ($postBits as $known => $knownBits) { $tick(); $map->put(self::key('post', (string) $known), ['bits' => $knownBits, 'parent' => $attachments[$known] ?? null]); }
                    $postBits = []; $attachments = []; $inMemory = false;
                }
                if ($inMemory) {
                    $postBits[$id] = $bits;
                    if ($attachmentParent !== null) { $attachments[$id] = $attachmentParent; }
                } else {
                    $map->put(self::key('post', $id), ['bits' => $bits, 'parent' => $attachmentParent]);
                }
            }
            if ($inMemory) {
                foreach ($attachments as $id => $parent) {
                    $tick();
                    $postBits[$id] = $this->attachmentBits($postBits[$id], (string) $parent, $parent === '' ? null : ($postBits[$parent] ?? null), $forms);
                }
                foreach ($postBits as $id => $bits) { $tick(); hash_update($postDigest, 'post/' . $id . ':' . $bits . (isset($attachments[$id]) ? ':' . $attachments[$id] : '') . "\n"); }
            } else {
                // The map has no ID order, so the posts are read again in order under the same snapshot to settle attachments and the digest.
                $lastId = ''; $seen = 0;
                foreach ($this->rows($posts, ['ID'], '', ' ORDER BY `ID`') as $row) {
                    $tick();
                    $id = (string) ($row['ID'] ?? '');
                    if (!self::id($id)) { continue; }
                    if (!self::after($id, $lastId)) { throw new RuntimeException('Posts were not read once each in ID order for the policy context.'); }
                    $lastId = $id; ++$seen;
                    $record = $map->get(self::key('post', $id));
                    if ($record === null) { throw new RuntimeException('Posts changed while the policy context was read.'); }
                    if ($record['parent'] !== null) {
                        $parent = $record['parent'] === '' ? null : $map->get(self::key('post', $record['parent']));
                        $record['bits'] = $this->attachmentBits($record['bits'], $record['parent'], $parent['bits'] ?? null, $forms);
                        $map->put(self::key('post', $id), $record, true);
                    }
                    hash_update($postDigest, 'post/' . $id . ':' . $record['bits'] . ($record['parent'] !== null ? ':' . $record['parent'] : '') . "\n");
                }
                if ($seen !== $postCount) { throw new RuntimeException('Posts changed while the policy context was read.'); }
            }
        }
        $bitsOf = $inMemory
            ? static function (string $id) use ($postBits): int { return $postBits[$id] ?? 0; }
            : static function (string $id) use ($map): int { return $map->get(self::key('post', $id))['bits'] ?? 0; };
        if ($hasMeta && ($forms || ($public && $this->policy->tableAction($meta)['action'] === 'include'))) {
            $fields = $this->fields($meta, ['meta_id', 'post_id', 'meta_key', 'meta_value']);
            $lastId = ''; $attached = null;
            foreach ($this->rows($meta, $fields, " WHERE `meta_key` IN ('_wp_attached_file','_wp_attachment_metadata')", " ORDER BY `post_id`,CASE WHEN `meta_key` = '_wp_attached_file' THEN 0 ELSE 1 END,`meta_id`") as $row) {
                $tick();
                $id = (string) ($row['post_id'] ?? '');
                if ($id !== $lastId) { $lastId = $id; $attached = null; }
                $post = $bitsOf($id);
                if (($post & self::ATTACHMENT) === 0 || ($post & (self::PUBLIC | self::FORM)) === 0) { continue; }
                $isPublic = $public && ($post & self::PUBLIC) !== 0 && !$this->policy->isExplicitlyExcludedRow($meta, $row);
                $isForm = $forms && ($post & self::FORM) !== 0;
                if (!$isPublic && !$isForm) { continue; }
                $value = $row['meta_value'] ?? null;
                if ($row['meta_key'] === '_wp_attached_file') {
                    $attached = self::mediaPath($value, !$isForm);
                    if ($attached !== null) { $this->media($attached, $isPublic, $isForm, $pulse); }
                    continue;
                }
                if (!is_string($value)) { continue; }
                if (strlen($value) > 1048576) { throw new RuntimeException('Attachment metadata exceeds its policy validation limit.'); }
                $data = @unserialize($value, ['allowed_classes' => false, 'max_depth' => 16]);
                if (!is_array($data)) { continue; }
                $base = array_key_exists('file', $data) ? self::mediaPath($data['file'], !$isForm) : $attached;
                if ($base === null) { continue; }
                $this->media($base, $isPublic, $isForm, $pulse);
                $names = [$data['original_image'] ?? null];
                foreach (is_array($data['sizes'] ?? null) ? $data['sizes'] : [] as $size) { $pulse(); if (is_array($size)) { $names[] = $size['file'] ?? null; } }
                foreach ($names as $name) {
                    $pulse();
                    if (!is_string($name) || strpos($name, '/') !== false || strpos($name, '\\') !== false) { continue; }
                    $path = self::mediaPath(substr(dirname($base) . '/' . $name, strlen('wp-content/uploads/')), !$isForm);
                    if ($path !== null) { $this->media($path, $isPublic, $isForm, $pulse); }
                }
            }
        }
        $directories = $this->directories;
        ksort($directories, SORT_STRING);
        $paths = $this->paths; $pathMap = $this->pathMap;
        $pathFlag = $paths === null
            ? static function (string $path, string $field) use ($pathMap): bool { return $pathMap !== null && ((($pathMap->get(self::key('path', $path))['flags'] ?? 0) & ($field === 'public' ? self::PATH_PUBLIC : self::PATH_FORM)) !== 0); }
            : static function (string $path, string $field) use ($paths): bool { return (($paths[self::key('path', $path)] ?? 0) & ($field === 'public' ? self::PATH_PUBLIC : self::PATH_FORM)) !== 0; };
        $this->policy->setPublicContentLookups(static function (string $id) use ($bitsOf): bool { return ($bitsOf($id) & self::PUBLIC) !== 0; },
            static function (string $path) use ($pathFlag, $directories): bool { return isset($directories[$path]) || $pathFlag($path, 'public'); },
            static function (string $id) use ($bitsOf): bool { return ($bitsOf($id) & self::CONFIG) !== 0; });
        $this->policy->setFormSubmissionLookups(static function (string $id) use ($bitsOf): bool { return ($bitsOf($id) & self::FORM) !== 0; },
            static function (string $path) use ($pathFlag): bool { return $pathFlag($path, 'form'); });
        $digest = hash_init('sha256');
        hash_update($digest, hash_final($postDigest) . "\n");
        foreach ($directories as $path => $included) { hash_update($digest, 'directory/' . $path . "\n"); }
        // Path records are hashed in the path map's order wherever they are kept, so moving them never changes the digest.
        // The post digest above covers post records, which the other map holds only after a move.
        if ($paths !== null) {
            foreach (self::mapOrder($paths, $pulse) as $key) { $tick(); hash_update($digest, $key . V4Format::json(self::pathRecord($paths[$key])) . "\n"); }
        } elseif ($pathMap !== null) {
            foreach ($pathMap->entries() as $key => $record) { $tick(); hash_update($digest, $key . V4Format::json($record) . "\n"); }
        }
        hash_update($digest, V4Format::json($this->summary));
        return hash_final($digest);
    }

    private function media(string $path, bool $public, bool $form, callable $pulse): void
    {
        $this->mediaRecord($path, $public, $form, $pulse);
        if ($public) {
            for ($parent = dirname($path); $parent !== 'wp-content' && $parent !== '.'; $parent = dirname($parent)) {
                $pulse();
                if (isset($this->directories[$parent])) { continue; }
                if (count($this->directories) < self::MAX_DIRECTORIES) { $this->directories[$parent] = true; } else { $this->mediaRecord($parent, true, false, $pulse); }
            }
        }
        foreach (['webp', 'avif'] as $extension) {
            foreach ([$path . '.' . $extension, preg_replace('/\.[^.\/]+\z/D', '.' . $extension, $path)] as $variant) {
                $pulse();
                if (!is_string($variant)) { continue; }
                $physical = $this->physicalMediaPath($variant);
                if ($physical !== null && is_file($physical) && !is_link($physical)) { $this->mediaRecord($variant, $public, $form, $pulse); }
            }
        }
    }

    private function attachmentBits(int $bits, string $parent, ?int $parentBits, bool $forms): int
    {
        $bits &= ~(self::PUBLIC | self::FORM);
        $parentIsPost = $parentBits !== null && ($parentBits & self::ATTACHMENT) === 0;
        if (($bits & self::IMAGE) !== 0 && ($parent === '0' || ($parentIsPost && ($parentBits & self::PUBLIC) !== 0))) { $bits |= self::PUBLIC; }
        if ($forms && $parentIsPost && ($parentBits & self::FORM) !== 0) { $bits |= self::FORM; }
        if ($forms && ($bits & self::FORM) === 0 && $parent !== '0' && $parentBits === null) { ++$this->summary['unresolvedAttachments']; }
        return $bits;
    }

    /** Half of PHP's memory limit; null when unlimited. An unreadable limit gives 0, which sends posts to the checked map. */
    private static function memoryBudget(): ?int
    {
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '-1') { return null; }
        if (!preg_match('/\A([1-9][0-9]{0,12})([KMG]?)\z/Di', $limit, $match)) { return 0; }
        return intdiv((int) $match[1] * ['' => 1, 'K' => 1024, 'M' => 1048576, 'G' => 1073741824][strtoupper($match[2])], 2);
    }

    private static function after(string $id, string $last): bool { return strlen($id) > strlen($last) || (strlen($id) === strlen($last) && strcmp($id, $last) > 0); }

    private function mediaRecord(string $path, bool $public, bool $form, callable $pulse): void
    {
        $key = self::key('path', $path);
        $bits = ($public ? self::PATH_PUBLIC : 0) | ($form ? self::PATH_FORM : 0);
        if ($this->paths !== null) {
            // An existing record keeps its place, as a replaced map record does.
            if (isset($this->paths[$key])) { $this->paths[$key] |= $bits; return; }
            $count = count($this->paths); $partition = self::partition($key);
            if ($count < $this->pathLimit && ($this->pathPartitions[$partition] ?? 0) < $this->partitionLimit
                && ($count % 1024 !== 0 || $this->pathBudget === null || memory_get_usage() + self::PATH_GROWTH_BYTES * ($count + 1024) <= $this->pathBudget)) {
                $this->paths[$key] = $bits; $this->pathPartitions[$partition] = ($this->pathPartitions[$partition] ?? 0) + 1; return;
            }
            // Past a limit or short of memory: hand every record to the path map in the order it was made, then use the map as before.
            $this->pathMap = MetadataMap::createProcessLocal($this->directory . '/policy-paths-' . bin2hex(random_bytes(16)));
            foreach ($this->paths as $known => $knownBits) { $pulse(); $this->pathMap->put((string) $known, self::pathRecord($knownBits)); }
            $this->paths = null; $this->pathPartitions = [];
        }
        if ($this->pathMap === null) { $this->pathMap = MetadataMap::createProcessLocal($this->directory . '/policy-paths-' . bin2hex(random_bytes(16))); }
        $old = $this->pathMap->get($key)['flags'] ?? 0;
        $this->pathMap->put($key, self::pathRecord($old | $bits), true);
    }

    /** One fixed-length value, so a map partition's size depends only on how many paths it holds, never on their flags. */
    private static function pathRecord(int $bits): array
    {
        return ['flags' => $bits];
    }

    /** The path map partition of a key: MetadataMap places it by the first three hex digits of sha256(key). */
    private static function partition(string $key): string
    {
        return substr(hash('sha256', $key), 0, 3);
    }

    /**
     * The order the path map's entries() gives these keys: partitions ascending, and records within a partition in the order
     * they were first written. That holds while no partition splits, which PATH_PARTITION_RECORDS guarantees here.
     */
    private static function mapOrder(array $paths, callable $pulse): array
    {
        $partitions = [];
        foreach ($paths as $key => $bits) { $pulse(); $partitions[self::partition((string) $key)][] = (string) $key; }
        ksort($partitions, SORT_STRING);
        return $partitions === [] ? [] : array_merge(...array_values($partitions));
    }

    private function physicalMediaPath(string $path): ?string
    {
        return $this->uploadsDirectory !== null ? $this->uploadsDirectory . '/' . substr($path, strlen('wp-content/uploads/')) : null;
    }

    private static function mediaPath($relative, bool $image): ?string
    {
        if (!is_string($relative) || $relative === '' || strlen($relative) > 1024 || $relative[0] === '/'
            || preg_match('~[\\\\\x00-\x1f\x7f:%?#]|//|(?:^|/)\.{1,2}(?:/|$)~', $relative)
            || ($image && !preg_match('/\.(?:jpe?g|png|gif|webp|avif|svg|ico|bmp|tiff?)\z/iD', $relative))) { return null; }
        return 'wp-content/uploads/' . $relative;
    }

    private function fields(string $table, array $base): array
    {
        $legacy = $this->policy->publicContextRules(); $rules = $this->policy->rules();
        return array_values(array_unique(array_merge($base, array_keys($legacy['excludedRows'][$table] ?? []), array_keys($rules['rows'][$table] ?? []))));
    }

    private function rows(string $table, array $fields, string $where = '', string $order = ''): Generator
    {
        $sql = 'SELECT ' . implode(',', array_map([self::class, 'identifier'], $fields)) . ' FROM ' . self::identifier($table) . $where . $order;
        $handle = null;
        if (property_exists($this->wpdb, 'dbh')) { $property = new \ReflectionProperty($this->wpdb, 'dbh'); $property->setAccessible(true); $handle = $property->getValue($this->wpdb); }
        if ($handle instanceof \mysqli) {
            $projection = implode(',', array_map(static function (string $field) use ($table): string {
                return 'CAST(' . self::identifier($table) . '.' . self::identifier($field) . ' AS BINARY) AS ' . self::identifier($field);
            }, $fields));
            $nativeOrder = preg_replace_callback('/`([A-Za-z_][A-Za-z0-9_]*)`/', static function (array $match) use ($table): string {
                return self::identifier($table) . '.' . self::identifier($match[1]);
            }, $order);
            $sql = 'SELECT ' . $projection . ' FROM ' . self::identifier($table) . $where . $nativeOrder;
            yield from MysqlRowCursor::rows($handle, $sql);
            return;
        }
        for ($offset = 0; ; ++$offset) {
            $rows = $this->results($sql . ' LIMIT ' . $offset . ',1');
            if ($rows === []) { break; }
            if (count($rows) !== 1) { throw new RuntimeException('The policy context row read was not bounded.'); }
            yield $rows[0];
        }
    }

    private function results(string $sql): array
    {
        $rows = $this->wpdb->get_results($sql, 'ARRAY_A');
        if (!is_array($rows) || ($this->wpdb->last_error ?? '') !== '') { throw new RuntimeException('Cannot read policy context.'); }
        return $rows;
    }

    private static function id(string $id): bool { return preg_match('/\A[1-9][0-9]{0,19}\z/D', $id) === 1; }
    private static function key(string $kind, string $value): string { return hash('sha256', $kind . '/' . $value); }
    private static function identifier(string $name): string
    {
        if (!preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,63}\z/D', $name)) { throw new RuntimeException('Invalid policy context identifier.'); }
        return '`' . $name . '`';
    }
}
