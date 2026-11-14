<?php
declare(strict_types=1);

namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Both entries against real WordPress core and synthetic content, up to the top page and a logged-in dashboard of the running copy.
 * Set WRB_WORDPRESS_ARCHIVE to an official tar.gz. @group integration
 */
final class RestoreRealWordPressTest extends TestCase
{
    private static ?RestoreRealWordPress $fixture = null;
    private $web = null;
    private array $pipes = [];

    public static function setUpBeforeClass(): void
    {
        $archive = getenv('WRB_WORDPRESS_ARCHIVE');
        if (RestoreTestBed::available() && is_string($archive) && $archive !== '') { self::$fixture = RestoreRealWordPress::build($archive, RestoreTestBed::freePort()); }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$fixture !== null) { self::$fixture->stop(); self::$fixture = null; }
        Fixture::cleanup();
    }

    protected function setUp(): void
    {
        if (self::$fixture === null) { self::markTestSkipped('WRB_WORDPRESS_ARCHIVE and the isolated MySQL authorization are required.'); }
    }

    protected function tearDown(): void
    {
        if (is_resource($this->web)) { proc_terminate($this->web, 15); foreach ($this->pipes as $pipe) { fclose($pipe); } proc_close($this->web); $this->web = null; }
    }

    public function testThePluginEntryRestoresToARunningRealWordPressOnSqlite(): void
    {
        $fixture = self::$fixture; $port = (int) parse_url($fixture->hostUrl, PHP_URL_PORT);
        $cookies = $this->login($port, 'synthetic-owner', $fixture->hostPassword);
        [$code, , $page] = RestoreTestBed::http($port, 'GET', '/wp-admin/admin.php?page=wrb-synthetic-backup&wrb_view=restore', ['Cookie' => $cookies]);
        self::assertSame(200, $code);
        self::assertStringContainsString('id="wrb-restore"', $page); self::assertStringContainsString('assets/restore.js', $page);
        self::assertSame(1, preg_match('/data-token="([a-f0-9]+)"/', $page, $token), 'The screen carries the nonce');
        $api = function (string $command, array $fields = []) use ($port, $cookies, $token): array {
            [$code, , $body] = RestoreTestBed::http($port, 'POST', '/wp-admin/admin-ajax.php', ['Cookie' => $cookies], http_build_query($fields + ['action' => 'wrb_restore', '_wrb_restore_nonce' => $token[1], 'command' => $command]));
            $data = json_decode($body, true);
            self::assertIsArray($data, $code . ' ' . substr($body, 0, 400));
            return [$code, $data['data'] ?? $data];
        };
        [$code, $context] = $api('context');
        self::assertSame(200, $code, json_encode($context, JSON_UNESCAPED_UNICODE)); self::assertCount(2, $context['connections']);
        foreach (['password'] as $word) { self::assertStringNotContainsString($fixture->connections['plugin']['_importer']['password'], json_encode($context)); }
        [, $list] = $api('snapshots');
        self::assertSame($fixture->bed->snapshot, $list['snapshots'][0]['snapshotId']);
        $preview = RestoreTestBed::freePort();
        $form = $fixture->bed->input('plugin-real', $preview) + $fixture->bed->secrets();
        [$code, $review] = $api('preflight', $form);
        self::assertTrue($review['ok'] ?? false, json_encode($review, JSON_UNESCAPED_UNICODE));
        $start = $form + ['planHash' => $review['planHash'], 'confirmName' => 'plugin-real'];
        [$code, $refused] = $api('start', $start + ['reauthPassword' => 'wrong-password']);
        self::assertSame(400, $code, 'A wrong password does not start'); self::assertSame('reauthPassword', $refused['field']);
        [$code, $status] = $api('start', $start + ['reauthPassword' => $fixture->hostPassword]);
        self::assertSame(200, $code, json_encode($status, JSON_UNESCAPED_UNICODE));
        $status = $this->until(static function () use ($api): array { return $api('status')[1]; }, static function (array $s): bool { return !in_array($s['state'], ['queued', 'running'], true); }, 300);
        self::assertSame('complete', $status['state'], json_encode($status, JSON_UNESCAPED_UNICODE));
        [$code, $status] = $api('site_start', ['job' => 'plugin-real']);
        self::assertSame(200, $code, json_encode($status, JSON_UNESCAPED_UNICODE));
        $status = $this->until(static function () use ($api): array { return $api('status')[1]; }, static function (array $s): bool { return ($s['site']['response'] ?? '') === 'responding'; }, 30);
        self::assertTrue($status['site']['openable'], json_encode($status['site'], JSON_UNESCAPED_UNICODE));
        $this->assertRunningWordPress($preview, 'sqlite');
        [, $stopped] = $api('site_stop', ['job' => 'plugin-real']);
        self::assertSame('stopped', $stopped['site']['state']);
    }

    public function testTheIndependentEntryRestoresToARunningRealWordPressOnMysql(): void
    {
        $fixture = self::$fixture; $state = Fixture::temp(); $port = RestoreTestBed::freePort();
        $script = dirname(__DIR__) . '/recovery/recover-web.php';
        $this->web = proc_open([PHP_BINARY, $script, 'start', '--state-dir', $state, '--port', (string) $port, '--age', Fixture::runtime()['age'], '--repository', $fixture->bed->repository, '--unit', $fixture->bed->unit, '--mysql-connections', $fixture->connectionsFile], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->pipes);
        $deadline = microtime(true) + 10; $output = '';
        while (microtime(true) < $deadline && strpos($output, 'Ctrl-C') === false) { $output .= (string) fread($this->pipes[1], 4096); }
        self::assertSame(1, preg_match('/([a-f0-9]{64})/', $output, $code));
        $origin = ['Origin' => 'http://127.0.0.1:' . $port];
        do { $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $c, $m, 0.2); if ($socket === false) { usleep(50000); } } while ($socket === false && microtime(true) < $deadline);
        [, $headers] = RestoreTestBed::http($port, 'POST', '/activate', $origin, http_build_query(['code' => $code[1]]));
        preg_match('/wrb_recovery_session=([a-f0-9]{64})/', $headers['set-cookie'], $session);
        $cookie = ['Cookie' => 'wrb_recovery_session=' . $session[1]];
        [, , $page] = RestoreTestBed::http($port, 'GET', '/', $cookie); preg_match('/data-token="([a-f0-9]{64})"/', $page, $csrf);
        $api = static function (string $command, array $fields = []) use ($port, $origin, $cookie, $csrf): array {
            [$code, , $body] = RestoreTestBed::http($port, 'POST', '/api', $origin + $cookie, http_build_query($fields + ['command' => $command, 'csrf' => $csrf[1]]));
            return [$code, json_decode($body, true)];
        };
        $preview = RestoreTestBed::freePort();
        $form = $fixture->bed->input('web-real', $preview, ['database' => 'mysql', 'connection' => 'independent']) + $fixture->bed->secrets();
        [, $review] = $api('preflight', $form);
        self::assertTrue($review['ok'] ?? false, json_encode($review, JSON_UNESCAPED_UNICODE));
        [$status, $body] = $api('start', $form + ['planHash' => $review['planHash'], 'confirmName' => 'web-real']);
        self::assertSame(200, $status, json_encode($body, JSON_UNESCAPED_UNICODE));
        $done = $this->until(static function () use ($api): array { return $api('status')[1]; }, static function (array $s): bool { return !in_array($s['state'], ['queued', 'running'], true); }, 300);
        self::assertSame('complete', $done['state'], json_encode($done, JSON_UNESCAPED_UNICODE));
        $api('site_start', ['job' => 'web-real']);
        $this->until(static function () use ($api): array { return $api('status')[1]; }, static function (array $s): bool { return ($s['site']['response'] ?? '') === 'responding'; }, 30);
        $this->assertRunningWordPress($preview, 'mysql');
        $api('site_stop', ['job' => 'web-real']);
        $schema = $fixture->connections['independent']['_schema'];
        self::assertSame([[RestoreTestBed::SOURCE_URL]], $fixture->bed->server->rows($schema, "SELECT option_value FROM wp_options WHERE option_name='home'"), 'The original keeps its URL');
        self::assertSame([[RestoreTestBed::TARGET_URL]], $fixture->bed->server->rows($schema . '_run', "SELECT option_value FROM wp_options WHERE option_name='home'"));
    }

    private function assertRunningWordPress(int $preview, string $mode): void
    {
        $base = 'http://127.0.0.1:' . $preview;
        self::assertStringContainsString('"database":"' . $mode . '"', RestoreTestBed::http($preview, 'GET', '/__wrb_isolation_check')[2]);
        $leak = '~(?:https?:|%3A)?(?://|\\\\/\\\\/|%2F%2F)new\.example\.com~i';
        [$code, $headers, $home] = RestoreTestBed::http($preview, 'GET', '/');
        self::assertSame(200, $code, substr($home, 0, 600));
        self::assertStringContainsString('合成サイト', $home); self::assertStringContainsString('合成の公開記事', $home);
        self::assertStringContainsString($base, $home); self::assertStringContainsString('/wp-includes/', $home, 'Real WordPress rendered the page');
        self::assertDoesNotMatchRegularExpression($leak, $home); self::assertStringNotContainsString('source.invalid', $home);
        self::assertStringContainsString("default-src 'self'", $headers['content-security-policy']);
        [, , $rest] = RestoreTestBed::http($preview, 'GET', '/?rest_route=/wp/v2/posts');
        self::assertStringContainsString('合成の公開記事', json_decode($rest, true)[0]['title']['rendered'] ?? '', substr($rest, 0, 300));
        self::assertDoesNotMatchRegularExpression($leak, $rest); self::assertStringNotContainsString('source.invalid', $rest);
        $cookies = $this->login($preview, 'restore-admin', RestoreTestBed::ADMIN_PASSWORD);
        [$code, , $dashboard] = RestoreTestBed::http($preview, 'GET', '/wp-admin/', ['Cookie' => $cookies]);
        self::assertSame(200, $code, substr($dashboard, 0, 600));
        self::assertStringContainsString('id="wpfooter"', $dashboard); self::assertStringContainsString('</html>', $dashboard);
        self::assertStringNotContainsString('critical error', $dashboard); self::assertStringNotContainsString('重大なエラー', $dashboard);
        self::assertDoesNotMatchRegularExpression($leak, $dashboard);
    }

    private function login(int $port, string $user, string $password): string
    {
        [$code, $headers, $body] = RestoreTestBed::http($port, 'POST', '/wp-login.php', ['Cookie' => 'wordpress_test_cookie=WP%20Cookie%20check'], http_build_query(['log' => $user, 'pwd' => $password, 'wp-submit' => 'Log In', 'testcookie' => '1', 'redirect_to' => '/wp-admin/']));
        self::assertSame(302, $code, 'Login: ' . substr($body, 0, 500));
        $pairs = ['wordpress_test_cookie=WP%20Cookie%20check'];
        foreach (explode("\n", $headers['set-cookie']) as $line) { $pairs[] = explode(';', $line)[0]; }
        return implode('; ', $pairs);
    }

    private function until(callable $read, callable $done, int $seconds): array
    {
        $deadline = microtime(true) + $seconds;
        do { $value = $read(); if ($done($value)) { return $value; } usleep(300000); } while (microtime(true) < $deadline);
        self::fail('Timed out: ' . json_encode($value, JSON_UNESCAPED_UNICODE));
    }
}
