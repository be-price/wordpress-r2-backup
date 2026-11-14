<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\{AgeProcess, DirectR2Repository, Restorer};

/** @group integration */
final class LiveV4LifecycleIntegrationTest extends TestCase
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

    public function testPersistedCaptureResumesAcrossFailuresAndCancellationAndKeepsV3JobsReadable(): void
    {
        if (getenv('WRB_RUN_V4_MYSQL_INTEGRATION') !== '1') { self::markTestSkipped('Explicit isolated v4 MySQL integration authorization is required.'); }
        $this->database = IsolatedMysql::start(null, 'DISABLED');
        $this->wordpress = IsolatedWordPress::start($this->database);
        $keys = Fixture::keys(); $private = Fixture::temp(); mkdir($private . '/objects', 0700);
        Fixture::write($this->wordpress->root(), 'proof.txt', 'captured before transport failure');
        Fixture::write($this->wordpress->root(), '.env', 'SYNTHETIC_SECRET=full-backup-value');
        $script = <<<'SCRIPT'
<?php
require WORDPRESS_ROOT . '/wp-load.php';
require TEST_TRANSPORT;
use WordPressR2Backup\{AgeBinary,AgeProcess,DirectR2Repository,LiveBackup,LiveSettings};
function check($condition,string $message): void { if(!$condition) throw new RuntimeException($message); }
function rejected(callable $action): void { try { $action(); } catch(RuntimeException $expected) { return; } throw new RuntimeException('Expected rejected operation'); }
function readJob(string $directory): array { return json_decode(file_get_contents($directory.'/job.json'),true,64,JSON_THROW_ON_ERROR); }
function privateWrite(string $file,string $body): void { check(file_put_contents($file,$body)===strlen($body),'Fixture write failed'); chmod($file,0600); }
$settings=LiveSettings::validate(['accountId'=>str_repeat('a',32),'bucket'=>'backup-test','accessKeyId'=>str_repeat('B',32),'secretAccessKey'=>str_repeat('c',64),'recipients'=>RECIPIENTS,'retentionDays'=>30]);
check($settings['policy']['preset']==='full','New settings must default to complete capture');
$settings['connectionVerified']=true; $settings['proofs']=[['confirmed'=>true],['confirmed'=>true]];
$settings['policy']['preset']='custom';
$settings['policy']['paths']=['wp-admin'=>'exclude','wp-includes'=>'exclude','wp-content'=>'exclude'];
LiveSettings::save($settings);
$connection=array_intersect_key($settings,array_flip(['accountId','bucket','accessKeyId','secretAccessKey','unitId']));
$memory=new WordPressR2Backup\Tests\MemoryR2Transport(OBJECT_DIRECTORY);
$fault='object'; $puts=[]; $now=1800000000;
$transport=static function(array $request,callable $consume) use($memory,&$fault,&$puts): array {
    $path=parse_url($request['url'],PHP_URL_PATH);
    if($request['method']==='PUT') {
        $puts[]=['path'=>$path,'sha256'=>$request['headers']['x-amz-content-sha256']];
        if($fault==='object' && substr($path,-5)!=='.json') { $fault=''; throw new RuntimeException('Injected transport interruption before PUT'); }
    }
    $response=$memory($request,$consume);
    if($request['method']==='PUT' && $fault==='commit' && strpos($path,'/commits/')!==false) { $fault=''; throw new RuntimeException('Injected lost commit response after successful PUT'); }
    return $response;
};
$repository=new DirectR2Repository($connection,$transport,static function() use(&$now): int { return $now; });
$backup=new LiveBackup($settings,$repository); $state=$backup->begin();
$workspace=$backup->workspace(); $directory=$workspace.'/job-'.$state['id']; $captured=readJob($directory);
check($captured['version']===4 && $captured['position']===0,'New capture must persist a v4 job');
$first=$captured['objects'][0]; $cipherHash=hash_file('sha256',$first['cipherFile']);
rejected(static function() use($backup): void { $backup->step(); });
check(readJob($directory)===$captured,'Failed transfer must retain the same saved capture and position');
check(hash_file('sha256',$first['cipherFile'])===$cipherHash,'Failure must not re-encrypt a saved object');
check($backup->state()['status']==='uploading','Transfer failure must remain resumable');
check(!file_exists(ABSPATH.'.maintenance'),'Transfer failure must not leave maintenance enabled');
$changed=$settings; $changed['policy']['paths']['proof.txt']='exclude'; LiveSettings::save($changed);
$changedBackup=new LiveBackup($changed,$repository); $putCount=count($puts);
rejected(static function() use($changedBackup): void { $changedBackup->step(); });
check(count($puts)===$putCount && readJob($directory)===$captured,'Changed settings must reject resume before any upload');
LiveSettings::save($settings);
privateWrite(ABSPATH.'proof.txt','source changed after immutable capture'); unlink(ABSPATH.'.env');
$wpdb->query("UPDATE wp_users SET user_email='changed-after-capture@example.invalid' WHERE user_login='fixture-admin'");
$backup=new LiveBackup(LiveSettings::load(),$repository);
while(readJob($directory)['position']<count($captured['objects'])) { $backup->step(); }
check($puts[0]===$puts[1],'Retry must use exactly the saved object key and ciphertext digest');
$fault='commit'; rejected(static function() use($backup): void { $backup->step(); });
$afterLost=readJob($directory); $rootHash=hash_file('sha256',$directory.'/manifest.age');
$completed=$repository->completed($state['snapshotId']);
check($afterLost['rootReceipt']===$completed['manifest'],'Lost response must retain the exact published root receipt');
check($backup->state()['status']==='uploading','Lost completion response must remain resumable');
$map=json_decode(file_get_contents(OBJECT_DIRECTORY.'/objects.json'),true,32,JSON_THROW_ON_ERROR);
$commitPath='/backup-test/wordpress-r2/'.$settings['unitId'].'/commits/'.$state['snapshotId'].'.json';
check(isset($map[$commitPath]),'Completion must have reached fake R2 before response failure');
$commitBytes=file_get_contents(OBJECT_DIRECTORY.'/'.$map[$commitPath]);
$retryFiles=[]; foreach($captured['objects'] as $entry) { $retryFiles[$entry['cipherFile']]=file_get_contents($entry['cipherFile']); }
$retryFiles[$directory.'/manifest.age']=file_get_contents($directory.'/manifest.age'); $now+=600;
$state=(new LiveBackup($settings,$repository))->step();
check($state['status']==='complete','Retry after lost commit response must complete');
check($repository->completed($state['snapshotId'])===$completed,'Retry must preserve original completion time and references');
check(file_get_contents(OBJECT_DIRECTORY.'/'.$map[$commitPath])===$commitBytes,'Retry must preserve immutable commit bytes');
check($rootHash===$completed['manifest']['sha256'],'Published root must match the saved root ciphertext');
check(!is_dir($directory),'Completion must remove its private job and capture metadata');
$published=$state['snapshotId'];
mkdir($directory,0700);
foreach($retryFiles as $file=>$bytes) { if(!is_dir(dirname($file))) mkdir(dirname($file),0700,true); privateWrite($file,$bytes); }
$crashed=$afterLost; $crashed['position']=count($crashed['objects'])+1; $crashed['state']=$state;
privateWrite($directory.'/job.json',json_encode($crashed,JSON_THROW_ON_ERROR)); update_option('wrb_live_operation',$afterLost['state'],false);
$reconciled=(new LiveBackup($settings,$repository))->step();
check($reconciled['status']==='complete' && $reconciled['snapshotId']===$published,'Saved completion must repair a stale global uploading state');
check($repository->completed($published)===$completed,'Completion reconciliation must preserve original publication and receipt');
check(!is_dir($directory),'Completion reconciliation must remove its private job');
$other=$workspace.'/job-'.str_repeat('f',64); mkdir($other,0700); privateWrite($other.'/keep.age','unrelated private fixture');
$cancelBackup=new LiveBackup($settings,$repository); $cancelState=$cancelBackup->begin(); $cancelDirectory=$workspace.'/job-'.$cancelState['id'];
$beforeCancel=file_get_contents(OBJECT_DIRECTORY.'/objects.json'); $putCount=count($puts); $cancelBackup->cancel();
check($cancelBackup->state()['status']==='cancelled' && !is_dir($cancelDirectory),'Cancellation must remove only its own v4 job');
check(file_get_contents($other.'/keep.age')==='unrelated private fixture','Cancellation must retain another job directory');
check($repository->completed($published)===$completed,'Cancellation must retain a previous published snapshot');
rejected(static function() use($cancelBackup): void { $cancelBackup->step(); });
check(count($puts)===$putCount && file_get_contents(OBJECT_DIRECTORY.'/objects.json')===$beforeCancel,'Cancelled step must never publish or mutate remote objects');
rejected(static function() use($repository,$cancelState): void { $repository->completed($cancelState['snapshotId']); });
$legacy=$settings; $legacy['policy']=['tables'=>['wp_posts'=>'include'],'fields'=>[],'paths'=>[],'scanPublic'=>true]; LiveSettings::save($legacy);
$id=bin2hex(random_bytes(32)); $snapshot=bin2hex(random_bytes(32)); $legacyDirectory=$workspace.'/job-'.$id; mkdir($legacyDirectory,0700);
$age=new AgeProcess(AgeBinary::path()); $fileBytes='pre-v4 saved ciphertext'; $sql="CREATE TABLE wp_legacy_proof(id INT PRIMARY KEY,payload VARBINARY(8));\nINSERT INTO wp_legacy_proof VALUES(7,X'000AFF');\n";
$age->encryptStream([$fileBytes],$legacyDirectory.'/file.age',$legacy['recipients']); $age->encryptStream([$sql],$legacyDirectory.'/database.age',$legacy['recipients']);
$legacyState=['id'=>$id,'snapshotId'=>$snapshot,'status'=>'uploading','completed'=>0,'total'=>3,'startedAt'=>'2026-10-30T00:00:00Z'];
$digest=hash('sha256',json_encode([$legacy['connectionDigest'],$legacy['unitId'],$legacy['recipients'],$legacy['policy']],JSON_THROW_ON_ERROR));
$job=['state'=>$legacyState,'capture'=>['files'=>[['type'=>'file','path'=>'legacy-proof.txt','size'=>strlen($fileBytes),'sha256'=>hash('sha256',$fileBytes),'cipherFile'=>$legacyDirectory.'/file.age']], 'database'=>['size'=>strlen($sql),'sha256'=>hash('sha256',$sql),'cipherFile'=>$legacyDirectory.'/database.age'],'evidence'=>['events'=>[]]],'position'=>0,'receipts'=>[],'settingsDigest'=>$digest];
privateWrite($legacyDirectory.'/job.json',json_encode($job,JSON_THROW_ON_ERROR)); update_option('wrb_live_operation',$legacyState,false);
$oldCipherHash=hash_file('sha256',$legacyDirectory.'/file.age');
$legacyBackup=new LiveBackup(LiveSettings::load(),$repository); $legacyState=$legacyBackup->step(); $resumed=readJob($legacyDirectory);
check(!isset($resumed['version']) && !isset($resumed['objects']) && $resumed['settingsDigest']===$digest,'Saved legacy job must keep the original format and settings digest');
check($legacyState['snapshotId']===$snapshot && hash_file('sha256',$legacyDirectory.'/file.age')===$oldCipherHash,'Legacy resume must preserve snapshot and ciphertext');
while($legacyState['status']==='uploading') { $legacyState=(new LiveBackup(LiveSettings::load(),$repository))->step(); }
check($legacyState['snapshotId']===$snapshot && $legacyState['status']==='complete','Legacy saved job must publish its original snapshot');
check(LiveSettings::load()['policy']===$legacy['policy'],'Legacy resume must not rewrite the saved old settings');
check($repository->completed($snapshot)['objects'][0]['sha256']===$oldCipherHash,'Legacy publication must reuse the saved ciphertext');
check($wpdb->get_var('SELECT @@GLOBAL.event_scheduler')==='DISABLED','Fixture scheduler must remain disabled');
echo json_encode(['connection'=>$connection,'v4'=>$published,'v3'=>$snapshot,'workspace'=>$workspace],JSON_THROW_ON_ERROR);
SCRIPT;
        $script = str_replace(['WORDPRESS_ROOT', 'TEST_TRANSPORT', 'RECIPIENTS', 'OBJECT_DIRECTORY'], array_map(static fn ($value): string => var_export($value, true), [$this->wordpress->root(), __DIR__ . '/MemoryR2Transport.php', array_column($keys, 'public'), $private . '/objects']), $script);
        Fixture::write($private, 'lifecycle.php', $script);
        $result = json_decode($this->command([PHP_BINARY, $private . '/lifecycle.php']), true, 64, JSON_THROW_ON_ERROR);
        $this->liveWorkspace = $result['workspace'];
        $repository = new DirectR2Repository($result['connection'], new MemoryR2Transport($private . '/objects'));
        $age = new AgeProcess(Fixture::runtime()['age']);
        foreach ($keys as $slot => $key) {
            $target = $this->database->newSchema(); $identity = fopen($key['identity'], 'rb');
            try { $restored = (new Restorer($age, $repository))->restore($result['v4'], $identity, $private . '/v4-' . $slot, $this->database->client(), $target); }
            finally { fclose($identity); }
            self::assertTrue($restored['databaseImported']);
            self::assertSame('captured before transport failure', file_get_contents($private . '/v4-' . $slot . '/roots/r0001/proof.txt'));
            self::assertSame('SYNTHETIC_SECRET=full-backup-value', file_get_contents($private . '/v4-' . $slot . '/roots/r0001/.env'));
            self::assertSame([['fixture@example.invalid']], $this->database->rows($target, "SELECT user_email FROM wp_users WHERE user_login='fixture-admin'"));
            $legacyTarget = $this->database->newSchema(); $identity = fopen($key['identity'], 'rb');
            try { $restored = (new Restorer($age, $repository))->restore($result['v3'], $identity, $private . '/v3-' . $slot, $this->database->client(), $legacyTarget); }
            finally { fclose($identity); }
            self::assertTrue($restored['databaseImported']);
            self::assertSame('pre-v4 saved ciphertext', file_get_contents($private . '/v3-' . $slot . '/legacy-proof.txt'));
            self::assertSame([['7', '000AFF']], $this->database->rows($legacyTarget, 'SELECT id,HEX(payload) FROM wp_legacy_proof'));
        }
        $receipt = $repository->completed($result['v3'])['manifest']; $repository->download($receipt, $private . '/v3-manifest.age');
        $identity = fopen($keys[0]['identity'], 'rb');
        try { $age->decrypt($private . '/v3-manifest.age', $private . '/v3-manifest.json', $identity); }
        finally { fclose($identity); }
        self::assertSame(3, json_decode(file_get_contents($private . '/v3-manifest.json'), true, 64, JSON_THROW_ON_ERROR)['version']);
        foreach (glob($private . '/objects/*') as $objectFile) {
            $bytes = file_get_contents($objectFile);
            foreach (['SYNTHETIC_SECRET=full-backup-value', 'captured before transport failure', 'fixture@example.invalid', 'pre-v4 saved ciphertext'] as $plain) { self::assertStringNotContainsString($plain, $bytes); }
        }
    }

    public function testSharingPersistsAcrossJobsProtectsPendingReferencesAndFailsOnIndexLoss(): void
    {
        if (getenv('WRB_RUN_V4_MYSQL_INTEGRATION') !== '1') { self::markTestSkipped('Explicit isolated v4 MySQL integration authorization is required.'); }
        $this->database = IsolatedMysql::start(null, 'DISABLED');
        $this->wordpress = IsolatedWordPress::start($this->database);
        $keys = Fixture::keys(); $private = Fixture::temp(); mkdir($private . '/objects', 0700);
        $this->persistentIndex = dirname(__DIR__) . '/.integration-index-' . bin2hex(random_bytes(12));
        Fixture::write($this->wordpress->root(), 'proof.txt', 'shared across generations');
        Fixture::write($this->wordpress->root(), 'other.txt', 'unchanged independent content');
        $script = <<<'SCRIPT'
<?php
require WORDPRESS_ROOT . '/wp-load.php';
require TEST_TRANSPORT;
use WordPressR2Backup\{DirectR2Repository,LiveBackup,LiveSettings};
function check($condition,string $message): void { if(!$condition) throw new RuntimeException($message); }
function rejected(callable $action): void { try { $action(); } catch(RuntimeException $expected) { return; } throw new RuntimeException('Expected rejected operation'); }
function drain(LiveBackup $backup,array $state): array { while($state['status']==='uploading') $state=$backup->step(); return $state; }
$settings=LiveSettings::validate(['accountId'=>str_repeat('a',32),'bucket'=>'backup-test','accessKeyId'=>str_repeat('B',32),'secretAccessKey'=>str_repeat('c',64),'recipients'=>RECIPIENTS,'retentionDays'=>30]);
$settings['connectionVerified']=true; $settings['proofs']=[['confirmed'=>true],['confirmed'=>true]];
$settings['policy']['preset']='custom';
$settings['policy']['paths']=['wp-admin'=>'exclude','wp-includes'=>'exclude','wp-content'=>'exclude'];
$settings['policy']['tables']['wp_options']='exclude';
$settings['sharing']=['enabled'=>true,'directory'=>INDEX_DIRECTORY];
LiveSettings::save($settings);
$connection=array_intersect_key($settings,array_flip(['accountId','bucket','accessKeyId','secretAccessKey','unitId']));
$memory=new WordPressR2Backup\Tests\MemoryR2Transport(OBJECT_DIRECTORY); $payloadPuts=0; $fault=false;
$transport=static function(array $request,callable $consume) use($memory,&$payloadPuts,&$fault): array {
    $pack=$request['method']==='PUT' && strpos(parse_url($request['url'],PHP_URL_PATH),'/packs/')!==false;
    if($pack) { ++$payloadPuts; }
    $response=$memory($request,$consume);
    if($pack && $fault) { $fault=false; throw new RuntimeException('Injected response loss after storing pack'); }
    return $response;
};
$repository=new DirectR2Repository($connection,$transport);
$temporary=$settings; $temporary['sharing']['directory']=realpath(sys_get_temp_dir()).'/wrb-forbidden-index';
rejected(static function() use($temporary,$repository): void { (new LiveBackup($temporary,$repository))->begin(); });
check(!file_exists($temporary['sharing']['directory']),'Temporary index rejection must precede creation');
(new LiveBackup($settings,$repository))->cancel();
$inside=$settings; $inside['sharing']['directory']=rtrim(ABSPATH,'/').'/.forbidden-index';
rejected(static function() use($inside,$repository): void { (new LiveBackup($inside,$repository))->begin(); });
check(!file_exists($inside['sharing']['directory']),'Source-overlap rejection must precede index creation');
(new LiveBackup($settings,$repository))->cancel();
$backup=new LiveBackup($settings,$repository); $first=$backup->begin(); $workspace=$backup->workspace();
rejected(static function() use($backup): void { $backup->delete(str_repeat('0',64)); });
$jobPath=$workspace.'/job-'.$first['id'].'/job.json'; $saved=file_get_contents($jobPath);
$fault=true; rejected(static function() use($backup): void { $backup->step(); });
check(file_get_contents($jobPath)===$saved,'Lost pack response must preserve the saved job position');
$first=drain($backup,$backup->state());
check($first['status']==='complete' && $first['sharing']['newBytes']>0,'Initial generation must upload payload');
$putsAfterFirst=$payloadPuts;
rename(ABSPATH.'proof.txt',ABSPATH.'renamed.txt');
$secondBackup=new LiveBackup($settings,$repository); $second=drain($secondBackup,$secondBackup->begin());
check($payloadPuts===$putsAfterFirst,'Unchanged content and a rename must not upload payload packs');
check($second['sharing']['newBytes']===0 && $second['sharing']['reusedBytes']>0,'Second generation must report content sharing');
$deletion=$secondBackup->delete($first['snapshotId']);
check(count($deletion['retainedShared'])>0,'Deleting an old generation must retain packs used by the new generation');
$thirdBackup=new LiveBackup($settings,$repository); $pending=$thirdBackup->begin();
$disabled=$settings; $disabled['sharing']['enabled']=false;
rejected(static function() use($disabled,$repository): void { (new LiveBackup($disabled,$repository))->step(); });
check($thirdBackup->state()['status']==='uploading','Changing sharing settings must not advance a pending job');
$thirdBackup->cancel();
$scope=glob(INDEX_DIRECTORY.'/*/scope.json'); check(count($scope)===1,'Expected one scoped index');
rename($scope[0],$scope[0].'.lost');
rejected(static function() use($settings,$repository): void { (new LiveBackup($settings,$repository))->begin(); });
check(!file_exists($scope[0]),'Index loss must not silently create a new index');
(new LiveBackup($settings,$repository))->cancel();
rename($scope[0].'.lost',$scope[0]);
$enabled=drain(new LiveBackup($settings,$repository),(new LiveBackup($settings,$repository))->begin());
check($enabled['status']==='complete','Restoring the original index must allow capture again');
echo json_encode(['workspace'=>$workspace,'snapshot'=>$second['snapshotId'],'connection'=>$connection,'newBytes'=>$second['sharing']['newBytes'],'reusedBytes'=>$second['sharing']['reusedBytes']],JSON_THROW_ON_ERROR);
SCRIPT;
        $script = str_replace(['WORDPRESS_ROOT', 'TEST_TRANSPORT', 'RECIPIENTS', 'OBJECT_DIRECTORY', 'INDEX_DIRECTORY'], array_map(static fn ($value): string => var_export($value, true), [$this->wordpress->root(), __DIR__ . '/MemoryR2Transport.php', array_column($keys, 'public'), $private . '/objects', $this->persistentIndex]), $script);
        Fixture::write($private, 'sharing.php', $script);
        $result = json_decode($this->command([PHP_BINARY, $private . '/sharing.php']), true, 64, JSON_THROW_ON_ERROR);
        $this->liveWorkspace = $result['workspace'];
        self::assertSame(0, $result['newBytes']); self::assertGreaterThan(0, $result['reusedBytes']);
        $repository = new DirectR2Repository($result['connection'], new MemoryR2Transport($private . '/objects'));
        $identity = fopen($keys[0]['identity'], 'rb');
        try { $restored = (new Restorer(new AgeProcess(Fixture::runtime()['age']), $repository))->restore($result['snapshot'], $identity, $private . '/shared-restored'); }
        finally { fclose($identity); }
        self::assertSame('shared across generations', file_get_contents($private . '/shared-restored/roots/r0001/renamed.txt'));
        self::assertSame('unchanged independent content', file_get_contents($private . '/shared-restored/roots/r0001/other.txt'));
        self::assertFileDoesNotExist($private . '/shared-restored/roots/r0001/proof.txt');
    }

    private function command(array $command): string
    {
        $output = tmpfile(); $error = tmpfile();
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $error], $pipes);
        self::assertIsResource($process); $exit = proc_close($process); rewind($output); rewind($error);
        $body = stream_get_contents($output); $message = stream_get_contents($error); fclose($output); fclose($error);
        self::assertSame(0, $exit, $message); return $body;
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
