<?php
declare(strict_types=1);

namespace WordPressR2Backup;

use PDO;
use RuntimeException;

/** URL and administrator changes applied only to a separate running database, for both database modes. */
final class SiteUrlRewriter
{
    private array $lastReplacements = [];
    private string $lastPattern = '';

    public static function assertUrl(string $url, bool $loopback): void
    {
        $parts = parse_url($url);
        if (strlen($url) > 4096 || filter_var($url, FILTER_VALIDATE_URL) === false || !is_array($parts) || !isset($parts['scheme'], $parts['host']) || !in_array($parts['scheme'], ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || preg_match('/[\x00-\x20\x7f]/', $url)
            || ($loopback && !in_array($parts['host'], ['localhost', '127.0.0.1'], true))) { throw new RuntimeException('The running site requires an explicit loopback URL.'); }
    }

    public static function assertAdministrator(array $admin): void
    {
        if ($admin === []) { return; }
        if (array_keys($admin) !== ['login', 'passwordHash'] || !is_string($admin['login']) || !preg_match('/\A[A-Za-z0-9_.-]{1,60}\z/D', $admin['login'])
            || !is_string($admin['passwordHash']) || !preg_match('/\A\$P\$[.\/0-9A-Za-z]{31}\z/D', $admin['passwordHash'])) { throw new RuntimeException('Supply a local administrator login and a WordPress portable password hash.'); }
    }

    /** @return array<string,string> source spelling => replacement, plain and JSON-escaped */
    public static function replacements(string $sourceUrl, string $storedUrl, array $aliases): array
    {
        if (count($aliases) > 8 || ($aliases !== [] && array_keys($aliases) !== range(0, count($aliases) - 1))) { throw new RuntimeException('Supply at most eight source URL aliases in a list.'); }
        $seen = []; $replacements = []; $storedIdentity = self::urlIdentity($storedUrl);
        foreach (array_merge([$sourceUrl], $aliases) as $url) {
            if (!is_string($url)) { throw new RuntimeException('Source URL aliases must be URL strings.'); }
            self::assertUrl($url, false); $url = rtrim($url, '/'); $identity = self::urlIdentity($url);
            if (isset($seen[$identity])) { throw new RuntimeException('Source URL aliases must be distinct.'); }
            if (strpos($identity, $storedIdentity) !== false || strpos($storedIdentity, $identity) !== false) { throw new RuntimeException('The source URL aliases cannot include or contain the local destination.'); }
            $seen[$identity] = true; $replacements[$url] = $storedUrl;
            $replacements[str_replace('/', '\\/', $url)] = str_replace('/', '\\/', $storedUrl);
        }
        return $replacements;
    }

    /**
     * Rewrites every table. Rows are read through $reader and the changes are applied after that table's cursor closes, so an
     * unbuffered server connection and a table-locking engine cannot block each other. Pass the same connection twice when it allows that.
     */
    public function rewrite($reader, $writer, array $replacements): int
    {
        $tables = $writer->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN); $changed = 0;
        if (count($tables) > 10000) { throw new RuntimeException('The local table inventory exceeds its limit.'); }
        $writer->beginTransaction();
        try {
            foreach ($tables as $table) {
                $id = $this->identifier($table); $primary = [];
                foreach ($writer->query('SHOW COLUMNS FROM ' . $id)->fetchAll(PDO::FETCH_ASSOC) as $column) { if ($column['Key'] === 'PRI') { $primary[] = $column['Field']; } }
                $pending = fopen('php://temp/maxmemory:33554432', 'w+b');
                if ($pending === false) { throw new RuntimeException('Cannot hold the local URL changes.'); }
                try {
                    $cursor = $reader->query('SELECT * FROM ' . $id);
                    try {
                        while (($row = $cursor->fetch(PDO::FETCH_ASSOC)) !== false) {
                            $updates = []; $values = [];
                            foreach ($row as $column => $value) {
                                if (!is_string($value) || !$this->mentionsSource($value, $replacements)) { continue; }
                                $replacement = $this->replaceValue($value, $replacements, 0);
                                if ($replacement === $value) { continue; }
                                if ($primary === [] || in_array($column, $primary, true)) { throw new RuntimeException('A URL change requires an unchanged primary key.'); }
                                $updates[] = $this->identifier($column) . '=?'; $values[] = $replacement; ++$changed;
                            }
                            if ($updates === []) { continue; }
                            $where = [];
                            foreach ($primary as $column) { $where[] = $this->identifier($column) . '=?'; $values[] = $row[$column]; }
                            $statement = $this->bind($writer, 'UPDATE ' . $id . ' SET ' . implode(',', $updates) . ' WHERE ' . implode(' AND ', $where), $values);
                            if (fwrite($pending, pack('N', strlen($statement)) . $statement) !== strlen($statement) + 4) { throw new RuntimeException('Cannot hold the local URL changes.'); }
                        }
                    } finally { $cursor->closeCursor(); }
                    rewind($pending);
                    while (($header = fread($pending, 4)) !== '' && $header !== false) {
                        $writer->query($this->readExact($pending, unpack('N', $header)[1]));
                    }
                } finally { fclose($pending); }
            }
            $writer->commit();
        } catch (\Throwable $error) { if ($writer->inTransaction()) { $writer->rollBack(); } throw new RuntimeException('Cannot safely rewrite the local site URLs.', 0, $error); }
        return $changed;
    }

    public function setOption($db, string $table, string $name, string $value): void
    {
        $statement = $db->query($this->bind($db, 'SELECT COUNT(*) FROM ' . $this->identifier($table) . ' WHERE option_name=?', [$name]));
        if ((int) $statement->fetchColumn() !== 1) { throw new RuntimeException('The restored site must contain one home and siteurl option.'); }
        $statement->closeCursor();
        $db->query($this->bind($db, 'UPDATE ' . $this->identifier($table) . ' SET option_value=? WHERE option_name=?', [$value, $name]));
    }

    public function createAdministrator($db, string $prefix, array $admin): void
    {
        $users = $this->identifier($prefix . 'users'); $meta = $this->identifier($prefix . 'usermeta');
        $statement = $db->query($this->bind($db, 'SELECT COUNT(*) FROM ' . $users . ' WHERE user_login=?', [$admin['login']]));
        if ((int) $statement->fetchColumn() !== 0) { throw new RuntimeException('The local administrator login already exists.'); }
        $statement->closeCursor();
        $db->beginTransaction();
        try {
            $db->query($this->bind($db, 'INSERT INTO ' . $users . ' (user_login,user_pass,user_nicename,user_email,user_url,user_registered,user_activation_key,user_status,display_name) VALUES (?,?,?,?,?,?,?,?,?)', [$admin['login'], $admin['passwordHash'], $admin['login'], 'restore-check@example.invalid', '', gmdate('Y-m-d H:i:s'), '', 0, 'Local restore check']));
            $id = $db->lastInsertId();
            $db->query($this->bind($db, 'INSERT INTO ' . $meta . ' (user_id,meta_key,meta_value) VALUES (?,?,?)', [$id, $prefix . 'capabilities', serialize(['administrator' => true])]));
            $db->query($this->bind($db, 'INSERT INTO ' . $meta . ' (user_id,meta_key,meta_value) VALUES (?,?,?)', [$id, $prefix . 'user_level', '10']));
            $db->commit();
        } catch (\Throwable $error) { if ($db->inTransaction()) { $db->rollBack(); } throw new RuntimeException('Cannot create the dedicated local administrator.', 0, $error); }
    }

    private function replaceValue(string $value, array $replacements, int $depth): string
    {
        if ($depth > 32) { throw new RuntimeException('A local URL value exceeds its nesting limit.'); }
        if (preg_match('/\A(?:a:\d+:\{|s:\d+:"|O:\d+:"|C:\d+:")/', $value)) {
            $decoded = @unserialize($value, ['allowed_classes' => false, 'max_depth' => 64]);
            if ($decoded === false) { throw new RuntimeException('Cannot safely replace an invalid serialized value.'); }
            return serialize($this->replaceDecoded($decoded, $replacements, $depth + 1));
        }
        return $this->substitute($value, $replacements);
    }

    /**
     * A source URL is replaced only where it ends at an origin or path boundary: `https://example.com` must not rewrite
     * `https://example.community`, `https://example.com.au`, `https://example.com:8443` or `https://example.com@other`.
     * The longest source URL wins, and the plain and JSON-escaped spellings are both handled.
     */
    private function substitute(string $value, array $replacements): string
    {
        if (strpos($value, '://') === false && strpos($value, ':\\/\\/') === false) { return $value; }
        $result = preg_replace_callback($this->urlPattern($replacements), static function (array $match) use ($replacements): string { return $replacements[$match[0]]; }, $value);
        if (!is_string($result)) { throw new RuntimeException('Cannot safely replace a local URL in a large value.'); }
        return $result;
    }

    private function mentionsSource(string $value, array $replacements): bool
    {
        if (strpos($value, '://') === false && strpos($value, ':\\/\\/') === false) { return false; }
        $found = preg_match($this->urlPattern($replacements), $value);
        if ($found === false) { throw new RuntimeException('Cannot safely inspect a local URL in a large value.'); }
        return $found === 1;
    }

    private function urlPattern(array $replacements): string
    {
        if ($this->lastPattern !== '' && $this->lastReplacements === $replacements) { return $this->lastPattern; }
        $sources = array_map('strval', array_keys($replacements));
        usort($sources, static function (string $a, string $b): int { return strlen($b) <=> strlen($a) ?: strcmp($a, $b); });
        $this->lastPattern = '#(?:' . implode('|', array_map(static function (string $source): string { return preg_quote($source, '#'); }, $sources)) . ')(?![A-Za-z0-9_~%@-]|[.:](?=[A-Za-z0-9]))#';
        $this->lastReplacements = $replacements;
        return $this->lastPattern;
    }

    private function replaceDecoded($value, array $replacements, int $depth)
    {
        if ($depth > 32 || is_object($value) || is_resource($value)) { throw new RuntimeException('Cannot safely replace this serialized value.'); }
        if (is_string($value)) { return $this->replaceValue($value, $replacements, $depth + 1); }
        if (!is_array($value)) { return $value; }
        $result = [];
        foreach ($value as $key => $entry) {
            $newKey = is_string($key) ? $this->replaceValue($key, $replacements, $depth + 1) : $key;
            if (array_key_exists($newKey, $result)) { throw new RuntimeException('URL replacement would collide with another array key.'); }
            $result[$newKey] = $this->replaceDecoded($entry, $replacements, $depth + 1);
        }
        return $result;
    }

    private function identifier(string $name): string
    {
        if ($name === '' || strlen($name) > 256 || strpos($name, "\0") !== false || !mb_check_encoding($name, 'UTF-8')) { throw new RuntimeException('Invalid local database identifier.'); }
        return '`' . str_replace('`', '``', $name) . '`';
    }

    private function bind($db, string $sql, array $values): string
    {
        $parts = explode('?', $sql);
        if (count($parts) !== count($values) + 1) { throw new RuntimeException('Invalid local startup query.'); }
        $query = array_shift($parts);
        foreach ($values as $index => $value) {
            $literal = $value === null ? 'NULL' : $db->quote((string) $value);
            if (!is_string($literal)) { throw new RuntimeException('Cannot quote a local startup value.'); }
            $query .= $literal . $parts[$index];
        }
        return $query;
    }

    private function readExact($stream, int $bytes): string
    {
        $read = '';
        while (strlen($read) < $bytes) {
            $chunk = fread($stream, $bytes - strlen($read));
            if ($chunk === false || $chunk === '') { throw new RuntimeException('Cannot read the held local URL changes.'); }
            $read .= $chunk;
        }
        return $read;
    }

    private static function urlIdentity(string $url): string
    {
        $parts = parse_url($url);
        return $parts['scheme'] . '://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '') . rtrim($parts['path'] ?? '', '/');
    }
}
