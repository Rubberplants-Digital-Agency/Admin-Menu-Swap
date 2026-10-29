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
 * administrator counts as one, so a site can never lock everybody out. On a
 * multisite with super admins that fallback never applies: a super admin can
 * always reach the page, so a subsite's own administrators stay clients.
 *
 * Agency accounts keep the menu settings out of clients' everyday reach.
 * They are not a security boundary against other administrators: any
 * administrator can add a user on an agency domain, change an email address
 * or deactivate the plugin.
 */
class AMSwap_Agency {

	/**
	 * Option holding the agency domains and picked user IDs. Not autoloaded.
	 */
	const OPTION = 'amswap_agency';

	/**
	 * Transient caching whether any account qualifies: '1' or '0', so a
	 * cached false is told apart from a miss, then ':' and a hash of the
	 * settings the answer belongs to.
	 */
	const ANY_TRANSIENT = 'amswap_any_agency';

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
	 * Forget the cached answer whenever an account could start or stop
	 * qualifying. Registered everywhere, not only in wp-admin, as users are
	 * also added and changed on the front end, over REST and from WP-CLI.
	 */
	public static function register() {
		$hooks = array(
			'profile_update',
			'user_register',
			'deleted_user',
			'set_user_role',
			'add_user_role',
			'remove_user_role',
			'add_user_to_blog',
			'remove_user_from_blog',
			'granted_super_admin',
			'revoked_super_admin',
			// The option written without save(), e.g. by wp option update.
			'add_option_' . self::OPTION,
			'update_option_' . self::OPTION,
			'delete_option_' . self::OPTION,
		);

		foreach ( $hooks as $hook ) {
			add_action( $hook, array( __CLASS__, 'forget' ) );
		}
	}

	/**
	 * Drop the cached answer of any_agency_account().
	 */
	public static function forget() {
		self::$any = null;

		delete_transient( self::ANY_TRANSIENT );
	}

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
		self::forget();

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
	 * Whether at least one administrator qualifies by domain or by being
	 * picked. Always true on a multisite with super admins.
	 *
	 * is_agency() asks this on every admin page of an administrator who does
	 * not qualify, so the answer for the saved settings is kept in a
	 * transient as well as per request.
	 *
	 * @param array|null $settings Settings to test, default the saved ones.
	 * @return bool
	 */
	public static function any_agency_account( $settings = null ) {
		// A super admin can always reach the page, so the fallback must not
		// hand the settings to every administrator of a subsite.
		if ( is_multisite() && ! empty( get_super_admins() ) ) {
			return true;
		}

		if ( null !== $settings ) {
			return self::find_agency_account( $settings );
		}

		if ( null !== self::$any ) {
			return self::$any;
		}

		// The answer is stored with a hash of the settings it was worked out
		// for, so a changed amswap_agency_domains filter is seen at once.
		$settings = self::get();
		$hash     = md5( (string) wp_json_encode( $settings ) );
		$cached   = explode( ':', (string) get_transient( self::ANY_TRANSIENT ), 2 );

		if ( 2 === count( $cached ) && $hash === $cached[1] && ( '1' === $cached[0] || '0' === $cached[0] ) ) {
			self::$any = '1' === $cached[0];

			return self::$any;
		}

		self::$any = self::find_agency_account( $settings );

		// Only an hour: capability changes that fire none of the hooks in
		// register() (a role editor, add_cap()) are picked up within it.
		set_transient( self::ANY_TRANSIENT, ( self::$any ? '1' : '0' ) . ':' . $hash, HOUR_IN_SECONDS );

		return self::$any;
	}

	/**
	 * Look for an administrator who qualifies, without caching. Picked users
	 * are checked one by one and each domain with a single query, so the
	 * answer does not depend on how many administrators the site has.
	 *
	 * @param array $settings Settings from get().
	 * @return bool
	 */
	protected static function find_agency_account( $settings ) {
		foreach ( $settings['users'] as $user_id ) {
			$user = get_userdata( (int) $user_id );

			if ( $user instanceof WP_User && user_can( $user, 'manage_options' ) ) {
				return true;
			}
		}

		foreach ( $settings['domains'] as $domain ) {
			$ids = get_users(
				array(
					'capability'     => 'manage_options',
					'search'         => '*@' . $domain,
					'search_columns' => array( 'user_email' ),
					'number'         => 1,
					'fields'         => 'ID',
				)
			);

			// The search is a LIKE match; qualifies() has the final say.
			$user = empty( $ids ) ? false : get_userdata( (int) reset( $ids ) );

			if ( $user instanceof WP_User && self::qualifies( $user, $settings ) ) {
				return true;
			}
		}

		return false;
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
