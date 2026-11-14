<?php
declare(strict_types=1);
namespace WordPressR2Backup;

use RuntimeException;

final class WordPressSource
{
    private string $root;
    private $wpdb;
    private PrivacyPolicy $policy;
    private string $privateDirectory;
    private const MAX_ENTRIES = 100000;
    private const MAX_PUBLIC_CONTEXT_ENTRIES = 500000;
    private const MAX_PUBLIC_MEDIA_PATH_BYTES = 33554432;
    private ?bool $priorErrorSuppression = null;

    public function __construct(string $root, $wpdb, PrivacyPolicy $policy, string $privateDirectory)
    {
        $resolved = realpath($root);
        if ($resolved === false || !is_dir($resolved) || is_link($root) || !is_object($wpdb)) { throw new RuntimeException('The WordPress source is unavailable.'); }
        $this->root = rtrim($resolved, DIRECTORY_SEPARATOR);
        $this->privateDirectory = FileSafety::privateDirectory($privateDirectory);
        if ($this->privateDirectory === $this->root || strpos($this->privateDirectory, $this->root . DIRECTORY_SEPARATOR) === 0) { throw new RuntimeException('The private backup directory must be outside the source.'); }
        $this->wpdb = $wpdb;
        $this->policy = $policy;
        if (method_exists($wpdb, 'suppress_errors')) { $this->priorErrorSuppression = $wpdb->suppress_errors(true); }
    }

    public function __destruct()
    {
        if ($this->priorErrorSuppression !== null) { $this->wpdb->suppress_errors($this->priorErrorSuppression); }
    }

    public function scan(?callable $progress = null): array
    {
        $tables = $this->tableMetadata();
        $this->preparePublicContentContext($tables);
        $unresolved = 0; $scannedRows = 0;
        foreach ($tables as &$table) {
            $decision = $this->policy->tableAction($table['name']);
            $table['action'] = $decision['action'];
            $table['reason'] = $decision['reason'];
            $fields = [];
            foreach ($table['columns'] as $column) {
                $field = $this->policy->fieldAction($table['name'], $column['Field']);
                $fields[$column['Field']] = ['name' => $column['Field'], 'type' => $column['Type'], 'action' => $field['action'], 'reason' => $field['reason']];
            }
            if ($table['action'] === 'include') {
                foreach ($this->rows($table['name']) as $row) {
                    if ($progress !== null && ++$scannedRows % 100 === 0) { $progress(['phase' => 'scanning_database', 'completed' => $scannedRows]); }
                    if (!$this->policy->includeRow($table['name'], $row)) { continue; }
                    foreach ($row as $name => $value) {
                        if (isset($fields[$name]) && $fields[$name]['action'] === 'include' && is_string($value)
                            && $this->policy->shouldInspectField($table['name'], $name, $row) && $this->policy->containsPersonalData($value)) {
                            $fields[$name]['action'] = 'review'; $fields[$name]['reason'] = 'personal_text_candidate';
                        }
                    }
                }
                foreach ($fields as $field) { if ($field['action'] === 'review') { $unresolved++; } }
            } elseif ($table['action'] === 'review') { $unresolved++; }
            $table['fields'] = array_values($fields);
            unset($table['columns'], $table['create']);
        }
        unset($table);
        $files = []; $filesTotal = 0; $largestIncludedFile = 0;
        foreach ($this->walkInventory() as $entry) {
            $decision = $this->fileDecision($entry);
            $filesTotal++;
            if ($entry['type'] === 'file' && $decision['action'] === 'include') { $largestIncludedFile = max($largestIncludedFile, $entry['stat']['size']); }
            if (count($files) < 500) { $files[] = ['path' => $entry['path'], 'action' => $decision['action'], 'reason' => $decision['reason']]; }
            if ($decision['action'] === 'review') { $unresolved++; }
            if ($progress !== null && $filesTotal % 100 === 0) { $progress(['phase' => 'scanning_files', 'completed' => $filesTotal]); }
        }
        return ['tables' => $tables, 'files' => $files, 'filesTotal' => $filesTotal, 'filesOmitted' => $filesTotal - count($files), 'largestIncludedFile' => $largestIncludedFile, 'unresolved' => $unresolved];
    }

    /** Capture bounded groups of files directly into encrypted portable tar streams. */
    public function captureArchives(AgeProcess $age, array $recipients, string $snapshotId, ?callable $progress = null): array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $snapshotId) !== 1) { throw new RuntimeException('Invalid snapshot identifier.'); }
        $scan = $this->scan($progress);
        if ($scan['unresolved'] !== 0) { throw new RuntimeException('Privacy decisions are required before backup.'); }
        if ($scan['largestIncludedFile'] > 5 * 1024 * 1024 * 1024 - 1048576) { throw new RuntimeException('A source file exceeds the single-object archive limit.'); }
        $started = gmdate('Y-m-d\TH:i:s\Z');
        $directory = FileSafety::createPrivateDirectory($this->privateDirectory . '/source-' . $snapshotId);
        $tables = $this->tableMetadata(); $this->assertSupportedDatabase($tables);
        if (!in_array(strtolower((string) $this->scalar('SELECT @@character_set_connection')), ['utf8', 'utf8mb3', 'utf8mb4'], true)) { throw new RuntimeException('The source connection must use UTF-8 for portable export.'); }
        $connection = (string) $this->scalar('SELECT CONNECTION_ID()');
        $this->execute('LOCK TABLES ' . implode(', ', array_map(function (array $table): string { return self::identifier($table['name']) . ' READ'; }, $tables)));
        $exported = []; $excluded = ['tables' => 0, 'fields' => 0, 'files' => 0, 'rows' => 0];
        $archives = []; $fileCount = 0; $directoryCount = 0;
        try {
            if ($this->tableMetadata() !== $tables) { throw new RuntimeException('The database structure changed before capture.'); }
            $this->preparePublicContentContext($tables);
            $databaseFile = $directory . '/database.age';
            $database = $age->encryptStream($this->progressChunks($this->databaseChunks($tables, $exported, $excluded, $connection), $progress, 'encrypting_database'), $databaseFile, $recipients);
            $database['cipherFile'] = $databaseFile;
            $digest = hash_init('sha256'); $batch = []; $bytes = 0;
            foreach ($this->walkInventory() as $entry) {
                $decision = $this->fileDecision($entry);
                if ($decision['action'] === 'exclude') { $excluded['files']++; continue; }
                if ($decision['action'] !== 'include' || !in_array($entry['type'], ['file', 'directory'], true)) { throw new RuntimeException('A file needs a privacy decision.'); }
                if ($entry['type'] === 'file' && $entry['stat']['size'] > 5 * 1024 * 1024 * 1024 - 1048576) { throw new RuntimeException('A source file exceeds the single-object archive limit.'); }
                $size = $entry['type'] === 'file' ? $entry['stat']['size'] : 0;
                if ($batch !== [] && (count($batch) >= 5000 || $bytes + $size > 512 * 1024 * 1024)) {
                    $archives[] = $this->encryptArchive($batch, $digest, $age, $recipients, $directory, $progress, $fileCount, $directoryCount);
                    $batch = []; $bytes = 0;
                }
                $batch[] = $entry; $bytes += $size;
            }
            if ($batch !== []) { $archives[] = $this->encryptArchive($batch, $digest, $age, $recipients, $directory, $progress, $fileCount, $directoryCount); }
            $capturedDigest = hash_final($digest); $verify = hash_init('sha256'); $verified = 0;
            if ($progress !== null) { $progress(['phase' => 'verifying_files', 'completed' => 0]); }
            foreach ($this->walkInventory() as $entry) {
                $decision = $this->fileDecision($entry);
                if ($decision['action'] === 'exclude') { continue; }
                if ($decision['action'] !== 'include' || !in_array($entry['type'], ['file', 'directory'], true)) { throw new RuntimeException('The source file inventory changed during capture.'); }
                $this->digestEntry($verify, $entry);
                if ($entry['type'] === 'file') {
                    $content = hash_init('sha256');
                    foreach ($this->progressChunks($this->fileChunks($this->root . '/' . $entry['path'], $entry['stat']), $progress, 'verifying_files') as $chunk) { hash_update($content, $chunk); }
                    hash_update($verify, hash_final($content));
                }
                if ($progress !== null && ++$verified % 100 === 0) { $progress(['phase' => 'verifying_files', 'completed' => $verified]); }
            }
            if (!hash_equals($capturedDigest, hash_final($verify)) || $this->tableMetadata() !== $tables || (string) $this->scalar('SELECT CONNECTION_ID()') !== $connection) { throw new RuntimeException('The WordPress source changed during capture.'); }
        } finally { $this->execute('UNLOCK TABLES'); }
        return ['files' => [], 'archives' => $archives, 'database' => $database, 'evidence' => ['tables' => $exported, 'events' => [], 'exclusions' => $excluded, 'fileCount' => $fileCount, 'directoryCount' => $directoryCount, 'startedAt' => $started, 'completedAt' => gmdate('Y-m-d\TH:i:s\Z')]];
    }

    private function encryptArchive(array $batch, $digest, AgeProcess $age, array $recipients, string $directory, ?callable $progress, int &$fileCount, int &$directoryCount): array
    {
        $files = 0; $directories = 0;
        $entries = (function () use ($batch, $digest, $progress, &$files, &$directories, &$fileCount, &$directoryCount): \Generator {
            foreach ($batch as $entry) {
                if ($this->fileStat($this->root . '/' . $entry['path']) !== $entry['stat']) { throw new RuntimeException('A source entry changed before capture.'); }
                $this->digestEntry($digest, $entry);
                if ($entry['type'] === 'directory') { $directories++; $directoryCount++; yield ['path' => $entry['path'], 'type' => 'directory', 'size' => 0, 'chunks' => []]; }
                else {
                    $content = hash_init('sha256');
                    $chunks = (function () use ($entry, $content, $progress): \Generator {
                        foreach ($this->progressChunks($this->fileChunks($this->root . '/' . $entry['path'], $entry['stat']), $progress, 'encrypting_files') as $chunk) { hash_update($content, $chunk); yield $chunk; }
                    })();
                    yield ['path' => $entry['path'], 'type' => 'file', 'size' => $entry['stat']['size'], 'chunks' => $chunks];
                    hash_update($digest, hash_final($content)); $files++; $fileCount++;
                }
                if ($progress !== null && ($fileCount + $directoryCount) % 100 === 0) { $progress(['phase' => 'encrypting_files', 'completed' => $fileCount + $directoryCount]); }
            }
        })();
        $cipher = $directory . '/' . bin2hex(random_bytes(16)) . '.age';
        $result = $age->encryptStream(BackupArchive::chunks($entries), $cipher, $recipients);
        $result['cipherFile'] = $cipher; $result['fileCount'] = $files; $result['directoryCount'] = $directories;
        if ($progress !== null) { $progress(['phase' => 'archive_complete', 'completed' => $fileCount + $directoryCount]); }
        return $result;
    }

    private function digestEntry($digest, array $entry): void
    {
        $encoded = json_encode([$entry['path'], $entry['type'], $entry['stat']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        hash_update($digest, strlen($encoded) . ':' . $encoded);
    }

    private function progressChunks(iterable $chunks, ?callable $progress, string $phase): \Generator
    {
        $bytes = 0; $reported = 0;
        if ($progress !== null && $phase === 'encrypting_database') { $progress(['phase' => $phase, 'bytes' => 0]); }
        foreach ($chunks as $chunk) {
            $bytes += strlen($chunk);
            if ($progress !== null && $bytes - $reported >= 32 * 1024 * 1024) { $reported = $bytes; $progress(['phase' => $phase, 'bytes' => $bytes]); }
            yield $chunk;
        }
    }

    private function walkInventory(): \Generator
    {
        $count = 0;
        $visit = function (string $directory, string $relative) use (&$visit, &$count): \Generator {
            $names = scandir($directory);
            if ($names === false) { throw new RuntimeException('Cannot list a source directory.'); }
            foreach ($names as $name) {
                if ($name === '.' || $name === '..') { continue; }
                if (++$count > 500000) { throw new RuntimeException('The source contains too many entries.'); }
                $path = $relative === '' ? $name : $relative . '/' . $name; $absolute = $directory . '/' . $name;
                $stat = $this->fileStat($absolute);
                $type = $stat['type'] === 0040000 ? 'directory' : ($stat['type'] === 0100000 ? 'file' : 'unsupported');
                yield ['path' => $path, 'type' => $type, 'stat' => $stat];
                if ($type === 'directory' && $this->policy->pathAction($path)['action'] !== 'exclude') { yield from $visit($absolute, $path); }
            }
        };
        yield from $visit($this->root, '');
    }

    public function capture(AgeProcess $age, array $recipients, string $snapshotId, ?callable $progress = null): array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $snapshotId) !== 1) { throw new RuntimeException('Invalid snapshot identifier.'); }
        if ($this->scan()['unresolved'] !== 0) { throw new RuntimeException('Privacy decisions are required before backup.'); }
        $started = gmdate('Y-m-d\TH:i:s\Z');
        $directory = FileSafety::createPrivateDirectory($this->privateDirectory . '/source-' . $snapshotId);
        $inventory = $this->inventory();
        $tables = $this->tableMetadata();
        $this->assertSupportedDatabase($tables);
        if (!in_array(strtolower((string) $this->scalar('SELECT @@character_set_connection')), ['utf8', 'utf8mb3', 'utf8mb4'], true)) { throw new RuntimeException('The source connection must use UTF-8 for a portable export.'); }
        $connection = (string) $this->scalar('SELECT CONNECTION_ID()');
        $this->execute('LOCK TABLES ' . implode(', ', array_map(function (array $table): string { return self::identifier($table['name']) . ' READ'; }, $tables)));
        $files = []; $exported = []; $excluded = ['tables' => 0, 'fields' => 0, 'files' => 0, 'rows' => 0];
        try {
            if ($this->tableMetadata() !== $tables) { throw new RuntimeException('The database structure changed before capture.'); }
            $this->preparePublicContentContext($tables);
            $databaseFile = $directory . '/database.age';
            $database = $age->encryptStream($this->databaseChunks($tables, $exported, $excluded, $connection), $databaseFile, $recipients);
            $database['cipherFile'] = $databaseFile;
            foreach ($inventory as $entry) {
                $decision = $this->fileDecision($entry);
                if ($decision['action'] === 'exclude') { $excluded['files']++; continue; }
                if ($entry['type'] === 'directory' && $decision['action'] === 'include') { $files[] = ['path' => $entry['path'], 'type' => 'directory']; continue; }
                if ($decision['action'] !== 'include' || $entry['type'] !== 'file') { throw new RuntimeException('A file needs a privacy decision.'); }
                $path = $this->root . '/' . $entry['path'];
                $cipher = $directory . '/' . bin2hex(random_bytes(16)) . '.age';
                $result = $age->encryptStream($this->fileChunks($path, $entry['stat']), $cipher, $recipients);
                clearstatcache(true, $path);
                if ($this->fileStat($path) !== $entry['stat'] || hash_file('sha256', $path) !== $result['sha256']) { throw new RuntimeException('A source file changed during capture.'); }
                $files[] = ['path' => $entry['path'], 'type' => 'file', 'size' => $result['size'], 'sha256' => $result['sha256'], 'cipherFile' => $cipher];
                if ($progress !== null) { $progress(['phase' => 'encrypting_files', 'completed' => count($files)]); }
            }
            foreach ($files as $entry) {
                if ($entry['type'] === 'file' && hash_file('sha256', $this->root . '/' . $entry['path']) !== $entry['sha256']) {
                    throw new RuntimeException('A previously captured source file changed.');
                }
            }
            if ($this->inventory() !== $inventory || $this->tableMetadata() !== $tables || (string) $this->scalar('SELECT CONNECTION_ID()') !== $connection) {
                throw new RuntimeException('The WordPress source changed during capture.');
            }
        } finally {
            $this->execute('UNLOCK TABLES');
        }
        return ['files' => $files, 'database' => $database, 'evidence' => ['tables' => $exported, 'events' => [], 'exclusions' => $excluded, 'startedAt' => $started, 'completedAt' => gmdate('Y-m-d\TH:i:s\Z')]];
    }

    private function preparePublicContentContext(array $tables): void
    {
        if (!$this->policy->needsPublicContentContext()) { return; }
        $prefix = $this->wpdb->prefix ?? 'wp_';
        if (!is_string($prefix)) { throw new RuntimeException('Cannot identify the WordPress content tables.'); }
        $postsTable = $prefix . 'posts'; $metaTable = $prefix . 'postmeta';
        $inventory = array_fill_keys(array_column($tables, 'name'), true);
        $published = []; $config = []; $attachments = []; $paths = []; $pathBytes = 0;
        if (!isset($inventory[$postsTable]) || $this->policy->tableAction($postsTable)['action'] !== 'include') {
            $this->policy->setPublicContentContext([], [], []); return;
        }
        $types = function_exists('get_post_types') ? \get_post_types(['public' => true], 'names') : ['post', 'page'];
        if (!is_array($types)) { throw new RuntimeException('Cannot inspect the registered public content types.'); }
        $publicTypes = [];
        foreach ($types as $type) { if (is_string($type) && $type !== '' && strlen($type) <= 32) { $publicTypes[$type] = true; } }
        $configTypes = array_fill_keys(['acf-field', 'acf-field-group', 'wp_block', 'wp_global_styles', 'wp_navigation', 'wpcf7_contact_form', 'lazyblocks', 'scs_search_group'], true);
        $postFields = array_values(array_unique(array_merge(['ID', 'post_status', 'post_type', 'post_parent', 'post_password', 'post_mime_type'], array_keys($this->policy->rules()['excludedRows'][$postsTable] ?? []))));
        $count = 0;
        // Complete each metadata-only stream before opening the next query on the same connection.
        foreach ($this->rows($postsTable, $postFields) as $row) {
            if (++$count > self::MAX_PUBLIC_CONTEXT_ENTRIES) { throw new RuntimeException('The public content inventory exceeds its validation limit.'); }
            if ($this->policy->isExplicitlyExcludedRow($postsTable, $row)) { continue; }
            $id = (string) ($row['ID'] ?? ''); $parent = (string) ($row['post_parent'] ?? '');
            $type = $row['post_type'] ?? ''; $status = $row['post_status'] ?? '';
            if (preg_match('/\A[1-9][0-9]{0,19}\z/D', $id) !== 1 || ($row['post_password'] ?? null) !== ''
                || !is_string($type) || preg_match('/(?:flamingo|submission|inbound|customer|session|order)/i', $type)) { continue; }
            if ($type === 'attachment') {
                if ($status === 'inherit' && preg_match('/\A(?:0|[1-9][0-9]{0,19})\z/D', $parent) === 1
                    && is_string($row['post_mime_type'] ?? null) && strpos($row['post_mime_type'], 'image/') === 0) { $attachments[$id] = $parent; }
            } elseif ($status === 'publish') {
                if (isset($configTypes[$type])) { $config[$id] = true; }
                elseif (isset($publicTypes[$type])) { $published[$id] = true; }
            }
        }
        $images = [];
        foreach ($attachments as $id => $parent) { if ($parent === '0' || isset($published[$parent])) { $images[$id] = true; } }
        unset($attachments);
        $public = $published + $images;
        unset($published);
        if ($images !== [] && isset($inventory[$metaTable]) && $this->policy->tableAction($metaTable)['action'] === 'include') {
            $metaFields = array_values(array_unique(array_merge(['meta_id', 'post_id', 'meta_value'], array_keys($this->policy->rules()['excludedRows'][$metaTable] ?? []))));
            $attached = []; $count = 0;
            foreach ($this->rows($metaTable, $metaFields, " WHERE `meta_key` = '_wp_attached_file'") as $row) {
                if (++$count > self::MAX_PUBLIC_CONTEXT_ENTRIES) { throw new RuntimeException('The public image inventory exceeds its validation limit.'); }
                if ($this->policy->isExplicitlyExcludedRow($metaTable, $row)) { continue; }
                $id = $row['post_id'] ?? '';
                if (!isset($images[$id])) { continue; }
                $path = $this->publicMediaPath($row['meta_value'] ?? null);
                if ($path !== null) { $attached[$id] = $path; $this->addPublicMediaPath($paths, $path, $pathBytes); }
            }
            $count = 0;
            foreach ($this->rows($metaTable, $metaFields, " WHERE `meta_key` = '_wp_attachment_metadata'") as $row) {
                if (++$count > self::MAX_PUBLIC_CONTEXT_ENTRIES) { throw new RuntimeException('The public image inventory exceeds its validation limit.'); }
                if ($this->policy->isExplicitlyExcludedRow($metaTable, $row)) { continue; }
                $id = $row['post_id'] ?? ''; $value = $row['meta_value'] ?? null;
                if (!isset($images[$id]) || !is_string($value)) { continue; }
                if (strlen($value) > 1048576) { throw new RuntimeException('Image metadata exceeds its public-content validation limit.'); }
                $metadata = @unserialize($value, ['allowed_classes' => false, 'max_depth' => 16]);
                if (!is_array($metadata)) { continue; }
                $base = array_key_exists('file', $metadata) ? $this->publicMediaPath($metadata['file']) : ($attached[$id] ?? null);
                if ($base === null) { continue; }
                $this->addPublicMediaPath($paths, $base, $pathBytes);
                $names = [$metadata['original_image'] ?? null];
                if (isset($metadata['sizes']) && is_array($metadata['sizes'])) {
                    foreach ($metadata['sizes'] as $size) { if (is_array($size)) { $names[] = $size['file'] ?? null; } }
                }
                foreach ($names as $name) {
                    if (!is_string($name) || strpos($name, '/') !== false || strpos($name, '\\') !== false) { continue; }
                    $path = $this->publicMediaPath(substr(dirname($base) . '/' . $name, strlen('wp-content/uploads/')));
                    if ($path !== null) { $this->addPublicMediaPath($paths, $path, $pathBytes); }
                }
            }
        }
        $this->policy->setPublicContentContext($public, $paths, $config);
    }

    private function publicMediaPath($relative): ?string
    {
        if (!is_string($relative) || $relative === '' || strlen($relative) > 1024 || $relative[0] === '/'
            || preg_match('~[\\\\\x00-\x1f\x7f:%?#]|//|(?:^|/)\.{1,2}(?:/|$)~', $relative)
            || !preg_match('/\.(?:jpe?g|png|gif|webp|avif|svg|ico|bmp|tiff?)\z/iD', $relative)) { return null; }
        return 'wp-content/uploads/' . $relative;
    }

    private function addPublicMediaPath(array &$paths, string $path, int &$bytes): void
    {
        $candidates = [$path];
        foreach (['webp', 'avif'] as $extension) {
            foreach ([$path . '.' . $extension, preg_replace('/\.[^.\/]+\z/D', '.' . $extension, $path)] as $variant) {
                if (is_string($variant) && is_file($this->root . '/' . $variant) && !is_link($this->root . '/' . $variant)) { $candidates[] = $variant; }
            }
        }
        foreach ($candidates as $candidate) {
            if (isset($paths[$candidate])) { continue; }
            if (count($paths) >= self::MAX_PUBLIC_CONTEXT_ENTRIES || $bytes + strlen($candidate) > self::MAX_PUBLIC_MEDIA_PATH_BYTES) { throw new RuntimeException('The public image path inventory exceeds its validation limit.'); }
            $paths[$candidate] = true; $bytes += strlen($candidate);
        }
    }

    private function tableMetadata(): array
    {
        $tables = [];
        foreach ($this->results('SHOW FULL TABLES') as $row) {
            $values = array_values($row); $name = $values[0] ?? ''; $type = $values[1] ?? '';
            if (!is_string($name) || $name === '' || $type !== 'BASE TABLE') { throw new RuntimeException('Database views require a supported export rule.'); }
            $columns = $this->results('SHOW FULL COLUMNS FROM ' . self::identifier($name));
            $create = $this->results('SHOW CREATE TABLE ' . self::identifier($name));
            $definition = isset($create[0]) ? array_values($create[0])[1] ?? null : null;
            if ($columns === [] || !is_string($definition) || strpos($definition, 'CREATE TABLE ') !== 0) { throw new RuntimeException('Cannot read a database definition.'); }
            foreach ($columns as $column) {
                if (!isset($column['Field'], $column['Type']) || preg_match('/GENERATED/i', (string) ($column['Extra'] ?? ''))) { throw new RuntimeException('Generated database fields require a supported export rule.'); }
            }
            $tables[] = ['name' => $name, 'rows' => (int) $this->scalar('SELECT COUNT(*) FROM ' . self::identifier($name)), 'columns' => $columns, 'create' => $definition];
        }
        usort($tables, static function (array $a, array $b): int { return strcmp($a['name'], $b['name']); });
        if ($tables === [] || count($tables) > 10000) { throw new RuntimeException('The database table inventory is invalid.'); }
        $excludedRows = $this->policy->rules()['excludedRows'];
        if ($excludedRows !== []) {
            $inventory = [];
            foreach ($tables as $table) { $inventory[$table['name']] = array_fill_keys(array_column($table['columns'], 'Field'), true); }
            foreach ($excludedRows as $table => $fields) {
                if (!isset($inventory[$table])) { throw new RuntimeException('An excluded-row table is not present in the database inventory.'); }
                foreach ($fields as $field => $values) {
                    if (!isset($inventory[$table][$field])) { throw new RuntimeException('An excluded-row field is not present in the database inventory.'); }
                }
            }
        }
        return $tables;
    }

    private function assertSupportedDatabase(array $tables): void
    {
        foreach (['TRIGGERS' => 'TRIGGER_SCHEMA', 'ROUTINES' => 'ROUTINE_SCHEMA', 'EVENTS' => 'EVENT_SCHEMA'] as $kind => $schema) {
            if ((int) $this->scalar('SELECT COUNT(*) FROM information_schema.' . $kind . ' WHERE ' . $schema . '=DATABASE()') !== 0) { throw new RuntimeException('Stored database definitions require a supported export rule.'); }
        }
        $inventory = array_fill_keys(array_column($tables, 'name'), true);
        foreach ($tables as $table) {
            if ($this->policy->containsPersonalData($table['create'])) { throw new RuntimeException('A database definition contains a personal-data candidate.'); }
            $identifier = '`[A-Za-z0-9_]{1,64}`';
            $unquoted = preg_replace("~'(?:[^'\\\\\r\n]|''|\\\\[^\r\n])*'~", '', $table['create']);
            $unquoted = $unquoted === null ? null : preg_replace('/' . $identifier . '/', '', $unquoted);
            if ($unquoted === null) { throw new RuntimeException('Database foreign keys require a supported privacy relationship rule.'); }
            $foreignKeys = preg_match_all('/\bFOREIGN\s+KEY\b/i', $unquoted);
            if ($foreignKeys === 0) { continue; }
            // Accept only the ordinary SHOW CREATE form; excluded relationships remain empty on restore.
            $columns = '\\([ \\t]*' . $identifier . '(?:[ \\t]*,[ \\t]*' . $identifier . ')*[ \\t]*\\)';
            $action = '(?:RESTRICT|CASCADE|SET NULL|NO ACTION)';
            $constraint = '/^[ \\t]*(?:CONSTRAINT[ \\t]+' . $identifier . '[ \\t]+)?FOREIGN[ \\t]+KEY[ \\t]*' . $columns
                . '[ \\t]+REFERENCES[ \\t]+`([A-Za-z0-9_]{1,64})`[ \\t]*' . $columns
                . '(?:[ \\t]+ON DELETE[ \\t]+' . $action . ')?(?:[ \\t]+ON UPDATE[ \\t]+' . $action . ')?[ \\t]*,?[ \\t]*\\r?$/mi';
            $references = []; $matched = preg_match_all($constraint, $table['create'], $references);
            if ($this->policy->tableAction($table['name'])['action'] !== 'exclude'
                || preg_match('/\\A[A-Za-z0-9_]{1,64}\\z/D', $table['name']) !== 1
                || $matched !== $foreignKeys || preg_match_all('/\\bREFERENCES\\b/i', $unquoted) !== $foreignKeys
                || preg_match('/[`\'"#]|\\/\\*|\\*\\/|--/', $unquoted)) {
                throw new RuntimeException('Database foreign keys require a supported privacy relationship rule.');
            }
            foreach ($references[1] as $reference) {
                if (!isset($inventory[$reference]) || $this->policy->tableAction($reference)['action'] !== 'exclude') {
                    throw new RuntimeException('Database foreign keys require a supported privacy relationship rule.');
                }
            }
        }
    }

    private function databaseChunks(array $tables, array &$exported, array &$excluded, string $connection): \Generator
    {
        yield "SET @WRB_OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n";
        foreach ($tables as $table) {
            yield $table['create'] . ";\n";
            $decision = $this->policy->tableAction($table['name']); $rows = 0;
            if ($decision['action'] === 'review') { throw new RuntimeException('A database table needs a privacy decision.'); }
            if ($decision['action'] === 'exclude') { $excluded['tables']++; }
            else {
                $columns = $table['columns'];
                foreach ($columns as $column) { if ($this->policy->fieldAction($table['name'], $column['Field'])['action'] === 'exclude') { $excluded['fields']++; } }
                $number = 0;
                foreach ($this->rows($table['name']) as $row) {
                    $number++;
                    if (!$this->policy->includeRow($table['name'], $row)) { $excluded['rows']++; continue; }
                    $values = [];
                    foreach ($columns as $column) {
                        if (!array_key_exists($column['Field'], $row)) { throw new RuntimeException('A database row has an unexpected shape.'); }
                        $value = $row[$column['Field']];
                        if ($this->policy->fieldAction($table['name'], $column['Field'])['action'] === 'include' && is_string($value)
                            && $this->policy->shouldInspectField($table['name'], $column['Field'], $row) && $this->policy->containsPersonalData($value)) { throw new RuntimeException('A new personal-data candidate needs review.'); }
                        $value = $this->policy->filterValue($table['name'], $column, $value, $number);
                        if ($value !== null && preg_match('/^(?:tinyint|smallint|mediumint|int|bigint|decimal|numeric|float|double|real)\b/i', $column['Type'])) {
                            if (!is_scalar($value) || !preg_match('/\A[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?\z/D', (string) $value)) { throw new RuntimeException('A numeric database value cannot be exported safely.'); }
                            $values[] = (string) $value;
                            continue;
                        }
                        $literal = $value === null ? 'NULL' : "X'" . bin2hex((string) $value) . "'";
                        $values[] = $value !== null && preg_match('/^(?:char|varchar|tinytext|text|mediumtext|longtext|enum|set|json|date|datetime|timestamp|time|year)\b/i', $column['Type'])
                            ? 'CONVERT(' . $literal . ' USING utf8mb4)' : $literal;
                    }
                    yield 'INSERT INTO ' . self::identifier($table['name']) . ' (' . implode(',', array_map(static function (array $column): string { return self::identifier($column['Field']); }, $columns)) . ') VALUES (' . implode(',', $values) . ");\n";
                    $rows++;
                }
            }
            if ((string) $this->scalar('SELECT CONNECTION_ID()') !== $connection) { throw new RuntimeException('The database capture connection changed.'); }
            $exported[] = ['name' => $table['name'], 'rows' => $rows, 'action' => $decision['action']];
        }
        yield "SET FOREIGN_KEY_CHECKS=1;\nSET SQL_MODE=@WRB_OLD_SQL_MODE;\n";
    }

    private function rows(string $table, array $fields = [], string $where = ''): \Generator
    {
        $select = $fields === [] ? '*' : implode(',', array_map([self::class, 'identifier'], $fields));
        $sql = 'SELECT ' . $select . ' FROM ' . self::identifier($table) . $where;
        $handle = null;
        if (property_exists($this->wpdb, 'dbh')) {
            $property = new \ReflectionProperty($this->wpdb, 'dbh'); $property->setAccessible(true); $handle = $property->getValue($this->wpdb);
        }
        if ($handle instanceof \mysqli) {
            $result = @mysqli_query($handle, $sql, MYSQLI_USE_RESULT);
            if (!($result instanceof \mysqli_result)) { throw new RuntimeException('Cannot stream a database table.'); }
            try { while (($row = mysqli_fetch_assoc($result)) !== null) { if ($row === false) { throw new RuntimeException('Cannot read a database row.'); } yield $row; } }
            finally { mysqli_free_result($result); }
            return;
        }
        $keys = [];
        foreach ($this->results('SHOW FULL COLUMNS FROM ' . self::identifier($table)) as $column) {
            if (($column['Key'] ?? '') === 'PRI') { $keys[] = self::identifier($column['Field']); }
        }
        if ($keys === []) { throw new RuntimeException('The database adapter needs a streaming connection or a primary key.'); }
        $offset = 0;
        do {
            $batch = $this->results($sql . ' ORDER BY ' . implode(',', $keys) . ' LIMIT ' . $offset . ',100');
            foreach ($batch as $row) { yield $row; }
            $offset += count($batch);
        } while (count($batch) === 100);
    }

    private function inventory(): array
    {
        $entries = [];
        $visit = function (string $directory, string $relative) use (&$visit, &$entries): void {
            $names = scandir($directory);
            if ($names === false) { throw new RuntimeException('Cannot list a source directory.'); }
            foreach ($names as $name) {
                if ($name === '.' || $name === '..') { continue; }
                $path = $relative === '' ? $name : $relative . '/' . $name;
                $absolute = $directory . '/' . $name;
                $stat = $this->fileStat($absolute);
                $type = $stat['type'] === 0040000 ? 'directory' : ($stat['type'] === 0100000 ? 'file' : 'unsupported');
                $entries[] = ['path' => $path, 'type' => $type, 'stat' => $stat];
                if (count($entries) > self::MAX_ENTRIES) { throw new RuntimeException('The source contains too many entries.'); }
                if ($type === 'directory' && $this->policy->pathAction($path)['action'] !== 'exclude') { $visit($absolute, $path); }
            }
        };
        $visit($this->root, '');
        usort($entries, static function (array $a, array $b): int { return strcmp($a['path'], $b['path']); });
        return $entries;
    }

    private function fileDecision(array $entry): array
    {
        $decision = $this->policy->pathAction($entry['path']);
        if ($decision['action'] === 'exclude') { return $decision; }
        if ($entry['type'] === 'directory') {
            if (in_array($decision['reason'], ['manual', 'public_content_scope'], true)) { return $decision; }
            if (in_array($entry['path'], ['wp-admin', 'wp-includes', 'wp-content', 'wp-content/uploads', 'wp-content/plugins', 'wp-content/themes', 'wp-content/mu-plugins'], true)
                || preg_match('~^(?:wp-admin/|wp-includes/|wp-content/(?:plugins|themes|mu-plugins)/)~', $entry['path'])) { return ['action' => 'include', 'reason' => 'application_directory']; }
            if (strpos($entry['path'], 'wp-content/uploads/') === 0 && !$this->policy->rules()['scanPublic']) { return ['action' => 'include', 'reason' => 'public_inspection_disabled']; }
            return ['action' => 'review', 'reason' => 'unknown_directory'];
        }
        if ($entry['type'] !== 'file') { return ['action' => 'review', 'reason' => 'unsupported_file_type']; }
        if ($decision['action'] === 'review' && $decision['reason'] === 'public_file_requires_review'
            && preg_match('/\.(?:txt|csv|json|xml|html?|md)$/i', $entry['path'])) {
            $tail = '';
            foreach ($this->fileChunks($this->root . '/' . $entry['path'], $entry['stat']) as $chunk) {
                $text = $tail . $chunk;
                if ($this->policy->containsPersonalData($text)) { return ['action' => 'review', 'reason' => 'personal_text_candidate']; }
                $tail = substr($text, -512);
            }
            return ['action' => 'include', 'reason' => 'no_text_candidate_detected'];
        }
        return $decision;
    }

    private function fileChunks(string $path, array $expected): \Generator
    {
        if ($this->fileStat($path) !== $expected || $expected['type'] !== 0100000) { throw new RuntimeException('A source file changed before reading.'); }
        $stream = @fopen($path, 'rb');
        if ($stream === false) { throw new RuntimeException('Cannot open a source file.'); }
        try {
            $stat = fstat($stream);
            if ($stat === false || $stat['dev'] !== $expected['dev'] || $stat['ino'] !== $expected['ino']) { throw new RuntimeException('A source file changed while opening.'); }
            while (!feof($stream)) {
                $chunk = fread($stream, 8192);
                if ($chunk === false) { throw new RuntimeException('Cannot read a source file.'); }
                if ($chunk !== '') { yield $chunk; }
            }
        } finally { fclose($stream); }
        clearstatcache(true, $path);
        if ($this->fileStat($path) !== $expected) { throw new RuntimeException('A source file changed while reading.'); }
    }

    private function fileStat(string $path): array
    {
        clearstatcache(true, $path); $stat = @lstat($path);
        if ($stat === false) { throw new RuntimeException('A source entry is unavailable.'); }
        return ['dev' => $stat['dev'], 'ino' => $stat['ino'], 'size' => $stat['size'], 'mtime' => $stat['mtime'], 'ctime' => $stat['ctime'], 'type' => $stat['mode'] & 0170000];
    }
    private function results(string $sql): array
    {
        $result = $this->wpdb->get_results($sql, 'ARRAY_A');
        if (!is_array($result) || ($this->wpdb->last_error ?? '') !== '') { throw new RuntimeException('Cannot read database metadata or rows.'); }
        return $result;
    }
    private function scalar(string $sql)
    {
        $value = $this->wpdb->get_var($sql);
        if ($value === null || ($this->wpdb->last_error ?? '') !== '') { throw new RuntimeException('Cannot inspect the database source.'); }
        return $value;
    }
    private function execute(string $sql): void
    {
        if ($this->wpdb->query($sql) === false || ($this->wpdb->last_error ?? '') !== '') { throw new RuntimeException('Cannot acquire or release the database capture lock.'); }
    }
    private static function identifier(string $name): string
    {
        if ($name === '' || strlen($name) > 64 || strpos($name, "\0") !== false) { throw new RuntimeException('Invalid database identifier.'); }
        return '`' . str_replace('`', '``', $name) . '`';
    }
}
