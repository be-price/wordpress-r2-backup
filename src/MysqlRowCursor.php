<?php
declare(strict_types=1);

namespace WordPressR2Backup;

use Generator;
use RuntimeException;

/**
 * Fetch on demand, without losing the connection or its table locks while the consumer pauses for policy, R2 or
 * encryption work, and without holding more than one row in memory.
 */
final class MysqlRowCursor
{
    /**
     * The longest pause between two rows that the server tolerates while it waits to send. A consumer may stop for R2
     * reads with retries and encryption; this exceeds any such pause, and equals MySQL's default idle timeout.
     */
    public const PAUSE_SECONDS = 28800;

    /**
     * Streams one statement's rows in order, as strings or null. The rows arrive as an unbuffered result, so they need no
     * round trip each; while they stream, this session's net_write_timeout is raised to PAUSE_SECONDS so a paused consumer
     * keeps the connection and its locks, and the previous value is set back afterwards, also when the consumer stops early
     * or fails. Until the generator finishes or is destroyed the connection serves no other statement: a statement sent
     * meanwhile fails instead of running. The raised value is read back; where it cannot be read, set, or confirmed at least
     * PAUSE_SECONDS, the previous value is put back and confirmed and the read-only server cursor is used. A previous value
     * that cannot be put back and confirmed fails the read: before any row, or where the generator ends or is destroyed.
     */
    public static function rows(\mysqli $connection, string $sql): Generator
    {
        $previous = self::raiseWriteTimeout($connection);
        if ($previous === null) { yield from self::cursorRows($connection, $sql); return; }
        $result = null;
        try {
            $result = mysqli_query($connection, $sql, MYSQLI_USE_RESULT);
            if (!$result instanceof \mysqli_result) { throw new RuntimeException('Cannot open database row cursor.'); }
            while (($row = mysqli_fetch_assoc($result)) !== null) {
                if (!is_array($row)) { throw new RuntimeException('Cannot read database row cursor.'); }
                foreach ($row as $name => $value) {
                    if (is_int($value)) { $row[$name] = (string) $value; }
                    elseif ($value !== null && !is_string($value)) { throw new RuntimeException('Database row values must arrive as text.'); }
                }
                yield $row;
            }
            if (mysqli_errno($connection) !== 0) { throw new RuntimeException('The database row cursor ended with an error.'); }
        } catch (\mysqli_sql_exception $error) {
            throw new RuntimeException('Cannot read database row cursor.');
        } finally {
            // Freeing an unfinished unbuffered result reads its remaining rows, so the connection is usable again afterwards.
            if ($result instanceof \mysqli_result) { mysqli_free_result($result); }
            // Also when the consumer stopped early or another failure is on its way out (PHP keeps that one as the previous
            // exception): a session left with the raised value must never pass unnoticed.
            if (!self::setWriteTimeout($connection, $previous)) { throw new RuntimeException('Cannot restore the database session write timeout.'); }
        }
    }

    /**
     * Returns the previous session value once a value of at least PAUSE_SECONDS is confirmed by reading it back, or null
     * when the session holds its previous value again (confirmed) and the server cursor must be used.
     */
    private static function raiseWriteTimeout(\mysqli $connection): ?string
    {
        $previous = self::writeTimeout($connection);
        if ($previous === null) { return null; }
        $required = max(self::PAUSE_SECONDS, (int) $previous);
        try { mysqli_query($connection, 'SET SESSION net_write_timeout=' . $required); }
        catch (\mysqli_sql_exception $error) {}
        // A server may accept the statement yet keep a smaller value, so only the value read back counts.
        $actual = self::writeTimeout($connection);
        if ($actual !== null && (int) $actual >= $required) { return $previous; }
        if ($actual === $previous) { return null; }
        // Unreadable or changed to something else: the session may hold any value, so put the previous one back first.
        if (!self::setWriteTimeout($connection, $previous)) { throw new RuntimeException('Cannot restore the database session write timeout.'); }
        return null;
    }

    /** The session value as decimal digits, or null when it cannot be read. */
    private static function writeTimeout(\mysqli $connection): ?string
    {
        try {
            $current = mysqli_query($connection, 'SELECT @@SESSION.net_write_timeout');
            if (!$current instanceof \mysqli_result) { return null; }
            $value = mysqli_fetch_row($current); mysqli_free_result($current);
            $text = is_array($value) ? (string) ($value[0] ?? '') : '';
            return preg_match('/\A[1-9][0-9]{0,8}\z/D', $text) ? $text : null;
        } catch (\mysqli_sql_exception $error) {
            return null;
        }
    }

    /** Sets the session value and confirms it by reading it back. */
    private static function setWriteTimeout(\mysqli $connection, string $value): bool
    {
        try { if (mysqli_query($connection, 'SET SESSION net_write_timeout=' . $value) !== true) { return false; } }
        catch (\mysqli_sql_exception $error) { return false; }
        return self::writeTimeout($connection) === $value;
    }

    /** The read-only server cursor: one round trip per row, used only where the write timeout cannot be raised. */
    private static function cursorRows(\mysqli $connection, string $sql): Generator
    {
        $statement = null;
        try {
            $statement = mysqli_prepare($connection, $sql);
            if ($statement === false
                || !mysqli_stmt_attr_set($statement, MYSQLI_STMT_ATTR_CURSOR_TYPE, MYSQLI_CURSOR_TYPE_READ_ONLY)
                || !mysqli_stmt_execute($statement)) {
                throw new RuntimeException('Cannot open database row cursor.');
            }
            $metadata = mysqli_stmt_result_metadata($statement);
            if ($metadata === false) {
                throw new RuntimeException('Cannot inspect database row cursor.');
            }
            $names = array_map(static function (\stdClass $field): string { return $field->name; }, mysqli_fetch_fields($metadata));
            mysqli_free_result($metadata);
            $values = array_fill(0, count($names), null);
            $references = [];
            foreach ($values as &$value) { $references[] = &$value; }
            unset($value);
            if (!mysqli_stmt_bind_result($statement, ...$references)) {
                throw new RuntimeException('Cannot bind database row cursor.');
            }
            while (true) {
                $fetched = mysqli_stmt_fetch($statement);
                if ($fetched === null) { break; }
                if ($fetched !== true) {
                    throw new RuntimeException('Cannot read database row cursor.');
                }
                $row = [];
                foreach ($names as $index => $name) {
                    $row[$name] = $values[$index] === null ? null : (string) $values[$index];
                }
                yield $row;
            }
            if (mysqli_stmt_errno($statement) !== 0 || mysqli_errno($connection) !== 0) {
                throw new RuntimeException('The database row cursor ended with an error.');
            }
        } catch (\mysqli_sql_exception $error) {
            throw new RuntimeException('Cannot read database row cursor.');
        } finally {
            if ($statement instanceof \mysqli_stmt) { mysqli_stmt_close($statement); }
        }
    }
}
