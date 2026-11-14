<?php
declare(strict_types=1);
namespace WordPressR2Backup { function time(): int { return (int) file_get_contents($GLOBALS['heartbeatClock']); } }
namespace {
    require $argv[1].'/vendor/autoload.php'; if ($argv[5] !== '-') { require $argv[5]; }
    $GLOBALS['heartbeatClock']=$argv[4]; $last=(int)file_get_contents($argv[4]); $start=$last; $calls=0;
    $recipients=explode(',', $argv[3]);
    $scope=['accountId'=>str_repeat('a',32),'bucket'=>'synthetic-bucket','unitId'=>str_repeat('c',64),
        'keySetId'=>\WordPressR2Backup\Manifest::keySetId($recipients),'encoding'=>\WordPressR2Backup\V4Format::ENCODING];
    $heartbeat=static function() use (&$last,&$calls): void {
        $now=(int)file_get_contents($GLOBALS['heartbeatClock']);
        if ($now-$last>=600) { throw new \RuntimeException('Maintenance heartbeat expired'); }
        $last=$now; ++$calls;
    };
    $store=new \WordPressR2Backup\V4ContentStore(new \WordPressR2Backup\AgeProcess($argv[2]),$recipients,$argv[6],$scope,str_repeat('b',64),null,null,false,null,$heartbeat);
    for ($i=0;$i<8;++$i) { $store->accept('synthetic content '.$i,'database:table_'.$i); }
    $result=$store->finish(); $heartbeat();
    if ($result['packCount']!==8 || $last-$start<=600 || $calls<8) { throw new \RuntimeException('Pack heartbeat missing'); }
    echo json_encode(['packs'=>$result['packCount'],'calls'=>$calls,'seconds'=>$last-$start]),"\n";
}
