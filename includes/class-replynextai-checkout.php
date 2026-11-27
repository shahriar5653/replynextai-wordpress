<?php
if (!defined('ABSPATH')) exit;

/** Signed classic-checkout runtime. Quotes are read-only; the separate create
 * handler requires a confirmed, operation-specific signed request and ledger.
 * Unsupported checkout rules fail closed. No persistent customer cart is used.
 */
final class ReplyNextAI_Checkout {
    const FIELDS = array('billing_first_name', 'billing_last_name', 'billing_phone', 'billing_email', 'billing_address_1', 'billing_address_2', 'billing_city', 'billing_state', 'billing_postcode', 'billing_country');

    private static function response($data, $status = 200) {
        $response = new WP_REST_Response($data, $status);
        $response->header('Cache-Control', 'no-store, private');
        return $response;
    }

    public static function handle($request, $options, $operation) {
        $raw = $request->get_body();
        $payload = $request->get_json_params();
        $timestamp = (string) $request->get_header('x-replynext-timestamp');
        $signature = (string) $request->get_header('x-replynext-signature');
        $parts = wp_parse_url(home_url('/'));
        $origin = is_array($parts) && isset($parts['scheme'], $parts['host']) && 'https' === $parts['scheme'] ? 'https://' . strtolower($parts['host']) . (isset($parts['port']) && 443 !== (int) $parts['port'] ? ':' . (int) $parts['port'] : '') : '';
        if (!in_array($operation, array('checkout-requirements', 'checkout-options', 'checkout-quote', 'checkout-create'), true) || !$origin || empty($options['api_token']) || strlen($raw) > 16384 || !is_array($payload) || !isset($payload['companyId'], $payload['siteUrl']) || !is_int($payload['companyId']) || $payload['companyId'] !== (int) $options['company_id'] || $payload['companyId'] < 1 || $payload['siteUrl'] !== $origin || !preg_match('/^[0-9]{13}$/D', $timestamp) || abs(microtime(true) * 1000 - (float) $timestamp) >= 60000 || !preg_match('/^[a-f0-9]{64}$/D', $signature)) {
            return self::response(array('success' => false, 'error' => 'Unauthorized checkout request'), 401);
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $operation . '.' . hash('sha256', $raw), hash('sha256', $options['api_token']));
        if (!hash_equals($expected, $signature)) return self::response(array('success' => false, 'error' => 'Unauthorized checkout request'), 401);
        // Translate the shopper's district/state name only after verifying the
        // signed request. WooCommerce stores the canonical code, not its label.
        if (isset($payload['customer']) && is_array($payload['customer'])) {
            $payload['customer'] = self::normalize_customer_state($payload['customer']);
        }
        $fields_by_operation = array(
            'checkout-create' => array('lines', 'customer', 'expectedQuote', 'idempotencyKey', 'confirmed'),
            'checkout-quote' => array('lines', 'customer', 'shippingRateId', 'paymentMethod'),
            'checkout-options' => array('lines', 'customer', 'shippingRateId'),
            'checkout-requirements' => array('country', 'paymentMethod'),
        );
        $allowed = array_merge(array('companyId', 'siteUrl'), $fields_by_operation[$operation]);
        if (array_diff(array_keys($payload), $allowed)) return self::response(array('success' => false, 'error' => 'Invalid checkout request'), 400);
        try {
            if ('checkout-create' === $operation) {
                require_once __DIR__ . '/class-replynextai-checkout-order.php';
                return ReplyNextAI_Checkout_Order::handle($payload, $options, static function ($continuation) use ($payload, $options) {
                    $quote_payload = $payload;
                    $reviewed = $payload['expectedQuote'];
                    if (true === ($reviewed['optionsSelected'] ?? false)) {
                        $quote_payload['paymentMethod'] = $reviewed['paymentMethod'] ?? null;
                        if ('' !== ($reviewed['shippingRateId'] ?? '')) $quote_payload['shippingRateId'] = $reviewed['shippingRateId'];
                    }
                    return self::runtime($quote_payload, 'checkout-quote', $continuation, $options);
                });
            }
            return self::runtime($payload, $operation, null, $options);
        } catch (Throwable $ignored) {
            return self::response(array('success' => false, 'error' => 'Checkout operation unavailable'), 503);
        }
    }

    private static function runtime($payload, $operation, $continuation, $options) {
            if (array_key_exists('paymentMethod', $payload) && !in_array($payload['paymentMethod'], array('cod', 'unpaid'), true)) return self::unsupported();
            if (array_key_exists('shippingRateId', $payload) && (!self::valid_rate_id($payload['shippingRateId']) || ('checkout-quote' === $operation && !isset($payload['paymentMethod'])))) return self::unsupported();
            if (!class_exists('WC_Gateway_COD') && defined('WC_ABSPATH') && is_readable(WC_ABSPATH . 'includes/gateways/cod/class-wc-gateway-cod.php')) require_once WC_ABSPATH . 'includes/gateways/cod/class-wc-gateway-cod.php';
            if (!function_exists('WC') || !class_exists('WC_Cart') || !class_exists('WC_Checkout') || !class_exists('WC_Session') || !class_exists('WC_Customer') || !class_exists('WC_Gateway_COD') || is_user_logged_in() || 2 !== (int) wc_get_price_decimals()) return self::unsupported();
            // Block checkout extensions have a separate field system; do not
            // pretend the classic field API covers those requirements.
            $page = get_post((int) get_option('woocommerce_checkout_page_id'));
            if (!$page || has_block('woocommerce/checkout', $page) || (function_exists('wc_terms_and_conditions_checkbox_enabled') && wc_terms_and_conditions_checkbox_enabled())) return self::unsupported();
            return self::isolated($payload, $operation, $continuation, $options);
    }

    private static function unsupported() {
        return self::response(array('success' => true, 'supported' => false, 'code' => 'NATIVE_CHECKOUT_REQUIRED'));
    }

    private static function valid_rate_id($id) {
        return is_string($id) && strlen($id) <= 128 && preg_match('/^[a-z0-9_-]+(?::[a-z0-9_-]+)?$/D', $id);
    }

    private static function gateways($available) {
        if (!is_callable(array(WC(), 'payment_gateways'))) return array();
        $registry = WC()->payment_gateways();
        return (array) ($available ? $registry->get_available_payment_gateways() : $registry->payment_gateways());
    }

    private static function payment_titles($gateways, $legacy = false) {
        $titles = array();
        foreach ($gateways as $id => $gateway) {
            if ('cod' === $id || ($legacy && in_array($id, array('bacs', 'cheque'), true)) || 'yes' !== ($gateway->enabled ?? 'no')) continue;
            $title = sanitize_text_field(is_callable(array($gateway, 'get_title')) ? $gateway->get_title() : ($gateway->title ?? ''));
            if ('' === $title || strlen($title) > 120) continue;
            $titles[] = $title;
        }
        $titles = array_values(array_unique($titles));
        return count($titles) <= 12 ? $titles : array();
    }

    private static function choices_response($shipping_required, $rates, $selected, $payments) {
        return self::response(array('success' => true, 'supported' => true, 'options' => array(
            'shippingRequired' => $shipping_required, 'shippingRates' => $rates, 'selectedRateId' => $selected, 'paymentChoices' => $payments,
        )));
    }

    private static function remove_hooks($object) {
        global $wp_filter;
        if (!$object || !is_array($wp_filter)) return;
        foreach ($wp_filter as $tag => $hook) {
            if (!is_object($hook) || !isset($hook->callbacks)) continue;
            foreach ($hook->callbacks as $priority => $callbacks) foreach ($callbacks as $callback) {
                $function = $callback['function'];
                if (is_array($function) && isset($function[0]) && $function[0] === $object) remove_filter($tag, $function, $priority);
            }
        }
    }

    private static function isolated($payload, $operation, $continuation, $options) {
        $wc = WC();
        $previous = array('cart' => $wc->cart, 'customer' => $wc->customer, 'session' => $wc->session);
        $shipping = $wc->shipping();
        $previous_shipping = array('packages' => $shipping->packages, 'shipping_methods' => $shipping->shipping_methods, 'shipping_classes' => $shipping->shipping_classes);
        $cart = null; $checkout = null; $gateway = null;
        // Session handlers are never initialized: this object only holds data
        // in memory and cannot save a session or emit a session cookie.
        $session = new class extends WC_Session {
            public function has_session() { return false; }
            public function set_customer_session_cookie($set) {}
            public function save_data($old_session_key = 0) {}
        };
        $disable_session_hooks = static function () { return false; };
        try {
            $wc->session = $session;
            $wc->customer = new WC_Customer(0, false);
            $shipping->packages = array(); $shipping->shipping_methods = null;
            $customer = isset($payload['customer']) && is_array($payload['customer']) ? $payload['customer'] : array();
            $base = wc_get_base_location();
            $country = isset($payload['country']) ? $payload['country'] : (isset($customer['billing_country']) ? $customer['billing_country'] : $base['country']);
            if (!is_string($country) || !preg_match('/^[A-Z]{2}$/D', $country) || !isset($wc->countries->get_allowed_countries()[$country])) return self::unsupported();
            $wc->customer->set_billing_country($country); $wc->customer->set_shipping_country($country);
            foreach ($customer as $key => $value) {
                if (!in_array($key, self::FIELDS, true) || !is_string($value) || strlen($value) > 255 || preg_match('/[\x00-\x1f<>]/', $value)) return self::response(array('success' => false, 'error' => 'Invalid checkout information'), 400);
                $setter = 'set_' . $key;
                $wc->customer->$setter(trim($value));
                $shipping_setter = 'set_' . str_replace('billing_', 'shipping_', $key);
                if (is_callable(array($wc->customer, $shipping_setter))) $wc->customer->$shipping_setter(trim($value));
            }
            $wc->customer->set_calculated_shipping(true);
            $checkout = new WC_Checkout();
            if ($checkout->is_registration_required()) return self::unsupported();
            $fields = self::fields($checkout->get_checkout_fields());
            if (false === $fields) return self::unsupported();
            $gateway = new WC_Gateway_COD();
            $cod = 'yes' === $gateway->enabled;
            $selected_payment = $payload['paymentMethod'] ?? null;
            $show_options = 'checkout-options' === $operation;
            $modern = $show_options || null !== $selected_payment;
            // An existing merchant's consent was only for COD-disabled stores.
            // Offering both requires the separate explicit WordPress opt-in.
            $links_enabled = '1' === ($options['chat_payment_links_enabled'] ?? '0') && (!$cod || '1' === ($options['chat_payment_links_with_cod'] ?? '0'));
            $configured = $links_enabled ? self::gateways(false) : array();
            $website_payment = (bool) self::payment_titles($configured);
            $unpaid = 'unpaid' === $selected_payment || (!$cod && null === $selected_payment && $website_payment);
            if ((!$cod && !$website_payment) || ('unpaid' === $selected_payment && !$website_payment) || ('cod' === $selected_payment && !$cod)) return self::unsupported();
            if ($unpaid) {
                foreach ($fields as &$field) if ('billing_email' === $field['id']) $field['required'] = true;
                unset($field);
                if (!in_array('billing_email', array_column($fields, 'id'), true)) $fields[] = array('id' => 'billing_email', 'required' => true);
            }
            if ('checkout-requirements' === $operation) return self::response(array('success' => true, 'supported' => true, 'fields' => $fields, 'codAvailable' => $cod, 'checkoutOptions' => true) + ($website_payment ? array('onlinePaymentAvailable' => true) : array()) + ($unpaid ? array('paymentMethod' => 'unpaid') : array()));
            if (!$modern && $unpaid && !self::payment_titles($configured, true)) return self::unsupported();
            if (!isset($payload['lines'], $payload['customer']) || !is_array($payload['customer'])) return self::response(array('success' => false, 'error' => 'Invalid checkout information'), 400);
            foreach ($fields as $field) if ($field['required'] && (!isset($customer[$field['id']]) || !strlen(trim($customer[$field['id']])))) return self::response(array('success' => true, 'supported' => false, 'code' => 'CUSTOMER_INFORMATION_REQUIRED', 'fields' => $fields));
            if (!self::valid_address($customer, $country)) return self::response(array('success' => false, 'error' => 'Invalid checkout information'), 400);
            $lines = self::products($payload['lines']);
            if (false === $lines) return self::unsupported();
            add_filter('woocommerce_cart_session_initialize', $disable_session_hooks, PHP_INT_MAX);
            try { $cart = new WC_Cart(); } finally { remove_filter('woocommerce_cart_session_initialize', $disable_session_hooks, PHP_INT_MAX); }
            $wc->cart = $cart;
            // Populate a fresh cart directly: never fire add_to_cart hooks that
            // can update a real user's persistent cart or customer metadata.
            $cart->set_cart_contents($lines);
            // Reject unsupported shipping providers BEFORE their rate callbacks
            // run (those callbacks can make remote carrier requests).
            $shipping_required = $cart->needs_shipping();
            $requested_rate = $payload['shippingRateId'] ?? null;
            if (!$shipping_required && null !== $requested_rate) return self::unsupported();
            if ($shipping_required) {
                if (!isset($wc->countries->get_shipping_countries()[$country])) return $show_options && null === $requested_rate ? self::choices_response(true, array(), null, array()) : self::unsupported();
                $packages = $cart->get_shipping_packages();
                if (count($packages) !== 1 || !class_exists('WC_Shipping_Zones')) return self::unsupported();
                $package = reset($packages);
                $methods = WC_Shipping_Zones::get_zone_matching_package($package)->get_shipping_methods(true);
                if (!$methods && $show_options && null === $requested_rate) return self::choices_response(true, array(), null, array());
                if (!$methods || count($methods) > 20 || (!$modern && count($methods) !== 1)) return self::unsupported();
                $method_classes = $modern ? array('WC_Shipping_Flat_Rate', 'WC_Shipping_Free_Shipping', 'WC_Shipping_Local_Pickup') : array('WC_Shipping_Flat_Rate', 'WC_Shipping_Free_Shipping');
                foreach ($methods as $method) if (!in_array(get_class($method), $method_classes, true)) return self::unsupported();
            }
            $cart->calculate_totals();
            if ((float) $cart->get_fee_total() !== 0.0 || (float) $cart->get_discount_total() !== 0.0 || count($cart->get_cart()) !== count($lines)) return self::unsupported();
            $rate_id = '';
            $rate_title = '';
            $shipping_rates = array();
            if ($shipping_required) {
                $packages = $shipping->get_packages();
                if (count($packages) !== 1) return self::unsupported();
                $package = reset($packages);
                $rates = $package['rates'] ?? array();
                if (!$rates && $show_options && null === $requested_rate) return self::choices_response(true, array(), null, array());
                if (!$rates || count($rates) > 20 || (!$modern && count($rates) !== 1)) return self::unsupported();
                $method_ids = $modern ? array('flat_rate', 'free_shipping', 'local_pickup') : array('flat_rate', 'free_shipping');
                foreach ($rates as $rate) {
                    $id = (string) $rate->get_id();
                    if (!in_array($rate->get_method_id(), $method_ids, true) || !self::valid_rate_id($id) || isset($shipping_rates[$id])) return self::unsupported();
                    // Read legacy quotes without changing their persisted shape.
                    $title = $modern ? sanitize_text_field($rate->get_label()) : '';
                    if ($modern && ('' === $title || strlen($title) > 120)) return self::unsupported();
                    $shipping_rates[$id] = array('id' => $id, 'title' => $title, 'price' => $modern ? self::money($rate->get_cost()) : '', 'currency' => get_woocommerce_currency());
                }
                if ($show_options && null === $requested_rate && count($shipping_rates) > 1) return self::choices_response(true, array_values($shipping_rates), null, array());
                // A modern quote/order must carry the exact reviewed shipping ID.
                if ($modern && !$show_options && null === $requested_rate) return self::unsupported();
                $rate_id = $requested_rate ?? array_key_first($shipping_rates);
                if (!isset($shipping_rates[$rate_id])) return self::unsupported();
                $rate_title = $shipping_rates[$rate_id]['title'];
                $session->set('chosen_shipping_methods', array($rate_id));
                $cart->calculate_totals();
                if (!$cart->has_calculated_shipping() || ($modern && array($rate_id) !== $session->get('chosen_shipping_methods'))) return self::unsupported();
            }
            if ((float) $cart->get_fee_total() !== 0.0 || (float) $cart->get_discount_total() !== 0.0 || count($cart->get_cart()) !== count($lines)) return self::unsupported();
            if ($modern) {
                $available = self::gateways(true);
                $payments = array();
                $cod_title = isset($available['cod']) ? sanitize_text_field($available['cod']->get_title()) : '';
                if ($cod && isset($available['cod']) && $gateway->is_available() && '' !== $cod_title && strlen($cod_title) <= 120) $payments['cod'] = array('id' => 'cod', 'methods' => array($cod_title));
                $titles = $website_payment && (float) $cart->get_total('edit') > 0 ? self::payment_titles($available) : array();
                if ($titles) $payments['unpaid'] = array('id' => 'unpaid', 'methods' => $titles);
                if ($show_options) return self::choices_response($shipping_required, array_values($shipping_rates), $rate_id ?: null, array_values($payments));
                if (!isset($payments[$selected_payment])) return self::unsupported();
            } elseif ((!$unpaid && (!$cod || !$gateway->is_available())) || ($unpaid && (!self::payment_titles(self::gateways(true), true) || (float) $cart->get_total('edit') <= 0))) return self::unsupported();
            $quoted = array();
            foreach ($cart->get_cart() as $key => $item) {
                if (!isset($lines[$key]) || $item['quantity'] !== $lines[$key]['quantity'] || $item['product_id'] !== $lines[$key]['product_id'] || $item['variation_id'] !== $lines[$key]['variation_id']) return self::unsupported();
                $quoted[] = array('productId' => (string) $item['product_id'], 'variationId' => $item['variation_id'] ? (string) $item['variation_id'] : null, 'quantity' => $item['quantity'], 'title' => sanitize_text_field($item['data']->get_name()), 'total' => self::money($item['line_total']));
            }
            $quote = array('currency' => get_woocommerce_currency(), 'lines' => $quoted, 'subtotal' => self::money($cart->get_cart_contents_total()), 'shipping' => self::money($cart->get_shipping_total()), 'tax' => self::money($cart->get_total_tax()), 'total' => self::money($cart->get_total('edit')), 'shippingRateId' => $rate_id)
                + ($rate_title ? array('shippingRateTitle' => $rate_title) : array()) + array('paymentMethod' => $unpaid ? 'unpaid' : 'cod') + ($modern ? array('optionsSelected' => true) : array());
            return $continuation ? $continuation($quote, $cart, $checkout, $gateway) : self::response(array('success' => true, 'supported' => true, 'quote' => $quote));
        } finally {
            self::remove_hooks($cart); self::remove_hooks($checkout); self::remove_hooks($gateway);
            $wc->cart = $previous['cart']; $wc->customer = $previous['customer']; $wc->session = $previous['session'];
            foreach ($previous_shipping as $key => $value) $shipping->$key = $value;
        }
    }

    private static function fields($groups) {
        $fields = array();
        foreach ($groups as $group => $items) foreach ($items as $id => $field) {
            if ('account' === $group) continue; // Guest checkout is required above.
            if ('order_comments' === $id && empty($field['required'])) continue;
            if (in_array($id, array('billing_company', 'shipping_company'), true) && empty($field['required'])) continue;
            $mapped = 'shipping' === $group ? str_replace('shipping_', 'billing_', $id) : $id;
            if (!in_array($mapped, self::FIELDS, true) || !in_array(isset($field['type']) ? $field['type'] : 'text', array('text', 'email', 'tel', 'country', 'state'), true)) return false;
            $required = !empty($field['required']);
            $fields[$mapped] = array('id' => $mapped, 'required' => $required || (!empty($fields[$mapped]['required'])));
        }
        return array_values($fields);
    }

    private static function valid_address($customer, $country) {
        if (isset($customer['billing_email']) && '' !== $customer['billing_email'] && !is_email($customer['billing_email'])) return false;
        if (isset($customer['billing_phone']) && '' !== $customer['billing_phone'] && !WC_Validation::is_phone($customer['billing_phone'])) return false;
        $states = WC()->countries->get_states($country);
        if (is_array($states) && count($states) && (!isset($customer['billing_state']) || !isset($states[$customer['billing_state']]))) return false;
        if (!empty($customer['billing_postcode']) && !WC_Validation::is_postcode($customer['billing_postcode'], $country)) return false;
        return true;
    }

    private static function normalize_customer_state($customer) {
        if (!isset($customer['billing_country'], $customer['billing_state']) || !is_string($customer['billing_country']) || !is_string($customer['billing_state']) || !function_exists('WC')) return $customer;
        $countries = WC()->countries;
        if (!$countries || !is_callable(array($countries, 'get_states'))) return $customer;
        $states = $countries->get_states($customer['billing_country']);
        if (!is_array($states) || !$states) return $customer;
        $input = trim($customer['billing_state']);
        if (isset($states[$input])) return $customer;
        $fold = static function ($value) {
            $value = trim((string) $value);
            if (function_exists('remove_accents')) $value = remove_accents($value);
            return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        };
        $needle = $fold($input);
        $matches = array();
        foreach ($states as $code => $label) {
            if ($needle === $fold($code) || $needle === $fold($label)) $matches[] = $code;
        }
        // Never guess when translated labels collide. Normal validation will
        // reject the unresolved value and keep the order unsubmitted.
        if (1 === count($matches)) $customer['billing_state'] = (string) $matches[0];
        return $customer;
    }

    private static function products($requested) {
        if (!is_array($requested) || count($requested) < 1 || count($requested) > 10 || array_keys($requested) !== range(0, count($requested) - 1)) return false;
        $items = array(); $stock = array();
        foreach ($requested as $line) {
            if (!is_array($line) || array_diff(array_keys($line), array('productId', 'variationId', 'quantity')) || !isset($line['productId'], $line['quantity']) || !is_string($line['productId']) || !preg_match('/^[1-9][0-9]{0,17}$/D', $line['productId']) || !is_int($line['quantity']) || $line['quantity'] < 1 || $line['quantity'] > 20) return false;
            if ((float) $line['productId'] > PHP_INT_MAX) return false;
            $id = (int) $line['productId']; $variation_id = isset($line['variationId']) ? $line['variationId'] : null;
            if (null !== $variation_id && (!is_string($variation_id) || !preg_match('/^[1-9][0-9]{0,17}$/D', $variation_id) || (float) $variation_id > PHP_INT_MAX)) return false;
            $parent = wc_get_product($id);
            if (!$parent || 'publish' !== $parent->get_status() || !$parent->is_visible() || post_password_required($id)) return false;
            $product = $variation_id ? wc_get_product((int) $variation_id) : $parent;
            if (!$product || ($variation_id ? (!$parent->is_type('variable') || !$product->is_type('variation') || $product->get_parent_id() !== $id || in_array('', $product->get_variation_attributes(), true)) : !$product->is_type('simple')) || 'publish' !== $product->get_status() || !$product->is_purchasable() || !$product->is_in_stock() || $product->backorders_allowed() || ($product->is_sold_individually() && $line['quantity'] !== 1)) return false;
            $stock_id = $product->get_stock_managed_by_id();
            $stock[$stock_id] = isset($stock[$stock_id]) ? $stock[$stock_id] + $line['quantity'] : $line['quantity'];
            $owner = wc_get_product($stock_id);
            if (!$owner || ($owner->is_sold_individually() && $stock[$stock_id] > 1) || !function_exists('wc_get_held_stock_quantity') || !$owner->has_enough_stock($stock[$stock_id] + max(0, wc_get_held_stock_quantity($owner)))) return false;
            $key = $id . ':' . (string) $variation_id;
            if (isset($items[$key])) return false;
            $items[$key] = array('key' => $key, 'product_id' => $id, 'variation_id' => $variation_id ? (int) $variation_id : 0, 'variation' => $variation_id ? $product->get_variation_attributes() : array(), 'quantity' => $line['quantity'], 'data' => $product, 'data_hash' => wc_get_cart_item_data_hash($product), 'line_total' => 0, 'line_subtotal' => 0);
        }
        return $items;
    }

    private static function money($value) {
        $amount = wc_format_decimal($value, 2);
        if (!is_string($amount) || !preg_match('/^(0|[1-9][0-9]{0,9})\.[0-9]{2}$/D', $amount)) throw new RuntimeException('Unsupported checkout amount');
        return $amount;
    }
}
