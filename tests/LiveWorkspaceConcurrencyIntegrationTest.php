<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;

/** Only isolated WordPress and MySQL are used; no backup or restore is started. */
final class LiveWorkspaceConcurrencyIntegrationTest extends TestCase
{
    private ?IsolatedMysql $database = null;
    private ?IsolatedWordPress $wordpress = null;
    protected function tearDown(): void
    {
        if ($this->wordpress !== null) { $this->wordpress->stop(); }
        if ($this->database !== null) { $this->database->stop(); }
        Fixture::cleanup();
    }
    private function invoke(string $file, string $temporary): array
    {
        $process=proc_open([PHP_BINARY,'-d','sys_temp_dir='.$temporary,$file], [0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
        self::assertIsResource($process); return [$process,$pipes];
    }
    private function result(array $child): array
    {
        [$process,$pipes]=$child; $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        return ['exit'=>proc_close($process),'out'=>$out,'err'=>$err];
    }
    public function testExplicitSharedBaseIsIndependentOfTempAndRejectsUnsafePaths(): void
    {
        if (getenv('WRB_RUN_V4_MYSQL_INTEGRATION') !== '1') { self::markTestSkipped('Isolated WordPress integration is not enabled.'); }
        $this->database=IsolatedMysql::start(null,'DISABLED'); $this->wordpress=IsolatedWordPress::start($this->database);
        $root=$this->wordpress->root(); $dir=Fixture::temp(); $base=Fixture::temp(); $tempA=Fixture::temp(); $tempB=Fixture::temp();
        $make=static function(string $target,string $public) use ($dir,$root): string {
            $file=$dir.'/run-'.bin2hex(random_bytes(5)).'.php';
            file_put_contents($file,'<?php define("WRB_LIVE_WORKSPACE_BASE",'.var_export($target,true).'); define("WRB_LIVE_PUBLIC_ROOT",'.var_export($public,true).'); $_SERVER["DOCUMENT_ROOT"]='.var_export($root,true).'; require '.var_export($root.'/wp-load.php',true).'; try { $p=WordPressR2Backup\\LiveSettings::workspace(); $s=stat($p); echo json_encode(["path"=>$p,"dev"=>$s["dev"],"ino"=>$s["ino"]]); } catch(Throwable $e) { echo "REJECTED"; }');
            return $file;
        };
        $legacy=$dir.'/legacy.php'; file_put_contents($legacy,'<?php require '.var_export($root.'/wp-load.php',true).'; echo WordPressR2Backup\\LiveSettings::workspace();');
        $legacyResult=$this->result($this->invoke($legacy,$tempA)); self::assertSame(0,$legacyResult['exit'],$legacyResult['err']);
        $legacyPath=$legacyResult['out']; file_put_contents($legacyPath.'/unfinished-marker','preserve');
        $file=$make($base,$root); $a=$this->result($this->invoke($file,$tempA)); $b=$this->result($this->invoke($file,$tempB));
        self::assertSame(0,$a['exit'],$a['err']); self::assertSame(0,$b['exit'],$b['err']);
        $x=json_decode($a['out'],true); self::assertIsArray($x); self::assertSame($x,json_decode($b['out'],true)); self::assertStringStartsWith($base.'/wrb-live-',$x['path']);
        self::assertNotSame($legacyPath,$x['path']); self::assertSame('preserve',file_get_contents($legacyPath.'/unfinished-marker')); self::assertFileDoesNotExist($x['path'].'/unfinished-marker');
        $unsafe=Fixture::temp(); chmod($unsafe,0755); $link=$dir.'/symlink'; symlink($base,$link);
        foreach ([$base.'/missing',$unsafe,$link,$root] as $bad) {
            $result=$this->result($this->invoke($make($bad,$root),$tempA)); self::assertSame(0,$result['exit'],$result['err']); self::assertSame('REJECTED',$result['out']);
        }
        $result=$this->result($this->invoke($make($base,$base),$tempA)); self::assertSame('REJECTED',$result['out']);
    }
    public function testConcurrentFirstUseSharesWorkspaceAndInvalidTokenIsNotReplaced(): void
    {
        if (getenv('WRB_RUN_V4_MYSQL_INTEGRATION') !== '1') { self::markTestSkipped('Isolated WordPress integration is not enabled.'); }
        $this->database=IsolatedMysql::start(null,'DISABLED'); $this->wordpress=IsolatedWordPress::start($this->database);
        $dir=Fixture::temp(); $root=$this->wordpress->root();
        $setup=$dir.'/setup.php'; file_put_contents($setup,'<?php require '.var_export($root.'/wp-load.php',true).'; delete_option("wrb_live_workspace_token");');
        self::assertSame(0,$this->result($this->invoke($setup,$dir))['exit']);
        $children=[];
        foreach ([1,2] as $id) {
            $script=$dir.'/worker-'.$id.'.php';
            $code='<?php namespace WordPressR2Backup { function mkdir($path,$mode=0777,$recursive=false) {'
              .'if (strpos($path,"wrb-live-")!==false) { $n='. $id .'; $dir='.var_export($dir,true).';'
              .'file_put_contents($dir."/mkdir-".$n,"1"); $limit=microtime(true)+10;'
              .'while(!is_file($dir."/mkdir-".($n===1?2:1))) { if(microtime(true)>$limit) throw new \\RuntimeException("mkdir barrier"); usleep(10000); }}'
              .'return \\mkdir($path,$mode,$recursive); }} namespace { require '.var_export($root.'/wp-load.php',true).';'
              .'$n='. $id .'; $dir='.var_export($dir,true).'; $waited=false;'
              .'add_filter("query",static function($sql) use($n,$dir,&$waited) {'
              .'if (!$waited && preg_match("/INSERT\\s+INTO/i",$sql) && strpos($sql,"wrb_live_workspace_token")!==false) {'
              .'$waited=true; file_put_contents($dir."/arrived-".$n,"1"); $limit=microtime(true)+10;'
              .'while (!is_file($dir."/arrived-".($n===1?2:1))) { if(microtime(true)>$limit) throw new RuntimeException("barrier"); usleep(10000); }} return $sql; });'
              .'try { echo json_encode(["path"=>WordPressR2Backup\\LiveSettings::workspace()]); } catch(Throwable $e) { fwrite(STDERR,get_class($e)); exit(1); }}';
            file_put_contents($script,$code); $children[]=$this->invoke($script,$dir);
        }
        $a=$this->result($children[0]); $b=$this->result($children[1]);
        self::assertSame(0,$a['exit'],$a['err']); self::assertSame(0,$b['exit'],$b['err']);
        self::assertFileExists($dir.'/arrived-1'); self::assertFileExists($dir.'/arrived-2');
        self::assertFileExists($dir.'/mkdir-1'); self::assertFileExists($dir.'/mkdir-2');
        self::assertSame(json_decode($a['out'],true)['path'],json_decode($b['out'],true)['path']);
        $invalid=$dir.'/invalid.php';
        file_put_contents($invalid,'<?php require '.var_export($root.'/wp-load.php',true).'; update_option("wrb_live_workspace_token","bad",false);'
          .'try { WordPressR2Backup\\LiveSettings::workspace(); $failed=false; } catch(Throwable $e) { $failed=true; }'
          .'echo json_encode(["failed"=>$failed,"value"=>get_option("wrb_live_workspace_token")]);');
        $result=$this->result($this->invoke($invalid,$dir)); self::assertSame(0,$result['exit'],$result['err']);
        self::assertSame(['failed'=>true,'value'=>'bad'],json_decode($result['out'],true));
    }
}
