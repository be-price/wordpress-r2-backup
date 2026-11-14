<?php
declare(strict_types=1);

namespace WordPressR2Backup\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use WordPressR2Backup\AgeProcess;
use WordPressR2Backup\V4Format;
use WordPressR2Backup\V4Pages;

final class V4PagesTest extends TestCase
{
    private string $directory;
    private array $keys;
    private AgeProcess $age;

    protected function setUp(): void
    {
        $this->directory = Fixture::temp(); $this->keys = Fixture::keys(); $this->age = new AgeProcess(Fixture::runtime()['age']);
    }
    protected function tearDown(): void { Fixture::cleanup(); }

    public function testCountBoundaryCreatesVerifiablePagesWithoutPlaintextFiles(): void
    {
        $pages = $this->pages(); $record = ['type' => 'end', 'sha256' => hash('sha256', '')];
        for ($i = 0; $i < 1025; ++$i) { $pages->append($record); }
        $descriptors = $pages->finish(); self::assertSame($descriptors, $pages->finish());
        self::assertSame([1024, 1], array_column($descriptors, 'count')); self::assertSame([0, 1], array_column($descriptors, 'sequence'));
        self::assertCount(2, array_diff(scandir($this->directory), ['.', '..']));
        foreach ($descriptors as $index => $descriptor) {
            self::assertSame(0600, fileperms($descriptor['cipherFile']) & 0777);
            self::assertSame(filesize($descriptor['cipherFile']), $descriptor['object']['bytes']);
            self::assertSame(hash_file('sha256', $descriptor['cipherFile']), $descriptor['object']['sha256']);
            foreach ($this->keys as $keyIndex => $key) {
                $plain = $this->directory . '/plain-' . $index . '-' . $keyIndex; $identity = fopen($key['identity'], 'rb');
                try { $this->age->decrypt($descriptor['cipherFile'], $plain, $identity); } finally { fclose($identity); }
                $bytes = file_get_contents($plain); $page = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR); $schema = $descriptor; unset($schema['cipherFile']);
                V4Format::validatePage($page, $schema); self::assertSame(strlen($bytes), $descriptor['size']);
                self::assertSame(array_fill(0, $descriptor['count'], $record), $page['records']);
            }
        }
        $this->expectException(RuntimeException::class); $pages->append($record);
    }

    public function testSizeBoundaryFlushesBeforeRecordCountLimitAndKeepsRecordOrder(): void
    {
        $pages = $this->pages(); $prefix = str_repeat(str_repeat('a', 200) . '/', 10);
        for ($i = 0; $i < 600; ++$i) { $pages->append(['type' => 'directory', 'rootId' => 'r0001', 'path' => $prefix . (string) $i, 'mode' => 0755]); }
        $descriptors = $pages->finish(); self::assertCount(2, $descriptors); self::assertLessThan(1024, $descriptors[0]['count']);
        $next = 0;
        foreach ($descriptors as $index => $descriptor) {
            self::assertLessThanOrEqual(V4Format::MAX_PAGE_BYTES, $descriptor['size']);
            $identity = fopen($this->keys[0]['identity'], 'rb'); $plain = $this->directory . '/page-' . $index . '.json';
            try { $this->age->decrypt($descriptor['cipherFile'], $plain, $identity); } finally { fclose($identity); }
            $page = json_decode(file_get_contents($plain), true, 512, JSON_THROW_ON_ERROR); $schema = $descriptor; unset($schema['cipherFile']);
            V4Format::validatePage($page, $schema);
            foreach ($page['records'] as $record) { self::assertSame($prefix . (string) $next++, $record['path']); }
        }
        self::assertSame(600, $next);
    }

    public function testEmptyFinishAndSchemaRejectionDoNotCreateCiphertext(): void
    {
        $pages = $this->pages();
        try { $pages->append(['type' => 'end', 'sha256' => str_repeat('0', 64), 'oversized' => str_repeat('x', V4Format::MAX_RECORD_BYTES)]); self::fail('An oversized record must fail.'); }
        catch (InvalidArgumentException $expected) { self::assertSame(['.', '..'], scandir($this->directory)); }
        self::assertSame([], $pages->finish()); self::assertSame(['.', '..'], scandir($this->directory));
    }

    public function testEncryptionFailureLeavesNoPartialPageAndCannotBeReportedAsFinished(): void
    {
        $fake = Fixture::temp() . '/failing-age';
        file_put_contents($fake, '#!' . Fixture::phpForShebang() . "\n<?php fwrite(STDOUT, 'partial'); exit(19);\n"); chmod($fake, 0700);
        $pages = new V4Pages(new AgeProcess($fake), array_column($this->keys, 'public'), $this->directory, str_repeat('b', 64), 'filesystem');
        $pages->append(['type' => 'end', 'sha256' => hash('sha256', '')]);
        try { $pages->finish(); self::fail('Encryption failure must fail.'); }
        catch (RuntimeException $expected) { self::assertSame(['.', '..'], scandir($this->directory)); }
        $this->expectException(RuntimeException::class); $pages->finish();
    }

    private function pages(): V4Pages { return new V4Pages($this->age, array_column($this->keys, 'public'), $this->directory, str_repeat('b', 64), 'filesystem'); }
}
