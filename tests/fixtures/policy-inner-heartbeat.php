<?php
declare(strict_types=1);
namespace WordPressR2Backup {
    // Time advances only during processing inside a single attachment, not between rows.
    function time(): int {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (($frame['function'] ?? '') === 'media') { $GLOBALS['policyClock'] += 20; break; }
        }
        return $GLOBALS['policyClock'];
    }
}
namespace {
    require $argv[1].'/vendor/autoload.php'; require $argv[1].'/tests/WordPressPolicyContextTest.php';
    if ($argv[3] !== '-') { require $argv[3]; }
    $GLOBALS['policyClock']=\time(); $calls=[];
    $rules=['version'=>2,'preset'=>'custom','legacy'=>['publicContentOnly'=>true,'scanPublic'=>false]];
    $wpdb=new \WordPressR2Backup\Tests\PolicyContextWpdbFake();
    $digest=(new \WordPressR2Backup\WordPressPolicyContext($wpdb,new \WordPressR2Backup\PrivacyPolicy($rules),$argv[2]))->prepare(
        static function(array $event) use (&$calls): void { $calls[]=$event; });
    $quiet=(new \WordPressR2Backup\WordPressPolicyContext($wpdb,new \WordPressR2Backup\PrivacyPolicy($rules),$argv[2]))->prepare();
    if ($digest!==$quiet || count($calls)<2 || max(array_column($calls,'entries'))>=500) throw new \RuntimeException('Inner attachment heartbeat failed');
    echo json_encode(['calls'=>count($calls),'maxEntries'=>max(array_column($calls,'entries'))]),"\n";
}
