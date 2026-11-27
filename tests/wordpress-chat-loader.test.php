<?php

define('ABSPATH', __DIR__ . '/');
define('REPLYNEXTAI_URL', 'https://store.example/wp-content/plugins/replynextai/');
define('REPLYNEXTAI_FILE', __FILE__);
define('REPLYNEXTAI_VERSION', 'test-version');

$options = array('ai_enabled' => '1', 'ai_sitewide' => '1', 'api_token' => 'private-connection-token');
$scripts = array();
$hooks = array();
$mobile = true;
$logged_in = false;

function get_option($key, $default = false) {
    global $options;
    if ('replynextai_options' === $key) return $options;
    if ('replynextai_widget_id' === $key) return 'public-widget-id';
    return $default;
}
function wp_parse_args($args, $defaults) { return array_merge($defaults, $args); }
function untrailingslashit($value) { return rtrim($value, '/'); }
function esc_url_raw($value) { return $value; }
function wp_http_validate_url($value) { return filter_var($value, FILTER_VALIDATE_URL); }
function sanitize_text_field($value) { return trim($value); }
function absint($value) { return abs((int) $value); }
function add_query_arg($key, $value, $url) { return $url . '?' . http_build_query(array($key => $value)); }
function is_admin() { return false; }
function is_feed() { return false; }
function is_robots() { return false; }
function is_trackback() { return false; }
function get_queried_object_id() { return 1; }
function wp_is_mobile() { return $GLOBALS['mobile']; }
function is_user_logged_in() { return $GLOBALS['logged_in']; }
function wp_get_current_user() { return (object) array('display_name' => 'Test </script> Visitor', 'user_email' => 'test@example.org'); }
function wp_enqueue_script($handle, $src, $deps, $version, $footer) { $GLOBALS['scripts'][] = array($handle, $src, $deps, $version, $footer); }
function wp_json_encode($data, $flags) { return json_encode($data, $flags); }
function wp_print_inline_script_tag($data, $attributes) {
    $GLOBALS['config_attrs'] = $attributes;
    echo $data;
}
function add_filter($hook, $callback) { $GLOBALS['hooks'][$hook] = $callback; }
function add_action($hook, $callback) { $GLOBALS['hooks'][$hook] = $callback; }
function add_shortcode() {}
function plugin_basename($path) { return basename($path); }

require_once __DIR__ . '/../includes/class-replynextai-plugin.php';

function check_loader($condition, $description) {
    if (!$condition) { fwrite(STDERR, "FAIL: $description\n"); exit(1); }
}
function rendered_widget_config($plugin) {
    ob_start();
    $plugin->print_ai_widget_config();
    return ob_get_clean();
}

$plugin = ReplyNextAI_Plugin::instance();
$pattern = '/replynextai-chat-loader.js';
foreach (array('litespeed_optimize_js_excludes', 'litespeed_optm_js_defer_exc', 'litespeed_optm_gm_js_exc') as $hook) {
    check_loader(isset($hooks[$hook]), "$hook is registered");
    check_loader(array('existing.js', $pattern) === call_user_func($hooks[$hook], array('existing.js')), 'only the ReplyNext loader is added');
    check_loader(array($pattern) === call_user_func($hooks[$hook], array($pattern)), 'exclusion is not duplicated');
}
check_loader(isset($hooks['wp_print_footer_scripts']), 'configuration is emitted in the footer');

$new_plugin = new ReflectionClass('ReplyNextAI_Plugin');
foreach (array(true, false) as $mobile) {
    $plugin = $new_plugin->newInstanceWithoutConstructor();
    $scripts = array();
    $plugin->frontend_assets();
    $plugin->frontend_assets();
    check_loader(array(array(ReplyNextAI_Plugin::AI_HANDLE, REPLYNEXTAI_URL . ReplyNextAI_Plugin::AI_LOADER, array(), REPLYNEXTAI_VERSION, true)) === $scripts, 'local loader is enqueued once on desktop and mobile');
    $json = rendered_widget_config($plugin);
    check_loader('application/json' === $config_attrs['type'] && 'replynextai-wordpress-widget-config' === $config_attrs['id'], 'configuration is inert and independently addressable');
    check_loader(array('widgetId' => 'public-widget-id', 'scriptUrl' => 'https://replynextai.com/chat-widget.js?ver=test-version') === json_decode($json, true), 'only public widget configuration is emitted by default');
}

$mobile = true;
$options['hide_mobile'] = '1';
$plugin = $new_plugin->newInstanceWithoutConstructor();
$scripts = array();
$plugin->frontend_assets();
check_loader(array() === $scripts && '' === rendered_widget_config($plugin), 'explicit hide-on-mobile setting remains effective');

$options['hide_mobile'] = '0';
$options['share_user_data'] = '1';
$logged_in = true;
$plugin = $new_plugin->newInstanceWithoutConstructor();
$plugin->frontend_assets();
$json = rendered_widget_config($plugin);
check_loader(false === strpos($json, '</script>') && false === strpos($json, 'private-connection-token'), 'script delimiters are escaped and private token is not sent');
check_loader('Test </script> Visitor' === json_decode($json, true)['visitor']['name'], 'opted-in visitor data still reaches the host script');

echo "PASS: WordPress AI loader, display rules and LiteSpeed exclusions\n";
