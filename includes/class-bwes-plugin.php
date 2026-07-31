<?php
/**
 * Core plugin bootstrapper.
 *
 * @package BlockSocial\WooExtraServices
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin orchestrator (singleton).
 */
final class BWES_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var BWES_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Modules registry.
	 *
	 * @var BWES_Modules
	 */
	public $modules;

	/**
	 * Services store.
	 *
	 * @var BWES_Services
	 */
	public $services;

	/**
	 * Frontend handler.
	 *
	 * @var BWES_Frontend
	 */
	public $frontend;

	/**
	 * Admin handler.
	 *
	 * @var BWES_Admin|null
	 */
	public $admin;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @return BWES_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}

		return self::$instance;
	}

	/**
	 * Private constructor to enforce the singleton.
	 */
	private function __construct() {}

	/**
	 * Wire everything together.
	 *
	 * @return void
	 */
	private function init() {
		$this->modules  = new BWES_Modules();
		$this->services = new BWES_Services( $this->modules );
		$this->frontend = new BWES_Frontend( $this->modules, $this->services );

		$this->frontend->hooks();

		if ( is_admin() ) {
			$this->admin = new BWES_Admin( $this->modules, $this->services );
			$this->admin->hooks();
		}

		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'bwes', false, dirname( BWES_PLUGIN_BASENAME ) . '/languages' );
	}
}
