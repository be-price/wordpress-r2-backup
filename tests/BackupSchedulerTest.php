<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use WordPressR2Backup\BackupScheduler;

final class BackupSchedulerTest extends TestCase
{
    public function testHourlyLongGapShowsLatestMissedAndNeverReplays(): void
    {
        $config = $this->config(); $config['frequency'] = 'hourly'; $config['time'] = '00:00'; $config['effectiveSince'] = '2026-01-01T00:00:00Z';
        $zone = new DateTimeZone('Asia/Tokyo'); $now = new DateTimeImmutable('2026-11-10T18:05:00Z');
        self::assertSame('2026-11-10T18:00:00Z', BackupScheduler::latestMissed($config, [], $zone, $now));
        self::assertNull(BackupScheduler::due($config, null, $zone, $now));
        self::assertSame('2026-11-10T19:00:00Z', BackupScheduler::due($config, null, $zone, new DateTimeImmutable('2026-11-10T19:00:10Z')));
    }
    private function config(): array
    {
        return ['enabled' => true, 'frequency' => 'daily', 'time' => '03:00', 'weekday' => null,
            'mode' => 'maintenance', 'revision' => str_repeat('a', 64), 'effectiveSince' => '2026-11-09T00:00:00Z'];
    }
    public function testDueOnceWithinWindow(): void
    {
        $zone = new DateTimeZone('Asia/Tokyo'); $now = new DateTimeImmutable('2026-11-10T18:00:30Z');
        $slot = BackupScheduler::due($this->config(), null, $zone, $now);
        self::assertSame('2026-11-10T18:00:00Z', $slot);
        self::assertNull(BackupScheduler::due($this->config(), $slot, $zone, $now));
    }
    public function testMissedAndNewlyEnabledSchedulesDoNotReplay(): void
    {
        $zone = new DateTimeZone('Asia/Tokyo');
        self::assertNull(BackupScheduler::due($this->config(), null, $zone, new DateTimeImmutable('2026-11-10T18:03:00Z')));
        $config = $this->config(); $config['effectiveSince'] = '2026-11-10T18:00:10Z';
        self::assertNull(BackupScheduler::due($config, null, $zone, new DateTimeImmutable('2026-11-10T18:00:30Z')));
    }
    public function testDisabledNeverRunsAndInvalidConfigRejected(): void
    {
        $zone = new DateTimeZone('UTC'); $now = new DateTimeImmutable('2026-11-10T03:00:30Z');
        $config = $this->config(); $config['enabled'] = false;
        self::assertNull(BackupScheduler::due($config, null, $zone, $now));
        $config['enabled'] = true; $config['mode'] = 'bad';
        $this->expectException(\InvalidArgumentException::class);
        BackupScheduler::due($config, null, $zone, $now);
    }

    public function testMissedSlotIsRecordedWithoutReplayingOrRepeating(): void
    {
        $zone=new DateTimeZone('Asia/Tokyo'); $now=new DateTimeImmutable('2026-11-10T18:05:00Z'); $config=$this->config();
        $state=['lastSeen'=>'2026-11-10T17:59:00Z','observedRevision'=>$config['revision']];
        self::assertSame('2026-11-10T18:00:00Z',BackupScheduler::latestMissed($config,$state,$zone,$now));
        $state['lastMissedSlot']='2026-11-10T18:00:00Z';
        self::assertNull(BackupScheduler::latestMissed($config,$state,$zone,$now));
        unset($state['lastMissedSlot']); $state['lastAttempt']='2026-11-10T18:00:00Z';
        self::assertNull(BackupScheduler::latestMissed($config,$state,$zone,$now));
    }

    public function testFirstTickAndChangedSettingsNeverReportBeforeActivation(): void
    {
        $zone=new DateTimeZone('Asia/Tokyo'); $now=new DateTimeImmutable('2026-11-10T18:05:00Z'); $config=$this->config();
        $config['effectiveSince']='2026-11-10T18:01:00Z';
        self::assertNull(BackupScheduler::latestMissed($config,[],$zone,$now));
        $state=['lastSeen'=>'2026-11-08T00:00:00Z','observedRevision'=>str_repeat('b',64)];
        self::assertNull(BackupScheduler::latestMissed($config,$state,$zone,$now));
    }

    public function testMissingDstWallTimeIsNotAnOccurrence(): void
    {
        $config=$this->config(); $config['time']='02:30'; $config['effectiveSince']='2026-03-08T05:00:00Z';
        self::assertNull(BackupScheduler::latestMissed($config,[],new DateTimeZone('America/New_York'),new DateTimeImmutable('2026-03-08T08:00:00Z')));
    }

    public function testWeeklyLongGapShowsOnlyLatestAndDisabledShowsNone(): void
    {
        $config=$this->config(); $config['frequency']='weekly'; $config['weekday']=1; $config['effectiveSince']='2026-11-01T00:00:00Z';
        $now=new DateTimeImmutable('2026-11-24T00:00:00Z'); $zone=new DateTimeZone('Asia/Tokyo');
        self::assertSame('2026-11-22T18:00:00Z',BackupScheduler::latestMissed($config,[],$zone,$now));
        $config['enabled']=false; self::assertNull(BackupScheduler::latestMissed($config,[],$zone,$now));
    }

    public function testRepeatedDstWallTimeIsOnlyOneOccurrence(): void
    {
        $config=$this->config(); $config['time']='01:30'; $config['effectiveSince']='2026-11-01T04:00:00Z';
        $zone=new DateTimeZone('America/New_York'); $now=new DateTimeImmutable('2026-11-01T08:00:00Z');
        $slot=BackupScheduler::latestMissed($config,[],$zone,$now);
        self::assertSame('2026-11-01T05:30:00Z',$slot);
        self::assertNull(BackupScheduler::latestMissed($config,['lastMissedSlot'=>$slot],$zone,$now));
    }
}
