<?php
/**
 * Abilities (WordPress 6.9+): read and change the menu order through MCP
 * clients and the Abilities REST API.
 *
 * @package Admin_Menu_Swap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers amswap/get-menu, amswap/update-menu-order and
 * amswap/reset-menu-order. All three are for agency accounts (see
 * AMSwap_Agency), like the settings page, and validate through the same store.
 */
class AMSwap_Abilities {

	/**
	 * Ability category slug.
	 */
	const CATEGORY = 'admin-menu-swap';

	/**
	 * Hook the registrations in.
	 */
	public function register() {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Register the ability category.
	 */
	public function register_category() {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Admin Menu Swap', 'admin-menu-swap' ),
				'description' => __( 'Order, hide and group the items of the WordPress admin menu, per role.', 'admin-menu-swap' ),
			)
		);
	}

	/**
	 * Register the abilities.
	 */
	public function register_abilities() {
		$slug_list = array(
			'type'  => 'array',
			'items' => array( 'type' => 'string' ),
		);

		$profile = array(
			'type'        => 'string',
			'default'     => AMSwap_Order_Store::DEFAULT_PROFILE,
			'description' => __( 'Menu profile: "default" (agency accounts and roles without a profile) or a role such as "administrator", "editor" or "shop_manager". amswap/get-menu lists the profiles.', 'admin-menu-swap' ),
		);

		wp_register_ability(
			'amswap/get-menu',
			array(
				'label'               => __( 'Get admin menu', 'admin-menu-swap' ),
				'description'         => __( 'Returns the top-level WordPress admin menu of one profile (default: "default") in display order: each item\'s slug, title, whether it is hidden and its preset group. Also returns where the profile\'s settings come from (source: saved, preset, default, or inherit for a role that uses the default menu), its effective settings (order, hidden, removed separators, separator color, follows_default_order), the preset groups with the slugs no preset pattern names, every profile with the number of its users (count: the agency accounts for the default profile, and for a role its users who are not agency accounts, also while the role has no profile and gets the default menu; capped is true when count is a lower bound because the site has more users than were counted), the agency domains and the number of agency accounts (account_count; they always get the default profile) and the age of the menu snapshot. User logins (users per profile, at most 50, and the agency accounts) are left out unless include_users is true. Use it before amswap/update-menu-order to get the exact slugs.', 'admin-menu-swap' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'profile'       => $profile,
						'include_users' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Also return the user logins of each profile and of the agency accounts. These are personal data that the menu does not need, so leave this out unless asked who a profile applies to.', 'admin-menu-swap' ),
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'profile'  => array( 'type' => 'string' ),
						'source'   => array(
							'type' => 'string',
							'enum' => array( 'saved', 'preset', 'default', 'inherit' ),
						),
						'snapshot' => array( 'type' => 'object' ),
						'settings' => array( 'type' => 'object' ),
						'items'    => array( 'type' => 'array' ),
						'preset'   => array( 'type' => array( 'object', 'null' ) ),
						'profiles' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'key'    => array( 'type' => 'string' ),
									'label'  => array( 'type' => 'string' ),
									'source' => array( 'type' => 'string' ),
									'users'  => $slug_list,
									'count'  => array( 'type' => 'integer' ),
									'capped' => array( 'type' => 'boolean' ),
								),
							),
						),
						'agency'   => array(
							'type'       => 'object',
							'properties' => array(
								'domains'       => $slug_list,
								'accounts'      => $slug_list,
								'account_count' => array( 'type' => 'integer' ),
							),
						),
						'notice'   => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'get_menu' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
				),
			)
		);

		wp_register_ability(
			'amswap/update-menu-order',
			array(
				'label'               => __( 'Update admin menu order', 'admin-menu-swap' ),
				'description'         => __( 'Saves the top-level WordPress admin menu of one profile, as the settings page does. It overwrites the order the profile had saved, and its hidden items when hidden is given; separators of core and plugins that are left out of order are removed. There is no undo other than sending the previous order and hidden items again, so get them first with amswap/get-menu (or use amswap/reset-menu-order if its source was not saved). profile is "default" (agency accounts and roles without a profile) or a role. order lists every slug in the wanted order; put "separator-amswap" entries where a separator belongs (they are renumbered). For a role profile, leave order out (or set follow_default_order) to follow the default order and only set hidden. hidden replaces the hidden items; leave it out to keep the current ones. separator_color (#rrggbb, "" for the default) is shared and only accepted for the default profile. Slugs must exist in the menu (see amswap/get-menu); unknown slugs are refused, except those already in the profile\'s order or hidden items (such as the menu of a deactivated plugin), which are kept. Afterwards the preset no longer applies to that profile until amswap/reset-menu-order.', 'admin-menu-swap' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'profile'              => $profile,
						'order'                => $slug_list + array(
							'description' => __( 'Every top-level slug in the wanted order, with "separator-amswap" entries for separators. Required for the default profile.', 'admin-menu-swap' ),
						),
						'follow_default_order' => array(
							'type'        => 'boolean',
							'description' => __( 'Role profiles only: follow the default order and ignore order.', 'admin-menu-swap' ),
						),
						'hidden'               => $slug_list + array(
							'description' => __( 'Slugs to hide from the menu. Leave out to keep the current hidden items.', 'admin-menu-swap' ),
						),
						'separator_color'      => array(
							'type'        => 'string',
							'description' => __( 'Default profile only: separator color as #rrggbb, or "" for the admin color scheme default. Leave out to keep the current color.', 'admin-menu-swap' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'saved'    => array( 'type' => 'boolean' ),
						'profile'  => array( 'type' => 'string' ),
						'settings' => array( 'type' => 'object' ),
						'missing'  => $slug_list,
						'warning'  => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'update_menu_order' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'meta'                => array(
					// Destructive: it overwrites saved settings. The REST run endpoint then takes DELETE.
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
				),
			)
		);

		wp_register_ability(
			'amswap/reset-menu-order',
			array(
				'label'               => __( 'Reset admin menu order', 'admin-menu-swap' ),
				'description'         => __( 'Deletes the saved settings of one profile (default: "default"). The default profile then follows the site\'s preset again, or the WordPress default when there is none; a role profile follows the preset\'s profile for that role, or the default menu. Returns what applies now.', 'admin-menu-swap' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'profile' => $profile,
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'reset'   => array( 'type' => 'boolean' ),
						'profile' => array( 'type' => 'string' ),
						'source'  => array(
							'type' => 'string',
							'enum' => array( 'preset', 'default', 'inherit' ),
						),
					),
				),
				'execute_callback'    => array( $this, 'reset_menu_order' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
				),
			)
		);
	}

	/**
	 * Permission check shared by the abilities: agency accounts only.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( 'manage_options' ) && AMSwap_Agency::is_agency();
	}

	/**
	 * Execute amswap/get-menu.
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public function get_menu( $input = array() ) {
		$profile = $this->profile( $input );

		if ( is_wp_error( $profile ) ) {
			return $profile;
		}

		$model = AMSwap_Menu_Model::describe( $profile );

		$model['agency']['account_count'] = count( $model['agency']['accounts'] );

		// Logins are the client's personal data and go to the AI provider, so only on request.
		if ( isset( $input['include_users'] ) && rest_sanitize_boolean( $input['include_users'] ) ) {
			return $model;
		}

		foreach ( array_keys( $model['profiles'] ) as $i ) {
			unset( $model['profiles'][ $i ]['users'] );
		}

		unset( $model['agency']['accounts'] );

		return $model;
	}

	/**
	 * Execute amswap/update-menu-order.
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public function update_menu_order( $input ) {
		$profile = $this->profile( $input );

		if ( is_wp_error( $profile ) ) {
			return $profile;
		}

		// Over REST this ability runs as DELETE, so its input arrives as query
		// parameters: a list can be a comma-separated string, a flag "false".
		foreach ( array( 'order', 'hidden' ) as $list ) {
			if ( isset( $input[ $list ] ) && ! is_array( $input[ $list ] ) ) {
				$input[ $list ] = wp_parse_list( (string) $input[ $list ] );
			}
		}

		$is_default = AMSwap_Order_Store::DEFAULT_PROFILE === $profile;
		$follow     = ! $is_default && ( ( isset( $input['follow_default_order'] ) && rest_sanitize_boolean( $input['follow_default_order'] ) ) || ! isset( $input['order'] ) );

		if ( $is_default && empty( $input['order'] ) ) {
			return new WP_Error( 'amswap_empty_order', __( 'order is required for the default profile.', 'admin-menu-swap' ) );
		}

		if ( ! $is_default && isset( $input['separator_color'] ) ) {
			return new WP_Error( 'amswap_color_is_shared', __( 'separator_color is shared by all profiles; set it on the default profile.', 'admin-menu-swap' ) );
		}

		$current  = AMSwap_Menu_Model::describe( $profile )['settings'];
		$snapshot = AMSwap_Menu_Snapshot::get();

		$order = array();
		if ( ! $follow ) {
			// "separator-amswap" without a number is accepted as a new separator.
			$order = array_map(
				static function ( $slug ) {
					return 'separator-amswap' === $slug ? AMSwap_Menu_Reorder::CUSTOM_SEPARATOR_PREFIX . 'new' : $slug;
				},
				$input['order']
			);
			$order = AMSwap_Order_Store::sanitize_order( $this->number_new_separators( $order ) );

			if ( empty( $order ) ) {
				return new WP_Error( 'amswap_empty_order', __( 'The order is empty after validation.', 'admin-menu-swap' ) );
			}
		}

		$hidden = AMSwap_Order_Store::sanitize_hidden( isset( $input['hidden'] ) ? $input['hidden'] : $current['hidden'], $profile );
		$color  = isset( $input['separator_color'] ) ? AMSwap_Order_Store::sanitize_color( $input['separator_color'] ) : $current['separator_color'];

		if ( isset( $input['separator_color'] ) && '' !== $input['separator_color'] && '' === $color ) {
			return new WP_Error( 'amswap_invalid_color', __( 'separator_color must be a hex color such as #eb6728, or "".', 'admin-menu-swap' ) );
		}

		$missing = array();
		$warning = '';

		if ( null === $snapshot ) {
			$removed = $current['removed'];
			$warning = __( 'There is no menu snapshot, so the slugs could not be checked and core separators were left as they were.', 'admin-menu-swap' );
		} else {
			$known   = array();
			$removed = array();

			foreach ( $snapshot['items'] as $item ) {
				$known[] = $item['slug'];

				// Core and plugin separators the order leaves out are removed.
				if ( ! $follow && $item['is_separator'] && ! AMSwap_Menu_Reorder::is_custom_separator( $item['slug'] ) && ! in_array( $item['slug'], $order, true ) ) {
					$removed[] = $item['slug'];
				}

				if ( ! $follow && ! $item['is_separator'] && ! in_array( $item['slug'], $order, true ) ) {
					$missing[] = $item['slug'];
				}
			}

			// Slugs the profile already has are kept: a deactivated plugin's
			// item leaves the menu but stays in the saved order or hidden list.
			$kept    = array_merge( $current['order'], $current['hidden'] );
			$unknown = array();
			foreach ( array_merge( $order, $hidden ) as $slug ) {
				if ( ! AMSwap_Menu_Reorder::is_custom_separator( $slug ) && ! in_array( $slug, $known, true ) && ! in_array( $slug, $kept, true ) ) {
					$unknown[] = $slug;
				}
			}

			if ( ! empty( $unknown ) ) {
				return new WP_Error(
					'amswap_unknown_slugs',
					sprintf(
						/* translators: %s: comma-separated menu slugs. */
						__( 'These slugs are not in the admin menu: %s. Use the slugs amswap/get-menu returns.', 'admin-menu-swap' ),
						implode( ', ', array_unique( $unknown ) )
					)
				);
			}

			if ( ! empty( $missing ) ) {
				$warning = __( 'The items in missing were not in the order; they appear below it, in their default order.', 'admin-menu-swap' );
			}
		}

		$removed = AMSwap_Order_Store::sanitize_removed( $removed );

		if ( $is_default ) {
			AMSwap_Order_Store::save( $order, $removed, $hidden, $color );
		} else {
			AMSwap_Order_Store::save_profile( $profile, $order, $removed, $hidden );
		}

		return array(
			'saved'    => true,
			'profile'  => $profile,
			'settings' => AMSwap_Menu_Model::describe( $profile )['settings'],
			'missing'  => $missing,
			'warning'  => $warning,
		);
	}

	/**
	 * Execute amswap/reset-menu-order.
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public function reset_menu_order( $input = array() ) {
		$profile = $this->profile( $input );

		if ( is_wp_error( $profile ) ) {
			return $profile;
		}

		AMSwap_Order_Store::reset_profile( $profile );

		$preset = AMSwap_Preset::get();

		if ( AMSwap_Order_Store::DEFAULT_PROFILE === $profile ) {
			$source = null === $preset ? 'default' : 'preset';
		} else {
			$source = isset( $preset['profiles'][ $profile ] ) ? 'preset' : 'inherit';
		}

		return array(
			'reset'   => true,
			'profile' => $profile,
			'source'  => $source,
		);
	}

	/**
	 * The profile from the input, validated.
	 *
	 * @param array $input Ability input.
	 * @return string|WP_Error
	 */
	protected function profile( $input ) {
		$profile = isset( $input['profile'] ) && '' !== $input['profile'] ? (string) $input['profile'] : AMSwap_Order_Store::DEFAULT_PROFILE;

		if ( ! AMSwap_Order_Store::is_profile( $profile ) ) {
			return new WP_Error(
				'amswap_unknown_profile',
				sprintf(
					/* translators: %s: profile key. */
					__( 'Unknown profile "%s". Use "default" or a role; amswap/get-menu lists them.', 'admin-menu-swap' ),
					$profile
				)
			);
		}

		return $profile;
	}

	/**
	 * Give every "separator-amswap-new" its own number, so the duplicate
	 * check in the store does not merge them; the store renumbers anyway.
	 *
	 * @param string[] $order Slugs.
	 * @return string[]
	 */
	protected function number_new_separators( $order ) {
		$n = 0;

		foreach ( $order as $i => $slug ) {
			if ( AMSwap_Menu_Reorder::CUSTOM_SEPARATOR_PREFIX . 'new' === $slug ) {
				++$n;
				$order[ $i ] = AMSwap_Menu_Reorder::CUSTOM_SEPARATOR_PREFIX . 'new-' . $n;
			}
		}

		return $order;
	}
}
