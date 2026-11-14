<?php
declare(strict_types=1);
namespace WordPressR2Backup { function time(): int { if (!empty($GLOBALS['tickEachRead'])) $GLOBALS['fakeClock']+=20; return $GLOBALS['fakeClock']; } }
namespace {
    require $argv[1].'/vendor/autoload.php';require $argv[1].'/tests/Fixture.php';require $argv[1].'/tests/WordPressDatabaseTest.php';
    use WordPressR2Backup\{LiveBackup,PrivacyPolicy,SourceRoots,V4Capture};
    use WordPressR2Backup\Tests\{Fixture,V4DatabaseWpdbFake};
    $GLOBALS['fakeClock']=\time();$GLOBALS['tickEachRead']=false;
    $private=Fixture::temp();$maintenance=$private.'/.maintenance';$token=str_repeat('a',32);$marker='<?php $upgrading = '.$GLOBALS['fakeClock'].'; /* wrb-'.$token.' */';
    file_put_contents($maintenance,$marker);$st=lstat($maintenance);$owner=['dev'=>$st['dev'],'ino'=>$st['ino'],'token'=>$token];
    $class=new ReflectionClass(LiveBackup::class);$backup=$class->newInstanceWithoutConstructor();$property=$class->getProperty('workspace');$property->setAccessible(true);$property->setValue($backup,$private);
    $record=$class->getMethod('recordCaptureProgress');$record->setAccessible(true);$snapshot=[];$last=0;$id=str_repeat('b',64);
    $send=static function(array $update) use($record,$backup,&$snapshot,$id,$maintenance,$owner,&$marker,&$last): void { $args=[&$snapshot,$update,$id,$maintenance,$owner,&$marker,&$last];$record->invokeArgs($backup,$args); };
    $send(['phase'=>'capture','files'=>1,'fileBytes'=>100,'path'=>'never persist']);$first=file_get_contents($private.'/progress.json');
    $GLOBALS['fakeClock']+=1;$send(['phase'=>'capture','fileBytes'=>12]);
    if($snapshot['fileBytes']!==100||$snapshot['currentFileBytes']!==12)throw new RuntimeException('File totals overlapped');
    $GLOBALS['fakeClock']+=4;$send(['phase'=>'capture','files'=>2,'fileBytes'=>200]);
    if(file_get_contents($private.'/progress.json')!==$first)throw new RuntimeException('Throttle changed');
    $GLOBALS['fakeClock']+=11;$send(['phase'=>'capture','heartbeat'=>true]);$saved=json_decode(file_get_contents($private.'/progress.json'),true,32,JSON_THROW_ON_ERROR);
    if($saved['files']!==2||$saved['fileBytes']!==200||$saved['currentFileBytes']!==0||isset($saved['path']))throw new RuntimeException('Counters not retained');
    $GLOBALS['fakeClock']+=1;$send(['phase'=>'scan','entries'=>0,'selected'=>0]);$GLOBALS['fakeClock']+=15;$send(['phase'=>'capture','heartbeat'=>true]);
    $saved=json_decode(file_get_contents($private.'/progress.json'),true,32,JSON_THROW_ON_ERROR);
    if($saved['phase']!=='scan'||$saved['entries']!==0||$saved['selected']!==0||isset($saved['files']))throw new RuntimeException('Phase transition differs');
    $snapshot=[];$last=0;$nextId=str_repeat('c',64);$args=[&$snapshot,['phase'=>'prepare','entries'=>0],$nextId,$maintenance,$owner,&$marker,&$last];$record->invokeArgs($backup,$args);
    $saved=json_decode(file_get_contents($private.'/progress.json'),true,32,JSON_THROW_ON_ERROR);
    if($saved['id']!==$nextId||$saved['phase']!=='prepare'||isset($saved['selected']))throw new RuntimeException('Previous run leaked');
    $property->setValue($backup,$private.'/missing-directory');$GLOBALS['fakeClock']+=16;$error=null;
    try{$args=[&$snapshot,['phase'=>'prepare','entries'=>3],$nextId,$maintenance,$owner,&$marker,&$last];$record->invokeArgs($backup,$args);}catch(Throwable $caught){$error=$caught;}
    if(!$error)throw new RuntimeException('Write failure was ignored');
    $root=Fixture::temp();Fixture::write($root,'file.bin','synthetic');$source=new SourceRoots($root);$entry=iterator_to_array($source->entries(),false)[0];
    $capture=new V4Capture($source,new V4DatabaseWpdbFake(),new PrivacyPolicy(['version'=>2,'preset'=>'full']),Fixture::temp());
    $read=(new ReflectionClass($capture))->getMethod('readFile');$read->setAccessible(true);$events=[];$GLOBALS['tickEachRead']=true;
    $read->invoke($capture,$entry,null,static function(array $event) use(&$events): void {$events[]=$event;},'scan');
    if(!$events||!array_key_exists('fileBytes',$events[0])||array_key_exists('currentFileBytes',$events[0])||isset($events[0]['path']))throw new RuntimeException('Existing per-file callback changed');
    echo "ok\n";Fixture::cleanup();
}
