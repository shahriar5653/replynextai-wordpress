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

    const CATALOG_LOCK_OPTION = 'replynextai_catalog_sync_lock';
    const CATALOG_STATE_TRANSIENT = 'replynextai_catalog_sync_state';
    const CATALOG_DIRTY_TRANSIENT = 'replynextai_catalog_dirty';
    const CATALOG_QUEUED_OPTION = 'replynextai_catalog_sync_queued';
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
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'admin_assets'));
        add_action('admin_post_replynextai_test_connection', array($this, 'handle_test_connection'));
        add_action('admin_post_replynextai_refresh_widget_connection', array($this, 'handle_refresh_widget_connection'));
        add_action('admin_post_replynextai_sync_products', array($this, 'handle_sync_products'));
        add_action('save_post_product', array($this, 'schedule_product_sync'), 20, 3);
        add_action('replynextai_deferred_product_sync', array($this, 'sync_products'));
        add_action('rest_api_init', array($this, 'register_commerce_routes'));
        add_filter('plugin_action_links_' . plugin_basename(REPLYNEXTAI_FILE), array($this, 'plugin_links'));
        add_action('wp_enqueue_scripts', array($this, 'frontend_assets'));
        add_action('wp_footer', array($this, 'render_sitewide_whatsapp'));
        add_filter('script_loader_tag', array($this, 'ai_script_attributes'), 10, 3);
        add_shortcode('replynextai_chat', array($this, 'ai_shortcode'));
        add_shortcode('replynextai_whatsapp', array($this, 'whatsapp_shortcode'));
    }

    private function options() {
        return wp_parse_args(get_option(self::OPTION_KEY, array()), self::defaults());
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
        register_rest_route('replynextai/v1', '/product-verify', array(
            'methods' => 'POST',
            'callback' => array($this, 'verify_woocommerce_products'),
            // The HMAC below authenticates the ReplyNext server. WordPress REST
            // authentication cannot be used because this is server-to-server.
            'permission_callback' => '__return_true',
        ));
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
            if (!$product || 'product' !== get_post_type($product_id)) continue;
            $products[] = $this->live_woocommerce_product($product, $site_url, $facts);
        }
        return new WP_REST_Response(array('success' => true, 'products' => $products), 200);
    }

    private function live_woocommerce_product($product, $site_url, $facts) {
        $include_price = in_array('price', $facts, true) || in_array('sale_price', $facts, true);
        $include_availability = in_array('availability', $facts, true) || in_array('variants', $facts, true);
        $attributes = array();
        foreach ($product->get_attributes() as $attribute) {
            $attributes[] = is_object($attribute) && method_exists($attribute, 'get_name') ? sanitize_text_field($attribute->get_name()) : '';
        }
        $variant_ids = $product->is_type('variable') ? array_slice($product->get_children(), 0, 100) : array($product->get_id());
        $variants = array();
        foreach ($variant_ids as $variant_id) {
            $variant = wc_get_product($variant_id);
            if (!$variant) continue;
            $row = array(
                'id' => (string) $variant->get_id(),
                'title' => sanitize_text_field($variant->get_name()),
                'selectedOptions' => array(),
            );
            foreach ((array) $variant->get_attributes() as $name => $value) {
                $row['selectedOptions'][] = array('name' => sanitize_text_field(str_replace('attribute_', '', (string) $name)), 'value' => sanitize_text_field((string) $value));
            }
            if ($include_price) {
                $row['price'] = (string) $variant->get_price();
                $row['regularPrice'] = (string) $variant->get_regular_price();
                if ($variant->is_on_sale()) $row['salePrice'] = (string) $variant->get_sale_price();
            }
            if ($include_availability) $row['availableForSale'] = $variant->is_in_stock() && $variant->is_purchasable();
            $variants[] = $row;
        }
        return array(
            'provider' => 'woocommerce',
            'storeIdentity' => $site_url,
            'externalProductId' => (string) $product->get_id(),
            'title' => sanitize_text_field($product->get_name()),
            'status' => sanitize_text_field($product->get_status()),
            'optionNames' => array_values(array_filter($attributes)),
            'variants' => $variants,
        );
    }

    public function register_settings() {
        register_setting('replynextai_group', self::OPTION_KEY, array('sanitize_callback' => array($this, 'sanitize_options')));
    }

    public function sanitize_options($input) {
        $current = $this->options();
        if (!is_array($input)) return $current;
        $output = self::defaults();
        $url = isset($input['server_url']) ? untrailingslashit(esc_url_raw(trim($input['server_url']))) : '';
        $output['server_url'] = $url && wp_http_validate_url($url) ? $url : $current['server_url'];
        $output['company_id'] = isset($input['company_id']) ? preg_replace('/[^0-9]/', '', (string) $input['company_id']) : '';
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
        add_submenu_page('replynextai', __('Display Rules', 'replynext-ai-chat-assistant-for-woocommerce'), __('Display Rules', 'replynext-ai-chat-assistant-for-woocommerce'), 'manage_options', 'replynextai-display', array($this, 'display_page'));
        add_submenu_page('replynextai', __('Connection', 'replynext-ai-chat-assistant-for-woocommerce'), __('Connection', 'replynext-ai-chat-assistant-for-woocommerce'), 'manage_options', 'replynextai-connection', array($this, 'connection_page'));
        add_submenu_page('replynextai', __('WooCommerce', 'replynext-ai-chat-assistant-for-woocommerce'), __('WooCommerce', 'replynext-ai-chat-assistant-for-woocommerce'), 'manage_options', 'replynextai-woocommerce', array($this, 'woocommerce_page'));
        add_submenu_page('replynextai', __('Help', 'replynext-ai-chat-assistant-for-woocommerce'), __('Help & Shortcodes', 'replynext-ai-chat-assistant-for-woocommerce'), 'manage_options', 'replynextai-help', array($this, 'help_page'));
    }

    public function plugin_links($links) {
        array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=replynextai')) . '">' . esc_html__('Dashboard', 'replynext-ai-chat-assistant-for-woocommerce') . '</a>', '<a href="' . esc_url(admin_url('admin.php?page=replynextai-ai')) . '">' . esc_html__('Settings', 'replynext-ai-chat-assistant-for-woocommerce') . '</a>');
        return $links;
    }

    public function admin_assets($hook) {
        if (false === strpos($hook, 'replynextai')) return;
        wp_enqueue_media();
        wp_enqueue_style('replynextai-admin', REPLYNEXTAI_URL . 'assets/admin.css', array(), REPLYNEXTAI_VERSION);
        wp_enqueue_script('replynextai-admin', REPLYNEXTAI_URL . 'assets/admin.js', array(), REPLYNEXTAI_VERSION, true);
    }

    private function page_header($title, $description) {
        ?>
        <div class="rn-admin-header"><div><span class="rn-eyebrow"><?php esc_html_e('ReplyNext AI for WordPress', 'replynext-ai-chat-assistant-for-woocommerce'); ?></span><h1><?php echo esc_html($title); ?></h1><p><?php echo esc_html($description); ?></p></div><a class="button button-secondary" href="<?php echo esc_url($this->portal_url()); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Open Client Portal ↗', 'replynext-ai-chat-assistant-for-woocommerce'); ?></a></div>
        <?php
    }

    private function portal_url() {
        return $this->options()['server_url'] . '/portal';
    }

    public function dashboard_page() {
        $o = $this->options();
        $ai_ready = $o['company_id'] && '1' === $o['ai_enabled'];
        $wa_ready = $o['whatsapp_number'] && '1' === $o['whatsapp_enabled'];
        ?>
        <div class="wrap rn-wrap"><?php $this->page_header(__('Dashboard', 'replynext-ai-chat-assistant-for-woocommerce'), __('Launch free WhatsApp chat or connect your ReplyNext AI sales assistant.', 'replynext-ai-chat-assistant-for-woocommerce')); ?>
        <div class="rn-grid rn-grid-3"><?php $this->status_card(__('AI Website Chat', 'replynext-ai-chat-assistant-for-woocommerce'), $ai_ready, $ai_ready ? __('Live and connected', 'replynext-ai-chat-assistant-for-woocommerce') : __('Company ID and activation required', 'replynext-ai-chat-assistant-for-woocommerce'), 'replynextai-ai'); ?><?php $this->status_card(__('Contact Channels', 'replynext-ai-chat-assistant-for-woocommerce'), $wa_ready, $wa_ready ? __('Live using your own number', 'replynext-ai-chat-assistant-for-woocommerce') : __('Add a WhatsApp number', 'replynext-ai-chat-assistant-for-woocommerce'), 'replynextai-whatsapp'); ?><?php $this->status_card(__('Display Rules', 'replynext-ai-chat-assistant-for-woocommerce'), true, __('Control pages and devices', 'replynext-ai-chat-assistant-for-woocommerce'), 'replynextai-display'); ?></div>
        <div class="rn-card rn-setup-card"><div><span class="rn-step"><?php esc_html_e('QUICK START', 'replynext-ai-chat-assistant-for-woocommerce'); ?></span><h2><?php esc_html_e('Start converting visitors in three steps', 'replynext-ai-chat-assistant-for-woocommerce'); ?></h2></div><ol class="rn-checklist"><li class="<?php echo $o['server_url'] ? 'done' : ''; ?>"><span>1</span><?php esc_html_e('Confirm your ReplyNext server URL', 'replynext-ai-chat-assistant-for-woocommerce'); ?></li><li class="<?php echo ($ai_ready || $wa_ready) ? 'done' : ''; ?>"><span>2</span><?php esc_html_e('Enable AI Chat or Contact Channels', 'replynext-ai-chat-assistant-for-woocommerce'); ?></li><li><span>3</span><?php esc_html_e('Open your website privately and send a test message', 'replynext-ai-chat-assistant-for-woocommerce'); ?></li></ol></div>
        <div class="rn-grid rn-grid-2"><div class="rn-card"><h2><?php esc_html_e('Contact Channels, no API required', 'replynext-ai-chat-assistant-for-woocommerce'); ?></h2><p><?php esc_html_e('Open a direct conversation using your own WhatsApp number.', 'replynext-ai-chat-assistant-for-woocommerce'); ?></p><a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=replynextai-whatsapp')); ?>"><?php esc_html_e('Configure Contact Channels', 'replynext-ai-chat-assistant-for-woocommerce'); ?></a></div><div class="rn-card rn-upgrade"><h2><?php esc_html_e('Turn chat into an AI sales agent', 'replynext-ai-chat-assistant-for-woocommerce'); ?></h2><p><?php esc_html_e('Capture leads and manage AI conversations in ReplyNext.', 'replynext-ai-chat-assistant-for-woocommerce'); ?></p><a class="button button-secondary" href="<?php echo esc_url(admin_url('admin.php?page=replynextai-ai')); ?>"><?php esc_html_e('Connect AI Website Chat', 'replynext-ai-chat-assistant-for-woocommerce'); ?></a></div></div></div>
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
        ?><div class="wrap rn-wrap"><?php $this->page_header(__('AI Website Chat', 'replynext-ai-chat-assistant-for-woocommerce'), __('Connect the AI chat module configured in your ReplyNext client portal.', 'replynext-ai-chat-assistant-for-woocommerce')); ?><?php /* phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The action handler validates its POST nonce; this query value only selects a bounded notice. */ if (isset($_GET['rn_widget_refresh'])) : ?><div class="notice <?php echo 'success' === sanitize_key(wp_unslash($_GET['rn_widget_refresh'])) ? 'notice-success' : 'notice-error'; ?> is-dismissible"><p><?php echo 'success' === sanitize_key(wp_unslash($_GET['rn_widget_refresh'])) ? esc_html__('Widget connection refreshed successfully.', 'replynext-ai-chat-assistant-for-woocommerce') : esc_html__('Unable to refresh the widget connection. Your existing connection settings were kept.', 'replynext-ai-chat-assistant-for-woocommerce'); ?></p></div><?php endif; ?><?php $this->form_open(); $this->preserve_fields(array('server_url', 'company_id', 'ai_enabled', 'ai_sitewide', 'share_user_data')); ?><div class="rn-grid rn-grid-form"><div class="rn-card">
        <label class="rn-toggle-row"><span><strong><?php esc_html_e('Enable AI Website Chat', 'replynext-ai-chat-assistant-for-woocommerce'); ?></strong><small><?php esc_html_e('Also enable it in the ReplyNext portal.', 'replynext-ai-chat-assistant-for-woocommerce'); ?></small></span><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[ai_enabled]" value="1" <?php checked($o['ai_enabled'], '1'); ?> /></label>
        <?php $this->field('server_url', __('ReplyNext server URL', 'replynext-ai-chat-assistant-for-woocommerce'), $o['server_url'], 'https://replynextai.com', 'url'); ?><?php $this->field('company_id', __('Company ID', 'replynext-ai-chat-assistant-for-woocommerce'), $o['company_id'], '12'); ?>
        <label class="rn-check"><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[ai_sitewide]" value="1" <?php checked($o['ai_sitewide'], '1'); ?> /> <?php esc_html_e('Show AI chat on every allowed page', 'replynext-ai-chat-assistant-for-woocommerce'); ?></label><label class="rn-check"><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[share_user_data]" value="1" <?php checked($o['share_user_data'], '1'); ?> /> <?php esc_html_e('Pass logged-in WordPress user name and email', 'replynext-ai-chat-assistant-for-woocommerce'); ?></label><p class="description"><?php esc_html_e('Enable only when covered by your privacy notice.', 'replynext-ai-chat-assistant-for-woocommerce'); ?></p><?php submit_button(__('Save AI Chat Settings', 'replynext-ai-chat-assistant-for-woocommerce')); ?></div>
        <div class="rn-card rn-preview-card"><span class="rn-step"><?php esc_html_e('LIVE PREVIEW', 'replynext-ai-chat-assistant-for-woocommerce'); ?></span><p class="description"><?php esc_html_e('Preview and manage your customer-facing chat from the ReplyNext Client Portal.', 'replynext-ai-chat-assistant-for-woocommerce'); ?></p><div class="rn-site-preview"><div class="rn-browser-bar"><i></i><i></i><i></i></div><div class="rn-preview-content"><h3><?php esc_html_e('ReplyNext AI customer chat', 'replynext-ai-chat-assistant-for-woocommerce'); ?></h3><p><?php esc_html_e('Open the ReplyNext Client Portal to preview and manage your live chat.', 'replynext-ai-chat-assistant-for-woocommerce'); ?></p><a href="https://replynextai.com" target="_blank" rel="noopener noreferrer" class="button button-primary"><?php esc_html_e('Open ReplyNext Client Portal', 'replynext-ai-chat-assistant-for-woocommerce'); ?></a></div></div><p><code>[replynextai_chat]</code></p></div></div></form><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="replynextai_refresh_widget_connection" /><?php wp_nonce_field('replynextai_refresh_widget_connection'); ?><?php submit_button(__('Refresh Widget Connection', 'replynext-ai-chat-assistant-for-woocommerce'), 'secondary', 'submit', false); ?></form></div><?php
    }

    public function whatsapp_page() {
        $o = $this->options();
        ?><div class="wrap rn-wrap"><?php $this->page_header(__('Contact Channels', 'replynext-ai-chat-assistant-for-woocommerce'), __('Customize the compact launcher and each channel. Icons use safe WordPress media URLs.', 'replynext-ai-chat-assistant-for-woocommerce')); ?><?php $this->form_open(); $this->preserve_fields(array('contact_label', 'contact_show_label', 'contact_color', 'contact_text_color', 'contact_icon_id', 'whatsapp_enabled', 'whatsapp_number', 'whatsapp_message', 'whatsapp_label', 'whatsapp_icon_id', 'whatsapp_show_label', 'whatsapp_color', 'whatsapp_text_color', 'whatsapp_position', 'whatsapp_delay', 'messenger_enabled', 'messenger_url', 'messenger_label', 'messenger_show_label', 'messenger_color', 'messenger_text_color', 'messenger_icon_id', 'call_enabled', 'call_number', 'call_label', 'call_show_label', 'call_color', 'call_text_color', 'phone_icon_id')); ?>
        <div class="rn-contact-cards">
            <section class="rn-card rn-contact-card"><h2><?php esc_html_e('Launcher', 'replynext-ai-chat-assistant-for-woocommerce'); ?></h2><?php $this->field('contact_label', __('Launcher label', 'replynext-ai-chat-assistant-for-woocommerce'), $o['contact_label'], __('Contact Us', 'replynext-ai-chat-assistant-for-woocommerce')); ?><label class="rn-check"><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[contact_show_label]" value="1" <?php checked($o['contact_show_label'], '1'); ?> /> <?php esc_html_e('Show label', 'replynext-ai-chat-assistant-for-woocommerce'); ?></label><?php $this->contact_icon_picker(__('Launcher icon', 'replynext-ai-chat-assistant-for-woocommerce'), 'contact_icon_id', 'rn-contact-icon-id', $o['contact_icon_id']); ?><div class="rn-inline-fields"><label class="rn-field"><span><?php esc_html_e('Background color', 'replynext-ai-chat-assistant-for-woocommerce'); ?></span><input type="color" name="<?php echo esc_attr(self::OPTION_KEY); ?>[contact_color]" value="<?php echo esc_attr($o['contact_color']); ?>" /></label><label class="rn-field"><span><?php esc_html_e('Icon/text color', 'replynext-ai-chat-assistant-for-woocommerce'); ?></span><input type="color" name="<?php echo esc_attr(self::OPTION_KEY); ?>[contact_text_color]" value="<?php echo esc_attr($o['contact_text_color']); ?>" /></label></div></section>
            <section class="rn-card rn-contact-card"><h2><?php esc_html_e('WhatsApp', 'replynext-ai-chat-assistant-for-woocommerce'); ?></h2><label class="rn-toggle-row"><span><strong><?php esc_html_e('Enable WhatsApp Channel', 'replynext-ai-chat-assistant-for-woocommerce'); ?></strong><small><?php esc_html_e('Opens wa.me in the visitor’s WhatsApp app.', 'replynext-ai-chat-assistant-for-woocommerce'); ?></small></span><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[whatsapp_enabled]" value="1" <?php checked($o['whatsapp_enabled'], '1'); ?> /></label><?php $this->field('whatsapp_number', __('WhatsApp number', 'replynext-ai-chat-assistant-for-woocommerce'), $o['whatsapp_number'], '8801XXXXXXXXX', 'tel'); ?><label class="rn-field"><span><?php esc_html_e('Prefilled message', 'replynext-ai-chat-assistant-for-woocommerce'); ?></span><textarea rows="3" name="<?php echo esc_attr(self::OPTION_KEY); ?>[whatsapp_message]"><?php echo esc_textarea($o['whatsapp_message']); ?></textarea></label><?php $this->field('whatsapp_label', __('Label', 'replynext-ai-chat-assistant-for-woocommerce'), $o['whatsapp_label'], __('Chat on WhatsApp', 'replynext-ai-chat-assistant-for-woocommerce')); ?><label class="rn-check"><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[whatsapp_show_label]" value="1" <?php checked($o['whatsapp_show_label'], '1'); ?> /> <?php esc_html_e('Show label', 'replynext-ai-chat-assistant-for-woocommerce'); ?></label><?php $this->contact_icon_picker(__('WhatsApp icon', 'replynext-ai-chat-assistant-for-woocommerce'), 'whatsapp_icon_id', 'rn-whatsapp-icon-id', $o['whatsapp_icon_id']); ?><div class="rn-inline-fields"><label class="rn-field"><span><?php esc_html_e('Background color', 'replynext-ai-chat-assistant-for-woocommerce'); ?></span><input type="color" name="<?php echo esc_attr(self::OPTION_KEY); ?>[whatsapp_color]" value="<?php echo esc_attr($o['whatsapp_color']); ?>" /></label><label class="rn-field"><span><?php esc_html_e('Icon/text color', 'replynext-ai-chat-assistant-for-woocommerce'); ?></span><input type="color" name="<?php echo esc_attr(self::OPTION_KEY); ?>[whatsapp_text_color]" value="<?php echo esc_attr($o['whatsapp_text_color']); ?>" /></label></div></section>
            <section class="rn-card rn-contact-card"><h2><?php esc_html_e('Messenger', 'replynext-ai-chat-assistant-for-woocommerce'); ?></h2><label class="rn-toggle-row"><span><strong><?php esc_html_e('Enable Messenger Channel', 'replynext-ai-chat-assistant-for-woocommerce'); ?></strong><small><?php esc_html_e('Opens your Facebook or Messenger link.', 'replynext-ai-chat-assistant-for-woocommerce'); ?></small></span><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[messenger_enabled]" value="1" <?php checked($o['messenger_enabled'], '1'); ?> /></label><?php $this->field('messenger_url', __('Messenger link', 'replynext-ai-chat-assistant-for-woocommerce'), $o['messenger_url'], 'https://m.me/your-page'); ?><?php $this->field('messenger_label', __('Label', 'replynext-ai-chat-assistant-for-woocommerce'), $o['messenger_label'], __('Message on Messenger', 'replynext-ai-chat-assistant-for-woocommerce')); ?><label class="rn-check"><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[messenger_show_label]" value="1" <?php checked($o['messenger_show_label'], '1'); ?> /> <?php esc_html_e('Show label', 'replynext-ai-chat-assistant-for-woocommerce'); ?></label><?php $this->contact_icon_picker(__('Messenger icon', 'replynext-ai-chat-assistant-for-woocommerce'), 'messenger_icon_id', 'rn-messenger-icon-id', $o['messenger_icon_id']); ?><div class="rn-inline-fields"><label class="rn-field"><span><?php esc_html_e('Background color', 'replynext-ai-chat-assistant-for-woocommerce'); ?></span><input type="color" name="<?php echo esc_attr(self::OPTION_KEY); ?>[messenger_color]" value="<?php echo esc_attr($o['messenger_color']); ?>" /></label><label class="rn-field"><span><?php esc_html_e('Icon/text color', 'replynext-ai-chat-assistant-for-woocommerce'); ?></span><input type="color" name="<?php echo esc_attr(self::OPTION_KEY); ?>[messenger_text_color]" value="<?php echo esc_attr($o['messenger_text_color']); ?>" /></label></div></section>
            <section class="rn-card rn-contact-card"><h2><?php esc_html_e('Phone / Call', 'replynext-ai-chat-assistant-for-woocommerce'); ?></h2><label class="rn-toggle-row"><span><strong><?php esc_html_e('Enable Phone / Call Channel', 'replynext-ai-chat-assistant-for-woocommerce'); ?></strong><small><?php esc_html_e('Starts a phone call from the visitor’s device.', 'replynext-ai-chat-assistant-for-woocommerce'); ?></small></span><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[call_enabled]" value="1" <?php checked($o['call_enabled'], '1'); ?> /></label><?php $this->field('call_number', __('Phone number', 'replynext-ai-chat-assistant-for-woocommerce'), $o['call_number'], '+8801XXXXXXXXX', 'tel'); ?><?php $this->field('call_label', __('Label', 'replynext-ai-chat-assistant-for-woocommerce'), $o['call_label'], __('Call us', 'replynext-ai-chat-assistant-for-woocommerce')); ?><label class="rn-check"><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[call_show_label]" value="1" <?php checked($o['call_show_label'], '1'); ?> /> <?php esc_html_e('Show label', 'replynext-ai-chat-assistant-for-woocommerce'); ?></label><?php $this->contact_icon_picker(__('Phone icon', 'replynext-ai-chat-assistant-for-woocommerce'), 'phone_icon_id', 'rn-phone-icon-id', $o['phone_icon_id']); ?><div class="rn-inline-fields"><label class="rn-field"><span><?php esc_html_e('Background color', 'replynext-ai-chat-assistant-for-woocommerce'); ?></span><input type="color" name="<?php echo esc_attr(self::OPTION_KEY); ?>[call_color]" value="<?php echo esc_attr($o['call_color']); ?>" /></label><label class="rn-field"><span><?php esc_html_e('Icon/text color', 'replynext-ai-chat-assistant-for-woocommerce'); ?></span><input type="color" name="<?php echo esc_attr(self::OPTION_KEY); ?>[call_text_color]" value="<?php echo esc_attr($o['call_text_color']); ?>" /></label></div></section>
            <section class="rn-card rn-contact-card rn-contact-display-settings"><h2><?php esc_html_e('Display Settings', 'replynext-ai-chat-assistant-for-woocommerce'); ?></h2><div class="rn-inline-fields"><label class="rn-field"><span><?php esc_html_e('Position', 'replynext-ai-chat-assistant-for-woocommerce'); ?></span><select name="<?php echo esc_attr(self::OPTION_KEY); ?>[whatsapp_position]"><option value="left" <?php selected($o['whatsapp_position'], 'left'); ?>><?php esc_html_e('Bottom left', 'replynext-ai-chat-assistant-for-woocommerce'); ?></option><option value="right" <?php selected($o['whatsapp_position'], 'right'); ?>><?php esc_html_e('Bottom right', 'replynext-ai-chat-assistant-for-woocommerce'); ?></option></select></label><label class="rn-field"><span><?php esc_html_e('Delay', 'replynext-ai-chat-assistant-for-woocommerce'); ?></span><input type="number" min="0" max="30" name="<?php echo esc_attr(self::OPTION_KEY); ?>[whatsapp_delay]" value="<?php echo esc_attr($o['whatsapp_delay']); ?>" /></label></div></section>
        </div><?php submit_button(__('Save Contact Channels Settings', 'replynext-ai-chat-assistant-for-woocommerce')); ?></form></div><?php
    }

    public function display_page() {
        $o = $this->options();
        $targets = get_posts(array('post_type' => array('page', 'post'), 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC'));
        ?><div class="wrap rn-wrap"><?php $this->page_header(__('Display Rules', 'replynext-ai-chat-assistant-for-woocommerce'), __('Control where both chat modules appear.', 'replynext-ai-chat-assistant-for-woocommerce')); ?><?php $this->form_open(); $this->preserve_fields(array('show_on', 'include_pages', 'exclude_pages', 'hide_mobile', 'hide_desktop')); ?><div class="rn-card rn-narrow"><label class="rn-field"><span><?php esc_html_e('Page targeting', 'replynext-ai-chat-assistant-for-woocommerce'); ?></span><select name="<?php echo esc_attr(self::OPTION_KEY); ?>[show_on]"><option value="all" <?php selected($o['show_on'], 'all'); ?>><?php esc_html_e('All public pages', 'replynext-ai-chat-assistant-for-woocommerce'); ?></option><option value="include" <?php selected($o['show_on'], 'include'); ?>><?php esc_html_e('Only selected pages/posts', 'replynext-ai-chat-assistant-for-woocommerce'); ?></option></select></label>
        <?php $include_ids = array_map('strval', explode(',', $o['include_pages'])); $exclude_ids = array_map('strval', explode(',', $o['exclude_pages'])); ?>
        <div class="rn-page-pickers"><div class="rn-page-picker"><strong><?php esc_html_e('Include pages', 'replynext-ai-chat-assistant-for-woocommerce'); ?></strong><small><?php esc_html_e('Show the chat only on selected pages.', 'replynext-ai-chat-assistant-for-woocommerce'); ?></small><div class="rn-page-list"><?php if (empty($targets)) : ?><em><?php esc_html_e('No published pages found.', 'replynext-ai-chat-assistant-for-woocommerce'); ?></em><?php else : foreach ($targets as $target) : ?><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[include_pages][]" value="<?php echo esc_attr($target->ID); ?>" <?php checked(in_array((string) $target->ID, $include_ids, true)); ?> /> <span><?php echo esc_html($target->post_title ?: __('(no title)', 'replynext-ai-chat-assistant-for-woocommerce')); ?></span></label><?php endforeach; endif; ?></div></div>
        <div class="rn-page-picker"><strong><?php esc_html_e('Exclude pages', 'replynext-ai-chat-assistant-for-woocommerce'); ?></strong><small><?php esc_html_e('Hide the chat on selected pages. Exclusions always win.', 'replynext-ai-chat-assistant-for-woocommerce'); ?></small><div class="rn-page-list"><?php if (empty($targets)) : ?><em><?php esc_html_e('No published pages found.', 'replynext-ai-chat-assistant-for-woocommerce'); ?></em><?php else : foreach ($targets as $target) : ?><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[exclude_pages][]" value="<?php echo esc_attr($target->ID); ?>" <?php checked(in_array((string) $target->ID, $exclude_ids, true)); ?> /> <span><?php echo esc_html($target->post_title ?: __('(no title)', 'replynext-ai-chat-assistant-for-woocommerce')); ?></span></label><?php endforeach; endif; ?></div></div></div>
        <p class="description"><?php esc_html_e('Click a page to select or deselect it.', 'replynext-ai-chat-assistant-for-woocommerce'); ?></p><label class="rn-check"><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[hide_mobile]" value="1" <?php checked($o['hide_mobile'], '1'); ?> /> <?php esc_html_e('Hide on mobile', 'replynext-ai-chat-assistant-for-woocommerce'); ?></label><label class="rn-check"><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[hide_desktop]" value="1" <?php checked($o['hide_desktop'], '1'); ?> /> <?php esc_html_e('Hide on desktop', 'replynext-ai-chat-assistant-for-woocommerce'); ?></label><?php submit_button(__('Save Display Rules', 'replynext-ai-chat-assistant-for-woocommerce')); ?></div></form></div><?php
    }

    public function connection_page() {
        $o = $this->options();
        $status = $this->remote_status(false);
        ?><div class="wrap rn-wrap"><?php $this->page_header(__('ReplyNext Connection', 'replynext-ai-chat-assistant-for-woocommerce'), __('Use a revocable WordPress token from your client portal—never enter your account password here.', 'replynext-ai-chat-assistant-for-woocommerce')); ?>
        <?php /* phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This is a read-only redirect notice; form processing is protected by settings_fields() and the admin-post nonce. */ if (isset($_GET['rn_notice'])) : ?><?php $notice = sanitize_key(wp_unslash($_GET['rn_notice'])); ?><div class="notice <?php echo 'success' === $notice ? 'notice-success' : 'notice-error'; ?> is-dismissible"><p><?php if ('success' === $notice) { esc_html_e('Connection successful.', 'replynext-ai-chat-assistant-for-woocommerce'); } elseif ('widget_revoked' === $notice) { esc_html_e('Secure AI Website Chat authorization is revoked. Reactivate or remove the website authorization in ReplyNext before retrying.', 'replynext-ai-chat-assistant-for-woocommerce'); } elseif ('widget_error' === $notice) { esc_html_e('Secure AI Website Chat authorization could not be created. Your connection settings were kept; please try again.', 'replynext-ai-chat-assistant-for-woocommerce'); } else { esc_html_e('Connection failed. Check the server URL and token.', 'replynext-ai-chat-assistant-for-woocommerce'); } ?></p></div><?php endif; ?>
        <div class="rn-grid rn-grid-form"><div class="rn-card"><?php $this->form_open(); $this->preserve_fields(array('server_url', 'api_token')); ?><?php $this->field('server_url', __('ReplyNext server URL', 'replynext-ai-chat-assistant-for-woocommerce'), $o['server_url'], 'https://replynextai.com', 'url'); ?><label class="rn-field"><span><?php esc_html_e('WordPress connection token', 'replynext-ai-chat-assistant-for-woocommerce'); ?></span><input type="password" autocomplete="new-password" name="<?php echo esc_attr(self::OPTION_KEY); ?>[api_token]" value="<?php echo esc_attr($o['api_token']); ?>" placeholder="rnwp_live_..." /></label><p class="description"><?php esc_html_e('Generate this token from Client Portal → Integrations → AI Website Chat.', 'replynext-ai-chat-assistant-for-woocommerce'); ?></p><?php submit_button(__('Save Connection', 'replynext-ai-chat-assistant-for-woocommerce')); ?></form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="replynextai_test_connection" /><?php wp_nonce_field('replynextai_test_connection'); ?><?php submit_button(__('Test Connection', 'replynext-ai-chat-assistant-for-woocommerce'), 'secondary', 'submit', false); ?></form></div>
        <div class="rn-card"><span class="rn-status <?php echo $status ? 'is-live' : 'is-off'; ?>"><?php echo $status ? esc_html__('Connected', 'replynext-ai-chat-assistant-for-woocommerce') : esc_html__('Not connected', 'replynext-ai-chat-assistant-for-woocommerce'); ?></span><h2><?php esc_html_e('Connection status', 'replynext-ai-chat-assistant-for-woocommerce'); ?></h2><?php if ($status) : ?><p><strong><?php echo esc_html($status['company']['name']); ?></strong></p><?php /* translators: 1: plan name, 2: product count, 3: lead count. */ ?><p><?php echo esc_html(sprintf(__('Plan: %1$s · Products: %2$d · Leads: %3$d', 'replynext-ai-chat-assistant-for-woocommerce'), $status['company']['planName'] ?: $status['company']['planId'], $status['stats']['products'], $status['stats']['totalLeads'])); ?></p><?php else : ?><p><?php esc_html_e('Save a valid token and test the connection.', 'replynext-ai-chat-assistant-for-woocommerce'); ?></p><?php endif; ?></div></div></div><?php
    }

    public function handle_test_connection() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Unauthorized', 'replynext-ai-chat-assistant-for-woocommerce'));
        check_admin_referer('replynextai_test_connection');
        delete_transient('replynextai_status_cache');
        $status = $this->remote_status(true);
        $success = (bool) $status;
        if ($success && !empty($status['company']['id'])) {
            $options = $this->options();
            $widget_id = $this->provision_widget($options);
            if ($widget_id) {
                $options['company_id'] = (string) absint($status['company']['id']);
                update_option(self::OPTION_KEY, $options);
            } else {
                $success = false;
            }
        } elseif ($success) {
            $success = false;
            $this->last_widget_provision_error = 'authorization';
        }
        $notice = $success ? 'success' : ('revoked' === $this->last_widget_provision_error ? 'widget_revoked' : ($status ? 'widget_error' : 'error'));
        wp_safe_redirect(admin_url('admin.php?page=replynextai-connection&rn_notice=' . $notice));
        exit;
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
        $status = get_transient('replynextai_last_sync');
        ?><div class="wrap rn-wrap"><?php $this->page_header(__('WooCommerce', 'replynext-ai-chat-assistant-for-woocommerce'), __('Sync products into the ReplyNext AI catalog for accurate recommendations.', 'replynext-ai-chat-assistant-for-woocommerce')); ?><div class="rn-grid rn-grid-form"><div class="rn-card"><span class="rn-status <?php echo $available ? 'is-live' : 'is-off'; ?>"><?php echo $available ? esc_html__('Detected', 'replynext-ai-chat-assistant-for-woocommerce') : esc_html__('Unavailable', 'replynext-ai-chat-assistant-for-woocommerce'); ?></span><h2><?php esc_html_e('Product catalog sync', 'replynext-ai-chat-assistant-for-woocommerce'); ?></h2><p><?php esc_html_e('Syncs up to 100 published WooCommerce products with names, prices, descriptions, categories, links, and images.', 'replynext-ai-chat-assistant-for-woocommerce'); ?></p><?php if ($status) : ?><p><strong><?php echo esc_html($status); ?></strong></p><?php endif; ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="replynextai_sync_products" /><?php wp_nonce_field('replynextai_sync_products'); ?><?php submit_button(__('Sync Products Now', 'replynext-ai-chat-assistant-for-woocommerce'), 'primary', 'submit', false, $available ? array() : array('disabled' => 'disabled')); ?></form></div><div class="rn-card"><h2><?php esc_html_e('Automatic updates', 'replynext-ai-chat-assistant-for-woocommerce'); ?></h2><p><?php esc_html_e('After a successful connection, product saves schedule a background catalog refresh.', 'replynext-ai-chat-assistant-for-woocommerce'); ?></p><p><?php esc_html_e('Requirements: WooCommerce active and a valid ReplyNext WordPress token.', 'replynext-ai-chat-assistant-for-woocommerce'); ?></p></div></div></div><?php
    }

    public function handle_sync_products() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Unauthorized', 'replynext-ai-chat-assistant-for-woocommerce'));
        check_admin_referer('replynextai_sync_products');
        $this->queue_catalog_sync(1);
        set_transient('replynextai_last_sync', __('Product synchronization scheduled in the background.', 'replynext-ai-chat-assistant-for-woocommerce'), DAY_IN_SECONDS);
        wp_safe_redirect(admin_url('admin.php?page=replynextai-woocommerce&rn_sync=scheduled'));
        exit;
    }

    public function schedule_product_sync($post_id, $post, $update) {
        if (wp_is_post_revision($post_id) || 'product' !== $post->post_type || !$update) return;
        set_transient(self::CATALOG_DIRTY_TRANSIENT, '1', DAY_IN_SECONDS);
        $this->queue_catalog_sync(60);
    }

    private function queue_catalog_sync($delay = 60) {
        // add_option is atomic, preventing save_post/manual requests from
        // enqueueing duplicate workers before WP-Cron observes the event.
        if (!add_option(self::CATALOG_QUEUED_OPTION, '1', '', 'no')) return;
        $scheduled = wp_schedule_single_event(time() + max(1, (int) $delay), 'replynextai_deferred_product_sync');
        if (false === $scheduled || is_wp_error($scheduled)) delete_option(self::CATALOG_QUEUED_OPTION);
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

    public function sync_products() {
        if (!function_exists('wc_get_products')) return false;
        $o = $this->options();
        if (!$o['api_token'] || !$o['server_url']) return false;
        $lock_token = $this->acquire_lock(self::CATALOG_LOCK_OPTION, 300);
        if (!$lock_token) {
            $this->queue_catalog_sync(60);
            return false;
        }
        try {
        delete_option(self::CATALOG_QUEUED_OPTION);
        $state = get_transient(self::CATALOG_STATE_TRANSIENT);
        if (!is_array($state) || empty($state['sync_id'])) $state = array('sync_id' => wp_generate_uuid4(), 'page' => 1, 'total_synced' => 0, 'index_failed' => false);
        delete_transient(self::CATALOG_DIRTY_TRANSIENT);
        $page = max(1, absint($state['page']));
        $sync_id = sanitize_text_field($state['sync_id']);
        $total_synced = absint($state['total_synced']);
        $index_failed = !empty($state['index_failed']);
        $products = array();
        $index_products = array();
        $batch = wc_get_products(array('status' => 'publish', 'limit' => 100, 'page' => $page, 'paginate' => true, 'return' => 'objects'));
        $batch_products = is_object($batch) && isset($batch->products) ? $batch->products : array();
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
                'searchableText' => wp_strip_all_tags($product->get_short_description() ?: $product->get_description()),
                'vendor' => $product->get_attribute('pa_brand'),
                'productType' => implode(', ', $category_names),
                'productStatus' => $product->get_status(),
                'optionSummary' => array_values(array_filter($attributes)),
                'sourceUpdatedAt' => get_post_modified_time('c', true, $product->get_id()),
            );
        }
        if (!$products) {
            $complete = wp_remote_post($o['server_url'] . '/api/wordpress/products', array(
                'timeout' => 15,
                'headers' => array('Authorization' => 'Bearer ' . $o['api_token'], 'Content-Type' => 'application/json'),
                'body' => wp_json_encode(array('siteUrl' => home_url('/'), 'sync_id' => $sync_id, 'sync_complete' => true, 'products' => array())),
            ));
            $index_complete = wp_remote_post($o['server_url'] . '/api/wordpress/catalog', array(
                'timeout' => 15,
                'headers' => array('Authorization' => 'Bearer ' . $o['api_token'], 'Content-Type' => 'application/json'),
                'body' => wp_json_encode(array('sync_id' => $sync_id, 'sync_complete' => true, 'products' => array())),
            ));
            if (is_wp_error($complete) || 200 !== wp_remote_retrieve_response_code($complete) || is_wp_error($index_complete) || 200 !== wp_remote_retrieve_response_code($index_complete)) {
                set_transient('replynextai_last_sync', __('Last sync failed.', 'replynext-ai-chat-assistant-for-woocommerce'), DAY_IN_SECONDS);
                set_transient(self::CATALOG_STATE_TRANSIENT, $state, DAY_IN_SECONDS);
                $this->queue_catalog_sync(300);
                return false;
            }
            delete_transient(self::CATALOG_STATE_TRANSIENT);
            if (get_transient(self::CATALOG_DIRTY_TRANSIENT)) $this->queue_catalog_sync(60);
            set_transient('replynextai_last_sync', sprintf(__('Last sync completed: %d products.', 'replynext-ai-chat-assistant-for-woocommerce'), $total_synced), DAY_IN_SECONDS);
            return true;
        }
        $response = wp_remote_post($o['server_url'] . '/api/wordpress/products', array(
            'timeout' => 15,
            'headers' => array('Authorization' => 'Bearer ' . $o['api_token'], 'Content-Type' => 'application/json'),
            'body' => wp_json_encode(array('siteUrl' => home_url('/'), 'sync_id' => $sync_id, 'products' => $products)),
        ));
        if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
            set_transient('replynextai_last_sync', __('Last sync failed.', 'replynext-ai-chat-assistant-for-woocommerce'), DAY_IN_SECONDS);
            set_transient(self::CATALOG_STATE_TRANSIENT, array('sync_id' => $sync_id, 'page' => $page, 'total_synced' => $total_synced, 'index_failed' => $index_failed), DAY_IN_SECONDS);
            $this->queue_catalog_sync(300);
            return false;
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['success'])) { $this->queue_catalog_sync(300); return false; }
        $index_response = wp_remote_post($o['server_url'] . '/api/wordpress/catalog', array(
            'timeout' => 15,
            'headers' => array('Authorization' => 'Bearer ' . $o['api_token'], 'Content-Type' => 'application/json'),
            'body' => wp_json_encode(array('sync_id' => $sync_id, 'products' => $index_products)),
        ));
        if (is_wp_error($index_response) || 200 !== wp_remote_retrieve_response_code($index_response)) $index_failed = true;
        $total_synced += absint($body['synced']);
        if (count($batch_products) === 100) {
            set_transient(self::CATALOG_STATE_TRANSIENT, array('sync_id' => $sync_id, 'page' => $page + 1, 'total_synced' => $total_synced, 'index_failed' => $index_failed), DAY_IN_SECONDS);
            $this->queue_catalog_sync(5);
            return true;
        }
        $complete = wp_remote_post($o['server_url'] . '/api/wordpress/products', array(
            'timeout' => 15,
            'headers' => array('Authorization' => 'Bearer ' . $o['api_token'], 'Content-Type' => 'application/json'),
            'body' => wp_json_encode(array('siteUrl' => home_url('/'), 'sync_id' => $sync_id, 'sync_complete' => true, 'products' => array())),
        ));
        if (is_wp_error($complete) || 200 !== wp_remote_retrieve_response_code($complete)) {
            set_transient('replynextai_last_sync', __('Product sync incomplete; existing products were preserved.', 'replynext-ai-chat-assistant-for-woocommerce'), DAY_IN_SECONDS);
            return false;
        }
        $index_complete = wp_remote_post($o['server_url'] . '/api/wordpress/catalog', array(
            'timeout' => 15,
            'headers' => array('Authorization' => 'Bearer ' . $o['api_token'], 'Content-Type' => 'application/json'),
            'body' => wp_json_encode(array('sync_id' => $sync_id, 'sync_complete' => true, 'products' => array())),
        ));
        if (is_wp_error($index_complete) || 200 !== wp_remote_retrieve_response_code($index_complete)) $index_failed = true;
        /* translators: 1: number of products synchronized, 2: number of batches used. */
        set_transient('replynextai_last_sync', $index_failed ? __('Product copy sync completed; the discovery index will retry on the next refresh.', 'replynext-ai-chat-assistant-for-woocommerce') : sprintf(__('Last sync completed: %d products.', 'replynext-ai-chat-assistant-for-woocommerce'), $total_synced), DAY_IN_SECONDS);
        delete_transient('replynextai_status_cache');
        return true;
        } finally {
            $this->release_lock(self::CATALOG_LOCK_OPTION, $lock_token);
        }
    }

    public function help_page() {
        ?><div class="wrap rn-wrap"><?php $this->page_header(__('Help & Shortcodes', 'replynext-ai-chat-assistant-for-woocommerce'), __('Use automatic display, shortcodes, or both.', 'replynext-ai-chat-assistant-for-woocommerce')); ?><div class="rn-grid rn-grid-2"><div class="rn-card"><h2><?php esc_html_e('AI Website Chat', 'replynext-ai-chat-assistant-for-woocommerce'); ?></h2><p><code>[replynextai_chat]</code></p><p><code>[replynextai_chat company_id="12" server_url="https://replynextai.com"]</code></p></div><div class="rn-card"><h2><?php esc_html_e('Contact Channels', 'replynext-ai-chat-assistant-for-woocommerce'); ?></h2><p><code>[replynextai_whatsapp]</code></p><p><code>[replynextai_whatsapp number="8801XXXXXXXXX" message="Hello"]</code></p></div></div></div><?php
    }

    private function field($key, $label, $value, $placeholder = '', $type = 'text') {
        printf('<label class="rn-field"><span>%s</span><input type="%s" name="%s[%s]" value="%s" placeholder="%s" /></label>', esc_html($label), esc_attr($type), esc_attr(self::OPTION_KEY), esc_attr($key), esc_attr($value), esc_attr($placeholder));
    }

    public function frontend_assets() {
        if (is_admin() || !$this->display_allowed()) return;
        $o = $this->options();
        if ('1' === $o['contact_enabled'] && (('1' === $o['whatsapp_enabled'] && $o['whatsapp_number']) || ('1' === $o['messenger_enabled'] && $o['messenger_url']) || ('1' === $o['call_enabled'] && $o['call_number']))) {
            wp_enqueue_style('replynextai-frontend', REPLYNEXTAI_URL . 'assets/frontend.css', array(), REPLYNEXTAI_VERSION);
            wp_enqueue_script('replynextai-frontend', REPLYNEXTAI_URL . 'assets/frontend.js', array(), REPLYNEXTAI_VERSION, true);
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
        wp_enqueue_style('replynextai-frontend', REPLYNEXTAI_URL . 'assets/frontend.css', array(), REPLYNEXTAI_VERSION);
        wp_enqueue_script('replynextai-frontend', REPLYNEXTAI_URL . 'assets/frontend.js', array(), REPLYNEXTAI_VERSION, true);
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
