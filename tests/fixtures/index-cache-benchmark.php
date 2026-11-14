<?php
declare(strict_types=1);
// Local synthetic index benchmark: no restore or remote connection.
require $argv[1].'/vendor/autoload.php';
if (($argv[2]??'-')!=='-') { require $argv[2]; }
$directory=realpath(sys_get_temp_dir()).'/wrb-index-bench-'.bin2hex(random_bytes(8)); if (!mkdir($directory,0700)) throw new RuntimeException('fixture');
$scope=['accountId'=>str_repeat('a',32),'bucket'=>'synthetic-bucket','unitId'=>str_repeat('1',64),'keySetId'=>str_repeat('2',64),'encoding'=>\WordPressR2Backup\V4Format::ENCODING];
$repo=new class { public function listPackObjects($scope): iterable { return []; } public function verify($receipt): void {} };
$index=\WordPressR2Backup\V4ContentIndex::openOrCreate($directory,$scope,$repo); $members=[];
for($i=0;$i<512;++$i) { $bytes='synthetic-'.$i;$hash=hash('sha256',$bytes);$members[]=['id'=>\WordPressR2Backup\V4Format::contentId(strlen($bytes),$hash),'size'=>strlen($bytes),'sha256'=>$hash]; }
$pack=['object'=>['key'=>'packs/'.\WordPressR2Backup\V4Format::scopeId($scope).'/'.str_repeat('a',64).'.age','bytes'=>100,'sha256'=>str_repeat('b',64)],'lane'=>'other','members'=>$members];
$result=$index->withLock(static function() use($index,$pack,$members): array {
    $index->recordCandidate($pack,null); $index->markReady($pack['object']);
    $start=hrtime(true); $count=0;
    for($round=0;$round<4;++$round) foreach($members as $member) { $found=$index->lookup($member['id']); if (($found['descriptor']??null)!==$pack) throw new RuntimeException('index mismatch'); ++$count; }
    return ['lookups'=>$count,'seconds'=>(hrtime(true)-$start)/1e9];
});
echo json_encode($result,JSON_THROW_ON_ERROR),"\n";
