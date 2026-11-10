<?php
define('ABSPATH', '/test/');
function sanitize_text_field($value) { return strip_tags((string) $value); }
function wc_get_product($id) { global $products; return isset($products[$id]) ? $products[$id] : false; }
function wc_attribute_label($name) { return $name === 'pa_color' ? 'Color' : $name; }
function taxonomy_exists($name) { return $name === 'pa_color'; }
function get_term_by($field, $value, $taxonomy) { return (object) array('name' => 'Red'); }
function is_wp_error($value) { return false; }
require_once __DIR__ . '/../includes/class-replynextai-plugin.php';
class StockProduct {
    public $quantity = 7; public $managed = true; public $owner = 1; public $id = 1;
    function get_id() { return $this->id; } function get_attributes() { return array(); }
    function is_type($type) { return false; } function get_name() { return 'Shirt'; }
    function get_status() { return 'publish'; } function is_in_stock() { return true; }
    function get_type() { return 'simple'; }
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
$live = $method->invoke($plugin, $product, 'https://store.example', array('variants'));
check($live['productType'] === 'simple' && $live['optionNames'] === array() && $live['variants'][0]['selectedOptions'] === array());
class ChoiceAttribute {
    private $variation;
    function __construct($variation) { $this->variation = $variation; }
    function get_variation() { return $this->variation; }
    function get_name() { return $this->variation ? 'pa_color' : 'Material'; }
}
class VariableStockProduct extends StockProduct {
    function is_type($type) { return $type === 'variable'; }
    function get_type() { return 'variable'; }
    function get_attributes() { return array(new ChoiceAttribute(true), new ChoiceAttribute(false)); }
    function get_children() { return array(4); }
}
class VariationStockProduct extends StockProduct {
    function get_attributes() { return array('pa_color' => 'red'); }
}
$variable = new VariableStockProduct(); $variable->id = 3;
$variation = new VariationStockProduct(); $variation->id = 4; $variation->owner = 4; $products[4] = $variation;
$live = $method->invoke($plugin, $variable, 'https://store.example', array('variants'));
check($live['productType'] === 'variable' && $live['optionNames'] === array('Color'));
check($live['variants'][0]['selectedOptions'] === array(array('name' => 'Color', 'value' => 'Red')));
echo "PASS: live tracked quantity, zero, unknown, invalid and shared-parent stock\n";
