<?php

namespace Kuba_Labs;

defined( 'ABSPATH' ) || exit;

/**
 * Enhances the WooCommerce checkout with:
 *   1. Country calling code select field (via WC Additional Checkout Fields API).
 *   2. WhatsApp consent checkbox (via WC Additional Checkout Fields API).
 *
 * Uses the official API so it works on both Classic and Blocks checkout.
 */
class Checkout {

	const FIELD_COUNTRY_CODE    = 'kuba-labs/country-calling-code';
	const FIELD_WHATSAPP_CONSENT = 'kuba-labs/whatsapp-consent';

	const CALLING_CODES = [
		'IT' => [ '39',  'Italy (+39)' ],
		'US' => [ '1',   'United States (+1)' ],
		'GB' => [ '44',  'United Kingdom (+44)' ],
		'DE' => [ '49',  'Germany (+49)' ],
		'FR' => [ '33',  'France (+33)' ],
		'ES' => [ '34',  'Spain (+34)' ],
		'PT' => [ '351', 'Portugal (+351)' ],
		'NL' => [ '31',  'Netherlands (+31)' ],
		'BE' => [ '32',  'Belgium (+32)' ],
		'AT' => [ '43',  'Austria (+43)' ],
		'CH' => [ '41',  'Switzerland (+41)' ],
		'PL' => [ '48',  'Poland (+48)' ],
		'SE' => [ '46',  'Sweden (+46)' ],
		'NO' => [ '47',  'Norway (+47)' ],
		'DK' => [ '45',  'Denmark (+45)' ],
		'FI' => [ '358', 'Finland (+358)' ],
		'IE' => [ '353', 'Ireland (+353)' ],
		'GR' => [ '30',  'Greece (+30)' ],
		'RO' => [ '40',  'Romania (+40)' ],
		'CZ' => [ '420', 'Czech Republic (+420)' ],
		'HR' => [ '385', 'Croatia (+385)' ],
		'HU' => [ '36',  'Hungary (+36)' ],
		'BG' => [ '359', 'Bulgaria (+359)' ],
		'SK' => [ '421', 'Slovakia (+421)' ],
		'SI' => [ '386', 'Slovenia (+386)' ],
		'LT' => [ '370', 'Lithuania (+370)' ],
		'LV' => [ '371', 'Latvia (+371)' ],
		'EE' => [ '372', 'Estonia (+372)' ],
		'MT' => [ '356', 'Malta (+356)' ],
		'CY' => [ '357', 'Cyprus (+357)' ],
		'LU' => [ '352', 'Luxembourg (+352)' ],
		'BR' => [ '55',  'Brazil (+55)' ],
		'MX' => [ '52',  'Mexico (+52)' ],
		'AR' => [ '54',  'Argentina (+54)' ],
		'CO' => [ '57',  'Colombia (+57)' ],
		'CL' => [ '56',  'Chile (+56)' ],
		'AU' => [ '61',  'Australia (+61)' ],
		'NZ' => [ '64',  'New Zealand (+64)' ],
		'JP' => [ '81',  'Japan (+81)' ],
		'KR' => [ '82',  'South Korea (+82)' ],
		'IN' => [ '91',  'India (+91)' ],
		'CA' => [ '1',   'Canada (+1)' ],
		'AE' => [ '971', 'UAE (+971)' ],
		'SA' => [ '966', 'Saudi Arabia (+966)' ],
		'ZA' => [ '27',  'South Africa (+27)' ],
		'TR' => [ '90',  'Turkey (+90)' ],
		'IL' => [ '972', 'Israel (+972)' ],
		'RU' => [ '7',   'Russia (+7)' ],
		'UA' => [ '380', 'Ukraine (+380)' ],
	];

	public function __construct() {
		// Blocks checkout: WC Additional Checkout Fields API.
		add_action( 'woocommerce_init', [ $this, 'register_fields' ] );

		// Classic checkout: render fields via PHP hooks.
		add_filter( 'woocommerce_checkout_fields', [ $this, 'add_classic_fields' ] );
		add_action( 'woocommerce_after_checkout_billing_form', [ $this, 'render_classic_consent' ] );

		// Order processing — Classic checkout.
		add_action( 'woocommerce_checkout_order_processed', [ $this, 'normalize_phone_on_order' ], 10, 3 );
		add_action( 'woocommerce_checkout_order_processed', [ $this, 'save_consent_on_order' ], 10, 3 );

		// Order processing — Blocks checkout (Store API).
		add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'normalize_phone_on_order_blocks' ] );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'save_consent_on_order_blocks' ] );
	}

	// ------------------------------------------------------------------
	// Field registration (WC Additional Checkout Fields API)
	// ------------------------------------------------------------------

	public function register_fields(): void {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return; // WC < 8.9, API not available.
		}

		if ( $this->is_phone_intl_enabled() ) {
			woocommerce_register_additional_checkout_field( [
				'id'       => self::FIELD_COUNTRY_CODE,
				'label'    => __( 'Country code', 'kuba-labs' ),
				'location' => 'address',
				'type'     => 'select',
				'options'  => $this->get_calling_code_options(),
			] );
		}

		if ( $this->is_consent_enabled() ) {
			$label = get_option(
				'kuba_labs_consent_label',
				__( "Ricevi aggiornamenti sull'ordine e offerte via WhatsApp", 'kuba-labs' )
			);

			woocommerce_register_additional_checkout_field( [
				'id'       => self::FIELD_WHATSAPP_CONSENT,
				'label'    => $label,
				'location' => 'contact',
				'type'     => 'checkbox',
			] );
		}
	}

	// ------------------------------------------------------------------
	// Classic checkout fields (PHP-rendered)
	// ------------------------------------------------------------------

	/**
	 * Add country code select to the classic checkout billing fields.
	 */
	public function add_classic_fields( array $fields ): array {
		if ( $this->is_phone_intl_enabled() ) {
			$options = [ '' => __( 'Select country code', 'kuba-labs' ) ];
			foreach ( self::CALLING_CODES as $iso => $data ) {
				$options[ $iso ] = $data[1];
			}

			$fields['billing']['kuba_country_code'] = [
				'type'     => 'select',
				'label'    => __( 'Country code', 'kuba-labs' ),
				'required' => false,
				'options'  => $options,
				'priority' => 95, // Just before phone (100).
				'class'    => [ 'form-row-wide' ],
			];
		}

		return $fields;
	}

	/**
	 * Render WhatsApp consent checkbox on classic checkout.
	 */
	public function render_classic_consent(): void {
		if ( ! $this->is_consent_enabled() ) {
			return;
		}

		$label = get_option(
			'kuba_labs_consent_label',
			__( "Ricevi aggiornamenti sull'ordine e offerte via WhatsApp", 'kuba-labs' )
		);

		woocommerce_form_field( 'kuba_whatsapp_consent', [
			'type'  => 'checkbox',
			'label' => $label,
			'class' => [ 'form-row-wide' ],
		] );
	}

	// ------------------------------------------------------------------
	// Phone normalization: prepend country code to billing_phone
	// ------------------------------------------------------------------

	/** Blocks checkout wrapper — receives only the order object. */
	public function normalize_phone_on_order_blocks( $order ): void {
		$this->normalize_phone_on_order( $order->get_id(), [], $order );
	}

	public function normalize_phone_on_order( int $order_id, $posted_data, $order ): void {
		if ( ! $this->is_phone_intl_enabled() ) {
			return;
		}
		if ( ! $order instanceof \WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) {
			return;
		}

		// Blocks checkout stores as _wc_billing/{field_id}.
		// Classic checkout stores via POST as kuba_country_code.
		$country_iso = $order->get_meta( '_wc_billing/' . self::FIELD_COUNTRY_CODE );
		if ( empty( $country_iso ) && is_array( $posted_data ) && ! empty( $posted_data['kuba_country_code'] ) ) {
			$country_iso = sanitize_text_field( $posted_data['kuba_country_code'] );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( empty( $country_iso ) && ! empty( $_POST['kuba_country_code'] ) ) {
			$country_iso = sanitize_text_field( wp_unslash( $_POST['kuba_country_code'] ) );
		}
		if ( empty( $country_iso ) ) {
			return;
		}

		$calling_code = $this->iso_to_calling_code( $country_iso );
		if ( empty( $calling_code ) ) {
			return;
		}

		$phone = $order->get_billing_phone();
		if ( empty( $phone ) ) {
			return;
		}

		// Extract digits only.
		$digits = preg_replace( '/[^0-9]/', '', $phone );

		// If the phone already starts with the calling code, don't double-prepend.
		// e.g. calling_code="39", digits="393348299274" → already has it.
		if ( str_starts_with( $digits, $calling_code ) ) {
			$normalized = '+' . $digits;
		} else {
			// Strip leading zeros (local format) and prepend.
			$digits     = ltrim( $digits, '0' );
			$normalized = '+' . $calling_code . $digits;
		}

		$order->set_billing_phone( $normalized );
		$order->save();
	}

	// ------------------------------------------------------------------
	// Consent: save to consent table + order meta
	// ------------------------------------------------------------------

	/** Blocks checkout wrapper. */
	public function save_consent_on_order_blocks( $order ): void {
		$this->save_consent_on_order( $order->get_id(), [], $order );
	}

	public function save_consent_on_order( int $order_id, $posted_data, $order ): void {
		if ( ! $this->is_consent_enabled() ) {
			return;
		}
		if ( ! $order instanceof \WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) {
			return;
		}

		// Blocks checkout stores consent in contact section meta.
		$consent = $order->get_meta( '_wc_other/' . self::FIELD_WHATSAPP_CONSENT );
		if ( '' === $consent ) {
			$consent = $order->get_meta( '_wc_billing/' . self::FIELD_WHATSAPP_CONSENT );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( '' === $consent && isset( $_POST['kuba_whatsapp_consent'] ) ) {
			$consent = $_POST['kuba_whatsapp_consent'];
		}

		$is_opted_in = in_array( $consent, [ '1', 'true', true, 1 ], true );

		// Store in our consent table.
		$session_token = WC()->session ? WC()->session->get_customer_id() : '';
		if ( $session_token ) {
			Consent_Table::save_consent(
				$order->get_billing_phone(),
				$is_opted_in,
				$session_token
			);
			Consent_Table::link_to_order( $session_token, $order_id );
		}

		// Also store as simple order meta for easy access.
		$order->update_meta_data( '_kuba_whatsapp_consent', $is_opted_in ? 'yes' : 'no' );
		$order->save();
	}

	// ------------------------------------------------------------------
	// Calling code options
	// ------------------------------------------------------------------

	private function get_calling_code_options(): array {
		$options = [];
		foreach ( self::CALLING_CODES as $iso => $data ) {
			$options[] = [
				'value' => $iso,
				'label' => $data[1],
			];
		}
		return $options;
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	private function iso_to_calling_code( string $iso ): string {
		$codes = self::CALLING_CODES;
		return $codes[ strtoupper( $iso ) ][0] ?? '';
	}

	private function is_phone_intl_enabled(): bool {
		return 'yes' === get_option( 'kuba_labs_phone_intl', 'yes' );
	}

	private function is_consent_enabled(): bool {
		return 'yes' === get_option( 'kuba_labs_whatsapp_consent', 'yes' );
	}
}
