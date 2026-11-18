<?php
declare(strict_types=1);

namespace WordPressR2Backup;

use RuntimeException;

/** Captures one independently restorable generation. Only metadata reaches disk in plaintext. */
final class V4Capture
{
    private SourceRoots $roots;
    private WordPressDatabase $database;
    private PrivacyPolicy $policy;
    private string $directory;
    private ?array $maintenanceOwner;
    private string $siteUrl;
    private ?MetadataMap $inventory = null;
    private array $summary = [];
    private string $state = 'new';
    private WordPressPolicyContext $policyContext;
    private string $contextDigest = '';
    /** Chunk ids of every selected file, recorded by the scan's hashing read. Each id is tied to the file's saved sha256. */
    private ?MetadataMap $scannedContents = null;
    /**
     * Elapsed milliseconds and counts of each phase, for measurement only. Fixed integer fields: no path, table name,
     * identifier, digest or content is ever recorded, and no check reads these values back.
     */
    private array $measurements = [];

    public function __construct(SourceRoots $roots, $wpdb, PrivacyPolicy $policy, string $privateDirectory, ?array $maintenanceOwner = null, string $siteUrl = 'https://example.invalid')
    {
        $roots->verifyPrivateLocations([$privateDirectory]);
        FileSafety::privateDirectory($privateDirectory);
        if (!filter_var($siteUrl, FILTER_VALIDATE_URL) || strlen($siteUrl) > 2048) { throw new RuntimeException('Invalid v4 capture site URL.'); }
        $this->roots = $roots;
        $this->database = new WordPressDatabase($wpdb, $policy);
        $this->policy = $policy;
        $this->directory = FileSafety::createPrivateDirectory($privateDirectory . '/capture-' . bin2hex(random_bytes(16)));
        $this->policyContext = new WordPressPolicyContext($wpdb, $policy, $this->directory);
        $this->maintenanceOwner = $maintenanceOwner;
        $this->siteUrl = $siteUrl;
    }

    /** Progress callbacks are trusted filesystem-only observers; they must not query the source database. */
    public function scan(?callable $progress = null): array
    {
        return $this->scanSelection($progress, false, true);
    }

    private function scanSelection(?callable $progress, bool $collectContents, bool $verifyEnumeration): array
    {
        $this->database->onHeartbeat($progress === null ? null : static function () use ($progress): void {
            $progress(['phase' => 'capture', 'heartbeat' => true]);
        });
        if ($this->state === 'scanned') { return $this->summary; }
        if ($this->state !== 'new') { throw new RuntimeException('V4 capture cannot be scanned in its current state.'); }
        try {
            $scanStarted = hrtime(true);
            $this->contextDigest = $this->policyContext->prepare($progress);
            $this->measurements['policyContextMs'] = self::elapsedMs($scanStarted);
            $this->inventory = MetadataMap::createProcessLocal($this->directory . '/source-map');
            if ($collectContents) { $this->scannedContents = MetadataMap::createProcessLocal($this->directory . '/selected-content-map'); }
            $summary = ['entries' => 0, 'selected' => 0, 'excluded' => 0, 'files' => 0, 'directories' => 0, 'links' => 0, 'fileBytes' => 0, 'decisions' => []];
            $ancestors = [];
            foreach ($this->entries($summary, $progress, 'scan') as $entry) {
                foreach ($ancestors as $key => $ancestor) {
                    if ($ancestor['entry']['rootId'] !== $entry['rootId'] || strpos($entry['path'], $ancestor['entry']['path'] . '/') !== 0) { unset($ancestors[$key]); }
                }
                if ($entry['selected']) {
                    foreach ($ancestors as $key => $ancestor) {
                        if (!$ancestor['remembered']) {
                            $this->rememberEntry($ancestor['entry'], $summary, $progress);
                            $ancestors[$key]['remembered'] = true;
                        }
                    }
                    $this->rememberEntry($entry, $summary, $progress);
                }
                if ($entry['type'] === 'directory') { $ancestors[self::key($entry)] = ['entry' => $entry, 'remembered' => $entry['selected']]; }
            }
            // A standalone scan must validate now; capture repeats this check under its read lock.
            if ($verifyEnumeration) {
                $seen = 0;
                $observation = null;
                foreach ($this->entries($observation, $progress, 'scan') as $entry) {
                    $saved = $this->savedEntry($entry);
                    if ($saved === null) { continue; }
                    ++$seen;
                    if ($entry['type'] === 'symlink') { $this->assertSelectedLink($entry); }
                }
                if ($seen !== $summary['selected']) { throw new RuntimeException('A source entry disappeared during preflight.'); }
            }
            $summary['excluded'] = $summary['entries'] - $summary['selected'];
            $preflight = $this->database->preflight();
            $summary['tableCount'] = $preflight['tableCount'];
            $summary['policyContext'] = $this->policyContext->summary();
            $summary['policySha256'] = hash('sha256', V4Format::json($this->policy->rules()));
            $this->summary = $summary;
            $this->measurements += ['scanMs' => self::elapsedMs($scanStarted), 'scanEntries' => $summary['entries'], 'scanSelected' => $summary['selected'],
                'scanFiles' => $summary['files'], 'scanFileBytes' => $summary['fileBytes'], 'scanCollectedContents' => $collectContents ? 1 : 0];
            $this->state = 'scanned';
            if ($progress !== null) { $progress(['phase' => 'scan', 'entries' => $summary['entries'], 'selected' => $summary['selected'], 'tableCount' => $summary['tableCount']]); }
            return $summary;
        } catch (\Throwable $error) { $this->state = 'failed'; throw $error; }
    }

    /** Progress may run inside a database row stream and must use filesystem operations only. */
    public function capture(AgeProcess $age, array $recipients, string $snapshotId, array $scope, ?callable $progress = null, ?V4ContentIndex $index = null, string $mode = 'maintenance'): array
    {
        if (!in_array($mode, ['maintenance', 'online'], true)) { throw new RuntimeException('Unsupported capture consistency mode.'); }
        $startedAt = gmdate('Y-m-d\TH:i:s\Z');
        $captureStarted = hrtime(true); $encryptionBefore = $age->streamTotals();
        $this->measurements['scanBeforeCapture'] = $this->state === 'scanned' ? 1 : 0;
        // A filtered policy needs membership proof whenever the index holds a ready pack, so the scan's hashing read
        // records chunk ids now instead of reading every file again under the lock. Other cases keep the locked pass.
        $this->scanSelection($progress, $index !== null && $this->state === 'new' && !$this->policy->isComplete(), false);
        if ($this->state !== 'scanned') { $this->state = 'failed'; throw new RuntimeException('Capture inspection did not complete.'); }
        $this->state = 'capturing';
        try {
            $selectedRoots = $this->selectedRoots();
            $rootScopeSha256 = V4ContentIndex::rootScopeSha256($selectedRoots);
            $selectedContents = $index !== null && $index->requiresMembershipProof($this->policy->isComplete(), $rootScopeSha256)
                ? ($this->scannedContents ?? MetadataMap::createProcessLocal($this->directory . '/selected-content-map')) : null;
            $filesCollected = $selectedContents !== null && $selectedContents === $this->scannedContents;
            $heartbeat = $progress === null ? null : static function () use ($progress): void { $progress(['phase' => 'capture', 'heartbeat' => true]); };
            $store = new V4ContentStore($age, $recipients, $this->directory, $scope, $snapshotId, $index, $rootScopeSha256, $this->policy->isComplete(), $selectedContents, $heartbeat);
            $filesystem = new V4Pages($age, $recipients, $this->directory, $snapshotId, 'filesystem');
            $database = new V4Pages($age, $recipients, $this->directory, $snapshotId, 'database');
            $sqlHash = hash_init('sha256');
            $databaseBytes = 0;
            $lockAcquiredAt = '';
            $verifiedAt = '';
            $databaseVerifiedAt = '';
            $counts = ['files' => 0, 'directories' => 0, 'links' => 0, 'contents' => 0, 'packs' => 0, 'databaseBytes' => 0, 'fileBytes' => 0];
            if ($progress !== null) { $this->database->onHeartbeat(static function () use ($progress): void { $progress(['phase' => 'capture', 'heartbeat' => true]); }); }
            $captureFiles = function (?WordPressDatabase $source) use ($store, $filesystem, &$counts, $progress, &$verifiedAt): void {
                $phase = hrtime(true);
                $fileProgress = $source === null ? static function (array $update) use ($progress): void { if ($progress !== null) { $progress($update); } } : $this->lockedFileProgress($source, $progress);
                $fileProgress(['phase' => 'capture', 'heartbeat' => true]);
                $observation = null;
                foreach ($this->entries($observation, $fileProgress) as $entry) {
                    $saved = $this->savedEntry($entry);
                    if ($saved === null) { continue; }
                    $header = ['type' => $entry['type'], 'rootId' => $entry['rootId'], 'path' => $entry['path']];
                    if ($entry['type'] === 'file') {
                        $header['size'] = $entry['stat']['size']; $header['mode'] = $entry['mode'];
                        $filesystem->append($header);
                        $hash = $this->readFile($entry, static function (string $bytes) use ($filesystem, $store, $entry): void {
                            $filesystem->append(['type' => 'part', 'content' => $store->accept($bytes, self::lane($entry['path']))]);
                        }, $fileProgress);
                        if (!hash_equals($saved['sha256'], $hash)) { throw new RuntimeException('Source file content changed after preflight.'); }
                        $filesystem->append(['type' => 'end', 'sha256' => $hash]);
                        ++$counts['files']; $counts['fileBytes'] += $entry['stat']['size'];
                    } elseif ($entry['type'] === 'directory') {
                        $header['mode'] = $entry['mode']; $filesystem->append($header); ++$counts['directories'];
                    } else {
                        $this->assertSelectedLink($entry);
                        $header['target'] = $entry['target']; $filesystem->append($header); ++$counts['links'];
                    }
                    $fileProgress(['phase' => 'capture', 'files' => $counts['files'], 'directories' => $counts['directories'], 'links' => $counts['links'], 'fileBytes' => $counts['fileBytes']]);
                }
                $this->measurements += ['filesCaptureMs' => self::elapsedMs($phase), 'filesCaptureFiles' => $counts['files'], 'filesCaptureBytes' => $counts['fileBytes']];
                $phase = hrtime(true);
                $seen = 0;
                foreach ($this->entries($observation, $fileProgress) as $entry) {
                    $saved = $this->savedEntry($entry);
                    if ($saved === null) { continue; }
                    if ($entry['type'] === 'file' && !hash_equals($saved['sha256'], $this->readFile($entry, null, $fileProgress))) { throw new RuntimeException('Source file content changed during capture.'); }
                    ++$seen;
                }
                if ($seen !== $this->summary['selected']) { throw new RuntimeException('A source entry disappeared during capture.'); }
                $this->measurements += ['filesFinalMs' => self::elapsedMs($phase), 'filesFinalEntries' => $seen];
                $store->verifyPending();
                $verifiedAt = gmdate('Y-m-d\TH:i:s\Z');
            };
            $lockCalled = hrtime(true); $lockHeld = 0; $lockLeft = 0;
            $captureDatabase = function (WordPressDatabase $source) use ($store, $database, $sqlHash, &$databaseBytes, &$lockAcquiredAt, $progress, $selectedContents, $filesCollected, $lockCalled, &$lockHeld, &$lockLeft, $captureFiles, $mode): void {
                $lockHeld = hrtime(true); $this->measurements['lockWaitMs'] = self::elapsedMs($lockCalled);
                if (!hash_equals($this->contextDigest, $this->policyContext->prepare($progress))) { throw new RuntimeException('Policy context changed after inspection. Run inspection again before capture.'); }
                $this->measurements['lockContextMs'] = self::elapsedMs($lockHeld);
                $lockAcquiredAt = $mode === 'online' ? $source->snapshotAt() : gmdate('Y-m-d\TH:i:s\Z');
                if ($selectedContents !== null) { $this->collectSelectedContents($source, $selectedContents, $progress, $filesCollected); }
                $phase = hrtime(true);
                $records = $selectedContents === null ? $source->records() : $source->replayRecords();
                foreach ($this->databaseRecords($records, $progress) as $record) {
                    if ($record['type'] === 'table') { $database->append($record); continue; }
                    $lane = 'database:' . hash('sha256', $record['name'] . '/' . $record['stage']);
                    hash_update($sqlHash, $record['bytes']); $databaseBytes += strlen($record['bytes']);
                    $database->append(['type' => 'sql', 'stage' => $record['stage'], 'name' => $record['name'], 'content' => $store->accept($record['bytes'], $lane)]);
                }
                $this->measurements += ['databaseCaptureMs' => self::elapsedMs($phase), 'databaseCaptureBytes' => $databaseBytes];
                $source->verification();
                if ($mode === 'maintenance') { $captureFiles($source); }
                $lockLeft = hrtime(true); $this->measurements['lockHeldMs'] = self::elapsedMs($lockHeld);
            };
            if ($mode === 'online') {
                $this->database->withConsistentSnapshot($captureDatabase);
                $databaseVerifiedAt = gmdate('Y-m-d\TH:i:s\Z');
                $this->measurements['lockReleaseMs'] = $lockLeft === 0 ? 0 : self::elapsedMs($lockLeft);
                $captureFiles(null);
            } else {
                $this->database->withReadLock($captureDatabase);
                $this->measurements['lockReleaseMs'] = $lockLeft === 0 ? 0 : self::elapsedMs($lockLeft);
            }
            $finishStarted = hrtime(true);
            // Both modes must finish verifying every saved file before the capture can be published.
            if ($verifiedAt === '') { throw new RuntimeException('Capture did not complete file verification.'); }
            $seals = $this->database->verification();
            $stored = $store->finish();
            if ($heartbeat !== null) { $heartbeat(); }
            $filesystemPages = $filesystem->finish();
            if ($heartbeat !== null) { $heartbeat(); }
            $databasePages = $database->finish();
            if ($heartbeat !== null) { $heartbeat(); }
            $this->measurements['finishMs'] = self::elapsedMs($finishStarted);
            $descriptors = array_merge($stored['pages'], $filesystemPages, $databasePages);
            $objects = $stored['objects']; $pages = [];
            foreach ($descriptors as $descriptor) {
                $objects[] = ['object' => $descriptor['object'], 'cipherFile' => $descriptor['cipherFile']];
                unset($descriptor['cipherFile']); $pages[] = $descriptor;
            }
            $counts['contents'] = $stored['contentCount']; $counts['packs'] = $stored['packCount']; $counts['databaseBytes'] = $databaseBytes;
            if ($counts['files'] !== $this->summary['files'] || $counts['directories'] !== $this->summary['directories'] || $counts['links'] !== $this->summary['links'] || $counts['fileBytes'] !== $this->summary['fileBytes']) { throw new RuntimeException('The captured selection differs from preflight.'); }
            $evidence = ['startedAt' => $startedAt, 'lockAcquiredAt' => $lockAcquiredAt, 'verifiedAt' => $verifiedAt,
                'finishedAt' => gmdate('Y-m-d\TH:i:s\Z'), 'scan' => $this->summary,
                'tables' => array_map(function (array $table): array { return ['name' => $table['name'], 'sourceRows' => $table['sourceRows'], 'rows' => $table['rows'], 'excludedRows' => $table['excludedRows'], 'decision' => $this->policy->tableAction($table['name'])]; }, $seals)];
            if ($mode === 'online') {
                unset($evidence['lockAcquiredAt'], $evidence['verifiedAt']);
                $evidence['snapshotAt'] = $lockAcquiredAt;
                $evidence['databaseVerifiedAt'] = $databaseVerifiedAt;
                $evidence['filesVerifiedAt'] = $verifiedAt;
            }
            $manifest = ['format' => 'wordpress-r2-backup', 'version' => 4, 'dataClass' => 'wordpress', 'unitId' => $scope['unitId'], 'snapshotId' => $snapshotId,
                'keySetId' => Manifest::keySetId($recipients), 'recipients' => $recipients, 'capturedAt' => $lockAcquiredAt, 'encoding' => V4Format::ENCODING,
                'scope' => $scope, 'roots' => $selectedRoots, 'pages' => $pages, 'counts' => $counts,
                'database' => ['size' => $databaseBytes, 'sha256' => hash_final($sqlHash), 'tableCount' => count($seals), 'events' => [], 'environment' => $this->database->environment()],
                'wordpress' => ['siteUrl' => $this->siteUrl, 'policySha256' => $this->summary['policySha256'], 'policy' => $this->policy->rules(), 'evidence' => $evidence]];
            if ($mode === 'online') { $manifest['wordpress']['consistencyMode'] = 'online'; }
            V4Format::validateRoot($manifest);
            $verification = $store->verificationTotals(); $encryption = $age->streamTotals();
            $this->measurements += ['sharedVerifyMs' => intdiv($verification['nanoseconds'], 1000000), 'sharedVerifyCalls' => $verification['calls'],
                'sharedVerifyPacks' => $verification['packs'], 'sharedVerifyBytes' => $verification['bytes'],
                'encryptMs' => intdiv(max(0, $encryption['nanoseconds'] - $encryptionBefore['nanoseconds']), 1000000),
                'encryptCalls' => $encryption['calls'] - $encryptionBefore['calls'], 'encryptPlainBytes' => $encryption['plainBytes'] - $encryptionBefore['plainBytes'],
                'objects' => count($objects), 'totalMs' => self::elapsedMs($captureStarted)];
            $this->state = 'complete';
            return ['manifest' => $manifest, 'objects' => $objects, 'evidence' => $evidence, 'sharing' => $stored['sharing'], 'measurements' => $this->measurements()];
        } catch (\Throwable $error) { $this->state = 'failed'; throw $error; }
    }

    /**
     * The measurements recorded so far: a fixed set of non-negative integers (milliseconds, counts and bytes) and a version.
     * Measurement only; available after a failure too, and never used by any check.
     */
    public function measurements(): array
    {
        $result = ['version' => 1];
        foreach ($this->measurements as $name => $value) { if (is_int($value) && $value >= 0) { $result[$name] = $value; } }
        return $result;
    }

    private static function elapsedMs(int $started): int { return intdiv(max(0, hrtime(true) - $started), 1000000); }

    private function entries(?array &$summary = null, ?callable $progress = null, string $phase = 'capture'): \Generator
    {
        $observed = 0;
        yield from $this->roots->entries($this->maintenanceOwner, function (array $entry) use (&$summary, &$observed, $progress, $phase): array {
            $logical = $this->roots->policyPath($entry); $scoped = $entry['rootId'] . '/' . $entry['path'];
            $decision = $this->fileDecision($entry, $logical, $scoped, $progress, $phase);
            if (!in_array($decision['action'], ['include', 'exclude'], true)) { throw new SourcePathReviewRequired('A source path requires an explicit inclusion decision.'); }
            if ($summary !== null) {
                ++$summary['entries'];
                $key = $decision['action'] . ':' . $decision['reason'];
                $summary['decisions'][$key] = ($summary['decisions'][$key] ?? 0) + 1;
            }
            if (++$observed % 100 === 0 && $progress !== null) { $progress(['phase' => $phase, 'entries' => $observed]); }
            return ['include' => $decision['action'] === 'include',
                'descend' => $entry['type'] === 'directory' && $this->policy->shouldTraversePath($logical, $scoped)];
        });
    }

    private function selectedRoots(): array
    {
        return array_values(array_filter($this->roots->descriptors(), function (array $root): bool {
            return $root['type'] !== 'file' || $this->inventory->get(hash('sha256', $root['id'] . '/' . basename($root['sourcePath']))) !== null;
        }));
    }

    private function lockedFileProgress(WordPressDatabase $source, ?callable $progress): callable
    {
        $nextCheck = 0.0;
        return static function (array $update) use ($source, $progress, &$nextCheck): void {
            $now = hrtime(true) / 1000000000;
            if ($now >= $nextCheck) {
                $source->maintainReadLock();
                $nextCheck = $now + 30.0;
            }
            if ($progress !== null) { $progress($update); }
        };
    }

    /**
     * File ids may come from the scan: every one is a chunk of a selected file whose whole sha256 the capture and the
     * final verification must match again, so a changed file fails the capture before it can be published.
     */
    private function collectSelectedContents(WordPressDatabase $source, MetadataMap $selected, ?callable $progress, bool $filesCollected): void
    {
        $remember = static function (string $bytes) use ($selected): void { self::rememberContent($selected, $bytes); };
        $started = hrtime(true); $bytes = 0;
        foreach ($this->databaseRecords($source->records(), $progress, 'sharing-preflight') as $record) {
            if ($record['type'] === 'sql') { $remember($record['bytes']); $bytes += strlen($record['bytes']); }
        }
        $this->measurements += ['databasePrepassMs' => self::elapsedMs($started), 'databasePrepassBytes' => $bytes];
        if ($filesCollected) { return; }
        $started = hrtime(true); $files = 0; $fileBytes = 0;
        $fileProgress = $this->lockedFileProgress($source, $progress);
        $fileProgress(['phase' => 'sharing-preflight', 'heartbeat' => true]);
        $observation = null;
        foreach ($this->entries($observation, $fileProgress, 'sharing-preflight') as $entry) {
            $saved = $this->savedEntry($entry);
            if ($saved === null || $entry['type'] !== 'file') { continue; }
            if (!hash_equals($saved['sha256'], $this->readFile($entry, $remember, $fileProgress, 'sharing-preflight'))) { throw new RuntimeException('Source file changed during sharing preflight.'); }
            ++$files; $fileBytes += $entry['stat']['size'];
        }
        $this->measurements += ['filesPrepassMs' => self::elapsedMs($started), 'filesPrepassFiles' => $files, 'filesPrepassBytes' => $fileBytes];
    }

    private function databaseRecords(iterable $records, ?callable $progress, string $phase = 'capture'): \Generator
    {
        $databaseBytes = 0; $nextProgress = 33554432;
        foreach ($records as $record) {
            if ($record['type'] === 'table') { yield $record; continue; }
            $buffer = '';
            foreach ($record['chunks'] as $chunk) {
                if (!is_string($chunk)) { throw new RuntimeException('Database SQL chunks must be bytes.'); }
                $offset = 0;
                while ($offset < strlen($chunk)) {
                    $take = min(V4Format::MAX_CONTENT_BYTES - strlen($buffer), strlen($chunk) - $offset);
                    $buffer .= substr($chunk, $offset, $take); $offset += $take; $databaseBytes += $take;
                    $this->sizeLimit($databaseBytes + $this->summary['fileBytes']);
                    if ($progress !== null && $databaseBytes >= $nextProgress) {
                        $progress(['phase' => $phase, 'databaseBytes' => $databaseBytes]); $nextProgress = $databaseBytes + 33554432;
                    }
                    if (strlen($buffer) === V4Format::MAX_CONTENT_BYTES) {
                        yield ['type' => 'sql', 'stage' => $record['stage'], 'name' => $record['name'], 'bytes' => $buffer]; $buffer = '';
                    }
                }
            }
            if ($buffer !== '') { yield ['type' => 'sql', 'stage' => $record['stage'], 'name' => $record['name'], 'bytes' => $buffer]; }
        }
    }

    private function fileDecision(array $entry, string $logical, string $scoped, ?callable $progress, string $phase): array
    {
        $decision = $this->policy->pathAction($logical, $scoped);
        $rules = $this->policy->rules();
        if ($decision['action'] === 'exclude' || (isset($rules['version']) && !isset($rules['legacy'])) || in_array($decision['reason'], ['manual', 'public_content_scope'], true)
            || ($decision['reason'] === 'category' && $this->policy->pathHasExplicitDecision($logical, $scoped))) { return $decision; }
        if ($entry['type'] === 'directory') {
            if (in_array($logical, ['wp-admin', 'wp-includes', 'wp-content', 'wp-content/uploads', 'wp-content/plugins', 'wp-content/themes', 'wp-content/mu-plugins'], true)
                || preg_match('~^(?:wp-admin/|wp-includes/|wp-content/(?:plugins|themes|mu-plugins)/)~', $logical)) { return ['action' => 'include', 'reason' => 'application_directory']; }
            if (strpos($logical, 'wp-content/uploads/') === 0 && !$this->policy->publicContextRules()['scanPublic']) { return ['action' => 'include', 'reason' => 'public_inspection_disabled']; }
            return ['action' => 'review', 'reason' => 'unknown_directory'];
        }
        if ($entry['type'] === 'file' && $decision['action'] === 'review' && $decision['reason'] === 'public_file_requires_review'
            && ($entry['stat']['nlink'] ?? 0) === 1 && ($entry['mode'] & 07000) === 0 && preg_match('/\.(?:txt|csv|json|xml|html?|md)$/i', $logical)) {
            $tail = '';
            $this->readFile($entry, function (string $chunk) use (&$tail): void {
                $text = $tail . $chunk;
                if ($this->policy->containsPersonalData($text)) { throw new RuntimeException('A legacy public-text candidate requires an explicit inclusion decision.'); }
                $tail = substr($text, -512);
            }, $progress, $phase);
            return ['action' => 'include', 'reason' => 'no_text_candidate_detected'];
        }
        return $decision;
    }

    private function rememberEntry(array $entry, array &$summary, ?callable $progress): void
    {
        if ($this->inventory->get(self::key($entry)) !== null) { return; }
        $metadata = ['stat' => self::savedStat($entry), 'type' => $entry['type'], 'mode' => $entry['mode'], 'selected' => true];
        if ($entry['type'] === 'file') {
            $contents = $this->scannedContents;
            $metadata['sha256'] = $this->readFile($entry, $contents === null ? null : static function (string $bytes) use ($contents): void { self::rememberContent($contents, $bytes); }, $progress, 'scan');
        }
        if (isset($entry['target'])) { $metadata['targetSha256'] = hash('sha256', $entry['target']); }
        $this->inventory->put(self::key($entry), $metadata);
        ++$summary['selected'];
        ++$summary[$entry['type'] === 'file' ? 'files' : ($entry['type'] === 'directory' ? 'directories' : 'links')];
        if ($entry['type'] === 'file') { $summary['fileBytes'] += $entry['stat']['size']; $this->sizeLimit($summary['fileBytes']); }
    }

    private function savedEntry(array $entry): ?array
    {
        $saved = $this->inventory->get(self::key($entry));
        if ($saved === null && !$entry['selected']) { return null; }
        $targetHash = isset($entry['target']) ? hash('sha256', $entry['target']) : null;
        if ($saved === null || $saved['stat'] !== self::savedStat($entry) || $saved['type'] !== $entry['type'] || ($saved['targetSha256'] ?? null) !== $targetHash) { throw new RuntimeException('Source entry metadata changed during capture.'); }
        return $saved;
    }

    private static function savedStat(array $entry): array
    {
        $stat = $entry['stat'];
        // Child creation/removal changes these directory fields even when every saved child is unchanged.
        if ($entry['type'] === 'directory') { unset($stat['size'], $stat['mtime'], $stat['ctime'], $stat['nlink']); }
        return $stat;
    }

    private function assertSelectedLink(array $entry): void
    {
        $parts = dirname($entry['path']) === '.' ? [] : explode('/', dirname($entry['path']));
        foreach (explode('/', $entry['target']) as $part) {
            if ($part === '' || $part === '.') { continue; }
            if ($part === '..') { array_pop($parts); } else { $parts[] = $part; }
        }
        if ($parts === []) { return; }
        $target = $this->inventory->get(hash('sha256', $entry['rootId'] . '/' . implode('/', $parts)));
        if ($target === null || !$target['selected'] || !in_array($target['type'], ['file', 'directory'], true)) { throw new RuntimeException('A saved symbolic link targets an excluded source entry.'); }
    }

    private function readFile(array $entry, ?callable $consume = null, ?callable $progress = null, string $phase = 'capture'): string
    {
        FileSafety::assertNoLinks($entry['absolute']);
        $stream = @fopen($entry['absolute'], 'rb');
        if ($stream === false) { throw new RuntimeException('Cannot read a source file.'); }
        $hash = hash_init('sha256'); $size = 0; $nextProgress = 33554432; $lastProgress = time();
        try {
            $this->assertFileStat($entry, fstat($stream));
            while (!feof($stream)) {
                $bytes = fread($stream, V4Format::MAX_CONTENT_BYTES);
                if ($bytes === false || ($bytes === '' && !feof($stream))) { throw new RuntimeException('Cannot read a source file completely.'); }
                if ($bytes === '') { continue; }
                if ($size === 0) { self::rejectAgeIdentity($bytes); }
                $size += strlen($bytes); $this->sizeLimit($size);
                if ($size > $entry['stat']['size']) { throw new RuntimeException('A source file grew during capture.'); }
                hash_update($hash, $bytes);
                if ($consume !== null) { $consume($bytes); }
                if ($progress !== null && ($size >= $nextProgress || time() - $lastProgress >= 15)) {
                    $progress(['phase' => $phase, 'fileBytes' => $size]); $nextProgress = $size + 33554432; $lastProgress = time();
                }
            }
            if ($size !== $entry['stat']['size']) { throw new RuntimeException('A source file changed size during capture.'); }
            $this->assertFileStat($entry, fstat($stream));
            clearstatcache(true, $entry['absolute']);
            $this->assertFileStat($entry, @lstat($entry['absolute']));
            return hash_final($hash);
        } finally { fclose($stream); }
    }

    private function assertFileStat(array $entry, $actual): void
    {
        if (!is_array($actual)) { throw new RuntimeException('Source file metadata is unavailable.'); }
        foreach ($entry['stat'] as $field => $value) {
            if ($field === 'type') { if (($actual['mode'] & 0170000) !== $value) { throw new RuntimeException('Source file type changed.'); } }
            elseif (!array_key_exists($field, $actual) || $actual[$field] !== $value) { throw new RuntimeException('Source file metadata changed while reading.'); }
        }
    }

    private function sizeLimit(int $size): void
    {
        if ($size > Manifest::MAX_RESTORED_BYTES) { throw new RuntimeException('V4 captured content exceeds its restored size limit.'); }
    }

    private static function key(array $entry): string { return hash('sha256', $entry['rootId'] . '/' . $entry['path']); }

    private static function rememberContent(MetadataMap $selected, string $bytes): void
    {
        $selected->put(V4Format::contentId(strlen($bytes), hash('sha256', $bytes)), []);
    }

    private static function rejectAgeIdentity(string $prefix): void
    {
        $offset = 0;
        while (preg_match('/(?:\A|\n)(AGE-SECRET-KEY-1[QPZRY9X8GF2TVDW0S3JN54KHCE6MUA7L]{58})(?:\r?\n|\z)/', $prefix, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $identity = strtolower($match[1][0]); $separator = strrpos($identity, '1'); $hrp = substr($identity, 0, $separator);
            $values = [];
            for ($i = 0; $i < strlen($hrp); ++$i) { $values[] = ord($hrp[$i]) >> 5; }
            $values[] = 0;
            for ($i = 0; $i < strlen($hrp); ++$i) { $values[] = ord($hrp[$i]) & 31; }
            $alphabet = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
            for ($i = $separator + 1; $i < strlen($identity); ++$i) { $values[] = strpos($alphabet, $identity[$i]); }
            $checksum = 1; $generators = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];
            foreach ($values as $value) {
                $top = $checksum >> 25; $checksum = (($checksum & 0x1ffffff) << 5) ^ $value;
                for ($bit = 0; $bit < 5; ++$bit) { if (($top >> $bit) & 1) { $checksum ^= $generators[$bit]; } }
            }
            if ($checksum === 1) { throw new RuntimeException('An age private identity is inside a capture source. Move the private key outside every source root before capture.'); }
            $offset = $match[1][1] + strlen($match[1][0]);
        }
    }

    private static function lane(string $path): string
    {
        if (preg_match('~(?:^|/)(?:wp-config\.php|\.env(?:\.[^/]*)?|\.htpasswd|id_rsa|id_ed25519)$~i', $path)) { return 'authentication'; }
        if (preg_match('~(?:^|/)uploads(?:/|$)~', $path)) { return 'media'; }
        if (preg_match('~(?:^|/)(?:forms?|submissions?)(?:/|$)~i', $path)) { return 'forms'; }
        if (preg_match('~^(?:wp-admin|wp-includes|wp-content/(?:plugins|themes|mu-plugins))(?:/|$)~', $path) || preg_match('/\.(?:php|js|css)$/i', $path)) { return 'code'; }
        return 'other';
    }
}
