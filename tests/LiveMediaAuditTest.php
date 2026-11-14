<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;

final class LiveMediaAuditTest extends TestCase
{
    protected function tearDown(): void { Fixture::cleanup(); }

    public function testBoundedPagesAccumulateAndCompletedRunDoesNotRunAgain(): void
    {
        $result = $this->runScript(<<<'PHP'
$calls = 0;
$audit = new LiveMediaAudit(static function ($db, $root, $cursor, $limit) use (&$calls): array {
    ++$calls; $counts = zeroCounts(); $counts['imagesChecked'] = 100;
    $counts['originalFilePresent'] = 99; $counts['originalFileMissing'] = 1;
    $counts['withSavedAlternative'] = 1; $counts['imagesWithMissingDeclaredFiles'] = 1;
    return ['nextId' => $cursor + 100, 'done' => $calls === 6, 'counts' => $counts];
});
$first = $audit->step('', true); $second = $audit->step($first['runId'], false);
$third = $audit->step($first['runId'], false);
echo json_encode(['first' => $first, 'second' => $second, 'same' => $second === $third, 'calls' => $calls, 'current' => LiveMediaAudit::current()]);
PHP);
        self::assertFalse($result['first']['done']);
        self::assertSame(500, $result['first']['counts']['imagesChecked']);
        self::assertTrue($result['second']['done']);
        self::assertSame(600, $result['second']['counts']['imagesChecked']);
        self::assertSame(6, $result['second']['counts']['withSavedAlternative']);
        self::assertTrue($result['same']); self::assertSame(6, $result['calls']);
        self::assertArrayNotHasKey('cursor', $result['current']);
        self::assertStringNotContainsString('/private-fixture-uploads', json_encode($result));
    }

    public function testRunTokenAndPerUserStatePreventCrossRunAndCrossUserMixing(): void
    {
        $result = $this->runScript(<<<'PHP'
$calls=0; $audit=new LiveMediaAudit(static function($db,$root,$cursor,$limit) use (&$calls): array {
    ++$calls; return ['nextId'=>$cursor+1,'done'=>true,'counts'=>zeroCounts()];
});
$state=$audit->step('',true); $rejected=false;
try {$audit->step(str_repeat('0',32),false);} catch(\RuntimeException $e) {$rejected=true;}
$GLOBALS['user']=2; $other=LiveMediaAudit::current();
$GLOBALS['user']=1; $unchanged=$state===LiveMediaAudit::current();
echo json_encode(['rejected'=>$rejected,'other'=>$other,'unchanged'=>$unchanged,'calls'=>$calls]);
PHP);
        self::assertTrue($result['rejected']); self::assertNull($result['other']);
        self::assertTrue($result['unchanged']); self::assertSame(1, $result['calls']);
    }

    public function testRestartDuringPageCannotBeOverwrittenByOldResult(): void
    {
        $result = $this->runScript(<<<'PHP'
$audit=new LiveMediaAudit(static function($db,$root,$cursor,$limit): array {
    $key='wrb_media_audit_1'; $new=$GLOBALS['cache'][$key];
    $new['runId']=str_repeat('f',32); $GLOBALS['cache'][$key]=$new;
    return ['nextId'=>100,'done'=>true,'counts'=>zeroCounts()];
});
$rejected=false; try {$audit->step('',true);} catch(\RuntimeException $e) {$rejected=true;}
echo json_encode(['rejected'=>$rejected,'current'=>LiveMediaAudit::current()]);
PHP);
        self::assertTrue($result['rejected']);
        self::assertSame(str_repeat('f', 32), $result['current']['runId']);
        self::assertFalse($result['current']['done']);
    }

    public function testBusyBackupRejectsScanBeforeReadingOrSavingAnything(): void
    {
        $result = $this->runScript(<<<'PHP'
$GLOBALS['operation']=['status'=>'capturing']; $calls=0;
$audit=new LiveMediaAudit(static function() use (&$calls): array {++$calls; return [];});
$rejected=false; try {$audit->step('',true);} catch(\RuntimeException $e) {$rejected=true;}
echo json_encode(['rejected'=>$rejected,'calls'=>$calls,'cache'=>$GLOBALS['cache']]);
PHP);
        self::assertTrue($result['rejected']); self::assertSame(0, $result['calls']);
        self::assertSame([], $result['cache']);
    }

    public function testInterruptedScanAndUnreadableMetadataDoNotClaimCompleteCoverage(): void
    {
        $result = $this->runScript(<<<'PHP'
$calls=0; $audit=new LiveMediaAudit(static function($db,$root,$cursor,$limit) use (&$calls): array {
    if(++$calls===3) {throw new \RuntimeException('fixture interruption');}
    $counts=zeroCounts(); $counts['imagesChecked']=1; $counts['incompleteMetadata']=1;
    return ['nextId'=>$cursor+1,'done'=>false,'counts'=>$counts];
});
$failed=false; try {$audit->step('',true);} catch(\RuntimeException $e) {$failed=true;}
$state=LiveMediaAudit::current(); $completed=$state; $completed['done']=true;
echo json_encode(['failed'=>$failed,'state'=>$state,'completedLines'=>LiveMediaAudit::lines($completed)]);
PHP);
        self::assertTrue($result['failed']); self::assertFalse($result['state']['done']);
        self::assertSame(2, $result['state']['counts']['incompleteMetadata']);
        self::assertTrue($result['state']['warning']);
        self::assertStringContainsString('確認中', implode('', $result['state']['lines']));
        self::assertStringContainsString('検査できない', implode('', $result['completedLines']));
    }

    public function testCorruptCachedReportLeavesTheScreenAvailableForRescanning(): void
    {
        $result = $this->runScript(<<<'PHP'
function esc_html($value){return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
$GLOBALS['cache']['wrb_media_audit_1']=['runId'=>['private-fixture-path']];
$method=new \ReflectionMethod(LiveAdminView::class,'mediaAudit');$method->setAccessible(true);
ob_start();$method->invoke(null,false);$html=ob_get_clean();
echo json_encode(['html'=>$html]);
PHP);
        self::assertStringContainsString('未確認として', $result['html']);
        self::assertStringContainsString('data-wrb-media-check', $result['html']);
        self::assertStringContainsString('data-wrb-media-done="0"', $result['html']);
        self::assertStringNotContainsString('private-fixture-path', $result['html']);
    }

    private function runScript(string $body): array
    {
        $root = Fixture::temp();
        $setup = <<<'PHP'
<?php
namespace WordPressR2Backup;
$GLOBALS['cache']=[]; $GLOBALS['user']=1; $GLOBALS['operation']=null; $wpdb=(object)[];
function get_current_user_id(){return $GLOBALS['user'];}
function get_option($name,$default=null){return $GLOBALS['operation']??$default;}
function get_transient($name){return $GLOBALS['cache'][$name]??false;}
function set_transient($name,$value,$expiry){$GLOBALS['cache'][$name]=$value;return true;}
function wp_upload_dir($time=null,$create=true){return ['basedir'=>'/private-fixture-uploads','error'=>false];}
function zeroCounts(){return array_fill_keys(['imagesChecked','originalFilePresent','originalFileMissing','withSavedAlternative','withoutSavedFiles','incompleteMetadata','unsafePaths','declaredFilesChecked','declaredFilesMissing','imagesWithMissingDeclaredFiles','imagesWithAllDeclaredFilesPresent'],0);}
require AUTOLOAD;
PHP;
        $setup = str_replace('AUTOLOAD', var_export(dirname(__DIR__) . '/vendor/autoload.php', true), $setup);
        Fixture::write($root, 'run.php', $setup . "\n" . $body);
        $process = proc_open([PHP_BINARY, $root . '/run.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);
        return json_decode($output, true, 32, JSON_THROW_ON_ERROR);
    }
}
