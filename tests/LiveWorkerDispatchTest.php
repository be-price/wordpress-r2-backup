<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;

/** Exercises the real worker entry with a synthetic WordPress bootstrap; no backup or restore. */
final class LiveWorkerDispatchTest extends TestCase
{
    protected function tearDown(): void { Fixture::cleanup(); }

    public function testAuthenticatedFailureRecordsOnlySafeDiagnosticMetadata(): void
    {
        if (!function_exists('posix_geteuid')) { self::markTestSkipped('POSIX identity is required.'); }
        $root=Fixture::temp(); $workspace=Fixture::temp();
        Fixture::write($root,'wp-load.php', '<?php throw new RuntimeException("private-marker user@example.invalid");');
        $id=str_repeat('d',64); $token=str_repeat('e',64); $ticket=$workspace.'/launch-'.$id.'.json';
        file_put_contents($ticket,json_encode(['version'=>2,'launchId'=>$id,'workspace'=>$workspace,'root'=>$root,'tokenHash'=>hash('sha256',$token),'action'=>'start','mode'=>'online','settingsDigest'=>str_repeat('a',64),'sharingDigest'=>str_repeat('b',64)])); chmod($ticket,0600);
        $env=getenv(); $env['WRB_LAUNCH_TOKEN']=$token;
        $p=proc_open([PHP_BINARY,dirname(__DIR__).'/worker.php',$ticket],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes,null,$env);
        self::assertNotSame(0,proc_close($p));
        $path=$workspace.'/runner-failure-'.$id.'.json'; self::assertSame(0600,fileperms($path)&0777);
        $raw=file_get_contents($path); $record=json_decode($raw,true);
        self::assertSame('RuntimeException',$record['exception']); self::assertSame('wp-load.php:1',$record['where']);
        self::assertStringNotContainsString('private-marker',$raw); self::assertStringNotContainsString('user@example.invalid',$raw);
        self::assertStringNotContainsString($root,$raw); self::assertStringNotContainsString($token,$raw);
    }

    public function testAuthenticatedLegacyLaunchLeavesFailureMarkerWithoutLoadingWordPress(): void
    {
        if (!function_exists('posix_geteuid')) self::markTestSkipped('POSIX identity is required.');
        $root=Fixture::temp();$workspace=Fixture::temp();Fixture::write($root,'wp-load.php','<?php file_put_contents(__DIR__."/loaded","yes");');
        $id=str_repeat('a',64);$token=str_repeat('b',64);$ticket=$workspace.'/launch-'.$id.'.json';$failure=$workspace.'/runner-failure-'.$id.'.json';
        file_put_contents($ticket,json_encode(['version'=>1,'launchId'=>$id,'workspace'=>$workspace,'root'=>$root,'tokenHash'=>hash('sha256',$token)]));chmod($ticket,0600);
        $run=static function(string $secret) use($ticket): int { $env=getenv();$env['WRB_LAUNCH_TOKEN']=$secret;$p=proc_open([PHP_BINARY,dirname(__DIR__).'/worker.php',$ticket],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes,null,$env);return proc_close($p); };
        self::assertNotSame(0,$run(str_repeat('c',64)));self::assertFileDoesNotExist($failure);
        self::assertNotSame(0,$run($token));self::assertFileDoesNotExist($root.'/loaded');self::assertFileExists($failure);self::assertSame(0600,fileperms($failure)&0777);
        $body=file_get_contents($failure);self::assertSame($id,json_decode($body,true)['launchId']);self::assertStringNotContainsString($token,$body);
        self::assertFileExists($ticket);self::assertNotSame(0,$run($token));self::assertSame($body,file_get_contents($failure));
    }

    public function testWorkerLoadsSelectedPluginOnlyAfterHoldingLockAndHidingToken(): void
    {
        if (!function_exists('posix_geteuid')) { self::markTestSkipped('POSIX identity is required.'); }
        $root=Fixture::temp(); $workspace=Fixture::temp();
        $relative='wp-content/plugins/wordpress-r2-backup/releases/test-1/src/LiveRunner.php';
        Fixture::write($root,$relative, <<<'PHP'
<?php
namespace WordPressR2Backup;
class LiveRunner {
    public static function workWithHeldLock(string $ticket,string $token,$lock): void {
        $other=fopen(dirname($ticket).'/runner.lock','c+b'); $free=flock($other,LOCK_EX|LOCK_NB); if($free)flock($other,LOCK_UN); fclose($other);
        file_put_contents(dirname($ticket).'/result',json_encode([!$free,hash_equals(hash('sha256',$token),json_decode(file_get_contents($ticket),true)['tokenHash'])])); unlink($ticket);
    }
}
PHP);
        Fixture::write($root,'wp-load.php', <<<'PHP'
<?php
file_put_contents(__DIR__.'/secret-check',json_encode([getenv('WRB_LAUNCH_TOKEN')===false,!isset($_ENV['WRB_LAUNCH_TOKEN']),!isset($_SERVER['WRB_LAUNCH_TOKEN']),!isset($token)]));
class FakeDatabase { public $options='wp_options'; public function get_var($sql) {return file_get_contents(__DIR__.'/active');} }
$GLOBALS['wpdb']=new FakeDatabase();
require __DIR__.'/wp-content/plugins/wordpress-r2-backup/releases/test-1/src/LiveRunner.php';
PHP);
        file_put_contents($root.'/active',serialize(['wordpress-r2-backup/release-test-1.php']));
        $id=str_repeat('a',64); $token=str_repeat('b',64); $ticket=$workspace.'/launch-'.$id.'.json';
        $data=['version'=>2,'launchId'=>$id,'workspace'=>$workspace,'root'=>$root,'tokenHash'=>hash('sha256',$token),'action'=>'start','mode'=>'online','settingsDigest'=>str_repeat('c',64),'sharingDigest'=>str_repeat('d',64)];
        file_put_contents($ticket,json_encode($data));chmod($ticket,0600);
        $env=getenv();$env['WRB_LAUNCH_TOKEN']=$token;
        $run=static function() use($ticket,$env): int { $p=proc_open([PHP_BINARY,dirname(__DIR__).'/worker.php',$ticket],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes,null,$env); return proc_close($p); };
        self::assertSame(0,$run()); self::assertFileDoesNotExist($ticket);
        self::assertSame([true,true],json_decode(file_get_contents($workspace.'/result'),true));
        self::assertSame([true,true,true,true],json_decode(file_get_contents($root.'/secret-check'),true));
        $data['version']=1;file_put_contents($ticket,json_encode($data));chmod($ticket,0600);unlink($root.'/secret-check');
        self::assertNotSame(0,$run());self::assertFileDoesNotExist($root.'/secret-check');self::assertFileExists($ticket);
        $data['version']=2;file_put_contents($ticket,json_encode($data));unlink($workspace.'/result');
        file_put_contents($root.'/active',serialize(['wordpress-r2-backup/wordpress-r2-backup.php','wordpress-r2-backup/release-test-1.php']));
        self::assertNotSame(0,$run());self::assertFileDoesNotExist($workspace.'/result');self::assertFileExists($ticket);
    }
}
