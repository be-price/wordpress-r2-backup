<?php
declare(strict_types=1);

namespace WordPressR2Backup\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use WordPressR2Backup\AgeProcess;
use WordPressR2Backup\BackupArchive;
use WordPressR2Backup\V4Format;
use WordPressR2Backup\V4Pack;

final class V4PackTest extends TestCase
{
    private string $directory;
    private array $keys;
    private AgeProcess $age;

    protected function setUp(): void
    {
        $this->directory = Fixture::temp(); $this->keys = Fixture::keys(); $this->age = new AgeProcess(Fixture::runtime()['age']);
    }
    protected function tearDown(): void { Fixture::cleanup(); }

    public function testReservedPackKeyRemainsBoundToItsScope(): void
    {
        $scope = str_repeat('a', 64);
        $key = V4Format::packKey($scope);
        $pack = V4Pack::write($this->age, array_column($this->keys, 'public'), $this->directory, $scope, 'code', [$this->content('content')], $key);
        self::assertSame($key, $pack['descriptor']['object']['key']);
        $this->expectException(\InvalidArgumentException::class);
        V4Pack::write($this->age, array_column($this->keys, 'public'), $this->directory, str_repeat('b', 64), 'code', [$this->content('content')], $key);
    }

    public function testEncryptedPackRestoresExactMembersForEitherRecipientWithoutPlainStaging(): void
    {
        $contents = [$this->content('private synthetic bytes'), $this->content(random_bytes(1048576)), $this->content('')];
        $pack = V4Pack::write($this->age, array_column($this->keys, 'public'), $this->directory, str_repeat('a', 64), 'authentication', $contents);
        self::assertSame([basename($pack['cipherFile'])], array_values(array_diff(scandir($this->directory), ['.', '..'])));
        self::assertSame(0600, fileperms($pack['cipherFile']) & 0777);
        self::assertSame(filesize($pack['cipherFile']), $pack['descriptor']['object']['bytes']);
        self::assertSame(hash_file('sha256', $pack['cipherFile']), $pack['descriptor']['object']['sha256']);
        self::assertSame('authentication', $pack['descriptor']['lane']);
        foreach ($this->keys as $index => $key) {
            $tar = $this->directory . '/decrypted-' . $index; $identity = fopen($key['identity'], 'rb');
            try { $this->age->decrypt($pack['cipherFile'], $tar, $identity); } finally { fclose($identity); }
            $destination = $this->directory . '/restored-' . $index;
            V4Pack::extract($tar, $destination, $pack['descriptor']);
            self::assertSame(0700, fileperms($destination) & 0777);
            foreach ($contents as $content) {
                self::assertSame($content['bytes'], file_get_contents($destination . '/' . $content['id']));
                self::assertSame(0600, fileperms($destination . '/' . $content['id']) & 0777);
            }
        }
    }

    public function testMaximumMemberCountUsesOnePackAndRejectsOneMore(): void
    {
        $contents = [];
        for ($i = 0; $i < 512; ++$i) { $contents[] = $this->content((string) $i); }
        $pack = V4Pack::write($this->age, array_column($this->keys, 'public'), $this->directory, str_repeat('a', 64), 'code', $contents);
        self::assertCount(512, $pack['descriptor']['members']); V4Format::validateRecord('packs', $pack['descriptor']);
        $contents[] = $this->content('one-more'); $before = scandir($this->directory);
        try { V4Pack::write($this->age, array_column($this->keys, 'public'), $this->directory, str_repeat('a', 64), 'code', $contents); self::fail('An oversized pack must fail.'); }
        catch (InvalidArgumentException $expected) { self::assertSame($before, scandir($this->directory)); }
    }

    public function testInvalidContentsFailBeforeProducingCiphertext(): void
    {
        $valid = $this->content('abc');
        $bad = [];
        foreach (['id', 'size', 'sha256', 'bytes'] as $field) {
            $changed = $valid; $changed[$field] = $field === 'size' ? 4 : ($field === 'bytes' ? 'modified' : str_repeat('f', 64)); $bad[] = [$changed];
        }
        $bad[] = [$valid, $valid]; $bad[] = [$valid + ['extra' => true]]; $bad[] = [$this->content(str_repeat('x', 1048577))];
        $large = [];
        for ($i = 0; $i < 33; ++$i) { $large[] = $this->content(str_repeat(chr(65 + $i), 1048576)); }
        $bad[] = $large;
        foreach ($bad as $contents) {
            try { V4Pack::write($this->age, array_column($this->keys, 'public'), $this->directory, str_repeat('a', 64), 'other', $contents); self::fail('Invalid contents must fail.'); }
            catch (InvalidArgumentException $expected) { self::assertSame(['.', '..'], scandir($this->directory)); }
        }
    }

    public function testFullArchiveAndMemberMismatchesAreRejectedBeforeDestinationCreation(): void
    {
        $content = $this->content('abc'); $member = $content; unset($member['bytes']);
        $descriptor = ['object' => ['key' => V4Format::packKey(str_repeat('a', 64)), 'bytes' => 1, 'sha256' => str_repeat('0', 64)], 'lane' => 'code', 'members' => [$member]];
        $valid = $this->tar([['path' => $content['id'], 'type' => 'file', 'size' => 3, 'chunks' => ['abc']]]);
        $directory = $this->tar([['path' => $content['id'], 'type' => 'directory']]);
        $extra = $this->tar([['path' => 'unexpected', 'type' => 'file', 'size' => 3, 'chunks' => ['abc']]]);
        $wrong = $this->tar([['path' => $content['id'], 'type' => 'file', 'size' => 3, 'chunks' => ['bad']]]);
        $link = $valid; $link[156] = '2'; $link = $this->checksum($link);
        $variants = [$directory, $extra, $wrong, substr($valid, 0, -1024) . $extra, substr($valid, 0, -1024) . $valid, $link, substr($valid, 0, -1), $valid . 'garbage'];
        foreach ($variants as $index => $bytes) {
            $tar = $this->directory . '/bad-' . $index . '.tar'; file_put_contents($tar, $bytes); chmod($tar, 0600); $destination = $this->directory . '/never-' . $index;
            try { V4Pack::extract($tar, $destination, $descriptor); self::fail('A mismatched or malformed archive must fail.'); }
            catch (\Throwable $expected) {
                if (!$expected instanceof InvalidArgumentException && !$expected instanceof RuntimeException) { throw $expected; }
                self::assertFileDoesNotExist($destination);
            }
            try { V4Pack::index($tar, $descriptor); self::fail('A mismatched or malformed archive cannot be indexed.'); }
            catch (InvalidArgumentException | RuntimeException $expected) { self::assertTrue(true); }
        }
    }

    public function testExistingDestinationIsPreserved(): void
    {
        $content = $this->content('abc'); $member = $content; unset($member['bytes']);
        $tar = $this->directory . '/valid.tar'; file_put_contents($tar, $this->tar([['path' => $content['id'], 'type' => 'file', 'size' => 3, 'chunks' => ['abc']]]));
        $destination = $this->directory . '/existing'; mkdir($destination, 0700);
        $descriptor = ['object' => ['key' => V4Format::packKey(str_repeat('a', 64)), 'bytes' => 1, 'sha256' => str_repeat('0', 64)], 'lane' => 'code', 'members' => [$member]];
        try { V4Pack::extract($tar, $destination, $descriptor); self::fail('Even an empty existing destination must be preserved.'); }
        catch (RuntimeException $expected) { self::assertDirectoryExists($destination); self::assertSame(['.', '..'], scandir($destination)); }
    }

    public function testIndexedPackKeepsNoExtractedFilesAndReadsEveryMember(): void
    {
        $contents = [$this->content('日本語'), $this->content("\0\xffbinary"), $this->content('')];
        [$tar, $descriptor] = $this->plainPack($contents);
        $before = scandir($this->directory); $index = V4Pack::index($tar, $descriptor);
        self::assertSame($before, scandir($this->directory)); self::assertCount(3, $index['members']);
        $expected = array_column($contents, 'bytes', 'id');
        foreach ($index['members'] as $member) { self::assertSame($expected[$member['id']], V4Pack::readIndexed($tar, $index['identity'], $member)); }
    }

    public function testIndexUsesTheExistingPaxScannerOffsets(): void
    {
        $content = $this->content('pax body'); [$tar, $descriptor] = $this->plainPack([$content]); $valid = file_get_contents($tar);
        $value = 'path=' . $content['id'] . "\n"; $length = strlen($value) + 3;
        while ($length !== strlen((string) $length) + 1 + strlen($value)) { $length = strlen((string) $length) + 1 + strlen($value); }
        $payload = $length . ' ' . $value; $header = substr($valid, 0, 512);
        $header = substr_replace($header, str_pad('PaxHeaders/x', 100, "\0"), 0, 100);
        $header = substr_replace($header, sprintf('%011o', strlen($payload)) . "\0", 124, 12); $header[156] = 'x';
        $header = $this->checksum($header);
        file_put_contents($tar, $header . $payload . str_repeat("\0", (512 - strlen($payload) % 512) % 512) . $valid);
        $index = V4Pack::index($tar, $descriptor);
        self::assertSame(1536, $index['members'][0]['offset']);
        self::assertSame($content['bytes'], V4Pack::readIndexed($tar, $index['identity'], $index['members'][0]));
    }

    public function testIndexedRangesAndSameLengthTamperingAreRejected(): void
    {
        [$tar, $descriptor] = $this->plainPack([$this->content('abc')]); $index = V4Pack::index($tar, $descriptor); $member = $index['members'][0];
        foreach ([0, -512, 513, PHP_INT_MAX] as $offset) {
            try { V4Pack::readIndexed($tar, $index['identity'], array_replace($member, ['offset' => $offset])); self::fail('An invalid range must fail.'); }
            catch (RuntimeException $expected) { self::assertTrue(true); }
        }
        $empty = $this->content(''); unset($empty['bytes']); $empty['offset'] = $index['identity']['size'];
        try { V4Pack::readIndexed($tar, $index['identity'], $empty); self::fail('The terminator is not an indexed content range.'); }
        catch (RuntimeException $expected) { self::assertTrue(true); }
        $stream = fopen($tar, 'r+b'); fseek($stream, $member['offset']); fwrite($stream, 'bad'); fclose($stream);
        $this->expectException(RuntimeException::class); V4Pack::readIndexed($tar, $index['identity'], $member);
    }

    public function testIndexedArchiveReplacementHardLinksAndPermissionsAreRejected(): void
    {
        [$tar, $descriptor] = $this->plainPack([$this->content('abc')]); $index = V4Pack::index($tar, $descriptor); $member = $index['members'][0];
        chmod($tar, 0644);
        try { V4Pack::readIndexed($tar, $index['identity'], $member); self::fail('Changed permissions must fail.'); }
        catch (RuntimeException $expected) { self::assertTrue(true); }
        chmod($tar, 0600); link($tar, $this->directory . '/alias');
        try { V4Pack::readIndexed($tar, $index['identity'], $member); self::fail('A hard link must fail.'); }
        catch (RuntimeException $expected) { self::assertTrue(true); }
        unlink($this->directory . '/alias'); rename($tar, $tar . '.retained'); copy($tar . '.retained', $tar); chmod($tar, 0600);
        $this->expectException(RuntimeException::class); V4Pack::readIndexed($tar, $index['identity'], $member);
    }

    public function testIndexRejectsAllMalformedOrMismatchedMembers(): void
    {
        $content = $this->content('abc'); [$tar, $descriptor] = $this->plainPack([$content]); $valid = file_get_contents($tar);
        $wrong = $descriptor; $wrong['members'][0]['sha256'] = hash('sha256', 'bad'); $wrong['members'][0]['id'] = V4Format::contentId(3, $wrong['members'][0]['sha256']);
        foreach ([$valid . 'garbage', substr($valid, 0, -1), str_replace('abc', 'bad', $valid)] as $bytes) {
            file_put_contents($tar, $bytes);
            try { V4Pack::index($tar, $descriptor); self::fail('Invalid archive content must fail.'); }
            catch (RuntimeException $expected) { self::assertTrue(true); }
        }
        file_put_contents($tar, $valid); $this->expectException(RuntimeException::class); V4Pack::index($tar, $wrong);
    }

    public function testIndexedReadStillRejectsAParentThatLostItsPrivateMode(): void
    {
        [$tar, $descriptor] = $this->plainPack([$this->content('abc')]);
        $index = V4Pack::index($tar, $descriptor);
        chmod(dirname($tar), 0755);
        try {
            $this->expectException(RuntimeException::class);
            V4Pack::readIndexed($tar, $index['identity'], $index['members'][0]);
        } finally { chmod(dirname($tar), 0700); }
    }

    private function plainPack(array $contents): array
    {
        $entries = []; $members = [];
        foreach ($contents as $content) {
            $entries[] = ['path' => $content['id'], 'type' => 'file', 'size' => $content['size'], 'chunks' => [$content['bytes']]];
            unset($content['bytes']); $members[] = $content;
        }
        $tar = $this->directory . '/plain-' . bin2hex(random_bytes(8)) . '.tar'; file_put_contents($tar, $this->tar($entries)); chmod($tar, 0600);
        return [$tar, ['object' => ['key' => V4Format::packKey(str_repeat('a', 64)), 'bytes' => 1, 'sha256' => str_repeat('0', 64)], 'lane' => 'code', 'members' => $members]];
    }

    private function content(string $bytes): array
    {
        $size = strlen($bytes); $hash = hash('sha256', $bytes);
        return ['id' => hash('sha256', $size . ':' . $hash), 'size' => $size, 'sha256' => $hash, 'bytes' => $bytes];
    }
    private function tar(array $entries): string { return implode('', iterator_to_array(BackupArchive::chunks($entries))); }
    private function checksum(string $tar): string
    {
        $header = substr_replace(substr($tar, 0, 512), str_repeat(' ', 8), 148, 8);
        return substr_replace($tar, sprintf('%06o', array_sum(unpack('C*', $header))) . "\0 ", 148, 8);
    }
}
