<?php
/**
 * Service definitions store.
 *
 * A "service" is a single selectable extra shown to the customer (a checkbox,
 * a radio group, a distance-based delivery, etc.). Built-in modules seed their
 * default services, and the shop owner can edit them or add custom ones.
 *
 * All amounts are stored as configuration only. The actual fee that lands on
 * the cart is always recomputed server-side from this configuration — the
 * client can only ever send an option *key*, never a price.
 *
 * @package BlockSocial\WooExtraServices
 */

defined( 'ABSPATH' ) || exit;

/**
 * CRUD + sanitization for services.
 */
class BWES_Services {

	const OPTION = 'bwes_services';

	/**
	 * Allowed service input types.
	 */
	const TYPES = array( 'checkbox', 'radio', 'radius' );

	/**
	 * Allowed fee calculation types.
	 */
	const FEE_TYPES = array( 'flat', 'percent', 'per_item' );

	/**
	 * Modules registry.
	 *
	 * @var BWES_Modules
	 */
	private $modules;

	/**
	 * Cached services.
	 *
	 * @var array<string,array>|null
	 */
	private $cache = null;

	/**
	 * Constructor.
	 *
	 * @param BWES_Modules $modules Modules registry.
	 */
	public function __construct( BWES_Modules $modules ) {
		$this->modules = $modules;
	}

	/**
	 * Default service definitions seeded on install.
	 *
	 * @return array<string,array>
	 */
	public function get_default_services() {
		$defaults = array(
			'gift_wrap'    => array(
				'module'          => 'gift_wrap',
				'title'           => __( 'Gift wrapping', 'bwes' ),
				'description'     => __( 'We will wrap your order as a gift.', 'bwes' ),
				'type'            => 'checkbox',
				'fee'             => 4.90,
				'fee_type'        => 'flat',
				'taxable'         => true,
				'tax_class'       => '',
				'allowed_roles'   => array(),
				'collect_message' => true,
				'message_label'   => __( 'Your gift message (optional)', 'bwes' ),
				'options'         => array(),
				'position'        => 10,
			),
			'express'      => array(
				'module'        => 'express',
				'title'         => __( 'Delivery speed', 'bwes' ),
				'description'   => __( 'Choose how fast you need your order.', 'bwes' ),
				'type'          => 'radio',
				'fee'           => 0,
				'fee_type'      => 'flat',
				'taxable'       => true,
				'tax_class'     => '',
				'allowed_roles' => array(),
				'options'       => array(
					array(
						'key'      => 'standard',
						'label'    => __( 'Standard delivery', 'bwes' ),
						'fee'      => 0,
						'fee_type' => 'flat',
						'default'  => true,
					),
					array(
						'key'      => 'express',
						'label'    => __( 'Express delivery (1–2 days)', 'bwes' ),
						'fee'      => 6.90,
						'fee_type' => 'flat',
						'default'  => false,
					),
				),
				'position'      => 20,
			),
			'carrier'      => array(
				'module'        => 'carrier',
				'title'         => __( 'Preferred carrier', 'bwes' ),
				'description'   => __( 'Pick your preferred carrier where available.', 'bwes' ),
				'type'          => 'radio',
				'fee'           => 0,
				'fee_type'      => 'flat',
				'taxable'       => true,
				'tax_class'     => '',
				'allowed_roles' => array(),
				'options'       => array(
					array(
						'key'      => 'dpd',
						'label'    => __( 'DPD (standard)', 'bwes' ),
						'fee'      => 0,
						'fee_type' => 'flat',
						'default'  => true,
					),
					array(
						'key'      => 'dhl',
						'label'    => __( 'DHL', 'bwes' ),
						'fee'      => 1.50,
						'fee_type' => 'flat',
						'default'  => false,
					),
				),
				'position'      => 30,
			),
			'pickup'       => array(
				'module'            => 'pickup',
				'title'             => __( 'Local pickup', 'bwes' ),
				'description'       => __( 'Pick up your order at our store — no shipping costs.', 'bwes' ),
				'type'              => 'checkbox',
				'fee'               => 0,
				'fee_type'          => 'flat',
				'taxable'           => false,
				'tax_class'         => '',
				'allowed_roles'     => array(),
				'suppress_shipping' => true,
				'options'           => array(),
				'position'          => 40,
			),
			'local_radius' => array(
				'module'        => 'local_radius',
				'title'         => __( 'Local delivery by distance', 'bwes' ),
				'description'   => __( 'We deliver locally. The fee depends on the distance to your address.', 'bwes' ),
				'type'          => 'radius',
				'fee'           => 0,
				'fee_type'      => 'flat',
				'taxable'       => true,
				'tax_class'     => '',
				'allowed_roles' => array(),
				'options'       => array(
					array(
						'key'    => 'tier-5',
						'label'  => __( 'Up to 5 km', 'bwes' ),
						'max_km' => 5,
						'fee'    => 2.90,
					),
					array(
						'key'    => 'tier-10',
						'label'  => __( 'Up to 10 km', 'bwes' ),
						'max_km' => 10,
						'fee'    => 4.90,
					),
					array(
						'key'    => 'tier-20',
						'label'  => __( 'Up to 20 km', 'bwes' ),
						'max_km' => 20,
						'fee'    => 8.90,
					),
				),
				'position'      => 50,
			),
		);

		$out = array();
		foreach ( $defaults as $id => $service ) {
			$service['id']      = $id;
			$service['enabled'] = true;
			$out[ $id ]         = $this->normalize( $service );
		}

		return $out;
	}

	/**
	 * Get all stored services (raw, unfiltered).
	 *
	 * @return array<string,array>
	 */
	public function all() {
		if ( null !== $this->cache ) {
			return $this->cache;
		}

		$stored = get_option( self::OPTION, null );
		if ( ! is_array( $stored ) ) {
			$stored = $this->get_default_services();
		}

		$services = array();
		foreach ( $stored as $id => $service ) {
			if ( ! is_array( $service ) ) {
				continue;
			}
			$service['id'] = is_string( $id ) ? $id : sanitize_key( (string) $id );
			$services[ $service['id'] ] = $this->normalize( $service );
		}

		uasort(
			$services,
			static function ( $a, $b ) {
				return $a['position'] <=> $b['position'];
			}
		);

		$this->cache = $services;

		return $services;
	}

	/**
	 * Get a single service by id.
	 *
	 * @param string $id Service id.
	 * @return array|null
	 */
	public function get( $id ) {
		$all = $this->all();

		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * Persist the full services map.
	 *
	 * @param array<string,array> $services Services.
	 * @return void
	 */
	public function save_all( array $services ) {
		$clean = array();
		foreach ( $services as $id => $service ) {
			$service['id']       = sanitize_key( is_string( $id ) ? $id : ( $service['id'] ?? '' ) );
			if ( '' === $service['id'] ) {
				continue;
			}
			$clean[ $service['id'] ] = $this->normalize( $service );
		}

		update_option( self::OPTION, $clean );
		$this->cache = null;
	}

	/**
	 * Insert or update a single service.
	 *
	 * @param array $service Service data.
	 * @return string Service id.
	 */
	public function upsert( array $service ) {
		$all               = $this->all();
		$service           = $this->normalize( $service );
		$all[ $service['id'] ] = $service;
		$this->save_all( $all );

		return $service['id'];
	}

	/**
	 * Delete a service by id.
	 *
	 * @param string $id Service id.
	 * @return void
	 */
	public function delete( $id ) {
		$all = $this->all();
		unset( $all[ $id ] );
		$this->save_all( $all );
	}

	/**
	 * Services that belong to active modules and are enabled.
	 *
	 * @return array<string,array>
	 */
	public function get_active() {
		$out = array();
		foreach ( $this->all() as $id => $service ) {
			if ( empty( $service['enabled'] ) ) {
				continue;
			}
			if ( ! $this->modules->is_active( $service['module'] ) ) {
				continue;
			}
			$out[ $id ] = $service;
		}

		return $out;
	}

	/**
	 * Services visible to the current user (role gating applied) and available
	 * for the given cart context.
	 *
	 * @param WC_Cart|null $cart Cart instance (optional).
	 * @return array<string,array>
	 */
	public function get_available_for_current_user( $cart = null ) {
		$out = array();
		foreach ( $this->get_active() as $id => $service ) {
			if ( ! $this->is_visible_to_current_user( $service ) ) {
				continue;
			}
			$out[ $id ] = $service;
		}

		/**
		 * Filter the list of services available to the current user.
		 *
		 * @param array        $out  Available services.
		 * @param WC_Cart|null $cart Cart instance.
		 */
		return apply_filters( 'bwes_available_services', $out, $cart );
	}

	/**
	 * Determine whether a service is visible to the current user based on the
	 * allowed roles configuration. An empty allow-list means "everyone".
	 *
	 * @param array $service Service definition.
	 * @return bool
	 */
	public function is_visible_to_current_user( array $service ) {
		$allowed = isset( $service['allowed_roles'] ) ? (array) $service['allowed_roles'] : array();
		if ( empty( $allowed ) ) {
			return true;
		}

		$user = wp_get_current_user();
		if ( ! $user || 0 === $user->ID ) {
			// Guests are represented by the virtual "guest" role.
			return in_array( 'guest', $allowed, true );
		}

		$roles = (array) $user->roles;
		foreach ( $roles as $role ) {
			if ( in_array( $role, $allowed, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalize / sanitize a service definition into a canonical shape.
	 *
	 * @param array $service Raw service data.
	 * @return array
	 */
	public function normalize( array $service ) {
		$modules   = array_keys( $this->modules->get_definitions() );
		$module    = isset( $service['module'] ) ? sanitize_key( $service['module'] ) : 'custom';
		$module    = in_array( $module, $modules, true ) ? $module : 'custom';
		$type      = isset( $service['type'] ) ? sanitize_key( $service['type'] ) : 'checkbox';
		$type      = in_array( $type, self::TYPES, true ) ? $type : 'checkbox';
		$fee_type  = isset( $service['fee_type'] ) ? sanitize_key( $service['fee_type'] ) : 'flat';
		$fee_type  = in_array( $fee_type, self::FEE_TYPES, true ) ? $fee_type : 'flat';

		$id = isset( $service['id'] ) ? sanitize_key( $service['id'] ) : '';
		if ( '' === $id ) {
			$id = sanitize_key( 'svc-' . substr( md5( wp_json_encode( $service ) . microtime() ), 0, 10 ) );
		}

		$normalized = array(
			'id'              => $id,
			'module'          => $module,
			'title'           => isset( $service['title'] ) ? sanitize_text_field( $service['title'] ) : '',
			'description'     => isset( $service['description'] ) ? wp_kses_post( $service['description'] ) : '',
			'type'            => $type,
			'fee'             => isset( $service['fee'] ) ? $this->sanitize_amount( $service['fee'] ) : 0.0,
			'fee_type'        => $fee_type,
			'taxable'         => ! empty( $service['taxable'] ),
			'tax_class'       => isset( $service['tax_class'] ) ? sanitize_text_field( $service['tax_class'] ) : '',
			'enabled'         => ! empty( $service['enabled'] ),
			'allowed_roles'   => $this->sanitize_roles( isset( $service['allowed_roles'] ) ? $service['allowed_roles'] : array() ),
			'collect_message' => ! empty( $service['collect_message'] ),
			'message_label'   => isset( $service['message_label'] ) ? sanitize_text_field( $service['message_label'] ) : '',
			'suppress_shipping' => array_key_exists( 'suppress_shipping', $service )
				? ! empty( $service['suppress_shipping'] )
				: ( 'pickup' === $module ),
			'position'        => isset( $service['position'] ) ? absint( $service['position'] ) : 100,
			'options'         => array(),
		);

		if ( in_array( $type, array( 'radio', 'radius' ), true ) && ! empty( $service['options'] ) && is_array( $service['options'] ) ) {
			foreach ( $service['options'] as $opt ) {
				if ( ! is_array( $opt ) ) {
					continue;
				}
				$normalized['options'][] = $this->normalize_option( $opt, $type );
			}
		}

		return $normalized;
	}

	/**
	 * Normalize a single option entry.
	 *
	 * @param array  $opt  Option data.
	 * @param string $type Parent service type.
	 * @return array
	 */
	private function normalize_option( array $opt, $type ) {
		$key = isset( $opt['key'] ) ? sanitize_key( $opt['key'] ) : '';
		if ( '' === $key ) {
			$key = sanitize_key( 'opt-' . substr( md5( wp_json_encode( $opt ) . microtime() ), 0, 8 ) );
		}

		$out = array(
			'key'   => $key,
			'label' => isset( $opt['label'] ) ? sanitize_text_field( $opt['label'] ) : $key,
			'fee'   => isset( $opt['fee'] ) ? $this->sanitize_amount( $opt['fee'] ) : 0.0,
		);

		if ( 'radius' === $type ) {
			$out['max_km'] = isset( $opt['max_km'] ) ? max( 0.0, (float) $opt['max_km'] ) : 0.0;
		} else {
			$fee_type        = isset( $opt['fee_type'] ) ? sanitize_key( $opt['fee_type'] ) : 'flat';
			$out['fee_type'] = in_array( $fee_type, self::FEE_TYPES, true ) ? $fee_type : 'flat';
			$out['default']  = ! empty( $opt['default'] );
		}

		return $out;
	}

	/**
	 * Sanitize a monetary amount. Allows negative (discount) values.
	 *
	 * @param mixed $value Raw value.
	 * @return float
	 */
	public function sanitize_amount( $value ) {
		if ( is_string( $value ) ) {
			$value = str_replace( ',', '.', $value );
		}

		return round( (float) $value, wc_get_price_decimals() );
	}

	/**
	 * Sanitize a list of role slugs against the real, registered roles.
	 *
	 * @param mixed $roles Raw roles.
	 * @return string[]
	 */
	public function sanitize_roles( $roles ) {
		$roles = is_array( $roles ) ? $roles : array();
		$valid = $this->get_selectable_roles();
		$out   = array();

		foreach ( $roles as $role ) {
			$role = sanitize_key( $role );
			if ( isset( $valid[ $role ] ) ) {
				$out[] = $role;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Roles that can be assigned to a service, including a virtual "guest".
	 *
	 * @return array<string,string> role slug => label
	 */
	public function get_selectable_roles() {
		$roles = array( 'guest' => __( 'Guest (not logged in)', 'bwes' ) );

		if ( ! function_exists( 'wp_roles' ) ) {
			require_once ABSPATH . 'wp-includes/capabilities.php';
		}

		$wp_roles = wp_roles();
		foreach ( $wp_roles->roles as $slug => $details ) {
			$name = isset( $details['name'] ) ? $details['name'] : $slug;
			// translate_user_role() lives in wp-admin; guard for front-end calls.
			$roles[ sanitize_key( $slug ) ] = function_exists( 'translate_user_role' )
				? translate_user_role( $name )
				: $name;
		}

		return $roles;
	}
}
