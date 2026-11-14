<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The transfer of shared packs checks the locked index without reading R2; publication still reads every object in full
 * before and after its completion record. Synthetic capture, transfer and publication only; no restore runs.
 */
final class LiveSharedPackTransferTest extends TestCase
{
    /** @dataProvider scenarios */
    public function testSharedPackTransferAndPublication(string $scenario, string $field): void
    {
        $output = tmpfile(); $error = tmpfile();
        $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/shared-pack-transfer.php', dirname(__DIR__), $scenario], [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $error], $pipes);
        self::assertIsResource($process); $exit = proc_close($process); rewind($output); rewind($error);
        $body = stream_get_contents($output); $message = stream_get_contents($error); fclose($output); fclose($error);
        self::assertSame(0, $exit, $message . $body);
        $result = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        self::assertSame($scenario, $result['scenario']);
        self::assertGreaterThanOrEqual(3, $result['shared']);
        self::assertArrayHasKey($field, $result);
    }

    public function scenarios(): array
    {
        return [
            'one step, no R2 access, two full reads at publication' => ['success', 'publicationFullReads'],
            'receipt differs from the index' => ['mismatch', 'mismatchRejected'],
            'pack no longer ready in the index' => ['not-ready', 'notReadyRejected'],
            'pack corrupted before publication' => ['corrupt', 'publicationRejected'],
            'pack deleted before publication' => ['deleted', 'publicationRejected'],
            'legacy job without a guard reads four packs in full per step' => ['legacy', 'legacyFullReads'],
            'capture-ready file missing' => ['guard-missing', 'guardRejected'],
            'guard differs between state and job' => ['guard-mismatch', 'guardRejected'],
            'guard only in the job' => ['guard-half', 'guardRejected'],
        ];
    }
}
