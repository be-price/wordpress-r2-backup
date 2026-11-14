<?php
declare(strict_types=1);
namespace WordPressR2Backup {
    function curl_init() { ++$GLOBALS['initCount']; return (object) ['opts'=>[], 'status'=>0]; }
    function curl_close($h): void {}
    function curl_reset($h): void { $h->opts=[]; }
    function curl_setopt_array($h, array $o): bool { $h->opts=$o+$h->opts; return true; }
    function curl_setopt($h, int $option, $value): bool { $h->opts[$option]=$value; return true; }
    function curl_errno($h): int { return 60; }
    function curl_getinfo($h, int $option) { return $h->status; }
    function curl_exec($h) {
        $o=$h->opts; $method=$o[CURLOPT_CUSTOMREQUEST];
        if (($o[CURLOPT_SSL_VERIFYPEER]??null)!==true || ($o[CURLOPT_SSL_VERIFYHOST]??null)!==2 || ($o[CURLOPT_FOLLOWLOCATION]??null)!==false) throw new \RuntimeException('TLS policy changed');
        if ($method!=='PUT' && isset($o[CURLOPT_UPLOAD])) throw new \RuntimeException('Upload state leaked');
        if ($method!=='HEAD' && !empty($o[CURLOPT_NOBODY])) throw new \RuntimeException('HEAD state leaked');
        if ($method==='PUT' && empty($o[CURLOPT_FRESH_CONNECT])) throw new \RuntimeException('Upload reused a possibly stale connection');
        if ($method!=='PUT' && !empty($o[CURLOPT_FRESH_CONNECT])) throw new \RuntimeException('Fresh connection state leaked');
        if ($method==='PUT') { $GLOBALS['body']=stream_get_contents($o[CURLOPT_INFILE]); $h->status=200; return true; }
        if ($method==='DELETE') { $h->status=204; return true; }
        if ($method==='HEAD') { $h->status=200; ($o[CURLOPT_HEADERFUNCTION])($h,'Content-Length: '.strlen($GLOBALS['body'])."\r\n"); return true; }
        if ($method==='GET') {
            if ($GLOBALS['failNext']) { $GLOBALS['failNext']=false; return false; }
            $h->status=200; return ($o[CURLOPT_WRITEFUNCTION])($h,$GLOBALS['body'])===strlen($GLOBALS['body']);
        }
        throw new \RuntimeException('Unexpected method');
    }
}
namespace {
    require dirname(__DIR__,2).'/vendor/autoload.php';
    $source=getenv('WRB_TEST_R2_SOURCE');if(is_string($source)&&$source!=='')require $source;
    $GLOBALS['initCount']=0;$GLOBALS['body']='';$GLOBALS['failNext']=false;
    $settings=['accountId'=>str_repeat('a',32),'bucket'=>'synthetic-bucket','accessKeyId'=>str_repeat('a',16),'secretAccessKey'=>str_repeat('b',32),'unitId'=>str_repeat('c',64)];
    $r=new \WordPressR2Backup\DirectR2Repository($settings);
    if (empty($r->testConnection()['ok'])) throw new \RuntimeException('probe failed');
    $GLOBALS['body']='synthetic';$receipt=['key'=>'packs/'.str_repeat('d',64).'/'.str_repeat('e',64).'.age','bytes'=>9,'sha256'=>hash('sha256','synthetic')];
    $GLOBALS['failNext']=true;$failed=false;try{$r->verify($receipt);}catch(\WordPressR2Backup\R2TransportException $e){$failed=$e->errno()===60;}
    if(!$failed)throw new \RuntimeException('TLS failure accepted');$r->verify($receipt);
    if($GLOBALS['initCount']!==1)throw new \RuntimeException('Connection was not reused');
    echo "probe, TLS failure, and subsequent verified read passed\n";
}
