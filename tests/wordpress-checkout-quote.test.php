<?php
// Contract/native-runtime test doubles only; no WordPress DB or real orders.
define('ABSPATH', __DIR__ . '/');
$quote_test = array('block' => false, 'terms' => false, 'registration' => false, 'custom' => false, 'cod' => true, 'online' => false, 'online_available' => true, 'cod_rate' => '', 'extra_rate' => false, 'not_shippable' => false, 'digital' => false, 'fee' => 0, 'out_of_stock' => false, 'variation' => false, 'stock_checks' => array(), 'cart_session_hooks' => null, 'external_shipping' => false, 'calculations' => 0, 'calculation_error' => false);
$filters = array(); $wp_filter = array();
function wp_parse_url($url) { return parse_url($url); }
function home_url($path) { return 'https://store.example/'; }
function is_user_logged_in() { return false; }
function get_post($id) { return (object) array('ID' => $id); }
function get_option($key, $default = false) { global $order_options; if (isset($order_options) && array_key_exists($key, $order_options)) return $order_options[$key]; return $key === 'woocommerce_checkout_page_id' ? 1 : $default; }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function has_block($name, $post) { global $quote_test; return $quote_test['block']; }
function wc_terms_and_conditions_checkbox_enabled() { global $quote_test; return $quote_test['terms']; }
function wc_get_price_decimals() { return 2; }
function wc_get_base_location() { return array('country' => 'BD'); }
function get_woocommerce_currency() { return 'BDT'; }
function sanitize_text_field($value) { return strip_tags($value); }
function wc_format_decimal($value, $decimals) { return number_format((float) $value, $decimals, '.', ''); }
function is_email($value) { return filter_var($value, FILTER_VALIDATE_EMAIL); }
function add_filter($name, $callback, $priority = 10) { global $filters; $filters[$name][$priority][] = $callback; }
function remove_filter($name, $callback, $priority = 10) { global $filters; if (isset($filters[$name][$priority])) $filters[$name][$priority] = array_filter($filters[$name][$priority], function ($item) use ($callback) { return $item !== $callback; }); }
function apply_filters($name, $value) { global $filters; foreach (isset($filters[$name]) ? $filters[$name] : array() as $callbacks) foreach ($callbacks as $callback) $value = $callback($value); return $value; }
function post_password_required($id) { return false; }
function wc_get_cart_item_data_hash($product) { return 'local-fixture-hash'; }
function wc_get_held_stock_quantity($product) { return 0; }
function wc_create_order() { throw new RuntimeException('Quote must never create an order'); }
class WP_REST_Response { public $data; public $status; public $headers = array(); public function __construct($data, $status) { $this->data = $data; $this->status = $status; } public function header($key, $value) { $this->headers[$key] = $value; } }
abstract class WC_Session { private $data = array(); public function get($key, $fallback = null) { return isset($this->data[$key]) ? $this->data[$key] : $fallback; } public function set($key, $value) { $this->data[$key] = $value; } }
class WC_Customer { private $country = 'BD'; public function __construct($id, $session) {} public function set_billing_country($country) { $this->country = $country; } public function get_billing_country() { return $this->country; } public function __call($method, $arguments) { if (strpos($method, 'set_') !== 0) throw new RuntimeException('Unexpected customer call'); } public function save() { throw new RuntimeException('Quote cannot save customers'); } }
class WC_Countries { public function get_allowed_countries() { return array('BD' => 'Bangladesh', 'DE' => 'Germany'); } public function get_shipping_countries() { global $quote_test; return $quote_test['not_shippable'] ? array() : $this->get_allowed_countries(); } public function get_states($country) { global $quote_test; return $quote_test['states'][$country] ?? array(); } }
class WC_Validation { public static function is_phone($phone) { return preg_match('/^[0-9+ -]{7,20}$/', $phone); } public static function is_postcode($postcode, $country) { return preg_match($country === 'DE' ? '/^[0-9]{5}$/' : '/^[0-9]{4}$/', $postcode); } }
class WC_Checkout {
    public function set_data_from_cart(&$order) { $order->from_cart(WC()->cart); }
    public function is_registration_required() { global $quote_test; return $quote_test['registration']; }
    public function get_checkout_fields() {
        global $quote_test;
        $fields = array('billing' => array('billing_first_name' => array('required' => true), 'billing_phone' => array('type' => 'tel', 'required' => true), 'billing_address_1' => array('required' => true), 'billing_country' => array('type' => 'country', 'required' => true)), 'order' => array('order_comments' => array('required' => false)));
        $fields['billing']['billing_company'] = array('required' => false);
        if (WC()->customer->get_billing_country() === 'DE') $fields['billing']['billing_postcode'] = array('required' => true);
        if ($quote_test['custom']) $fields['billing']['required_custom_field'] = array('required' => true);
        return $fields;
    }
}
class WC_Gateway_COD { public $enabled; public function __construct() { global $quote_test; $this->enabled = $quote_test['cod'] ? 'yes' : 'no'; } public function get_title() { return 'Cash on delivery'; } public function is_available() { global $quote_test; return $this->enabled === 'yes' && (!$quote_test['cod_rate'] || in_array($quote_test['cod_rate'], WC()->session->get('chosen_shipping_methods', array()), true)); } }
class QuoteRate {
    private $instance; public function __construct($instance = 1) { $this->instance = $instance; }
    public function get_method_id() { return 'flat_rate'; } public function get_id() { return 'flat_rate:' . $this->instance; }
    public function get_instance_id() { return $this->instance; }
    public function get_label() { return $this->instance === 1 ? 'Standard' : 'Express'; }
    public function get_cost() { return $this->instance === 1 ? 60 : 90; }
}
class WC_Shipping_Flat_Rate {}
class WC_Shipping_Zones { public static function get_zone_matching_package($package) { return new self(); } public function get_shipping_methods($enabled) { global $quote_test; return $quote_test['extra_rate'] ? array(new WC_Shipping_Flat_Rate(), new WC_Shipping_Flat_Rate()) : array($quote_test['external_shipping'] ? new stdClass() : new WC_Shipping_Flat_Rate()); } }
class QuoteShipping { public $packages = array('original'); public $shipping_methods = array('original'); public $shipping_classes = array('original'); public function get_packages() { global $quote_test; return array(array('rates' => $quote_test['extra_rate'] ? array(new QuoteRate(), new QuoteRate(2)) : array(new QuoteRate()))); } }
class WC_Cart {
    private $items = array();
    public function __construct() { global $quote_test; $quote_test['cart_session_hooks'] = apply_filters('woocommerce_cart_session_initialize', true); }
    public function set_cart_contents($items) { $this->items = $items; }
    public function get_cart() { return $this->items; }
    public function calculate_totals() { global $quote_test; $quote_test['calculations']++; if ($quote_test['calculation_error']) throw new RuntimeException('Private native exception not for response'); foreach ($this->items as &$item) $item['line_total'] = $item['quantity'] * 100; unset($item); }
    public function get_fee_total() { global $quote_test; return $quote_test['fee']; }
    public function get_discount_total() { return 0; }
    public function needs_shipping() { global $quote_test; return !$quote_test['digital']; }
    public function get_shipping_packages() { return array(array('contents' => $this->items)); }
    public function has_calculated_shipping() { return true; }
    public function get_cart_contents_total() { return array_sum(array_column($this->items, 'line_total')); }
    public function get_shipping_total() { if (!$this->needs_shipping()) return 0; return WC()->session->get('chosen_shipping_methods') === array('flat_rate:2') ? 90 : 60; }
    public function get_total_tax() { return 20; }
    public function get_total($mode) { return $this->get_cart_contents_total() + $this->get_shipping_total() + 20; }
}
class QuoteProduct {
    private $id; public function __construct($id) { $this->id = $id; }
    public function get_status() { return 'publish'; } public function is_visible() { return true; }
    public function is_type($type) { global $quote_test; return $type === ($this->id === 43 ? 'variation' : ($quote_test['variation'] ? 'variable' : 'simple')); }
    public function get_parent_id() { return 42; } public function get_variation_attributes() { return array('attribute_color' => 'blue'); }
    public function is_purchasable() { return true; } public function is_in_stock() { global $quote_test; return !$quote_test['out_of_stock']; }
    public function backorders_allowed() { return false; } public function is_sold_individually() { return false; }
    public function get_stock_managed_by_id() { return 42; }
    public function has_enough_stock($quantity) { global $quote_test; $quote_test['stock_checks'][] = $quantity; return $quantity <= 5; }
    public function get_name() { return 'Demo Product'; }
    public function managing_stock() { return true; }
}
function wc_get_product($id) { return in_array($id, array(42, 43, 44), true) ? new QuoteProduct($id) : false; }
$wc = (object) array('cart' => (object) array('original' => 'cart'), 'session' => (object) array('original' => 'session'), 'customer' => (object) array('original' => 'customer'), 'countries' => new WC_Countries());
class QuoteGateways {
    public function payment_gateways() { global $quote_test; return array('cod' => new WC_Gateway_COD(), 'online_gateway' => (object) array('enabled' => $quote_test['online'] ? 'yes' : 'no', 'title' => 'Test card')); }
    public function get_available_payment_gateways() { global $quote_test; $items = $this->payment_gateways(); if (!$items['cod']->is_available()) unset($items['cod']); if (!$quote_test['online_available']) unset($items['online_gateway']); return $items; }
}
class QuoteWooRuntime { public $cart; public $session; public $customer; public $countries; private $shipping; public function __construct($value) { foreach ($value as $key => $item) $this->$key = $item; $this->shipping = new QuoteShipping(); } public function shipping() { return $this->shipping; } public function payment_gateways() { return new QuoteGateways(); } }
$wc = new QuoteWooRuntime($wc);
function WC() { global $wc; return $wc; }
require __DIR__ . '/../includes/class-replynextai-checkout.php';
$options = array('company_id' => '12', 'api_token' => 'local-fixture-only-token');
$quote_payload = array('companyId' => 12, 'siteUrl' => 'https://store.example', 'lines' => array(array('productId' => '42', 'variationId' => null, 'quantity' => 2)), 'customer' => array('billing_first_name' => 'Demo', 'billing_phone' => '01700000000', 'billing_address_1' => 'Local test address', 'billing_country' => 'BD'));
class QuoteRequest {
    public $body; public $timestamp; public $signature;
    public function __construct($payload, $operation = 'checkout-quote') { global $options; $this->body = json_encode($payload, JSON_UNESCAPED_SLASHES); $this->timestamp = (string) (int) floor(microtime(true) * 1000); $this->signature = hash_hmac('sha256', $this->timestamp . '.' . $operation . '.' . hash('sha256', $this->body), hash('sha256', $options['api_token'])); }
    public function get_body() { return $this->body; } public function get_json_params() { return json_decode($this->body, true); } public function get_header($key) { return $key === 'x-replynext-timestamp' ? $this->timestamp : $this->signature; }
}
if (isset($argv[1]) && '--signed-input' === $argv[1]) {
    $input = json_decode(stream_get_contents(STDIN), true); $request = new QuoteRequest(array());
    if (!empty($input['multiOptionsFixture'])) { $quote_test['extra_rate'] = true; $quote_test['online'] = true; $quote_test['cod_rate'] = 'flat_rate:2'; $options['chat_payment_links_enabled'] = '1'; $options['chat_payment_links_with_cod'] = '1'; }
    $request->body = $input['body']; $request->timestamp = $input['timestamp']; $request->signature = $input['signature'];
    $result = ReplyNextAI_Checkout::handle($request, $options, $input['operation']);
    echo json_encode(array('status' => $result->status, 'body' => $result->data)); exit;
}
if (isset($argv[1]) && '--bootstrap-only' === $argv[1]) return;
function expect_checkout($condition, $message) { if (!$condition) { fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL); exit(1); } }
$before = array($wc->cart, $wc->customer, $wc->session, $wc->shipping()->packages);
$requirements = ReplyNextAI_Checkout::handle(new QuoteRequest(array('companyId' => 12, 'siteUrl' => 'https://store.example'), 'checkout-requirements'), $options, 'checkout-requirements');
expect_checkout($requirements->status === 200 && $requirements->data['supported'] && $requirements->data['codAvailable'], 'native fields and COD configuration discovered');
$result = ReplyNextAI_Checkout::handle(new QuoteRequest($quote_payload), $options, 'checkout-quote');
expect_checkout($result->status === 200 && $result->data['supported'] && $result->data['quote']['total'] === '280.00', 'native live totals and shipping are returned');
expect_checkout($result->headers['Cache-Control'] === 'no-store, private', 'quote cannot be cached');
expect_checkout($quote_test['cart_session_hooks'] === false, 'cart persistence hooks disabled');
expect_checkout(array($wc->cart, $wc->customer, $wc->session, $wc->shipping()->packages) === $before, 'real cart customer session and shipping restored');
expect_checkout(strpos(json_encode($result->data), '01700000000') === false, 'quote response excludes customer data');
foreach (array('block', 'terms', 'registration', 'custom', 'extra_rate', 'out_of_stock') as $condition) {
    $quote_test[$condition] = true;
    $result = ReplyNextAI_Checkout::handle(new QuoteRequest($quote_payload), $options, 'checkout-quote');
    expect_checkout($result->data['supported'] === false, $condition . ' fails closed'); $quote_test[$condition] = false;
}
$quote_test['fee'] = 10; expect_checkout(!ReplyNextAI_Checkout::handle(new QuoteRequest($quote_payload), $options, 'checkout-quote')->data['supported'], 'unsupported fees not silently omitted'); $quote_test['fee'] = 0;
$quote_test['cod'] = false; expect_checkout(!ReplyNextAI_Checkout::handle(new QuoteRequest($quote_payload), $options, 'checkout-quote')->data['supported'], 'disabled COD cannot produce an accepted quote'); $quote_test['cod'] = true;
$quote_test['external_shipping'] = true; $calculations = $quote_test['calculations'];
expect_checkout(!ReplyNextAI_Checkout::handle(new QuoteRequest($quote_payload), $options, 'checkout-quote')->data['supported'] && $quote_test['calculations'] === $calculations, 'unsupported carrier callbacks cannot run'); $quote_test['external_shipping'] = false;
$quote_test['calculation_error'] = true; $error = ReplyNextAI_Checkout::handle(new QuoteRequest($quote_payload), $options, 'checkout-quote');
expect_checkout($error->status === 503 && strpos(json_encode($error->data), 'Private native exception') === false, 'native errors do not leak internal details'); $quote_test['calculation_error'] = false;
expect_checkout(array($wc->cart, $wc->customer, $wc->session, $wc->shipping()->packages) === $before, 'native exception restores real cart/session');
$bad = $quote_payload; unset($bad['customer']['billing_phone']);
expect_checkout(ReplyNextAI_Checkout::handle(new QuoteRequest($bad), $options, 'checkout-quote')->data['code'] === 'CUSTOMER_INFORMATION_REQUIRED', 'missing required native field is not skipped');
foreach (array(array('companyId' => 99), array('siteUrl' => 'https://other.example')) as $change) expect_checkout(ReplyNextAI_Checkout::handle(new QuoteRequest(array_merge($quote_payload, $change)), $options, 'checkout-quote')->status === 401, 'wrong company/site rejected');
$request = new QuoteRequest($quote_payload, 'order-billing-recipient'); expect_checkout(ReplyNextAI_Checkout::handle($request, $options, 'checkout-quote')->status === 401, 'other operation signature cannot authorize checkout');
$request = new QuoteRequest($quote_payload); $request->timestamp = (string) ((int) $request->timestamp - 60001); expect_checkout(ReplyNextAI_Checkout::handle($request, $options, 'checkout-quote')->status === 401, 'expired signature rejected');
$bad = $quote_payload; $bad['lines'][0]['price'] = '0.01'; expect_checkout(!ReplyNextAI_Checkout::handle(new QuoteRequest($bad), $options, 'checkout-quote')->data['supported'], 'caller price rejected');
$quote_test['variation'] = true; $variation = $quote_payload; $variation['lines'][0]['variationId'] = '43';
expect_checkout(ReplyNextAI_Checkout::handle(new QuoteRequest($variation), $options, 'checkout-quote')->data['supported'], 'selected variation uses live parent-bound product');
$variation['lines'][0]['productId'] = '44'; expect_checkout(!ReplyNextAI_Checkout::handle(new QuoteRequest($variation), $options, 'checkout-quote')->data['supported'], 'variation cannot be taken from a different parent');
expect_checkout(array($wc->cart, $wc->customer, $wc->session, $wc->shipping()->packages) === $before, 'state restored after all failures');
echo "PASS: signed read-only WooCommerce quote contract, native rules, live stock, totals and isolated runtime\n";

$quote_test['variation'] = false;
$quote_test['extra_rate'] = true;
$quote_test['online'] = true;
$quote_test['cod_rate'] = 'flat_rate:2';
$links = $options + array('chat_payment_links_enabled' => '1', 'chat_payment_links_with_cod' => '1');
$choices = static function ($payload, $settings) { return ReplyNextAI_Checkout::handle(new QuoteRequest($payload, 'checkout-options'), $settings, 'checkout-options')->data; };
$result = $choices($quote_payload, $links)['options'];
expect_checkout(array_column($result['shippingRates'], 'id') === array('flat_rate:1', 'flat_rate:2') && null === $result['selectedRateId'] && !$result['paymentChoices'], 'do not infer a shipping selection when the destination has multiple live rates');
$standard = $quote_payload + array('shippingRateId' => 'flat_rate:1');
$express = $quote_payload + array('shippingRateId' => 'flat_rate:2');
expect_checkout(array_column($choices($standard, $links)['options']['paymentChoices'], 'id') === array('unpaid'), 'configured COD is not available for the selected shipping rate');
expect_checkout(array_column($choices($express, $links)['options']['paymentChoices'], 'id') === array('cod', 'unpaid'), 'native COD plus opted-in website payment on the eligible rate');
expect_checkout(array_column($choices($express, $options + array('chat_payment_links_enabled' => '1'))['options']['paymentChoices'], 'id') === array('cod'), 'previous opt-in does not silently enable website payment alongside COD');
$wrong_rate = $quote_payload + array('shippingRateId' => 'flat_rate:999');
expect_checkout(!$choices($wrong_rate, $links)['supported'], 'a removed rate cannot be substituted');
$bad_cod = $standard + array('paymentMethod' => 'cod');
expect_checkout(!ReplyNextAI_Checkout::handle(new QuoteRequest($bad_cod), $links, 'checkout-quote')->data['supported'], 'reject COD if it is no longer available on the selected rate');
$selected = $express + array('paymentMethod' => 'cod');
$review = ReplyNextAI_Checkout::handle(new QuoteRequest($selected), $links, 'checkout-quote')->data['quote'];
expect_checkout($review['shippingRateTitle'] === 'Express' && $review['shipping'] === '90.00' && $review['paymentMethod'] === 'cod' && $review['optionsSelected'], 'quote uses the exact selected store rate and payment');
$needs_email = $standard + array('paymentMethod' => 'unpaid');
expect_checkout(ReplyNextAI_Checkout::handle(new QuoteRequest($needs_email), $links, 'checkout-quote')->data['code'] === 'CUSTOMER_INFORMATION_REQUIRED', 'email becomes required for website payment');
$quote_test['not_shippable'] = true;
expect_checkout($choices($quote_payload, $links)['options']['shippingRates'] === array(), 'no available shipping when store excludes this destination');
$quote_test['digital'] = true;
expect_checkout(!$choices($quote_payload, $links)['options']['shippingRequired'], 'no delivery choice for virtual-only carts');
$de_requirements = array('companyId' => 12, 'siteUrl' => 'https://store.example', 'country' => 'DE');
$fields = ReplyNextAI_Checkout::handle(new QuoteRequest($de_requirements, 'checkout-requirements'), $links, 'checkout-requirements')->data['fields'];
expect_checkout(array_column($fields, 'required', 'id')['billing_postcode'], 'required checkout fields come from selected destination');
expect_checkout(ReplyNextAI_Checkout::handle(new QuoteRequest($quote_payload, 'checkout-quote'), $links, 'checkout-options')->status === 401, 'quote signature never authorizes options');
expect_checkout(array($wc->cart, $wc->customer, $wc->session, $wc->shipping()->packages) === $before, 'new choices also restore native cart and session');
$quote_test['states'] = array('BD' => array('BD-13' => 'Dhaka', 'BD-15' => 'Faridpur'));
$quote_test['not_shippable'] = false;
$quote_test['digital'] = false;
foreach (array('Dhaka', 'dhaka', 'BD-13', 'Faridpur', 'faridpur') as $district) {
    $named = $quote_payload;
    $named['customer']['billing_state'] = $district;
    $named_result = ReplyNextAI_Checkout::handle(new QuoteRequest($named, 'checkout-options'), $links, 'checkout-options');
    expect_checkout(!empty($named_result->data['supported']), 'customer district name or native code is accepted: ' . $district . ' ' . json_encode($named_result->data));
}
$unknown = $quote_payload;
$unknown['customer']['billing_state'] = 'Unknown district';
expect_checkout(ReplyNextAI_Checkout::handle(new QuoteRequest($unknown, 'checkout-options'), $links, 'checkout-options')->status === 400, 'unknown district is not guessed');
$quote_test['states']['BD']['BD-99'] = 'Dhaka';
$ambiguous = $quote_payload;
$ambiguous['customer']['billing_state'] = 'dhaka';
expect_checkout(ReplyNextAI_Checkout::handle(new QuoteRequest($ambiguous, 'checkout-options'), $links, 'checkout-options')->status === 400, 'ambiguous district label is not guessed');
unset($quote_test['states']);
echo "PASS: live destination fields, shipping selection, gateway restrictions and consent\n";
