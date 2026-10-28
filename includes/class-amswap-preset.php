<?php
/**
 * Presets: group rules that order a menu the plugin has never seen.
 *
 * @package Admin_Menu_Swap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the preset from the amswap_preset filter and resolves it against
 * the menu items of a site.
 *
 * A preset is a list of groups. Each group lists slug patterns in the order
 * its items should appear; '*' matches any run of characters. A slug lands
 * in the first active group with a pattern that matches it. A group with
 * 'requires' is only active when one of those patterns matches an item, so
 * a shop group disappears on a site without WooCommerce and its items fall
 * through to later groups. Two tokens catch what no pattern names:
 * '@post_types' takes unmatched edit.php?post_type= items, '@unknown' every
 * other unmatched item (without it they go into a trailing group). Groups
 * are divided by custom separators; core and plugin separators are removed.
 *
 * Without a saved order the resolved preset is the menu, so items a newly
 * activated plugin adds are grouped too. Saving on the settings page turns
 * the result into a saved order that the preset no longer touches.
 *
 * Shape of the filtered value:
 *
 *     array(
 *         'groups'          => array(
 *             array(
 *                 'key'      => 'shop',
 *                 'label'    => 'Shop',
 *                 'requires' => array( 'woocommerce' ),
 *                 'items'    => array( 'woocommerce', 'wc-admin*', 'edit.php?post_type=product' ),
 *             ),
 *         ),
 *         'hidden'          => array( 'edit-comments.php' ),
 *         'separator_color' => '#eb6728',
 *     )
 */
class AMSwap_Preset {

	/**
	 * Token for unmatched post type screens (edit.php?post_type=...).
	 */
	const TOKEN_POST_TYPES = '@post_types';

	/**
	 * Token for every other unmatched item.
	 */
	const TOKEN_UNKNOWN = '@unknown';

	/**
	 * Key of the trailing group that collects unmatched items when no group
	 * has the '@unknown' token.
	 */
	const OTHER_GROUP = 'other';

	/**
	 * The preset, normalized, or null when none is set.
	 *
	 * @return array|null {
	 *     @type array[]  $groups          Groups with key, label, requires and items.
	 *     @type string[] $hidden          Slug patterns to hide.
	 *     @type string   $separator_color Hex color, or ''.
	 * }
	 */
	public static function get() {
		/**
		 * The preset that orders the menu as long as no order is saved.
		 * Return null (the default) for no preset.
		 *
		 * @param array|null $preset See the class description for the shape.
		 */
		$preset = apply_filters( 'amswap_preset', null );

		if ( ! is_array( $preset ) || empty( $preset['groups'] ) || ! is_array( $preset['groups'] ) ) {
			return null;
		}

		$groups = array();

		foreach ( $preset['groups'] as $group ) {
			if ( ! is_array( $group ) || empty( $group['key'] ) || empty( $group['items'] ) || ! is_array( $group['items'] ) ) {
				continue;
			}

			$key = sanitize_key( (string) $group['key'] );

			$groups[] = array(
				'key'      => $key,
				'label'    => isset( $group['label'] ) ? (string) $group['label'] : $key,
				'requires' => isset( $group['requires'] ) ? self::string_list( (array) $group['requires'] ) : array(),
				'items'    => self::string_list( $group['items'] ),
			);
		}

		if ( empty( $groups ) ) {
			return null;
		}

		return array(
			'groups'          => $groups,
			'hidden'          => isset( $preset['hidden'] ) ? self::string_list( (array) $preset['hidden'] ) : array(),
			'separator_color' => AMSwap_Order_Store::sanitize_color( isset( $preset['separator_color'] ) ? $preset['separator_color'] : '' ),
		);
	}

	/**
	 * Resolve a preset against menu items.
	 *
	 * @param array   $preset Normalized preset from get().
	 * @param array[] $items  Menu items, see AMSwap_Menu_Snapshot::items_from_menu().
	 * @return array {
	 *     @type string[] $order           Slugs with custom separators between the groups.
	 *     @type string[] $removed         Core and plugin separators in the menu.
	 *     @type string[] $hidden          Item slugs matching the hidden patterns.
	 *     @type string   $separator_color Hex color, or ''.
	 *     @type array[]  $groups          Non-empty groups in order: key, label, items (slugs).
	 *     @type string[] $unmatched       Slugs no pattern names (placed by a token or the trailing group).
	 * }
	 */
	public static function resolve( $preset, $items ) {
		$groups = array();

		foreach ( $preset['groups'] as $group ) {
			if ( ! empty( $group['requires'] ) && ! self::any_item_matches( $items, $group['requires'] ) ) {
				continue;
			}

			$group['members'] = array();
			$groups[]         = $group;
		}

		$other = array(
			'key'     => self::OTHER_GROUP,
			'label'   => __( 'Other', 'admin-menu-swap' ),
			'members' => array(),
		);

		$removed   = array();
		$hidden    = array();
		$unmatched = array();

		foreach ( $items as $index => $item ) {
			$slug = $item['slug'];

			if ( ! empty( $item['is_separator'] ) ) {
				if ( ! AMSwap_Menu_Reorder::is_custom_separator( $slug ) ) {
					$removed[] = $slug;
				}
				continue;
			}

			if ( ! AMSwap_Menu_Reorder::is_protected( $slug ) && self::matches_any( $slug, $preset['hidden'] ) ) {
				$hidden[] = $slug;
			}

			$slot   = self::find_slot( $groups, $slug );
			$member = array(
				'slug'     => $slug,
				'position' => null === $slot ? 0 : $slot['position'],
				'index'    => $index,
			);

			if ( null === $slot || $slot['by_token'] ) {
				$unmatched[] = $slug;
			}

			if ( null === $slot ) {
				$other['members'][] = $member;
			} else {
				$groups[ $slot['group'] ]['members'][] = $member;
			}
		}

		$groups[] = $other;

		$order    = array();
		$resolved = array();
		$n        = 0;

		foreach ( $groups as $group ) {
			if ( empty( $group['members'] ) ) {
				continue;
			}

			// Pattern position first, then the item's place in the menu, so
			// several items under one wildcard keep their relative order.
			usort(
				$group['members'],
				static function ( $a, $b ) {
					return array( $a['position'], $a['index'] ) <=> array( $b['position'], $b['index'] );
				}
			);

			if ( ! empty( $order ) ) {
				++$n;
				$order[] = AMSwap_Menu_Reorder::CUSTOM_SEPARATOR_PREFIX . $n;
			}

			$slugs = wp_list_pluck( $group['members'], 'slug' );
			$order = array_merge( $order, $slugs );

			$resolved[] = array(
				'key'   => $group['key'],
				'label' => $group['label'],
				'items' => $slugs,
			);
		}

		return array(
			'order'           => $order,
			'removed'         => $removed,
			'hidden'          => $hidden,
			'separator_color' => $preset['separator_color'],
			'groups'          => $resolved,
			'unmatched'       => $unmatched,
		);
	}

	/**
	 * Map each slug of a resolved preset to its group key.
	 *
	 * @param array|null $resolved Result of resolve(), or null.
	 * @return array<string, string>
	 */
	public static function group_map( $resolved ) {
		$map = array();

		if ( ! is_array( $resolved ) ) {
			return $map;
		}

		foreach ( $resolved['groups'] as $group ) {
			foreach ( $group['items'] as $slug ) {
				$map[ $slug ] = $group['key'];
			}
		}

		return $map;
	}

	/**
	 * Find the group and position for a slug: a pattern match first, then
	 * the '@post_types' token for post type screens, then '@unknown'.
	 *
	 * @param array[] $groups Active groups.
	 * @param string  $slug   Menu slug.
	 * @return array|null { group: int, position: int, by_token: bool }, or null.
	 */
	protected static function find_slot( $groups, $slug ) {
		foreach ( $groups as $g => $group ) {
			foreach ( $group['items'] as $position => $pattern ) {
				if ( '@' !== substr( $pattern, 0, 1 ) && self::matches( $slug, $pattern ) ) {
					return array(
						'group'    => $g,
						'position' => $position,
						'by_token' => false,
					);
				}
			}
		}

		$tokens = array( self::TOKEN_UNKNOWN );
		if ( 0 === strpos( $slug, 'edit.php?post_type=' ) ) {
			array_unshift( $tokens, self::TOKEN_POST_TYPES );
		}

		foreach ( $tokens as $token ) {
			foreach ( $groups as $g => $group ) {
				$position = array_search( $token, $group['items'], true );

				if ( false !== $position ) {
					return array(
						'group'    => $g,
						'position' => $position,
						'by_token' => true,
					);
				}
			}
		}

		return null;
	}

	/**
	 * Whether a slug matches a pattern. '*' matches any run of characters;
	 * everything else is compared byte-exact, like core's sort_menu() does.
	 *
	 * @param string $slug    Menu slug.
	 * @param string $pattern Slug pattern.
	 * @return bool
	 */
	public static function matches( $slug, $pattern ) {
		if ( false === strpos( $pattern, '*' ) ) {
			return $slug === $pattern;
		}

		$regex = '#^' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . '$#';

		return 1 === preg_match( $regex, $slug );
	}

	/**
	 * Whether a slug matches any of the patterns.
	 *
	 * @param string   $slug     Menu slug.
	 * @param string[] $patterns Slug patterns.
	 * @return bool
	 */
	protected static function matches_any( $slug, $patterns ) {
		foreach ( $patterns as $pattern ) {
			if ( self::matches( $slug, $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether any non-separator item matches any of the patterns.
	 *
	 * @param array[]  $items    Menu items.
	 * @param string[] $patterns Slug patterns.
	 * @return bool
	 */
	protected static function any_item_matches( $items, $patterns ) {
		foreach ( $items as $item ) {
			if ( empty( $item['is_separator'] ) && self::matches_any( $item['slug'], $patterns ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Keep the non-empty strings of a list, re-indexed.
	 *
	 * @param array $list Raw list.
	 * @return string[]
	 */
	protected static function string_list( $list ) {
		return array_values(
			array_filter(
				$list,
				static function ( $value ) {
					return is_string( $value ) && '' !== $value;
				}
			)
		);
	}
}
