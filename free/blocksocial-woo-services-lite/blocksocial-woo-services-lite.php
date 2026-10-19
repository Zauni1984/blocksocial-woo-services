<?php
/**
 * Plugin Name:       BlockSocial Extra Services Lite
 * Plugin URI:        https://blocksocial.eu/woo-extra-services
 * Description:        Free gift wrapping and local pickup for WooCommerce checkout. Upgrade to Pro for express, carrier choice, radius delivery, role-based fees and more.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            BlockSocial UG (haftungsbeschränkt)
 * Author URI:        https://blocksocial.eu
 * Text Domain:       bwes-lite
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * WC requires at least: 6.0
 * WC tested up to:      9.5
 *
 * @package BlockSocial\WooExtraServicesLite
 */

defined( 'ABSPATH' ) || exit;

define( 'BWESL_VERSION', '1.0.0' );
define( 'BWESL_PLUGIN_FILE', __FILE__ );
define( 'BWESL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BWESL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BWESL_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'BWESL_PRO_URL', 'https://blocksocial.eu/woo-extra-services' );

/**
 * Declare HPOS + blocks compatibility.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', BWESL_PLUGIN_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', BWESL_PLUGIN_FILE, true );
		}
	}
);

/**
 * Bootstrap once plugins are loaded.
 *
 * @return void
 */
function bwesl_bootstrap() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action(
			'admin_notices',
			static function () {
				echo '<div class="notice notice-error"><p>';
				echo esc_html__( 'BlockSocial Extra Services Lite requires WooCommerce to be installed and active.', 'bwes-lite' );
				echo '</p></div>';
			}
		);
		return;
	}

	require_once BWESL_PLUGIN_DIR . 'includes/class-bwesl-settings.php';
	require_once BWESL_PLUGIN_DIR . 'includes/class-bwesl-checkout.php';
	require_once BWESL_PLUGIN_DIR . 'includes/class-bwesl-admin.php';

	$settings = new BWESL_Settings();
	( new BWESL_Checkout( $settings ) )->hooks();

	if ( is_admin() ) {
		( new BWESL_Admin( $settings ) )->hooks();
	}

	add_action(
		'init',
		static function () {
			load_plugin_textdomain( 'bwes-lite', false, dirname( BWESL_PLUGIN_BASENAME ) . '/languages' );
		}
	);
}
add_action( 'plugins_loaded', 'bwesl_bootstrap', 20 );

/**
 * Settings link on the plugins list, with an Upgrade call to action.
 */
add_filter(
	'plugin_action_links_' . BWESL_PLUGIN_BASENAME,
	static function ( $links ) {
		$settings = '<a href="' . esc_url( admin_url( 'admin.php?page=bwesl-settings' ) ) . '">' . esc_html__( 'Settings', 'bwes-lite' ) . '</a>';
		$upgrade  = '<a href="' . esc_url( BWESL_PRO_URL ) . '" target="_blank" rel="noopener" style="color:#2271b1;font-weight:600;">' . esc_html__( 'Upgrade to Pro', 'bwes-lite' ) . '</a>';
		array_unshift( $links, $settings, $upgrade );

		return $links;
	}
);
