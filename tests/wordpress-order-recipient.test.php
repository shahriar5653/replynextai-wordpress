<?php
define('ABSPATH', __DIR__ . '/');
$stored = array('company_id' => '12', 'api_token' => 'local-fixture-only-token');
$reads = 0;
$saved_email = 'billing@example.invalid';
function get_option($key, $default = false) { global $stored; return $key === 'replynextai_options' ? $stored : $default; }
function wp_parse_args($args, $defaults) { return array_merge($defaults, $args); }
function wp_parse_url($value) { return parse_url($value); }
function home_url($path) { return 'https://store.example/'; }
function absint($value) { return abs((int) $value); }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function is_email($value) { return filter_var($value, FILTER_VALIDATE_EMAIL); }
class WP_REST_Response {
    public $data; public $status; public $headers = array();
    function __construct($data, $status) { $this->data = $data; $this->status = $status; }
    function header($key, $value) { $this->headers[$key] = $value; }
}
class WC_Order { function get_billing_email() { global $saved_email; return $saved_email; } }
function wc_get_order($id) { global $reads; $reads++; return $id === 123 ? new WC_Order() : false; }
class RecipientRequest {
    public $payload; public $timestamp; public $signature;
    function __construct($changes = array()) {
        $this->payload = array_merge(array('companyId' => 12, 'siteUrl' => 'https://store.example', 'orderId' => '123', 'challengeId' => str_repeat('b', 64)), $changes);
        $this->timestamp = (string) (int) floor(microtime(true) * 1000); $this->sign();
    }
    function sign($operation = 'order-billing-recipient') { global $stored; $this->signature = hash_hmac('sha256', $this->timestamp . '.' . $operation . '.' . hash('sha256', json_encode($this->payload, JSON_UNESCAPED_SLASHES)), hash('sha256', $stored['api_token'])); }
    function get_json_params() { return $this->payload; }
    function get_body() { return json_encode($this->payload, JSON_UNESCAPED_SLASHES); }
    function get_header($key) { return $key === 'x-replynext-timestamp' ? $this->timestamp : $this->signature; }
}
require_once __DIR__ . '/../includes/class-replynextai-plugin.php';
$plugin = (new ReflectionClass('ReplyNextAI_Plugin'))->newInstanceWithoutConstructor();
function expect_recipient($condition, $message) { if (!$condition) { fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL); exit(1); } }
$request = new RecipientRequest();
$response = $plugin->order_billing_recipient($request);
expect_recipient($response->status === 200 && is_string($response->data['sealedRecipient']), 'valid signed request returns sealed recipient');
expect_recipient(false === strpos(json_encode($response->data), $saved_email), 'no plaintext email returned');
expect_recipient($response->headers['Cache-Control'] === 'no-store, private', 'recipient response is not cached');
$parts = array_map(function ($part) { return base64_decode(strtr($part, '-_', '+/')); }, explode('.', $response->data['sealedRecipient']));
$key = hash_hmac('sha256', 'replynext-order-recipient-key-v1', hash('sha256', $stored['api_token']), true);
$aad = 'replynext-order-recipient-v1.' . hash('sha256', $request->get_body());
$decoded = json_decode(openssl_decrypt($parts[2], 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $parts[0], $parts[1], $aad), true);
expect_recipient($decoded['email'] === $saved_email && $decoded['challengeId'] === $request->payload['challengeId'], 'encryption binds saved order email and challenge');
if (isset($argv[1]) && $argv[1] === '--contract-envelope') { echo json_encode(array('binding' => $request->payload, 'sealedRecipient' => $response->data['sealedRecipient'])); exit; }
$missing = $plugin->order_billing_recipient(new RecipientRequest(array('orderId' => '999')));
$saved_email = '';
expect_recipient($plugin->order_billing_recipient(new RecipientRequest())->data === $missing->data, 'missing email and unknown order have same safe response');
$saved_email = 'a@example.com,b@example.com';
expect_recipient($plugin->order_billing_recipient(new RecipientRequest())->data['sealedRecipient'] === null, 'multiple recipients rejected');
$before = $reads;
foreach (array(array('companyId' => 99), array('siteUrl' => 'https://other.example'), array('recipient' => 'attacker@example.com'), array('challengeId' => 'bad'), array('orderId' => '123 OR 1=1')) as $changes) {
    expect_recipient($plugin->order_billing_recipient(new RecipientRequest($changes))->status === 401, 'invalid company/site/challenge/caller destination rejected');
}
$invalid = new RecipientRequest(); $invalid->sign('limited-order-status');
expect_recipient($plugin->order_billing_recipient($invalid)->status === 401, 'another operation signature cannot authorize email lookup');
$expired = new RecipientRequest(); $expired->timestamp = (string) ((int) $expired->timestamp - 60000); $expired->sign();
expect_recipient($plugin->order_billing_recipient($expired)->status === 401, 'expired signed request rejected');
expect_recipient($reads === $before, 'unauthorized requests do not read orders');
echo "PASS: sealed WooCommerce billing email, exact scope, signature separation and privacy\n";
