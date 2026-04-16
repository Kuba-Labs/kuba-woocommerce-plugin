<?php
/**
 * Kuba Labs uninstall — clean up all plugin data.
 *
 * Runs when the plugin is deleted (not just deactivated).
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Wrap in a closure so locals don't leak into the global scope.
( function () {
	global $wpdb;

	// Notify the Kuba backend before deleting credentials.
	$webhook_secret = get_option( 'kuba_labs_webhook_secret', '' );
	$store_id       = get_option( 'kuba_labs_store_id', '' );
	if ( ! empty( $webhook_secret ) && ! empty( $store_id ) ) {
		$payload   = wp_json_encode( [ 'reason' => 'plugin_uninstalled' ] );
		$signature = hash_hmac( 'sha256', $payload, $webhook_secret );
		$api_base  = defined( 'KUBA_LABS_API_BASE' ) ? KUBA_LABS_API_BASE : 'https://api.kubalabs.com';

		wp_remote_post( $api_base . '/shops/woocommerce/disconnect', [
			'timeout'  => 5,
			'blocking' => true,
			'headers'  => [
				'Content-Type'           => 'application/json',
				'X-WC-Store-Id'          => $store_id,
				'X-WC-Webhook-Signature' => $signature,
			],
			'body' => $payload,
		] );
	}

	// Plugin options.
	delete_option( 'kuba_labs_store_id' );
	delete_option( 'kuba_labs_webhook_secret' );
	delete_option( 'kuba_labs_connected_at' );
	delete_option( 'kuba_labs_phone_intl' );
	delete_option( 'kuba_labs_whatsapp_consent' );
	delete_option( 'kuba_labs_consent_label' );
	delete_option( 'kuba_labs_widget_key' );
	delete_option( 'kuba_labs_widget_enabled' );
	delete_option( 'kuba_labs_status_map' );
	delete_option( 'kuba_labs_tracking_mode' );
	delete_option( 'kuba_labs_show_welcome_notice' );

	// Drop consent table.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema change on uninstall; caching doesn't apply.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'kuba_labs_consents' ) );

	// Remove classic checkout capture data.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_query
	$wpdb->query( $wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( '_kuba_capture_' ) . '%'
	) );

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
} )();
