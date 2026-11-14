<?php
declare(strict_types=1);
namespace WordPressR2Backup;

final class SyntheticCapture
{
    private const RECORD_LIMIT = 16777216;

    private MysqlClient $mysql;
    private ManagedCapture $managed;
    private string $privateStagingDirectory;

    public function __construct(MysqlClient $mysql, ManagedCapture $managed, string $privateStagingDirectory)
    {
        $this->mysql = $mysql;
        $this->managed = $managed;
        $this->privateStagingDirectory = $privateStagingDirectory;

        FileSafety::privateDirectory($privateStagingDirectory);
    }

    public function prepare(SyntheticFileSource $source, string $syntheticSchema, array $recipients): string
    {
        Manifest::keySetId($recipients);
        $startedAt = self::now();
        $directory = FileSafety::createPrivateDirectory($this->privateStagingDirectory . '/capture-' . bin2hex(random_bytes(16)));
        $root = FileSafety::createPrivateDirectory($directory . '/content');
        self::persist($root . '/.wrb-synthetic', 'wordpress-r2-backup-v1');
        $prior = [];
        try {
            $prior = $source->inventory();
            $this->synchronize($source->root(), $root, [], $prior);
        } catch (\RuntimeException $ignoredError) {
            self::verifyPrivateTree($root);
            $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($entries as $entry) {
                if ($entry->getPathname() === $root . '/.wrb-synthetic') { continue; }
                $removed = $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
                if (!$removed) { throw new \RuntimeException('Cannot discard incomplete preliminary copy.'); }
            }
            $prior = [];
        }
        $paused = $source->whilePaused(function () use ($source, $syntheticSchema, $directory, $root, $prior, $recipients): array {
            return $this->mysql->withGlobalReadLock($syntheticSchema, function (array $database) use ($source, $syntheticSchema, $directory, $root, $prior, $recipients): array {
                $inventory = $source->inventory();
                $this->synchronize($source->root(), $root, $prior, $inventory);
                $this->mysql->dump($syntheticSchema, $directory . '/database.sql');
                $plan = SnapshotWriter::sourcePlan($root, $directory . '/database.sql', $recipients, str_repeat('a', 64));
                if ($plan['inventory'] !== $inventory || $source->inventory() !== $inventory) { throw new \RuntimeException('Synthetic content changed while fixed.'); }
                self::verifyPrivateTree($root);
                foreach ($inventory as $entry) { if ($entry['type'] === 'file') { self::flushFile($root . '/' . $entry['path']); } }
                self::flushFile($directory . '/database.sql');
                if (SnapshotWriter::sourcePlan($root, $directory . '/database.sql', $recipients, str_repeat('a', 64)) !== $plan) { throw new \RuntimeException('Fixed content changed while persisting.'); }
                return ['inventory' => $inventory, 'sqlHash' => $plan['sqlHash'], 'sqlBytes' => $plan['sqlBytes'], 'fixedAt' => self::now()];
            });
        });
        $fixed = $paused['result']['result'];
        $record = ['version' => 1, 'startedAt' => $startedAt, 'sourceRoot' => $source->root(),
            'file' => $paused['file'], 'database' => $paused['result']['database'], 'fixedAt' => $fixed['fixedAt'],
            'inventory' => $fixed['inventory'], 'sqlHash' => $fixed['sqlHash'], 'sqlBytes' => $fixed['sqlBytes'], 'sealedAt' => self::now()];
        self::persist($directory . '/capture.json', json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        return $this->managed->prepareFrozen($directory, $recipients);
    }

    public static function verifiedInput(string $privateInputDirectory, array $recipients, string $unitId): array
    {
        $directory = FileSafety::privateDirectory($privateInputDirectory);
        $names = array_values(array_diff(scandir($directory), ['.', '..'])); sort($names, SORT_STRING);
        if ($names !== ['capture.json', 'content', 'database.sql']) { throw new \RuntimeException('Incomplete frozen capture input.'); }
        $recordFile = $directory . '/capture.json'; self::privateFile($recordFile);
        if (filesize($recordFile) > self::RECORD_LIMIT) { throw new \RuntimeException('Frozen capture record exceeds its limit.'); }
        $bytes = file_get_contents($recordFile);
        $record = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($record) || array_keys($record) !== ['version', 'startedAt', 'sourceRoot', 'file', 'database', 'fixedAt', 'inventory', 'sqlHash', 'sqlBytes', 'sealedAt']
            || $record['version'] !== 1 || !is_string($record['sourceRoot']) || $record['sourceRoot'] === $directory . '/content'
            || !is_array($record['inventory']) || !is_array($record['file']) || array_keys($record['file']) !== ['pausedAt', 'releasedAt']
            || !is_array($record['database'])) { throw new \RuntimeException('Invalid frozen capture record.'); }
        $database = $record['database'];
        $databaseKeys = array_keys($database); sort($databaseKeys, SORT_STRING);
        if ($databaseKeys !== ['definitions', 'inventorySha256', 'lockedAt', 'releasedAt', 'schema', 'serverVersion', 'tables', 'version']
            || $database['version'] !== 1 || !is_string($database['schema']) || !preg_match('/\Awrb_test_[a-z0-9_]{1,55}\z/D', $database['schema'])
            || !is_string($database['serverVersion']) || $database['serverVersion'] === '' || !is_array($database['tables']) || !array_is_list($database['tables']) || !is_array($database['definitions'])
            || array_keys($database['definitions']) !== ['views', 'triggers', 'routines', 'events']
            || !is_string($database['inventorySha256']) || !preg_match('/^[a-f0-9]{64}$/D', $database['inventorySha256'])) { throw new \RuntimeException('Invalid frozen database evidence.'); }
        foreach ($database['tables'] as $table) {
            if (!is_array($table) || array_keys($table) !== ['name', 'type', 'engine'] || !is_string($table['name']) || $table['name'] === ''
                || !in_array($table['type'], ['BASE TABLE', 'VIEW'], true)
                || ($table['type'] === 'BASE TABLE' && !in_array($table['engine'], ['InnoDB', 'MyISAM', 'MEMORY'], true))
                || ($table['type'] === 'VIEW' && $table['engine'] !== null)) { throw new \RuntimeException('Invalid frozen database table evidence.'); }
        }
        foreach ($database['definitions'] as $kind => $definitions) {
            if (!is_array($definitions) || !array_is_list($definitions)) { throw new \RuntimeException('Invalid frozen database definition evidence.'); }
            foreach ($definitions as $definition) {
                if (!is_array($definition) || array_keys($definition) !== ($kind === 'routines' ? ['name', 'type', 'sha256'] : ['name', 'sha256'])
                    || !is_string($definition['name']) || $definition['name'] === ''
                    || !is_string($definition['sha256']) || !preg_match('/^[a-f0-9]{64}$/D', $definition['sha256'])
                    || ($kind === 'routines' && !in_array($definition['type'], ['PROCEDURE', 'FUNCTION'], true))) { throw new \RuntimeException('Invalid frozen database definition evidence.'); }
            }
            $ordered = $definitions;
            usort($ordered, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']) ?: strcmp($a['type'] ?? '', $b['type'] ?? ''));
            if ($ordered !== $definitions) { throw new \RuntimeException('Unordered frozen database definitions.'); }
        }
        $tables = $database['tables']; usort($tables, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        if ($tables !== $database['tables'] || count(array_unique(array_column($tables, 'name'))) !== count($tables)
            || !hash_equals($database['inventorySha256'], hash('sha256', json_encode(['tables' => $tables, 'definitions' => $database['definitions']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)))) { throw new \RuntimeException('Frozen database inventory digest differs.'); }
        $previous = null;
        foreach ([$record['startedAt'], $record['file']['pausedAt'], $database['lockedAt'], $record['fixedAt'], $database['releasedAt'], $record['file']['releasedAt'], $record['sealedAt']] as $time) {
            if (!is_string($time) || !preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{6}Z$/D', $time)
                || ($parsed = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $time, new \DateTimeZone('UTC'))) === false
                || $parsed->format('Y-m-d\TH:i:s.u\Z') !== $time || ($previous !== null && $parsed < $previous)) { throw new \RuntimeException('Invalid frozen capture time order.'); }
            $previous = $parsed;
        }
        self::verifyPrivateTree($directory . '/content'); self::privateFile($directory . '/database.sql');
        $plan = SnapshotWriter::sourcePlan($directory . '/content', $directory . '/database.sql', $recipients, $unitId);
        if ($plan['inventory'] !== $record['inventory'] || $plan['sqlHash'] !== $record['sqlHash'] || $plan['sqlBytes'] !== $record['sqlBytes']) { throw new \RuntimeException('Frozen input differs from its capture record.'); }
        return ['plan' => $plan, 'capture' => ['record' => $recordFile, 'sha256' => hash('sha256', $bytes), 'report' => CaptureReport::fromRecord($record, $plan)]];
    }

    private function synchronize(string $source, string $destination, array $prior, array $current): void
    {
        $old = array_column($prior, null, 'path'); $new = array_column($current, null, 'path');
        foreach (array_reverse($prior) as $entry) {
            if (!isset($new[$entry['path']]) || $new[$entry['path']]['type'] !== $entry['type']) {
                $path = $destination . '/' . $entry['path'];
                if ($entry['type'] === 'directory') { if (!rmdir($path)) { throw new \RuntimeException('Cannot remove obsolete fixed directory.'); } }
                else { self::privateFile($path); if (!unlink($path)) { throw new \RuntimeException('Cannot remove obsolete fixed file.'); } }
            }
        }
        foreach ($current as $entry) {
            $path = $destination . '/' . $entry['path'];
            if ($entry['type'] === 'directory') { FileSafety::createPrivateDirectory($path); continue; }
            if (($old[$entry['path']] ?? null) === $entry) { continue; }
            if (is_file($path)) { self::privateFile($path); unlink($path); }
            $origin = $source . '/' . $entry['path']; self::privateFile($origin);
            $input = fopen($origin, 'rb'); $output = fopen($path, 'xb');
            if ($input === false || $output === false) { throw new \RuntimeException('Cannot copy synthetic content.'); }
            try {
                if (!chmod($path, 0600) || stream_copy_to_stream($input, $output) !== $entry['size'] || !fflush($output) || !\WordPressR2Backup\FileSafety::sync($output)) { throw new \RuntimeException('Cannot persist complete synthetic content.'); }
            } finally { fclose($input); fclose($output); }
            if (hash_file('sha256', $path) !== $entry['sha256']) { throw new \RuntimeException('Synthetic content changed during copy.'); }
            $original = stat($origin); $copied = stat($path);
            if ($original['dev'] === $copied['dev'] && $original['ino'] === $copied['ino']) { throw new \RuntimeException('Fixed input must not share source inodes.'); }
        }
    }

    private static function verifyPrivateTree(string $root): void
    {
        FileSafety::privateDirectory($root);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) { FileSafety::privateDirectory($entry->getPathname()); }
            else { self::privateFile($entry->getPathname()); }
        }
    }

    private static function privateFile(string $path): void
    {
        FileSafety::assertRegularFile($path); clearstatcache(true, $path); $stat = lstat($path);
        if (($stat['mode'] & 0777) !== 0600 || $stat['nlink'] !== 1 || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())) { throw new \RuntimeException('Expected a private owner-only capture file.'); }
    }

    private static function flushFile(string $path): void
    {
        self::privateFile($path); $stream = fopen($path, 'r+b');
        if ($stream === false) { throw new \RuntimeException('Cannot open fixed content for persistence.'); }
        try { if (!fflush($stream) || !\WordPressR2Backup\FileSafety::sync($stream)) { throw new \RuntimeException('Cannot persist fixed content.'); } }
        finally { fclose($stream); }
    }

    private static function persist(string $path, string $contents): void
    {
        if (strlen($contents) > self::RECORD_LIMIT) { throw new \RuntimeException('Capture record exceeds its limit.'); }
        $temporary = dirname($path) . '/.capture-' . bin2hex(random_bytes(12));
        $stream = fopen($temporary, 'xb');
        if ($stream === false) { throw new \RuntimeException('Cannot create the private capture record.'); }
        try {
            if (!chmod($temporary, 0600) || fwrite($stream, $contents) !== strlen($contents) || !fflush($stream) || !\WordPressR2Backup\FileSafety::sync($stream) || !rename($temporary, $path)) { throw new \RuntimeException('Cannot persist the private capture record.'); }
        } finally { fclose($stream); if (is_file($temporary)) { unlink($temporary); } }
    }

    private static function now(): string { return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'); }
}
