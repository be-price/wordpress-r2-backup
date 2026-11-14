<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;

/** Structural release checks in isolated WordPress; no backup or restore. */
final class BackupSchedulerReleaseIntegrationTest extends TestCase
{
    private ?IsolatedMysql $database = null;
    private ?IsolatedWordPress $wordpress = null;
    protected function tearDown(): void
    {
        if ($this->wordpress !== null) { $this->wordpress->stop(); }
        if ($this->database !== null) { $this->database->stop(); }
        Fixture::cleanup();
    }
    private function invoke(string $code): array
    {
        $directory=Fixture::temp(); $file=$directory.'/probe.php';
        file_put_contents($file,'<?php require '.var_export($this->wordpress->root().'/wp-load.php',true).';'.$code);
        $p=proc_open([PHP_BINARY,'-d','opcache.enable_cli=0',$file],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        self::assertIsResource($p); $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0,proc_close($p),$err); return json_decode($out,true,32,JSON_THROW_ON_ERROR);
    }
    public function testActiveExactReleaseWithOnlineMethodIsRequired(): void
    {
        if (getenv('WRB_RUN_V4_MYSQL_INTEGRATION') !== '1') { self::markTestSkipped('Isolated WordPress integration is not enabled.'); }
        $this->database=IsolatedMysql::start(null,'DISABLED'); $this->wordpress=IsolatedWordPress::start($this->database);
        $release=$this->wordpress->root().'/wp-content/plugins/wordpress-r2-backup';
        $call='require_once '.var_export($release.'/vendor/autoload.php',true).';'
            .'try { WordPressR2Backup\\BackupScheduler::assertOnlineRelease('.var_export($release,true).'); $ok=true; } catch(Throwable $e) { $ok=false; }'
            .'echo json_encode(["ok"=>$ok]);';
        self::assertTrue($this->invoke($call)['ok']);
        $runner=$release.'/src/LiveRunner.php'; $original=(string)file_get_contents($runner);
        $old='    public function startOnline(): array { return $this->launch(false, \'online\'); }';
        self::assertStringContainsString($old,$original);
        file_put_contents($runner,str_replace($old,'',$original));
        try { self::assertFalse($this->invoke($call)['ok'], 'an older runner without startOnline must not be accepted'); }
        finally { file_put_contents($runner,$original); }
        $wrong='require_once '.var_export($release.'/vendor/autoload.php',true).';'
            .'try { WordPressR2Backup\\BackupScheduler::assertOnlineRelease('.var_export(dirname($release),true).'); $ok=true; } catch(Throwable $e) { $ok=false; }'
            .'echo json_encode(["ok"=>$ok]);';
        self::assertFalse($this->invoke($wrong)['ok']);
        $this->invoke('update_option("active_plugins",[],false); echo "{}";');
        self::assertFalse($this->invoke($call)['ok']);
    }
}
