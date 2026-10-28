<?php
/**
 * Agency accounts: the people who build and maintain the site.
 *
 * @package Admin_Menu_Swap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides who is an agency account. Agency accounts see the default menu,
 * never a role profile, and are the only ones who reach the settings page,
 * the abilities and the agency settings.
 *
 * An administrator is an agency account when their email address is on one
 * of the agency domains or when they were picked by hand. Super admins on a
 * multisite always are. As long as no account on the site qualifies, every
 * administrator counts as one, so a site can never lock everybody out.
 */
class AMSwap_Agency {

	/**
	 * Option holding the agency domains and picked user IDs. Not autoloaded.
	 */
	const OPTION = 'amswap_agency';

	/**
	 * Agency domains until others are saved.
	 */
	const DEFAULT_DOMAINS = array( 'rubberplants.nl' );

	/**
	 * Whether any account qualifies, cached per request.
	 *
	 * @var bool|null
	 */
	protected static $any = null;

	/**
	 * The agency settings.
	 *
	 * @return array {
	 *     @type string[] $domains Email domains, lowercase, without "@".
	 *     @type int[]    $users   Picked user IDs.
	 * }
	 */
	public static function get() {
		$saved = get_option( self::OPTION );

		if ( is_array( $saved ) && isset( $saved['domains'] ) && is_array( $saved['domains'] ) ) {
			$domains = $saved['domains'];
		} else {
			/**
			 * The agency domains used until domains are saved on the settings page.
			 *
			 * @param string[] $domains Default: rubberplants.nl.
			 */
			$domains = apply_filters( 'amswap_agency_domains', self::DEFAULT_DOMAINS );
		}

		$users = ( is_array( $saved ) && isset( $saved['users'] ) && is_array( $saved['users'] ) ) ? $saved['users'] : array();

		return array(
			'domains' => self::sanitize_domains( (array) $domains ),
			'users'   => array_values( array_unique( array_filter( array_map( 'absint', $users ) ) ) ),
		);
	}

	/**
	 * Save the agency settings.
	 *
	 * @param string[] $domains Email domains.
	 * @param int[]    $users   User IDs.
	 * @return bool Whether the option was updated.
	 */
	public static function save( $domains, $users ) {
		self::$any = null;

		return update_option(
			self::OPTION,
			array(
				'domains' => self::sanitize_domains( $domains ),
				'users'   => array_values( array_unique( array_filter( array_map( 'absint', (array) $users ) ) ) ),
			),
			false
		);
	}

	/**
	 * Whether a user is an agency account.
	 *
	 * @param WP_User|int|null $user A user, an ID, or null for the current user.
	 * @return bool
	 */
	public static function is_agency( $user = null ) {
		if ( null === $user ) {
			$user = wp_get_current_user();
		} elseif ( ! $user instanceof WP_User ) {
			$user = get_userdata( (int) $user );
		}

		if ( ! $user instanceof WP_User || ! $user->exists() ) {
			return false;
		}

		if ( is_multisite() && is_super_admin( $user->ID ) ) {
			return true;
		}

		if ( ! user_can( $user, 'manage_options' ) ) {
			return false;
		}

		if ( self::qualifies( $user, self::get() ) ) {
			return true;
		}

		return ! self::any_agency_account();
	}

	/**
	 * Whether a user qualifies by domain or by being picked, without the
	 * "nobody qualifies" fallback.
	 *
	 * @param WP_User $user     User.
	 * @param array   $settings Settings from get().
	 * @return bool
	 */
	public static function qualifies( $user, $settings ) {
		if ( in_array( (int) $user->ID, $settings['users'], true ) ) {
			return true;
		}

		$at = strrchr( (string) $user->user_email, '@' );

		return false !== $at && in_array( strtolower( substr( $at, 1 ) ), $settings['domains'], true );
	}

	/**
	 * Whether at least one administrator qualifies by domain or by being picked.
	 *
	 * @param array|null $settings Settings to test, default the saved ones.
	 * @return bool
	 */
	public static function any_agency_account( $settings = null ) {
		$cache = null === $settings;

		if ( $cache && null !== self::$any ) {
			return self::$any;
		}

		$settings = $cache ? self::get() : $settings;
		$any      = false;

		foreach ( self::administrators() as $user ) {
			if ( self::qualifies( $user, $settings ) ) {
				$any = true;
				break;
			}
		}

		if ( $cache ) {
			self::$any = $any;
		}

		return $any;
	}

	/**
	 * The users who can manage options: the candidates for agency accounts.
	 *
	 * @return WP_User[]
	 */
	public static function administrators() {
		return get_users(
			array(
				'capability' => 'manage_options',
				'orderby'    => 'login',
				'number'     => 200,
			)
		);
	}

	/**
	 * Clean a list of email domains: lowercase, no "@", valid host names only.
	 *
	 * @param string|string[] $raw A list, or one string separated by commas, spaces or new lines.
	 * @return string[]
	 */
	public static function sanitize_domains( $raw ) {
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/[\s,;]+/', $raw );
		}

		$clean = array();

		foreach ( (array) $raw as $domain ) {
			$domain = strtolower( trim( (string) $domain ) );
			$domain = ltrim( $domain, '@' );

			if ( preg_match( '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $domain ) && ! in_array( $domain, $clean, true ) ) {
				$clean[] = $domain;
			}
		}

		return $clean;
	}
}
