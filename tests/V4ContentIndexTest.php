<?php
declare(strict_types=1);

namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\MetadataMap;
use WordPressR2Backup\V4ContentIndex;
use WordPressR2Backup\V4Format;

final class V4ContentIndexTest extends TestCase
{
    public function testCandidateBecomesReusableOnlyAfterWholeCipherVerification(): void
    {
        $repository = new V4IndexRepository(); $directory = Fixture::temp();
        $index = V4ContentIndex::openOrCreate($directory, self::scope(), $repository);
        $pack = self::descriptor(); $root = hash('sha256', 'roots');
        $index->withLock(function () use ($index, $pack, $root, $repository): void {
            $index->recordCandidate($pack, $root);
            self::assertNull($index->lookup($pack['members'][0]['id']));
            $repository->objects[$pack['object']['key']] = $pack['object'];
            $index->markReady($pack['object']);
            self::assertSame(['descriptor' => $pack, 'rootScopeSha256' => $root], $index->lookup($pack['members'][0]['id']));
            self::assertSame($pack, $index->verifyReadyPack($pack['object']['key']));
        });
        self::assertSame([$pack['object'], $pack['object']], $repository->verified);
        $reopened = V4ContentIndex::openOrCreate($directory, self::scope(), $repository);
        self::assertSame($index->identity(), $reopened->identity());
        $reopened->withLock(function () use ($reopened, $pack): void { self::assertNotNull($reopened->lookup($pack['members'][1]['id'])); });
        self::assertSame(0600, fileperms($directory . '/scope.json') & 0777);
        self::assertSame(0600, fileperms($directory . '/unit.lock') & 0777);
        foreach (['packs', 'contents'] as $map) {
            foreach (glob($directory . '/' . $map . '/*') as $file) { self::assertSame(0600, fileperms($file) & 0777); }
        }
    }

    public function testReadyRetryRepairsEveryLocatorAfterAnInterruptedUpdate(): void
    {
        $repository = new V4IndexRepository(); $directory = Fixture::temp();
        $pack = self::descriptor(); $index = self::ready($directory, $repository, $pack);
        $map = MetadataMap::open($directory . '/contents');
        $other = self::descriptor('another');
        $index->withLock(function () use ($index, $other): void { $index->recordCandidate($other, null); });
        foreach ($pack['members'] as $member) { $map->put($member['id'], ['pack' => $other['object']['key']], true); }
        $index->withLock(function () use ($index, $pack): void {
            self::assertNull($index->lookup($pack['members'][0]['id']));
            $index->markReady($pack['object']);
            $index->markReady($pack['object']);
            foreach ($pack['members'] as $member) { self::assertSame($pack, $index->lookup($member['id'])['descriptor']); }
        });
    }

    public function testMismatchedReceiptCannotPublishACandidate(): void
    {
        $repository = new V4IndexRepository(); $index = V4ContentIndex::openOrCreate(Fixture::temp(), self::scope(), $repository);
        $pack = self::descriptor();
        $this->expectException(\RuntimeException::class);
        $index->withLock(function () use ($index, $pack): void {
            $index->recordCandidate($pack, null); $receipt = $pack['object']; $receipt['bytes']++;
            $index->markReady($receipt);
        });
    }

    public function testAnUnrecordedReceiptCannotBecomeReady(): void
    {
        $index = V4ContentIndex::openOrCreate(Fixture::temp(), self::scope(), new V4IndexRepository());
        $this->expectException(\RuntimeException::class);
        $index->withLock(function () use ($index): void { $index->markReady(self::descriptor()['object']); });
    }

    /** @dataProvider remoteFailure */
    public function testUnexpectedReadyCipherLossOrCorruptionStopsReuse(bool $corrupt): void
    {
        $repository = new V4IndexRepository(); $pack = self::descriptor();
        $index = self::ready(Fixture::temp(), $repository, $pack);
        if ($corrupt) { $repository->objects[$pack['object']['key']]['sha256'] = hash('sha256', 'corrupt'); }
        else { unset($repository->objects[$pack['object']['key']]); }
        $this->expectException(\RuntimeException::class);
        $index->withLock(function () use ($index, $pack): void { $index->verifyReadyPack($pack['object']['key']); });
    }

    public function remoteFailure(): array { return [[false], [true]]; }

    public function testExpectedRetentionDeletionOnlyInvalidatesTheCurrentScope(): void
    {
        $repository = new V4IndexRepository(); $pack = self::descriptor(); $index = self::ready(Fixture::temp(), $repository, $pack);
        $index->withLock(function () use ($index, $pack): void {
            $index->markMissing(['packs/' . str_repeat('f', 64) . '/' . str_repeat('1', 64) . '.age', 'pages/' . str_repeat('1', 64) . '/' . str_repeat('2', 64) . '.age']);
            self::assertNotNull($index->lookup($pack['members'][0]['id']));
            $index->markMissing([$pack['object']['key']]);
            self::assertNull($index->lookup($pack['members'][0]['id']));
            self::assertFalse($index->requiresMembershipProof(false, hash('sha256', 'roots')));
        });
    }

    public function testMissingIndexStopsWhenTheSameScopeHasAnyPack(): void
    {
        $repository = new V4IndexRepository(); $pack = self::descriptor(); $repository->objects[$pack['object']['key']] = $pack['object'];
        $directory = Fixture::temp();
        try { V4ContentIndex::openOrCreate($directory, self::scope(), $repository); self::fail('Stored packs require recovery.'); }
        catch (\RuntimeException $error) { self::assertStringContainsString('Rebuild', $error->getMessage()); }
        self::assertFileDoesNotExist($directory . '/scope.json');
    }

    public function testLegacyFilesAndOtherScopesDoNotPreventInitialization(): void
    {
        $repository = new V4IndexRepository();
        $repository->objects['files/legacy.age'] = ['key' => 'files/legacy.age', 'bytes' => 1, 'sha256' => hash('sha256', 'legacy')];
        $other = self::descriptor('other', array_replace(self::scope(), ['bucket' => 'other-bucket']));
        $repository->objects[$other['object']['key']] = $other['object'];
        self::assertSame(V4Format::scopeId(self::scope()), V4ContentIndex::openOrCreate(Fixture::temp(), self::scope(), $repository)->identity()['scopeId']);
    }

    public function testAHeaderlessNonemptyDirectoryIsNeverReinitialized(): void
    {
        $directory = Fixture::temp(); file_put_contents($directory . '/unrelated', 'existing');
        $this->expectException(\RuntimeException::class);
        V4ContentIndex::openOrCreate($directory, self::scope(), new V4IndexRepository());
    }

    /** @dataProvider changedScope */
    public function testScopeChangesCannotReuseAnIndex(string $field, string $value): void
    {
        $directory = Fixture::temp(); $repository = new V4IndexRepository();
        V4ContentIndex::openOrCreate($directory, self::scope(), $repository);
        $scope = self::scope(); $scope[$field] = $value;
        $this->expectException(\Throwable::class);
        V4ContentIndex::openOrCreate($directory, $scope, $repository);
    }

    public function changedScope(): array
    {
        return [['accountId', str_repeat('b', 32)], ['bucket', 'different-bucket'], ['unitId', str_repeat('b', 64)], ['keySetId', str_repeat('c', 64)], ['encoding', 'future-format']];
    }

    public function testCredentialRotationDoesNotChangeScopeOrPersistSecrets(): void
    {
        $directory = Fixture::temp(); $repository = new V4IndexRepository();
        $repository->credential = 'first-secret';
        $index = V4ContentIndex::openOrCreate($directory, self::scope(), $repository);
        $repository->credential = 'replacement-secret';
        self::assertSame($index->identity(), V4ContentIndex::openOrCreate($directory, self::scope(), $repository)->identity());
        self::assertStringNotContainsString('secret', file_get_contents($directory . '/scope.json'));
        $scope = self::scope(); $scope['credential'] = 'secret';
        $this->expectException(\InvalidArgumentException::class);
        V4ContentIndex::openOrCreate(Fixture::temp(), $scope, $repository);
    }

    public function testMembershipProofDependsOnReadyPackProvenanceAndFullSelection(): void
    {
        $repository = new V4IndexRepository(); $index = V4ContentIndex::openOrCreate(Fixture::temp(), self::scope(), $repository);
        $pack = self::descriptor(); $roots = hash('sha256', 'roots');
        $index->withLock(function () use ($index, $repository, $pack, $roots): void {
            self::assertFalse($index->requiresMembershipProof(false, $roots));
            $index->recordCandidate($pack, $roots);
            self::assertFalse($index->requiresMembershipProof(false, $roots));
            $repository->objects[$pack['object']['key']] = $pack['object']; $index->markReady($pack['object']);
            self::assertFalse($index->requiresMembershipProof(true, $roots));
            self::assertTrue($index->requiresMembershipProof(false, $roots));
            self::assertTrue($index->requiresMembershipProof(true, hash('sha256', 'changed')));
            $unknown = self::descriptor('unknown'); $repository->objects[$unknown['object']['key']] = $unknown['object'];
            $index->recordCandidate($unknown, null); $index->markReady($unknown['object']);
            self::assertTrue($index->requiresMembershipProof(true, $roots));
        });
    }

    public function testRootScopeIgnoresOrderModeIdentifiersAndTime(): void
    {
        $roots = [['sourcePath' => '/one', 'type' => 'directory', 'mode' => 0700, 'id' => 'r0001'], ['sourcePath' => '/two', 'type' => 'file', 'mode' => 0600]];
        $changed = array_reverse($roots); $changed[1]['mode'] = 0755; $changed[1]['id'] = 'r0020'; $changed[1]['time'] = 'later';
        self::assertSame(V4ContentIndex::rootScopeSha256($roots), V4ContentIndex::rootScopeSha256($changed));
        $changed[1]['sourcePath'] = '/another';
        self::assertNotSame(V4ContentIndex::rootScopeSha256($roots), V4ContentIndex::rootScopeSha256($changed));
        $changed = $roots; $changed[0]['type'] = 'file';
        self::assertNotSame(V4ContentIndex::rootScopeSha256($roots), V4ContentIndex::rootScopeSha256($changed));
    }

    public function testLockIsMandatoryAndReleasedAfterExceptions(): void
    {
        $index = V4ContentIndex::openOrCreate(Fixture::temp(), self::scope(), new V4IndexRepository());
        $operations = [static function () use ($index) { $index->lookup(str_repeat('a', 64)); }, static function () use ($index) { $index->recordCandidate(self::descriptor(), null); },
            static function () use ($index) { $index->markReady(self::descriptor()['object']); }, static function () use ($index) { $index->markMissing([]); },
            static function () use ($index) { $index->requiresMembershipProof(true, str_repeat('a', 64)); }, static function () use ($index) { $index->verifyReadyPack(self::descriptor()['object']['key']); }];
        foreach ($operations as $operation) {
            try { $operation(); self::fail('An unlocked operation must fail.'); } catch (\LogicException $error) { self::assertNotEmpty($error->getMessage()); }
        }
        try { $index->withLock(function () use ($index): void { $index->withLock(static function (): void {}); }); self::fail('Nested locks must fail.'); }
        catch (\LogicException $error) { self::assertStringContainsString('Nested', $error->getMessage()); }
        self::assertSame('released', $index->withLock(static function (): string { return 'released'; }));
    }

    public function testTheOperationHoldsAnExclusiveFilesystemLock(): void
    {
        $directory = Fixture::temp(); $index = V4ContentIndex::openOrCreate($directory, self::scope(), new V4IndexRepository());
        $second = fopen($directory . '/unit.lock', 'c+b');
        try {
            $index->withLock(function () use ($second): void { self::assertFalse(flock($second, LOCK_EX | LOCK_NB)); });
            self::assertTrue(flock($second, LOCK_EX | LOCK_NB));
            flock($second, LOCK_UN);
        } finally { fclose($second); }
    }

    public function testTheIndexDirectoryMustRemainOwnerOnly(): void
    {
        $directory = Fixture::temp(); chmod($directory, 0755);
        $this->expectException(\RuntimeException::class);
        V4ContentIndex::openOrCreate($directory, self::scope(), new V4IndexRepository());
    }

    /** @dataProvider damagedIndex */
    public function testDamagedOrUnsafeIndexNeverSilentlyReinitializes(string $damage): void
    {
        $directory = Fixture::temp(); $repository = new V4IndexRepository();
        $pack = self::descriptor(); self::ready($directory, $repository, $pack);
        $path = $directory . '/scope.json';
        if ($damage === 'permission') { chmod($path, 0644); }
        elseif ($damage === 'header') { file_put_contents($path, '{}'); }
        elseif ($damage === 'map') { unlink($directory . '/packs/owner'); }
        elseif ($damage === 'partition') { unlink(glob($directory . '/packs/leaf-*.json')[0]); }
        else {
            $header = json_decode(file_get_contents($path), true); unset($header['checksum']);
            if ($damage === 'schema') { $header['extra'] = 'unsupported'; }
            if ($damage === 'version') { $header['version'] = 5; }
            $header['checksum'] = $damage === 'checksum' ? str_repeat('0', 64) : hash('sha256', V4Format::json($header));
            file_put_contents($path, V4Format::json($header));
        }
        $this->expectException(\Throwable::class);
        $index = V4ContentIndex::openOrCreate($directory, self::scope(), $repository);
        $index->withLock(function () use ($index, $pack): void { $index->verifyReadyPack($pack['object']['key']); });
    }

    public function damagedIndex(): array { return [['permission'], ['header'], ['map'], ['partition'], ['schema'], ['version'], ['checksum']]; }

    public function testLookupRejectsALocatorWhoseMemberDoesNotExist(): void
    {
        $directory = Fixture::temp(); $repository = new V4IndexRepository(); $pack = self::descriptor(); $index = self::ready($directory, $repository, $pack);
        $id = hash('sha256', 'not-a-member'); MetadataMap::open($directory . '/contents')->put($id, ['pack' => $pack['object']['key']]);
        $this->expectException(\RuntimeException::class);
        $index->withLock(function () use ($index, $id): void { $index->lookup($id); });
    }

    public function testRebuildVerifiesEachCipherAndPublishesOnlyCompleteIndex(): void
    {
        $repository = new V4IndexRepository(); $pack = self::descriptor(); $repository->objects[$pack['object']['key']] = $pack['object'];
        $directory = Fixture::temp();
        $packs = (static function () use ($pack): \Generator { yield ['descriptor' => $pack, 'rootScopeSha256' => null]; })();
        $index = V4ContentIndex::rebuild($directory, self::scope(), $repository, $packs);
        self::assertSame([$pack['object']], $repository->verified);
        $index->withLock(function () use ($index, $pack): void { self::assertSame($pack, $index->lookup($pack['members'][0]['id'])['descriptor']); });
        self::assertSame($index->identity(), V4ContentIndex::openOrCreate($directory, self::scope(), $repository)->identity());
    }

    public function testSharedPackCanBeRebuiltFromGenerationsWithDifferentRootScopes(): void
    {
        $repository = new V4IndexRepository(); $pack = self::descriptor(); $repository->objects[$pack['object']['key']] = $pack['object'];
        $first = hash('sha256', 'first'); $second = hash('sha256', 'second');
        $index = V4ContentIndex::rebuild(Fixture::temp(), self::scope(), $repository, [
            ['descriptor' => $pack, 'rootScopeSha256' => null],
            ['descriptor' => $pack, 'rootScopeSha256' => $first],
            ['descriptor' => $pack, 'rootScopeSha256' => $second],
        ]);
        $index->withLock(function () use ($index, $pack, $first): void { self::assertSame($first, $index->lookup($pack['members'][0]['id'])['rootScopeSha256']); });
        self::assertCount(1, $repository->verified);
    }

    public function testFailedRebuildRemainsIncompleteAndCannotBeOverwritten(): void
    {
        $repository = new V4IndexRepository(); $directory = Fixture::temp();
        try { V4ContentIndex::rebuild($directory, self::scope(), $repository, [['descriptor' => self::descriptor(), 'rootScopeSha256' => null]]); self::fail('Missing cipher must stop rebuild.'); }
        catch (\RuntimeException $error) { self::assertSame('rebuilding', json_decode(file_get_contents($directory . '/scope.json'), true)['state']); }
        $before = file_get_contents($directory . '/scope.json');
        foreach ([static function () use ($directory, $repository): void { V4ContentIndex::openOrCreate($directory, self::scope(), $repository); },
            static function () use ($directory, $repository): void { V4ContentIndex::rebuild($directory, self::scope(), $repository, []); }] as $operation) {
            try { $operation(); self::fail('An incomplete index requires a new rebuild directory.'); } catch (\RuntimeException $error) { self::assertSame($before, file_get_contents($directory . '/scope.json')); }
        }
    }

    public function testRebuildRejectsOtherScopePacks(): void
    {
        $pack = self::descriptor('other', array_replace(self::scope(), ['bucket' => 'other-bucket']));
        $this->expectException(\RuntimeException::class);
        V4ContentIndex::rebuild(Fixture::temp(), self::scope(), new V4IndexRepository(), [['descriptor' => $pack, 'rootScopeSha256' => null]]);
    }

    private static function ready(string $directory, V4IndexRepository $repository, array $pack): V4ContentIndex
    {
        $index = V4ContentIndex::openOrCreate($directory, self::scope(), $repository);
        $repository->objects[$pack['object']['key']] = $pack['object'];
        $index->withLock(function () use ($index, $pack): void { $index->recordCandidate($pack, hash('sha256', 'roots')); $index->markReady($pack['object']); });
        return $index;
    }

    private static function descriptor(string $name = 'pack', ?array $scope = null): array
    {
        $members = [];
        foreach (['first', 'second'] as $text) { $sha256 = hash('sha256', $text); $members[] = ['id' => V4Format::contentId(strlen($text), $sha256), 'size' => strlen($text), 'sha256' => $sha256]; }
        return ['object' => ['key' => 'packs/' . V4Format::scopeId($scope ?? self::scope()) . '/' . hash('sha256', $name) . '.age', 'bytes' => 200, 'sha256' => hash('sha256', 'cipher-' . $name)], 'lane' => 'other', 'members' => $members];
    }

    private static function scope(): array
    {
        return ['accountId' => str_repeat('a', 32), 'bucket' => 'synthetic-bucket', 'unitId' => str_repeat('1', 64), 'keySetId' => str_repeat('2', 64), 'encoding' => V4Format::ENCODING];
    }
}

final class V4IndexRepository
{
    public array $objects = [];
    public array $verified = [];
    public string $credential = '';

    public function listPackObjects(string $scopeId): iterable
    {
        foreach ($this->objects as $receipt) { if (str_starts_with($receipt['key'], 'packs/' . $scopeId . '/')) { yield $receipt; } }
    }

    public function verify(array $receipt): void
    {
        $this->verified[] = $receipt;
        if (($this->objects[$receipt['key']] ?? null) !== $receipt) { throw new \RuntimeException('Stored ciphertext is missing or corrupt.'); }
    }
}
