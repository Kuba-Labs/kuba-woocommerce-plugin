<?php

namespace Kuba_Labs;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks into WooCommerce actions and queues events via Action Scheduler.
 *
 * This class never sends HTTP requests directly. It only captures event data
 * and schedules delivery via Action Scheduler, which handles retries and
 * concurrency.
 */
class Events {

	public function __construct() {
		// Order lifecycle.
		add_action( 'woocommerce_new_order', [ $this, 'on_new_order' ], 10, 2 );
		add_action( 'woocommerce_order_status_changed', [ $this, 'on_order_status_changed' ], 10, 4 );

		// Product catalog.
		add_action( 'woocommerce_update_product', [ $this, 'on_product_updated' ], 10, 2 );
		add_action( 'woocommerce_new_product', [ $this, 'on_product_created' ], 10, 2 );

		// Abandoned checkout: recurring sweep for stale draft orders.
		add_action( 'kuba_labs_sweep_abandoned', [ $this, 'sweep_abandoned_checkouts' ] );
		add_action( 'action_scheduler_init', [ $this, 'schedule_abandoned_sweep' ] );

		// Classic checkout capture script (Blocks checkout is handled by draft orders).
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_capture_script' ] );
	}

	// ------------------------------------------------------------------
	// Order events
	// ------------------------------------------------------------------

	public function on_new_order( int $order_id, \WC_Order $order ): void {
		$capture_checkout_id = $this->cleanup_capture_for_session();
		if ( $capture_checkout_id ) {
			$order->update_meta_data( '_kuba_capture_id', (string) $capture_checkout_id );
			$order->save();
		}
		$this->queue_event( 'order.created', [ 'order_id' => $order_id ] );
	}

	public function on_order_status_changed( int $order_id, string $old_status, string $new_status, \WC_Order $order ): void {
		$defaults = [
			'completed' => 'Delivered',
			'cancelled' => 'cancelled',
			'refunded'  => 'refunded',
		];
		$saved   = get_option( 'kuba_labs_status_map', [] );
		$mapping = array_merge( $defaults, is_array( $saved ) ? $saved : [] );
		$mapped_event = $mapping[ $new_status ] ?? null;

		$this->queue_event( 'order.status_changed', [
			'order_id'     => $order_id,
			'old_status'   => $old_status,
			'new_status'   => $new_status,
			'mapped_event' => $mapped_event,
		] );
	}

	// ------------------------------------------------------------------
	// Product events
	// ------------------------------------------------------------------

	public function on_product_updated( int $product_id, \WC_Product $product ): void {
		$this->queue_event( 'product.updated', [ 'product_id' => $product_id ] );
	}

	public function on_product_created( int $product_id, \WC_Product $product ): void {
		$this->queue_event( 'product.created', [ 'product_id' => $product_id ] );
	}

	// ------------------------------------------------------------------
	// Abandoned checkout sweep
	// ------------------------------------------------------------------

	public function schedule_abandoned_sweep(): void {
		if ( ! as_has_scheduled_action( 'kuba_labs_sweep_abandoned', null, 'kuba-labs' ) ) {
			as_schedule_recurring_action( time() + 300, 300, 'kuba_labs_sweep_abandoned', [], 'kuba-labs' );
		}
	}

	/**
	 * Find checkout-draft orders older than KUBA_LABS_ABANDONED_CART_DELAY
	 * that have a phone or email, and send them as abandoned checkouts.
	 *
	 * WooCommerce Blocks creates draft orders as customers fill in checkout.
	 * If they never complete, the order stays in checkout-draft status.
	 */
	public function sweep_abandoned_checkouts(): void {
		// --- Blocks checkout: draft orders ---
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - KUBA_LABS_ABANDONED_CART_DELAY );

		$orders = wc_get_orders( [
			'status'       => 'checkout-draft',
			'date_before'  => $cutoff,
			'limit'        => 20,
			'orderby'      => 'date',
			'order'        => 'ASC',
		] );

		foreach ( $orders as $order ) {
			$phone = $order->get_billing_phone();
			$email = $order->get_billing_email();

			if ( empty( $phone ) && empty( $email ) ) {
				continue;
			}

			if ( $order->get_meta( '_kuba_abandoned_sent' ) ) {
				continue;
			}

			$this->queue_event( 'checkout.abandoned', [ 'order_id' => $order->get_id() ] );

			$order->update_meta_data( '_kuba_abandoned_sent', gmdate( 'c' ) );
			$order->save();
		}

		// --- Classic checkout: field captures stored in wp_options ---
		$this->sweep_classic_captures();
	}

	private function sweep_classic_captures(): void {
		global $wpdb;

		$prefix = $wpdb->esc_like( '_kuba_capture_' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
				$prefix
			)
		);

		if ( empty( $rows ) ) {
			return;
		}

		$cutoff  = time() - KUBA_LABS_ABANDONED_CART_DELAY;
		$max_age = time() - ( 48 * HOUR_IN_SECONDS );

		foreach ( $rows as $row ) {
			$data = maybe_unserialize( $row->option_value );
			if ( ! is_array( $data ) || empty( $data['captured_at'] ) ) {
				delete_option( $row->option_name );
				continue;
			}

			// Purge stale captures older than 48 h.
			if ( $data['captured_at'] < $max_age ) {
				delete_option( $row->option_name );
				continue;
			}

			// Not old enough to be considered abandoned yet.
			if ( $data['captured_at'] > $cutoff ) {
				continue;
			}

			// Already queued.
			if ( ! empty( $data['sent'] ) ) {
				continue;
			}

			if ( empty( $data['phone'] ) && empty( $data['email'] ) ) {
				delete_option( $row->option_name );
				continue;
			}

			$this->queue_event( 'checkout.abandoned', [
				'capture_key' => $row->option_name,
			] );

			$data['sent'] = gmdate( 'c' );
			update_option( $row->option_name, $data, false );
		}
	}

	// ------------------------------------------------------------------
	// Classic checkout capture — JS enqueue & cleanup
	// ------------------------------------------------------------------

	public function enqueue_capture_script(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) {
			return;
		}

		wp_enqueue_script(
			'kuba-checkout-capture',
			KUBA_LABS_PLUGIN_URL . 'assets/js/checkout-capture.js',
			[],
			KUBA_LABS_VERSION,
			[ 'strategy' => 'defer', 'in_footer' => true ]
		);

		wp_localize_script( 'kuba-checkout-capture', 'kubaCheckoutCapture', [
			'endpoint'  => esc_url_raw( rest_url( 'kuba-labs/v1/checkout-capture' ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'selectors' => apply_filters( 'kuba_checkout_capture_selectors', [
				'phone'      => '#billing_phone',
				'email'      => '#billing_email',
				'first_name' => '#billing_first_name',
				'last_name'  => '#billing_last_name',
			] ),
		] );
	}

	/**
	 * Delete the capture for the current WC session and return the synthetic
	 * checkout ID so the caller can attach it to the order for backend
	 * completion tracking.
	 */
	private function cleanup_capture_for_session(): ?int {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return null;
		}
		$session_key = WC()->session->get_customer_id();
		if ( empty( $session_key ) ) {
			return null;
		}

		$option_key = '_kuba_capture_' . md5( $session_key );
		$data       = get_option( $option_key );
		delete_option( $option_key );

		if ( ! is_array( $data ) ) {
			return null;
		}

		// Same synthetic ID that hydrate_capture() generates.
		return hexdec( substr( md5( $option_key ), 0, 7 ) );
	}


	/**
	 * Queue an event for delivery via Action Scheduler.
	 *
	 * We only store the topic and IDs here. The Sender class hydrates the full
	 * data from WooCommerce at send-time, so we always send the latest state.
	 * Deduplicates: skips if an identical action is already pending.
	 */
	private function queue_event( string $topic, array $args ): void {
		$payload = [ array_merge( $args, [ 'topic' => $topic ] ) ];

		$dedup_key = '_kuba_dedup_' . md5( wp_json_encode( $payload ) );
		if ( get_transient( $dedup_key ) ) {
			return;
		}

		if ( as_has_scheduled_action( 'kuba_labs_send_event', $payload, 'kuba-labs' ) ) {
			return;
		}

		set_transient( $dedup_key, 1, 60 );
		as_enqueue_async_action( 'kuba_labs_send_event', $payload, 'kuba-labs' );
	}
}
