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
	}

	// ------------------------------------------------------------------
	// Order events
	// ------------------------------------------------------------------

	public function on_new_order( int $order_id, \WC_Order $order ): void {
		$this->queue_event( 'order.created', [ 'order_id' => $order_id ] );
	}

	public function on_order_status_changed( int $order_id, string $old_status, string $new_status, \WC_Order $order ): void {
		$this->queue_event( 'order.status_changed', [
			'order_id'   => $order_id,
			'old_status' => $old_status,
			'new_status' => $new_status,
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

			// Only send if we have at least a phone or email.
			if ( empty( $phone ) && empty( $email ) ) {
				continue;
			}

			// Mark as sent so we don't send again. Use order meta as a flag.
			if ( $order->get_meta( '_kuba_abandoned_sent' ) ) {
				continue;
			}

			$this->queue_event( 'checkout.abandoned', [ 'order_id' => $order->get_id() ] );

			$order->update_meta_data( '_kuba_abandoned_sent', gmdate( 'c' ) );
			$order->save();
		}
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Queue an event for delivery via Action Scheduler.
	 *
	 * We only store the topic and IDs here. The Sender class hydrates the full
	 * data from WooCommerce at send-time, so we always send the latest state.
	 * Deduplicates: skips if an identical action is already pending.
	 */
	private function queue_event( string $topic, array $args ): void {
		$payload = [ array_merge( $args, [ 'topic' => $topic ] ) ];

		if ( as_has_scheduled_action( 'kuba_labs_send_event', $payload, 'kuba-labs' ) ) {
			return;
		}

		as_enqueue_async_action( 'kuba_labs_send_event', $payload, 'kuba-labs' );
	}
}
