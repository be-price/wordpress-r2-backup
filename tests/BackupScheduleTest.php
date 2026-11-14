<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use WordPressR2Backup\BackupSchedule;

final class BackupScheduleTest extends TestCase
{
    public function testHourlyUsesLocalMinuteAndHandlesClockChanges(): void
    {
        self::assertSame('2026-11-10T18:30:00+00:00', BackupSchedule::next('hourly', '00:00', null, new DateTimeZone('Asia/Kolkata'), new DateTimeImmutable('2026-11-10T18:00:00Z'))->format('c'));
        self::assertSame('2026-11-01T06:00:00+00:00', BackupSchedule::next('hourly', '00:00', null, new DateTimeZone('America/New_York'), new DateTimeImmutable('2026-11-01T05:00:00Z'))->format('c'));
        self::assertSame('2026-03-08T07:15:00+00:00', BackupSchedule::next('hourly', '00:15', null, new DateTimeZone('America/New_York'), new DateTimeImmutable('2026-03-08T06:15:00Z'))->format('c'));
    }
    public function testDailyAndWeeklyUseSiteTime(): void
    {
        $tz = new DateTimeZone('Asia/Tokyo');
        $after = new DateTimeImmutable('2026-11-10T18:00:00Z');
        self::assertSame('2026-11-11T18:00:00+00:00', BackupSchedule::next('daily', '03:00', null, $tz, $after)->format('c'));
        self::assertSame('2026-11-15T18:00:00+00:00', BackupSchedule::next('weekly', '03:00', 1, $tz, $after)->format('c'));
    }

    public function testMissingDaylightSavingTimeIsSkipped(): void
    {
        $next = BackupSchedule::next('daily', '02:30', null, new DateTimeZone('America/New_York'), new DateTimeImmutable('2026-03-08T05:00:00Z'));
        self::assertSame('2026-03-09T06:30:00+00:00', $next->format('c'));
    }

    public function testInvalidScheduleIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BackupSchedule::next('weekly', '25:00', 9, new DateTimeZone('UTC'), new DateTimeImmutable());
    }
}
