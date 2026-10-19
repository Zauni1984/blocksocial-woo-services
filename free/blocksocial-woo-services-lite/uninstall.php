<?php
/**
 * Uninstall: remove Lite options.
 *
 * @package BlockSocial\WooExtraServicesLite
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'bwesl_settings' );
