<?php

namespace Kuba_Labs;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->load_includes();
		$this->init();
	}

	private function load_includes(): void {
		// Settings loaded later via woocommerce_get_settings_pages (needs WC_Settings_Page).
		require_once KUBA_LABS_PLUGIN_DIR . 'includes/class-kuba-rest.php';
		require_once KUBA_LABS_PLUGIN_DIR . 'includes/class-kuba-sender.php';
		require_once KUBA_LABS_PLUGIN_DIR . 'includes/class-kuba-events.php';
		require_once KUBA_LABS_PLUGIN_DIR . 'includes/class-kuba-widget.php';
		require_once KUBA_LABS_PLUGIN_DIR . 'includes/class-kuba-cart.php';
		require_once KUBA_LABS_PLUGIN_DIR . 'includes/class-kuba-consent-table.php';
		require_once KUBA_LABS_PLUGIN_DIR . 'includes/class-kuba-checkout.php';
	}

	private function init(): void {
		add_filter( 'woocommerce_get_settings_pages', function ( $settings ) {
			require_once KUBA_LABS_PLUGIN_DIR . 'includes/class-kuba-settings.php';
			return Settings::register( $settings );
		} );

		// Disconnect must be registered early — admin-post.php fires before WC settings load.
		add_action( 'admin_post_kuba_labs_disconnect', [ self::class, 'handle_disconnect' ] );

		new REST();
		new Sender();

		new Cart();
		new Checkout();

		if ( self::is_connected() ) {
			new Events();
			new Widget();
		}
	}

	public static function is_connected(): bool {
		return ! empty( get_option( 'kuba_labs_webhook_secret', '' ) );
	}

	public static function get_store_id(): string {
		return get_option( 'kuba_labs_store_id', '' );
	}

	public static function handle_disconnect(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Unauthorized' );
		}
		check_admin_referer( 'kuba_labs_disconnect' );

		delete_option( 'kuba_labs_webhook_secret' );
		delete_option( 'kuba_labs_connected_at' );

		wp_safe_redirect( admin_url( 'admin.php?page=wc-settings&tab=kuba_labs' ) );
		exit;
	}
}
