<?php
declare(strict_types=1);

namespace WordPressR2Backup;

use InvalidArgumentException;

final class V4Format
{
    public const ENCODING = 'wrb4-tar-raw1-sql1';
    public const MAX_CONTENT_BYTES = 1048576;
    public const MAX_PACK_BYTES = 33554432;
    public const MAX_PACK_MEMBERS = 512;
    public const MAX_CIPHER_BYTES = 35651584;
    public const MAX_PAGE_BYTES = 1048576;
    public const MAX_PAGE_RECORDS = 1024;
    public const MAX_RECORD_BYTES = 32768;
    public const MAX_PACK_RECORD_BYTES = 262144;
    public const MAX_ROOT_BYTES = 4194304;
    public const MAX_PAGES = 8192;
    public const MAX_ROOTS = 64;
    public const MAX_ENTRIES = 500000;
    public const MAX_OBJECTS = 100000;

    public static function contentId(int $size, string $sha256): string
    {
        self::integer($size, self::MAX_CONTENT_BYTES);
        self::hex($sha256);
        return hash('sha256', $size . ':' . $sha256);
    }

    public static function scopeId(array $scope): string
    {
        self::fields($scope, ['accountId', 'bucket', 'unitId', 'keySetId', 'encoding']);
        if (!is_string($scope['accountId']) || !preg_match('/^[a-f0-9]{32}$/D', $scope['accountId'])
            || !is_string($scope['bucket']) || !preg_match('/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/D', $scope['bucket'])
            || $scope['encoding'] !== self::ENCODING) {
            throw new InvalidArgumentException('Invalid v4 storage scope.');
        }
        self::hex($scope['unitId']); self::hex($scope['keySetId']);
        $ordered = [];
        foreach (['accountId', 'bucket', 'unitId', 'keySetId', 'encoding'] as $field) { $ordered[$field] = $scope[$field]; }
        return hash('sha256', json_encode($ordered, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public static function json(array $value): string
    {
        self::jsonValue($value, 0);
        try { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }
        catch (\JsonException $error) { throw new InvalidArgumentException('Invalid v4 JSON value.', 0, $error); }
    }

    public static function packKey(string $scopeId): string
    {
        self::hex($scopeId);
        return 'packs/' . $scopeId . '/' . bin2hex(random_bytes(32)) . '.age';
    }

    public static function pageKey(string $snapshotId): string
    {
        self::hex($snapshotId);
        return 'pages/' . $snapshotId . '/' . bin2hex(random_bytes(32)) . '.age';
    }

    public static function validateReceipt(array $receipt): void
    {
        self::fields($receipt, ['key', 'bytes', 'sha256']);
        if (!is_string($receipt['key']) || !preg_match('~^(?:(?:packs|pages)/[a-f0-9]{64}/[a-f0-9]{64}|manifests/[a-f0-9]{64})\.age$~D', $receipt['key'])) {
            throw new InvalidArgumentException('Invalid v4 ciphertext key.');
        }
        $maximum = str_starts_with($receipt['key'], 'packs/') ? self::MAX_CIPHER_BYTES
            : (str_starts_with($receipt['key'], 'pages/') ? self::MAX_PAGE_BYTES + 65536 : self::MAX_ROOT_BYTES + 65536);
        self::integer($receipt['bytes'], $maximum, 1); self::hex($receipt['sha256']);
    }

    public static function validateContent(array $ref): void
    {
        self::fields($ref, ['id', 'size', 'sha256', 'pack']);
        self::member(['id' => $ref['id'], 'size' => $ref['size'], 'sha256' => $ref['sha256']]);
        if (!is_string($ref['pack']) || !preg_match('~^packs/[a-f0-9]{64}/[a-f0-9]{64}\.age$~D', $ref['pack'])) {
            throw new InvalidArgumentException('Invalid v4 content pack.');
        }
    }

    public static function validatePack(array $descriptor): void
    {
        self::fields($descriptor, ['object', 'lane', 'members']);
        self::arrayValue($descriptor['object']); self::validateReceipt($descriptor['object']);
        if (!str_starts_with($descriptor['object']['key'], 'packs/') || !is_string($descriptor['lane'])
            || !preg_match('/^(?:code|media|authentication|forms|other|database:[A-Za-z0-9_][A-Za-z0-9_.-]{0,255})$/D', $descriptor['lane'])) {
            throw new InvalidArgumentException('Invalid v4 pack classification or key.');
        }
        self::listValue($descriptor['members'], self::MAX_PACK_MEMBERS, 1);
        $seen = []; $bytes = 0;
        foreach ($descriptor['members'] as $member) {
            self::arrayValue($member); self::member($member);
            if (isset($seen[$member['id']])) { throw new InvalidArgumentException('Duplicate v4 pack member.'); }
            $seen[$member['id']] = true; $bytes += $member['size'];
        }
        self::integer($bytes, self::MAX_PACK_BYTES);
    }

    public static function validatePageDescriptor(array $descriptor): void
    {
        self::fields($descriptor, ['kind', 'sequence', 'count', 'size', 'sha256', 'object']);
        self::kind($descriptor['kind']); self::integer($descriptor['sequence'], self::MAX_PAGES - 1);
        self::integer($descriptor['count'], self::MAX_PAGE_RECORDS, 1);
        self::integer($descriptor['size'], self::MAX_PAGE_BYTES, 1); self::hex($descriptor['sha256']);
        self::arrayValue($descriptor['object']); self::validateReceipt($descriptor['object']);
        if (!str_starts_with($descriptor['object']['key'], 'pages/')) { throw new InvalidArgumentException('Invalid v4 page object.'); }
    }

    public static function validatePage(array $page, ?array $descriptor = null): void
    {
        self::fields($page, ['version', 'snapshotId', 'kind', 'sequence', 'records']);
        if ($page['version'] !== 4) { throw new InvalidArgumentException('Unsupported v4 page version.'); }
        self::hex($page['snapshotId']); self::kind($page['kind']);
        self::integer($page['sequence'], self::MAX_PAGES - 1);
        self::listValue($page['records'], self::MAX_PAGE_RECORDS, 1);
        foreach ($page['records'] as $record) { self::arrayValue($record); self::validateRecord($page['kind'], $record); }
        $json = self::json($page);
        if (strlen($json) > self::MAX_PAGE_BYTES) { throw new InvalidArgumentException('V4 page exceeds its size limit.'); }
        if ($descriptor !== null) {
            self::validatePageDescriptor($descriptor);
            if ($descriptor['kind'] !== $page['kind'] || $descriptor['sequence'] !== $page['sequence']
                || $descriptor['count'] !== count($page['records']) || $descriptor['size'] !== strlen($json)
                || !hash_equals($descriptor['sha256'], hash('sha256', $json))
                || !str_starts_with($descriptor['object']['key'], 'pages/' . $page['snapshotId'] . '/')) {
                throw new InvalidArgumentException('V4 page differs from its descriptor.');
            }
        }
    }

    public static function validateRecord(string $kind, array $record): void
    {
        self::kind($kind);
        if (strlen(self::json($record)) > ($kind === 'packs' ? self::MAX_PACK_RECORD_BYTES : self::MAX_RECORD_BYTES)) { throw new InvalidArgumentException('V4 record exceeds its size limit.'); }
        if ($kind === 'packs') { self::validatePack($record); return; }
        $type = $record['type'] ?? null;
        if ($kind === 'filesystem') {
            if ($type === 'part') {
                self::fields($record, ['type', 'content']); self::arrayValue($record['content']); self::validateContent($record['content']); return;
            }
            if ($type === 'end') { self::fields($record, ['type', 'sha256']); self::hex($record['sha256']); return; }
            $fields = ['type', 'rootId', 'path'];
            if ($type === 'directory') { $fields[] = 'mode'; }
            elseif ($type === 'file') { $fields[] = 'size'; $fields[] = 'mode'; self::integer($record['size'] ?? null, Manifest::MAX_RESTORED_BYTES); }
            elseif ($type === 'symlink') { $fields[] = 'target'; }
            else { throw new InvalidArgumentException('Unsupported v4 filesystem record.'); }
            self::fields($record, $fields); self::rootId($record['rootId']);
            if (!is_string($record['path'])) { throw new InvalidArgumentException('Invalid v4 filesystem path.'); }
            FileSafety::validatePaths([$record['path']]);
            if ($type === 'symlink') { self::linkTarget($record['path'], $record['target']); }
            else { self::integer($record['mode'], 0777); }
            return;
        }
        if ($type === 'sql') {
            self::fields($record, ['type', 'stage', 'name', 'content']);
            if (!in_array($record['stage'], ['preamble', 'definition', 'data', 'postamble'], true)) { throw new InvalidArgumentException('Invalid v4 SQL stage.'); }
            self::text($record['name'], 256); self::arrayValue($record['content']); self::validateContent($record['content']);
        } elseif ($type === 'table') {
            self::fields($record, ['type', 'name', 'rows', 'sourceRows', 'excludedRows', 'definitionSha256', 'rowSha256', 'columns', 'order', 'foreignKeys']);
            self::sqlIdentifier($record['name']);
            foreach (['rows', 'sourceRows', 'excludedRows'] as $field) { self::integer($record[$field], Manifest::MAX_RESTORED_BYTES); }
            if ($record['rows'] > $record['sourceRows'] || $record['excludedRows'] !== $record['sourceRows'] - $record['rows']) {
                throw new InvalidArgumentException('V4 table row counts differ.');
            }
            self::hex($record['definitionSha256']); self::hex($record['rowSha256']);
            $columns = self::identifiers($record['columns']); $order = self::identifiers($record['order']);
            if (array_diff_key($order, $columns) !== []) { throw new InvalidArgumentException('V4 table order names an unknown column.'); }
            self::listValue($record['foreignKeys'], 4096);
            foreach ($record['foreignKeys'] as $foreignKey) {
                self::arrayValue($foreignKey); self::fields($foreignKey, ['columns', 'referencedTable', 'referencedColumns']);
                $local = self::identifiers($foreignKey['columns']); $referenced = self::identifiers($foreignKey['referencedColumns']);
                self::sqlIdentifier($foreignKey['referencedTable']);
                if (count($local) !== count($referenced) || array_diff_key($local, $columns) !== []) { throw new InvalidArgumentException('Invalid v4 foreign key columns.'); }
            }
        } else { throw new InvalidArgumentException('Unsupported v4 database record.'); }
    }

    public static function validateRoot(array $root): void
    {
        self::fields($root, ['format', 'version', 'dataClass', 'unitId', 'snapshotId', 'keySetId', 'recipients', 'capturedAt', 'encoding', 'scope', 'roots', 'pages', 'counts', 'database', 'wordpress']);
        if (strlen(self::json($root)) > self::MAX_ROOT_BYTES || $root['format'] !== 'wordpress-r2-backup'
            || $root['version'] !== 4 || $root['dataClass'] !== 'wordpress' || $root['encoding'] !== self::ENCODING) {
            throw new InvalidArgumentException('Unsupported v4 root or root size.');
        }
        foreach (['unitId', 'snapshotId', 'keySetId'] as $field) { self::hex($root[$field]); }
        self::arrayValue($root['recipients']);
        if (Manifest::keySetId($root['recipients']) !== $root['keySetId']) { throw new InvalidArgumentException('V4 recipient set differs.'); }
        self::timestamp($root['capturedAt']); self::arrayValue($root['scope']); self::scopeId($root['scope']);
        if ($root['scope']['unitId'] !== $root['unitId'] || $root['scope']['keySetId'] !== $root['keySetId']) {
            throw new InvalidArgumentException('V4 root differs from its scope.');
        }
        self::listValue($root['roots'], self::MAX_ROOTS, 1); $seen = [];
        foreach ($root['roots'] as $entry) {
            self::arrayValue($entry); self::fields($entry, ['id', 'name', 'sourcePath', 'type', 'mode']);
            self::rootId($entry['id']); self::rootId($entry['name']);
            if (isset($seen['id:' . $entry['id']]) || isset($seen['name:' . $entry['name']]) || !in_array($entry['type'], ['directory', 'file'], true)) {
                throw new InvalidArgumentException('Invalid or repeated v4 root.');
            }
            $seen['id:' . $entry['id']] = true; $seen['name:' . $entry['name']] = true;
            self::integer($entry['mode'], 0777); self::sourcePath($entry['sourcePath']);
        }
        self::listValue($root['pages'], self::MAX_PAGES); $keys = []; $sequences = ['packs' => 0, 'filesystem' => 0, 'database' => 0]; $packRecords = 0;
        foreach ($root['pages'] as $descriptor) {
            self::arrayValue($descriptor); self::validatePageDescriptor($descriptor); $key = $descriptor['object']['key'];
            if (isset($keys[$key]) || $descriptor['sequence'] !== $sequences[$descriptor['kind']]++
                || !str_starts_with($key, 'pages/' . $root['snapshotId'] . '/')) {
                throw new InvalidArgumentException('Invalid v4 page sequence or snapshot binding.');
            }
            $keys[$key] = true;
            if ($descriptor['kind'] === 'packs') { $packRecords += $descriptor['count']; }
        }
        self::arrayValue($root['counts']); self::fields($root['counts'], ['files', 'directories', 'links', 'contents', 'packs', 'databaseBytes', 'fileBytes']);
        foreach (['files', 'directories', 'links'] as $field) { self::integer($root['counts'][$field], self::MAX_ENTRIES); }
        if ($root['counts']['files'] + $root['counts']['directories'] + $root['counts']['links'] > self::MAX_ENTRIES) { throw new InvalidArgumentException('V4 filesystem entry limit exceeded.'); }
        self::integer($root['counts']['contents'], self::MAX_PAGES * self::MAX_PAGE_RECORDS);
        self::integer($root['counts']['packs'], self::MAX_OBJECTS);
        if ($root['counts']['packs'] !== $packRecords || $root['counts']['packs'] + count($root['pages']) > self::MAX_OBJECTS) { throw new InvalidArgumentException('V4 ciphertext count differs or exceeds its limit.'); }
        foreach (['databaseBytes', 'fileBytes'] as $field) { self::integer($root['counts'][$field], Manifest::MAX_RESTORED_BYTES); }
        if ($root['counts']['databaseBytes'] + $root['counts']['fileBytes'] > Manifest::MAX_RESTORED_BYTES) { throw new InvalidArgumentException('V4 restored size limit exceeded.'); }
        self::arrayValue($root['database']); self::fields($root['database'], ['size', 'sha256', 'tableCount', 'events', 'environment']);
        self::integer($root['database']['size'], Manifest::MAX_RESTORED_BYTES); self::hex($root['database']['sha256']);
        if ($root['database']['size'] !== $root['counts']['databaseBytes']) { throw new InvalidArgumentException('V4 database size differs.'); }
        self::integer($root['database']['tableCount'], self::MAX_PAGES * self::MAX_PAGE_RECORDS);
        self::listValue($root['database']['events'], 10000); $events = [];
        foreach ($root['database']['events'] as $event) { self::text($event, 256); if (isset($events[$event])) { throw new InvalidArgumentException('Duplicate v4 database event.'); } $events[$event] = true; }
        self::arrayValue($root['database']['environment']);
        self::fields($root['database']['environment'], ['serverVersion', 'sqlMode', 'timeZone', 'characterSet', 'collation']);
        foreach ($root['database']['environment'] as $field => $value) { self::text($value, 4096, $field === 'sqlMode'); }
        self::arrayValue($root['wordpress']);
        $online = array_key_exists('consistencyMode', $root['wordpress']);
        self::fields($root['wordpress'], $online ? ['siteUrl', 'policySha256', 'policy', 'evidence', 'consistencyMode'] : ['siteUrl', 'policySha256', 'policy', 'evidence']);
        self::text($root['wordpress']['siteUrl'], 2048);
        if (!filter_var($root['wordpress']['siteUrl'], FILTER_VALIDATE_URL)) { throw new InvalidArgumentException('Invalid v4 site URL.'); }
        self::hex($root['wordpress']['policySha256']); self::arrayValue($root['wordpress']['policy']); self::arrayValue($root['wordpress']['evidence']);
        if (!hash_equals($root['wordpress']['policySha256'], hash('sha256', self::json($root['wordpress']['policy'])))) { throw new InvalidArgumentException('V4 policy digest differs.'); }
        if ($online) {
            if ($root['wordpress']['consistencyMode'] !== 'online') { throw new InvalidArgumentException('Unsupported v4 consistency mode.'); }
            $evidence = $root['wordpress']['evidence'];
            self::fields($evidence, ['startedAt', 'snapshotAt', 'databaseVerifiedAt', 'filesVerifiedAt', 'finishedAt', 'scan', 'tables']);
            self::arrayValue($evidence['scan']); self::listValue($evidence['tables'], self::MAX_PAGES * self::MAX_PAGE_RECORDS);
            $previous = '';
            foreach (['startedAt', 'snapshotAt', 'databaseVerifiedAt', 'filesVerifiedAt', 'finishedAt'] as $field) {
                $value = $evidence[$field]; self::timestamp($value);
                if (!preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $value) || ($previous !== '' && strcmp($value, $previous) < 0)) {
                    throw new InvalidArgumentException('Invalid online capture evidence order.');
                }
                $previous = $value;
            }
            if ($root['capturedAt'] !== $evidence['snapshotAt']) { throw new InvalidArgumentException('V4 online snapshot time differs.'); }
        }
    }

    private static function member(array $member): void
    {
        self::fields($member, ['id', 'size', 'sha256']); self::hex($member['id']);
        self::integer($member['size'], self::MAX_CONTENT_BYTES); self::hex($member['sha256']);
        if (!hash_equals($member['id'], self::contentId($member['size'], $member['sha256']))) { throw new InvalidArgumentException('V4 content identifier differs.'); }
    }

    private static function linkTarget(string $path, $target): void
    {
        self::text($target, 4096);
        if ($target[0] === '/' || preg_match('/[\\\\:]/', $target)) { throw new InvalidArgumentException('Unsafe v4 link target.'); }
        $parts = explode('/', $path); array_pop($parts);
        foreach (explode('/', $target) as $part) {
            if (strlen($part) > 255) { throw new InvalidArgumentException('Unsafe v4 link component.'); }
            if ($part === '' || $part === '.') { continue; }
            if ($part === '..') { if ($parts === []) { throw new InvalidArgumentException('V4 link escapes its root.'); } array_pop($parts); }
            else { $parts[] = $part; }
        }
    }

    private static function sourcePath($path): void
    {
        self::text($path, 4096);
        if ($path[0] !== '/' || str_contains($path, '\\')) { throw new InvalidArgumentException('Invalid v4 source path.'); }
        if ($path === '/') { return; }
        foreach (explode('/', substr($path, 1)) as $part) {
            if ($part === '' || $part === '.' || $part === '..' || strlen($part) > 255) { throw new InvalidArgumentException('Noncanonical v4 source path.'); }
        }
    }

    private static function rootId($value): void
    {
        if (!is_string($value) || !preg_match('/^r[0-9]{4}$/D', $value) || $value === 'r0000') { throw new InvalidArgumentException('Invalid v4 root identifier.'); }
    }

    private static function fields(array $value, array $expected): void
    {
        $actual = array_keys($value); sort($actual); sort($expected);
        if ($actual !== $expected) { throw new InvalidArgumentException('Unexpected or missing v4 fields.'); }
    }

    private static function integer($value, int $maximum, int $minimum = 0): void
    {
        if (!is_int($value) || $value < $minimum || $value > $maximum) { throw new InvalidArgumentException('Invalid v4 integer or limit exceeded.'); }
    }

    private static function hex($value): void
    {
        if (!is_string($value) || !preg_match('/^[a-f0-9]{64}$/D', $value)) { throw new InvalidArgumentException('Invalid v4 256-bit identifier.'); }
    }

    private static function kind($value): void
    {
        if (!in_array($value, ['packs', 'filesystem', 'database'], true)) { throw new InvalidArgumentException('Unsupported v4 page kind.'); }
    }

    private static function text($value, int $maximum, bool $empty = false): void
    {
        if (!is_string($value) || (!$empty && $value === '') || strlen($value) > $maximum || !mb_check_encoding($value, 'UTF-8') || preg_match('/\p{Cc}/u', $value)) {
            throw new InvalidArgumentException('Invalid v4 text.');
        }
    }

    private static function arrayValue($value): void
    {
        if (!is_array($value)) { throw new InvalidArgumentException('Expected a v4 object.'); }
    }

    private static function listValue($value, int $maximum, int $minimum = 0): void
    {
        if (!is_array($value) || !array_is_list($value) || count($value) < $minimum || count($value) > $maximum) { throw new InvalidArgumentException('Invalid v4 list or limit exceeded.'); }
    }

    private static function timestamp($value): void
    {
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d+)?(?:Z|[+-](\d{2}):(\d{2}))$/D', $value, $parts)
            || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) || (int) $parts[4] > 23 || (int) $parts[5] > 59 || (int) $parts[6] > 59
            || (isset($parts[7]) && (int) $parts[7] > 23) || (isset($parts[8]) && (int) $parts[8] > 59)) { throw new InvalidArgumentException('Invalid v4 capture timestamp.'); }
    }

    private static function jsonValue($value, int $depth): void
    {
        if ($depth > 512) { throw new InvalidArgumentException('V4 JSON nesting exceeds its limit.'); }
        if (is_array($value)) { foreach ($value as $child) { self::jsonValue($child, $depth + 1); } }
        elseif ($value !== null && !is_scalar($value)) { throw new InvalidArgumentException('Unsupported v4 JSON value.'); }
    }

    private static function sqlIdentifier($value): void
    {
        if (!is_string($value) || !preg_match('/^[A-Za-z0-9_]{1,64}$/D', $value)) { throw new InvalidArgumentException('Invalid v4 SQL identifier.'); }
    }

    private static function identifiers($values): array
    {
        self::listValue($values, 4096, 1); $seen = [];
        foreach ($values as $value) {
            self::sqlIdentifier($value); $folded = strtolower($value);
            if (isset($seen[$folded])) { throw new InvalidArgumentException('Repeated v4 SQL identifier.'); }
            $seen[$folded] = true;
        }
        return $seen;
    }
}
