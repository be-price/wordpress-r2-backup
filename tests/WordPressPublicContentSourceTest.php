<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\{AgeProcess, PrivacyPolicy, WordPressSource};

final class WordPressPublicContentSourceTest extends TestCase
{
    protected function tearDown(): void { Fixture::cleanup(); }

    public function testScanExemptsOnlyPublishedContentAndKnownPublicImageFiles(): void
    {
        [$source, $db, $root] = $this->source();
        $scan = $source->scan();
        self::assertSame(0, $scan['unresolved']);
        $files = array_column($scan['files'], 'action', 'path');
        foreach (['photo.jpg', 'photo-100x100.jpg', 'photo-original.jpg', 'photo.jpg.webp', 'photo.avif', 'unattached.png'] as $name) {
            self::assertSame('include', $files['wp-content/uploads/2026/' . $name], $name);
        }
        foreach (['private.jpg', 'protected.jpg', 'orphan.jpg', 'document.pdf', 'unlisted.jpg', 'outside.jpg'] as $name) {
            self::assertSame('exclude', $files['wp-content/uploads/2026/' . $name], $name);
        }
        self::assertStringNotContainsString('private@example.test', json_encode($scan));
        $metadataQueries = array_filter($db->queries, static function (string $sql): bool { return strpos($sql, 'SELECT `ID`,`post_status`') === 0; });
        self::assertCount(1, $metadataQueries);
        self::assertStringNotContainsString('post_content', reset($metadataQueries));
        self::assertFileExists($root . '/wp-content/uploads/2026/private.jpg');
    }

    /** @dataProvider captureMethods */
    public function testCaptureExportsPublicContentAndRebuildsContextAfterLock(string $method): void
    {
        [$source, $db] = $this->source();
        $keys = Fixture::keys();
        $result = $source->$method(new AgeProcess(Fixture::runtime()['age']), array_column($keys, 'public'), str_repeat('b', 64));
        $sql = $this->decryptInMemory($result['database']['cipherFile'], $keys[0]['identity']);
        self::assertStringContainsString(bin2hex('public@example.test'), $sql);
        self::assertStringContainsString(bin2hex('public metadata'), $sql);
        self::assertStringContainsString(bin2hex('configuration'), $sql);
        self::assertStringNotContainsString(bin2hex('private@example.test'), $sql);
        self::assertStringNotContainsString(bin2hex('sensitive form'), $sql);
        self::assertStringNotContainsString(bin2hex('protected value'), $sql);
        self::assertSame(2, count(array_filter($db->queries, static function (string $query): bool { return strpos($query, 'SELECT `ID`,`post_status`') === 0; })));
        self::assertTrue($db->contextReadUnderLock);

        [$source, $db] = $this->source(); $db->makePrivateOnLock = true;
        $result = $source->$method(new AgeProcess(Fixture::runtime()['age']), array_column($keys, 'public'), str_repeat('c', 64));
        $sql = $this->decryptInMemory($result['database']['cipherFile'], $keys[0]['identity']);
        self::assertStringNotContainsString(bin2hex('public@example.test'), $sql);
        self::assertStringNotContainsString(bin2hex('public metadata'), $sql);
        if ($method === 'capture') {
            self::assertNotContains('wp-content/uploads/2026/photo.jpg', array_column($result['files'], 'path'));
            self::assertSame(1, count(array_filter($result['files'], static function (array $file): bool { return $file['type'] === 'file'; })));
        } else {
            self::assertSame(1, array_sum(array_column($result['archives'], 'fileCount')));
        }
        self::assertTrue($db->unlocked);
    }

    public function captureMethods(): array { return [['capture'], ['captureArchives']]; }

    public function testConfigurationContentReceivesNoPublicInspectionExemption(): void
    {
        [$source, $db] = $this->source();
        $db->posts[9]['post_content'] = 'configuration-owner@example.test';
        self::assertGreaterThan(0, $source->scan()['unresolved']);
    }

    public function testMalformedMetadataCannotGrantAdditionalFilesAndCannotInstantiateClasses(): void
    {
        [$source, $db] = $this->source();
        foreach ($db->meta as &$row) {
            if ($row['meta_key'] === '_wp_attachment_metadata') {
                $row['meta_value'] = serialize(['file' => '../outside.jpg', 'sizes' => ['unsafe' => ['file' => 'unlisted.jpg']]]);
            }
        }
        unset($row);
        $files = array_column($source->scan()['files'], 'action', 'path');
        self::assertSame('include', $files['wp-content/uploads/2026/photo.jpg']);
        self::assertSame('exclude', $files['wp-content/uploads/2026/unlisted.jpg']);
        self::assertSame('exclude', $files['wp-content/uploads/2026/photo-100x100.jpg']);

        foreach ($db->meta as &$row) {
            if ($row['meta_key'] === '_wp_attachment_metadata') { $row['meta_value'] = serialize(new PublicMetadataObjectFixture()); }
        }
        unset($row);
        $files = array_column($source->scan()['files'], 'action', 'path');
        self::assertSame('exclude', $files['wp-content/uploads/2026/outside.jpg']);
    }

    /** @dataProvider excludedPublicPostRules */
    public function testExplicitPostExclusionCannotReviveRelatedImagesOrMetadata(string $field, string $value): void
    {
        [$source] = $this->source(['excludedRows' => ['wp_posts' => [$field => [$value]]]]);
        $keys = Fixture::keys();
        $result = $source->captureArchives(new AgeProcess(Fixture::runtime()['age']), array_column($keys, 'public'), str_repeat('d', 64));
        $sql = $this->decryptInMemory($result['database']['cipherFile'], $keys[0]['identity']);
        self::assertStringNotContainsString(bin2hex('public@example.test'), $sql);
        self::assertStringNotContainsString(bin2hex('public metadata'), $sql);
        self::assertStringNotContainsString(bin2hex('2026/photo.jpg'), $sql);
        self::assertStringContainsString(bin2hex('configuration'), $sql);
        self::assertSame(1, array_sum(array_column($result['archives'], 'fileCount')));
    }

    public function excludedPublicPostRules(): array { return [['ID', '1'], ['post_content', 'public@example.test']]; }

    public function testExplicitImageMetadataExclusionCannotGrantItsDerivedPaths(): void
    {
        [$source] = $this->source(['excludedRows' => ['wp_postmeta' => ['meta_id' => ['7']]]]);
        $files = array_column($source->scan()['files'], 'action', 'path');
        self::assertSame('include', $files['wp-content/uploads/2026/photo.jpg']);
        self::assertSame('exclude', $files['wp-content/uploads/2026/photo-100x100.jpg']);
        self::assertSame('exclude', $files['wp-content/uploads/2026/photo-original.jpg']);
    }

    /** @dataProvider unknownExclusionTargets */
    public function testUnknownRowExclusionTargetsStopBeforeContentProjection(array $rules, string $message): void
    {
        [$source, $db] = $this->source(['excludedRows' => $rules]);
        $caught = null;
        try { $source->scan(); } catch (\RuntimeException $error) { $caught = $error; }
        self::assertInstanceOf(\RuntimeException::class, $caught);
        self::assertSame($message, $caught->getMessage());
        self::assertSame([], array_values(array_filter($db->queries, static function (string $sql): bool { return strpos($sql, 'SELECT ') === 0; })));
    }

    public function unknownExclusionTargets(): array
    {
        return [
            [[ 'wp_missing' => ['ID' => ['1']] ], 'An excluded-row table is not present in the database inventory.'],
            [[ 'wp_posts' => ['missing_field' => ['1']] ], 'An excluded-row field is not present in the database inventory.'],
        ];
    }

    public function testAnExistingRowExclusionTargetMayHaveNoCurrentValueMatch(): void
    {
        [$source] = $this->source(['excludedRows' => ['wp_posts' => ['ID' => ['999999']]]]);
        $scan = $source->scan();
        self::assertSame(0, $scan['unresolved']);
        $files = array_column($scan['files'], 'action', 'path');
        self::assertSame('include', $files['wp-content/uploads/2026/photo.jpg']);
    }

    private function source(array $rules = []): array
    {
        $root = Fixture::temp(); $db = new PublicContentWpdbFixture();
        foreach (['photo.jpg', 'photo-100x100.jpg', 'photo-original.jpg', 'photo.jpg.webp', 'photo.avif', 'unattached.png', 'private.jpg', 'protected.jpg', 'orphan.jpg', 'document.pdf', 'unlisted.jpg', 'outside.jpg'] as $name) {
            Fixture::write($root, 'wp-content/uploads/2026/' . $name, 'synthetic image');
        }
        $policy = new PrivacyPolicy(array_replace(['publicContentOnly' => true, 'scanPublic' => false], $rules));
        return [new WordPressSource($root, $db, $policy, Fixture::temp()), $db, $root];
    }

    private function decryptInMemory(string $cipher, string $identity): string
    {
        $process = proc_open([Fixture::runtime()['age'], '--decrypt', '--identity', $identity, $cipher], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $plain = stream_get_contents($pipes[1]); fclose($pipes[1]);
        stream_get_contents($pipes[2]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process));
        return $plain;
    }
}

final class PublicMetadataObjectFixture
{
    public string $file = '2026/outside.jpg';
    public function __wakeup(): void { throw new \RuntimeException('Attachment metadata must never instantiate serialized classes.'); }
}

final class PublicContentWpdbFixture
{
    public string $prefix = 'wp_';
    public string $last_error = '';
    public bool $locked = false;
    public bool $unlocked = false;
    public bool $contextReadUnderLock = false;
    public bool $makePrivateOnLock = false;
    public array $queries = [];
    public array $posts = [];
    public array $meta = [];

    public function __construct()
    {
        foreach ([
            [1, 'publish', 'post', 0, '', '', 'public@example.test'],
            [2, 'private', 'post', 0, '', '', 'private@example.test'],
            [3, 'publish', 'post', 0, 'protected value', '', 'private@example.test'],
            [4, 'inherit', 'attachment', 1, '', 'image/jpeg', 'public image'],
            [5, 'inherit', 'attachment', 2, '', 'image/jpeg', 'private@example.test'],
            [6, 'inherit', 'attachment', 3, '', 'image/jpeg', 'private@example.test'],
            [7, 'inherit', 'attachment', 0, '', 'image/png', 'unattached image'],
            [8, 'inherit', 'attachment', 99, '', 'image/jpeg', 'private@example.test'],
            [9, 'publish', 'acf-field-group', 0, '', '', 'configuration'],
            [10, 'publish', 'flamingo_inbound', 0, '', '', 'sensitive form'],
            [11, 'inherit', 'attachment', 1, '', 'application/pdf', 'private@example.test'],
            [12, 'draft', 'post', 0, '', '', 'private@example.test'],
        ] as $row) {
            $this->posts[$row[0]] = array_combine(['ID', 'post_status', 'post_type', 'post_parent', 'post_password', 'post_mime_type', 'post_content'], array_map('strval', $row));
        }
        foreach ([4 => 'photo.jpg', 5 => 'private.jpg', 6 => 'protected.jpg', 7 => 'unattached.png', 8 => 'orphan.jpg', 11 => 'document.pdf'] as $id => $file) {
            $this->addMeta($id, '_wp_attached_file', '2026/' . $file);
        }
        $this->addMeta(4, '_wp_attachment_metadata', serialize(['file' => '2026/photo.jpg', 'original_image' => 'photo-original.jpg', 'sizes' => [
            'thumbnail' => ['file' => 'photo-100x100.jpg'], 'unsafe' => ['file' => '../outside.jpg'], 'absolute' => ['file' => '/2026/outside.jpg'],
        ]]));
        $this->addMeta(1, 'display', 'public metadata');
        $this->addMeta(2, 'display', 'private@example.test');
    }
    private function addMeta(int $id, string $key, string $value): void { $this->meta[] = ['meta_id' => (string) (count($this->meta) + 1), 'post_id' => (string) $id, 'meta_key' => $key, 'meta_value' => $value]; }
    public function query(string $sql)
    {
        if (strpos($sql, 'LOCK TABLES ') === 0) { $this->locked = true; if ($this->makePrivateOnLock) { $this->posts[1]['post_status'] = 'private'; } }
        if ($sql === 'UNLOCK TABLES') { $this->unlocked = true; }
        return 1;
    }
    public function get_var(string $sql)
    {
        if (strpos($sql, 'information_schema.') !== false) { return '0'; }
        if (strpos($sql, 'COUNT(*)') !== false) { return strpos($sql, '`wp_postmeta`') !== false ? (string) count($this->meta) : (string) count($this->posts); }
        if (strpos($sql, 'character_set_connection') !== false) { return 'utf8mb4'; }
        if (strpos($sql, 'CONNECTION_ID()') !== false) { return '10'; }
        throw new \RuntimeException('Unexpected synthetic scalar query.');
    }
    public function get_results(string $sql, $format = null): array
    {
        $this->queries[] = $sql;
        if ($sql === 'SHOW FULL TABLES') { return [['name' => 'wp_posts', 'type' => 'BASE TABLE'], ['name' => 'wp_postmeta', 'type' => 'BASE TABLE']]; }
        $meta = strpos($sql, '`wp_postmeta`') !== false;
        if (strpos($sql, 'SHOW FULL COLUMNS FROM ') === 0) {
            $fields = array_keys($meta ? $this->meta[0] : $this->posts[1]); $columns = [];
            foreach ($fields as $field) {
                $numeric = in_array($field, ['ID', 'post_parent', 'meta_id', 'post_id'], true);
                $columns[] = ['Field' => $field, 'Type' => $numeric ? 'bigint' : 'text', 'Null' => 'NO', 'Key' => in_array($field, ['ID', 'meta_id'], true) ? 'PRI' : '', 'Extra' => ''];
            }
            return $columns;
        }
        if (strpos($sql, 'SHOW CREATE TABLE ') === 0) { $name = $meta ? 'wp_postmeta' : 'wp_posts'; return [['Table' => $name, 'Create Table' => 'CREATE TABLE `' . $name . '` (`ID` bigint NOT NULL) ENGINE=InnoDB']]; }
        if (preg_match('/^SELECT (.+) FROM `(wp_posts|wp_postmeta)`/', $sql, $select)) {
            if (strpos($sql, 'SELECT `ID`,`post_status`') === 0 && $this->locked) { $this->contextReadUnderLock = true; }
            $rows = array_values($meta ? $this->meta : $this->posts);
            if (preg_match("/WHERE `meta_key` = '([^']+)'/", $sql, $where)) { $rows = array_values(array_filter($rows, static function (array $row) use ($where): bool { return $row['meta_key'] === $where[1]; })); }
            if (preg_match('/ LIMIT ([0-9]+),100$/', $sql, $limit)) { $rows = array_slice($rows, (int) $limit[1], 100); }
            if ($select[1] !== '*') {
                preg_match_all('/`([^`]+)`/', $select[1], $fields); $keys = array_fill_keys($fields[1], true);
                $rows = array_map(static function (array $row) use ($keys): array { return array_intersect_key($row, $keys); }, $rows);
            }
            return $rows;
        }
        throw new \RuntimeException('Unexpected synthetic row query.');
    }
}
