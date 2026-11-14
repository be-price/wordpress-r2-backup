<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\PrivacyPolicy;
use WordPressR2Backup\WordPressPolicyContext;

final class WordPressPolicyContextTest extends TestCase
{
    private string $directory;
    protected function setUp(): void { $this->directory = realpath(sys_get_temp_dir()) . '/wrb-policy-' . bin2hex(random_bytes(12)); mkdir($this->directory, 0700); }
    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) { if ($file->isDir()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); } }
        rmdir($this->directory);
    }

    public function testCompleteAndUnclassifiedCustomNeedNoQueries(): void
    {
        foreach (['full', 'custom'] as $preset) {
            $wpdb = new PolicyContextWpdbFake();
            (new WordPressPolicyContext($wpdb, new PrivacyPolicy(['version' => 2, 'preset' => $preset]), $this->directory))->prepare();
            self::assertSame([], $wpdb->queries);
        }
    }

    public function testLongPreparationReportsProgressSoMaintenanceCanBeRefreshed(): void
    {
        $wpdb = new PolicyContextWpdbFake();
        for ($id = 100; $id < 1300; ++$id) {
            $wpdb->posts[] = ['ID' => (string) $id, 'post_type' => 'post', 'post_status' => 'publish', 'post_parent' => '0', 'post_password' => '', 'post_mime_type' => ''];
        }
        $policy = new PrivacyPolicy(['version' => 2, 'preset' => 'custom', 'legacy' => ['publicContentOnly' => true, 'scanPublic' => false]]);
        $calls = [];
        $digest = (new WordPressPolicyContext($wpdb, $policy, $this->directory))->prepare(static function (array $progress) use (&$calls): void { $calls[] = $progress['phase']; });
        self::assertGreaterThanOrEqual(2, count($calls));
        self::assertSame(['prepare'], array_values(array_unique($calls)));
        $quiet = (new WordPressPolicyContext($wpdb, new PrivacyPolicy(['version' => 2, 'preset' => 'custom', 'legacy' => ['publicContentOnly' => true, 'scanPublic' => false]]), $this->directory))->prepare();
        self::assertSame($digest, $quiet, 'progress reporting must not change the policy digest');
    }

    public function testMigratedPublicScopeRetainsDefinitionsImagesMetadataAndExcludedParents(): void
    {
        $wpdb = new PolicyContextWpdbFake();
        $policy = new PrivacyPolicy(['version' => 2, 'preset' => 'custom', 'legacy' => ['publicContentOnly' => true, 'scanPublic' => false, 'excludedRows' => ['wp_posts' => ['ID' => ['8']]]]]);
        $context = new WordPressPolicyContext($wpdb, $policy, $this->directory);
        $digest = $context->prepare();
        self::assertSame($digest, $context->prepare());
        foreach (['1', '2', '3', '4', '5'] as $id) { self::assertTrue($policy->includeRow('wp_postmeta', ['post_id' => $id])); }
        foreach (['6', '7', '8', '9', '10', '11'] as $id) { self::assertFalse($policy->includeRow('wp_postmeta', ['post_id' => $id])); }
        self::assertFalse($policy->shouldInspectField('wp_posts', 'post_content', ['ID' => '1']));
        self::assertTrue($policy->shouldInspectField('wp_posts', 'post_content', ['ID' => '3']));
        foreach (['image.jpg', 'image-small.jpg', 'image-original.jpg', 'fallback-small.jpg'] as $file) { self::assertSame('include', $policy->pathAction('wp-content/uploads/2026/' . $file)['action']); }
        self::assertSame('include', $policy->pathAction('wp-content/uploads/2026')['action']);
        self::assertSame('exclude', $policy->pathAction('wp-content/uploads/2026/draft.jpg')['action']);
        $wpdb->posts[0]['post_status'] = 'draft';
        self::assertNotSame($digest, $context->prepare());
        self::assertFalse($policy->includeRow('wp_postmeta', ['post_id' => '2']));
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file->isFile()) { continue; }
            $contents = file_get_contents($file->getPathname());
            foreach (['image.jpg', 'private@example.test', 'post_status', 'SELECT ', 'flamingo_inbound'] as $secret) { self::assertStringNotContainsString($secret, $contents); }
        }
    }

    public function testRescanFollowsPublishedAndExcludedChangesInBothDirections(): void
    {
        $wpdb = new PolicyContextWpdbFake();
        $rules = ['version' => 2, 'preset' => 'custom', 'legacy' => ['publicContentOnly' => true, 'scanPublic' => false]];
        $policy = new PrivacyPolicy($rules); $context = new WordPressPolicyContext($wpdb, $policy, $this->directory);
        $published = $context->prepare();
        self::assertSame($published, (new WordPressPolicyContext($wpdb, new PrivacyPolicy($rules), $this->directory))->prepare(), 'same rows give the same digest');
        self::assertSame('include', $policy->pathAction('wp-content/uploads/2026/image.jpg')['action']);
        self::assertTrue($policy->includeRow('wp_postmeta', ['post_id' => '2']));
        $wpdb->posts[0]['post_status'] = 'draft';
        $draft = $context->prepare();
        self::assertNotSame($published, $draft);
        self::assertSame('exclude', $policy->pathAction('wp-content/uploads/2026/image.jpg')['action'], 'an image of a withdrawn post must leave the backup');
        self::assertSame('exclude', $policy->pathAction('wp-content/uploads/2026/image-small.jpg')['action']);
        self::assertFalse($policy->includeRow('wp_postmeta', ['post_id' => '2']));
        self::assertSame('include', $policy->pathAction('wp-content/uploads/2026')['action'], 'the directory stays for the other public image');
        self::assertSame('exclude', $policy->pathAction('wp-content/uploads/2026/form.pdf')['action'], 'a public directory does not make its other files public');
        $wpdb->posts[0]['post_status'] = 'publish';
        self::assertSame($published, $context->prepare());
        self::assertSame('include', $policy->pathAction('wp-content/uploads/2026/image.jpg')['action']);
        $wpdb->posts[1]['post_parent'] = '7';
        self::assertNotSame($published, $context->prepare(), 'moving an attachment under another post changes the digest');
        self::assertSame('exclude', $policy->pathAction('wp-content/uploads/2026/image.jpg')['action'], 'an attachment of an attachment is not public');
    }

    public function testLowMemoryMovesPostsToTheCheckedMapWithTheSameDecisionsAndDigest(): void
    {
        $wpdb = new PolicyContextWpdbFake();
        foreach ([['version' => 2, 'preset' => 'custom', 'legacy' => ['publicContentOnly' => true, 'scanPublic' => false]], ['version' => 2, 'preset' => 'forms']] as $rules) {
            $memory = new PrivacyPolicy($rules); $memoryContext = new WordPressPolicyContext($wpdb, $memory, $this->directory);
            $expected = $memoryContext->prepare();
            // Ballast lifts usage above half of a lowered limit while leaving the map room for its reads.
            $limit = ini_get('memory_limit'); $ballast = str_repeat('x', 16 << 20);
            ini_set('memory_limit', (string) (int) (memory_get_usage() * 1.9));
            try {
                $mapped = new PrivacyPolicy($rules); $mappedContext = new WordPressPolicyContext($wpdb, $mapped, $this->directory);
                $digest = $mappedContext->prepare();
            } finally {
                unset($ballast); ini_set('memory_limit', $limit);
            }
            self::assertSame($expected, $digest, 'the digest must not depend on where post decisions are kept');
            self::assertSame($memoryContext->summary(), $mappedContext->summary());
            self::assertNotSame([], glob($this->directory . '/policy-*/leaf-*') ?: []);
            foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11', '12'] as $id) {
                self::assertSame($memory->includeRow('wp_posts', ['ID' => $id]), $mapped->includeRow('wp_posts', ['ID' => $id]));
                self::assertSame($memory->includeRow('wp_postmeta', ['post_id' => $id]), $mapped->includeRow('wp_postmeta', ['post_id' => $id]));
                self::assertSame($memory->shouldInspectField('wp_posts', 'post_content', ['ID' => $id]), $mapped->shouldInspectField('wp_posts', 'post_content', ['ID' => $id]));
            }
            foreach (['2026', '2026/image.jpg', '2026/image-small.jpg', '2026/fallback.jpg', '2026/form.pdf', '2026/unknown.pdf', '2026/draft.jpg'] as $path) {
                self::assertSame($memory->pathAction('wp-content/uploads/' . $path), $mapped->pathAction('wp-content/uploads/' . $path));
            }
            foreach (glob($this->directory . '/policy-*/leaf-*') ?: [] as $leaf) { file_put_contents($leaf, 'x', FILE_APPEND); }
            try {
                $mapped->includeRow('wp_postmeta', ['post_id' => '2']);
                self::fail('Post decisions kept in the map must be checked on every question, as before.');
            } catch (\Throwable $expected) {
                self::assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $expected);
            }
        }
    }

    public function testMediaPathsMovedToTheCheckedMapAreCheckedAndDuplicatePostsAreRejected(): void
    {
        $wpdb = new PolicyContextWpdbFake();
        $policy = new PrivacyPolicy(['version' => 2, 'preset' => 'custom', 'legacy' => ['publicContentOnly' => true, 'scanPublic' => false]]);
        // A limit of 0 sends every media path to the map, as a site past the in-memory limit does.
        (new WordPressPolicyContext($wpdb, $policy, $this->directory, 0))->prepare();
        self::assertSame('include', $policy->pathAction('wp-content/uploads/2026/image.jpg')['action']);
        $leaves = glob($this->directory . '/policy-*/leaf-*') ?: [];
        self::assertNotSame([], $leaves);
        foreach ($leaves as $leaf) { file_put_contents($leaf, 'x', FILE_APPEND); }
        try {
            $policy->pathAction('wp-content/uploads/2026/image.jpg');
            self::fail('A changed media map must be detected on the next path decision.');
        } catch (\Throwable $expected) {
            self::assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $expected);
        }
        foreach (glob($this->directory . '/policy-*/*') ?: [] as $file) { unlink($file); }
        try {
            $policy->pathAction('wp-content/uploads/2026/fallback.jpg');
            self::fail('A removed media map must not answer path decisions.');
        } catch (\Throwable $expected) {
            self::assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $expected);
        }
        $wpdb->posts[] = $wpdb->posts[0];
        $this->expectException(\RuntimeException::class);
        (new WordPressPolicyContext($wpdb, new PrivacyPolicy(['version' => 2, 'preset' => 'forms']), $this->directory))->prepare();
    }

    public function testMediaPathsWithinTheLimitStayInThisProcessAndLeaveNoPathOnDisk(): void
    {
        $wpdb = new PolicyContextWpdbFake();
        $policy = new PrivacyPolicy(['version' => 2, 'preset' => 'forms', 'legacy' => ['publicContentOnly' => true, 'scanPublic' => false]]);
        $context = new WordPressPolicyContext($wpdb, $policy, $this->directory);
        $digest = $context->prepare();
        self::assertSame([], glob($this->directory . '/policy-*/leaf-*') ?: [], 'paths and posts within their limits need no map partition');
        self::assertSame('include', $policy->pathAction('wp-content/uploads/2026/image.jpg')['action']);
        self::assertSame('exclude', $policy->pathAction('wp-content/uploads/2026/form.pdf')['action']);
        self::assertSame('exclude', $policy->pathAction('wp-content/uploads/2026/draft.jpg')['action']);
        self::assertSame($digest, (new WordPressPolicyContext($wpdb, new PrivacyPolicy(['version' => 2, 'preset' => 'forms', 'legacy' => ['publicContentOnly' => true, 'scanPublic' => false]]), $this->directory, 0))->prepare());
        $this->expectException(\RuntimeException::class);
        new WordPressPolicyContext($wpdb, $policy, $this->directory, -1);
    }

    /** @dataProvider largeSiteRules */
    public function testManyMediaPathsGiveTheSameDigestAndDecisionsWhereverAndWheneverTheyMove(array $rules): void
    {
        $wpdb = new PolicyContextLargeWpdbFake(900);
        $reference = new PrivacyPolicy($rules); $referenceContext = new WordPressPolicyContext($wpdb, $reference, $this->directory, 0);
        $expected = $referenceContext->prepare();
        $paths = $wpdb->paths();
        self::assertGreaterThan(2500, count($paths));
        $decisions = []; foreach ($paths as $path) { $decisions[$path] = $reference->pathAction($path); }
        $actions = array_count_values(array_column($decisions, 'action'));
        self::assertGreaterThan(100, $actions['include'] ?? 0); self::assertGreaterThan(100, $actions['exclude'] ?? 0);
        foreach ([250000, 1, 777, 2048] as $limit) {
            $policy = new PrivacyPolicy($rules); $context = new WordPressPolicyContext($wpdb, $policy, $this->directory, $limit);
            self::assertSame($expected, $context->prepare(), 'limit ' . $limit);
            self::assertSame($referenceContext->summary(), $context->summary());
            foreach ($decisions as $path => $decision) { self::assertSame($decision, $policy->pathAction($path), $path . ' with limit ' . $limit); }
            foreach (['1', '2', '3', '601', '901', '1350', '1799', '1800'] as $id) {
                self::assertSame($reference->includeRow('wp_posts', ['ID' => $id]), $policy->includeRow('wp_posts', ['ID' => $id]));
                self::assertSame($reference->includeRow('wp_postmeta', ['post_id' => $id]), $policy->includeRow('wp_postmeta', ['post_id' => $id]));
            }
        }
        $limit = ini_get('memory_limit'); $ballast = str_repeat('x', 16 << 20);
        ini_set('memory_limit', (string) (int) (memory_get_usage() * 1.9));
        try { $lowMemory = (new WordPressPolicyContext($wpdb, new PrivacyPolicy($rules), $this->directory))->prepare(); }
        finally { unset($ballast); ini_set('memory_limit', $limit); }
        self::assertSame($expected, $lowMemory, 'paths moved for memory give the same digest');
        $wpdb->makeSubmission(601);
        $changed = [];
        foreach ([0, 250000] as $limit) { $changed[] = (new WordPressPolicyContext($wpdb, new PrivacyPolicy($rules), $this->directory, $limit))->prepare(); }
        self::assertNotSame($expected, $changed[0], 'a post that became a form submission changes the digest');
        self::assertSame($changed[0], $changed[1]);
    }

    public function largeSiteRules(): array
    {
        return ['public images' => [['version' => 2, 'preset' => 'custom', 'legacy' => ['publicContentOnly' => true, 'scanPublic' => false, 'excludedRows' => ['wp_posts' => ['ID' => ['9']]]]]],
            'forms and public images' => [['version' => 2, 'preset' => 'forms', 'legacy' => ['publicContentOnly' => true, 'scanPublic' => false]]],
            'forms' => [['version' => 2, 'preset' => 'forms']]];
    }

    /** @dataProvider skewedPartitionSizes */
    public function testPathsSkewedIntoOnePartitionMoveBeforeItCanSplitWithTheSameDigest(int $skewed, bool $moves): void
    {
        // Chosen names put every skewed path in one map partition, as crafted upload names could.
        $files = PolicyContextSkewedWpdbFake::skewedFiles($skewed);
        $wpdb = new PolicyContextSkewedWpdbFake($files);
        $sawMove = false;
        foreach ([['version' => 2, 'preset' => 'forms', 'legacy' => ['publicContentOnly' => true, 'scanPublic' => false]], ['version' => 2, 'preset' => 'forms']] as $rules) {
            $before = glob($this->directory . '/policy-paths-*') ?: [];
            $reference = new PrivacyPolicy($rules); $expected = (new WordPressPolicyContext($wpdb, $reference, $this->directory, 0))->prepare();
            // The map built from the start shows how many recorded paths share the skewed partition under these rules.
            $fullest = 0;
            foreach (array_diff(glob($this->directory . '/policy-paths-*') ?: [], $before) as $map) {
                foreach (glob($map . '/leaf-*.json') ?: [] as $leaf) { $fullest = max($fullest, count(json_decode((string) file_get_contents($leaf), true, 16, JSON_THROW_ON_ERROR)['records'])); }
            }
            $movesHere = $fullest > 8; $sawMove = $sawMove || $movesHere;
            foreach ([[250000, 8], [250000, 4096]] as [$pathLimit, $partitionLimit]) {
                $before = glob($this->directory . '/policy-paths-*') ?: [];
                $policy = new PrivacyPolicy($rules); $digest = (new WordPressPolicyContext($wpdb, $policy, $this->directory, $pathLimit, $partitionLimit))->prepare();
                $created = array_diff(glob($this->directory . '/policy-paths-*') ?: [], $before);
                self::assertSame($expected, $digest, $skewed . ' skewed paths, partition limit ' . $partitionLimit);
                self::assertCount($movesHere && $partitionLimit === 8 ? 1 : 0, $created, 'paths move to the map only past the partition limit');
                foreach ($wpdb->paths() as $path) { self::assertSame($reference->pathAction($path), $policy->pathAction($path), $path); }
            }
        }
        self::assertSame($moves, $sawMove, 'the skewed partition must reach the limit exactly when expected');
    }

    public function skewedPartitionSizes(): array
    {
        return ['the last path before the partition limit' => [8, false], 'the first path past it' => [9, true], 'far past it' => [40, true]];
    }

    public function testPathMapPartitionsGrowByOneFixedRecordLengthFarBelowTheSplitSize(): void
    {
        $wpdb = new PolicyContextSkewedWpdbFake(PolicyContextSkewedWpdbFake::skewedFiles(40));
        (new WordPressPolicyContext($wpdb, new PrivacyPolicy(['version' => 2, 'preset' => 'forms', 'legacy' => ['publicContentOnly' => true, 'scanPublic' => false]]), $this->directory, 0))->prepare();
        $leaves = glob($this->directory . '/policy-paths-*/leaf-*.json') ?: [];
        self::assertNotSame([], $leaves);
        $overhead = strlen('{"version":1,"records":{},"checksum":"' . str_repeat('0', 64) . '"}');
        $largest = 0;
        foreach ($leaves as $leaf) {
            $records = count(json_decode((string) file_get_contents($leaf), true, 16, JSON_THROW_ON_ERROR)['records']);
            self::assertSame($overhead + $records * WordPressPolicyContext::PATH_RECORD_BYTES - 1, filesize($leaf), 'every path record has one encoded length');
            $largest = max($largest, $records);
        }
        self::assertGreaterThanOrEqual(40, $largest, 'the skewed paths share one partition');
        self::assertLessThan(4194304 / 6, $overhead + 4096 * WordPressPolicyContext::PATH_RECORD_BYTES, 'the in-memory partition limit stays far below the split size');
        foreach ([[250000, 0], [250000, 4097], [-1, 8]] as [$pathLimit, $partitionLimit]) {
            try { new WordPressPolicyContext($wpdb, new PrivacyPolicy(['version' => 2, 'preset' => 'forms']), $this->directory, $pathLimit, $partitionLimit); self::fail('Invalid limit accepted'); }
            catch (\RuntimeException $expected) { self::assertSame('Invalid media path memory limit.', $expected->getMessage()); }
        }
    }

    public function testFormsExcludeOnlyConfirmedSubmissionRelationshipsAndRespectExplicitRules(): void
    {
        $wpdb = new PolicyContextWpdbFake(); $policy = new PrivacyPolicy(['version' => 2, 'preset' => 'forms']);
        $context = new WordPressPolicyContext($wpdb, $policy, $this->directory); $context->prepare();
        foreach (['6', '7'] as $id) {
            self::assertFalse($policy->includeRow('wp_posts', ['ID' => $id]));
            self::assertFalse($policy->includeRow('wp_postmeta', ['post_id' => $id, 'meta_key' => '_ordinary']));
        }
        foreach (['1', '3', '4', '9', '10', '11'] as $id) { self::assertTrue($policy->includeRow('wp_postmeta', ['post_id' => $id])); }
        self::assertSame('exclude', $policy->pathAction('wp-content/uploads/2026/form.pdf')['action']);
        self::assertSame('include', $policy->pathAction('wp-content/uploads/2026/unknown.pdf')['action']);
        self::assertTrue($policy->includeRow('wp_users', ['ID' => '6']));
        self::assertTrue($policy->includeRow('wp_options', ['option_name' => 'admin_email']));
        self::assertSame(['unresolvedAttachments' => 1], $context->summary());
        $rules = ['version' => 2, 'preset' => 'forms', 'rows' => ['wp_postmeta' => ['post_id' => ['6' => 'include']]], 'tables' => ['wp_posts' => 'include'], 'paths' => ['wp-content/uploads/2026/form.pdf' => 'include'], 'fields' => ['wp_postmeta.meta_value' => 'exclude']];
        $override = new PrivacyPolicy($rules); (new WordPressPolicyContext($wpdb, $override, $this->directory))->prepare();
        self::assertTrue($override->includeRow('wp_posts', ['ID' => '6']));
        self::assertTrue($override->includeRow('wp_postmeta', ['post_id' => '6']));
        self::assertSame('include', $override->pathAction('wp-content/uploads/2026/form.pdf')['action']);
        self::assertSame('exclude', $override->fieldAction('wp_postmeta', 'meta_value', ['post_id' => '6'])['action']);
        $onlyForms = new PrivacyPolicy(['version' => 2, 'preset' => 'custom', 'categories' => ['content' => 'exclude', 'forms' => 'include']]);
        (new WordPressPolicyContext($wpdb, $onlyForms, $this->directory))->prepare();
        self::assertTrue($onlyForms->tableMayIncludeRows('wp_postmeta'));
        self::assertTrue($onlyForms->includeRow('wp_postmeta', ['post_id' => '6']));
        self::assertFalse($onlyForms->includeRow('wp_postmeta', ['post_id' => '3']));
    }

    public function testLookupApiPreservesOldArrayContextAndRootSpecificRules(): void
    {
        $policy = new PrivacyPolicy(['publicContentOnly' => true, 'scanPublic' => false]);
        $policy->setPublicContentLookups(static function ($id): bool { return $id === '1'; }, static function ($path): bool { return $path === 'wp-content/uploads/one.jpg'; }, static function ($id): bool { return $id === '2'; });
        self::assertTrue($policy->includeRow('wp_postmeta', ['post_id' => '2']));
        self::assertFalse($policy->shouldInspectField('wp_posts', 'post_content', ['ID' => '1']));
        $policy->setPublicContentContext(['3' => true], ['wp-content/uploads/three.jpg' => true]);
        self::assertFalse($policy->includeRow('wp_postmeta', ['post_id' => '1']));
        self::assertTrue($policy->includeRow('wp_postmeta', ['post_id' => '3']));
        $policy = new PrivacyPolicy(['version' => 2, 'preset' => 'custom', 'categories' => ['media' => 'exclude'], 'paths' => ['r0002/same.jpg' => 'include']]);
        self::assertSame('include', $policy->pathAction('wp-content/uploads/same.jpg', 'r0002/same.jpg')['action']);
        self::assertSame('exclude', $policy->pathAction('wp-content/uploads/same.jpg', 'r0003/same.jpg')['action']);
        self::assertSame('exclude', $policy->pathAction('wp-content/uploads/same.jpg')['action']);
    }
}

final class PolicyContextWpdbFake
{
    public string $prefix = 'wp_';
    public string $last_error = '';
    public array $queries = [];
    public array $posts = [];
    public array $meta = [];
    public function __construct()
    {
        foreach ([['1','post','publish','0'], ['2','attachment','inherit','1'], ['3','wpcf7_contact_form','publish','0'], ['4','acf-field-group','publish','0'], ['5','attachment','inherit','0'], ['6','flamingo_inbound','publish','0'], ['7','attachment','inherit','6'], ['8','post','publish','0'], ['9','attachment','inherit','8'], ['10','attachment','inherit','999'], ['11','post','draft','0']] as $row) {
            $this->posts[] = ['ID' => $row[0], 'post_type' => $row[1], 'post_status' => $row[2], 'post_parent' => $row[3], 'post_password' => '', 'post_mime_type' => $row[1] === 'attachment' ? 'image/jpeg' : ''];
        }
        $this->add('2', '_wp_attached_file', '2026/image.jpg');
        $this->add('2', '_wp_attachment_metadata', serialize(['file' => '2026/image.jpg', 'original_image' => 'image-original.jpg', 'sizes' => [['file' => 'image-small.jpg']]]));
        $this->add('5', '_wp_attached_file', '2026/fallback.jpg');
        $this->add('5', '_wp_attachment_metadata', serialize(['sizes' => [['file' => 'fallback-small.jpg']]]));
        $this->add('7', '_wp_attached_file', '2026/form.pdf');
        $this->add('10', '_wp_attached_file', '2026/unknown.pdf');
    }
    private function add(string $id, string $key, string $value): void { $this->meta[] = ['meta_id' => (string) (count($this->meta) + 1), 'post_id' => $id, 'meta_key' => $key, 'meta_value' => $value]; }
    public function get_results(string $sql, string $mode): array
    {
        $this->queries[] = $sql;
        if ($sql === 'SHOW FULL TABLES') { return [['name' => 'wp_posts', 'type' => 'BASE TABLE'], ['name' => 'wp_postmeta', 'type' => 'BASE TABLE']]; }
        if (!preg_match('/ LIMIT ([0-9]+),1\z/D', $sql, $match)) { throw new \RuntimeException('Unbounded context read.'); }
        $rows = strpos($sql, ' FROM `wp_posts`') !== false ? $this->posts : $this->meta;
        if (strpos($sql, ' FROM `wp_postmeta`') !== false) {
            usort($rows, static function (array $a, array $b): int {
                $id = (int) $a['post_id'] <=> (int) $b['post_id'];
                if ($id !== 0) { return $id; }
                $kind = ($a['meta_key'] === '_wp_attached_file' ? 0 : 1) <=> ($b['meta_key'] === '_wp_attached_file' ? 0 : 1);
                return $kind !== 0 ? $kind : (int) $a['meta_id'] <=> (int) $b['meta_id'];
            });
        }
        return array_slice($rows, (int) $match[1], 1);
    }
}

/** Synthetic site with many attachments, some sharing files. Each bounded read is answered from rows sorted once. */
final class PolicyContextLargeWpdbFake
{
    public string $prefix = 'wp_';
    public string $last_error = '';
    private array $posts = [];
    private array $postRows = [];
    private array $meta = [];
    private array $sortedMeta = [];

    public function __construct(int $owners)
    {
        // Owner posts 1..owners: published posts, drafts, form submissions; attachments owners+1..2*owners belong to them.
        for ($id = 1; $id <= $owners; ++$id) {
            $type = $id % 7 === 0 ? 'flamingo_inbound' : 'post';
            $this->posts[$id] = ['ID' => (string) $id, 'post_type' => $type, 'post_status' => $id % 5 === 0 ? 'draft' : 'publish', 'post_parent' => '0', 'post_password' => '', 'post_mime_type' => ''];
        }
        for ($number = 1; $number <= $owners; ++$number) {
            $id = $owners + $number; $parent = $number % 11 === 0 ? '0' : (string) $number;
            $this->posts[$id] = ['ID' => (string) $id, 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_parent' => $parent, 'post_password' => '', 'post_mime_type' => $number % 13 === 0 ? 'application/pdf' : 'image/jpeg'];
            // Every ninth attachment past 300 reuses the exact file of the one 300 earlier, so a path is written again after
            // many others, sometimes by a form owner after a public one (378 after 78, for example).
            $shared = $number > 300 && $number % 9 === 0 ? $number - 300 : $number;
            $file = sprintf('20%02d/%02d/file-%d', 10 + $shared % 16, 1 + $shared % 12, $shared) . ($shared % 13 === 0 ? '.pdf' : '.jpg');
            $this->add($id, '_wp_attached_file', $file);
            $base = substr($file, 0, -4);
            $this->add($id, '_wp_attachment_metadata', serialize(['file' => $file, 'original_image' => basename($base) . '-original.jpg',
                'sizes' => [['file' => basename($base) . '-150x150.jpg'], ['file' => basename($base) . '-300x200.jpg']]]));
        }
        ksort($this->posts);
        $this->postRows = array_values($this->posts);
        $this->sortMeta();
    }

    public function makeSubmission(int $id): void { $this->posts[$id]['post_type'] = 'flamingo_inbound'; $this->postRows = array_values($this->posts); }

    public function paths(): array
    {
        $paths = [];
        foreach ($this->meta as $row) {
            if ($row['meta_key'] !== '_wp_attached_file') { continue; }
            $base = 'wp-content/uploads/' . substr($row['meta_value'], 0, -4);
            foreach (['', '-original', '-150x150', '-300x200'] as $suffix) { $paths[] = $base . $suffix . ($suffix === '' ? substr($row['meta_value'], -4) : '.jpg'); }
            $paths[] = dirname($base);
        }
        return array_values(array_unique($paths));
    }

    private function add(int $id, string $key, string $value): void { $this->meta[] = ['meta_id' => (string) (count($this->meta) + 1), 'post_id' => (string) $id, 'meta_key' => $key, 'meta_value' => $value]; }

    private function sortMeta(): void
    {
        $rows = $this->meta;
        usort($rows, static function (array $a, array $b): int {
            $id = (int) $a['post_id'] <=> (int) $b['post_id'];
            if ($id !== 0) { return $id; }
            $kind = ($a['meta_key'] === '_wp_attached_file' ? 0 : 1) <=> ($b['meta_key'] === '_wp_attached_file' ? 0 : 1);
            return $kind !== 0 ? $kind : (int) $a['meta_id'] <=> (int) $b['meta_id'];
        });
        $this->sortedMeta = $rows;
    }

    public function get_results(string $sql, string $mode): array
    {
        if ($sql === 'SHOW FULL TABLES') { return [['name' => 'wp_posts', 'type' => 'BASE TABLE'], ['name' => 'wp_postmeta', 'type' => 'BASE TABLE']]; }
        if (!preg_match('/ LIMIT ([0-9]+),1\z/D', $sql, $match)) { throw new \RuntimeException('Unbounded context read.'); }
        $rows = strpos($sql, ' FROM `wp_posts`') !== false ? $this->postRows : $this->sortedMeta;
        $offset = (int) $match[1];
        return isset($rows[$offset]) ? [$rows[$offset]] : [];
    }
}

/** Public and form attachments whose files may be chosen so their path keys share one map partition. */
final class PolicyContextSkewedWpdbFake
{
    public string $prefix = 'wp_';
    public string $last_error = '';
    private array $posts = [];
    private array $meta = [];

    /** File names, under uploads, whose path keys all fall in the partition of the first one. */
    public static function skewedFiles(int $count): array
    {
        $files = []; $target = null;
        for ($number = 0; count($files) < $count; ++$number) {
            $file = 'skew/f' . $number . '.jpg';
            $partition = substr(hash('sha256', hash('sha256', 'path/wp-content/uploads/' . $file)), 0, 3);
            $target = $target ?? $partition;
            if ($partition === $target) { $files[] = $file; }
        }
        return $files;
    }

    public function __construct(array $skewed)
    {
        $this->posts[1] = ['ID' => '1', 'post_type' => 'post', 'post_status' => 'publish', 'post_parent' => '0', 'post_password' => '', 'post_mime_type' => ''];
        $this->posts[2] = ['ID' => '2', 'post_type' => 'flamingo_inbound', 'post_status' => 'publish', 'post_parent' => '0', 'post_password' => '', 'post_mime_type' => ''];
        // Ordinary paths interleave with the skewed ones; the first skewed file is written again last by a form owner.
        $files = [];
        foreach ($skewed as $number => $file) { $files[] = $file; $files[] = 'plain/p' . $number . '.jpg'; }
        $files[] = $skewed[0];
        foreach ($files as $number => $file) {
            $id = 10 + $number;
            $this->posts[$id] = ['ID' => (string) $id, 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_parent' => $number === count($files) - 1 || $number % 5 === 4 ? '2' : '1', 'post_password' => '', 'post_mime_type' => 'image/jpeg'];
            $this->meta[] = ['meta_id' => (string) (count($this->meta) + 1), 'post_id' => (string) $id, 'meta_key' => '_wp_attached_file', 'meta_value' => $file];
        }
        ksort($this->posts); $this->posts = array_values($this->posts);
    }

    public function paths(): array
    {
        $paths = ['wp-content/uploads/skew', 'wp-content/uploads/plain'];
        foreach ($this->meta as $row) { $paths[] = 'wp-content/uploads/' . $row['meta_value']; }
        return array_values(array_unique($paths));
    }

    public function get_results(string $sql, string $mode): array
    {
        if ($sql === 'SHOW FULL TABLES') { return [['name' => 'wp_posts', 'type' => 'BASE TABLE'], ['name' => 'wp_postmeta', 'type' => 'BASE TABLE']]; }
        if (!preg_match('/ LIMIT ([0-9]+),1\z/D', $sql, $match)) { throw new \RuntimeException('Unbounded context read.'); }
        $rows = strpos($sql, ' FROM `wp_posts`') !== false ? $this->posts : $this->meta;
        $offset = (int) $match[1];
        return isset($rows[$offset]) ? [$rows[$offset]] : [];
    }
}
