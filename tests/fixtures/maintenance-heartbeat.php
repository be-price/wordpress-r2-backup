<?php
declare(strict_types=1);
namespace WordPressR2Backup {
    // Simulate slow progress without sleeping: every clock observation advances twenty seconds.
    function time(): int { $GLOBALS['heartbeatClock'] += 20; return $GLOBALS['heartbeatClock']; }
}
namespace {
    require $argv[1] . '/vendor/autoload.php';
    require $argv[1] . '/tests/WordPressPolicyContextTest.php';
    if (isset($argv[3]) && $argv[3] !== '-') { require $argv[3]; }
    if (isset($argv[4]) && $argv[4] !== '-') { require $argv[4]; }
    $GLOBALS['heartbeatClock'] = \time(); $start = $GLOBALS['heartbeatClock']; $directory = $argv[2];
    $token = str_repeat('a',32); $path = $directory . '/.maintenance';
    $marker = '<?php $upgrading = ' . $GLOBALS['heartbeatClock'] . '; /* wrb-' . $token . ' */';
    file_put_contents($path,$marker); $stat=lstat($path); $owner=['dev'=>$stat['dev'],'ino'=>$stat['ino'],'token'=>$token];
    $class=new \ReflectionClass(\WordPressR2Backup\LiveBackup::class); $backup=$class->newInstanceWithoutConstructor();
    $refresh=$class->getMethod('refreshMaintenance'); $refresh->setAccessible(true); $fresh=$class->getMethod('assertFreshMaintenance'); $fresh->setAccessible(true);
    $calls=0; $progress=static function(array $event) use($backup,$refresh,$path,$owner,&$marker,&$calls): void {
        ++$calls; $args=[$path,$owner,&$marker]; $refresh->invokeArgs($backup,$args);
    };
    $rules=['version'=>2,'preset'=>'custom','legacy'=>['publicContentOnly'=>true,'scanPublic'=>false]];
    $wpdb=new \WordPressR2Backup\Tests\PolicyContextWpdbFake(); $policy=new \WordPressR2Backup\PrivacyPolicy($rules);
    $digest=(new \WordPressR2Backup\WordPressPolicyContext($wpdb,$policy,$directory))->prepare($progress); $policyCalls=$calls;
    $quiet=(new \WordPressR2Backup\WordPressPolicyContext($wpdb,new \WordPressR2Backup\PrivacyPolicy($rules),$directory))->prepare();
    if($digest!==$quiet || $policyCalls<2 || $GLOBALS['heartbeatClock']-$start<=600) throw new \RuntimeException('Policy heartbeat or digest failed');
    $database=new \WordPressR2Backup\WordPressDatabase($wpdb,$policy); $database->onHeartbeat(static function() use($progress): void { $progress(['phase'=>'capture']); });
    $pulse=(new \ReflectionClass($database))->getMethod('pulseHeartbeat'); $pulse->setAccessible(true);
    for($i=0;$i<5;$i++) $pulse->invoke($database,true);
    $databaseCalls=$calls-$policyCalls; if($databaseCalls<2) throw new \RuntimeException('Database heartbeat failed');
    $file=$directory.'/small-file'; $bytes=str_repeat('x',2097152); file_put_contents($file,$bytes); $st=stat($file);
    $capture=(new \ReflectionClass(\WordPressR2Backup\V4Capture::class))->newInstanceWithoutConstructor();
    $read=(new \ReflectionClass($capture))->getMethod('readFile'); $read->setAccessible(true);
    $fileHash=$read->invoke($capture,['absolute'=>$file,'stat'=>['size'=>$st['size']]],null,$progress,'capture');
    $fileCalls=$calls-$policyCalls-$databaseCalls;
    if($fileCalls<1 || !hash_equals(hash('sha256',$bytes),$fileHash)) throw new \RuntimeException('File heartbeat failed');
    $fresh->invoke($backup,$path,$owner,$marker);
    echo json_encode(['policyCalls'=>$policyCalls,'databaseCalls'=>$databaseCalls,'fileCalls'=>$fileCalls,'simulatedSeconds'=>$GLOBALS['heartbeatClock']-$start]),"\n";
}
