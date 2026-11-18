<?php
declare(strict_types=1);
namespace WordPressR2Backup;

use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Ordered calendar rules. The first matching rule decides whether a slot may start. */
final class ScheduleRules
{
    public const LIMIT = 20;

    public static function validate($input): array
    {
        if (!is_array($input) || count($input) > self::LIMIT) {
            throw new InvalidArgumentException('スケジュールルールは20件までです。');
        }
        $rules = [];
        $basic = 0;
        foreach ($input as $rule) {
            if (!is_array($rule)) { throw new InvalidArgumentException('定期実行ルールが不正です。'); }
            $type = $rule['type'] ?? null;
            if ($type === 'basic') {
                ++$basic;
                $enabled = $rule['enabled'] ?? '1';
                if (!in_array($enabled, ['0', '1'], true)) { throw new InvalidArgumentException('基本ルールの有効状態が不正です。'); }
                $rules[] = ['type' => $type, 'enabled' => $enabled];
            } elseif ($type === 'cron') {
                $expression = $rule['cron'] ?? null;
                $term = '(?:\*|[0-9]{1,2}(?:-[0-9]{1,2})?)(?:\/[1-9][0-9]?)?';
                $field = $term . '(?:,' . $term . ')*';
                if (!is_string($expression) || strlen($expression) > 128 || !preg_match('/\A' . $field . '(?: +' . $field . '){4}\z/D', trim($expression))) {
                    throw new InvalidArgumentException('cron式は分・時・日・月・曜日の5項目で入力してください。間隔は正の整数で指定します。');
                }
                $expression = implode(' ', preg_split('/ +/', trim($expression)));
                try { new CronExpression($expression); }
                catch (InvalidArgumentException $error) {
                    throw new InvalidArgumentException('cron式の値または範囲を確認してください。', 0, $error);
                }
                $rules[] = ['type' => $type, 'cron' => $expression];
            } elseif ($type === 'exclude') {
                $day = $rule['day'] ?? null;
                $from = $rule['from'] ?? null;
                $to = $rule['to'] ?? null;
                if (!is_string($day) || !preg_match('/\A[0-7]\z/D', $day)
                    || !is_string($from) || !is_string($to) || $from === $to
                    || !preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/D', $from)
                    || !preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/D', $to)) {
                    throw new InvalidArgumentException('除外の曜日と、異なる開始・終了時刻を指定してください。');
                }
                $rules[] = ['type' => $type, 'day' => $day, 'from' => $from, 'to' => $to];
            } else {
                throw new InvalidArgumentException('定期実行ルールの種類が不正です。');
            }
        }
        if ($basic !== 1) { throw new InvalidArgumentException('基本の実行ルールが1件必要です。'); }
        return $rules;
    }

    public static function next(array $config, DateTimeZone $zone, DateTimeImmutable $after, ?DateTimeImmutable $until = null): ?DateTimeImmutable
    {
        $rules = self::validate($config['rules']);
        $until = $until ?? $after->modify('+370 days');
        $earliest = null;
        $exclusions = [];
        foreach ($rules as $rule) {
            if ($rule['type'] === 'exclude') { $exclusions[] = $rule; continue; }
            if ($rule['type'] === 'basic' && $rule['enabled'] === '0') { continue; }
            $cron = $rule['type'] === 'cron' ? new CronExpression($rule['cron']) : null;
            $cursor = $after;
            while ($cursor < ($earliest ?? $until)) {
                try {
                    $slot = $cron === null
                        ? BackupSchedule::next($config['frequency'], $config['time'], $config['weekday'], $zone, $cursor)
                        : DateTimeImmutable::createFromMutable($cron->getNextRunDate($cursor, 0, false, $zone->getName()));
                } catch (\RuntimeException $error) {
                    // The cron library reports impossible dates after its bounded search.
                    if ($cron === null || $error->getMessage() !== 'Impossible CRON expression') { throw $error; }
                    break;
                }
                if ($slot <= $cursor) { throw new \RuntimeException('次の実行時刻を計算できません。'); }
                if ($slot > $until || ($earliest !== null && $slot >= $earliest)) { break; }
                $local = $slot->setTimezone($zone);
                $excludedUntil = null;
                foreach ($exclusions as $exclusion) {
                    $end = self::endOfExclusion($local, $exclusion);
                    if ($end !== null && ($excludedUntil === null || $end > $excludedUntil)) { $excludedUntil = $end; }
                }
                if ($excludedUntil === null) {
                    $earliest = $slot;
                    break;
                }
                // Skip the excluded interval for this rule. Higher-priority run rules are evaluated independently.
                $cursor = $excludedUntil->modify('-1 minute');
                if ($cursor < $slot) { $cursor = $slot; }
            }
        }
        return $earliest === null ? null : $earliest->setTimezone(new DateTimeZone('UTC'));
    }

    private static function endOfExclusion(DateTimeImmutable $local, array $rule): ?DateTimeImmutable
    {
        $time = $local->format('H:i');
        $overnight = $rule['from'] > $rule['to'];
        $startDay = $local;
        $endDay = $local;
        if ($overnight && $time < $rule['to']) { $startDay = $local->modify('-1 day'); }
        elseif ($overnight && $time >= $rule['from']) { $endDay = $local->modify('+1 day'); }
        elseif ($time < $rule['from'] || $time >= $rule['to']) { return null; }
        if ($rule['day'] !== '0' && $startDay->format('N') !== $rule['day']) { return null; }
        [$hour, $minute] = array_map('intval', explode(':', $rule['to']));
        $end = $endDay->setTime($hour, $minute);
        // Around a clock change, step one slot at a time to preserve local clock matching.
        $transitions = $local->getTimezone()->getTransitions($local->getTimestamp(), max($local->getTimestamp(), $end->getTimestamp()) + 1);
        return $transitions !== false && count($transitions) > 1 ? $local : $end;
    }
}
