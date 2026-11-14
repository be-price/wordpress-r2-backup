<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;

final class CurlConnectionReuseTest extends TestCase
{
    public function testConnectionReuseResetsRequestStateAndKeepsTlsFailuresFatal(): void
    {
        $p=proc_open([PHP_BINARY,__DIR__.'/fixtures/r2-curl-reuse.php'],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        self::assertIsResource($p);
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        self::assertSame(0,proc_close($p),$err);
        self::assertStringContainsString('probe, TLS failure, and subsequent verified read passed',$out);
    }
}
