<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;

/** Uses a private MySQL instance and a read-only query; no restore is performed. */
final class WordPressDatabaseHeartbeatIntegrationTest extends TestCase
{
    private ?IsolatedMysql $database=null;
    protected function tearDown(): void { if ($this->database !== null) { $this->database->stop(); } Fixture::cleanup(); }

    public function testRealMysqliRowStreamNeedsElapsedTimeHeartbeat(): void
    {
        if (getenv('WRB_RUN_V4_MYSQL_INTEGRATION') !== '1') { self::markTestSkipped('Explicit isolated v4 MySQL integration authorization is required.'); }
        $this->database=IsolatedMysql::start(null,'DISABLED'); $schema=$this->database->newSchema();
        $config=parse_ini_file($this->database->defaultsFile(),true,INI_SCANNER_RAW); $socket=$config['client']['socket'];
        $root=dirname(__DIR__); $private=Fixture::temp();
        $source=file_get_contents($root.'/src/WordPressDatabase.php'); $mutants=[];
        $rows=str_replace('$this->pulseHeartbeat(true);','/* notification removed */',$source,$count);
        self::assertSame(2,$count,'Both actual row-stream branches must be targeted');
        $mutants['row']=$private.'/rows.php'; file_put_contents($mutants['row'],$rows);
        $value=str_replace('$this->pulseHeartbeat(); yield bin2hex','/* notification removed */ yield bin2hex',$source,$count);
        self::assertSame(1,$count,'The large-value stream must be targeted');
        $mutants['value']=$private.'/value.php'; file_put_contents($mutants['value'],$value);
        foreach (['row','value'] as $mode) foreach (['-', $mutants[$mode]] as $sourcePath) {
            $out=tmpfile(); $err=tmpfile();
            $process=proc_open([PHP_BINARY,__DIR__.'/fixtures/real-db-heartbeat.php',$root,$socket,$schema,$sourcePath,$mode],
                [0=>['file','/dev/null','r'],1=>$out,2=>$err],$pipes);
            self::assertIsResource($process); $exit=proc_close($process); rewind($out); rewind($err);
            $body=stream_get_contents($out); $error=stream_get_contents($err); fclose($out); fclose($err);
            if ($sourcePath==='-') {
                self::assertSame(0,$exit,$error); $result=json_decode($body,true,16,JSON_THROW_ON_ERROR);
                self::assertSame($mode==='row' ? 3 : 1,$result['rows']); self::assertGreaterThanOrEqual(2,$result['calls']);
            } else { self::assertNotSame(0,$exit,'Removing actual ' . $mode . ' notifications must fail'); }
        }
    }

    public function testRealMetadataQueriesNeedElapsedTimeHeartbeat(): void
    {
        if (getenv('WRB_RUN_V4_MYSQL_INTEGRATION') !== '1') { self::markTestSkipped('Explicit isolated v4 MySQL integration authorization is required.'); }
        $this->database=IsolatedMysql::start(null,'DISABLED');
        $config=parse_ini_file($this->database->defaultsFile(),true,INI_SCANNER_RAW); $socket=$config['client']['socket'];
        $root=dirname(__DIR__); $private=Fixture::temp(); $source=file_get_contents($root.'/src/WordPressDatabase.php');
        $mutant=str_replace('$this->pulseHeartbeat();','/* metadata pulse removed */',$source,$count);
        self::assertGreaterThan(10,$count); $path=$private.'/metadata-mutant.php'; file_put_contents($path,$mutant);
        foreach (['-', $path] as $sourcePath) {
            $schema=$this->database->newSchema(); $out=tmpfile(); $err=tmpfile();
            $process=proc_open([PHP_BINARY,__DIR__.'/fixtures/database-metadata-heartbeat.php',$root,$socket,$schema,$sourcePath], [0=>['file','/dev/null','r'],1=>$out,2=>$err],$pipes);
            self::assertIsResource($process); $exit=proc_close($process); rewind($out); rewind($err);
            $body=stream_get_contents($out); $error=stream_get_contents($err); fclose($out); fclose($err);
            if ($sourcePath==='-') { self::assertSame(0,$exit,$error . $body); $result=json_decode($body,true,16,JSON_THROW_ON_ERROR); self::assertGreaterThan(600,$result['seconds']); self::assertGreaterThanOrEqual(8,$result['calls']); }
            else { self::assertNotSame(0,$exit,'Removing metadata notifications must cause maintenance expiry'); }
        }
    }
}
