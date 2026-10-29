<?php
/**
 * Menu snapshot: the top-level admin menu as an administrator last saw it.
 *
 * @package Admin_Menu_Swap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores the top-level menu items whenever an administrator loads wp-admin,
 * so the abilities and WP-CLI can describe a menu that only exists there.
 *
 * Many plugins only register their menus when is_admin() is true, so the
 * menu cannot be rebuilt in a REST or MCP request. WP-CLI can rebuild it
 * with WP_ADMIN defined early (see build()); everything else reads the snapshot.
 */
class AMSwap_Menu_Snapshot {

	/**
	 * Option holding the snapshot. Not autoloaded.
	 */
	const OPTION = 'amswap_menu_snapshot';

	/**
	 * An unchanged menu is stored again at most this often, so the capture
	 * time stays meaningful without a database write on every admin page.
	 */
	const REFRESH_INTERVAL = HOUR_IN_SECONDS;

	/**
	 * Whether the next capture writes even an unchanged, fresh snapshot.
	 *
	 * @var bool
	 */
	protected static $force = false;

	/**
	 * The menu as plugins registered it, kept until it is final.
	 *
	 * @var array[]|null
	 */
	protected static $raw = null;

	/**
	 * Hook the capture in.
	 */
	public function register() {
		// Just before AMSwap_Menu_Reorder::prepare_menu() (99999) changes $menu.
		add_action( 'admin_menu', array( __CLASS__, 'capture' ), 99998 );
		// By admin_head core has removed what the user cannot reach.
		add_action( 'admin_head', array( __CLASS__, 'finalize' ), 1 );
	}

	/**
	 * Remember the menu as it is before this plugin changes it.
	 *
	 * Only an administrator's menu is complete enough to plan an order with;
	 * the network and user admin menus are never touched by this plugin.
	 */
	public static function capture() {
		if ( is_network_admin() || is_user_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $menu, $submenu;

		if ( is_array( $menu ) ) {
			self::$raw = self::items_from_menu( $menu, is_array( $submenu ) ? $submenu : array() );
		}
	}

	/**
	 * Store the remembered menu, minus the items core dropped after
	 * admin_menu (no capability, or a parent without reachable pages). Items
	 * this plugin hid and separators it removed are kept: they exist.
	 */
	public static function finalize() {
		global $menu;

		if ( null === self::$raw || ! is_array( $menu ) ) {
			return;
		}

		$shown    = wp_list_pluck( $menu, 2 );
		$settings = AMSwap_Menu_Reorder::current_settings();
		$items    = array();

		foreach ( self::$raw as $item ) {
			$own_doing = $item['is_separator']
				? in_array( $item['slug'], $settings['removed'], true )
				: in_array( $item['slug'], $settings['hidden'], true );

			if ( $own_doing || in_array( $item['slug'], $shown, true ) ) {
				$items[] = $item;
			}
		}

		self::$raw = null;

		self::store( $items, self::$force );
		self::$force = false;
	}

	/**
	 * Store a list of items, unless it is unchanged and still fresh.
	 *
	 * Titles are left out of the comparison: they are translated, so two
	 * administrators with different languages would otherwise rewrite the
	 * option on every admin page they load. A title-only change is stored
	 * with the next refresh.
	 *
	 * @param array[] $items Items from items_from_menu().
	 * @param bool    $force Store even an unchanged, fresh list.
	 * @return bool Whether the option was written.
	 */
	public static function store( $items, $force = false ) {
		$stored = self::get();

		if ( ! $force && null !== $stored && self::signature( $stored['items'] ) === self::signature( $items ) && time() - $stored['captured_at'] < self::REFRESH_INTERVAL ) {
			return false;
		}

		return update_option(
			self::OPTION,
			array(
				'captured_at' => time(),
				'items'       => $items,
			),
			false
		);
	}

	/**
	 * Items without their titles, to tell whether the menu itself changed.
	 *
	 * @param array[] $items Items from items_from_menu().
	 * @return array[]
	 */
	protected static function signature( $items ) {
		foreach ( $items as $key => $item ) {
			if ( is_array( $item ) ) {
				unset( $items[ $key ]['title'] );
			}
		}

		return $items;
	}

	/**
	 * The stored snapshot, or null when there is none yet.
	 *
	 * @return array|null { captured_at: int (Unix time), items: array[] }
	 */
	public static function get() {
		$stored = get_option( self::OPTION );

		if ( ! is_array( $stored ) || ! isset( $stored['captured_at'], $stored['items'] ) || ! is_array( $stored['items'] ) ) {
			return null;
		}

		return array(
			'captured_at' => (int) $stored['captured_at'],
			'items'       => $stored['items'],
		);
	}

	/**
	 * Delete the snapshot.
	 *
	 * @return bool
	 */
	public static function delete() {
		return delete_option( self::OPTION );
	}

	/**
	 * Build the admin menu in this request and store it.
	 *
	 * Plugins only register their menus when is_admin() is true, so WP_ADMIN
	 * has to be defined before WordPress loads (WP-CLI: --exec). WP-CLI's own
	 * --context=admin does not do: it loads core's menu file inside a
	 * function, so the core items never reach the global $menu.
	 *
	 * @return array|WP_Error The fresh snapshot.
	 */
	public static function build() {
		$how = __( 'Run: wp amswap list --refresh --exec=\'define( "WP_ADMIN", true );\' --user=<administrator>', 'admin-menu-swap' );

		if ( ! is_admin() ) {
			/* translators: %s: the command to run. */
			return new WP_Error( 'amswap_no_admin_context', sprintf( __( 'The admin menu only exists in wp-admin. %s', 'admin-menu-swap' ), $how ) );
		}

		if ( did_action( 'admin_menu' ) ) {
			/* translators: %s: the command to run. */
			return new WP_Error( 'amswap_menu_already_built', sprintf( __( 'The admin menu was already built in this request; WP-CLI\'s --context=admin builds it incompletely. %s', 'admin-menu-swap' ), $how ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			/* translators: %s: the command to run. */
			return new WP_Error( 'amswap_no_admin_user', sprintf( __( 'Only an administrator sees the whole menu. %s', 'admin-menu-swap' ), $how ) );
		}

		self::$force = true;
		self::load_admin_menu();
		self::finalize();

		$snapshot = self::get();

		if ( null === $snapshot ) {
			return new WP_Error( 'amswap_no_snapshot', __( 'The admin menu was built, but no snapshot was stored.', 'admin-menu-swap' ) );
		}

		return $snapshot;
	}

	/**
	 * Run core's menu build: wp-admin/menu.php adds the core items, then
	 * wp-admin/includes/menu.php fires admin_menu (where capture() runs) and
	 * filters the menu by capability. Both files write these globals.
	 * admin_head never fires here, so build() calls finalize() itself.
	 */
	protected static function load_admin_menu() {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- core's menu files fill these globals.
		global $menu, $submenu, $compat, $menu_order, $default_menu_order, $_wp_menu_nopriv, $_wp_submenu_nopriv, $_registered_pages, $_parent_pages, $admin_page_hooks, $_wp_real_parent_file, $parent_file, $submenu_file, $plugin_page, $typenow, $pagenow;

		require_once ABSPATH . 'wp-admin/includes/admin.php';
		require ABSPATH . 'wp-admin/menu.php';
	}

	/**
	 * Turn $menu rows into plain items.
	 *
	 * @param array $menu    The global $menu, or a copy of it.
	 * @param array $submenu The global $submenu, to note what each item's
	 *                       pages need: core keeps an item for a user who may
	 *                       open any of them, even without the item's own capability.
	 * @return array[] {
	 *     @type string   $slug         Menu slug ($item[2]).
	 *     @type string   $title        Clean text title, '' for separators.
	 *     @type string   $icon         Raw icon value ($item[6]), '' for separators.
	 *     @type bool     $is_separator Whether the row is a separator.
	 *     @type string   $capability   Capability the item needs ($item[1]).
	 *     @type string[] $sub_caps     Capabilities of its submenu pages, without repeats.
	 * }
	 */
	public static function items_from_menu( $menu, $submenu = array() ) {
		$items = array();

		foreach ( $menu as $row ) {
			$slug = isset( $row[2] ) ? (string) $row[2] : '';

			if ( '' === $slug ) {
				continue;
			}

			$is_separator = AMSwap_Menu_Reorder::is_separator( $row );
			$sub_caps     = array();

			if ( ! $is_separator && isset( $submenu[ $slug ] ) && is_array( $submenu[ $slug ] ) ) {
				foreach ( $submenu[ $slug ] as $sub ) {
					if ( isset( $sub[1] ) && '' !== (string) $sub[1] ) {
						$sub_caps[] = (string) $sub[1];
					}
				}
			}

			$items[] = array(
				'slug'         => $slug,
				'title'        => $is_separator ? '' : self::clean_title( isset( $row[0] ) ? (string) $row[0] : '', $slug ),
				'icon'         => ( $is_separator || ! isset( $row[6] ) ) ? '' : (string) $row[6],
				'is_separator' => $is_separator,
				'capability'   => isset( $row[1] ) ? (string) $row[1] : '',
				'sub_caps'     => array_values( array_unique( $sub_caps ) ),
			);
		}

		return $items;
	}

	/**
	 * A menu title as plain text.
	 *
	 * Strips whole <span> blocks first so update/comment count digits do not
	 * leak into the title. Non-greedy so legitimate text between two spans
	 * is preserved.
	 *
	 * @param string $raw  Raw menu title ($item[0]).
	 * @param string $slug Fallback when nothing is left.
	 * @return string
	 */
	public static function clean_title( $raw, $slug ) {
		$title = wp_strip_all_tags( (string) preg_replace( '#<span[^>]*>.*?</span>#is', '', $raw ) );

		// Menu titles are stored escaped ("Pagina&#039;s"); output re-escapes.
		$title = trim( html_entity_decode( $title, ENT_QUOTES, 'UTF-8' ) );

		return '' === $title ? $slug : $title;
	}
}
