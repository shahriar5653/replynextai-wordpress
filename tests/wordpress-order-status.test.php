<?php
define('ABSPATH', __DIR__ . '/');
$stored = array('company_id' => '12', 'api_token' => 'local-test-only-token');
$reads = 0; $sent = array(); $created_via = 'checkout'; $order_company = 12; $paid = false; $paid_date = false;
function get_option($key, $default = false) { global $stored; return $key === 'replynextai_options' ? $stored : $default; }
function wp_parse_args($args, $defaults) { return array_merge($defaults, $args); }
function wp_parse_url($value) { return parse_url($value); }
function home_url($path = '') { return 'https://store.example/'; }
function absint($value) { return abs((int) $value); }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function wp_safe_remote_post($url, $request) { global $sent; $sent[] = array($url, $request); return array('code' => 204); }
function is_wp_error($response) { return false; }
function wp_remote_retrieve_response_code($response) { return $response['code']; }
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
    function get_id() { return 123; }
    function get_created_via() { global $created_via; return $created_via; }
    function get_meta($key) { global $order_company; return $order_company; }
    function is_paid() { global $paid; return $paid; }
    function get_date_paid() { global $paid_date; return $paid_date; }
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
    function get_body() { return json_encode($this->payload, JSON_UNESCAPED_SLASHES); }
    function get_header($key) { return $key === 'x-replynext-timestamp' ? $this->timestamp : $this->signature; }
}
class ChatOwnedOrderRequest {
    public $payload; public $timestamp; public $signature;
    function __construct($overrides = array()) { $this->payload = array_merge(array('companyId' => 12, 'siteUrl' => 'https://store.example', 'orderId' => '123'), $overrides); $this->timestamp = (string) time(); $this->sign(); }
    function sign($operation = 'chat-owned-order-status') { global $stored; $this->signature = hash_hmac('sha256', $this->timestamp . '.' . $operation . '.' . hash('sha256', json_encode($this->payload, JSON_UNESCAPED_SLASHES)), hash('sha256', $stored['api_token'])); }
    function get_json_params() { return $this->payload; }
    function get_body() { return json_encode($this->payload, JSON_UNESCAPED_SLASHES); }
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
$owned = $plugin->limited_order_status(new ChatOwnedOrderRequest());
check_order($owned->data === array('success' => true, 'matched' => true, 'status' => 'processing'), 'server-owned order identity returns only current status without billing phone');
check_order($owned->headers['Cache-Control'] === 'no-store, private', 'chat-owned status is not cached');
$before = $reads;
foreach (array(array('companyId' => 13), array('siteUrl' => 'https://other.example'), array('billingPhone' => '8801712345678'), array('orderId' => '1 OR 1=1')) as $overrides) {
    check_order($plugin->limited_order_status(new ChatOwnedOrderRequest($overrides))->status === 401, 'chat-owned status rejects foreign tenant/site and extra/invalid fields');
}
$wrong_operation = new ChatOwnedOrderRequest(); $wrong_operation->sign('limited-order-status');
check_order($plugin->limited_order_status($wrong_operation)->status === 401, 'legacy phone signature cannot authorize chat-owned status');
check_order($reads === $before, 'invalid chat-owned signatures do not read orders');
$plugin->send_chat_order_status_update(123, 'pending', 'processing');
check_order(!$sent, 'regular website orders do not emit ReplyNext chat events');
$created_via = 'replynext-ai'; $order_company = 13;
$plugin->send_chat_order_status_update(123, 'pending', 'processing');
check_order(!$sent, 'orders created for a different connection do not emit events');
$order_company = 12;
$plugin->send_chat_order_status_update(123, 'pending', 'processing');
$plugin->send_chat_payment_confirmation(123);
$paid = true;
$plugin->send_chat_payment_confirmation(123);
check_order(count($sent) === 1, 'payment notification requires both paid status and a recorded payment date');
$paid_date = new DateTimeImmutable();
$plugin->send_chat_payment_confirmation(123);
check_order(count($sent) === 2 && $sent[1][0] === 'https://replynextai.com/api/wordpress/order-update', 'publish event only to the configured ReplyNext server');
$event = $sent[1][1];
check_order(json_decode($event['body'], true) === array('companyId' => 12, 'siteUrl' => 'https://store.example', 'orderId' => '123', 'status' => 'paid'), 'event includes order ID and status only, without customer data');
$signature = hash_hmac('sha256', $event['headers']['X-ReplyNext-Timestamp'] . '.woocommerce-order-update.' . hash('sha256', $event['body']), hash('sha256', $stored['api_token']));
check_order(hash_equals($signature, $event['headers']['X-ReplyNext-Signature']), 'event signs the exact transmitted body');
echo "PASS: read-only WooCommerce order status, tenant/signature validation and privacy\n";
