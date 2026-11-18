<?php
declare(strict_types=1);

namespace WordPressR2Backup;

use Generator;
use RuntimeException;

final class SourceRoots
{
    private const MAX_ROOTS = 64;
    private const MAX_ENTRIES = 500000;
    private array $roots = [];
    private array $identities = [];
    private string $maintenancePath;
    private array $policyRoots = [];

    public function __construct(string $wordpressRoot, array $additionalRoots = [], array $privatePaths = [])
    {
        $wordpressRoot = self::existingPath($wordpressRoot);
        $wordpressStat = self::sourceStat($wordpressRoot);
        if ($wordpressStat['type'] !== 0040000) { throw self::failure($wordpressRoot, 'The WordPress root must be a directory.'); }
        $this->maintenancePath = self::join($wordpressRoot, '.maintenance');
        $selected = [];
        foreach (array_merge([$wordpressRoot], $additionalRoots) as $path) {
            if (!is_string($path)) { throw new RuntimeException('A source root must be an absolute filesystem path.'); }
            $path = self::existingPath($path);
            $stat = self::sourceStat($path);
            if (!in_array($stat['type'], [0040000, 0100000], true)) { throw self::failure($path, 'A source root must be a regular file or directory.'); }
            $covered = false;
            foreach ($selected as $existing => $prior) {
                if ($existing === $path || ($prior['type'] === 0040000 && self::contains($existing, $path))) { $covered = true; break; }
            }
            if ($covered) { continue; }
            if ($stat['type'] === 0040000) {
                foreach ($selected as $existing => $prior) {
                    if (self::contains($path, $existing)) { unset($selected[$existing]); }
                }
            }
            $selected[$path] = $stat;
        }
        if (count($selected) > self::MAX_ROOTS) { throw new RuntimeException('The source contains more than 64 roots.'); }
        foreach ($selected as $path => $stat) {
            $id = sprintf('r%04d', count($this->roots) + 1);
            $this->roots[] = ['id' => $id, 'name' => $id, 'sourcePath' => $path,
                'type' => $stat['type'] === 0040000 ? 'directory' : 'file', 'mode' => $stat['mode'] & 0777];
            $this->identities[$id] = ['dev' => $stat['dev'], 'ino' => $stat['ino'], 'mode' => $stat['mode']];
        }
        $this->verifyPrivateLocations($privatePaths);
    }

    public static function fromWordPress(string $root, array $privatePaths = []): self
    {
        $root = self::existingPath($root);
        $additional = []; $policyRoots = [$root => ''];
        if (defined('WP_CONTENT_DIR')) {
            $content = self::projectedPath((string) constant('WP_CONTENT_DIR'));
            $policyRoots[$content] = 'wp-content';
            if (!self::contains($root, $content)) { $additional[] = constant('WP_CONTENT_DIR'); }
        }
        if (function_exists('wp_upload_dir')) {
            $uploads = wp_upload_dir(null, false);
            if (!is_array($uploads) || !isset($uploads['basedir']) || !is_string($uploads['basedir']) || !empty($uploads['error'])) {
                throw new RuntimeException('The WordPress upload directory is unavailable.');
            }
            $media = self::projectedPath($uploads['basedir']);
            $policyRoots[$media] = 'wp-content/uploads';
            if (!self::contains($root, $media)) { $additional[] = $uploads['basedir']; }
        }
        $parent = dirname($root);
        if (!file_exists(self::join($root, 'wp-config.php')) && file_exists(self::join($parent, 'wp-config.php'))
            && !file_exists(self::join($parent, 'wp-settings.php'))) {
            $additional[] = self::join($parent, 'wp-config.php');
        }
        $roots = new self($root, $additional, $privatePaths);
        $roots->policyRoots = $policyRoots;
        uksort($roots->policyRoots, static function (string $a, string $b): int { return strlen($b) <=> strlen($a); });
        return $roots;
    }

    public function policyPath(array $entry): string
    {
        foreach ($this->policyRoots as $physical => $logical) {
            if (self::contains($physical, $entry['absolute'])) {
                $relative = ltrim(substr($entry['absolute'], strlen($physical)), '/');
                return $logical === '' ? $relative : $logical . ($relative === '' ? '' : '/' . $relative);
            }
        }
        return $entry['path'];
    }

    public function descriptors(): array
    {
        return $this->roots;
    }

    public function verifyPrivateLocations(array $paths): void
    {
        foreach ($paths as $path) {
            if (!is_string($path)) { throw new RuntimeException('A private location must be an absolute filesystem path.'); }
            $path = self::projectedPath($path);
            foreach ($this->roots as $root) {
                if (self::contains($root['sourcePath'], $path) || self::contains($path, $root['sourcePath'])) {
                    throw self::failure($path, 'A private location overlaps source root ' . $root['sourcePath'] . '.');
                }
            }
        }
    }

    /** Selection receives an un-followed lstat entry and returns boolean include/descend flags. */
    public function entries(?array $maintenanceOwner = null, ?callable $selection = null): Generator
    {
        $count = 0;
        foreach ($this->roots as $root) {
            $stat = self::sourceStat($root['sourcePath']);
            foreach ($this->identities[$root['id']] as $field => $value) {
                if ($stat[$field] !== $value) { throw self::failure($root['sourcePath'], 'The source root changed after selection.'); }
            }
            if ($root['type'] === 'file') {
                $path = basename($root['sourcePath']);
                self::safePath($path, $root['sourcePath']);
                if (!$this->ownedMaintenance($root['sourcePath'], $stat, $maintenanceOwner)) {
                    self::countEntry($count);
                    $entry = $this->selectedEntry($root, $path, $root['sourcePath'], $stat, $maintenanceOwner, $selection);
                    if ($entry !== null) { yield $entry; }
                }
            } else {
                yield from $this->directoryEntries($root, '', $count, $maintenanceOwner, $selection);
            }
        }
    }

    private function directoryEntries(array $root, string $relative, int &$count, ?array $owner, ?callable $selection): Generator
    {
        $directory = $relative === '' ? $root['sourcePath'] : self::join($root['sourcePath'], $relative);
        FileSafety::assertNoLinks($directory);
        $stat = self::sourceStat($directory);
        if ($stat['type'] !== 0040000) { throw self::failure($directory, 'A source directory changed type.'); }
        try { $iterator = new \DirectoryIterator($directory); }
        catch (\UnexpectedValueException $error) { throw self::failure($directory, 'Cannot read the source directory.'); }
        $names = [];
        foreach ($iterator as $item) {
            if ($item->isDot()) { continue; }
            $name = $item->getFilename();
            $path = $relative === '' ? $name : $relative . '/' . $name;
            $absolute = self::join($root['sourcePath'], $path);
            self::safePath($path, $absolute);
            $folded = mb_convert_case(\Normalizer::normalize($name, \Normalizer::FORM_C), MB_CASE_FOLD, 'UTF-8');
            if (isset($names[$folded])) { throw new SourcePathCollision('Normalized source path collision.'); }
            $names[$folded] = $name;
            FileSafety::assertNoLinks($directory);
            $stat = self::sourceStat($absolute, $selection === null);
            if ($this->ownedMaintenance($absolute, $stat, $owner)) { continue; }
            self::countEntry($count);
            $entry = $this->selectedEntry($root, $path, $absolute, $stat, $owner, $selection);
            if ($entry === null) { continue; }
            yield $entry;
            if ($entry['type'] === 'directory' && ($selection === null || $entry['descend'])) { yield from $this->directoryEntries($root, $path, $count, $owner, $selection); }
        }
    }

    private function selectedEntry(array $root, string $path, string $absolute, array $stat, ?array $owner, ?callable $selection): ?array
    {
        if ($selection === null) { return $this->entry($root, $path, $absolute, $stat, $owner); }
        $type = [0040000 => 'directory', 0100000 => 'file', 0120000 => 'symlink'][$stat['type']] ?? 'unsupported';
        $raw = ['rootId' => $root['id'], 'path' => $path, 'type' => $type,
            'mode' => $stat['mode'] & 0777, 'absolute' => $absolute, 'stat' => $stat];
        $decision = $selection($raw);
        if (!is_array($decision) || !is_bool($decision['include'] ?? null) || !is_bool($decision['descend'] ?? null)
            || ($decision['descend'] && $type !== 'directory')) { throw new RuntimeException('Invalid source selection decision.'); }
        if (!$decision['include'] && !$decision['descend']) { return null; }
        self::validateSourceStat($stat, $absolute);
        $entry = $this->entry($root, $path, $absolute, $stat, $owner);
        $entry['selected'] = $decision['include'];
        $entry['descend'] = $decision['descend'];
        return $entry;
    }

    private function entry(array $root, string $path, string $absolute, array $stat, ?array $owner): array
    {
        $type = $stat['type'] === 0040000 ? 'directory' : ($stat['type'] === 0100000 ? 'file' : 'symlink');
        $entry = ['rootId' => $root['id'], 'path' => $path, 'type' => $type,
            'mode' => $stat['mode'] & 0777, 'absolute' => $absolute, 'stat' => $stat];
        if ($type === 'symlink') { $entry['target'] = $this->linkTarget($root, $path, $absolute, $owner); }
        return $entry;
    }

    private function linkTarget(array $root, string $path, string $absolute, ?array $owner): string
    {
        $target = @readlink($absolute);
        if (!is_string($target) || $target === '' || strlen($target) > 4096 || !mb_check_encoding($target, 'UTF-8')
            || $target[0] === '/' || preg_match('/[\p{Cc}\\\\:]/u', $target)) {
            throw self::failure($absolute, 'The symbolic link target must be a safe relative path.');
        }
        $parts = dirname($path) === '.' ? [] : explode('/', dirname($path));
        $components = explode('/', $target);
        foreach ($components as $index => $part) {
            if (strlen($part) > 255) { throw self::failure($absolute, 'The symbolic link target has an unsafe component.'); }
            if ($part === '' || $part === '.') { continue; }
            if ($part === '..') {
                if ($parts === []) { throw self::failure($absolute, 'The symbolic link leaves its source root.'); }
                array_pop($parts);
            } else { $parts[] = $part; }
            $prefix = $parts === [] ? $root['sourcePath'] : self::join($root['sourcePath'], implode('/', $parts));
            try {
                FileSafety::assertNoLinks($prefix);
                $prefixStat = self::sourceStat($prefix);
            } catch (RuntimeException $error) {
                throw self::failure($absolute, 'The symbolic link target is unavailable or traverses an unsupported entry. ' . $error->getMessage());
            }
            if ($index < count($components) - 1 && $prefixStat['type'] !== 0040000) {
                throw self::failure($absolute, 'A symbolic link target ancestor is not a directory.');
            }
        }
        $relative = implode('/', $parts);
        if ($relative !== '') { self::safePath($relative, $absolute); }
        $resolved = $relative === '' ? $root['sourcePath'] : self::join($root['sourcePath'], $relative);
        try { FileSafety::assertNoLinks($resolved); }
        catch (RuntimeException $error) { throw self::failure($absolute, 'The symbolic link target has a symbolic link ancestor or target.'); }
        $stat = self::sourceStat($resolved);
        if (!in_array($stat['type'], [0040000, 0100000], true) || $this->ownedMaintenance($resolved, $stat, $owner)) {
            throw self::failure($absolute, 'The symbolic link target is not a saved regular file or directory.');
        }
        if (substr($target, -1) === '/' && $stat['type'] !== 0040000) {
            throw self::failure($absolute, 'A symbolic link target ending in a slash must be a directory.');
        }
        return $target;
    }

    private function ownedMaintenance(string $path, array $stat, ?array $owner): bool
    {
        if ($path !== $this->maintenancePath || $owner === null || $stat['type'] !== 0100000
            || $stat['dev'] !== ($owner['dev'] ?? null) || $stat['ino'] !== ($owner['ino'] ?? null) || $stat['size'] > 512) { return false; }
        $stream = @fopen($path, 'rb');
        if ($stream === false) { throw self::failure($path, 'Cannot verify maintenance ownership.'); }
        try {
            $opened = fstat($stream);
            if ($opened === false || $opened['dev'] !== $stat['dev'] || $opened['ino'] !== $stat['ino']) {
                throw self::failure($path, 'The maintenance file changed during ownership verification.');
            }
            $body = stream_get_contents($stream, 513);
        } finally { fclose($stream); }
        if (!is_string($body)) { throw self::failure($path, 'Cannot read maintenance ownership.'); }
        if (isset($owner['token'])) {
            return is_string($owner['token']) && preg_match('~\A<\?php \$upgrading = [0-9]+; /\* wrb-([a-f0-9]{32}) \*/\z~D', $body, $match) === 1
                && hash_equals($owner['token'], $match[1]);
        }
        return isset($owner['sha256']) && is_string($owner['sha256']) && hash_equals($owner['sha256'], hash('sha256', $body));
    }

    private static function sourceStat(string $path, bool $validate = true): array
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false) { throw self::failure($path, 'Cannot inspect the source entry.'); }
        $type = $stat['mode'] & 0170000;
        $result = [];
        foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $field) { $result[$field] = $stat[$field]; }
        $result['type'] = $type;
        if ($validate) { self::validateSourceStat($result, $path); }
        return $result;
    }

    private static function validateSourceStat(array $stat, string $path): void
    {
        if (!in_array($stat['type'], [0040000, 0100000, 0120000], true)) { throw self::failure($path, 'Special filesystem entries are unsupported.'); }
        if (($stat['mode'] & 07000) !== 0) { throw self::failure($path, 'Setuid, setgid and sticky modes are unsupported.'); }
        if ($stat['type'] !== 0040000 && $stat['nlink'] !== 1) { throw self::failure($path, 'Hard-linked source entries are unsupported.'); }
    }

    private static function existingPath(string $path): string
    {
        self::absolutePath($path);
        clearstatcache(true, $path);
        $resolved = realpath($path);
        if ($resolved === false) { throw self::failure($path, 'The selected source does not exist.'); }
        FileSafety::assertNoLinks($resolved);
        return $resolved;
    }

    private static function projectedPath(string $path): string
    {
        self::absolutePath($path);
        $resolved = '/';
        foreach (explode('/', substr($path, 1)) as $part) {
            if ($part === '' || $part === '.') { continue; }
            if ($part === '..') { $resolved = dirname($resolved); continue; }
            $candidate = self::join($resolved, $part);
            clearstatcache(true, $candidate);
            $stat = @lstat($candidate);
            if ($stat !== false) {
                $actual = realpath($candidate);
                if ($actual === false) { throw self::failure($candidate, 'Cannot resolve the private location.'); }
                $resolved = $actual;
            } else { $resolved = $candidate; }
        }
        return $resolved;
    }

    private static function absolutePath(string $path): void
    {
        if ($path === '' || $path[0] !== '/' || strpos($path, "\0") !== false || strpos($path, '\\') !== false) {
            throw self::failure($path, 'Expected an absolute filesystem path.');
        }
    }

    private static function safePath(string $path, string $absolute): void
    {
        try { FileSafety::validateEntries([['path' => $path, 'type' => 'file']]); }
        catch (\InvalidArgumentException $error) { throw self::failure($absolute, $error->getMessage()); }
    }

    private static function contains(string $parent, string $path): bool
    {
        return $parent === $path || strpos($path, rtrim($parent, '/') . '/') === 0;
    }

    private static function join(string $parent, string $child): string
    {
        return rtrim($parent, '/') . '/' . $child;
    }

    private static function countEntry(int &$count): void
    {
        if (++$count > self::MAX_ENTRIES) { throw new RuntimeException('The source contains more than 500000 entries.'); }
    }

    private static function failure(string $path, string $reason): RuntimeException
    {
        return new RuntimeException($reason . ' Source: ' . $path);
    }
}
