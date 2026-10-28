<?php
/**
 * Menu model: the menu as it will be shown, for the abilities and WP-CLI.
 *
 * @package Admin_Menu_Swap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Combines the snapshot, the saved order and the preset into one
 * description of the admin menu, outside wp-admin.
 */
class AMSwap_Menu_Model {

	/**
	 * Describe the admin menu of one profile.
	 *
	 * @param string $profile Profile key: 'default' or a role.
	 * @return array {
	 *     @type string     $profile  The profile described.
	 *     @type string     $source   What orders it: 'saved', 'preset', 'default' or 'inherit' (a role
	 *                                profile without settings of its own, which shows the default menu).
	 *     @type array      $snapshot { captured_at: int|null, age_seconds: int|null }.
	 *     @type array      $settings Effective order, hidden, removed, separator_color, follows_default_order.
	 *     @type array[]    $items    The menu in display order: slug, title, type, hidden, group.
	 *     @type array|null $preset   Preset groups and unmatched slugs, or null without a preset.
	 *     @type array[]    $profiles Every profile: key, label, source, users (logins it applies to).
	 *     @type array      $agency   Agency domains and the logins of the agency accounts.
	 *     @type string     $notice   Advice when the snapshot is missing, else ''.
	 * }
	 */
	public static function describe( $profile = AMSwap_Order_Store::DEFAULT_PROFILE ) {
		$snapshot  = AMSwap_Menu_Snapshot::get();
		$items     = null === $snapshot ? array() : $snapshot['items'];
		$effective = AMSwap_Order_Store::effective( $items, $profile );
		$groups    = AMSwap_Preset::group_map( $effective['preset'] );
		$rows      = array();

		foreach ( self::apply_to_items( $items, $effective ) as $item ) {
			$rows[] = array(
				'slug'   => $item['slug'],
				'title'  => $item['title'],
				'type'   => $item['is_separator'] ? 'separator' : 'item',
				'hidden' => ! empty( $item['hidden'] ),
				'group'  => isset( $groups[ $item['slug'] ] ) ? $groups[ $item['slug'] ] : '',
			);
		}

		$preset = null;
		if ( null !== $effective['preset'] ) {
			$preset = array(
				'groups'    => $effective['preset']['groups'],
				'unmatched' => $effective['preset']['unmatched'],
			);
		} elseif ( null !== AMSwap_Preset::get() ) {
			// A preset exists but there is no snapshot to resolve it against.
			$preset = array(
				'groups'    => array(),
				'unmatched' => array(),
			);
		}

		$agency = AMSwap_Agency::get();

		return array(
			'profile'  => $effective['profile'],
			'source'   => $effective['source'],
			'snapshot' => array(
				'captured_at' => null === $snapshot ? null : $snapshot['captured_at'],
				'age_seconds' => null === $snapshot ? null : max( 0, time() - $snapshot['captured_at'] ),
			),
			'settings' => array(
				'order'                 => array_values( $effective['order'] ),
				'hidden'                => array_values( $effective['hidden'] ),
				'removed'               => array_values( $effective['removed'] ),
				'separator_color'       => $effective['separator_color'],
				'follows_default_order' => $effective['follows_default_order'],
			),
			'items'    => $rows,
			'preset'   => $preset,
			'profiles' => self::profiles(),
			'agency'   => array(
				'domains'  => $agency['domains'],
				'accounts' => wp_list_pluck( self::agency_accounts(), 'user_login' ),
			),
			'notice'   => null === $snapshot
				? __( 'No menu snapshot yet: open any wp-admin page as an administrator once, or run: wp amswap list --refresh --exec=\'define( "WP_ADMIN", true );\' --user=<administrator>', 'admin-menu-swap' )
				: '',
		);
	}

	/**
	 * Every profile with where its settings come from and who it applies to.
	 *
	 * Only users who can open the dashboard count (see dashboard_caps()), so
	 * a shop's customers do not swell the lists.
	 *
	 * @return array[] { key, label, source ('saved', 'preset' or 'inherit'; the default profile:
	 *                 'saved', 'preset' or 'default'), users (logins, at most 50), count (all of them) }.
	 */
	public static function profiles() {
		$sources = AMSwap_Order_Store::role_sources();
		$preset  = AMSwap_Preset::get();
		$users   = array( AMSwap_Order_Store::DEFAULT_PROFILE => array() );

		$query = get_users(
			array(
				'capability__in' => self::dashboard_caps(),
				'number'         => 500,
			)
		);

		foreach ( $query as $user ) {
			$users[ AMSwap_Order_Store::profile_for_user( $user ) ][] = $user->user_login;
		}

		if ( AMSwap_Order_Store::has_saved() ) {
			$default_source = 'saved';
		} else {
			$default_source = null === $preset ? 'default' : 'preset';
		}

		$profiles = array(
			array(
				'key'    => AMSwap_Order_Store::DEFAULT_PROFILE,
				'label'  => __( 'Default (agency accounts and roles without a profile)', 'admin-menu-swap' ),
				'source' => $default_source,
				'users'  => array_slice( $users[ AMSwap_Order_Store::DEFAULT_PROFILE ], 0, 50 ),
				'count'  => count( $users[ AMSwap_Order_Store::DEFAULT_PROFILE ] ),
			),
		);

		foreach ( AMSwap_Order_Store::sort_roles( array_keys( wp_roles()->get_names() ) ) as $role ) {
			$label = translate_user_role( wp_roles()->get_names()[ $role ] );

			if ( isset( $preset['profiles'][ $role ]['label'] ) && 'preset' === ( $sources[ $role ] ?? '' ) ) {
				$label = $preset['profiles'][ $role ]['label'];
			}

			$profiles[] = array(
				'key'    => $role,
				'label'  => $label,
				'source' => isset( $sources[ $role ] ) ? $sources[ $role ] : 'inherit',
				'users'  => array_slice( isset( $users[ $role ] ) ? $users[ $role ] : array(), 0, 50 ),
				'count'  => isset( $users[ $role ] ) ? count( $users[ $role ] ) : 0,
			);
		}

		return $profiles;
	}

	/**
	 * The capabilities of which a user needs one to open the dashboard, and
	 * so to see the admin menu. With WooCommerce that is edit_posts,
	 * manage_woocommerce or view_admin_dashboard: WooCommerce sends everyone
	 * else (its customers) to My Account while it hides their admin bar.
	 * Without WooCommerce every user with read reaches the dashboard.
	 *
	 * @return string[]
	 */
	public static function dashboard_caps() {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own filter.
		if ( class_exists( 'WooCommerce' ) && apply_filters( 'woocommerce_disable_admin_bar', true ) ) {
			$caps = array( 'edit_posts', 'manage_woocommerce', 'view_admin_dashboard' );
		} else {
			$caps = array( 'read' );
		}

		/**
		 * Filters the capabilities of which a user needs one to count as
		 * someone who sees the admin menu.
		 *
		 * @param string[] $caps Capabilities.
		 */
		return (array) apply_filters( 'amswap_dashboard_caps', $caps );
	}

	/**
	 * The administrators who are agency accounts.
	 *
	 * @return WP_User[]
	 */
	public static function agency_accounts() {
		return array_values(
			array_filter(
				AMSwap_Agency::administrators(),
				array( 'AMSwap_Agency', 'is_agency' )
			)
		);
	}

	/**
	 * Apply settings to menu items the way prepare_menu() and core's
	 * sort_menu() do: drop removed separators, add custom separators, sort
	 * the slugs in the order first and keep the rest behind them in menu
	 * order, then collapse doubled, leading and trailing separators. Hidden
	 * items stay in the list, flagged, so they can be reported.
	 *
	 * @param array[] $items    Items from AMSwap_Menu_Snapshot::items_from_menu().
	 * @param array   $settings Settings with order, removed, hidden and profile.
	 * @param bool    $collapse Whether to collapse separators as core does (false for an editable list).
	 * @return array[] Items in display order, each with a 'hidden' flag.
	 */
	public static function apply_to_items( $items, $settings, $collapse = true ) {
		$rows    = array();
		$protect = ! isset( $settings['profile'] ) || AMSwap_Order_Store::DEFAULT_PROFILE === $settings['profile'];

		foreach ( $items as $item ) {
			if ( $item['is_separator'] && in_array( $item['slug'], $settings['removed'], true ) ) {
				continue;
			}

			// Custom separators belong to the order they came with.
			if ( $item['is_separator'] && AMSwap_Menu_Reorder::is_custom_separator( $item['slug'] ) && ! in_array( $item['slug'], $settings['order'], true ) ) {
				continue;
			}

			$item['hidden'] = ! $item['is_separator']
				&& ! ( $protect && AMSwap_Menu_Reorder::is_protected( $item['slug'] ) )
				&& in_array( $item['slug'], $settings['hidden'], true );

			$rows[] = $item;
		}

		if ( ! empty( $settings['order'] ) ) {
			$existing = wp_list_pluck( $rows, 'slug' );

			foreach ( $settings['order'] as $slug ) {
				if ( AMSwap_Menu_Reorder::is_custom_separator( $slug ) && ! in_array( $slug, $existing, true ) ) {
					$rows[] = array(
						'slug'         => $slug,
						'title'        => '',
						'icon'         => '',
						'is_separator' => true,
						'capability'   => '',
						'hidden'       => false,
					);
				}
			}

			$position = array_flip( array_values( $settings['order'] ) );
			$unsorted = count( $position );

			foreach ( $rows as $index => $row ) {
				$rows[ $index ]['sort'] = array(
					isset( $position[ $row['slug'] ] ) ? $position[ $row['slug'] ] : $unsorted,
					$index,
				);
			}

			usort(
				$rows,
				static function ( $a, $b ) {
					return $a['sort'] <=> $b['sort'];
				}
			);

			foreach ( $rows as $index => $row ) {
				unset( $rows[ $index ]['sort'] );
			}
		}

		if ( ! $collapse ) {
			return $rows;
		}

		// Core collapses separators around the items it actually shows.
		$shown    = array();
		$previous = true; // Treat the start as a separator: no leading one.

		foreach ( $rows as $row ) {
			if ( $row['is_separator'] ) {
				if ( $previous ) {
					continue;
				}
				$previous = true;
			} elseif ( ! $row['hidden'] ) {
				$previous = false;
			}

			$shown[] = $row;
		}

		while ( ! empty( $shown ) && end( $shown )['is_separator'] ) {
			array_pop( $shown );
		}

		return $shown;
	}
}
