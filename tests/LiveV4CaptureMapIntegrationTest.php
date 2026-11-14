<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Capture-only boundaries of the process-local capture maps: a resumed transfer and its publication use only the saved job
 * and ciphertexts, and a capture killed mid-way leaves maps that are never opened again. No restore is run.
 * @group integration
 */
final class LiveV4CaptureMapIntegrationTest extends TestCase
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

    public function testResumedTransferIgnoresLostCaptureMapsAndAKilledCaptureCannotReopenThem(): void
    {
        if (getenv('WRB_RUN_V4_MYSQL_INTEGRATION') !== '1') { self::markTestSkipped('Explicit isolated v4 MySQL integration authorization is required.'); }
        $this->database = IsolatedMysql::start(null, 'DISABLED');
        $this->wordpress = IsolatedWordPress::start($this->database);
        $keys = Fixture::keys(); $private = Fixture::temp(); mkdir($private . '/objects', 0700);
        $this->persistentIndex = dirname(__DIR__) . '/.integration-index-' . bin2hex(random_bytes(12));
        Fixture::write($this->wordpress->root(), 'proof.txt', 'kept across generations');
        Fixture::write($this->wordpress->root(), 'changed.txt', 'first generation');
        $prelude = <<<'SCRIPT'
<?php
require WORDPRESS_ROOT . '/wp-load.php';
require TEST_TRANSPORT;
use WordPressR2Backup\{DirectR2Repository,LiveBackup,LiveSettings,MetadataMap};
function check($condition,string $message): void { if(!$condition) throw new RuntimeException($message); }
function rejected(callable $action): void { try { $action(); } catch(RuntimeException $expected) { return; } throw new RuntimeException('Expected rejected operation'); }
function drain(LiveBackup $backup,array $state): array { while($state['status']==='uploading') $state=$backup->step(); return $state; }
function repository(): DirectR2Repository { $settings=LiveSettings::load(); return new DirectR2Repository(array_intersect_key($settings,array_flip(['accountId','bucket','accessKeyId','secretAccessKey','unitId'])),new WordPressR2Backup\Tests\MemoryR2Transport(OBJECT_DIRECTORY)); }
function captureMaps(string $job): array { $maps=[]; foreach(['source-map','selected-content-map','content-map','reuse-map','policy-*'] as $name) { $maps=array_merge($maps,glob($job.'/capture-*/'.$name,GLOB_ONLYDIR)?:[]); } return $maps; }
function neverReopened(array $maps): void { check(count($maps)>=4,'Expected the capture maps'); foreach($maps as $map) { rejected(static function() use($map): void { MetadataMap::open($map); }); } }
SCRIPT;
        $main = $prelude . <<<'SCRIPT'
$settings=LiveSettings::validate(['accountId'=>str_repeat('a',32),'bucket'=>'backup-test','accessKeyId'=>str_repeat('B',32),'secretAccessKey'=>str_repeat('c',64),'recipients'=>RECIPIENTS,'retentionDays'=>30]);
$settings['connectionVerified']=true; $settings['proofs']=[['confirmed'=>true],['confirmed'=>true]];
$settings['policy']['preset']='custom';
$settings['policy']['paths']=['wp-admin'=>'exclude','wp-includes'=>'exclude','wp-content'=>'exclude'];
$settings['policy']['tables']['wp_options']='exclude';
$settings['sharing']=['enabled'=>true,'directory'=>INDEX_DIRECTORY];
LiveSettings::save($settings);
$first=drain(new LiveBackup($settings,repository()),(new LiveBackup($settings,repository()))->begin());
check($first['status']==='complete','The first generation must complete');
file_put_contents(ABSPATH.'changed.txt','second generation');
$backup=new LiveBackup($settings,repository()); $state=$backup->begin(); $workspace=$backup->workspace();
$job=$workspace.'/job-'.$state['id']; $saved=json_decode(file_get_contents($job.'/job.json'),true,64,JSON_THROW_ON_ERROR);
check($state['status']==='uploading' && $saved['state']['sharing']['reusedPacks']>0,'The filtered second capture must reuse ready packs');
$maps=captureMaps($job); neverReopened($maps);
// What a power loss can leave of unflushed maps: missing, torn, or emptied files, including the owner.
foreach($maps as $number=>$map) {
    foreach(scandir($map) as $name) { if($name==='.'||$name==='..') continue; $file=$map.'/'.$name; ($number+strlen($name))%2===0 ? unlink($file) : file_put_contents($file,substr((string)file_get_contents($file),0,3)); }
}
neverReopened($maps);
$state=$backup->step(); check($state['status']==='uploading','The first object must transfer');
$resumed=drain(new LiveBackup(LiveSettings::load(),repository()),(new LiveBackup(LiveSettings::load(),repository()))->state());
check($resumed['status']==='complete' && $resumed['snapshotId']===$saved['state']['snapshotId'],'A resumed transfer must publish the captured snapshot');
$completed=repository()->completed($saved['state']['snapshotId']);
check($completed['objects']===array_column($saved['objects'],'object'),'Publication must reference exactly the captured objects');
foreach($completed['objects'] as $receipt) { repository()->verify($receipt); }
repository()->verify($completed['manifest']);
check(!is_dir($job),'Completion must remove the job and its capture maps');
echo json_encode(['workspace'=>$workspace,'published'=>$saved['state']['snapshotId']],JSON_THROW_ON_ERROR);
SCRIPT;
        $killed = $prelude . <<<'SCRIPT'
$settings=LiveSettings::load();
(new LiveBackup($settings,repository()))->begin();
SCRIPT;
        $after = $prelude . <<<'SCRIPT'
$settings=LiveSettings::load(); $backup=new LiveBackup($settings,repository()); $state=$backup->state();
check($state['status']==='capturing','A killed capture must remain marked as capturing');
check(file_exists(ABSPATH.'.maintenance'),'A killed capture leaves its own maintenance marker');
$job=$backup->workspace().'/job-'.$state['id'];
check(!file_exists($job.'/job.json'),'A killed capture must not have saved a transferable job');
neverReopened(captureMaps($job));
rejected(static function() use($backup): void { $backup->step(); });
rejected(static function() use($settings): void { (new LiveBackup($settings,repository()))->begin(); });
rejected(static function() use($state): void { repository()->completed($state['snapshotId']); });
$backup->cancel();
check($backup->state()['status']==='cancelled' && !is_dir($job) && !file_exists(ABSPATH.'.maintenance'),'Cancellation must remove the killed job, its maps, and its maintenance marker');
unlink(ABSPATH.'large.bin');
$next=drain(new LiveBackup($settings,repository()),(new LiveBackup($settings,repository()))->begin());
check($next['status']==='complete','A new capture must succeed after cancellation');
rejected(static function() use($state): void { repository()->completed($state['snapshotId']); });
echo json_encode(['killed'=>$state['snapshotId']],JSON_THROW_ON_ERROR);
SCRIPT;
        $values = [$this->wordpress->root(), __DIR__ . '/MemoryR2Transport.php', array_column($keys, 'public'), $private . '/objects', $this->persistentIndex];
        $replace = static function (string $script) use ($values): string {
            return str_replace(['WORDPRESS_ROOT', 'TEST_TRANSPORT', 'RECIPIENTS', 'OBJECT_DIRECTORY', 'INDEX_DIRECTORY'], array_map(static fn ($value): string => var_export($value, true), $values), $script);
        };
        foreach (['main' => $main, 'killed' => $killed, 'after' => $after] as $name => $script) { Fixture::write($private, $name . '.php', $replace($script)); }
        $result = json_decode($this->command([PHP_BINARY, $private . '/main.php']), true, 64, JSON_THROW_ON_ERROR);
        $this->liveWorkspace = $result['workspace'];

        // A large selected file keeps the capture running long enough to stop it once every capture map exists, at the locked pass.
        $large = fopen($this->wordpress->root() . '/large.bin', 'wb');
        for ($block = 0; $block < 192; $block++) { fwrite($large, random_bytes(1048576)); }
        fclose($large);
        $process = proc_open([PHP_BINARY, $private . '/killed.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        self::assertIsResource($process);
        $deadline = microtime(true) + 60; $seen = false;
        while (microtime(true) < $deadline && proc_get_status($process)['running']) {
            if ((glob($this->liveWorkspace . '/job-*/capture-*/content-map/partitions.json') ?: []) !== []) { $seen = true; break; }
            usleep(2000);
        }
        self::assertTrue($seen, 'The capture ended before its locked pass began.');
        proc_terminate($process, 9); proc_close($process);
        // WordPress honours a maintenance marker for ten minutes; the killed run no longer refreshes it, so age it in place.
        $marker = $this->wordpress->root() . '/.maintenance'; $stream = fopen($marker, 'r+b');
        $aged = preg_replace('/\$upgrading = [0-9]+;/', '$upgrading = ' . (time() - 700) . ';', (string) stream_get_contents($stream), 1, $count);
        self::assertSame(1, $count);
        rewind($stream); fwrite($stream, $aged); ftruncate($stream, strlen($aged)); fclose($stream);
        $body = $this->command([PHP_BINARY, $private . '/after.php']);
        $after = json_decode($body, true, 64);
        self::assertIsArray($after, 'Synthetic fixture output: ' . substr($body, 0, 2000));
        self::assertNotSame($result['published'], $after['killed']);
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
