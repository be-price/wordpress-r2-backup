<?php
declare(strict_types=1);

namespace WordPressR2Backup;

use InvalidArgumentException;

final class CaptureReport
{
    public static function fromRecord(array $record, array $plan): array
    {
        $database = $record['database'];
        $report = [
            'method' => 'synthetic-files-mysql-global-read-lock-v1',
            'startedAt' => $record['startedAt'], 'fixedAt' => $record['fixedAt'], 'sealedAt' => $record['sealedAt'],
            'file' => ['pausedAt' => $record['file']['pausedAt'], 'releasedAt' => $record['file']['releasedAt'],
                'inventorySha256' => self::fileDigest($plan['inventory'])],
            'database' => ['lockedAt' => $database['lockedAt'], 'releasedAt' => $database['releasedAt'],
                'serverVersion' => $database['serverVersion'], 'tables' => $database['tables'], 'definitions' => $database['definitions'],
                'inventorySha256' => $database['inventorySha256'], 'sqlHash' => $record['sqlHash'], 'sqlBytes' => $record['sqlBytes']],
        ];
        self::validate($report, $plan['inventory'], ['sha256' => $plan['sqlHash'], 'size' => $plan['sqlBytes']]);
        return $report;
    }

    public static function validate(array $report, array $files, array $database): void
    {
        self::fields($report, ['method', 'startedAt', 'fixedAt', 'sealedAt', 'file', 'database']);
        if ($report['method'] !== 'synthetic-files-mysql-global-read-lock-v1' || !is_array($report['file']) || !is_array($report['database'])) {
            throw new InvalidArgumentException('Invalid capture report method or evidence.');
        }
        self::fields($report['file'], ['pausedAt', 'releasedAt', 'inventorySha256']);
        $facts = $report['database'];
        self::fields($facts, ['lockedAt', 'releasedAt', 'serverVersion', 'tables', 'definitions', 'inventorySha256', 'sqlHash', 'sqlBytes']);
        self::hex($report['file']['inventorySha256']);
        self::hex($facts['inventorySha256']);
        self::hex($facts['sqlHash']);
        if (!is_string($facts['serverVersion']) || !preg_match('/\A8\.0\.[0-9]+(?:[-.][A-Za-z0-9.-]+)?\z/D', $facts['serverVersion']) || !is_int($facts['sqlBytes'])
            || $facts['sqlBytes'] < 0 || $facts['sqlBytes'] > Manifest::MAX_RESTORED_BYTES
            || $facts['sqlHash'] !== ($database['sha256'] ?? null) || $facts['sqlBytes'] !== ($database['size'] ?? null)) {
            throw new InvalidArgumentException('Capture report SQL evidence differs from the database.');
        }
        self::databaseFacts($facts);
        if (!hash_equals($report['file']['inventorySha256'], self::fileDigest($files))) {
            throw new InvalidArgumentException('Capture report file inventory differs.');
        }
        $previous = null;
        foreach ([$report['startedAt'], $report['file']['pausedAt'], $facts['lockedAt'], $report['fixedAt'],
            $facts['releasedAt'], $report['file']['releasedAt'], $report['sealedAt']] as $time) {
            if (!is_string($time) || !preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{6}Z$/D', $time)
                || ($parsed = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $time, new \DateTimeZone('UTC'))) === false
                || $parsed->format('Y-m-d\TH:i:s.u\Z') !== $time || ($previous !== null && $parsed < $previous)) {
                throw new InvalidArgumentException('Invalid capture report time order.');
            }
            $previous = $parsed;
        }
    }

    private static function fileDigest(array $files): string
    {
        if (!array_is_list($files) || count($files) > Manifest::MAX_ENTRIES) {
            throw new InvalidArgumentException('Invalid capture report file inventory.');
        }
        $inventory = [];
        foreach ($files as $file) {
            if (!is_array($file) || !is_string($file['path'] ?? null)) {
                throw new InvalidArgumentException('Invalid capture report file entry.');
            }
            if (($file['type'] ?? null) === 'directory') {
                $inventory[] = ['path' => $file['path'], 'type' => 'directory'];
            } elseif (($file['type'] ?? null) === 'file' && is_int($file['size'] ?? null)
                && $file['size'] >= 0 && $file['size'] <= Manifest::MAX_RESTORED_BYTES) {
                self::hex($file['sha256'] ?? null);
                $inventory[] = ['path' => $file['path'], 'type' => 'file', 'size' => $file['size'], 'sha256' => $file['sha256']];
            } else {
                throw new InvalidArgumentException('Invalid capture report file entry.');
            }
        }
        FileSafety::validateEntries($inventory);
        usort($inventory, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));
        return self::digest($inventory);
    }

    private static function databaseFacts(array $facts): void
    {
        if (!is_array($facts['tables']) || !array_is_list($facts['tables']) || !is_array($facts['definitions'])
            || array_keys($facts['definitions']) !== ['views', 'triggers', 'routines', 'events']) {
            throw new InvalidArgumentException('Invalid capture report database inventory.');
        }
        foreach ($facts['tables'] as $table) {
            if (!is_array($table) || array_keys($table) !== ['name', 'type', 'engine'] || !is_string($table['name']) || $table['name'] === ''
                || !in_array($table['type'], ['BASE TABLE', 'VIEW'], true)
                || ($table['type'] === 'BASE TABLE' && !in_array($table['engine'], ['InnoDB', 'MyISAM', 'MEMORY'], true))
                || ($table['type'] === 'VIEW' && $table['engine'] !== null)) {
                throw new InvalidArgumentException('Invalid capture report table evidence.');
            }
        }
        foreach ($facts['definitions'] as $kind => $definitions) {
            if (!is_array($definitions) || !array_is_list($definitions)) {
                throw new InvalidArgumentException('Invalid capture report definition evidence.');
            }
            foreach ($definitions as $definition) {
                if (!is_array($definition) || array_keys($definition) !== ($kind === 'routines' ? ['name', 'type', 'sha256'] : ['name', 'sha256'])
                    || !is_string($definition['name']) || $definition['name'] === ''
                    || ($kind === 'routines' && !in_array($definition['type'], ['PROCEDURE', 'FUNCTION'], true))) {
                    throw new InvalidArgumentException('Invalid capture report definition evidence.');
                }
                self::hex($definition['sha256']);
            }
            $ordered = $definitions;
            usort($ordered, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']) ?: strcmp($a['type'] ?? '', $b['type'] ?? ''));
            if ($ordered !== $definitions) {
                throw new InvalidArgumentException('Unordered capture report database definitions.');
            }
        }
        $tables = $facts['tables'];
        usort($tables, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        if ($tables !== $facts['tables'] || count(array_unique(array_column($tables, 'name'))) !== count($tables)
            || !hash_equals($facts['inventorySha256'], self::digest(['tables' => $tables, 'definitions' => $facts['definitions']]))) {
            throw new InvalidArgumentException('Capture report database inventory differs.');
        }
    }

    private static function fields(array $value, array $expected): void
    {
        $actual = array_keys($value); sort($actual); sort($expected);
        if ($actual !== $expected) { throw new InvalidArgumentException('Unexpected or missing capture report fields.'); }
    }

    private static function hex($value): void
    {
        if (!is_string($value) || !preg_match('/^[a-f0-9]{64}$/D', $value)) {
            throw new InvalidArgumentException('Invalid capture report digest.');
        }
    }

    private static function digest(array $value): string
    {
        try { return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); }
        catch (\JsonException $error) { throw new InvalidArgumentException('Capture report cannot be encoded.', 0, $error); }
    }
}
