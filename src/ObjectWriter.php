<?php
declare(strict_types=1);

namespace WordPressR2Backup;

interface ObjectWriter
{
    public function unitId(): string;
    public function putNew(string $objectKey, string $cipherFile): array;
    public function verify(array $receipt): void;
    public function publish(string $snapshotId, array $manifestReceipt): void;
    public function hasObjects(): bool;
}
