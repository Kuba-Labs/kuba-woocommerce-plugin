<?php

namespace Kuba_Labs;

defined( 'ABSPATH' ) || exit;

/**
 * Injects the Kuba Labs WhatsApp widget on the storefront.
 *
 * Uses the same widget.js that the Shopify integration and standalone embed use.
 * The store_id doubles as the widget public key.
 */
class Widget {

	public function __construct() {
		add_action( 'wp_footer', [ $this, 'render_widget_script' ] );
	}

	public function render_widget_script(): void {
		// Only render on the frontend, not in admin or REST requests.
		if ( is_admin() ) {
			return;
		}

		// Respect the widget toggle in WooCommerce settings.
		if ( 'yes' !== get_option( 'kuba_labs_widget_enabled', 'yes' ) ) {
			return;
		}

		$widget_key = get_option( 'kuba_labs_widget_key', '' );
		if ( empty( $widget_key ) ) {
			return;
		}

		// For local dev: the widget JS runs in the customer's browser, so it
		// needs localhost rather than the Docker-internal hostname.
		$api_attr = '';
		if ( defined( 'KUBA_LABS_WIDGET_API_BASE' ) ) {
			$api_attr = sprintf( ' data-kuba-api="%s"', esc_attr( KUBA_LABS_WIDGET_API_BASE ) );
		}

		printf(
			'<script src="%s" data-kuba-key="%s"%s defer></script>',
			esc_url( KUBA_LABS_FRONTEND_BASE . '/widget.js' ),
			esc_attr( $widget_key ),
			$api_attr
		);
	}
}
