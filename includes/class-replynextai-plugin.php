<?php

if (!defined('ABSPATH')) {
    exit;
}

final class ReplyNextAI_Plugin {
    const OPTION_KEY = 'replynextai_options';
    const LEGACY_OPTION_KEY = 'replynextai_chat_options';
    const AI_HANDLE = 'replynextai-ai-widget';

    private static $instance = null;
    private $ai_config = null;
    private $whatsapp_rendered = false;
    private $last_widget_provision_error = '';
    /** Set only during the server-authenticated connection test, never from a settings form. */
    private $verified_connection_company_id = null;

    const CATALOG_LOCK_OPTION = 'replynextai_catalog_sync_lock';
    const CATALOG_STATE_TRANSIENT = 'replynextai_catalog_sync_state';
    const CATALOG_CHECKPOINT_OPTION = 'replynextai_catalog_sync_checkpoint';
    const CATALOG_PROGRESS_TRANSIENT = 'replynextai_catalog_sync_progress';
    const CATALOG_DIRTY_TRANSIENT = 'replynextai_catalog_dirty';
    const CATALOG_QUEUED_OPTION = 'replynextai_catalog_sync_queued';
    const CHAT_PAYMENT_LINKS_OPTION = 'replynextai_chat_payment_links_enabled';
    const CHAT_PAYMENT_LINKS_WITH_COD_OPTION = 'replynextai_chat_payment_links_with_cod';
    const PROVISION_LOCK_OPTION = 'replynextai_widget_provision_lock';

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function activate() {
        $existing = get_option(self::OPTION_KEY, array());
        if (empty($existing)) {
            $legacy = get_option(self::LEGACY_OPTION_KEY, array());
            update_option(self::OPTION_KEY, wp_parse_args($legacy, self::defaults()));
        }
        set_transient('replynextai_activation_redirect', '1', 30);
    }

    public static function defaults() {
        return array(
            'server_url'          => 'https://replynextai.com',
            'company_id'          => '',
            'api_token'           => '',
            'ai_enabled'          => '0',
            'ai_sitewide'         => '0',
            'share_user_data'     => '0',
            'contact_enabled'     => '1',
            'contact_label'       => 'Contact Us',
            'contact_show_label'  => '1',
            'contact_color'       => '#6949D7',
            'contact_text_color'  => '#FFFFFF',
            'contact_icon_id'     => '0',
            'whatsapp_enabled'    => '0',
            'whatsapp_number'     => '',
            'whatsapp_message'    => 'Hello! I found you through your website.',
            'whatsapp_label'      => 'Chat on WhatsApp',
            'whatsapp_icon_id'    => '0',
            'whatsapp_show_label' => '1',
            'whatsapp_text_color' => '#FFFFFF',
            'whatsapp_color'      => '#25D366',
            'whatsapp_position'   => 'left',
            'whatsapp_delay'      => '0',
            'messenger_enabled'   => '0',
            'messenger_url'       => '',
            'messenger_label'     => 'Message on Messenger',
            'messenger_icon_id'   => '0',
            'messenger_show_label'=> '1',
            'messenger_color'     => '#1877F2',
            'messenger_text_color'=> '#FFFFFF',
            'call_enabled'        => '0',
            'call_number'         => '',
            'call_label'          => 'Call us',
            'phone_icon_id'      => '0',
            'call_show_label'     => '1',
            'call_color'          => '#334155',
            'call_text_color'     => '#FFFFFF',
            'show_on'             => 'all',
            'include_pages'       => '',
            'exclude_pages'       => '',
            'hide_mobile'         => '0',
            'hide_desktop'        => '0',
        );
    }

    private function __construct() {
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_init', array($this, 'activation_redirect'));
        add_action('admin_init', array($this, 'redirect_legacy_connection_page'));
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'admin_assets'));
        add_action('admin_post_replynextai_test_connection', array($this, 'handle_test_connection'));
        add_action('admin_post_replynextai_refresh_widget_connection', array($this, 'handle_refresh_widget_connection'));
        add_action('admin_post_replynextai_sync_products', array($this, 'handle_sync_products'));
        add_action('wp_ajax_replynextai_sync_products', array($this, 'ajax_sync_products'));
        add_action('wp_ajax_replynextai_sync_products_status', array($this, 'ajax_sync_products_status'));
        add_action('wp_ajax_replynextai_sync_products_step', array($this, 'ajax_sync_products_step'));
        add_action('save_post_product', array($this, 'schedule_product_sync'), 20, 3);
        add_action('replynextai_deferred_product_sync', array($this, 'sync_products'));
        add_action('rest_api_init', array($this, 'register_commerce_routes'));
        add_action('woocommerce_order_status_changed', array($this, 'send_chat_order_status_update'), 10, 3);
        add_action('woocommerce_payment_complete', array($this, 'send_chat_payment_confirmation'), 10, 1);
        add_filter('woocommerce_order_actions', array($this, 'add_chat_shipment_action'), 10, 2);
        add_action('woocommerce_order_action_replynextai_confirm_shipment', array($this, 'confirm_chat_shipment'));
        add_filter('plugin_action_links_' . plugin_basename(REPLYNEXTAI_FILE), array($this, 'plugin_links'));
        add_action('wp_enqueue_scripts', array($this, 'frontend_assets'));
        add_action('wp_footer', array($this, 'render_sitewide_whatsapp'));
        add_filter('script_loader_tag', array($this, 'ai_script_attributes'), 10, 3);
        add_shortcode('replynextai_chat', array($this, 'ai_shortcode'));
        add_shortcode('replynextai_whatsapp', array($this, 'whatsapp_shortcode'));
    }

    public function send_chat_order_status_update($order_id, $previous_status, $status) {
        if (!in_array($status, array('processing', 'on-hold', 'completed', 'cancelled'), true) || !function_exists('wc_get_order')) return;
        $this->send_chat_order_event(wc_get_order($order_id), $status);
    }

    public function send_chat_payment_confirmation($order_id) {
        if (!function_exists('wc_get_order')) return;
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order || !$order->is_paid() || !$order->get_date_paid()) return;
        $this->send_chat_order_event($order, 'paid');
    }

    private function can_confirm_chat_shipment($order) {
        return $order instanceof WC_Order
            && current_user_can('edit_shop_order', $order->get_id())
            && 'replynext-ai' === $order->get_created_via()
            && '1' !== $order->get_meta('_replynext_shipment_confirmed')
            && in_array($order->get_status(), array('processing', 'on-hold', 'completed'), true)
            && absint($order->get_meta('_replynext_company_id')) === absint($this->options()['company_id']);
    }

    public function add_chat_shipment_action($actions, $order = null) {
        if ($this->can_confirm_chat_shipment($order)) {
            $actions['replynextai_confirm_shipment'] = __('ReplyNext AI: Confirm dispatch and notify customer', 'replynext-ai-chat-assistant-for-woocommerce');
        }
        return $actions;
    }

    public function confirm_chat_shipment($order) {
        if (!$this->can_confirm_chat_shipment($order)) return;
        if ($this->send_chat_order_event($order, 'shipped')) {
            $order->update_meta_data('_replynext_shipment_confirmed', '1');
            $order->save_meta_data();
        }
    }

    private function send_chat_order_event($order, $status) {
        if (!$order instanceof WC_Order || 'replynext-ai' !== $order->get_created_via()) return false;
        $options = $this->options();
        $origin = $this->canonical_origin(home_url());
        $server = $this->canonical_origin($options['server_url']);
        $company_id = absint($options['company_id']);
        if (!$origin || !$server || 0 !== strpos($origin, 'https://') || 0 !== strpos($server, 'https://') || !$company_id || absint($order->get_meta('_replynext_company_id')) !== $company_id || empty($options['api_token'])) return false;
        $body = wp_json_encode(array('companyId' => $company_id, 'siteUrl' => $origin, 'orderId' => (string) $order->get_id(), 'status' => $status));
        if (!$body) return;
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp . '.woocommerce-order-update.' . hash('sha256', $body), hash('sha256', $options['api_token']));
        $response = wp_safe_remote_post($server . '/api/wordpress/order-update', array(
            'timeout' => 3, 'redirection' => 0,
            'headers' => array('Content-Type' => 'application/json', 'X-ReplyNext-Timestamp' => $timestamp, 'X-ReplyNext-Signature' => $signature),
            'body' => $body,
        ));
        return !is_wp_error($response) && 204 === wp_remote_retrieve_response_code($response);
    }

    private function options() {
        return wp_parse_args(get_option(self::OPTION_KEY, array()), self::defaults());
    }

    private function checkout_options() {
        return array_merge($this->options(), array(
            'chat_payment_links_enabled' => get_option(self::CHAT_PAYMENT_LINKS_OPTION, '0'),
            'chat_payment_links_with_cod' => get_option(self::CHAT_PAYMENT_LINKS_WITH_COD_OPTION, '0'),
        ));
    }

    private function canonical_origin($value) {
        $parts = wp_parse_url((string) $value);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host']) || !in_array(strtolower($parts['scheme']), array('http', 'https'), true)) return '';
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) && (int) $parts['port'] !== ('https' === $scheme ? 443 : 80) ? ':' . (int) $parts['port'] : '';
        return $scheme . '://' . $host . $port;
    }

    public function register_commerce_routes() {
        register_rest_route('replynextai/v1', '/checkout-create', array(
            'methods' => 'POST', 'callback' => array($this, 'checkout_create'),
            'permission_callback' => '__return_true', // Explicit signed confirmation plus durable idempotency.
        ));
        register_rest_route('replynextai/v1', '/checkout-requirements', array(
            'methods' => 'POST', 'callback' => array($this, 'checkout_requirements'),
            'permission_callback' => '__return_true', // Operation-specific server HMAC below.
        ));
        register_rest_route('replynextai/v1', '/checkout-quote', array(
            'methods' => 'POST', 'callback' => array($this, 'checkout_quote'),
            'permission_callback' => '__return_true', // Read-only; never creates an order.
        ));
        register_rest_route('replynextai/v1', '/checkout-options', array(
            'methods' => 'POST', 'callback' => array($this, 'checkout_live_options'),
            'permission_callback' => '__return_true', // Read-only, authenticated by its own operation signature.
        ));
        register_rest_route('replynextai/v1', '/order-billing-recipient', array(
            'methods' => 'POST',
            'callback' => array($this, 'order_billing_recipient'),
            // Only an operation-specific server HMAC authorizes this handler.
            'permission_callback' => '__return_true',
        ));
        register_rest_route('replynextai/v1', '/order-status', array(
            'methods' => 'POST',
            'callback' => array($this, 'limited_order_status'),
            // Authenticated by the server-to-server HMAC in the handler.
            'permission_callback' => '__return_true',
        ));
        register_rest_route('replynextai/v1', '/product-verify', array(
            'methods' => 'POST',
            'callback' => array($this, 'verify_woocommerce_products'),
            // The HMAC below authenticates the ReplyNext server. WordPress REST
            // authentication cannot be used because this is server-to-server.
            'permission_callback' => '__return_true',
        ));
    }

    public function checkout_create($request) {
        require_once __DIR__ . '/class-replynextai-checkout.php';
        return ReplyNextAI_Checkout::handle($request, $this->checkout_options(), 'checkout-create');
    }

    public function checkout_requirements($request) {
        require_once __DIR__ . '/class-replynextai-checkout.php';
        return ReplyNextAI_Checkout::handle($request, $this->checkout_options(), 'checkout-requirements');
    }

    public function checkout_quote($request) {
        require_once __DIR__ . '/class-replynextai-checkout.php';
        return ReplyNextAI_Checkout::handle($request, $this->checkout_options(), 'checkout-quote');
    }

    public function checkout_live_options($request) {
        require_once __DIR__ . '/class-replynextai-checkout.php';
        return ReplyNextAI_Checkout::handle($request, $this->checkout_options(), 'checkout-options');
    }

    public function order_billing_recipient($request) {
        $o = $this->options();
        $payload = $request->get_json_params();
        $timestamp = (string) $request->get_header('x-replynext-timestamp');
        $signature = (string) $request->get_header('x-replynext-signature');
        $site_url = $this->canonical_origin(home_url('/'));
        if (!$o['api_token'] || !$site_url || 0 !== strpos($site_url, 'https://') || strlen($request->get_body()) > 4096 || !is_array($payload) || array_diff(array_keys($payload), array('companyId', 'siteUrl', 'orderId', 'challengeId')) || !preg_match('/^[0-9]{13}$/D', $timestamp) || abs(microtime(true) * 1000 - (float) $timestamp) >= 60000 || !preg_match('/^[a-f0-9]{64}$/D', $signature) || !isset($payload['companyId'], $payload['siteUrl'], $payload['orderId'], $payload['challengeId']) || !is_int($payload['companyId']) || $payload['companyId'] !== absint($o['company_id']) || $payload['siteUrl'] !== $site_url || !is_string($payload['orderId']) || !preg_match('/^[1-9][0-9]{0,19}$/D', $payload['orderId']) || !is_string($payload['challengeId']) || !preg_match('/^[a-f0-9]{64}$/D', $payload['challengeId'])) {
            return new WP_REST_Response(array('success' => false, 'error' => 'Unauthorized order request'), 401);
        }
        $binding = array('companyId' => $payload['companyId'], 'siteUrl' => $site_url, 'orderId' => $payload['orderId'], 'challengeId' => $payload['challengeId']);
        $hash = hash('sha256', wp_json_encode($binding, JSON_UNESCAPED_SLASHES));
        $secret = hash('sha256', $o['api_token']);
        $expected = hash_hmac('sha256', $timestamp . '.order-billing-recipient.' . $hash, $secret);
        if (!hash_equals($expected, $signature)) return new WP_REST_Response(array('success' => false, 'error' => 'Unauthorized order request'), 401);
        $sealed = null;
        try {
        if (function_exists('openssl_encrypt') && function_exists('wc_get_order') && strlen($payload['orderId']) <= strlen((string) PHP_INT_MAX) && (float) $payload['orderId'] <= PHP_INT_MAX) {
            $order = wc_get_order((int) $payload['orderId']);
            if ($order && is_a($order, 'WC_Order')) {
                $email = (string) $order->get_billing_email();
                if (strlen($email) <= 254 && is_email($email) && !preg_match('/[\s<>,;]/', $email)) {
                    $key = hash_hmac('sha256', 'replynext-order-recipient-key-v1', $secret, true);
                    $iv = random_bytes(12);
                    $tag = '';
                    $data = array_merge($binding, array('email' => $email, 'expiresAt' => (int) floor(microtime(true) * 1000) + 60000));
                    $encrypted = openssl_encrypt(wp_json_encode($data, JSON_UNESCAPED_SLASHES), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'replynext-order-recipient-v1.' . $hash, 16);
                    if (false !== $encrypted) {
                        $parts = array($iv, $tag, $encrypted);
                        $encoded = array_map(function ($part) { return rtrim(strtr(base64_encode($part), '+/', '-_'), '='); }, $parts);
                        $sealed = implode('.', $encoded);
                    }
                }
            }
        }
        } catch (Throwable $ignored) { $sealed = null; }
        // No plaintext recipient or OTP. The caller keeps the outward response generic.
        $response = new WP_REST_Response(array('success' => true, 'sealedRecipient' => $sealed), 200);
        $response->header('Cache-Control', 'no-store, private');
        return $response;
    }

    public function limited_order_status($request) {
        $o = $this->options();
        $payload = $request->get_json_params();
        $timestamp = (string) $request->get_header('x-replynext-timestamp');
        $signature = (string) $request->get_header('x-replynext-signature');
        $site_url = $this->canonical_origin(home_url('/'));
        $body = $request->get_body();
        $chat_owned = is_array($payload) && 3 === count($payload) && !array_diff(array_keys($payload), array('companyId', 'siteUrl', 'orderId'));
        $limited = is_array($payload) && 4 === count($payload) && !array_diff(array_keys($payload), array('companyId', 'siteUrl', 'orderId', 'billingPhone'));
        if (!$o['api_token'] || !$site_url || 0 !== strpos($site_url, 'https://') || strlen($body) > 4096 || !is_array($payload) || (!$chat_owned && !$limited) || !preg_match('/^[0-9]{10}$/D', $timestamp) || abs(time() - (int) $timestamp) > 60 || !isset($payload['companyId'], $payload['siteUrl'], $payload['orderId']) || !is_int($payload['companyId']) || absint($o['company_id']) !== $payload['companyId'] || $site_url !== $payload['siteUrl'] || !is_string($payload['orderId']) || !preg_match('/^[1-9][0-9]{0,19}$/D', $payload['orderId']) || ($limited && (!isset($payload['billingPhone']) || !is_string($payload['billingPhone']) || !preg_match('/^[0-9]{7,15}$/D', $payload['billingPhone'])))) {
            return new WP_REST_Response(array('success' => false, 'error' => 'Unauthorized order request'), 401);
        }
        if (strlen($payload['orderId']) > strlen((string) PHP_INT_MAX) || (float) $payload['orderId'] > PHP_INT_MAX) {
            return new WP_REST_Response(array('success' => false, 'error' => 'Unauthorized order request'), 401);
        }
        $canonical_payload = array('companyId' => $payload['companyId'], 'siteUrl' => $site_url, 'orderId' => $payload['orderId']);
        if ($limited) $canonical_payload['billingPhone'] = $payload['billingPhone'];
        $operation_prefix = $chat_owned ? $timestamp . '.chat-owned-order-status.' : $timestamp . '.';
        $expected = hash_hmac('sha256', $operation_prefix . hash('sha256', wp_json_encode($canonical_payload, JSON_UNESCAPED_SLASHES)), hash('sha256', $o['api_token']));
        if (!hash_equals($expected, $signature)) return new WP_REST_Response(array('success' => false, 'error' => 'Unauthorized order request'), 401);
        $response = array('success' => true, 'matched' => false);
        if (function_exists('wc_get_order')) {
            $order = wc_get_order((int) $payload['orderId']);
            if ($order && is_a($order, 'WC_Order')) {
                $phone_matches = false;
                if ($limited) {
                    $phone = preg_replace('/[\s()+.\-]/', '', (string) $order->get_billing_phone());
                    // Legacy limited lookup requires a full normalized phone match.
                    $phone_matches = (bool) preg_match('/^[0-9]{7,15}$/D', $phone) && hash_equals($phone, $payload['billingPhone']);
                }
                if ($chat_owned || $phone_matches) {
                    $status = $order->get_status();
                    if (in_array($status, array('pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed', 'checkout-draft'), true)) $response = array('success' => true, 'matched' => true, 'status' => $status);
                }
            }
        }
        $result = new WP_REST_Response($response, 200);
        $result->header('Cache-Control', 'no-store, private');
        return $result;
    }

    public function verify_woocommerce_products($request) {
        $o = $this->options();
        $payload = $request->get_json_params();
        $timestamp = (string) $request->get_header('x-replynext-timestamp');
        $signature = (string) $request->get_header('x-replynext-signature');
        $issued_at = absint($timestamp);
        $site_url = $this->canonical_origin(home_url('/'));
        $expected_company = absint($o['company_id']);
        if (!$o['api_token'] || !$site_url || !is_array($payload) || !$issued_at || abs(time() - $issued_at) > 300 || empty($payload['companyId']) || $expected_company !== absint($payload['companyId']) || empty($payload['siteUrl']) || $site_url !== $this->canonical_origin($payload['siteUrl']) || !is_array($payload['products']) || count($payload['products']) > 3 || !is_array($payload['requestedFacts'])) {
            return new WP_REST_Response(array('success' => false, 'error' => 'Unauthorized verification request'), 401);
        }
        $allowed_facts = array('price', 'sale_price', 'availability', 'variants');
        $facts = array_values(array_unique(array_filter($payload['requestedFacts'], function ($fact) use ($allowed_facts) { return is_string($fact) && in_array($fact, $allowed_facts, true); })));
        sort($facts, SORT_STRING);
        if ($facts !== $payload['requestedFacts']) return new WP_REST_Response(array('success' => false, 'error' => 'Invalid verification request'), 400);
        $canonical_payload = array(
            'companyId' => $expected_company,
            'siteUrl' => $site_url,
            'requestedFacts' => $facts,
            'products' => $payload['products'],
        );
        $expected = hash_hmac('sha256', $timestamp . '.' . hash('sha256', wp_json_encode($canonical_payload)), hash('sha256', $o['api_token']));
        if (!hash_equals($expected, $signature) || !function_exists('wc_get_product')) return new WP_REST_Response(array('success' => false, 'error' => 'Unauthorized verification request'), 401);

        $products = array();
        foreach ($payload['products'] as $requested) {
            $product_id = is_array($requested) && isset($requested['externalProductId']) ? absint($requested['externalProductId']) : 0;
            $product = $product_id ? wc_get_product($product_id) : false;
            if (!$product || 'product' !== get_post_type($product_id) || 'publish' !== $product->get_status() || get_post_field('post_password', $product_id)) continue;
            $products[] = $this->live_woocommerce_product($product, $site_url, $facts);
        }
        $result = new WP_REST_Response(array('success' => true, 'products' => $products), 200);
        $result->header('Cache-Control', 'no-store, private');
        return $result;
    }

    private function live_product_price($product, $price) {
        return '' === $price || !is_numeric($price) ? '' : wc_format_decimal(wc_get_price_to_display($product, array('price' => $price)), wc_get_price_decimals());
    }

    private function live_woocommerce_product($product, $site_url, $facts) {
        $include_price = in_array('price', $facts, true) || in_array('sale_price', $facts, true);
        $include_availability = in_array('availability', $facts, true) || in_array('variants', $facts, true);
        $currency = $include_price ? (string) get_woocommerce_currency() : '';
        $is_variable = $product->is_type('variable');
        $attributes = array();
        foreach ($product->get_attributes() as $attribute) {
            if ($is_variable && is_object($attribute) && $attribute->get_variation()) {
                $attributes[] = sanitize_text_field(wc_attribute_label($attribute->get_name(), $product));
            }
        }
        $variant_ids = $is_variable ? $product->get_visible_children() : array($product->get_id());
        $variants = array();
        foreach (array_slice($variant_ids, 0, 100) as $variant_id) {
            $variant = $is_variable ? wc_get_product($variant_id) : $product;
            if (!$variant || 'publish' !== $variant->get_status()) continue;
            $row = array(
                'id' => (string) $variant->get_id(),
                'title' => sanitize_text_field($variant->get_name()),
                'selectedOptions' => array(),
            );
            foreach ($is_variable ? (array) $variant->get_attributes() : array() as $name => $value) {
                if (!is_scalar($value) || '' === (string) $value) continue;
                $taxonomy = str_replace('attribute_', '', (string) $name);
                $term = taxonomy_exists($taxonomy) ? get_term_by('slug', (string) $value, $taxonomy) : false;
                $row['selectedOptions'][] = array('name' => sanitize_text_field(wc_attribute_label($taxonomy, $product)), 'value' => sanitize_text_field($term && !is_wp_error($term) ? $term->name : (string) $value));
            }
            if ($include_price) {
                $row['price'] = $this->live_product_price($variant, $variant->get_price());
                $row['regularPrice'] = $this->live_product_price($variant, $variant->get_regular_price());
                if (preg_match('/^[A-Z]{3}$/D', $currency)) $row['priceCurrencyCode'] = $currency;
                if ($variant->is_on_sale()) $row['salePrice'] = $this->live_product_price($variant, $variant->get_sale_price());
            }
            if ($include_availability) {
                $row['availableForSale'] = $variant->is_in_stock() && $variant->is_purchasable();
                // Parent-managed variations share their parent's stock pool.
                // Report live quantity only when WooCommerce actually tracks it.
                $stock_owner = wc_get_product($variant->get_stock_managed_by_id());
                $stock_quantity = $stock_owner && $stock_owner->managing_stock() ? $stock_owner->get_stock_quantity() : null;
                if (is_numeric($stock_quantity) && (float) $stock_quantity >= 0 && (float) $stock_quantity <= 9007199254740991 && floor((float) $stock_quantity) === (float) $stock_quantity) {
                    $row['stockQuantity'] = (int) $stock_quantity;
                    $row['stockScope'] = $stock_owner->get_id() === $variant->get_id() ? 'variant' : 'shared_parent';
                }
            }
            $variants[] = $row;
        }
        return array(
            'provider' => 'woocommerce',
            'storeIdentity' => $site_url,
            'externalProductId' => (string) $product->get_id(),
            'title' => sanitize_text_field($product->get_name()),
            'status' => sanitize_text_field($product->get_status()),
            'productType' => sanitize_text_field($product->get_type()),
            'description' => trim(wp_html_excerpt(strip_shortcodes($product->get_short_description() ?: $product->get_description()), 1200)),
            'optionNames' => array_values(array_filter($attributes)),
            'variants' => $variants,
            'variantsComplete' => count($variant_ids) <= 100,
        );
    }

    public function register_settings() {
        register_setting('replynextai_group', self::OPTION_KEY, array('sanitize_callback' => array($this, 'sanitize_options')));
        register_setting('replynextai_woocommerce_group', self::CHAT_PAYMENT_LINKS_OPTION, array('type' => 'string', 'default' => '0', 'sanitize_callback' => array($this, 'sanitize_payment_links_setting')));
        register_setting('replynextai_woocommerce_group', self::CHAT_PAYMENT_LINKS_WITH_COD_OPTION, array('type' => 'string', 'default' => '0', 'sanitize_callback' => array($this, 'sanitize_payment_links_setting')));
    }

    public function sanitize_payment_links_setting($value) {
        return '1' === $value ? '1' : '0';
    }

    public function sanitize_options($input) {
        $current = $this->options();
        if (!is_array($input)) return $current;
        $output = self::defaults();
        $url = isset($input['server_url']) ? untrailingslashit(esc_url_raw(trim($input['server_url']))) : '';
        $output['server_url'] = $url && wp_http_validate_url($url) ? $url : $current['server_url'];
        // The company mapping is set only after the authenticated WordPress
        // connection test. Keep the existing internal value when a settings
        // form does not submit it; it is never a merchant-facing setting.
        $output['company_id'] = $current['company_id'];
        if (null !== $this->verified_connection_company_id) $output['company_id'] = $this->verified_connection_company_id;
        $output['api_token'] = isset($input['api_token']) ? sanitize_text_field($input['api_token']) : $current['api_token'];
        foreach (array('ai_enabled', 'ai_sitewide', 'share_user_data', 'contact_show_label', 'whatsapp_enabled', 'whatsapp_show_label', 'messenger_enabled', 'messenger_show_label', 'call_enabled', 'call_show_label', 'hide_mobile', 'hide_desktop') as $checkbox) {
            $output[$checkbox] = empty($input[$checkbox]) ? '0' : '1';
        }
        // Contact Channels currently has no master toggle in its rendered form.
        // Preserve the saved value unless a future rendered control explicitly submits it.
        $output['contact_enabled'] = array_key_exists('contact_enabled', $input) ? (empty($input['contact_enabled']) ? '0' : '1') : $current['contact_enabled'];
        $output['whatsapp_number'] = isset($input['whatsapp_number']) ? preg_replace('/[^0-9]/', '', (string) $input['whatsapp_number']) : '';
        $output['whatsapp_message'] = isset($input['whatsapp_message']) ? sanitize_textarea_field($input['whatsapp_message']) : self::defaults()['whatsapp_message'];
        $output['whatsapp_label'] = isset($input['whatsapp_label']) ? sanitize_text_field($input['whatsapp_label']) : self::defaults()['whatsapp_label'];
        $output['whatsapp_icon_id'] = isset($input['whatsapp_icon_id']) ? (string) absint($input['whatsapp_icon_id']) : '0';
        $output['whatsapp_show_label'] = empty($input['whatsapp_show_label']) ? '0' : '1';
        $color = isset($input['whatsapp_color']) ? sanitize_hex_color($input['whatsapp_color']) : '';
        $output['whatsapp_color'] = $color ?: self::defaults()['whatsapp_color'];
        $output['contact_label'] = isset($input['contact_label']) ? sanitize_text_field($input['contact_label']) : self::defaults()['contact_label'];
        $output['contact_color'] = sanitize_hex_color($input['contact_color'] ?? '') ?: self::defaults()['contact_color'];
        $output['contact_text_color'] = sanitize_hex_color($input['contact_text_color'] ?? '') ?: self::defaults()['contact_text_color'];
        $output['contact_icon_id'] = isset($input['contact_icon_id']) ? (string) absint($input['contact_icon_id']) : '0';
        $output['contact_show_label'] = empty($input['contact_show_label']) ? '0' : '1';
        $output['whatsapp_text_color'] = sanitize_hex_color($input['whatsapp_text_color'] ?? '') ?: self::defaults()['whatsapp_text_color'];
        $output['whatsapp_position'] = isset($input['whatsapp_position']) && in_array($input['whatsapp_position'], array('left', 'right'), true) ? $input['whatsapp_position'] : 'left';
        $output['whatsapp_delay'] = isset($input['whatsapp_delay']) ? (string) min(30, max(0, absint($input['whatsapp_delay']))) : '0';
        $output['messenger_url'] = isset($input['messenger_url']) ? esc_url_raw(trim($input['messenger_url'])) : '';
        $output['messenger_label'] = isset($input['messenger_label']) ? sanitize_text_field($input['messenger_label']) : self::defaults()['messenger_label'];
        $output['messenger_icon_id'] = isset($input['messenger_icon_id']) ? (string) absint($input['messenger_icon_id']) : '0';
        $output['messenger_show_label'] = empty($input['messenger_show_label']) ? '0' : '1';
        $output['messenger_color'] = sanitize_hex_color($input['messenger_color'] ?? '') ?: self::defaults()['messenger_color'];
        $output['messenger_text_color'] = sanitize_hex_color($input['messenger_text_color'] ?? '') ?: self::defaults()['messenger_text_color'];
        $output['call_number'] = isset($input['call_number']) ? preg_replace('/[^0-9+]/', '', (string) $input['call_number']) : '';
        $output['call_label'] = isset($input['call_label']) ? sanitize_text_field($input['call_label']) : self::defaults()['call_label'];
        $output['phone_icon_id'] = isset($input['phone_icon_id']) ? (string) absint($input['phone_icon_id']) : '0';
        $output['call_show_label'] = empty($input['call_show_label']) ? '0' : '1';
        $output['call_color'] = sanitize_hex_color($input['call_color'] ?? '') ?: self::defaults()['call_color'];
        $output['call_text_color'] = sanitize_hex_color($input['call_text_color'] ?? '') ?: self::defaults()['call_text_color'];
        $output['show_on'] = isset($input['show_on']) && in_array($input['show_on'], array('all', 'include'), true) ? $input['show_on'] : 'all';
        $output['include_pages'] = isset($input['include_pages']) ? $this->sanitize_id_list($input['include_pages']) : '';
        $output['exclude_pages'] = isset($input['exclude_pages']) ? $this->sanitize_id_list($input['exclude_pages']) : '';
        add_settings_error('replynextai_messages', 'replynextai_saved', __('ReplyNext settings saved.', 'replynext-ai-chat-assistant-for-woocommerce'), 'updated');
        return $output;
    }

    private function sanitize_id_list($value) {
        $values = is_array($value) ? $value : preg_split('/[\s,]+/', (string) $value);
        $ids = array_filter(array_map('absint', $values));
        return implode(',', array_unique($ids));
    }

    public function activation_redirect() {
        if (!get_transient('replynextai_activation_redirect')) return;
        delete_transient('replynextai_activation_redirect');
        if (!is_network_admin() && current_user_can('manage_options')) {
            wp_safe_redirect(admin_url('admin.php?page=replynextai'));
            exit;
        }
    }

    public function admin_menu() {
        add_menu_page(__('ReplyNext AI', 'replynext-ai-chat-assistant-for-woocommerce'), __('ReplyNext AI', 'replynext-ai-chat-assistant-for-woocommerce'), 'manage_options', 'replynextai', array($this, 'dashboard_page'), 'dashicons-format-chat', 58);
        add_submenu_page('replynextai', __('Dashboard', 'replynext-ai-chat-assistant-for-woocommerce'), __('Dashboard', 'replynext-ai-chat-assistant-for-woocommerce'), 'manage_options', 'replynextai', array($this, 'dashboard_page'));
        add_submenu_page('replynextai', __('AI Website Chat', 'replynext-ai-chat-assistant-for-woocommerce'), __('AI Website Chat', 'replynext-ai-chat-assistant-for-woocommerce'), 'manage_options', 'replynextai-ai', array($this, 'ai_page'));
        add_submenu_page('replynextai', __('Contact Channels', 'replynext-ai-chat-assistant-for-woocommerce'), __('Contact Channels', 'replynext-ai-chat-assistant-for-woocommerce'), 'manage_options', 'replynextai-whatsapp', array($this, 'whatsapp_page'));
        add_submenu_page('replynextai', __('Catalog & Checkout', 'replynext-ai-chat-assistant-for-woocommerce'), __('Catalog & Checkout', 'replynext-ai-chat-assistant-for-woocommerce'), 'manage_options', 'replynextai-woocommerce', array($this, 'woocommerce_page'));
        add_submenu_page('replynextai', __('Display Rules', 'replynext-ai-chat-assistant-for-woocommerce'), __('Display Rules', 'replynext-ai-chat-assistant-for-woocommerce'), 'manage_options', 'replynextai-display', array($this, 'display_page'));
    }

    public function plugin_links($links) {
        array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=replynextai')) . '">' . esc_html__('Dashboard', 'replynext-ai-chat-assistant-for-woocommerce') . '</a>', '<a href="' . esc_url(admin_url('admin.php?page=replynextai-ai')) . '">' . esc_html__('Settings', 'replynext-ai-chat-assistant-for-woocommerce') . '</a>');
        return $links;
    }

    public function admin_assets($hook) {
        if (false === strpos($hook, 'replynextai')) return;
        wp_enqueue_media();
        wp_enqueue_style('replynextai-admin', REPLYNEXTAI_URL . 'assets/admin.css', array(), REPLYNEXTAI_VERSION . '.workspace7');
        wp_enqueue_script('replynextai-admin', REPLYNEXTAI_URL . 'assets/admin.js', array(), REPLYNEXTAI_VERSION . '.workspace6', true);
    }

    private function page_header($title, $description, $dashboard = false) {
        $current = isset($_GET['page']) && is_string($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : 'replynextai';
        $sections = array(
            'replynextai' => array('dashboard', __('Dashboard', 'replynext-ai-chat-assistant-for-woocommerce')),
            'replynextai-ai' => array('format-chat', __('AI Website Chat', 'replynext-ai-chat-assistant-for-woocommerce')),
            'replynextai-whatsapp' => array('phone', __('Contact Channels', 'replynext-ai-chat-assistant-for-woocommerce')),
            'replynextai-woocommerce' => array('cart', __('Catalog & Checkout', 'replynext-ai-chat-assistant-for-woocommerce')),
            'replynextai-display' => array('visibility', __('Display Rules', 'replynext-ai-chat-assistant-for-woocommerce')),
        );
        ?>
        <aside class="rn-workspace-sidebar">
            <div class="rn-workspace-brand"><span class="rn-workspace-mark" aria-hidden="true">RN</span><div><strong>ReplyNext AI</strong><small><?php esc_html_e('WordPress workspace', 'replynext-ai-chat-assistant-for-woocommerce'); ?></small></div></div>
            <nav class="rn-workspace-nav" aria-label="<?php esc_attr_e('ReplyNext settings', 'replynext-ai-chat-assistant-for-woocommerce'); ?>">
                <?php foreach ($sections as $page => $section) : ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . $page)); ?>"<?php if ($current === $page) : ?> aria-current="page"<?php endif; ?>>
                        <span class="dashicons dashicons-<?php echo esc_attr($section[0]); ?>" aria-hidden="true"></span>
                        <?php echo esc_html($section[1]); ?>
                    </a>
                <?php endforeach; ?>
            </nav>
            <div class="rn-workspace-version"><?php esc_html_e('Plugin version', 'replynext-ai-chat-assistant-for-woocommerce'); ?> <strong><?php echo esc_html(REPLYNEXTAI_VERSION); ?></strong></div>
        </aside>
        <?php if ('commerce' === $dashboard) return; ?>
        <?php if ('ai' === $dashboard || 'contact' === $dashboard) : ?>
            <header class="rn-ai-heading<?php echo 'contact' === $dashboard ? ' rn-contact-heading' : ''; ?>">
                <?php if ('contact' === $dashboard) : ?>
                <div><span class="rn-ai-module"><?php esc_html_e('Contact Channels Module', 'replynext-ai-chat-assistant-for-woocommerce'); ?></span><h1><?php echo esc_html($title); ?></h1><p><?php echo esc_html($description); ?></p></div>
                <?php else : ?>
                <span class="dashicons dashicons-admin-customizer" aria-hidden="true"></span>
                <div><span class="rn-ai-module"><?php esc_html_e('AI Website Chat Module', 'replynext-ai-chat-assistant-for-woocommerce'); ?></span><h1><?php echo esc_html($title); ?></h1><p><?php echo esc_html($description); ?></p></div>
                <?php endif; ?>
            </header>
            <?php return; ?>
        <?php endif; ?>
        <div class="rn-admin-header"><div><span class="rn-eyebrow"><?php if ($dashboard) { printf(esc_html__('✧ ReplyNext AI Intelligence Suite v%s', 'replynext-ai-chat-assistant-for-woocommerce'), esc_html(REPLYNEXTAI_VERSION)); } else { esc_html_e('ReplyNext AI for WordPress', 'replynext-ai-chat-assistant-for-woocommerce'); } ?></span><h1><?php echo esc_html($title); ?></h1><p><?php echo esc_html($description); ?></p></div>
        <?php if ($dashboard) : ?><div class="rn-dash-actions"><a class="button button-primary" href="<?php echo esc_url(home_url('/')); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('⚡ Live Storefront Preview', 'replynext-ai-chat-assistant-for-woocommerce'); ?></a><?php endif; ?>
        <a class="button button-secondary" href="<?php echo esc_url($this->portal_url()); ?>" target="_blank" rel="noopener noreferrer"><?php echo $dashboard ? esc_html__('Client Portal ↗', 'replynext-ai-chat-assistant-for-woocommerce') : esc_html__('Open Client Portal ↗', 'replynext-ai-chat-assistant-for-woocommerce'); ?></a>
        <?php if ($dashboard) : ?></div><?php endif; ?></div>
        <?php
    }

    private function portal_url() {
        return $this->options()['server_url'] . '/portal';
    }

    public function dashboard_page() {
        $o = $this->options();
        $connected = !empty($o['api_token']) && (bool) get_option('replynextai_widget_id');
        $ai = $connected && '1' === $o['ai_enabled'];
        $contact = ('1' === $o['whatsapp_enabled'] && !empty($o['whatsapp_number']))
            || ('1' === $o['messenger_enabled'] && !empty($o['messenger_url']))
            || ('1' === $o['call_enabled'] && !empty($o['call_number']));
        $woo = function_exists('wc_get_products');
        $sync = $woo ? $this->catalog_sync_view() : array('phase' => 'idle');
        $synced = 'completed' === $sync['phase'];
        $catalog_value = array('completed' => __('Synced', 'replynext-ai-chat-assistant-for-woocommerce'), 'queued' => __('Pending', 'replynext-ai-chat-assistant-for-woocommerce'), 'running' => __('Syncing', 'replynext-ai-chat-assistant-for-woocommerce'), 'failed' => __('Needs attention', 'replynext-ai-chat-assistant-for-woocommerce'));
        $metrics = array(
            array('purple', 'chart-line', '—', __('Chat Uptime', 'replynext-ai-chat-assistant-for-woocommerce')),
            array('green', 'groups', $ai ? __('Active', 'replynext-ai-chat-assistant-for-woocommerce') : __('Off', 'replynext-ai-chat-assistant-for-woocommerce'), __('AI Assistant', 'replynext-ai-chat-assistant-for-woocommerce')),
            array('blue', 'cart', $catalog_value[$sync['phase']] ?? '—', __('Catalog Sync', 'replynext-ai-chat-assistant-for-woocommerce')),
            array('orange', 'chart-area', '—', __('Conversion Rate', 'replynext-ai-chat-assistant-for-woocommerce')),
        );
        $features = array(
            array('purple', 'format-chat', __('AI Website Chat', 'replynext-ai-chat-assistant-for-woocommerce'), $ai ? __('Active', 'replynext-ai-chat-assistant-for-woocommerce') : __('Setup needed', 'replynext-ai-chat-assistant-for-woocommerce'), $ai ? __('Live and securely connected to ReplyNext.', 'replynext-ai-chat-assistant-for-woocommerce') : __('Connect ReplyNext to enable the AI assistant on your storefront.', 'replynext-ai-chat-assistant-for-woocommerce'), __('Configure AI Chat', 'replynext-ai-chat-assistant-for-woocommerce'), 'replynextai-ai', $ai),
            array('green', 'phone', __('Contact Channels', 'replynext-ai-chat-assistant-for-woocommerce'), $contact ? __('Enabled', 'replynext-ai-chat-assistant-for-woocommerce') : __('Setup needed', 'replynext-ai-chat-assistant-for-woocommerce'), __('Manage WhatsApp, Messenger and call buttons for your storefront.', 'replynext-ai-chat-assistant-for-woocommerce'), __('Manage Channels', 'replynext-ai-chat-assistant-for-woocommerce'), 'replynextai-whatsapp', $contact),
            array('blue', 'cart', __('WooCommerce Commerce', 'replynext-ai-chat-assistant-for-woocommerce'), $synced ? __('Synchronized', 'replynext-ai-chat-assistant-for-woocommerce') : ($woo ? __('Detected', 'replynext-ai-chat-assistant-for-woocommerce') : __('Unavailable', 'replynext-ai-chat-assistant-for-woocommerce')), __('Catalog synchronization in small, resumable batches, live price verification, and chat checkout orders.', 'replynext-ai-chat-assistant-for-woocommerce'), __('View Catalog & Orders', 'replynext-ai-chat-assistant-for-woocommerce'), 'replynextai-woocommerce', $synced),
        );
        $steps = array(
            array('purple', __('Server & Connection', 'replynext-ai-chat-assistant-for-woocommerce'), __('Configure your ReplyNext server and verify your WordPress connection.', 'replynext-ai-chat-assistant-for-woocommerce'), $connected),
            array('green', __('Enable Channels', 'replynext-ai-chat-assistant-for-woocommerce'), __('Enable AI website chat or configure the contact channels your customers use.', 'replynext-ai-chat-assistant-for-woocommerce'), $ai || $contact),
            array('blue', __('Test Storefront', 'replynext-ai-chat-assistant-for-woocommerce'), __('Open the live storefront and test the chat assistant and checkout.', 'replynext-ai-chat-assistant-for-woocommerce'), false),
        );
        ?>
        <div class="wrap rn-wrap rn-dashboard">
            <?php $this->page_header(__('WooCommerce AI Sales & Support', 'replynext-ai-chat-assistant-for-woocommerce'), __('Supercharge your online store with AI-powered website chat, seamless WhatsApp contact channels, and automated WooCommerce catalog synchronization.', 'replynext-ai-chat-assistant-for-woocommerce'), true); ?>
            <div class="rn-metric-grid">
                <?php foreach ($metrics as $metric) : ?>
                    <div class="rn-dash-panel rn-dash-metric rn-tone-<?php echo esc_attr($metric[0]); ?>">
                        <span class="rn-dash-icon dashicons dashicons-<?php echo esc_attr($metric[1]); ?>" aria-hidden="true"></span>
                        <div><strong><?php echo esc_html($metric[2]); ?></strong><small><?php echo esc_html($metric[3]); ?></small></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="rn-feature-grid">
                <?php foreach ($features as $feature) : ?>
                    <section class="rn-dash-panel rn-dash-feature rn-tone-<?php echo esc_attr($feature[0]); ?>">
                        <div class="rn-dash-feature-top"><span class="rn-dash-icon dashicons dashicons-<?php echo esc_attr($feature[1]); ?>" aria-hidden="true"></span><span class="rn-dash-badge<?php echo $feature[7] ? '' : ' rn-dash-muted'; ?>"><?php echo esc_html($feature[3]); ?></span></div>
                        <h2><?php echo esc_html($feature[2]); ?></h2>
                        <p><?php echo esc_html($feature[4]); ?></p>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=' . $feature[6])); ?>"><?php echo esc_html($feature[5]); ?> <span aria-hidden="true">→</span></a>
                    </section>
                <?php endforeach; ?>
            </div>
            <section class="rn-dash-panel rn-dash-checklist">
                <header><span class="rn-dash-icon rn-tone-purple" aria-hidden="true">🚀</span><div><h2><?php esc_html_e('Quick Start Checklist', 'replynext-ai-chat-assistant-for-woocommerce'); ?></h2><p><?php esc_html_e('Follow these 3 simple steps to launch your AI sales agent', 'replynext-ai-chat-assistant-for-woocommerce'); ?></p></div></header>
                <ol>
                    <?php foreach ($steps as $index => $step) : ?>
                        <li class="rn-tone-<?php echo esc_attr($step[0]); ?>">
                            <div class="rn-dash-feature-top"><span class="rn-dash-badge"><?php printf(esc_html__('Step %d', 'replynext-ai-chat-assistant-for-woocommerce'), $index + 1); ?></span><?php if ($step[3]) : ?><span class="dashicons dashicons-yes-alt" role="img" aria-label="<?php esc_attr_e('Complete', 'replynext-ai-chat-assistant-for-woocommerce'); ?>"></span><?php endif; ?></div>
                            <h3><?php echo esc_html($step[1]); ?></h3><p><?php echo esc_html($step[2]); ?></p>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </section>
        </div>
        <?php
    }
    private function status_card($title, $ready, $message, $page) {
        ?><a class="rn-card rn-status-card" href="<?php echo esc_url(admin_url('admin.php?page=' . $page)); ?>"><span class="rn-status <?php echo $ready ? 'is-live' : 'is-off'; ?>"><?php echo $ready ? esc_html__('Active', 'replynext-ai-chat-assistant-for-woocommerce') : esc_html__('Setup', 'replynext-ai-chat-assistant-for-woocommerce'); ?></span><h2><?php echo esc_html($title); ?></h2><p><?php echo esc_html($message); ?></p></a><?php
    }

    private function contact_icon_picker($label, $option_key, $input_id, $attachment_id) {
        $preview = $attachment_id ? (wp_get_attachment_image_url((int) $attachment_id, 'thumbnail') ?: '') : '';
        ?><label class="rn-field"><span><?php echo esc_html($label); ?></span><span class="rn-media-picker"><input type="hidden" id="<?php echo esc_attr($input_id); ?>" name="<?php echo esc_attr(self::OPTION_KEY); ?>[<?php echo esc_attr($option_key); ?>]" value="<?php echo esc_attr($attachment_id); ?>" /><button type="button" class="button" data-rn-media-choose="<?php echo esc_attr($input_id); ?>" data-rn-media-preview="<?php echo esc_attr($input_id); ?>-preview"><?php esc_html_e('Choose / Upload Icon', 'replynext-ai-chat-assistant-for-woocommerce'); ?></button><button type="button" class="button-link-delete" data-rn-media-remove="<?php echo esc_attr($input_id); ?>" data-rn-media-preview="<?php echo esc_attr($input_id); ?>-preview"><?php esc_html_e('Remove / Use Default', 'replynext-ai-chat-assistant-for-woocommerce'); ?></button><img id="<?php echo esc_attr($input_id); ?>-preview" src="<?php echo esc_url($preview); ?>" alt="" style="max-width:48px;max-height:48px;<?php echo $preview ? '' : 'display:none;'; ?>" /></span></label><?php
    }

    private function form_open() {
        settings_errors('replynextai_messages');
        ?><form method="post" action="options.php" class="rn-settings-form"><?php settings_fields('replynextai_group');
    }

    private function preserve_fields($except) {
        foreach ($this->options() as $key => $value) {
            if (!in_array($key, $except, true)) printf('<input type="hidden" name="%s[%s]" value="%s" />', esc_attr(self::OPTION_KEY), esc_attr($key), esc_attr($value));
        }
    }

    public function ai_page() {
        $o = $this->options();
        ?>
        <div class="wrap rn-wrap rn-ai-page">
            <?php $this->page_header(__('AI Chat & Connection', 'replynext-ai-chat-assistant-for-woocommerce'), __('Securely link your ReplyNext Client Portal with your WordPress store.', 'replynext-ai-chat-assistant-for-woocommerce'), 'ai'); ?>
            <?php /* phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The action handler validates its POST nonce; this query value only selects a bounded notice. */ if (isset($_GET['rn_widget_refresh'])) : ?>
                <div class="notice <?php echo 'success' === sanitize_key(wp_unslash($_GET['rn_widget_refresh'])) ? 'notice-success' : 'notice-error'; ?> is-dismissible"><p><?php echo 'success' === sanitize_key(wp_unslash($_GET['rn_widget_refresh'])) ? esc_html__('Widget connection refreshed successfully.', 'replynext-ai-chat-assistant-for-woocommerce') : esc_html__('Unable to refresh the widget connection. Your existing connection settings were kept.', 'replynext-ai-chat-assistant-for-woocommerce'); ?></p></div>
            <?php endif; ?>
            <?php /* phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This is a read-only redirect notice; the action handler validates its POST nonce. */ if (isset($_GET['rn_notice'])) : ?>
                <?php $notice = sanitize_key(wp_unslash($_GET['rn_notice'])); ?>
                <div class="notice <?php echo 'success' === $notice ? 'notice-success' : 'notice-error'; ?> is-dismissible"><p><?php if ('success' === $notice) { esc_html_e('Connection successful. Your secure website widget is ready.', 'replynext-ai-chat-assistant-for-woocommerce'); } elseif ('company_save_error' === $notice) { esc_html_e('Connection verified, but the company binding could not be saved. Please try again or contact support.', 'replynext-ai-chat-assistant-for-woocommerce'); } elseif ('widget_revoked' === $notice) { esc_html_e('Secure AI Website Chat authorization is revoked. Reactivate or remove the website authorization in ReplyNext before retrying.', 'replynext-ai-chat-assistant-for-woocommerce'); } elseif ('widget_error' === $notice) { esc_html_e('Secure AI Website Chat authorization could not be created. Your connection settings were kept; please try again.', 'replynext-ai-chat-assistant-for-woocommerce'); } else { esc_html_e('Connection failed. Check the server URL and token.', 'replynext-ai-chat-assistant-for-woocommerce'); } ?></p></div>
            <?php endif; ?>

            <?php $this->form_open(); $this->preserve_fields(array('server_url', 'api_token', 'ai_enabled', 'ai_sitewide', 'share_user_data')); ?>
                <div class="rn-card rn-ai-settings">
                    <label class="rn-toggle-row rn-ai-toggle"><span><strong><?php esc_html_e('Enable AI Website Chat', 'replynext-ai-chat-assistant-for-woocommerce'); ?></strong><small><?php esc_html_e('Turn on the assistant for this connected WordPress site.', 'replynext-ai-chat-assistant-for-woocommerce'); ?></small></span><input class="rn-ai-switch" type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[ai_enabled]" value="1" <?php checked($o['ai_enabled'], '1'); ?> /></label>
                    <?php $this->field('server_url', __('ReplyNext Server URL', 'replynext-ai-chat-assistant-for-woocommerce'), $o['server_url'], 'https://replynextai.com', 'url'); ?>
                    <label for="rn-ai-token" class="rn-ai-token-label"><?php esc_html_e('WordPress Connection Token', 'replynext-ai-chat-assistant-for-woocommerce'); ?></label>
                    <div class="rn-ai-token-row">
                        <input id="rn-ai-token" type="password" autocomplete="new-password" name="<?php echo esc_attr(self::OPTION_KEY); ?>[api_token]" value="<?php echo esc_attr($o['api_token']); ?>" placeholder="rnwp_live_..." aria-describedby="rn-ai-token-help" />
                        <button class="button rn-ai-test" type="submit" form="rn-ai-test-form"><?php esc_html_e('Test Connection', 'replynext-ai-chat-assistant-for-woocommerce'); ?></button>
                    </div>
                    <p id="rn-ai-token-help" class="description"><?php esc_html_e('Create a token in Client Portal → Integrations → WordPress. Save changes before testing the connection.', 'replynext-ai-chat-assistant-for-woocommerce'); ?></p>
                    <div class="rn-ai-options">
                        <label class="rn-check"><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[ai_sitewide]" value="1" <?php checked($o['ai_sitewide'], '1'); ?> /><span><strong><?php esc_html_e('Sitewide Display', 'replynext-ai-chat-assistant-for-woocommerce'); ?></strong><small><?php esc_html_e('Show the chat widget on all storefront pages allowed by Display Rules.', 'replynext-ai-chat-assistant-for-woocommerce'); ?></small></span></label>
                        <label class="rn-check"><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[share_user_data]" value="1" <?php checked($o['share_user_data'], '1'); ?> /><span><strong><?php esc_html_e('Share Logged-in User Identity', 'replynext-ai-chat-assistant-for-woocommerce'); ?></strong><small><?php esc_html_e('Share the logged-in user’s name and email only when covered by your privacy notice.', 'replynext-ai-chat-assistant-for-woocommerce'); ?></small></span></label>
                    </div>
                </div>
                <div class="rn-ai-save"><?php submit_button(__('Save AI Chat Settings', 'replynext-ai-chat-assistant-for-woocommerce')); ?></div>
            </form>
            <form id="rn-ai-test-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" hidden><input type="hidden" name="action" value="replynextai_test_connection" /><input type="hidden" name="return_page" value="replynextai-ai" /><?php wp_nonce_field('replynextai_test_connection'); ?></form>
            <details class="rn-ai-advanced">
                <summary><?php esc_html_e('Advanced connection tools', 'replynext-ai-chat-assistant-for-woocommerce'); ?></summary>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="replynextai_refresh_widget_connection" /><?php wp_nonce_field('replynextai_refresh_widget_connection'); ?><?php submit_button(__('Refresh Widget Connection', 'replynext-ai-chat-assistant-for-woocommerce'), 'secondary', 'submit', false); ?></form>
            </details>
        </div>
        <?php
    }

    public function whatsapp_page() {
        $o = $this->options();
        ?><div class="wrap rn-wrap rn-contact-page"><?php $this->page_header(__('WhatsApp & Contact Buttons', 'replynext-ai-chat-assistant-for-woocommerce'), __('Enable direct WhatsApp, Messenger and phone buttons for your store visitors.', 'replynext-ai-chat-assistant-for-woocommerce'), 'contact'); ?><?php $this->form_open(); $this->preserve_fields(array('contact_label', 'contact_show_label', 'contact_color', 'contact_text_color', 'contact_icon_id', 'whatsapp_enabled', 'whatsapp_number', 'whatsapp_message', 'whatsapp_label', 'whatsapp_icon_id', 'whatsapp_show_label', 'whatsapp_color', 'whatsapp_text_color', 'whatsapp_position', 'whatsapp_delay', 'messenger_enabled', 'messenger_url', 'messenger_label', 'messenger_show_label', 'messenger_color', 'messenger_text_color', 'messenger_icon_id', 'call_enabled', 'call_number', 'call_label', 'call_show_label', 'call_color', 'call_text_color', 'phone_icon_id')); ?>
        <div class="rn-contact-cards">
            <section class="rn-card rn-contact-card rn-contact-wa"><label class="rn-toggle-row"><span><strong><?php esc_html_e('WhatsApp Click-to-Chat', 'replynext-ai-chat-assistant-for-woocommerce'); ?></strong><small><?php esc_html_e('Opens wa.me in the visitor’s WhatsApp app.', 'replynext-ai-chat-assistant-for-woocommerce'); ?></small></span><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[whatsapp_enabled]" value="1" <?php checked($o['whatsapp_enabled'], '1'); ?> /></label><?php $this->field('whatsapp_number', __('WhatsApp number', 'replynext-ai-chat-assistant-for-woocommerce'), $o['whatsapp_number'], '8801XXXXXXXXX', 'tel'); ?><label class="rn-field"><span><?php esc_html_e('Prefilled message', 'replynext-ai-chat-assistant-for-woocommerce'); ?></span><textarea rows="3" name="<?php echo esc_attr(self::OPTION_KEY); ?>[whatsapp_message]"><?php echo esc_textarea($o['whatsapp_message']); ?></textarea></label><?php $this->field('whatsapp_label', __('Label', 'replynext-ai-chat-assistant-for-woocommerce'), $o['whatsapp_label'], __('Chat on WhatsApp', 'replynext-ai-chat-assistant-for-woocommerce')); ?><div class="rn-inline-fields"><label class="rn-field"><span><?php esc_html_e('Position', 'replynext-ai-chat-assistant-for-woocommerce'); ?></span><select name="<?php echo esc_attr(self::OPTION_KEY); ?>[whatsapp_position]"><option value="left" <?php selected($o['whatsapp_position'], 'left'); ?>><?php esc_html_e('Bottom left', 'replynext-ai-chat-assistant-for-woocommerce'); ?></option><option value="right" <?php selected($o['whatsapp_position'], 'right'); ?>><?php esc_html_e('Bottom right', 'replynext-ai-chat-assistant-for-woocommerce'); ?></option></select></label><label class="rn-field"><span><?php esc_html_e('Delay', 'replynext-ai-chat-assistant-for-woocommerce'); ?></span><input type="number" min="0" max="30" name="<?php echo esc_attr(self::OPTION_KEY); ?>[whatsapp_delay]" value="<?php echo esc_attr($o['whatsapp_delay']); ?>" /></label></div><details class="rn-contact-customize"><summary><?php esc_html_e('Label, icon & colors', 'replynext-ai-chat-assistant-for-woocommerce'); ?></summary><label class="rn-check"><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[whatsapp_show_label]" value="1" <?php checked($o['whatsapp_show_label'], '1'); ?> /> <?php esc_html_e('Show label', 'replynext-ai-chat-assistant-for-woocommerce'); ?></label><?php $this->contact_icon_picker(__('WhatsApp icon', 'replynext-ai-chat-assistant-for-woocommerce'), 'whatsapp_icon_id', 'rn-whatsapp-icon-id', $o['whatsapp_icon_id']); ?><div class="rn-inline-fields"><label class="rn-field"><span><?php esc_html_e('Background color', 'replynext-ai-chat-assistant-for-woocommerce'); ?></span><input type="color" name="<?php echo esc_attr(self::OPTION_KEY); ?>[whatsapp_color]" value="<?php echo esc_attr($o['whatsapp_color']); ?>" /></label><label class="rn-field"><span><?php esc_html_e('Icon/text color', 'replynext-ai-chat-assistant-for-woocommerce'); ?></span><input type="color" name="<?php echo esc_attr(self::OPTION_KEY); ?>[whatsapp_text_color]" value="<?php echo esc_attr($o['whatsapp_text_color']); ?>" /></label></div></details></section>
            <section class="rn-card rn-contact-card"><h2><?php esc_html_e('Messenger', 'replynext-ai-chat-assistant-for-woocommerce'); ?></h2><label class="rn-toggle-row"><span><strong><?php esc_html_e('Enable Messenger Channel', 'replynext-ai-chat-assistant-for-woocommerce'); ?></strong><small><?php esc_html_e('Opens your Facebook or Messenger link.', 'replynext-ai-chat-assistant-for-woocommerce'); ?></small></span><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[messenger_enabled]" value="1" <?php checked($o['messenger_enabled'], '1'); ?> /></label><?php $this->field('messenger_url', __('Messenger link', 'replynext-ai-chat-assistant-for-woocommerce'), $o['messenger_url'], 'https://m.me/your-page'); ?><details class="rn-contact-customize"><summary><?php esc_html_e('Label, icon & colors', 'replynext-ai-chat-assistant-for-woocommerce'); ?></summary><?php $this->field('messenger_label', __('Label', 'replynext-ai-chat-assistant-for-woocommerce'), $o['messenger_label'], __('Message on Messenger', 'replynext-ai-chat-assistant-for-woocommerce')); ?><label class="rn-check"><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[messenger_show_label]" value="1" <?php checked($o['messenger_show_label'], '1'); ?> /> <?php esc_html_e('Show label', 'replynext-ai-chat-assistant-for-woocommerce'); ?></label><?php $this->contact_icon_picker(__('Messenger icon', 'replynext-ai-chat-assistant-for-woocommerce'), 'messenger_icon_id', 'rn-messenger-icon-id', $o['messenger_icon_id']); ?><div class="rn-inline-fields"><label class="rn-field"><span><?php esc_html_e('Background color', 'replynext-ai-chat-assistant-for-woocommerce'); ?></span><input type="color" name="<?php echo esc_attr(self::OPTION_KEY); ?>[messenger_color]" value="<?php echo esc_attr($o['messenger_color']); ?>" /></label><label class="rn-field"><span><?php esc_html_e('Icon/text color', 'replynext-ai-chat-assistant-for-woocommerce'); ?></span><input type="color" name="<?php echo esc_attr(self::OPTION_KEY); ?>[messenger_text_color]" value="<?php echo esc_attr($o['messenger_text_color']); ?>" /></label></div></details></section>
            <section class="rn-card rn-contact-card"><h2><?php esc_html_e('Phone / Call', 'replynext-ai-chat-assistant-for-woocommerce'); ?></h2><label class="rn-toggle-row"><span><strong><?php esc_html_e('Enable Phone / Call Channel', 'replynext-ai-chat-assistant-for-woocommerce'); ?></strong><small><?php esc_html_e('Starts a phone call from the visitor’s device.', 'replynext-ai-chat-assistant-for-woocommerce'); ?></small></span><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[call_enabled]" value="1" <?php checked($o['call_enabled'], '1'); ?> /></label><?php $this->field('call_number', __('Phone number', 'replynext-ai-chat-assistant-for-woocommerce'), $o['call_number'], '+8801XXXXXXXXX', 'tel'); ?><details class="rn-contact-customize"><summary><?php esc_html_e('Label, icon & colors', 'replynext-ai-chat-assistant-for-woocommerce'); ?></summary><?php $this->field('call_label', __('Label', 'replynext-ai-chat-assistant-for-woocommerce'), $o['call_label'], __('Call us', 'replynext-ai-chat-assistant-for-woocommerce')); ?><label class="rn-check"><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[call_show_label]" value="1" <?php checked($o['call_show_label'], '1'); ?> /> <?php esc_html_e('Show label', 'replynext-ai-chat-assistant-for-woocommerce'); ?></label><?php $this->contact_icon_picker(__('Phone icon', 'replynext-ai-chat-assistant-for-woocommerce'), 'phone_icon_id', 'rn-phone-icon-id', $o['phone_icon_id']); ?><div class="rn-inline-fields"><label class="rn-field"><span><?php esc_html_e('Background color', 'replynext-ai-chat-assistant-for-woocommerce'); ?></span><input type="color" name="<?php echo esc_attr(self::OPTION_KEY); ?>[call_color]" value="<?php echo esc_attr($o['call_color']); ?>" /></label><label class="rn-field"><span><?php esc_html_e('Icon/text color', 'replynext-ai-chat-assistant-for-woocommerce'); ?></span><input type="color" name="<?php echo esc_attr(self::OPTION_KEY); ?>[call_text_color]" value="<?php echo esc_attr($o['call_text_color']); ?>" /></label></div></details></section>
            <details class="rn-card rn-contact-card rn-contact-appearance"><summary><?php esc_html_e('Launcher', 'replynext-ai-chat-assistant-for-woocommerce'); ?></summary><?php $this->field('contact_label', __('Launcher label', 'replynext-ai-chat-assistant-for-woocommerce'), $o['contact_label'], __('Contact Us', 'replynext-ai-chat-assistant-for-woocommerce')); ?><label class="rn-check"><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[contact_show_label]" value="1" <?php checked($o['contact_show_label'], '1'); ?> /> <?php esc_html_e('Show label', 'replynext-ai-chat-assistant-for-woocommerce'); ?></label><?php $this->contact_icon_picker(__('Launcher icon', 'replynext-ai-chat-assistant-for-woocommerce'), 'contact_icon_id', 'rn-contact-icon-id', $o['contact_icon_id']); ?><div class="rn-inline-fields"><label class="rn-field"><span><?php esc_html_e('Background color', 'replynext-ai-chat-assistant-for-woocommerce'); ?></span><input type="color" name="<?php echo esc_attr(self::OPTION_KEY); ?>[contact_color]" value="<?php echo esc_attr($o['contact_color']); ?>" /></label><label class="rn-field"><span><?php esc_html_e('Icon/text color', 'replynext-ai-chat-assistant-for-woocommerce'); ?></span><input type="color" name="<?php echo esc_attr(self::OPTION_KEY); ?>[contact_text_color]" value="<?php echo esc_attr($o['contact_text_color']); ?>" /></label></div></details>
        </div><?php submit_button(__('Save Contact Channels Settings', 'replynext-ai-chat-assistant-for-woocommerce')); ?></form></div><?php
    }

    public function display_page() {
        $o = $this->options();
        $targets = get_posts(array('post_type' => array('page', 'post'), 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC'));
        $include = explode(',', $o['include_pages']);
        $exclude = explode(',', $o['exclude_pages']);
        $listed_ids = array_map(static function ($target) { return (string) $target->ID; }, $targets);
        ?><div class="wrap rn-wrap rn-display-page">
            <?php $this->page_header(__('Display Rules', 'replynext-ai-chat-assistant-for-woocommerce'), __('Choose where the AI chat and contact buttons appear on your storefront.', 'replynext-ai-chat-assistant-for-woocommerce')); ?>
            <?php $this->form_open(); $this->preserve_fields(array('show_on', 'include_pages', 'exclude_pages', 'hide_mobile', 'hide_desktop')); ?>
            <section class="rn-card rn-display-card" data-rn-display-rules>
                <h2><?php esc_html_e('Page Visibility', 'replynext-ai-chat-assistant-for-woocommerce'); ?></h2>
                <p><?php esc_html_e('In All public pages mode, new pages are shown automatically. Uncheck a page to hide both launchers there.', 'replynext-ai-chat-assistant-for-woocommerce'); ?></p>
                <label class="rn-field"><span><?php esc_html_e('Visibility mode', 'replynext-ai-chat-assistant-for-woocommerce'); ?></span>
                    <select name="<?php echo esc_attr(self::OPTION_KEY); ?>[show_on]" data-rn-display-mode>
                        <option value="all" <?php selected($o['show_on'], 'all'); ?>><?php esc_html_e('All public pages', 'replynext-ai-chat-assistant-for-woocommerce'); ?></option>
                        <option value="include" <?php selected($o['show_on'], 'include'); ?>><?php esc_html_e('Only selected pages/posts', 'replynext-ai-chat-assistant-for-woocommerce'); ?></option>
                    </select>
                </label>
                <input type="hidden" data-rn-display-include name="<?php echo esc_attr(self::OPTION_KEY); ?>[include_pages]" value="<?php echo esc_attr($o['include_pages']); ?>" data-preserve="<?php echo esc_attr(implode(',', array_diff($include, $listed_ids))); ?>" />
                <input type="hidden" data-rn-display-exclude name="<?php echo esc_attr(self::OPTION_KEY); ?>[exclude_pages]" value="<?php echo esc_attr($o['exclude_pages']); ?>" data-preserve="<?php echo esc_attr(implode(',', array_diff($exclude, $listed_ids))); ?>" />
                <div class="rn-display-toolbar">
                    <label><?php esc_html_e('Find a page or post', 'replynext-ai-chat-assistant-for-woocommerce'); ?><input type="search" data-rn-display-filter /></label>
                    <span data-rn-display-count aria-live="polite"></span>
                </div>
                <div class="rn-page-list rn-display-list">
                    <?php if (!$targets) : ?><p><?php esc_html_e('No published pages or posts found.', 'replynext-ai-chat-assistant-for-woocommerce'); ?></p><?php endif; ?>
                    <?php foreach ($targets as $target) :
                        $id = (string) $target->ID;
                        $all = !in_array($id, $exclude, true);
                        $selected = $all && in_array($id, $include, true);
                    ?>
                    <label data-rn-display-row><input type="checkbox" data-rn-display-page data-all="<?php echo $all ? '1' : '0'; ?>" data-selected="<?php echo $selected ? '1' : '0'; ?>" value="<?php echo esc_attr($id); ?>" <?php checked('all' === $o['show_on'] ? $all : $selected); ?> /><span><?php echo esc_html($target->post_title ?: __('(no title)', 'replynext-ai-chat-assistant-for-woocommerce')); ?></span><small><?php echo 'page' === $target->post_type ? esc_html__('Page', 'replynext-ai-chat-assistant-for-woocommerce') : esc_html__('Post', 'replynext-ai-chat-assistant-for-woocommerce'); ?></small></label>
                    <?php endforeach; ?>
                </div>
                <noscript><p><?php esc_html_e('Enable JavaScript to edit individual page checkboxes.', 'replynext-ai-chat-assistant-for-woocommerce'); ?></p></noscript>
            </section>
            <section class="rn-card rn-display-card">
                <h2><?php esc_html_e('Device Visibility', 'replynext-ai-chat-assistant-for-woocommerce'); ?></h2>
                <p><?php esc_html_e('These settings apply to both launchers. Leave both unchecked to show them on all devices.', 'replynext-ai-chat-assistant-for-woocommerce'); ?></p>
                <div class="rn-display-devices">
                    <label class="rn-check"><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[hide_mobile]" value="1" <?php checked($o['hide_mobile'], '1'); ?> /> <?php esc_html_e('Hide on mobile', 'replynext-ai-chat-assistant-for-woocommerce'); ?></label>
                    <label class="rn-check"><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[hide_desktop]" value="1" <?php checked($o['hide_desktop'], '1'); ?> /> <?php esc_html_e('Hide on desktop', 'replynext-ai-chat-assistant-for-woocommerce'); ?></label>
                </div>
            </section>
            <div class="rn-ai-save"><?php submit_button(__('Save Display Rules', 'replynext-ai-chat-assistant-for-woocommerce')); ?></div>
            </form>
        </div><?php
    }
    public function redirect_legacy_connection_page() {
        global $pagenow;
        // Read-only navigation for saved bookmarks; never submit connection settings here.
        if ('admin.php' !== $pagenow || !current_user_can('manage_options') || !isset($_GET['page']) ||
            !is_string($_GET['page']) || 'replynextai-connection' !== sanitize_key(wp_unslash($_GET['page']))) return;
        wp_safe_redirect(admin_url('admin.php?page=replynextai-ai'));
        exit;
    }
    public function handle_test_connection() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Unauthorized', 'replynext-ai-chat-assistant-for-woocommerce'));
        check_admin_referer('replynextai_test_connection');
        delete_transient('replynextai_status_cache');
        $authenticated_options = $this->options();
        $status = $this->remote_status(true);
        $success = (bool) $status;
        if ($success && !empty($status['company']['id'])) {
            $options = $this->options();
            $same_credentials = hash_equals((string) $authenticated_options['api_token'], (string) $options['api_token']) && hash_equals((string) $authenticated_options['server_url'], (string) $options['server_url']);
            $widget_id = false;
            if ($same_credentials) $widget_id = $this->provision_widget($options);
            $verified_company_id = (string) absint($status['company']['id']);
            if ($widget_id && '0' !== $verified_company_id && $same_credentials) {
                if (!$this->save_verified_company($options, $verified_company_id)) {
                    $success = false;
                    $this->last_widget_provision_error = 'company_save';
                }
            } else {
                $success = false;
            }
        } elseif ($success) {
            $success = false;
            $this->last_widget_provision_error = 'authorization';
        }
        $notice = $success ? 'success' : ('company_save' === $this->last_widget_provision_error ? 'company_save_error' : ('revoked' === $this->last_widget_provision_error ? 'widget_revoked' : ($status ? 'widget_error' : 'error')));
        $return_page = 'replynextai-ai';
        wp_safe_redirect(admin_url('admin.php?page=' . $return_page . '&rn_notice=' . $notice));
        exit;
    }

    /** The ID must come from remote_status(), never request parameters. */
    private function save_verified_company($options, $company_id) {
        if (!is_array($options) || !is_string($company_id) || !preg_match('/^[1-9][0-9]*$/D', $company_id)) return false;
        $options['company_id'] = $company_id;
        // update_option invokes our registered sanitizer; scope this override
        // to the server-authenticated write and always restore normal handling.
        $this->verified_connection_company_id = $company_id;
        try { update_option(self::OPTION_KEY, $options); }
        catch (Throwable $ignored) { return false; }
        finally { $this->verified_connection_company_id = null; }
        $saved = $this->options();
        return (string) $saved['company_id'] === $company_id;
    }

    public function handle_refresh_widget_connection() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Unauthorized', 'replynext-ai-chat-assistant-for-woocommerce'));
        check_admin_referer('replynextai_refresh_widget_connection');

        $previous_widget_id = sanitize_text_field((string) get_option('replynextai_widget_id', ''));
        delete_option('replynextai_widget_id');
        $widget_id = $this->provision_widget($this->options());
        if (!$widget_id && $previous_widget_id) update_option('replynextai_widget_id', $previous_widget_id, false);

        wp_safe_redirect(admin_url('admin.php?page=replynextai-ai&rn_widget_refresh=' . ($widget_id ? 'success' : 'error')));
        exit;
    }

    private function remote_status($force = false) {
        if (!$force) {
            $cached = get_transient('replynextai_status_cache');
            if (is_array($cached)) return $cached;
        }
        $o = $this->options();
        if (!$o['api_token'] || !$o['server_url']) return false;
        $response = wp_remote_get($o['server_url'] . '/api/wordpress/status', array(
            'timeout' => 15,
            'headers' => array('Authorization' => 'Bearer ' . $o['api_token'], 'Accept' => 'application/json'),
        ));
        if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) return false;
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['success'])) return false;
        set_transient('replynextai_status_cache', $body, 5 * MINUTE_IN_SECONDS);
        return $body;
    }

    public function woocommerce_page() {
        $available = class_exists('WooCommerce');
        $status = $available ? $this->catalog_sync_view() : array('phase' => 'idle', 'message' => '', 'marker' => '');
        $busy = $available && in_array($status['phase'], array('queued', 'running'), true);
        $payment_links = get_option(self::CHAT_PAYMENT_LINKS_OPTION, '0');
        $payment_links_with_cod = get_option(self::CHAT_PAYMENT_LINKS_WITH_COD_OPTION, '0');
        ?><div class="wrap rn-wrap rn-commerce-page">
            <?php $this->page_header('', '', 'commerce'); ?>
            <div class="rn-commerce-stack">
                <div data-rn-catalog-sync data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" data-phase="<?php echo esc_attr($status['phase']); ?>" data-marker="<?php echo esc_attr($status['marker']); ?>" data-starting="<?php esc_attr_e('Starting product sync…', 'replynext-ai-chat-assistant-for-woocommerce'); ?>" data-unreachable="<?php esc_attr_e('Could not reach WordPress. Reload this page, then click Sync Catalog Now to resume.', 'replynext-ai-chat-assistant-for-woocommerce'); ?>" data-waiting="<?php esc_attr_e('No recent sync progress. Click Sync Catalog Now to resume from the saved batch.', 'replynext-ai-chat-assistant-for-woocommerce'); ?>" data-expired="<?php esc_attr_e('Your WordPress session expired. Reload this page before trying again.', 'replynext-ai-chat-assistant-for-woocommerce'); ?>">
                    <header class="rn-ai-heading rn-commerce-heading">
                        <div><span class="rn-ai-module"><?php esc_html_e('WooCommerce Commerce Module', 'replynext-ai-chat-assistant-for-woocommerce'); ?></span><h1><?php esc_html_e('Catalog Sync & Live Verification', 'replynext-ai-chat-assistant-for-woocommerce'); ?></h1><p><?php esc_html_e('Sync published products in small batches. Manual sync does not need WP-Cron.', 'replynext-ai-chat-assistant-for-woocommerce'); ?></p></div>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="replynextai_sync_products" />
                        <?php wp_nonce_field('replynextai_sync_products'); ?>
                        <button type="submit" class="button button-primary" data-rn-sync-submit data-busy-label="<?php esc_attr_e('Syncing products…', 'replynext-ai-chat-assistant-for-woocommerce'); ?>" data-idle-label="<?php esc_attr_e('Sync Catalog Now', 'replynext-ai-chat-assistant-for-woocommerce'); ?>" data-available="<?php echo $available ? '1' : '0'; ?>" <?php disabled(!$available); ?>><?php esc_html_e('Sync Catalog Now', 'replynext-ai-chat-assistant-for-woocommerce'); ?></button>
                    </form>
                    </header>
                    <section class="rn-card rn-commerce-status">
                        <div class="rn-commerce-status-top"><span class="rn-dash-icon rn-tone-blue dashicons dashicons-products" aria-hidden="true"></span><h2><?php esc_html_e('Synchronization Status', 'replynext-ai-chat-assistant-for-woocommerce'); ?></h2><span class="rn-status <?php echo $available ? 'is-live' : 'is-off'; ?>"><?php echo $available ? esc_html__('WooCommerce detected', 'replynext-ai-chat-assistant-for-woocommerce') : esc_html__('Activate WooCommerce', 'replynext-ai-chat-assistant-for-woocommerce'); ?></span></div>
                        <p class="rn-catalog-progress" role="status" aria-live="polite" aria-atomic="true"><span class="rn-catalog-spinner" data-rn-sync-spinner aria-hidden="true" <?php echo $busy ? '' : 'hidden'; ?>></span><strong data-rn-sync-message><?php echo esc_html($status['message']); ?></strong></p>
                        <div class="rn-commerce-progress-track" aria-hidden="true"><span></span></div>
                    </section>
                </div>
                <div class="rn-card">
                    <h2><?php esc_html_e('Automatic updates', 'replynext-ai-chat-assistant-for-woocommerce'); ?></h2>
                    <p><?php esc_html_e('After a successful connection, product saves schedule a background catalog refresh.', 'replynext-ai-chat-assistant-for-woocommerce'); ?></p>
                    <p><?php esc_html_e('Requirements: WooCommerce active and a valid ReplyNext WordPress token.', 'replynext-ai-chat-assistant-for-woocommerce'); ?></p>
                </div>
                <?php if ($available) : ?>
                    <div class="rn-card">
                        <h2><?php esc_html_e('Chat payment links', 'replynext-ai-chat-assistant-for-woocommerce'); ?></h2>
                        <form method="post" action="<?php echo esc_url(admin_url('options.php')); ?>">
                            <?php settings_fields('replynextai_woocommerce_group'); ?>
                            <label class="rn-check"><input type="checkbox" name="<?php echo esc_attr(self::CHAT_PAYMENT_LINKS_OPTION); ?>" value="1" <?php checked($payment_links, '1'); ?> /> <?php esc_html_e('Allow order-specific payment links when cash on delivery is disabled', 'replynext-ai-chat-assistant-for-woocommerce'); ?></label>
                            <label class="rn-check"><input type="checkbox" name="<?php echo esc_attr(self::CHAT_PAYMENT_LINKS_WITH_COD_OPTION); ?>" value="1" <?php checked($payment_links_with_cod, '1'); ?> /> <?php esc_html_e('Also offer payment links if cash on delivery is enabled (requires the setting above)', 'replynext-ai-chat-assistant-for-woocommerce'); ?></label>
                            <p><?php esc_html_e('Also enable WooCommerce chat checkout in the ReplyNext portal. A supported, confirmed order remains unpaid until the customer pays through WooCommerce. ReplyNext does not collect card details.', 'replynext-ai-chat-assistant-for-woocommerce'); ?></p>
                            <?php submit_button(__('Save Checkout Settings', 'replynext-ai-chat-assistant-for-woocommerce')); ?>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div><?php
    }

    public function handle_sync_products() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Unauthorized', 'replynext-ai-chat-assistant-for-woocommerce'));
        check_admin_referer('replynextai_sync_products');
        $this->sync_products(true);
        wp_safe_redirect(admin_url('admin.php?page=replynextai-woocommerce'));
        exit;
    }

    private function authorize_catalog_ajax() {
        if (!current_user_can('manage_options') || !check_ajax_referer('replynextai_sync_products', '_ajax_nonce', false)) {
            wp_send_json_error(array('code' => 'sync_access_denied'), 403);
        }
    }

    public function ajax_sync_products() {
        $this->authorize_catalog_ajax();
        $this->run_catalog_ajax(null);
    }

    public function ajax_sync_products_status() {
        $this->authorize_catalog_ajax();
        $marker = isset($_POST['marker']) && is_string($_POST['marker']) ? sanitize_text_field(wp_unslash($_POST['marker'])) : '';
        if ('' !== $marker && !wp_is_uuid($marker, 4)) wp_send_json_error(array('code' => 'invalid_sync_marker'), 400);
        wp_send_json_success($this->catalog_sync_view($marker));
    }

    public function ajax_sync_products_step() {
        $this->authorize_catalog_ajax();
        $marker = isset($_POST['marker']) && is_string($_POST['marker']) ? sanitize_text_field(wp_unslash($_POST['marker'])) : '';
        if (!wp_is_uuid($marker, 4)) wp_send_json_error(array('code' => 'invalid_sync_marker'), 400);
        $this->run_catalog_ajax($marker);
    }

    private function catalog_checkpoint() {
        return get_option(self::CATALOG_CHECKPOINT_OPTION, false) ?: get_transient(self::CATALOG_STATE_TRANSIENT);
    }

    private function run_catalog_ajax($marker) {
        $this->sync_products(true, $marker);
        $progress = $this->catalog_progress();
        if ($this->catalog_worker_running()) {
            $progress['phase'] = 'running';
            $progress['message'] = __('Another sync request is finishing. Waiting before continuing…', 'replynext-ai-chat-assistant-for-woocommerce');
        }
        wp_send_json_success($progress);
    }

    private function catalog_worker_running() {
        $lock = get_option(self::CATALOG_LOCK_OPTION, array());
        return is_array($lock) && !empty($lock['token']) && (int) ($lock['expires'] ?? 0) >= time();
    }

    private function catalog_queue_claim_pending() {
        $mark = get_option(self::CATALOG_QUEUED_OPTION, false);
        return is_array($mark) && (int) ($mark['claimed_at'] ?? 0) > time() - 15;
    }

    private function catalog_progress() {
        $data = get_transient(self::CATALOG_PROGRESS_TRANSIENT);
        return is_array($data) ? $data : array('phase' => 'idle', 'message' => '', 'marker' => '');
    }

    private function record_catalog_progress($phase, $message) {
        $status = array('phase' => $phase, 'message' => $message, 'marker' => wp_generate_uuid4());
        set_transient(self::CATALOG_PROGRESS_TRANSIENT, $status, DAY_IN_SECONDS);
        if (in_array($phase, array('completed', 'failed'), true)) set_transient('replynextai_last_sync', $message, DAY_IN_SECONDS);
        return $status;
    }

    private function catalog_sync_view($after = null) {
        $progress = $this->catalog_progress();
        $done = in_array($progress['phase'], array('completed', 'failed'), true);
        // The browser that started a sync must see a new result, never the
        // preceding run's product count. Before following a job, show the last result.
        if ($done && null !== $after && $progress['marker'] !== $after) return $progress;
        if ($this->catalog_worker_running()) {
            return 'running' === $progress['phase'] ? $progress : array('phase' => 'running', 'message' => __('Syncing products…', 'replynext-ai-chat-assistant-for-woocommerce'), 'marker' => $progress['marker']);
        }
        if ($this->catalog_checkpoint() && in_array($progress['phase'], array('queued', 'running'), true)) return $progress;
        if ('' === $after && $done) return $progress;
        $scheduled = wp_next_scheduled('replynextai_deferred_product_sync');
        if ($scheduled && (int) $scheduled < time() - 90) {
            return array('phase' => 'failed', 'message' => __('The background sync has not started. Click Sync Products Now to process it directly.', 'replynext-ai-chat-assistant-for-woocommerce'), 'marker' => $progress['marker']);
        }
        if ($scheduled || $this->catalog_queue_claim_pending()) {
            return 'queued' === $progress['phase'] ? $progress : array('phase' => 'queued', 'message' => __('Product synchronization scheduled in the background.', 'replynext-ai-chat-assistant-for-woocommerce'), 'marker' => $progress['marker']);
        }
        if (in_array($progress['phase'], array('queued', 'running'), true)) {
            return array('phase' => 'failed', 'message' => __('Sync stopped before it could finish. Please try again.', 'replynext-ai-chat-assistant-for-woocommerce'), 'marker' => $progress['marker']);
        }
        if (null !== $after) return array('phase' => 'queued', 'message' => __('Waiting for the current sync result…', 'replynext-ai-chat-assistant-for-woocommerce'), 'marker' => $progress['marker']);
        if ($done) return $progress;
        $legacy = get_transient('replynextai_last_sync');
        return array('phase' => 'idle', 'message' => is_string($legacy) ? $legacy : '', 'marker' => $progress['marker']);
    }

    public function schedule_product_sync($post_id, $post, $update) {
        if (wp_is_post_revision($post_id) || 'product' !== $post->post_type || (!$update && 'publish' !== $post->post_status)) return;
        set_transient(self::CATALOG_DIRTY_TRANSIENT, '1', DAY_IN_SECONDS);
        $this->queue_catalog_sync(60);
    }

    private function queue_catalog_sync($delay = 60, $recover_stale_queue = false) {
        // add_option is atomic, preventing save_post/manual requests from
        // enqueueing duplicate workers before WP-Cron observes the event.
        if (wp_next_scheduled('replynextai_deferred_product_sync')) return true;
        $claim = array('claimed_at' => time());
        if (!add_option(self::CATALOG_QUEUED_OPTION, $claim, '', 'no')) {
            // A prior request may have died after setting the queue marker but
            // before the cron event was saved. Only a deliberate admin retry
            // may recover that orphaned marker; product-save workers never do.
            if (!$recover_stale_queue || $this->catalog_worker_running() || $this->catalog_queue_claim_pending()) return false;
            delete_option(self::CATALOG_QUEUED_OPTION);
            if (!add_option(self::CATALOG_QUEUED_OPTION, $claim, '', 'no')) return false;
        }
        $scheduled = wp_schedule_single_event(time() + max(1, (int) $delay), 'replynextai_deferred_product_sync');
        if (false === $scheduled || is_wp_error($scheduled)) {
            // WordPress can report a duplicate event as false. Treat it as
            // successful only when the event is actually present.
            if (wp_next_scheduled('replynextai_deferred_product_sync')) return true;
            delete_option(self::CATALOG_QUEUED_OPTION);
            return false;
        }
        return true;
    }

    private function acquire_lock($option_name, $ttl) {
        $token = wp_generate_uuid4();
        $lock = array('token' => $token, 'expires' => time() + (int) $ttl);
        if (add_option($option_name, $lock, '', 'no')) return $token;
        $existing = get_option($option_name, array());
        if (is_array($existing) && !empty($existing['expires']) && (int) $existing['expires'] < time()) {
            delete_option($option_name);
            if (add_option($option_name, $lock, '', 'no')) return $token;
        }
        return '';
    }

    private function release_lock($option_name, $token) {
        $existing = get_option($option_name, array());
        if (is_array($existing) && isset($existing['token']) && hash_equals((string) $existing['token'], (string) $token)) delete_option($option_name);
    }

    private function send_catalog_batch($options, $endpoint, $payload) {
        $response = wp_remote_post($options['server_url'] . $endpoint, array(
            'timeout' => 15,
            'headers' => array('Authorization' => 'Bearer ' . $options['api_token'], 'Content-Type' => 'application/json'),
            'body' => wp_json_encode($payload),
        ));
        if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) return null;
        $data = json_decode(wp_remote_retrieve_body($response), true);
        return is_array($data) && true === ($data['success'] ?? false) ? $data : null;
    }

    private function retry_catalog_batch($state, $manual) {
        $state['attempts'] = 1 + (int) ($state['attempts'] ?? 0);
        update_option(self::CATALOG_CHECKPOINT_OPTION, $state, false);
        if ($manual) {
            $this->record_catalog_progress($state['attempts'] < 3 ? 'queued' : 'failed', $state['attempts'] < 3
                ? __('Batch not confirmed. Retrying from the saved position…', 'replynext-ai-chat-assistant-for-woocommerce')
                : __('ReplyNext did not confirm the batch. Check the plugin connection and your host’s outbound HTTPS, then click Sync Products Now to resume.', 'replynext-ai-chat-assistant-for-woocommerce'));
            return false;
        }
        $retry = $this->queue_catalog_sync(300);
        $this->record_catalog_progress('failed', $retry
            ? __('Product sync did not finish. A background retry is scheduled.', 'replynext-ai-chat-assistant-for-woocommerce')
            : __('Product sync did not finish. Check your ReplyNext connection and try again.', 'replynext-ai-chat-assistant-for-woocommerce'));
        return false;
    }

    public function sync_products($manual = false, $marker = null) {
        $lock_token = $this->acquire_catalog_lock();
        if (!$lock_token) {
            if (!$manual) $this->queue_catalog_sync(60);
            return false;
        }
        try {
            $progress = $this->catalog_progress();
            if ($manual && null !== $marker && ($marker !== $progress['marker'] || in_array($progress['phase'], array('completed', 'failed'), true))) return true;
            if (!$manual) delete_option(self::CATALOG_QUEUED_OPTION);
            return $this->sync_catalog_batch($manual, $manual && null === $marker);
        } catch (Throwable $ignored) {
            $this->record_catalog_progress('failed', __('Product sync stopped unexpectedly. Please try again.', 'replynext-ai-chat-assistant-for-woocommerce'));
            return false;
        } finally {
            $this->release_catalog_lock($lock_token);
        }
    }

    private function acquire_catalog_lock() {
        global $wpdb;
        $token = wp_generate_uuid4();
        $lock = array('token' => $token, 'expires' => time() + 300);
        if (1 === $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::CATALOG_LOCK_OPTION, maybe_serialize($lock)))) {
            wp_cache_delete(self::CATALOG_LOCK_OPTION, 'options');
            wp_cache_delete('notoptions', 'options');
            return $token;
        }
        $previous = get_option(self::CATALOG_LOCK_OPTION);
        if (!is_array($previous) || (int) ($previous['expires'] ?? 0) >= time()) return '';
        return $this->replace_catalog_lock($previous, $lock) ? $token : '';
    }

    private function release_catalog_lock($token) {
        $previous = get_option(self::CATALOG_LOCK_OPTION);
        if (is_array($previous) && ($previous['token'] ?? '') === $token) $this->replace_catalog_lock($previous, array());
    }

    private function replace_catalog_lock($expected, $replacement) {
        global $wpdb;
        if (!$replacement) {
            $changed = $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name=%s AND BINARY option_value=BINARY %s", self::CATALOG_LOCK_OPTION, maybe_serialize($expected)));
        } else {
            $changed = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND BINARY option_value=BINARY %s", maybe_serialize($replacement), self::CATALOG_LOCK_OPTION, maybe_serialize($expected)));
        }
        wp_cache_delete(self::CATALOG_LOCK_OPTION, 'options');
        wp_cache_delete('notoptions', 'options');
        return 1 === $changed;
    }

    private function sync_catalog_batch($manual = false, $restart = false) {
        $o = $this->options();
        if (!function_exists('wc_get_products') || !$o['api_token'] || !$o['server_url']) {
            $this->record_catalog_progress('failed', __('Connect ReplyNext and activate WooCommerce before syncing products.', 'replynext-ai-chat-assistant-for-woocommerce'));
            return false;
        }
        $state = $this->catalog_checkpoint();
        $binding = hash('sha256', wp_json_encode(array(home_url('/'), $o['server_url'], $o['api_token'])));
        if (!is_array($state) || empty($state['sync_id']) || ($state['binding'] ?? '') !== $binding) $state = array('sync_id' => wp_generate_uuid4(), 'page' => 1, 'total_synced' => 0, 'binding' => $binding, 'batch_size' => 20);
        // Existing checkpoints used 100 rows per page. Do not change their offset.
        $batch_size = 20 === (int) ($state['batch_size'] ?? 100) ? 20 : 100;
        if ($restart) $state['attempts'] = 0;
        if (1 === (int) $state['page'] && empty($state['started'])) {
            delete_transient(self::CATALOG_DIRTY_TRANSIENT);
            $state['started'] = true;
        }
        update_option(self::CATALOG_CHECKPOINT_OPTION, $state, false);
        $page = max(1, absint($state['page']));
        $sync_id = sanitize_text_field($state['sync_id']);
        $total_synced = absint($state['total_synced']);
        $this->record_catalog_progress('running', __('Syncing products…', 'replynext-ai-chat-assistant-for-woocommerce'));
        if (!empty($state['finalizing'])) return $this->finish_catalog_sync($o, $state, $manual);
        $products = array();
        $index_products = array();
        $batch = wc_get_products(array('status' => 'publish', 'limit' => $batch_size, 'page' => $page, 'orderby' => 'ID', 'order' => 'ASC', 'paginate' => true, 'return' => 'objects'));
        if (!is_object($batch) || !isset($batch->products) || !is_array($batch->products)) return $this->retry_catalog_batch($state, $manual);
        $batch_products = $batch->products;
        foreach ($batch_products as $product) {
            $image_id = $product->get_image_id();
            $category_names = wp_get_post_terms($product->get_id(), 'product_cat', array('fields' => 'names'));
            $products[] = array(
                'id' => $product->get_id(),
                'name' => $product->get_name(),
                'price' => $product->get_price(),
                'description' => wp_strip_all_tags($product->get_short_description() ?: $product->get_description()),
                'category' => implode(', ', $category_names),
                'url' => get_permalink($product->get_id()),
                'imageUrl' => $image_id ? wp_get_attachment_image_url($image_id, 'full') : '',
            );
            $attributes = array();
            foreach ($product->get_attributes() as $attribute) {
                if (is_object($attribute) && method_exists($attribute, 'get_name')) $attributes[] = sanitize_text_field($attribute->get_name());
            }
            $index_products[] = array(
                'externalProductId' => (string) $product->get_id(),
                'title' => $product->get_name(),
                'handle' => $product->get_slug(),
                'productUrl' => get_permalink($product->get_id()),
                'imageUrl' => $image_id ? wp_get_attachment_image_url($image_id, 'full') : null,
                'searchableText' => wp_strip_all_tags($product->get_short_description() ?: $product->get_description()),
                'vendor' => $product->get_attribute('pa_brand'),
                'productType' => implode(', ', $category_names),
                'productStatus' => $product->get_status(),
                'optionSummary' => array_values(array_filter($attributes)),
                'sourceUpdatedAt' => get_post_modified_time('c', true, $product->get_id()),
            );
        }
        if ($products) {
            $body = $this->send_catalog_batch($o, '/api/wordpress/products', array('siteUrl' => home_url('/'), 'sync_id' => $sync_id, 'products' => $products));
            if (($body['synced'] ?? null) !== count($products)) return $this->retry_catalog_batch($state, $manual);
            $index = $this->send_catalog_batch($o, '/api/wordpress/catalog', array('sync_id' => $sync_id, 'products' => $index_products));
            // Do not finalize an incomplete index: finalization retires older entries.
            if (($index['indexed'] ?? null) !== count($index_products)) return $this->retry_catalog_batch($state, $manual);
            $total_synced += $body['synced'];
        }
        if (count($batch_products) === $batch_size || ($manual && $products)) {
            update_option(self::CATALOG_CHECKPOINT_OPTION, array('sync_id' => $sync_id, 'page' => $page + 1, 'total_synced' => $total_synced, 'started' => true, 'binding' => $binding, 'batch_size' => $batch_size, 'finalizing' => count($batch_products) < $batch_size), false);
            if (!$manual && !$this->queue_catalog_sync(5)) {
                $this->record_catalog_progress('failed', __('The next product batch could not be scheduled. Try again to continue.', 'replynext-ai-chat-assistant-for-woocommerce'));
                return false;
            }
            /* translators: %d: number of products synchronized so far. */
            $this->record_catalog_progress('queued', sprintf(__('Synced %d products so far. Waiting for the next batch…', 'replynext-ai-chat-assistant-for-woocommerce'), $total_synced));
            return true;
        }
        $state['total_synced'] = $total_synced;
        $state['finalizing'] = true;
        update_option(self::CATALOG_CHECKPOINT_OPTION, $state, false);
        return $this->finish_catalog_sync($o, $state, $manual);
    }

    private function finish_catalog_sync($o, $state, $manual) {
        $finished = array('sync_id' => $state['sync_id'], 'sync_complete' => true, 'products' => array());
        if (!$this->send_catalog_batch($o, '/api/wordpress/products', array('siteUrl' => home_url('/')) + $finished)
            || !$this->send_catalog_batch($o, '/api/wordpress/catalog', $finished)) return $this->retry_catalog_batch($state, $manual);
        delete_option(self::CATALOG_CHECKPOINT_OPTION);
        delete_transient(self::CATALOG_STATE_TRANSIENT);
        if (get_transient(self::CATALOG_DIRTY_TRANSIENT)) $this->queue_catalog_sync(60);
        /* translators: %d: number of products synchronized. */
        $this->record_catalog_progress('completed', sprintf(__('Last sync completed: %d products.', 'replynext-ai-chat-assistant-for-woocommerce'), $state['total_synced']));
        delete_transient('replynextai_status_cache');
        return true;
    }

    private function field($key, $label, $value, $placeholder = '', $type = 'text') {
        printf('<label class="rn-field"><span>%s</span><input type="%s" name="%s[%s]" value="%s" placeholder="%s" /></label>', esc_html($label), esc_attr($type), esc_attr(self::OPTION_KEY), esc_attr($key), esc_attr($value), esc_attr($placeholder));
    }

    public function frontend_assets() {
        if (is_admin() || !$this->display_allowed()) return;
        $o = $this->options();
        if ('1' === $o['contact_enabled'] && (('1' === $o['whatsapp_enabled'] && $o['whatsapp_number']) || ('1' === $o['messenger_enabled'] && $o['messenger_url']) || ('1' === $o['call_enabled'] && $o['call_number']))) {
            wp_enqueue_style('replynextai-frontend', REPLYNEXTAI_URL . 'assets/frontend.css', array(), REPLYNEXTAI_VERSION . '.viewport1');
            wp_enqueue_script('replynextai-frontend', REPLYNEXTAI_URL . 'assets/frontend.js', array(), REPLYNEXTAI_VERSION . '.viewport1', true);
        }
        if ('1' === $o['ai_enabled'] && '1' === $o['ai_sitewide']) $this->enqueue_ai($o);
    }

    private function display_allowed() {
        if (is_admin() || is_feed() || is_robots() || is_trackback()) return false;
        $o = $this->options();
        $id = get_queried_object_id();
        $include = array_filter(array_map('absint', explode(',', $o['include_pages'])));
        $exclude = array_filter(array_map('absint', explode(',', $o['exclude_pages'])));
        if ($id && in_array($id, $exclude, true)) return false;
        if ('include' === $o['show_on'] && (!$id || !in_array($id, $include, true))) return false;
        if ('1' === $o['hide_mobile'] && wp_is_mobile()) return false;
        if ('1' === $o['hide_desktop'] && !wp_is_mobile()) return false;
        return true;
    }

    public function render_sitewide_whatsapp() {
        $o = $this->options();
        if ($this->display_allowed() && '1' === $o['contact_enabled']) echo $this->whatsapp_markup($o); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public function whatsapp_shortcode($attributes) {
        $o = $this->options();
        $a = shortcode_atts(array('number' => $o['whatsapp_number'], 'message' => $o['whatsapp_message'], 'label' => $o['whatsapp_label']), $attributes, 'replynextai_whatsapp');
        $config = array_merge($o, array('whatsapp_enabled' => '1', 'whatsapp_number' => preg_replace('/[^0-9]/', '', (string) $a['number']), 'whatsapp_message' => sanitize_text_field($a['message']), 'whatsapp_label' => sanitize_text_field($a['label'])));
        wp_enqueue_style('replynextai-frontend', REPLYNEXTAI_URL . 'assets/frontend.css', array(), REPLYNEXTAI_VERSION . '.viewport1');
        wp_enqueue_script('replynextai-frontend', REPLYNEXTAI_URL . 'assets/frontend.js', array(), REPLYNEXTAI_VERSION . '.viewport1', true);
        return $this->whatsapp_markup($config);
    }

    private function whatsapp_markup($o) {
        if ($this->whatsapp_rendered) return '';
        $this->whatsapp_rendered = true;
        $buttons = array();
        $wa_index = null;
        $messenger_index = null;
        $phone_index = null;
        if ('1' === $o['whatsapp_enabled'] && $o['whatsapp_number']) {
            $url = 'https://wa.me/' . rawurlencode($o['whatsapp_number']) . '?text=' . rawurlencode($o['whatsapp_message']);
            $wa_index = count($buttons);
            $buttons[] = sprintf('<a class="rn-wa-button rn-wa-%s" href="%s" target="_blank" rel="noopener noreferrer" aria-label="%s" style="--rn-wa-color:%s" data-rn-delay="%d"><span class="rn-wa-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M20 4.5A10 10 0 0 0 3.8 17.2L3 21l3.9-1A10 10 0 1 0 20 4.5Zm-8 15a8 8 0 0 1-4-1.1l-.3-.2-2.3.6.6-2.2-.2-.3A8 8 0 1 1 12 19.5Zm4.4-5.9c-.2-.1-1.4-.7-1.6-.8-.2-.1-.4-.1-.6.1l-.7.9c-.1.2-.3.2-.5.1a6.4 6.4 0 0 1-1.9-1.2 7 7 0 0 1-1.3-1.7c-.1-.2 0-.4.1-.5l.4-.5.2-.4c.1-.1 0-.3 0-.4l-.7-1.6c-.2-.4-.4-.4-.6-.4h-.5c-.2 0-.4.1-.6.3-.2.2-.8.8-.8 2s.8 2.3.9 2.5c.1.2 1.6 2.5 3.9 3.5.5.2.9.3 1.2.4.5.2 1 .1 1.4.1.4-.1 1.4-.6 1.6-1.1.2-.5.2-.9.1-1Z"/></svg></span><span class="rn-wa-label">%s</span></a>', 'right' === $o['whatsapp_position'] ? 'right' : 'left', esc_url($url), esc_attr($o['whatsapp_label']), esc_attr($o['whatsapp_color']), absint($o['whatsapp_delay']), esc_html($o['whatsapp_label']));
        }
        if ('1' === $o['messenger_enabled'] && $o['messenger_url']) { $messenger_index = count($buttons); $buttons[] = sprintf('<a class="rn-wa-button rn-wa-%s" href="%s" target="_blank" rel="noopener noreferrer" aria-label="%s" style="--rn-wa-color:#1877f2"><span class="rn-wa-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M12 2C6.5 2 2 6.1 2 11.1c0 2.9 1.5 5.5 4 7.2V22l3.5-1.9c.8.2 1.6.3 2.5.3 5.5 0 10-4.1 10-9.3S17.5 2 12 2Zm1 12.2-2.6-2.8-5 2.8 5.5-5.8 2.6 2.8 4.9-2.8-5.4 5.8Z"/></svg></span><span class="rn-wa-label">%s</span></a>', 'right' === $o['whatsapp_position'] ? 'right' : 'left', esc_url($o['messenger_url']), esc_attr($o['messenger_label']), esc_html($o['messenger_label'])); }
        if ('1' === $o['call_enabled'] && $o['call_number']) { $phone_index = count($buttons); $buttons[] = sprintf('<a class="rn-wa-button rn-wa-%s" href="%s" aria-label="%s" style="--rn-wa-color:#334155"><span class="rn-wa-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M6.6 2.8 9 2.2c.6-.1 1.2.2 1.5.8l1.2 2.8c.2.5.1 1-.2 1.4L10 8.9a13.6 13.6 0 0 0 5.1 5.1l1.7-1.5c.4-.3.9-.4 1.4-.2l2.8 1.2c.6.3.9.9.8 1.5l-.6 2.4c-.2.8-.9 1.4-1.7 1.5C10.3 19.8 4.2 13.7 5.1 4.5c.1-.8.7-1.5 1.5-1.7Z"/></svg></span><span class="rn-wa-label">%s</span></a>', 'right' === $o['whatsapp_position'] ? 'right' : 'left', esc_url('tel:' . $o['call_number']), esc_attr($o['call_label']), esc_html($o['call_label'])); }
        if (null !== $wa_index && '1' !== $o['whatsapp_show_label']) { $buttons[$wa_index] = str_replace('rn-wa-button ', 'rn-wa-button rn-icon-only ', $buttons[$wa_index]); $buttons[$wa_index] = preg_replace('/<span class="rn-wa-label">.*?<\/span>/', '', $buttons[$wa_index]); }
        if (null !== $wa_index && !empty($o['whatsapp_icon_id'])) {
            $wa_icon = wp_get_attachment_image_url((int) $o['whatsapp_icon_id'], 'thumbnail');
            if ($wa_icon) $buttons[$wa_index] = str_replace('<svg viewBox="0 0 24 24" focusable="false">', '<img src="' . esc_url($wa_icon) . '" alt="" />', $buttons[$wa_index]);
        }
        if (null !== $wa_index) $buttons[$wa_index] = str_replace('--rn-wa-color:' . $o['whatsapp_color'], '--rn-wa-color:' . $o['whatsapp_color'] . ';--rn-wa-text:' . esc_attr($o['whatsapp_text_color']), $buttons[$wa_index]);
        if (null !== $messenger_index) {
            if ('1' !== $o['messenger_show_label']) { $buttons[$messenger_index] = str_replace('rn-wa-button ', 'rn-wa-button rn-icon-only ', $buttons[$messenger_index]); $buttons[$messenger_index] = preg_replace('/<span class="rn-wa-label">.*?<\/span>/', '', $buttons[$messenger_index]); }
            $mi = $o['messenger_icon_id'] ? wp_get_attachment_image_url((int) $o['messenger_icon_id'], 'thumbnail') : '';
            if ($mi) $buttons[$messenger_index] = str_replace('<svg viewBox="0 0 24 24" focusable="false">', '<img src="' . esc_url($mi) . '" alt="" />', $buttons[$messenger_index]);
            $buttons[$messenger_index] = str_replace('style="--rn-wa-color:#1877f2"', 'style="--rn-wa-color:' . esc_attr($o['messenger_color']) . ';--rn-wa-text:' . esc_attr($o['messenger_text_color']) . '"', $buttons[$messenger_index]);
        }
        if (null !== $phone_index) {
            if ('1' !== $o['call_show_label']) { $buttons[$phone_index] = str_replace('rn-wa-button ', 'rn-wa-button rn-icon-only ', $buttons[$phone_index]); $buttons[$phone_index] = preg_replace('/<span class="rn-wa-label">.*?<\/span>/', '', $buttons[$phone_index]); }
            $pi = $o['phone_icon_id'] ? wp_get_attachment_image_url((int) $o['phone_icon_id'], 'thumbnail') : '';
            if ($pi) $buttons[$phone_index] = str_replace('<svg viewBox="0 0 24 24" focusable="false">', '<img src="' . esc_url($pi) . '" alt="" />', $buttons[$phone_index]);
            $buttons[$phone_index] = str_replace('style="--rn-wa-color:#334155"', 'style="--rn-wa-color:' . esc_attr($o['call_color']) . ';--rn-wa-text:' . esc_attr($o['call_text_color']) . '"', $buttons[$phone_index]);
        }
        if (!$buttons) return '';
        $menu = implode('', $buttons);
        $icon = $o['contact_icon_id'] ? wp_get_attachment_image_url((int) $o['contact_icon_id'], 'thumbnail') : '';
        $icon_markup = $icon ? sprintf('<img src="%s" alt="" />', esc_url($icon)) : '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 5.5A3.5 3.5 0 0 1 7.5 2h9A3.5 3.5 0 0 1 20 5.5v6a3.5 3.5 0 0 1-3.5 3.5H12l-4.5 4v-4.1A3.5 3.5 0 0 1 4 11.5v-6Zm4 3.2h8m-8 3h5"/></svg>';
        $main_label = $o['contact_label'] ?: __('Contact Us', 'replynext-ai-chat-assistant-for-woocommerce');
        $main_class = 'rn-contact-main' . ('1' === $o['contact_show_label'] ? '' : ' rn-icon-only');
        $main_label_markup = '1' === $o['contact_show_label'] ? '<span class="rn-contact-main-label">' . esc_html($main_label) . '</span>' : '';
        $width = '1' === $o['contact_show_label'] ? 'auto' : '58px';
        $padding = '1' === $o['contact_show_label'] ? '0 18px' : '0';
        return sprintf('<div class="rn-contact-float rn-contact-%s" data-rn-contact-float><div class="rn-contact-menu" data-rn-contact-menu>%s</div><button type="button" class="%s" aria-expanded="false" aria-label="%s" title="%s" data-rn-contact-toggle style="--rn-contact-color:%s;--rn-contact-text:%s;background:%s;color:%s;width:%s;padding:%s;display:flex;gap:8px;align-items:center;justify-content:center"><span class="rn-contact-main-icon">%s</span>%s</button></div>', 'right' === $o['whatsapp_position'] ? 'right' : 'left', $menu, esc_attr($main_class), esc_attr($main_label), esc_attr($main_label), esc_attr($o['contact_color']), esc_attr($o['contact_text_color']), esc_attr($o['contact_color']), esc_attr($o['contact_text_color']), esc_attr($width), esc_attr($padding), $icon_markup, $main_label_markup);
    }

    public function ai_shortcode($attributes) {
        $o = $this->options();
        $a = shortcode_atts(array('company_id' => $o['company_id'], 'server_url' => $o['server_url']), $attributes, 'replynextai_chat');
        $config = array_merge($o, array('company_id' => preg_replace('/[^0-9]/', '', (string) $a['company_id']), 'server_url' => untrailingslashit(esc_url_raw($a['server_url']))));
        if (!$this->enqueue_ai($config) && current_user_can('manage_options')) return '<p class="replynextai-chat-error">' . esc_html__('ReplyNext AI Chat is not configured.', 'replynext-ai-chat-assistant-for-woocommerce') . '</p>';
        return '<span class="replynextai-chat-shortcode" aria-hidden="true"></span>';
    }

    private function enqueue_ai($config) {
        if ($this->ai_config) return true;
        $server_url = isset($config['server_url']) ? untrailingslashit(esc_url_raw($config['server_url'])) : '';
        if (!$server_url || !wp_http_validate_url($server_url) || empty($config['api_token'])) return false;
        $widget_id = sanitize_text_field((string) get_option('replynextai_widget_id', ''));
        if (!$widget_id) $widget_id = $this->provision_widget($config);
        if (!$widget_id) return false;
        $this->ai_config = array('widget_id' => $widget_id, 'name' => '', 'email' => '', 'is_user' => 'false');
        if ('1' === $config['share_user_data'] && is_user_logged_in()) {
            $user = wp_get_current_user();
            $this->ai_config['name'] = $user->display_name;
            $this->ai_config['email'] = $user->user_email;
            $this->ai_config['is_user'] = 'true';
        }
        wp_enqueue_script(self::AI_HANDLE, $server_url . '/chat-widget.js', array(), REPLYNEXTAI_VERSION, true);
        return true;
    }

    private function provision_widget($config) {
        $this->last_widget_provision_error = '';
        $server_url = isset($config['server_url']) ? untrailingslashit(esc_url_raw($config['server_url'])) : '';
        if (!$server_url || !wp_http_validate_url($server_url) || empty($config['api_token'])) {
            $this->last_widget_provision_error = 'authorization';
            return '';
        }
        $lock_token = $this->acquire_lock(self::PROVISION_LOCK_OPTION, 30);
        if (!$lock_token) {
            $this->last_widget_provision_error = 'busy';
            return '';
        }
        try {
        $provision = wp_remote_post($server_url . '/api/wordpress/widget', array(
            'timeout' => 8,
            'headers' => array('Authorization' => 'Bearer ' . $config['api_token'], 'Content-Type' => 'application/json'),
            'body' => wp_json_encode(array('siteUrl' => home_url('/'))),
        ));
        if (is_wp_error($provision)) {
            $this->last_widget_provision_error = 'authorization';
            return '';
        }
        $body = json_decode(wp_remote_retrieve_body($provision), true);
        if (200 !== wp_remote_retrieve_response_code($provision)) {
            $this->last_widget_provision_error = is_array($body) && isset($body['code']) && 'WORDPRESS_WIDGET_REVOKED' === $body['code'] ? 'revoked' : 'authorization';
            return '';
        }
        $widget_id = is_array($body) && !empty($body['widgetId']) ? sanitize_text_field($body['widgetId']) : '';
        if (!$widget_id) $this->last_widget_provision_error = 'authorization';
        if ($widget_id) update_option('replynextai_widget_id', $widget_id, false);
        return $widget_id;
        } finally {
            $this->release_lock(self::PROVISION_LOCK_OPTION, $lock_token);
        }
    }

    public function ai_script_attributes($tag, $handle, $src) {
        if (self::AI_HANDLE !== $handle || !$this->ai_config) return $tag;
        /* phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- This modifies the tag for the already-enqueued external widget to pass its documented data attributes. */
        return sprintf('<script src="%s" data-widget-id="%s" data-visitor-name="%s" data-visitor-email="%s" data-is-user="%s" id="%s-js"></script>', esc_url($src), esc_attr($this->ai_config['widget_id']), esc_attr($this->ai_config['name']), esc_attr($this->ai_config['email']), esc_attr($this->ai_config['is_user']), esc_attr(self::AI_HANDLE));
    }
}
