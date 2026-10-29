<?php
/**
 * Uninstall handler: remove the saved menu order and the menu snapshot.
 *
 * @package Admin_Menu_Swap
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// A copy built into an active site plugin keeps using the same options;
// this plugin is inactive while it is uninstalled, so any registered copy
// is another one.
if ( ! empty( $GLOBALS['amswap_copies'] ) ) {
	return;
}

$amswap_options = array( 'amswap_menu_order', 'amswap_menu_profiles', 'amswap_menu_snapshot', 'amswap_agency' );

if ( is_multisite() ) {
	// number => 0 removes the default 100-site limit so every site is cleaned.
	$amswap_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $amswap_site_ids as $amswap_site_id ) {
		switch_to_blog( $amswap_site_id );
		array_map( 'delete_option', $amswap_options );
		restore_current_blog();
	}
} else {
	array_map( 'delete_option', $amswap_options );
}

delete_site_transient( 'amswap_latest_release' );
