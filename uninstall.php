<?php
/**
 * Uninstall handler: remove the saved menu order and the menu snapshot.
 *
 * @package Admin_Menu_Swap
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// A copy built into an active site plugin keeps using the same options, so
// then nothing is removed. This plugin's own copy does not count: it is
// still loaded when WP-CLI uninstalls it with --deactivate. Copies before
// 1.3.0 do not register, but define the constants as soon as they load.
$amswap_own_file = wp_normalize_path( __DIR__ . '/admin-menu-swap.php' );

foreach ( array_keys( isset( $GLOBALS['amswap_copies'] ) ? (array) $GLOBALS['amswap_copies'] : array() ) as $amswap_copy_file ) {
	if ( wp_normalize_path( (string) $amswap_copy_file ) !== $amswap_own_file ) {
		return;
	}
}

if ( defined( 'AMSWAP_VERSION' ) && ( ! defined( 'AMSWAP_PLUGIN_FILE' ) || wp_normalize_path( AMSWAP_PLUGIN_FILE ) !== $amswap_own_file ) ) {
	return;
}

$amswap_options = array( 'amswap_menu_order', 'amswap_menu_profiles', 'amswap_menu_snapshot', 'amswap_agency', 'amswap_embedded_copy' );

if ( is_multisite() ) {
	// The guard above only sees the plugins of the main site. A subsite with
	// a copy built into one of its own active plugins has noted that copy
	// (see amswap_note_embedded_copies()); its options stay.
	$amswap_network_plugins = array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) );

	// number => 0 removes the default 100-site limit so every site is cleaned.
	$amswap_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $amswap_site_ids as $amswap_site_id ) {
		switch_to_blog( $amswap_site_id );

		$amswap_in_use = false;

		foreach ( (array) get_option( 'amswap_embedded_copy', array() ) as $amswap_copy ) {
			$amswap_folder = strtok( (string) $amswap_copy, '/' );

			// The owning plugin sits in the same top-level folder as its copy.
			foreach ( array_merge( (array) get_option( 'active_plugins', array() ), $amswap_network_plugins ) as $amswap_plugin ) {
				if ( false !== $amswap_folder && strtok( (string) $amswap_plugin, '/' ) === $amswap_folder ) {
					$amswap_in_use = true;
					break 2;
				}
			}
		}

		if ( ! $amswap_in_use ) {
			array_map( 'delete_option', $amswap_options );
			delete_transient( 'amswap_any_agency' );
		}

		restore_current_blog();
	}
} else {
	array_map( 'delete_option', $amswap_options );
	delete_transient( 'amswap_any_agency' );
}

delete_site_transient( 'amswap_latest_release' );
