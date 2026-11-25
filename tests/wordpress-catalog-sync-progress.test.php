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
$product_count = 101;
$marker_index = 0;

function __($message, $domain) { return $message; }
function get_option($key, $default = false) { global $options; return $options[$key] ?? $default; }
function add_option($key, $value, $unused, $autoload) { global $options; if (isset($options[$key])) return false; $options[$key] = $value; return true; }
function delete_option($key) { global $options; unset($options[$key]); }
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

$previous = wp_generate_uuid4();
$transients[$progress_key] = array('phase' => 'completed', 'message' => 'Last sync completed: 12 products.', 'marker' => $previous);
$accepted = call_catalog_ajax($start)->body;
sync_assert($accepted['phase'] === 'queued' && $accepted['marker'] === $previous && strpos($accepted['message'], '12 products') === false, 'enqueue never reports an earlier result');
call_catalog_ajax($start);
sync_assert($schedule_calls === 1 && !$requests, 'a second click attaches to the queued sync; AJAX does not perform the batch');
$_POST = array('marker' => $previous);
sync_assert(call_catalog_ajax($status)->body['phase'] === 'queued', 'waiting for current job');
sync_assert(tick_catalog(), 'first batch succeeds');
$part = call_catalog_ajax($status)->body;
sync_assert($part['phase'] === 'queued' && strpos($part['message'], '100 products so far') !== false && $reads === array('running'), 'status reports running and continues polling after a batch');
sync_assert(!isset($requests[0][1]['sync_complete']) && count($requests) === 2, 'not finalized after an intermediate batch');
sync_assert(tick_catalog(), 'last batch succeeds');
$current = call_catalog_ajax($status)->body;
sync_assert($current['phase'] === 'completed' && $current['marker'] !== $previous && $current['message'] === 'Last sync completed: 101 products.', 'result and total belong to the new run');

$old_result = $current['marker']; $requests = array(); $fail_index = true;
call_catalog_ajax($start);
$_POST['marker'] = $old_result;
sync_assert(!tick_catalog(), 'index failure is not success');
$failure = call_catalog_ajax($status)->body;
sync_assert($failure['phase'] === 'failed' && strpos($failure['message'], 'retry is scheduled') !== false, 'shows error even when a background retry is queued');
sync_assert(count($requests) === 2 && !isset($requests[1][1]['sync_complete']), 'failed index is not finalized');

$scheduled = time() - 100;
$_POST['marker'] = $failure['marker'];
sync_assert(strpos(call_catalog_ajax($status)->body['message'], 'WP-Cron') !== false, 'overdue cron stops indefinite loading and explains the problem');
$scheduled = false; $schedule_fails = true;
$options[ReplyNextAI_Plugin::CATALOG_QUEUED_OPTION] = array('claimed_at' => time() - 30);
$options[ReplyNextAI_Plugin::CATALOG_LOCK_OPTION] = array('token' => 'expired', 'expires' => time() - 30);
sync_assert(call_catalog_ajax($start)->status === 409 && !isset($options[ReplyNextAI_Plugin::CATALOG_QUEUED_OPTION]), 'a dead worker does not hide a real scheduling failure');
echo "PASS: authentic AJAX, no duplicate queues, live batch status, new result count, and safe failure handling\n";
