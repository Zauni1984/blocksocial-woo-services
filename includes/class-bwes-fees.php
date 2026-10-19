<?php
/**
 * Server-side fee calculator.
 *
 * Turns a stored service definition + a validated customer selection into a
 * concrete fee amount. Prices are ALWAYS derived here from configuration; the
 * amount is never accepted from the request.
 *
 * @package BlockSocial\WooExtraServices
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fee computation utilities.
 */
class BWES_Fees {

	/**
	 * Compute the raw fee (before tax) for a service given a selection.
	 *
	 * @param array   $service   Normalized service definition.
	 * @param mixed   $selection Validated selection value (see BWES_Frontend::sanitize_selection).
	 * @param WC_Cart $cart      Cart instance.
	 * @return float|null Fee amount, or null when the service does not apply.
	 */
	public static function compute( array $service, $selection, $cart ) {
		switch ( $service['type'] ) {
			case 'checkbox':
				if ( empty( $selection ) ) {
					return null;
				}
				return self::apply_fee_type( (float) $service['fee'], $service['fee_type'], $cart );

			case 'radio':
				$option = self::find_option( $service, is_string( $selection ) ? $selection : '' );
				if ( null === $option ) {
					return null;
				}
				return self::apply_fee_type( (float) $option['fee'], $option['fee_type'], $cart );

			case 'radius':
				return self::compute_radius( $service, $selection, $cart );
		}

		return null;
	}

	/**
	 * Compute the radius-based delivery fee from the customer shipping address.
	 *
	 * @param array   $service   Service definition.
	 * @param mixed   $selection Selection ('1' when opted in).
	 * @param WC_Cart $cart      Cart instance.
	 * @return float|null
	 */
	private static function compute_radius( array $service, $selection, $cart ) {
		if ( empty( $selection ) ) {
			return null;
		}
		if ( empty( $service['options'] ) ) {
			return null;
		}

		$address  = self::get_customer_shipping_address_string();
		$distance = BWES_Geolocation::distance_to( $address );
		if ( null === $distance ) {
			return null;
		}

		// Tiers sorted ascending by max_km; pick the first that covers the distance.
		$tiers = $service['options'];
		usort(
			$tiers,
			static function ( $a, $b ) {
				return ( (float) $a['max_km'] ) <=> ( (float) $b['max_km'] );
			}
		);

		foreach ( $tiers as $tier ) {
			if ( $distance <= (float) $tier['max_km'] ) {
				return self::apply_fee_type( (float) $tier['fee'], 'flat', $cart );
			}
		}

		// Out of range — no local delivery fee applies.
		return null;
	}

	/**
	 * Resolve the current customer shipping address as a single string.
	 *
	 * @return string
	 */
	private static function get_customer_shipping_address_string() {
		$customer = WC()->customer;
		if ( ! $customer ) {
			return '';
		}

		$parts = array(
			$customer->get_shipping_address_1(),
			$customer->get_shipping_address_2(),
			$customer->get_shipping_postcode(),
			$customer->get_shipping_city(),
			$customer->get_shipping_state(),
			$customer->get_shipping_country(),
		);

		$parts = array_filter( array_map( 'trim', $parts ) );

		return implode( ', ', $parts );
	}

	/**
	 * Apply the fee-type multiplier (flat / percent / per_item).
	 *
	 * @param float   $amount   Base amount.
	 * @param string  $fee_type Fee type.
	 * @param WC_Cart $cart     Cart instance.
	 * @return float
	 */
	private static function apply_fee_type( $amount, $fee_type, $cart ) {
		switch ( $fee_type ) {
			case 'percent':
				$base = (float) $cart->get_subtotal();
				return round( $base * ( $amount / 100 ), wc_get_price_decimals() );

			case 'per_item':
				$count = (int) $cart->get_cart_contents_count();
				return round( $amount * max( 0, $count ), wc_get_price_decimals() );

			case 'flat':
			default:
				return round( $amount, wc_get_price_decimals() );
		}
	}

	/**
	 * Find a radio option by key.
	 *
	 * @param array  $service Service definition.
	 * @param string $key     Option key.
	 * @return array|null
	 */
	public static function find_option( array $service, $key ) {
		if ( '' === $key || empty( $service['options'] ) ) {
			return null;
		}
		foreach ( $service['options'] as $option ) {
			if ( isset( $option['key'] ) && $option['key'] === $key ) {
				return $option;
			}
		}

		return null;
	}
}
