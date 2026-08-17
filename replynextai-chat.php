<?php
/**
 * Plugin Name: ReplyNext AI Chat & WhatsApp
 * Plugin URI: https://replynextai.com
 * Description: AI website chat plus WhatsApp, Messenger, and phone contact buttons, targeting, and WooCommerce catalog sync.
 * Version: 1.2.0
 * Author: ReplyNext AI
 * Author URI: https://replynextai.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: replynextai-chat
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('REPLYNEXTAI_VERSION', '1.2.0');
define('REPLYNEXTAI_FILE', __FILE__);
define('REPLYNEXTAI_DIR', plugin_dir_path(__FILE__));
define('REPLYNEXTAI_URL', plugin_dir_url(__FILE__));

require_once REPLYNEXTAI_DIR . 'includes/class-replynextai-plugin.php';

register_activation_hook(__FILE__, array('ReplyNextAI_Plugin', 'activate'));
ReplyNextAI_Plugin::instance();
