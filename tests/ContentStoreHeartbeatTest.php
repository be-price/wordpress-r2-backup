<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;
use PHPUnit\Framework\TestCase;

/** Real encrypted pack creation; no backup is restored. */
final class ContentStoreHeartbeatTest extends TestCase
{
    protected function tearDown(): void { Fixture::cleanup(); }
    public function testSlowEncryptedPacksRemainLiveAndMissingPulseExpires(): void
    {
        $root=dirname(__DIR__); $tmp=Fixture::temp(); $keys=Fixture::keys(); $age=Fixture::runtime()['age'];
        $clock=$tmp.'/clock'; $wrapper=$tmp.'/age-wrapper';
        file_put_contents($wrapper,"#!/bin/sh\nn=\$(cat ".escapeshellarg($clock).")\necho \$((n+120)) > ".escapeshellarg($clock)."\nexec ".escapeshellarg($age)." \"\$@\"\n"); chmod($wrapper,0700);
        $source=file_get_contents($root.'/src/V4ContentStore.php');
        $mutant=str_replace('$this->pulseHeartbeat();','/* pulse removed */',$source,$count);
        self::assertGreaterThan(5,$count); $mutantPath=$tmp.'/mutant.php'; file_put_contents($mutantPath,$mutant);
        foreach (['-', $mutantPath] as $candidate) {
            file_put_contents($clock,(string)time()); $target=Fixture::temp(); $out=tmpfile(); $err=tmpfile();
            $process=proc_open([PHP_BINARY,'-d','display_errors=1',__DIR__.'/fixtures/content-store-heartbeat.php',$root,$wrapper,implode(',',array_column($keys,'public')),$clock,$candidate,$target], [0=>['file','/dev/null','r'],1=>$out,2=>$err],$pipes);
            self::assertIsResource($process); $exit=proc_close($process); rewind($out); rewind($err); $body=stream_get_contents($out); $error=stream_get_contents($err); fclose($out); fclose($err);
            if ($candidate==='-') { self::assertSame(0,$exit,$error.$body); $result=json_decode($body,true,16,JSON_THROW_ON_ERROR); self::assertSame(8,$result['packs']); self::assertGreaterThan(600,$result['seconds']); }
            else { self::assertNotSame(0,$exit,'Removing pack notifications must cause maintenance expiry'); }
        }
    }
}
