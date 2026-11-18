<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\LiveRunner;

final class LiveRunnerTest extends TestCase
{
    protected function tearDown(): void { Fixture::cleanup(); }

    public function testHeldLockRejectsForeignHandleBeforeConsumingTicket(): void
    {
        $workspace=Fixture::temp(); $root=Fixture::temp(); Fixture::write($root,'wp-load.php','<?php');
        $id=str_repeat('a',64); $token=str_repeat('b',64); $ticket=$workspace.'/launch-'.$id.'.json';
        Fixture::write($workspace,basename($ticket),json_encode(['version'=>2,'launchId'=>$id,'workspace'=>$workspace,'root'=>$root,'tokenHash'=>hash('sha256',$token),'action'=>'start','mode'=>'maintenance','settingsDigest'=>str_repeat('c',64),'sharingDigest'=>str_repeat('d',64)])); chmod($ticket,0600);
        $wrong=fopen($workspace.'/wrong.lock','c+b');
        try { LiveRunner::workWithHeldLock($ticket,$token,$wrong); self::fail('foreign handle accepted'); }
        catch (\RuntimeException $error) { self::assertFileExists($ticket); }
        finally { fclose($wrong); }
    }

    public function testQueueIsSavedBeforeLaunchAndContainsNoCredentials(): void
    {
        $root = Fixture::temp(); Fixture::write($root, 'wp-load.php', '<?php');
        $script = <<<'PHP'
<?php
namespace WordPressR2Backup;
function get_option($key,$default=null) { return $GLOBALS['options'][$key]??$default; }
function update_option($key,$value,$autoload=false) { $GLOBALS['options'][$key]=$value; return true; }
function home_url($path='/') { return 'https://fixture.invalid/'.hash('sha256',ABSPATH).$path; }
function wp_salt($scheme='auth') { return 'fixture-auth-salt'; }
require STUB; require AUTOLOAD;
define('ABSPATH', SITE . '/');
$settings=['accountId'=>str_repeat('a',32),'bucket'=>'backup-test','accessKeyId'=>str_repeat('B',32),'secretAccessKey'=>str_repeat('c',64),'unitId'=>str_repeat('d',64),'connectionVerified'=>true,'proofs'=>[['confirmed'=>true],['confirmed'=>true]]];
LiveSettings::save($settings);
$calls=0; $ticket=null;
$runner=new LiveRunner($settings,static function(string $php,string $path,array $environment,string $log) use (&$calls,&$ticket): void {
  $calls++; if(get_option('wrb_live_operation')['status']!=='queued') throw new \RuntimeException('not queued first');
  $ticket=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
  LiveRunner::validateLaunch($path,$environment['WRB_LAUNCH_TOKEN']);
});
try {
  $state=$runner->start(); $blocked=false;
  try { $runner->start(); } catch(\RuntimeException $e) { $blocked=true; }
  $workspace=LiveSettings::workspace();
  $history=(new BackupHistory($workspace))->find($state['id']);
  $firstTicket=$ticket;
  // An unsafe journal file must be left alone and may not prevent legitimate queue/cancel operations.
  chmod($workspace.'/backup-history.json',0644);
  (new LiveBackup($settings))->cancel();
  $withoutHistory=$runner->start();
  echo json_encode(['state'=>$state,'ticket'=>$firstTicket,'calls'=>$calls,'blocked'=>$blocked,'history'=>$history,
    'afterHistoryError'=>$withoutHistory['status'],'unsafeMode'=>fileperms($workspace.'/backup-history.json')&0777]);
} finally {
  $workspace=LiveSettings::workspace();
  foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($workspace,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST) as $entry) { $entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname()); }
  rmdir($workspace);
}
PHP;
        $script = str_replace(['AUTOLOAD', 'SITE', 'STUB'], [var_export(dirname(__DIR__) . '/vendor/autoload.php', true), var_export($root, true), var_export(__DIR__ . '/fixtures/runner-workspace-db.php', true)], $script);
        Fixture::write($root, 'runner-test.php', $script);
        $process = proc_open([PHP_BINARY, $root . '/runner-test.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);
        $result = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('queued', $result['state']['status']);
        self::assertSame(2, $result['calls']); self::assertTrue($result['blocked']);
        self::assertSame('queued', $result['afterHistoryError']);
        self::assertSame(0644, $result['unsafeMode']);
        self::assertSame('queued', $result['history']['status']);
        self::assertSame('manual', $result['history']['source']);
        self::assertSame('backup-test', $result['history']['settings']['bucket']);
        self::assertStringNotContainsString(str_repeat('c', 64), $output);
        self::assertArrayNotHasKey('token', $result['ticket']);
    }

    public function testForgedLaunchIsRejectedBeforeLoadingWordPress(): void
    {
        $workspace = Fixture::temp(); $root = Fixture::temp();
        Fixture::write($root, 'wp-load.php', '<?php file_put_contents(__DIR__."/loaded", "yes");');
        $id = str_repeat('a', 64); $ticket = $workspace . '/launch-' . $id . '.json';
        Fixture::write($workspace, basename($ticket), json_encode(['version' => 1, 'launchId' => $id, 'workspace' => $workspace, 'root' => $root, 'tokenHash' => hash('sha256', str_repeat('b', 64))])); chmod($ticket, 0600);
        try { LiveRunner::validateLaunch($ticket, str_repeat('c', 64)); self::fail('Forged launch accepted'); }
        catch (\RuntimeException $error) { self::assertFileDoesNotExist($root . '/loaded'); }
    }

    public function testV2TicketValidatesItsBoundModeAndSettings(): void
    {
        $workspace=Fixture::temp(); $root=Fixture::temp(); Fixture::write($root,'wp-load.php','<?php');
        $id=str_repeat('a',64); $token=str_repeat('b',64); $path=$workspace.'/launch-'.$id.'.json';
        $ticket=['version'=>2,'launchId'=>$id,'workspace'=>$workspace,'root'=>$root,'tokenHash'=>hash('sha256',$token),
            'action'=>'start','mode'=>'maintenance','settingsDigest'=>str_repeat('c',64),'sharingDigest'=>str_repeat('d',64)];
        Fixture::write($workspace,basename($path),json_encode($ticket,JSON_THROW_ON_ERROR)); chmod($path,0600);
        self::assertSame($ticket,LiveRunner::validateLaunch($path,$token));
        foreach (['mode'=>'invalid','action'=>'unknown','settingsDigest'=>null] as $field=>$value) {
            $bad=$ticket; $bad[$field]=$value; file_put_contents($path,json_encode($bad,JSON_THROW_ON_ERROR));
            $error=null; try { LiveRunner::validateLaunch($path,$token); } catch(\RuntimeException $caught) { $error=$caught; }
            self::assertInstanceOf(\RuntimeException::class,$error);
        }
    }

    public function testOnlineLaunchPinsModeAndSettingsBeforeTheWorkerIsLaunched(): void
    {
        $root = Fixture::temp(); Fixture::write($root, 'wp-load.php', '<?php');
        $script = <<<'PHP'
<?php
namespace WordPressR2Backup;
function get_option($key,$default=null) { return $GLOBALS['options'][$key]??$default; }
function update_option($key,$value,$autoload=false) { $GLOBALS['options'][$key]=$value; return true; }
function home_url($path='/') { return 'https://fixture.invalid/'.hash('sha256',ABSPATH).$path; }
function wp_salt($scheme='auth') { return 'fixture-auth-salt'; }
require STUB; require AUTOLOAD;
define('ABSPATH', SITE . '/');
$settings=['accountId'=>str_repeat('a',32),'bucket'=>'backup-test','accessKeyId'=>str_repeat('B',32),'secretAccessKey'=>str_repeat('c',64),'unitId'=>str_repeat('d',64),'connectionDigest'=>str_repeat('e',64),'recipients'=>['pub1','pub2'],'policy'=>[],'connectionVerified'=>true,'proofs'=>[['confirmed'=>true],['confirmed'=>true]]];
LiveSettings::save($settings);
$runner=new LiveRunner($settings,static function(): void {});
try {
  $state=$runner->startOnline();
  echo json_encode(['mode'=>$state['consistencyMode']??null,'digest'=>isset($state['settingsDigest']),'sharing'=>isset($state['sharingDigest'])]);
} finally {
  $workspace=LiveSettings::workspace();
  foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($workspace,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST) as $entry) { $entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname()); }
  rmdir($workspace);
}
PHP;
        $script = str_replace(['AUTOLOAD', 'SITE', 'STUB'], [var_export(dirname(__DIR__) . '/vendor/autoload.php', true), var_export($root, true), var_export(__DIR__ . '/fixtures/runner-workspace-db.php', true)], $script);
        Fixture::write($root, 'runner-online.php', $script);
        $process = proc_open([PHP_BINARY, $root . '/runner-online.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);
        self::assertSame(['mode'=>'online', 'digest'=>true, 'sharing'=>true], json_decode($output, true, 32, JSON_THROW_ON_ERROR));
    }

    public function testAuthenticatedBootstrapFailureLeavesPrivateFailureEvidence(): void
    {
        $workspace = Fixture::temp(); $root = Fixture::temp();
        Fixture::write($root, 'wp-load.php', '<?php throw new RuntimeException("private bootstrap details");');
        $id = str_repeat('a', 64); $token = str_repeat('b', 64); $ticket = $workspace . '/launch-' . $id . '.json';
        Fixture::write($workspace, basename($ticket), json_encode(['version' => 1, 'launchId' => $id, 'workspace' => $workspace, 'root' => $root, 'tokenHash' => hash('sha256', $token)])); chmod($ticket, 0600);
        try { LiveRunner::work($ticket, $token); self::fail('Bootstrap failure accepted'); }
        catch (\RuntimeException $error) { self::assertFileDoesNotExist($ticket); }
        $failure = $workspace . '/runner-failure-' . $id . '.json';
        self::assertFileExists($failure);
        self::assertSame(0600, fileperms($failure) & 0777);
        self::assertStringNotContainsString('private bootstrap details', file_get_contents($failure));
        self::assertStringNotContainsString($token, file_get_contents($failure));
        self::assertSame($id, json_decode(file_get_contents($failure), true)['launchId']);
    }

    public function testDetachedWorkerReturnsBeforeBootstrapFinishes(): void
    {
        $root = Fixture::temp();
        Fixture::write($root, 'wp-load.php', '<?php sleep(2); file_put_contents(__DIR__."/loaded", "yes"); throw new RuntimeException("fixture stop");');
        $script = <<<'PHP'
<?php
namespace WordPressR2Backup;
function get_option($key,$default=null) { return $GLOBALS['options'][$key]??$default; }
function update_option($key,$value,$autoload=false) { $GLOBALS['options'][$key]=$value; return true; }
function home_url($path='/') { return 'https://fixture.invalid/'.hash('sha256',ABSPATH).$path; }
function wp_salt($scheme='auth') { return 'fixture-auth-salt'; }
require STUB; require AUTOLOAD;
define('ABSPATH', SITE . '/');
$settings=['accountId'=>str_repeat('a',32),'bucket'=>'backup-test','accessKeyId'=>str_repeat('B',32),'secretAccessKey'=>str_repeat('c',64),'unitId'=>str_repeat('d',64),'connectionVerified'=>true,'proofs'=>[['confirmed'=>true],['confirmed'=>true]]];
LiveSettings::save($settings);
$runner=new LiveRunner($settings); $workspace=LiveSettings::workspace();
try {
  $start=microtime(true); $state=$runner->start(); $elapsed=microtime(true)-$start;
  $failure=$workspace.'/runner-failure-'.$state['launchId'].'.json';
  $deadline=microtime(true)+8;
  while(!is_file($failure)&&microtime(true)<$deadline) { usleep(50000); clearstatcache(); }
  $history=(new BackupHistory($workspace))->find($state['id']);
  echo json_encode(['initial'=>$state['status'],'elapsed'=>$elapsed,'loaded'=>is_file(ABSPATH.'loaded'),'final'=>$runner->state()['status'],
    'history'=>$history['status'],'events'=>array_column($history['events'],'code')]);
} finally {
  foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($workspace,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST) as $entry) { $entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname()); }
  rmdir($workspace);
}
PHP;
        $script = str_replace(['AUTOLOAD', 'SITE', 'STUB'], [var_export(dirname(__DIR__) . '/vendor/autoload.php', true), var_export($root, true), var_export(__DIR__ . '/fixtures/runner-workspace-db.php', true)], $script);
        Fixture::write($root, 'detach-test.php', $script);
        $process = proc_open([PHP_BINARY, $root . '/detach-test.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);
        $result = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('queued', $result['initial']); self::assertLessThan(1.5, $result['elapsed']);
        self::assertTrue($result['loaded']); self::assertSame('failed', $result['final']);
        self::assertSame('failed', $result['history']);
        self::assertSame(['queued', 'launch_failed'], $result['events']);
    }

    public function testCancelledQueuedTicketCannotBeginCapture(): void
    {
        $root = Fixture::temp();
        $script = <<<'PHP'
<?php
namespace WordPressR2Backup;
function get_option($key,$default=null) { return $GLOBALS['options'][$key]??$default; }
function update_option($key,$value,$autoload=false) { $GLOBALS['options'][$key]=$value; return true; }
function home_url($path='/') { return 'https://fixture.invalid/'.hash('sha256',ABSPATH).$path; }
function wp_salt($scheme='auth') { return 'fixture-auth-salt'; }
require STUB; require AUTOLOAD;
define('ABSPATH', SITE . '/');
$settings=['accountId'=>str_repeat('a',32),'bucket'=>'backup-test','accessKeyId'=>str_repeat('B',32),'secretAccessKey'=>str_repeat('c',64),'unitId'=>str_repeat('d',64),'connectionVerified'=>true,'proofs'=>[['confirmed'=>true],['confirmed'=>true]],'recipients'=>[],'policy'=>[]];
$GLOBALS['wpdb']->prefix='wp_';
LiveSettings::save($settings);
$backup=new LiveBackup($settings); $workspace=LiveSettings::workspace(); $launchId=str_repeat('a',64);
try {
  $backup->queue($launchId); $backup->cancel(); $blocked=false;
  try { $backup->begin($launchId); } catch(\Throwable $error) { $blocked=true; }
  echo json_encode(['blocked'=>$blocked,'state'=>$backup->state()['status'],'jobs'=>count(glob($workspace.'/job-*')),'maintenance'=>file_exists(ABSPATH.'.maintenance')]);
} finally {
  foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($workspace,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST) as $entry) { $entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname()); }
  rmdir($workspace);
}
PHP;
        $script = str_replace(['AUTOLOAD', 'SITE', 'STUB'], [var_export(dirname(__DIR__) . '/vendor/autoload.php', true), var_export($root, true), var_export(__DIR__ . '/fixtures/runner-workspace-db.php', true)], $script);
        Fixture::write($root, 'cancel-test.php', $script);
        $process = proc_open([PHP_BINARY, $root . '/cancel-test.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);
        $result = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
        self::assertTrue($result['blocked']); self::assertSame('cancelled', $result['state']);
        self::assertSame(0, $result['jobs']); self::assertFalse($result['maintenance']);
    }
}
