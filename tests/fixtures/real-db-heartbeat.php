<?php
declare(strict_types=1);
namespace WordPressR2Backup {
    function time(): int { $GLOBALS['databaseClock'] += 20; return $GLOBALS['databaseClock']; }
}
namespace {
    require $argv[1] . '/vendor/autoload.php'; if ($argv[4] !== '-') { require $argv[4]; }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $connection=new \mysqli('localhost','root','',$argv[3],0,$argv[2]);
    $wpdb=new class($connection) { public $dbh; public function __construct($connection) { $this->dbh=$connection; } };
    $db=new \WordPressR2Backup\WordPressDatabase($wpdb,new \WordPressR2Backup\PrivacyPolicy(['preset'=>'full']));
    $GLOBALS['databaseClock']=\time(); $calls=0; $db->onHeartbeat(static function() use (&$calls): void { ++$calls; });
    $method=(new \ReflectionClass($db))->getMethod('stream'); $method->setAccessible(true);
    $query=$argv[5]==='value' ? "SELECT CAST(REPEAT('x',196608) AS BINARY) AS `value`"
        : 'SELECT CAST(1 AS BINARY) AS `value` UNION ALL SELECT CAST(2 AS BINARY) UNION ALL SELECT CAST(3 AS BINARY)';
    $rows=iterator_to_array($method->invoke($db,$query,false),false);
    if ($argv[5]==='value') {
        if (count($rows)!==1 || strlen($rows[0]['value'])!==196608) throw new \RuntimeException('Real large row failed');
        $calls=0; $method=(new \ReflectionClass($db))->getMethod('valueChunks'); $method->setAccessible(true);
        $result=implode('',iterator_to_array($method->invoke($db,['Type'=>'longblob'],$rows[0]['value']),false));
        if ($result!=="X'".str_repeat('78',196608)."'" || $calls<2) throw new \RuntimeException('Large-value heartbeat failed');
    } elseif ($rows!==[['value'=>'1'],['value'=>'2'],['value'=>'3']] || $calls<2) { throw new \RuntimeException('Real mysqli heartbeat failed'); }
    echo json_encode(['rows'=>count($rows),'calls'=>$calls]),"\n"; $connection->close();
}
