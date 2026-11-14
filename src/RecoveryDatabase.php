<?php
declare(strict_types=1);

namespace WordPressR2Backup;

interface RecoveryDatabase
{
    public function importIntoEmpty(string $schema, string $sqlFile, ?array $expectedEvents = null): void;
    public function verifyV4Database(string $schema, iterable $tableRecords, array $environment): void;
}
