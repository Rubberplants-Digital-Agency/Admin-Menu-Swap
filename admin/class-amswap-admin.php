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
 * Everything here is for agency accounts only (see AMSwap_Agency).
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
		add_action( 'admin_post_amswap_save_agency', array( $this, 'handle_save_agency' ) );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
		add_filter( 'plugin_action_links_' . AMSWAP_PLUGIN_BASENAME, array( $this, 'action_links' ) );
	}

	/**
	 * Register the settings page, for agency accounts only. A submenu under
	 * Settings is never itself part of the sortable top-level list.
	 */
	public function register_page() {
		if ( ! AMSwap_Agency::is_agency() ) {
			return;
		}

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
		if ( ! AMSwap_Agency::is_agency() ) {
			return $links;
		}

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
			array( 'dashicons', 'wp-color-picker' ),
			AMSWAP_VERSION
		);

		wp_enqueue_script(
			'amswap-admin',
			AMSWAP_PLUGIN_URL . 'assets/admin.js',
			array( 'jquery-ui-sortable', 'wp-color-picker', 'wp-a11y' ),
			AMSWAP_VERSION,
			true
		);

		$profile    = $this->requested_profile();
		$has_preset = null !== AMSwap_Preset::get();

		if ( AMSwap_Order_Store::DEFAULT_PROFILE !== $profile ) {
			$confirm = __( 'Reset this profile? Its saved order and hidden items are deleted; the preset\'s profile, or else the default menu, applies again.', 'admin-menu-swap' );
		} elseif ( $has_preset ) {
			$confirm = __( 'Reset the admin menu to the preset? Your saved order, hidden items, custom separators and separator color will be deleted.', 'admin-menu-swap' );
		} else {
			$confirm = __( 'Reset the admin menu to the WordPress default? Your saved order, hidden items, custom separators and separator color will be deleted.', 'admin-menu-swap' );
		}

		wp_localize_script(
			'amswap-admin',
			'amswapL10n',
			array(
				'confirmReset' => $confirm,
				'hideLabel'    => __( 'Hide from menu', 'admin-menu-swap' ),
				'showLabel'    => __( 'Show in menu', 'admin-menu-swap' ),
				/* translators: 1: number of menu items, 2: number of hidden items. */
				'count'        => __( '%1$d items, %2$d hidden', 'admin-menu-swap' ),
				/* translators: 1: new position, 2: number of rows. */
				'moved'        => __( 'Moved to position %1$d of %2$d.', 'admin-menu-swap' ),
				'leave'        => __( 'You have unsaved changes to the menu.', 'admin-menu-swap' ),
			)
		);
	}

	/**
	 * Render the settings page: profile tabs, the menu as a sortable copy of
	 * the admin menu, and a sidebar with who sees it and what to do with it.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) || ! AMSwap_Agency::is_agency() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'admin-menu-swap' ), 403 );
		}

		$profile    = $this->requested_profile();
		$is_default = AMSwap_Order_Store::DEFAULT_PROFILE === $profile;
		$items      = AMSwap_Menu_Reorder::get_display_items( $profile );
		$profiles   = AMSwap_Menu_Model::profiles();
		$current    = wp_list_filter( $profiles, array( 'key' => $profile ) );
		$current    = reset( $current );
		$has_preset = null !== AMSwap_Preset::get();

		if ( $is_default ) {
			$saved = AMSwap_Menu_Reorder::current_settings();
		} else {
			$snapshot = AMSwap_Menu_Snapshot::get();
			$saved    = AMSwap_Order_Store::effective( null === $snapshot ? array() : $snapshot['items'], $profile );
		}

		// Carry the removed-separator list forward so a later save that
		// removes nothing new does not resurrect earlier removals. While the
		// preset applies, this is the preset's list, so saving keeps its look.
		$removed_json = wp_json_encode( array_values( $saved['removed'] ) );
		if ( ! is_string( $removed_json ) ) {
			$removed_json = '[]';
		}

		$rows   = wp_list_filter( $items, array( 'is_separator' => false ) );
		$hidden = wp_list_filter( $rows, array( 'is_hidden' => true ) );

		// The list draws separators in the color the real menu uses (shared
		// by every profile, so a role profile carries the default's).
		$list_style = '' === $saved['separator_color'] ? '' : '--amswap-separator: ' . $saved['separator_color'] . ';';
		?>
		<div class="wrap amswap-wrap">
			<header class="amswap-header">
				<h1><?php esc_html_e( 'Admin Menu Swap', 'admin-menu-swap' ); ?></h1>
				<p><?php esc_html_e( 'Arrange the admin menu, hide what nobody uses and give each role its own view.', 'admin-menu-swap' ); ?></p>
			</header>

			<nav class="amswap-profiles" aria-label="<?php esc_attr_e( 'Menu profiles', 'admin-menu-swap' ); ?>">
				<?php foreach ( $profiles as $option ) : ?>
					<?php
					$url   = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
					$url   = AMSwap_Order_Store::DEFAULT_PROFILE === $option['key'] ? $url : add_query_arg( 'profile', $option['key'], $url );
					$label = AMSwap_Order_Store::DEFAULT_PROFILE === $option['key'] ? __( 'Default menu', 'admin-menu-swap' ) : $option['label'];
					?>
					<a href="<?php echo esc_url( $url ); ?>"
						class="amswap-profile amswap-source-<?php echo esc_attr( $option['source'] ); ?><?php echo $option['key'] === $profile ? ' is-current' : ''; ?>"
						<?php echo $option['key'] === $profile ? 'aria-current="page"' : ''; ?>
						title="<?php echo esc_attr( $this->source_label( $option['source'] ) ); ?>">
						<span class="amswap-profile-name"><?php echo esc_html( $label ); ?></span>
						<?php if ( ! empty( $option['users'] ) ) : ?>
							<span class="amswap-profile-count"><?php echo esc_html( (string) count( $option['users'] ) ); ?></span>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="amswap-layout">
				<section class="amswap-menu" aria-labelledby="amswap-menu-title">
					<div class="amswap-menu-head">
						<h2 id="amswap-menu-title"><?php esc_html_e( 'Admin menu', 'admin-menu-swap' ); ?></h2>
						<span class="amswap-count" id="amswap-count" aria-live="polite">
							<?php
							/* translators: 1: number of menu items, 2: number of hidden items. */
							echo esc_html( sprintf( __( '%1$d items, %2$d hidden', 'admin-menu-swap' ), count( $rows ), count( $hidden ) ) );
							?>
						</span>
					</div>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="amswap-form">
						<ul id="amswap-menu-list" style="<?php echo esc_attr( $list_style ); ?>">
							<?php
							foreach ( $items as $item ) {
								$this->render_item( $item );
							}
							?>
						</ul>

						<input type="hidden" name="action" value="amswap_save" />
						<input type="hidden" name="amswap_profile" value="<?php echo esc_attr( $profile ); ?>" />
						<input type="hidden" name="amswap_order" id="amswap_order" value="" />
						<input type="hidden" name="amswap_hidden" id="amswap_hidden" value="" />
						<input type="hidden" name="amswap_removed" id="amswap_removed" value="<?php echo esc_attr( $removed_json ); ?>" />
						<?php wp_nonce_field( 'amswap_save' ); ?>
					</form>

					<div class="amswap-menu-foot">
						<button type="button" class="amswap-add" id="amswap-add-separator">
							<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
							<?php esc_html_e( 'Add separator', 'admin-menu-swap' ); ?>
						</button>
						<span class="amswap-hint"><?php esc_html_e( 'Drag by the handle, or focus it and use the arrow keys.', 'admin-menu-swap' ); ?></span>
					</div>
				</section>

				<aside class="amswap-side">
					<section class="amswap-side-section">
						<h2><?php esc_html_e( 'Who sees this menu', 'admin-menu-swap' ); ?></h2>
						<p>
							<?php
							echo esc_html(
								$is_default
									? __( 'Agency accounts, and every role without a profile of its own.', 'admin-menu-swap' )
									: __( 'Users with this role who are not agency accounts. The profile hides items on top of what the default menu hides.', 'admin-menu-swap' )
							);
							?>
						</p>

						<?php if ( ! empty( $current['users'] ) ) : ?>
							<ul class="amswap-users">
								<?php foreach ( $current['users'] as $login ) : ?>
									<li><?php echo esc_html( $login ); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php else : ?>
							<p class="amswap-muted"><?php esc_html_e( 'Nobody gets this menu right now.', 'admin-menu-swap' ); ?></p>
						<?php endif; ?>

						<p class="amswap-source">
							<?php echo esc_html( $this->source_text( $saved['source'], $is_default ) ); ?>
						</p>

						<?php if ( ! $is_default ) : ?>
							<label class="amswap-check">
								<input type="checkbox" name="amswap_follow_default" value="1" form="amswap-form" <?php checked( $saved['follows_default_order'] ); ?> />
								<?php esc_html_e( 'Follow the default order', 'admin-menu-swap' ); ?>
							</label>
						<?php endif; ?>
					</section>

					<?php if ( $is_default ) : ?>
						<section class="amswap-side-section">
							<h2><label for="amswap_separator_color"><?php esc_html_e( 'Separator color', 'admin-menu-swap' ); ?></label></h2>
							<input type="text" name="amswap_separator_color" id="amswap_separator_color" form="amswap-form" value="<?php echo esc_attr( $saved['separator_color'] ); ?>" />
							<p class="amswap-muted"><?php esc_html_e( 'Shared by every profile. Clear it to keep the color scheme\'s own.', 'admin-menu-swap' ); ?></p>
						</section>
					<?php endif; ?>

					<section class="amswap-side-section amswap-actions">
						<div class="amswap-save">
							<button type="submit" form="amswap-form" class="button button-primary"><?php esc_html_e( 'Save changes', 'admin-menu-swap' ); ?></button>
							<span class="amswap-dirty" id="amswap-dirty" hidden><?php esc_html_e( 'Unsaved changes', 'admin-menu-swap' ); ?></span>
						</div>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="amswap-reset-form">
							<input type="hidden" name="action" value="amswap_reset" />
							<input type="hidden" name="amswap_profile" value="<?php echo esc_attr( $profile ); ?>" />
							<?php wp_nonce_field( 'amswap_reset' ); ?>
							<button type="submit" class="button-link amswap-reset"><?php echo esc_html( $this->reset_label( $profile, $has_preset ) ); ?></button>
						</form>
					</section>

					<?php if ( $is_default ) : ?>
						<section class="amswap-side-section">
							<?php $this->render_agency_form(); ?>
						</section>
					<?php endif; ?>

					<p class="amswap-note">
						<?php esc_html_e( 'Hiding takes an item out of the menu; its page stays reachable by URL. Roles and capabilities decide who may open it.', 'admin-menu-swap' ); ?>
					</p>
				</aside>
			</div>

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
						'no_access'    => false,
					)
				);
				?>
			</template>
		</div>
		<?php
	}

	/**
	 * Render the agency accounts form, folded away.
	 */
	protected function render_agency_form() {
		$settings = AMSwap_Agency::get();
		?>
		<details class="amswap-agency">
			<summary><h2><?php esc_html_e( 'Agency accounts', 'admin-menu-swap' ); ?></h2></summary>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="amswap-agency-form">
				<p class="amswap-muted"><?php esc_html_e( 'They always get the default menu and are the only ones who can open this page.', 'admin-menu-swap' ); ?></p>

				<p>
					<label for="amswap_agency_domains"><?php esc_html_e( 'Email domains', 'admin-menu-swap' ); ?></label>
					<input type="text" class="widefat" name="amswap_agency_domains" id="amswap_agency_domains" value="<?php echo esc_attr( implode( ', ', $settings['domains'] ) ); ?>" />
				</p>

				<fieldset>
					<legend><?php esc_html_e( 'Administrators', 'admin-menu-swap' ); ?></legend>
					<?php foreach ( AMSwap_Agency::administrators() as $user ) : ?>
						<?php
						$by_domain = AMSwap_Agency::qualifies(
							$user,
							array(
								'domains' => $settings['domains'],
								'users'   => array(),
							)
						);
						?>
						<label class="amswap-check">
							<input type="checkbox" name="amswap_agency_users[]" value="<?php echo esc_attr( (string) $user->ID ); ?>" <?php checked( in_array( (int) $user->ID, $settings['users'], true ) ); ?> />
							<span>
								<?php echo esc_html( $user->user_login ); ?>
								<span class="amswap-muted"><?php echo esc_html( $user->user_email ); ?></span>
								<?php if ( $by_domain ) : ?>
									<span class="amswap-badge"><?php esc_html_e( 'by domain', 'admin-menu-swap' ); ?></span>
								<?php endif; ?>
							</span>
						</label>
					<?php endforeach; ?>
				</fieldset>

				<?php if ( ! AMSwap_Agency::any_agency_account() ) : ?>
					<p class="amswap-muted"><?php esc_html_e( 'No administrator qualifies yet, so every administrator counts as an agency account for now.', 'admin-menu-swap' ); ?></p>
				<?php endif; ?>

				<input type="hidden" name="action" value="amswap_save_agency" />
				<?php wp_nonce_field( 'amswap_save_agency' ); ?>
				<button type="submit" class="button"><?php esc_html_e( 'Save agency accounts', 'admin-menu-swap' ); ?></button>
			</form>
		</details>
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
		if ( ! empty( $item['no_access'] ) ) {
			$classes .= ' amswap-no-access';
		}

		$move = $is_separator
			? __( 'Move separator', 'admin-menu-swap' )
			/* translators: %s: menu item title. */
			: sprintf( __( 'Move %s', 'admin-menu-swap' ), $item['title'] );
		?>
		<li class="<?php echo esc_attr( $classes ); ?>"
			data-slug="<?php echo esc_attr( $item['slug'] ); ?>"
			data-separator="<?php echo $is_separator ? '1' : '0'; ?>"
			data-core-separator="<?php echo $is_core_sep ? '1' : '0'; ?>"
			data-hidden="<?php echo $is_hidden ? '1' : '0'; ?>">
			<button type="button" class="amswap-handle" aria-label="<?php echo esc_attr( $move ); ?>" title="<?php echo esc_attr( $move ); ?>">
				<span class="dashicons dashicons-menu" aria-hidden="true"></span>
			</button>
			<?php if ( $is_separator ) : ?>
				<span class="amswap-sep-line" aria-hidden="true"></span>
				<span class="screen-reader-text"><?php esc_html_e( 'Separator', 'admin-menu-swap' ); ?></span>
				<button type="button" class="amswap-remove" aria-label="<?php esc_attr_e( 'Remove separator', 'admin-menu-swap' ); ?>" title="<?php esc_attr_e( 'Remove separator', 'admin-menu-swap' ); ?>">
					<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
				</button>
			<?php else : ?>
				<span class="amswap-icon"><?php echo $this->icon_html( $item['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside icon_html(). ?></span>
				<span class="amswap-label">
					<span class="amswap-title"><?php echo esc_html( $item['title'] ); ?></span>
					<span class="amswap-slug"><?php echo esc_html( $item['slug'] ); ?></span>
				</span>
				<span class="amswap-badge amswap-badge-hidden"><?php esc_html_e( 'Hidden', 'admin-menu-swap' ); ?></span>
				<?php if ( ! empty( $item['no_access'] ) ) : ?>
					<span class="amswap-badge" title="<?php esc_attr_e( 'This role cannot open this page, so it never sees the item anyway.', 'admin-menu-swap' ); ?>"><?php esc_html_e( 'No access', 'admin-menu-swap' ); ?></span>
				<?php endif; ?>
				<?php $this->render_toggle( $is_hidden, ! empty( $item['is_protected'] ) ); ?>
			<?php endif; ?>
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
		$color_raw    = isset( $_POST['amswap_separator_color'] ) ? wp_unslash( $_POST['amswap_separator_color'] ) : '';
		$follow       = ! empty( $_POST['amswap_follow_default'] );
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$profile = $this->posted_profile();
		$order   = AMSwap_Order_Store::sanitize_order( $order_json );
		$hidden  = AMSwap_Order_Store::sanitize_hidden( $hidden_json, $profile );
		$removed = AMSwap_Order_Store::sanitize_removed( $removed_json );

		if ( AMSwap_Order_Store::DEFAULT_PROFILE !== $profile ) {
			if ( ! $follow && empty( $order ) ) {
				$this->redirect_with_notice( __( 'Nothing to save: the submitted menu order was empty.', 'admin-menu-swap' ), 'error', $profile );
			}

			AMSwap_Order_Store::save_profile( $profile, $follow ? array() : $order, $removed, $hidden );

			$this->redirect_with_notice( __( 'Profile saved.', 'admin-menu-swap' ), 'success', $profile );
		}

		if ( empty( $order ) ) {
			$this->redirect_with_notice( __( 'Nothing to save: the submitted menu order was empty.', 'admin-menu-swap' ), 'error' );
		}

		AMSwap_Order_Store::save( $order, $removed, $hidden, AMSwap_Order_Store::sanitize_color( $color_raw ) );

		$this->redirect_with_notice( __( 'Menu saved.', 'admin-menu-swap' ), 'success' );
	}

	/**
	 * Handle the reset form (admin-post.php, PRG).
	 */
	public function handle_reset() {
		$this->verify( 'amswap_reset' );

		$profile = $this->posted_profile();

		AMSwap_Order_Store::reset_profile( $profile );

		if ( AMSwap_Order_Store::DEFAULT_PROFILE !== $profile ) {
			$this->redirect_with_notice( __( 'Profile reset.', 'admin-menu-swap' ), 'success', $profile );
		}

		$this->redirect_with_notice(
			null === AMSwap_Preset::get() ? __( 'Menu reset to the WordPress default.', 'admin-menu-swap' ) : __( 'Menu reset to the preset.', 'admin-menu-swap' ),
			'success'
		);
	}

	/**
	 * Handle the agency accounts form (admin-post.php, PRG). Refuses a
	 * change that would make the current user lose access to this page.
	 */
	public function handle_save_agency() {
		$this->verify( 'amswap_save_agency' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in verify().
		$domains = isset( $_POST['amswap_agency_domains'] ) ? sanitize_text_field( wp_unslash( $_POST['amswap_agency_domains'] ) ) : '';
		$users   = isset( $_POST['amswap_agency_users'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['amswap_agency_users'] ) ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$settings = array(
			'domains' => AMSwap_Agency::sanitize_domains( $domains ),
			'users'   => $users,
		);

		$me = wp_get_current_user();

		// is_super_admin() is true for every administrator on a single site.
		$super = is_multisite() && is_super_admin( $me->ID );

		if ( ! $super && ! AMSwap_Agency::qualifies( $me, $settings ) && AMSwap_Agency::any_agency_account( $settings ) ) {
			$this->redirect_with_notice( __( 'Not saved: you would no longer be an agency account. Tick yourself or keep your email domain in the list.', 'admin-menu-swap' ), 'error' );
		}

		AMSwap_Agency::save( $settings['domains'], $settings['users'] );

		$this->redirect_with_notice( __( 'Agency accounts saved.', 'admin-menu-swap' ), 'success' );
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
	 * The profile asked for in the URL, the default when missing or unknown.
	 *
	 * @return string
	 */
	protected function requested_profile() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen choice, changes no state.
		$profile = isset( $_GET['profile'] ) ? sanitize_key( wp_unslash( $_GET['profile'] ) ) : '';

		return AMSwap_Order_Store::is_profile( $profile ) ? $profile : AMSwap_Order_Store::DEFAULT_PROFILE;
	}

	/**
	 * The profile a form was posted for, the default when missing or unknown.
	 *
	 * @return string
	 */
	protected function posted_profile() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in verify() by every caller.
		$profile = isset( $_POST['amswap_profile'] ) ? sanitize_key( wp_unslash( $_POST['amswap_profile'] ) ) : '';

		return AMSwap_Order_Store::is_profile( $profile ) ? $profile : AMSwap_Order_Store::DEFAULT_PROFILE;
	}

	/**
	 * Human label for where a profile's settings come from.
	 *
	 * @param string $source 'saved', 'preset', 'default' or 'inherit'.
	 * @return string
	 */
	protected function source_label( $source ) {
		switch ( $source ) {
			case 'saved':
				return __( 'saved', 'admin-menu-swap' );
			case 'preset':
				return __( 'preset', 'admin-menu-swap' );
			case 'inherit':
				return __( 'uses the default menu', 'admin-menu-swap' );
			default:
				return __( 'WordPress default', 'admin-menu-swap' );
		}
	}

	/**
	 * One sentence on where the shown profile's settings come from.
	 *
	 * @param string $source     'saved', 'preset', 'default' or 'inherit'.
	 * @param bool   $is_default Whether this is the default profile.
	 * @return string
	 */
	protected function source_text( $source, $is_default ) {
		switch ( $source ) {
			case 'saved':
				return __( 'Saved on this page.', 'admin-menu-swap' );
			case 'preset':
				return $is_default
					? __( 'From the site\'s preset, which also places plugins activated later. Saving makes it your own.', 'admin-menu-swap' )
					: __( 'From the site\'s preset. Saving makes it your own.', 'admin-menu-swap' );
			case 'inherit':
				return __( 'No profile yet: this role gets the default menu. Saving creates its profile.', 'admin-menu-swap' );
			default:
				return __( 'The WordPress menu, unchanged. Saving stores your order.', 'admin-menu-swap' );
		}
	}

	/**
	 * Label of the reset button.
	 *
	 * @param string $profile    Profile key.
	 * @param bool   $has_preset Whether a preset exists.
	 * @return string
	 */
	protected function reset_label( $profile, $has_preset ) {
		if ( AMSwap_Order_Store::DEFAULT_PROFILE === $profile ) {
			return $has_preset ? __( 'Reset to preset', 'admin-menu-swap' ) : __( 'Reset to default menu', 'admin-menu-swap' );
		}

		$preset = AMSwap_Preset::get();

		return isset( $preset['profiles'][ $profile ] ) ? __( 'Reset to preset', 'admin-menu-swap' ) : __( 'Remove this profile', 'admin-menu-swap' );
	}

	/**
	 * Capability, agency + nonce gate for the admin-post handlers.
	 *
	 * @param string $action Nonce action name.
	 */
	protected function verify( $action ) {
		if ( ! current_user_can( 'manage_options' ) || ! AMSwap_Agency::is_agency() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'admin-menu-swap' ), 403 );
		}

		check_admin_referer( $action );
	}

	/**
	 * Store a flash notice and redirect back to the settings page.
	 *
	 * @param string $message Notice text.
	 * @param string $type    'success' or 'error'.
	 * @param string $profile Profile to return to.
	 */
	protected function redirect_with_notice( $message, $type, $profile = AMSwap_Order_Store::DEFAULT_PROFILE ) {
		set_transient(
			$this->notice_key(),
			array(
				'message' => $message,
				'type'    => $type,
			),
			60
		);

		$url = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
		if ( AMSwap_Order_Store::DEFAULT_PROFILE !== $profile ) {
			$url = add_query_arg( 'profile', $profile, $url );
		}

		wp_safe_redirect( $url );
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
