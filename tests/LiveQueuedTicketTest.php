<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\LiveBackup;

final class LiveQueuedTicketTest extends TestCase
{
    public function testV2TicketBindsModeAndSettingsWhileV1OnlyResumesUnboundLegacyJob(): void
    {
        $class=new \ReflectionClass(LiveBackup::class); $backup=$class->newInstanceWithoutConstructor();
        $verify=$class->getMethod('assertQueuedTicket'); $verify->setAccessible(true);
        $state=['status'=>'queued','consistencyMode'=>'maintenance','settingsDigest'=>str_repeat('a',64),'sharingDigest'=>str_repeat('b',64)];
        $ticket=['version'=>2,'action'=>'start','mode'=>'maintenance','settingsDigest'=>$state['settingsDigest'],'sharingDigest'=>$state['sharingDigest']];
        $verify->invoke($backup,$state,$ticket); self::assertTrue(true);
        $legacy=['status'=>'queued','resumeStatus'=>'uploading'];
        $verify->invoke($backup,$legacy,['version'=>1]);
        $verify->invoke($backup,$legacy,['version'=>2,'action'=>'resume','mode'=>'maintenance','settingsDigest'=>null,'sharingDigest'=>null]);
        foreach ([[$state,['version'=>1]],[$state,array_merge($ticket,['mode'=>'online'])],[$state,array_merge($ticket,['settingsDigest'=>str_repeat('c',64)])],
            [$legacy,['version'=>2,'action'=>'start','mode'=>'maintenance','settingsDigest'=>null,'sharingDigest'=>null]],
            [array_merge($state,['resumeStatus'=>'uploading']),['version'=>1]],
            [array_merge($legacy,['settingsDigest'=>null]),['version'=>2,'action'=>'resume','mode'=>'maintenance','settingsDigest'=>null,'sharingDigest'=>null]],
            [['status'=>'queued'],['version'=>1]]] as $pair) {
            $error=null; try { $verify->invoke($backup,$pair[0],$pair[1]); } catch(\RuntimeException $caught) { $error=$caught; }
            self::assertInstanceOf(\RuntimeException::class,$error);
        }
    }
}
