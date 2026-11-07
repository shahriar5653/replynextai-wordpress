<?php
define('ABSPATH', __DIR__ . '/');
$stored = array('company_id' => '12', 'api_token' => 'local-test-only-token');
$reads = 0;
function get_option($key, $default = false) { global $stored; return $key === 'replynextai_options' ? $stored : $default; }
function wp_parse_args($args, $defaults) { return array_merge($defaults, $args); }
function wp_parse_url($value) { return parse_url($value); }
function home_url($path) { return 'https://store.example/'; }
function absint($value) { return abs((int) $value); }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
class WP_REST_Response {
    public $data;
    public $status;
    public $headers = array();
    function __construct($data, $status) { $this->data = $data; $this->status = $status; }
    function header($key, $value) { $this->headers[$key] = $value; }
}
class WC_Order {
    function get_billing_phone() { return '+880 1712345678'; }
    function get_status() { return 'processing'; }
}
function wc_get_order($id) { global $reads; $reads++; return $id === 123 ? new WC_Order() : false; }
class OrderRequest {
    public $payload;
    public $timestamp;
    public $signature;
    function __construct($overrides = array()) {
        global $stored;
        $this->payload = array_merge(array('companyId' => 12, 'siteUrl' => 'https://store.example', 'orderId' => '123', 'billingPhone' => '8801712345678'), $overrides);
        $this->timestamp = (string) time();
        $this->sign();
    }
    function sign() { global $stored; $this->signature = hash_hmac('sha256', $this->timestamp . '.' . hash('sha256', json_encode($this->payload, JSON_UNESCAPED_SLASHES)), hash('sha256', $stored['api_token'])); }
    function get_json_params() { return $this->payload; }
    function get_header($key) { return $key === 'x-replynext-timestamp' ? $this->timestamp : $this->signature; }
}
require_once isset($argv[1]) ? $argv[1] : __DIR__ . '/../includes/class-replynextai-plugin.php';
$plugin = (new ReflectionClass('ReplyNextAI_Plugin'))->newInstanceWithoutConstructor();
function check_order($condition, $message) { if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } }
$response = $plugin->limited_order_status(new OrderRequest());
check_order($response->data === array('success' => true, 'matched' => true, 'status' => 'processing'), 'only status is returned');
check_order($response->headers['Cache-Control'] === 'no-store, private', 'private status is never cached');
$wrong_phone = $plugin->limited_order_status(new OrderRequest(array('billingPhone' => '8801799999999')));
$missing = $plugin->limited_order_status(new OrderRequest(array('orderId' => '999')));
check_order($wrong_phone->data === $missing->data && $missing->data === array('success' => true, 'matched' => false), 'unknown order and wrong phone are indistinguishable');
$before = $reads;
foreach (array(array('companyId' => 13), array('siteUrl' => 'https://other.example'), array('billingPhone' => '5678'), array('orderId' => '1 OR 1=1')) as $overrides) {
    check_order($plugin->limited_order_status(new OrderRequest($overrides))->status === 401, 'invalid scope or input denied');
}
$request = new OrderRequest(); $request->signature = str_repeat('0', 64);
check_order($plugin->limited_order_status($request)->status === 401, 'invalid signature denied');
$request = new OrderRequest(); $request->timestamp = (string) (time() - 120); $request->sign();
check_order($plugin->limited_order_status($request)->status === 401, 'expired signature denied');
check_order($reads === $before, 'authentication failures do not read WooCommerce orders');
echo "PASS: read-only WooCommerce order status, tenant/signature validation and privacy\n";
