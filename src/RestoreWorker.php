<?php
declare(strict_types=1);

namespace WordPressR2Backup;

/** The detached process behind RestoreService. It reports only fixed messages, and secrets live only in its memory. */
final class RestoreWorker
{
    private string $job;
    private array $data;
    private array $status;
    private $lock;

    public static function main(string $base, string $name, string $frameBytes): int
    {
        FileSafety::privateDirectory($base);
        if (!preg_match('/\A[a-z0-9-]{1,40}\z/D', $name)) { return 2; }
        $frame = json_decode($frameBytes, true, 8);
        if (!is_array($frame) || !in_array($frame['mode'] ?? '', ['restore', 'startup', 'serve'], true)) { return 2; }
        $worker = new self($base . '/jobs/' . $name);
        return $frame['mode'] === 'serve' ? $worker->serve() : $worker->work($frame);
    }

    private function __construct(string $job)
    {
        FileSafety::privateDirectory($job);
        $this->job = $job;
        $data = json_decode((string) file_get_contents($job . '/job.json'), true, 32, JSON_THROW_ON_ERROR);
        $this->data = $data;
    }

    private function work(array $frame): int
    {
        $this->lock = $this->acquire('worker.lock');
        if ($this->lock === null) { return 3; }
        $this->status = json_decode((string) file_get_contents($this->job . '/status.json'), true, 32, JSON_THROW_ON_ERROR);
        if ($this->status['state'] !== 'queued') { return 3; }
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, static function (): void { throw new RestoreError('依頼により処理を停止しました。'); });
        $credentials = [];
        try {
            $this->update(['state' => 'running', 'phase' => $frame['mode'] === 'startup' ? 'site-copy' : 'starting']);
            $plan = $this->data['plan'];
            if ($plan['database'] === 'mysql' && $frame['mode'] === 'restore') {
                $files = RestoreService::writeCredentials($this->data['connection'], $plan, $frame['accounts']);
                $credentials = $files['all'];
                $this->writeJson($this->job . '/credential-files.json', $credentials);
                $mysql = new MysqlClient($this->data['connection']['mysql'], $this->data['connection']['mysqldump'], $files['defaults'], $this->data['connection']['workspace'], 86400);
            }
            if ($frame['mode'] === 'restore') { $this->restore($frame, $mysql ?? null); }
            $this->startup($frame, $mysql ?? null);
            $this->update(['state' => 'complete', 'phase' => 'done']);
            return 0;
        } catch (\Throwable $error) {
            $stopped = is_file($this->job . '/stop-requested');
            $restored = ($this->status['results']['files']['state'] ?? '') === 'verified';
            $this->update(['state' => $stopped ? 'stopped' : ($restored ? 'startup-failed' : 'failed'), 'results' => RestoreService::failCurrent($this->status['results']),
                'message' => $error instanceof RestoreError ? $error->getMessage() : $this->failureMessage($restored),
                'diagnostic' => (new \ReflectionClass($error))->getShortName() . ' ' . basename($error->getFile()) . ':' . $error->getLine()]);
            return 1;
        } finally {
            RestoreService::removeFiles($credentials);
            if (@lstat($this->job . '/credential-files.json') !== false) { @unlink($this->job . '/credential-files.json'); }
            flock($this->lock, LOCK_UN); fclose($this->lock);
        }
    }

    private function restore(array $frame, ?MysqlClient $mysql): void
    {
        $plan = $this->data['plan'];
        $source = $this->data['source'];
        if ($source['type'] === 'direct-r2') { $source['profile'] = $frame['profile']; }
        $identity = fopen('php://memory', 'w+b');
        if ($identity === false || fwrite($identity, (string) $frame['identity']) === false || !rewind($identity)) { throw new \RuntimeException('Cannot hold the identity.'); }
        $client = $mysql;
        $schema = $mysql !== null ? $plan['mysql']['database'] : null;
        if ($mysql === null) {
            $originalDirectory = $this->job . '/original-db';
            if (!mkdir($originalDirectory, 0700)) { throw new \RuntimeException('Cannot create the database directory.'); }
            require_once dirname(__DIR__) . '/dependencies/sqlite-database-integration/wp-includes/database/load.php';
            $client = new SqliteDatabaseClient($originalDirectory . '/site.sqlite'); $schema = 'sqlite';
        }
        $this->mark('archive', 'running');
        $prefix = $plan['purpose'] === 'startup-check' ? 'roots/' . $this->data['root'] . '/wp-content/uploads' : null;
        try {
            $result = (new Restorer(new AgeProcess($this->data['age']), RestoreService::openRepository($source)))->restore($plan['snapshot'], $identity, $this->job . '/restore', $client, $schema, null, $prefix,
                function (string $phase): void { $this->phase($phase); });
        } finally { fclose($identity); if ($client instanceof SqliteDatabaseClient) { $client->close(); } }
        $this->mark('files', 'verified', ['files' => $result['files'], 'bytes' => $result['bytes']]);
        if ($prefix !== null) { $this->mark('media', 'verified', ['files' => $result['mediaDryRun']['files'], 'bytes' => $result['mediaDryRun']['bytes'], 'placed' => 0]); }
        else { $this->mark('media', 'verified', ['placed' => null]); }
    }

    private function phase(string $phase): void
    {
        $changes = ['phase' => $phase];
        if ($phase === 'database-import') { $this->mark('archive', 'verified'); $this->mark('database', 'running'); }
        elseif ($phase === 'files-publish') { $this->mark('archive', 'verified'); $this->mark('database', 'verified'); $this->mark('files', 'running'); $this->mark('media', 'running'); }
        $this->update($changes);
    }

    private function startup(array $frame, ?MysqlClient $mysql): void
    {
        $plan = $this->data['plan'];
        $this->mark('startup', 'running'); $this->update(['phase' => 'site-copy']);
        $administrator = ['login' => $plan['adminLogin'], 'passwordHash' => $frame['adminHash']];
        $root = $this->job . '/restore/roots/' . $this->data['root'];
        [$tables, $options] = $mysql !== null ? $this->mysqlFacts($frame['accounts']['inspector']) : $this->sqliteFacts();
        $oldLayout = RestoredSiteCheck::assertSupported($tables, $options, $plan['tablePrefix'], $root);
        $attempt = '';
        for ($number = 2; @lstat($this->job . '/site' . $attempt) !== false; ++$number) { $attempt = '-' . $number; }
        $site = $this->job . '/site' . $attempt;
        if ($mysql !== null) {
            $result = (new MysqlSiteSetup())->prepare($mysql, $plan['mysql']['database'], $plan['mysql']['runningDatabase'], $frame['accounts']['runner'], $this->data['connection']['socket'], $root, $site, $this->data['sourceUrl'], $plan['previewUrl'], $plan['targetUrl'], $plan['tablePrefix'], $administrator, $plan['aliases'], $this->job);
            $info = ['siteRoot' => $site, 'databaseFile' => null, 'socket' => $this->data['connection']['socket']];
        } else {
            $directory = $this->job . '/running-db' . $attempt;
            if (!mkdir($directory, 0700)) { throw new \RuntimeException('Cannot create the running database directory.'); }
            require_once dirname(__DIR__) . '/dependencies/sqlite-database-integration/wp-includes/database/load.php';
            $result = (new SqliteSiteSetup(dirname(__DIR__) . '/dependencies/sqlite-database-integration'))->prepare($root, $site, $this->job . '/original-db/site.sqlite', $directory . '/site.sqlite', $this->data['sourceUrl'], $plan['previewUrl'], $plan['tablePrefix'], $administrator, $plan['aliases'], $plan['targetUrl']);
            $info = ['siteRoot' => $site, 'databaseFile' => $directory . '/site.sqlite', 'socket' => null];
        }
        $this->writeJson($this->job . '/site.json', $info + ['url' => $plan['previewUrl'], 'targetUrl' => $plan['targetUrl']]);
        $this->mark('startup', 'verified', ['urlFieldsUpdated' => $result['urlFieldsUpdated'], 'storedUrl' => $result['storedUrl']]);
        $this->update(['startup' => ['state' => 'prepared', 'storedUrl' => $result['storedUrl'], 'previewUrl' => $plan['previewUrl'], 'urlFieldsUpdated' => $result['urlFieldsUpdated'], 'notCarriedOver' => $oldLayout]]);
    }

    private function sqliteFacts(): array
    {
        $db = new \SQLite3($this->job . '/original-db/site.sqlite', SQLITE3_OPEN_READONLY);
        try {
            $db->enableExceptions(true);
            $tables = [];
            $rows = $db->query("SELECT name FROM sqlite_master WHERE type='table'");
            while (($row = $rows->fetchArray(SQLITE3_NUM)) !== false) { $tables[] = $row[0]; }
            $options = [];
            if (in_array($this->data['plan']['tablePrefix'] . 'options', $tables, true)) {
                foreach (['home', 'siteurl'] as $name) {
                    $statement = $db->prepare('SELECT option_value FROM "' . $this->data['plan']['tablePrefix'] . 'options" WHERE option_name=:name'); $statement->bindValue(':name', $name);
                    $row = $statement->execute()->fetchArray(SQLITE3_NUM); $options[$name] = $row === false ? null : (string) $row[0];
                }
            }
            return [$tables, $options];
        } finally { $db->close(); }
    }

    private function mysqlFacts(array $inspector): array
    {
        $plan = $this->data['plan'];
        $db = new \PDO('mysql:unix_socket=' . $this->data['connection']['socket'] . ';dbname=' . $plan['mysql']['database'] . ';charset=utf8mb4', $inspector['user'], $inspector['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::MYSQL_ATTR_LOCAL_INFILE => false]);
        $tables = $db->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        $options = [];
        if (in_array($plan['tablePrefix'] . 'options', $tables, true)) {
            $statement = $db->prepare('SELECT option_value FROM `' . $plan['tablePrefix'] . 'options` WHERE option_name=?');
            foreach (['home', 'siteurl'] as $name) { $statement->execute([$name]); $value = $statement->fetchColumn(); $options[$name] = $value === false ? null : (string) $value; }
        }
        return [$tables, $options];
    }

    private function serve(): int
    {
        $this->lock = $this->acquire('site.lock');
        if ($this->lock === null) { return 3; }
        try {
            $site = json_decode((string) file_get_contents($this->job . '/site.json'), true, 8, JSON_THROW_ON_ERROR);
            $this->writeJson($this->job . '/site-state.json', ['state' => 'running', 'updatedAt' => time()]);
            $exit = LocalSiteServer::run($site['siteRoot'], $site['databaseFile'], $site['url'], dirname(__DIR__) . '/local-router.php', $site['socket'], $site['targetUrl']);
            $this->writeJson($this->job . '/site-state.json', ['state' => $exit === 0 || is_file($this->job . '/site-stop-requested') ? 'stopped' : 'failed', 'updatedAt' => time()]);
            return 0;
        } catch (\Throwable $error) {
            $this->writeJson($this->job . '/site-state.json', ['state' => 'failed', 'updatedAt' => time(), 'diagnostic' => (new \ReflectionClass($error))->getShortName() . ' ' . basename($error->getFile()) . ':' . $error->getLine()]);
            return 1;
        } finally { flock($this->lock, LOCK_UN); fclose($this->lock); }
    }

    private function failureMessage(bool $restored): string
    {
        if ($restored) { return '元データの復元と照合は完了しましたが、確認用サイトを準備できませんでした。元データは保持しています。表の接頭辞と新しいアドレスを確認してください。'; }
        return ['prepare' => 'バックアップの検証に失敗しました。秘密鍵とバックアップを確認してください。', 'database-import' => 'データベースの取り込みに失敗しました。同じデータベースへは再取込しません。新しい空のデータベースと新しい名前でやり直してください。', 'database-verify' => 'データベースの照合に失敗しました。同じデータベースへは再取込しません。新しい空のデータベースと新しい名前でやり直してください。'][$this->status['phase'] ?? ''] ?? '復元を完了できませんでした。バックアップと保存先を確認してください。';
    }

    private function acquire(string $name)
    {
        $path = $this->job . '/' . $name; FileSafety::assertNoLinks($path);
        $lock = @fopen($path, 'c+b');
        if ($lock === false || !chmod($path, 0600) || !flock($lock, LOCK_EX | LOCK_NB)) { if (is_resource($lock)) { fclose($lock); } return null; }
        ftruncate($lock, 0); rewind($lock); fwrite($lock, (string) getmypid()); fflush($lock);
        return $lock;
    }

    private function mark(string $id, string $state, array $details = []): void
    {
        $this->status['results'][$id] = array_merge($this->status['results'][$id] ?? [], ['state' => $state], $details);
        $this->writeJson($this->job . '/status.json', $this->status);
    }

    private function update(array $changes): void
    {
        $this->status = array_merge($this->status, $changes, ['updatedAt' => time()]);
        $this->writeJson($this->job . '/status.json', $this->status);
    }

    private function writeJson(string $path, array $data): void
    {
        $temporary = $path . '.' . bin2hex(random_bytes(6));
        $stream = fopen($temporary, 'xb');
        if ($stream === false) { throw new \RuntimeException('Cannot write the status.'); }
        $bytes = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        try { if (!chmod($temporary, 0600) || fwrite($stream, $bytes) !== strlen($bytes)) { throw new \RuntimeException('Cannot write the status.'); } }
        finally { fclose($stream); }
        if (!rename($temporary, $path)) { @unlink($temporary); throw new \RuntimeException('Cannot publish the status.'); }
    }
}
