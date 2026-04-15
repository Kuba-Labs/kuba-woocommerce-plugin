<?php

namespace Kuba_Labs;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the REST endpoint that receives the OAuth callback from Kuba frontend.
 *
 * Flow:
 * 1. Merchant clicks "Connect to Kuba Labs" in WC Settings.
 * 2. Redirected to app.kubalabs.com/connect/woocommerce with store_id, store_url, callback_url.
 * 3. Merchant logs in / approves.
 * 4. Kuba backend generates webhook_secret, sends it via POST to our callback_url.
 * 5. We also generate WooCommerce REST API keys and send them to Kuba backend.
 */
class REST {

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( 'kuba-labs/v1', '/connect', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_connect' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( 'kuba-labs/v1', '/policies', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'handle_get_policies' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( 'kuba-labs/v1', '/consent', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_consent' ],
			'permission_callback' => function () {
				return wp_verify_nonce( sanitize_text_field( $_SERVER['HTTP_X_WP_NONCE'] ?? '' ), 'wp_rest' );
			},
			'args'                => [
				'phone'         => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
				'consent'       => [ 'required' => true, 'type' => 'boolean' ],
				'session_token' => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
			],
		] );

		register_rest_route( 'kuba-labs/v1', '/checkout-capture', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_checkout_capture' ],
			'permission_callback' => function () {
				return wp_verify_nonce( sanitize_text_field( $_SERVER['HTTP_X_WP_NONCE'] ?? '' ), 'wp_rest' );
			},
			'args'                => [
				'phone'      => [ 'sanitize_callback' => 'sanitize_text_field' ],
				'email'      => [ 'sanitize_callback' => 'sanitize_email' ],
				'first_name' => [ 'sanitize_callback' => 'sanitize_text_field' ],
				'last_name'  => [ 'sanitize_callback' => 'sanitize_text_field' ],
				'country'    => [ 'sanitize_callback' => 'sanitize_text_field' ],
				'cart_total' => [ 'sanitize_callback' => 'sanitize_text_field' ],
			],
		] );

		register_rest_route( 'kuba-labs/v1', '/last-order-by-phone', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'handle_last_order_by_phone' ],
			'permission_callback' => [ $this, 'check_wc_api_key_permission' ],
			'args'                => [
				'phone' => [
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		] );
	}

	/**
	 * Verify that the request contains valid WooCommerce REST API credentials.
	 * Supports Basic Auth (HTTPS) and query param auth (consumer_key/consumer_secret).
	 */
	public function check_wc_api_key_permission( \WP_REST_Request $request ): bool {
		// Try Basic Auth header first.
		$consumer_key    = '';
		$consumer_secret = '';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_SERVER['PHP_AUTH_USER'] ) ) {
			$consumer_key    = sanitize_text_field( $_SERVER['PHP_AUTH_USER'] );
			$consumer_secret = sanitize_text_field( $_SERVER['PHP_AUTH_PW'] ?? '' );
		}
		// Fall back to query params.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $consumer_key ) && ! empty( $_GET['consumer_key'] ) ) {
			$consumer_key    = sanitize_text_field( wp_unslash( $_GET['consumer_key'] ) );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$consumer_secret = sanitize_text_field( wp_unslash( $_GET['consumer_secret'] ?? '' ) );
		}

		if ( empty( $consumer_key ) || empty( $consumer_secret ) ) {
			return false;
		}

		// WC stores hashed consumer_key in the DB.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT consumer_secret, permissions FROM {$wpdb->prefix}woocommerce_api_keys WHERE consumer_key = %s",
			wc_api_hash( $consumer_key )
		) );

		if ( ! $row ) {
			return false;
		}

		return hash_equals( $row->consumer_secret, $consumer_secret );
	}

	/**
	 * Save WhatsApp consent from the checkout page.
	 * Called immediately when the customer toggles the checkbox.
	 */
	public function handle_consent( \WP_REST_Request $request ): \WP_REST_Response {
		$phone         = $request->get_param( 'phone' );
		$consent       = (bool) $request->get_param( 'consent' );
		$session_token = $request->get_param( 'session_token' );

		Consent_Table::save_consent( $phone, $consent, $session_token );

		return new \WP_REST_Response( [ 'success' => true ], 200 );
	}

	/**
	 * Store checkout field data so the abandoned-cart sweep can detect classic
	 * checkout abandonments (where no draft order exists).
	 */
	public function handle_checkout_capture( \WP_REST_Request $request ): \WP_REST_Response {
		$phone      = $request->get_param( 'phone' ) ?? '';
		$email      = $request->get_param( 'email' ) ?? '';
		$first_name = $request->get_param( 'first_name' ) ?? '';
		$last_name  = $request->get_param( 'last_name' ) ?? '';
		$country    = $request->get_param( 'country' ) ?? '';

		if ( empty( $phone ) && empty( $email ) ) {
			return new \WP_REST_Response( [ 'skipped' => true ], 200 );
		}

		// WC sessions aren't loaded during REST requests, so we derive a
		// stable key from the WC session cookie the browser already has.
		$session_key = '';
		foreach ( $_COOKIE as $name => $value ) {
			if ( str_starts_with( $name, 'wp_woocommerce_session_' ) ) {
				// Cookie value is "customer_id||expiry||expiring||hash".
				$session_key = explode( '||', $value )[0] ?? '';
				break;
			}
		}
		if ( empty( $session_key ) ) {
			return new \WP_REST_Response( [ 'skipped' => true ], 200 );
		}

		// Rate-limit: max 1 capture per session every 5 seconds.
		$throttle = '_kuba_throttle_' . md5( $session_key );
		if ( get_transient( $throttle ) ) {
			return new \WP_REST_Response( [ 'skipped' => true ], 200 );
		}
		set_transient( $throttle, 1, 5 );

		$cart_total = $request->get_param( 'cart_total' ) ?? '0';
		$option_key = '_kuba_capture_' . md5( $session_key );

		update_option( $option_key, [
			'phone'       => $phone,
			'email'       => $email,
			'first_name'  => $first_name,
			'last_name'   => $last_name,
			'country'     => $country,
			'cart_total'  => $cart_total,
			'captured_at' => time(),
		], false );

		return new \WP_REST_Response( [ 'success' => true ], 200 );
	}

	/**
	 * Returns store policy pages (privacy, terms & conditions, refund, etc.).
	 *
	 * Collects pages from:
	 * - WordPress privacy policy page (wp_page_for_privacy_policy)
	 * - WooCommerce terms page (woocommerce_terms_page_id)
	 * - WooCommerce refund/returns page (woocommerce_refund_returns_page_id)
	 */
	public function handle_get_policies(): \WP_REST_Response {
		$policy_sources = [
			'wp_page_for_privacy_policy'           => 'Privacy Policy',
			'woocommerce_terms_page_id'            => 'Terms & Conditions',
			'woocommerce_refund_returns_page_id'   => 'Refund & Returns Policy',
		];

		$policies = [];
		$seen_ids = [];

		foreach ( $policy_sources as $option => $fallback_title ) {
			$page_id = (int) get_option( $option, 0 );
			if ( $page_id <= 0 || isset( $seen_ids[ $page_id ] ) ) {
				continue;
			}

			$page = get_post( $page_id );
			if ( ! $page || 'publish' !== $page->post_status ) {
				continue;
			}

			$seen_ids[ $page_id ] = true;
			$policies[] = [
				'title'   => $page->post_title ?: $fallback_title,
				'content' => apply_filters( 'the_content', $page->post_content ),
			];
		}

		return new \WP_REST_Response( $policies, 200 );
	}

	/**
	 * Returns the creation date of the most recent order for a given phone number.
	 *
	 * Searches billing_phone using wc_get_orders() which works with both
	 * classic (CPT) and HPOS storage.
	 */
	public function handle_last_order_by_phone( \WP_REST_Request $request ): \WP_REST_Response {
		$phone  = $request->get_param( 'phone' );
		$digits = preg_replace( '/\D/', '', $phone );

		// Build a set of plausible variants for the phone number.
		// Merchants store phones inconsistently — with or without country code,
		// with or without +, with spaces/dashes, etc.
		$variants = array_unique( array_filter( [
			$phone,                       // as-is from backend
			$digits,                      // digits only (e.g. "393348299274")
			'+' . $digits,                // with + prefix
			ltrim( $digits, '0' ),        // strip leading zeros
		] ) );

		// Also try without common country code prefixes (1-3 digit codes).
		// This handles the case where backend sends "+393348299274" but WC
		// stores "3348299274" (no country code). We try removing 1, 2, and 3
		// digit prefixes to cover most country codes.
		if ( strlen( $digits ) >= 9 ) {
			for ( $strip = 1; $strip <= 3; $strip++ ) {
				$without_cc = substr( $digits, $strip );
				if ( strlen( $without_cc ) >= 7 ) {
					$variants[] = $without_cc;
				}
			}
			$variants = array_unique( $variants );
		}

		foreach ( $variants as $variant ) {
			$orders = wc_get_orders( [
				'billing_phone' => $variant,
				'orderby'       => 'date',
				'order'         => 'DESC',
				'limit'         => 1,
			] );

			if ( ! empty( $orders ) ) {
				return $this->order_date_response( $orders[0] );
			}
		}

		return new \WP_REST_Response( [ 'date_created_gmt' => null ], 200 );
	}

	private function order_date_response( \WC_Order $order ): \WP_REST_Response {
		return new \WP_REST_Response( [
			'date_created_gmt' => $order->get_date_created()
				? $order->get_date_created()->format( 'Y-m-d\TH:i:s' )
				: null,
		], 200 );
	}

	/**
	 * Receives the connection handshake from Kuba backend.
	 *
	 * Expected JSON body:
	 * {
	 *   "store_id":       "uuid — must match our local store_id",
	 *   "webhook_secret": "string — HMAC signing key for outgoing events"
	 * }
	 *
	 * On success, this endpoint:
	 * 1. Stores the webhook_secret.
	 * 2. Generates WooCommerce REST API keys.
	 * 3. Returns the keys to Kuba backend so it can pull data.
	 */
	public function handle_connect( \WP_REST_Request $request ): \WP_REST_Response {
		$store_id       = sanitize_text_field( $request->get_param( 'store_id' ) );
		$webhook_secret = $request->get_param( 'webhook_secret' );

		if ( empty( $store_id ) || empty( $webhook_secret ) || ! is_string( $webhook_secret ) ) {
			return new \WP_REST_Response(
				[ 'error' => 'Missing required parameters.' ],
				400
			);
		}

		// Reject secrets that are too short or contain unexpected characters.
		if ( strlen( $webhook_secret ) < 32 ) {
			return new \WP_REST_Response(
				[ 'error' => 'webhook_secret must be at least 32 characters.' ],
				400
			);
		}
		if ( ! preg_match( '/^[a-zA-Z0-9+\/=_\-]+$/', $webhook_secret ) ) {
			return new \WP_REST_Response(
				[ 'error' => 'Invalid webhook_secret format.' ],
				400
			);
		}

		// Verify the store_id matches.
		$local_store_id = Plugin::get_store_id();
		if ( $store_id !== $local_store_id ) {
			return new \WP_REST_Response(
				[ 'error' => 'Store ID mismatch.' ],
				403
			);
		}

		// Verify the request is signed with the pre-shared connect_token that
		// was generated when the merchant clicked "Connect" and passed to the
		// Kuba frontend via the redirect URL.
		$connect_token = get_transient( 'kuba_labs_connect_token' );
		if ( empty( $connect_token ) ) {
			return new \WP_REST_Response(
				[ 'error' => 'No pending connection. Please start from the settings page.' ],
				403
			);
		}

		$signature = $request->get_header( 'X-Kuba-Signature' );
		$raw_body  = $request->get_body();

		if ( empty( $signature ) || empty( $raw_body ) ) {
			return new \WP_REST_Response(
				[ 'error' => 'Missing signature.' ],
				401
			);
		}

		$expected = hash_hmac( 'sha256', $raw_body, $connect_token );
		if ( ! hash_equals( $expected, $signature ) ) {
			return new \WP_REST_Response(
				[ 'error' => 'Invalid signature.' ],
				401
			);
		}

		// Token is single-use — delete it immediately after verification.
		delete_transient( 'kuba_labs_connect_token' );

		// Store connection credentials.
		update_option( 'kuba_labs_webhook_secret', $webhook_secret, true );
		update_option( 'kuba_labs_connected_at', gmdate( 'c' ), false );

		$widget_public_key = sanitize_text_field( $request->get_param( 'widget_public_key' ) );
		if ( ! empty( $widget_public_key ) ) {
			update_option( 'kuba_labs_widget_key', $widget_public_key, false );
		}

		// Generate WooCommerce REST API keys for this connection.
		$api_keys = $this->generate_wc_api_keys();
		if ( is_wp_error( $api_keys ) ) {
			return new \WP_REST_Response(
				[ 'error' => $api_keys->get_error_message() ],
				500
			);
		}

		return new \WP_REST_Response( [
			'success'         => true,
			'store_url'       => get_site_url(),
			'consumer_key'    => $api_keys['consumer_key'],
			'consumer_secret' => $api_keys['consumer_secret'],
		], 200 );
	}

	/**
	 * Create a WooCommerce REST API key pair for Kuba Labs.
	 *
	 * Uses the same method WooCommerce uses internally.
	 * Keys are tied to the first administrator (by ID) since this endpoint
	 * is called by the Kuba backend (no WordPress session / current user).
	 */
	private function generate_wc_api_keys(): array|\WP_Error {
		global $wpdb;

		// Remove old Kuba keys if reconnecting.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete(
			$wpdb->prefix . 'woocommerce_api_keys',
			[ 'description' => 'Kuba Labs' ],
			[ '%s' ]
		);

		$consumer_key    = 'ck_' . wc_rand_hash();
		$consumer_secret = 'cs_' . wc_rand_hash();

		// Use the first admin by ID for deterministic key assignment.
		$admin_users = get_users( [
			'role'    => 'administrator',
			'number'  => 1,
			'orderby' => 'ID',
			'order'   => 'ASC',
		] );

		if ( empty( $admin_users ) ) {
			return new \WP_Error( 'no_admin', 'No administrator user found.' );
		}

		$user_id = $admin_users[0]->ID;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->insert(
			$wpdb->prefix . 'woocommerce_api_keys',
			[
				'user_id'         => $user_id,
				'description'     => 'Kuba Labs',
				'permissions'     => 'read_write',
				'consumer_key'    => wc_api_hash( $consumer_key ),
				'consumer_secret' => $consumer_secret,
				'truncated_key'   => substr( $consumer_key, -7 ),
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s' ]
		);

		if ( ! $result ) {
			return new \WP_Error( 'db_error', 'Failed to create API keys.' );
		}

		return [
			'consumer_key'    => $consumer_key,
			'consumer_secret' => $consumer_secret,
		];
	}
}
