<?php
declare(strict_types=1);

namespace WordPressR2Backup\Tests;

use InvalidArgumentException;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\CaptureReport;

final class CaptureReportTest extends TestCase
{
    public function testProjectsOnlyPortableEvidenceAndBindsBothInventories(): void
    {
        [$record, $plan, $files, $database, $expected] = $this->fixture();
        $report = CaptureReport::fromRecord($record, $plan);
        self::assertSame($expected, $report);
        CaptureReport::validate($report, $files, $database);
        $encoded = json_encode($report, JSON_THROW_ON_ERROR);
        foreach (['private-source', 'wrb_test_private', 'password', 'PRIVATE KEY', 'SELECT secret'] as $secret) {
            self::assertStringNotContainsString($secret, $encoded);
        }
    }

    public function testFileDigestIgnoresObjectAndInputFieldOrder(): void
    {
        [$record, $plan, $files, $database] = $this->fixture();
        $report = CaptureReport::fromRecord($record, $plan);
        $files = array_reverse($files);
        $files[1] = array_reverse($files[1], true);
        $files[1]['object'] = ['key' => 'other-ciphertext'];
        CaptureReport::validate($report, $files, $database);
        self::assertSame($report, CaptureReport::fromRecord($record, $plan));
    }

    public static function invalidReports(): array
    {
        return [
            'unknown method' => [['method'], 'other-method'],
            'unsupported server family' => [['database', 'serverVersion'], '10.11.9-MariaDB'],
            'unsupported MySQL series' => [['database', 'serverVersion'], '8.4.1'],
            'extra root field' => [['sourceRoot'], '/private-source'],
            'extra file field' => [['file', 'sourceRoot'], '/private-source'],
            'extra database field' => [['database', 'schema'], 'wrb_test_private'],
            'wrong file digest' => [['file', 'inventorySha256'], str_repeat('0', 64)],
            'wrong database digest' => [['database', 'inventorySha256'], str_repeat('0', 64)],
            'wrong SQL hash' => [['database', 'sqlHash'], str_repeat('0', 64)],
            'wrong SQL bytes' => [['database', 'sqlBytes'], 8],
            'string SQL bytes' => [['database', 'sqlBytes'], '7'],
            'empty version' => [['database', 'serverVersion'], ''],
            'non UTC time' => [['startedAt'], '2026-10-19T00:00:00.000000+00:00'],
            'missing microseconds' => [['fixedAt'], '2026-10-19T00:00:03Z'],
            'impossible date' => [['fixedAt'], '2026-02-30T00:00:03.000000Z'],
            'reversed time' => [['file', 'releasedAt'], '2026-10-19T00:00:02.000000Z'],
            'table secret' => [['database', 'tables', 0, 'password'], 'secret'],
            'unsupported engine' => [['database', 'tables', 0, 'engine'], 'unknown'],
            'invalid table type' => [['database', 'tables', 0, 'type'], 'SYSTEM VIEW'],
            'view engine' => [['database', 'tables', 1, 'engine'], 'InnoDB'],
            'bad definition digest' => [['database', 'definitions', 'views', 0, 'sha256'], 'bad'],
            'definition SQL' => [['database', 'definitions', 'views', 0, 'sql'], 'SELECT secret'],
            'invalid routine type' => [['database', 'definitions', 'routines', 0, 'type'], 'TRIGGER'],
        ];
    }

    /** @dataProvider invalidReports */
    public function testRejectsInvalidReport(array $path, $value): void
    {
        [, , $files, $database, $report] = $this->fixture();
        $target = &$report;
        foreach (array_slice($path, 0, -1) as $part) { $target = &$target[$part]; }
        $target[$path[array_key_last($path)]] = $value;
        $this->expectException(InvalidArgumentException::class);
        CaptureReport::validate($report, $files, $database);
    }

    public function testRejectsMissingEvidenceAndChangedFileContents(): void
    {
        [, , $files, $database, $report] = $this->fixture();
        unset($report['sealedAt']);
        $this->rejects($report, $files, $database);
        [, , $files, $database, $report] = $this->fixture();
        $files[0]['sha256'] = hash('sha256', 'changed');
        $this->rejects($report, $files, $database);
    }

    public function testRejectsSelfConsistentButUnorderedOrDuplicateDatabaseFacts(): void
    {
        foreach (['unordered tables', 'duplicate tables', 'unordered definitions'] as $case) {
            [, , $files, $database, $report] = $this->fixture();
            if ($case === 'unordered tables') { $report['database']['tables'] = array_reverse($report['database']['tables']); }
            if ($case === 'duplicate tables') { $report['database']['tables'][] = $report['database']['tables'][1]; }
            if ($case === 'unordered definitions') {
                $report['database']['definitions']['views'][] = ['name' => 'a_view', 'sha256' => str_repeat('b', 64)];
            }
            $report['database']['inventorySha256'] = $this->digest(['tables' => $report['database']['tables'], 'definitions' => $report['database']['definitions']]);
            $this->rejects($report, $files, $database);
        }
    }

    private function rejects(array $report, array $files, array $database): void
    {
        try { CaptureReport::validate($report, $files, $database); self::fail('Expected invalid report to be rejected.'); }
        catch (InvalidArgumentException $ignoredError) { $this->addToAssertionCount(1); }
    }

    private function fixture(): array
    {
        $inventory = [
            ['path' => 'uploads', 'type' => 'directory'],
            ['path' => 'uploads/日本語.txt', 'type' => 'file', 'size' => 3, 'sha256' => hash('sha256', 'abc')],
        ];
        $tables = [['name' => 'posts', 'type' => 'BASE TABLE', 'engine' => 'InnoDB'], ['name' => 'posts_view', 'type' => 'VIEW', 'engine' => null]];
        $definitions = ['views' => [['name' => 'posts_view', 'sha256' => str_repeat('a', 64)]], 'triggers' => [],
            'routines' => [['name' => 'refresh', 'type' => 'PROCEDURE', 'sha256' => str_repeat('b', 64)]], 'events' => []];
        $databaseFacts = ['lockedAt' => '2026-10-19T00:00:02.000000Z', 'releasedAt' => '2026-10-19T00:00:04.000000Z',
            'serverVersion' => '8.0.35', 'tables' => $tables, 'definitions' => $definitions,
            'inventorySha256' => $this->digest(['tables' => $tables, 'definitions' => $definitions])];
        $record = ['version' => 1, 'sourceRoot' => '/private-source', 'startedAt' => '2026-10-19T00:00:00.000000Z',
            'fixedAt' => '2026-10-19T00:00:03.000000Z', 'sealedAt' => '2026-10-19T00:00:06.000000Z',
            'file' => ['pausedAt' => '2026-10-19T00:00:01.000000Z', 'releasedAt' => '2026-10-19T00:00:05.000000Z'],
            'database' => $databaseFacts + ['version' => 1, 'schema' => 'wrb_test_private'],
            'inventory' => $inventory, 'sqlHash' => hash('sha256', 'sql'), 'sqlBytes' => 3,
            'password' => 'password', 'identity' => 'PRIVATE KEY', 'sql' => 'SELECT secret'];
        $plan = ['root' => '/private-source', 'sql' => '/private-source.sql', 'inventory' => $inventory,
            'sqlHash' => $record['sqlHash'], 'sqlBytes' => $record['sqlBytes']];
        $files = [$inventory[1] + ['object' => ['key' => 'ciphertext']], $inventory[0]];
        $database = ['size' => 3, 'sha256' => $record['sqlHash'], 'object' => ['key' => 'database-ciphertext']];
        $expected = ['method' => 'synthetic-files-mysql-global-read-lock-v1', 'startedAt' => $record['startedAt'],
            'fixedAt' => $record['fixedAt'], 'sealedAt' => $record['sealedAt'],
            'file' => $record['file'] + ['inventorySha256' => $this->digest($inventory)],
            'database' => $databaseFacts + ['sqlHash' => $record['sqlHash'], 'sqlBytes' => 3]];
        return [$record, $plan, $files, $database, $expected];
    }

    private function digest(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
