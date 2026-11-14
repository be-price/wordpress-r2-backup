<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;

final class BackupSchedulerCliTest extends TestCase
{
    /** @dataProvider modes */
    public function testDueTickRecordsUnavailableWithoutStartingBackup(string $mode, bool $failFinalWrite, bool $changeConfig): void
    {
        $directory = sys_get_temp_dir() . '/wrb-schedule-test-' . bin2hex(random_bytes(12));
        self::assertTrue(mkdir($directory, 0700));
        $options = $directory . '/options.json';
        $config = ['enabled' => true, 'frequency' => 'daily', 'time' => gmdate('H:i'), 'weekday' => null,
            'mode' => $mode, 'revision' => str_repeat('a', 64), 'effectiveSince' => gmdate('Y-m-d\TH:i:s\Z', time()-86400)];
        file_put_contents($options, json_encode(['wrb_live_schedule' => $config]));
        copy(__DIR__ . '/fixtures/schedule-cli-wp.php', $directory . '/wp-load.php');
        $env = getenv(); $env['WRB_SCHEDULE_FIXTURE'] = $options; $env['TMPDIR'] = $directory;
        $env['WRB_SCHEDULE_FAIL_FINAL'] = $failFinalWrite ? '1' : '0';
        $env['WRB_SCHEDULE_CHANGE_CONFIG'] = $changeConfig ? '1' : '0';
        $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/schedule.php', $directory], [0=>['file','/dev/null','r'], 1=>['pipe','w'], 2=>['pipe','w']], $pipes, null, $env);
        self::assertIsResource($process);
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        $exit = proc_close($process);
        try {
            self::assertSame($failFinalWrite ? 1 : 0, $exit, $out . $err);
            $state = json_decode((string) file_get_contents($options), true);
            $expectedResult = $failFinalWrite ? 'claimed' : ($changeConfig ? 'config_changed' : 'unavailable');
            self::assertSame($expectedResult, $state['wrb_live_schedule_runner']['status']);
            self::assertSame($expectedResult, $state['wrb_live_schedule_runner']['lastResult']);
            self::assertSame($config['revision'], $state['wrb_live_schedule_runner']['claimRevision']);
            self::assertArrayNotHasKey('wrb_live_operation', $state);
            $expectedConfig = $config; if ($changeConfig) { $expectedConfig['revision'] = str_repeat('b',64); }
            self::assertSame($expectedConfig, $state['wrb_live_schedule']);
            $firstAttempt = $state['wrb_live_schedule_runner']['lastAttempt'];
            $env['WRB_SCHEDULE_FAIL_FINAL'] = '0';
            $workspace = realpath($directory) . '/wrb-live-' . hash('sha256', 'https://fixture.invalid/' . $options . '/' . $state['wrb_live_workspace_token']);
            $held = fopen($workspace . '/schedule.lock', 'c+b'); self::assertIsResource($held); self::assertTrue(flock($held, LOCK_EX | LOCK_NB));
            try {
                $blocked = proc_open([PHP_BINARY, dirname(__DIR__) . '/schedule.php', $directory], [0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, null, $env);
                self::assertIsResource($blocked); stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
                self::assertSame(1, proc_close($blocked));
                self::assertSame($state, json_decode((string) file_get_contents($options), true));
            } finally { flock($held, LOCK_UN); fclose($held); }
            $again = proc_open([PHP_BINARY, dirname(__DIR__) . '/schedule.php', $directory], [0=>['file','/dev/null','r'], 1=>['pipe','w'], 2=>['pipe','w']], $pipes, null, $env);
            self::assertIsResource($again);
            $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
            self::assertSame(0, proc_close($again), $out . $err);
            $againState = json_decode((string) file_get_contents($options), true);
            self::assertSame('not_due', $againState['wrb_live_schedule_runner']['status']);
            self::assertSame($failFinalWrite ? 'interrupted' : $expectedResult, $againState['wrb_live_schedule_runner']['lastResult']);
            self::assertSame($firstAttempt, $againState['wrb_live_schedule_runner']['lastAttempt']);
            self::assertArrayNotHasKey('wrb_live_operation', $againState);
        } finally {
            foreach (glob($directory . '/wrb-live-*/*') ?: [] as $path) { unlink($path); }
            foreach (glob($directory . '/wrb-live-*') ?: [] as $path) { rmdir($path); }
            unlink($options); unlink($directory . '/wp-load.php'); rmdir($directory);
        }
    }

    public function modes(): array { return [['maintenance',false,false], ['online',false,false], ['online',true,false], ['online',false,true]]; }

    public function testMissedSlotIsRecordedWithoutLaunchAndNotDuplicated(): void
    {
        $directory = sys_get_temp_dir() . '/wrb-schedule-test-' . bin2hex(random_bytes(12)); self::assertTrue(mkdir($directory,0700));
        $options=$directory.'/options.json'; $epoch=intdiv(time(),60)*60-300;
        $revision=str_repeat('a',64); $slot=gmdate('Y-m-d\TH:i:s\Z',$epoch);
        $config=['enabled'=>true,'frequency'=>'daily','time'=>gmdate('H:i',$epoch),'weekday'=>null,'mode'=>'online','revision'=>$revision,'effectiveSince'=>gmdate('Y-m-d\TH:i:s\Z',$epoch-86400)];
        $old=['lastSeen'=>gmdate('Y-m-d\TH:i:s\Z',$epoch-60),'observedRevision'=>$revision];
        file_put_contents($options,json_encode(['wrb_live_schedule'=>$config,'wrb_live_schedule_runner'=>$old]));
        copy(__DIR__.'/fixtures/schedule-cli-wp.php',$directory.'/wp-load.php');
        $env=getenv(); $env['WRB_SCHEDULE_FIXTURE']=$options; $env['TMPDIR']=$directory; $env['WRB_SCHEDULE_FAIL_FINAL']='0'; $env['WRB_SCHEDULE_CHANGE_CONFIG']='0';
        try {
            for($i=0;$i<2;++$i) {
                $p=proc_open([PHP_BINARY,dirname(__DIR__).'/schedule.php',$directory],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
                self::assertIsResource($p); $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
                self::assertSame(0,proc_close($p),$out.$err); $state=json_decode((string)file_get_contents($options),true);
                self::assertSame($slot,$state['wrb_live_schedule_runner']['lastMissedSlot']); self::assertSame('not_due',$state['wrb_live_schedule_runner']['status']);
                self::assertSame($revision,$state['wrb_live_schedule_runner']['lastMissedRevision']);
                self::assertArrayNotHasKey('wrb_live_operation',$state); self::assertArrayNotHasKey('lastAttempt',$state['wrb_live_schedule_runner']);
                if($i===0) { $recorded=$state['wrb_live_schedule_runner']['lastMissedRecordedAt']; }
                else { self::assertSame($recorded,$state['wrb_live_schedule_runner']['lastMissedRecordedAt']); }
            }
        } finally {
            foreach(glob($directory.'/wrb-live-*/*')?:[] as $path) { unlink($path); }
            foreach(glob($directory.'/wrb-live-*')?:[] as $path) { rmdir($path); }
            unlink($options); unlink($directory.'/wp-load.php'); rmdir($directory);
        }
    }
}
