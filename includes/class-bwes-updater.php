<?php
/**
 * Automatic updates from GitHub releases.
 *
 * Two modes, chosen automatically:
 *
 *  - PROXY (default for customers): the plugin asks the vendor endpoint on
 *    blocksocial.eu for the latest version, sending the license key. The server
 *    validates the purchase and returns version info plus a license-gated
 *    download URL that it streams from the (private) GitHub release. No GitHub
 *    token is ever shipped to the client, and the repository stays private.
 *
 *  - DIRECT GITHUB (for the vendor's own sites / public releases): set the
 *    constant BWES_GITHUB_TOKEN (and optionally BWES_GITHUB_OWNER / _REPO) and
 *    the plugin talks to the GitHub API directly, attaching the token only to
 *    requests aimed at GitHub.
 *
 * Everything is overridable via constants or the `bwes_update_config` filter,
 * and all remote calls fail safe (no update offered rather than a broken one).
 *
 * @package BlockSocial\WooExtraServices
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin update checker.
 */
class BWES_Updater {

	const CACHE_KEY   = 'bwes_update_info';
	const CACHE_HOURS = 6;

	/**
	 * License handler.
	 *
	 * @var BWES_License
	 */
	private $license;

	/**
	 * Plugin basename (folder/file.php).
	 *
	 * @var string
	 */
	private $basename;

	/**
	 * Plugin slug (folder).
	 *
	 * @var string
	 */
	private $slug = 'blocksocial-woo-services';

	/**
	 * Constructor.
	 *
	 * @param BWES_License $license License handler.
	 */
	public function __construct( BWES_License $license ) {
		$this->license  = $license;
		$this->basename = BWES_PLUGIN_BASENAME;
	}

	/**
	 * Register update hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
		add_filter( 'http_request_args', array( $this, 'maybe_authorize_github' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( $this, 'flush_cache' ), 10, 0 );
	}

	/* ------------------------------------------------------------------ *
	 * Configuration
	 * ------------------------------------------------------------------ */

	/**
	 * Resolve the merged configuration.
	 *
	 * @return array
	 */
	public static function config() {
		$config = array(
			'proxy_base'   => defined( 'BWES_UPDATE_PROXY_URL' ) ? BWES_UPDATE_PROXY_URL : 'https://blocksocial.eu/wp-json/bwes/v1',
			'github_owner' => defined( 'BWES_GITHUB_OWNER' ) ? BWES_GITHUB_OWNER : 'Zauni1984',
			'github_repo'  => defined( 'BWES_GITHUB_REPO' ) ? BWES_GITHUB_REPO : 'blocksocial-woo-services',
			'github_token' => defined( 'BWES_GITHUB_TOKEN' ) ? BWES_GITHUB_TOKEN : '',
			'mode'         => defined( 'BWES_UPDATE_MODE' ) ? BWES_UPDATE_MODE : '', // '', 'proxy' or 'github'.
		);

		/**
		 * Filter the update configuration.
		 *
		 * @param array $config Update config.
		 */
		return apply_filters( 'bwes_update_config', $config );
	}

	/**
	 * Determine the active update mode.
	 *
	 * @return string 'proxy' | 'github' | 'none'
	 */
	public static function mode() {
		$config = self::config();

		if ( 'github' === $config['mode'] || 'proxy' === $config['mode'] ) {
			return $config['mode'];
		}
		if ( '' !== $config['github_token'] ) {
			return 'github';
		}
		if ( '' !== $config['proxy_base'] ) {
			return 'proxy';
		}

		return 'none';
	}

	/**
	 * Build a proxy endpoint URL for the given route.
	 *
	 * @param string $route Route suffix (e.g. 'update', 'license', 'download').
	 * @return string
	 */
	public static function proxy_url( $route ) {
		$config = self::config();
		if ( '' === $config['proxy_base'] ) {
			return '';
		}

		return trailingslashit( $config['proxy_base'] ) . ltrim( $route, '/' );
	}

	/* ------------------------------------------------------------------ *
	 * Remote info
	 * ------------------------------------------------------------------ */

	/**
	 * Fetch (and cache) the latest release info in a normalized shape.
	 *
	 * @param bool $force Bypass cache.
	 * @return array|null { version, package, requires, tested, requires_php, changelog, homepage, last_updated } or null.
	 */
	public function get_remote_info( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$mode = self::mode();
		if ( 'proxy' === $mode ) {
			$info = $this->fetch_from_proxy();
		} elseif ( 'github' === $mode ) {
			$info = $this->fetch_from_github();
		} else {
			$info = null;
		}

		// Cache both hits and misses (misses briefly) to avoid hammering.
		set_transient( self::CACHE_KEY, null === $info ? array() : $info, self::CACHE_HOURS * HOUR_IN_SECONDS );

		return ( is_array( $info ) && ! empty( $info ) ) ? $info : null;
	}

	/**
	 * Query the vendor proxy for license-gated update info.
	 *
	 * @return array|null
	 */
	private function fetch_from_proxy() {
		$endpoint = self::proxy_url( 'update' );
		if ( '' === $endpoint ) {
			return null;
		}

		$response = wp_safe_remote_post(
			$endpoint,
			array(
				'timeout' => 12,
				'body'    => array(
					'license'  => $this->license->get_key(),
					'site_url' => home_url( '/' ),
					'slug'     => $this->slug,
					'version'  => BWES_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['version'] ) || empty( $data['package'] ) ) {
			return null;
		}

		return $this->normalize_info( $data );
	}

	/**
	 * Query the GitHub releases API directly (vendor/public mode).
	 *
	 * @return array|null
	 */
	private function fetch_from_github() {
		$config = self::config();
		$url    = sprintf(
			'https://api.github.com/repos/%s/%s/releases/latest',
			rawurlencode( $config['github_owner'] ),
			rawurlencode( $config['github_repo'] )
		);

		$headers = array(
			'Accept'               => 'application/vnd.github+json',
			'X-GitHub-Api-Version' => '2022-11-28',
			'User-Agent'           => 'BlockSocial-WooExtraServices/' . BWES_VERSION,
		);
		if ( '' !== $config['github_token'] ) {
			$headers['Authorization'] = 'Bearer ' . $config['github_token'];
		}

		$response = wp_safe_remote_get( $url, array( 'timeout' => 12, 'headers' => $headers ) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$release = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $release ) || empty( $release['tag_name'] ) ) {
			return null;
		}

		// Prefer a packaged .zip asset; fall back to the auto zipball.
		$package = '';
		if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				if ( isset( $asset['name'], $asset['url'] ) && preg_match( '/\.zip$/i', $asset['name'] ) ) {
					// Asset API URL works for private repos when combined with the auth header.
					$package = $asset['url'];
					break;
				}
			}
		}
		if ( '' === $package && ! empty( $release['zipball_url'] ) ) {
			$package = $release['zipball_url'];
		}
		if ( '' === $package ) {
			return null;
		}

		return $this->normalize_info(
			array(
				'version'      => $release['tag_name'],
				'package'      => $package,
				'changelog'    => isset( $release['body'] ) ? $release['body'] : '',
				'last_updated' => isset( $release['published_at'] ) ? $release['published_at'] : '',
			)
		);
	}

	/**
	 * Normalize a raw info array into the canonical shape.
	 *
	 * @param array $data Raw data.
	 * @return array
	 */
	private function normalize_info( array $data ) {
		return array(
			'version'      => ltrim( sanitize_text_field( (string) $data['version'] ), 'vV' ),
			'package'      => esc_url_raw( (string) $data['package'] ),
			'requires'     => isset( $data['requires'] ) ? sanitize_text_field( $data['requires'] ) : '6.0',
			'tested'       => isset( $data['tested'] ) ? sanitize_text_field( $data['tested'] ) : '',
			'requires_php' => isset( $data['requires_php'] ) ? sanitize_text_field( $data['requires_php'] ) : '7.4',
			'changelog'    => isset( $data['changelog'] ) ? wp_kses_post( $data['changelog'] ) : '',
			'homepage'     => isset( $data['homepage'] ) ? esc_url_raw( $data['homepage'] ) : 'https://blocksocial.eu',
			'last_updated' => isset( $data['last_updated'] ) ? sanitize_text_field( $data['last_updated'] ) : '',
		);
	}

	/* ------------------------------------------------------------------ *
	 * WordPress update integration
	 * ------------------------------------------------------------------ */

	/**
	 * Inject our update into the update-plugins transient.
	 *
	 * @param mixed $transient Update transient.
	 * @return mixed
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$info = $this->get_remote_info();
		if ( null === $info || '' === $info['package'] ) {
			return $transient;
		}

		if ( version_compare( $info['version'], BWES_VERSION, '>' ) ) {
			$item = array(
				'slug'        => $this->slug,
				'plugin'      => $this->basename,
				'new_version' => $info['version'],
				'url'         => $info['homepage'],
				'package'     => $info['package'],
				'tested'      => $info['tested'],
				'requires'    => $info['requires'],
				'requires_php' => $info['requires_php'],
			);
			$transient->response[ $this->basename ] = (object) $item;
		} else {
			// Record that we are up to date (helps the "no_update" list).
			$transient->no_update[ $this->basename ] = (object) array(
				'slug'        => $this->slug,
				'plugin'      => $this->basename,
				'new_version' => BWES_VERSION,
				'url'         => $info['homepage'],
				'package'     => '',
			);
		}

		return $transient;
	}

	/**
	 * Provide the "View details" modal content.
	 *
	 * @param false|object|array $result Result.
	 * @param string             $action Action.
	 * @param object             $args   Args.
	 * @return false|object|array
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$info = $this->get_remote_info();
		if ( null === $info ) {
			return $result;
		}

		return (object) array(
			'name'          => 'BlockSocial WooCommerce Extra Services',
			'slug'          => $this->slug,
			'version'       => $info['version'],
			'author'        => '<a href="https://blocksocial.eu">BlockSocial UG (haftungsbeschränkt)</a>',
			'homepage'      => $info['homepage'],
			'requires'      => $info['requires'],
			'tested'        => $info['tested'],
			'requires_php'  => $info['requires_php'],
			'last_updated'  => $info['last_updated'],
			'sections'      => array(
				'changelog' => '' !== $info['changelog'] ? wpautop( $info['changelog'] ) : __( 'See the release notes on blocksocial.eu.', 'bwes' ),
			),
		);
	}

	/**
	 * GitHub (and some asset hosts) extract to a folder named after the repo and
	 * commit; rename it back to the plugin slug so the update lands in place.
	 *
	 * @param string      $source        Extracted source dir.
	 * @param string      $remote_source Remote source dir.
	 * @param WP_Upgrader $upgrader      Upgrader.
	 * @param array       $args          Args.
	 * @return string|WP_Error
	 */
	public function fix_source_dir( $source, $remote_source, $upgrader, $args = array() ) {
		global $wp_filesystem;

		if ( ! isset( $args['plugin'] ) || $args['plugin'] !== $this->basename ) {
			return $source;
		}
		if ( ! $wp_filesystem ) {
			return $source;
		}

		$desired = trailingslashit( $remote_source ) . $this->slug;
		$source  = untrailingslashit( $source );

		if ( $source === untrailingslashit( $desired ) ) {
			return trailingslashit( $desired );
		}

		if ( $wp_filesystem->move( $source, $desired, true ) ) {
			return trailingslashit( $desired );
		}

		return new WP_Error( 'bwes_rename_failed', __( 'Could not prepare the update package.', 'bwes' ) );
	}

	/**
	 * Attach the GitHub token to GitHub-bound requests only (direct mode,
	 * private repositories). Never adds the header to any other host.
	 *
	 * @param array  $args HTTP args.
	 * @param string $url  Request URL.
	 * @return array
	 */
	public function maybe_authorize_github( $args, $url ) {
		$config = self::config();
		if ( '' === $config['github_token'] ) {
			return $args;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( 'api.github.com' !== $host ) {
			return $args;
		}

		if ( ! isset( $args['headers'] ) || ! is_array( $args['headers'] ) ) {
			$args['headers'] = array();
		}
		$args['headers']['Authorization'] = 'Bearer ' . $config['github_token'];
		// Asset downloads require the octet-stream accept header.
		if ( false !== strpos( $url, '/releases/assets/' ) ) {
			$args['headers']['Accept'] = 'application/octet-stream';
		}

		return $args;
	}

	/**
	 * Flush the cached remote info (after updates, or on demand).
	 *
	 * @return void
	 */
	public function flush_cache() {
		delete_transient( self::CACHE_KEY );
	}
}
