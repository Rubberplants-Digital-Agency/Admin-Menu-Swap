<?php
/**
 * Bootstrap: wires the reorder engine and the admin UI.
 *
 * @package Admin_Menu_Swap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin controller (singleton).
 */
class AMSwap_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var AMSwap_Plugin|null
	 */
	protected static $instance = null;

	/**
	 * The reorder engine.
	 *
	 * @var AMSwap_Menu_Reorder
	 */
	protected $reorder;

	/**
	 * Get the singleton.
	 *
	 * @return AMSwap_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Wire everything up.
	 */
	protected function boot() {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		/**
		 * Whether to register the amswap/* abilities (WordPress 6.9+), which
		 * MCP clients and the Abilities REST API reach.
		 *
		 * @param bool $register Default true.
		 */
		if ( function_exists( 'wp_register_ability' ) && apply_filters( 'amswap_register_abilities', true ) ) {
			require_once AMSWAP_PLUGIN_DIR . 'includes/class-amswap-abilities.php';

			$abilities = new AMSwap_Abilities();
			$abilities->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once AMSWAP_PLUGIN_DIR . 'includes/class-amswap-cli.php';

			WP_CLI::add_command( 'amswap', 'AMSwap_CLI' );
		}

		// The menu itself only exists in wp-admin. This also covers
		// admin-post.php, which runs with WP_ADMIN set, so the save/reset
		// handlers are wired there too, and WP-CLI with WP_ADMIN defined.
		if ( is_admin() ) {
			$snapshot = new AMSwap_Menu_Snapshot();
			$snapshot->register();

			$this->reorder = new AMSwap_Menu_Reorder();
			$this->reorder->register();

			$this->load_admin();
		}
	}

	/**
	 * Load translations. Hooked on init: since WP 6.7 translating
	 * earlier triggers a doing-it-wrong notice.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'admin-menu-swap', false, dirname( AMSWAP_PLUGIN_BASENAME ) . '/languages' );
	}

	/**
	 * Instantiate the admin UI.
	 */
	protected function load_admin() {
		require_once AMSWAP_PLUGIN_DIR . 'admin/class-amswap-admin.php';

		$admin = new AMSwap_Admin();
		$admin->register();
	}
}
