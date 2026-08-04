<?php
/**
 * Admin UI: the Settings → Menu Order page, save/reset handlers and notices.
 *
 * @package Admin_Menu_Swap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the settings page and handles the admin-post save/reset flow.
 */
class AMSwap_Admin {

	/**
	 * Settings page slug.
	 */
	const PAGE_SLUG = 'admin-menu-swap';

	/**
	 * Hook suffix of the settings page, used to guard the enqueue.
	 *
	 * @var string|false
	 */
	protected $hook_suffix = false;

	/**
	 * Hook everything up.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_amswap_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_amswap_reset', array( $this, 'handle_reset' ) );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
		add_filter( 'plugin_action_links_' . AMSWAP_PLUGIN_BASENAME, array( $this, 'action_links' ) );
	}

	/**
	 * Register the settings page. A submenu under Settings is never
	 * itself part of the sortable top-level list.
	 */
	public function register_page() {
		$this->hook_suffix = add_options_page(
			__( 'Admin Menu Swap', 'admin-menu-swap' ),
			__( 'Menu Order', 'admin-menu-swap' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Add an "Order menu" link on the Plugins screen row.
	 *
	 * @param string[] $links Existing action links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		$url = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );

		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Order menu', 'admin-menu-swap' ) . '</a>'
		);

		return $links;
	}

	/**
	 * Enqueue the page assets, only on our own screen.
	 *
	 * @param string $hook The current admin page hook suffix.
	 */
	public function enqueue_assets( $hook ) {
		if ( false === $this->hook_suffix || $hook !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'amswap-admin',
			AMSWAP_PLUGIN_URL . 'assets/admin.css',
			array( 'dashicons' ),
			AMSWAP_VERSION
		);

		wp_enqueue_script(
			'amswap-admin',
			AMSWAP_PLUGIN_URL . 'assets/admin.js',
			array( 'jquery-ui-sortable' ),
			AMSWAP_VERSION,
			true
		);

		wp_localize_script(
			'amswap-admin',
			'amswapL10n',
			array(
				'confirmReset' => __( 'Reset the admin menu to the WordPress default? Your saved order, hidden items and custom separators will be deleted.', 'admin-menu-swap' ),
				'hideLabel'    => __( 'Hide from menu', 'admin-menu-swap' ),
				'showLabel'    => __( 'Show in menu', 'admin-menu-swap' ),
			)
		);
	}

	/**
	 * Render the settings page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'admin-menu-swap' ), 403 );
		}

		$items = AMSwap_Menu_Reorder::get_display_items();

		// Carry the saved removed-separator list forward so a later save
		// that removes nothing new does not resurrect earlier removals.
		$saved        = AMSwap_Order_Store::get();
		$removed_json = wp_json_encode( array_values( $saved['removed'] ) );
		if ( ! is_string( $removed_json ) ) {
			$removed_json = '[]';
		}
		?>
		<div class="wrap amswap-wrap">
			<h1><?php esc_html_e( 'Admin Menu Swap', 'admin-menu-swap' ); ?></h1>

			<p><?php esc_html_e( 'Drag the items below into the order you want the admin menu to appear in. Use the eye button to hide an item you never use. Separators can be dragged, removed and added to group items. Both the order and the hidden items apply to all users.', 'admin-menu-swap' ); ?></p>

			<p class="description amswap-note">
				<?php esc_html_e( 'Hiding an item only takes it out of the menu — the page behind it stays reachable by its direct URL. Use user roles and capabilities to actually restrict access.', 'admin-menu-swap' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="amswap-form">
				<ul id="amswap-menu-list">
					<?php
					foreach ( $items as $item ) {
						$this->render_item( $item );
					}
					?>
				</ul>

				<p class="amswap-actions">
					<button type="button" class="button" id="amswap-add-separator">
						<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
						<?php esc_html_e( 'Add separator', 'admin-menu-swap' ); ?>
					</button>
					<?php submit_button( __( 'Save changes', 'admin-menu-swap' ), 'primary', 'submit', false ); ?>
				</p>

				<input type="hidden" name="action" value="amswap_save" />
				<input type="hidden" name="amswap_order" id="amswap_order" value="" />
				<input type="hidden" name="amswap_hidden" id="amswap_hidden" value="" />
				<input type="hidden" name="amswap_removed" id="amswap_removed" value="<?php echo esc_attr( $removed_json ); ?>" />
				<?php wp_nonce_field( 'amswap_save' ); ?>
			</form>

			<template id="amswap-separator-template">
				<?php
				$this->render_item(
					array(
						'slug'         => 'separator-amswap-new',
						'title'        => '',
						'icon'         => '',
						'is_separator' => true,
						'is_custom'    => true,
						'is_hidden'    => false,
						'is_protected' => false,
					)
				);
				?>
			</template>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="amswap-reset-form">
				<input type="hidden" name="action" value="amswap_reset" />
				<?php wp_nonce_field( 'amswap_reset' ); ?>
				<?php submit_button( __( 'Reset to default menu', 'admin-menu-swap' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render a single sortable row.
	 *
	 * @param array $item Display model row from AMSwap_Menu_Reorder::get_display_items().
	 */
	protected function render_item( $item ) {
		$is_separator = ! empty( $item['is_separator'] );
		$is_core_sep  = $is_separator && empty( $item['is_custom'] );
		$is_hidden    = ! empty( $item['is_hidden'] );

		$classes = 'amswap-item';
		if ( $is_separator ) {
			$classes .= ' amswap-separator';
		}
		if ( $is_hidden ) {
			$classes .= ' amswap-is-hidden';
		}
		?>
		<li class="<?php echo esc_attr( $classes ); ?>"
			data-slug="<?php echo esc_attr( $item['slug'] ); ?>"
			data-separator="<?php echo $is_separator ? '1' : '0'; ?>"
			data-core-separator="<?php echo $is_core_sep ? '1' : '0'; ?>"
			data-hidden="<?php echo $is_hidden ? '1' : '0'; ?>">
			<?php if ( $is_separator ) : ?>
				<span class="amswap-sep-line" aria-hidden="true"></span>
				<span class="amswap-sep-label"><?php esc_html_e( 'Separator', 'admin-menu-swap' ); ?></span>
				<span class="amswap-sep-line" aria-hidden="true"></span>
				<button type="button" class="amswap-remove" aria-label="<?php esc_attr_e( 'Remove separator', 'admin-menu-swap' ); ?>" title="<?php esc_attr_e( 'Remove separator', 'admin-menu-swap' ); ?>">&times;</button>
			<?php else : ?>
				<span class="amswap-icon"><?php echo $this->icon_html( $item['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside icon_html(). ?></span>
				<span class="amswap-title"><?php echo esc_html( $item['title'] ); ?></span>
				<span class="amswap-badge"><?php esc_html_e( 'Hidden', 'admin-menu-swap' ); ?></span>
				<?php $this->render_toggle( $is_hidden, ! empty( $item['is_protected'] ) ); ?>
			<?php endif; ?>
			<span class="amswap-handle dashicons dashicons-menu" title="<?php esc_attr_e( 'Drag to reorder', 'admin-menu-swap' ); ?>" aria-hidden="true"></span>
		</li>
		<?php
	}

	/**
	 * Render the show/hide toggle for a menu row.
	 *
	 * The label doubles as the state readout, so it flips with the button
	 * rather than describing it: pressed means the item is hidden.
	 *
	 * @param bool $is_hidden    Whether the item is currently hidden.
	 * @param bool $is_protected Whether the item refuses to be hidden.
	 */
	protected function render_toggle( $is_hidden, $is_protected ) {
		if ( $is_protected ) {
			$locked = __( 'Settings cannot be hidden: this plugin\'s own page lives under it.', 'admin-menu-swap' );
			?>
			<button type="button" class="amswap-toggle" disabled aria-label="<?php echo esc_attr( $locked ); ?>" title="<?php echo esc_attr( $locked ); ?>">
				<span class="dashicons dashicons-lock" aria-hidden="true"></span>
			</button>
			<?php
			return;
		}

		$label = $is_hidden
			? __( 'Show in menu', 'admin-menu-swap' )
			: __( 'Hide from menu', 'admin-menu-swap' );
		$icon  = $is_hidden ? 'dashicons-hidden' : 'dashicons-visibility';
		?>
		<button type="button" class="amswap-toggle"
			aria-pressed="<?php echo $is_hidden ? 'true' : 'false'; ?>"
			aria-label="<?php echo esc_attr( $label ); ?>"
			title="<?php echo esc_attr( $label ); ?>">
			<span class="dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
		</button>
		<?php
	}

	/**
	 * Build the icon markup for a menu item.
	 *
	 * @param string $icon Raw $menu icon value: dashicon class, 'none'/'div', data URI or URL.
	 * @return string Escaped HTML.
	 */
	protected function icon_html( $icon ) {
		if ( 0 === strpos( $icon, 'dashicons-' ) ) {
			return '<span class="dashicons ' . esc_attr( $icon ) . '" aria-hidden="true"></span>';
		}

		if ( '' === $icon || 'none' === $icon || 'div' === $icon ) {
			return '<span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>';
		}

		// esc_url() would strip data: URIs, so escape those as attributes.
		if ( 0 === strpos( $icon, 'data:image' ) ) {
			return '<img src="' . esc_attr( $icon ) . '" alt="" />';
		}

		return '<img src="' . esc_url( $icon ) . '" alt="" />';
	}

	/**
	 * Handle the save form (admin-post.php, PRG).
	 */
	public function handle_save() {
		$this->verify( 'amswap_save' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in verify().
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per entry by the store.
		$order_json   = isset( $_POST['amswap_order'] ) ? wp_unslash( $_POST['amswap_order'] ) : '';
		$hidden_json  = isset( $_POST['amswap_hidden'] ) ? wp_unslash( $_POST['amswap_hidden'] ) : '';
		$removed_json = isset( $_POST['amswap_removed'] ) ? wp_unslash( $_POST['amswap_removed'] ) : '';
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$order   = AMSwap_Order_Store::sanitize_order( $order_json );
		$hidden  = AMSwap_Order_Store::sanitize_hidden( $hidden_json );
		$removed = AMSwap_Order_Store::sanitize_removed( $removed_json );

		if ( empty( $order ) ) {
			$this->redirect_with_notice( __( 'Nothing to save: the submitted menu order was empty.', 'admin-menu-swap' ), 'error' );
		}

		AMSwap_Order_Store::save( $order, $removed, $hidden );

		$this->redirect_with_notice( __( 'Menu saved.', 'admin-menu-swap' ), 'success' );
	}

	/**
	 * Handle the reset form (admin-post.php, PRG).
	 */
	public function handle_reset() {
		$this->verify( 'amswap_reset' );

		AMSwap_Order_Store::reset();

		$this->redirect_with_notice( __( 'Menu reset to the WordPress default.', 'admin-menu-swap' ), 'success' );
	}

	/**
	 * Print the flash notice left by a save/reset redirect.
	 */
	public function render_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$notice = get_transient( $this->notice_key() );

		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}

		delete_transient( $this->notice_key() );

		$type = ( isset( $notice['type'] ) && 'error' === $notice['type'] ) ? 'notice-error' : 'notice-success';

		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $notice['message'] )
		);
	}

	/**
	 * Capability + nonce gate for the admin-post handlers.
	 *
	 * @param string $action Nonce action name.
	 */
	protected function verify( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'admin-menu-swap' ), 403 );
		}

		check_admin_referer( $action );
	}

	/**
	 * Store a flash notice and redirect back to the settings page.
	 *
	 * @param string $message Notice text.
	 * @param string $type    'success' or 'error'.
	 */
	protected function redirect_with_notice( $message, $type ) {
		set_transient(
			$this->notice_key(),
			array(
				'message' => $message,
				'type'    => $type,
			),
			60
		);

		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Per-user transient key for flash notices.
	 *
	 * @return string
	 */
	protected function notice_key() {
		return 'amswap_notice_' . get_current_user_id();
	}
}
