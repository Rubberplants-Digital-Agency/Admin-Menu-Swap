<?php
/**
 * Updates from GitHub releases, through WordPress' own update system.
 *
 * @package Admin_Menu_Swap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Offers new versions of the plugin from its GitHub releases.
 *
 * The plugin header names the repository as its Update URI, so WordPress
 * (5.8+) asks the update_plugins_github.com filter instead of
 * wordpress.org. The answer comes from the latest release and its
 * admin-menu-swap.zip and info.json assets, which the release workflow
 * builds. Updates then show under Dashboard → Updates like any other
 * plugin's, auto-updates included.
 *
 * Only admin-menu-swap/admin-menu-swap.php is ever answered. Updating
 * replaces the plugin's whole folder, so a copy anywhere else must not be
 * offered one: a copy built into a site plugin would take that plugin's
 * folder with it, and an install from GitHub's "Source code" zip
 * (Admin-Menu-Swap-main/) would be moved and deactivated. Copies built into
 * site plugins must therefore sit two folders deep
 * (site-plugin/admin-menu-swap/admin-menu-swap.php), where get_plugins()
 * does not look.
 */
class AMSwap_Updater {

	/**
	 * The repository on GitHub.
	 */
	const REPOSITORY = 'Rubberplants-Digital-Agency/Admin-Menu-Swap';

	/**
	 * The Update URI in the plugin header.
	 */
	const UPDATE_URI = 'https://github.com/Rubberplants-Digital-Agency/Admin-Menu-Swap';

	/**
	 * The release asset WordPress installs.
	 */
	const ASSET = 'admin-menu-swap.zip';

	/**
	 * The release asset with the requirements, tested version and changelog.
	 */
	const INFO = 'info.json';

	/**
	 * The account that uploads the assets: the release workflow's token.
	 */
	const UPLOADER = 'github-actions[bot]';

	/**
	 * Site transient caching the latest release.
	 */
	const CACHE = 'amswap_latest_release';

	/**
	 * The most of the changelog kept, in bytes.
	 */
	const CHANGELOG_MAX = 100000;

	/**
	 * Hook into the update check, the plugin details popup and the downloads.
	 */
	public function register() {
		add_filter( 'update_plugins_github.com', array( $this, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( $this, 'details' ), 10, 3 );
		add_filter( 'http_request_args', array( $this, 'request_args' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'forget' ) );
	}

	/**
	 * Answer WordPress' update check for this plugin.
	 *
	 * @param array|false $update      Update data, false when nobody answered yet.
	 * @param array       $plugin_data The plugin's header data.
	 * @param string      $plugin_file The plugin's basename.
	 * @return array|false
	 */
	public function check( $update, $plugin_data, $plugin_file ) {
		// Any other file with this Update URI is a copy inside another
		// folder, which updating would replace (see the class comment).
		if ( AMSWAP_SLUG . '/admin-menu-swap.php' !== $plugin_file ) {
			return $update;
		}

		if ( ! isset( $plugin_data['UpdateURI'] ) || self::UPDATE_URI !== untrailingslashit( $plugin_data['UpdateURI'] ) ) {
			return $update;
		}

		$release = self::latest_release();

		if ( null === $release ) {
			return $update;
		}

		return array(
			'slug'         => AMSWAP_SLUG,
			'version'      => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'requires'     => $release['requires'],
			'requires_php' => $release['requires_php'],
			'tested'       => self::tested( $release['tested'] ),
		);
	}

	/**
	 * Fill the "View details" popup, which would otherwise ask wordpress.org.
	 *
	 * @param false|object|array $result The result so far.
	 * @param string             $action The plugins_api action.
	 * @param object             $args   Its arguments.
	 * @return false|object|array
	 */
	public function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || AMSWAP_SLUG !== $args->slug ) {
			return $result;
		}

		$release = self::latest_release();

		if ( null === $release ) {
			return $result;
		}

		return (object) array(
			'name'          => 'Admin Menu Swap',
			'slug'          => AMSWAP_SLUG,
			'version'       => $release['version'],
			'author'        => '<a href="https://rubberplants.nl/">Rubberplants</a>',
			'homepage'      => self::UPDATE_URI,
			'download_link' => $release['package'],
			'last_updated'  => $release['published'],
			'requires'      => $release['requires'],
			'requires_php'  => $release['requires_php'],
			'tested'        => self::tested( $release['tested'] ),
			'sections'      => array(
				'changelog' => self::changelog_html( $release['changelog'] ),
			),
		);
	}

	/**
	 * Send only the plugin's name and version as the user agent when
	 * WordPress downloads a release asset. Its own user agent carries the
	 * site's URL, which GitHub has no need for.
	 *
	 * @param array  $args The request arguments.
	 * @param string $url  The URL requested.
	 * @return array
	 */
	public function request_args( $args, $url ) {
		if ( is_array( $args ) && is_string( $url ) && 0 === strpos( $url, self::download_prefix() ) ) {
			$args['user-agent'] = self::user_agent();
		}

		return $args;
	}

	/**
	 * The latest release, cached for 12 hours (a failed lookup for 1).
	 * "Check again" under Dashboard → Updates skips the cache.
	 *
	 * @return array|null { version, package, url, published, requires, requires_php, tested, changelog }, or null.
	 */
	public static function latest_release() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: only skips a cache.
		$force  = is_admin() && isset( $_GET['force-check'] );
		$cached = $force ? false : get_site_transient( self::CACHE );

		if ( is_array( $cached ) && isset( $cached['version'] ) ) {
			if ( '' === $cached['version'] ) {
				return null;
			}

			// A release cached by 1.6.0 lacks the info.json fields: look again.
			if ( isset( $cached['package'], $cached['url'], $cached['published'], $cached['requires'], $cached['requires_php'], $cached['tested'], $cached['changelog'] ) ) {
				return $cached;
			}
		}

		$release  = null;
		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPOSITORY . '/releases/latest',
			array(
				'timeout' => self::timeout(),
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					// Explicit, as WordPress' default user agent carries the site's URL.
					'User-Agent' => self::user_agent(),
				),
			)
		);

		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$body    = json_decode( wp_remote_retrieve_body( $response ), true );
			$version = is_array( $body ) && isset( $body['tag_name'] ) ? ltrim( (string) $body['tag_name'], 'vV' ) : '';
			$assets  = is_array( $body ) && isset( $body['assets'] ) && is_array( $body['assets'] ) ? $body['assets'] : array();
			$package = self::asset_url( $assets, self::ASSET );
			$info    = self::asset_url( $assets, self::INFO );

			if ( preg_match( '/^\d+(\.\d+){1,3}$/D', $version ) && '' !== $package && '' !== $info ) {
				$info = self::fetch_info( $info, $version );

				// Without valid requirements there is no update: guessing them
				// could install a version the site cannot run.
				if ( null !== $info ) {
					$release = array_merge(
						array(
							'version'   => $version,
							'package'   => $package,
							'url'       => isset( $body['html_url'] ) ? (string) $body['html_url'] : self::UPDATE_URI,
							'published' => isset( $body['published_at'] ) ? (string) $body['published_at'] : '',
						),
						$info
					);
				}
			}
		}

		set_site_transient( self::CACHE, null === $release ? array( 'version' => '' ) : $release, null === $release ? HOUR_IN_SECONDS : 12 * HOUR_IN_SECONDS );

		return $release;
	}

	/**
	 * Drop the cached release after an update, so the next check is fresh.
	 */
	public static function forget() {
		delete_site_transient( self::CACHE );
	}

	/**
	 * The download URL of a release asset the release workflow uploaded.
	 *
	 * Anyone with write access can attach a file to a release by hand; only
	 * the workflow's uploads come from the tagged commit on main, so those
	 * are the only ones accepted. The URL must also point into this
	 * repository's releases, so the API cannot send WordPress elsewhere.
	 *
	 * @param array  $assets The release's assets.
	 * @param string $name   The asset's file name.
	 * @return string The URL, or '' when there is no such asset.
	 */
	protected static function asset_url( $assets, $name ) {
		foreach ( $assets as $asset ) {
			if (
				isset( $asset['name'], $asset['browser_download_url'], $asset['uploader']['login'] )
				&& $name === $asset['name']
				&& self::UPLOADER === $asset['uploader']['login']
				&& is_string( $asset['browser_download_url'] )
				&& 0 === strpos( $asset['browser_download_url'], self::download_prefix() )
			) {
				return $asset['browser_download_url'];
			}
		}

		return '';
	}

	/**
	 * Fetch and check a release's info.json.
	 *
	 * @param string $url     Its download URL.
	 * @param string $version The release's version, which it must name.
	 * @return array|null { requires, requires_php, tested, changelog }, or null.
	 */
	protected static function fetch_info( $url, $version ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => self::timeout(),
				'headers' => array(
					'User-Agent' => self::user_agent(),
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$info = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $info ) || ! isset( $info['version'], $info['requires'], $info['requires_php'], $info['tested'], $info['changelog'] ) ) {
			return null;
		}

		if ( $version !== $info['version'] || ! is_string( $info['changelog'] ) ) {
			return null;
		}

		foreach ( array( 'requires', 'requires_php', 'tested' ) as $key ) {
			if ( ! is_string( $info[ $key ] ) || ! preg_match( '/^\d+(\.\d+){0,2}$/D', $info[ $key ] ) ) {
				return null;
			}
		}

		$changelog = $info['changelog'];

		// Cut an oversized changelog at a line end, never inside a character.
		if ( strlen( $changelog ) > self::CHANGELOG_MAX ) {
			$changelog = substr( $changelog, 0, self::CHANGELOG_MAX );
			$changelog = substr( $changelog, 0, (int) strrpos( $changelog, "\n" ) );
		}

		return array(
			'requires'     => $info['requires'],
			'requires_php' => $info['requires_php'],
			'tested'       => $info['tested'],
			'changelog'    => $changelog,
		);
	}

	/**
	 * The tested version to report. Tested up to 7.1 covers 7.1.x, but
	 * WordPress compares versions exactly, so on 7.1.2 report 7.1.2.
	 *
	 * @param string $tested The release's "Tested up to".
	 * @return string
	 */
	protected static function tested( $tested ) {
		$running = (string) get_bloginfo( 'version' );

		if ( $running === $tested || 0 === strpos( $running, $tested . '.' ) ) {
			return $running;
		}

		return $tested;
	}

	/**
	 * Turn readme.txt's changelog into HTML for the "View details" popup.
	 * Every line is escaped before any markup is added.
	 *
	 * @param string $text The text of the == Changelog == section.
	 * @return string
	 */
	protected static function changelog_html( $text ) {
		$html = '';
		$list = false;

		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
			$line = esc_html( trim( $line ) );
			$item = 0 === strpos( $line, '* ' );

			if ( $list && ! $item ) {
				$html .= '</ul>';
				$list  = false;
			}

			if ( '' === $line ) {
				continue;
			}

			// `code` spans, within the line so a stray backtick cannot
			// reach into the next one.
			$line = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $line );

			if ( preg_match( '/^=\s*(.+?)\s*=$/', $line, $matches ) ) {
				$html .= '<h4>' . $matches[1] . '</h4>';
			} elseif ( $item ) {
				if ( ! $list ) {
					$html .= '<ul>';
					$list  = true;
				}

				$html .= '<li>' . substr( $line, 2 ) . '</li>';
			} else {
				$html .= '<p>' . $line . '</p>';
			}
		}

		if ( $list ) {
			$html .= '</ul>';
		}

		return $html;
	}

	/**
	 * Where this repository's release assets are downloaded from.
	 *
	 * @return string
	 */
	protected static function download_prefix() {
		return 'https://github.com/' . self::REPOSITORY . '/releases/download/';
	}

	/**
	 * The user agent for requests to GitHub: no site URL.
	 *
	 * @return string
	 */
	protected static function user_agent() {
		return 'Admin-Menu-Swap/' . AMSWAP_VERSION;
	}

	/**
	 * Short in wp-admin, so an unreachable GitHub cannot hold up a page;
	 * cron has time to spare.
	 *
	 * @return int Seconds.
	 */
	protected static function timeout() {
		return wp_doing_cron() ? 10 : 3;
	}
}
