<?php
/**
 * Geolocation helper for the local radius delivery module.
 *
 * Geocodes an address to coordinates (cached) and computes the great-circle
 * distance to the store origin. Uses the free OpenStreetMap Nominatim service
 * by default; results are cached in transients to respect rate limits.
 *
 * The module fails "open but free": if a distance cannot be determined, no fee
 * is added rather than blocking checkout.
 *
 * @package BlockSocial\WooExtraServices
 */

defined( 'ABSPATH' ) || exit;

/**
 * Distance calculation and geocoding.
 */
class BWES_Geolocation {

	const CACHE_GROUP = 'bwes_geo_';

	/**
	 * Compute the Haversine distance (km) between two lat/lng pairs.
	 *
	 * @param float $lat1 Origin latitude.
	 * @param float $lng1 Origin longitude.
	 * @param float $lat2 Destination latitude.
	 * @param float $lng2 Destination longitude.
	 * @return float Distance in kilometres.
	 */
	public static function haversine( $lat1, $lng1, $lat2, $lng2 ) {
		$earth = 6371.0088; // km.

		$lat1 = deg2rad( (float) $lat1 );
		$lng1 = deg2rad( (float) $lng1 );
		$lat2 = deg2rad( (float) $lat2 );
		$lng2 = deg2rad( (float) $lng2 );

		$d_lat = $lat2 - $lat1;
		$d_lng = $lng2 - $lng1;

		$a = sin( $d_lat / 2 ) ** 2 + cos( $lat1 ) * cos( $lat2 ) * sin( $d_lng / 2 ) ** 2;
		$c = 2 * asin( min( 1.0, sqrt( $a ) ) );

		return $earth * $c;
	}

	/**
	 * Geocode a free-form address string to [lat, lng] or null on failure.
	 *
	 * @param string $address Address string.
	 * @return array{0:float,1:float}|null
	 */
	public static function geocode( $address ) {
		$address = trim( wp_strip_all_tags( (string) $address ) );
		if ( '' === $address ) {
			return null;
		}

		$cache_key = self::CACHE_GROUP . md5( strtolower( $address ) );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : null;
		}

		$settings = get_option( BWES_Install::SETTINGS_OPTION, array() );
		$endpoint = ! empty( $settings['geocode_endpoint'] )
			? esc_url_raw( $settings['geocode_endpoint'] )
			: 'https://nominatim.openstreetmap.org/search';

		$url = add_query_arg(
			array(
				'q'      => rawurlencode( $address ),
				'format' => 'json',
				'limit'  => 1,
			),
			$endpoint
		);

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'    => 6,
				'user-agent' => 'BlockSocial-WooExtraServices/' . BWES_VERSION . '; ' . home_url( '/' ),
				'headers'    => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			// Cache the failure briefly to avoid hammering the endpoint.
			set_transient( $cache_key, 'none', HOUR_IN_SECONDS );
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body[0]['lat'] ) || empty( $body[0]['lon'] ) ) {
			set_transient( $cache_key, 'none', HOUR_IN_SECONDS );
			return null;
		}

		$coords = array( (float) $body[0]['lat'], (float) $body[0]['lon'] );
		set_transient( $cache_key, $coords, MONTH_IN_SECONDS );

		return $coords;
	}

	/**
	 * Resolve the store origin coordinates from settings (explicit lat/lng wins,
	 * otherwise geocode the configured origin address).
	 *
	 * @return array{0:float,1:float}|null
	 */
	public static function get_origin() {
		$settings = get_option( BWES_Install::SETTINGS_OPTION, array() );

		if ( ! empty( $settings['origin_lat'] ) && ! empty( $settings['origin_lng'] ) ) {
			return array( (float) $settings['origin_lat'], (float) $settings['origin_lng'] );
		}

		if ( ! empty( $settings['origin_address'] ) ) {
			return self::geocode( $settings['origin_address'] );
		}

		return null;
	}

	/**
	 * Distance in km from the store origin to a customer address, or null.
	 *
	 * @param string $destination_address Customer address string.
	 * @return float|null
	 */
	public static function distance_to( $destination_address ) {
		$origin = self::get_origin();
		if ( null === $origin ) {
			return null;
		}

		$dest = self::geocode( $destination_address );
		if ( null === $dest ) {
			return null;
		}

		return self::haversine( $origin[0], $origin[1], $dest[0], $dest[1] );
	}
}
