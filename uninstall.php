<?php
/**
 * Uninstall handler: remove the saved menu order.
 *
 * @package Admin_Menu_Swap
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

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
		delete_option( 'amswap_menu_order' );
		restore_current_blog();
	}
} else {
	delete_option( 'amswap_menu_order' );
}
