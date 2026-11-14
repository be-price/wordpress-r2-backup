<?php
declare(strict_types=1);

namespace WordPressR2Backup\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use WordPressR2Backup\Manifest;
use WordPressR2Backup\V4Format;

final class V4FormatTest extends TestCase
{
    protected function tearDown(): void { Fixture::cleanup(); }

    public function testContentIdentityAndScopeUseOnlyTheirOrderedInputs(): void
    {
        $hash = hash('sha256', 'abc');
        self::assertSame(hash('sha256', '3:' . $hash), V4Format::contentId(3, $hash));
        $scope = $this->scope();
        self::assertSame(hash('sha256', json_encode($scope, JSON_UNESCAPED_SLASHES)), V4Format::scopeId($scope));
        self::assertSame(V4Format::scopeId($scope), V4Format::scopeId(array_reverse($scope, true)));
        foreach (['accountId', 'bucket', 'unitId', 'keySetId'] as $field) {
            $changed = $scope; $changed[$field] = $field === 'bucket' ? 'other-bucket' : str_repeat('f', strlen($changed[$field]));
            self::assertNotSame(V4Format::scopeId($scope), V4Format::scopeId($changed));
        }
    }

    public function testValidRootRetainsPolicyAndScopeBindings(): void
    {
        $root = $this->root(); V4Format::validateRoot($root);
        $root['roots'][] = ['id' => 'r0002', 'name' => 'r0002', 'sourcePath' => '/source/wp-config.php', 'type' => 'file', 'mode' => 0600];
        $root['wordpress']['policy'] = ['mode' => 'full', 'settings' => ['日本語' => true]];
        $root['wordpress']['policySha256'] = hash('sha256', V4Format::json($root['wordpress']['policy']));
        V4Format::validateRoot($root); self::assertCount(2, $root['roots']);
    }

    public function testOnlineEvidenceIsDistinctAndReportedWithoutPretendingToHoldAReadLock(): void
    {
        $legacy = $this->root();
        $legacy['wordpress']['evidence'] = ['startedAt' => '2026-10-29T23:59:59Z', 'lockAcquiredAt' => '2026-10-30T00:00:00Z', 'verifiedAt' => '2026-10-30T00:00:02Z', 'finishedAt' => '2026-10-30T00:00:03Z'];
        V4Format::validateRoot($legacy);
        $online = $this->onlineRoot();
        V4Format::validateRoot($online);
        // Older v4 readers demand precisely these four wordpress fields and must reject the online root.
        $oldFields = ['siteUrl', 'policySha256', 'policy', 'evidence']; sort($oldFields);
        $actualFields = array_keys($online['wordpress']); sort($actualFields);
        self::assertNotSame($oldFields, $actualFields);
        $restorer = (new \ReflectionClass(\WordPressR2Backup\V4Restorer::class))->newInstanceWithoutConstructor();
        $summaryMethod = new \ReflectionMethod($restorer, 'summary'); $summaryMethod->setAccessible(true);
        $summary = $summaryMethod->invoke($restorer, $online, false);
        self::assertSame('online', $summary['capture']['consistencyMode']);
        self::assertFalse($summary['capture']['samePointInTimeGuaranteed']);
        self::assertSame($online['wordpress']['evidence']['snapshotAt'], $summary['capture']['snapshotAt']);
        self::assertSame($online['wordpress']['evidence']['filesVerifiedAt'], $summary['capture']['filesVerifiedAt']);
        self::assertArrayNotHasKey('lockAcquiredAt', $summary['capture']);
        $legacySummary = $summaryMethod->invoke($restorer, $legacy, false);
        self::assertSame('wordpress-v4', $legacySummary['capture']['method']);
        self::assertArrayNotHasKey('consistencyMode', $legacySummary['capture']);
        self::assertSame('2026-10-30T00:00:00Z', $legacySummary['capture']['lockAcquiredAt']);
    }

    public function testOnlineEvidenceRejectsMalformedOrMisleadingTimesAndModes(): void
    {
        $root = $this->onlineRoot();
        $cases = [];
        $invalid = $root; $invalid['wordpress']['consistencyMode'] = 'maintenance'; $cases[] = $invalid;
        $invalid = $root; unset($invalid['wordpress']['evidence']['snapshotAt']); $cases[] = $invalid;
        $invalid = $root; $invalid['wordpress']['evidence']['snapshotAt'] = '2026-02-30T00:00:00Z'; $cases[] = $invalid;
        $invalid = $root; $invalid['wordpress']['evidence']['snapshotAt'] = '2026-10-30T09:00:00+09:00'; $cases[] = $invalid;
        $invalid = $root; $invalid['wordpress']['evidence']['snapshotAt'] = '2026-10-30T00:00:01Z'; $cases[] = $invalid;
        $invalid = $root; $invalid['wordpress']['evidence']['databaseVerifiedAt'] = '2026-10-29T23:59:59Z'; $cases[] = $invalid;
        $invalid = $root; $invalid['wordpress']['evidence']['filesVerifiedAt'] = '2026-10-29T23:59:59Z'; $cases[] = $invalid;
        $invalid = $root; $invalid['wordpress']['evidence']['finishedAt'] = '2026-10-29T23:59:59Z'; $cases[] = $invalid;
        $invalid = $root; $invalid['wordpress']['evidence']['lockAcquiredAt'] = $root['capturedAt']; $cases[] = $invalid;
        $invalid = $root; $invalid['wordpress']['evidence']['verifiedAt'] = $root['capturedAt']; $cases[] = $invalid;
        foreach ($cases as $case) { $this->rejects(static function () use ($case): void { V4Format::validateRoot($case); }); }
    }

    /** @dataProvider invalidRootChanges */
    public function testRootRejectsBrokenContracts(array $path, $value): void
    {
        $root = $this->root(); $slot =& $root;
        foreach ($path as $key) { $slot =& $slot[$key]; }
        $slot = $value; unset($slot);
        $this->expectException(InvalidArgumentException::class); V4Format::validateRoot($root);
    }

    public static function invalidRootChanges(): array
    {
        return [
            [['extra'], true], [['version'], 3], [['capturedAt'], '2026-02-30T00:00:00Z'],
            [['scope', 'unitId'], str_repeat('f', 64)], [['scope', 'secretAccessKey'], 'must-not-be-here'],
            [['roots', 0, 'mode'], 04755], [['roots', 0, 'type'], 'socket'], [['roots', 0, 'sourcePath'], '/../source'],
            [['roots', 0, 'sourcePath'], 'relative'], [['roots', 0, 'name'], '../root'],
            [['counts', 'files'], 500001], [['counts', 'packs'], 1], [['counts', 'fileBytes'], 1099511627777],
            [['database', 'size'], 1], [['database', 'environment', 'extra'], true],
            [['wordpress', 'policySha256'], str_repeat('f', 64)], [['wordpress', 'siteUrl'], 'invalid'],
            [['wordpress', 'evidence'], ['not-json' => new \stdClass()]],
        ];
    }

    public function testRootRejectsRepeatedRootsAndMisboundOrUnorderedPages(): void
    {
        $root = $this->root(); $root['roots'][] = $root['roots'][0];
        $this->rejects(static function () use ($root): void { V4Format::validateRoot($root); });
        $root = $this->root(); $page = $this->page(); $descriptor = $this->descriptor($page);
        $root['pages'] = [$descriptor]; V4Format::validateRoot($root);
        $root['pages'][0]['sequence'] = 1;
        $this->rejects(static function () use ($root): void { V4Format::validateRoot($root); });
        $root['pages'][0] = $descriptor; $root['pages'][0]['object']['key'] = V4Format::pageKey(str_repeat('f', 64));
        $this->rejects(static function () use ($root): void { V4Format::validateRoot($root); });
    }

    public function testPageDescriptorChecksSnapshotCountSizeAndHash(): void
    {
        $page = $this->page(); $descriptor = $this->descriptor($page); V4Format::validatePage($page, $descriptor);
        foreach (['snapshotId', 'sequence', 'kind', 'records'] as $field) {
            $changed = $page;
            if ($field === 'snapshotId') { $changed[$field] = str_repeat('f', 64); }
            elseif ($field === 'sequence') { $changed[$field] = 1; }
            elseif ($field === 'kind') { $changed[$field] = 'packs'; }
            else { $changed[$field][] = $changed[$field][0]; }
            $this->rejects(static function () use ($changed, $descriptor): void { V4Format::validatePage($changed, $descriptor); });
        }
        foreach (['count', 'size', 'sha256'] as $field) {
            $changed = $descriptor; $changed[$field] = $field === 'sha256' ? str_repeat('0', 64) : $changed[$field] + 1;
            $this->rejects(static function () use ($page, $changed): void { V4Format::validatePage($page, $changed); });
        }
        $this->addToAssertionCount(1);
    }

    /** @dataProvider malformedRecords */
    public function testMalformedRecordsAreRejected(string $kind, array $record): void
    {
        $this->expectException(InvalidArgumentException::class); V4Format::validateRecord($kind, $record);
    }

    public static function malformedRecords(): array
    {
        $content = self::content();
        return [
            ['filesystem', ['type' => 'file', 'rootId' => 'r0001', 'path' => '../file', 'size' => 1, 'mode' => 0600]],
            ['filesystem', ['type' => 'directory', 'rootId' => 'r0001', 'path' => 'dir', 'mode' => 01777]],
            ['filesystem', ['type' => 'symlink', 'rootId' => 'r0001', 'path' => 'link', 'target' => '../escape']],
            ['filesystem', ['type' => 'symlink', 'rootId' => 'r0001', 'path' => 'dir/link', 'target' => '/absolute']],
            ['filesystem', ['type' => 'part', 'content' => array_merge($content, ['id' => str_repeat('f', 64)])]],
            ['filesystem', ['type' => 'end', 'sha256' => str_repeat('0', 64), 'extra' => true]],
            ['database', ['type' => 'sql', 'stage' => 'unknown', 'name' => 'table', 'content' => $content]],
            ['database', ['type' => 'table', 'name' => 'table', 'rows' => 2, 'sourceRows' => 1, 'excludedRows' => 0, 'definitionSha256' => str_repeat('0', 64), 'rowSha256' => str_repeat('0', 64), 'columns' => ['id'], 'order' => ['id'], 'foreignKeys' => []]],
            ['database', ['type' => 'sql', 'stage' => 'data', 'name' => str_repeat('a', 32769), 'content' => $content]],
        ];
    }

    public function testSupportedRecordsAndLargePackRecordAreAccepted(): void
    {
        foreach ([['type' => 'file', 'rootId' => 'r0001', 'path' => '日本語.txt', 'size' => 1, 'mode' => 0644],
            ['type' => 'symlink', 'rootId' => 'r0001', 'path' => 'dir/link', 'target' => '../日本語.txt'],
            ['type' => 'symlink', 'rootId' => 'r0001', 'path' => 'link', 'target' => 'directory//file'],
            ['type' => 'symlink', 'rootId' => 'r0001', 'path' => 'link', 'target' => 'directory/'],
            ['type' => 'part', 'content' => self::content()], ['type' => 'end', 'sha256' => str_repeat('0', 64)]] as $record) { V4Format::validateRecord('filesystem', $record); }
        V4Format::validateRecord('database', ['type' => 'sql', 'stage' => 'preamble', 'name' => 'preamble', 'content' => self::content()]);
        $members = [];
        for ($i = 0; $i < 512; ++$i) { $sha256 = hash('sha256', (string) $i); $members[] = ['id' => V4Format::contentId(1, $sha256), 'size' => 1, 'sha256' => $sha256]; }
        $record = ['object' => ['key' => self::content()['pack'], 'bytes' => 1, 'sha256' => str_repeat('0', 64)], 'lane' => 'code', 'members' => $members];
        self::assertGreaterThan(V4Format::MAX_RECORD_BYTES, strlen(V4Format::json($record)));
        V4Format::validateRecord('packs', $record);
        $record['members'][] = $members[0];
        $this->expectException(InvalidArgumentException::class); V4Format::validateRecord('packs', $record);
    }

    public function testCipherKeysAndSizeLimitsRejectLegacyOrMalformedReferences(): void
    {
        foreach (['packs/' . str_repeat('a', 64) . '/' . str_repeat('b', 64) . '.age', V4Format::pageKey(str_repeat('a', 64)), 'manifests/' . str_repeat('a', 64) . '.age'] as $key) {
            V4Format::validateReceipt(['key' => $key, 'bytes' => 1, 'sha256' => str_repeat('0', 64)]);
        }
        foreach (['files/' . str_repeat('a', 64) . '/' . str_repeat('b', 64) . '.age', 'packs/../bad.age', 'pages/' . str_repeat('A', 64) . '/' . str_repeat('b', 64) . '.age'] as $key) {
            $this->rejects(static function () use ($key): void { V4Format::validateReceipt(['key' => $key, 'bytes' => 1, 'sha256' => str_repeat('0', 64)]); });
        }
        $this->rejects(static function (): void { V4Format::contentId(1048577, str_repeat('0', 64)); });
        $this->rejects(static function (): void { V4Format::validateReceipt(['key' => self::content()['pack'], 'bytes' => V4Format::MAX_CIPHER_BYTES + 1, 'sha256' => str_repeat('0', 64)]); });
    }

    public function testDatabaseColumnsOrderAndForeignKeySchemaAreBoundedAndBound(): void
    {
        $table = ['type' => 'table', 'name' => 'wp_child', 'rows' => 1, 'sourceRows' => 2, 'excludedRows' => 1,
            'definitionSha256' => str_repeat('0', 64), 'rowSha256' => str_repeat('1', 64), 'columns' => ['id', 'parent_id'], 'order' => ['id'],
            'foreignKeys' => [['columns' => ['parent_id'], 'referencedTable' => 'wp_parent', 'referencedColumns' => ['id']]]];
        V4Format::validateRecord('database', $table); $this->addToAssertionCount(1);
        foreach ([['columns', []], ['columns', ['id', 'ID']], ['columns', [str_repeat('a', 65)]], ['order', []], ['order', ['missing']],
            ['order', ['id', 'id']], ['foreignKeys', [['columns' => ['missing'], 'referencedTable' => 'wp_parent', 'referencedColumns' => ['id']]]],
            ['foreignKeys', [['columns' => ['parent_id'], 'referencedTable' => 'wp_parent', 'referencedColumns' => ['id', 'other']]]],
            ['foreignKeys', [['columns' => ['parent_id'], 'referencedTable' => 'bad`table', 'referencedColumns' => ['id']]]],
            ['foreignKeys', [['columns' => ['parent_id'], 'referencedTable' => 'wp_parent', 'referencedColumns' => ['id'], 'extra' => true]]]] as [$field, $value]) {
            $invalid = $table; $invalid[$field] = $value;
            $this->rejects(static function () use ($invalid): void { V4Format::validateRecord('database', $invalid); });
        }
        $legacy = $table; unset($legacy['columns'], $legacy['order'], $legacy['foreignKeys']);
        $this->rejects(static function () use ($legacy): void { V4Format::validateRecord('database', $legacy); });
    }

    private function root(): array
    {
        $keys = Fixture::keys(); $scope = $this->scope(); $scope['keySetId'] = Manifest::keySetId(array_column($keys, 'public'));
        return ['format' => 'wordpress-r2-backup', 'version' => 4, 'dataClass' => 'wordpress', 'unitId' => $scope['unitId'], 'snapshotId' => str_repeat('b', 64),
            'keySetId' => $scope['keySetId'], 'recipients' => array_column($keys, 'public'), 'capturedAt' => '2026-10-30T00:00:00Z', 'encoding' => V4Format::ENCODING, 'scope' => $scope,
            'roots' => [['id' => 'r0001', 'name' => 'r0001', 'sourcePath' => '/source/wordpress', 'type' => 'directory', 'mode' => 0755]], 'pages' => [],
            'counts' => ['files' => 0, 'directories' => 0, 'links' => 0, 'contents' => 0, 'packs' => 0, 'databaseBytes' => 0, 'fileBytes' => 0],
            'database' => ['size' => 0, 'sha256' => hash('sha256', ''), 'tableCount' => 0, 'events' => [], 'environment' => ['serverVersion' => '8.0.42', 'sqlMode' => '', 'timeZone' => '+00:00', 'characterSet' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci']],
            'wordpress' => ['siteUrl' => 'https://example.test/', 'policySha256' => hash('sha256', V4Format::json([])), 'policy' => [], 'evidence' => []]];
    }

    private function onlineRoot(): array
    {
        $root = $this->root();
        $root['wordpress']['consistencyMode'] = 'online';
        $root['wordpress']['evidence'] = ['startedAt' => '2026-10-29T23:59:59Z', 'snapshotAt' => $root['capturedAt'],
            'databaseVerifiedAt' => '2026-10-30T00:00:01Z', 'filesVerifiedAt' => '2026-10-30T00:00:02Z',
            'finishedAt' => '2026-10-30T00:00:03Z', 'scan' => [], 'tables' => []];
        return $root;
    }

    private function scope(): array { return ['accountId' => str_repeat('a', 32), 'bucket' => 'synthetic-backup', 'unitId' => str_repeat('c', 64), 'keySetId' => str_repeat('d', 64), 'encoding' => V4Format::ENCODING]; }
    private static function content(): array { $hash = hash('sha256', 'x'); return ['id' => V4Format::contentId(1, $hash), 'size' => 1, 'sha256' => $hash, 'pack' => 'packs/' . str_repeat('c', 64) . '/' . str_repeat('d', 64) . '.age']; }
    private function page(): array { return ['version' => 4, 'snapshotId' => str_repeat('b', 64), 'kind' => 'filesystem', 'sequence' => 0, 'records' => [['type' => 'file', 'rootId' => 'r0001', 'path' => 'file', 'size' => 0, 'mode' => 0600]]]; }
    private function descriptor(array $page): array { $json = V4Format::json($page); return ['kind' => $page['kind'], 'sequence' => $page['sequence'], 'count' => count($page['records']), 'size' => strlen($json), 'sha256' => hash('sha256', $json), 'object' => ['key' => V4Format::pageKey($page['snapshotId']), 'bytes' => 1, 'sha256' => str_repeat('0', 64)]]; }
    private function rejects(callable $call): void { try { $call(); self::fail('Malformed v4 data must fail.'); } catch (InvalidArgumentException $expected) { $this->addToAssertionCount(1); } }
}
