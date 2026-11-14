<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;

/** Isolated WordPress authorization and read-only status response; no restore or external service. */
final class LiveProgressAjaxIntegrationTest extends TestCase
{
    private ?IsolatedMysql $database = null;
    private ?IsolatedWordPress $wordpress = null;

    protected function tearDown(): void
    {
        if ($this->wordpress !== null) { $this->wordpress->stop(); }
        if ($this->database !== null) { $this->database->stop(); }
        Fixture::cleanup();
    }

    public function testStatusRequiresAuthorityAndDoesNotExposeInternalGuards(): void
    {
        if (getenv('WRB_RUN_V4_MYSQL_INTEGRATION') !== '1') { self::markTestSkipped('Isolated WordPress integration is not enabled.'); }
        $this->database = IsolatedMysql::start(null, 'DISABLED');
        $this->wordpress = IsolatedWordPress::start($this->database);
        $keys = Fixture::keys(); $script = Fixture::temp() . '/setup.php';
        $code = '<?php require ' . var_export($this->wordpress->root() . '/wp-load.php', true) . ';'
            . '$s=WordPressR2Backup\\LiveSettings::validate(' . var_export(['accountId'=>str_repeat('a',32),'bucket'=>'backup-test','accessKeyId'=>str_repeat('B',32),'secretAccessKey'=>str_repeat('c',64),'recipients'=>array_column($keys, 'public'),'retentionDays'=>30], true) . ');'
            . 'WordPressR2Backup\\LiveSettings::save($s); update_option("wrb_live_operation", ["status"=>"complete","message"=>"done","completed"=>2,"total"=>2,"captureGuard"=>"SECRET_GUARD","launchId"=>"SECRET_LAUNCH","maintenanceOwner"=>["token"=>"SECRET_OWNER"]], false);';
        file_put_contents($script, $code); chmod($script, 0600);
        $process = proc_open([PHP_BINARY, $script], [0=>['file','/dev/null','r'], 1=>['pipe','w'], 2=>['pipe','w']], $pipes);
        self::assertIsResource($process); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $out . $err);
        $page = $this->wordpress->request('/wp-admin/tools.php?page=wrb-synthetic-backup');
        self::assertSame(200, $page['status']);
        self::assertMatchesRegularExpression('/var wrbLive\s*=\s*(\{[^;]+\});/', $page['body']);
        preg_match('/var wrbLive\s*=\s*(\{[^;]+\});/', $page['body'], $match);
        $nonce = json_decode($match[1], true, 16, JSON_THROW_ON_ERROR)['nonce'];
        $fields = ['action'=>'wrb_live','command'=>'state','nonce'=>$nonce];
        self::assertSame(403, $this->wordpress->request('/wp-admin/admin-ajax.php', $fields, 'reader')['status']);
        self::assertSame(403, $this->wordpress->request('/wp-admin/admin-ajax.php', ['action'=>'wrb_live','command'=>'state','nonce'=>'invalid'])['status']);
        $response = $this->wordpress->request('/wp-admin/admin-ajax.php', $fields);
        self::assertSame(200, $response['status']);
        $result = json_decode($response['body'], true, 16, JSON_THROW_ON_ERROR);
        self::assertTrue($result['success']); self::assertSame('complete', $result['data']['status']);
        self::assertStringNotContainsString('SECRET_', $response['body']);
        foreach (['captureGuard','launchId','maintenanceOwner'] as $key) { self::assertArrayNotHasKey($key, $result['data']); }
        $online = ['action'=>'wrb_live','command'=>'start','mode'=>'online','onlineAck'=>'1','nonce'=>$nonce];
        self::assertSame(403, $this->wordpress->request('/wp-admin/admin-ajax.php', $online, 'reader')['status']);
        $invalidNonce = $online; $invalidNonce['nonce'] = 'invalid';
        self::assertSame(403, $this->wordpress->request('/wp-admin/admin-ajax.php', $invalidNonce)['status']);
        $online['onlineAck'] = '0'; $response = $this->wordpress->request('/wp-admin/admin-ajax.php', $online);
        $result = json_decode($response['body'], true, 16, JSON_THROW_ON_ERROR);
        self::assertFalse($result['success']); self::assertStringContainsString('注意点', $result['data']['message']);
    }
}
