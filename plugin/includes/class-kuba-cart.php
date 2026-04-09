<?php

namespace Kuba_Labs;

defined( 'ABSPATH' ) || exit;

/**
 * Handles multi-product add-to-cart via a custom URL parameter.
 *
 * Usage:  https://store.com/?kuba_cart=15:2,23:1
 * Format: product_id:quantity pairs, comma-separated.
 *         Quantity defaults to 1 if omitted (e.g. ?kuba_cart=15,23).
 *
 * Clears the cart first, adds all products, then redirects to checkout.
 */
class Cart {

	public function __construct() {
		add_action( 'template_redirect', [ $this, 'handle_kuba_cart' ] );
	}

	public function handle_kuba_cart(): void {
		if ( empty( $_GET['kuba_cart'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		$raw = sanitize_text_field( wp_unslash( $_GET['kuba_cart'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$items = $this->parse_cart_param( $raw );

		if ( empty( $items ) ) {
			return;
		}

		// Clear existing cart to avoid mixing with old items.
		WC()->cart->empty_cart();

		foreach ( $items as $item ) {
			WC()->cart->add_to_cart( $item['product_id'], $item['quantity'], $item['variation_id'] );
		}

		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	/**
	 * Parse the kuba_cart parameter.
	 *
	 * Supports:
	 *   "15:2,23:1"     → product 15 qty 2, product 23 qty 1
	 *   "15,23"         → product 15 qty 1, product 23 qty 1
	 *   "15:2"          → product 15 qty 2
	 *   "v42:1"         → variation 42 qty 1 (parent resolved automatically)
	 *
	 * @return array<array{product_id: int, variation_id: int, quantity: int}>
	 */
	private function parse_cart_param( string $raw ): array {
		$items  = [];
		$pieces = explode( ',', $raw );

		foreach ( $pieces as $piece ) {
			$piece = trim( $piece );
			if ( '' === $piece ) {
				continue;
			}

			$is_variation = str_starts_with( $piece, 'v' );
			if ( $is_variation ) {
				$piece = substr( $piece, 1 );
			}

			$parts = explode( ':', $piece );
			$id    = absint( $parts[0] ?? 0 );
			$qty   = max( 1, absint( $parts[1] ?? 1 ) );

			if ( $id <= 0 ) {
				continue;
			}

			if ( $is_variation ) {
				$variation = wc_get_product( $id );
				$parent_id = $variation ? $variation->get_parent_id() : 0;
				if ( $parent_id <= 0 ) {
					continue;
				}
				$items[] = [
					'product_id'   => $parent_id,
					'variation_id' => $id,
					'quantity'     => $qty,
				];
			} else {
				$items[] = [
					'product_id'   => $id,
					'variation_id' => 0,
					'quantity'     => $qty,
				];
			}
		}

		return $items;
	}
}
