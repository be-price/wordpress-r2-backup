<?php
declare(strict_types=1);
// Runs MysqlRowCursor against a real isolated server while interfering only with its net_write_timeout statements. Every
// other call, and every statement it does not change, reaches the real mysqli functions.
// Usage: php mysql-row-cursor-fallback.php <socket> <schema> <report mode> <interference>
//   Falls back:  set-false, set-throws (the setting is refused), select-false (the value cannot be read),
//                clamp (the server keeps 600 for any larger value), readback-false (the value read after raising is unreadable)
//   Must fail:   restore-fails, restore-wrong (after a clamp the previous value cannot be put back, or a different one is kept),
//                final-restore-fails, final-restore-wrong (the same after streaming), abandon-restore-fails (after an early stop)
namespace WordPressR2Backup {
    function mysqli_query(\mysqli $connection, string $sql, int $mode = MYSQLI_STORE_RESULT)
    {
        $GLOBALS['calls'][] = ['query', $sql, $mode];
        $mode === MYSQLI_USE_RESULT && $GLOBALS['streamedOnce'] = true;
        $kind = $GLOBALS['refusal']; $refuse = false;
        $setting = strpos($sql, 'SET SESSION net_write_timeout=') === 0 ? (int) substr($sql, strlen('SET SESSION net_write_timeout=')) : null;
        $reading = $sql === 'SELECT @@SESSION.net_write_timeout';
        if (in_array($kind, ['set-false', 'set-throws'], true)) { $refuse = $setting !== null; }
        elseif ($kind === 'select-false') { $refuse = $reading; }
        elseif ($kind === 'readback-false') { $refuse = $reading && $GLOBALS['justRaised']; }
        elseif ($setting !== null && $setting > 600 && in_array($kind, ['clamp', 'restore-fails', 'restore-wrong'], true)) { $sql = 'SET SESSION net_write_timeout=600'; ++$GLOBALS['refusals']; }
        elseif ($setting === 7 && ($kind === 'restore-fails' || ($kind === 'final-restore-fails' && $GLOBALS['streamedOnce']) || ($kind === 'abandon-restore-fails' && $GLOBALS['streamedOnce']))) { $refuse = true; }
        elseif ($setting === 7 && ($kind === 'restore-wrong' || ($kind === 'final-restore-wrong' && $GLOBALS['streamedOnce']))) { $sql = 'SET SESSION net_write_timeout=8'; ++$GLOBALS['refusals']; }
        $GLOBALS['justRaised'] = $setting !== null && $setting >= 28800;
        if ($refuse) {
            ++$GLOBALS['refusals'];
            if ($kind === 'set-throws') { throw new \mysqli_sql_exception('Synthetic refusal of the session setting', 1227); }
            return false;
        }
        return \mysqli_query($connection, $sql, $mode);
    }

    function mysqli_prepare(\mysqli $connection, string $sql)
    {
        $GLOBALS['calls'][] = ['prepare', $sql, 0];
        return \mysqli_prepare($connection, $sql);
    }
}

namespace {
    require dirname(__DIR__, 2) . '/vendor/autoload.php';
    use WordPressR2Backup\MysqlRowCursor;

    [, $socket, $schema, $report, $refusal] = $argv;
    $GLOBALS['calls'] = []; $GLOBALS['refusal'] = $refusal; $GLOBALS['refusals'] = 0; $GLOBALS['streamedOnce'] = false; $GLOBALS['justRaised'] = false;
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $connection = new mysqli('localhost', 'root', '', $schema, 0, $socket);
    \mysqli_query($connection, 'SET SESSION net_write_timeout=7');
    mysqli_report((int) $report);
    $encode = static function (array $rows): array {
        return array_map(static function (array $row): array {
            return array_map(static function ($value) { return $value === null ? null : base64_encode($value); }, $row);
        }, $rows);
    };
    $outcome = ['refusal' => $refusal];
    $streamedCount = static fn (): int => count(array_filter($GLOBALS['calls'], static fn (array $call): bool => $call[0] === 'query' && $call[2] === MYSQLI_USE_RESULT));
    if (in_array($refusal, ['restore-fails', 'restore-wrong', 'final-restore-fails', 'final-restore-wrong', 'abandon-restore-fails'], true)) {
        // A read whose previous session value cannot be put back must fail, never end quietly.
        $seen = 0; $outcome['result'] = 'completed';
        try {
            if ($refusal === 'abandon-restore-fails') {
                $cursor = MysqlRowCursor::rows($connection, 'SELECT * FROM kinds ORDER BY id');
                $cursor->current(); $seen = 1; unset($cursor);
            } else {
                foreach (MysqlRowCursor::rows($connection, 'SELECT * FROM kinds ORDER BY id') as $row) { ++$seen; }
            }
        } catch (RuntimeException $error) { $outcome['result'] = $error->getMessage(); }
        $outcome['seen'] = $seen; $outcome['streamed'] = $streamedCount();
        echo json_encode($outcome, JSON_THROW_ON_ERROR), "\n";
        exit(0);
    }
    // 1. Every row, with the server cursor's own value types.
    $outcome['rows'] = $encode(iterator_to_array(MysqlRowCursor::rows($connection, 'SELECT * FROM kinds ORDER BY id'), false));
    $outcome['streamed'] = $streamedCount();
    $outcome['prepared'] = count(array_filter($GLOBALS['calls'], static fn (array $call): bool => $call[0] === 'prepare' && $call[1] === 'SELECT * FROM kinds ORDER BY id'));
    $outcome['refusals'] = $GLOBALS['refusals'];
    $outcome['sessionAfter'] = \mysqli_query($connection, 'SELECT @@SESSION.net_write_timeout')->fetch_row()[0];
    // 2. A failing statement fails without naming it, and the connection stays usable.
    try { iterator_to_array(MysqlRowCursor::rows($connection, 'SELECT secret_missing_field FROM secret_missing_table')); $outcome['invalid'] = 'completed'; }
    catch (RuntimeException $error) { $outcome['invalid'] = $error->getMessage(); }
    $outcome['usable'] = \mysqli_query($connection, 'SELECT 1')->fetch_row()[0];
    // 3. A connection lost between rows ends the fallback with an exception, never as a complete read.
    $id = mysqli_thread_id($connection); $read = 0;
    try {
        foreach (MysqlRowCursor::rows($connection, 'SELECT id FROM many ORDER BY id') as $row) {
            if (++$read === 1) {
                mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
                $other = new mysqli('localhost', 'root', '', $schema, 0, $socket); \mysqli_query($other, 'KILL ' . $id); $other->close();
                mysqli_report((int) $report); usleep(200000);
            }
        }
        $outcome['killed'] = 'completed';
    } catch (RuntimeException $error) { $outcome['killed'] = $error->getMessage(); }
    $outcome['killedRows'] = $read;
    echo json_encode($outcome, JSON_THROW_ON_ERROR), "\n";
}
