<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;
use PHPUnit\Framework\TestCase;
use WordPressR2Backup\{AgeProcess, PrivacyPolicy, WordPressSource};

final class WordPressSourceTest extends TestCase
{
    protected function tearDown(): void { Fixture::cleanup(); }
    public function testScanReportsTargetsWithoutReportingTheirPrivateValues(): void
    {
        $root = Fixture::temp(); Fixture::write($root, 'wp-config.php', 'secret configuration');
        Fixture::write($root, 'index.php', '<?php echo "public";');
        Fixture::write($root, 'wp-content/uploads/note.txt', 'person@example.test');
        $source = new WordPressSource($root, new SourceWpdbFixture(), new PrivacyPolicy([]), Fixture::temp());
        $scan = $source->scan();
        self::assertGreaterThan(0, $scan['unresolved']);
        self::assertStringNotContainsString('person@example.test', json_encode($scan));
        self::assertStringNotContainsString('private@example.test', json_encode($scan));
        self::assertSame('exclude', $scan['tables'][1]['action']);
    }
    public function testCaptureStreamsFilteredDatabaseAndFilesAndKeepsOnlyCiphertext(): void
    {
        $root = Fixture::temp(); Fixture::write($root, 'index.php', '<?php echo "public";');
        Fixture::write($root, 'wp-config.php', 'secret configuration');
        Fixture::write($root, 'wp-includes/SimplePie/src/Cache/BaseDataCache.php', '<?php interface BaseDataCache {}');
        Fixture::write($root, 'wp-content/cache/customer.json', 'private@example.test');
        $private = Fixture::temp(); $keys = Fixture::keys();
        $db = new SourceWpdbFixture();
        $policy = new PrivacyPolicy(['scanPublic' => false]);
        $source = new WordPressSource($root, $db, $policy, $private);
        $age = new AgeProcess(Fixture::runtime()['age']);
        $result = $source->capture($age, array_column($keys, 'public'), str_repeat('a', 64));
        $capturedPaths = array_column($result['files'], 'path');
        self::assertContains('index.php', $capturedPaths);
        self::assertContains('wp-includes/SimplePie/src/Cache', $capturedPaths);
        self::assertContains('wp-includes/SimplePie/src/Cache/BaseDataCache.php', $capturedPaths);
        self::assertNotContains('wp-content/cache/customer.json', $capturedPaths);
        self::assertTrue($db->locked);
        self::assertTrue($db->unlocked);
        $plain = Fixture::temp() . '/database.sql'; $identity = fopen($keys[0]['identity'], 'rb');
        try { $age->decrypt($result['database']['cipherFile'], $plain, $identity); }
        finally { fclose($identity); }
        $sql = file_get_contents($plain);
        self::assertStringContainsString('CREATE TABLE `wp_posts`', $sql);
        self::assertStringContainsString('CREATE TABLE `wp_users`', $sql);
        self::assertStringContainsString('INSERT INTO `wp_posts`', $sql);
        self::assertStringContainsString('CONVERT(', $sql);
        self::assertStringNotContainsString('INSERT INTO `wp_users`', $sql);
        self::assertStringNotContainsString(bin2hex('private@example.test'), $sql);
        self::assertSame(hash('sha256', $sql), $result['database']['sha256']);
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($private, \FilesystemIterator::SKIP_DOTS)) as $file) {
            self::assertSame('age', $file->getExtension());
            self::assertStringNotContainsString('private@example.test', file_get_contents($file->getPathname()));
        }
    }
    public function testUnresolvedCaptureAndDatabaseFailuresCannotSucceed(): void
    {
        $root = Fixture::temp(); Fixture::write($root, 'unknown.dat', 'private');
        $source = new WordPressSource($root, new SourceWpdbFixture(), new PrivacyPolicy([]), Fixture::temp());
        $this->expectException(\RuntimeException::class);
        $source->capture(new AgeProcess(Fixture::runtime()['age']), array_column(Fixture::keys(), 'public'), str_repeat('b', 64));
    }
    public function testAChangedFileAndFailedReadLockCannotBeReportedAsSuccessfulCapture(): void
    {
        $root = Fixture::temp(); Fixture::write($root, 'index.php', 'public before');
        $db = new SourceWpdbFixture(); $keys = Fixture::keys();
        $source = new WordPressSource($root, $db, new PrivacyPolicy(['scanPublic' => false]), Fixture::temp());
        $failure = null;
        try { $source->capture(new AgeProcess(Fixture::runtime()['age']), array_column($keys, 'public'), str_repeat('c', 64), static function () use ($root): void { file_put_contents($root . '/index.php', 'public after'); }); }
        catch (\RuntimeException $error) { $failure = $error; }
        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertTrue($db->unlocked);
        $db = new SourceWpdbFixture(); $db->failLock = true;
        $source = new WordPressSource($root, $db, new PrivacyPolicy(['scanPublic' => false]), Fixture::temp());
        $failure = null;
        try { $source->capture(new AgeProcess(Fixture::runtime()['age']), array_column($keys, 'public'), str_repeat('d', 64)); }
        catch (\RuntimeException $error) { $failure = $error; }
        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertFalse($db->locked);
    }
    public function testDatabaseReadErrorsAreNotTreatedAsAnEmptySource(): void
    {
        $db = new SourceWpdbFixture(); $db->last_error = 'fixture failure';
        $source = new WordPressSource(Fixture::temp(), $db, new PrivacyPolicy([]), Fixture::temp());
        $this->expectException(\RuntimeException::class);
        $source->scan();
    }
}

final class SourceWpdbFixture
{
    public string $prefix = 'wp_';
    public string $last_error = '';
    public bool $locked = false;
    public bool $unlocked = false;
    public bool $failLock = false;
    public function query(string $sql) { if (strpos($sql, 'LOCK TABLES ') === 0) { if ($this->failLock) { return false; } $this->locked = true; } if ($sql === 'UNLOCK TABLES') { $this->unlocked = true; } return 1; }
    public function get_var(string $sql)
    {
        if (strpos($sql, 'CONNECTION_ID') !== false) { return '10'; }
        if (strpos($sql, 'character_set_connection') !== false) { return 'utf8mb4'; }
        if (strpos($sql, 'information_schema') !== false) { return '0'; }
        if (strpos($sql, 'COUNT(*)') !== false) { return '1'; }
        return null;
    }
    public function get_results(string $sql, $format = null): array
    {
        if ($sql === 'SHOW FULL TABLES') { return [['table' => 'wp_posts', 'type' => 'BASE TABLE'], ['table' => 'wp_users', 'type' => 'BASE TABLE']]; }
        if (strpos($sql, 'SHOW FULL COLUMNS FROM ') === 0) { return [
            ['Field' => 'ID', 'Type' => 'bigint unsigned', 'Null' => 'NO', 'Key' => 'PRI', 'Extra' => 'auto_increment'],
            ['Field' => 'post_title', 'Type' => 'text', 'Null' => 'NO', 'Key' => '', 'Extra' => ''],
        ]; }
        if (strpos($sql, 'SHOW CREATE TABLE ') === 0) { preg_match('/`([^`]+)`/', $sql, $m); return [['Table' => $m[1], 'Create Table' => 'CREATE TABLE `' . $m[1] . '` (`ID` bigint NOT NULL, `post_title` text NOT NULL) ENGINE=InnoDB']]; }
        if (strpos($sql, 'SELECT * FROM ') === 0) {
            if (strpos($sql, 'LIMIT 0,') === false) { return []; }
            return [['ID' => '1', 'post_title' => strpos($sql, 'wp_users') !== false ? 'private@example.test' : 'public title']];
        }
        return [];
    }
}
