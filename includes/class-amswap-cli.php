<?php
/**
 * WP-CLI command: wp amswap.
 *
 * @package Admin_Menu_Swap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Inspect the admin menu order and reset it.
 *
 * The admin menu only exists in wp-admin. "list" reads the snapshot that is
 * stored whenever an administrator opens wp-admin; "refresh" (or "list
 * --refresh") builds the menu itself, which needs WP_ADMIN defined before WordPress
 * loads (--exec='define( "WP_ADMIN", true );') and an administrator (--user).
 */
class AMSwap_CLI {

	/**
	 * Lists the admin menu in display order, with each item's preset group.
	 *
	 * ## OPTIONS
	 *
	 * [--refresh]
	 * : Build the admin menu first instead of reading the last snapshot. Needs --exec='define( "WP_ADMIN", true );' and --user=<administrator>.
	 *
	 * [--format=<format>]
	 * : Output format. "json" prints the whole description (source, settings, preset groups).
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - yaml
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp amswap list
	 *     wp amswap list --refresh --exec='define( "WP_ADMIN", true );' --user=1
	 *     wp amswap list --format=json
	 *
	 * @subcommand list
	 *
	 * @param string[] $args       Positional arguments (unused).
	 * @param array    $assoc_args Options.
	 */
	public function list_( $args, $assoc_args ) {
		if ( ! empty( $assoc_args['refresh'] ) ) {
			$this->build();
		}

		$model  = AMSwap_Menu_Model::describe();
		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';

		if ( 'json' === $format ) {
			WP_CLI::line( (string) wp_json_encode( $model, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return;
		}

		if ( '' !== $model['notice'] ) {
			WP_CLI::warning( $model['notice'] );
			return;
		}

		$rows = array();
		foreach ( $model['items'] as $item ) {
			$rows[] = array(
				'slug'   => $item['slug'],
				'title'  => 'separator' === $item['type'] ? '----' : $item['title'],
				'hidden' => $item['hidden'] ? 'yes' : '',
				'group'  => $item['group'],
			);
		}

		WP_CLI\Utils\format_items( $format, $rows, array( 'slug', 'title', 'hidden', 'group' ) );

		if ( 'table' === $format ) {
			WP_CLI::log(
				sprintf(
					/* translators: 1: saved, preset or default. 2: human time difference. */
					__( 'Ordered by: %1$s. Snapshot from %2$s ago.', 'admin-menu-swap' ),
					$model['source'],
					human_time_diff( (int) $model['snapshot']['captured_at'] )
				)
			);

			if ( ! empty( $model['preset']['unmatched'] ) ) {
				WP_CLI::log(
					sprintf(
						/* translators: %s: comma-separated menu slugs. */
						__( 'Not named by the preset: %s', 'admin-menu-swap' ),
						implode( ', ', $model['preset']['unmatched'] )
					)
				);
			}
		}
	}

	/**
	 * Builds the admin menu and stores it as the snapshot the abilities read.
	 *
	 * ## EXAMPLES
	 *
	 *     wp amswap refresh --exec='define( "WP_ADMIN", true );' --user=1
	 *
	 * @param string[] $args       Positional arguments (unused).
	 * @param array    $assoc_args Options (unused).
	 */
	public function refresh( $args, $assoc_args ) {
		$snapshot = $this->build();

		WP_CLI::success(
			sprintf(
				/* translators: %d: number of menu rows. */
				__( 'Stored a snapshot of %d admin menu rows.', 'admin-menu-swap' ),
				count( $snapshot['items'] )
			)
		);
	}

	/**
	 * Deletes the saved order: the menu follows the preset again, or the WordPress default without one.
	 *
	 * ## EXAMPLES
	 *
	 *     wp amswap reset
	 *
	 * @param string[] $args       Positional arguments (unused).
	 * @param array    $assoc_args Options (unused).
	 */
	public function reset( $args, $assoc_args ) {
		if ( ! AMSwap_Order_Store::has_saved() ) {
			WP_CLI::success( __( 'Nothing to reset: no order is saved.', 'admin-menu-swap' ) );
			return;
		}

		AMSwap_Order_Store::reset();

		WP_CLI::success(
			null === AMSwap_Preset::get()
				? __( 'Deleted the saved order. The admin menu is back to the WordPress default.', 'admin-menu-swap' )
				: __( 'Deleted the saved order. The admin menu follows the preset again.', 'admin-menu-swap' )
		);
	}

	/**
	 * Build the snapshot or stop with the reason.
	 *
	 * @return array The snapshot.
	 */
	protected function build() {
		$snapshot = AMSwap_Menu_Snapshot::build();

		if ( is_wp_error( $snapshot ) ) {
			WP_CLI::error( $snapshot->get_error_message() );
		}

		return $snapshot;
	}
}
