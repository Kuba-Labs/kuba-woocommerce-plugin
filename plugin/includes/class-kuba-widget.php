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

		$store_id = Plugin::get_store_id();
		if ( empty( $store_id ) ) {
			return;
		}

		printf(
			'<script src="%s" data-kuba-key="%s" defer></script>',
			esc_url( KUBA_LABS_FRONTEND_BASE . '/widget.js' ),
			esc_attr( $store_id )
		);
	}
}
