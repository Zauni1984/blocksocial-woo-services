<?php
/**
 * Uninstall routine — removes all plugin data.
 *
 * Runs only when the user deletes the plugin from the WordPress admin.
 *
 * @package BlockSocial\WooExtraServices
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$bwes_options = array(
	'bwes_settings',
	'bwes_modules',
	'bwes_services',
	'bwes_license',
);

foreach ( $bwes_options as $bwes_option ) {
	delete_option( $bwes_option );
}

delete_transient( 'bwes_update_info' );

// Clean up cached geocoding transients (single site).
global $wpdb;
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_bwes_geo_%' OR option_name LIKE '_transient_timeout_bwes_geo_%'"
);
