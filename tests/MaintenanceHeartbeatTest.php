<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;

final class MaintenanceHeartbeatTest extends TestCase
{
    protected function tearDown(): void { Fixture::cleanup(); }

    public function testSlowActiveWorkRefreshesMaintenanceForMoreThanTenMinutes(): void
    {
        $directory=Fixture::temp(); $out=tmpfile(); $err=tmpfile();
        $process=proc_open([PHP_BINARY,__DIR__.'/fixtures/maintenance-heartbeat.php',dirname(__DIR__),$directory],
            [0=>['file','/dev/null','r'],1=>$out,2=>$err],$pipes);
        self::assertIsResource($process); $exit=proc_close($process); rewind($out); rewind($err);
        $body=stream_get_contents($out); $error=stream_get_contents($err); fclose($out); fclose($err);
        self::assertSame(0,$exit,$error); $result=json_decode($body,true,16,JSON_THROW_ON_ERROR);
        self::assertGreaterThan(600,$result['simulatedSeconds']); self::assertGreaterThanOrEqual(2,$result['policyCalls']);
        self::assertGreaterThanOrEqual(2,$result['databaseCalls']); self::assertGreaterThanOrEqual(1,$result['fileCalls']);
    }

    public function testElapsedTimeChecksAreRequiredForShortPolicyAndFileWork(): void
    {
        $root=dirname(__DIR__); $private=Fixture::temp();
        foreach (['WordPressPolicyContext','V4Capture'] as $class) {
            $source=file_get_contents($root.'/src/'.$class.'.php');
            $source=str_replace('time() - $lastProgress >= 15','false',$source,$count);
            self::assertSame(1,$count, $class . ' elapsed-time condition must be targeted');
            $mutant=$private.'/'.$class.'.php'; file_put_contents($mutant,$source);
            $args=[PHP_BINARY,__DIR__.'/fixtures/maintenance-heartbeat.php',$root,Fixture::temp(),
                $class==='WordPressPolicyContext' ? $mutant : '-', $class==='V4Capture' ? $mutant : '-'];
            $out=tmpfile(); $err=tmpfile(); $process=proc_open($args,[0=>['file','/dev/null','r'],1=>$out,2=>$err],$pipes);
            self::assertIsResource($process); $exit=proc_close($process); fclose($out); fclose($err);
            self::assertNotSame(0,$exit,$class . ' must fail when its elapsed-time notification is removed');
        }
    }

    public function testSlowSingleAttachmentNeedsItsInnerHeartbeat(): void
    {
        $root=dirname(__DIR__); $private=Fixture::temp(); $source=file_get_contents($root.'/src/WordPressPolicyContext.php');
        $start=strpos($source,'private function media('); $end=strpos($source,'private function attachmentBits(', $start);
        self::assertIsInt($start); self::assertIsInt($end);
        $section=substr($source,$start,$end-$start); $section=str_replace('$pulse();','/* removed */',$section,$count);
        self::assertGreaterThanOrEqual(2,$count); $mutant=$private.'/policy.php';
        file_put_contents($mutant,substr($source,0,$start).$section.substr($source,$end));
        foreach (['-', $mutant] as $path) {
            $out=tmpfile(); $err=tmpfile();
            $process=proc_open([PHP_BINARY,__DIR__.'/fixtures/policy-inner-heartbeat.php',$root,Fixture::temp(),$path],
                [0=>['file','/dev/null','r'],1=>$out,2=>$err],$pipes);
            self::assertIsResource($process); $exit=proc_close($process); rewind($out); rewind($err);
            $body=stream_get_contents($out); $error=stream_get_contents($err); fclose($out); fclose($err);
            if ($path==='-') {
                self::assertSame(0,$exit,$error); $result=json_decode($body,true,16,JSON_THROW_ON_ERROR);
                self::assertGreaterThanOrEqual(2,$result['calls']); self::assertLessThan(500,$result['maxEntries']);
            } else { self::assertNotSame(0,$exit,'Removing the single-attachment heartbeat must fail'); }
        }
    }
}
