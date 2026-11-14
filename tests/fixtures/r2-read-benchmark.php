<?php
declare(strict_types=1);
// Loopback-only controlled latency benchmark; no real R2, backup or restore.
require dirname(__DIR__,2).'/vendor/autoload.php';require dirname(__DIR__).'/Fixture.php';
use WordPressR2Backup\Tests\Fixture;
$dir=Fixture::temp();$host=str_repeat('a',32).'.r2.cloudflarestorage.com';$config=$dir.'/openssl.cnf';
file_put_contents($config,"[req]\ndistinguished_name=d\nx509_extensions=v\nprompt=no\n[d]\nCN=$host\n[v]\nsubjectAltName=DNS:$host\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,digitalSignature,keyCertSign\nsubjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid:always\nextendedKeyUsage=serverAuth\n");
$options=['config'=>$config,'private_key_bits'=>2048,'digest_alg'=>'sha256','x509_extensions'=>'v'];
$key=openssl_pkey_new($options);$cert=openssl_csr_sign(openssl_csr_new(['commonName'=>$host],$key,$options),null,$key,2,$options);
if(!$cert || !openssl_x509_export_to_file($cert,$dir.'/cert.pem') || !openssl_pkey_export_to_file($key,$dir.'/key.pem',null,$options))throw new RuntimeException('Cannot create synthetic certificate');
$source=file_get_contents(dirname(__DIR__,2).'/src/DirectR2Repository.php');$mutant=str_replace('private const BULK_PARALLEL_READS = 8;','private const BULK_PARALLEL_READS = 4;',$source,$count);if($count!==1)throw new RuntimeException('Benchmark source changed');
$mutantPath=$dir.'/mutant.php';file_put_contents($mutantPath,$mutant);$prepend=$dir.'/prepend.php';
file_put_contents($prepend,'<?php require '.var_export(dirname(__DIR__,2).'/vendor/autoload.php',true).'; require '.var_export($mutantPath,true).';');
$server=proc_open(['/usr/bin/ruby',__DIR__.'/r2-read-benchmark-server.rb',$dir.'/key.pem',$dir.'/cert.pem'],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
if(!is_resource($server))throw new RuntimeException('Cannot start synthetic server');
try {
    $port=trim((string)fgets($pipes[1]));if(!preg_match('/\A[0-9]{1,5}\z/D',$port))throw new RuntimeException('Synthetic server failed: '.stream_get_contents($pipes[2]));
    $results=[];foreach([[4,80,0],[8,80,0],[8,80,0],[4,80,0],[4,80,0],[8,80,0],[4,16,1048576],[8,16,1048576],[4,16,8388608],[8,16,8388608]] as [$parallel,$objects,$payload]) {
        $args=[PHP_BINARY];if($parallel===4){$args[]='-d';$args[]='auto_prepend_file='.$prepend;}
        $args=array_merge($args,[__DIR__.'/r2-read-benchmark-client.php',$port,$dir.'/cert.pem',dirname(__DIR__,2),(string)$objects,(string)$payload]);
        $out=tmpfile();$err=tmpfile();$client=proc_open($args,[0=>['file','/dev/null','r'],1=>$out,2=>$err],$unused);
        if(!is_resource($client))throw new RuntimeException('Cannot start synthetic client');$exit=proc_close($client);rewind($out);rewind($err);$body=stream_get_contents($out);$error=stream_get_contents($err);fclose($out);fclose($err);
        if($exit!==0)throw new RuntimeException('Synthetic client failed: '.$error.' '.$body);
        $sample=json_decode($body,true,16,JSON_THROW_ON_ERROR);$sample['parallel']=$parallel;$results[]=$sample;
    }
    echo json_encode(['latencyMs'=>50,'samples'=>$results],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),"\n";
} finally { proc_terminate($server);fclose($pipes[1]);fclose($pipes[2]);proc_close($server);Fixture::cleanup(); }
