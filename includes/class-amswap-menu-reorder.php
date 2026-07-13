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
 * Injects/removes separators and hands the saved order to core's
 * menu_order sorting.
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
	 * The saved order handed to the menu_order filter.
	 *
	 * @var string[]
	 */
	protected $saved_order = array();

	/**
	 * Hook everything up.
	 */
	public function register() {
		// Very late, so effectively all plugins have registered their menus.
		add_action( 'admin_menu', array( $this, 'prepare_menu' ), 99999 );
	}

	/**
	 * Remove deleted separators, inject custom ones and register the
	 * sort filters. Runs on admin_menu, which never fires in the
	 * network admin — that menu is untouched by construction.
	 */
	public function prepare_menu() {
		$saved = AMSwap_Order_Store::get();

		if ( empty( $saved['order'] ) ) {
			return;
		}

		global $menu;

		if ( ! is_array( $menu ) ) {
			return;
		}

		// Remove separators the user deleted. The removed list is
		// separator-pattern-validated on save and the row must also be
		// a real separator, so an actual menu item can never be hidden.
		if ( ! empty( $saved['removed'] ) ) {
			foreach ( $menu as $key => $item ) {
				if ( self::is_separator( $item ) && in_array( $item[2], $saved['removed'], true ) ) {
					unset( $menu[ $key ] );
				}
			}
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
	 * Extract a display model from the live $menu for the settings page.
	 *
	 * Called at render time, when $menu is final: capability-filtered,
	 * sorted by the saved order and separator-cleaned — WYSIWYG.
	 *
	 * @return array[] {
	 *     @type string $slug         Menu slug ($item[2]).
	 *     @type string $title        Clean text title (count bubbles stripped).
	 *     @type string $icon         Raw icon value ($item[6]) — dashicon class, URL or data URI.
	 *     @type bool   $is_separator Whether the row is a separator.
	 *     @type bool   $is_custom    Whether it is one of our custom separators.
	 * }
	 */
	public static function get_display_items() {
		global $menu;

		$items = array();

		if ( ! is_array( $menu ) ) {
			return $items;
		}

		foreach ( $menu as $item ) {
			$slug = isset( $item[2] ) ? (string) $item[2] : '';

			if ( '' === $slug ) {
				continue;
			}

			$is_separator = self::is_separator( $item );
			$title        = '';

			if ( ! $is_separator ) {
				$raw = isset( $item[0] ) ? (string) $item[0] : '';

				// Strip whole <span> blocks first so update/comment
				// count digits do not leak into the title. Non-greedy so
				// legitimate text between two spans is preserved.
				$title = trim( wp_strip_all_tags( (string) preg_replace( '#<span[^>]*>.*?</span>#is', '', $raw ) ) );

				if ( '' === $title ) {
					$title = $slug;
				}
			}

			$items[] = array(
				'slug'         => $slug,
				'title'        => $title,
				'icon'         => ( $is_separator || ! isset( $item[6] ) ) ? '' : (string) $item[6],
				'is_separator' => $is_separator,
				'is_custom'    => self::is_custom_separator( $slug ),
			);
		}

		return $items;
	}
}
