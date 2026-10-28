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
			array( 'jquery-ui-sortable', 'wp-color-picker' ),
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
			)
		);
	}

	/**
	 * Render the settings page.
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
		?>
		<div class="wrap amswap-wrap">
			<h1><?php esc_html_e( 'Admin Menu Swap', 'admin-menu-swap' ); ?></h1>

			<p><?php esc_html_e( 'Drag the items below into the order you want the admin menu to appear in. Use the eye button to hide an item you never use. Separators can be dragged, removed and added to group items, and given a color of your own.', 'admin-menu-swap' ); ?></p>

			<p class="description amswap-note">
				<?php esc_html_e( 'Hiding an item only takes it out of the menu — the page behind it stays reachable by its direct URL. Use user roles and capabilities to actually restrict access.', 'admin-menu-swap' ); ?>
			</p>

			<form method="get" action="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>" class="amswap-profile-picker">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<label for="amswap-profile"><?php esc_html_e( 'Menu profile', 'admin-menu-swap' ); ?></label>
				<select name="profile" id="amswap-profile">
					<?php foreach ( $profiles as $option ) : ?>
						<option value="<?php echo esc_attr( $option['key'] ); ?>" <?php selected( $option['key'], $profile ); ?>>
							<?php echo esc_html( $option['label'] . ' — ' . $this->source_label( $option['source'] ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<?php submit_button( __( 'Edit', 'admin-menu-swap' ), 'secondary', '', false ); ?>
			</form>

			<p class="description amswap-applies">
				<?php
				if ( $is_default ) {
					esc_html_e( 'The default menu applies to agency accounts and to every role without a profile of its own.', 'admin-menu-swap' );
				} else {
					esc_html_e( 'This profile applies to users with this role who are not agency accounts. It hides items on top of what the default menu hides, and follows the default order unless you untick that below.', 'admin-menu-swap' );
				}

				if ( ! empty( $current['users'] ) ) {
					echo ' ';
					/* translators: %s: comma-separated user names. */
					echo esc_html( sprintf( __( 'Now: %s.', 'admin-menu-swap' ), implode( ', ', $current['users'] ) ) );
				}
				?>
			</p>

			<?php if ( 'preset' === $saved['source'] ) : ?>
				<div class="notice notice-info inline">
					<p>
						<?php
						echo esc_html(
							$is_default
								? __( 'This menu follows the site\'s preset, which also groups the items of plugins you activate later. Saving makes this order your own; "Reset to preset" returns to the preset.', 'admin-menu-swap' )
								: __( 'This profile comes from the site\'s preset. Saving makes it your own; "Reset to preset" returns to the preset.', 'admin-menu-swap' )
						);
						?>
					</p>
				</div>
			<?php elseif ( 'inherit' === $saved['source'] ) : ?>
				<div class="notice notice-info inline">
					<p><?php esc_html_e( 'This role has no profile yet, so it gets the default menu. Saving creates its profile.', 'admin-menu-swap' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="amswap-form">
				<ul id="amswap-menu-list">
					<?php
					foreach ( $items as $item ) {
						$this->render_item( $item );
					}
					?>
				</ul>

				<?php if ( $is_default ) : ?>
					<div class="amswap-color">
						<label for="amswap_separator_color"><?php esc_html_e( 'Separator color', 'admin-menu-swap' ); ?></label>
						<input type="text" name="amswap_separator_color" id="amswap_separator_color" value="<?php echo esc_attr( $saved['separator_color'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Colors the separators in the admin menu, for every profile. Clear it to keep the admin color scheme\'s default.', 'admin-menu-swap' ); ?></p>
					</div>
				<?php else : ?>
					<p class="amswap-follow">
						<label>
							<input type="checkbox" name="amswap_follow_default" value="1" <?php checked( $saved['follows_default_order'] ); ?> />
							<?php esc_html_e( 'Follow the default order (only the hidden items are this profile\'s own)', 'admin-menu-swap' ); ?>
						</label>
					</p>
				<?php endif; ?>

				<p class="amswap-actions">
					<button type="button" class="button" id="amswap-add-separator">
						<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
						<?php esc_html_e( 'Add separator', 'admin-menu-swap' ); ?>
					</button>
					<?php submit_button( __( 'Save changes', 'admin-menu-swap' ), 'primary', 'submit', false ); ?>
				</p>

				<input type="hidden" name="action" value="amswap_save" />
				<input type="hidden" name="amswap_profile" value="<?php echo esc_attr( $profile ); ?>" />
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
						'no_access'    => false,
					)
				);
				?>
			</template>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="amswap-reset-form">
				<input type="hidden" name="action" value="amswap_reset" />
				<input type="hidden" name="amswap_profile" value="<?php echo esc_attr( $profile ); ?>" />
				<?php wp_nonce_field( 'amswap_reset' ); ?>
				<?php submit_button( $this->reset_label( $profile, $has_preset ), 'secondary', 'submit', false ); ?>
			</form>

			<?php
			if ( $is_default ) {
				$this->render_agency_form();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render the agency accounts form.
	 */
	protected function render_agency_form() {
		$settings = AMSwap_Agency::get();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="amswap-agency-form">
			<h2><?php esc_html_e( 'Agency accounts', 'admin-menu-swap' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Agency accounts always get the default menu and are the only ones who can open this page. An administrator is one when their email address is on an agency domain or when they are ticked below.', 'admin-menu-swap' ); ?></p>

			<p>
				<label for="amswap_agency_domains"><strong><?php esc_html_e( 'Agency email domains', 'admin-menu-swap' ); ?></strong></label><br />
				<input type="text" class="regular-text" name="amswap_agency_domains" id="amswap_agency_domains" value="<?php echo esc_attr( implode( ', ', $settings['domains'] ) ); ?>" />
			</p>

			<fieldset>
				<legend><strong><?php esc_html_e( 'Administrators', 'admin-menu-swap' ); ?></strong></legend>
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
					<label class="amswap-agency-user">
						<input type="checkbox" name="amswap_agency_users[]" value="<?php echo esc_attr( (string) $user->ID ); ?>" <?php checked( in_array( (int) $user->ID, $settings['users'], true ) ); ?> />
						<?php echo esc_html( $user->user_login . ' <' . $user->user_email . '>' ); ?>
						<?php if ( $by_domain ) : ?>
							<span class="amswap-badge amswap-badge-on"><?php esc_html_e( 'Agency by domain', 'admin-menu-swap' ); ?></span>
						<?php endif; ?>
					</label>
				<?php endforeach; ?>
			</fieldset>

			<?php if ( ! AMSwap_Agency::any_agency_account() ) : ?>
				<p class="description"><?php esc_html_e( 'No administrator qualifies yet, so every administrator counts as an agency account for now.', 'admin-menu-swap' ); ?></p>
			<?php endif; ?>

			<input type="hidden" name="action" value="amswap_save_agency" />
			<?php wp_nonce_field( 'amswap_save_agency' ); ?>
			<?php submit_button( __( 'Save agency accounts', 'admin-menu-swap' ), 'secondary', 'submit', false ); ?>
		</form>
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
				<?php if ( ! empty( $item['no_access'] ) ) : ?>
					<span class="amswap-badge amswap-badge-on" title="<?php esc_attr_e( 'This role cannot open this page, so it never sees the item anyway.', 'admin-menu-swap' ); ?>"><?php esc_html_e( 'No access', 'admin-menu-swap' ); ?></span>
				<?php endif; ?>
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
