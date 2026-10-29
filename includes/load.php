<?php
/**
 * Loads the classes of the copy chosen by amswap_load_newest_copy() and boots it.
 *
 * @package Admin_Menu_Swap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core requires. No Composer, no autoloader — plain includes.
 */
require_once AMSWAP_PLUGIN_DIR . 'includes/class-amswap-agency.php';
require_once AMSWAP_PLUGIN_DIR . 'includes/class-amswap-order-store.php';
require_once AMSWAP_PLUGIN_DIR . 'includes/class-amswap-preset.php';
require_once AMSWAP_PLUGIN_DIR . 'includes/class-amswap-menu-snapshot.php';
require_once AMSWAP_PLUGIN_DIR . 'includes/class-amswap-menu-reorder.php';
require_once AMSWAP_PLUGIN_DIR . 'includes/class-amswap-menu-model.php';
require_once AMSWAP_PLUGIN_DIR . 'includes/class-amswap-plugin.php';

/**
 * Get the plugin instance.
 *
 * @return AMSwap_Plugin
 */
function amswap() {
	return AMSwap_Plugin::instance();
}

/**
 * Note on this site which copies built into other plugins are loaded.
 *
 * On a network, uninstalling the standalone plugin cleans every site, and
 * it can only see the copies loaded on the main site; the note tells it
 * which sites still use the options (see uninstall.php). Every registered
 * copy counts, not just the newest. Sites without such a copy never read
 * the option, and it is only written when it changes.
 */
function amswap_note_embedded_copies() {
	$embedded = array();

	foreach ( array_keys( $GLOBALS['amswap_copies'] ) as $file ) {
		$basename = plugin_basename( $file );

		if ( 'admin-menu-swap/admin-menu-swap.php' !== $basename ) {
			$embedded[] = $basename;
		}
	}

	if ( ! $embedded ) {
		return;
	}

	sort( $embedded );

	if ( get_option( 'amswap_embedded_copy' ) !== $embedded ) {
		update_option( 'amswap_embedded_copy', $embedded, false );
	}
}

amswap();

if ( is_admin() ) {
	amswap_note_embedded_copies();
}
