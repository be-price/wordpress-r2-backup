<?php
declare(strict_types=1);

namespace WordPressR2Backup;

interface ObjectReader
{
    public function unitId(): string;
    public function verify(array $receipt): void;
    public function download(array $receipt, string $newCipherFile): void;
    public function completed(string $snapshotId): array;
    public function listCompleted(): array;
    public function listStatus(): array;
    public function listFileObjects(string $keySetId): array;
}
