<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\Restorer;
use WordPressR2Backup\RestoreService;

/** Checks presentation only: no restore or external service. */
final class RestoreConsistencyWarningTest extends TestCase
{
    protected function tearDown(): void { Fixture::cleanup(); }

    public function testVerifiedOnlineDescriptionAndReviewIncludeTheDifferenceAndLegacyDoesNot(): void
    {
        require_once __DIR__ . '/V4FormatTest.php';
        $fixture = new V4FormatTest('root');
        $method = new \ReflectionMethod($fixture, 'root'); $method->setAccessible(true);
        $legacy = $method->invoke($fixture);
        $describe = new \ReflectionMethod(Restorer::class, 'describeManifest'); $describe->setAccessible(true);
        $old = $describe->invoke(null, $legacy, ['sha256' => str_repeat('f', 64)]);
        self::assertArrayNotHasKey('consistency', $old);
        $online = $legacy; $online['wordpress']['consistencyMode'] = 'online';
        $online['wordpress']['evidence'] = ['startedAt' => '2026-10-29T23:59:59Z', 'snapshotAt' => $online['capturedAt'], 'databaseVerifiedAt' => '2026-10-30T00:00:01Z', 'filesVerifiedAt' => '2026-10-30T00:00:02Z', 'finishedAt' => '2026-10-30T00:00:03Z', 'scan' => [], 'tables' => []];
        $new = $describe->invoke(null, $online, ['sha256' => str_repeat('f', 64)]);
        self::assertSame(['mode' => 'online', 'snapshotAt' => '2026-10-30T00:00:00Z', 'filesVerifiedAt' => '2026-10-30T00:00:02Z'], $new['consistency']);
        $service = (new \ReflectionClass(RestoreService::class))->newInstanceWithoutConstructor();
        $summary = new \ReflectionMethod($service, 'summary'); $summary->setAccessible(true);
        $plan = ['name' => 'sample', 'snapshot' => str_repeat('a', 64), 'purpose' => 'local', 'database' => 'sqlite', 'targetUrl' => '', 'previewUrl' => 'http://127.0.0.1:1234', 'aliases' => [], 'adminLogin' => 'test'];
        $facts = ['sourceUrl' => 'https://example.test'];
        self::assertArrayNotHasKey('consistency', $summary->invoke($service, $plan, $facts, $old, '/tmp/example'));
        self::assertSame($new['consistency'], $summary->invoke($service, $plan, $facts, $new, '/tmp/example')['consistency']);
        unset($online['wordpress']['evidence']['filesVerifiedAt']);
        $this->expectException(\InvalidArgumentException::class);
        $describe->invoke(null, $online, ['sha256' => str_repeat('f', 64)]);
    }

    public function testAnUnknownModeCannotAppearAsAnOrdinaryGeneration(): void
    {
        require_once __DIR__ . '/V4FormatTest.php';
        $fixture = new V4FormatTest('root');
        $method = new \ReflectionMethod($fixture, 'root'); $method->setAccessible(true);
        $root = $method->invoke($fixture); $root['wordpress']['consistencyMode'] = 'forged';
        $describe = new \ReflectionMethod(Restorer::class, 'describeManifest'); $describe->setAccessible(true);
        $this->expectException(\InvalidArgumentException::class);
        $describe->invoke(null, $root, ['sha256' => str_repeat('f', 64)]);
    }

    public function testConfirmationUsesAVisibleWarningOnlyForOnlineGeneration(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') { self::markTestSkipped('Node.js is required.'); }
        $process = proc_open([$node, __DIR__ . '/fixtures/restore-consistency-warning.cjs', dirname(__DIR__)], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $out . $err);
        self::assertSame("ok\n", $out);
    }
}
