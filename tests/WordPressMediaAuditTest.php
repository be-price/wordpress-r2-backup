<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use WordPressR2Backup\WordPressMediaAudit;

/** 合成fixturesとfake wpdbだけを使い、実DB・復元原本・外部通信には触れない。 */
final class WordPressMediaAuditTest extends TestCase
{
    private string $directory;
    private string $uploads;

    protected function setUp(): void
    {
        $this->directory = (string) realpath(sys_get_temp_dir()) . '/wrb-media-audit-' . bin2hex(random_bytes(5));
        $this->uploads = $this->directory . '/uploads';
        mkdir($this->uploads . '/2025/05', 0700, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) { $this->remove($this->directory); }
    }

    public function testPagesAreOrderedBoundedAndTheFinalPageIsDetermined(): void
    {
        $db = new FakeMediaDatabase();
        for ($id = 10; $id <= 14; ++$id) { $db->image($id, "2025/05/p$id.jpg"); $this->touch("2025/05/p$id.jpg"); }
        $seen = []; $after = 0; $total = 0;
        do {
            $page = WordPressMediaAudit::page($db, $this->uploads, $after, 2);
            self::assertGreaterThanOrEqual($after, $page['nextId']);
            $seen[] = [$page['nextId'], $page['done'], $page['counts']['imagesChecked']];
            $total += $page['counts']['imagesChecked']; $after = $page['nextId'];
        } while (!$page['done']);
        self::assertSame([[11, false, 2], [13, false, 2], [14, true, 1]], $seen);
        self::assertSame(5, $total);
        $empty = WordPressMediaAudit::page($db, $this->uploads, 14, 2);
        self::assertSame(['nextId' => 14, 'done' => true], ['nextId' => $empty['nextId'], 'done' => $empty['done']]);
        self::assertSame(array_fill_keys(array_keys($empty['counts']), 0), $empty['counts']);
        foreach ($db->queries as $sql) { self::assertMatchesRegularExpression('/ LIMIT [0-9]+\z/D', $sql); }
        self::assertStringContainsString('LEFT(CAST(', $db->queries[1]);
    }

    public function testOnlyPublicPasswordFreeImagesAreSelected(): void
    {
        $db = new FakeMediaDatabase();
        $db->post(1, 'publish', 'post', '0', ''); $db->post(2, 'draft', 'post', '0', ''); $db->post(3, 'publish', 'post', '0', 'secret');
        $db->image(10, '2025/05/a.jpg', 0); $db->image(11, '2025/05/b.jpg', 1); $db->image(12, '2025/05/c.jpg', 2);
        $db->image(13, '2025/05/d.jpg', 3); $db->image(14, '2025/05/e.jpg', 99); $db->image(15, '2025/05/f.jpg', 0, 'secret');
        $db->post(16, 'private', 'attachment', '0', '', 'image/png'); $db->post(17, 'inherit', 'attachment', '0', '', 'application/pdf');
        $page = WordPressMediaAudit::page($db, $this->uploads);
        self::assertSame(2, $page['counts']['imagesChecked']);
        self::assertSame(11, $page['nextId']);
    }

    public function testOriginalMissingAlternativeSizeOnlyAndNothingSavedAreDistinguished(): void
    {
        $db = new FakeMediaDatabase();
        $db->image(1, '2025/05/webp.jpg'); $this->touch('2025/05/webp.jpg.webp');
        $db->image(2, '2025/05/avif.jpg'); $this->touch('2025/05/avif.avif');
        $db->image(3, '2025/05/sized.jpg', 0, '', $this->metadata('2025/05/sized.jpg', ['thumb' => 'sized-150x150.jpg'])); $this->touch('2025/05/sized-150x150.jpg');
        $db->image(4, '2025/05/none.jpg', 0, '', $this->metadata('2025/05/none.jpg', ['thumb' => 'none-150x150.jpg']));
        $counts = WordPressMediaAudit::page($db, $this->uploads)['counts'];
        self::assertSame(4, $counts['imagesChecked']);
        self::assertSame(0, $counts['originalFilePresent']);
        self::assertSame(4, $counts['originalFileMissing']);
        self::assertSame(3, $counts['withSavedAlternative']);
        self::assertSame(1, $counts['withoutSavedFiles']);
        self::assertSame(4, $counts['imagesWithMissingDeclaredFiles']);
        self::assertSame(0, $counts['imagesWithAllDeclaredFilesPresent']);
    }

    public function testPresentOriginalWithMissingSizeIsNotReportedAsComplete(): void
    {
        $db = new FakeMediaDatabase();
        $db->image(1, '2025/05/full.jpg', 0, '', $this->metadata('2025/05/full.jpg', ['a' => 'full-150x150.jpg', 'b' => 'full-300x300.jpg'], 'full-original.jpg'));
        $this->touch('2025/05/full.jpg'); $this->touch('2025/05/full-150x150.jpg');
        $db->image(2, '2025/05/ok.jpg', 0, '', $this->metadata('2025/05/ok.jpg', ['a' => 'ok-150x150.jpg']));
        $this->touch('2025/05/ok.jpg'); $this->touch('2025/05/ok-150x150.jpg');
        $counts = WordPressMediaAudit::page($db, $this->uploads)['counts'];
        self::assertSame(2, $counts['originalFilePresent']);
        self::assertSame(0, $counts['originalFileMissing']);
        self::assertSame(6, $counts['declaredFilesChecked']);
        self::assertSame(2, $counts['declaredFilesMissing']);
        self::assertSame(1, $counts['imagesWithMissingDeclaredFiles']);
        self::assertSame(1, $counts['imagesWithAllDeclaredFilesPresent']);
    }

    public function testOversizedBrokenAndMissingMetadataAreIncompleteWithoutClassifyingThemAsMissing(): void
    {
        $db = new FakeMediaDatabase();
        $huge = 'a:1:{s:4:"file";s:' . 70000 . ':"' . str_repeat('x', 70000) . '";}';
        $db->image(1, '2025/05/huge.jpg', 0, '', $huge); $this->touch('2025/05/huge.jpg');
        $db->image(2, '2025/05/broken.jpg', 0, '', 'a:1:{s:4:"file"'); $this->touch('2025/05/broken.jpg');
        $db->image(3, null, 0, '', 'a:0:{}');
        $db->image(4, '2025/05/object.jpg', 0, '', 'O:8:"stdClass":0:{}');
        $db->image(5, '2025/05/deep.jpg', 0, '', str_repeat('a:1:{i:0;', 20) . 'i:1;' . str_repeat('}', 20));
        $db->image(6, '2025/05/plain.jpg'); $this->touch('2025/05/plain.jpg');
        $counts = WordPressMediaAudit::page($db, $this->uploads)['counts'];
        self::assertSame(6, $counts['imagesChecked']);
        self::assertSame(5, $counts['incompleteMetadata']);
        self::assertSame(3, $counts['originalFilePresent']);
        self::assertSame(1, $counts['imagesWithAllDeclaredFilesPresent']);
        self::assertSame(2, $counts['originalFileMissing']);
        self::assertSame(2, $counts['withoutSavedFiles']);
    }

    public function testDuplicateMetadataRowsAreIncompleteAndTheFirstRowIsUsed(): void
    {
        $db = new FakeMediaDatabase();
        $db->image(1, '2025/05/dup.jpg'); $db->meta(1, '_wp_attached_file', '2025/05/other.jpg'); $this->touch('2025/05/dup.jpg');
        $counts = WordPressMediaAudit::page($db, $this->uploads)['counts'];
        self::assertSame(1, $counts['incompleteMetadata']);
        self::assertSame(1, $counts['originalFilePresent']);
    }

    public function testUnsafePathsAndEscapingSymbolicLinksAreNotFollowedOrCountedAsPresent(): void
    {
        $outside = $this->directory . '/outside.jpg'; file_put_contents($outside, 'x');
        symlink($outside, $this->uploads . '/2025/05/leak.jpg');
        symlink($this->directory, $this->uploads . '/escape');
        symlink($this->uploads . '/2025/05/real.jpg', $this->uploads . '/2025/05/inside.jpg'); $this->touch('2025/05/real.jpg');
        symlink($this->uploads . '/2025/05/nothing.jpg', $this->uploads . '/2025/05/dangling.jpg');
        $unsafe = ['../outside.jpg', '/etc/passwd', "2025/05/nul\0.jpg", 'https://example.invalid/a.jpg', 'C:\\a.jpg', '2025//05/a.jpg', '2025/05/../../../outside.jpg', 'escape/outside.jpg', '2025/05/leak.jpg', '2025/05/dangling.jpg'];
        $db = new FakeMediaDatabase(); $id = 1;
        foreach ($unsafe as $path) { $db->image($id++, $path); }
        $db->image($id, '2025/05/inside.jpg');
        $counts = WordPressMediaAudit::page($db, $this->uploads)['counts'];
        self::assertSame(count($unsafe) + 1, $counts['imagesChecked']);
        self::assertSame(count($unsafe), $counts['unsafePaths']);
        self::assertSame(1, $counts['originalFilePresent']);
        self::assertSame(0, $counts['originalFileMissing']);
        self::assertSame(1, $counts['declaredFilesChecked']);
        self::assertSame(1, $counts['imagesWithAllDeclaredFilesPresent']);
    }

    public function testIdsBeyondPhpIntMaxAreRejectedSafely(): void
    {
        $db = new FakeMediaDatabase();
        $db->image(1, '2025/05/a.jpg');
        $db->overrideId = '9223372036854775808';
        try { WordPressMediaAudit::page($db, $this->uploads); self::fail('Expected an out-of-range ID to fail.'); }
        catch (RuntimeException $e) { self::assertStringNotContainsString('9223372036854775808', $e->getMessage()); }
        $db->overrideId = '9223372036854775807';
        self::assertSame(PHP_INT_MAX, WordPressMediaAudit::page($db, $this->uploads)['nextId']);
    }

    public function testOverlongAttachedFileIsReportedUnsafeInsteadOfFallingBackToMetadataFile(): void
    {
        $this->touch('2025/05/safe.jpg');
        $db = new FakeMediaDatabase();
        $db->image(1, '2025/05/' . str_repeat('a', 1100) . '.jpg', 0, '', $this->metadata('2025/05/safe.jpg', []));
        $counts = WordPressMediaAudit::page($db, $this->uploads)['counts'];
        self::assertSame(1, $counts['unsafePaths']);
        self::assertSame(0, $counts['originalFilePresent']);
        self::assertSame(0, $counts['originalFileMissing']);
        self::assertSame(0, $counts['imagesWithAllDeclaredFilesPresent']);
    }

    public function testSizeEntriesWithoutAFileKeyAreIncompleteAndNeverFullyVerified(): void
    {
        $this->touch('2025/05/a.jpg');
        $db = new FakeMediaDatabase();
        $db->image(1, '2025/05/a.jpg', 0, '', serialize(['file' => '2025/05/a.jpg', 'sizes' => ['thumbnail' => ['width' => 150]]]));
        $counts = WordPressMediaAudit::page($db, $this->uploads)['counts'];
        self::assertSame(1, $counts['incompleteMetadata']);
        self::assertSame(1, $counts['originalFilePresent']);
        self::assertSame(0, $counts['imagesWithAllDeclaredFilesPresent']);
    }

    public function testDeclaredSizeNamesCannotEscapeTheirDirectory(): void
    {
        $db = new FakeMediaDatabase();
        $db->image(1, '2025/05/a.jpg', 0, '', $this->metadata('2025/05/a.jpg', ['a' => '../b.jpg', 'b' => 'x\\y.jpg'])); $this->touch('2025/05/a.jpg');
        $counts = WordPressMediaAudit::page($db, $this->uploads)['counts'];
        self::assertSame(2, $counts['unsafePaths']);
        self::assertSame(1, $counts['originalFilePresent']);
        self::assertSame(0, $counts['imagesWithAllDeclaredFilesPresent']);
    }

    public function testReturnedValuesNeverExposePathsContentOrIdLists(): void
    {
        $db = new FakeMediaDatabase();
        $db->image(41, '2025/05/private-name.jpg', 0, '', $this->metadata('2025/05/private-name.jpg', ['a' => 'private-name-150x150.jpg']));
        $page = WordPressMediaAudit::page($db, $this->uploads, 40, 1);
        self::assertSame(['nextId', 'done', 'counts'], array_keys($page));
        foreach ($page['counts'] as $value) { self::assertIsInt($value); self::assertGreaterThanOrEqual(0, $value); }
        $encoded = json_encode($page, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('private-name', $encoded);
        self::assertStringNotContainsString($this->directory, $encoded);
        self::assertSame(41, $page['nextId']);
    }

    public function testInvalidRequestsAndDatabaseFailuresFailSafelyWithoutLeakingDetails(): void
    {
        $db = new FakeMediaDatabase();
        foreach ([[-1, 10], [0, 0], [0, 101]] as [$after, $limit]) {
            try { WordPressMediaAudit::page($db, $this->uploads, $after, $limit); self::fail('Expected a rejected request.'); }
            catch (InvalidArgumentException $e) { self::assertTrue(true); }
        }
        $db->failure = "secret SQL detail {$this->directory}";
        try { WordPressMediaAudit::page($db, $this->uploads); self::fail('Expected a database failure.'); }
        catch (RuntimeException $e) { self::assertStringNotContainsString('secret', $e->getMessage()); self::assertStringNotContainsString($this->directory, $e->getMessage()); }
        $bad = new FakeMediaDatabase(); $bad->posts = 'wp_posts`; DROP TABLE x; --';
        $this->expectException(RuntimeException::class);
        WordPressMediaAudit::page($bad, $this->uploads);
    }

    public function testMissingUploadsDirectoryIsAFailureNotAnEmptySuccess(): void
    {
        $this->expectException(RuntimeException::class);
        WordPressMediaAudit::page(new FakeMediaDatabase(), $this->directory . '/absent');
    }

    public function testRunawayMetadataRowsAreRejectedInsteadOfLoadedWithoutBound(): void
    {
        $db = new FakeMediaDatabase(); $db->image(1, '2025/05/a.jpg');
        for ($i = 0; $i < 10; ++$i) { $db->meta(1, '_wp_attached_file', '2025/05/a.jpg'); }
        $this->expectException(RuntimeException::class);
        WordPressMediaAudit::page($db, $this->uploads, 0, 2);
    }

    /** SQL自体を使い捨てMySQLで確認する。合成DBだけを作り、実DBには接続しない。 */
    public function testQueriesRunOnSyntheticMysqlWithTheSameSelection(): void
    {
        try { $server = IsolatedMysql::start(); }
        catch (\Throwable $error) { self::markTestSkipped('A synthetic MySQL server is unavailable.'); }
        try {
            $schema = $server->newSchema();
            $server->execute($schema, 'CREATE TABLE wp_posts (ID BIGINT UNSIGNED PRIMARY KEY, post_type VARCHAR(20), post_status VARCHAR(20), post_mime_type VARCHAR(100), post_password VARCHAR(255) NOT NULL DEFAULT \'\', post_parent BIGINT UNSIGNED NOT NULL DEFAULT 0);'
                . 'CREATE TABLE wp_postmeta (meta_id BIGINT AUTO_INCREMENT PRIMARY KEY, post_id BIGINT UNSIGNED, meta_key VARCHAR(255), meta_value LONGTEXT);'
                . "INSERT INTO wp_posts VALUES (1,'post','publish','','',0),(2,'post','draft','','',0),(10,'attachment','inherit','image/jpeg','',0),(11,'attachment','inherit','image/jpeg','',1),(12,'attachment','inherit','image/jpeg','',2),(13,'attachment','inherit','application/pdf','',0),(14,'attachment','inherit','image/jpeg','x',0);"
                . "INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES (10,'_wp_attached_file','2025/05/a.jpg'),(11,'_wp_attached_file','2025/05/b.jpg'),(11,'_wp_attachment_metadata',REPEAT('a',70000)),(12,'_wp_attached_file','2025/05/c.jpg'),(12,'_wp_attached_file','other');");
            $this->touch('2025/05/a.jpg');
            $wpdb = new SyntheticMysqlAdapter($server, $schema);
            $page = WordPressMediaAudit::page($wpdb, $this->uploads, 0, 1);
            self::assertSame([10, false, 1], [$page['nextId'], $page['done'], $page['counts']['imagesChecked']]);
            self::assertSame(1, $page['counts']['originalFilePresent']);
            $page = WordPressMediaAudit::page($wpdb, $this->uploads, 10, 100);
            self::assertSame([11, true, 1], [$page['nextId'], $page['done'], $page['counts']['imagesChecked']]);
            self::assertSame(1, $page['counts']['incompleteMetadata']);
        } finally {
            $server->stop();
        }
    }

    private function metadata(string $file, array $sizes, ?string $original = null): string
    {
        $data = ['width' => 100, 'height' => 100, 'file' => $file, 'sizes' => array_map(static fn (string $name): array => ['file' => $name], $sizes)];
        if ($original !== null) { $data['original_image'] = $original; }
        return serialize($data);
    }

    private function touch(string $relative): void
    {
        $path = $this->uploads . '/' . $relative;
        if (!is_dir(dirname($path))) { mkdir(dirname($path), 0700, true); }
        file_put_contents($path, 'x');
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) { unlink($path); return; }
        foreach (scandir($path) as $name) { if ($name !== '.' && $name !== '..') { $this->remove($path . '/' . $name); } }
        rmdir($path);
    }
}

/** posts/postmeta の2種類の問い合わせだけに答える。SQLの条件はPHPで同じ選択を再現し、LIMITの有無は記録して検証する。 */
final class FakeMediaDatabase
{
    public string $posts = 'wp_posts';
    public string $postmeta = 'wp_postmeta';
    public string $last_error = '';
    public ?string $overrideId = null;
    public ?string $failure = null;
    public array $queries = [];
    private array $postRows = [];
    private array $metaRows = [];

    public function post(int $id, string $status, string $type, string $parent, string $password, string $mime = ''): void
    {
        $this->postRows[$id] = ['ID' => $id, 'post_status' => $status, 'post_type' => $type, 'post_parent' => (int) $parent, 'post_password' => $password, 'post_mime_type' => $mime];
    }

    public function image(int $id, ?string $file, int $parent = 0, string $password = '', ?string $metadata = null): void
    {
        $this->post($id, 'inherit', 'attachment', (string) $parent, $password, 'image/jpeg');
        if ($file !== null) { $this->meta($id, '_wp_attached_file', $file); }
        if ($metadata !== null) { $this->meta($id, '_wp_attachment_metadata', $metadata); }
    }

    public function meta(int $post, string $key, string $value): void { $this->metaRows[] = ['post_id' => $post, 'meta_key' => $key, 'meta_value' => $value]; }

    public function get_results(string $sql, $output = 'OBJECT'): array
    {
        $this->queries[] = $sql;
        if ($this->failure !== null) { $this->last_error = $this->failure; return []; }
        preg_match('/ LIMIT ([0-9]+)\z/D', $sql, $limit);
        $max = (int) ($limit[1] ?? PHP_INT_MAX);
        if (strpos($sql, 'FROM `' . $this->posts . '` p') !== false) {
            preg_match('/p\.`ID` > ([0-9]+)/', $sql, $after);
            $rows = [];
            ksort($this->postRows);
            foreach ($this->postRows as $row) {
                $parent = $this->postRows[$row['post_parent']] ?? null;
                if ($row['post_type'] === 'attachment' && $row['post_status'] === 'inherit' && strpos($row['post_mime_type'], 'image/') === 0 && $row['post_password'] === '' && $row['ID'] > (int) $after[1]
                    && ($row['post_parent'] === 0 || ($parent !== null && $parent['post_type'] !== 'attachment' && $parent['post_status'] === 'publish' && $parent['post_password'] === ''))) { $rows[] = ['id' => (string) $row['ID']]; }
            }
            if ($this->overrideId !== null) { $rows = [['id' => $this->overrideId]]; }
            return array_slice($rows, 0, $max);
        }
        preg_match('/IN \(([0-9,]+)\)/', $sql, $in);
        $ids = array_map('intval', explode(',', $in[1]));
        $rows = [];
        foreach ($this->metaRows as $index => $row) {
            if (in_array($row['post_id'], $ids, true)) { $rows[] = ['post_id' => (string) $row['post_id'], 'meta_key' => $row['meta_key'], 'value_prefix' => substr($row['meta_value'], 0, 65537), 'order' => $index]; }
        }
        usort($rows, static fn (array $a, array $b): int => [(int) $a['post_id'], $a['order']] <=> [(int) $b['post_id'], $b['order']]);
        $rows = array_map(static function (array $row): array { unset($row['order']); return $row; }, $rows);
        return array_slice($rows, 0, $max);
    }
}

/** IsolatedMysql の合成スキーマに対し、問い合わせの列順で結果を連想配列にする。 */
final class SyntheticMysqlAdapter
{
    public string $posts = 'wp_posts';
    public string $postmeta = 'wp_postmeta';
    public string $last_error = '';

    private IsolatedMysql $server;
    private string $schema;

    public function __construct(IsolatedMysql $server, string $schema) { $this->server = $server; $this->schema = $schema; }

    public function get_results(string $sql, $output = 'OBJECT'): array
    {
        $columns = strpos($sql, 'FROM `wp_postmeta`') !== false ? ['post_id', 'meta_key', 'value_prefix'] : ['id'];
        return array_map(static fn (array $row): array => array_combine($columns, $row), $this->server->rows($this->schema, $sql));
    }
}
