<?php
/**
 * Plugin Name:       Admin Menu Swap
 * Plugin URI:        https://github.com/Rubberplants-Digital-Agency/Admin-Menu-Swap
 * Description:       Reorder the WordPress admin menu with drag and drop, hide items you never use, and add or remove separators to group menu items.
 * Version:           1.6.2
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Rubberplants
 * Author URI:        https://rubberplants.nl/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       admin-menu-swap
 * Domain Path:       /languages
 * Update URI:        https://github.com/Rubberplants-Digital-Agency/Admin-Menu-Swap
 *
 * @package Admin_Menu_Swap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * More than one copy can be loaded: this plugin, and copies built into site
 * plugins (which require this file from their own folder). Each copy only
 * registers itself here; on plugins_loaded the newest one defines the
 * constants and loads its classes, so nothing is ever declared twice.
 */
if ( ! function_exists( 'amswap_register_copy' ) ) {
	/**
	 * Register a copy of Admin Menu Swap.
	 *
	 * @param string $version Version of the copy.
	 * @param string $file    Absolute path of the copy's admin-menu-swap.php.
	 */
	function amswap_register_copy( $version, $file ) {
		$GLOBALS['amswap_copies'][ $file ] = $version;
	}

	/**
	 * Load and boot the newest registered copy.
	 */
	function amswap_load_newest_copy() {
		// Copies before 1.3.0 define AMSWAP_VERSION and load their classes as
		// soon as their file is included, and boot themselves. Leave the field
		// to such a copy rather than declare the classes a second time.
		if ( defined( 'AMSWAP_VERSION' ) || empty( $GLOBALS['amswap_copies'] ) ) {
			if ( defined( 'AMSWAP_VERSION' ) && ! empty( $GLOBALS['amswap_copies'] ) ) {
				add_action( 'admin_notices', 'amswap_old_copy_notice' );
			}

			return;
		}

		$copies = $GLOBALS['amswap_copies'];
		uasort( $copies, 'version_compare' );

		$file = (string) array_key_last( $copies );

		define( 'AMSWAP_VERSION', $copies[ $file ] );
		define( 'AMSWAP_SLUG', 'admin-menu-swap' );
		define( 'AMSWAP_PLUGIN_FILE', $file );
		define( 'AMSWAP_PLUGIN_DIR', plugin_dir_path( $file ) );
		define( 'AMSWAP_PLUGIN_URL', plugin_dir_url( $file ) );
		define( 'AMSWAP_PLUGIN_BASENAME', plugin_basename( $file ) );

		require_once AMSWAP_PLUGIN_DIR . 'includes/load.php';
	}
	add_action( 'plugins_loaded', 'amswap_load_newest_copy', 1 );

	/**
	 * Say which old copy runs in the place of the newer one, which otherwise
	 * steps aside without a word while the plugins screen shows its version.
	 */
	function amswap_old_copy_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$copies = $GLOBALS['amswap_copies'];
		uasort( $copies, 'version_compare' );

		$newest = (string) end( $copies );
		$file   = defined( 'AMSWAP_PLUGIN_FILE' ) ? wp_normalize_path( (string) AMSWAP_PLUGIN_FILE ) : '';
		$file   = str_replace( wp_normalize_path( WP_CONTENT_DIR ), 'wp-content', $file );

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: installed version, 2: version of the old copy, 3: file of the old copy. */
					__( 'Admin Menu Swap %1$s is installed, but an old copy (%2$s) in %3$s runs instead. Delete that copy and %1$s takes over, with the saved menu.', 'admin-menu-swap' ),
					$newest,
					(string) AMSWAP_VERSION,
					$file
				)
			)
		);
	}
}

amswap_register_copy( '1.6.2', __FILE__ );
