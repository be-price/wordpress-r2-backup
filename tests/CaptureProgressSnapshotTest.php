<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;
use PHPUnit\Framework\TestCase;

/** Synthetic progress and local file operations only; no recovery or network. */
final class CaptureProgressSnapshotTest extends TestCase
{
    public function testHeartbeatPreservesCountersAndRespectsRunPhaseAndFailures(): void
    {
        $out=tmpfile();$err=tmpfile();$p=proc_open([PHP_BINARY,'-d','display_errors=1','-d','log_errors=0',__DIR__.'/fixtures/capture-progress-snapshot.php',dirname(__DIR__)],[0=>['file','/dev/null','r'],1=>$out,2=>$err],$pipes);
        self::assertIsResource($p);$exit=proc_close($p);rewind($out);rewind($err);$body=stream_get_contents($out);$error=stream_get_contents($err);fclose($out);fclose($err);
        self::assertSame(0,$exit,$error.$body);self::assertStringEndsWith("ok\n",$body);
    }
}
