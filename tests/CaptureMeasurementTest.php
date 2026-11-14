<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use WordPressR2Backup\AgeProcess;
use WordPressR2Backup\LiveBackup;
use WordPressR2Backup\Manifest;
use WordPressR2Backup\PrivacyPolicy;
use WordPressR2Backup\SourceRoots;
use WordPressR2Backup\V4Capture;
use WordPressR2Backup\V4Format;

require_once __DIR__ . '/WordPressDatabaseTest.php';

/**
 * Elapsed-time and count measurements of capture and transfer: integers only, no names or content, and no change to
 * any result or failure. Synthetic capture, transfer and publication only; no restore runs.
 */
final class CaptureMeasurementTest extends TestCase
{
    private const CAPTURE_FIELDS = ['version', 'scanBeforeCapture', 'policyContextMs', 'scanMs', 'scanEntries', 'scanSelected', 'scanFiles', 'scanFileBytes',
        'scanCollectedContents', 'lockWaitMs', 'lockContextMs', 'databasePrepassMs', 'databasePrepassBytes', 'filesPrepassMs', 'filesPrepassFiles',
        'filesPrepassBytes', 'databaseCaptureMs', 'databaseCaptureBytes', 'filesCaptureMs', 'filesCaptureFiles', 'filesCaptureBytes', 'filesFinalMs',
        'filesFinalEntries', 'lockHeldMs', 'lockReleaseMs', 'finishMs', 'sharedVerifyMs', 'sharedVerifyCalls', 'sharedVerifyPacks', 'sharedVerifyBytes',
        'encryptMs', 'encryptCalls', 'encryptPlainBytes', 'objects', 'totalMs'];

    protected function tearDown(): void { Fixture::cleanup(); }

    public function testCaptureMeasurementsAreIntegersThatMatchTheCaptureAndCarryNoNamesOrContent(): void
    {
        $root = Fixture::temp();
        Fixture::write($root, 'distinctive-directory/visible-name.php', 'visible body marker');
        Fixture::write($root, 'distinctive-directory/excluded-name.txt', 'excluded body marker');
        $keys = Fixture::keys(); $recipients = array_column($keys, 'public');
        $scope = ['accountId' => str_repeat('a', 32), 'bucket' => 'synthetic-capture', 'unitId' => str_repeat('c', 64), 'keySetId' => Manifest::keySetId($recipients), 'encoding' => V4Format::ENCODING];
        $policy = new PrivacyPolicy(['version' => 2, 'preset' => 'custom', 'paths' => ['distinctive-directory/excluded-name.txt' => 'exclude']]);
        $capture = new V4Capture(new SourceRoots($root), new V4DatabaseWpdbFake(), $policy, Fixture::temp());
        $result = $capture->capture(new AgeProcess(Fixture::runtime()['age']), $recipients, str_repeat('e', 64), $scope);
        $measured = $result['measurements'];
        self::assertSame($measured, $capture->measurements(), 'the result carries the same record the getter returns');
        self::assertSame([], array_diff(array_keys($measured), self::CAPTURE_FIELDS), 'only the fixed fields');
        foreach ($measured as $name => $value) { self::assertIsInt($value, $name); self::assertGreaterThanOrEqual(0, $value, $name); }
        foreach (['scanMs', 'lockWaitMs', 'lockHeldMs', 'databaseCaptureMs', 'filesCaptureMs', 'filesFinalMs', 'finishMs', 'encryptMs', 'totalMs'] as $name) { self::assertArrayHasKey($name, $measured); }
        self::assertSame(1, $measured['version']);
        self::assertSame($result['manifest']['counts']['files'], $measured['filesCaptureFiles']);
        self::assertSame($result['manifest']['counts']['fileBytes'], $measured['filesCaptureBytes']);
        self::assertSame($result['manifest']['database']['size'], $measured['databaseCaptureBytes']);
        self::assertSame($result['evidence']['scan']['selected'], $measured['scanSelected']);
        self::assertSame($result['evidence']['scan']['selected'], $measured['filesFinalEntries']);
        self::assertSame(count($result['objects']), $measured['objects']);
        self::assertSame(count($result['objects']), $measured['encryptCalls'], 'one encryption per ciphertext');
        self::assertGreaterThan(0, $measured['encryptPlainBytes']);
        self::assertSame(0, $measured['sharedVerifyPacks'], 'no sharing index, no shared-pack reads');
        $text = json_encode($measured, JSON_THROW_ON_ERROR);
        foreach (['distinctive', 'visible', 'excluded', 'marker', 'records', 'private@example.test', str_repeat('e', 64), $recipients[0], $root] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $text);
        }
    }

    public function testMeasurementsRemainAvailableAfterAFailureWhichIsUnchanged(): void
    {
        $root = Fixture::temp(); Fixture::write($root, 'a.php', 'initial body');
        $keys = Fixture::keys(); $recipients = array_column($keys, 'public');
        $scope = ['accountId' => str_repeat('a', 32), 'bucket' => 'synthetic-capture', 'unitId' => str_repeat('c', 64), 'keySetId' => Manifest::keySetId($recipients), 'encoding' => V4Format::ENCODING];
        $capture = new V4Capture(new SourceRoots($root), new V4DatabaseWpdbFake(), new PrivacyPolicy(['version' => 2, 'preset' => 'full']), Fixture::temp());
        $changed = false; $failure = null;
        try {
            $capture->capture(new AgeProcess(Fixture::runtime()['age']), $recipients, str_repeat('e', 64), $scope, static function (array $progress) use ($root, &$changed): void {
                if ($changed || $progress['phase'] !== 'capture') { return; }
                $changed = true; file_put_contents($root . '/a.php', 'mutated body');
            });
        } catch (RuntimeException $error) { $failure = $error->getMessage(); }
        self::assertTrue($changed);
        self::assertNotNull($failure, 'a changed source file still fails the capture');
        self::assertStringContainsString('changed', $failure);
        $measured = $capture->measurements();
        self::assertSame(1, $measured['version']);
        self::assertArrayHasKey('scanMs', $measured);
        self::assertArrayNotHasKey('totalMs', $measured, 'a failed capture records no completion');
        foreach ($measured as $name => $value) { self::assertContains($name, self::CAPTURE_FIELDS); self::assertIsInt($value); }
    }

    public function testEncryptionTotalsCountEveryStream(): void
    {
        $age = new AgeProcess(Fixture::runtime()['age']); $recipients = array_column(Fixture::keys(), 'public'); $directory = Fixture::temp();
        self::assertSame(['nanoseconds' => 0, 'calls' => 0, 'plainBytes' => 0], $age->streamTotals());
        $age->encryptStream(['abc', str_repeat('x', 20000)], $directory . '/one.age', $recipients);
        $age->encryptStream([''], $directory . '/two.age', $recipients);
        $totals = $age->streamTotals();
        self::assertSame(2, $totals['calls']); self::assertSame(20003, $totals['plainBytes']); self::assertGreaterThan(0, $totals['nanoseconds']);
    }

    /** @dataProvider transferScenarios */
    public function testTransferMeasurementsAddUpToTheSavedObjects(string $scenario): void
    {
        $output = tmpfile(); $error = tmpfile();
        $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/shared-pack-transfer.php', dirname(__DIR__), $scenario], [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $error], $pipes);
        self::assertIsResource($process); $exit = proc_close($process); rewind($output); rewind($error);
        $body = stream_get_contents($output); $message = stream_get_contents($error); fclose($output); fclose($error);
        self::assertSame(0, $exit, $message . $body);
        $result = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        $transfer = $result['stateMeasurements']['transfer'];
        self::assertSame(1, $result['stateMeasurements']['version']);
        foreach ($transfer as $kind => $values) {
            self::assertContains($kind, ['sharedPacks', 'pages', 'newPacks', 'otherObjects', 'publication']);
            foreach ($values as $name => $value) { self::assertMatchesRegularExpression('/\A[A-Za-z]+\z/', $name); self::assertIsInt($value); self::assertGreaterThanOrEqual(0, $value); }
        }
        if ($scenario === 'success') {
            $objects = 0; foreach ($transfer as $kind => $values) { if ($kind !== 'publication') { $objects += $values['objects']; } }
            self::assertSame($result['objects'], $objects, 'every object counted once');
            self::assertSame(['ms', 'steps', 'objects', 'bytes'], array_keys($transfer['sharedPacks']));
            self::assertSame(1, $transfer['sharedPacks']['steps']);
            self::assertSame($result['shared'], $transfer['sharedPacks']['objects']);
            self::assertSame($result['sharedBytes'], $transfer['sharedPacks']['bytes']);
            self::assertSame($result['objects'] + 1, $transfer['publication']['objects']);
            $capture = $result['captureMeasurements'];
            self::assertSame($result['shared'], $capture['sharedVerifyPacks'], 'the capture read every shared pack once');
            self::assertSame($result['sharedBytes'], $capture['sharedVerifyBytes']);
        } else {
            // An older job had no record: it starts from zero. The failed batch saved nothing, so two batches of four count.
            self::assertSame(2, $transfer['sharedPacks']['steps']);
            self::assertSame(8, $transfer['sharedPacks']['objects']);
        }
    }

    public function transferScenarios(): array { return ['guarded job' => ['success'], 'legacy job without a record' => ['legacy']]; }

    public function testMalformedOrMissingRecordsStartFromZeroWithoutFailing(): void
    {
        $method = new \ReflectionMethod(LiveBackup::class, 'withTransferMeasurement'); $method->setAccessible(true);
        $add = ['ms' => 5, 'steps' => 1, 'objects' => 2, 'bytes' => 30];
        foreach ([[], ['measurements' => 'not a record'], ['measurements' => ['transfer' => 'bad']], ['measurements' => ['transfer' => ['pages' => 7]]]] as $state) {
            $updated = $method->invoke(null, $state + ['status' => 'uploading'], 'pages', $add);
            self::assertSame($add, $updated['measurements']['transfer']['pages'], json_encode($state));
            self::assertSame('uploading', $updated['status'], 'the rest of the state is untouched');
        }
        $state = ['measurements' => ['version' => 1, 'transfer' => ['pages' => ['ms' => -4, 'steps' => 'two', 'objects' => 3, 'path/name' => 9, 'bytes' => 1.5]]]];
        $updated = $method->invoke(null, $state, 'pages', $add);
        self::assertSame(['objects' => 5, 'ms' => 5, 'steps' => 1, 'bytes' => 30], $updated['measurements']['transfer']['pages'], 'only valid integer fields are kept');
        $twice = $method->invoke(null, $method->invoke(null, [], 'sharedPacks', $add), 'sharedPacks', $add);
        self::assertSame(['ms' => 10, 'steps' => 2, 'objects' => 4, 'bytes' => 60], $twice['measurements']['transfer']['sharedPacks']);
    }
}
