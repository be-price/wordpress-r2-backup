<?php
declare(strict_types=1);
namespace WordPressR2Backup;

use Generator;
use RuntimeException;

/** One selected database, one pinned read-lock connection, no plaintext spool. */
final class WordPressDatabase
{
    private $wpdb;
    private PrivacyPolicy $policy;
    private array $tables = [];
    private array $environment = [];
    private array $verified = [];
    private ?string $connection = null;
    private bool $locked = false;
    private bool $snapshot = false;
    private ?string $snapshotTime = null;
    private bool $emitting = false;
    private bool $recordsComplete = false;
    private bool $replayed = false;
    private array $frozenMemoryCounts = [];
    private $secondaryFactory;

    public function __construct($wpdb, PrivacyPolicy $policy, ?callable $secondaryFactory = null)
    {
        $this->wpdb = $wpdb;
        $this->policy = $policy;
        $this->secondaryFactory = $secondaryFactory;
    }

    public function preflight(): array
    {
        if ($this->locked) { throw new RuntimeException('Database inspection cannot restart during capture.'); }
        $this->environment = $this->readEnvironment();
        $this->pulseHeartbeat();
        $this->assertMetadataPrivileges();
        $this->pulseHeartbeat();
        $oldLockWait = (string) $this->scalar('SELECT @@SESSION.lock_wait_timeout');
        if (!ctype_digit($oldLockWait)) { throw new RuntimeException('Cannot inspect database lock timeout.'); }
        $timezoneChanged = false;
        try {
            $this->execute('SET SESSION lock_wait_timeout=5');
            $this->execute("SET SESSION time_zone='+00:00'"); $timezoneChanged = true;
            $this->tables = $this->inventory();
            $this->pulseHeartbeat();
            $modes = $this->assertPolicy($this->tables);
            $this->pulseHeartbeat();
            $this->assertForeignKeys($this->tables, $modes);
        } finally {
            try { if ($timezoneChanged) { $this->execute('SET SESSION time_zone=' . self::literal($this->environment['timeZone'])); } }
            finally { $this->execute('SET SESSION lock_wait_timeout=' . $oldLockWait); }
        }
        $this->pulseHeartbeat();
        $summary = [];
        foreach ($this->tables as $table) {
            $summary[] = ['name' => $table['name'], 'sourceRows' => $table['sourceRows'], 'action' => $table['action'], 'columns' => array_column($table['columns'], 'Field')];
        }
        return ['tables' => $summary, 'tableCount' => count($summary), 'environment' => $this->environment];
    }

    /** Keep a single read-only InnoDB snapshot; callers must consume all records before returning. */
    public function withConsistentSnapshot(callable $operation)
    {
        if ($this->locked || $this->connection !== null) { throw new RuntimeException('Nested database capture is not supported.'); }
        $this->verified = []; $this->recordsComplete = false; $this->replayed = false;
        $this->preflight();
        $expected = $this->snapshotFingerprint($this->tables);
        $this->connection = (string) $this->scalar('SELECT CONNECTION_ID()');
        try { $oldLockWait = (string) $this->scalar('SELECT @@SESSION.lock_wait_timeout'); }
        catch (\Throwable $error) { $this->connection = null; throw $error; }
        if (!ctype_digit($oldLockWait)) { $this->connection = null; throw new RuntimeException('Cannot inspect database lock timeout.'); }
        $started = false; $timezoneChanged = false; $complete = false; $closed = false; $restored = false;
        $aux = null; $auxLocked = false; $mainNamed = null; $auxNamed = null;
        try {
            $this->execute('SET SESSION lock_wait_timeout=5');
            $memory = $this->excludedMemoryTables();
            if ($memory !== []) {
                $database = (string) $this->scalar('SELECT DATABASE()');
                if (!preg_match('/\A[A-Za-z0-9_]+\z/D', $database)
                    || ($this->secondaryFactory === null && (!defined('DB_NAME') || DB_NAME !== $database))) { throw new UnsupportedOnlineDatabaseEngine('追加のDB接続を安全に確認できません。'); }
                $aux = $this->secondaryConnection();
                $mainNamed = 'wrb-' . bin2hex(random_bytes(16)); $auxNamed = 'wrb-' . bin2hex(random_bytes(16));
                if ((string) $this->scalar('SELECT GET_LOCK(' . self::literal($mainNamed) . ',0)') !== '1'
                    || $this->auxScalar($aux, 'SELECT GET_LOCK(' . self::literal($auxNamed) . ',0)') !== '1'
                    || $this->connection === $this->auxScalar($aux, 'SELECT CONNECTION_ID()')
                    || $this->auxScalar($aux, 'SELECT IS_USED_LOCK(' . self::literal($mainNamed) . ')') !== $this->connection
                    || (string) $this->scalar('SELECT IS_USED_LOCK(' . self::literal($auxNamed) . ')') !== $this->auxScalar($aux, 'SELECT CONNECTION_ID()')
                    || $database !== $this->auxScalar($aux, 'SELECT DATABASE()')) { throw new RuntimeException('Cannot prove the secondary database connection.'); }
                $this->auxExecute($aux, 'SET SESSION lock_wait_timeout=5');
                $this->auxExecute($aux, 'LOCK TABLES ' . implode(', ', array_map(static function (string $table): string { return self::identifier($table) . ' READ'; }, $memory)));
                $auxLocked = true;
            }
            $this->execute('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->execute('SET TRANSACTION READ ONLY');
            $this->execute('START TRANSACTION WITH CONSISTENT SNAPSHOT');
            $this->snapshotTime = gmdate('Y-m-d\TH:i:s\Z');
            $started = true; $this->assertConnection(); $this->locked = true; $this->snapshot = true;
            $this->execute('SAVEPOINT wrb_snapshot_guard');
            $this->execute("SET SESSION time_zone='+00:00'"); $timezoneChanged = true;
            foreach ($memory as $name) {
                $count = (string) $this->scalar('SELECT COUNT(*) FROM ' . self::identifier($name));
                if (!ctype_digit($count) || (float) $count > PHP_INT_MAX) { throw new RuntimeException('Cannot represent an excluded table count.'); }
                $this->frozenMemoryCounts[$name] = (int) $count;
            }
            if ($auxLocked) {
                $this->assertConnection();
                if ($this->auxScalar($aux, 'SELECT IS_USED_LOCK(' . self::literal($mainNamed) . ')') !== $this->connection
                    || (string) $this->scalar('SELECT IS_USED_LOCK(' . self::literal($auxNamed) . ')') !== $this->auxScalar($aux, 'SELECT CONNECTION_ID()')) { throw new RuntimeException('The secondary database connection changed.'); }
                $this->auxExecute($aux, 'UNLOCK TABLES'); $auxLocked = false;
            }
            $this->tables = $this->inventory();
            if (!hash_equals($expected, $this->snapshotFingerprint($this->tables))) { throw new RuntimeException('Database definitions changed before snapshot.'); }
            foreach ($this->tables as $table) {
                $engine = (string) $this->scalar("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=X'" . bin2hex($table['name']) . "'");
                if (strcasecmp($engine, 'InnoDB') !== 0 && !(strcasecmp($engine, 'MEMORY') === 0 && array_key_exists($table['name'], $this->frozenMemoryCounts))) { throw new UnsupportedOnlineDatabaseEngine('保守なし取得は、対象テーブルの安全性を確認できる場合に利用できます。'); }
            }
            $modes = $this->assertPolicy($this->tables); $this->assertForeignKeys($this->tables, $modes);
            $result = $operation($this);
            if ($this->emitting || !$this->recordsComplete) { throw new RuntimeException('Database records were not fully consumed in the snapshot.'); }
            $this->assertConnection();
            // A commit or implicit transaction boundary destroys the savepoint even on the same connection.
            $this->execute('ROLLBACK TO SAVEPOINT wrb_snapshot_guard');
            if (!hash_equals($expected, $this->snapshotFingerprint($this->inventory()))) { throw new RuntimeException('Database definitions changed during snapshot.'); }
            $this->execute('ROLLBACK TO SAVEPOINT wrb_snapshot_guard');
            $complete = true;
            return $result;
        } finally {
            $this->locked = false; $this->snapshot = false; $this->snapshotTime = null;
            try {
                try {
                    if ($aux instanceof \mysqli) {
                        try { if ($auxLocked) { $this->auxExecute($aux, 'UNLOCK TABLES'); } }
                        finally { $aux->close(); }
                    }
                } finally { if ($started) { $this->assertConnection(); $this->execute('ROLLBACK'); } }
                $closed = true;
            } finally {
                try {
                    $this->assertConnection();
                    if ($timezoneChanged) { $this->execute('SET SESSION time_zone=' . self::literal($this->environment['timeZone'])); }
                    $this->execute('SET SESSION lock_wait_timeout=' . $oldLockWait);
                    $restored = true;
                } finally {
                    if ($mainNamed !== null && $this->connection !== null) { try { $this->scalar('SELECT RELEASE_LOCK(' . self::literal($mainNamed) . ')'); } catch (\Throwable $ignored) { $restored = false; } }
                    $this->frozenMemoryCounts = []; $this->connection = null;
                    if (!$complete || !$closed || !$restored) { $this->verified = []; $this->recordsComplete = false; }
                }
            }
        }
    }

    /** The caller must be trusted internal capture code and consume records before returning. */
    public function withReadLock(callable $operation)
    {
        if ($this->locked || $this->connection !== null) { throw new RuntimeException('Nested database capture is not supported.'); }
        $this->verified = [];
        $this->recordsComplete = false; $this->replayed = false;
        $this->preflight();
        $expected = $this->definitionFingerprint($this->tables);
        $this->connection = (string) $this->scalar('SELECT CONNECTION_ID()');
        $acquired = false;
        $timezoneChanged = false;
        $operationComplete = false;
        $lockReleased = false;
        $timezoneRestored = false;
        try {
            $locks = [];
            foreach ($this->tables as $index => $table) {
                $id = self::identifier($table['name']);
                $locks[] = $id . ' READ';
                $locks[] = $id . ' AS ' . self::identifier('__wrb4_child_' . $index) . ' READ';
                $locks[] = $id . ' AS ' . self::identifier('__wrb4_parent_' . $index) . ' READ';
            }
            $this->execute('LOCK TABLES ' . implode(', ', $locks));
            $acquired = true;
            $this->assertConnection();
            $this->locked = true;
            $this->execute("SET SESSION time_zone='+00:00'");
            $timezoneChanged = true;
            $this->tables = $this->inventory();
            if (!hash_equals($expected, $this->definitionFingerprint($this->tables))) { throw new RuntimeException('Database definitions changed before the read lock.'); }
            $modes = $this->assertPolicy($this->tables);
            $this->assertForeignKeys($this->tables, $modes);
            $result = $operation($this);
            if ($this->emitting) { throw new RuntimeException('Database records were not fully consumed under the read lock.'); }
            $this->assertConnection();
            if (!hash_equals($expected, $this->definitionFingerprint($this->inventory()))) { throw new RuntimeException('Database definitions changed during capture.'); }
            $operationComplete = true;
            return $result;
        } finally {
            $this->locked = false;
            try {
                if ($acquired) { $this->assertConnection(); $this->execute('UNLOCK TABLES'); }
                $lockReleased = true;
            } finally {
                try {
                    if ($timezoneChanged) { $this->assertConnection(); $this->execute('SET SESSION time_zone=' . self::literal($this->environment['timeZone'])); }
                    $timezoneRestored = true;
                } finally {
                    $this->connection = null;
                    if (!$operationComplete || !$lockReleased || !$timezoneRestored) { $this->verified = []; }
                }
            }
        }
    }

    public function environment(): array { return $this->environment; }

    /** Client timestamp immediately after the server accepted the snapshot request. */
    public function snapshotAt(): string
    {
        if (!$this->snapshot || $this->snapshotTime === null) { throw new RuntimeException('No active database snapshot.'); }
        return $this->snapshotTime;
    }

    /** 除外行が多い走査でも保守状態の更新が届くよう、行の走査数に応じて呼ぶ心拍。 */
    private $heartbeat = null;
    private int $streamedRows = 0;
    private int $lastHeartbeat = 0;

    public function onHeartbeat(?callable $callback): void { $this->heartbeat = $callback; $this->lastHeartbeat = time(); }

    /** Called only between operations; it cannot interrupt a blocked database query or read. */
    private function pulseHeartbeat(bool $row = false): void
    {
        if ($this->heartbeat === null) { return; }
        if ($row) { ++$this->streamedRows; }
        if (($row && $this->streamedRows % 1000 === 0) || time() - $this->lastHeartbeat >= 15) {
            ($this->heartbeat)(); $this->lastHeartbeat = time();
        }
    }

    public function records(): Generator
    {
        $this->assertHeld();
        if ($this->emitting || $this->verified !== [] || $this->recordsComplete) { throw new RuntimeException('Database records can only be consumed once per capture.'); }
        yield from $this->emitRecords(null);
    }

    public function replayRecords(): Generator
    {
        $this->assertHeld();
        if ($this->emitting || !$this->recordsComplete || $this->replayed) { throw new RuntimeException('Database replay requires one complete first pass under the same read lock.'); }
        $actual = $this->inventory();
        $expectedHash = $this->snapshot ? $this->snapshotFingerprint($this->tables) : $this->definitionFingerprint($this->tables);
        $actualHash = $this->snapshot ? $this->snapshotFingerprint($actual) : $this->definitionFingerprint($actual);
        if (!hash_equals($expectedHash, $actualHash)) { throw new RuntimeException('Database definitions changed before replay.'); }
        $expected = $this->verified;
        $this->verified = []; $this->recordsComplete = false; $this->replayed = true;
        yield from $this->emitRecords($expected);
    }

    private function emitRecords(?array $expected): Generator
    {
        $this->emitting = true;
        $verified = [];
        try {
            yield ['type' => 'sql', 'stage' => 'preamble', 'name' => 'database', 'chunks' => [$this->preamble()]];
            foreach ($this->tables as $table) {
                $this->pulseHeartbeat();
                $this->assertHeld();
                yield ['type' => 'sql', 'stage' => 'definition', 'name' => $table['name'], 'chunks' => [$table['create'] . ";\n"]];
                $state = ['rows' => 0, 'excludedRows' => 0, 'hash' => hash_init('sha256'), 'complete' => false];
                yield ['type' => 'sql', 'stage' => 'data', 'name' => $table['name'], 'chunks' => $this->dataChunks($table, $state)];
                if (!$state['complete']) { throw new RuntimeException('Database SQL chunks were not fully consumed.'); }
                $metadata = ['type' => 'table', 'name' => $table['name'], 'rows' => $state['rows'], 'sourceRows' => $table['sourceRows'], 'excludedRows' => $state['excludedRows'],
                    'definitionSha256' => self::definitionSha256($table['create'], $this->environment['sqlMode']), 'rowSha256' => hash_final($state['hash']),
                    'columns' => array_column($table['columns'], 'Field'), 'order' => $table['order'], 'foreignKeys' => $table['foreignKeys']];
                if ($expected !== null && ($expected[count($verified)] ?? null) !== $metadata) { throw new RuntimeException('Database replay differs from the verified first pass.'); }
                $verified[] = $metadata;
                yield $metadata;
            }
            yield ['type' => 'sql', 'stage' => 'postamble', 'name' => 'database', 'chunks' => ["SET FOREIGN_KEY_CHECKS=@WRB4_FOREIGN_KEY_CHECKS;\nSET SQL_MODE=@WRB4_SQL_MODE;\nSET SESSION time_zone=@WRB4_TIME_ZONE;\n"]];
            $this->assertHeld();
            $this->verified = $verified; $this->recordsComplete = true;
        } finally { $this->emitting = false; }
    }

    public function maintainReadLock(): void
    {
        if (!$this->locked || $this->emitting || !$this->recordsComplete) {
            throw new RuntimeException('Read-lock maintenance requires fully consumed database records.');
        }
        $this->assertConnection();
    }

    public function verification(): array
    {
        if ($this->emitting || !$this->recordsComplete || count($this->verified) !== count($this->tables)) { throw new RuntimeException('Database verification is incomplete.'); }
        return $this->verified;
    }

    /** Rebuild a verification SELECT from identifiers, never execute SQL from a saved manifest. */
    public static function verificationQuery(string $table, array $columns, array $order): string
    {
        self::assertColumnLists($columns, $order);
        return 'SELECT ' . self::rowDigestExpression($columns) . ' AS `wrb_row_sha256` FROM ' . self::identifier($table) . self::orderSql($columns, $order);
    }

    public static function definitionSha256(string $definition, string $sqlMode): string
    {
        $normalize = static function (string $text): string {
            return preg_replace_callback('/\bCHARACTER\s+SET\s+([A-Za-z0-9_]+)\s+COLLATE\s+([A-Za-z0-9_]+)\b/i', static function (array $match): string {
                $charset = strtolower($match[1]); $collation = strtolower($match[2]);
                return strpos($collation, $charset . '_') === 0 || ($charset === 'binary' && $collation === 'binary') ? 'COLLATE ' . $match[2] : $match[0];
            }, $text);
        };
        $plain = ''; $canonical = ''; $length = strlen($definition);
        $backslashEscapes = strpos(',' . strtoupper($sqlMode) . ',', ',NO_BACKSLASH_ESCAPES,') === false;
        $ansiQuotes = strpos(',' . strtoupper($sqlMode) . ',', ',ANSI_QUOTES,') !== false;
        for ($offset = 0; $offset < $length;) {
            $character = $definition[$offset];
            $quoted = $character === "'" || $character === '"' || $character === '`';
            $blockComment = substr($definition, $offset, 2) === '/*';
            $lineComment = $character === '#' || (substr($definition, $offset, 2) === '--' && isset($definition[$offset + 2]) && ord($definition[$offset + 2]) <= 32);
            if (!$quoted && !$blockComment && !$lineComment) { $plain .= $character; $offset++; continue; }
            $canonical .= $normalize($plain); $plain = ''; $start = $offset;
            if ($blockComment) {
                $end = strpos($definition, '*/', $offset + 2);
                if ($end === false) { throw new RuntimeException('Invalid database definition comment.'); }
                $offset = $end + 2;
            } elseif ($lineComment) {
                $end = strpos($definition, "\n", $offset);
                $offset = $end === false ? $length : $end + 1;
            } else {
                $quote = $character; $offset++; $closed = false;
                while ($offset < $length) {
                    if ($definition[$offset] === $quote) {
                        if (isset($definition[$offset + 1]) && $definition[$offset + 1] === $quote) { $offset += 2; continue; }
                        $offset++; $closed = true; break;
                    }
                    if ($definition[$offset] === '\\' && $backslashEscapes && $quote !== '`' && !($quote === '"' && $ansiQuotes)) { $offset += 2; }
                    else { $offset++; }
                }
                if (!$closed || $offset > $length) { throw new RuntimeException('Invalid database definition quotation.'); }
            }
            $canonical .= substr($definition, $start, $offset - $start);
        }
        return hash('sha256', $canonical . $normalize($plain) . ";\n");
    }

    private function readEnvironment(): array
    {
        $rows = $this->results('SELECT VERSION() AS serverVersion,@@SESSION.sql_mode AS sqlMode,@@SESSION.time_zone AS timeZone,@@SESSION.character_set_connection AS characterSet,@@SESSION.collation_connection AS collation');
        $row = $rows[0] ?? [];
        foreach (['serverVersion', 'sqlMode', 'timeZone', 'characterSet', 'collation'] as $key) {
            if (!isset($row[$key]) || !is_string($row[$key])) { throw new RuntimeException('Cannot read database connection conditions.'); }
        }
        if (!preg_match('/\A[A-Za-z0-9_]+\z/D', $row['characterSet']) || !preg_match('/\A[A-Za-z0-9_,]*\z/D', $row['sqlMode'])) { throw new RuntimeException('Unsupported database connection conditions.'); }
        return $row;
    }

    private function assertMetadataPrivileges(): void
    {
        $database = (string) $this->scalar('SELECT DATABASE()');
        $granted = [];
        foreach ($this->results('SHOW GRANTS') as $row) {
            $grant = array_values($row)[0] ?? '';
            if (!is_string($grant) || !preg_match('/\AGRANT (.+) ON (.+) TO /D', $grant, $matches)) { continue; }
            $scope = $matches[2];
            $escapedDatabase = str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $database);
            if ($scope !== '*.*' && $scope !== self::identifier($database) . '.*' && $scope !== self::identifier($escapedDatabase) . '.*') { continue; }
            foreach (explode(', ', $matches[1]) as $privilege) { $granted[$privilege] = true; }
        }
        if (isset($granted['ALL PRIVILEGES'])) { return; }
        // Schema-wide routine privileges expose the routine inventory without SHOW_ROUTINE or performance_schema access.
        foreach (['SELECT', 'SHOW VIEW', 'TRIGGER', 'EVENT', 'CREATE ROUTINE', 'ALTER ROUTINE', 'EXECUTE', 'LOCK TABLES'] as $required) {
            if (!isset($granted[$required])) { throw new RuntimeException('Database metadata visibility or read-lock privileges are insufficient.'); }
        }
    }

    private function inventory(): array
    {
        foreach (['TRIGGERS' => 'TRIGGER_SCHEMA', 'ROUTINES' => 'ROUTINE_SCHEMA', 'EVENTS' => 'EVENT_SCHEMA'] as $kind => $schema) {
            $this->pulseHeartbeat();
            if ((int) $this->scalar('SELECT COUNT(*) FROM information_schema.' . $kind . ' WHERE ' . $schema . '=DATABASE()') !== 0) { throw new RuntimeException('Views, triggers, routines and events are not supported by this database format.'); }
        }
        $this->pulseHeartbeat();
        $tables = [];
        $database = (string) $this->scalar('SELECT DATABASE()');
        foreach ($this->results('SHOW FULL TABLES') as $row) {
            $this->pulseHeartbeat();
            $values = array_values($row);
            $name = $values[0] ?? '';
            if (!is_string($name) || ($values[1] ?? '') !== 'BASE TABLE') { throw new RuntimeException('Views are not supported by this database format.'); }
            $id = self::identifier($name);
            $columns = $this->results('SHOW FULL COLUMNS FROM ' . $id);
            if ($columns === []) { throw new RuntimeException('Cannot read database columns.'); }
            $names = [];
            foreach ($columns as $column) {
                $this->pulseHeartbeat();
                if (!isset($column['Field'], $column['Type']) || !is_string($column['Field']) || !is_string($column['Type'])
                    || !preg_match('/\A(?:tinyint|smallint|mediumint|int|integer|bigint|decimal|numeric|float|double|real|bit|char|varchar|binary|varbinary|tinytext|text|mediumtext|longtext|tinyblob|blob|mediumblob|longblob|enum|set|json|date|datetime|timestamp|time|year)\b/i', $column['Type'])) { throw new RuntimeException('An unsupported database column type was found.'); }
                self::identifier($column['Field']);
                $names[] = $column['Field'];
                if (!empty($column['Collation']) && !preg_match('/\A[A-Za-z0-9]+_[A-Za-z0-9_]+\z/D', $column['Collation'])) { throw new RuntimeException('Unsupported database column collation.'); }
            }
            $createRows = $this->results('SHOW CREATE TABLE ' . $id);
            $this->pulseHeartbeat();
            $create = isset($createRows[0]) ? (array_values($createRows[0])[1] ?? null) : null;
            if (!is_string($create) || strpos($create, 'CREATE TABLE ') !== 0 || strlen($create) > 1048576) { throw new RuntimeException('Cannot read a supported database definition.'); }
            if (preg_match('/\bENGINE\s*=\s*(?:FEDERATED|MRG_MYISAM)\b/i', $create)) { throw new RuntimeException('An external or merged database table requires an explicit dependency rule.'); }
            $order = $this->keyOrder($name, $names);
            $this->pulseHeartbeat();
            $foreignKeys = $this->foreignKeys($name, $database);
            $this->pulseHeartbeat();
            $sizeTerms = array_map(static function (string $column): string { return 'COALESCE(OCTET_LENGTH(CAST(' . self::identifier($column) . ' AS BINARY)),0)'; }, $names);
            $frozen = array_key_exists($name, $this->frozenMemoryCounts);
            $maxBytes = $frozen ? '0' : (string) $this->scalar('SELECT COALESCE(MAX(' . implode('+', $sizeTerms) . '),0) FROM ' . $id);
            $this->pulseHeartbeat();
            if (!ctype_digit($maxBytes) || (float) $maxBytes > PHP_INT_MAX / 8) { throw new RuntimeException('The database row size cannot be represented safely.'); }
            if ($this->policy->tableMayIncludeRows($name)) { $this->assertMemory((int) $maxBytes, count($columns)); }
            $action = $this->policy->tableAction($name)['action'];
            if (!in_array($action, ['include', 'exclude'], true)) { throw new RuntimeException('A database table needs an explicit inclusion decision.'); }
            $tables[] = ['name' => $name, 'columns' => $columns, 'create' => $create, 'order' => $order, 'foreignKeys' => $foreignKeys,
                'sourceRows' => $frozen ? $this->frozenMemoryCounts[$name] : (int) $this->scalar('SELECT COUNT(*) FROM ' . $id), 'action' => $action];
            $this->pulseHeartbeat();
        }
        if ($tables === [] || count($tables) > 10000) { throw new RuntimeException('The database table inventory is empty or exceeds its supported size.'); }
        usort($tables, static function (array $a, array $b): int { return strcmp($a['name'], $b['name']); });
        $known = array_fill_keys(array_column($tables, 'name'), true);
        foreach ($tables as $table) { foreach ($table['foreignKeys'] as $fk) { if (!isset($known[$fk['referencedTable']])) { throw new RuntimeException('A foreign key references a table outside the selected database inventory.'); } } }
        return $tables;
    }

    private function keyOrder(string $table, array $columns): array
    {
        $keys = [];
        foreach ($this->results('SHOW INDEX FROM ' . self::identifier($table)) as $index) {
            if ((string) ($index['Non_unique'] ?? '1') !== '0' || !is_string($index['Column_name'] ?? null) || !in_array($index['Column_name'], $columns, true)) { continue; }
            $keys[(string) $index['Key_name']][(int) $index['Seq_in_index']] = $index['Column_name'];
        }
        if ($keys === []) { return $columns; }
        ksort($keys, SORT_STRING);
        $key = $keys['PRIMARY'] ?? reset($keys);
        ksort($key, SORT_NUMERIC);
        return array_values($key);
    }

    private function foreignKeys(string $table, string $database): array
    {
        $sql = 'SELECT CONSTRAINT_NAME,COLUMN_NAME,REFERENCED_TABLE_SCHEMA,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME,ORDINAL_POSITION FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=X\'' . bin2hex($table) . '\' AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY CONSTRAINT_NAME,ORDINAL_POSITION';
        $keys = [];
        foreach ($this->results($sql) as $row) {
            if (($row['REFERENCED_TABLE_SCHEMA'] ?? null) !== $database) { throw new RuntimeException('A foreign key depends on another database.'); }
            $name = $row['CONSTRAINT_NAME'];
            if (!isset($keys[$name])) { $keys[$name] = ['columns' => [], 'referencedTable' => $row['REFERENCED_TABLE_NAME'], 'referencedColumns' => []]; }
            $keys[$name]['columns'][] = $row['COLUMN_NAME'];
            $keys[$name]['referencedColumns'][] = $row['REFERENCED_COLUMN_NAME'];
        }
        return array_values($keys);
    }

    private function assertPolicy(array $tables): array
    {
        $referenced = [];
        foreach ($tables as $table) { foreach ($table['foreignKeys'] as $fk) { $referenced[$table['name']] = true; $referenced[$fk['referencedTable']] = true; } }
        $modes = [];
        foreach ($tables as $table) {
            $this->pulseHeartbeat();
            if (isset($referenced[$table['name']])) { $modes[$table['name']] = $this->foreignKeyPolicyMode($table); }
        }
        foreach ($tables as $table) {
            foreach ($table['foreignKeys'] as $fk) {
                $this->pulseHeartbeat();
                $child = $modes[$table['name']]; $parent = $modes[$fk['referencedTable']];
                if ($child === 'partial' || $parent === 'partial' || ($child !== 'empty_source' && $parent !== 'empty_source' && $child !== $parent)) {
                    throw new RuntimeException('Foreign-key related tables must preserve all rows and fields together or exclude all related rows together.');
                }
            }
        }
        foreach ($tables as $table) {
            $this->pulseHeartbeat();
            $mutated = false;
            $mayInclude = $this->policy->tableMayIncludeRows($table['name']);
            foreach ($table['columns'] as $column) {
                // Row-level include rules can override category decisions. Only a
                // field's explicit exclusion is unconditional before reading rows.
                $mutated = $mutated || (($this->policy->rules()['fields'][$table['name'] . '.' . $column['Field']] ?? null) === 'exclude');
            }
            if ($mutated && $mayInclude && ($modes[$table['name']] ?? null) !== 'excluded') {
                foreach ($table['columns'] as $column) {
                    if (self::isGenerated($column)) { throw new RuntimeException('Field exclusions cannot safely recalculate generated columns.'); }
                }
                $primary = array_values(array_filter($table['columns'], static function (array $column): bool { return ($column['Key'] ?? '') === 'PRI'; }));
                if ($primary === []) { throw new RuntimeException('Field exclusions require an unchanged primary key for stable verification.'); }
            }
        }
        return $modes;
    }

    private function foreignKeyPolicyMode(array $table): string
    {
        if ($table['sourceRows'] === 0) { return 'empty_source'; }
        if ($this->policy->isComplete()) { return 'included'; }
        if (!$this->policy->tableMayIncludeRows($table['name'])) { return 'excluded'; }
        list($sql, $digestName) = $this->rowQuery($table);
        $seen = 0; $included = 0; $allFields = true;
        foreach ($this->stream($sql, $this->locked) as $row) {
            $seen++;
            unset($row[$digestName]);
            if (!$this->policy->includeRow($table['name'], $row)) { continue; }
            $included++;
            foreach ($table['columns'] as $column) {
                $field = $column['Field'];
                if (!array_key_exists($field, $row)) { throw new RuntimeException('A database row has an unexpected shape.'); }
                if ($this->policy->fieldAction($table['name'], $field, $row)['action'] !== 'include') { $allFields = false; continue; }
                if ($this->policy->filterValue($table['name'], $column, $row[$field], $seen, $row) !== $row[$field]) { $allFields = false; }
            }
        }
        if ($seen !== $table['sourceRows']) { throw new RuntimeException('Database rows changed during the foreign-key policy inspection.'); }
        if ($included === 0) { return 'excluded'; }
        return $included === $seen && $allFields ? 'included' : 'partial';
    }

    private function assertForeignKeys(array $tables, array $modes): void
    {
        $indices = array_flip(array_column($tables, 'name'));
        foreach ($tables as $index => $table) {
            $this->pulseHeartbeat();
            if (in_array($modes[$table['name']] ?? '', ['excluded', 'empty_source'], true)) { continue; }
            foreach ($table['foreignKeys'] as $fk) {
                $this->pulseHeartbeat();
                $child = self::identifier('__wrb4_child_' . $index);
                $parent = self::identifier('__wrb4_parent_' . $indices[$fk['referencedTable']]);
                $equal = []; $present = [];
                foreach ($fk['columns'] as $position => $column) {
                    $equal[] = $child . '.' . self::identifier($column) . '=' . $parent . '.' . self::identifier($fk['referencedColumns'][$position]);
                    $present[] = $child . '.' . self::identifier($column) . ' IS NOT NULL';
                }
                $sql = 'SELECT COUNT(*) FROM ' . self::identifier($table['name']) . ' AS ' . $child . ' LEFT JOIN ' . self::identifier($fk['referencedTable']) . ' AS ' . $parent
                    . ' ON ' . implode(' AND ', $equal) . ' WHERE ' . implode(' AND ', $present) . ' AND ' . $parent . '.' . self::identifier($fk['referencedColumns'][0]) . ' IS NULL';
                if ((string) $this->scalar($sql) !== '0') { throw new RuntimeException('The source database contains orphaned foreign-key rows.'); }
                $this->pulseHeartbeat();
            }
        }
    }

    private function dataChunks(array $table, array &$state): Generator
    {
        $this->assertHeld();
        if (!$this->policy->tableMayIncludeRows($table['name'])) { $state['excludedRows'] = $table['sourceRows']; $state['complete'] = true; return; }
        $names = array_column($table['columns'], 'Field');
        list($sql, $digestName) = $this->rowQuery($table);
        $insertColumns = array_values(array_filter($table['columns'], static function (array $column): bool { return !self::isGenerated($column); }));
        $number = 0;
        foreach ($this->stream($sql) as $row) {
            $number++;
            $digest = $row[$digestName] ?? null;
            unset($row[$digestName]);
            if (!$this->policy->includeRow($table['name'], $row)) { $state['excludedRows']++; continue; }
            if (!is_string($digest) || !preg_match('/\A[a-f0-9]{64}\z/D', $digest)) { throw new RuntimeException('Cannot verify a source database row.'); }
            $filtered = [];
            $changed = false;
            foreach ($table['columns'] as $column) {
                $field = $column['Field'];
                if (!array_key_exists($field, $row)) { throw new RuntimeException('A database row has an unexpected shape.'); }
                if (is_string($row[$field]) && $this->policy->shouldInspectField($table['name'], $field, $row) && $this->policy->containsPersonalData($row[$field])) { throw new RuntimeException('A database value needs a privacy decision.'); }
                $filtered[$field] = $this->policy->filterValue($table['name'], $column, $row[$field], $number, $row);
                $changed = $changed || $filtered[$field] !== $row[$field];
            }
            if ($changed) {
                foreach ($table['columns'] as $column) {
                    if (self::isGenerated($column)) { throw new RuntimeException('Field exclusions cannot safely recalculate generated columns.'); }
                }
                if (array_filter($table['columns'], static function (array $column): bool { return ($column['Key'] ?? '') === 'PRI'; }) === []) { throw new RuntimeException('Field exclusions require an unchanged primary key for stable verification.'); }
                $digest = self::phpRowDigest($names, $filtered);
            }
            hash_update($state['hash'], $digest . "\n");
            yield 'INSERT INTO ' . self::identifier($table['name']) . ' (' . implode(',', array_map(static function (array $column): string { return self::identifier($column['Field']); }, $insertColumns)) . ') VALUES (';
            foreach ($insertColumns as $index => $column) {
                if ($index > 0) { yield ','; }
                yield from $this->valueChunks($column, $filtered[$column['Field']]);
            }
            yield ");\n";
            $state['rows']++;
        }
        if ($number !== $table['sourceRows']) { throw new RuntimeException('The database source row count changed during capture.'); }
        $this->assertHeld();
        $state['complete'] = true;
    }

    private function valueChunks(array $column, $value): Generator
    {
        if ($value === null) { yield 'NULL'; return; }
        $value = (string) $value;
        if (preg_match('/\A(?:tinyint|smallint|mediumint|int|integer|bigint|decimal|numeric|float|double|real)\b/i', $column['Type'])) {
            if (!preg_match('/\A[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?\z/D', $value)) { throw new RuntimeException('A numeric database value cannot be preserved.'); }
            yield $value; return;
        }
        $charset = null;
        if (!empty($column['Collation'])) { $charset = explode('_', $column['Collation'], 2)[0]; }
        elseif (preg_match('/\Ajson\b/i', $column['Type'])) { $charset = 'utf8mb4'; }
        elseif (preg_match('/\A(?:date|datetime|timestamp|time|year)\b/i', $column['Type'])) { $charset = 'ascii'; }
        yield $charset === null ? "X'" : "CONVERT(X'";
        for ($offset = 0, $length = strlen($value); $offset < $length; $offset += 65536) { $this->pulseHeartbeat(); yield bin2hex(substr($value, $offset, 65536)); }
        yield $charset === null ? "'" : "' USING " . $charset . ')';
    }

    private static function isGenerated(array $column): bool
    {
        return preg_match('/\b(?:VIRTUAL|STORED)\s+GENERATED\b/i', (string) ($column['Extra'] ?? '')) === 1;
    }

    private function rowQuery(array $table): array
    {
        $names = array_column($table['columns'], 'Field');
        $fields = array_map(static function (string $name): string { return 'CAST(' . self::identifier($name) . ' AS BINARY) AS ' . self::identifier($name); }, $names);
        $digestName = '__wrb4_row_sha256';
        for ($suffix = 0; in_array($digestName, $names, true); $suffix++) { $digestName = '__wrb4_row_sha256_' . $suffix; }
        $fields[] = self::rowDigestExpression($names) . ' AS ' . self::identifier($digestName);
        return ['SELECT ' . implode(',', $fields) . ' FROM ' . self::identifier($table['name']) . self::orderSql($names, $table['order']), $digestName];
    }

    private function stream(string $sql, bool $requireLock = true): Generator
    {
        $handle = null;
        if (property_exists($this->wpdb, 'dbh')) {
            $property = new \ReflectionProperty($this->wpdb, 'dbh');
            $property->setAccessible(true);
            $handle = $property->getValue($this->wpdb);
        }
        if ($handle instanceof \mysqli) {
            foreach (MysqlRowCursor::rows($handle, $sql) as $row) {
                $this->pulseHeartbeat(true);
                yield $row;
            }
            return;
        }
        // Non-mysqli WordPress adapters still receive one bounded row per request.
        for ($offset = 0; ; $offset++) {
            if ($requireLock) { $this->assertHeld(); }
            $rows = $this->results($sql . ' LIMIT ' . $offset . ',1');
            if ($rows === []) { break; }
            if (count($rows) !== 1) { throw new RuntimeException('The database adapter did not bound its row read.'); }
            $this->pulseHeartbeat(true);
            yield $rows[0];
        }
    }

    private function preamble(): string
    {
        $modes = array_filter(explode(',', $this->environment['sqlMode']), static function (string $mode): bool { return !in_array($mode, ['STRICT_TRANS_TABLES', 'STRICT_ALL_TABLES', 'NO_ZERO_DATE', 'NO_ZERO_IN_DATE'], true); });
        $modes[] = 'NO_AUTO_VALUE_ON_ZERO';
        return "SET @WRB4_SQL_MODE=@@SESSION.SQL_MODE,@WRB4_TIME_ZONE=@@SESSION.time_zone,@WRB4_FOREIGN_KEY_CHECKS=@@SESSION.FOREIGN_KEY_CHECKS;\n"
            . 'SET SESSION SQL_MODE=' . self::literal(implode(',', array_unique($modes))) . ";\nSET SESSION time_zone='+00:00';\nSET NAMES " . $this->environment['characterSet'] . ";\nSET FOREIGN_KEY_CHECKS=0;\n";
    }

    private static function rowDigestExpression(array $columns): string
    {
        $pieces = [];
        foreach ($columns as $column) {
            $id = self::identifier($column);
            $pieces[] = "IF(" . $id . " IS NULL,'N;',CONCAT('V',OCTET_LENGTH(CAST(" . $id . " AS BINARY)),':',CAST(" . $id . " AS BINARY),';'))";
        }
        return 'SHA2(CONCAT(' . implode(',', $pieces) . '),256)';
    }

    private static function phpRowDigest(array $columns, array $row): string
    {
        $hash = hash_init('sha256');
        foreach ($columns as $column) {
            $value = $row[$column];
            hash_update($hash, $value === null ? 'N;' : 'V' . strlen((string) $value) . ':' . (string) $value . ';');
        }
        return hash_final($hash);
    }

    private static function orderSql(array $columns, array $order): string
    {
        $parts = [];
        foreach (array_unique(array_merge($order, $columns)) as $column) { $id = self::identifier($column); $parts[] = '(' . $id . ' IS NOT NULL)'; $parts[] = 'CAST(' . $id . ' AS BINARY)'; }
        return ' ORDER BY ' . implode(',', $parts);
    }

    private static function assertColumnLists(array $columns, array $order): void
    {
        foreach ([$columns, $order] as $list) {
            if ($list === [] || array_keys($list) !== range(0, count($list) - 1) || count(array_unique($list, SORT_REGULAR)) !== count($list)) { throw new RuntimeException('Invalid database verification columns.'); }
            foreach ($list as $column) { if (!is_string($column)) { throw new RuntimeException('Invalid database verification column.'); } self::identifier($column); }
        }
        if (array_diff($order, $columns) !== []) { throw new RuntimeException('Invalid database verification order.'); }
    }

    private function definitionFingerprint(array $tables): string
    {
        $definitions = [];
        foreach ($tables as $key => $table) {
            $this->pulseHeartbeat();
            $definitions[$key] = [$table['name'], $table['create'], $table['columns'], $table['order'], $table['foreignKeys']];
        }
        return hash('sha256', serialize($definitions));
    }

    /** Ignore only the mutable table-level AUTO_INCREMENT counter, never column definitions or quoted text. */
    private function snapshotFingerprint(array $tables): string
    {
        $backslash = strpos(',' . strtoupper($this->environment['sqlMode']) . ',', ',NO_BACKSLASH_ESCAPES,') === false;
        $ansiQuotes = strpos(',' . strtoupper($this->environment['sqlMode']) . ',', ',ANSI_QUOTES,') !== false;
        foreach ($tables as &$table) {
            $sql = $table['create']; $depth = 0; $opened = false; $end = null; $length = strlen($sql);
            for ($i = 0; $i < $length; ++$i) {
                $c = $sql[$i];
                if ($c === "'" || $c === '"' || $c === '`') {
                    $quote = $c; $closed = false;
                    while (++$i < $length) {
                        if ($sql[$i] === $quote) {
                            if ($i + 1 < $length && $sql[$i + 1] === $quote) { ++$i; continue; }
                            $closed = true; break;
                        }
                        if ($sql[$i] === '\\' && $backslash && $quote !== '`' && !($quote === '"' && $ansiQuotes)) { ++$i; }
                    }
                    if (!$closed) { throw new RuntimeException('Invalid table definition.'); }
                } elseif ($c === '/' && substr($sql, $i, 2) === '/*') {
                    $close = strpos($sql, '*/', $i + 2);
                    if ($close === false) { throw new RuntimeException('Invalid table definition.'); }
                    $i = $close + 1;
                } elseif ($c === '(') { ++$depth; $opened = true; }
                elseif ($c === ')' && $opened && --$depth === 0) { $end = $i + 1; break; }
            }
            if ($end === null) { throw new RuntimeException('Invalid table definition.'); }
            $suffix = substr($sql, $end);
            $suffix = preg_replace('/\A(\s*ENGINE\s*=\s*InnoDB)\s+AUTO_INCREMENT\s*=\s*[0-9]+(?=\s|$)/i', '$1', $suffix, 1);
            if (!is_string($suffix)) { throw new RuntimeException('Invalid table definition.'); }
            $table['create'] = substr($sql, 0, $end) . $suffix;
        }
        unset($table);
        return $this->definitionFingerprint($tables);
    }

    private function assertMemory(int $bytes, int $columns): void
    {
        $configured = trim((string) ini_get('memory_limit'));
        if ($configured === '' || $configured === '-1') { return; }
        $unit = strtolower(substr($configured, -1));
        $limit = (float) $configured * (['g' => 1073741824, 'm' => 1048576, 'k' => 1024][$unit] ?? 1);
        // mysqli retains a row; filtering and hashing may each hold another copy.
        if ($bytes * 6 + $columns * 2048 + 2097152 > $limit - memory_get_usage(true)) { throw new RuntimeException('The largest database row exceeds available PHP capture memory.'); }
    }

    private function assertHeld(): void
    {
        if (!$this->locked) { throw new RuntimeException('Database rows require the active read lock.'); }
        $this->assertConnection();
    }

    private function assertConnection(): void
    {
        if ($this->connection === null || (string) $this->scalar('SELECT CONNECTION_ID()') !== $this->connection) { throw new RuntimeException('The database capture connection changed.'); }
    }

    /** Only completely excluded, unrelated MEMORY tables may use a short separate lock. */
    private function excludedMemoryTables(): array
    {
        $prefix = $this->wpdb->prefix ?? null;
        if (!is_string($prefix)) { throw new RuntimeException('Cannot identify the policy context tables.'); }
        $result = [];
        foreach ($this->tables as $table) {
            $name = $table['name'];
            $engine = (string) $this->scalar("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=X'" . bin2hex($name) . "'");
            if (strcasecmp($engine, 'InnoDB') === 0) { continue; }
            if (strcasecmp($engine, 'MEMORY') !== 0 || !preg_match('/\A[A-Za-z0-9_]+\z/D', $name) || $this->policy->tableMayIncludeRows($name)
                || in_array($name, [$prefix . 'posts', $prefix . 'postmeta', $prefix . 'options'], true)
                || $table['foreignKeys'] !== []) { throw new UnsupportedOnlineDatabaseEngine('保守なし取得の対象テーブルを安全に確認できません。'); }
            foreach ($this->tables as $other) { foreach ($other['foreignKeys'] as $fk) {
                if (($fk['referencedTable'] ?? null) === $name) { throw new UnsupportedOnlineDatabaseEngine('保守なし取得の参照関係を安全に確認できません。'); }
            } }
            $result[] = $name;
        }
        return $result;
    }

    private function secondaryConnection(): \mysqli
    {
        if ($this->secondaryFactory !== null) { $connection = ($this->secondaryFactory)(); }
        else {
            if (!class_exists('wpdb', false) || get_class($this->wpdb) !== 'wpdb' || !extension_loaded('mysqli')
                || !defined('WP_CONTENT_DIR') || !defined('WP_PLUGIN_DIR') || !defined('ABSPATH')
                || !defined('DB_HOST') || !is_string(DB_HOST)
                || !defined('DB_USER') || !defined('DB_PASSWORD') || !defined('DB_NAME')
                || (defined('MYSQL_CLIENT_FLAGS') && MYSQL_CLIENT_FLAGS !== 0)) { throw new UnsupportedOnlineDatabaseEngine('追加のDB接続を安全に確認できません。'); }
            $dropin = WP_CONTENT_DIR . '/db.php';
            if (@lstat($dropin) !== false && !self::trustedQueryMonitorDropIn($dropin, WP_PLUGIN_DIR, PHP_SAPI, defined('QM_TESTS'),
                extension_loaded('Zend OPcache') && ini_get('opcache.enable_cli') !== '0', (string) (new \ReflectionClass('wpdb'))->getFileName(), ABSPATH . 'wp-includes/class-wpdb.php')) {
                throw new UnsupportedOnlineDatabaseEngine('追加のDB接続を安全に確認できません。');
            }
            $host = $this->wpdb->parse_db_host(DB_HOST);
            if (!is_array($host) || count($host) !== 4 || !in_array($host[0], ['localhost', '127.0.0.1'], true) || $host[3]
                || ($host[1] !== null && (!is_int($host[1]) || $host[1] < 1 || $host[1] > 65535))
                || ($host[2] !== null && (!is_string($host[2]) || $host[2] === '' || $host[2][0] !== '/' || strpos($host[2], "\0") !== false))) { throw new UnsupportedOnlineDatabaseEngine('追加のDB接続を安全に確認できません。'); }
            $connection = mysqli_init();
            if (!$connection) { throw new RuntimeException('Cannot initialize a secondary database connection.'); }
            try {
                mysqli_options($connection, MYSQLI_OPT_CONNECT_TIMEOUT, 5);
                if (!@mysqli_real_connect($connection, $host[0], DB_USER, DB_PASSWORD, DB_NAME, $host[1], $host[2])) { throw new RuntimeException('Cannot open a secondary database connection.'); }
            } catch (\Throwable $error) { $connection->close(); throw new RuntimeException('Cannot open a secondary database connection.'); }
        }
        if (!$connection instanceof \mysqli) { throw new RuntimeException('Invalid secondary database connection.'); }
        return $connection;
    }

    /** The fixed Query Monitor CLI drop-in returns before changing the connection; every other drop-in is rejected. */
    private static function trustedQueryMonitorDropIn(string $dropin, string $plugins, string $sapi, bool $qmTests, bool $opcache, string $classFile, string $coreFile): bool
    {
        if ($sapi !== 'cli' || $qmTests || $opcache || !function_exists('posix_geteuid')) { return false; }
        $class = realpath($classFile); $core = realpath($coreFile);
        if ($class === false || $core === false || $class !== $core) { return false; }
        clearstatcache(true, $dropin); $link = @lstat($dropin); $target = realpath($dropin);
        $expected = realpath(rtrim($plugins, '/') . '/query-monitor/wp-content/db.php');
        if (!is_array($link) || $link['uid'] !== posix_geteuid() || $target === false || $expected === false || $target !== $expected) { return false; }
        clearstatcache(true, $target); $before = @lstat($target);
        if (!is_array($before) || ($before['mode'] & 0170000) !== 0100000 || ($before['mode'] & 0022) !== 0 || $before['uid'] !== posix_geteuid() || $before['nlink'] !== 1 || $before['size'] !== 2403) { return false; }
        $hash = @hash_file('sha256', $target); clearstatcache(true, $target); clearstatcache(true, $dropin); $after = @lstat($target); $linkAfter = @lstat($dropin);
        foreach (['dev','ino','mode','uid','nlink','size','mtime','ctime'] as $key) { if (!is_array($after) || $before[$key] !== $after[$key]) { return false; } }
        foreach (['dev','ino','mode','uid','size','mtime','ctime'] as $key) { if (!is_array($linkAfter) || $link[$key] !== $linkAfter[$key]) { return false; } }
        return is_string($hash) && hash_equals('795f12a7b591a38db8d9ff1e5a0dab82f20afe216b2547ee156922791e9bac16', $hash) && realpath($dropin) === $target;
    }

    private function auxScalar(\mysqli $connection, string $sql): string
    {
        try { $result = mysqli_query($connection, $sql); if (!$result instanceof \mysqli_result) { throw new RuntimeException('Invalid result.'); } $row = mysqli_fetch_row($result); mysqli_free_result($result); }
        catch (\Throwable $error) { throw new RuntimeException('Cannot verify the secondary database connection.'); }
        if (!is_array($row) || $row[0] === null) { throw new RuntimeException('Cannot verify the secondary database connection.'); }
        return (string) $row[0];
    }

    private function auxExecute(\mysqli $connection, string $sql): void
    {
        try { $result = mysqli_query($connection, $sql); } catch (\Throwable $error) { throw new RuntimeException('Cannot acquire or release the excluded table lock.'); }
        if ($result !== true) { throw new RuntimeException('Cannot acquire or release the excluded table lock.'); }
    }

    private function results(string $sql): array
    {
        try { $rows = $this->wpdb->get_results($sql, 'ARRAY_A'); }
        catch (\Throwable $error) { throw new RuntimeException('Cannot read database metadata or rows.'); }
        if (!is_array($rows) || ($this->wpdb->last_error ?? '') !== '') { throw new RuntimeException('Cannot read database metadata or rows.'); }
        return $rows;
    }

    private function scalar(string $sql)
    {
        try { $value = $this->wpdb->get_var($sql); }
        catch (\Throwable $error) { throw new RuntimeException('Cannot inspect database capture state.'); }
        if ($value === null || ($this->wpdb->last_error ?? '') !== '') { throw new RuntimeException('Cannot inspect database capture state.'); }
        return $value;
    }

    private function execute(string $sql): void
    {
        try { $result = $this->wpdb->query($sql); }
        catch (\Throwable $error) { throw new RuntimeException('Cannot acquire, maintain or release the database read lock.'); }
        if ($result === false || ($this->wpdb->last_error ?? '') !== '') { throw new RuntimeException('Cannot acquire, maintain or release the database read lock.'); }
    }

    private static function literal(string $value): string { return "'" . str_replace("'", "''", $value) . "'"; }

    private static function identifier(string $name): string
    {
        if ($name === '' || strlen($name) > 64 || preg_match('/[\x00-\x1f\x7f]/', $name)) { throw new RuntimeException('Invalid database identifier.'); }
        return '`' . str_replace('`', '``', $name) . '`';
    }
}
