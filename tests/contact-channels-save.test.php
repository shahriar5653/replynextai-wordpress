<?php

define('ABSPATH', __DIR__ . '/');
define('REPLYNEXTAI_FILE', __FILE__);

$stored_options = array('contact_enabled' => '1');

function get_option($key, $default = false) {
    global $stored_options;
    return 'replynextai_options' === $key ? $stored_options : $default;
}
function wp_parse_args($args, $defaults) { return array_merge($defaults, $args); }
function untrailingslashit($value) { return rtrim((string) $value, '/'); }
function esc_url_raw($value) { return $value; }
function wp_http_validate_url($value) { return filter_var($value, FILTER_VALIDATE_URL) ? $value : false; }
function sanitize_text_field($value) { return trim((string) $value); }
function sanitize_textarea_field($value) { return trim((string) $value); }
function absint($value) { return abs((int) $value); }
function sanitize_hex_color($value) { return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? $value : null; }
function __($value) { return $value; }
function add_settings_error() {}

require_once __DIR__ . '/../includes/class-replynextai-plugin.php';

function expect_contact_save($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$plugin = (new ReflectionClass('ReplyNextAI_Plugin'))->newInstanceWithoutConstructor();
$sanitize = new ReflectionMethod('ReplyNextAI_Plugin', 'sanitize_options');
$sanitize->setAccessible(true);

$base = array('server_url' => 'https://replynextai.com', 'api_token' => 'test-token');

$saved = $sanitize->invoke($plugin, array_merge($base, array('whatsapp_enabled' => '1', 'messenger_enabled' => '1', 'call_enabled' => '1')));
expect_contact_save('1' === $saved['contact_enabled'], 'a non-rendered contact_enabled field preserves the saved setting');
expect_contact_save('1' === $saved['whatsapp_enabled'], 'rendered WhatsApp toggle saves true');
expect_contact_save('1' === $saved['messenger_enabled'], 'rendered Messenger toggle saves true');
expect_contact_save('1' === $saved['call_enabled'], 'rendered Phone toggle saves true');

$saved = $sanitize->invoke($plugin, $base);
expect_contact_save('0' === $saved['whatsapp_enabled'], 'missing rendered WhatsApp checkbox saves false');
expect_contact_save('0' === $saved['messenger_enabled'], 'missing rendered Messenger checkbox saves false');
expect_contact_save('0' === $saved['call_enabled'], 'missing rendered Phone checkbox saves false');
expect_contact_save('0' === $saved['contact_show_label'], 'missing rendered launcher show-label checkbox saves false');

$saved = $sanitize->invoke($plugin, array_merge($base, array('contact_show_label' => '1', 'whatsapp_show_label' => '1', 'messenger_show_label' => '1', 'call_show_label' => '1')));
expect_contact_save('1' === $saved['contact_show_label'], 'rendered launcher show-label checkbox saves true');
expect_contact_save('1' === $saved['whatsapp_show_label'], 'rendered channel show-label checkbox saves true');
expect_contact_save('1' === $saved['messenger_show_label'], 'rendered channel show-label checkbox saves true');
expect_contact_save('1' === $saved['call_show_label'], 'rendered channel show-label checkbox saves true');

echo "PASS: Contact Channels save behavior\n";
