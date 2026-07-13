<?php
/**
 * Plugin Name:       Admin Menu Swap
 * Plugin URI:        https://rubberplants.nl/
 * Description:       Reorder the WordPress admin menu with drag and drop, and add or remove separators to group menu items.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Bjorn (Rubberplants)
 * Author URI:        https://rubberplants.nl/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       admin-menu-swap
 * Domain Path:       /languages
 *
 * @package Admin_Menu_Swap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AMSWAP_VERSION', '1.0.0' );
define( 'AMSWAP_SLUG', 'admin-menu-swap' );
define( 'AMSWAP_PLUGIN_FILE', __FILE__ );
define( 'AMSWAP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AMSWAP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AMSWAP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Core requires. No Composer, no autoloader — plain includes.
 */
require_once AMSWAP_PLUGIN_DIR . 'includes/class-amswap-order-store.php';
require_once AMSWAP_PLUGIN_DIR . 'includes/class-amswap-menu-reorder.php';
require_once AMSWAP_PLUGIN_DIR . 'includes/class-amswap-plugin.php';

/**
 * Boot the plugin.
 *
 * @return AMSwap_Plugin
 */
function amswap() {
	return AMSwap_Plugin::instance();
}
add_action( 'plugins_loaded', 'amswap', 5 );
