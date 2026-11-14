<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use RuntimeException;

final class IsolatedWordPress
{
    private string $root;
    private string $base;
    private string $password;
    private $process = null;
    private $fpmProcess = null;
    private array $cookies = [];

    public static function start(IsolatedMysql $database, ?string $profileFile = null): self
    {
        $self = new self(); $self->root = Fixture::temp();
        $core = realpath(getenv('WRB_WORDPRESS_CORE') ?: '');
        if ($core === false || !is_file($core . '/wp-includes/version.php')) { throw new RuntimeException('Verified WordPress 7.1.3 fixture is required.'); }
        self::copy($core, $self->root . '/wordpress');
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorText);
        if ($listener === false) { throw new RuntimeException('Cannot reserve fixture HTTP port.'); }
        $address = stream_socket_get_name($listener, false); fclose($listener);
        $self->base = 'http://' . $address; $self->password = bin2hex(random_bytes(24));
        $schema = $database->newSchema();
        $defaults = parse_ini_file($database->defaultsFile(), true, INI_SCANNER_RAW);
        $config = [
            'DB_NAME' => $schema, 'DB_USER' => 'root', 'DB_PASSWORD' => '', 'DB_HOST' => 'localhost:' . $defaults['client']['socket'],
            'DB_CHARSET' => 'utf8mb4', 'DB_COLLATE' => '', 'WP_HOME' => $self->base, 'WP_SITEURL' => $self->base,
            'DISABLE_WP_CRON' => true, 'WP_HTTP_BLOCK_EXTERNAL' => true, 'AUTOMATIC_UPDATER_DISABLED' => true,
            'WP_AUTO_UPDATE_CORE' => false, 'DISALLOW_FILE_MODS' => true, 'WP_DEBUG' => false, 'WP_DEBUG_DISPLAY' => false,
            'WP_ENVIRONMENT_TYPE' => 'local', 'WRB_SYNTHETIC_RUNTIME_PROFILE' => $profileFile,
        ];
        if ($profileFile === null) { unset($config['WRB_SYNTHETIC_RUNTIME_PROFILE']); }
        foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'] as $key) { $config[$key] = bin2hex(random_bytes(32)); }
        $source = "<?php\n";
        foreach ($config as $name => $value) { $source .= 'define(' . var_export($name, true) . ', ' . var_export($value, true) . ");\n"; }
        $source .= '$table_prefix = "wp_"; if (!defined("ABSPATH")) { define("ABSPATH", __DIR__ . "/"); } require_once ABSPATH . "wp-settings.php";' . "\n";
        self::write($self->root . '/wordpress/wp-config.php', $source);
        $pluginSource = dirname(__DIR__); $plugin = $self->root . '/wordpress/wp-content/plugins/wordpress-r2-backup';
        mkdir($plugin, 0700); self::copy($pluginSource . '/src', $plugin . '/src');
        self::copy($pluginSource . '/assets', $plugin . '/assets');
        if (is_dir($pluginSource . '/bin')) {
            self::copy($pluginSource . '/bin', $plugin . '/bin');
            foreach (glob($plugin . '/bin/*/age') as $binary) { chmod($binary, 0700); }
        }
        foreach (['wordpress-r2-backup.php', 'worker.php', 'composer.json', 'composer.lock'] as $name) { self::copy($pluginSource . '/' . $name, $plugin . '/' . $name); }
        self::command([PHP_BINARY, Fixture::composer(), 'install', '--no-dev', '--no-scripts', '--no-plugins', '--no-interaction', '--no-progress', '--prefer-dist', '--optimize-autoloader'], $plugin,
            ['COMPOSER_HOME' => $self->root . '/composer-home', 'COMPOSER_CACHE_DIR' => $self->root . '/composer-cache']);
        $install = $self->root . '/install.php';
        $script = '<?php define("WP_INSTALLING", true); $_SERVER["HTTP_HOST"]=' . var_export($address, true) . '; require ' . var_export($self->root . '/wordpress/wp-load.php', true) . ';'
            . 'add_filter("pre_wp_mail",static fn()=>true); require ABSPATH . "wp-admin/includes/upgrade.php";'
            . 'wp_install("Isolated R2 fixture","fixture-admin","fixture@example.invalid",false,"",' . var_export($self->password, true) . ',"en_US");'
            . '$id=wp_create_user("fixture-reader",' . var_export($self->password, true) . ',"reader@example.invalid"); wp_update_user(["ID"=>$id,"role"=>"subscriber"]);'
            . 'update_option("active_plugins",["wordpress-r2-backup/wordpress-r2-backup.php"]); echo "installed";';
        self::write($install, $script); self::command([PHP_BINARY, $install], $self->root); unlink($install);
        if (getenv('WRB_TEST_FPM_BIN') && getenv('WRB_TEST_NGINX_BIN')) {
            $self->startFpm($address);
        } else {
            $wrapper = __DIR__ . '/fixtures/wordpress-server.php';
            $self->process = proc_open([PHP_BINARY, $wrapper, '-d', 'opcache.enable=0', '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'sys_temp_dir=' . realpath(sys_get_temp_dir()), '-S', $address, '-t', $self->root . '/wordpress'],
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', $self->root . '/http.log', 'a'], 2 => ['file', $self->root . '/http.log', 'a']], $pipes, $self->root,
                ['PHP_CLI_SERVER_WORKERS' => '5', 'PATH' => (getenv('PATH') ?: '/usr/bin:/bin')], ['bypass_shell' => true]);
        }
        if (!is_resource($self->process)) { throw new RuntimeException('Cannot start isolated WordPress.'); }
        $deadline = microtime(true) + 15;
        do {
            if (!proc_get_status($self->process)['running']) { throw new RuntimeException('Isolated WordPress exited.'); }
            try { if ($self->request('/wp-login.php', null, 'guest')['status'] === 200) { return $self; } } catch (RuntimeException $ignoredError) {}
            usleep(100000);
        } while (microtime(true) < $deadline);
        $self->stop(); throw new RuntimeException('Isolated WordPress did not start.');
    }

    public function url(string $path = ''): string { return $this->base . $path; }
    public function root(): string { return $this->root . '/wordpress'; }
    public function credentials(): array { return ['username' => 'fixture-admin', 'password' => $this->password]; }

    public function request(string $path, ?array $fields = null, string $role = 'admin', int $timeout = 60): array
    {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, "\0") || !in_array($role, ['admin', 'reader', 'guest'], true)) { throw new RuntimeException('Invalid fixture request.'); }
        if ($role !== 'guest' && !isset($this->cookies[$role])) {
            $cookie = $this->root . '/cookie-' . $role; self::write($cookie, ''); $this->cookies[$role] = $cookie;
            $this->request('/wp-login.php', null, $role);
            $login = $this->request('/wp-login.php', ['log' => $role === 'admin' ? 'fixture-admin' : 'fixture-reader', 'pwd' => $this->password, 'wp-submit' => 'Log In', 'redirect_to' => $this->base . '/wp-admin/', 'testcookie' => '1'], $role);
            if ($login['status'] !== 302) { throw new RuntimeException('Fixture authentication failed.'); }
        }
        $handle = curl_init($this->base . $path); $headers = [];
        $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int { if (str_contains($line, ':')) { [$key, $value] = explode(':', $line, 2); $headers[strtolower(trim($key))] = trim($value); } return strlen($line); }];
        if ($role !== 'guest') { $options[CURLOPT_COOKIEFILE] = $this->cookies[$role]; $options[CURLOPT_COOKIEJAR] = $this->cookies[$role]; }
        if ($fields !== null) { $options[CURLOPT_POST] = true; $options[CURLOPT_POSTFIELDS] = http_build_query($fields); }
        curl_setopt_array($handle, $options); $body = curl_exec($handle); $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $errorCode = curl_errno($handle); curl_close($handle);
        if (!is_string($body)) { throw new RuntimeException('Fixture HTTP request failed (cURL errno ' . $errorCode . ').'); }
        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }

    public function launchPost(string $path, array $fields): array
    {
        if (!str_starts_with($path, '/wp-admin/') || !isset($this->cookies['admin'])) { throw new RuntimeException('Authenticated fixture POST is required.'); }
        $input = $this->root . '/post-' . bin2hex(random_bytes(8)) . '.json';
        self::write($input, json_encode(['url' => $this->base . $path, 'cookie' => $this->cookies['admin'], 'fields' => $fields], JSON_THROW_ON_ERROR));
        $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/wordpress-post.php', $input], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, []);
        if (!is_resource($process)) { throw new RuntimeException('Cannot start overlapping fixture request.'); }
        return ['process' => $process, 'pipes' => $pipes];
    }

    public function stop(): void
    {
        foreach (['process', 'fpmProcess'] as $property) {
          if (is_resource($this->$property)) {
            $status = proc_get_status($this->$property); $pid = $status['pid'];
            if ($pid > 1 && posix_kill(-$pid, 0)) { posix_kill(-$pid, SIGTERM); }
            elseif ($status['running']) { proc_terminate($this->$property); }
            proc_close($this->$property); $this->$property = null;
          }
        }
    }

    private function startFpm(string $address): void
    {
        $root = $this->root;
        $temporaryRoot = realpath(sys_get_temp_dir());
        $socket = $root . '/php.sock';
        self::write($root . '/fpm.conf', "[global]\ndaemonize = no\nerror_log = $root/fpm.log\n[wordpress]\nlisten = $socket\nlisten.mode = 0600\npm = static\npm.max_children = 5\nclear_env = no\ncatch_workers_output = yes\nphp_admin_flag[display_errors] = off\nphp_admin_flag[log_errors] = on\nphp_admin_flag[opcache.enable] = off\nphp_admin_value[error_log] = $root/php.log\nphp_admin_value[sys_temp_dir] = $temporaryRoot\n");
        $params = ['QUERY_STRING' => '$query_string', 'REQUEST_METHOD' => '$request_method',
            'CONTENT_TYPE' => '$content_type', 'CONTENT_LENGTH' => '$content_length',
            'SCRIPT_FILENAME' => '$document_root$fastcgi_script_name', 'SCRIPT_NAME' => '$fastcgi_script_name',
            'REQUEST_URI' => '$request_uri', 'DOCUMENT_URI' => '$document_uri', 'DOCUMENT_ROOT' => '$document_root',
            'SERVER_PROTOCOL' => '$server_protocol', 'REQUEST_SCHEME' => '$scheme',
            'GATEWAY_INTERFACE' => 'CGI/1.1', 'SERVER_SOFTWARE' => 'nginx', 'REMOTE_ADDR' => '$remote_addr',
            'REMOTE_PORT' => '$remote_port', 'SERVER_ADDR' => '$server_addr', 'SERVER_PORT' => '$server_port',
            'SERVER_NAME' => '$server_name', 'REDIRECT_STATUS' => '200', 'HTTP_PROXY' => '""'];
        $fastcgi = '';
        foreach ($params as $name => $value) { $fastcgi .= "fastcgi_param $name $value;\n"; }
        self::write($root . '/nginx.conf', "daemon off;\nworker_processes 1;\npid $root/nginx.pid;\nerror_log $root/nginx.log;\nevents { worker_connections 128; }\nhttp { access_log off; client_body_temp_path $root/body; fastcgi_temp_path $root/fastcgi; server { listen $address; server_name 127.0.0.1; root $root/wordpress; index index.php; location / { try_files \$uri \$uri/ /index.php?\$args; } location ~ \\.php$ { try_files \$uri =404; fastcgi_pass unix:$socket; $fastcgi } } }\n");
        $launch = static function (array $command) use ($root) {
            $code = 'if (posix_setsid() === -1) { exit(1); } pcntl_exec($argv[1], array_slice($argv, 2)); exit(1);';
            return proc_open(array_merge([PHP_BINARY, '-r', $code], $command),
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', $root . '/http.log', 'a'], 2 => ['file', $root . '/http.log', 'a']], $pipes,
                $root, ['PATH' => (getenv('PATH') ?: '/usr/bin:/bin')]);
        };
        $this->fpmProcess = $launch([getenv('WRB_TEST_FPM_BIN'), '-F', '-y', $root . '/fpm.conf']);
        $deadline = microtime(true) + 5;
        while (!file_exists($socket) && microtime(true) < $deadline) { usleep(50000); }
        if (!file_exists($socket)) { $this->stop(); throw new RuntimeException('Isolated PHP-FPM did not start.'); }
        $this->process = $launch([getenv('WRB_TEST_NGINX_BIN'), '-p', $root . '/', '-c', $root . '/nginx.conf']);
        self::write($root . '/wordpress/wrb-test-runtime.php', '<?php echo PHP_VERSION;');
        try {
            $deadline = microtime(true) + 5;
            do {
                try {
                    $runtime = $this->request('/wrb-test-runtime.php', null, 'guest');
                    if ($runtime['status'] === 200 && $runtime['body'] === PHP_VERSION) { return; }
                } catch (RuntimeException $ignoredError) {}
                usleep(50000);
            } while (microtime(true) < $deadline);
            $this->stop(); throw new RuntimeException('Isolated PHP-FPM runtime did not match the test runner.');
        } finally { unlink($root . '/wordpress/wrb-test-runtime.php'); }
    }

    public function __destruct() { $this->stop(); }

    private static function command(array $command, string $directory, array $environment = []): string
    {
        $output = tmpfile(); $error = tmpfile();
        if ($output === false || $error === false) { throw new RuntimeException('Cannot create fixture setup streams.'); }
        try {
            $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $error], $pipes, $directory, ['PATH' => (getenv('PATH') ?: '/usr/bin:/bin')] + $environment);
            if (!is_resource($process)) { throw new RuntimeException('Cannot execute fixture setup.'); }
            $exit = proc_close($process);
            rewind($output); rewind($error);
            if ($exit !== 0) { throw new RuntimeException('Isolated WordPress setup failed: ' . substr((string) stream_get_contents($error), 0, 1024)); }
            return (string) stream_get_contents($output);
        } finally { fclose($output); fclose($error); }
    }

    private static function copy(string $source, string $destination): void
    {
        if (is_dir($source) && !is_link($source)) {
            mkdir($destination, 0700);
            foreach (scandir($source) as $name) { if ($name !== '.' && $name !== '..') { self::copy($source . '/' . $name, $destination . '/' . $name); } }
        } elseif (is_file($source) && !is_link($source)) { if (!copy($source, $destination)) { throw new RuntimeException('Cannot copy fixture file.'); } chmod($destination, 0600); }
        else { throw new RuntimeException('Unsupported fixture entry.'); }
    }

    private static function write(string $path, string $bytes): void
    {
        if (file_put_contents($path, $bytes) !== strlen($bytes) || !chmod($path, 0600)) { throw new RuntimeException('Cannot write private fixture.'); }
    }
}
