<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;

/** Exercises a live capture in a private WordPress and database; it never performs a restore. */
final class LiveMaintenanceExpiryIntegrationTest extends TestCase
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

    public function testRunningCaptureWithExpiredMaintenanceFailsWithoutTransferableJob(): void
    {
        if (getenv('WRB_RUN_V4_MYSQL_INTEGRATION') !== '1') { self::markTestSkipped('Explicit isolated v4 MySQL integration authorization is required.'); }
        $this->database = IsolatedMysql::start(null, 'DISABLED');
        $this->wordpress = IsolatedWordPress::start($this->database);
        $private = Fixture::temp(); mkdir($private . '/objects', 0700); $keys = Fixture::keys();
        $script = <<<'SCRIPT'
<?php
namespace WordPressR2Backup {
    // The marker starts at real time. Simulate a stall at the callback or either publication boundary.
    function time(): int {
        if ($GLOBALS['wrbPhase'] === 'continuous') { $GLOBALS['wrbClock'] += 2; return $GLOBALS['wrbClock']; }
        static $guardCalls = 0;
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (($frame['function'] ?? '') === 'assertFreshMaintenance') {
                ++$guardCalls;
                if ($GLOBALS['wrbPhase'] === 'interrupt' && $guardCalls === 3) { exit(87); }
                if (($GLOBALS['wrbPhase'] === 'before' && $guardCalls >= 1) || ($GLOBALS['wrbPhase'] === 'after' && $guardCalls >= 2)
                    || (in_array($GLOBALS['wrbPhase'], ['state', 'dbfail'], true) && $guardCalls >= 3)) { return \time() + 601; }
                break;
            }
        }
        $marker = defined('ABSPATH') ? ABSPATH . '.maintenance' : '';
        return \time() + ($GLOBALS['wrbPhase'] === 'callback' && $marker !== '' && is_file($marker) && strpos((string) file_get_contents($marker), '<?php $upgrading = ') === 0 ? 601 : 0);
    }
}
namespace {
    $GLOBALS['wrbPhase']=$argv[1]; $GLOBALS['wrbClock']=\time(); $clockStart=$GLOBALS['wrbClock'];
    if ($GLOBALS['wrbPhase']==='inspect') { define('WP_INSTALLING',true); }
    require WORDPRESS_ROOT . '/wp-load.php'; require_once WP_PLUGIN_DIR . '/wordpress-r2-backup/vendor/autoload.php'; require TEST_TRANSPORT;
    if ($GLOBALS['wrbPhase']==='inspect') {
        $s=\WordPressR2Backup\LiveSettings::load(); $transport=new \WordPressR2Backup\Tests\MemoryR2Transport(OBJECT_DIRECTORY);
        $repo=new \WordPressR2Backup\DirectR2Repository(array_intersect_key($s,array_flip(['accountId','bucket','accessKeyId','secretAccessKey','unitId'])),$transport);
        $backup=new \WordPressR2Backup\LiveBackup($s,$repo); $state=$backup->state(); $stepRejected=false;
        try { $backup->step(); } catch (\RuntimeException $e) { $stepRejected=true; }
        $guard=glob($backup->workspace().'/job-'.$state['id'].'/capture-ready') ?: [];
        $backup->cancel();
        echo json_encode(['status'=>$state['status'],'stepRejected'=>$stepRejected,'guards'=>count($guard),
            'objects'=>count(glob(OBJECT_DIRECTORY.'/*') ?: []),'maintenance'=>file_exists(ABSPATH.'.maintenance')],JSON_THROW_ON_ERROR); exit;
    }
    $s=\WordPressR2Backup\LiveSettings::validate(['accountId'=>str_repeat('a',32),'bucket'=>'backup-test','accessKeyId'=>str_repeat('B',32),'secretAccessKey'=>str_repeat('c',64),'recipients'=>RECIPIENTS,'retentionDays'=>30]);
    $s['connectionVerified']=true; $s['proofs']=[['confirmed'=>true],['confirmed'=>true]];
    $s['policy']['preset']='custom'; $s['policy']['paths']=['wp-admin'=>'exclude','wp-includes'=>'exclude','wp-content'=>'exclude'];
    $s['policy']['tables']['wp_options']='exclude'; \WordPressR2Backup\LiveSettings::save($s);
    if ($GLOBALS['wrbPhase']==='dbfail') {
        // Preserve the already-written uploading state when saving failed, to simulate a DB state-write failure.
        add_filter('pre_update_option_wrb_live_operation', static function($value,$old) { return ($value['status']??'')==='failed' ? $old : $value; },10,2);
    }
    $transport=new \WordPressR2Backup\Tests\MemoryR2Transport(OBJECT_DIRECTORY);
    $repo=new \WordPressR2Backup\DirectR2Repository(array_intersect_key($s,array_flip(['accountId','bucket','accessKeyId','secretAccessKey','unitId'])),$transport);
    $backup=new \WordPressR2Backup\LiveBackup($s,$repo); $failure='';
    try { $backup->begin(); } catch (\RuntimeException $e) { $failure=$e->getMessage(); }
    $state=$backup->state(); $workspace=$backup->workspace();
    if ($GLOBALS['wrbPhase']==='continuous') {
        $captured=$state['status']??null; while(($state['status']??null)==='uploading') { $state=$backup->step(); }
        echo json_encode(['captured'=>$captured,'status'=>$state['status']??null,'simulatedSeconds'=>$GLOBALS['wrbClock']-$clockStart,
            'maintenance'=>file_exists(ABSPATH.'.maintenance')],JSON_THROW_ON_ERROR); exit;
    }
    $jobs=glob($workspace.'/job-*/job.json') ?: []; $objects=glob(OBJECT_DIRECTORY.'/*') ?: [];
    $stepRejected=false; try { $backup->step(); } catch (\RuntimeException $e) { $stepRejected=true; }
    if ($GLOBALS['wrbPhase']==='dbfail') { $backup->cancel(); }
    echo json_encode(['expired'=>strpos($failure,'期限')!==false,'failedSaveError'=>strpos($failure,'実行状態を保存できません')!==false,'status'=>$state['status']??null,'jobs'=>count($jobs),'objects'=>count($objects),
        'stepRejected'=>$stepRejected,'maintenance'=>file_exists(ABSPATH.'.maintenance'),'workspace'=>$workspace],JSON_THROW_ON_ERROR);
}
SCRIPT;
        $replace = ['WORDPRESS_ROOT' => $this->wordpress->root(), 'TEST_TRANSPORT' => __DIR__ . '/MemoryR2Transport.php',
            'RECIPIENTS' => array_column($keys, 'public'), 'OBJECT_DIRECTORY' => $private . '/objects'];
        $script = str_replace(array_keys($replace), array_map(static fn ($value): string => var_export($value, true), array_values($replace)), $script);
        Fixture::write($private, 'expiry.php', $script);
        foreach (['callback', 'before', 'after', 'state', 'dbfail'] as $phase) {
            $out = tmpfile(); $err = tmpfile();
            $process = proc_open([PHP_BINARY, $private . '/expiry.php', $phase], [0=>['file','/dev/null','r'],1=>$out,2=>$err], $pipes);
            self::assertIsResource($process); $exit = proc_close($process); rewind($out); rewind($err);
            $body = stream_get_contents($out); $error = stream_get_contents($err); fclose($out); fclose($err);
            self::assertSame(0, $exit, $phase . ': ' . $error);
            $result = json_decode($body, true, 32, JSON_THROW_ON_ERROR); $this->workspace = $result['workspace'];
            self::assertTrue($phase === 'dbfail' ? $result['failedSaveError'] : $result['expired'], $phase);
            self::assertSame($phase === 'dbfail' ? 'uploading' : 'failed', $result['status'], $phase);
            self::assertSame(['callback'=>0,'before'=>0,'after'=>1,'state'=>2,'dbfail'=>3][$phase], $result['jobs'], $phase); self::assertSame(0, $result['objects'], $phase);
            self::assertTrue($result['stepRejected'], $phase); self::assertFalse($result['maintenance'], $phase);
        }
        $out = tmpfile(); $err = tmpfile();
        $process = proc_open([PHP_BINARY, $private . '/expiry.php', 'interrupt'], [0=>['file','/dev/null','r'],1=>$out,2=>$err], $pipes);
        self::assertIsResource($process); self::assertSame(87, proc_close($process)); fclose($out); fclose($err);
        $out = tmpfile(); $err = tmpfile();
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=1', '-d', 'log_errors=0', $private . '/expiry.php', 'inspect'], [0=>['file','/dev/null','r'],1=>$out,2=>$err], $pipes);
        self::assertIsResource($process); $exit=proc_close($process); rewind($out); rewind($err);
        $body=stream_get_contents($out); $error=stream_get_contents($err); fclose($out); fclose($err); self::assertSame(0, $exit, $error . ' ' . $body);
        $interrupted=json_decode($body,true,32,JSON_THROW_ON_ERROR);
        self::assertSame('uploading', $interrupted['status']); self::assertTrue($interrupted['stepRejected']);
        self::assertSame(0, $interrupted['guards']); self::assertSame(0, $interrupted['objects']); self::assertFalse($interrupted['maintenance']);
        // Slow but active work: synthetic time advances beyond ten minutes while progress keeps renewing the marker.
        for ($i=0; $i<400; $i++) { Fixture::write($this->wordpress->root(), 'heartbeat-' . $i . '.txt', 'synthetic content'); }
        $out=tmpfile(); $err=tmpfile();
        $process=proc_open([PHP_BINARY,$private.'/expiry.php','continuous'],[0=>['file','/dev/null','r'],1=>$out,2=>$err],$pipes);
        self::assertIsResource($process); $exit=proc_close($process); rewind($out); rewind($err);
        $body=stream_get_contents($out); $error=stream_get_contents($err); fclose($out); fclose($err); self::assertSame(0,$exit,$error);
        $continuous=json_decode($body,true,32,JSON_THROW_ON_ERROR);
        self::assertSame('uploading',$continuous['captured']); self::assertSame('complete',$continuous['status']);
        self::assertGreaterThan(600,$continuous['simulatedSeconds']); self::assertFalse($continuous['maintenance']);
    }

    private function removeTree(string $path): void
    {
        foreach (new \FilesystemIterator($path) as $entry) {
            if ($entry->isDir() && !$entry->isLink()) { $this->removeTree($entry->getPathname()); } else { unlink($entry->getPathname()); }
        }
        rmdir($path);
    }
}
