<?php

namespace Kuba_Labs;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce settings tab for Kuba Labs.
 *
 * Extends WC_Settings_Page — WC handles form rendering, Save button, and saving.
 * We only provide the settings array and custom HTML for connection status.
 */
class Settings extends \WC_Settings_Page {

	public function __construct() {
		$this->id    = 'kuba_labs';
		$this->label = __( 'Kuba Labs', 'kuba-labs' );
		parent::__construct();

		// Render connection status above the settings fields.
		add_action( 'woocommerce_sections_' . $this->id, [ $this, 'render_connection_status' ] );

		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_styles' ] );

		// Custom field type for status mapping.
		add_action( 'woocommerce_admin_field_kuba_status_mapping', [ $this, 'render_status_mapping' ] );
		add_action( 'woocommerce_settings_save_' . $this->id, [ $this, 'save_status_mapping' ] );

		// Disconnect hook registered separately in Plugin::init() — see class-kuba-plugin.php.
	}

	public static function register( array $settings ): array {
		$settings[] = new self();
		return $settings;
	}

	// ------------------------------------------------------------------
	// Settings fields — WC renders and saves these automatically.
	// ------------------------------------------------------------------

	protected function get_settings_for_default_section() {
		return [
			[
				'title' => __( 'Checkout Enhancements', 'kuba-labs' ),
				'type'  => 'title',
				'desc'  => __( 'Improve phone number quality and collect WhatsApp opt-in at checkout.', 'kuba-labs' ),
				'id'    => 'kuba_labs_checkout_section',
			],
			[
				'title'   => __( 'Phone normalization', 'kuba-labs' ),
				'type'    => 'checkbox',
				'id'      => 'kuba_labs_phone_intl',
				'default' => 'yes',
				'desc'    => __( 'Replace the billing phone field with an international phone input (country code selector).', 'kuba-labs' ),
			],
			[
				'title'   => __( 'WhatsApp consent checkbox', 'kuba-labs' ),
				'type'    => 'checkbox',
				'id'      => 'kuba_labs_whatsapp_consent',
				'default' => 'yes',
				'desc'    => __( 'Show WhatsApp opt-in checkbox at checkout.', 'kuba-labs' ),
			],
			[
				'title'   => __( 'Consent label', 'kuba-labs' ),
				'type'    => 'text',
				'id'      => 'kuba_labs_consent_label',
				'default' => 'Ricevi aggiornamenti sull\'ordine e offerte via WhatsApp',
				'css'     => 'min-width: 400px;',
			],
			[
				'type' => 'sectionend',
				'id'   => 'kuba_labs_checkout_section',
			],
			[
				'title' => __( 'WhatsApp Widget', 'kuba-labs' ),
				'type'  => 'title',
				'desc'  => __( 'Show a WhatsApp button on your storefront. Configure the widget appearance in your Kuba Labs dashboard.', 'kuba-labs' ),
				'id'    => 'kuba_labs_widget_section',
			],
			[
				'title'   => __( 'Enable widget', 'kuba-labs' ),
				'type'    => 'checkbox',
				'id'      => 'kuba_labs_widget_enabled',
				'default' => 'yes',
				'desc'    => __( 'Show WhatsApp chat button on your store.', 'kuba-labs' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'kuba_labs_widget_section',
			],
			[
				'title' => __( 'Order Status Mapping', 'kuba-labs' ),
				'type'  => 'title',
				'desc'  => __( 'Map your WooCommerce order statuses to Kuba events. This controls which automations trigger when an order changes status.', 'kuba-labs' ),
				'id'    => 'kuba_labs_status_mapping_section',
			],
			[
				'type' => 'kuba_status_mapping',
				'id'   => 'kuba_labs_status_map',
			],
			[
				'type' => 'sectionend',
				'id'   => 'kuba_labs_status_mapping_section',
			],
			[
				'title' => __( 'Shipment Tracking', 'kuba-labs' ),
				'type'  => 'title',
				'desc'  => __( 'Choose how shipment tracking events (shipped, in delivery, delivered) are detected.', 'kuba-labs' ),
				'id'    => 'kuba_labs_tracking_section',
			],
			[
				'title'   => __( 'Tracking mode', 'kuba-labs' ),
				'type'    => 'select',
				'id'      => 'kuba_labs_tracking_mode',
				'default' => 'kuba',
				'options' => [
					'kuba'       => __( 'Kuba tracking — we monitor the tracking code via 17track', 'kuba-labs' ),
					'woocommerce' => __( 'Store-managed — my plugins handle shipping statuses (e.g. ShipStation, AST)', 'kuba-labs' ),
				],
				'desc'    => __( 'If your store has plugins that set shipping statuses (shipped, delivered, etc.), choose "Store-managed" to avoid duplicate notifications.', 'kuba-labs' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'kuba_labs_tracking_section',
			],
		];
	}

	// ------------------------------------------------------------------
	// Admin styles
	// ------------------------------------------------------------------

	public function enqueue_admin_styles( string $hook ): void {
		if ( 'woocommerce_page_wc-settings' !== $hook ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['tab'] ) || 'kuba_labs' !== $_GET['tab'] ) {
			return;
		}
		wp_enqueue_style(
			'kuba-labs-admin',
			KUBA_LABS_PLUGIN_URL . 'admin/css/kuba-admin.css',
			[],
			KUBA_LABS_VERSION
		);
	}

	// ------------------------------------------------------------------
	// Connection status (custom HTML, rendered above settings fields)
	// ------------------------------------------------------------------

	public function render_connection_status(): void {
		$is_connected = Plugin::is_connected();
		$store_id     = Plugin::get_store_id();

		if ( $is_connected ) {
			$this->render_connected_state( $store_id );
		} else {
			$this->render_disconnected_state( $store_id );
		}
	}


	private function render_connected_state( string $store_id ): void {
		$connected_at = get_option( 'kuba_labs_connected_at', '' );
		?>
		<div class="kuba-labs-settings">
			<div class="kuba-labs-status kuba-labs-status--connected">
				<span class="kuba-labs-status__icon">&#10003;</span>
				<div>
					<strong><?php esc_html_e( 'Collegato a Kuba Labs', 'kuba-labs' ); ?></strong>
					<?php if ( $connected_at ) : ?>
						<p class="description">
							<?php
							printf(
								/* translators: %s: date string */
								esc_html__( 'Collegato dal %s', 'kuba-labs' ),
								esc_html( wp_date( get_option( 'date_format' ), strtotime( $connected_at ) ) )
							);
							?>
						</p>
					<?php endif; ?>
				</div>
			</div>

			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Store ID', 'kuba-labs' ); ?></th>
					<td><code><?php echo esc_html( $store_id ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Store URL', 'kuba-labs' ); ?></th>
					<td><code><?php echo esc_html( get_site_url() ); ?></code></td>
				</tr>
			</table>

			<p>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=kuba_labs_disconnect' ), 'kuba_labs_disconnect' ) ); ?>"
					class="button kuba-labs-disconnect-btn"
					onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to disconnect from Kuba Labs? Events will stop being sent.', 'kuba-labs' ); ?>');">
					<?php esc_html_e( 'Disconnect', 'kuba-labs' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	private function render_disconnected_state( string $store_id ): void {
		$connect_token = get_transient( 'kuba_labs_connect_token' );
		if ( empty( $connect_token ) ) {
			$connect_token = wp_generate_password( 48, false );
			set_transient( 'kuba_labs_connect_token', $connect_token, HOUR_IN_SECONDS );
		}

		$connect_url = add_query_arg(
			[
				'store_id'      => $store_id,
				'store_url'     => rawurlencode( get_site_url() ),
				'callback_url'  => rawurlencode( rest_url( 'kuba-labs/v1/connect' ) ),
				'connect_token' => $connect_token,
			],
			KUBA_LABS_FRONTEND_BASE . '/connect/woocommerce'
		);
		?>
		<div class="kuba-labs-settings">
			<div class="kuba-labs-status kuba-labs-status--disconnected">
				<span class="kuba-labs-status__icon">&#9679;</span>
				<div>
					<strong><?php esc_html_e( 'Not connected', 'kuba-labs' ); ?></strong>
					<p class="description">
						<?php esc_html_e( 'Connect your store to Kuba Labs to enable WhatsApp marketing automation.', 'kuba-labs' ); ?>
					</p>
				</div>
			</div>

			<p>
				<a href="<?php echo esc_url( $connect_url ); ?>" class="button button-primary button-hero">
					<?php esc_html_e( 'Connect to Kuba Labs', 'kuba-labs' ); ?>
				</a>
			</p>

			<p class="description">
				<?php
				printf(
					/* translators: %s: URL to Kuba Labs */
					esc_html__( 'Don\'t have an account? %s', 'kuba-labs' ),
					'<a href="' . esc_url( KUBA_LABS_FRONTEND_BASE . '/account/signup' ) . '" target="_blank">' .
					esc_html__( 'Sign up for free', 'kuba-labs' ) . '</a>'
				);
				?>
			</p>
		</div>
		<?php
	}

	// ------------------------------------------------------------------
	// Status mapping — custom WC settings field
	// ------------------------------------------------------------------

	/** Kuba events a WC status can be mapped to. */
	private static function kuba_events(): array {
		return [
			''                => __( '— ignore —', 'kuba-labs' ),
			'date_created'    => __( 'New order', 'kuba-labs' ),
			'date_shipped'    => __( 'Order shipped', 'kuba-labs' ),
			'OutForDelivery'  => __( 'Out for delivery', 'kuba-labs' ),
			'Delivered'       => __( 'Delivered', 'kuba-labs' ),
			'cancelled'       => __( 'Order cancelled', 'kuba-labs' ),
			'refunded'        => __( 'Order refunded', 'kuba-labs' ),
		];
	}

	/** Default mapping for standard WC statuses. */
	public static function default_status_map(): array {
		return [
			'completed'  => 'Delivered',
			'cancelled'  => 'cancelled',
			'refunded'   => 'refunded',
			'processing' => '',
			'on-hold'    => '',
			'failed'     => '',
		];
	}

	/** Get the saved mapping, merged with defaults for any new statuses. */
	public static function get_status_map(): array {
		$saved    = get_option( 'kuba_labs_status_map', [] );
		$defaults = self::default_status_map();
		return is_array( $saved ) ? array_merge( $defaults, $saved ) : $defaults;
	}

	public function render_status_mapping( $value ): void {
		$statuses    = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : [];
		$mapping     = self::get_status_map();
		$kuba_events = self::kuba_events();
		?>
		<tr>
			<td colspan="2" style="padding: 0;">
				<table class="widefat striped" style="max-width: 600px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'WooCommerce Status', 'kuba-labs' ); ?></th>
							<th><?php esc_html_e( 'Kuba Event', 'kuba-labs' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $statuses as $slug => $label ) :
							// WC status slugs have 'wc-' prefix, strip it.
							$status_key = str_replace( 'wc-', '', $slug );
							// Skip internal statuses handled separately by the plugin.
							if ( 'checkout-draft' === $status_key ) { continue; }
							$current    = $mapping[ $status_key ] ?? '';
						?>
						<tr>
							<td><?php echo esc_html( $label ); ?> <code><?php echo esc_html( $status_key ); ?></code></td>
							<td>
								<select name="kuba_labs_status_map[<?php echo esc_attr( $status_key ); ?>]">
									<?php foreach ( $kuba_events as $event_value => $event_label ) : ?>
										<option value="<?php echo esc_attr( $event_value ); ?>"
											<?php selected( $current, $event_value ); ?>>
											<?php echo esc_html( $event_label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</td>
		</tr>
		<?php
	}

	public function save_status_mapping(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing — WC handles nonce.
		if ( isset( $_POST['kuba_labs_status_map'] ) && is_array( $_POST['kuba_labs_status_map'] ) ) {
			$raw = array_map( 'sanitize_text_field', wp_unslash( $_POST['kuba_labs_status_map'] ) );
			$valid_events = array_keys( self::kuba_events() );
			$clean = [];
			foreach ( $raw as $status => $event ) {
				if ( in_array( $event, $valid_events, true ) ) {
					$clean[ sanitize_key( $status ) ] = $event;
				}
			}
			update_option( 'kuba_labs_status_map', $clean );
		}
	}
}
