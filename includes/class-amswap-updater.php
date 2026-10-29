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
 * admin-menu-swap.zip asset, which the release workflow builds. Updates then
 * show under Dashboard → Updates like any other plugin's, auto-updates
 * included. Copies built into site plugins have no plugin header of their
 * own, so WordPress never asks about them.
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
	 * Site transient caching the latest release.
	 */
	const CACHE = 'amswap_latest_release';

	/**
	 * Hook into the update check and the plugin details popup.
	 */
	public function register() {
		add_filter( 'update_plugins_github.com', array( $this, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( $this, 'details' ), 10, 3 );
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
		unset( $plugin_file );

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
			'requires'     => '6.0',
			'requires_php' => '7.4',
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
			'requires'      => '6.0',
			'requires_php'  => '7.4',
			'sections'      => array(
				'changelog' => wpautop( esc_html( $release['notes'] ) ),
			),
		);
	}

	/**
	 * The latest release, cached for 12 hours (a failed lookup for 1).
	 * "Check again" under Dashboard → Updates skips the cache.
	 *
	 * @return array|null { version, package, url, notes, published }, or null.
	 */
	public static function latest_release() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: only skips a cache.
		$force  = is_admin() && isset( $_GET['force-check'] );
		$cached = $force ? false : get_site_transient( self::CACHE );

		if ( is_array( $cached ) ) {
			return '' === $cached['version'] ? null : $cached;
		}

		$release  = null;
		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPOSITORY . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'Admin-Menu-Swap/' . AMSWAP_VERSION . '; ' . home_url( '/' ),
				),
			)
		);

		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$body    = json_decode( wp_remote_retrieve_body( $response ), true );
			$version = is_array( $body ) && isset( $body['tag_name'] ) ? ltrim( (string) $body['tag_name'], 'vV' ) : '';
			$package = '';

			foreach ( ( is_array( $body ) && isset( $body['assets'] ) && is_array( $body['assets'] ) ) ? $body['assets'] : array() as $asset ) {
				if ( isset( $asset['name'], $asset['browser_download_url'] ) && self::ASSET === $asset['name'] ) {
					$package = (string) $asset['browser_download_url'];
				}
			}

			if ( preg_match( '/^\d+(\.\d+){1,3}$/', $version ) && '' !== $package ) {
				$release = array(
					'version'   => $version,
					'package'   => $package,
					'url'       => isset( $body['html_url'] ) ? (string) $body['html_url'] : self::UPDATE_URI,
					'notes'     => isset( $body['body'] ) ? (string) $body['body'] : '',
					'published' => isset( $body['published_at'] ) ? (string) $body['published_at'] : '',
				);
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
}
