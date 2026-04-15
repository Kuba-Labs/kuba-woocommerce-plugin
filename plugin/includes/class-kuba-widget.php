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
		add_action( 'wp_head', [ $this, 'render_head_fallback' ] );
	}

	private function should_render(): bool {
		if ( is_admin() ) {
			return false;
		}

		if ( 'yes' !== get_option( 'kuba_labs_widget_enabled', 'yes' ) ) {
			return false;
		}

		if ( empty( get_option( 'kuba_labs_widget_key', '' ) ) ) {
			return false;
		}

		return true;
	}

	private function get_script_url(): string {
		return KUBA_LABS_FRONTEND_BASE . '/widget.js';
	}

	private function get_api_base(): string {
		return defined( 'KUBA_LABS_WIDGET_API_BASE' ) ? KUBA_LABS_WIDGET_API_BASE : '';
	}

	public function render_widget_script(): void {
		if ( ! $this->should_render() ) {
			return;
		}

		$widget_key = get_option( 'kuba_labs_widget_key', '' );
		$api_attr   = '';
		if ( $this->get_api_base() ) {
			$api_attr = sprintf( ' data-kuba-api="%s"', esc_attr( $this->get_api_base() ) );
		}

		printf(
			'<script id="kuba-widget-js" src="%s" data-kuba-key="%s"%s defer></script>',
			esc_url( $this->get_script_url() ),
			esc_attr( $widget_key ),
			$api_attr
		);
	}

	/**
	 * Inline fallback injected in <head>: if wp_footer never fires (broken
	 * theme), this snippet appends the widget script after DOMContentLoaded.
	 */
	public function render_head_fallback(): void {
		if ( ! $this->should_render() ) {
			return;
		}

		$widget_key = get_option( 'kuba_labs_widget_key', '' );
		$src        = esc_url( $this->get_script_url() );
		$api_base   = $this->get_api_base();

		?>
		<script>
		document.addEventListener("DOMContentLoaded",function(){
			if(document.getElementById("kuba-widget-js"))return;
			var s=document.createElement("script");
			s.id="kuba-widget-js";
			s.src=<?php echo wp_json_encode( $src ); ?>;
			s.setAttribute("data-kuba-key",<?php echo wp_json_encode( $widget_key ); ?>);
			<?php if ( $api_base ) : ?>
			s.setAttribute("data-kuba-api",<?php echo wp_json_encode( $api_base ); ?>);
			<?php endif; ?>
			s.defer=true;
			document.body.appendChild(s);
		});
		</script>
		<?php
	}
}
