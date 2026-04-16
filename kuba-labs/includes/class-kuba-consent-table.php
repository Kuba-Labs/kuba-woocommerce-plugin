<?php

namespace Kuba_Labs;

defined( 'ABSPATH' ) || exit;

/**
 * Manages the kuba_labs_consents database table for storing WhatsApp
 * opt-in consent records before, during, and after checkout.
 */
class Consent_Table {

	const TABLE_NAME = 'kuba_labs_consents';

	/**
	 * Create the consent table. Safe to call multiple times (uses IF NOT EXISTS).
	 */
	public static function create_table(): void {
		global $wpdb;
		$table   = $wpdb->prefix . self::TABLE_NAME;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			phone         VARCHAR(30)     NOT NULL DEFAULT '',
			consent       TINYINT(1)      NOT NULL DEFAULT 0,
			session_token VARCHAR(64)     NOT NULL DEFAULT '',
			order_id      BIGINT UNSIGNED DEFAULT NULL,
			created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY session_token (session_token),
			KEY phone (phone),
			KEY order_id (order_id)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Drop the consent table. Called on plugin uninstall.
	 */
	public static function drop_table(): void {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema change on uninstall; caching doesn't apply.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
	}

	/**
	 * Save a consent record. Upserts by session_token so toggling
	 * the checkbox multiple times updates the same row.
	 */
	public static function save_consent( string $phone, bool $consent, string $session_token ): void {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$existing = $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM %i WHERE session_token = %s LIMIT 1',
			$table,
			$session_token
		) );

		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update(
				$table,
				[
					'phone'   => $phone,
					'consent' => $consent ? 1 : 0,
				],
				[ 'id' => $existing ],
				[ '%s', '%d' ],
				[ '%d' ]
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				$table,
				[
					'phone'         => $phone,
					'consent'       => $consent ? 1 : 0,
					'session_token' => $session_token,
				],
				[ '%s', '%d', '%s' ]
			);
		}
	}

	/**
	 * Link a consent record to an order after checkout completes.
	 */
	public static function link_to_order( string $session_token, int $order_id ): void {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$table,
			[ 'order_id' => $order_id ],
			[ 'session_token' => $session_token ],
			[ '%d' ],
			[ '%s' ]
		);
	}

	/**
	 * Get the latest consent for a session token.
	 *
	 * @return object|null  Row with phone, consent, session_token, order_id, created_at.
	 */
	public static function get_by_session( string $session_token ): ?object {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM %i WHERE session_token = %s ORDER BY id DESC LIMIT 1',
			$table,
			$session_token
		) );
	}
}
