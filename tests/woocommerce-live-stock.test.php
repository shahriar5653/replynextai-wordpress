<?php
define('ABSPATH', '/test/');
function sanitize_text_field($value) { return strip_tags((string) $value); }
function wc_get_product($id) { global $products; return isset($products[$id]) ? $products[$id] : false; }
require_once __DIR__ . '/../includes/class-replynextai-plugin.php';
class StockProduct {
    public $quantity = 7; public $managed = true; public $owner = 1; public $id = 1;
    function get_id() { return $this->id; } function get_attributes() { return array(); }
    function is_type($type) { return false; } function get_name() { return 'Shirt'; }
    function get_status() { return 'publish'; } function is_in_stock() { return true; }
    function is_purchasable() { return true; } function get_stock_managed_by_id() { return $this->owner; }
    function managing_stock() { return $this->managed; } function get_stock_quantity() { return $this->quantity; }
}
$plugin = (new ReflectionClass('ReplyNextAI_Plugin'))->newInstanceWithoutConstructor();
$method = new ReflectionMethod($plugin, 'live_woocommerce_product');
$product = new StockProduct(); $products = array(1 => $product);
function row($facts = array('availability')) { global $method, $plugin, $product; return $method->invoke($plugin, $product, 'https://store.example', $facts)['variants'][0]; }
function check($condition) { if (!$condition) { fwrite(STDERR, "FAIL: live stock boundary\n"); exit(1); } }
check(row()['stockQuantity'] === 7 && row()['stockScope'] === 'variant');
$product->quantity = 0; check(row()['stockQuantity'] === 0);
$product->managed = false; check(!isset(row()['stockQuantity']));
$product->managed = true; $product->quantity = null; check(!isset(row()['stockQuantity']));
$product->quantity = -1; check(!isset(row()['stockQuantity']));
$product->quantity = 2.5; check(!isset(row()['stockQuantity']));
$parent = new StockProduct(); $parent->id = 2; $parent->quantity = 20; $products[2] = $parent; $product->owner = 2;
check(row()['stockQuantity'] === 20 && row()['stockScope'] === 'shared_parent');
check(!isset(row(array())['stockQuantity']));
echo "PASS: live tracked quantity, zero, unknown, invalid and shared-parent stock\n";
