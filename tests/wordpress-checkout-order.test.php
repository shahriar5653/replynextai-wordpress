<?php
// Native WooCommerce/order persistence doubles. No real database/order/payment.
$requested_mode = isset($argv[1]) ? $argv[1] : '';
$argv[1] = '--bootstrap-only';
require __DIR__ . '/wordpress-checkout-quote.test.php';
$order_options = array('woocommerce_manage_stock' => 'yes', 'woocommerce_hold_stock_minutes' => 60);
$order_objects = array(); $orders_created = 0; $order_events = array(); $order_failure = ''; $simulate_competitor = false;
function add_option($key, $value, $deprecated = '', $autoload = 'no') {
    global $order_options, $simulate_competitor;
    if ($simulate_competitor) { $order_options[$key] = $value; return false; }
    if (array_key_exists($key, $order_options)) return false;
    $order_options[$key] = $value; return true;
}
function update_option($key, $value, $autoload = null) {
    global $order_options, $order_failure;
    if ($order_failure === 'ledger-final' && isset($value['state']) && $value['state'] === 'completed') return false;
    if (isset($order_options[$key]) && $order_options[$key] === $value) return false;
    $order_options[$key] = $value; return true;
}
class WP_Error { private $errors = array(); public function add($code, $message) { $this->errors[] = $code; } public function has_errors() { return count($this->errors) > 0; } }
function wc_notice_count($type) { return 0; }
function do_action($hook, ...$args) { global $order_failure; if ($hook === 'woocommerce_after_checkout_validation' && $order_failure === 'validation') $args[1]->add('merchant-rule', 'native validation failure'); }
function wc_get_order($id) { global $order_objects; return isset($order_objects[$id]) ? $order_objects[$id] : false; }
function wc_reserve_stock_for_order($order) { global $order_events, $order_failure; $order_events[] = 'reserve'; if ($order_failure === 'stock-reservation') throw new RuntimeException('No stock available'); }
function wc_release_stock_for_order($order) { global $order_events; $order_events[] = 'release'; }
class OrderLineDouble {
    private $item; public function __construct($item) { $this->item = $item; }
    public function get_product_id() { return $this->item['product_id']; }
    public function get_variation_id() { return $this->item['variation_id']; }
    public function get_quantity() { return $this->item['quantity']; }
    public function get_total() { return $this->item['line_total']; }
}
class OrderDataStoreDouble { public function get_stock_reduced($id) { global $order_failure; return $order_failure !== 'stock-not-reduced'; } }
class WC_Order {
    private $id = 0; private $meta = array(); private $items = array(); private $customer = 0; private $status = 'pending'; private $currency = 'BDT'; private $total = 0; private $shipping = 0; private $tax = 0; private $created = ''; private $method = '';
    private $billing_email = ''; private $shipping_items = array();
    public function set_address($data, $type) { if ($type === 'billing') $this->billing_email = $data['email'] ?? ''; }
    public function get_billing_email() { return $this->billing_email; }
    public function needs_payment() { return $this->status === 'pending'; }
    public function get_checkout_payment_url() { return 'https://store.example/checkout/order-pay/' . $this->id . '/?key=wc_order_fixture'; }
    public function set_customer_id($id) { $this->customer = $id; }
    public function get_customer_id() { return $this->customer; }
    public function set_currency($value) { $this->currency = $value; } public function get_currency() { return $this->currency; }
    public function set_payment_method($gateway) { $this->method = $gateway instanceof WC_Gateway_COD ? 'cod' : ''; } public function get_payment_method() { return $this->method; }
    public function set_status($value) { $this->status = $value; } public function get_status() { return $this->status; }
    public function get_date_paid() { return null; }
    public function get_data_store() { return new OrderDataStoreDouble(); }
    public function get_id() { return $this->id; }
    public function set_prices_include_tax($value) {}
    public function set_created_via($value) { $this->created = $value; } public function get_created_via() { return $this->created; }
    public function update_meta_data($key, $value) { $this->meta[$key] = $value; } public function get_meta($key) { return isset($this->meta[$key]) ? $this->meta[$key] : ''; }
    public function from_cart($cart) { foreach ($cart->get_cart() as $item) $this->items[] = new OrderLineDouble($item); foreach (WC()->session->get('chosen_shipping_methods', array()) as $id) $this->shipping_items[] = new QuoteRate((int) substr($id, -1)); $this->total = $cart->get_total('edit'); $this->shipping = $cart->get_shipping_total(); $this->tax = $cart->get_total_tax(); }
    public function get_total() { return $this->total; } public function get_total_tax() { return $this->tax; } public function get_shipping_total() { return $this->shipping; }
    public function get_items($type) { return $type === 'shipping' ? $this->shipping_items : $this->items; }
    public function get_order_number() { return 'TEST-' . $this->id; }
    public function save() { global $order_objects, $orders_created, $order_failure; if (!$this->id) $this->id = 100 + ++$orders_created; $order_objects[$this->id] = $this; if ($order_failure === 'after-save') throw new RuntimeException('Storage response unknown'); return $this->id; }
    public function update_status($status, $note) { global $order_events; $order_events[] = $status; $this->status = $status; }
    public function payment_complete() { throw new RuntimeException('Never mark a COD order paid'); }
}
$expected_quote = ReplyNextAI_Checkout::handle(new QuoteRequest($quote_payload), $options, 'checkout-quote')->data['quote'];
$create_payload = array_merge($quote_payload, array('expectedQuote' => $expected_quote, 'idempotencyKey' => str_repeat('a', 64), 'confirmed' => true));
function run_create($payload) { global $options; return ReplyNextAI_Checkout::handle(new QuoteRequest($payload, 'checkout-create'), $options, 'checkout-create'); }
if ('--signed-input' === $requested_mode) {
    $input = json_decode(stream_get_contents(STDIN), true); $request = new QuoteRequest(array());
    if (!empty($input['multiOptionsFixture'])) { $quote_test['extra_rate'] = true; $quote_test['cod_rate'] = 'flat_rate:2'; }
    $request->body = $input['body']; $request->timestamp = $input['timestamp']; $request->signature = $input['signature'];
    $result = ReplyNextAI_Checkout::handle($request, $options, $input['operation']);
    echo json_encode(array('status' => $result->status, 'body' => $result->data, 'ordersCreated' => $orders_created)); exit;
}
function reset_orders() { global $order_options, $order_objects, $orders_created, $order_events, $order_failure, $simulate_competitor; $order_options = array('woocommerce_manage_stock' => 'yes', 'woocommerce_hold_stock_minutes' => 60); $order_objects = array(); $orders_created = 0; $order_events = array(); $order_failure = ''; $simulate_competitor = false; }
function order_assert($condition, $message) { if (!$condition) { fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL); exit(1); } }
$before = array(WC()->cart, WC()->customer, WC()->session);
$result = run_create($create_payload);
order_assert($result->status === 200 && $result->data['success'] && $result->data['receipt']['status'] === 'on-hold', 'real provider receipt only after COD creation');
order_assert($orders_created === 1 && $order_events === array('reserve', 'on-hold', 'release'), 'native stock reservation precedes merchant-review transition');
order_assert(run_create($create_payload)->data === $result->data && $orders_created === 1, 'completed replay returns identical receipt without creating again');
order_assert(strpos(json_encode($order_options), '01700000000') === false && strpos(json_encode($order_options), 'Local test address') === false, 'durable ledger has no customer details');
$changed = $create_payload; $changed['customer']['billing_first_name'] = 'Different';
order_assert(run_create($changed)->status === 409 && $orders_created === 1, 'same key with changed request cannot create another order');
reset_orders(); $bad = $create_payload; $bad['confirmed'] = false; order_assert(run_create($bad)->status === 400 && $orders_created === 0, 'explicit confirmation required');
$bad = $create_payload; $bad['expectedQuote']['total'] = '1.00'; order_assert(run_create($bad)->data['code'] === 'QUOTE_CHANGED' && $orders_created === 0, 'stale/forged total requires new quote');
$bad = $create_payload; $bad['companyId'] = 99; order_assert(run_create($bad)->status === 401 && $orders_created === 0, 'cross-company creation rejected');
$request = new QuoteRequest($create_payload, 'checkout-quote'); order_assert(ReplyNextAI_Checkout::handle($request, $options, 'checkout-create')->status === 401, 'quote signature never authorizes create');
$order_failure = 'validation'; order_assert(run_create($create_payload)->status === 409 && $orders_created === 0, 'native merchant validation blocks creation');
reset_orders(); $simulate_competitor = true; order_assert(run_create($create_payload)->status === 503 && $orders_created === 0, 'concurrent durable claim blocks second worker');
foreach (array('after-save', 'stock-reservation', 'stock-not-reduced', 'ledger-final') as $failure) {
    reset_orders(); $order_failure = $failure;
    order_assert(run_create($create_payload)->status === 503 && $orders_created === 1, $failure . ' is not falsely reported as successful');
    $order_failure = ''; order_assert(run_create($create_payload)->status === 503 && $orders_created === 1, $failure . ' cannot be retried into a duplicate');
}
reset_orders(); $order_options['woocommerce_hold_stock_minutes'] = 0;
order_assert(run_create($create_payload)->status === 409 && $orders_created === 0, 'managed stock without native reservation fails closed');
order_assert(array(WC()->cart, WC()->customer, WC()->session) === $before, 'real session/cart restored after success and failures');
echo "PASS: signed COD creation, immutable confirmation, durable idempotency, native stock order and ambiguous-failure safety (runtime doubles)\n";
reset_orders();
$online = $create_payload;
$online['customer']['billing_email'] = 'demo@example.test';
$online['expectedQuote']['paymentMethod'] = 'unpaid';
$quote_test['cod'] = false;
$quote_test['online'] = true;
order_assert(run_create($online)->data['code'] === 'NATIVE_CHECKOUT_REQUIRED' && $orders_created === 0, 'existing installations cannot create online chat orders without separate consent');
$options['chat_payment_links_enabled'] = '1';
$requirements = ReplyNextAI_Checkout::handle(new QuoteRequest(array('companyId' => 12, 'siteUrl' => 'https://store.example'), 'checkout-requirements'), $options, 'checkout-requirements');
order_assert(!$requirements->data['codAvailable'] && $requirements->data['paymentMethod'] === 'unpaid' && array_column($requirements->data['fields'], 'required', 'id')['billing_email'], 'opted-in online checkout requires billing email');
$quote_test['online_available'] = false;
order_assert(run_create($online)->data['code'] === 'NATIVE_CHECKOUT_REQUIRED' && $orders_created === 0, 'disabled gateway in the live cart cannot create an unpaid order');
$quote_test['online_available'] = true;
$payment = run_create($online);
order_assert($payment->status === 200 && $payment->data['receipt']['status'] === 'pending' && strpos($payment->data['receipt']['paymentUrl'], '/order-pay/101/') !== false, 'online receipt uses the order-specific payment URL');
order_assert($order_events === array('reserve') && $orders_created === 1, 'online order reserves stock without COD or paid transition');
order_assert(run_create($online)->data === $payment->data && $orders_created === 1, 'payment receipt replay does not create a duplicate');
echo "PASS: opt-in, available gateway, pending order, order-specific payment URL and idempotent replay (runtime doubles)\n";

reset_orders();
$quote_test['cod'] = true; $quote_test['extra_rate'] = true; $quote_test['cod_rate'] = 'flat_rate:2';
$selected = $quote_payload + array('shippingRateId' => 'flat_rate:2', 'paymentMethod' => 'cod');
$review = ReplyNextAI_Checkout::handle(new QuoteRequest($selected), $options, 'checkout-quote')->data['quote'];
$create = $quote_payload + array('expectedQuote' => $review, 'idempotencyKey' => str_repeat('b', 64), 'confirmed' => true);
$quote_test['extra_rate'] = false;
order_assert(run_create($create)->data['code'] === 'NATIVE_CHECKOUT_REQUIRED' && $orders_created === 0, 'a stale rate cannot create an order or switch delivery');
$quote_test['extra_rate'] = true;
$quote_test['cod_rate'] = 'flat_rate:1';
order_assert(run_create($create)->data['code'] === 'NATIVE_CHECKOUT_REQUIRED' && $orders_created === 0, 'a stale gateway cannot be switched or submitted');
$quote_test['cod_rate'] = 'flat_rate:2';
$placed = run_create($create);
order_assert($placed->data['receipt']['status'] === 'on-hold' && $orders_created === 1 && wc_get_order(101)->get_items('shipping')[0]->get_instance_id() === 2, 'created COD order uses the exact reviewed shipping instance');
echo "PASS: verified shipping choice at creation; removed methods fail closed\n";
