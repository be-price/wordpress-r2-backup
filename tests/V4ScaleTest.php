<?php
declare(strict_types=1);

namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use WordPressR2Backup\AgeProcess;
use WordPressR2Backup\Manifest;
use WordPressR2Backup\ObjectReader;
use WordPressR2Backup\V4Format;
use WordPressR2Backup\V4Pack;
use WordPressR2Backup\V4Pages;
use WordPressR2Backup\V4Restorer;

/** Opt-in metadata capacity check; no real source files, database, or remote objects. */
final class V4ScaleTest extends TestCase
{
    private const FULL_FILES = 229712;

    public function testEncryptedInventoryInspectionCompletesWithin128MiB(): void
    {
        if (getenv('WRB_RUN_V4_SCALE') !== '1') { self::markTestSkipped('Set WRB_RUN_V4_SCALE=1 to run the synthetic v4 inventory capacity check.'); }
        $requested = getenv('WRB_V4_SCALE_FILES');
        if ($requested !== false && !preg_match('/\A[1-9][0-9]*\z/D', $requested)) { self::fail('WRB_V4_SCALE_FILES must be a positive integer.'); }
        $files = $requested === false ? self::FULL_FILES : (int) $requested;
        self::assertGreaterThan(0, $files); self::assertLessThanOrEqual(self::FULL_FILES, $files);
        $code = 'require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . '; require ' . var_export(__FILE__, true)
            . '; echo json_encode(' . self::class . '::runInventory(' . $files . '), JSON_THROW_ON_ERROR), "\\n";';
        $process = proc_open([PHP_BINARY, '-d', 'memory_limit=128M', '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', 'php://stderr', 'w']], $pipes);
        self::assertIsResource($process); fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]); fclose($pipes[1]); $exit = proc_close($process);
        self::assertSame(0, $exit, 'The 128MiB child failed: ' . $output);
        $result = json_decode($output, true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('128M', $result['memoryLimit']);
        self::assertLessThanOrEqual(128 * 1024 * 1024, $result['peakBytes']);
        self::assertSame($files, $result['generatedFiles']);
        self::assertSame($files * 3, $result['filesystemRecords']);
        self::assertSame($files, $result['summary']['capture']['counts']['files']);
        self::assertSame(2, $result['summary']['capture']['counts']['contents']);
        self::assertSame(1, $result['summary']['capture']['counts']['packs']);
        self::assertTrue($result['summary']['verification']['pagesDecrypted']);
        self::assertTrue($result['summary']['verification']['referencedCiphertexts']);
        self::assertFalse($result['summary']['verification']['payloadDecrypted']);
        self::assertFalse($result['summary']['verification']['databaseImported']);
        self::assertSame($result['pages'], $result['pageDownloads']);
        self::assertSame(1, $result['packVerifications']);
        self::assertLessThan(1000, $result['pages']);
        fwrite(STDOUT, 'V4_SCALE_RESULT ' . json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
    }

    /** The entire fixture generation and inspect path runs in the constrained child. */
    public static function runInventory(int $files): array
    {
        if (getenv('WRB_RUN_V4_SCALE') !== '1' || ini_get('memory_limit') !== '128M' || $files < 1 || $files > self::FULL_FILES) { throw new RuntimeException('Synthetic scale child requires explicit opt-in and memory_limit=128M.'); }
        $directory = Fixture::temp(); $identity = null;
        try {
            $keys = Fixture::keys(2); $recipients = array_column($keys, 'public');
            $age = new AgeProcess(Fixture::runtime()['age']);
            $reader = new V4ScaleReader(str_repeat('a', 64));
            $snapshot = hash('sha256', 'v4-scale-inventory-' . $files);
            $scope = ['accountId' => str_repeat('b', 32), 'bucket' => 'fixture-bucket', 'unitId' => $reader->unitId(), 'keySetId' => Manifest::keySetId($recipients), 'encoding' => V4Format::ENCODING];
            $text = self::content('synthetic-shared-content'); $sql = self::content("SELECT 1;\n");
            $pack = V4Pack::write($age, $recipients, $directory, V4Format::scopeId($scope), 'code', [$text, $sql]);
            $reader->add($pack['descriptor']['object'], $pack['cipherFile']);
            $textRef = $text; unset($textRef['bytes']); $textRef['pack'] = $pack['descriptor']['object']['key'];
            $sqlRef = $sql; unset($sqlRef['bytes']); $sqlRef['pack'] = $pack['descriptor']['object']['key'];
            $packPages = new V4Pages($age, $recipients, $directory, $snapshot, 'packs');
            $packPages->append($pack['descriptor']); $pages = self::finishPages($packPages, $reader);
            $filesystem = new V4Pages($age, $recipients, $directory, $snapshot, 'filesystem');
            for ($index = 0; $index < $files; ++$index) {
                $filesystem->append(['type' => 'file', 'rootId' => 'r0001', 'path' => sprintf('f%09d.bin', $index), 'size' => $text['size'], 'mode' => 0600]);
                $filesystem->append(['type' => 'part', 'content' => $textRef]);
                $filesystem->append(['type' => 'end', 'sha256' => $text['sha256']]);
                if (($index + 1) % 25000 === 0) { fwrite(STDERR, 'V4_SCALE_GENERATED_FILES ' . ($index + 1) . "\n"); }
            }
            $filesystemPages = self::finishPages($filesystem, $reader); $filesystemRecords = 0;
            foreach ($filesystemPages as $descriptor) { $filesystemRecords += $descriptor['count']; $pages[] = $descriptor; }
            unset($filesystemPages, $filesystem, $packPages);
            $database = new V4Pages($age, $recipients, $directory, $snapshot, 'database');
            $database->append(['type' => 'sql', 'stage' => 'preamble', 'name' => 'database', 'content' => $sqlRef]);
            foreach (self::finishPages($database, $reader) as $descriptor) { $pages[] = $descriptor; }
            unset($database);
            $policy = ['version' => 2, 'preset' => 'full'];
            $manifest = ['format' => 'wordpress-r2-backup', 'version' => 4, 'dataClass' => 'wordpress', 'unitId' => $scope['unitId'], 'snapshotId' => $snapshot, 'keySetId' => $scope['keySetId'], 'recipients' => $recipients, 'capturedAt' => '2026-10-30T00:00:00Z', 'encoding' => V4Format::ENCODING, 'scope' => $scope,
                'roots' => [['id' => 'r0001', 'name' => 'r0001', 'sourcePath' => '/synthetic/r0001', 'type' => 'directory', 'mode' => 0700]], 'pages' => $pages,
                'counts' => ['files' => $files, 'directories' => 0, 'links' => 0, 'contents' => 2, 'packs' => 1, 'databaseBytes' => $sql['size'], 'fileBytes' => $files * $text['size']],
                'database' => ['size' => $sql['size'], 'sha256' => $sql['sha256'], 'tableCount' => 0, 'events' => [], 'environment' => ['serverVersion' => '8.0.40', 'sqlMode' => '', 'timeZone' => '+00:00', 'characterSet' => 'utf8mb4', 'collation' => 'utf8mb4_bin']],
                'wordpress' => ['siteUrl' => 'https://synthetic.invalid', 'policySha256' => hash('sha256', V4Format::json($policy)), 'policy' => $policy, 'evidence' => []]];
            V4Format::validateRoot($manifest);
            if (count($pages) >= 1000 || $filesystemRecords !== $files * 3) { throw new RuntimeException('Synthetic inventory generation is incomplete or unexpectedly large.'); }
            $identity = fopen($keys[0]['identity'], 'rb');
            if (!is_resource($identity)) { throw new RuntimeException('Cannot open synthetic identity.'); }
            fwrite(STDERR, 'V4_SCALE_INSPECT_START files=' . $files . ' records=' . $filesystemRecords . ' pages=' . count($pages) . "\n");
            // inspect consumes every filesystem path/reference/count via its on-disk MetadataMap.
            $summary = (new V4Restorer($age, $reader))->inspect($manifest, $identity, $directory);
            return ['phpVersion' => PHP_VERSION, 'memoryLimit' => ini_get('memory_limit'), 'peakBytes' => memory_get_peak_usage(true), 'generatedFiles' => $files, 'filesystemRecords' => $filesystemRecords, 'pages' => count($pages), 'pageDownloads' => $reader->pageDownloads, 'packVerifications' => $reader->packVerifications, 'summary' => $summary];
        } finally { if (is_resource($identity)) { fclose($identity); } Fixture::cleanup(); }
    }

    private static function content(string $bytes): array
    {
        $hash = hash('sha256', $bytes);
        return ['id' => V4Format::contentId(strlen($bytes), $hash), 'size' => strlen($bytes), 'sha256' => $hash, 'bytes' => $bytes];
    }

    private static function finishPages(V4Pages $writer, V4ScaleReader $reader): array
    {
        $pages = $writer->finish();
        foreach ($pages as &$page) { $reader->add($page['object'], $page['cipherFile']); unset($page['cipherFile']); }
        unset($page);
        return $pages;
    }
}

/** Keeps only bounded ciphertext paths and receipts; never the filesystem inventory. */
final class V4ScaleReader implements ObjectReader
{
    private string $unit;
    private array $objects = [];
    public int $pageDownloads = 0;
    public int $packVerifications = 0;
    public function __construct(string $unit) { $this->unit = $unit; }
    public function unitId(): string { return $this->unit; }
    public function add(array $receipt, string $cipherFile): void { $this->objects[$receipt['key']] = ['receipt' => $receipt, 'path' => $cipherFile]; }
    public function verify(array $receipt): void
    {
        $object = $this->objects[$receipt['key']] ?? null; $path = $object['path'] ?? '';
        if ($object === null || $object['receipt'] !== $receipt || !is_file($path) || filesize($path) !== $receipt['bytes'] || hash_file('sha256', $path) !== $receipt['sha256']) { throw new RuntimeException('Synthetic scale ciphertext receipt mismatch.'); }
        if (strpos($receipt['key'], 'packs/') === 0) { ++$this->packVerifications; }
    }
    public function download(array $receipt, string $newCipherFile): void
    {
        if (strpos($receipt['key'], 'pages/') !== 0) { throw new RuntimeException('Metadata inspection must never download a pack body.'); }
        $this->verify($receipt); $target = fopen($newCipherFile, 'x+b');
        if (!is_resource($target)) { throw new RuntimeException('Cannot create synthetic ciphertext download.'); }
        chmod($newCipherFile, 0600); $source = fopen($this->objects[$receipt['key']]['path'], 'rb');
        if (!is_resource($source)) { fclose($target); throw new RuntimeException('Cannot read synthetic ciphertext.'); }
        try { if (stream_copy_to_stream($source, $target) !== $receipt['bytes']) { throw new RuntimeException('Incomplete synthetic ciphertext copy.'); } }
        finally { fclose($source); fclose($target); }
        ++$this->pageDownloads;
        if ($this->pageDownloads % 100 === 0) { fwrite(STDERR, 'V4_SCALE_INSPECTED_PAGES ' . $this->pageDownloads . "\n"); }
    }
    public function completed(string $snapshotId): array { throw new RuntimeException('Synthetic scale inspection must not use an index.'); }
    public function listCompleted(): array { throw new RuntimeException('No synthetic index is available.'); }
    public function listStatus(): array { return []; }
    public function listFileObjects(string $keySetId): array { throw new RuntimeException('No prior synthetic objects are available.'); }
}
