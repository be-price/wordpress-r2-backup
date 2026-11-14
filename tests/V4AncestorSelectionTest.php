<?php
declare(strict_types=1);

namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use WordPressR2Backup\AgeProcess;
use WordPressR2Backup\Manifest;
use WordPressR2Backup\PrivacyPolicy;
use WordPressR2Backup\SourceRoots;
use WordPressR2Backup\V4Capture;
use WordPressR2Backup\V4Format;

require_once __DIR__ . '/WordPressDatabaseTest.php';

/** Captures and inspects synthetic page records; no recovery or restore is performed. */
final class V4AncestorSelectionTest extends TestCase
{
    private array $keys;
    private AgeProcess $age;

    protected function setUp(): void { $this->keys=Fixture::keys(); $this->age=new AgeProcess(Fixture::runtime()['age']); }
    protected function tearDown(): void { Fixture::cleanup(); }

    public function testSelectedAncestorsAndExcludedParentAcrossRootsKeepExactSelection(): void
    {
        $root=Fixture::temp(); $extra=Fixture::temp();
        foreach (['keep/a','keep/b','private/chosen/one','private/chosen/two','sibling/file'] as $path) { Fixture::write($root,$path,'synthetic'); }
        Fixture::write($root,'private/drop','excluded');
        foreach (['nest/one','nest/two'] as $path) { Fixture::write($extra,$path,'synthetic'); }
        $rules=['version'=>2,'preset'=>'custom','paths'=>['private'=>'exclude','private/chosen'=>'include']];
        $capture=new V4Capture(new SourceRoots($root,[$extra]),new V4DatabaseWpdbFake(),new PrivacyPolicy($rules),Fixture::temp());
        $result=$capture->capture($this->age,$this->recipients(),str_repeat('b',64),$this->scope());
        self::assertSame(7,$result['manifest']['counts']['files']);
        self::assertSame(5,$result['manifest']['counts']['directories']);
        self::assertSame(0,$result['manifest']['counts']['links']);
        $selected=[];
        foreach ($this->filesystemRecords($result) as $record) {
            if (isset($record['path'])) { $selected[]=$record['rootId'].'/'.$record['path']; }
        }
        sort($selected,SORT_STRING);
        $expected=['r0001/keep','r0001/keep/a','r0001/keep/b','r0001/private','r0001/private/chosen',
            'r0001/private/chosen/one','r0001/private/chosen/two','r0001/sibling','r0001/sibling/file',
            'r0002/nest','r0002/nest/one','r0002/nest/two'];
        sort($expected,SORT_STRING); self::assertSame($expected,$selected);
    }

    public function testExcludedLinkTargetStillFailsAfterAncestorRegistration(): void
    {
        $root=Fixture::temp(); Fixture::write($root,'secret.bin','secret');
        Fixture::write($root,'private/keep/one','one');
        self::assertTrue(symlink('../../secret.bin',$root.'/private/keep/link'));
        $rules=['version'=>2,'preset'=>'custom','paths'=>['private'=>'exclude','private/keep'=>'include',
            'private/keep/link'=>'include','secret.bin'=>'exclude']];
        $policy=new PrivacyPolicy($rules);
        self::assertSame('include',$policy->pathAction('private/keep/link','r0001/private/keep/link')['action']);
        self::assertSame('exclude',$policy->pathAction('secret.bin','r0001/secret.bin')['action']);
        $capture=new V4Capture(new SourceRoots($root),new V4DatabaseWpdbFake(),$policy,Fixture::temp());
        $error=null;
        try { $capture->capture($this->age,$this->recipients(),str_repeat('b',64),$this->scope()); } catch (RuntimeException $caught) { $error=$caught; }
        self::assertInstanceOf(RuntimeException::class,$error); self::assertStringContainsString('excluded',$error->getMessage());
    }

    public function testSelectedChildDisappearanceAndModificationStillFail(): void
    {
        foreach (['disappear','modify'] as $case) {
            $root=Fixture::temp(); Fixture::write($root,'private/keep/one','original'); Fixture::write($root,'private/keep/two','original');
            $rules=['version'=>2,'preset'=>'custom','paths'=>['private'=>'exclude','private/keep'=>'include']];
            $capture=new V4Capture(new SourceRoots($root),new V4DatabaseWpdbFake(),new PrivacyPolicy($rules),Fixture::temp());
            $changed=false; $error=null;
            try {
                $capture->capture($this->age,$this->recipients(),str_repeat('b',64),$this->scope(),static function(array $event) use ($root,$case,&$changed): void {
                    if ($changed || ($event['phase'] ?? '')!=='scan' || !isset($event['selected'])) { return; }
                    $changed=true;
                    if ($case==='disappear') { unlink($root.'/private/keep/one'); }
                    else { file_put_contents($root.'/private/keep/one','modified'); }
                });
            } catch (RuntimeException $caught) { $error=$caught; }
            self::assertTrue($changed); self::assertInstanceOf(RuntimeException::class,$error,'Capture must reject '.$case);
        }
    }

    private function recipients(): array { return array_column($this->keys,'public'); }
    private function scope(): array
    {
        return ['accountId'=>str_repeat('a',32),'bucket'=>'synthetic-capture','unitId'=>str_repeat('c',64),
            'keySetId'=>Manifest::keySetId($this->recipients()),'encoding'=>V4Format::ENCODING];
    }

    private function filesystemRecords(array $result): array
    {
        $objects=[]; foreach ($result['objects'] as $object) { $objects[$object['object']['key']]=$object['cipherFile']; }
        $records=[];
        foreach ($result['manifest']['pages'] as $descriptor) {
            if ($descriptor['kind']!=='filesystem') { continue; }
            $plain=Fixture::temp().'/page'; $identity=fopen($this->keys[0]['identity'],'rb');
            try { $this->age->decrypt($objects[$descriptor['object']['key']],$plain,$identity); } finally { fclose($identity); }
            $page=json_decode(file_get_contents($plain),true,512,JSON_THROW_ON_ERROR);
            V4Format::validatePage($page,$descriptor);
            foreach ($page['records'] as $record) { $records[]=$record; }
        }
        return $records;
    }
}
