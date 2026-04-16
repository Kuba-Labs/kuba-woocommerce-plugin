<?php

namespace Kuba_Labs;

defined( 'ABSPATH' ) || exit;

/**
 * Handles Action Scheduler callbacks. Hydrates event data from WooCommerce,
 * signs the payload with HMAC-SHA256, and POSTs to the Kuba backend.
 *
 * Data is hydrated at send-time (not capture-time) so the backend always
 * receives the latest state of the order/product.
 */
class Sender {

	public function __construct() {
		add_action( 'kuba_labs_send_event', [ $this, 'handle_send_event' ] );
	}

	// ------------------------------------------------------------------
	// Action Scheduler handlers
	// ------------------------------------------------------------------

	public function handle_send_event( array $args ): void {
		$topic = $args['topic'] ?? '';

		$data = $this->hydrate( $topic, $args );
		if ( null === $data ) {
			return; // Entity deleted or not found — skip silently.
		}

		// Pass through mapped_event from the event args (set by Events class).
		$extra = [];
		if ( isset( $args['mapped_event'] ) ) {
			$extra['mapped_event'] = $args['mapped_event'];
		}

		$this->send( $topic, $data, $extra );
	}

	// ------------------------------------------------------------------
	// Hydration — fetch current state from WooCommerce at send-time
	// ------------------------------------------------------------------

	private function hydrate( string $topic, array $args ): ?array {
		if ( str_starts_with( $topic, 'order.' ) || str_starts_with( $topic, 'checkout.' ) ) {
			// Classic checkout capture — no WC order to hydrate from.
			if ( ! empty( $args['capture_key'] ) ) {
				return $this->hydrate_capture( $args['capture_key'] );
			}

			$order = wc_get_order( $args['order_id'] ?? 0 );
			if ( ! $order ) {
				return null;
			}

			$data = $this->hydrate_order( $order );

			// Include status transition for status_changed events.
			if ( 'order.status_changed' === $topic ) {
				$data['old_status'] = $args['old_status'] ?? '';
				$data['new_status'] = $args['new_status'] ?? '';
			}

			return $data;
		}

		if ( str_starts_with( $topic, 'product.' ) ) {
			$product = wc_get_product( $args['product_id'] ?? 0 );
			if ( ! $product ) {
				return null;
			}
			return $this->hydrate_product( $product );
		}

		return null;
	}

	/**
	 * Build a minimal order-shaped payload from a classic checkout capture
	 * stored in wp_options. Uses a stable numeric ID so the backend can parse
	 * it the same way as a real order ID.
	 */
	private function hydrate_capture( string $capture_key ): ?array {
		$data = get_option( $capture_key );
		if ( ! is_array( $data ) ) {
			return null;
		}

		// Stable numeric ID derived from the capture key.
		$numeric_id = hexdec( substr( md5( $capture_key ), 0, 7 ) );

		return [
			'id'      => $numeric_id,
			'billing' => [
				'phone'      => $data['phone'] ?? '',
				'email'      => $data['email'] ?? '',
				'first_name' => $data['first_name'] ?? '',
				'last_name'  => $data['last_name'] ?? '',
				'country'    => $data['country'] ?? '',
			],
			'total' => $data['cart_total'] ?? '0',
		];
	}

	/**
	 * Send raw WooCommerce data. The Rust backend owns all normalization.
	 */
	private function hydrate_order( \WC_Order $order ): array {
		$data                = $order->get_data();
		$data['line_items']  = array_map(
			fn( $item ) => $item->get_data(),
			array_values( $order->get_items() )
		);
		$data['shipping_lines'] = array_map(
			fn( $item ) => $item->get_data(),
			array_values( $order->get_items( 'shipping' ) )
		);
		$data['fee_lines'] = array_map(
			fn( $item ) => $item->get_data(),
			array_values( $order->get_items( 'fee' ) )
		);
		$data['coupon_lines'] = array_map(
			fn( $item ) => $item->get_data(),
			array_values( $order->get_items( 'coupon' ) )
		);
		// Send meta so backend can extract tracking info server-side.
		// Filter out sensitive fields (payment tokens, internal WC state).
		$data['meta_data'] = array_values( array_filter(
			array_map(
				fn( $meta ) => [ 'key' => $meta->key, 'value' => $meta->value ],
				$order->get_meta_data()
			),
			fn( $m ) => ! str_starts_with( $m['key'], '_payment_' )
				&& ! str_starts_with( $m['key'], '_stripe_' )
				&& ! str_starts_with( $m['key'], '_paypal_' )
				&& ! str_starts_with( $m['key'], '_transaction_' )
		) );

		return $data;
	}

	private function hydrate_product( \WC_Product $product ): array {
		$data = $product->get_data();

		$image_id = $product->get_image_id();
		$data['image_url'] = $image_id ? wp_get_attachment_url( $image_id ) : null;
		$data['permalink'] = $product->get_permalink();

		$data['categories'] = wp_get_post_terms(
			$product->get_id(),
			'product_cat',
			[ 'fields' => 'names' ]
		);

		$data['meta_data'] = array_map(
			fn( $meta ) => [ 'key' => $meta->key, 'value' => $meta->value ],
			$product->get_meta_data()
		);

		return $data;
	}

	// ------------------------------------------------------------------
	// HTTP delivery with HMAC signing
	// ------------------------------------------------------------------

	private function send( string $topic, array $data, array $extra = [] ): void {
		$webhook_secret = get_option( 'kuba_labs_webhook_secret', '' );
		$store_id       = Plugin::get_store_id();

		if ( empty( $webhook_secret ) || empty( $store_id ) ) {
			return;
		}

		$envelope = [
			'topic'         => $topic,
			'timestamp'     => gmdate( 'c' ),
			'tracking_mode' => get_option( 'kuba_labs_tracking_mode', 'kuba' ),
			'data'          => $data,
		];

		// Merge in mapped_event etc. at the envelope level.
		foreach ( $extra as $key => $value ) {
			$envelope[ $key ] = $value;
		}

		$payload = wp_json_encode( $envelope );

		$signature = hash_hmac( 'sha256', $payload, $webhook_secret );

		$response = wp_remote_post( KUBA_LABS_API_BASE . '/shops/woocommerce/webhook', [
			'timeout' => 15,
			'headers' => [
				'Content-Type'           => 'application/json',
				'X-WC-Store-Id'          => $store_id,
				'X-WC-Store-Domain'      => get_site_url(),
				'X-WC-Webhook-Topic'     => $topic,
				'X-WC-Webhook-Signature' => $signature,
			],
			'body' => $payload,
		] );

		// Throw on failure so Action Scheduler retries.
		if ( is_wp_error( $response ) ) {
			throw new \Exception( esc_html( 'Kuba API request failed: ' . $response->get_error_message() ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$body = wp_remote_retrieve_body( $response );
			throw new \Exception( esc_html( "Kuba API returned HTTP {$code}: {$body}" ) );
		}
	}
}
