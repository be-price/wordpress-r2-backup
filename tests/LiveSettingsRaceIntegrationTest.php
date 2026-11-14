<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;

/** Isolated WordPress only: no backup, remote service, or restore. */
final class LiveSettingsRaceIntegrationTest extends TestCase
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

    public function testAdminCannotModifySettingsWhileOperationLockIsHeld(): void
    {
        if (getenv('WRB_RUN_V4_MYSQL_INTEGRATION') !== '1') { self::markTestSkipped('Isolated WordPress integration is not enabled.'); }
        $this->database = IsolatedMysql::start(null, 'DISABLED'); $this->wordpress = IsolatedWordPress::start($this->database);
        $keys=Fixture::keys(); $temp=Fixture::temp(); $root=$this->wordpress->root();
        $config=['accountId'=>str_repeat('a',32),'bucket'=>'backup-test','accessKeyId'=>str_repeat('B',32),'secretAccessKey'=>str_repeat('c',64),'recipients'=>array_column($keys,'public'),'retentionDays'=>30];
        $setup='<?php require '.var_export($root.'/wp-load.php',true).'; $s=WordPressR2Backup\LiveSettings::validate('.var_export($config,true).'); WordPressR2Backup\LiveSettings::save($s); echo WordPressR2Backup\LiveSettings::workspace();';
        Fixture::write($temp, 'setup.php', $setup); $this->workspace=trim($this->command($temp.'/setup.php'));
        self::assertStringStartsWith(realpath(sys_get_temp_dir()).'/wrb-live-', $this->workspace);
        $page=$this->wordpress->request('/wp-admin/tools.php?page=wrb-synthetic-backup&wrb_view=settings');
        self::assertSame(200,$page['status']); self::assertMatchesRegularExpression('/name="_wrb_live_nonce" value="([^"]+)"/',$page['body']);
        preg_match('/name="_wrb_live_nonce" value="([^"]+)"/',$page['body'],$match);
        $lock=fopen($this->workspace.'/operation.lock','c+b'); self::assertIsResource($lock); self::assertTrue(flock($lock,LOCK_EX|LOCK_NB));
        try {
            $this->wordpress->request('/wp-admin/admin-post.php',['action'=>'wrb_live','command'=>'retention','wrb_view'=>'settings','wrb_admin_base'=>'tools.php','_wrb_live_nonce'=>$match[1],'retentionDays'=>'17']);
        } finally { flock($lock,LOCK_UN); fclose($lock); }
        Fixture::write($temp, 'read.php','<?php require '.var_export($root.'/wp-load.php',true).'; echo WordPressR2Backup\LiveSettings::load()["retentionDays"];');
        self::assertSame('30',trim($this->command($temp.'/read.php')),'stale admin POST must not change settings during a backup step');
        Fixture::write($temp, 'fail.php','<?php require '.var_export($root.'/wp-load.php',true).'; update_option("wrb_live_operation",["status"=>"failed"],false);');
        $this->command($temp.'/fail.php');
        $this->wordpress->request('/wp-admin/admin-post.php',['action'=>'wrb_live','command'=>'retention','wrb_view'=>'settings','wrb_admin_base'=>'tools.php','_wrb_live_nonce'=>$match[1],'retentionDays'=>'17']);
        self::assertSame('30',trim($this->command($temp.'/read.php')),'failed capture must be cancelled before settings change');
    }

    public function testUnauthorizedAndInvalidNonceDoNotCreateWorkspace(): void
    {
        if (getenv('WRB_RUN_V4_MYSQL_INTEGRATION') !== '1') { self::markTestSkipped('Isolated WordPress integration is not enabled.'); }
        $this->database = IsolatedMysql::start(null, 'DISABLED'); $this->wordpress = IsolatedWordPress::start($this->database);
        $fields=['action'=>'wrb_live','command'=>'retention','_wrb_live_nonce'=>'invalid','retentionDays'=>'17'];
        self::assertSame(403,$this->wordpress->request('/wp-admin/admin-post.php',$fields,'reader')['status']);
        self::assertSame(403,$this->wordpress->request('/wp-admin/admin-post.php',$fields,'admin')['status']);
        $temp=Fixture::temp(); Fixture::write($temp,'read.php','<?php require '.var_export($this->wordpress->root().'/wp-load.php',true).'; echo get_option("wrb_live_workspace_token",false)===false ? "absent" : "present";');
        self::assertSame('absent',trim($this->command($temp.'/read.php')));
    }

    private function command(string $script): string
    {
        $process=proc_open([PHP_BINARY,$script],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        self::assertIsResource($process); $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0,proc_close($process),$err); return $out;
    }
    private function removeTree(string $path): void
    {
        foreach (new \FilesystemIterator($path) as $entry) { if ($entry->isDir() && !$entry->isLink()) { $this->removeTree($entry->getPathname()); } else { unlink($entry->getPathname()); } }
        rmdir($path);
    }
}
