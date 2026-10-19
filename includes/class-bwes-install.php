<?php
/**
 * Activation / deactivation and default option seeding.
 *
 * @package BlockSocial\WooExtraServices
 */

defined( 'ABSPATH' ) || exit;

/**
 * Install routines.
 */
class BWES_Install {

	const SETTINGS_OPTION = 'bwes_settings';

	/**
	 * Default general settings.
	 *
	 * @return array
	 */
	public static function default_settings() {
		return array(
			'display_location' => 'checkout', // checkout | cart | both.
			'origin_address'   => '',
			'origin_lat'       => '',
			'origin_lng'       => '',
			'geocode_endpoint' => 'https://nominatim.openstreetmap.org/search',
		);
	}

	/**
	 * Plugin activation: seed defaults without overwriting existing config.
	 *
	 * @return void
	 */
	public static function activate() {
		if ( false === get_option( self::SETTINGS_OPTION, false ) ) {
			add_option( self::SETTINGS_OPTION, self::default_settings() );
		}

		if ( false === get_option( BWES_Modules::OPTION, false ) ) {
			$modules  = new BWES_Modules();
			add_option( BWES_Modules::OPTION, $modules->get_defaults() );
		}

		if ( false === get_option( BWES_Services::OPTION, false ) ) {
			$modules  = new BWES_Modules();
			$services = new BWES_Services( $modules );
			add_option( BWES_Services::OPTION, $services->get_default_services() );
		}
	}

	/**
	 * Plugin deactivation. Nothing persistent to tear down; settings are kept
	 * until the plugin is uninstalled.
	 *
	 * @return void
	 */
	public static function deactivate() {
		// Intentionally left minimal — configuration is preserved on deactivation.
	}
}
