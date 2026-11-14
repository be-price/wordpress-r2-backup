<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\MetadataMap;
use WordPressR2Backup\V4ContentIndex;
use WordPressR2Backup\V4Format;

/** Synthetic index and repository only: no restoration or network. */
final class V4IndexValidatedCacheTest extends TestCase
{
    protected function tearDown(): void { Fixture::cleanup(); }

    public function testRepeatedLookupStillVerifiesEveryCipherAndInvalidatesMissingPack(): void
    {
        [$index,$repo,$directory,$pack]=$this->ready();
        $index->withLock(function() use ($index,$repo,$pack): void {
            foreach ($pack['members'] as $member) { self::assertSame($pack,$index->lookup($member['id'])['descriptor']); }
            $index->verifyReadyPacks([$pack['object']['key']]); $index->verifyReadyPacks([$pack['object']['key']]);
            self::assertCount(3,$repo->verified); // markReady plus both complete verification requests.
            $index->markMissing([$pack['object']['key']]); self::assertNull($index->lookup($pack['members'][0]['id']));
        });
    }

    public function testChangedValidatedRecordIsReadAgainAndRejected(): void
    {
        [$index,$repo,$directory,$pack]=$this->ready();
        $index->withLock(function() use ($index,$directory,$pack): void {
            self::assertNotNull($index->lookup($pack['members'][0]['id']));
            $map=MetadataMap::open($directory.'/packs');
            $map->put($pack['object']['key'],['descriptor'=>$pack,'status'=>'invalid','rootScopeSha256'=>null],true);
            $error=null; try { $index->lookup($pack['members'][1]['id']); } catch (\RuntimeException $caught) { $error=$caught; }
            self::assertInstanceOf(\RuntimeException::class,$error);
        });
    }

    public function testReplacedLocatorCannotReuseWrongPackMembership(): void
    {
        [$index,$repo,$directory,$pack]=$this->ready(); $other=$this->pack('other',2); $repo->objects[$other['object']['key']]=$other['object'];
        $index->withLock(function() use ($index,$directory,$pack,$other): void {
            $index->recordCandidate($other,null); $index->markReady($other['object']);
            self::assertNotNull($index->lookup($pack['members'][0]['id']));
            MetadataMap::open($directory.'/contents')->put($pack['members'][0]['id'],['pack'=>$other['object']['key']],true);
            $error=null; try { $index->lookup($pack['members'][0]['id']); } catch (\RuntimeException $caught) { $error=$caught; }
            self::assertInstanceOf(\RuntimeException::class,$error); self::assertStringContainsString('membership',$error->getMessage());
        });
    }

    public function testDamagedPartitionCannotBeHiddenByTheValidatedCache(): void
    {
        [$index,$repo,$directory,$pack]=$this->ready();
        $index->withLock(function() use ($index,$directory,$pack): void {
            self::assertNotNull($index->lookup($pack['members'][0]['id']));
            $partitions=glob($directory.'/packs/leaf-*.json'); self::assertCount(1,$partitions);
            file_put_contents($partitions[0],'{"damaged":true}');
            $error=null; try { $index->lookup($pack['members'][1]['id']); } catch (\Throwable $caught) { $error=$caught; }
            self::assertInstanceOf(\Throwable::class,$error);
        });
    }

    private function ready(): array
    {
        $directory=Fixture::temp(); $repo=new V4IndexCacheRepository(); $pack=$this->pack('first',32);
        $index=V4ContentIndex::openOrCreate($directory,$this->scope(),$repo); $repo->objects[$pack['object']['key']]=$pack['object'];
        $index->withLock(static function() use ($index,$pack): void { $index->recordCandidate($pack,null); $index->markReady($pack['object']); });
        return [$index,$repo,$directory,$pack];
    }
    private function scope(): array { return ['accountId'=>str_repeat('a',32),'bucket'=>'synthetic-bucket','unitId'=>str_repeat('1',64),'keySetId'=>str_repeat('2',64),'encoding'=>V4Format::ENCODING]; }
    private function pack(string $name,int $count): array
    {
        $members=[]; for($i=0;$i<$count;++$i) { $text=$name.'-'.$i; $hash=hash('sha256',$text); $members[]=['id'=>V4Format::contentId(strlen($text),$hash),'size'=>strlen($text),'sha256'=>$hash]; }
        return ['object'=>['key'=>'packs/'.V4Format::scopeId($this->scope()).'/'.hash('sha256',$name).'.age','bytes'=>200,'sha256'=>hash('sha256','cipher-'.$name)],'lane'=>'other','members'=>$members];
    }
}

final class V4IndexCacheRepository
{
    public array $objects=[]; public array $verified=[];
    public function listPackObjects(string $scope): iterable { return []; }
    public function verify(array $receipt): void { $this->verified[]=$receipt; if (($this->objects[$receipt['key']]??null)!==$receipt) throw new \RuntimeException('Missing synthetic ciphertext'); }
}
