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

amswap();
