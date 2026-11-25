<?php
// Isolated test doubles for the real signed verification handler; no WP/DB.
define('ABSPATH', '/test/');
function get_option($key, $default = false) { return $key === 'replynextai_options' ? array('api_token' => 'rnwp_live_local_test_token_only', 'company_id' => '12') : $default; }
function wp_parse_args($args, $defaults) { return array_merge($defaults, $args); }
function wp_parse_url($url) { return parse_url($url); }
function home_url($path) { return 'https://store.example/'; }
function absint($value) { return abs((int) $value); }
function wp_json_encode($value) { return json_encode($value); }
function sanitize_text_field($value) { return strip_tags((string) $value); }
function get_post_type($id) { return $id === 372 ? 'product' : false; }
function get_post_field($field, $id) { return ''; }
function wc_get_product($id) { return $id === 372 ? new VerificationProduct() : false; }
function get_woocommerce_currency() { return 'BDT'; }
function wc_get_price_decimals() { return 2; }
function wc_get_price_to_display($product, $args) { return $args['price']; }
function wc_format_decimal($price, $decimals) { return number_format((float) $price, $decimals, '.', ''); }
function strip_shortcodes($text) { return str_replace('[test]', '', $text); }
function wp_html_excerpt($text, $length) { return substr(strip_tags($text), 0, $length); }
class WP_REST_Response {
    public $data; public $status;
    function __construct($data, $status) { $this->data = $data; $this->status = $status; }
    function header($name, $value) {}
}
class VerificationProduct {
    function get_id() { return 372; } function get_type() { return 'simple'; }
    function is_type($type) { return false; } function get_attributes() { return array(); }
    function get_name() { return 'Test Product'; } function get_status() { return 'publish'; }
    function get_short_description() { return '<p>Storefront description.</p>[test]'; }
    function get_price() { return '123.00'; } function get_regular_price() { return '123.00'; }
    function is_on_sale() { return false; } function is_in_stock() { return true; }
    function is_purchasable() { return true; } function get_stock_managed_by_id() { return 372; }
    function managing_stock() { return true; } function get_stock_quantity() { return 4; }
}
class VerificationRequest {
    private $input;
    function __construct($input) { $this->input = $input; }
    function get_json_params() { return $this->input['payload']; }
    function get_header($name) { return $name === 'x-replynext-timestamp' ? $this->input['timestamp'] : $this->input['signature']; }
}
require_once __DIR__ . '/../includes/class-replynextai-plugin.php';
if (($argv[1] ?? '') !== '--signed-input') { echo "PASS: signed verification fixture available\n"; exit(0); }
$input = json_decode(stream_get_contents(STDIN), true);
$plugin = (new ReflectionClass('ReplyNextAI_Plugin'))->newInstanceWithoutConstructor();
$response = $plugin->verify_woocommerce_products(new VerificationRequest($input));
echo json_encode(array('status' => $response->status, 'body' => $response->data));
