<?php
declare(strict_types=1);

namespace WordPressR2Backup;

use RuntimeException;

/**
 * 通常復元の一時索引。再開時は必ず新規作成し、永続の台帳には使わない。
 *
 * backend: sqlite=単一のSQLite DB / files=既存MetadataMapの64件分割 / legacy=既存MetadataMapの旧既定 / auto=SQLiteが使えればsqlite、なければfiles。
 * SQLiteが存在するのに作成・SQL・検査が失敗した場合は例外にし、別のbackendへ黙って切り替えない。
 *
 * SQLite backendの整合性: 各行を実行ごとのメモリー内HMAC鍵で束縛し、件数とMAC累積XORをメモリーに保持する。
 * 同じinodeへの改ざんはSQLiteのpage cacheが隠し得るため、get()/entries()の直後の検出は保証しない。
 * 破壊的な処理へ進む前に seal() で commit、全接続の close、新しいREADONLY接続による全体検証を通すこと。
 * pin stream(同一inodeを開き続ける識別用のfd)は、全SQLite接続・文を閉じた後にだけ閉じる。同じinodeの別fdを
 * 接続中に開閉するとPOSIXロックが消えるため、hash_file などをこのファイルに使わない。
 */
final class RecoveryMetadataMap
{
    private const FILE = 'index.sqlite';
    private const SCHEMA = 'CREATE TABLE records (id BLOB PRIMARY KEY, k BLOB NOT NULL, v BLOB NOT NULL, mac BLOB NOT NULL) WITHOUT ROWID';
    private const APPLICATION_ID = 1464946993;
    private const USER_VERSION = 1;
    private const PAGE_ROWS = 256;
    private const KEY_LIMIT = 8192;
    /** 過長な行はNULLにして、改ざんされた巨大データを一括で転送しない。 */
    private const BOUNDED = 'id, CASE WHEN LENGTH(k) <= 8192 THEN k END AS k, CASE WHEN LENGTH(v) <= 262144 THEN v END AS v, CASE WHEN LENGTH(mac) = 32 THEN mac END AS mac';
    private const PAGE_BYTES = 1048576;
    private const VALUE_LIMIT = 262144;
    private const VALUE_DEPTH = 12;
    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    private const BACKENDS = ['auto', 'sqlite', 'files', 'legacy'];

    private string $backend;
    private int $cacheKiB = 2048;
    private ?MetadataMap $files = null;
    private bool $sealed = false;
    private bool $closed = false;
    private bool $broken = false;
    private int $enumerating = 0;
    // SQLite backend
    private string $directory = '';
    private string $path = '';
    private $pin = null;
    private array $fileIdentity = [];
    private array $directoryIdentity = [];
    private ?\SQLite3 $db = null;
    private array $statements = [];
    private bool $transaction = false;
    private string $macKey = '';
    private int $count = 0;
    private string $accumulator = '';
    private array $master = [];

    private function __construct(string $backend) { $this->backend = $backend; }
    private function __clone() {}
    public function __destruct() { try { $this->close(); } catch (\Throwable $ignored) { } }

    public static function create(string $newDirectory, string $backend = 'auto'): self
    {
        if (!in_array($backend, self::BACKENDS, true)) { throw new RuntimeException('Unknown recovery metadata backend.'); }
        if ($backend === 'auto') { $backend = self::sqliteAvailable() ? 'sqlite' : 'files'; }
        if ($backend === 'sqlite' && !self::sqliteAvailable()) { throw new RuntimeException('The SQLite recovery metadata backend is unavailable.'); }
        $map = new self($backend);
        if ($backend === 'sqlite') { $map->cacheKiB = self::configuredCacheKiB(); $map->initialize($newDirectory); }
        else { $map->files = MetadataMap::create($newDirectory, 4194304, $backend === 'files' ? 64 : PHP_INT_MAX); }
        return $map;
    }

    public function backend(): string { return $this->backend; }

    public function get(string $key): ?array
    {
        $this->assertUsable();
        if ($this->files !== null) { return $this->files->get($key); }
        try {
            $this->guard();
            $id = self::id($key); $row = $this->row($id);
            if ($row === null) { return null; }
            return $this->value($id, $row, $key);
        } catch (RuntimeException $error) { throw $error; } catch (\Throwable $error) { throw $this->failure($error); }
    }

    public function put(string $key, array $value, bool $replace = false): void
    {
        $this->assertUsable();
        if ($this->sealed) { throw new RuntimeException('A sealed recovery metadata map is read-only.'); }
        if ($this->enumerating > 0) { throw new RuntimeException('Recovery metadata cannot be written during enumeration.'); }
        if ($this->files !== null) { $this->files->put($key, $value, $replace); return; }
        try {
            $this->guard();
            $id = self::id($key);
            try { $json = json_encode($value, self::JSON_FLAGS, self::VALUE_DEPTH); }
            catch (\JsonException $ignored) { throw new RuntimeException('Metadata value cannot be encoded within its limits.'); }
            if (strlen($json) > self::VALUE_LIMIT) { throw new RuntimeException('Metadata value exceeds its limit.'); }
            $row = $this->row($id); $mac = $this->mac($id, $key, $json);
            if ($row === null) {
                $this->execute('insert', 'INSERT INTO records(id,k,v,mac) VALUES(:id,:k,:v,:mac)', [':id' => $id, ':k' => $key, ':v' => $json, ':mac' => $mac]);
                ++$this->count; $this->accumulator ^= $mac;
                return;
            }
            $existing = $this->value($id, $row, $key);
            if (!$replace && $existing !== $value) { throw new RuntimeException('Metadata key is already assigned.'); }
            if ($existing === $value) { return; }
            $this->execute('update', 'UPDATE records SET v=:v, mac=:mac WHERE id=:id', [':id' => $id, ':v' => $json, ':mac' => $mac]);
            $this->accumulator ^= $row['mac'] ^ $mac;
        } catch (RuntimeException $error) { throw $error; } catch (\Throwable $error) { throw $this->failure($error); }
    }

    /** SQLite backendではキー順に有界のページを読み、結果を解放してからyieldする。途中でbreakしてもclose()できる。 */
    public function entries(): \Generator
    {
        $this->assertUsable();
        ++$this->enumerating;
        try {
            if ($this->files !== null) { foreach ($this->files->entries() as $key => $value) { yield $key => $value; } return; }
            $after = '';
            while (true) {
                $this->assertUsable();
                try {
                    $this->guard();
                    $rows = $this->page($after);
                } catch (RuntimeException $error) { throw $error; } catch (\Throwable $error) { throw $this->failure($error); }
                if ($rows === []) { return; }
                foreach ($rows as $row) {
                    $after = $row['id'];
                    $decoded = $this->value($row['id'], $row, null);
                    yield $row['k'] => $decoded;
                }
            }
        } finally { --$this->enumerating; }
    }

    /** 書込みを終了し、新しい接続で全体を再検証する。破壊的処理や公開の前に呼ぶ。再実行すると改めて新しい接続で検証する。 */
    public function seal(): void
    {
        $this->assertUsable();
        if ($this->enumerating > 0) { throw new RuntimeException('Recovery metadata cannot be sealed during enumeration.'); }
        if ($this->files !== null) {
            foreach ($this->files->entries() as $ignoredKey => $ignoredValue) { }
            $this->sealed = true; return;
        }
        try {
            $this->guard();
            if ($this->transaction) { $this->db->exec('COMMIT'); $this->transaction = false; }
            $this->sealed = true;
            $this->closeDatabase();
            $this->guard();
            FileSafety::assertNoLinks($this->path); FileSafety::privateDirectory($this->directory);
            $reader = new \SQLite3($this->path, SQLITE3_OPEN_READONLY);
            $this->db = $reader;
            $reader->enableExceptions(true); $reader->busyTimeout(5000);
            $this->configure($reader, false);
            $this->checkIdentity($reader);
            if ($reader->querySingle('PRAGMA integrity_check') !== 'ok') { throw new RuntimeException('Recovery metadata integrity check failed.'); }
            $count = 0; $accumulator = str_repeat("\0", 32); $after = '';
            while (true) {
                $rows = $this->page($after);
                if ($rows === []) { break; }
                foreach ($rows as $row) {
                    $this->value($row['id'], $row, null);
                    ++$count; $accumulator ^= $row['mac']; $after = $row['id'];
                }
            }
            // ページ走査は `id > 空BLOB` から始まるため、空BLOBや型違いのidの行は走査に現れない。実総件数も照合する。
            if ((int) $reader->querySingle('SELECT COUNT(*) FROM records') !== $this->count) { throw new RuntimeException('Recovery metadata has rows outside the sealed set.'); }
            if ($count !== $this->count || !hash_equals($this->accumulator, $accumulator)) { throw new RuntimeException('Recovery metadata differs from the sealed set.'); }
            $this->guard();
        } catch (\Throwable $error) {
            $this->broken = true;
            try { $this->closeDatabase(); } catch (\Throwable $ignored) { }
            throw $error instanceof RuntimeException ? $error : $this->failure($error);
        }
    }

    /**
     * 接続を先に閉じ、pin streamは接続が閉じた後にだけ閉じる。接続のcloseに失敗した場合は参照とpinを保持して失敗状態にし、
     * 再度close()できる。呼出側はcloseが失敗したら作業領域を削除せずに停止すること。
     */
    public function close(): void
    {
        if ($this->closed) { return; }
        if ($this->files !== null) { $this->closed = true; return; }
        $error = null;
        try {
            if ($this->db !== null && $this->transaction) { try { $this->db->exec('ROLLBACK'); } catch (\Throwable $ignored) { } $this->transaction = false; }
            $this->closeDatabase();
        } catch (\Throwable $caught) { $error = $caught; $this->broken = true; }
        if ($this->db === null) {
            $this->closed = true;
            if (is_resource($this->pin)) { fclose($this->pin); }
            $this->pin = null; $this->macKey = '';
        }
        if ($error !== null) { throw $error; }
    }

    /** Only a trusted CLI environment may tune this per-connection cache; request input is never consulted. */
    private static function configuredCacheKiB(): int
    {
        $value = PHP_SAPI === 'cli' ? getenv('WRB_RECOVERY_METADATA_CACHE_MIB') : false;
        if ($value === false) { return 2048; }
        if (!preg_match('/\A[1-9][0-9]{0,3}\z/D', $value) || (int) $value < 2 || (int) $value > 1024) {
            throw new RuntimeException('Invalid recovery metadata cache size.');
        }
        return (int) $value * 1024;
    }

    private static function sqliteAvailable(): bool
    {
        return extension_loaded('sqlite3') && class_exists('SQLite3') && \SQLite3::version()['versionNumber'] >= 3008002;
    }

    private function initialize(string $directory): void
    {
        FileSafety::assertNoLinks($directory);
        FileSafety::privateDirectory(dirname($directory));
        if (@lstat($directory) !== false || !mkdir($directory, 0700) || !chmod($directory, 0700)) { throw new RuntimeException('Recovery metadata requires a new private directory.'); }
        FileSafety::privateDirectory($directory);
        $this->directory = $directory; $this->path = $directory . '/' . self::FILE;
        try {
            $pin = fopen($this->path, 'x+b');
            if ($pin === false) { throw new RuntimeException('Cannot create the recovery metadata file.'); }
            $this->pin = $pin;
            if (!chmod($this->path, 0600)) { throw new RuntimeException('Cannot restrict the recovery metadata file.'); }
            $file = fstat($pin); $parent = lstat($directory);
            if ($file === false || $parent === false || ($file['mode'] & 0177777) !== 0100600 || $file['nlink'] !== 1) { throw new RuntimeException('Unsafe recovery metadata file.'); }
            $this->fileIdentity = ['dev' => $file['dev'], 'ino' => $file['ino'], 'uid' => $file['uid']];
            $this->directoryIdentity = ['dev' => $parent['dev'], 'ino' => $parent['ino'], 'uid' => $parent['uid']];
            $this->macKey = random_bytes(32); $this->accumulator = str_repeat("\0", 32);
            $db = new \SQLite3($this->path, SQLITE3_OPEN_READWRITE);
            $this->db = $db;
            $db->enableExceptions(true); $db->busyTimeout(5000);
            $this->configure($db, true);
            $db->exec(self::SCHEMA);
            $db->exec('PRAGMA application_id=' . self::APPLICATION_ID); $db->exec('PRAGMA user_version=' . self::USER_VERSION);
            $this->master = $this->masterRows($db);
            $this->checkIdentity($db);
            $db->exec('BEGIN IMMEDIATE'); $this->transaction = true;
            $this->guard();
            FileSafety::assertNoLinks($this->path);
        } catch (\Throwable $error) {
            $this->broken = true;
            try { $this->close(); } catch (\Throwable $ignored) { }
            throw $error instanceof RuntimeException ? $error : $this->failure($error);
        }
    }

    /** 設定は接続単位なので、書込み用・READONLY用のどちらを開いた時も適用して読み戻す。 */
    private function configure(\SQLite3 $db, bool $writer): void
    {
        $hardened = \SQLite3::version()['versionNumber'] >= 3031000;
        if ($writer) { $db->exec('PRAGMA journal_mode=MEMORY'); $db->exec('PRAGMA synchronous=FULL'); }
        else { $db->exec('PRAGMA query_only=ON'); }
        $db->exec('PRAGMA cache_size=-' . $this->cacheKiB); $db->exec('PRAGMA temp_store=MEMORY'); $db->exec('PRAGMA mmap_size=0'); $db->exec('PRAGMA cell_size_check=ON');
        if ($hardened) { $db->exec('PRAGMA trusted_schema=OFF'); }
        if ((int) $db->querySingle('PRAGMA cache_size') !== -$this->cacheKiB || (int) $db->querySingle('PRAGMA temp_store') !== 2 || (int) $db->querySingle('PRAGMA mmap_size') !== 0
            || (int) $db->querySingle('PRAGMA cell_size_check') !== 1 || ($hardened && (int) $db->querySingle('PRAGMA trusted_schema') !== 0)
            || ($writer && (strtolower((string) $db->querySingle('PRAGMA journal_mode')) !== 'memory' || (int) $db->querySingle('PRAGMA synchronous') !== 2))
            || (!$writer && (int) $db->querySingle('PRAGMA query_only') !== 1)) {
            throw new RuntimeException('The recovery metadata database settings were not applied.');
        }
    }

    private function masterRows(\SQLite3 $db): array
    {
        $result = $db->query('SELECT type,name,tbl_name,sql FROM sqlite_master ORDER BY name'); $rows = [];
        while (($row = $result->fetchArray(SQLITE3_NUM)) !== false) { $rows[] = $row; }
        $result->finalize();
        return $rows;
    }

    private function checkIdentity(\SQLite3 $db): void
    {
        if ((int) $db->querySingle('PRAGMA application_id') !== self::APPLICATION_ID || (int) $db->querySingle('PRAGMA user_version') !== self::USER_VERSION
            || $this->masterRows($db) !== $this->master || $this->master !== [['table', 'records', 'records', self::SCHEMA]]) {
            throw new RuntimeException('Recovery metadata database identity differs.');
        }
    }

    /** 保持中のdev/ino/UID・種類・権限と、親ディレクトリの同一性を確認する。内容の改ざんは行ごとのMACとseal()で検出する。 */
    private function guard(): void
    {
        if (!is_resource($this->pin)) { throw new RuntimeException('The recovery metadata file is not pinned.'); }
        clearstatcache(true, $this->path); clearstatcache(true, $this->directory);
        $path = @lstat($this->path); $pinned = fstat($this->pin); $parent = @lstat($this->directory);
        if ($path === false || $pinned === false || $parent === false
            || ($path['mode'] & 0177777) !== 0100600 || $path['nlink'] !== 1 || $pinned['nlink'] !== 1
            || $path['dev'] !== $this->fileIdentity['dev'] || $path['ino'] !== $this->fileIdentity['ino'] || $path['uid'] !== $this->fileIdentity['uid']
            || $pinned['dev'] !== $this->fileIdentity['dev'] || $pinned['ino'] !== $this->fileIdentity['ino']
            || ($parent['mode'] & 0177777) !== 040700 || $parent['dev'] !== $this->directoryIdentity['dev'] || $parent['ino'] !== $this->directoryIdentity['ino']
            || $parent['uid'] !== $this->directoryIdentity['uid']) {
            $this->broken = true;
            throw new RuntimeException('The recovery metadata file or directory changed.');
        }
    }

    private function statement(string $name, string $sql): \SQLite3Stmt
    {
        if (!isset($this->statements[$name])) { $this->statements[$name] = $this->db->prepare($sql); }
        return $this->statements[$name];
    }

    private function execute(string $name, string $sql, array $values): void
    {
        $statement = $this->statement($name, $sql);
        foreach ($values as $parameter => $value) { $statement->bindValue($parameter, $value, SQLITE3_BLOB); }
        $result = $statement->execute(); $result->finalize(); $statement->reset(); $statement->clear();
    }

    private function row(string $id): ?array
    {
        $statement = $this->statement('select', 'SELECT ' . self::BOUNDED . ' FROM records WHERE id=:id');
        $statement->bindValue(':id', $id, SQLITE3_BLOB);
        $result = $statement->execute(); $row = $result->fetchArray(SQLITE3_ASSOC);
        $result->finalize(); $statement->reset(); $statement->clear();
        return $row === false ? null : $row;
    }

    /** 合計約1MiB(最低1行)・最大256行を1ページとし、空のページだけを終端とする。 */
    private function page(string $after): array
    {
        $sizes = $this->statement('sizes', 'SELECT id,LENGTH(k)+LENGTH(v),LENGTH(id),LENGTH(mac) FROM records WHERE id>:after ORDER BY id LIMIT ' . self::PAGE_ROWS);
        $sizes->bindValue(':after', $after, SQLITE3_BLOB);
        $result = $sizes->execute(); $last = null; $total = 0; $selected = 0; $stop = false;
        while (($row = $result->fetchArray(SQLITE3_NUM)) !== false) {
            if ((int) $row[1] > self::VALUE_LIMIT + self::KEY_LIMIT || (int) $row[2] !== 32 || (int) $row[3] !== 32) {
                $result->finalize(); $sizes->reset(); $sizes->clear();
                throw new RuntimeException('Recovery metadata record exceeds its bounds.');
            }
            if ($stop) { continue; }
            if ($last !== null && $total + (int) $row[1] > self::PAGE_BYTES) { $stop = true; continue; }
            $total += (int) $row[1]; $last = $row[0]; ++$selected;
        }
        $result->finalize(); $sizes->reset(); $sizes->clear();
        if ($last === null) { return []; }
        $range = $this->statement('range', 'SELECT ' . self::BOUNDED . ' FROM records WHERE id>:after AND id<=:last ORDER BY id');
        $range->bindValue(':after', $after, SQLITE3_BLOB); $range->bindValue(':last', $last, SQLITE3_BLOB);
        $result = $range->execute(); $rows = [];
        while (($row = $result->fetchArray(SQLITE3_ASSOC)) !== false) { $rows[] = $row; }
        $result->finalize(); $range->reset(); $range->clear();
        if (count($rows) !== $selected) { throw new RuntimeException('Recovery metadata changed during enumeration.'); }
        return $rows;
    }

    /** 行のキー・値・MACを検証してdecodeした値を返す。$expectedKeyがnullなら行自身のキーとIDの一致だけを確認する。 */
    private function value(string $id, array $row, ?string $expectedKey): array
    {
        if (!is_string($row['k'] ?? null) || !is_string($row['v'] ?? null) || !is_string($row['mac'] ?? null) || ($row['id'] ?? null) !== $id
            || self::id($row['k']) !== $id || strlen($row['v']) > self::VALUE_LIMIT || !hash_equals($row['mac'], $this->mac($id, $row['k'], $row['v']))) {
            throw new RuntimeException('Invalid recovery metadata record.');
        }
        if ($expectedKey !== null && $row['k'] !== $expectedKey) { throw new RuntimeException('Metadata identity collision.'); }
        try { $value = json_decode($row['v'], true, 16, JSON_THROW_ON_ERROR); }
        catch (\JsonException $ignored) { throw new RuntimeException('Invalid recovery metadata record.'); }
        if (!is_array($value)) { throw new RuntimeException('Invalid recovery metadata record.'); }
        return $value;
    }

    private function mac(string $id, string $key, string $json): string
    {
        return hash_hmac('sha256', "wordpress-r2-backup recovery metadata v1\0" . $id . "\0" . $key . "\0" . $json, $this->macKey, true);
    }

    private static function id(string $key): string
    {
        if ($key === '' || strlen($key) > 8192 || strpos($key, "\0") !== false || !mb_check_encoding($key, 'UTF-8')) { throw new RuntimeException('Invalid metadata key.'); }
        return hash('sha256', $key, true);
    }

    private function assertUsable(): void
    {
        if ($this->closed) { throw new RuntimeException('The recovery metadata map is closed.'); }
        if ($this->broken) { throw new RuntimeException('The recovery metadata map failed verification.'); }
    }

    /**
     * 全ての文とDBの close を試みる。DBが閉じた場合だけ参照を外し、失敗した場合は参照を保持して再試行できるようにする。
     * 1つでも失敗したら、最後に固定文言の例外を投げる。
     */
    private function closeDatabase(): void
    {
        $failed = false; $remaining = [];
        foreach ($this->statements as $name => $statement) {
            try { if ($statement->close() === false) { $failed = true; $remaining[$name] = $statement; } }
            catch (\Throwable $ignored) { $failed = true; $remaining[$name] = $statement; }
        }
        $this->statements = $remaining;
        if ($this->db !== null) {
            try {
                if ($this->db->close() === false) { $failed = true; } else { $this->db = null; }
            } catch (\Throwable $ignored) { $failed = true; }
        }
        if ($this->db === null) { $this->statements = []; }
        if ($failed) { throw new RuntimeException('The recovery metadata database could not be closed cleanly.'); }
    }

    private function failure(\Throwable $error): RuntimeException
    {
        $this->broken = true;
        return new RuntimeException('The recovery metadata index failed.', 0, $error);
    }
}
