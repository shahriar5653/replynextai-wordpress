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
            'whatsapp_enabled'    => '0',
            'whatsapp_number'     => '',
            'whatsapp_message'    => 'Hello! I found you through your website.',
            'whatsapp_label'      => 'Chat on WhatsApp',
            'whatsapp_color'      => '#25D366',
            'whatsapp_position'   => 'left',
            'whatsapp_delay'      => '0',
            'messenger_enabled'   => '0',
            'messenger_url'       => '',
            'messenger_label'     => 'Message on Messenger',
            'call_enabled'        => '0',
            'call_number'         => '',
            'call_label'          => 'Call us',
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
        add_action('admin_post_replynextai_sync_products', array($this, 'handle_sync_products'));
        add_action('save_post_product', array($this, 'schedule_product_sync'), 20, 3);
        add_action('replynextai_deferred_product_sync', array($this, 'sync_products'));
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
        foreach (array('ai_enabled', 'ai_sitewide', 'share_user_data', 'whatsapp_enabled', 'messenger_enabled', 'call_enabled', 'hide_mobile', 'hide_desktop') as $checkbox) {
            $output[$checkbox] = empty($input[$checkbox]) ? '0' : '1';
        }
        $output['whatsapp_number'] = isset($input['whatsapp_number']) ? preg_replace('/[^0-9]/', '', (string) $input['whatsapp_number']) : '';
        $output['whatsapp_message'] = isset($input['whatsapp_message']) ? sanitize_textarea_field($input['whatsapp_message']) : self::defaults()['whatsapp_message'];
        $output['whatsapp_label'] = isset($input['whatsapp_label']) ? sanitize_text_field($input['whatsapp_label']) : self::defaults()['whatsapp_label'];
        $color = isset($input['whatsapp_color']) ? sanitize_hex_color($input['whatsapp_color']) : '';
        $output['whatsapp_color'] = $color ?: self::defaults()['whatsapp_color'];
        $output['whatsapp_position'] = isset($input['whatsapp_position']) && in_array($input['whatsapp_position'], array('left', 'right'), true) ? $input['whatsapp_position'] : 'left';
        $output['whatsapp_delay'] = isset($input['whatsapp_delay']) ? (string) min(30, max(0, absint($input['whatsapp_delay']))) : '0';
        $output['messenger_url'] = isset($input['messenger_url']) ? esc_url_raw(trim($input['messenger_url'])) : '';
        $output['messenger_label'] = isset($input['messenger_label']) ? sanitize_text_field($input['messenger_label']) : self::defaults()['messenger_label'];
        $output['call_number'] = isset($input['call_number']) ? preg_replace('/[^0-9+]/', '', (string) $input['call_number']) : '';
        $output['call_label'] = isset($input['call_label']) ? sanitize_text_field($input['call_label']) : self::defaults()['call_label'];
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
        add_submenu_page('replynextai', __('Free WhatsApp', 'replynext-ai-chat-assistant-for-woocommerce'), __('Free WhatsApp', 'replynext-ai-chat-assistant-for-woocommerce'), 'manage_options', 'replynextai-whatsapp', array($this, 'whatsapp_page'));
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
        <div class="rn-grid rn-grid-3"><?php $this->status_card(__('AI Website Chat', 'replynext-ai-chat-assistant-for-woocommerce'), $ai_ready, $ai_ready ? __('Live and connected', 'replynext-ai-chat-assistant-for-woocommerce') : __('Company ID and activation required', 'replynext-ai-chat-assistant-for-woocommerce'), 'replynextai-ai'); ?><?php $this->status_card(__('Free WhatsApp', 'replynext-ai-chat-assistant-for-woocommerce'), $wa_ready, $wa_ready ? __('Live using your own number', 'replynext-ai-chat-assistant-for-woocommerce') : __('Add a WhatsApp number', 'replynext-ai-chat-assistant-for-woocommerce'), 'replynextai-whatsapp'); ?><?php $this->status_card(__('Display Rules', 'replynext-ai-chat-assistant-for-woocommerce'), true, __('Control pages and devices', 'replynext-ai-chat-assistant-for-woocommerce'), 'replynextai-display'); ?></div>
        <div class="rn-card rn-setup-card"><div><span class="rn-step"><?php esc_html_e('QUICK START', 'replynext-ai-chat-assistant-for-woocommerce'); ?></span><h2><?php esc_html_e('Start converting visitors in three steps', 'replynext-ai-chat-assistant-for-woocommerce'); ?></h2></div><ol class="rn-checklist"><li class="<?php echo $o['server_url'] ? 'done' : ''; ?>"><span>1</span><?php esc_html_e('Confirm your ReplyNext server URL', 'replynext-ai-chat-assistant-for-woocommerce'); ?></li><li class="<?php echo ($ai_ready || $wa_ready) ? 'done' : ''; ?>"><span>2</span><?php esc_html_e('Enable AI Chat or Free WhatsApp', 'replynext-ai-chat-assistant-for-woocommerce'); ?></li><li><span>3</span><?php esc_html_e('Open your website privately and send a test message', 'replynext-ai-chat-assistant-for-woocommerce'); ?></li></ol></div>
        <div class="rn-grid rn-grid-2"><div class="rn-card"><h2><?php esc_html_e('Free WhatsApp, no API required', 'replynext-ai-chat-assistant-for-woocommerce'); ?></h2><p><?php esc_html_e('Open a direct conversation using your own WhatsApp number.', 'replynext-ai-chat-assistant-for-woocommerce'); ?></p><a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=replynextai-whatsapp')); ?>"><?php esc_html_e('Configure Free WhatsApp', 'replynext-ai-chat-assistant-for-woocommerce'); ?></a></div><div class="rn-card rn-upgrade"><h2><?php esc_html_e('Turn chat into an AI sales agent', 'replynext-ai-chat-assistant-for-woocommerce'); ?></h2><p><?php esc_html_e('Capture leads and manage AI conversations in ReplyNext.', 'replynext-ai-chat-assistant-for-woocommerce'); ?></p><a class="button button-secondary" href="<?php echo esc_url(admin_url('admin.php?page=replynextai-ai')); ?>"><?php esc_html_e('Connect AI Website Chat', 'replynext-ai-chat-assistant-for-woocommerce'); ?></a></div></div></div>
        <?php
    }

    private function status_card($title, $ready, $message, $page) {
        ?><a class="rn-card rn-status-card" href="<?php echo esc_url(admin_url('admin.php?page=' . $page)); ?>"><span class="rn-status <?php echo $ready ? 'is-live' : 'is-off'; ?>"><?php echo $ready ? esc_html__('Active', 'replynext-ai-chat-assistant-for-woocommerce') : esc_html__('Setup', 'replynext-ai-chat-assistant-for-woocommerce'); ?></span><h2><?php echo esc_html($title); ?></h2><p><?php echo esc_html($message); ?></p></a><?php
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
        ?><div class="wrap rn-wrap"><?php $this->page_header(__('AI Website Chat', 'replynext-ai-chat-assistant-for-woocommerce'), __('Connect the AI chat module configured in your ReplyNext client portal.', 'replynext-ai-chat-assistant-for-woocommerce')); ?><?php $this->form_open(); $this->preserve_fields(array('server_url', 'company_id', 'ai_enabled', 'ai_sitewide', 'share_user_data')); ?><div class="rn-grid rn-grid-form"><div class="rn-card">
        <label class="rn-toggle-row"><span><strong><?php esc_html_e('Enable AI Website Chat', 'replynext-ai-chat-assistant-for-woocommerce'); ?></strong><small><?php esc_html_e('Also enable it in the ReplyNext portal.', 'replynext-ai-chat-assistant-for-woocommerce'); ?></small></span><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[ai_enabled]" value="1" <?php checked($o['ai_enabled'], '1'); ?> /></label>
        <?php $this->field('server_url', __('ReplyNext server URL', 'replynext-ai-chat-assistant-for-woocommerce'), $o['server_url'], 'https://replynextai.com', 'url'); ?><?php $this->field('company_id', __('Company ID', 'replynext-ai-chat-assistant-for-woocommerce'), $o['company_id'], '12'); ?>
        <label class="rn-check"><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[ai_sitewide]" value="1" <?php checked($o['ai_sitewide'], '1'); ?> /> <?php esc_html_e('Show AI chat on every allowed page', 'replynext-ai-chat-assistant-for-woocommerce'); ?></label><label class="rn-check"><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[share_user_data]" value="1" <?php checked($o['share_user_data'], '1'); ?> /> <?php esc_html_e('Pass logged-in WordPress user name and email', 'replynext-ai-chat-assistant-for-woocommerce'); ?></label><p class="description"><?php esc_html_e('Enable only when covered by your privacy notice.', 'replynext-ai-chat-assistant-for-woocommerce'); ?></p><?php submit_button(__('Save AI Chat Settings', 'replynext-ai-chat-assistant-for-woocommerce')); ?></div>
        <div class="rn-card rn-preview-card"><span class="rn-step"><?php esc_html_e('LIVE PREVIEW', 'replynext-ai-chat-assistant-for-woocommerce'); ?></span><p class="description"><?php esc_html_e('Preview and manage your customer-facing chat from the ReplyNext Client Portal.', 'replynext-ai-chat-assistant-for-woocommerce'); ?></p><div class="rn-site-preview"><div class="rn-browser-bar"><i></i><i></i><i></i></div><div class="rn-preview-content"><h3><?php esc_html_e('ReplyNext AI customer chat', 'replynext-ai-chat-assistant-for-woocommerce'); ?></h3><p><?php esc_html_e('Open the ReplyNext Client Portal to preview and manage your live chat.', 'replynext-ai-chat-assistant-for-woocommerce'); ?></p><a href="https://replynextai.com" target="_blank" rel="noopener noreferrer" class="button button-primary"><?php esc_html_e('Open ReplyNext Client Portal', 'replynext-ai-chat-assistant-for-woocommerce'); ?></a></div></div><p><code>[replynextai_chat]</code></p></div></div></form></div><?php
    }

    public function whatsapp_page() {
        $o = $this->options();
        $preview_whatsapp_url = $o['whatsapp_number'] ? 'https://wa.me/' . rawurlencode($o['whatsapp_number']) . '?text=' . rawurlencode($o['whatsapp_message']) : '#';
        ?><div class="wrap rn-wrap"><?php $this->page_header(__('Contact Buttons', 'replynext-ai-chat-assistant-for-woocommerce'), __('Offer WhatsApp, Messenger, and direct call buttons without any API integration.', 'replynext-ai-chat-assistant-for-woocommerce')); ?><?php $this->form_open(); $this->preserve_fields(array('whatsapp_enabled', 'whatsapp_number', 'whatsapp_message', 'whatsapp_label', 'whatsapp_color', 'whatsapp_position', 'whatsapp_delay', 'messenger_enabled', 'messenger_url', 'messenger_label', 'call_enabled', 'call_number', 'call_label')); ?><div class="rn-grid rn-grid-form"><div class="rn-card">
        <label class="rn-toggle-row"><span><strong><?php esc_html_e('Enable Free WhatsApp Button', 'replynext-ai-chat-assistant-for-woocommerce'); ?></strong><small><?php esc_html_e('Opens wa.me in the visitor’s WhatsApp app.', 'replynext-ai-chat-assistant-for-woocommerce'); ?></small></span><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[whatsapp_enabled]" value="1" <?php checked($o['whatsapp_enabled'], '1'); ?> /></label>
        <?php $this->field('whatsapp_number', __('WhatsApp number', 'replynext-ai-chat-assistant-for-woocommerce'), $o['whatsapp_number'], '8801XXXXXXXXX', 'tel'); ?><p class="description"><?php esc_html_e('Include country code without +, spaces, or dashes.', 'replynext-ai-chat-assistant-for-woocommerce'); ?></p><?php $this->field('whatsapp_label', __('Button label', 'replynext-ai-chat-assistant-for-woocommerce'), $o['whatsapp_label'], __('Chat on WhatsApp', 'replynext-ai-chat-assistant-for-woocommerce')); ?>
        <label class="rn-field"><span><?php esc_html_e('Prefilled message', 'replynext-ai-chat-assistant-for-woocommerce'); ?></span><textarea rows="3" name="<?php echo esc_attr(self::OPTION_KEY); ?>[whatsapp_message]"><?php echo esc_textarea($o['whatsapp_message']); ?></textarea></label>
        <hr /><label class="rn-toggle-row"><span><strong><?php esc_html_e('Enable Messenger Button', 'replynext-ai-chat-assistant-for-woocommerce'); ?></strong><small><?php esc_html_e('Open your Facebook or Messenger link.', 'replynext-ai-chat-assistant-for-woocommerce'); ?></small></span><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[messenger_enabled]" value="1" <?php checked($o['messenger_enabled'], '1'); ?> /></label><?php $this->field('messenger_url', __('Messenger link', 'replynext-ai-chat-assistant-for-woocommerce'), $o['messenger_url'], 'https://m.me/your-page'); ?><?php $this->field('messenger_label', __('Messenger button label', 'replynext-ai-chat-assistant-for-woocommerce'), $o['messenger_label'], __('Message on Messenger', 'replynext-ai-chat-assistant-for-woocommerce')); ?>
        <hr /><label class="rn-toggle-row"><span><strong><?php esc_html_e('Enable Direct Call Button', 'replynext-ai-chat-assistant-for-woocommerce'); ?></strong><small><?php esc_html_e('Start a phone call from the visitor’s device.', 'replynext-ai-chat-assistant-for-woocommerce'); ?></small></span><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[call_enabled]" value="1" <?php checked($o['call_enabled'], '1'); ?> /></label><?php $this->field('call_number', __('Phone number', 'replynext-ai-chat-assistant-for-woocommerce'), $o['call_number'], '+8801XXXXXXXXX', 'tel'); ?><?php $this->field('call_label', __('Call button label', 'replynext-ai-chat-assistant-for-woocommerce'), $o['call_label'], __('Call us', 'replynext-ai-chat-assistant-for-woocommerce')); ?>
        <div class="rn-inline-fields"><label class="rn-field"><span><?php esc_html_e('Color', 'replynext-ai-chat-assistant-for-woocommerce'); ?></span><input type="color" data-rn-preview-color name="<?php echo esc_attr(self::OPTION_KEY); ?>[whatsapp_color]" value="<?php echo esc_attr($o['whatsapp_color']); ?>" /></label><label class="rn-field"><span><?php esc_html_e('Position', 'replynext-ai-chat-assistant-for-woocommerce'); ?></span><select name="<?php echo esc_attr(self::OPTION_KEY); ?>[whatsapp_position]"><option value="left" <?php selected($o['whatsapp_position'], 'left'); ?>><?php esc_html_e('Bottom left', 'replynext-ai-chat-assistant-for-woocommerce'); ?></option><option value="right" <?php selected($o['whatsapp_position'], 'right'); ?>><?php esc_html_e('Bottom right', 'replynext-ai-chat-assistant-for-woocommerce'); ?></option></select></label><label class="rn-field"><span><?php esc_html_e('Delay', 'replynext-ai-chat-assistant-for-woocommerce'); ?></span><input type="number" min="0" max="30" name="<?php echo esc_attr(self::OPTION_KEY); ?>[whatsapp_delay]" value="<?php echo esc_attr($o['whatsapp_delay']); ?>" /></label></div><?php submit_button(__('Save WhatsApp Settings', 'replynext-ai-chat-assistant-for-woocommerce')); ?></div>
        <div class="rn-card rn-preview-card"><span class="rn-step"><?php esc_html_e('LIVE PREVIEW', 'replynext-ai-chat-assistant-for-woocommerce'); ?></span><p class="description"><?php esc_html_e('Click the button to test the customer experience.', 'replynext-ai-chat-assistant-for-woocommerce'); ?></p><div class="rn-phone-preview"><div class="rn-phone-content"><a href="<?php echo esc_url($preview_whatsapp_url); ?>" target="_blank" rel="noopener noreferrer" class="rn-whatsapp-preview <?php echo 'right' === $o['whatsapp_position'] ? 'is-right' : ''; ?>" data-rn-whatsapp-preview style="--rn-wa-color:<?php echo esc_attr($o['whatsapp_color']); ?>"><b>☎</b><em data-rn-preview-label><?php echo esc_html($o['whatsapp_label']); ?></em></a></div></div><p><code>[replynextai_whatsapp]</code></p></div></div></form></div><?php
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
        <?php /* phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This is a read-only redirect notice; form processing is protected by settings_fields() and the admin-post nonce. */ if (isset($_GET['rn_notice'])) : ?><div class="notice <?php echo 'success' === sanitize_key(wp_unslash($_GET['rn_notice'])) ? 'notice-success' : 'notice-error'; ?> is-dismissible"><p><?php echo 'success' === sanitize_key(wp_unslash($_GET['rn_notice'])) ? esc_html__('Connection successful.', 'replynext-ai-chat-assistant-for-woocommerce') : esc_html__('Connection failed. Check the server URL and token.', 'replynext-ai-chat-assistant-for-woocommerce'); ?></p></div><?php endif; ?>
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
            $options['company_id'] = (string) absint($status['company']['id']);
            update_option(self::OPTION_KEY, $options);
        }
        wp_safe_redirect(admin_url('admin.php?page=replynextai-connection&rn_notice=' . ($success ? 'success' : 'error')));
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
        $result = $this->sync_products();
        wp_safe_redirect(admin_url('admin.php?page=replynextai-woocommerce&rn_sync=' . ($result ? 'success' : 'error')));
        exit;
    }

    public function schedule_product_sync($post_id, $post, $update) {
        if (wp_is_post_revision($post_id) || 'product' !== $post->post_type || !$update) return;
        if (!wp_next_scheduled('replynextai_deferred_product_sync')) {
            wp_schedule_single_event(time() + 60, 'replynextai_deferred_product_sync');
        }
    }

    public function sync_products() {
        if (!function_exists('wc_get_products')) return false;
        $o = $this->options();
        if (!$o['api_token'] || !$o['server_url']) return false;
        $total_synced = 0;
        $page = 1;
        $sync_id = wp_generate_uuid4();
        do {
        $products = array();
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
        }
        if (!$products) break;
        $response = wp_remote_post($o['server_url'] . '/api/wordpress/products', array(
            'timeout' => 30,
            'headers' => array('Authorization' => 'Bearer ' . $o['api_token'], 'Content-Type' => 'application/json'),
            'body' => wp_json_encode(array('siteUrl' => home_url('/'), 'sync_id' => $sync_id, 'products' => $products)),
        ));
        if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
            set_transient('replynextai_last_sync', __('Last sync failed.', 'replynext-ai-chat-assistant-for-woocommerce'), DAY_IN_SECONDS);
            return false;
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['success'])) return false;
        $total_synced += absint($body['synced']);
        $page++;
        } while (count($batch_products) === 100);
        $complete = wp_remote_post($o['server_url'] . '/api/wordpress/products', array(
            'timeout' => 30,
            'headers' => array('Authorization' => 'Bearer ' . $o['api_token'], 'Content-Type' => 'application/json'),
            'body' => wp_json_encode(array('siteUrl' => home_url('/'), 'sync_id' => $sync_id, 'sync_complete' => true, 'products' => array())),
        ));
        if (is_wp_error($complete) || 200 !== wp_remote_retrieve_response_code($complete)) {
            set_transient('replynextai_last_sync', __('Product sync incomplete; existing products were preserved.', 'replynext-ai-chat-assistant-for-woocommerce'), DAY_IN_SECONDS);
            return false;
        }
        /* translators: 1: number of products synchronized, 2: number of batches used. */
        set_transient('replynextai_last_sync', sprintf(__('Last sync completed: %1$d products across %2$d batches.', 'replynext-ai-chat-assistant-for-woocommerce'), $total_synced, max(1, $page - 1)), DAY_IN_SECONDS);
        delete_transient('replynextai_status_cache');
        return true;
    }

    public function help_page() {
        ?><div class="wrap rn-wrap"><?php $this->page_header(__('Help & Shortcodes', 'replynext-ai-chat-assistant-for-woocommerce'), __('Use automatic display, shortcodes, or both.', 'replynext-ai-chat-assistant-for-woocommerce')); ?><div class="rn-grid rn-grid-2"><div class="rn-card"><h2><?php esc_html_e('AI Website Chat', 'replynext-ai-chat-assistant-for-woocommerce'); ?></h2><p><code>[replynextai_chat]</code></p><p><code>[replynextai_chat company_id="12" server_url="https://replynextai.com"]</code></p></div><div class="rn-card"><h2><?php esc_html_e('Free WhatsApp', 'replynext-ai-chat-assistant-for-woocommerce'); ?></h2><p><code>[replynextai_whatsapp]</code></p><p><code>[replynextai_whatsapp number="8801XXXXXXXXX" message="Hello"]</code></p></div></div></div><?php
    }

    private function field($key, $label, $value, $placeholder = '', $type = 'text') {
        printf('<label class="rn-field"><span>%s</span><input type="%s" name="%s[%s]" value="%s" placeholder="%s" /></label>', esc_html($label), esc_attr($type), esc_attr(self::OPTION_KEY), esc_attr($key), esc_attr($value), esc_attr($placeholder));
    }

    public function frontend_assets() {
        if (is_admin() || !$this->display_allowed()) return;
        $o = $this->options();
        if (('1' === $o['whatsapp_enabled'] && $o['whatsapp_number']) || ('1' === $o['messenger_enabled'] && $o['messenger_url']) || ('1' === $o['call_enabled'] && $o['call_number'])) {
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
        if ($this->display_allowed()) echo $this->whatsapp_markup($o); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
        if ('1' === $o['whatsapp_enabled'] && $o['whatsapp_number']) {
            $url = 'https://wa.me/' . rawurlencode($o['whatsapp_number']) . '?text=' . rawurlencode($o['whatsapp_message']);
            $buttons[] = sprintf('<a class="rn-wa-button rn-wa-%s" href="%s" target="_blank" rel="noopener noreferrer" aria-label="%s" style="--rn-wa-color:%s" data-rn-delay="%d"><span class="rn-wa-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M20 4.5A10 10 0 0 0 3.8 17.2L3 21l3.9-1A10 10 0 1 0 20 4.5Zm-8 15a8 8 0 0 1-4-1.1l-.3-.2-2.3.6.6-2.2-.2-.3A8 8 0 1 1 12 19.5Zm4.4-5.9c-.2-.1-1.4-.7-1.6-.8-.2-.1-.4-.1-.6.1l-.7.9c-.1.2-.3.2-.5.1a6.4 6.4 0 0 1-1.9-1.2 7 7 0 0 1-1.3-1.7c-.1-.2 0-.4.1-.5l.4-.5.2-.4c.1-.1 0-.3 0-.4l-.7-1.6c-.2-.4-.4-.4-.6-.4h-.5c-.2 0-.4.1-.6.3-.2.2-.8.8-.8 2s.8 2.3.9 2.5c.1.2 1.6 2.5 3.9 3.5.5.2.9.3 1.2.4.5.2 1 .1 1.4.1.4-.1 1.4-.6 1.6-1.1.2-.5.2-.9.1-1Z"/></svg></span><span class="rn-wa-label">%s</span></a>', 'right' === $o['whatsapp_position'] ? 'right' : 'left', esc_url($url), esc_attr($o['whatsapp_label']), esc_attr($o['whatsapp_color']), absint($o['whatsapp_delay']), esc_html($o['whatsapp_label']));
        }
        if ('1' === $o['messenger_enabled'] && $o['messenger_url']) $buttons[] = sprintf('<a class="rn-wa-button rn-wa-%s" href="%s" target="_blank" rel="noopener noreferrer" aria-label="%s" style="--rn-wa-color:#1877f2"><span class="rn-wa-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M12 2C6.5 2 2 6.1 2 11.1c0 2.9 1.5 5.5 4 7.2V22l3.5-1.9c.8.2 1.6.3 2.5.3 5.5 0 10-4.1 10-9.3S17.5 2 12 2Zm1 12.2-2.6-2.8-5 2.8 5.5-5.8 2.6 2.8 4.9-2.8-5.4 5.8Z"/></svg></span><span class="rn-wa-label">%s</span></a>', 'right' === $o['whatsapp_position'] ? 'right' : 'left', esc_url($o['messenger_url']), esc_attr($o['messenger_label']), esc_html($o['messenger_label']));
        if ('1' === $o['call_enabled'] && $o['call_number']) $buttons[] = sprintf('<a class="rn-wa-button rn-wa-%s" href="%s" aria-label="%s" style="--rn-wa-color:#334155"><span class="rn-wa-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M6.6 2.8 9 2.2c.6-.1 1.2.2 1.5.8l1.2 2.8c.2.5.1 1-.2 1.4L10 8.9a13.6 13.6 0 0 0 5.1 5.1l1.7-1.5c.4-.3.9-.4 1.4-.2l2.8 1.2c.6.3.9.9.8 1.5l-.6 2.4c-.2.8-.9 1.4-1.7 1.5C10.3 19.8 4.2 13.7 5.1 4.5c.1-.8.7-1.5 1.5-1.7Z"/></svg></span><span class="rn-wa-label">%s</span></a>', 'right' === $o['whatsapp_position'] ? 'right' : 'left', esc_url('tel:' . $o['call_number']), esc_attr($o['call_label']), esc_html($o['call_label']));
        if (!$buttons) return '';
        $menu = implode('', $buttons);
        return sprintf('<div class="rn-contact-float rn-contact-%s" data-rn-contact-float><div class="rn-contact-menu" data-rn-contact-menu>%s</div><button type="button" class="rn-contact-main" aria-expanded="false" aria-label="%s" data-rn-contact-toggle><span class="rn-contact-tooltip">%s</span><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 5.5A3.5 3.5 0 0 1 7.5 2h9A3.5 3.5 0 0 1 20 5.5v6a3.5 3.5 0 0 1-3.5 3.5H12l-4.5 4v-4.1A3.5 3.5 0 0 1 4 11.5v-6Zm4 3.2h8m-8 3h5"/></svg></button></div>', 'right' === $o['whatsapp_position'] ? 'right' : 'left', $menu, esc_attr__('Open contact options', 'replynext-ai-chat-assistant-for-woocommerce'), esc_html__('Contact Us', 'replynext-ai-chat-assistant-for-woocommerce'));
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
        if (empty($config['company_id']) || empty($config['server_url']) || !wp_http_validate_url($config['server_url'])) return false;
        $this->ai_config = array('company_id' => $config['company_id'], 'name' => '', 'email' => '', 'is_user' => 'false');
        if ('1' === $config['share_user_data'] && is_user_logged_in()) {
            $user = wp_get_current_user();
            $this->ai_config['name'] = $user->display_name;
            $this->ai_config['email'] = $user->user_email;
            $this->ai_config['is_user'] = 'true';
        }
        wp_enqueue_script(self::AI_HANDLE, $config['server_url'] . '/chat-widget.js', array(), REPLYNEXTAI_VERSION, true);
        return true;
    }

    public function ai_script_attributes($tag, $handle, $src) {
        if (self::AI_HANDLE !== $handle || !$this->ai_config) return $tag;
        /* phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- This modifies the tag for the already-enqueued external widget to pass its documented data attributes. */
        return sprintf('<script src="%s" data-company-id="%s" data-visitor-name="%s" data-visitor-email="%s" data-is-user="%s" id="%s-js"></script>', esc_url($src), esc_attr($this->ai_config['company_id']), esc_attr($this->ai_config['name']), esc_attr($this->ai_config['email']), esc_attr($this->ai_config['is_user']), esc_attr(self::AI_HANDLE));
    }
}
