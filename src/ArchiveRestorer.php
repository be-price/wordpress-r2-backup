<?php
declare(strict_types=1);

namespace WordPressR2Backup;

use RuntimeException;

/** Resumable, non-replacing publication of independently authenticated archives. */
final class ArchiveRestorer
{
    private AgeProcess $age;
    private ObjectReader $reader;

    public function __construct(AgeProcess $age, ObjectReader $reader)
    {
        $this->age = $age;
        $this->reader = $reader;
    }

    public function restore(string $snapshotId, $identityStream, string $newDestination, ?MysqlClient $database = null, ?string $newDatabase = null, ?callable $summary = null): array
    {
        return $this->recover($snapshotId, $identityStream, $newDestination, $database, $newDatabase, $summary, false);
    }

    public function resume(string $snapshotId, $identityStream, string $newDestination, ?MysqlClient $database = null, ?string $newDatabase = null, ?callable $summary = null): array
    {
        return $this->recover($snapshotId, $identityStream, $newDestination, $database, $newDatabase, $summary, true);
    }

    public function discard(string $snapshotId, string $destination): void
    {
        $workspace = $this->workspace($snapshotId, $destination);
        $this->assertOwner($workspace, $snapshotId, $destination);
        $lock = $this->lock($workspace);
        try { $this->removeTree($workspace); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public function pending(string $snapshotId, string $destination): bool
    {
        return @lstat($this->workspace($snapshotId, $destination)) !== false;
    }

    private function recover(string $snapshotId, $input, string $destination, ?MysqlClient $database, ?string $schema, ?callable $summary, bool $resume): array
    {
        if (($database === null) !== ($schema === null)) { throw new RuntimeException('Supply the database client and new database together.'); }
        $workspace = $this->workspace($snapshotId, $destination);
        if (!$resume && @lstat($destination) !== false) { throw new RuntimeException('Recovery requires a new destination.'); }
        $identity = $this->identity($input);
        $lock = null;
        try {
            $receipt = $this->reader->completed($snapshotId)['manifest'];
            if ($resume) {
                $this->assertOwner($workspace, $snapshotId, $destination);
            } else {
                if (!@mkdir($workspace, 0700)) { throw new RuntimeException('Archive workspace exists; use resume or explicit discard.'); }
                $this->writeNew($workspace . '/owner', $this->owner($snapshotId, $destination));
            }
            $lock = $this->lock($workspace);
            $manifestPath = $workspace . '/manifest-' . bin2hex(random_bytes(8));
            try {
                $this->decrypt($receipt, $manifestPath, $identity, $workspace);
                if (filesize($manifestPath) > Manifest::MAX_BYTES) { throw new RuntimeException('Recovery manifest exceeds its size limit.'); }
                $manifest = Manifest::fromArray(json_decode(file_get_contents($manifestPath), true, 32, JSON_THROW_ON_ERROR))->toArray();
            } finally { if (@lstat($manifestPath) !== false) { unlink($manifestPath); } }
            if ($manifest['unitId'] !== $this->reader->unitId() || $manifest['snapshotId'] !== $snapshotId || $manifest['version'] !== 3 || !isset($manifest['wordpress']['archives'])) {
                throw new RuntimeException('Manifest does not belong to this archive recovery job.');
            }
            $this->assertMemoryBudget($manifest['wordpress']['archives'], (string) ini_get('memory_limit'));
            if ($resume) {
                $state = $this->readJson($workspace . '/state.json');
                if (($state['manifestSha256'] ?? null) !== $receipt['sha256']) { throw new RuntimeException('Recovery manifest changed.'); }
            } else {
                $state = ['manifestSha256' => $receipt['sha256'], 'parts' => [], 'publication' => null];
                $this->saveState($workspace, $state);
                if (!mkdir($workspace . '/parts', 0700)) { throw new RuntimeException('Cannot create private archive workspace.'); }
            }
            FileSafety::privateDirectory($workspace . '/parts');
            if ($state['publication'] !== null) { $this->publicationOwner($workspace, $destination, $state['publication']); }
            elseif (@lstat($destination) !== false) { throw new RuntimeException('Existing destination has no ownership proof; use a new destination.'); }
            foreach ($manifest['wordpress']['archives'] as $archive) { $this->reader->verify($archive['object']); }
            $this->reader->verify($manifest['database']['object']);
            if ($summary !== null) {
                $summary(['manifestVersion' => 3, 'captureEvidence' => 'reported',
                    'capture' => ['method' => 'wordpress-live-filtered', 'siteUrl' => $manifest['wordpress']['siteUrl'], 'policySha256' => $manifest['wordpress']['policySha256'], 'evidence' => $manifest['wordpress']['evidence'], 'archiveCount' => count($manifest['wordpress']['archives'])],
                    'verification' => ['manifest' => true, 'referencedCiphertexts' => true, 'payloadDecrypted' => false, 'databaseImported' => false]]);
            }
            $entries = []; $components = []; $files = 0; $bytes = 0; $directoryPaths = [];
            foreach ($manifest['wordpress']['archives'] as $ordinal => $archive) {
                $part = $workspace . '/parts/' . $ordinal;
                if (isset($state['parts'][$ordinal])) {
                    FileSafety::privateDirectory($part);
                    $metadata = $part . '/entries.json';
                    if (!hash_equals($state['parts'][$ordinal], $this->hash($metadata))) { throw new RuntimeException('Completed archive metadata changed.'); }
                    $partEntries = $this->readJson($metadata);
                    $this->verifyPart($part . '/files', $partEntries);
                } else {
                    if (@lstat($part) !== false) { throw new RuntimeException('Incomplete archive has no complete ownership record; explicitly discard this workspace.'); }
                    if (!mkdir($part, 0700)) { throw new RuntimeException('Cannot create private archive part.'); }
                    try {
                        $tar = $part . '/archive.tar';
                        $this->decrypt($archive['object'], $tar, $identity, $part);
                        $this->verifyContent($tar, $archive);
                        $scan = BackupArchive::inspect($tar);
                        $partEntries = $scan['entries'];
                        if ($scan['files'] !== $archive['fileCount'] || $scan['directories'] !== $archive['directoryCount']) { throw new RuntimeException('Archive entry counts differ from the manifest.'); }
                        BackupArchive::extract($tar, $part . '/files');
                        $this->writeNew($part . '/entries.json', json_encode($partEntries, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
                        unlink($tar);
                        $state['parts'][$ordinal] = $this->hash($part . '/entries.json');
                        $this->saveState($workspace, $state);
                    } catch (\Throwable $error) {
                        // This invocation created the part. A killed process never reaches this cleanup.
                        $this->removeTree($part);
                        unset($state['parts'][$ordinal]);
                        throw $error;
                    }
                }
                foreach ($partEntries as $entry) {
                    $path = $entry['path'];
                    $this->register($entry, $entries, $components);
                    $entry['source'] = $part . '/files/' . $path;
                    $entries[$path] = $entry;
                    $pieces = explode('/', $path);
                    if ($entry['type'] === 'file') { array_pop($pieces); ++$files; $bytes += $entry['size']; }
                    $directory = '';
                    foreach ($pieces as $piece) { $directory = $directory === '' ? $piece : $directory . '/' . $piece; $directoryPaths[$directory] = true; }
                }
            }
            if ($database !== null) {
                $sql = $workspace . '/database-' . bin2hex(random_bytes(8)) . '.sql';
                try {
                    $this->decrypt($manifest['database']['object'], $sql, $identity, $workspace);
                    $this->verifyContent($sql, $manifest['database']);
                    $database->importIntoEmpty($schema, $sql, $manifest['wordpress']['evidence']['events']);
                } finally { if (@lstat($sql) !== false) { unlink($sql); } }
            }
            $this->publish($workspace, $destination, $state, $entries, $directoryPaths);
            $marker = $destination . '/' . $state['publication']['marker'];
            if (!unlink($marker)) { throw new RuntimeException('Cannot remove publication ownership marker.'); }
            $this->removeTree($workspace);
            return ['snapshotId' => $snapshotId, 'files' => $files, 'directories' => count($directoryPaths), 'bytes' => $bytes, 'databaseImported' => $database !== null];
        } finally {
            if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
            fclose($identity);
        }
    }

    private function publish(string $workspace, string $destination, array &$state, array $entries, array $directories): void
    {
        if ($state['publication'] === null) {
            if (!@mkdir($destination, 0700)) { throw new RuntimeException('Recovery destination already exists.'); }
            $stat = lstat($destination);
            do { $marker = '.wrb-publication-' . bin2hex(random_bytes(16)); } while (isset($entries[$marker]) || isset($directories[$marker]));
            if (!link($workspace . '/owner', $destination . '/' . $marker)) { throw new RuntimeException('Cannot create publication ownership proof; use a new destination.'); }
            $state['publication'] = ['dev' => $stat['dev'], 'ino' => $stat['ino'], 'marker' => $marker, 'directories' => [], 'ready' => false];
            $this->saveState($workspace, $state);
        }
        $this->publicationOwner($workspace, $destination, $state['publication']);
        if (!$state['publication']['ready']) {
            // Persist all directory identities before publishing any file. An interrupted
            // directory creation without a journal entry deliberately requires discard.
            uksort($directories, static function (string $a, string $b): int { return substr_count($a, '/') <=> substr_count($b, '/') ?: strcmp($a, $b); });
            foreach ($directories as $relative => $_) {
                $target = $destination . '/' . $relative;
                if (@lstat($target) !== false) { throw new RuntimeException('Unrecorded publication directory exists; explicitly discard and use a new destination.'); }
                FileSafety::privateDirectory(dirname($target));
                if (!mkdir($target, 0700)) { throw new RuntimeException('Cannot create publication directory.'); }
                $stat = lstat($target);
                $state['publication']['directories'][$relative] = ['dev' => $stat['dev'], 'ino' => $stat['ino']];
            }
            $state['publication']['ready'] = true;
            $this->saveState($workspace, $state);
        }
        foreach ($directories as $relative => $_) {
            $target = $destination . '/' . $relative;
            FileSafety::privateDirectory($target);
            $stat = lstat($target);
            $record = $state['publication']['directories'][$relative] ?? [];
            if (($record['dev'] ?? null) !== $stat['dev'] || ($record['ino'] ?? null) !== $stat['ino']) { throw new RuntimeException('Publication directory ownership changed.'); }
        }
        $this->verifyPublishedTree($destination, $state['publication'], $entries, $directories);
        foreach ($entries as $relative => $entry) {
            if ($entry['type'] !== 'file') { continue; }
            $target = $destination . '/' . $relative;
            FileSafety::privateDirectory(dirname($target));
            if (@lstat($target) === false && !@link($entry['source'], $target)) { throw new RuntimeException('Cannot publish recovered file without replacement.'); }
            $this->sameFile($entry['source'], $target, $entry);
        }
        $this->publicationOwner($workspace, $destination, $state['publication']);
        $this->verifyPublishedTree($destination, $state['publication'], $entries, $directories, true);
    }

    private function verifyPublishedTree(string $root, array $publication, array $entries, array $directories, bool $complete = false): void
    {
        $pending = ['']; $seen = 0;
        while ($pending !== []) {
            $relative = array_pop($pending);
            $path = $relative === '' ? $root : $root . '/' . $relative;
            FileSafety::privateDirectory($path);
            foreach (scandir($path) as $name) {
                if ($name === '.' || $name === '..' || ($relative === '' && $name === $publication['marker'])) { continue; }
                $child = $relative === '' ? $name : $relative . '/' . $name;
                if (isset($directories[$child])) { FileSafety::privateDirectory($root . '/' . $child); $pending[] = $child; }
                elseif (isset($entries[$child]) && $entries[$child]['type'] === 'file') {
                    $this->sameFile($entries[$child]['source'], $root . '/' . $child, $entries[$child]); ++$seen;
                } else { throw new RuntimeException('Unknown entry in recovery destination; recovery never replaces it.'); }
            }
        }
        if ($complete && $seen !== count(array_filter($entries, static fn(array $e): bool => $e['type'] === 'file'))) { throw new RuntimeException('Published recovery is incomplete.'); }
    }

    private function register(array $entry, array $entries, array &$components): void
    {
        $path = $entry['path'];
        FileSafety::validatePaths([$path]);
        if (isset($entries[$path])) { throw new RuntimeException('Duplicate paths across archive parts.'); }
        if ($entry['type'] === 'file' && isset($components[$this->fold($path)])) { throw new RuntimeException('Archive file is an ancestor of another entry.'); }
        $prefix = ''; $pieces = explode('/', $path);
        foreach ($pieces as $index => $piece) {
            $prefix = $prefix === '' ? $piece : $prefix . '/' . $piece;
            $folded = $this->fold($prefix);
            if (isset($components[$folded]) && $components[$folded] !== $prefix) { throw new RuntimeException('Archive paths collide after Unicode case folding.'); }
            $components[$folded] = $prefix;
            if ($index < count($pieces) - 1 && isset($entries[$prefix]) && $entries[$prefix]['type'] === 'file') { throw new RuntimeException('Archive file is an ancestor of another entry.'); }
        }
    }

    private function fold(string $path): string
    {
        return mb_convert_case(\Normalizer::normalize($path, \Normalizer::FORM_C), MB_CASE_FOLD, 'UTF-8');
    }

    /** The recovery maps retain each path and its metadata until final publication. */
    private function assertMemoryBudget(array $archives, string $limit): void
    {
        $totalEntries = 0;
        foreach ($archives as $archive) {
            $count = $archive['fileCount'] + $archive['directoryCount'];
            if ($count > intdiv(PHP_INT_MAX - 67108864, 4096) - $totalEntries) { throw new RuntimeException('Archive entry count exceeds the recovery memory budget.'); }
            $totalEntries += $count;
        }
        $required = 67108864 + 4096 * $totalEntries;
        $limit = trim($limit);
        if ($limit === '-1') { return; }
        if (!preg_match('/^([0-9]+)\s*([KMG]?)$/iD', $limit, $match)) { throw new RuntimeException('Cannot determine the PHP recovery memory limit.'); }
        $multipliers = ['' => 1, 'K' => 1024, 'M' => 1048576, 'G' => 1073741824];
        $available = (float) $match[1] * $multipliers[strtoupper($match[2])];
        if ($available < $required) {
            $gigabytes = (int) ceil($required / 1073741824);
            throw new RuntimeException('Archive recovery requires at least ' . $required . ' bytes of PHP memory. Run php -d memory_limit=' . $gigabytes . 'G recover.php ... before recovering this snapshot.');
        }
    }

    private function verifyPart(string $root, array $entries): void
    {
        FileSafety::privateDirectory($root);
        foreach ($entries as $entry) {
            $path = $root . '/' . $entry['path'];
            if ($entry['type'] === 'directory') { FileSafety::privateDirectory($path); }
            else { $this->verifyContent($path, $entry); }
        }
    }

    private function sameFile(string $source, string $target, array $entry): void
    {
        FileSafety::assertRegularFile($source); FileSafety::assertRegularFile($target);
        $a = lstat($source); $b = lstat($target);
        if ($a['dev'] !== $b['dev'] || $a['ino'] !== $b['ino']) { throw new RuntimeException('Existing recovered file has a different identity; refusing replacement.'); }
        $this->verifyContent($target, $entry);
    }

    private function publicationOwner(string $workspace, string $destination, array $publication): void
    {
        FileSafety::privateDirectory($destination);
        $stat = lstat($destination);
        if (($publication['dev'] ?? null) !== $stat['dev'] || ($publication['ino'] ?? null) !== $stat['ino'] || !preg_match('/^\.wrb-publication-[a-f0-9]{32}$/D', $publication['marker'] ?? '')) { throw new RuntimeException('Recovery destination ownership changed.'); }
        $this->privateFile($workspace . '/owner');
        $this->privateFile($destination . '/' . $publication['marker']);
        $owner = lstat($workspace . '/owner'); $marker = lstat($destination . '/' . $publication['marker']);
        if ($owner['dev'] !== $marker['dev'] || $owner['ino'] !== $marker['ino']) { throw new RuntimeException('Recovery destination ownership proof changed.'); }
    }

    private function workspace(string $snapshot, string $destination): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $snapshot) || $destination === '' || $destination[0] !== '/' || strpos($destination, "\0") !== false || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $destination) || substr($destination, -1) === '/') { throw new RuntimeException('Invalid archive recovery destination or snapshot.'); }
        FileSafety::assertNoLinks($destination);
        FileSafety::privateDirectory(dirname($destination));
        return dirname($destination) . '/.wrb-archive-' . hash('sha256', $this->reader->unitId() . "\0" . $snapshot . "\0" . $destination);
    }

    private function owner(string $snapshot, string $destination): string
    {
        return 'wordpress-r2-backup archive recovery v1 ' . hash('sha256', $this->reader->unitId() . "\0" . $snapshot . "\0" . $destination) . "\n";
    }

    private function assertOwner(string $workspace, string $snapshot, string $destination): void
    {
        FileSafety::privateDirectory($workspace); $this->privateFile($workspace . '/owner');
        if (filesize($workspace . '/owner') > 256 || file_get_contents($workspace . '/owner') !== $this->owner($snapshot, $destination)) { throw new RuntimeException('Archive workspace does not belong to this recovery job.'); }
    }

    private function lock(string $workspace)
    {
        FileSafety::privateDirectory($workspace); FileSafety::assertNoLinks($workspace . '/job.lock');
        if (@lstat($workspace . '/job.lock') !== false) { FileSafety::assertRegularFile($workspace . '/job.lock'); }
        $lock = fopen($workspace . '/job.lock', 'c+b');
        if ($lock === false || !chmod($workspace . '/job.lock', 0600) || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) { fclose($lock); }
            throw new RuntimeException('Archive recovery job is already running or cannot be locked.');
        }
        return $lock;
    }

    private function identity($input)
    {
        if (!is_resource($input) || get_resource_type($input) !== 'stream') { throw new RuntimeException('A readable identity stream is required.'); }
        $identity = fopen('php://memory', 'w+b');
        $size = stream_copy_to_stream($input, $identity, 65537);
        if ($size === false || $size === 0 || $size > 65536) { fclose($identity); throw new RuntimeException('Identity stream must contain at most 64 KiB.'); }
        rewind($identity); return $identity;
    }

    private function decrypt(array $receipt, string $plain, $identity, string $workspace): void
    {
        $cipher = $workspace . '/cipher-' . bin2hex(random_bytes(8));
        try { rewind($identity); $this->reader->download($receipt, $cipher); $this->age->decrypt($cipher, $plain, $identity); }
        finally { if (@lstat($cipher) !== false) { unlink($cipher); } }
    }

    private function hash(string $path): string
    {
        $this->privateFile($path); $hash = hash_file('sha256', $path);
        if ($hash === false) { throw new RuntimeException('Cannot verify recovery content.'); }
        return $hash;
    }

    private function verifyContent(string $path, array $expected): void
    {
        $actual = $this->hash($path);
        if (filesize($path) !== $expected['size'] || !hash_equals($expected['sha256'], $actual)) { throw new RuntimeException('Recovery content differs from the authenticated manifest.'); }
    }

    private function readJson(string $path): array
    {
        $this->privateFile($path);
        if (filesize($path) > Manifest::MAX_BYTES) { throw new RuntimeException('Recovery metadata exceeds its limit.'); }
        $result = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($result)) { throw new RuntimeException('Recovery metadata is invalid.'); }
        return $result;
    }

    private function privateFile(string $path): void
    {
        FileSafety::assertRegularFile($path);
        $stat = lstat($path);
        if (($stat['mode'] & 0777) !== 0600 || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())) { throw new RuntimeException('Expected an owner-only recovery file.'); }
    }

    private function writeNew(string $path, string $bytes): void
    {
        $previous = umask(0077);
        try { $stream = @fopen($path, 'x+b'); } finally { umask($previous); }
        if ($stream === false) { throw new RuntimeException('Cannot create private recovery metadata.'); }
        try {
            if (!chmod($path, 0600) || fwrite($stream, $bytes) !== strlen($bytes) || !fflush($stream)) { throw new RuntimeException('Cannot persist private recovery metadata.'); }
        } finally { fclose($stream); }
    }

    private function saveState(string $workspace, array $state): void
    {
        $temporary = $workspace . '/state-' . bin2hex(random_bytes(8));
        try {
            $this->writeNew($temporary, json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            if (!rename($temporary, $workspace . '/state.json')) { throw new RuntimeException('Cannot persist archive recovery state.'); }
        } finally { if (@lstat($temporary) !== false) { unlink($temporary); } }
    }

    private function removeTree(string $path): void
    {
        $stat = @lstat($path);
        if ($stat === false) { return; }
        if (($stat['mode'] & 0170000) !== 0040000) { if (!unlink($path)) { throw new RuntimeException('Cannot remove owned archive workspace entry.'); } return; }
        FileSafety::privateDirectory($path);
        foreach (scandir($path) as $name) { if ($name !== '.' && $name !== '..') { $this->removeTree($path . '/' . $name); } }
        if (!rmdir($path)) { throw new RuntimeException('Cannot remove owned archive workspace.'); }
    }
}
