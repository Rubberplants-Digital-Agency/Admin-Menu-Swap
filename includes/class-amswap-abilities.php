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
 * amswap/reset-menu-order. All three need manage_options, the same
 * capability as the settings page, and validate through the same store.
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
				'description' => __( 'Order, hide and group the items of the WordPress admin menu.', 'admin-menu-swap' ),
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

		wp_register_ability(
			'amswap/get-menu',
			array(
				'label'               => __( 'Get admin menu', 'admin-menu-swap' ),
				'description'         => __( 'Returns the top-level WordPress admin menu in display order: each item\'s slug, title, whether it is hidden and its preset group. Also returns what orders the menu (source: saved, preset or default), the effective settings (order, hidden, removed separators, separator color), the preset groups with the slugs no preset pattern names, and the age of the menu snapshot the answer is based on. Use it before amswap/update-menu-order to get the exact slugs.', 'admin-menu-swap' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'source'   => array(
							'type' => 'string',
							'enum' => array( 'saved', 'preset', 'default' ),
						),
						'snapshot' => array( 'type' => 'object' ),
						'settings' => array( 'type' => 'object' ),
						'items'    => array( 'type' => 'array' ),
						'preset'   => array( 'type' => array( 'object', 'null' ) ),
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
				'description'         => __( 'Saves the order of the top-level WordPress admin menu, for all users, as the settings page does. order lists every slug in the wanted order; put "separator-amswap" entries where a separator belongs (they are renumbered). Separators of core and plugins that are not in order are removed. hidden replaces the hidden items and separator_color the separator color (#rrggbb, "" for the default); leave them out to keep the current values. Slugs must exist in the menu (see amswap/get-menu); unknown slugs are refused. Afterwards the preset no longer applies until amswap/reset-menu-order.', 'admin-menu-swap' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'order'           => $slug_list + array(
							'minItems'    => 1,
							'description' => __( 'Every top-level slug in the wanted order, with "separator-amswap" entries for separators.', 'admin-menu-swap' ),
						),
						'hidden'          => $slug_list + array(
							'description' => __( 'Slugs to hide from the menu. Leave out to keep the current hidden items.', 'admin-menu-swap' ),
						),
						'separator_color' => array(
							'type'        => 'string',
							'description' => __( 'Separator color as #rrggbb, or "" for the admin color scheme default. Leave out to keep the current color.', 'admin-menu-swap' ),
						),
					),
					'required'             => array( 'order' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'saved'    => array( 'type' => 'boolean' ),
						'settings' => array( 'type' => 'object' ),
						'missing'  => $slug_list,
						'warning'  => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'update_menu_order' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
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
				'description'         => __( 'Deletes the saved admin menu order, hidden items and separator color. The menu then follows the site\'s preset again, or the WordPress default when there is none. Returns which of the two applies now.', 'admin-menu-swap' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'reset'  => array( 'type' => 'boolean' ),
						'source' => array(
							'type' => 'string',
							'enum' => array( 'preset', 'default' ),
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
	 * Permission check shared by the abilities.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Execute amswap/get-menu.
	 *
	 * @return array
	 */
	public function get_menu() {
		return AMSwap_Menu_Model::describe();
	}

	/**
	 * Execute amswap/update-menu-order.
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public function update_menu_order( $input ) {
		$current  = AMSwap_Menu_Model::describe()['settings'];
		$snapshot = AMSwap_Menu_Snapshot::get();

		// "separator-amswap" without a number is accepted as a new separator.
		$order = array_map(
			static function ( $slug ) {
				return 'separator-amswap' === $slug ? AMSwap_Menu_Reorder::CUSTOM_SEPARATOR_PREFIX . 'new' : $slug;
			},
			$input['order']
		);
		$order = $this->number_new_separators( $order );
		$order = AMSwap_Order_Store::sanitize_order( $order );

		$hidden = AMSwap_Order_Store::sanitize_hidden( isset( $input['hidden'] ) ? $input['hidden'] : $current['hidden'] );
		$color  = isset( $input['separator_color'] ) ? AMSwap_Order_Store::sanitize_color( $input['separator_color'] ) : $current['separator_color'];

		if ( empty( $order ) ) {
			return new WP_Error( 'amswap_empty_order', __( 'The order is empty after validation.', 'admin-menu-swap' ) );
		}

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
				if ( $item['is_separator'] && ! AMSwap_Menu_Reorder::is_custom_separator( $item['slug'] ) && ! in_array( $item['slug'], $order, true ) ) {
					$removed[] = $item['slug'];
				}

				if ( ! $item['is_separator'] && ! in_array( $item['slug'], $order, true ) ) {
					$missing[] = $item['slug'];
				}
			}

			$unknown = array();
			foreach ( array_merge( $order, $hidden ) as $slug ) {
				if ( ! AMSwap_Menu_Reorder::is_custom_separator( $slug ) && ! in_array( $slug, $known, true ) ) {
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

		AMSwap_Order_Store::save( $order, AMSwap_Order_Store::sanitize_removed( $removed ), $hidden, $color );

		return array(
			'saved'    => true,
			'settings' => AMSwap_Menu_Model::describe()['settings'],
			'missing'  => $missing,
			'warning'  => $warning,
		);
	}

	/**
	 * Execute amswap/reset-menu-order.
	 *
	 * @return array
	 */
	public function reset_menu_order() {
		AMSwap_Order_Store::reset();

		return array(
			'reset'  => true,
			'source' => null === AMSwap_Preset::get() ? 'default' : 'preset',
		);
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
