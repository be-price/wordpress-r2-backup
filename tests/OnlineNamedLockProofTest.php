<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;
use PHPUnit\Framework\TestCase;
use WordPressR2Backup\PrivacyPolicy;
use WordPressR2Backup\WordPressDatabase;
require_once __DIR__ . '/WordPressDatabaseIntegrationTest.php';
/** Isolated investigation only; no restore and no production changes. */
final class OnlineNamedLockProofTest extends TestCase
{
    public function testOnlyExactCliQueryMonitorDropInIsAccepted(): void
    {
        $original=getenv('WRB_TEST_QM_DROPIN');
        if (!is_string($original) || !is_file($original)) { self::markTestSkipped('The exact Query Monitor fixture is required.'); }
        $root=Fixture::temp(); $plugins=$root.'/plugins'; $target=$plugins.'/query-monitor/wp-content/db.php';
        mkdir(dirname($target),0700,true); copy($original,$target); chmod($target,0644);
        $dropin=$root.'/db.php'; symlink($target,$dropin); $core=$root.'/class-wpdb.php'; file_put_contents($core,'core');
        try {
            $method=new \ReflectionMethod(WordPressDatabase::class,'trustedQueryMonitorDropIn'); $method->setAccessible(true);
            $check=static function(string $sapi,bool $qmTests,bool $cache,string $classFile) use($method,$dropin,$plugins,$core): bool { return $method->invoke(null,$dropin,$plugins,$sapi,$qmTests,$cache,$classFile,$core); };
            self::assertTrue($check('cli',false,false,$core)); self::assertFalse($check('cgi-fcgi',false,false,$core));
            self::assertFalse($check('cli',true,false,$core)); self::assertFalse($check('cli',false,true,$core));
            self::assertFalse($check('cli',false,false,$root.'/other.php'));
            chmod($target,0664); self::assertFalse($check('cli',false,false,$core)); chmod($target,0644);
            $elsewhere=$root.'/elsewhere.php'; copy($target,$elsewhere); unlink($dropin); symlink($elsewhere,$dropin);
            self::assertFalse($check('cli',false,false,$core)); unlink($dropin); symlink($target,$dropin);
            file_put_contents($target,"\nchanged",FILE_APPEND); self::assertFalse($check('cli',false,false,$core));
        } finally { Fixture::cleanup(); }
    }
    /** @dataProvider engines */
    public function testConnectionProofAndMemoryMetadataLock(string $engine): void
    {
        if (getenv('WRB_RUN_V4_MYSQL_INTEGRATION') !== '1' || ($engine === 'mariadb105' && !IsolatedMysql::mariadb105Available())) { self::markTestSkipped(); }
        $server = IsolatedMysql::start(null, $engine === 'mariadb105' ? 'OFF' : 'DISABLED', $engine); $links=[];
        try {
            $schema=$server->newSchema(); $config=parse_ini_file($server->defaultsFile(),true,INI_SCANNER_RAW);
            foreach ([0,1] as $i) { $db=mysqli_init(); self::assertTrue(mysqli_real_connect($db,'localhost','root','',$schema,0,$config['client']['socket'])); $links[]=$db; }
            [$a,$b]=$links; $first='wrb-test-'.bin2hex(random_bytes(12)); $second='wrb-test-'.bin2hex(random_bytes(12));
            $scalar=static function($db,string $sql): string { $res=mysqli_query($db,$sql); if (!$res) { throw new \RuntimeException(mysqli_error($db)); } return (string) mysqli_fetch_row($res)[0]; };
            self::assertSame('1',$scalar($a,"SELECT GET_LOCK('$first',0)"));
            self::assertSame('1',$scalar($b,"SELECT GET_LOCK('$second',0)"));
            self::assertSame($scalar($a,'SELECT CONNECTION_ID()'),$scalar($b,"SELECT IS_USED_LOCK('$first')"));
            self::assertSame($scalar($b,'SELECT CONNECTION_ID()'),$scalar($a,"SELECT IS_USED_LOCK('$second')"));
            self::assertTrue(mysqli_query($a,'CREATE TABLE proof_memory(id INT PRIMARY KEY) ENGINE=MEMORY'));
            self::assertTrue(mysqli_query($a,'INSERT INTO proof_memory VALUES(1)'));
            self::assertTrue(mysqli_query($b,'LOCK TABLES proof_memory READ'));
            self::assertTrue(mysqli_query($a,'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'));
            self::assertTrue(mysqli_query($a,'SET TRANSACTION READ ONLY'));
            self::assertTrue(mysqli_query($a,'START TRANSACTION WITH CONSISTENT SNAPSHOT'));
            self::assertSame('1',$scalar($a,'SELECT COUNT(*) FROM proof_memory'));
            self::assertTrue(mysqli_query($b,'UNLOCK TABLES'));
            self::assertTrue(mysqli_query($b,'INSERT INTO proof_memory VALUES(2)'));
            self::assertSame('2',$scalar($a,'SELECT COUNT(*) FROM proof_memory'));
            self::assertTrue(mysqli_query($b,'SET SESSION lock_wait_timeout=1'));
            try { $alter=@mysqli_query($b,'ALTER TABLE proof_memory ADD extra INT'); }
            catch (\mysqli_sql_exception $error) { $alter=false; }
            self::assertFalse($alter,'The transaction must retain the MEMORY metadata lock.');
            self::assertTrue(mysqli_query($a,'ROLLBACK'));
            self::assertTrue(mysqli_query($b,'ALTER TABLE proof_memory ADD extra INT'));
        } finally { foreach($links as $db){$db->close();} $server->stop(); Fixture::cleanup(); }
    }
    public static function engines(): array { return [['mysql'],['mariadb105']]; }

    /** @dataProvider engines */
    public function testExcludedMemoryCountRemainsFrozenWhileWritesContinue(string $engine): void
    {
        if (getenv('WRB_RUN_V4_MYSQL_INTEGRATION') !== '1' || ($engine === 'mariadb105' && !IsolatedMysql::mariadb105Available())) { self::markTestSkipped(); }
        $server=IsolatedMysql::start(null,$engine === 'mariadb105' ? 'OFF' : 'DISABLED',$engine); $links=[];
        try {
            $schema=$server->newSchema(); $config=parse_ini_file($server->defaultsFile(),true,INI_SCANNER_RAW);
            foreach ([0,1,2] as $i) { $db=mysqli_init(); self::assertTrue(mysqli_real_connect($db,'localhost','root','',$schema,0,$config['client']['socket'])); $links[]=$db; }
            $reader=new V4DatabaseMysqliAdapter($links[0]);
            $reader->executeBatch('CREATE TABLE ordinary(id INT PRIMARY KEY) ENGINE=InnoDB; INSERT INTO ordinary VALUES(1); CREATE TABLE excluded(id INT PRIMARY KEY) ENGINE=MEMORY; INSERT INTO excluded VALUES(9991);');
            $policy=new PrivacyPolicy(['version'=>2,'preset'=>'custom','tables'=>['excluded'=>'exclude']]);
            $source=new WordPressDatabase($reader,$policy,static function() use ($links) { return $links[1]; });
            $source->withConsistentSnapshot(static function(WordPressDatabase $database) use ($links): void {
                self::assertTrue(mysqli_query($links[2],'INSERT INTO excluded VALUES(9992)'));
                self::assertTrue(mysqli_query($links[2],'SET SESSION lock_wait_timeout=1'));
                try { $ddl=@mysqli_query($links[2],'ALTER TABLE excluded ADD extra INT'); } catch (\mysqli_sql_exception $error) { $ddl=false; }
                self::assertFalse($ddl,'DDL must wait for the source snapshot to finish.');
                $table=null; $sql='';
                foreach($database->records() as $record) { if($record['type']==='table' && $record['name']==='excluded') {$table=$record;} elseif($record['type']==='sql') { foreach($record['chunks'] as $chunk) { $sql.=$chunk; } } }
                self::assertIsArray($table); self::assertSame(1,$table['sourceRows']); self::assertSame(1,$table['excludedRows']);
                self::assertStringNotContainsString('9991',$sql); self::assertStringNotContainsString('9992',$sql);
                foreach($database->replayRecords() as $record) { if($record['type']==='sql') { foreach($record['chunks'] as $ignored) {} } }
            });
            self::assertTrue(mysqli_query($links[2],'ALTER TABLE excluded ADD extra INT'));
        } finally { foreach($links as $db) { try { $db->close(); } catch(\Throwable $ignored) {} } $server->stop(); Fixture::cleanup(); }
    }

    /** @dataProvider engines */
    public function testIncludedMemoryOverrideIsRejectedAndFailureReleasesLocks(string $engine): void
    {
        if (getenv('WRB_RUN_V4_MYSQL_INTEGRATION') !== '1' || ($engine === 'mariadb105' && !IsolatedMysql::mariadb105Available())) { self::markTestSkipped(); }
        $server=IsolatedMysql::start(null,$engine === 'mariadb105' ? 'OFF' : 'DISABLED',$engine); $links=[];
        try {
            $schema=$server->newSchema(); $config=parse_ini_file($server->defaultsFile(),true,INI_SCANNER_RAW);
            foreach ([0,1,2] as $i) { $db=mysqli_init(); self::assertTrue(mysqli_real_connect($db,'localhost','root','',$schema,0,$config['client']['socket'])); $links[]=$db; }
            $reader=new V4DatabaseMysqliAdapter($links[0]);
            $reader->executeBatch('CREATE TABLE ordinary(id INT PRIMARY KEY) ENGINE=InnoDB; CREATE TABLE excluded(id INT PRIMARY KEY) ENGINE=MEMORY; INSERT INTO excluded VALUES(1);');
            $policy=new PrivacyPolicy(['version'=>2,'preset'=>'custom','tables'=>['excluded'=>'exclude'],'rows'=>['excluded'=>['id'=>['1'=>'include']]]]);
            $source=new WordPressDatabase($reader,$policy,static function() use ($links) { return $links[1]; });
            try { $source->withConsistentSnapshot(static function(): void { self::fail('Included MEMORY data must not be accepted.'); }); self::fail('Expected rejection'); }
            catch (\WordPressR2Backup\UnsupportedOnlineDatabaseEngine $expected) { self::assertTrue(true); }
            $policy=new PrivacyPolicy(['version'=>2,'preset'=>'custom','tables'=>['excluded'=>'exclude']]);
            $source=new WordPressDatabase($reader,$policy,static function() use ($links) { return $links[1]; });
            try { $source->withConsistentSnapshot(static function(): void { throw new \RuntimeException('synthetic failure'); }); self::fail('Expected failure'); }
            catch (\RuntimeException $expected) { self::assertSame('synthetic failure',$expected->getMessage()); }
            self::assertTrue(mysqli_query($links[2],'SET SESSION lock_wait_timeout=1'));
            self::assertTrue(mysqli_query($links[2],'ALTER TABLE excluded ADD extra INT'));
        } finally { foreach($links as $db) { try { $db->close(); } catch(\Throwable $ignored) {} } $server->stop(); Fixture::cleanup(); }
    }

    public function testDifferentServerIsRejectedBeforeSnapshotAndReleasesLocks(): void
    {
        if (getenv('WRB_RUN_V4_MYSQL_INTEGRATION') !== '1') { self::markTestSkipped(); }
        $first=IsolatedMysql::start(); $second=IsolatedMysql::start(); $links=[];
        try {
            $schema=$first->newSchema();
            foreach ([$first,$second] as $server) {
                $config=parse_ini_file($server->defaultsFile(),true,INI_SCANNER_RAW); $db=mysqli_init();
                self::assertTrue(mysqli_real_connect($db,'localhost','root','',null,0,$config['client']['socket'])); $links[]=$db;
            }
            self::assertTrue(mysqli_query($links[1],'CREATE DATABASE `'.$schema.'`'));
            self::assertTrue(mysqli_select_db($links[0],$schema)); self::assertTrue(mysqli_select_db($links[1],$schema));
            $reader=new V4DatabaseMysqliAdapter($links[0]);
            $reader->executeBatch('CREATE TABLE ordinary(id INT PRIMARY KEY) ENGINE=InnoDB; CREATE TABLE excluded(id INT PRIMARY KEY) ENGINE=MEMORY;');
            $policy=new PrivacyPolicy(['version'=>2,'preset'=>'custom','tables'=>['excluded'=>'exclude']]);
            $source=new WordPressDatabase($reader,$policy,static function() use ($links) { return $links[1]; });
            try { $source->withConsistentSnapshot(static function(): void { self::fail('Different DB servers must never be accepted.'); }); self::fail('Expected rejection'); }
            catch (\RuntimeException $expected) { self::assertStringContainsString('secondary',$expected->getMessage()); }
            self::assertTrue(mysqli_query($links[0],'ALTER TABLE excluded ADD extra INT'));
        } finally { foreach($links as $db) { try { $db->close(); } catch(\Throwable $ignored) {} } $first->stop(); $second->stop(); Fixture::cleanup(); }
    }

    public function testNonAsciiExcludedTableIsRejectedBeforeOpeningAuxiliaryConnection(): void
    {
        if (getenv('WRB_RUN_V4_MYSQL_INTEGRATION') !== '1') { self::markTestSkipped(); }
        $server=IsolatedMysql::start(); $db=null;
        try {
            $schema=$server->newSchema(); $config=parse_ini_file($server->defaultsFile(),true,INI_SCANNER_RAW);
            $db=mysqli_init(); self::assertTrue(mysqli_real_connect($db,'localhost','root','',$schema,0,$config['client']['socket'])); self::assertTrue(mysqli_set_charset($db,'utf8mb4'));
            $reader=new V4DatabaseMysqliAdapter($db);
            $reader->executeBatch('CREATE TABLE ordinary(id INT PRIMARY KEY) ENGINE=InnoDB; CREATE TABLE `mémoire_logs`(id INT PRIMARY KEY) ENGINE=MEMORY;');
            $policy=new PrivacyPolicy(['tables'=>['ordinary'=>'include']]); $opened=false;
            $source=new WordPressDatabase($reader,$policy,static function() use (&$opened) { $opened=true; return null; });
            try { $source->withConsistentSnapshot(static function(): void { self::fail('Unrepresentable MEMORY name must never be accepted.'); }); self::fail('Expected rejection'); }
            catch (\WordPressR2Backup\UnsupportedOnlineDatabaseEngine $expected) { self::assertTrue(true); }
            self::assertFalse($opened);
        } finally { if($db)$db->close(); $server->stop();Fixture::cleanup(); }
    }

    public function testNonAsciiDatabaseIsRejectedBeforeOpeningAuxiliaryConnection(): void
    {
        if (getenv('WRB_RUN_V4_MYSQL_INTEGRATION') !== '1') { self::markTestSkipped(); }
        $server=IsolatedMysql::start(); $db=null;
        try {
            $config=parse_ini_file($server->defaultsFile(),true,INI_SCANNER_RAW);
            $db=mysqli_init(); self::assertTrue(mysqli_real_connect($db,'localhost','root','',null,0,$config['client']['socket'])); self::assertTrue(mysqli_set_charset($db,'utf8mb4'));
            self::assertTrue(mysqli_query($db,'CREATE DATABASE `mémoire`')); self::assertTrue(mysqli_select_db($db,'mémoire'));
            $reader=new V4DatabaseMysqliAdapter($db); $reader->executeBatch('CREATE TABLE ordinary(id INT PRIMARY KEY) ENGINE=InnoDB; CREATE TABLE excluded(id INT PRIMARY KEY) ENGINE=MEMORY;');
            $policy=new PrivacyPolicy(['version'=>2,'preset'=>'custom','tables'=>['excluded'=>'exclude']]); $opened=false;
            $source=new WordPressDatabase($reader,$policy,static function() use (&$opened) { $opened=true; return null; });
            try { $source->withConsistentSnapshot(static function(): void { self::fail('Unrepresentable DB name must never be accepted.'); }); self::fail('Expected rejection'); }
            catch (\WordPressR2Backup\UnsupportedOnlineDatabaseEngine $expected) { self::assertTrue(true); }
            self::assertFalse($opened);
        } finally { if($db)$db->close(); $server->stop();Fixture::cleanup(); }
    }

    /** @dataProvider dropInModes */
    public function testStockWordPressOpensVerifiedSocketConnection(bool $withDropIn): void
    {
        if (getenv('WRB_RUN_V4_MYSQL_INTEGRATION') !== '1') { self::markTestSkipped(); }
        $original=getenv('WRB_TEST_QM_DROPIN'); if($withDropIn && (!is_string($original) || !is_file($original))) { self::markTestSkipped('The exact Query Monitor fixture is required.'); }
        $server=IsolatedMysql::start(); $wp=null;
        try {
            $wp=IsolatedWordPress::start($server); $file=Fixture::temp().'/stock-memory-test.php';
            if ($withDropIn) { $target=$wp->root().'/wp-content/plugins/query-monitor/wp-content/db.php'; mkdir(dirname($target),0700,true); copy($original,$target); chmod($target,0644); symlink($target,$wp->root().'/wp-content/db.php'); }
            $script= <<<'PHP'
<?php
require ROOT . '/wp-load.php';
$wpdb->query('CREATE TABLE '.$wpdb->prefix.'excluded_memory (id INT PRIMARY KEY) ENGINE=MEMORY');
$wpdb->query('INSERT INTO '.$wpdb->prefix.'excluded_memory VALUES(1)');
$policy=new \WordPressR2Backup\PrivacyPolicy(['version'=>2,'preset'=>'custom','tables'=>[$wpdb->prefix.'excluded_memory'=>'exclude']]);
$source=new \WordPressR2Backup\WordPressDatabase($wpdb,$policy);
$source->withConsistentSnapshot(static function($db): void { foreach($db->records() as $record) { if ($record['type']==='sql') { foreach($record['chunks'] as $unused) {} } } });
echo 'ok';
PHP;
            file_put_contents($file,str_replace('ROOT',var_export($wp->root(),true),$script));
            $p=proc_open([PHP_BINARY,$file],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
            self::assertSame(0,proc_close($p),$err); self::assertSame('ok',$out);
        } finally { if($wp)$wp->stop();$server->stop();Fixture::cleanup(); }
    }
    public static function dropInModes(): array { return [[false],[true]]; }

    /** @dataProvider engines */
    public function testMemoryReadLockHasBoundedWait(string $engine): void
    {
        if (getenv('WRB_RUN_V4_MYSQL_INTEGRATION') !== '1' || ($engine === 'mariadb105' && !IsolatedMysql::mariadb105Available())) { self::markTestSkipped(); }
        $server=IsolatedMysql::start(null,$engine === 'mariadb105' ? 'OFF' : 'DISABLED',$engine); $db=null; $process=null;
        try {
            $schema=$server->newSchema(); $config=parse_ini_file($server->defaultsFile(),true,INI_SCANNER_RAW); $socket=$config['client']['socket'];
            $db=mysqli_init(); self::assertTrue(mysqli_real_connect($db,'localhost','root','',$schema,0,$socket));
            self::assertTrue(mysqli_query($db,'CREATE TABLE memory_wait(id INT) ENGINE=MEMORY')); self::assertTrue(mysqli_query($db,'LOCK TABLES memory_wait WRITE'));
            $file=Fixture::temp().'/read-lock-child.php';
            $program='<?php $db=mysqli_init(); mysqli_real_connect($db,"localhost","root","",'.var_export($schema,true).',0,'.var_export($socket,true).'); mysqli_query($db,"SET SESSION lock_wait_timeout=1"); try {$r=@mysqli_query($db,"LOCK TABLES memory_wait READ");} catch (Throwable $e) {$r=false;} echo $r ? "unexpected-lock" : "timed-out";';
            file_put_contents($file,$program); $process=proc_open([PHP_BINARY,$file],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            $deadline=microtime(true)+5; do { $status=proc_get_status($process); if (!$status['running']) { break; } usleep(50000); } while(microtime(true)<$deadline);
            if ($status['running']) { proc_terminate($process,9); }
            $out=stream_get_contents($pipes[1]); fclose($pipes[1]); fclose($pipes[2]); proc_close($process); $process=null;
            self::assertFalse($status['running'],'Table lock wait exceeded the allowed bound.'); self::assertSame('timed-out',$out);
        } finally { if(is_resource($process)) {proc_terminate($process,9);proc_close($process);} if($db)$db->close(); $server->stop();Fixture::cleanup(); }
    }

    /** @dataProvider engines */
    public function testPreflightTimeoutRestoresSessionSetting(string $engine): void
    {
        if (getenv('WRB_RUN_V4_MYSQL_INTEGRATION') !== '1' || ($engine === 'mariadb105' && !IsolatedMysql::mariadb105Available())) { self::markTestSkipped(); }
        $server=IsolatedMysql::start(null,$engine === 'mariadb105' ? 'OFF' : 'DISABLED',$engine); $db=null; $process=null;
        try {
            $schema=$server->newSchema(); $config=parse_ini_file($server->defaultsFile(),true,INI_SCANNER_RAW); $socket=$config['client']['socket'];
            $db=mysqli_init(); self::assertTrue(mysqli_real_connect($db,'localhost','root','',$schema,0,$socket));
            self::assertTrue(mysqli_query($db,'CREATE TABLE memory_wait(id INT PRIMARY KEY) ENGINE=MEMORY')); self::assertTrue(mysqli_query($db,'LOCK TABLES memory_wait WRITE'));
            $file=Fixture::temp().'/preflight-child.php';
            $program='<?php require '.var_export(dirname(__DIR__).'/vendor/autoload.php',true).'; require '.var_export(__DIR__.'/WordPressDatabaseIntegrationTest.php',true).'; $db=mysqli_init(); mysqli_real_connect($db,"localhost","root","",'.var_export($schema,true).',0,'.var_export($socket,true).'); mysqli_query($db,"SET SESSION lock_wait_timeout=23"); $adapter=new \\WordPressR2Backup\\Tests\\V4DatabaseMysqliAdapter($db); $source=new \\WordPressR2Backup\\WordPressDatabase($adapter,new \\WordPressR2Backup\\PrivacyPolicy(["version"=>2,"preset"=>"full"])); try {$source->preflight(); echo "unexpected:";} catch(Throwable $e) {echo "failed:";} echo mysqli_fetch_row(mysqli_query($db,"SELECT @@SESSION.lock_wait_timeout"))[0];';
            file_put_contents($file,$program); $process=proc_open([PHP_BINARY,$file],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            $deadline=microtime(true)+10; do { $status=proc_get_status($process); if (!$status['running']) { break; } usleep(50000); } while(microtime(true)<$deadline);
            if ($status['running']) { proc_terminate($process,9); }
            $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); proc_close($process); $process=null;
            self::assertFalse($status['running'],'Preflight wait exceeded the allowed bound.'); self::assertSame('failed:23',$out,$err);
        } finally { if(is_resource($process)) {proc_terminate($process,9);proc_close($process);} if($db)$db->close(); $server->stop();Fixture::cleanup(); }
    }

    /** @dataProvider engines */
    public function testAuxiliaryFailureAfterReadLockReleasesBothConnections(string $engine): void
    {
        if (getenv('WRB_RUN_V4_MYSQL_INTEGRATION') !== '1' || ($engine === 'mariadb105' && !IsolatedMysql::mariadb105Available())) { self::markTestSkipped(); }
        $server=IsolatedMysql::start(null,$engine === 'mariadb105' ? 'OFF' : 'DISABLED',$engine); $links=[];
        try {
            $schema=$server->newSchema(); $config=parse_ini_file($server->defaultsFile(),true,INI_SCANNER_RAW);
            foreach ([0,1,2] as $i) { $db=mysqli_init(); self::assertTrue(mysqli_real_connect($db,'localhost','root','',$schema,0,$config['client']['socket'])); $links[]=$db; }
            $underlying=new V4DatabaseMysqliAdapter($links[0]); $underlying->executeBatch('CREATE TABLE ordinary(id INT PRIMARY KEY) ENGINE=InnoDB; CREATE TABLE excluded(id INT PRIMARY KEY) ENGINE=MEMORY; INSERT INTO excluded VALUES(1); SET SESSION lock_wait_timeout=23;');
            $disconnected=false; $adapter=new MemoryDisconnectAdapter($underlying,static function() use ($links,&$disconnected): void { $disconnected=true; $links[1]->close(); });
            $source=new WordPressDatabase($adapter,new PrivacyPolicy(['version'=>2,'preset'=>'custom','tables'=>['excluded'=>'exclude']]),static function() use ($links) { return $links[1]; });
            $failure=null; try { $source->withConsistentSnapshot(static function(): void { self::fail('Lost auxiliary lock must never be accepted.'); }); }
            catch (\Throwable $error) { $failure=$error; }
            self::assertTrue($disconnected); self::assertInstanceOf(\Throwable::class,$failure); self::assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class,$failure);
            self::assertTrue(mysqli_query($links[2],'SET SESSION lock_wait_timeout=1'));
            self::assertTrue(mysqli_query($links[2],'INSERT INTO excluded VALUES(2)'));
            self::assertTrue(mysqli_query($links[2],'ALTER TABLE excluded ADD extra INT'));
            self::assertSame('23',(string)mysqli_fetch_row(mysqli_query($links[0],'SELECT @@SESSION.lock_wait_timeout'))[0]);
        } finally { foreach($links as $db) { try { $db->close(); } catch(\Throwable $ignored) {} } $server->stop();Fixture::cleanup(); }
    }
}

final class MemoryDisconnectAdapter
{
    public string $prefix='wp_'; public string $last_error=''; private $inner; private $disconnect; private bool $armed=false;
    public function __construct($inner,callable $disconnect) {$this->inner=$inner;$this->disconnect=$disconnect;}
    public function query(string $sql) { $result=$this->inner->query($sql); if($sql==='SAVEPOINT wrb_snapshot_guard')$this->armed=true; return $result; }
    public function get_results(string $sql,string $format): array { return $this->inner->get_results($sql,$format); }
    public function get_var(string $sql) { if($this->armed && $sql==='SELECT COUNT(*) FROM `excluded`') {$this->armed=false;($this->disconnect)();} return $this->inner->get_var($sql); }
}
