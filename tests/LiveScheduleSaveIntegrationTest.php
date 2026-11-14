<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;

/** Isolated WordPress only. This test does not start a backup or restore. */
final class LiveScheduleSaveIntegrationTest extends TestCase
{
    private ?IsolatedMysql $database = null;
    private ?IsolatedWordPress $wordpress = null;
    protected function tearDown(): void
    {
        if ($this->wordpress !== null) { $this->wordpress->stop(); }
        if ($this->database !== null) { $this->database->stop(); }
        Fixture::cleanup();
    }
    private function wp(string $code): array
    {
        $path = Fixture::temp() . '/schedule-' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($path, '<?php require ' . var_export($this->wordpress->root() . '/wp-load.php', true) . ';' . $code);
        $p = proc_open([PHP_BINARY, $path], [0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
        self::assertIsResource($p); $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0, proc_close($p), $err); unlink($path);
        return json_decode($out, true, 32, JSON_THROW_ON_ERROR);
    }
    public function testScheduleSaveRequiresAdminAndNonceAndPreservesCredentialsAndRejectsBusy(): void
    {
        if (getenv('WRB_RUN_V4_MYSQL_INTEGRATION') !== '1') { self::markTestSkipped('Isolated WordPress integration is not enabled.'); }
        $this->database = IsolatedMysql::start(null, 'DISABLED');
        $this->wordpress = IsolatedWordPress::start($this->database);
        $before = $this->wp('$v=WordPressR2Backup\\LiveSettings::seal(["example"=>"secret"],wp_salt("auth")); update_option("wrb_live_config",$v,false); echo json_encode(["digest"=>hash("sha256",$v)]);');
        $page = $this->wordpress->request('/wp-admin/tools.php?page=wrb-synthetic-backup&wrb_view=settings');
        self::assertSame(200, $page['status']);
        self::assertMatchesRegularExpression('/name="_wrb_live_nonce" value="([^"]+)"/', $page['body']);
        preg_match('/name="_wrb_live_nonce" value="([^"]+)"/', $page['body'], $match);
        $fields=['action'=>'wrb_live','command'=>'schedule','wrb_view'=>'settings','_wrb_live_nonce'=>$match[1],
            'scheduleEnabled'=>'1','scheduleFrequency'=>'weekly','scheduleTime'=>'03:30','scheduleWeekday'=>'1','scheduleMode'=>'online'];
        self::assertSame(403, $this->wordpress->request('/wp-admin/admin-post.php', $fields, 'reader')['status']);
        $bad=$fields; $bad['_wrb_live_nonce']='invalid';
        self::assertSame(403, $this->wordpress->request('/wp-admin/admin-post.php', $bad)['status']);
        self::assertSame(303, $this->wordpress->request('/wp-admin/admin-post.php', $fields)['status']);
        $saved=$this->wp('echo json_encode(["digest"=>hash("sha256",get_option("wrb_live_config")),"schedule"=>get_option("wrb_live_schedule"),"operation"=>get_option("wrb_live_operation",null)]);');
        self::assertSame($before['digest'], $saved['digest']); self::assertTrue($saved['schedule']['enabled']);
        self::assertSame('weekly', $saved['schedule']['frequency']); self::assertSame('03:30', $saved['schedule']['time']);
        self::assertSame(1, $saved['schedule']['weekday']); self::assertSame('online', $saved['schedule']['mode']); self::assertNull($saved['operation']);
        $workspace=$this->wp('echo json_encode(["path"=>WordPressR2Backup\\LiveSettings::workspace()]);')['path'];
        foreach (['schedule.lock','operation.lock'] as $filename) {
            $lock=fopen($workspace . '/' . $filename,'c+b'); self::assertIsResource($lock); self::assertTrue(flock($lock,LOCK_EX|LOCK_NB));
            try {
                $held=$fields; $held['scheduleTime']='05:00'; self::assertSame(303,$this->wordpress->request('/wp-admin/admin-post.php',$held)['status']);
                $read=$this->wp('echo json_encode(["schedule"=>get_option("wrb_live_schedule")]);');
                self::assertSame($saved['schedule'],$read['schedule']);
            } finally { flock($lock,LOCK_UN); fclose($lock); }
        }
        // Switching from weekly must not retain a stale weekday in the hourly recurrence.
        $hourly = $fields; $hourly['scheduleFrequency'] = 'hourly';
        self::assertSame(303, $this->wordpress->request('/wp-admin/admin-post.php', $hourly)['status']);
        $saved=$this->wp('echo json_encode(["digest"=>hash("sha256",get_option("wrb_live_config")),"schedule"=>get_option("wrb_live_schedule")]);');
        self::assertSame('hourly', $saved['schedule']['frequency']);
        self::assertNull($saved['schedule']['weekday']);
        self::assertSame('03:30', $saved['schedule']['time']);
        self::assertSame($before['digest'], $saved['digest']);
        $this->wp('update_option("wrb_live_operation",["status"=>"uploading"],false); echo "{}";');
        $fields['scheduleTime']='04:00'; self::assertSame(303, $this->wordpress->request('/wp-admin/admin-post.php', $fields)['status']);
        $after=$this->wp('echo json_encode(["schedule"=>get_option("wrb_live_schedule"),"digest"=>hash("sha256",get_option("wrb_live_config"))]);');
        self::assertSame($saved['schedule'], $after['schedule']); self::assertSame($before['digest'], $after['digest']);
    }
}
