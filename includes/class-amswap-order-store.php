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
	 * Option holding the role profiles: role => { version, order, removed,
	 * hidden }. An empty order follows the default order. Not autoloaded.
	 */
	const PROFILES_OPTION = 'amswap_menu_profiles';

	/**
	 * Key of the default profile: agency accounts and roles without a profile.
	 */
	const DEFAULT_PROFILE = 'default';

	/**
	 * Roles in the order their profile wins for a user with several roles.
	 * Other roles follow, alphabetically.
	 */
	const ROLE_PRIORITY = array( 'administrator', 'shop_manager', 'editor', 'author', 'contributor', 'subscriber' );

	/**
	 * role_sources() cached per request; emptied when a profile is saved or reset.
	 *
	 * @var array|null
	 */
	protected static $role_sources = null;

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
	 * The settings that apply to a profile's menu.
	 *
	 * Default profile: the saved order, else the preset resolved against the
	 * menu items, else the empty defaults. Role profile: its saved settings
	 * (an empty order follows the default order), else the preset's profile
	 * for the role, else the default profile's settings.
	 *
	 * @param array[] $items   Menu items, see AMSwap_Menu_Snapshot::items_from_menu().
	 * @param string  $profile Profile key: 'default' or a role.
	 * @return array Same shape as get(), plus:
	 *     @type string     $profile               The profile key.
	 *     @type string     $source                'saved', 'preset', 'default' (the WordPress menu) or,
	 *                                             for a role profile without settings, 'inherit'.
	 *     @type bool       $follows_default_order Whether the order is the default profile's.
	 *     @type array|null $preset                The resolved preset (see AMSwap_Preset::resolve()), or null.
	 */
	public static function effective( $items, $profile = self::DEFAULT_PROFILE ) {
		$preset   = AMSwap_Preset::get();
		$resolved = ( null !== $preset && ! empty( $items ) ) ? AMSwap_Preset::resolve( $preset, $items ) : null;

		if ( self::has_saved() ) {
			$base = array(
				'source' => 'saved',
				'preset' => $resolved,
			) + self::get();
		} elseif ( null !== $resolved ) {
			$base = array(
				'source'          => 'preset',
				'preset'          => $resolved,
				'version'         => self::SCHEMA_VERSION,
				'order'           => $resolved['order'],
				'removed'         => $resolved['removed'],
				'hidden'          => $resolved['hidden'],
				'separator_color' => $resolved['separator_color'],
			);
		} else {
			$base = array(
				'source' => 'default',
				'preset' => null,
			) + self::defaults();
		}

		$base = array(
			'profile'               => self::DEFAULT_PROFILE,
			'follows_default_order' => true,
		) + $base;

		if ( self::DEFAULT_PROFILE === $profile ) {
			return $base;
		}

		$saved = self::get_profile( $profile );

		if ( null !== $saved ) {
			$follows = empty( $saved['order'] );

			return array(
				'profile'               => $profile,
				'source'                => 'saved',
				'follows_default_order' => $follows,
				'order'                 => $follows ? $base['order'] : $saved['order'],
				'removed'               => $follows ? $base['removed'] : $saved['removed'],
				'hidden'                => $saved['hidden'],
			) + $base;
		}

		if ( null !== $resolved && isset( $preset['profiles'][ $profile ] ) ) {
			return array(
				'profile' => $profile,
				'source'  => 'preset',
				'hidden'  => AMSwap_Preset::resolve_profile( $preset['profiles'][ $profile ], $items, $base['hidden'], AMSwap_Preset::group_map( $resolved ) ),
			) + $base;
		}

		return array(
			'profile' => $profile,
			'source'  => 'inherit',
		) + $base;
	}

	/**
	 * Whether a key names a profile: 'default' or an existing role.
	 *
	 * @param string $profile Profile key.
	 * @return bool
	 */
	public static function is_profile( $profile ) {
		return self::DEFAULT_PROFILE === $profile || ( is_string( $profile ) && wp_roles()->is_role( $profile ) );
	}

	/**
	 * The saved settings of a profile, or null when it has none.
	 *
	 * @param string $profile Profile key.
	 * @return array|null Same shape as get() (without separator_color for roles).
	 */
	public static function get_profile( $profile ) {
		if ( self::DEFAULT_PROFILE === $profile ) {
			return self::has_saved() ? self::get() : null;
		}

		$all = get_option( self::PROFILES_OPTION );

		if ( ! is_array( $all ) || ! isset( $all[ $profile ] ) || ! is_array( $all[ $profile ] ) ) {
			return null;
		}

		$saved = wp_parse_args( $all[ $profile ], self::defaults() );

		foreach ( array( 'order', 'removed', 'hidden' ) as $list ) {
			if ( ! is_array( $saved[ $list ] ) ) {
				$saved[ $list ] = array();
			}
		}

		unset( $saved['separator_color'] );

		return $saved;
	}

	/**
	 * Save a role profile. An empty order follows the default order.
	 *
	 * @param string   $profile Role.
	 * @param string[] $order   Ordered menu slugs, or an empty list.
	 * @param string[] $removed Removed separator slugs.
	 * @param string[] $hidden  Hidden menu slugs.
	 * @return bool Whether the option was updated.
	 */
	public static function save_profile( $profile, $order, $removed, $hidden ) {
		$all = get_option( self::PROFILES_OPTION );
		$all = is_array( $all ) ? $all : array();

		self::$role_sources = null;

		$all[ $profile ] = array(
			'version' => self::SCHEMA_VERSION,
			'order'   => array_values( $order ),
			'removed' => empty( $order ) ? array() : array_values( $removed ),
			'hidden'  => array_values( $hidden ),
		);

		return update_option( self::PROFILES_OPTION, $all, false );
	}

	/**
	 * Delete a profile's saved settings: the default profile's order, or a
	 * role profile.
	 *
	 * @param string $profile Profile key.
	 * @return bool Whether something was deleted.
	 */
	public static function reset_profile( $profile ) {
		if ( self::DEFAULT_PROFILE === $profile ) {
			return self::reset();
		}

		$all = get_option( self::PROFILES_OPTION );

		if ( ! is_array( $all ) || ! isset( $all[ $profile ] ) ) {
			return false;
		}

		self::$role_sources = null;
		unset( $all[ $profile ] );

		return empty( $all ) ? delete_option( self::PROFILES_OPTION ) : update_option( self::PROFILES_OPTION, $all, false );
	}

	/**
	 * The profile that applies to a user: the default for agency accounts,
	 * else the profile of their highest-priority role that has one (saved
	 * or in the preset), else the default.
	 *
	 * @param WP_User|null $user User, default the current one.
	 * @return string Profile key.
	 */
	public static function profile_for_user( $user = null ) {
		$user = null === $user ? wp_get_current_user() : $user;

		if ( ! $user instanceof WP_User || ! $user->exists() || AMSwap_Agency::is_agency( $user ) ) {
			return self::DEFAULT_PROFILE;
		}

		$with_profile = array_keys( self::role_sources() );

		foreach ( self::sort_roles( (array) $user->roles ) as $role ) {
			if ( in_array( $role, $with_profile, true ) ) {
				return $role;
			}
		}

		return self::DEFAULT_PROFILE;
	}

	/**
	 * The roles that have a profile, and where it comes from.
	 *
	 * @return array<string, string> role => 'saved' or 'preset'.
	 */
	public static function role_sources() {
		if ( null !== self::$role_sources ) {
			return self::$role_sources;
		}

		$sources = array();
		$preset  = AMSwap_Preset::get();

		if ( null !== $preset ) {
			foreach ( array_keys( $preset['profiles'] ) as $role ) {
				$sources[ $role ] = 'preset';
			}
		}

		$saved = get_option( self::PROFILES_OPTION );
		if ( is_array( $saved ) ) {
			foreach ( array_keys( $saved ) as $role ) {
				$sources[ $role ] = 'saved';
			}
		}

		self::$role_sources = $sources;

		return $sources;
	}

	/**
	 * Sort role keys by ROLE_PRIORITY, other roles after them alphabetically.
	 *
	 * @param string[] $roles Role keys.
	 * @return string[]
	 */
	public static function sort_roles( $roles ) {
		$priority = array_flip( self::ROLE_PRIORITY );

		usort(
			$roles,
			static function ( $a, $b ) use ( $priority ) {
				$pa = isset( $priority[ $a ] ) ? $priority[ $a ] : count( $priority );
				$pb = isset( $priority[ $b ] ) ? $priority[ $b ] : count( $priority );

				return array( $pa, $a ) <=> array( $pb, $b );
			}
		);

		return $roles;
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
	 * Role profiles may hide protected slugs: their users never reach the
	 * settings page, which is for agency accounts only.
	 *
	 * @param string|array $json    Raw JSON string from the form, or a list.
	 * @param string       $profile Profile the list is for.
	 * @return string[] Clean list of hidden menu slugs.
	 */
	public static function sanitize_hidden( $json, $profile = self::DEFAULT_PROFILE ) {
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

			if ( self::DEFAULT_PROFILE === $profile && AMSwap_Menu_Reorder::is_protected( $entry ) ) {
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
