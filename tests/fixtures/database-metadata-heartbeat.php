<?php
declare(strict_types=1);
namespace WordPressR2Backup { function time(): int { return $GLOBALS['databaseClock']; } }
namespace {
    require $argv[1] . '/vendor/autoload.php';
    if ($argv[4] !== '-') { require $argv[4]; }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $connection = new \mysqli('localhost', 'root', '', $argv[3], 0, $argv[2]);
    $connection->query('CREATE TABLE hb_parent (id INT PRIMARY KEY) ENGINE=InnoDB');
    $connection->query('CREATE TABLE hb_child (id INT PRIMARY KEY, parent_id INT, FOREIGN KEY (parent_id) REFERENCES hb_parent(id)) ENGINE=InnoDB');
    $connection->query('INSERT INTO hb_parent VALUES (1)');
    $connection->query('INSERT INTO hb_child VALUES (1,1)');
    $wpdb = new class($connection) {
        public $dbh; public $last_error = '';
        public function __construct($connection) { $this->dbh = $connection; }
        public function get_results($sql, $mode = null) { $r = $this->dbh->query($sql); $GLOBALS['databaseClock'] += 65; return $r->fetch_all(MYSQLI_ASSOC); }
        public function get_var($sql) { $r = $this->dbh->query($sql); $GLOBALS['databaseClock'] += 65; $row = $r->fetch_row(); return $row[0] ?? null; }
        public function query($sql) { $result = $this->dbh->query($sql); $GLOBALS['databaseClock'] += 65; return $result; }
    };
    $GLOBALS['databaseClock'] = \time(); $last = $GLOBALS['databaseClock']; $calls = 0;
    $db = new \WordPressR2Backup\WordPressDatabase($wpdb, new \WordPressR2Backup\PrivacyPolicy(['version'=>2, 'preset'=>'full']));
    $db->onHeartbeat(static function() use (&$last, &$calls): void {
        if ($GLOBALS['databaseClock'] - $last >= 600) { throw new \RuntimeException('Maintenance heartbeat expired'); }
        $last = $GLOBALS['databaseClock']; ++$calls;
    });
    $result = $db->preflight();
    if ($GLOBALS['databaseClock'] - $last >= 600 || $result['tableCount'] !== 2 || $calls < 8) { throw new \RuntimeException('Metadata heartbeat missing'); }
    echo json_encode(['calls'=>$calls, 'seconds'=>$GLOBALS['databaseClock']-\time()]), "\n";
}
