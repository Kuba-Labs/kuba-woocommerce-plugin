<?php
/**
 * Kuba Labs uninstall — clean up all plugin data.
 *
 * Runs when the plugin is deleted (not just deactivated).
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Plugin options.
delete_option( 'kuba_labs_store_id' );
delete_option( 'kuba_labs_webhook_secret' );
delete_option( 'kuba_labs_connected_at' );
delete_option( 'kuba_labs_phone_intl' );
delete_option( 'kuba_labs_whatsapp_consent' );
delete_option( 'kuba_labs_consent_label' );

global $wpdb;

// Drop consent table.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}kuba_labs_consents" );

// Remove WooCommerce API keys created by this plugin.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->delete(
	$wpdb->prefix . 'woocommerce_api_keys',
	[ 'description' => 'Kuba Labs' ],
	[ '%s' ]
);

// Clean up Action Scheduler actions.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'kuba_labs_send_event', null, 'kuba-labs' );
	as_unschedule_all_actions( 'kuba_labs_check_abandoned', null, 'kuba-labs' );
	as_unschedule_all_actions( 'kuba_labs_sweep_abandoned', null, 'kuba-labs' );
}
