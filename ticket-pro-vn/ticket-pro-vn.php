<?php
/**
 * Plugin Name: Ticket Pro VN
 * Plugin URI: https://osb.vn
 * Description: Secure, multilingual support tickets for WordPress sites.
 * Version: 1.9.34
 * Author: Ticket Pro VN Team
 * Author URI: https://osb.vn
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: ticket-pro-vn
 * Domain Path: /languages
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

define('TPV_VERSION', '1.9.34');
define('TPV_FILE', __FILE__);
define('TPV_DIR', plugin_dir_path(__FILE__));
define('TPV_URL', plugin_dir_url(__FILE__));
define('TPV_LICENSE_SERVER_URL', 'https://license.osb.vn');
define('TPV_LICENSE_PUBLIC_KEY_FILE', TPV_DIR . 'includes/license-public-key.pem');

if (version_compare(PHP_VERSION, '5.6.0', '<')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>' . esc_html__('Ticket Pro VN requires PHP 5.6 or later.', 'ticket-pro-vn') . '</p></div>';
    });
    return;
}

require_once TPV_DIR . 'includes/class-tpv-db.php';
require_once TPV_DIR . 'includes/class-tpv-security.php';
require_once TPV_DIR . 'includes/class-tpv-license.php';
require_once TPV_DIR . 'includes/class-tpv-plugin.php';
require_once TPV_DIR . 'includes/class-tpv-front.php';
require_once TPV_DIR . 'includes/class-tpv-admin.php';

register_activation_hook(TPV_FILE, array('TPV_Plugin', 'activate'));
register_deactivation_hook(TPV_FILE, array('TPV_Plugin', 'deactivate'));

add_action('plugins_loaded', array('TPV_Plugin', 'instance'));
