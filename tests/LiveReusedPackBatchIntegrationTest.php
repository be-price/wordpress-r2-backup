<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Transfer steps over a run of shared packs, in an isolated WordPress and database with synthetic files. Captures and
 * publishes to an in-memory R2; no restore runs.
 * @group integration
 */
final class LiveReusedPackBatchIntegrationTest extends TestCase
{
    private ?IsolatedMysql $database = null;
    private ?IsolatedWordPress $wordpress = null;
    private ?string $liveWorkspace = null;
    private ?string $persistentIndex = null;

    protected function tearDown(): void
    {
        if ($this->wordpress !== null) { $this->wordpress->stop(); }
        if ($this->liveWorkspace !== null && is_dir($this->liveWorkspace)) { $this->removeTree($this->liveWorkspace); }
        if ($this->persistentIndex !== null && is_dir($this->persistentIndex)) { $this->removeTree($this->persistentIndex); }
        if ($this->database !== null) { $this->database->stop(); }
        Fixture::cleanup();
    }

    public function testSharedPackRunAdvancesWithoutReadsAndPublicationRereadsEveryPack(): void
    {
        if (getenv('WRB_RUN_V4_MYSQL_INTEGRATION') !== '1') { self::markTestSkipped('Explicit isolated v4 MySQL integration authorization is required.'); }
        $this->database = IsolatedMysql::start(null, 'DISABLED');
        $this->wordpress = IsolatedWordPress::start($this->database);
        $keys = Fixture::keys(); $private = Fixture::temp(); mkdir($private . '/objects', 0700);
        $this->persistentIndex = dirname(__DIR__) . '/.integration-index-' . bin2hex(random_bytes(12));
        // About fifteen code packs of 512 members each, unchanged between the two generations.
        for ($number = 0; $number < 7200; ++$number) { Fixture::write($this->wordpress->root(), sprintf('bulk/f%04d.php', $number), '<?php // synthetic shared file ' . $number . "\n"); }
        $script = <<<'SCRIPT'
<?php
require WORDPRESS_ROOT . '/wp-load.php';
require TEST_TRANSPORT;
use WordPressR2Backup\{DirectR2Repository,LiveBackup,LiveSettings};
function check($condition,string $message): void { if(!$condition) throw new RuntimeException($message); }
function rejected(callable $action): string { try { $action(); } catch(RuntimeException $expected) { return $expected->getMessage(); } throw new RuntimeException('Expected rejected operation'); }
function readJob(string $directory): array { return json_decode(file_get_contents($directory.'/job.json'),true,64,JSON_THROW_ON_ERROR); }
function privateWrite(string $file,string $body): void { check(file_put_contents($file,$body)===strlen($body),'Fixture write failed'); chmod($file,0600); }
function drain(LiveBackup $backup,array $state): array { while($state['status']==='uploading') $state=$backup->step(); return $state; }
$settings=LiveSettings::validate(['accountId'=>str_repeat('a',32),'bucket'=>'backup-test','accessKeyId'=>str_repeat('B',32),'secretAccessKey'=>str_repeat('c',64),'recipients'=>RECIPIENTS,'retentionDays'=>30]);
$settings['connectionVerified']=true; $settings['proofs']=[['confirmed'=>true],['confirmed'=>true]];
$settings['policy']['preset']='custom';
$settings['policy']['paths']=['wp-admin'=>'exclude','wp-includes'=>'exclude','wp-content'=>'exclude'];
$settings['policy']['tables']['wp_options']='exclude';
$settings['sharing']=['enabled'=>true,'directory'=>INDEX_DIRECTORY];
LiveSettings::save($settings);
$connection=array_intersect_key($settings,array_flip(['accountId','bucket','accessKeyId','secretAccessKey','unitId']));
$memory=new WordPressR2Backup\Tests\MemoryR2Transport(OBJECT_DIRECTORY); $gets=[]; $failPath=null; $unsigned=0;
$transport=static function(array $request,callable $consume) use($memory,&$gets,&$failPath,&$unsigned): array {
    if(strpos($request['headers']['authorization']??'','AWS4-HMAC-SHA256 Credential=')!==0 || !preg_match('/\A[0-9]{8}T[0-9]{6}Z\z/',$request['headers']['x-amz-date']??'')) ++$unsigned;
    $path=parse_url($request['url'],PHP_URL_PATH);
    if($request['method']==='GET') {
        $gets[$path]=($gets[$path]??0)+1;
        if($path===$failPath) { $failPath=null; return ['status'=>500,'headers'=>[]]; }
    }
    return $memory($request,$consume);
};
$repository=new DirectR2Repository($connection,$transport);
$first=drain(new LiveBackup($settings,$repository),(new LiveBackup($settings,$repository))->begin());
check($first['status']==='complete','The first generation must complete');
$gets=[];
$backup=new LiveBackup($settings,$repository); $state=$backup->begin(); $directory=$backup->workspace().'/job-'.$state['id'];
$objects=readJob($directory)['objects']; $pathOf=static fn(array $entry): string => '/backup-test/wordpress-r2/'.$settings['unitId'].'/'.$entry['object']['key'];
// The longest run of shared packs, as in a real unchanged generation.
$start=0; $length=0;
for($i=0;$i<count($objects);) {
    if($objects[$i]['cipherFile']!==null) { ++$i; continue; }
    for($j=$i;$j<count($objects) && $objects[$j]['cipherFile']===null;++$j);
    if($j-$i>$length) { $start=$i; $length=$j-$i; }
    $i=$j;
}
check($length>=14,'Expected at least fourteen consecutive shared packs, found '.$length);
$end=$start+$length;
while(readJob($directory)['position']<$start) { $backup->step(); }
check(readJob($directory)['position']===$start,'Positions before the run must still move one at a time');
$stepGets=static function() use(&$gets,$objects,$pathOf,$start,$end): array { $counts=[]; for($i=$start;$i<$end;++$i) { $counts[]=$gets[$pathOf($objects[$i])]??0; } return $counts; };
$captured=$stepGets(); check(array_unique($captured)===[1],'Capture must have read every shared pack in full once');
// 1. A refused run (a receipt that differs from the index) saves nothing and reads nothing.
$original=file_get_contents($directory.'/job.json'); $tampered=readJob($directory); $tampered['objects'][$start+1]['object']['sha256']=str_repeat('0',64);
privateWrite($directory.'/job.json',json_encode($tampered,JSON_THROW_ON_ERROR)); $changed=file_get_contents($directory.'/job.json');
$message=rejected(static function() use($backup): void { $backup->step(); });
check($message==='共有パックを照合できません。' && file_get_contents($directory.'/job.json')===$changed,'An index mismatch must stop the run without saving: '.$message);
check($stepGets()===$captured,'A refused run must not read R2');
privateWrite($directory.'/job.json',$original);
// 2. With the job put back, one step checks the whole run against the locked index and moves past all of it, reading nothing.
$backup->step(); check(readJob($directory)['position']===$end,'One step must move past every shared pack');
$transfer=$stepGets();
while(readJob($directory)['position']<count($objects)) { $backup->step(); }
// 3. A failed full read of a shared pack at publication keeps the position at the publication step, writes no completion
// record and stays resumable; resuming publishes.
$failPath=$pathOf($objects[$start+2]); $saved=readJob($directory)['position'];
$message=rejected(static function() use($backup): void { $backup->step(); });
check(readJob($directory)['position']===$saved && $saved===count($objects) && $backup->state()['status']==='uploading','A failed publication must keep the position: '.$message);
check($memory(['method'=>'HEAD','url'=>'https://synthetic/backup-test/wordpress-r2/'.$settings['unitId'].'/commits/'.$state['snapshotId'].'.json','headers'=>[],'body'=>null,'bodyBytes'=>0],static fn(string $c): int => strlen($c))['status']===404,'No completion record may exist');
$backup=new LiveBackup(LiveSettings::load(),$repository);
$final=drain($backup,$backup->state());
check($final['status']==='complete','The generation must publish');
$all=$stepGets();
check($unsigned===0,'Every request must be signed');
echo json_encode(['start'=>$start,'length'=>$length,'captured'=>$captured,'transfer'=>$transfer,'all'=>$all,'workspace'=>$backup->workspace()],JSON_THROW_ON_ERROR);
SCRIPT;
        $values = [$this->wordpress->root(), __DIR__ . '/MemoryR2Transport.php', array_column($keys, 'public'), $private . '/objects', $this->persistentIndex];
        $script = str_replace(['WORDPRESS_ROOT', 'TEST_TRANSPORT', 'RECIPIENTS', 'OBJECT_DIRECTORY', 'INDEX_DIRECTORY'], array_map(static fn ($value): string => var_export($value, true), $values), $script);
        Fixture::write($private, 'batch.php', $script);
        $output = tmpfile(); $error = tmpfile();
        $process = proc_open([PHP_BINARY, $private . '/batch.php'], [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $error], $pipes);
        self::assertIsResource($process); $exit = proc_close($process); rewind($output); rewind($error);
        $body = stream_get_contents($output); $message = stream_get_contents($error); fclose($output); fclose($error);
        self::assertSame(0, $exit, $message . $body);
        $result = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        $this->liveWorkspace = $result['workspace'];
        // Full reads per shared pack: one at capture and none in the transfer steps. The failed publication read the packs
        // before the failed one again (the failed one returned an error); the resumed publication and its completion check
        // read every pack once each.
        $expected = array_fill(0, $result['length'], 1);
        self::assertSame($expected, $result['transfer'], 'no full read of a shared pack in the transfer steps');
        $all = [];
        for ($offset = 0; $offset < $result['length']; ++$offset) { $all[] = 3 + ($offset <= 2 ? 1 : 0); }
        self::assertSame($all, $result['all'], 'publication reads each pack in full before and after the completion record');
    }

    private function removeTree(string $path): void
    {
        foreach (new \FilesystemIterator($path) as $entry) {
            if ($entry->isDir() && !$entry->isLink()) { $this->removeTree($entry->getPathname()); }
            else { unlink($entry->getPathname()); }
        }
        rmdir($path);
    }
}
