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
		$connect_url = add_query_arg(
			[
				'store_id'     => $store_id,
				'store_url'    => rawurlencode( get_site_url() ),
				'callback_url' => rawurlencode( rest_url( 'kuba-labs/v1/connect' ) ),
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
}
