<?php
/**
 * Plugin Name:       BlockSocial WooCommerce Extra Services
 * Plugin URI:        https://blocksocial.eu/woo-extra-services
 * Description:        Adds paid extra shipping options and services (gift wrapping, express, carrier choice, pickup, local radius delivery and custom services) that customers can select in the cart or at checkout. Fully modular, role-aware and security hardened.
 * Version:           1.3.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            BlockSocial UG (haftungsbeschränkt)
 * Author URI:        https://blocksocial.eu
 * Text Domain:       bwes
 * Domain Path:       /languages
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 *
 * WC requires at least: 6.0
 * WC tested up to:      9.5
 *
 * @package BlockSocial\WooExtraServices
 */

defined( 'ABSPATH' ) || exit;

define( 'BWES_VERSION', '1.3.0' );
define( 'BWES_PLUGIN_FILE', __FILE__ );
define( 'BWES_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BWES_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BWES_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Declare compatibility with WooCommerce High-Performance Order Storage (HPOS).
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', BWES_PLUGIN_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', BWES_PLUGIN_FILE, true );
		}
	}
);

/**
 * PSR-ish lightweight loader for the plugin classes.
 *
 * @param string $class Fully qualified class name.
 * @return void
 */
spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, 'BWES_' ) ) {
			return;
		}

		$slug     = strtolower( str_replace( '_', '-', substr( $class, 5 ) ) );
		$filename = 'class-bwes-' . $slug . '.php';

		$candidates = array(
			BWES_PLUGIN_DIR . 'includes/' . $filename,
			BWES_PLUGIN_DIR . 'includes/modules/' . $filename,
		);

		foreach ( $candidates as $path ) {
			if ( is_readable( $path ) ) {
				require_once $path;
				return;
			}
		}
	}
);

/**
 * Dynamically translate a stored string against the plugin text domain.
 *
 * Built-in service titles, descriptions and option labels are seeded into the
 * database in English (their source strings are registered for translation via
 * __() in the defaults, so they exist in the catalog). Passing a stored value
 * through here localizes the built-in defaults at render time, while any value
 * the shop owner customized simply passes through unchanged (no catalog match).
 *
 * @param string $text Stored text.
 * @return string
 */
function bwes_translate( $text ) {
	if ( ! is_string( $text ) || '' === $text ) {
		return $text;
	}

	// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- intentional dynamic lookup of catalog strings.
	return __( $text, 'bwes' );
}

/**
 * Bootstrap the plugin once all plugins are loaded (so WooCommerce is available).
 *
 * @return void
 */
function bwes_bootstrap() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action(
			'admin_notices',
			static function () {
				echo '<div class="notice notice-error"><p>';
				echo esc_html__( 'BlockSocial WooCommerce Extra Services requires WooCommerce to be installed and active.', 'bwes' );
				echo '</p></div>';
			}
		);
		return;
	}

	BWES_Plugin::instance();
}
add_action( 'plugins_loaded', 'bwes_bootstrap', 20 );

/**
 * Activation / deactivation hooks.
 */
register_activation_hook( __FILE__, array( 'BWES_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'BWES_Install', 'deactivate' ) );
