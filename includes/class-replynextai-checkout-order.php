<?php
if (!defined('ABSPATH')) exit;

/** Invoked ONLY after the parent handler validates the operation-specific HMAC.
 * Ledger records contain hashes/receipt IDs, never billing addresses or tokens.
 * A processing claim never expires automatically: unknown writes need review.
 */
final class ReplyNextAI_Checkout_Order {
    private static function response($data, $status = 200) {
        $response = new WP_REST_Response($data, $status);
        $response->header('Cache-Control', 'no-store, private');
        return $response;
    }
    private static function unknown() { return self::response(array('success' => false, 'code' => 'ORDER_OUTCOME_UNKNOWN', 'error' => 'Order result requires review. Do not submit another order.'), 503); }
    private static function replay($record, $hash) {
        if (!is_array($record) || !isset($record['request_hash'], $record['state']) || !is_string($record['request_hash']) || !hash_equals($record['request_hash'], $hash)) return self::response(array('success' => false, 'code' => 'IDEMPOTENCY_CONFLICT', 'error' => 'Order confirmation does not match the original request.'), 409);
        if ('completed' !== $record['state']) return self::unknown();
        $receipt = isset($record['receipt']) ? $record['receipt'] : null;
        if (!is_array($receipt) || !isset($receipt['orderId'], $receipt['orderNumber'], $receipt['status']) || !is_string($receipt['orderId']) || !preg_match('/^[1-9][0-9]*$/D', $receipt['orderId']) || !is_string($receipt['orderNumber']) || !$receipt['orderNumber'] || strlen($receipt['orderNumber']) > 64 || preg_match('/[\x00-\x1f<>]/', $receipt['orderNumber']) || !in_array($receipt['status'], array('on-hold', 'processing', 'pending'), true)) return self::unknown();
        return self::response(array('success' => true, 'receipt' => array('orderId' => $receipt['orderId'], 'orderNumber' => $receipt['orderNumber'], 'status' => $receipt['status'])));
    }

    public static function handle($payload, $options, $quote_runner) {
        if (!isset($payload['lines'], $payload['customer'], $payload['expectedQuote'], $payload['idempotencyKey'], $payload['confirmed']) || true !== $payload['confirmed'] || !is_string($payload['idempotencyKey']) || !preg_match('/^[a-f0-9]{64}$/D', $payload['idempotencyKey']) || !is_array($payload['expectedQuote'])) return self::response(array('success' => false, 'code' => 'CONFIRMATION_REQUIRED', 'error' => 'Explicit order confirmation is required.'), 400);
        $key = 'replynextai_checkout_' . hash('sha256', $payload['companyId'] . '.' . $payload['siteUrl'] . '.' . $payload['idempotencyKey']);
        $hash = hash_hmac('sha256', wp_json_encode($payload, JSON_UNESCAPED_SLASHES), hash('sha256', $options['api_token']));
        $existing = get_option($key, false);
        if (false !== $existing) return self::replay($existing, $hash);
        return $quote_runner(static function ($quote, $cart, $checkout, $gateway) use ($payload, $key, $hash) {
            // Strict equality to the fresh quote: changed stock, rates, price,
            // tax or currency requires a new summary and customer confirmation.
            if (wp_json_encode($quote, JSON_UNESCAPED_SLASHES) !== wp_json_encode($payload['expectedQuote'], JSON_UNESCAPED_SLASHES)) return self::response(array('success' => false, 'code' => 'QUOTE_CHANGED', 'error' => 'Order details changed. Request a new quote and confirm it again.'), 409);
            if (!class_exists('WC_Order') || !function_exists('wc_reserve_stock_for_order') || !function_exists('wc_release_stock_for_order')) return self::unknown();
            // Never fall back to a non-atomic stock decrement for managed stock.
            $managed_stock = false;
            foreach ($cart->get_cart() as $item) if ($item['data']->managing_stock()) {
                $managed_stock = true;
                if (!apply_filters('woocommerce_hold_stock_for_checkout', 'yes' === get_option('woocommerce_manage_stock', 'yes')) || (int) get_option('woocommerce_hold_stock_minutes', 60) < 1) return self::response(array('success' => false, 'code' => 'NATIVE_CHECKOUT_REQUIRED', 'error' => 'Use the store checkout for this order.'), 409);
            }
            $record = array('request_hash' => $hash, 'state' => 'processing', 'order_id' => null);
            // add_option is backed by the unique option_name index. A duplicate
            // or DB failure cannot authorize another creation attempt.
            if (!add_option($key, $record, '', 'no')) return self::replay(get_option($key, false), $hash);
            try {
                $data = $payload['customer'];
                $data['payment_method'] = 'cod'; $data['shipping_method'] = $quote['shippingRateId'] ? array($quote['shippingRateId']) : array(); $data['ship_to_different_address'] = false;
                foreach ($payload['customer'] as $field => $value) if (!in_array($field, array('billing_phone', 'billing_email'), true)) $data[str_replace('billing_', 'shipping_', $field)] = $value;
                $errors = new WP_Error();
                foreach ($cart->get_cart() as $item) if (!apply_filters('woocommerce_add_to_cart_validation', true, $item['product_id'], $item['quantity'], $item['variation_id'], $item['variation'])) $errors->add('cart-invalid', 'Unsupported cart');
                do_action('woocommerce_after_checkout_validation', $data, $errors);
                if ($errors->has_errors() || wc_notice_count('error')) { $record['state'] = 'rejected'; update_option($key, $record, false); return self::response(array('success' => false, 'code' => 'NATIVE_CHECKOUT_REQUIRED', 'error' => 'Use the store checkout to complete this order.'), 409); }
                $order = new WC_Order();
                $billing = array();
                foreach ($payload['customer'] as $field => $value) $billing[substr($field, 8)] = trim($value);
                $billing['country'] = WC()->customer->get_billing_country();
                $order->set_address($billing, 'billing');
                $shipping = array_diff_key($billing, array('email' => true, 'phone' => true));
                $order->set_address($shipping, 'shipping');
                $order->set_customer_id(0); $order->set_currency($quote['currency']); $order->set_payment_method($gateway);
                $order->set_status('pending'); $order->set_prices_include_tax('yes' === get_option('woocommerce_prices_include_tax'));
                $checkout->set_data_from_cart($order);
                do_action('woocommerce_checkout_create_order', $order, $data);
                // Reassert attribution after extension hooks. No paid transition
                // or gateway process_payment() is called by this adapter.
                $order->set_created_via('replynext-ai');
                $order->update_meta_data('_replynext_checkout_key', $key);
                $order->update_meta_data('_replynext_checkout_hash', $hash);
                $order->update_meta_data('_replynext_company_id', $payload['companyId']);
                if (!self::order_matches($order, $quote) || $order->get_id() !== 0 || $order->get_customer_id() !== 0 || $order->get_payment_method() !== 'cod' || $order->get_status() !== 'pending' || $order->get_date_paid()) throw new RuntimeException('Order changed before persistence');
                $id = $order->save();
                if (!$id || !is_numeric($id)) throw new RuntimeException('Order persistence uncertain');
                $record['order_id'] = (int) $id;
                if (!update_option($key, $record, false)) throw new RuntimeException('Order ledger unavailable');
                $check = wc_get_order($id);
                if (!$check || $check->get_status() !== 'pending' || $check->get_date_paid()) throw new RuntimeException('Order state changed during persistence');
                // WooCommerce's native reservation is concurrency-safe. On-hold
                // triggers native stock handling; payment is due on delivery.
                wc_reserve_stock_for_order($order);
                $order->update_status('on-hold', 'ReplyNext AI COD order awaiting merchant review. Payment is due on delivery.');
                if ($managed_stock && !$order->get_data_store()->get_stock_reduced($id)) throw new RuntimeException('Native stock handling incomplete');
                wc_release_stock_for_order($order);
                $persisted = wc_get_order($id);
                if (!$persisted || !self::order_matches($persisted, $quote) || $persisted->get_created_via() !== 'replynext-ai' || $persisted->get_meta('_replynext_checkout_key') !== $key || $persisted->get_status() !== 'on-hold') throw new RuntimeException('Order result uncertain');
                $number = (string) $persisted->get_order_number();
                if (!$number || strlen($number) > 64 || preg_match('/[\x00-\x1f<>]/', $number)) throw new RuntimeException('Invalid order reference');
                $record['state'] = 'completed'; $record['receipt'] = array('orderId' => (string) $id, 'orderNumber' => $number, 'status' => 'on-hold');
                if (!update_option($key, $record, false)) return self::unknown();
                return self::replay($record, $hash);
            } catch (Throwable $ignored) {
                // Do not delete the claim, retry, refund or cancel an order after
                // an ambiguous failure. It may already have been persisted.
                return self::unknown();
            }
        });
    }

    private static function order_matches($order, $quote) {
        if ($order->get_currency() !== $quote['currency'] || wc_format_decimal($order->get_total(), 2) !== $quote['total'] || wc_format_decimal($order->get_shipping_total(), 2) !== $quote['shipping'] || wc_format_decimal($order->get_total_tax(), 2) !== $quote['tax']) return false;
        $actual = array();
        foreach ($order->get_items('line_item') as $item) $actual[] = array('productId' => (string) $item->get_product_id(), 'variationId' => $item->get_variation_id() ? (string) $item->get_variation_id() : null, 'quantity' => (int) $item->get_quantity(), 'total' => wc_format_decimal($item->get_total(), 2));
        $expected = array();
        foreach ($quote['lines'] as $line) $expected[] = array('productId' => $line['productId'], 'variationId' => $line['variationId'], 'quantity' => $line['quantity'], 'total' => $line['total']);
        return $actual === $expected;
    }
}
