<?php
declare(strict_types=1);

// Real WordPress core with synthetic content, for checking both restore entries by hand. Reads the official archive; touches no real site,
// person's data, key or R2. Stop with Ctrl-C; everything is removed. Details are written to a 0600 file, not to the terminal.
// php tests/restore-real-wp-fixture.php --wordpress-archive <official.tar.gz> [--host-port 8771] [--web-port 8770] [--kit <new-kit-directory>]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/Fixture.php';
require __DIR__ . '/IsolatedMysql.php';
require __DIR__ . '/RestoreTestBed.php';
require __DIR__ . '/RestoreRealWordPress.php';

use WordPressR2Backup\Tests\{Fixture, RestoreRealWordPress, RestoreTestBed};

$options = getopt('', ['wordpress-archive:', 'host-port:', 'web-port:', 'kit:']);
if (!isset($options['wordpress-archive'])) { fwrite(STDERR, "--wordpress-archive <official.tar.gz> is required.\n"); exit(2); }
$hostPort = (int) ($options['host-port'] ?? 8771); $webPort = (int) ($options['web-port'] ?? 8770);
$fixture = RestoreRealWordPress::build($options['wordpress-archive'], $hostPort);
$state = Fixture::temp(); $kit = $options['kit'] ?? null;
$script = $kit !== null ? $kit . '/recover-web.php' : dirname(__DIR__) . '/recovery/recover-web.php';
$age = $kit !== null ? $kit . '/dependencies/age/age' : Fixture::runtime()['age'];
$process = proc_open([PHP_BINARY, $script, 'start', '--state-dir', $state, '--port', (string) $webPort, '--age', $age, '--repository', $fixture->bed->repository, '--unit', $fixture->bed->unit, '--mysql-connections', $fixture->connectionsFile], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
$output = ''; $deadline = microtime(true) + 10;
while (microtime(true) < $deadline && strpos($output, 'Ctrl-C') === false) { $output .= (string) fread($pipes[1], 4096); }
preg_match('/[a-f0-9]{64}/', $output, $code);
$details = Fixture::temp() . '/details.json';
file_put_contents($details, json_encode([
    'pluginEntry' => ['login' => $fixture->hostUrl . '/wp-login.php', 'user' => 'synthetic-owner', 'password' => $fixture->hostPassword, 'screen' => $fixture->hostUrl . '/wp-admin/admin.php?page=wrb-synthetic-backup&wrb_view=restore', 'connectionSet' => 'plugin'],
    'independentEntry' => ['url' => 'http://127.0.0.1:' . $webPort . '/', 'activationCode' => $code[0] ?? null, 'stateDirectory' => $state, 'connectionSet' => 'independent'],
    'bothEntries' => ['identityFile' => $fixture->bed->identityFile, 'targetUrl' => RestoreTestBed::TARGET_URL, 'runningCopyAdministrator' => 'synthetic: choose any 12+ characters in the form'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
chmod($details, 0600);
echo "合成の実WordPress環境を起動しました。詳細: $details\nCtrl-Cで停止し、すべて削除します。\n";
$stop = false; pcntl_async_signals(true);
foreach ([SIGINT, SIGTERM] as $signal) { pcntl_signal($signal, static function () use (&$stop): void { $stop = true; }); }
while (!$stop && proc_get_status($process)['running']) { usleep(200000); }
foreach (['stop-site', 'stop-job'] as $command) { $stopper = proc_open([PHP_BINARY, $script, $command, '--state-dir', $state], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $unused); proc_close($stopper); }
proc_terminate($process, 15); fclose($pipes[1]); proc_close($process);
$fixture->stop(); Fixture::cleanup();
