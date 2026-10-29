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
	 * Lists the admin menu of a profile in display order, with each item's preset group.
	 *
	 * ## OPTIONS
	 *
	 * [--profile=<profile>]
	 * : "default" (agency accounts and roles without a profile) or a role, such as administrator or editor.
	 * ---
	 * default: default
	 * ---
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
	 *     wp amswap list --profile=administrator
	 *     wp amswap list --format=json
	 *
	 * @subcommand list
	 *
	 * @param string[] $args       Positional arguments (unused).
	 * @param array    $assoc_args Options.
	 */
	public function list_( $args, $assoc_args ) {
		$profile = $this->profile( $assoc_args );

		if ( ! empty( $assoc_args['refresh'] ) ) {
			$this->build();
		}

		$model  = AMSwap_Menu_Model::describe( $profile );
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
			$current = wp_list_filter( $model['profiles'], array( 'key' => $profile ) );
			$current = reset( $current );

			WP_CLI::log(
				sprintf(
					/* translators: 1: profile key. 2: saved, preset, default or inherit. 3: human time difference. */
					__( 'Profile: %1$s (%2$s). Snapshot from %3$s ago.', 'admin-menu-swap' ),
					$profile,
					$model['source'],
					human_time_diff( (int) $model['snapshot']['captured_at'] )
				)
			);

			if ( ! empty( $current['users'] ) ) {
				$logins = implode( ', ', $current['users'] );

				if ( ! empty( $current['capped'] ) ) {
					// Not every user was looked at, so the count is a lower bound.
					/* translators: 1: comma-separated user logins. 2: number of users counted, a lower bound. */
					$logins = sprintf( __( '%1$s (%2$d+ users)', 'admin-menu-swap' ), $logins, $current['count'] );
				} elseif ( $current['count'] > count( $current['users'] ) ) {
					/* translators: 1: comma-separated user logins. 2: number of users not listed. */
					$logins = sprintf( __( '%1$s and %2$d more', 'admin-menu-swap' ), $logins, $current['count'] - count( $current['users'] ) );
				}

				WP_CLI::log(
					sprintf(
						/* translators: %s: comma-separated user logins. */
						__( 'Users: %s', 'admin-menu-swap' ),
						$logins
					)
				);
			}

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
	 * Deletes a profile's saved settings: it follows the preset again, or the default menu (the WordPress default) without one.
	 *
	 * ## OPTIONS
	 *
	 * [--profile=<profile>]
	 * : "default" or a role.
	 * ---
	 * default: default
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp amswap reset
	 *     wp amswap reset --profile=administrator
	 *
	 * @param string[] $args       Positional arguments (unused).
	 * @param array    $assoc_args Options.
	 */
	public function reset( $args, $assoc_args ) {
		$profile = $this->profile( $assoc_args );

		if ( null === AMSwap_Order_Store::get_profile( $profile ) ) {
			WP_CLI::success( __( 'Nothing to reset: this profile has no saved settings.', 'admin-menu-swap' ) );
			return;
		}

		AMSwap_Order_Store::reset_profile( $profile );

		$preset = AMSwap_Preset::get();

		if ( AMSwap_Order_Store::DEFAULT_PROFILE !== $profile ) {
			WP_CLI::success(
				isset( $preset['profiles'][ $profile ] )
					? __( 'Deleted the profile. The preset\'s profile for this role applies again.', 'admin-menu-swap' )
					: __( 'Deleted the profile. This role gets the default menu.', 'admin-menu-swap' )
			);
			return;
		}

		WP_CLI::success(
			null === $preset
				? __( 'Deleted the saved order. The admin menu is back to the WordPress default.', 'admin-menu-swap' )
				: __( 'Deleted the saved order. The admin menu follows the preset again.', 'admin-menu-swap' )
		);
	}

	/**
	 * Shows or changes the agency accounts: administrators who always get the default menu and alone may change it.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : list, add, remove or domains.
	 * ---
	 * options:
	 *   - list
	 *   - add
	 *   - remove
	 *   - domains
	 * ---
	 *
	 * [<value>...]
	 * : add/remove: user IDs, logins or emails (a value of digits only is tried as an ID first, then as a login). domains: the email domains (replaces the list; none clears it).
	 *
	 * ## EXAMPLES
	 *
	 *     wp amswap agency list
	 *     wp amswap agency add 12
	 *     wp amswap agency add jdoe jdoe@example.com
	 *     wp amswap agency remove jdoe
	 *     wp amswap agency domains example.com
	 *
	 * @param string[] $args       Action and values.
	 * @param array    $assoc_args Options (unused).
	 */
	public function agency( $args, $assoc_args ) {
		$action   = array_shift( $args );
		$settings = AMSwap_Agency::get();

		if ( 'list' === $action ) {
			$rows = array();
			foreach ( AMSwap_Agency::administrators() as $user ) {
				$rows[] = array(
					'user'   => $user->user_login,
					'email'  => $user->user_email,
					'picked' => in_array( (int) $user->ID, $settings['users'], true ) ? 'yes' : '',
					'agency' => AMSwap_Agency::is_agency( $user ) ? 'yes' : '',
				);
			}

			WP_CLI\Utils\format_items( 'table', $rows, array( 'user', 'email', 'picked', 'agency' ) );
			WP_CLI::log(
				sprintf(
					/* translators: %s: comma-separated domains. */
					__( 'Agency domains: %s', 'admin-menu-swap' ),
					empty( $settings['domains'] ) ? '-' : implode( ', ', $settings['domains'] )
				)
			);

			if ( ! AMSwap_Agency::any_agency_account() ) {
				WP_CLI::warning( __( 'No administrator qualifies, so every administrator counts as an agency account.', 'admin-menu-swap' ) );
			}
			return;
		}

		if ( 'domains' === $action ) {
			AMSwap_Agency::save( AMSwap_Agency::sanitize_domains( $args ), $settings['users'] );
			WP_CLI::success( __( 'Saved the agency domains.', 'admin-menu-swap' ) );
			return;
		}

		if ( empty( $args ) ) {
			WP_CLI::error( __( 'Name at least one user.', 'admin-menu-swap' ) );
		}

		$users    = $settings['users'];
		$resolved = array();

		foreach ( $args as $value ) {
			$value = (string) $value;
			$user  = false;

			// Digits only is an ID, unless no user has it: logins can be all digits too.
			if ( preg_match( '/^\d+$/D', $value ) ) {
				$user = get_userdata( (int) $value );
			}

			if ( ! $user ) {
				$user = get_user_by( 'login', $value );
			}

			if ( ! $user ) {
				$user = get_user_by( 'email', $value );
			}

			if ( ! $user ) {
				/* translators: %s: user ID, login or email. */
				WP_CLI::error( sprintf( __( 'No such user: %s', 'admin-menu-swap' ), $value ) );
			}

			if ( 'add' === $action ) {
				if ( ! user_can( $user, 'manage_options' ) ) {
					/* translators: %s: user login. */
					WP_CLI::error( sprintf( __( '%s is no administrator.', 'admin-menu-swap' ), $user->user_login ) );
				}
				$users[] = (int) $user->ID;
			} else {
				$users = array_diff( $users, array( (int) $user->ID ) );
			}

			// Named in the output, so a value that matched someone else shows.
			/* translators: 1: user login. 2: user ID. */
			$resolved[] = sprintf( __( '%1$s (ID %2$d)', 'admin-menu-swap' ), $user->user_login, $user->ID );
		}

		AMSwap_Agency::save( $settings['domains'], $users );

		if ( 'add' === $action ) {
			/* translators: %s: comma-separated user logins with their IDs. */
			$message = __( 'Saved the agency accounts. Added: %s', 'admin-menu-swap' );
		} else {
			/* translators: %s: comma-separated user logins with their IDs. */
			$message = __( 'Saved the agency accounts. Removed: %s', 'admin-menu-swap' );
		}

		WP_CLI::success( sprintf( $message, implode( ', ', array_unique( $resolved ) ) ) );
	}

	/**
	 * The --profile option, validated.
	 *
	 * @param array $assoc_args Options.
	 * @return string
	 */
	protected function profile( $assoc_args ) {
		$profile = isset( $assoc_args['profile'] ) ? (string) $assoc_args['profile'] : AMSwap_Order_Store::DEFAULT_PROFILE;

		if ( ! AMSwap_Order_Store::is_profile( $profile ) ) {
			/* translators: %s: profile key. */
			WP_CLI::error( sprintf( __( 'Unknown profile "%s". Use "default" or a role.', 'admin-menu-swap' ), $profile ) );
		}

		return $profile;
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
