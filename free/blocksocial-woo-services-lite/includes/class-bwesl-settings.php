<?php
/**
 * Settings store for the Lite plugin.
 *
 * @package BlockSocial\WooExtraServicesLite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Holds and sanitizes the Lite options.
 */
class BWESL_Settings {

	const OPTION = 'bwesl_settings';

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public function defaults() {
		return array(
			'display_location'      => 'checkout', // checkout | cart | both.

			'gift_enabled'          => true,
			'gift_title'            => __( 'Gift wrapping', 'bwes-lite' ),
			'gift_description'      => __( 'We will wrap your order as a gift.', 'bwes-lite' ),
			'gift_fee'              => 4.90,
			'gift_taxable'          => true,
			'gift_collect_message'  => true,
			'gift_message_label'    => __( 'Your gift message (optional)', 'bwes-lite' ),

			'pickup_enabled'        => true,
			'pickup_title'          => __( 'Local pickup', 'bwes-lite' ),
			'pickup_description'    => __( 'Pick up your order at our store — no shipping costs.', 'bwes-lite' ),
			'pickup_fee'            => 0.0,
			'pickup_taxable'        => false,
		);
	}

	/**
	 * Get the merged settings.
	 *
	 * @return array
	 */
	public function all() {
		$stored = get_option( self::OPTION, array() );

		return wp_parse_args( is_array( $stored ) ? $stored : array(), $this->defaults() );
	}

	/**
	 * Get a single setting.
	 *
	 * @param string $key     Key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		$all = $this->all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Sanitize a raw settings array and persist it.
	 *
	 * @param array $raw Raw input.
	 * @return void
	 */
	public function save( array $raw ) {
		$location = isset( $raw['display_location'] ) ? sanitize_key( $raw['display_location'] ) : 'checkout';
		if ( ! in_array( $location, array( 'checkout', 'cart', 'both' ), true ) ) {
			$location = 'checkout';
		}

		$clean = array(
			'display_location'     => $location,

			'gift_enabled'         => ! empty( $raw['gift_enabled'] ),
			'gift_title'           => isset( $raw['gift_title'] ) ? sanitize_text_field( $raw['gift_title'] ) : '',
			'gift_description'     => isset( $raw['gift_description'] ) ? sanitize_text_field( $raw['gift_description'] ) : '',
			'gift_fee'             => $this->amount( isset( $raw['gift_fee'] ) ? $raw['gift_fee'] : 0 ),
			'gift_taxable'         => ! empty( $raw['gift_taxable'] ),
			'gift_collect_message' => ! empty( $raw['gift_collect_message'] ),
			'gift_message_label'   => isset( $raw['gift_message_label'] ) ? sanitize_text_field( $raw['gift_message_label'] ) : '',

			'pickup_enabled'       => ! empty( $raw['pickup_enabled'] ),
			'pickup_title'         => isset( $raw['pickup_title'] ) ? sanitize_text_field( $raw['pickup_title'] ) : '',
			'pickup_description'   => isset( $raw['pickup_description'] ) ? sanitize_text_field( $raw['pickup_description'] ) : '',
			'pickup_fee'           => $this->amount( isset( $raw['pickup_fee'] ) ? $raw['pickup_fee'] : 0 ),
			'pickup_taxable'       => ! empty( $raw['pickup_taxable'] ),
		);

		update_option( self::OPTION, $clean );
	}

	/**
	 * Sanitize a monetary amount (allows negative = discount).
	 *
	 * @param mixed $value Raw.
	 * @return float
	 */
	public function amount( $value ) {
		if ( is_string( $value ) ) {
			$value = str_replace( ',', '.', $value );
		}

		return round( (float) $value, wc_get_price_decimals() );
	}
}
