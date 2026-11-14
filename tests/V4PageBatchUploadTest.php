<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\DirectR2Repository;
use WordPressR2Backup\LiveBackup;

/** Synthetic transfer only; no external service or recovery. */
final class V4PageBatchUploadTest extends TestCase
{
    protected function tearDown(): void { Fixture::cleanup(); }

    public function testSixteenPageLimitBoundaryAndEveryFullRead(): void
    {
        [$method,$backup,$transport,$directory,$objects]=$this->fixture();
        self::assertSame(16,$method->invoke($backup,$directory,$objects,0));
        self::assertSame(16,count($transport->objects)); self::assertSame(16,$transport->puts);
        foreach ($transport->getCounts as $count) { self::assertSame(2,$count); }
        self::assertSame(4,$method->invoke($backup,$directory,$objects,16));
        self::assertSame(20,count($transport->objects)); self::assertSame(0,$transport->deletes);
    }

    public function testPutFailureCanRetryWithoutReplacingOrDeletingAlreadyUploadedPages(): void
    {
        [$method,$backup,$transport,$directory,$objects]=$this->fixture(); $transport->failPut=3;
        $error=null; try { $method->invoke($backup,$directory,$objects,0); } catch (\Throwable $caught) { $error=$caught; }
        self::assertInstanceOf(\Throwable::class,$error); self::assertCount(2,$transport->objects); self::assertSame(0,$transport->deletes);
        $before=$transport->objects; $transport->failPut=0;
        self::assertSame(16,$method->invoke($backup,$directory,$objects,0));
        foreach ($before as $key=>$bytes) { self::assertSame($bytes,$transport->objects[$key]); }
        self::assertSame(0,$transport->deletes); self::assertGreaterThanOrEqual(2,$transport->preconditions);
    }

    public function testOuterVerificationFailureRejectsEntireBatchAndRetainsObjects(): void
    {
        [$method,$backup,$transport,$directory,$objects]=$this->fixture(); $transport->corruptOuter=true;
        $error=null; try { $method->invoke($backup,$directory,$objects,0); } catch (\Throwable $caught) { $error=$caught; }
        self::assertInstanceOf(\Throwable::class,$error); self::assertCount(16,$transport->objects); self::assertSame(0,$transport->deletes);
        $transport->corruptOuter=false; self::assertSame(16,$method->invoke($backup,$directory,$objects,0));
    }

    public function testAdjacentPackStopsPageBatch(): void
    {
        [$method,$backup,$transport,$directory,$objects]=$this->fixture();
        $objects[2]['object']['key']='packs/'.str_repeat('b',64).'/'.str_repeat('c',64).'.age';
        self::assertSame(2,$method->invoke($backup,$directory,$objects,0)); self::assertCount(2,$transport->objects);
    }

    public function testActualV4StepPersistsOnlyAfterTheWholeBatch(): void
    {
        $out=tmpfile();$err=tmpfile();
        $process=proc_open([PHP_BINARY,'-d','display_errors=1','-d','log_errors=0',__DIR__.'/fixtures/page-batch-step.php',dirname(__DIR__)],[0=>['file','/dev/null','r'],1=>$out,2=>$err],$pipes);
        self::assertIsResource($process);$exit=proc_close($process);rewind($out);rewind($err);$body=stream_get_contents($out);$error=stream_get_contents($err);fclose($out);fclose($err);
        self::assertSame(0,$exit,$error.$body);$result=json_decode($body,true,16,JSON_THROW_ON_ERROR);
        self::assertTrue($result['failedPositionUnchanged']);self::assertGreaterThanOrEqual(2,$result['pages']);
    }

    public function testActualStepRejectsReorderedReturnedReceipts(): void
    {
        $source=file_get_contents(dirname(__DIR__).'/src/DirectR2Repository.php');
        $mutant=str_replace('return $receipts;', 'return array_reverse($receipts);',$source,$count);
        self::assertSame(1,$count);$directory=Fixture::temp();$path=$directory.'/mutant.php';file_put_contents($path,$mutant);
        $bootstrap=$directory.'/bootstrap.php';file_put_contents($bootstrap,'<?php require '.var_export(dirname(__DIR__).'/vendor/autoload.php',true).'; require '.var_export($path,true).';');
        $out=tmpfile();$err=tmpfile();
        $process=proc_open([PHP_BINARY,'-d','display_errors=1','-d','log_errors=0','-d','auto_prepend_file='.$bootstrap,__DIR__.'/fixtures/page-batch-step.php',dirname(__DIR__),'receipt-mismatch'],[0=>['file','/dev/null','r'],1=>$out,2=>$err],$pipes);
        self::assertIsResource($process);$exit=proc_close($process);rewind($out);rewind($err);$body=stream_get_contents($out);$error=stream_get_contents($err);fclose($out);fclose($err);
        self::assertSame(0,$exit,$error.$body);$result=json_decode($body,true,16,JSON_THROW_ON_ERROR);self::assertTrue($result['receiptMismatchRejected']);
    }

    private function fixture(): array
    {
        $directory=Fixture::temp(); $transport=new V4PageBatchTransport();
        $connection=['accountId'=>str_repeat('a',32),'bucket'=>'backup-test','accessKeyId'=>str_repeat('b',32),'secretAccessKey'=>str_repeat('c',64),'unitId'=>str_repeat('d',64)];
        $repo=new DirectR2Repository($connection,$transport,null,static function(int $seconds): void {});
        $reflection=new \ReflectionClass(LiveBackup::class); $backup=$reflection->newInstanceWithoutConstructor();
        $property=$reflection->getProperty('repository'); $property->setAccessible(true); $property->setValue($backup,$repo);
        $method=$reflection->getMethod('uploadPageBatch');$method->setAccessible(true);$objects=[];
        for($i=0;$i<20;++$i) { $bytes='synthetic-page-'.$i; $file=$directory.'/page-'.$i; file_put_contents($file,$bytes);chmod($file,0600);
            $objects[]=['object'=>['key'=>'pages/'.str_repeat('e',64).'/'.hash('sha256',(string)$i).'.age','bytes'=>strlen($bytes),'sha256'=>hash('sha256',$bytes)],'cipherFile'=>$file]; }
        return [$method,$backup,$transport,$directory,$objects];
    }
}

final class V4PageBatchTransport
{
    public array $objects=[]; public array $getCounts=[]; public int $puts=0; public int $deletes=0; public int $preconditions=0; public int $failPut=0; public bool $corruptOuter=false;
    public function __invoke(array $request, callable $consume): array
    {
        $key=(string)parse_url($request['url'],PHP_URL_PATH); $method=$request['method'];
        if ($method==='PUT') {
            ++$this->puts; if (($request['headers']['if-none-match']??null)!=='*') throw new \RuntimeException('Missing conditional PUT');
            if ($this->failPut!==0 && $this->puts===$this->failPut) return ['status'=>403,'headers'=>[]];
            if (isset($this->objects[$key])) { ++$this->preconditions; return ['status'=>412,'headers'=>[]]; }
            $this->objects[$key]=stream_get_contents($request['body']); return ['status'=>200,'headers'=>[]];
        }
        if ($method==='DELETE') { ++$this->deletes; return ['status'=>204,'headers'=>[]]; }
        if (!isset($this->objects[$key])) return ['status'=>404,'headers'=>[]];
        if ($method==='HEAD') return ['status'=>200,'headers'=>['content-length'=>(string)strlen($this->objects[$key])]];
        $this->getCounts[$key]=($this->getCounts[$key]??0)+1;
        $body=$this->objects[$key]; if ($this->corruptOuter && $this->getCounts[$key]>=2) { $body[0]=$body[0]==='x'?'y':'x'; }
        $consume($body); return ['status'=>200,'headers'=>[]];
    }
}
