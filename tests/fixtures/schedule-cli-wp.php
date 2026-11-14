<?php
function get_option($key, $default = false) { $data = json_decode(file_get_contents(getenv('WRB_SCHEDULE_FIXTURE')), true); return $data[$key] ?? $default; }
function update_option($key, $value, $autoload = null) { if ($key === 'wrb_live_schedule_runner' && getenv('WRB_SCHEDULE_FAIL_FINAL') === '1' && ($value['lastResult'] ?? '') === 'unavailable') return false; $p = getenv('WRB_SCHEDULE_FIXTURE'); $data = json_decode(file_get_contents($p), true); $data[$key] = $value; if ($key === 'wrb_live_schedule_runner' && getenv('WRB_SCHEDULE_CHANGE_CONFIG') === '1' && ($value['lastResult'] ?? '') === 'claimed') $data['wrb_live_schedule']['revision'] = str_repeat('b',64); file_put_contents($p, json_encode($data)); return true; }
function wp_cache_delete($key, $group = '') { return true; }
function wp_timezone() { return new DateTimeZone('UTC'); }
function home_url($path = '') { return 'https://fixture.invalid/' . getenv('WRB_SCHEDULE_FIXTURE') . $path; }
class SchedulerFixtureDb {
    public $options = 'wp_options'; public $last_error = ''; private $arguments = [];
    function suppress_errors($value = true) { return false; }
    function prepare($query, ...$arguments) { $this->arguments = $arguments; return $query; }
    function get_var($query) { return get_option('wrb_live_workspace_token', null); }
    function query($query) { if (get_option('wrb_live_workspace_token', null) === null) { update_option('wrb_live_workspace_token', $this->arguments[1], false); } return 1; }
}
$GLOBALS['wpdb'] = new SchedulerFixtureDb();
