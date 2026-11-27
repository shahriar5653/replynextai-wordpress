<?php

// WordPress and WooCommerce runtime doubles: no site, database, or network.
define('ABSPATH', __DIR__ . '/');
define('DAY_IN_SECONDS', 86400);
$options = array('replynextai_options' => array('server_url' => 'https://service.example', 'api_token' => 'fixture'));
$transients = array();
$scheduled = false;
$schedule_calls = 0;
$schedule_fails = false;
$reads = array();
$requests = array();
$allowed = true;
$nonce_ok = true;
$fail_index = false;
$product_count = 21;
$marker_index = 0;

function __($message, $domain) { return $message; }
function get_option($key, $default = false) { global $options; return $options[$key] ?? $default; }
function add_option($key, $value, $unused, $autoload) { global $options; if (isset($options[$key])) return false; $options[$key] = $value; return true; }
function delete_option($key) { global $options; unset($options[$key]); }
function update_option($key, $value, $autoload = null) { global $options; $options[$key] = $value; }
function maybe_serialize($value) { return serialize($value); }
function wp_cache_delete($key, $group) {}
class CatalogTestDb {
    public $options = 'wp_options';
    public function prepare($sql, ...$args) { return array($sql, $args); }
    public function query($query) {
        global $options;
        list($sql, $args) = $query;
        if (0 === strpos($sql, 'INSERT')) return add_option($args[0], unserialize($args[1]), '', 'no') ? 1 : 0;
        if (0 === strpos($sql, 'DELETE')) {
            if (serialize($options[$args[0]] ?? null) !== $args[1]) return 0;
            delete_option($args[0]);
        } else {
            if (serialize($options[$args[1]] ?? null) !== $args[2]) return 0;
            update_option($args[1], unserialize($args[0]));
        }
        return 1;
    }
}
$wpdb = new CatalogTestDb();
function get_transient($key) { global $transients; return $transients[$key] ?? false; }
function set_transient($key, $value, $ttl) { global $transients; $transients[$key] = $value; return true; }
function delete_transient($key) { global $transients; unset($transients[$key]); }
function wp_parse_args($given, $defaults) { return array_merge($defaults, $given); }
function wp_generate_uuid4() { global $marker_index; return '00000000-0000-4000-8000-' . str_pad((string) ++$marker_index, 12, '0', STR_PAD_LEFT); }
function wp_is_uuid($value, $version) { return $version === 4 && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $value); }
function wp_next_scheduled($hook) { global $scheduled; return $scheduled; }
function wp_schedule_single_event($when, $hook) { global $scheduled, $schedule_calls, $schedule_fails; $schedule_calls++; if ($schedule_fails) return false; $scheduled = $when; return true; }
function current_user_can($capability) { global $allowed; return $capability === 'manage_options' && $allowed; }
function check_ajax_referer($action, $parameter, $die) { global $nonce_ok; return $nonce_ok && $action === 'replynextai_sync_products' && $parameter === '_ajax_nonce' && !$die; }
function sanitize_text_field($value) { return trim(strip_tags($value)); }
function wp_unslash($value) { return $value; }
function wp_json_encode($value) { return json_encode($value); }
function is_wp_error($response) { return false; }
function wp_remote_retrieve_response_code($response) { return $response['code']; }
function wp_remote_retrieve_body($response) { return $response['body']; }
function absint($value) { return abs((int) $value); }
function home_url($path) { return 'https://store.example/'; }
function wp_get_post_terms($id, $type, $args) { return array('Test'); }
function wp_strip_all_tags($text) { return strip_tags($text); }
function get_permalink($id) { return 'https://store.example/product/' . $id; }
function get_post_modified_time($format, $gmt, $id) { return '2026-01-01T00:00:00+00:00'; }

class CatalogTestReply extends RuntimeException {
    public $body; public $status;
    public function __construct($body, $status) { $this->body = $body; $this->status = $status; }
}
function wp_send_json_success($data) { throw new CatalogTestReply($data, 200); }
function wp_send_json_error($data, $status) { throw new CatalogTestReply($data, $status); }
function call_catalog_ajax($callback) { try { $callback(); } catch (CatalogTestReply $reply) { return $reply; } throw new RuntimeException('Missing AJAX response'); }
class CatalogTestProduct {
    private $id;
    public function __construct($id) { $this->id = $id; }
    public function get_id() { return $this->id; }
    public function get_image_id() { return 0; }
    public function get_name() { return 'Product ' . $this->id; }
    public function get_slug() { return 'product-' . $this->id; }
    public function get_price() { return '1'; }
    public function get_short_description() { return 'Fixture product'; }
    public function get_attributes() { return array(); }
    public function get_attribute($key) { return ''; }
    public function get_status() { return 'publish'; }
}
function wc_get_products($params) {
    global $reads, $plugin, $product_count;
    $reads[] = call_catalog_ajax(array($plugin, 'ajax_sync_products_status'))->body['phase'];
    $first = ($params['page'] - 1) * $params['limit'] + 1;
    $products = array();
    for ($i = $first; $i <= min($product_count, $first + $params['limit'] - 1); $i++) $products[] = new CatalogTestProduct($i);
    return (object) array('products' => $products);
}
function wp_remote_post($url, $request) {
    global $requests, $fail_index;
    $body = json_decode($request['body'], true);
    $index = substr($url, -8) === '/catalog';
    $requests[] = array($url, $body);
    $data = $fail_index && $index ? array('success' => false) : array('success' => true, $index ? 'indexed' : 'synced' => count($body['products']));
    return array('code' => 200, 'body' => json_encode($data));
}

require __DIR__ . '/../includes/class-replynextai-plugin.php';
$plugin = (new ReflectionClass('ReplyNextAI_Plugin'))->newInstanceWithoutConstructor();
$progress_key = ReplyNextAI_Plugin::CATALOG_PROGRESS_TRANSIENT;
function sync_assert($condition, $message) { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } }
function tick_catalog() { global $scheduled, $plugin; $scheduled = false; return $plugin->sync_products(); }

$start = array($plugin, 'ajax_sync_products');
$status = array($plugin, 'ajax_sync_products_status');
$allowed = false;
sync_assert(call_catalog_ajax($start)->status === 403 && $schedule_calls === 0, 'an unauthorized request cannot enqueue sync');
$allowed = true; $nonce_ok = false;
sync_assert(call_catalog_ajax($status)->status === 403, 'polling requires the action nonce and capability');
$nonce_ok = true;

$step = array($plugin, 'ajax_sync_products_step');
$checkpoint = ReplyNextAI_Plugin::CATALOG_CHECKPOINT_OPTION;
$schedule_fails = true;
$scheduled = time() - 100;
sync_assert(strpos(call_catalog_ajax($status)->body['message'], 'has not started') !== false, 'overdue background job can be bypassed');
$part = call_catalog_ajax($start)->body;
sync_assert($part['phase'] === 'queued' && strpos($part['message'], '20 products so far') !== false && $reads === array('running'), 'manual AJAX runs first 20 despite unavailable cron');
sync_assert($schedule_calls === 0 && count($requests) === 2, 'no cron needed and no early completion');
$sync_id = $requests[0][1]['sync_id'];
$_POST = array('marker' => $part['marker']);
$options[ReplyNextAI_Plugin::CATALOG_LOCK_OPTION] = array('token' => 'other', 'expires' => time() + 300);
sync_assert(call_catalog_ajax($step)->body['phase'] === 'running' && count($requests) === 2, 'live worker excludes overlapping tab');
$options[ReplyNextAI_Plugin::CATALOG_LOCK_OPTION]['expires'] = time() - 1;
$fail_index = true;
$retry = call_catalog_ajax($step)->body;
sync_assert($retry['phase'] === 'queued' && $options[$checkpoint]['page'] === 2, 'expired lock recovered and failed batch remains resumable');
sync_assert(call_catalog_ajax($step)->body['marker'] === $retry['marker'] && count($requests) === 4, 'stale step does not repeat requests');
for ($i = 0; $i < 2; $i++) {
    $_POST['marker'] = $retry['marker'];
    $retry = call_catalog_ajax($step)->body;
}
sync_assert($retry['phase'] === 'failed' && isset($options[$checkpoint]), 'retry cap retains checkpoint');
$fail_index = false;
$last_batch = call_catalog_ajax($start)->body;
sync_assert($last_batch['phase'] === 'queued' && $options[$checkpoint]['finalizing'], 'manual finalization gets its own request');
$_POST['marker'] = $last_batch['marker'];
$current = call_catalog_ajax($step)->body;
sync_assert($current['phase'] === 'completed' && $current['message'] === 'Last sync completed: 21 products.', 'retry click resumes and finalizes exact total');
foreach ($requests as $entry) sync_assert($entry[1]['sync_id'] === $sync_id, 'all batch retries and finalization share sync id');
sync_assert(!isset($options[$checkpoint]), 'completion clears checkpoint');
$count = count($requests);
$_POST['marker'] = $current['marker'];
call_catalog_ajax($step);
sync_assert(count($requests) === $count, 'late duplicate cannot restart a completed run');
$product_count = 101;
$part = call_catalog_ajax($start)->body;
$options[$checkpoint]['total_synced'] = 100;
unset($options[$checkpoint]['batch_size']);
$_POST['marker'] = $part['marker'];
$offset = count($requests);
$legacy = call_catalog_ajax($step)->body;
sync_assert($requests[$offset][1]['products'][0]['id'] === 101, 'legacy checkpoint keeps its original 100-product offset');
$_POST['marker'] = $legacy['marker'];
call_catalog_ajax($step);
$product_count = 21;
$schedule_fails = false;
$scheduled = false;
sync_assert(tick_catalog() && $scheduled && $schedule_calls === 1, 'automatic worker still schedules next batch');
sync_assert(tick_catalog() && call_catalog_ajax($status)->body['phase'] === 'completed', 'automatic worker can complete');
echo "PASS: manual and cron lifecycles, authentication, lock recovery, idempotent replay, retry and completion\n";
