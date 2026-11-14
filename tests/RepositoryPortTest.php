<?php
declare(strict_types=1);

namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\{LocalRepository, ObjectReader, ObjectWriter};

final class RepositoryPortTest extends TestCase
{
    protected function tearDown(): void { Fixture::cleanup(); }

    public function testDownloadCreatesOnlyNewVerifiedCiphertext(): void
    {
        [$writer,$reader,$receipt]= $this->repository();
        $destination=Fixture::temp() . '/cipher.age';
        $reader->download($receipt,$destination);
        self::assertInstanceOf(ObjectWriter::class,$writer);
        self::assertInstanceOf(ObjectReader::class,$reader);
        self::assertSame('synthetic ciphertext',file_get_contents($destination));
        self::assertSame(0600,fileperms($destination)&0777);
        $this->assertRejected(fn () => $reader->download($receipt, $destination));
        self::assertSame('synthetic ciphertext', file_get_contents($destination));
        self::assertSame(['.','..','cipher.age'],scandir(dirname($destination)));
    }

    public function testCorruptAndMissingObjectsDoNotPublish(): void
    {
        [, $reader,$receipt]=$this->repository();
        $source=$reader->objectPath($receipt['key']);
        file_put_contents($source,'changed ciphertext');
        $parent=Fixture::temp();
        foreach ([false,true] as $missing) {
            if ($missing) { unlink($source); }
            $this->assertRejected(fn () => $reader->download($receipt, $parent . '/cipher'));
            self::assertSame(['.', '..'], scandir($parent));
        }
    }

    public function testDownloadRefusesLinksAndWriterRole(): void
    {
        [$writer,$reader,$receipt]=$this->repository();
        $parent=Fixture::temp(); Fixture::write($parent,'sentinel','retained');
        symlink($parent . '/sentinel',$parent . '/link');
        foreach ([fn ()=>$reader->download($receipt,$parent . '/link'),fn ()=>$writer->download($receipt,$parent . '/new')] as $operation) {
            $this->assertRejected($operation);
            self::assertSame('retained', file_get_contents($parent . '/sentinel'));
        }
        self::assertFileDoesNotExist($parent . '/new');
    }

    private function assertRejected(callable $operation): void
    {
        try { $operation(); }
        catch (\RuntimeException $ignoredError) { $this->addToAssertionCount(1); return; }
        self::fail('The operation must be rejected.');
    }

    private function repository(): array
    {
        $root=Fixture::temp(); $unit=str_repeat('a',64);
        $writer=LocalRepository::forWriting($root,$unit); $reader=LocalRepository::forRecovery($root,$unit);
        $cipher=Fixture::temp() . '/source.age'; file_put_contents($cipher,'synthetic ciphertext');
        $receipt=$writer->putNew('files/' . str_repeat('b',64) . '/' . str_repeat('c',64) . '.age',$cipher);
        return [$writer,$reader,$receipt];
    }
}
