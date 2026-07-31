<?php
/**
 * Module registry: defines the built-in modules and their enabled state.
 *
 * Every feature area is a "module" that can be individually enabled or
 * disabled by the shop owner. Disabling a module hides all of its services
 * from the storefront and skips any fee it would add — enforced server-side.
 *
 * @package BlockSocial\WooExtraServices
 */

defined( 'ABSPATH' ) || exit;

/**
 * Holds module definitions and their on/off state.
 */
class BWES_Modules {

	const OPTION = 'bwes_modules';

	/**
	 * Cached module definitions.
	 *
	 * @var array<string,array>|null
	 */
	private $definitions = null;

	/**
	 * Cached enabled map.
	 *
	 * @var array<string,bool>|null
	 */
	private $enabled = null;

	/**
	 * Return the static module definitions.
	 *
	 * @return array<string,array>
	 */
	public function get_definitions() {
		if ( null !== $this->definitions ) {
			return $this->definitions;
		}

		$definitions = array(
			'gift_wrap'     => array(
				'label'          => __( 'Gift wrapping', 'bwes' ),
				'description'    => __( 'Let customers add gift wrapping for a fee, with an optional personal message.', 'bwes' ),
				'default_active' => true,
			),
			'express'       => array(
				'label'          => __( 'Express & priority shipping', 'bwes' ),
				'description'    => __( 'Offer faster delivery tiers (e.g. express / priority) against a surcharge.', 'bwes' ),
				'default_active' => true,
			),
			'carrier'       => array(
				'label'          => __( 'Carrier choice', 'bwes' ),
				'description'    => __( 'Allow customers to pick a preferred carrier (e.g. DHL instead of DPD) where possible.', 'bwes' ),
				'default_active' => true,
			),
			'pickup'        => array(
				'label'          => __( 'Local pickup', 'bwes' ),
				'description'    => __( 'Offer store pickup as a selectable option, with or without a fee.', 'bwes' ),
				'default_active' => true,
			),
			'local_radius'  => array(
				'label'          => __( 'Local radius delivery', 'bwes' ),
				'description'    => __( 'Charge a delivery fee based on the distance from your store (km radius tiers).', 'bwes' ),
				'default_active' => false,
			),
			'custom'        => array(
				'label'          => __( 'Custom services', 'bwes' ),
				'description'    => __( 'Create your own paid services and shipping extras manually.', 'bwes' ),
				'default_active' => true,
			),
		);

		/**
		 * Filter the available modules.
		 *
		 * @param array $definitions Module definitions keyed by module id.
		 */
		$this->definitions = apply_filters( 'bwes_module_definitions', $definitions );

		return $this->definitions;
	}

	/**
	 * Default enabled map derived from the definitions.
	 *
	 * @return array<string,bool>
	 */
	public function get_defaults() {
		$defaults = array();
		foreach ( $this->get_definitions() as $id => $def ) {
			$defaults[ $id ] = ! empty( $def['default_active'] );
		}

		return $defaults;
	}

	/**
	 * Get the enabled map (module id => bool).
	 *
	 * @return array<string,bool>
	 */
	public function get_enabled_map() {
		if ( null !== $this->enabled ) {
			return $this->enabled;
		}

		$stored   = get_option( self::OPTION, array() );
		$stored   = is_array( $stored ) ? $stored : array();
		$defaults = $this->get_defaults();

		$map = array();
		foreach ( array_keys( $this->get_definitions() ) as $id ) {
			if ( array_key_exists( $id, $stored ) ) {
				$map[ $id ] = (bool) $stored[ $id ];
			} else {
				$map[ $id ] = ! empty( $defaults[ $id ] );
			}
		}

		$this->enabled = $map;

		return $map;
	}

	/**
	 * Whether a given module is currently active.
	 *
	 * @param string $module_id Module identifier.
	 * @return bool
	 */
	public function is_active( $module_id ) {
		$map = $this->get_enabled_map();

		return ! empty( $map[ $module_id ] );
	}

	/**
	 * Persist the enabled map (already sanitized by the caller).
	 *
	 * @param array<string,bool> $map Enabled map.
	 * @return void
	 */
	public function save_enabled_map( array $map ) {
		$clean = array();
		foreach ( array_keys( $this->get_definitions() ) as $id ) {
			$clean[ $id ] = ! empty( $map[ $id ] );
		}

		update_option( self::OPTION, $clean );
		$this->enabled = $clean;
	}
}
