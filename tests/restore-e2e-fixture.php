<?php
declare(strict_types=1);

// Synthetic end-to-end fixture for the restore screen: a throwaway MySQL server, one synthetic generation and registered connection
// sets, then the independent entry. It touches no real site, key or database. Stop it with Ctrl-C; everything is removed.
// php tests/restore-e2e-fixture.php [--kit <new-kit-directory>] [--port 8765]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/Fixture.php';
require __DIR__ . '/IsolatedMysql.php';
require __DIR__ . '/RestoreTestBed.php';

use WordPressR2Backup\Tests\{Fixture, RestoreTestBed};

$options = getopt('', ['kit:', 'port:']);
$port = (int) ($options['port'] ?? 8765);
$bed = RestoreTestBed::build();
$set = RestoreTestBed::connectionSet($bed->server, 'synthetic');
$state = Fixture::temp();
$connections = Fixture::temp() . '/connections.json';
file_put_contents($connections, json_encode(['version' => 1, 'connections' => [array_diff_key($set, ['_schema' => 1, '_importer' => 1, '_runner' => 1])]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); chmod($connections, 0600);
$identity = Fixture::temp() . '/synthetic-identity.txt';
file_put_contents($identity, $bed->identity); chmod($identity, 0600);
$kit = $options['kit'] ?? null;
$script = $kit !== null ? $kit . '/recover-web.php' : dirname(__DIR__) . '/recovery/recover-web.php';
$age = $kit !== null ? $kit . '/dependencies/age/age' : Fixture::runtime()['age'];
echo "合成の秘密鍵（画面の秘密鍵欄へ貼り付け）: $identity\n合成の管理者パスワード例: " . RestoreTestBed::ADMIN_PASSWORD . "\n合成の接続セット: synthetic（原本DB {$set['_schema']}）\n状態フォルダー: $state\n";
$process = proc_open([PHP_BINARY, $script, 'start', '--state-dir', $state, '--port', (string) $port, '--age', $age, '--repository', $bed->repository, '--unit', $bed->unit, '--mysql-connections', $connections], [0 => ['file', '/dev/null', 'r']], $pipes);
$stop = false;
pcntl_async_signals(true);
foreach ([SIGINT, SIGTERM] as $signal) { pcntl_signal($signal, static function () use (&$stop): void { $stop = true; }); }
while (!$stop && proc_get_status($process)['running']) { usleep(200000); }
proc_terminate($process, 15); proc_close($process);
foreach (['stop-site', 'stop-job'] as $command) { $stopper = proc_open([PHP_BINARY, $script, $command, '--state-dir', $state], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes); proc_close($stopper); }
$bed->server->stop();
Fixture::cleanup();
