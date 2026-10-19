<?php
/**
 * License handling for the commercial distribution.
 *
 * Stores the customer's license key and a cached validation status. The key is
 * sent to the vendor update proxy (blocksocial.eu) which authorizes updates
 * against the purchase. Nothing here hard-codes a secret; the proxy keeps the
 * GitHub token server-side.
 *
 * @package BlockSocial\WooExtraServices
 */

defined( 'ABSPATH' ) || exit;

/**
 * License key storage + validation client.
 */
class BWES_License {

	const OPTION      = 'bwes_license';
	const NONCE       = 'bwes_license';
	const CACHE_HOURS = 12;

	/**
	 * Get the stored license record.
	 *
	 * @return array{key:string,status:string,expires:string,message:string,last_check:int}
	 */
	public function get_record() {
		$defaults = array(
			'key'        => '',
			'status'     => 'unknown', // unknown | valid | invalid | expired.
			'expires'    => '',
			'message'    => '',
			'last_check' => 0,
		);
		$stored = get_option( self::OPTION, array() );

		return wp_parse_args( is_array( $stored ) ? $stored : array(), $defaults );
	}

	/**
	 * The license key.
	 *
	 * @return string
	 */
	public function get_key() {
		return (string) $this->get_record()['key'];
	}

	/**
	 * Whether the license is currently considered active.
	 *
	 * @return bool
	 */
	public function is_active() {
		return 'valid' === $this->get_record()['status'];
	}

	/**
	 * Persist a (sanitized) license key, resetting the cached status.
	 *
	 * @param string $key License key.
	 * @return void
	 */
	public function save_key( $key ) {
		$record            = $this->get_record();
		$record['key']     = $this->sanitize_key_value( $key );
		$record['status']  = 'unknown';
		$record['message'] = '';
		update_option( self::OPTION, $record );
	}

	/**
	 * Sanitize a license key (alphanumeric, dashes, underscores).
	 *
	 * @param string $key Raw key.
	 * @return string
	 */
	public function sanitize_key_value( $key ) {
		$key = is_string( $key ) ? trim( $key ) : '';

		return preg_replace( '/[^A-Za-z0-9\-_]/', '', $key );
	}

	/**
	 * Validate the license against the vendor proxy and cache the result.
	 *
	 * Fails "soft": a transport error never flips a previously valid license to
	 * invalid; it only refreshes the timestamp so the store keeps working.
	 *
	 * @param bool $force Ignore the cache window.
	 * @return array The refreshed record.
	 */
	public function validate( $force = false ) {
		$record = $this->get_record();

		if ( '' === $record['key'] ) {
			$record['status']  = 'unknown';
			$record['message'] = __( 'No license key entered.', 'bwes' );
			update_option( self::OPTION, $record );
			return $record;
		}

		if ( ! $force && $record['last_check'] > 0 && ( time() - $record['last_check'] ) < self::CACHE_HOURS * HOUR_IN_SECONDS ) {
			return $record;
		}

		$endpoint = BWES_Updater::proxy_url( 'license' );
		if ( '' === $endpoint ) {
			$record['last_check'] = time();
			update_option( self::OPTION, $record );
			return $record;
		}

		$response = wp_safe_remote_post(
			$endpoint,
			array(
				'timeout' => 10,
				'body'    => array(
					'action'   => 'validate',
					'license'  => $record['key'],
					'site_url' => home_url( '/' ),
					'slug'     => 'blocksocial-woo-services',
					'version'  => BWES_VERSION,
				),
			)
		);

		$record['last_check'] = time();

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			// Soft-fail: keep the previous status, note we could not reach the server.
			$record['message'] = __( 'Could not reach the license server. Will retry later.', 'bwes' );
			update_option( self::OPTION, $record );
			return $record;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( is_array( $data ) ) {
			$record['status']  = isset( $data['status'] ) ? sanitize_key( $data['status'] ) : 'unknown';
			$record['expires'] = isset( $data['expires'] ) ? sanitize_text_field( $data['expires'] ) : '';
			$record['message'] = isset( $data['message'] ) ? sanitize_text_field( $data['message'] ) : '';
		}

		update_option( self::OPTION, $record );

		return $record;
	}
}
