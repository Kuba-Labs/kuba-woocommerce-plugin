<?php
/**
 * Plugin Name: Kuba Labs - WhatsApp Marketing for WooCommerce
 * Plugin URI:  https://kubalabs.com
 * Description: Connect your WooCommerce store to Kuba Labs for automated WhatsApp marketing, order notifications, and abandoned cart recovery.
 * Version:     1.0.0
 * Author:      Kuba Labs
 * Author URI:  https://kubalabs.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: kuba-labs
 * Domain Path: /languages
 *
 * Requires at least: 6.8
 * Tested up to: 6.8
 * Requires PHP: 7.4
 *
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 * WC tested up to: 9.3
 */

defined( 'ABSPATH' ) || exit;

define( 'KUBA_LABS_VERSION', '1.0.0' );
define( 'KUBA_LABS_PLUGIN_FILE', __FILE__ );
define( 'KUBA_LABS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'KUBA_LABS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Allow overrides via wp-config.php for local development.
// define( 'KUBA_LABS_API_BASE', 'http://host.docker.internal:8080' );
// define( 'KUBA_LABS_FRONTEND_BASE', 'http://localhost:5173' );
if ( ! defined( 'KUBA_LABS_API_BASE' ) ) {
	define( 'KUBA_LABS_API_BASE', 'https://api.kubalabs.com' );
}
if ( ! defined( 'KUBA_LABS_FRONTEND_BASE' ) ) {
	define( 'KUBA_LABS_FRONTEND_BASE', 'https://app.kubalabs.com' );
}
if ( ! defined( 'KUBA_LABS_ABANDONED_CART_DELAY' ) ) {
	define( 'KUBA_LABS_ABANDONED_CART_DELAY', 30 * MINUTE_IN_SECONDS );
}

// Declare HPOS and Blocks compatibility.
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			__FILE__,
			true
		);
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'cart_checkout_blocks',
			__FILE__,
			true
		);
	}
} );

// Generate stable store UUID and create DB tables on activation.
register_activation_hook( __FILE__, function () {
	if ( ! get_option( 'kuba_labs_store_id' ) ) {
		update_option( 'kuba_labs_store_id', wp_generate_uuid4(), false );
	}

	require_once KUBA_LABS_PLUGIN_DIR . 'includes/class-kuba-consent-table.php';
	Kuba_Labs\Consent_Table::create_table();

	// Redirect to settings page after activation.
	set_transient( 'kuba_labs_activated', true, 30 );
} );

// Redirect to settings on first activation.
add_action( 'admin_init', function () {
	if ( ! get_transient( 'kuba_labs_activated' ) ) {
		return;
	}
	delete_transient( 'kuba_labs_activated' );

	// Don't redirect on bulk activation, AJAX, or network admin.
	if ( isset( $_GET['activate-multi'] ) || wp_doing_ajax() || is_network_admin() ) {
		return;
	}

	wp_safe_redirect( admin_url( 'admin.php?page=wc-settings&tab=kuba_labs' ) );
	exit;
} );

// Notify the Kuba backend, clear connection state, and clean up on deactivation.
register_deactivation_hook( __FILE__, function () {
	// Notify backend first — needs the webhook secret for HMAC signing.
	kuba_labs_notify_disconnect();

	// Clear connection state so reactivation shows "Not connected".
	// Store ID is kept — it's stable across connections.
	delete_option( 'kuba_labs_webhook_secret' );
	delete_option( 'kuba_labs_connected_at' );
	delete_option( 'kuba_labs_widget_key' );

	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'kuba_labs_send_event', null, 'kuba-labs' );
		as_unschedule_all_actions( 'kuba_labs_check_abandoned', null, 'kuba-labs' );
		as_unschedule_all_actions( 'kuba_labs_sweep_abandoned', null, 'kuba-labs' );
	}
} );

/**
 * Send a signed disconnect request to the Kuba backend so it can mark the
 * store as inactive.
 */
function kuba_labs_notify_disconnect(): void {
	$webhook_secret = get_option( 'kuba_labs_webhook_secret', '' );
	$store_id       = get_option( 'kuba_labs_store_id', '' );
	$api_base       = defined( 'KUBA_LABS_API_BASE' ) ? KUBA_LABS_API_BASE : 'https://api.kubalabs.com';

	if ( empty( $webhook_secret ) || empty( $store_id ) ) {
		return;
	}

	$payload   = wp_json_encode( [ 'reason' => 'plugin_deactivated' ] );
	$signature = hash_hmac( 'sha256', $payload, $webhook_secret );

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

// Bootstrap the plugin after all plugins are loaded.
add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	require_once KUBA_LABS_PLUGIN_DIR . 'includes/class-kuba-plugin.php';
	Kuba_Labs\Plugin::instance();
} );
