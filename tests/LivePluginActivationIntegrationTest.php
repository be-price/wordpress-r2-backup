<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;

/** Uses WordPress activation in a disposable database; no backup or restore. */
final class LivePluginActivationIntegrationTest extends TestCase
{
    private ?IsolatedMysql $database=null;
    private ?IsolatedWordPress $wordpress=null;
    protected function tearDown(): void { if($this->wordpress)$this->wordpress->stop(); if($this->database)$this->database->stop(); Fixture::cleanup(); }

    public function testBothActivationOrdersRejectSecondReleaseBeforeLoadingComposer(): void
    {
        if(getenv('WRB_RUN_V4_MYSQL_INTEGRATION')!=='1') self::markTestSkipped('Isolated WordPress integration is not enabled.');
        $this->database=IsolatedMysql::start(null,'DISABLED'); $this->wordpress=IsolatedWordPress::start($this->database);
        $root=$this->wordpress->root();$plugin=$root.'/wp-content/plugins/wordpress-r2-backup';$release=$plugin.'/releases/probe';mkdir($release,0700,true);
        foreach(['src','vendor'] as $name) { $p=proc_open(['/bin/cp','-R',$plugin.'/'.$name,$release.'/'.$name],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['pipe','w']],$pipes); $err=stream_get_contents($pipes[2]);fclose($pipes[2]); self::assertSame(0,proc_close($p),$err); }
        copy($plugin.'/wordpress-r2-backup.php',$release.'/wordpress-r2-backup.php');
        file_put_contents($plugin.'/release-probe.php',"<?php\n/* Plugin Name: Test release */\nrequire __DIR__ . '/releases/probe/wordpress-r2-backup.php';\n");
        $old='wordpress-r2-backup/wordpress-r2-backup.php';$new='wordpress-r2-backup/release-probe.php';
        foreach ([[$old,$new],[$new,$old]] as $pair) {
            $script=Fixture::temp().'/activate.php';
            $code=<<<'PHP'
<?php
require ROOT.'/wp-load.php'; require_once ABSPATH.'wp-admin/includes/plugin.php';
update_option('active_plugins',[]); update_option('wrb_activation_sentinel','unchanged',false);
add_filter('wp_die_handler',static function(){return static function($message){throw new RuntimeException((string)$message);};});
$first=activate_plugin(FIRST); $blocked=false;try { activate_plugin(SECOND); } catch(RuntimeException $error) { $blocked=true; }
$result=['first'=>$first===null,'blocked'=>$blocked,'active'=>get_option('active_plugins'),'sentinel'=>get_option('wrb_activation_sentinel')];
update_option('active_plugins',[]); echo json_encode($result);
PHP;
            file_put_contents($script,str_replace(['ROOT','FIRST','SECOND'],array_map(static fn($v)=>var_export($v,true),[$root,$pair[0],$pair[1]]),$code));
            $p=proc_open([PHP_BINARY,$script],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
            self::assertSame(0,proc_close($p),$err);self::assertSame(['first'=>true,'blocked'=>true,'active'=>[$pair[0]],'sentinel'=>'unchanged'],json_decode($out,true));
        }
    }
}
