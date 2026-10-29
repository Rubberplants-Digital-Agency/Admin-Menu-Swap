<?php
/**
 * The reorder engine: applies the saved order to the live admin menu.
 *
 * @package Admin_Menu_Swap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Injects/removes separators, drops hidden items, hands the saved
 * order to core's menu_order sorting and paints the separator color.
 *
 * Hiding is presentational only: unsetting a $menu row takes the item out
 * of the menu, it does not gate the page behind it. Anything a role can
 * still reach by URL stays reachable — capabilities are the only real
 * access control.
 *
 * Core pipeline (wp-admin/includes/menu.php): the admin_menu action
 * fires first (site admin only), then capability filtering, then the
 * custom_menu_order/menu_order filters run sort_menu(), then adjacent
 * separators are collapsed and a trailing separator is stripped. This
 * class only prepares $menu and feeds the filters — core's sort_menu()
 * already gives the wanted semantics: slugs in the saved order sort
 * first in that order, unknown (newly activated) items sink to the
 * bottom keeping their relative default order, and stale slugs are
 * silently ignored.
 */
class AMSwap_Menu_Reorder {

	/**
	 * Slug prefix for user-created separators.
	 */
	const CUSTOM_SEPARATOR_PREFIX = 'separator-amswap-';

	/**
	 * Top-level slugs that may never be hidden.
	 *
	 * This plugin's own settings page lives under Settings; hiding it would
	 * leave no route back to the toggles that undo the hiding.
	 */
	const PROTECTED_SLUGS = array( 'options-general.php' );

	/**
	 * The saved order handed to the menu_order filter.
	 *
	 * @var string[]
	 */
	protected $saved_order = array();

	/**
	 * The settings applied in this request (see AMSwap_Order_Store::effective()),
	 * set on admin_menu.
	 *
	 * @var array|null
	 */
	protected static $effective = null;

	/**
	 * Hook everything up.
	 */
	public function register() {
		// Very late, so effectively all plugins have registered their menus.
		add_action( 'admin_menu', array( $this, 'prepare_menu' ), 99999 );
		add_action( 'admin_head', array( $this, 'print_separator_css' ) );
	}

	/**
	 * Paint the menu separators in the saved color.
	 *
	 * Core draws each separator as a transparent 2px div.separator inside
	 * its row, so a 1px bottom border on that div, pushed down a little,
	 * turns it into a visible line. Like the reordering, this stays out of
	 * the network and user admin, whose menus this plugin never touches.
	 */
	public function print_separator_css() {
		if ( is_network_admin() || is_user_admin() ) {
			return;
		}

		$saved = self::current_settings();

		if ( '' === $saved['separator_color'] ) {
			return;
		}

		// The id lets the settings page swap the rule for a live preview.
		printf(
			'<style id="amswap-separator-css">#adminmenu li.wp-menu-separator div.separator { border-bottom: 1px solid %s; margin-top: 5px; }</style>' . "\n",
			esc_html( $saved['separator_color'] )
		);
	}

	/**
	 * Remove deleted separators and hidden items, inject custom separators
	 * and register the sort filters. Runs on admin_menu, which never fires
	 * in the network admin — that menu is untouched by construction.
	 */
	public function prepare_menu() {
		global $menu;

		if ( ! is_array( $menu ) ) {
			return;
		}

		// The current user's profile: its saved order, or else the preset
		// resolved against this menu.
		$saved           = AMSwap_Order_Store::effective( AMSwap_Menu_Snapshot::items_from_menu( $menu ), AMSwap_Order_Store::profile_for_user() );
		self::$effective = $saved;
		$is_default      = AMSwap_Order_Store::DEFAULT_PROFILE === $saved['profile'];

		if ( empty( $saved['order'] ) && empty( $saved['removed'] ) && empty( $saved['hidden'] ) ) {
			return;
		}

		// Remove separators the user deleted. The removed list is
		// separator-pattern-validated on save and the row must also be
		// a real separator, so an actual menu item can never be dropped here.
		if ( ! empty( $saved['removed'] ) ) {
			foreach ( $menu as $key => $item ) {
				if ( self::is_separator( $item ) && in_array( $item[2], $saved['removed'], true ) ) {
					unset( $menu[ $key ] );
				}
			}
		}

		// Drop the items the user switched off. Skipped on our own settings
		// page, where hidden rows have to stay in the list to be switched
		// back on — so that one screen is deliberately not WYSIWYG.
		if ( ! empty( $saved['hidden'] ) && ! self::is_settings_screen() ) {
			foreach ( $menu as $key => $item ) {
				$slug = isset( $item[2] ) ? (string) $item[2] : '';

				// Separators go through the removed list instead, and the
				// protected slugs are re-checked here in case the option was
				// written by hand or by a future version with a shorter list.
				// Role profiles may hide them: only agency accounts, who get
				// the default profile, use the settings page.
				if ( '' === $slug || self::is_separator( $item ) || ( $is_default && self::is_protected( $slug ) ) ) {
					continue;
				}

				if ( in_array( $slug, $saved['hidden'], true ) ) {
					unset( $menu[ $key ] );
				}
			}
		}

		// Everything below needs an order. Hiding and separator removal are
		// already applied, and handing core an empty order would only put
		// every item through its default-order fallback for nothing.
		if ( empty( $saved['order'] ) ) {
			return;
		}

		// Inject the user's custom separators. Appending never
		// overwrites an existing row; the later usort discards keys.
		$existing = wp_list_pluck( $menu, 2 );

		foreach ( $saved['order'] as $slug ) {
			if ( self::is_custom_separator( $slug ) && ! in_array( $slug, $existing, true ) ) {
				$menu[] = array( '', 'read', $slug, '', 'wp-menu-separator' );
			}
		}

		$this->saved_order = $saved['order'];

		// Priority 9999 so our order wins over plugins like WooCommerce
		// that also filter menu_order at default priority.
		add_filter( 'custom_menu_order', '__return_true', 9999 );
		add_filter( 'menu_order', array( $this, 'filter_menu_order' ), 9999 );
	}

	/**
	 * Hand core the saved order.
	 *
	 * Extra slugs that are not in $menu are harmless: sort_menu() only
	 * does isset() lookups on the flipped array.
	 *
	 * @param string[] $menu_order Incoming order (ignored).
	 * @return string[]
	 */
	public function filter_menu_order( $menu_order ) {
		unset( $menu_order );

		return $this->saved_order;
	}

	/**
	 * The settings applied in this request: the effective settings once
	 * admin_menu ran, the saved ones (marked as such) before that.
	 *
	 * @return array See AMSwap_Order_Store::effective().
	 */
	public static function current_settings() {
		if ( null !== self::$effective ) {
			return self::$effective;
		}

		return array(
			'profile'               => AMSwap_Order_Store::DEFAULT_PROFILE,
			'follows_default_order' => true,
			'source'                => AMSwap_Order_Store::has_saved() ? 'saved' : 'default',
			'preset'                => null,
		) + AMSwap_Order_Store::get();
	}

	/**
	 * Whether a $menu row is a separator. Mirrors core's own check.
	 *
	 * @param array $item A single $menu row.
	 * @return bool
	 */
	public static function is_separator( $item ) {
		return isset( $item[4] ) && false !== stristr( (string) $item[4], 'wp-menu-separator' );
	}

	/**
	 * Whether a slug is one of our user-created separators.
	 *
	 * @param string $slug Menu slug.
	 * @return bool
	 */
	public static function is_custom_separator( $slug ) {
		return is_string( $slug ) && 0 === strpos( $slug, self::CUSTOM_SEPARATOR_PREFIX );
	}

	/**
	 * Whether a slug is one this plugin refuses to hide.
	 *
	 * @param string $slug Menu slug.
	 * @return bool
	 */
	public static function is_protected( $slug ) {
		return is_string( $slug ) && in_array( $slug, self::PROTECTED_SLUGS, true );
	}

	/**
	 * Whether the current request is this plugin's own settings page.
	 *
	 * admin_menu fires long before current_screen exists, so this reads the
	 * request directly. AMSwap_Admin is loaded in the same is_admin() branch
	 * as this class (see AMSwap_Plugin::boot()), so its slug is available by
	 * the time admin_menu runs.
	 *
	 * @return bool
	 */
	protected static function is_settings_screen() {
		if ( ! isset( $GLOBALS['pagenow'] ) || 'options-general.php' !== $GLOBALS['pagenow'] ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check, changes no state.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return AMSwap_Admin::PAGE_SLUG === $page;
	}

	/**
	 * Extract a display model from the live $menu for the settings page.
	 *
	 * Called at render time, when $menu is final: capability-filtered,
	 * sorted by the saved order and separator-cleaned. Hidden items are
	 * still present, flagged rather than dropped, so they can be switched
	 * back on — the one respect in which this list is not WYSIWYG.
	 *
	 * A role profile is built from the menu snapshot instead, in that
	 * profile's order, with the items the role cannot open flagged.
	 *
	 * @param string $profile Profile key, default the default profile.
	 * @return array[] {
	 *     @type string $slug         Menu slug ($item[2]).
	 *     @type string $title        Clean text title (count bubbles stripped).
	 *     @type string $icon         Raw icon value ($item[6]) — dashicon class, URL or data URI.
	 *     @type bool   $is_separator Whether the row is a separator.
	 *     @type bool   $is_custom    Whether it is one of our custom separators.
	 *     @type bool   $is_hidden    Whether the user switched the item off.
	 *     @type bool   $is_protected Whether the item refuses to be hidden.
	 *     @type bool   $no_access    Whether the profile's role cannot open the item.
	 * }
	 */
	public static function get_display_items( $profile = AMSwap_Order_Store::DEFAULT_PROFILE ) {
		global $menu;

		$items = array();

		if ( ! is_array( $menu ) ) {
			return $items;
		}

		if ( AMSwap_Order_Store::DEFAULT_PROFILE !== $profile ) {
			return self::get_profile_display_items( $profile );
		}

		$saved  = self::current_settings();
		$hidden = $saved['hidden'];

		foreach ( AMSwap_Menu_Snapshot::items_from_menu( $menu ) as $item ) {
			$slug         = $item['slug'];
			$is_separator = $item['is_separator'];

			$items[] = $item + array(
				'is_custom'    => self::is_custom_separator( $slug ),
				'is_hidden'    => ! $is_separator && in_array( $slug, $hidden, true ),
				'is_protected' => ! $is_separator && self::is_protected( $slug ),
				'no_access'    => false,
			);
		}

		return $items;
	}

	/**
	 * Display model of a role profile, see get_display_items().
	 *
	 * @param string $profile Role.
	 * @return array[]
	 */
	protected static function get_profile_display_items( $profile ) {
		global $menu;

		$snapshot = AMSwap_Menu_Snapshot::get();
		$source   = null === $snapshot ? AMSwap_Menu_Snapshot::items_from_menu( $menu ) : $snapshot['items'];
		$settings = AMSwap_Order_Store::effective( $source, $profile );
		$role     = get_role( $profile );
		$items    = array();

		foreach ( AMSwap_Menu_Model::apply_to_items( $source, $settings, false ) as $item ) {
			$is_separator = $item['is_separator'];
			$capability   = isset( $item['capability'] ) ? (string) $item['capability'] : '';

			$items[] = $item + array(
				'is_custom'    => self::is_custom_separator( $item['slug'] ),
				'is_hidden'    => ! empty( $item['hidden'] ),
				'is_protected' => false,
				'no_access'    => ! $is_separator && '' !== $capability && ( ! $role || ! $role->has_cap( $capability ) ),
			);
		}

		return $items;
	}
}
