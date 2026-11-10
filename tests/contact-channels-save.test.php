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
function update_option($key, $value) {
    global $stored_options, $plugin, $simulate_save_failure, $simulate_save_exception;
    if ($simulate_save_exception) throw new RuntimeException('Simulated storage exception');
    // WordPress update_option runs the registered settings sanitizer.
    $sanitized = $plugin->sanitize_options($value);
    if ($simulate_save_failure) return false;
    $changed = $stored_options !== $sanitized; $stored_options = $sanitized;
    return $changed;
}

require_once __DIR__ . '/../includes/class-replynextai-plugin.php';

function expect_contact_save($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$plugin = (new ReflectionClass('ReplyNextAI_Plugin'))->newInstanceWithoutConstructor();
$simulate_save_failure = false; $simulate_save_exception = false;
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

$stored_options = array_merge(ReplyNextAI_Plugin::defaults(), array('server_url' => 'https://replynextai.com', 'api_token' => 'private-test-token', 'company_id' => '0', 'ai_enabled' => '1'));
$method = new ReflectionMethod('ReplyNextAI_Plugin', 'save_verified_company');
expect_contact_save($method->invoke($plugin, $stored_options, '12'), 'authenticated company binding survives the WordPress option sanitizer');
expect_contact_save('12' === $stored_options['company_id'] && '1' === $stored_options['ai_enabled'], 'authenticated save preserves company and unrelated settings');
expect_contact_save($method->invoke($plugin, $stored_options, '12'), 'unchanged already-saved binding remains successful when update_option returns false');
$form = $plugin->sanitize_options(array_merge($stored_options, array('company_id' => '99')));
expect_contact_save('12' === $form['company_id'], 'browser-submitted company id cannot alter authenticated binding');
$simulate_save_failure = true;
expect_contact_save(!$method->invoke($plugin, $stored_options, '13'), 'failed database write does not report a successful binding');
$simulate_save_failure = false;
expect_contact_save(!$method->invoke($plugin, $stored_options, '0'), 'zero is not a verified company');
$simulate_save_exception = true;
expect_contact_save(!$method->invoke($plugin, $stored_options, '13'), 'storage exceptions fail safely');
$simulate_save_exception = false;
$form = $plugin->sanitize_options(array_merge($stored_options, array('company_id' => '99')));
expect_contact_save('12' === $form['company_id'], 'exception clears the server-only override');

echo "PASS: Contact Channels save behavior\n";
