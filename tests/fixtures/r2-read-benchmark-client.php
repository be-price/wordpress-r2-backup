<?php
declare(strict_types=1);
namespace WordPressR2Backup {
    // Preserve all production TLS checks while resolving the synthetic hostname exclusively to loopback.
    function curl_setopt_array($handle,array $options): bool {
        if (($options[CURLOPT_SSL_VERIFYPEER]??null)!==true || ($options[CURLOPT_SSL_VERIFYHOST]??null)!==2 || ($options[CURLOPT_FOLLOWLOCATION]??null)!==false) throw new \RuntimeException('TLS settings changed');
        $host=str_repeat('a',32).'.r2.cloudflarestorage.com';$url=$options[CURLOPT_URL];
        if (strpos($url,'https://'.$host.'/')!==0) throw new \RuntimeException('Unexpected host');
        $options[CURLOPT_URL]='https://'.$host.':'.FIXTURE_PORT.substr($url,strlen('https://'.$host));
        $options[CURLOPT_RESOLVE]=[$host.':'.FIXTURE_PORT.':127.0.0.1'];$options[CURLOPT_CAINFO]=FIXTURE_CERT;
        $options[CURLOPT_PROXY]='';$options[CURLOPT_NOPROXY]='*';
        return \curl_setopt_array($handle,$options);
    }
}
namespace {
    define('FIXTURE_PORT',(int)$argv[1]);define('FIXTURE_CERT',$argv[2]);require $argv[3].'/vendor/autoload.php';
    $repo=new \WordPressR2Backup\DirectR2Repository(['accountId'=>str_repeat('a',32),'bucket'=>'synthetic-bucket','accessKeyId'=>str_repeat('A',16),'secretAccessKey'=>str_repeat('b',32),'unitId'=>str_repeat('c',64)]);
    $count=isset($argv[4])?(int)$argv[4]:80;$payload=isset($argv[5])?(int)$argv[5]:0;
    if(!in_array($count,[16,80],true)||!in_array($payload,[0,1048576,8388608],true))throw new \RuntimeException('Invalid synthetic fixture');
    $receipts=[];for($i=0;$i<$count;++$i) {
        $name=hash('sha256',(string)$i).'.age';$body='synthetic-cipher-'.$name.str_repeat('x',$payload);
        $prefix=$payload===8388608?'packs/'.str_repeat('f',64):'pages/'.str_repeat($payload?'e':'d',64);
        $receipts[]=['key'=>$prefix.'/'.$name,'bytes'=>strlen($body),'sha256'=>hash('sha256',$body)];
    }
    $start=hrtime(true);$repo->verifyMany($receipts);$repo->verifyMany($receipts);
    $usage=getrusage();echo json_encode(['objects'=>count($receipts),'payloadBytes'=>$payload,'passes'=>2,'seconds'=>(hrtime(true)-$start)/1e9,'phpPeakBytes'=>memory_get_peak_usage(true),'maxRss'=>$usage['ru_maxrss']??null],JSON_THROW_ON_ERROR),"\n";
}
