<?php
declare(strict_types=1);
namespace WordPressR2Backup;

function wp_cache_delete($key, $group = '') { return true; }
class RunnerWorkspaceFixtureDb
{
    public $options = 'wp_options';
    public $prefix = 'wp_';
    public $last_error = '';
    private array $parameters = [];
    public function suppress_errors($value = true) { return false; }
    public function prepare($sql, ...$parameters) { $this->parameters = $parameters; return $sql; }
    public function get_var($sql) { return get_option('wrb_live_workspace_token', null); }
    public function query($sql) { if (get_option('wrb_live_workspace_token', null) === null) { update_option('wrb_live_workspace_token', $this->parameters[1], false); } return 1; }
}
$GLOBALS['wpdb'] = new RunnerWorkspaceFixtureDb();
