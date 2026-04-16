<?php

namespace Kuba_Labs;

defined( 'ABSPATH' ) || exit;

/**
 * Injects the Kuba Labs messaging widget on the storefront.
 *
 * Uses the same widget.js that the Shopify integration and standalone embed use.
 * The store_id doubles as the widget public key.
 */
class Widget {

	// WP renders the tag with id="{HANDLE}-js" — keep the handle so the DOM
	// id lines up with "kuba-widget-js" referenced by the fallback snippet.
	const HANDLE = 'kuba-widget';

	public function __construct() {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_widget_script' ] );
		add_filter( 'script_loader_tag', [ $this, 'add_widget_data_attributes' ], 10, 2 );
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

	public function enqueue_widget_script(): void {
		if ( ! $this->should_render() ) {
			return;
		}

		wp_register_script(
			self::HANDLE,
			$this->get_script_url(),
			[],
			KUBA_LABS_VERSION,
			[
				'in_footer' => true,
				'strategy'  => 'defer',
			]
		);
		wp_enqueue_script( self::HANDLE );
	}

	/**
	 * Inject data-kuba-key and data-kuba-api onto the widget <script> tag.
	 * WordPress has no first-class API for custom data-* attrs on enqueued
	 * scripts, so we filter the rendered tag.
	 */
	public function add_widget_data_attributes( string $tag, string $handle ): string {
		if ( self::HANDLE !== $handle ) {
			return $tag;
		}

		$widget_key = get_option( 'kuba_labs_widget_key', '' );
		$attrs      = sprintf( ' data-kuba-key="%s"', esc_attr( $widget_key ) );

		if ( $this->get_api_base() ) {
			$attrs .= sprintf( ' data-kuba-api="%s"', esc_attr( $this->get_api_base() ) );
		}

		return str_replace( ' src=', $attrs . ' src=', $tag );
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
