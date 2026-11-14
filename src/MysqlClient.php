<?php

declare(strict_types=1);

namespace WordPressR2Backup;

use RuntimeException;

final class MysqlClient implements RecoveryDatabase
{
    private string $mysql;
    private string $dump;
    private int $ownerId;
    private ?array $readLock = null;
    private int $operationTimeoutSeconds;

    private string $privateDefaultsFile;
    private string $ownedFixtureWorkspace;

    public function __construct(
        string $mysqlBinary,
        string $dumpBinary,
        string $privateDefaultsFile,
        string $ownedFixtureWorkspace,
        int $operationTimeoutSeconds = 300)
    {
        if ($operationTimeoutSeconds < 1 || $operationTimeoutSeconds > 86400) { throw new RuntimeException('Invalid database operation time limit.'); }
        $this->operationTimeoutSeconds = $operationTimeoutSeconds;
        $this->privateDefaultsFile = $privateDefaultsFile;
        $this->ownedFixtureWorkspace = $ownedFixtureWorkspace;

        $ownerProbe = tmpfile();
        if ($ownerProbe === false) {
            throw new RuntimeException('Cannot establish the current file owner.');
        }
        $this->ownerId = fstat($ownerProbe)['uid'];
        fclose($ownerProbe);
        $this->mysql = $this->binary($mysqlBinary);
        $this->dump = $this->binary($dumpBinary);
        $this->connection();
    }

    public function dump(string $schema, string $sqlFile): void
    {
        $this->schema($schema);
        if ($this->readLock !== null) { $this->assertReadLock($schema); }
        $tables = $this->query($schema, 'SELECT HEX(TABLE_NAME),TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME');
        $definitions = $this->readLock === null ? [] : $this->databaseInventory($schema)['definitions'];
        $parent = $this->regularParent($sqlFile);
        $destination = $parent . '/' . basename($sqlFile);
        if (@lstat($destination) !== false) {
            throw new RuntimeException('The database output already exists.');
        }
        $temporary = $parent . '/.wrb-sql-' . bin2hex(random_bytes(12));
        $output = fopen($temporary, 'x+b');
        if ($output === false) {
            throw new RuntimeException('Cannot create a private database output.');
        }
        try {
            if (!chmod($temporary, 0600)) {
                throw new RuntimeException('Cannot protect the database output.');
            }
            // MariaDB has no GTID purge option and cannot read events while the scheduler is disabled; it is dumped only when it holds none.
            $mariaDb = $this->isMariaDb($schema);
            if ($mariaDb && $this->eventNames($schema) !== []) { throw new RuntimeException('MariaDB events cannot be output with event_scheduler=DISABLED.'); }
            $command = array_merge($this->connection($this->dump), [
                '--single-transaction', '--quick', '--hex-blob', '--triggers', '--routines', $mariaDb ? '--skip-events' : '--events', '--skip-lock-tables'],
                $mariaDb ? [] : ['--set-gtid-purged=OFF'], ['--no-tablespaces', '--skip-add-drop-table',
                '--default-character-set=utf8mb4', $schema,
            ]);
            $this->run($command, null, $output);
            if ($tables !== $this->query($schema, 'SELECT HEX(TABLE_NAME),TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')) {
                throw new RuntimeException('The source table list changed during database output.');
            }
            if (!fflush($output) || !\WordPressR2Backup\FileSafety::sync($output)) {
                throw new RuntimeException('Cannot persist the database output.');
            }
            $this->verifyDumpDefinitions($output, $tables, $definitions);
            if ($this->readLock !== null) { $this->assertReadLock($schema); }
            fclose($output);
            $output = null;
            if (!link($temporary, $destination)) {
                throw new RuntimeException('Cannot publish database output without replacement.');
            }
        } finally {
            if (is_resource($output)) {
                fclose($output);
            }
            unlink($temporary);
        }
    }

    public function importIntoEmpty(string $schema, string $sqlFile, ?array $expectedEvents = null): void
    {
        if ($this->readLock !== null) { throw new RuntimeException('Import is not allowed during a source read lock.'); }
        $this->schema($schema);
        if ($expectedEvents !== null) {
            if (!array_is_list($expectedEvents) || count($expectedEvents) > 10000) {
                throw new RuntimeException('Expected event names must be a bounded list.');
            }
            foreach ($expectedEvents as $name) {
                if (!is_string($name) || $name === '' || strlen($name) > 256
                    || str_contains($name, "\0") || !mb_check_encoding($name, 'UTF-8')) {
                    throw new RuntimeException('An expected event name is invalid.');
                }
            }
            sort($expectedEvents, SORT_STRING);
            if (count(array_unique($expectedEvents, SORT_STRING)) !== count($expectedEvents)) {
                throw new RuntimeException('Expected event names must be unique.');
            }
        }
        $importer = $this->assertImportReady($schema, $expectedEvents);
        $this->regularParent($sqlFile);
        $stat = @lstat($sqlFile);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || !is_readable($sqlFile)) {
            throw new RuntimeException('The database input must be a readable regular file.');
        }
        $source = fopen($sqlFile, 'rb');
        $input = tmpfile();
        $output = tmpfile();
        try {
            if ($source === false || $input === false || $output === false) {
                throw new RuntimeException('Cannot open private database streams.');
            }
            $transaction = "SET SESSION autocommit=0;\n";
            if (fwrite($input, $transaction) !== strlen($transaction)) { throw new RuntimeException('Cannot prepare the database import transaction.'); }
            if (stream_copy_to_stream($source, $input) === false) {
                throw new RuntimeException('Cannot copy the database input.');
            }
            $nonce = bin2hex(random_bytes(16));
            $start = 'wrb-import-start-' . $nonce;
            $end = 'wrb-import-end-' . $nonce;
            $audit = "\n;\nDELIMITER ;\nCOMMIT;\nSELECT '$start';\nSHOW SESSION STATUS LIKE 'Com_create_event';\nSELECT '$end';\n";
            if (fwrite($input, $audit) !== strlen($audit) || !rewind($input)) {
                throw new RuntimeException('Cannot prepare the database import audit.');
            }
            $attempt = @fopen($this->ownedFixtureWorkspace . '/.wrb-import-' . $schema, 'x+b');
            if ($attempt === false) {
                throw new RuntimeException('A previously attempted import requires a fresh synthetic schema.');
            }
            try {
                $record = "synthetic import attempted\n";
                if (!chmod($this->ownedFixtureWorkspace . '/.wrb-import-' . $schema, 0600)
                    || fwrite($attempt, $record) !== strlen($record)
                    || !fflush($attempt) || !\WordPressR2Backup\FileSafety::sync($attempt)) {
                    throw new RuntimeException('Cannot persist the synthetic import attempt.');
                }
            } finally {
                fclose($attempt);
            }
            $this->run(array_merge($importer, [
                '--batch', '--skip-column-names', '--raw', '--binary-mode=1', '--local-infile=0', '--skip-reconnect',
                '--default-character-set=utf8mb4', $schema,
            ]), $input, $output);
            $this->verifyImportedEvents($schema, $output, $start, $end, $expectedEvents);
        } finally {
            foreach ([$source, $input, $output] as $stream) {
                if (is_resource($stream)) { fclose($stream); }
            }
        }
    }

    /**
     * Everything importIntoEmpty() requires of the server, the empty schema and its importer, without starting an import. A previous
     * attempt on this schema also refuses, so a request can fail here rather than after the recovery work.
     * @return array the importer connection command
     */
    public function assertImportReady(string $schema, ?array $expectedEvents = null): array
    {
        $this->schema($schema);
        if (trim($this->query($schema, 'SELECT @@GLOBAL.event_scheduler')) !== 'DISABLED') {
            throw new RuntimeException('Database import requires event_scheduler=DISABLED at server startup.');
        }
        if ($expectedEvents !== null && $expectedEvents !== [] && $this->isMariaDb($schema)) {
            throw new RuntimeException('MariaDB cannot import events with event_scheduler=DISABLED.');
        }
        $objects = $this->query($schema, <<<'SQL'
SELECT (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE())
+ (SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE())
SQL);
        if (trim($objects) !== '0' || $this->eventNames($schema) !== []) {
            throw new RuntimeException('Database import requires a new empty synthetic schema.');
        }
        if (@lstat($this->ownedFixtureWorkspace . '/.wrb-import-' . $schema) !== false) {
            throw new RuntimeException('A previously attempted import requires a fresh synthetic schema.');
        }
        $importer = $this->importerConnection($schema);
        $this->assertImporter($schema, $importer);
        return $importer;
    }

    /** Objects whose dump carries a definer, which another account cannot recreate. */
    public function definedObjectCount(string $schema): int
    {
        $this->schema($schema);
        $count = trim($this->query($schema, <<<'SQL'
SELECT (SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE())
+ (SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE())
+ (SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE())
SQL));
        if (!ctype_digit($count)) { throw new RuntimeException('Cannot count the database definitions.'); }
        return (int) $count + count($this->eventNames($schema));
    }

    /** @return array<string,string> base table name in hex => server checksum, comparable between two schemas of one server */
    public function tableChecksums(string $schema): array
    {
        $this->schema($schema);
        $checksums = [];
        foreach (self::listingRows($this->query($schema, "SELECT HEX(TABLE_NAME) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME"), 1) as [$hex]) {
            $row = explode("\t", rtrim($this->query($schema, 'CHECKSUM TABLE `' . str_replace('`', '``', self::metadataName($hex)) . '` EXTENDED'), "\n"));
            if (count($row) !== 2 || !ctype_digit($row[1])) { throw new RuntimeException('Cannot read a table checksum.'); }
            $checksums[$hex] = $row[1];
        }
        return $checksums;
    }

    /**
     * The import body is executed with the server's own privileges of the account that runs it, so that account must be
     * a dedicated importer for this one schema. The operator (or fixture) creates it, checks that no anonymous or
     * same-name account on another host can also match it, and stores its generated client settings next to the
     * default settings as importer-<schema>.cnf. This does not isolate the host operating system or limit resource use.
     */
    private function importerConnection(string $schema): array
    {
        $file = $this->ownedFixtureWorkspace . '/importer-' . $schema . '.cnf';
        if (@lstat($file) === false) {
            throw new RuntimeException('A dedicated importer account for this schema is required; the import was not started.');
        }
        $this->connection($this->mysql, null, $default);
        $command = $this->connection($this->mysql, $file, $importer);
        if ($importer === null || $importer === '' || $importer === $default) {
            throw new RuntimeException('The importer account must differ from the administrative account.');
        }
        return $command;
    }

    private const IMPORTER_PRIVILEGES = ['ALTER', 'ALTER ROUTINE', 'CREATE', 'CREATE ROUTINE', 'CREATE TEMPORARY TABLES', 'CREATE VIEW', 'DELETE', 'DROP', 'EVENT',
        'EXECUTE', 'INDEX', 'INSERT', 'LOCK TABLES', 'REFERENCES', 'SELECT', 'SHOW VIEW', 'TRIGGER', 'UPDATE'];

    /** Closed allow-list over what this connection can see; anything not recognised refuses the import before it starts. */
    private function assertImporter(string $schema, array $connection): void
    {
        $refuse = static function (): void { throw new RuntimeException('The import account is not limited to the new schema; the import was not started.'); };
        $identity = explode("\t", rtrim($this->query('', "SELECT VERSION(), USER(), CURRENT_USER(), IFNULL(CURRENT_ROLE(), 'NONE')", $connection), "\n"));
        if (count($identity) !== 4 || $identity[3] !== 'NONE') { $refuse(); }
        [$version, $requested, $matched] = $identity;
        $at = static function (string $account): array { $position = strrpos($account, '@'); return $position === false ? ['', ''] : [substr($account, 0, $position), substr($account, $position + 1)]; };
        [$requestedUser] = $at($requested); [$matchedUser, $matchedHost] = $at($matched);
        if ($requestedUser === '' || $requestedUser !== $matchedUser || $matchedHost === '') { $refuse(); }
        $mariaDb = str_contains($version, 'MariaDB');
        $literal = $schema;
        if (!$mariaDb) {
            $settings = explode("\t", rtrim($this->query('', 'SELECT @@GLOBAL.partial_revokes, @@GLOBAL.mandatory_roles', $connection), "\n"));
            if (count($settings) !== 2 || $settings[1] !== '' || !in_array($settings[0], ['0', '1', 'OFF', 'ON'], true)) { $refuse(); }
            $literal = in_array($settings[0], ['1', 'ON'], true) ? $schema : str_replace('_', '\\_', $schema);
        } else {
            $literal = str_replace('_', '\\_', $schema);
        }
        $quote = static function (string $value): string { return '`' . str_replace('`', '``', $value) . '`'; };
        $grantee = $quote($matchedUser) . '@' . $quote($matchedHost);
        $suffix = "(?: IDENTIFIED BY PASSWORD '\\*[0-9A-F]{40}')?";
        $global = 0; $scoped = 0;
        foreach (explode("\n", rtrim($this->query('', 'SHOW GRANTS', $connection), "\n")) as $line) {
            $line = str_replace('\\\\', '\\', $line); // batch output doubles backslashes; any other escape stays and is refused below
            if (preg_match('/\AGRANT USAGE ON \*\.\* TO ' . preg_quote($grantee, '/') . $suffix . '\z/D', $line) === 1) { ++$global; continue; }
            if (preg_match('/\AGRANT ([A-Z ,]+) ON `((?:[^`]|``)+)`\.\* TO ' . preg_quote($grantee, '/') . '\z/D', $line, $match) !== 1
                || str_replace('``', '`', $match[2]) !== $literal) { $refuse(); }
            $privileges = explode(', ', $match[1]);
            if ($privileges === ['ALL PRIVILEGES']) { ++$scoped; continue; }
            if ($privileges !== array_values(array_unique($privileges)) || array_diff($privileges, self::IMPORTER_PRIVILEGES) !== []) { $refuse(); }
            ++$scoped;
        }
        if ($global !== 1 || $scoped !== 1) { $refuse(); }
        // SHOW GRANTS lists only the matched account. A same-name entry for another host can still add database, table, column
        // or routine rights to this connection; every schema the connection holds any right on is visible here.
        $visible = explode("\n", rtrim($this->query('', 'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA ORDER BY SCHEMA_NAME', $connection), "\n"));
        if (!in_array($schema, $visible, true) || array_diff($visible, [$schema, 'information_schema', 'performance_schema']) !== []) { $refuse(); }
    }

    private function verifyImportedEvents(string $schema, $output, string $start, string $end, ?array $expectedEvents): void
    {
        if (!rewind($output)) { throw new RuntimeException('Cannot read the database import audit.'); }
        $contents = stream_get_contents($output, 16 * 1024 * 1024 + 1);
        if ($contents === false || strlen($contents) > 16 * 1024 * 1024
            || substr_count($contents, $start) !== 1 || substr_count($contents, $end) !== 1
            || preg_match('/(?:\A|\n)' . preg_quote($start, '/') . '\nCom_create_event\t(0|[1-9][0-9]{0,4})\n'
                . preg_quote($end, '/') . '\n\z/D', $contents, $match) !== 1 || (int) $match[1] > 10000) {
            throw new RuntimeException('The database import event audit is missing or invalid; a fresh target is required.');
        }
        $created = (int) $match[1];
        $events = $this->eventNames($schema);
        if ($created !== count($events) || ($expectedEvents !== null && $expectedEvents !== $events)) {
            throw new RuntimeException('The imported event definitions do not match the import audit or expected names; a fresh target is required.');
        }
    }

    private function eventNames(string $schema): array
    {
        $query = $this->isMariaDb($schema)
            ? 'SELECT HEX(name) FROM mysql.event WHERE db=DATABASE()'
            : 'SELECT HEX(EVENT_NAME) FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE()';
        $events = [];
        foreach (self::listingRows($this->query($schema, $query), 1) as [$name]) {
            $events[] = self::metadataName($name);
        }
        sort($events, SORT_STRING);
        return $events;
    }

    private function isMariaDb(string $schema): bool
    {
        return str_contains($this->query($schema, 'SELECT VERSION()'), 'MariaDB');
    }

    public function withGlobalReadLock(string $schema, callable $capture, int $timeoutSeconds = 30): array
    {
        $this->schema($schema);
        if ($this->readLock !== null || $timeoutSeconds < 1 || $timeoutSeconds > 300) {
            throw new RuntimeException('Invalid or nested synthetic read lock.');
        }
        $this->requireInventoryPrivileges($schema);
        $command = array_merge($this->connection(), ['--batch', '--skip-column-names', '--raw', '--unbuffered',
            '--binary-mode=1', '--skip-reconnect', '--default-character-set=utf8mb4', $schema]);
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
            $this->ownedFixtureWorkspace, ['MYSQL_TEST_LOGIN_FILE' => $this->ownedFixtureWorkspace . '/absent-login.cnf'], ['bypass_shell' => true]);
        if (!is_resource($process)) { throw new RuntimeException('Cannot start the synthetic read-lock connection.'); }
        foreach ($pipes as $pipe) { stream_set_blocking($pipe, false); }
        $this->readLock = ['process' => $process, 'pipes' => $pipes, 'schema' => $schema, 'connectionId' => null,
            'phase' => 'acquiring', 'deadline' => microtime(true) + $timeoutSeconds];
        try {
            $this->readLockCommand('SET SESSION lock_wait_timeout=' . min(5, $timeoutSeconds) . '; FLUSH TABLES WITH READ LOCK;');
            $this->readLock['phase'] = 'held';
            $facts = $this->databaseInventory($schema);
            $facts['lockedAt'] = self::captureTime();
            $result = $capture($facts);
            $this->assertReadLock($schema);
            $current = $this->databaseInventory($schema);
            if ($current['inventorySha256'] !== $facts['inventorySha256'] || $current['serverVersion'] !== $facts['serverVersion']) {
                throw new RuntimeException('The locked database inventory changed.');
            }
            $this->readLockCommand('UNLOCK TABLES;');
            $this->readLock['phase'] = 'released';
            $facts['releasedAt'] = self::captureTime();
            $this->closeReadLock();
            return ['result' => $result, 'database' => $facts];
        } finally {
            if ($this->readLock !== null) { $this->closeReadLock(); }
        }
    }

    private static function captureTime(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    }

    private function requireInventoryPrivileges(string $schema): void
    {
        $privileges = [];
        foreach (explode("\n", $this->query($schema, 'SHOW GRANTS FOR CURRENT_USER()')) as $grant) {
            if (preg_match('/\AGRANT (.+) ON \*\.\* TO /i', $grant, $match) !== 1) { continue; }
            foreach (explode(',', strtoupper($match[1])) as $privilege) { $privileges[trim($privilege)] = true; }
        }
        if (isset($privileges['ALL PRIVILEGES'])) { return; }
        foreach (['SELECT', 'SHOW VIEW', 'TRIGGER', 'EVENT'] as $required) {
            if (!isset($privileges[$required])) { throw new RuntimeException('Complete synthetic metadata requires explicit global read privileges.'); }
        }
    }

    private function databaseInventory(string $schema): array
    {
        $version = trim($this->query($schema, 'SELECT VERSION()'));
        if (!preg_match('/\A8\.0\.[0-9]+(?:[-.][A-Za-z0-9.-]+)?\z/D', $version)) { throw new RuntimeException('This capture path requires the supported MySQL 8.0 synthetic server.'); }
        $tables = [];
        $listing = $this->query($schema, 'SELECT HEX(TABLE_NAME),TABLE_TYPE,COALESCE(ENGINE,\'NULL\') FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY HEX(TABLE_NAME)');
        foreach (self::listingRows($listing, 3) as [$name, $type, $engine]) {
            $name = self::metadataName($name);
            if ($type !== 'BASE TABLE' && $type !== 'VIEW') { throw new RuntimeException('Unsupported database table kind.'); }
            if (($type === 'BASE TABLE' && !in_array($engine, ['InnoDB', 'MyISAM', 'MEMORY'], true)) || ($type === 'VIEW' && $engine !== 'NULL')) {
                throw new RuntimeException('Unsupported database storage engine.');
            }
            $tables[] = ['name' => $name, 'type' => $type, 'engine' => $engine === 'NULL' ? null : $engine];
        }
        $queries = [
            'views' => "SELECT HEX(TABLE_NAME),SHA2(CONCAT_WS(0x1F,VIEW_DEFINITION,CHECK_OPTION,IS_UPDATABLE,DEFINER,SECURITY_TYPE,CHARACTER_SET_CLIENT,COLLATION_CONNECTION),256) FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE() AND VIEW_DEFINITION IS NOT NULL ORDER BY HEX(TABLE_NAME)",
            'triggers' => "SELECT HEX(TRIGGER_NAME),SHA2(CONCAT_WS(0x1F,ACTION_STATEMENT,ACTION_TIMING,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,DEFINER,SQL_MODE),256) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY HEX(TRIGGER_NAME)",
            'routines' => "SELECT HEX(ROUTINE_NAME),ROUTINE_TYPE,IF(ROUTINE_DEFINITION IS NULL,NULL,SHA2(CONCAT_WS(0x1F,ROUTINE_DEFINITION,DTD_IDENTIFIER,IS_DETERMINISTIC,SQL_DATA_ACCESS,SECURITY_TYPE,DEFINER,SQL_MODE),256)) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE() ORDER BY HEX(ROUTINE_NAME),ROUTINE_TYPE",
            'events' => "SELECT HEX(EVENT_NAME),IF(EVENT_DEFINITION IS NULL,NULL,SHA2(CONCAT_WS(0x1F,EVENT_DEFINITION,DEFINER,TIME_ZONE,EVENT_TYPE,COALESCE(EXECUTE_AT,''),COALESCE(INTERVAL_VALUE,''),COALESCE(INTERVAL_FIELD,''),COALESCE(STARTS,''),COALESCE(ENDS,''),STATUS,ON_COMPLETION,SQL_MODE),256)) FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE() ORDER BY HEX(EVENT_NAME)",
        ];
        $definitions = [];
        foreach ($queries as $kind => $query) {
            $definitions[$kind] = [];
            foreach (self::listingRows($this->query($schema, $query), $kind === 'routines' ? 3 : 2) as $row) {
                $name = self::metadataName($row[0]); $hash = $row[array_key_last($row)];
                if (!preg_match('/\A[a-f0-9]{64}\z/D', $hash)) { throw new RuntimeException('A required database definition is unavailable.'); }
                if ($kind === 'routines') {
                    if (!in_array($row[1], ['PROCEDURE', 'FUNCTION'], true)) { throw new RuntimeException('Unsupported database routine kind.'); }
                    $definitions[$kind][] = ['name' => $name, 'type' => $row[1], 'sha256' => $hash];
                } else { $definitions[$kind][] = ['name' => $name, 'sha256' => $hash]; }
            }
        }
        if (count($definitions['views']) !== count(array_filter($tables, static fn (array $table): bool => $table['type'] === 'VIEW'))) {
            throw new RuntimeException('A required view definition is unavailable.');
        }
        $canonical = json_encode(['tables' => $tables, 'definitions' => $definitions], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($canonical) > 16 * 1024 * 1024) { throw new RuntimeException('The database inventory exceeds its limit.'); }
        return ['version' => 1, 'schema' => $schema, 'serverVersion' => $version, 'tables' => $tables,
            'definitions' => $definitions, 'inventorySha256' => hash('sha256', $canonical)];
    }

    private static function listingRows(string $listing, int $columns): array
    {
        if ($listing === '') { return []; }
        $rows = explode("\n", rtrim($listing, "\n"));
        if (count($rows) > 10000) { throw new RuntimeException('Database inventory count exceeds its limit.'); }
        return array_map(static function (string $row) use ($columns): array {
            $values = explode("\t", $row);
            if (count($values) !== $columns) { throw new RuntimeException('Invalid database inventory row.'); }
            return $values;
        }, $rows);
    }
    private static function metadataName(string $hex): string
    {
        if (!preg_match('/\A(?:[A-Fa-f0-9]{2}){1,256}\z/D', $hex)) { throw new RuntimeException('Invalid database object name.'); }
        $name = hex2bin($hex);
        if (!mb_check_encoding($name, 'UTF-8') || str_contains($name, "\0")) { throw new RuntimeException('Unsupported database object name.'); }
        return $name;
    }

    private function assertReadLock(string $schema): void
    {
        if ($this->readLock === null || $this->readLock['schema'] !== $schema || $this->readLock['phase'] !== 'held') {
            throw new RuntimeException('The matching source read lock is not held.');
        }
        $this->readLockCommand('');
    }
    private function readLockCommand(string $sql): void
    {
        $nonce = bin2hex(random_bytes(16)); $prefix = 'wrb-read-lock-' . $nonce . ':';
        $command = $sql . " SELECT CONCAT('$prefix',CONNECTION_ID());\n";
        $offset = 0; $output = '';
        while (true) {
            $this->readLockAlive();
            if ($offset < strlen($command)) {
                $written = @fwrite($this->readLock['pipes'][0], substr($command, $offset));
                if ($written === false) { throw new RuntimeException('The source lock command failed.'); }
                $offset += $written;
            }
            $output .= stream_get_contents($this->readLock['pipes'][1]);
            $error = stream_get_contents($this->readLock['pipes'][2]);
            if ($error !== '' || strlen($output) > 8192) { throw new RuntimeException('The source lock connection refused a command.'); }
            foreach (explode("\n", $output) as $line) {
                if (preg_match('/\A' . preg_quote($prefix, '/') . '([0-9]+)\z/D', $line, $match) === 1) {
                    if ($this->readLock['connectionId'] !== null && $this->readLock['connectionId'] !== $match[1]) {
                        throw new RuntimeException('The source lock connection changed.');
                    }
                    $this->readLock['connectionId'] = $match[1]; return;
                }
            }
            usleep(10000);
        }
    }
    private function readLockAlive(): void
    {
        if ($this->readLock === null || microtime(true) >= $this->readLock['deadline']
            || !proc_get_status($this->readLock['process'])['running']) { throw new RuntimeException('The source read lock expired or its connection ended.'); }
    }
    private function closeReadLock(): void
    {
        $lock = $this->readLock; $this->readLock = null;
        if ($lock === null) { return; }
        if (is_resource($lock['pipes'][0])) { fclose($lock['pipes'][0]); }
        $deadline = microtime(true) + 2;
        while (proc_get_status($lock['process'])['running'] && microtime(true) < $deadline) { usleep(10000); }
        if (proc_get_status($lock['process'])['running']) { proc_terminate($lock['process']); }
        foreach ($lock['pipes'] as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
        proc_close($lock['process']);
    }

    private function verifyDumpDefinitions($output, string $listing, array $definitions): void
    {
        $missing = [];
        foreach (self::listingRows($listing, 2) as [$hex, $type]) {
            $missing[($type === 'BASE TABLE' ? 'TABLE' : 'VIEW') . ':' . strtoupper($hex)] = true;
        }
        foreach ($definitions as $kind => $items) {
            foreach ($items as $item) {
                switch ($kind) {
                    case 'views': $type = 'VIEW'; break;
                    case 'triggers': $type = 'TRIGGER'; break;
                    case 'events': $type = 'EVENT'; break;
                    case 'routines': $type = $item['type']; break;
                    default: throw new RuntimeException('Unsupported database definition type.');
                }
                $missing[$type . ':' . strtoupper(bin2hex($item['name']))] = true;
            }
        }
        $identifier = '`((?:``|[^`])+)`';
        $definer = '`(?:``|[^`])*`@`(?:``|[^`])*`';
        $patterns = [
            'TABLE' => '~\nCREATE TABLE ' . $identifier . ' \\(~',
            'VIEW' => '~\n/\\*![0-9]{5,6} VIEW ' . $identifier . ' AS ~',
            'TRIGGER' => '~\n/\\*![0-9]{5,6} CREATE\\*/ /\\*![0-9]{5,6} DEFINER=' . $definer . '\\*/ /\\*![0-9]{5,6} TRIGGER ' . $identifier . ' ~',
            'EVENT' => '~\n/\\*![0-9]{5,6} CREATE\\*/ /\\*![0-9]{5,6} DEFINER=' . $definer . '\\*/ /\\*![0-9]{5,6} EVENT ' . $identifier . ' ~',
            'PROCEDURE' => '~\nCREATE DEFINER=' . $definer . ' PROCEDURE ' . $identifier . '\\(~',
            'FUNCTION' => '~\nCREATE DEFINER=' . $definer . ' FUNCTION ' . $identifier . '\\(~',
        ];
        rewind($output);
        $tail = "\n";
        while (!feof($output)) {
            $chunk = fread($output, 8192);
            if ($chunk === false) { throw new RuntimeException('Cannot verify database definitions.'); }
            $window = $tail . $chunk;
            foreach ($patterns as $type => $pattern) {
                preg_match_all($pattern, $window, $matches);
                foreach ($matches[1] as $name) {
                    unset($missing[$type . ':' . strtoupper(bin2hex(str_replace('``', '`', $name)))]);
                }
            }
            $tail = substr($window, -4096);
        }
        if ($missing !== []) { throw new RuntimeException('The database output does not contain every source definition.'); }
    }

    public function verifyV4Database(string $schema, iterable $tableRecords, array $environment): void
    {
        $this->schema($schema);
        if (trim($this->query($schema, 'SELECT @@GLOBAL.event_scheduler')) !== 'DISABLED') { throw new RuntimeException('Database verification requires the disabled event scheduler.'); }
        $fields = array_keys($environment); sort($fields);
        if ($fields !== ['characterSet', 'collation', 'serverVersion', 'sqlMode', 'timeZone']) { throw new RuntimeException('Invalid database verification environment.'); }
        foreach ($environment as $value) { if (!is_string($value) || strlen($value) > 16384 || strpos($value, "\0") !== false) { throw new RuntimeException('Invalid database verification environment.'); } }
        foreach (['characterSet', 'collation'] as $field) {
            if (!preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $environment[$field])) { throw new RuntimeException('Invalid database verification character set.'); }
        }
        $prefix = "SET SESSION time_zone='+00:00'; SET SESSION sql_mode=CONVERT(X'" . bin2hex($environment['sqlMode']) . "' USING utf8mb4); SET NAMES "
            . $environment['characterSet'] . ' COLLATE ' . $environment['collation'] . ";\n";
        $names = [];
        foreach ($tableRecords as $table) {
            if (!is_array($table)) { throw new RuntimeException('Invalid database verification table.'); }
            V4Format::validateRecord('database', $table);
            if (($table['type'] ?? '') !== 'table' || isset($names[$table['name']]) || count($names) >= 10000) { throw new RuntimeException('Invalid or repeated database verification table.'); }
            $names[$table['name']] = true;
            $identifier = '`' . $table['name'] . '`';
            $definition = $this->v4Query($schema, $prefix . 'SHOW CREATE TABLE ' . $identifier, $environment['characterSet']);
            $parts = explode("\t", $definition, 2);
            if (count($parts) !== 2 || $parts[0] !== $table['name'] || substr($parts[1], -1) !== "\n"
                || !hash_equals($table['definitionSha256'], WordPressDatabase::definitionSha256(substr($parts[1], 0, -1), $environment['sqlMode']))) { throw new RuntimeException('Restored database definition differs from the selected generation.'); }
            $input = tmpfile(); $output = tmpfile();
            if ($input === false || $output === false) { throw new RuntimeException('Cannot create database verification streams.'); }
            try {
                $sql = $prefix . WordPressDatabase::verificationQuery($table['name'], $table['columns'], $table['order']) . ";\n";
                if (fwrite($input, $sql) !== strlen($sql) || !rewind($input)) { throw new RuntimeException('Cannot prepare database verification.'); }
                $this->run($this->v4Command($schema, $environment['characterSet']), $input, $output);
                rewind($output); $hash = hash_init('sha256'); $count = 0;
                while (($line = fgets($output, 67)) !== false) {
                    if (!preg_match('/\A[a-f0-9]{64}\n\z/D', $line)) { throw new RuntimeException('Invalid restored database row digest.'); }
                    hash_update($hash, $line); $count++;
                }
                if (!feof($output) || $count !== $table['rows'] || !hash_equals($table['rowSha256'], hash_final($hash))) { throw new RuntimeException('Restored database values differ from the selected generation.'); }
            } finally { fclose($input); fclose($output); }
            foreach ($table['foreignKeys'] as $foreignKey) {
                $equal = []; $present = [];
                foreach ($foreignKey['columns'] as $position => $column) {
                    $equal[] = 'c.`' . $column . '`=p.`' . $foreignKey['referencedColumns'][$position] . '`';
                    $present[] = 'c.`' . $column . '` IS NOT NULL';
                }
                $sql = 'SELECT COUNT(*) FROM ' . $identifier . ' c LEFT JOIN `' . $foreignKey['referencedTable'] . '` p ON '
                    . implode(' AND ', $equal) . ' WHERE ' . implode(' AND ', $present) . ' AND p.`' . $foreignKey['referencedColumns'][0] . '` IS NULL';
                if (trim($this->v4Query($schema, $prefix . $sql, $environment['characterSet'])) !== '0') { throw new RuntimeException('Restored database contains orphaned foreign-key rows.'); }
            }
        }
        if ($names === [] || trim($this->query($schema, 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')) !== (string) count($names)) { throw new RuntimeException('Restored database table inventory differs from the selected generation.'); }
    }

    private function v4Command(string $schema, string $characterSet): array
    {
        return array_merge($this->connection($this->mysql), ['--batch', '--skip-column-names', '--raw', '--binary-mode=1', '--skip-reconnect', '--default-character-set=' . $characterSet, $schema]);
    }

    private function v4Query(string $schema, string $sql, string $characterSet): string
    {
        $input = tmpfile(); $output = tmpfile();
        if ($input === false || $output === false) { throw new RuntimeException('Cannot create private verification streams.'); }
        try {
            if (fwrite($input, $sql . ";\n") !== strlen($sql) + 2 || !rewind($input)) { throw new RuntimeException('Cannot prepare database verification.'); }
            $this->run($this->v4Command($schema, $characterSet), $input, $output);
            rewind($output); $contents = stream_get_contents($output, 16777217);
            if (!is_string($contents) || strlen($contents) > 16777216) { throw new RuntimeException('Database verification metadata exceeds its limit.'); }
            return $contents;
        } finally { fclose($input); fclose($output); }
    }

    private function query(string $schema, string $sql, ?array $connection = null): string
    {
        $input = tmpfile();
        $output = tmpfile();
        if ($input === false || $output === false) {
            throw new RuntimeException('Cannot create private query streams.');
        }
        try {
            fwrite($input, $sql);
            rewind($input);
            $this->run(array_merge($connection ?? $this->connection($this->mysql), [
                '--batch', '--skip-column-names', '--binary-mode=1', '--skip-reconnect',
            ], $schema === '' ? [] : [$schema]), $input, $output);
            rewind($output);
            $contents = stream_get_contents($output, 16 * 1024 * 1024 + 1);
            if ($contents === false || strlen($contents) > 16 * 1024 * 1024) {
                throw new RuntimeException('The database object listing exceeds its limit.');
            }
            return $contents;
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    private function connection(?string $binary = null, ?string $defaultsFile = null, ?string &$user = null): array
    {
        $workspace = $this->ownedFixtureWorkspace;
        if (realpath($workspace) !== $workspace || !is_dir($workspace)) {
            throw new RuntimeException('A canonical owned synthetic workspace is required.');
        }
        $this->noLinks($workspace);
        $this->privateOwned($workspace, 0040000, 0700);
        $marker = $workspace . '/.wrb-synthetic-mysql';
        $this->privateOwned($marker, 0100000, 0600);
        if (file_get_contents($marker) !== "wordpress-r2-backup synthetic mysql v1\n") {
            throw new RuntimeException('The owned synthetic database marker is invalid.');
        }
        $defaults = $defaultsFile ?? $this->privateDefaultsFile;
        if (!str_starts_with($defaults, $workspace . '/') || realpath($defaults) !== $defaults) {
            throw new RuntimeException('Connection settings must belong to the synthetic workspace.');
        }
        $this->noLinks($defaults);
        $this->privateOwned($defaults, 0100000, 0600);
        $contents = file_get_contents($defaults);
        if ($contents === false || strlen($contents) > 8192 || str_contains($contents, "\0")) {
            throw new RuntimeException('Connection settings are invalid.');
        }
        $values = [];
        $section = false;
        foreach (explode("\n", $contents) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) {
                continue;
            }
            if (!$section && $line === '[client]') {
                $section = true;
                continue;
            }
            if (!$section || preg_match('/\A(user|password|socket)\s*=\s*(.*)\z/D', $line, $match) !== 1 || array_key_exists($match[1], $values)) {
                throw new RuntimeException('Only generated client authentication and socket settings are allowed.');
            }
            $value = $match[2];
            if (str_starts_with($value, '"')) {
                if (preg_match('/\A"([^"\\\\\r\n]*)"\z/D', $value, $quoted) !== 1) {
                    throw new RuntimeException('A client setting is invalid.');
                }
                $value = $quoted[1];
            } elseif (preg_match('/\A[A-Za-z0-9_\/\.\-]*\z/D', $value) !== 1) {
                throw new RuntimeException('A client setting is invalid.');
            }
            $values[$match[1]] = $value;
        }
        $socket = $values['socket'] ?? '';
        $user = $values['user'] ?? null;
        if (!isset($values['user']) || $values['user'] === '' || !str_starts_with($socket, $workspace . '/') || realpath(dirname($socket)) . '/' . basename($socket) !== $socket) {
            throw new RuntimeException('An owned synthetic socket and explicit user are required.');
        }
        $this->noLinks($socket);
        $stat = @lstat($socket);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0140000 || $stat['uid'] !== $this->ownerId || $stat['nlink'] !== 1) {
            throw new RuntimeException('The owned synthetic socket is unavailable.');
        }
        if (@lstat($workspace . '/absent-login.cnf') !== false) {
            throw new RuntimeException('External MySQL login settings are not allowed.');
        }
        $command = [$binary ?? $this->mysql, '--defaults-file=' . $defaults, '--protocol=SOCKET', '--socket=' . $socket];
        if (($binary ?? $this->mysql) === $this->mysql) {
            $command[] = '--connect-timeout=2';
        }
        return $command;
    }

    private function run(array $command, $input, $output): void
    {
        $process = proc_open($command, [0 => $input ?? ['file', '/dev/null', 'r'], 1 => $output, 2 => ['pipe', 'w']], $pipes, $this->ownedFixtureWorkspace, ['MYSQL_TEST_LOGIN_FILE' => $this->ownedFixtureWorkspace . '/absent-login.cnf'], ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start the MySQL client.');
        }
        try {
            stream_set_blocking($pipes[2], false);
            $deadline = min(microtime(true) + $this->operationTimeoutSeconds, $this->readLock['deadline'] ?? PHP_FLOAT_MAX);
            do {
                if ($this->readLock !== null) { $this->readLockAlive(); }
                while (($chunk = fread($pipes[2], 8192)) !== false && $chunk !== '') {
                    if (microtime(true) > $deadline) {
                        throw new RuntimeException('The MySQL operation timed out.');
                    }
                }
                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
                }
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('The MySQL operation timed out.');
                }
                usleep(10000);
            } while (true);
            fclose($pipes[2]);
            $pipes = [];
            $exit = proc_close($process);
            $process = null;
            if (($status['exitcode'] >= 0 ? $status['exitcode'] : $exit) !== 0) {
                throw new RuntimeException('The MySQL operation failed; a fresh target is required after an import failure.');
            }
        } finally {
            if (is_resource($process)) {
                proc_terminate($process, 15);
                foreach ($pipes as $pipe) {
                    fclose($pipe);
                }
                proc_close($process);
            }
        }
    }

    private function schema(string $schema): void
    {
        if (preg_match('/\Awrb_test_[a-z0-9_]{1,55}\z/D', $schema) !== 1) {
            throw new RuntimeException('Only generated synthetic schemas are allowed.');
        }
    }

    private function binary(string $binary): string
    {
        $path = realpath($binary);
        if ($path === false || !is_file($path) || !is_executable($path)) {
            throw new RuntimeException('A fixed executable MySQL client is required.');
        }
        return $path;
    }

    private function noLinks(string $path): void
    {
        $current = '';
        foreach (explode('/', ltrim($path, '/')) as $component) {
            $current .= '/' . $component;
            clearstatcache(true, $current);
            if (@is_link($current)) {
                throw new RuntimeException('Symbolic links are not allowed in database paths.');
            }
        }
    }

    private function privateOwned(string $path, int $type, int $permissions): void
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false || ($stat['mode'] & 0170000) !== $type || ($stat['mode'] & 0777) !== $permissions || $stat['uid'] !== $this->ownerId
            || ($type === 0100000 && $stat['nlink'] !== 1)) {
            throw new RuntimeException('A private owner-only synthetic database path is required.');
        }
    }

    private function regularParent(string $path): string
    {
        if (str_contains($path, "\0") || in_array(basename($path), ['', '.', '..'], true)) {
            throw new RuntimeException('The SQL path is invalid.');
        }
        $parent = realpath(dirname($path));
        if ($parent === false || !is_dir($parent)) {
            throw new RuntimeException('The SQL directory is unavailable.');
        }
        $absolute = str_starts_with($path, '/') ? $path : getcwd() . '/' . $path;
        $this->noLinks($absolute);
        return $parent;
    }
}
