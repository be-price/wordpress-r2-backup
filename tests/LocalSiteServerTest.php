<?php
declare(strict_types=1);

namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use WordPressR2Backup\LocalSiteServer;

final class LocalSiteServerTest extends TestCase
{
    private string $root;
    private string $site;
    private string $database;
    private string $router;
    private string $secret;
    private $process = null;
    private array $listeners = [];
    private int $port;

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY !== 'Darwin' || !is_executable('/usr/bin/sandbox-exec')) { self::markTestSkipped('macOS sandbox-exec is required for the local server.'); }
        $this->root = Fixture::temp(); $this->site = $this->root . '/site'; mkdir($this->site, 0700);
        $this->database = $this->root . '/database.sqlite'; $db = new \SQLite3($this->database); $db->exec('CREATE TABLE probe(marker TEXT)'); $db->exec("INSERT INTO probe VALUES ('sqlite-ready')"); $db->close(); chmod($this->database, 0600);
        $this->router = dirname(__DIR__) . '/local-router.php';
        $this->secret = getenv('HOME') . '/.wrb-server-synthetic-' . bin2hex(random_bytes(8)); file_put_contents($this->secret, 'synthetic private marker'); chmod($this->secret, 0600);
        file_put_contents($this->site . '/index.php', '<?php echo "front:" . $_SERVER["REQUEST_URI"];');
        mkdir($this->site . '/wp-admin', 0700); file_put_contents($this->site . '/wp-admin/index.php', '<?php echo "admin-ready";');
        file_put_contents($this->site . '/style.css', 'body{color:black}'); file_put_contents($this->site . '/private.sql', 'synthetic private sql');
        file_put_contents($this->site . '/wp-config.php', '<?php echo "must-not-run";'); file_put_contents($this->site . '/wp-cron.php', '<?php echo "must-not-run";');
        $this->port = $this->freePort();
    }

    protected function tearDown(): void
    {
        $this->stop();
        foreach ($this->listeners as $listener) { fclose($listener); }
        if (isset($this->secret) && is_file($this->secret)) { unlink($this->secret); }
        Fixture::cleanup();
    }

    private function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process, 15);
            $deadline = microtime(true) + 5;
            do { $status = proc_get_status($this->process); if (!$status['running']) { break; } usleep(50000); } while (microtime(true) < $deadline);
            if ($status['running']) { proc_terminate($this->process, 9); }
            proc_close($this->process); $this->process = null;
        }
    }

    public function testTheServerAllowsLocalRequestsAndBlocksHomeReadsAndAllOutboundSockets(): void
    {
        $tcp = stream_socket_server('tcp://127.0.0.1:0', $errno, $error); self::assertIsResource($tcp); $this->listeners[] = $tcp;
        $tcpAddress = stream_socket_get_name($tcp, false);
        $unixPath = $this->root . '/s'; $unix = stream_socket_server('unix://' . $unixPath, $errno, $error); self::assertIsResource($unix); $this->listeners[] = $unix;
        file_put_contents($this->root . '/database-adjacent.key', 'synthetic adjacent key'); chmod($this->root . '/database-adjacent.key', 0600);
        $code = '<?php $d=new SQLite3(' . var_export($this->database, true) . '); echo json_encode(['
            . '"database"=>$d->querySingle("SELECT marker FROM probe"),'
            . '"databaseWriteAllowed"=>$d->exec("UPDATE probe SET marker=marker"),'
            . '"homeReadDenied"=>@file_get_contents(' . var_export($this->secret, true) . ')===false,'
            . '"databaseSiblingReadDenied"=>@file_get_contents(' . var_export($this->root . '/database-adjacent.key', true) . ')===false,'
            . '"tcpDenied"=>@stream_socket_client(' . var_export('tcp://' . $tcpAddress, true) . ',$e,$m,0.3)===false,'
            . '"unixDenied"=>@stream_socket_client(' . var_export('unix://' . $unixPath, true) . ',$e,$m,0.3)===false,'
            . '"urlReadsDisabled"=>ini_get("allow_url_fopen")==="0",'
            . '"execDisabled"=>strpos(ini_get("disable_functions"),"shell_exec")!==false]);';
        file_put_contents($this->site . '/probe.php', $code);
        $this->start(); $response = $this->request('/probe.php'); self::assertSame(200, $response['status']);
        self::assertSame(['database' => 'sqlite-ready', 'databaseWriteAllowed' => true, 'homeReadDenied' => true, 'databaseSiblingReadDenied' => true, 'tcpDenied' => true, 'unixDenied' => true, 'urlReadsDisabled' => true, 'execDisabled' => true], json_decode($response['body'], true));
        self::assertStringContainsString("default-src 'self'", $response['headers']);
        $response = $this->request('/style.css'); self::assertSame(200, $response['status']); self::assertSame('body{color:black}', $response['body']); self::assertStringContainsString('Content-Security-Policy:', $response['headers']);
        self::assertSame('front:/representative/article/', $this->request('/representative/article/')['body']);
        self::assertSame(302, $this->request('/wp-admin')['status']); self::assertSame('admin-ready', $this->request('/wp-admin/')['body']);
        foreach (['/.env', '/%2e%2e/outside', '/private.sql', '/wp-config.php', '/wp-cron.php'] as $path) { self::assertSame(403, $this->request($path)['status']); }
        self::assertSame(403, $this->request('/', 'attacker.invalid')['status']);
        self::assertTrue(json_decode($this->request('/__wrb_isolation_check')['body'], true)['localOnly']);
        $this->stop();
        self::assertFalse(@stream_socket_client('tcp://127.0.0.1:' . $this->port, $errno, $error, 0.3));
    }

    public function testExternalRedirectsAreBlockedAndLocalRedirectsContinueToWork(): void
    {
        file_put_contents($this->site . '/external.php', '<?php header("Location: https://external.invalid/"); header("Refresh: 0;url=https://external.invalid/"); echo "blocked";');
        file_put_contents($this->site . '/relative.php', '<?php header("Location: /wp-admin/");');
        $this->start(); $response = $this->request('/external.php'); self::assertSame(409, $response['status']);
        self::assertStringNotContainsString('Location:', $response['headers']); self::assertStringNotContainsString('Refresh:', $response['headers']);
        $response = $this->request('/relative.php'); self::assertSame(302, $response['status']); self::assertStringContainsString('Location: /wp-admin/', $response['headers']);
    }

    public function testSiteSiblingReadsAreBlockedOutsideHomeWhenTheDatabaseIsElsewhere(): void
    {
        $databaseDirectory = Fixture::temp(); $newDatabase = $databaseDirectory . '/database.sqlite';
        copy($this->database, $newDatabase); chmod($newDatabase, 0600); $this->database = $newDatabase;
        $sibling = $this->root . '/sibling.key'; file_put_contents($sibling, 'synthetic sibling marker'); chmod($sibling, 0600);
        file_put_contents($this->site . '/sibling-probe.php', '<?php echo @file_get_contents(' . var_export($sibling, true) . ') === false ? "denied" : "exposed";');
        $this->start(); self::assertSame('denied', $this->request('/sibling-probe.php')['body']);
    }

    public function testPublicUrlsAndWebExposedDatabasesAreRejectedBeforeStarting(): void
    {
        try { LocalSiteServer::run($this->site, $this->database, 'http://external.invalid:18889', $this->router); self::fail('A public URL must be rejected.'); }
        catch (RuntimeException $error) { self::assertStringContainsString('loopback', $error->getMessage()); }
        $inside = $this->site . '/inside.sqlite'; copy($this->database, $inside); chmod($inside, 0600);
        $this->expectException(RuntimeException::class); LocalSiteServer::run($this->site, $inside, 'http://127.0.0.1:' . $this->port, $this->router);
    }

    private function start(): void
    {
        $script = '<?php require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . '; exit(WordPressR2Backup\\LocalSiteServer::run('
            . var_export($this->site, true) . ',' . var_export($this->database, true) . ',' . var_export('http://127.0.0.1:' . $this->port, true) . ',' . var_export($this->router, true) . '));';
        file_put_contents($this->root . '/run.php', $script);
        $this->process = proc_open([PHP_BINARY, $this->root . '/run.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->root . '/wrapper.log', 'a'], 2 => ['file', $this->root . '/wrapper-errors.log', 'a']], $pipes);
        self::assertIsResource($this->process); $deadline = microtime(true) + 10;
        do {
            $status = proc_get_status($this->process); if (!$status['running']) { self::fail('The isolated server stopped before accepting requests.'); }
            try { if ($this->request('/')['status'] === 200) { return; } } catch (RuntimeException $ignored) { }
            usleep(100000);
        } while (microtime(true) < $deadline);
        self::fail('The isolated server did not become ready.');
    }

    private function request(string $path, ?string $host = null): array
    {
        $stream = @stream_socket_client('tcp://127.0.0.1:' . $this->port, $errno, $error, 0.3);
        if ($stream === false) { throw new RuntimeException('Local synthetic HTTP listener is not ready.'); }
        try {
            stream_set_timeout($stream, 3); fwrite($stream, 'GET ' . $path . " HTTP/1.0\r\nHost: " . ($host ?? '127.0.0.1:' . $this->port) . "\r\nConnection: close\r\n\r\n");
            $bytes = stream_get_contents($stream, 1048576); $parts = explode("\r\n\r\n", $bytes, 2);
            if (!preg_match('/\AHTTP\/1\.[01] ([0-9]{3})/', $parts[0] ?? '', $match) || count($parts) !== 2) { throw new RuntimeException('Invalid synthetic HTTP response.'); }
            return ['status' => (int) $match[1], 'headers' => $parts[0], 'body' => $parts[1]];
        } finally { fclose($stream); }
    }

    private function freePort(): int
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error); if ($listener === false) { throw new RuntimeException('Cannot select a synthetic local port.'); }
        $address = stream_socket_get_name($listener, false); fclose($listener); return (int) substr(strrchr($address, ':'), 1);
    }
}
