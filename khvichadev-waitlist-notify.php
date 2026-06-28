<?php
/**
 * Plugin Name: KhvichaDev - Waitlist & Notify
 * Description: Allows users to pre-register for upcoming products, services, or applications and enables admins to send batch notifications with a single click.
 * Version: 1.0
 * Author: KhvichaDev
 * Author URI: https://khvichadev.com
 * Plugin URI: https://github.com/KhvichaDev/KD-Waitlist-Notify
 * Requires at least: 5.6
 * Requires PHP: 8.2
 * Tested up to: 7.0
 * Text Domain: khvichadev-waitlist-notify
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Define plugin paths and constants.
 */
define('KDWN_PATH', plugin_dir_path(__FILE__));
define('KDWN_URL', plugin_dir_url(__FILE__));
define('KDWN_VERSION', '1.0');

/**
 * Load core database file.
 */
require_once KDWN_PATH . 'core/kd-database.php';

/**
 * Activation hook to build the database table.
 */
register_activation_hook(__FILE__, 'kdwn_activate');
function kdwn_activate() {
    kdwn_Database::kdwn_create_table();
}

/**
 * Load features.
 */
// Load frontend widget/shortcode feature
require_once KDWN_PATH . 'features/widget/controller/kd-signup-handler.php';
require_once KDWN_PATH . 'features/widget/ui/kd-signup-form.php';

// Load admin dashboard feature
require_once KDWN_PATH . 'features/dashboard/controller/kd-admin-handler.php';
require_once KDWN_PATH . 'features/dashboard/ui/kd-admin-page.php';

/**
 * Initialize all features.
 */
add_action('plugins_loaded', 'kdwn_init');
function kdwn_init() {
    // Instantiate signup form and signup AJAX handler
    new kdwn_Signup_Form();
    new kdwn_Signup_Handler();

    // Instantiate admin page and admin AJAX handler
    new kdwn_Admin_Handler();
}
