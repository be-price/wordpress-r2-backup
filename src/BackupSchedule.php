<?php
declare(strict_types=1);
namespace WordPressR2Backup;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Calendar calculation only; this class cannot start a backup. */
final class BackupSchedule
{
    /** Return the next occurrence strictly after $after, in UTC. Missing local DST times are skipped. */
    public static function next(string $frequency, string $time, ?int $weekday, DateTimeZone $zone, DateTimeImmutable $after): DateTimeImmutable
    {
        if (!in_array($frequency, ['hourly', 'daily', 'weekly'], true) || !preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/D', $time)
            || ($frequency === 'weekly' && ($weekday === null || $weekday < 1 || $weekday > 7))) {
            throw new InvalidArgumentException('定期実行の頻度・時刻・曜日が不正です。');
        }
        if ($frequency === 'hourly') {
            // Walk UTC minutes so repeated and skipped local DST hours are represented correctly.
            $timestamp = intdiv($after->getTimestamp(), 60) * 60 + 60;
            $minute = substr($time, 3, 2);
            for ($i = 0; $i < 181; ++$i, $timestamp += 60) {
                $candidate = (new DateTimeImmutable('@' . $timestamp))->setTimezone($zone);
                if ($candidate->format('i') === $minute) { return $candidate->setTimezone(new DateTimeZone('UTC')); }
            }
            throw new InvalidArgumentException('次の実行時刻を計算できません。');
        }
        $local = $after->setTimezone($zone);
        $date = $local->setTime(12, 0);
        for ($i = 0; $i < 370; ++$i, $date = $date->modify('+1 day')) {
            if ($frequency === 'weekly' && (int) $date->format('N') !== $weekday) { continue; }
            $day = $date->format('Y-m-d');
            $candidate = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $day . ' ' . $time, $zone);
            if ($candidate === false || $candidate->format('Y-m-d H:i') !== $day . ' ' . $time || $candidate <= $after) { continue; }
            return $candidate->setTimezone(new DateTimeZone('UTC'));
        }
        throw new InvalidArgumentException('次の実行時刻を計算できません。');
    }
}
