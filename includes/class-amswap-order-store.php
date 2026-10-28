<?php
/**
 * Option storage and sanitization for the saved menu order.
 *
 * @package Admin_Menu_Swap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads, writes and sanitizes the single amswap_menu_order option.
 */
class AMSwap_Order_Store {

	/**
	 * Option name holding the saved order.
	 */
	const OPTION = 'amswap_menu_order';

	/**
	 * Option schema version. Bumped to 2 when the hidden list was added and
	 * to 3 for the separator color; the shape is purely additive, so older
	 * options are upgraded implicitly by the wp_parse_args() in get().
	 */
	const SCHEMA_VERSION = 3;

	/**
	 * Maximum number of entries accepted in the order list.
	 */
	const MAX_ORDER_ENTRIES = 300;

	/**
	 * Maximum number of entries accepted in the removed-separators list.
	 */
	const MAX_REMOVED_ENTRIES = 50;

	/**
	 * Maximum number of entries accepted in the hidden-items list.
	 */
	const MAX_HIDDEN_ENTRIES = 300;

	/**
	 * Maximum length of a single menu slug.
	 */
	const MAX_SLUG_LENGTH = 200;

	/**
	 * Default (empty) option shape.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'version'         => self::SCHEMA_VERSION,
			'order'           => array(),
			'removed'         => array(),
			'hidden'          => array(),
			'separator_color' => '',
		);
	}

	/**
	 * Get the saved order merged over defaults.
	 *
	 * @return array {
	 *     @type int      $version         Option schema version.
	 *     @type string[] $order           Ordered top-level menu slugs, separators inline.
	 *     @type string[] $removed         Core/foreign separator slugs the user deleted.
	 *     @type string[] $hidden          Top-level menu slugs the user switched off.
	 *     @type string   $separator_color Hex color for menu separators, '' for the default.
	 * }
	 */
	public static function get() {
		$saved = get_option( self::OPTION );

		if ( ! is_array( $saved ) ) {
			return self::defaults();
		}

		$saved = wp_parse_args( $saved, self::defaults() );

		foreach ( array( 'order', 'removed', 'hidden' ) as $list ) {
			if ( ! is_array( $saved[ $list ] ) ) {
				$saved[ $list ] = array();
			}
		}

		// Re-validated on read too: the value ends up inside a <style> block.
		$saved['separator_color'] = self::sanitize_color( $saved['separator_color'] );

		return $saved;
	}

	/**
	 * Whether an order was saved on the settings page (or through an ability).
	 *
	 * @return bool
	 */
	public static function has_saved() {
		return is_array( get_option( self::OPTION ) );
	}

	/**
	 * The settings that apply to the menu: the saved ones, else the preset
	 * resolved against the menu items, else the empty defaults.
	 *
	 * @param array[] $items Menu items, see AMSwap_Menu_Snapshot::items_from_menu().
	 * @return array Same shape as get(), plus:
	 *     @type string     $source 'saved', 'preset' or 'default'.
	 *     @type array|null $preset The resolved preset (see AMSwap_Preset::resolve()), or null without one.
	 */
	public static function effective( $items ) {
		$preset   = AMSwap_Preset::get();
		$resolved = ( null !== $preset && ! empty( $items ) ) ? AMSwap_Preset::resolve( $preset, $items ) : null;

		if ( self::has_saved() ) {
			return array(
				'source' => 'saved',
				'preset' => $resolved,
			) + self::get();
		}

		if ( null !== $resolved ) {
			return array(
				'source'          => 'preset',
				'preset'          => $resolved,
				'version'         => self::SCHEMA_VERSION,
				'order'           => $resolved['order'],
				'removed'         => $resolved['removed'],
				'hidden'          => $resolved['hidden'],
				'separator_color' => $resolved['separator_color'],
			);
		}

		return array(
			'source' => 'default',
			'preset' => null,
		) + self::defaults();
	}

	/**
	 * Persist a sanitized order. Autoload is off: the option is only
	 * needed on admin requests.
	 *
	 * @param string[] $order           Ordered menu slugs.
	 * @param string[] $removed         Removed separator slugs.
	 * @param string[] $hidden          Hidden menu slugs.
	 * @param string   $separator_color Hex separator color, '' for the default.
	 * @return bool Whether the option was updated.
	 */
	public static function save( $order, $removed, $hidden = array(), $separator_color = '' ) {
		$value = array(
			'version'         => self::SCHEMA_VERSION,
			'order'           => array_values( $order ),
			'removed'         => array_values( $removed ),
			'hidden'          => array_values( $hidden ),
			'separator_color' => self::sanitize_color( $separator_color ),
		);

		return update_option( self::OPTION, $value, false );
	}

	/**
	 * Delete the saved order, restoring the default WordPress menu.
	 *
	 * @return bool Whether the option was deleted.
	 */
	public static function reset() {
		return delete_option( self::OPTION );
	}

	/**
	 * Sanitize the submitted order list (JSON array of slugs).
	 *
	 * The live $menu is not available in admin-post.php context, so
	 * validation is shape-based: unknown slugs are inert in core's
	 * sort_menu(). Custom separators are renumbered positionally so
	 * their IDs stay compact and unique without a persistent counter.
	 *
	 * @param string|array $json Raw JSON string from the form, or a list.
	 * @return string[] Clean, ordered list of slugs.
	 */
	public static function sanitize_order( $json ) {
		$entries = self::decode_list( $json, self::MAX_ORDER_ENTRIES );
		$clean   = array();

		foreach ( $entries as $entry ) {
			$entry = self::clean_slug( $entry );

			if ( '' === $entry ) {
				continue;
			}

			// Duplicate slugs would confuse the array_flip in core's sort.
			if ( in_array( $entry, $clean, true ) ) {
				continue;
			}

			$clean[] = $entry;
		}

		return self::renumber_custom_separators( $clean );
	}

	/**
	 * Sanitize the submitted removed-separators list (JSON array of slugs).
	 *
	 * Our own custom separators are never accepted here (those are removed
	 * by omission from the order instead). Any other slug is safe to accept:
	 * prepare_menu() only ever unsets rows that are genuinely separators, so
	 * a crafted slug naming a real menu item is simply ignored. Accepting
	 * arbitrary separator slugs lets third-party separators whose slug does
	 * not start with "separator" be removed too.
	 *
	 * @param string|array $json Raw JSON string from the form, or a list.
	 * @return string[] Clean list of removed separator slugs.
	 */
	public static function sanitize_removed( $json ) {
		$entries = self::decode_list( $json, self::MAX_REMOVED_ENTRIES );
		$clean   = array();

		foreach ( $entries as $entry ) {
			$entry = self::clean_slug( $entry );

			if ( '' === $entry ) {
				continue;
			}

			if ( AMSwap_Menu_Reorder::is_custom_separator( $entry ) ) {
				continue;
			}

			if ( in_array( $entry, $clean, true ) ) {
				continue;
			}

			$clean[] = $entry;
		}

		return $clean;
	}

	/**
	 * Sanitize the submitted hidden-items list (JSON array of slugs).
	 *
	 * Separators are never accepted: custom ones are dropped by omission
	 * from the order, core/foreign ones through the removed list. Protected
	 * slugs are refused here as well as at apply time, so a hand-crafted
	 * POST cannot hide the menu this plugin's own page lives under and
	 * strand the user with no way to switch anything back on.
	 *
	 * Unknown slugs are inert: prepare_menu() only unsets rows that are
	 * actually present in $menu.
	 *
	 * @param string|array $json Raw JSON string from the form, or a list.
	 * @return string[] Clean list of hidden menu slugs.
	 */
	public static function sanitize_hidden( $json ) {
		$entries = self::decode_list( $json, self::MAX_HIDDEN_ENTRIES );
		$clean   = array();

		foreach ( $entries as $entry ) {
			$entry = self::clean_slug( $entry );

			if ( '' === $entry ) {
				continue;
			}

			if ( AMSwap_Menu_Reorder::is_custom_separator( $entry ) ) {
				continue;
			}

			if ( AMSwap_Menu_Reorder::is_protected( $entry ) ) {
				continue;
			}

			if ( in_array( $entry, $clean, true ) ) {
				continue;
			}

			$clean[] = $entry;
		}

		return $clean;
	}

	/**
	 * Sanitize the separator color: a #rgb or #rrggbb hex value, or ''
	 * to leave separators as the admin color scheme draws them.
	 *
	 * @param mixed $color Raw color.
	 * @return string Clean hex color, or ''.
	 */
	public static function sanitize_color( $color ) {
		if ( ! is_string( $color ) ) {
			return '';
		}

		return (string) sanitize_hex_color( trim( $color ) );
	}

	/**
	 * Validate a slug without transforming it.
	 *
	 * Menu slugs are matched byte-exact against $menu by core's sort_menu(),
	 * so they must round-trip unchanged — sanitize_text_field() would strip
	 * percent-encoded octets and tag-like text and break the match. Slugs are
	 * only ever compared, flipped into isset() keys, or re-escaped with
	 * esc_attr()/esc_html() at output, never echoed raw or used in SQL, so
	 * preserving the bytes is safe.
	 *
	 * @param string $slug Raw slug.
	 * @return string Clean slug, or '' if invalid.
	 */
	protected static function clean_slug( $slug ) {
		if ( ! is_string( $slug ) ) {
			return '';
		}

		// Reject invalid UTF-8 and any control characters.
		if ( $slug !== wp_check_invalid_utf8( $slug ) ) {
			return '';
		}
		if ( preg_match( '/[\x00-\x1f\x7f]/', $slug ) ) {
			return '';
		}

		$slug = trim( $slug );

		if ( '' === $slug || strlen( $slug ) > self::MAX_SLUG_LENGTH ) {
			return '';
		}

		return $slug;
	}

	/**
	 * Decode a JSON list of strings, capped at $max entries. The abilities
	 * pass an already decoded list, which is accepted as is.
	 *
	 * @param string|array $json Raw JSON string, or a list.
	 * @param int          $max  Maximum entries to keep.
	 * @return string[]
	 */
	protected static function decode_list( $json, $max ) {
		if ( is_array( $json ) ) {
			$list = $json;
		} elseif ( ! is_string( $json ) || '' === $json ) {
			return array();
		} else {
			$list = json_decode( $json, true );
		}

		if ( ! is_array( $list ) ) {
			return array();
		}

		$list = array_slice( array_values( $list ), 0, $max );

		return array_filter( $list, 'is_string' );
	}

	/**
	 * Renumber custom separators positionally: every separator-amswap-*
	 * entry becomes separator-amswap-1, -2, ... in list order.
	 *
	 * @param string[] $order Ordered slugs.
	 * @return string[]
	 */
	protected static function renumber_custom_separators( $order ) {
		$n = 0;

		foreach ( $order as $i => $slug ) {
			if ( AMSwap_Menu_Reorder::is_custom_separator( $slug ) ) {
				++$n;
				$order[ $i ] = 'separator-amswap-' . $n;
			}
		}

		return $order;
	}
}
