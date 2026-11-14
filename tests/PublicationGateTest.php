<?php
declare(strict_types=1);

namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\LocalRepository;

final class PublicationGateTest extends TestCase
{
    protected function tearDown(): void { Fixture::cleanup(); }

    public function testRejectedGatePreventsEveryObjectAndCompletionPublication(): void
    {
        $root = Fixture::temp();
        $unit = str_repeat('a', 64);
        $calls = 0;
        $guarded = LocalRepository::forWriting($root, $unit, static function () use (&$calls): void {
            $calls++;
            throw new \RuntimeException('Synthetic lease expired before publication.');
        });
        $reader = LocalRepository::forRecovery($root, $unit);
        $cipher = Fixture::temp() . '/cipher';
        file_put_contents($cipher, 'synthetic cipher');
        $snapshot = str_repeat('b', 64);
        $keys = ['files/' . str_repeat('c', 64) . '/' . str_repeat('d', 64) . '.age',
            'databases/' . $snapshot . '.age', 'manifests/' . $snapshot . '.age'];
        foreach ($keys as $key) {
            try {
                $guarded->putNew($key, $cipher);
            } catch (\RuntimeException $error) {
                self::assertSame('Synthetic lease expired before publication.', $error->getMessage());
            }
            self::assertFalse($guarded->hasObjects());
        }
        $writer = LocalRepository::forWriting($root, $unit);
        $manifest = $writer->putNew('manifests/' . $snapshot . '.age', $cipher);
        try {
            $guarded->publish($snapshot, $manifest);
        } catch (\RuntimeException $error) {
            self::assertSame('Synthetic lease expired before publication.', $error->getMessage());
        }
        self::assertSame([], $reader->listStatus());
        self::assertSame(4, $calls);
        $reader->verify($manifest);
    }
}
