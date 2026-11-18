<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use WordPressR2Backup\BackupScheduler;
use WordPressR2Backup\ScheduleRules;

final class ScheduleRulesTest extends TestCase
{
    public function testPriorityAffectsBothNextAndDueAcrossMidnight(): void
    {
        $zone = new DateTimeZone('Asia/Tokyo');
        $start = new DateTimeImmutable('2026-11-18 01:55', $zone);
        $config = [
            'enabled' => true, 'mode' => 'online', 'frequency' => 'hourly', 'time' => '00:15', 'weekday' => null,
            'revision' => str_repeat('a', 64), 'effectiveSince' => '2026-11-17T00:00:00Z',
            'rules' => [
                ['type' => 'exclude', 'day' => '2', 'from' => '23:00', 'to' => '03:15'],
                ['type' => 'cron', 'cron' => '15 2 * * 3'],
                ['type' => 'basic'],
            ],
        ];
        $now = $start->modify('+20 minutes');
        self::assertNull(BackupScheduler::due($config, null, $zone, $now));
        self::assertSame('03:15', ScheduleRules::next($config, $zone, $start)->setTimezone($zone)->format('H:i'));
        self::assertNull(BackupScheduler::latestMissed($config, ['lastAttempt' => '2026-11-17T16:15:00Z'], $zone, $now->modify('+5 minutes')));

        [$config['rules'][0], $config['rules'][1]] = [$config['rules'][1], $config['rules'][0]];
        self::assertSame('2026-11-17T17:15:00Z', BackupScheduler::due($config, null, $zone, $now));
        self::assertNull(BackupScheduler::due($config, '2026-11-17T17:15:00Z', $zone, $now));
        self::assertSame('2026-11-17T17:15:00Z', BackupScheduler::latestMissed($config, [], $zone, $now->modify('+5 minutes')));
    }

    public function testCronOnlySchedule(): void
    {
        $zone = new DateTimeZone('Asia/Tokyo');
        $start = new DateTimeImmutable('2026-11-18 02:00', $zone);
        $config = ['rules' => [
            ['type' => 'basic', 'enabled' => '0'],
            ['type' => 'cron', 'cron' => '*/20 3 * * 1-5'],
        ]];
        self::assertSame('03:00', ScheduleRules::next($config, $zone, $start)->setTimezone($zone)->format('H:i'));
    }

    /** @dataProvider invalidCronExpressions */
    public function testInvalidCronExpressionIsRejected(string $expression): void
    {
        $this->expectException(InvalidArgumentException::class);
        ScheduleRules::validate([['type' => 'basic'], ['type' => 'cron', 'cron' => $expression]]);
    }

    public static function invalidCronExpressions(): array
    {
        return [['60 2 * * *'], ['*/0 * * * *'], ['*/-1 * * * *'], ['0 3 * * * command']];
    }
}
