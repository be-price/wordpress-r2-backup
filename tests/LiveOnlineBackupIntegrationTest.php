<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;

/** Synthetic WordPress, database and object storage; does not restore or access a remote service. */
final class LiveOnlineBackupIntegrationTest extends TestCase
{
    private ?IsolatedMysql $database = null;
    private ?IsolatedWordPress $wordpress = null;
    private ?string $workspace = null;

    protected function tearDown(): void
    {
        if ($this->wordpress !== null) { $this->wordpress->stop(); }
        if ($this->workspace !== null && is_dir($this->workspace)) { $this->removeTree($this->workspace); }
        if ($this->database !== null) { $this->database->stop(); }
        Fixture::cleanup();
    }

    public function testQueuedOnlineCapturePreservesSettingsAndPublishesOnlyWithGuard(): void
    {
        if (getenv('WRB_RUN_V4_MYSQL_INTEGRATION') !== '1') { self::markTestSkipped('Isolated WordPress integration is not enabled.'); }
        $this->database = IsolatedMysql::start(null, 'DISABLED');
        $this->wordpress = IsolatedWordPress::start($this->database);
        $keys = Fixture::keys(); $private = Fixture::temp(); mkdir($private . '/objects', 0700);
        Fixture::write($this->wordpress->root(), 'proof.txt', 'synthetic proof');
        $script = <<<'SCRIPT'
<?php
namespace WordPressR2Backup {
function time(): int {
    if (!empty($GLOBALS['wrbObserveOnline']) && defined('ABSPATH') && is_file(ABSPATH.'.maintenance')) { $GLOBALS['wrbSawMaintenance']=true; }
    return \time();
}
}
namespace {
require ROOT . '/wp-load.php'; require TRANSPORT;
use WordPressR2Backup\{BackupHistory,LiveBackup,LiveSettings,DirectR2Repository,R2TransportException};
function check($condition,string $message): void { if(!$condition) throw new RuntimeException($message); }
$settings=LiveSettings::validate(['accountId'=>str_repeat('a',32),'bucket'=>'backup-test','accessKeyId'=>str_repeat('B',32),'secretAccessKey'=>str_repeat('c',64),'recipients'=>RECIPIENTS,'retentionDays'=>30]);
$settings['connectionVerified']=true; $settings['proofs']=[['confirmed'=>true],['confirmed'=>true]];
$settings['policy']['preset']='custom'; $settings['policy']['paths']=['wp-admin'=>'exclude','wp-includes'=>'exclude','wp-content'=>'exclude'];
LiveSettings::save($settings); $memory=new WordPressR2Backup\Tests\MemoryR2Transport(OBJECT_DIRECTORY); $fault='';
$transport=static function (array $request,callable $consume) use($memory,&$fault): array {
    if ($fault==='connection' && $request['method']==='PUT') { $fault=''; throw new R2TransportException(7); }
    $result=$memory($request,$consume);
    if ($fault==='commit' && $request['method']==='PUT' && strpos($request['url'],'/commits/')!==false) { $fault=''; throw new RuntimeException('Synthetic lost commit response'); }
    return $result;
};
$repo=new DirectR2Repository(array_intersect_key($settings,array_flip(['accountId','bucket','accessKeyId','secretAccessKey','unitId'])),$transport);
$backup=new LiveBackup($settings,$repo);
$id=str_repeat('1',64);$token=str_repeat('2',64);$workspace=$backup->workspace();$ticket=$workspace.'/launch-'.$id.'.json';
$history=new BackupHistory($workspace);
file_put_contents($ticket,json_encode(['version'=>1,'launchId'=>$id,'workspace'=>$workspace,'root'=>realpath(ABSPATH),'tokenHash'=>hash('sha256',$token)]));chmod($ticket,0600);
update_option('wrb_live_operation',['id'=>str_repeat('3',64),'snapshotId'=>str_repeat('4',64),'status'=>'queued','launchId'=>$id,'completed'=>0,'total'=>0],false);
$env=getenv();$env['WRB_LAUNCH_TOKEN']=$token;$process=proc_open([PHP_BINARY,ABSPATH.'wp-content/plugins/wordpress-r2-backup/worker.php',$ticket],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes,null,$env);
check(is_resource($process) && proc_close($process)!==0,'legacy worker must reject');
check(($backup->state()['status']??'')==='failed','legacy worker failure must be visible');$backup->cancel();check(($backup->state()['status']??'')==='cancelled','legacy worker failure must be cancellable');
$legacyId=str_repeat('e',64); update_option('wrb_live_operation',['id'=>str_repeat('f',64),'snapshotId'=>str_repeat('9',64),'status'=>'queued','launchId'=>$legacyId,'completed'=>0,'total'=>0],false);
$rejected=false; try { $backup->begin($legacyId); } catch(RuntimeException $e) { $rejected=true; }
check($rejected,'unbound legacy fresh queue must fail'); check(!file_exists(ABSPATH.'.maintenance'),'unbound queue entered maintenance'); $backup->cancel();
$changed=$settings; $changed['policy']['paths']['proof.txt']='exclude'; LiveSettings::save($changed);
$rejected=false; try { $backup->queue(str_repeat('8',64)); } catch(RuntimeException $e) { $rejected=true; }
check($rejected,'stale caller must not queue after settings changed'); LiveSettings::save($settings);
$maintenanceId=str_repeat('7',64); $maintenance=$backup->queue($maintenanceId);
check(($maintenance['consistencyMode']??null)==='maintenance' && isset($maintenance['settingsDigest'],$maintenance['sharingDigest']),'maintenance queue missing settings binding');
LiveSettings::save($changed); $rejected=false; try { $backup->begin($maintenanceId); } catch(RuntimeException $e) { $rejected=true; }
check($rejected,'maintenance queue must reject changed settings'); check(!file_exists(ABSPATH.'.maintenance'),'changed queue entered maintenance'); LiveSettings::save($settings); $backup->cancel();
$id=str_repeat('a',64); $queued=$backup->queue($id,false,'online');
$changed=$settings; $changed['policy']['paths']['proof.txt']='exclude';
$rejected=false; try { (new LiveBackup($changed,$repo))->begin($id); } catch(RuntimeException $e) { $rejected=true; }
check($rejected,'changed queued privacy settings must fail'); $backup->cancel();
$id=str_repeat('c',64); $backup->queue($id,false,'online'); LiveSettings::save($changed);
$rejected=false; try { $backup->begin($id); } catch(RuntimeException $e) { $rejected=true; }
check($rejected,'stored queued privacy changes must fail');
LiveSettings::save($settings); $backup->cancel();
$wpdb->query('CREATE TABLE '.$wpdb->prefix.'wrb_memory_test (id INT PRIMARY KEY) ENGINE=MEMORY');
$id=str_repeat('6',64); $backup->queue($id,false,'online');
$rejected=false; try { $backup->begin($id); } catch(RuntimeException $e) { $rejected=true; }
$failed=$backup->state();
check($rejected && ($failed['status']??'')==='failed','non-InnoDB capture must fail');
check(($failed['message']??'')==='保守なしでは、対象データベースにInnoDB以外のテーブルがあるため実行できません。','non-InnoDB message must be fixed and safe');
$wpdb->query('DROP TABLE '.$wpdb->prefix.'wrb_memory_test'); $backup->cancel();
$failure=$history->find($failed['id']);
check($failure['status']==='failed' && in_array('capture_unsupported_engine',array_column($failure['events'],'code'),true),'Failed run must keep its safe diagnosis after cleanup');
$id=str_repeat('b',64); $queued=$backup->queue($id,false,'online');
$GLOBALS['wrbObserveOnline']=true; $GLOBALS['wrbSawMaintenance']=false;
$state=$backup->begin($id); $GLOBALS['wrbObserveOnline']=false;
$workspace=$backup->workspace(); $dir=$workspace.'/job-'.$state['id']; $job=json_decode(file_get_contents($dir.'/job.json'),true,64,JSON_THROW_ON_ERROR);
check(($state['consistencyMode']??null)==='online','state mode missing');
check(($job['manifest']['wordpress']['consistencyMode']??null)==='online','manifest mode missing');
check(!file_exists(ABSPATH.'.maintenance'),'maintenance should be absent');
check(!$GLOBALS['wrbSawMaintenance'],'online progress observed maintenance during capture');
LiveSettings::save($changed); $rejected=false; try { $backup->step(); } catch(RuntimeException $e) { $rejected=true; }
check($rejected,'stored post-capture privacy changes must fail'); check(count(glob(OBJECT_DIRECTORY.'/*')?:[])===0,'changed settings uploaded data'); LiveSettings::save($settings);
$guard=file_get_contents($dir.'/capture-ready'); unlink($dir.'/capture-ready');
$rejected=false; try { $backup->step(); } catch(RuntimeException $e) { $rejected=true; }
check($rejected,'guard missing must fail'); check(count(glob(OBJECT_DIRECTORY.'/*')?:[])===0,'missing guard uploaded data');
file_put_contents($dir.'/capture-ready',$guard); chmod($dir.'/capture-ready',0600);
$runId=$state['id']; $fault='connection'; $rejected=false;
try { $backup->step(); } catch(RuntimeException $e) { $rejected=true; }
$interrupted=$history->find($runId);
check($rejected && $interrupted['status']==='interrupted' && in_array('transfer_connection',array_column($interrupted['events'],'code'),true),'Failed R2 connection must be resumable and have a safe history event');
$resumeId=str_repeat('d',64); $resume=$backup->queue($resumeId,true);
check(($resume['consistencyMode']??null)==='online','resume lost its capture mode');
$ticket=['version'=>2,'action'=>'resume','mode'=>'online','settingsDigest'=>$resume['settingsDigest'],'sharingDigest'=>$resume['sharingDigest']];
$bad=$ticket; $bad['settingsDigest']=str_repeat('f',64); $rejected=false;
try { $backup->runQueued($resumeId,$bad); } catch(RuntimeException $e) { $rejected=true; }
check($rejected,'mismatched resume ticket must fail before transfer');
$fault='commit'; $rejected=false;
try { $backup->runQueued($resumeId,$ticket); } catch(RuntimeException $e) { $rejected=true; }
check($rejected && $history->find($runId)['status']==='interrupted','Lost commit response must not be presented as verified completion');
$resumeId=str_repeat('0',64); $backup->queue($resumeId,true);
$state=$backup->runQueued($resumeId,$ticket);
$saved=$history->find($runId); $events=array_column($saved['events'],'code');
check($saved['status']==='complete' && $saved['snapshotId']===$state['snapshotId'] && $saved['bytes']>0,'Verified R2 completion must use the original run ID and recorded saved bytes');
check(in_array('resumed',$events,true) && in_array('final_verification',$events,true) && end($events)==='complete','History must log resume, final verification and verified completion');
check($saved['settings']['mode']==='online' && $saved['settings']['exclusions']['paths']===3,'Saved history must retain the original capture mode and exclusion counts');
echo json_encode(['status'=>$state['status'],'workspace'=>$workspace,'maintenance'=>file_exists(ABSPATH.'.maintenance')],JSON_THROW_ON_ERROR);
}
SCRIPT;
        $replace = ['ROOT'=>$this->wordpress->root(), 'TRANSPORT'=>__DIR__.'/MemoryR2Transport.php', 'RECIPIENTS'=>array_column($keys,'public'), 'OBJECT_DIRECTORY'=>$private.'/objects'];
        $script = str_replace(array_keys($replace), array_map(static fn($v): string => var_export($v,true), array_values($replace)), $script);
        Fixture::write($private, 'online.php', $script);
        $process = proc_open([PHP_BINARY,$private.'/online.php'], [0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
        self::assertIsResource($process); $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0,proc_close($process),$err . ' ' . $out);
        $result=json_decode($out,true,32,JSON_THROW_ON_ERROR); $this->workspace=$result['workspace'];
        self::assertSame('complete',$result['status']); self::assertFalse($result['maintenance']);
    }

    private function removeTree(string $path): void
    {
        foreach (new \FilesystemIterator($path) as $entry) { if ($entry->isDir() && !$entry->isLink()) { $this->removeTree($entry->getPathname()); } else { unlink($entry->getPathname()); } }
        rmdir($path);
    }
}
