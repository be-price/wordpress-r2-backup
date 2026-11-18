<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;

/**
 * assets/live.js polling, controls, progress semantics and announcements, run by Node against a minimal fake DOM with
 * scripted responses. No network, server, WordPress or restore.
 */
final class LiveProgressScriptTest extends TestCase
{
    /** @dataProvider scenarios */
    public function testProgressScript(string $scenario): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') { self::markTestSkipped('Node.js is required to run the admin script checks.'); }
        $process = proc_open([$node, __DIR__ . '/fixtures/live-progress-script.cjs', dirname(__DIR__), $scenario], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $output . $error);
        self::assertSame('ok ' . $scenario . "\n", $output);
    }

    public function scenarios(): array
    {
        return [
            'history notifications use authenticated exact run links and preserve confirmed records' => ['history-notices-track-exact-run'],
            'a finished page still checks and picks up a run started elsewhere' => ['idle-detects-new-run'],
            'returning to the tab always rechecks' => ['visible-rechecks'],
            'unconfirmed state disables start and resume' => ['unreachable-disables'],
            'unconfirmed state disables start on a finished page' => ['unreachable-idle-disables-start'],
            'labelled native progress, indeterminate without a total' => ['progress-semantics'],
            'completion is announced without moving focus' => ['completion-announced-without-focus'],
            'expired permission stops polling' => ['forbidden-stops'],
            'a command error disables controls until the state is checked' => ['command-error-disables'],
            'online starts only after explicit acknowledgment' => ['online-requires-ack'],
            'the cancel form follows the confirmed state' => ['cancel-follows-state'],
            'the existing-pack row does not claim R2 is never read' => ['measurement-labels'],
        ];
    }
}
