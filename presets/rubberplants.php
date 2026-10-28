<?php
/**
 * The Rubberplants preset: the admin menu ordered by how often an item is
 * used and whether the client works with it, with plugin families together.
 *
 * Not loaded by default. A site plugin opts in with:
 *
 *     add_filter( 'amswap_preset', static function () {
 *         return require AMSWAP_PLUGIN_DIR . 'presets/rubberplants.php';
 *     } );
 *
 * See AMSwap_Preset for the rules.
 *
 * @package Admin_Menu_Swap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'groups'          => array(
		array(
			'key'   => 'dashboard',
			'label' => __( 'Dashboard', 'admin-menu-swap' ),
			'items' => array( 'index.php' ),
		),
		array(
			// What the client works with every day. A site's own items land
			// here too (@unknown), so a client feature is never buried.
			'key'   => 'content',
			'label' => __( 'Content', 'admin-menu-swap' ),
			'items' => array(
				'upload.php',
				'gf_edit_forms',
				'forminator',
				'wpcf7',
				'edit.php?post_type=page',
				'edit.php?post_type=blocks',
				AMSwap_Preset::TOKEN_POST_TYPES,
				'edit.php',
				'edit-comments.php',
				AMSwap_Preset::TOKEN_UNKNOWN,
			),
		),
		array(
			// The shop, only with WooCommerce. Users sit here because on a shop
			// they are the customers; without a shop they fall through to the
			// developer group.
			'key'      => 'shop',
			'label'    => __( 'Shop', 'admin-menu-swap' ),
			'requires' => array( 'woocommerce' ),
			'items'    => array(
				'woocommerce',
				'wc-admin*',
				'admin.php?page=wc-settings*',
				'wc-settings*',
				'edit.php?post_type=shop_*',
				'wc-orders*',
				'edit.php?post_type=product',
				'woocommerce-marketing',
				'users.php',
			),
		),
		array(
			'key'   => 'wpmudev',
			'label' => __( 'WPMU DEV', 'admin-menu-swap' ),
			'items' => array(
				'wpmudev',
				'wds_*',
				'smartcrawl*',
				'wp-defender*',
				'smush*',
				'wphb*',
				'branding*',
				'hustle*',
				'beehive*',
				'snapshot*',
			),
		),
		array(
			// Rarely used, and not by the client.
			'key'   => 'developer',
			'label' => __( 'Developer', 'admin-menu-swap' ),
			'items' => array(
				'pmxi-admin-home',
				'wpcodebox*',
				'plugins.php',
				'users.php',
				'themes.php',
				'tools.php',
				'options-general.php',
				'edit.php?post_type=acf-field-group',
				'flatsome-panel',
				'astra',
				'gravitysmtp*',
				'wp-mail-smtp*',
				'postman*',
				'fluent-mail*',
				'chaty*',
				'wpgmp_*',
				'wpcas*',
				// A one-time setup (media folders), so last.
				'cat_folders*',
			),
		),
	),
	'hidden'          => array( 'edit-comments.php' ),
	'separator_color' => '#ffffff',
	'profiles'        => array(
		// Client administrators (administrators who are not agency accounts):
		// no developer tools and no WPMU DEV, apart from what they work with.
		'administrator' => array(
			'label'       => __( 'Client administrator', 'admin-menu-swap' ),
			'hide_groups' => array( 'developer', 'wpmudev' ),
			'show'        => array( 'forminator', 'hustle*', 'themes.php', 'users.php' ),
		),
	),
);
