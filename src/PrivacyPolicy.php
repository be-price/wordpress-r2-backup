<?php
declare(strict_types=1);
namespace WordPressR2Backup;

use RuntimeException;

final class PrivacyPolicy
{
    private array $rules;
    private string $prefix;
    private ?self $legacy = null;
    private bool $publicContextReady = false;
    private $publicPostLookup = null;
    private $configPostLookup = null;
    private $publicMediaLookup = null;
    private $formPostLookup = null;
    private $formMediaLookup = null;
    private array $publicPostIds = [];
    private array $configPostIds = [];
    private array $publicMediaPaths = [];
    private array $publicMediaDirectories = [];
    public function __construct(array $rules, string $tablePrefix = 'wp_')
    {
        $this->prefix = $tablePrefix;
        if (array_key_exists('version', $rules)) {
            $this->initializeOptionalRules($rules);
            return;
        }
        $this->rules = ['tables' => [], 'fields' => [], 'paths' => [], 'categories' => [], 'scanPublic' => true, 'publicContentOnly' => false, 'excludedOptions' => [], 'excludedRows' => []];
        if (isset($rules['categories']) && !is_array($rules['categories'])) { throw new RuntimeException('Invalid privacy categories.'); }
        foreach ($rules['categories'] ?? [] as $category => $action) {
            if (!in_array($category, ['contacts', 'members', 'content', 'media'], true) || !in_array($action, ['include', 'exclude'], true)) { throw new RuntimeException('Invalid privacy category.'); }
            $this->rules['categories'][$category] = $action;
        }
        foreach (['tables', 'fields', 'paths'] as $kind) {
            if (isset($rules[$kind]) && !is_array($rules[$kind])) { throw new RuntimeException('Invalid privacy rules.'); }
            foreach ($rules[$kind] ?? [] as $target => $action) {
                if (!is_string($target) || $target === '' || strlen($target) > 1024 || strpos($target, "\0") !== false || !in_array($action, ['include', 'exclude'], true)) { throw new RuntimeException('Invalid privacy rule.'); }
                if ($kind === 'paths') { self::relativePath($target); }
                $this->rules[$kind][$target] = $action;
            }
        }
        if (isset($rules['scanPublic']) && !is_bool($rules['scanPublic'])) { throw new RuntimeException('Invalid public inspection setting.'); }
        $this->rules['scanPublic'] = $rules['scanPublic'] ?? true;
        if (array_key_exists('publicContentOnly', $rules) && !is_bool($rules['publicContentOnly'])) { throw new RuntimeException('Invalid public content setting.'); }
        $this->rules['publicContentOnly'] = $rules['publicContentOnly'] ?? false;
        $excludedOptions = array_key_exists('excludedOptions', $rules) ? $rules['excludedOptions'] : [];
        if (!is_array($excludedOptions) || count($excludedOptions) > 5000
            || ($excludedOptions !== [] && array_keys($excludedOptions) !== range(0, count($excludedOptions) - 1))) { throw new RuntimeException('Invalid excluded options.'); }
        foreach ($excludedOptions as $name) {
            if (!is_string($name) || $name === '' || strlen($name) > 191 || preg_match('/[\x00-\x1f\x7f]/', $name)) { throw new RuntimeException('Invalid excluded option name.'); }
        }
        $this->rules['excludedOptions'] = $excludedOptions;
        $excludedRows = array_key_exists('excludedRows', $rules) ? $rules['excludedRows'] : [];
        if (!is_array($excludedRows)) { throw new RuntimeException('Invalid excluded rows.'); }
        $valueCount = 0;
        foreach ($excludedRows as $table => $fields) {
            if (!is_string($table) || !preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,63}\z/D', $table) || !is_array($fields)) { throw new RuntimeException('Invalid excluded row table.'); }
            foreach ($fields as $field => $values) {
                if (!is_string($field) || !preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,63}\z/D', $field) || !is_array($values)
                    || ($values !== [] && array_keys($values) !== range(0, count($values) - 1))) { throw new RuntimeException('Invalid excluded row field.'); }
                $valueCount += count($values);
                if ($valueCount > 5000) { throw new RuntimeException('Too many excluded row values.'); }
                foreach ($values as $value) {
                    if (!is_string($value) || $value === '' || strlen($value) > 191 || preg_match('/[\x00\r\n]/', $value)) { throw new RuntimeException('Invalid excluded row value.'); }
                }
            }
        }
        $this->rules['excludedRows'] = $excludedRows;
        $this->prefix = $tablePrefix;
    }
    public function rules(): array { return $this->rules; }
    public function tableAction(string $table): array
    {
        if ($this->isOptional()) {
            if (isset($this->rules['tables'][$table])) { return self::decision($this->rules['tables'][$table], 'manual'); }
            $category = $this->tableCategory($table);
            if (isset($this->rules['categories'][$category])) { return self::decision($this->rules['categories'][$category], 'category'); }
            if ($this->legacy !== null) { return $this->legacy->tableAction($table); }
            return self::decision('include', 'complete_base');
        }
        if (isset($this->rules['tables'][$table])) { return self::decision($this->rules['tables'][$table], 'manual'); }
        $name = strpos($table, $this->prefix) === 0 ? substr($table, strlen($this->prefix)) : $table;
        $category = in_array($name, ['users', 'usermeta', 'comments', 'commentmeta'], true) ? 'members'
            : (in_array($name, ['posts', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta', 'links'], true) ? 'content' : 'contacts');
        $isContact = preg_match('/(?:^|_)(?:customer|customers|session|sessions|logs?|forms?|flamingo|leads?|orders?|submissions?|entries|messages|mail|inbox|contacts?)(?:_|$)/i', $name);
        if (($category !== 'contacts' || $isContact) && isset($this->rules['categories'][$category])) { return self::decision($this->rules['categories'][$category], 'category'); }
        if (preg_match('/(?:^|_)(?:users|usermeta|comments|commentmeta|customer|customers|session|sessions|logs?|forms?|flamingo|leads?|orders?|submissions?|entries|messages|mail|inbox|contacts?)(?:_|$)/i', $name)) { return self::decision('exclude', 'sensitive_table'); }
        if (in_array($name, ['posts', 'postmeta', 'options', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta', 'links'], true)) { return self::decision('include', 'wordpress_table'); }
        return self::decision('review', 'unknown_table');
    }
    public function fieldAction(string $table, string $field, ?array $row = null): array
    {
        if ($this->isOptional()) {
            if (isset($this->rules['fields'][$table . '.' . $field])) { return self::decision($this->rules['fields'][$table . '.' . $field], 'manual'); }
            if ($row !== null && $this->optionalRowRule($table, $row) === 'include') { return self::decision('include', 'manual_row'); }
            if (($this->rules['tables'][$table] ?? null) === 'include') { return self::decision('include', 'manual_table'); }
            $category = $row !== null ? $this->rowCategory($table, $row) : $this->tableCategory($table);
            if (isset($this->rules['categories'][$category])) { return self::decision($this->rules['categories'][$category], 'category'); }
            return $this->legacy !== null ? $this->legacy->fieldAction($table, $field) : self::decision('include', 'complete_base');
        }
        if ($this->rules['publicContentOnly'] && $table === $this->prefix . 'posts' && $field === 'post_password') { return self::decision('include', 'empty_public_password'); }
        if (isset($this->rules['fields'][$table . '.' . $field])) { return self::decision($this->rules['fields'][$table . '.' . $field], 'manual'); }
        if (($this->rules['tables'][$table] ?? null) === 'include') { return self::decision('include', 'manual_table'); }
        if (preg_match('/(?:e_?mail|phone|tel(?:ephone)?|address|password|user_pass|full_?name|first_?name|last_?name|display_?name|ip_?address|remote_addr|token|secret|credential)/i', $field)) { return self::decision('review', 'personal_field_name'); }
        return self::decision('include', 'ordinary_field');
    }
    public function fieldHasExplicitDecision(string $table, string $field): bool
    {
        if ($this->isOptional()) {
            return isset($this->rules['fields'][$table . '.' . $field]) || ($this->rules['tables'][$table] ?? null) === 'include'
                || isset($this->rules['categories'][$this->tableCategory($table)])
                || ($this->legacy !== null && $this->legacy->fieldHasExplicitDecision($table, $field));
        }
        return isset($this->rules['fields'][$table . '.' . $field]) || ($this->rules['tables'][$table] ?? null) === 'include';
    }
    public function pathAction(string $path, ?string $scopedPath = null): array
    {
        self::relativePath($path);
        if ($this->isOptional()) {
            if ($scopedPath !== null) {
                self::relativePath($scopedPath);
                $scoped = $this->optionalPathRule($scopedPath);
                if ($scoped !== null) { return self::decision($scoped, 'manual'); }
            }
            $match = $this->optionalPathRule($path);
            if ($match !== null) { return self::decision($match, 'manual'); }
            $category = $this->formMediaLookup !== null && ($this->formMediaLookup)($path) ? 'forms' : $this->pathCategory($path);
            if (isset($this->rules['categories'][$category])) { return self::decision($this->rules['categories'][$category], 'category'); }
            return $this->legacy !== null ? $this->legacy->pathAction($path) : self::decision('include', 'complete_base');
        }
        // These WordPress library sources are not generated cache data.
        $simplePieCacheSource = preg_match('~^wp-includes/SimplePie/(?:src/Cache(?:/(?:Redis|CallableNameFilter|NameFilter|File|DB|Psr16|Memcache|BaseDataCache|DataCache|Base|Memcached|MySQL)\.php)?|library/SimplePie/Cache(?:/(?:Redis|File|DB|Memcache|Memcached|Base|MySQL)\.php)?)$~D', $path) === 1;
        if (!$simplePieCacheSource && (preg_match('~(?:^|/)(?:wp-config\.php|\.env(?:\.[^/]*)?|\.git|\.svn|\.aws|\.htpasswd|\.maintenance|\.npmrc|auth\.json|credentials\.json|application_default_credentials\.json|id_rsa|id_ed25519|node_modules|tests?|cache|caches|logs?|private|wrb-private|wordpress-r2-backup-work)(?:/|$)~i', $path) || preg_match('/\.(?:log|sql|bak|pem|key|p12|pfx)$/i', $path))) { return self::decision('exclude', 'secret_or_generated_file'); }
        $match = null;
        foreach ($this->rules['paths'] as $prefix => $action) {
            if ($path === rtrim($prefix, '/') || strpos($path, rtrim($prefix, '/') . '/') === 0) {
                if ($match === null || strlen($prefix) > strlen($match[0])) { $match = [$prefix, $action]; }
            }
        }
        if ($this->rules['publicContentOnly'] && ($path === 'wp-content/uploads' || strpos($path, 'wp-content/uploads/') === 0)) {
            if ($match !== null && $match[1] === 'exclude') { return self::decision('exclude', 'manual'); }
            if (($this->rules['categories']['media'] ?? null) === 'exclude' && $match === null) { return self::decision('exclude', 'category'); }
            $allowed = $this->publicContextReady && ($this->publicMediaLookup !== null ? ($this->publicMediaLookup)($path) : (isset($this->publicMediaPaths[$path]) || isset($this->publicMediaDirectories[$path])));
            return self::decision($allowed ? 'include' : 'exclude', 'public_content_scope');
        }
        if ($match !== null) { return self::decision($match[1], 'manual'); }
        if (strpos($path, 'wp-content/uploads/') === 0 && isset($this->rules['categories']['media'])) { return self::decision($this->rules['categories']['media'], 'category'); }
        if (preg_match('~^(?:wp-admin|wp-includes)/~', $path)
            || (strpos($path, '/') === false && preg_match('/\.(?:php|txt|html)$/i', $path))
            || (preg_match('~^wp-content/(?:plugins|themes|mu-plugins)/~', $path) && preg_match('/\.(?:php|js|css|json|md|txt|html|xml|map|woff2?|ttf|eot|svg|png|jpe?g|gif|webp|ico)$/i', $path))) { return self::decision('include', 'application_code'); }
        if (strpos($path, 'wp-content/uploads/') === 0) { return self::decision($this->rules['scanPublic'] ? 'review' : 'include', $this->rules['scanPublic'] ? 'public_file_requires_review' : 'public_inspection_disabled'); }
        return self::decision('review', 'unknown_file');
    }
    public function pathHasExplicitDecision(string $path, ?string $scopedPath = null): bool
    {
        if (!$this->isOptional()) { return false; }
        if (($scopedPath !== null && $this->optionalPathRule($scopedPath) !== null) || $this->optionalPathRule($path) !== null) { return true; }
        $category = $this->formMediaLookup !== null && ($this->formMediaLookup)($path) ? 'forms' : $this->pathCategory($path);
        return isset($this->rules['categories'][$category]);
    }

    public function containsPersonalData(string $value): bool
    {
        $withoutDates = preg_replace('/\b\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?)?\b/', '', $value);
        return preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $value) === 1
            || preg_match('/(?<!\d)(?:\+81[- ]?|0)(?:\d[- ]?){8,10}(?!\d)/', $withoutDates) === 1
            || preg_match('/(?<![\d.])(?:\d{1,3}\.){3}\d{1,3}(?![\d.])/', $value) === 1;
    }
    public function needsPublicContentContext(): bool
    {
        if ($this->isOptional()) { return $this->legacy !== null && $this->legacy->needsPublicContentContext(); }
        return !empty($this->rules['publicContentOnly']) || !$this->rules['scanPublic'];
    }

    public function setPublicContentContext(array $publicPostIds, array $publicMediaPaths, array $configPostIds = []): void
    {
        if ($this->isOptional()) {
            if ($this->legacy !== null) { $this->legacy->setPublicContentContext($publicPostIds, $publicMediaPaths, $configPostIds); }
            return;
        }
        $directories = [];
        foreach ($publicMediaPaths as $path => $included) {
            self::relativePath($path);
            if ($included !== true || strpos($path, 'wp-content/uploads/') !== 0) { throw new RuntimeException('Invalid public media context.'); }
            $parent = dirname($path);
            while ($parent !== 'wp-content' && $parent !== '.') {
                $directories[$parent] = true;
                $parent = dirname($parent);
            }
        }
        foreach ([$publicPostIds, $configPostIds] as $ids) {
            foreach ($ids as $id => $included) {
                if ($included !== true || !preg_match('/\A[1-9][0-9]*\z/D', (string) $id)) { throw new RuntimeException('Invalid public post context.'); }
            }
        }
        $this->publicPostLookup = null; $this->configPostLookup = null; $this->publicMediaLookup = null;
        $this->publicPostIds = array_diff_key($publicPostIds, $configPostIds);
        $this->configPostIds = $configPostIds;
        $this->publicMediaPaths = $publicMediaPaths;
        $this->publicMediaDirectories = $directories;
        $this->publicContextReady = true;
    }

    public function setPublicContentLookups(callable $publicPost, callable $mediaPath, callable $configPost): void
    {
        if ($this->isOptional()) {
            if ($this->legacy !== null) { $this->legacy->setPublicContentLookups($publicPost, $mediaPath, $configPost); }
            return;
        }
        $this->publicPostLookup = $publicPost;
        $this->publicMediaLookup = $mediaPath;
        $this->configPostLookup = $configPost;
        $this->publicContextReady = true;
    }

    public function needsFormSubmissionContext(): bool
    {
        return $this->isOptional() && isset($this->rules['categories']['forms']);
    }

    public function setFormSubmissionLookups(callable $post, callable $mediaPath): void
    {
        $this->formPostLookup = $post;
        $this->formMediaLookup = $mediaPath;
    }

    public function publicContextRules(): array
    {
        return $this->legacy !== null ? $this->legacy->rules() : $this->rules;
    }

    private function publicPost($id): bool
    {
        return $this->publicPostLookup !== null ? (bool) ($this->publicPostLookup)((string) $id) : isset($this->publicPostIds[$id]);
    }

    private function configPost($id): bool
    {
        return $this->configPostLookup !== null ? (bool) ($this->configPostLookup)((string) $id) : isset($this->configPostIds[$id]);
    }

    public function shouldInspectField(string $table, string $field, ?array $row = null): bool
    {
        if ($this->isOptional()) {
            if ($this->legacy === null || $this->fieldHasExplicitDecision($table, $field)
                || ($row !== null && (isset($this->rules['categories'][$this->rowCategory($table, $row)]) || $this->optionalRowRule($table, $row) !== null))) { return false; }
            return $this->legacy->shouldInspectField($table, $field, $row);
        }
        if ($this->fieldHasExplicitDecision($table, $field)) { return false; }
        $idField = $table === $this->prefix . 'posts' ? 'ID' : ($table === $this->prefix . 'postmeta' ? 'post_id' : null);
        $public = $this->publicContextReady && $idField !== null && $row !== null
            && $this->publicPost($row[$idField] ?? '');
        return !$public || $this->rules['scanPublic'];
    }
    public function isExplicitlyExcludedRow(string $table, array $row): bool
    {
        if ($this->isOptional()) {
            $action = $this->optionalRowRule($table, $row);
            if ($action !== null) { return $action === 'exclude'; }
            if (isset($this->rules['tables'][$table]) || isset($this->rules['categories'][$this->tableCategory($table)])) { return false; }
            return $this->legacy !== null && $this->legacy->isExplicitlyExcludedRow($table, $row);
        }
        foreach ($this->rules['excludedRows'][$table] ?? [] as $field => $values) {
            if (array_key_exists($field, $row) && is_scalar($row[$field]) && in_array((string) $row[$field], $values, true)) { return true; }
        }
        return false;
    }

    public function includeRow(string $table, array $row): bool
    {
        if ($this->isOptional()) {
            $action = $this->optionalRowRule($table, $row);
            if ($action !== null) { return $action === 'include'; }
            if (isset($this->rules['tables'][$table])) { return $this->rules['tables'][$table] === 'include'; }
            $category = $this->rowCategory($table, $row);
            if (isset($this->rules['categories'][$category])) { return $this->rules['categories'][$category] === 'include'; }
            if ($this->legacy !== null) { return $this->legacy->includeRow($table, $row); }
            return true;
        }
        if ($table === $this->prefix . 'options') {
            $name = (string) ($row['option_name'] ?? '');
            if (strpos($name, 'wrb_') === 0 || strpos($name, '_transient_') === 0 || strpos($name, '_site_transient_') === 0
                || preg_match('/(?:auth|secret|token|password|credential|session|api[_-]?key|admin_email|new_admin_email)/i', $name)) { return false; }
            if (in_array($name, $this->rules['excludedOptions'], true)) { return false; }
        }
        if ($table === $this->prefix . 'posts' && preg_match('/(?:flamingo|submission|inbound|customer|session|order)/i', (string) ($row['post_type'] ?? ''))) { return false; }
        if ($table === $this->prefix . 'postmeta' && preg_match('/(?:email|phone|address|password|secret|token|credential|session|customer)/i', (string) ($row['meta_key'] ?? ''))) { return false; }
        if ($this->isExplicitlyExcludedRow($table, $row)) { return false; }
        if ($table === $this->prefix . 'postmeta' && $this->isExplicitlyExcludedRow($this->prefix . 'posts', ['ID' => $row['post_id'] ?? null])) { return false; }
        if ($this->rules['publicContentOnly'] && in_array($table, [$this->prefix . 'posts', $this->prefix . 'postmeta'], true)) {
            if (!$this->publicContextReady) { return false; }
            $id = $row[$table === $this->prefix . 'posts' ? 'ID' : 'post_id'] ?? '';
            if (!$this->publicPost($id) && !$this->configPost($id)) { return false; }
            if ($table === $this->prefix . 'posts') {
                if (($row['post_password'] ?? null) !== '' || in_array($row['post_status'] ?? '', ['private', 'draft', 'pending', 'future', 'trash', 'auto-draft'], true) || ($row['post_type'] ?? '') === 'revision') { return false; }
            }
        }
        return true;
    }
    public function filterValue(string $table, array $column, $value, int $rowNumber, ?array $row = null)
    {
        if ($this->isOptional()) {
            $field = (string) ($column['Field'] ?? '');
            $category = $row !== null ? $this->rowCategory($table, $row) : $this->tableCategory($table);
            $hasOverride = isset($this->rules['fields'][$table . '.' . $field])
                || ($this->rules['tables'][$table] ?? null) === 'include' || isset($this->rules['categories'][$category])
                || ($row !== null && $this->optionalRowRule($table, $row) === 'include');
            if (!$hasOverride && $this->legacy !== null) { return $this->legacy->filterValue($table, $column, $value, $rowNumber, $row); }
            $action = $this->fieldAction($table, $field, $row)['action'];
            if ($action === 'include') { return $value; }
            if ($action === 'exclude' && ($column['Null'] ?? 'NO') === 'YES'
                && ($column['Key'] ?? '') !== 'PRI' && stripos((string) ($column['Extra'] ?? ''), 'auto_increment') === false) { return null; }
            throw new RuntimeException('This field cannot be safely removed. Exclude its row or configure an explicit replacement.');
        }
        if ($this->rules['publicContentOnly'] && $table === $this->prefix . 'posts' && $column['Field'] === 'post_password') {
            if ($value !== '') { throw new RuntimeException('A protected post cannot be exported as public content.'); }
            return '';
        }
        $action = $this->fieldAction($table, $column['Field']);
        if ($action['action'] === 'review') { throw new RuntimeException('A database field needs a privacy decision.'); }
        if ($action['action'] === 'include') { return $value; }
        if (($column['Key'] ?? '') === 'PRI' || stripos((string) ($column['Extra'] ?? ''), 'auto_increment') !== false) { throw new RuntimeException('A key field cannot be removed without a relationship rule.'); }
        if (($column['Null'] ?? 'NO') === 'YES') { return null; }
        if (is_string($value) && (preg_match('/\A(?:a|O|s|i|b|d|C):|\AN;/', $value)
            || (in_array(substr(ltrim($value), 0, 1), ['{', '['], true) && json_decode($value, true) !== null))) {
            throw new RuntimeException('A structured field needs a format-preserving replacement rule.');
        }
        $type = strtolower((string) $column['Type']);
        if (preg_match('/^(?:tinyint|smallint|mediumint|int|bigint|decimal|numeric|float|double|real|bit)\b/', $type)) {
            if (($column['Key'] ?? '') === 'UNI') { throw new RuntimeException('A unique numeric field needs a replacement rule.'); }
            return '0';
        }
        if (preg_match('/^(?:var)?(?:char|binary)\((\d+)\)/', $type, $m) || preg_match('/(?:text|blob)$/', $type)) {
            $replacement = preg_match('/email|e_mail/i', $column['Field']) ? 'removed-' . $rowNumber . '@example.invalid' : 'removed-' . $rowNumber;
            if (isset($m[1]) && strlen($replacement) > (int) $m[1]) { throw new RuntimeException('A field is too short for a safe replacement.'); }
            return $replacement;
        }
        throw new RuntimeException('This field type needs an explicit safe replacement rule.');
    }
    public static function migrateLegacy(array $rules, string $prefix = 'wp_'): array
    {
        if (array_key_exists('version', $rules)) { throw new RuntimeException('Only unversioned legacy policies can be migrated.'); }
        $legacy = new self($rules, $prefix);
        return ['version' => 2, 'preset' => 'custom', 'tables' => [], 'fields' => [], 'paths' => [], 'categories' => [],
            'options' => [], 'rows' => [], 'legacy' => $legacy->rules()];
    }

    public function isComplete(): bool
    {
        if (!$this->isOptional() || $this->legacy !== null) { return false; }
        foreach (['tables', 'fields', 'paths', 'categories', 'options'] as $kind) {
            if (in_array('exclude', $this->rules[$kind], true)) { return false; }
        }
        foreach ($this->rules['rows'] as $fields) {
            foreach ($fields as $values) { if (in_array('exclude', $values, true)) { return false; } }
        }
        return true;
    }

    public function shouldTraversePath(string $path, ?string $scopedPath = null): bool
    {
        $decision = $this->pathAction($path, $scopedPath);
        if ($decision['action'] !== 'exclude') { return true; }
        if (!$this->isOptional()) { return false; }
        if ($decision['reason'] === 'category') { return true; }
        foreach ($this->rules['paths'] as $target => $action) {
            if ($action === 'include' && (strpos($target, rtrim($path, '/') . '/') === 0 || ($scopedPath !== null && strpos($target, rtrim($scopedPath, '/') . '/') === 0))) { return true; }
        }
        return false;
    }

    public function tableMayIncludeRows(string $table): bool
    {
        $decision = $this->tableAction($table);
        if ($decision['action'] !== 'exclude') { return true; }
        if (!$this->isOptional()) { return false; }
        if ($decision['reason'] === 'category') {
            if (in_array($table, [$this->prefix . 'posts', $this->prefix . 'postmeta'], true) && ($this->rules['categories']['forms'] ?? 'include') === 'include') { return true; }
            if ($table === $this->prefix . 'options' && ($this->rules['categories']['authentication'] ?? 'include') === 'include') { return true; }
        }
        if ($table === $this->prefix . 'options' && in_array('include', $this->rules['options'], true)) { return true; }
        foreach ($this->rules['rows'][$table] ?? [] as $values) { if (in_array('include', $values, true)) { return true; } }
        return false;
    }

    public function legacyRuleSummary(): array
    {
        if ($this->isOptional() && $this->legacy === null) { return []; }
        return [
            ['id' => 'legacy.sensitive_tables', 'description' => 'Legacy sensitive table names are excluded; unknown tables require review. Override the table or category to change this.', 'overridable' => true],
            ['id' => 'legacy.personal_fields', 'description' => 'Legacy personal field names and detected values require review. Override the field or include its table to change this.', 'overridable' => true],
            ['id' => 'legacy.secret_generated_paths', 'description' => 'Legacy configuration, credential, private, generated and log paths are excluded. Override the path to include it.', 'overridable' => true],
            ['id' => 'legacy.option_names', 'description' => 'Legacy credential, transient and wrb option names are excluded. Override the option or include its table to change this.', 'overridable' => true],
            ['id' => 'legacy.form_post_types', 'description' => 'Legacy submission-like post types are excluded. Override the row or include its table to change this.', 'overridable' => true],
            ['id' => 'legacy.personal_meta_keys', 'description' => 'Legacy personal and credential meta keys are excluded. Override the row or include its table to change this.', 'overridable' => true],
            ['id' => 'legacy.public_content', 'description' => 'Legacy public-content scope and inspection settings are retained. Override individual targets or select complete saving to remove them.', 'overridable' => true],
            ['id' => 'legacy.saved_rules', 'description' => 'Legacy saved table, field, path, category, option and row exclusions remain effective until overridden or removed.', 'overridable' => true],
        ];
    }

    private function isOptional(): bool { return ($this->rules['version'] ?? null) === 2; }

    private function initializeOptionalRules(array $rules): void
    {
        if ($rules['version'] !== 2 || !in_array($rules['preset'] ?? null, ['full', 'forms', 'custom'], true)) { throw new RuntimeException('Invalid optional policy version or preset.'); }
        $allowed = ['version', 'preset', 'tables', 'fields', 'paths', 'categories', 'options', 'rows', 'legacy'];
        if (array_diff(array_keys($rules), $allowed) !== []) { throw new RuntimeException('Unknown optional policy setting.'); }
        $this->rules = ['version' => 2, 'preset' => $rules['preset'], 'tables' => [], 'fields' => [], 'paths' => [], 'categories' => [], 'options' => [], 'rows' => []];
        $categories = ['forms', 'wordpress_users', 'authentication', 'content', 'media', 'logs', 'backups', 'system_settings', 'other'];
        foreach (['tables', 'fields', 'paths', 'categories', 'options'] as $kind) {
            if (array_key_exists($kind, $rules) && !is_array($rules[$kind])) { throw new RuntimeException('Invalid optional policy rules.'); }
            foreach ($rules[$kind] ?? [] as $target => $action) {
                if (!is_string($target) || $target === '' || strlen($target) > 1024 || preg_match('/[\x00-\x1f\x7f]/', $target)
                    || !in_array($action, ['include', 'exclude'], true)) { throw new RuntimeException('Invalid optional policy rule.'); }
                if ($kind === 'paths') { self::relativePath($target); $target = rtrim($target, '/'); }
                if ($kind === 'tables' && !self::validIdentifier($target)) { throw new RuntimeException('Invalid optional policy table.'); }
                if ($kind === 'fields' && !preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,63}\.[A-Za-z_][A-Za-z0-9_]{0,63}\z/D', $target)) { throw new RuntimeException('Invalid optional policy field.'); }
                if ($kind === 'categories' && !in_array($target, $categories, true)) { throw new RuntimeException('Invalid optional policy category.'); }
                if ($kind === 'options' && strlen($target) > 191) { throw new RuntimeException('Invalid optional policy option.'); }
                if (isset($this->rules[$kind][$target]) && $this->rules[$kind][$target] !== $action) { throw new RuntimeException('Conflicting optional policy rules.'); }
                $this->rules[$kind][$target] = $action;
            }
        }
        if ($rules['preset'] === 'forms' && !isset($this->rules['categories']['forms'])) { $this->rules['categories']['forms'] = 'exclude'; }
        if (array_key_exists('rows', $rules) && !is_array($rules['rows'])) { throw new RuntimeException('Invalid optional row rules.'); }
        $valueCount = 0;
        foreach ($rules['rows'] ?? [] as $table => $fields) {
            if (!is_string($table) || !self::validIdentifier($table) || !is_array($fields)) { throw new RuntimeException('Invalid optional row table.'); }
            $fieldActions = [];
            foreach ($fields as $field => $values) {
                if (!is_string($field) || !self::validIdentifier($field) || !is_array($values)) { throw new RuntimeException('Invalid optional row field.'); }
                foreach ($values as $value => $action) {
                    if ((!is_string($value) && !is_int($value)) || strlen((string) $value) > 191 || preg_match('/[\x00\r\n]/', (string) $value)
                        || !in_array($action, ['include', 'exclude'], true) || ++$valueCount > 5000) { throw new RuntimeException('Invalid optional row value.'); }
                    $this->rules['rows'][$table][$field][$value] = $action;
                    $fieldActions[$field][$action] = true;
                    if ($table === $this->prefix . 'options' && $field === 'option_name'
                        && isset($this->rules['options'][(string) $value]) && $this->rules['options'][(string) $value] !== $action) { throw new RuntimeException('Conflicting option and row rules.'); }
                }
            }
            foreach ($fieldActions as $field => $actions) {
                foreach ($fieldActions as $other => $otherActions) {
                    if ($field !== $other && ((isset($actions['include']) && isset($otherActions['exclude']))
                        || (isset($actions['exclude']) && isset($otherActions['include'])))) { throw new RuntimeException('Row rules on different fields can conflict. Use a single field to identify the row.'); }
                }
            }
            if ($table === $this->prefix . 'options') {
                foreach ($fieldActions as $field => $actions) {
                    if ($field !== 'option_name' && ((isset($actions['include']) && in_array('exclude', $this->rules['options'], true))
                        || (isset($actions['exclude']) && in_array('include', $this->rules['options'], true)))) { throw new RuntimeException('Option and row rules can conflict. Use option_name to identify the option.'); }
                }
            }
        }
        if (array_key_exists('legacy', $rules)) {
            if (!is_array($rules['legacy']) || array_key_exists('version', $rules['legacy'])) { throw new RuntimeException('Invalid legacy policy fallback.'); }
            $this->legacy = new self($rules['legacy'], $this->prefix);
            $this->rules['legacy'] = $this->legacy->rules();
        }
        if ($rules['preset'] === 'full' && !$this->isComplete()) { throw new RuntimeException('Complete saving cannot contain exclusions or a legacy fallback.'); }
    }

    private static function validIdentifier(string $name): bool { return preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,63}\z/D', $name) === 1; }

    private function optionalPathRule(string $path): ?string
    {
        $match = null; $length = -1;
        foreach ($this->rules['paths'] as $target => $action) {
            if (($path === $target || strpos($path, $target . '/') === 0) && strlen($target) > $length) { $match = $action; $length = strlen($target); }
        }
        return $match;
    }

    private function optionalRowRule(string $table, array $row): ?string
    {
        $action = null;
        if ($table === $this->prefix . 'options' && isset($row['option_name']) && is_scalar($row['option_name'])) { $action = $this->rules['options'][(string) $row['option_name']] ?? null; }
        foreach ($this->rules['rows'][$table] ?? [] as $field => $values) {
            if (!array_key_exists($field, $row) || !is_scalar($row[$field]) || !isset($values[(string) $row[$field]])) { continue; }
            $candidate = $values[(string) $row[$field]];
            if ($action !== null && $candidate !== $action) { throw new RuntimeException('Conflicting optional row decisions.'); }
            $action = $candidate;
        }
        return $action;
    }

    private function tableCategory(string $table): string
    {
        $name = strpos($table, $this->prefix) === 0 ? substr($table, strlen($this->prefix)) : $table;
        if (in_array($name, ['db7_forms', 'cf7dbplugin_submits', 'wpforms_entries', 'wpforms_entry_fields', 'wpforms_entry_meta'], true)) { return 'forms'; }
        if (in_array($name, ['users', 'usermeta'], true)) { return 'wordpress_users'; }
        if ($name === 'options') { return 'system_settings'; }
        if (in_array($name, ['posts', 'postmeta', 'comments', 'commentmeta', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta', 'links'], true)) { return 'content'; }
        return 'other';
    }

    private function rowCategory(string $table, array $row): string
    {
        if ($table === $this->prefix . 'posts' && ($row['post_type'] ?? '') === 'flamingo_inbound') { return 'forms'; }
        $idField = $table === $this->prefix . 'posts' ? 'ID' : ($table === $this->prefix . 'postmeta' ? 'post_id' : null);
        if ($idField !== null && $this->formPostLookup !== null && ($this->formPostLookup)((string) ($row[$idField] ?? ''))) { return 'forms'; }
        if ($table === $this->prefix . 'options' && preg_match('/(?:auth|secret|token|password|credential|session|api[_-]?key)/i', (string) ($row['option_name'] ?? ''))) { return 'authentication'; }
        return $this->tableCategory($table);
    }

    private function pathCategory(string $path): string
    {
        if (preg_match('~(?:^|/)(?:wp-config\.php|\.env(?:\.[^/]*)?|\.htpasswd|\.aws|auth\.json|credentials\.json|application_default_credentials\.json|id_rsa|id_ed25519)(?:/|$)~i', $path)
            || preg_match('/\.(?:pem|key|p12|pfx)$/i', $path)) { return 'authentication'; }
        if (preg_match('~(?:^|/)(?:logs?)(?:/|$)|\.log$~i', $path)) { return 'logs'; }
        if (preg_match('~(?:^|/)(?:backups?|wrb-private|wordpress-r2-backup-work)(?:/|$)|\.(?:sql|bak)$~i', $path)) { return 'backups'; }
        if ($path === 'wp-content/uploads' || strpos($path, 'wp-content/uploads/') === 0) { return 'media'; }
        return 'content';
    }

    private static function decision(string $action, string $reason): array { return ['action' => $action, 'reason' => $reason]; }
    private static function relativePath(string $path): void
    {
        if ($path === '' || $path[0] === '/' || strpos($path, '\\') !== false || preg_match('~(?:^|/)\.\.(?:/|$)~', $path) || strpos($path, "\0") !== false) { throw new RuntimeException('A source-relative path is required.'); }
    }
}
