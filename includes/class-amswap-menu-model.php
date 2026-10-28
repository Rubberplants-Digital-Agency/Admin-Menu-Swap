<?php
/**
 * Menu model: the menu as it will be shown, for the abilities and WP-CLI.
 *
 * @package Admin_Menu_Swap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Combines the snapshot, the saved order and the preset into one
 * description of the admin menu, outside wp-admin.
 */
class AMSwap_Menu_Model {

	/**
	 * Describe the admin menu.
	 *
	 * @return array {
	 *     @type string     $source   'saved', 'preset' or 'default': what orders the menu.
	 *     @type array      $snapshot { captured_at: int|null, age_seconds: int|null }.
	 *     @type array      $settings Effective order, hidden, removed and separator_color.
	 *     @type array[]    $items    The menu in display order: slug, title, type, hidden, group.
	 *     @type array|null $preset   Preset groups and unmatched slugs, or null without a preset.
	 *     @type string     $notice   Advice when the snapshot is missing, else ''.
	 * }
	 */
	public static function describe() {
		$snapshot  = AMSwap_Menu_Snapshot::get();
		$items     = null === $snapshot ? array() : $snapshot['items'];
		$effective = AMSwap_Order_Store::effective( $items );
		$groups    = AMSwap_Preset::group_map( $effective['preset'] );
		$rows      = array();

		foreach ( self::apply_to_items( $items, $effective ) as $item ) {
			$rows[] = array(
				'slug'   => $item['slug'],
				'title'  => $item['title'],
				'type'   => $item['is_separator'] ? 'separator' : 'item',
				'hidden' => ! empty( $item['hidden'] ),
				'group'  => isset( $groups[ $item['slug'] ] ) ? $groups[ $item['slug'] ] : '',
			);
		}

		$preset = null;
		if ( null !== $effective['preset'] ) {
			$preset = array(
				'groups'    => $effective['preset']['groups'],
				'unmatched' => $effective['preset']['unmatched'],
			);
		} elseif ( null !== AMSwap_Preset::get() ) {
			// A preset exists but there is no snapshot to resolve it against.
			$preset = array(
				'groups'    => array(),
				'unmatched' => array(),
			);
		}

		return array(
			'source'   => $effective['source'],
			'snapshot' => array(
				'captured_at' => null === $snapshot ? null : $snapshot['captured_at'],
				'age_seconds' => null === $snapshot ? null : max( 0, time() - $snapshot['captured_at'] ),
			),
			'settings' => array(
				'order'           => array_values( $effective['order'] ),
				'hidden'          => array_values( $effective['hidden'] ),
				'removed'         => array_values( $effective['removed'] ),
				'separator_color' => $effective['separator_color'],
			),
			'items'    => $rows,
			'preset'   => $preset,
			'notice'   => null === $snapshot
				? __( 'No menu snapshot yet: open any wp-admin page as an administrator once, or run: wp amswap list --refresh --exec=\'define( "WP_ADMIN", true );\' --user=<administrator>', 'admin-menu-swap' )
				: '',
		);
	}

	/**
	 * Apply settings to menu items the way prepare_menu() and core's
	 * sort_menu() do: drop removed separators, add custom separators, sort
	 * the slugs in the order first and keep the rest behind them in menu
	 * order, then collapse doubled, leading and trailing separators. Hidden
	 * items stay in the list, flagged, so they can be reported.
	 *
	 * @param array[] $items    Items from AMSwap_Menu_Snapshot::items_from_menu().
	 * @param array   $settings Settings with order, removed and hidden.
	 * @return array[] Items in display order, each with a 'hidden' flag.
	 */
	public static function apply_to_items( $items, $settings ) {
		$rows = array();

		foreach ( $items as $item ) {
			if ( $item['is_separator'] && in_array( $item['slug'], $settings['removed'], true ) ) {
				continue;
			}

			$item['hidden'] = ! $item['is_separator']
				&& ! AMSwap_Menu_Reorder::is_protected( $item['slug'] )
				&& in_array( $item['slug'], $settings['hidden'], true );

			$rows[] = $item;
		}

		if ( ! empty( $settings['order'] ) ) {
			$existing = wp_list_pluck( $rows, 'slug' );

			foreach ( $settings['order'] as $slug ) {
				if ( AMSwap_Menu_Reorder::is_custom_separator( $slug ) && ! in_array( $slug, $existing, true ) ) {
					$rows[] = array(
						'slug'         => $slug,
						'title'        => '',
						'icon'         => '',
						'is_separator' => true,
						'hidden'       => false,
					);
				}
			}

			$position = array_flip( array_values( $settings['order'] ) );
			$unsorted = count( $position );

			foreach ( $rows as $index => $row ) {
				$rows[ $index ]['sort'] = array(
					isset( $position[ $row['slug'] ] ) ? $position[ $row['slug'] ] : $unsorted,
					$index,
				);
			}

			usort(
				$rows,
				static function ( $a, $b ) {
					return $a['sort'] <=> $b['sort'];
				}
			);

			foreach ( $rows as $index => $row ) {
				unset( $rows[ $index ]['sort'] );
			}
		}

		// Core collapses separators around the items it actually shows.
		$shown    = array();
		$previous = true; // Treat the start as a separator: no leading one.

		foreach ( $rows as $row ) {
			if ( $row['is_separator'] ) {
				if ( $previous ) {
					continue;
				}
				$previous = true;
			} elseif ( ! $row['hidden'] ) {
				$previous = false;
			}

			$shown[] = $row;
		}

		while ( ! empty( $shown ) && end( $shown )['is_separator'] ) {
			array_pop( $shown );
		}

		return $shown;
	}
}
