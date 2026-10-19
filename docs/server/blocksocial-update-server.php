<?php
/**
 * Plugin Name:       BlockSocial Update Server (for Extra Services)
 * Description:        VENDOR-SIDE companion for blocksocial.eu. Serves license-gated
 *                     automatic updates for "BlockSocial WooCommerce Extra Services"
 *                     by fetching releases from a PRIVATE GitHub repo with a
 *                     server-side token. Install this on blocksocial.eu only.
 * Version:           1.0.0
 * Author:            BlockSocial UG (haftungsbeschränkt)
 * License:           GPL-2.0-or-later
 *
 * ---------------------------------------------------------------------------
 * REFERENCE IMPLEMENTATION. Do NOT ship this file inside the customer plugin.
 *
 * Configure via wp-config.php on blocksocial.eu:
 *
 *   define( 'BWES_SRV_GH_OWNER', 'Zauni1984' );
 *   define( 'BWES_SRV_GH_REPO',  'blocksocial-woo-services' );
 *   define( 'BWES_SRV_GH_TOKEN', 'ghp_xxx' );   // fine-grained token, Contents: read
 *   define( 'BWES_SRV_SECRET',   'a-long-random-string' ); // signs download links
 *
 * License validation defaults to "License Manager for WooCommerce" if active;
 * override with the `bwes_server_license_status` filter to plug in any system.
 * ---------------------------------------------------------------------------
 *
 * @package BlockSocial\UpdateServer
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'rest_api_init',
	static function () {
		$ns = 'bwes/v1';

		register_rest_route(
			$ns,
			'/update',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => 'bwes_srv_update',
			)
		);

		register_rest_route(
			$ns,
			'/license',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => 'bwes_srv_license',
			)
		);

		register_rest_route(
			$ns,
			'/download',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => 'bwes_srv_download',
			)
		);
	}
);

/**
 * Validate a license key and return a normalized status.
 *
 * @param string $license  License key.
 * @param string $site_url Requesting site URL.
 * @return array{status:string,expires:string,message:string}
 */
function bwes_srv_license_status( $license, $site_url ) {
	$license = preg_replace( '/[^A-Za-z0-9\-_]/', '', (string) $license );

	$default = array(
		'status'  => 'invalid',
		'expires' => '',
		'message' => 'Unknown license.',
	);

	// Built-in support for "License Manager for WooCommerce", if present.
	if ( '' !== $license && function_exists( 'lmfwc_get_license' ) ) {
		$obj = lmfwc_get_license( $license );
		if ( $obj ) {
			$expires = method_exists( $obj, 'getExpiresAt' ) ? (string) $obj->getExpiresAt() : '';
			$expired = $expires && strtotime( $expires ) < time();
			$default = array(
				'status'  => $expired ? 'expired' : 'valid',
				'expires' => $expires,
				'message' => $expired ? 'License expired.' : 'License active.',
			);
		}
	}

	/**
	 * Override/replace the license validation.
	 *
	 * @param array  $status   {status, expires, message}
	 * @param string $license  License key.
	 * @param string $site_url Site URL.
	 */
	return apply_filters( 'bwes_server_license_status', $default, $license, $site_url );
}

/**
 * Fetch (cached) the latest GitHub release metadata.
 *
 * @return array|null { version, asset_id, asset_name, changelog, published_at } or null.
 */
function bwes_srv_latest_release() {
	$cached = get_transient( 'bwes_srv_release' );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$owner = defined( 'BWES_SRV_GH_OWNER' ) ? BWES_SRV_GH_OWNER : '';
	$repo  = defined( 'BWES_SRV_GH_REPO' ) ? BWES_SRV_GH_REPO : '';
	$token = defined( 'BWES_SRV_GH_TOKEN' ) ? BWES_SRV_GH_TOKEN : '';
	if ( '' === $owner || '' === $repo || '' === $token ) {
		return null;
	}

	$response = wp_remote_get(
		sprintf( 'https://api.github.com/repos/%s/%s/releases/latest', rawurlencode( $owner ), rawurlencode( $repo ) ),
		array(
			'timeout' => 12,
			'headers' => array(
				'Accept'               => 'application/vnd.github+json',
				'X-GitHub-Api-Version' => '2022-11-28',
				'User-Agent'           => 'BlockSocial-UpdateServer',
				'Authorization'        => 'Bearer ' . $token,
			),
		)
	);

	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return null;
	}

	$release = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $release ) || empty( $release['tag_name'] ) ) {
		return null;
	}

	$asset_id   = 0;
	$asset_name = '';
	if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
		foreach ( $release['assets'] as $asset ) {
			if ( isset( $asset['name'], $asset['id'] ) && preg_match( '/\.zip$/i', $asset['name'] ) ) {
				$asset_id   = (int) $asset['id'];
				$asset_name = (string) $asset['name'];
				break;
			}
		}
	}
	if ( 0 === $asset_id ) {
		return null;
	}

	$info = array(
		'version'      => ltrim( (string) $release['tag_name'], 'vV' ),
		'asset_id'     => $asset_id,
		'asset_name'   => $asset_name,
		'changelog'    => isset( $release['body'] ) ? (string) $release['body'] : '',
		'published_at' => isset( $release['published_at'] ) ? (string) $release['published_at'] : '',
	);

	set_transient( 'bwes_srv_release', $info, 10 * MINUTE_IN_SECONDS );

	return $info;
}

/**
 * Sign a download token for a license + version.
 *
 * @param string $license License key.
 * @param string $version Version.
 * @param int    $expiry  Unix expiry.
 * @return string
 */
function bwes_srv_sign( $license, $version, $expiry ) {
	$secret = defined( 'BWES_SRV_SECRET' ) ? BWES_SRV_SECRET : wp_salt( 'auth' );

	return hash_hmac( 'sha256', $license . '|' . $version . '|' . $expiry, $secret );
}

/**
 * POST /update — return version info + a license-gated download URL.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function bwes_srv_update( WP_REST_Request $request ) {
	$license  = (string) $request->get_param( 'license' );
	$site_url = esc_url_raw( (string) $request->get_param( 'site_url' ) );

	$status = bwes_srv_license_status( $license, $site_url );
	if ( 'valid' !== $status['status'] ) {
		return new WP_REST_Response(
			array(
				'status'  => $status['status'],
				'message' => $status['message'],
			),
			200
		);
	}

	$release = bwes_srv_latest_release();
	if ( null === $release ) {
		return new WP_REST_Response( array( 'message' => 'No release available.' ), 200 );
	}

	$expiry   = time() + HOUR_IN_SECONDS;
	$token    = bwes_srv_sign( $license, $release['version'], $expiry );
	$download = add_query_arg(
		array(
			'license' => rawurlencode( $license ),
			'v'       => rawurlencode( $release['version'] ),
			'e'       => $expiry,
			't'       => $token,
		),
		rest_url( 'bwes/v1/download' )
	);

	return new WP_REST_Response(
		array(
			'version'      => $release['version'],
			'package'      => $download,
			'requires'     => '6.0',
			'tested'       => '6.7',
			'requires_php' => '7.4',
			'homepage'     => 'https://blocksocial.eu',
			'last_updated' => $release['published_at'],
			'changelog'    => $release['changelog'],
			'license'      => $status,
		),
		200
	);
}

/**
 * POST /license — validate a license key only.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function bwes_srv_license( WP_REST_Request $request ) {
	$status = bwes_srv_license_status(
		(string) $request->get_param( 'license' ),
		esc_url_raw( (string) $request->get_param( 'site_url' ) )
	);

	return new WP_REST_Response( $status, 200 );
}

/**
 * GET /download — verify the signed token + license, then redirect to a
 * short-lived GitHub asset URL fetched with the server token.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|void
 */
function bwes_srv_download( WP_REST_Request $request ) {
	$license = (string) $request->get_param( 'license' );
	$version = (string) $request->get_param( 'v' );
	$expiry  = (int) $request->get_param( 'e' );
	$token   = (string) $request->get_param( 't' );

	if ( $expiry < time() || ! hash_equals( bwes_srv_sign( $license, $version, $expiry ), $token ) ) {
		return new WP_REST_Response( array( 'message' => 'Invalid or expired link.' ), 403 );
	}
	if ( 'valid' !== bwes_srv_license_status( $license, '' )['status'] ) {
		return new WP_REST_Response( array( 'message' => 'License not active.' ), 403 );
	}

	$release = bwes_srv_latest_release();
	if ( null === $release ) {
		return new WP_REST_Response( array( 'message' => 'No release available.' ), 404 );
	}

	$owner = defined( 'BWES_SRV_GH_OWNER' ) ? BWES_SRV_GH_OWNER : '';
	$repo  = defined( 'BWES_SRV_GH_REPO' ) ? BWES_SRV_GH_REPO : '';
	$tok   = defined( 'BWES_SRV_GH_TOKEN' ) ? BWES_SRV_GH_TOKEN : '';

	// Ask GitHub for the asset; with Accept: octet-stream it 302-redirects to a
	// short-lived signed URL. Capture that Location and hand it to the client.
	$response = wp_remote_get(
		sprintf(
			'https://api.github.com/repos/%s/%s/releases/assets/%d',
			rawurlencode( $owner ),
			rawurlencode( $repo ),
			$release['asset_id']
		),
		array(
			'timeout'     => 15,
			'redirection' => 0,
			'headers'     => array(
				'Accept'               => 'application/octet-stream',
				'X-GitHub-Api-Version' => '2022-11-28',
				'User-Agent'           => 'BlockSocial-UpdateServer',
				'Authorization'        => 'Bearer ' . $tok,
			),
		)
	);

	$location = is_wp_error( $response ) ? '' : wp_remote_retrieve_header( $response, 'location' );
	if ( '' === $location ) {
		return new WP_REST_Response( array( 'message' => 'Could not resolve the package.' ), 502 );
	}

	wp_redirect( esc_url_raw( $location ), 302 );
	exit;
}
