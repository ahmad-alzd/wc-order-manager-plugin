<?php
/**
 * Handles plugin activation: creates/upgrades the database schema.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCOM_Activator {

	/**
	 * Create (or upgrade) the credentials table and record the schema
	 * version so future plugin updates can run migrations safely.
	 */
	public static function activate() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = $wpdb->prefix . WCOM_TABLE_CREDENTIALS;
		$charset_collate = $wpdb->get_charset_collate();

		// secret_encrypted / secret_iv: the API secret is never stored in
		// plaintext. It is encrypted at rest (see WCOM_Crypto) so that a
		// database leak alone does not expose working credentials, while
		// still letting the server decrypt it to verify HMAC signatures.
		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			label VARCHAR(190) NOT NULL DEFAULT '',
			api_key VARCHAR(64) NOT NULL,
			secret_encrypted TEXT NOT NULL,
			secret_iv VARCHAR(64) NOT NULL,
			status TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			last_used_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY api_key (api_key)
		) {$charset_collate};";

		dbDelta( $sql );

		$tokens_table = $wpdb->prefix . WCOM_TABLE_DEVICE_TOKENS;

		// One row per credential (one issued Key/Secret = one app
		// install), holding the most recent FCM registration token
		// for that device. The token is overwritten in place
		// (upsert) whenever the app re-registers, e.g. after Firebase
		// rotates it.
		$sql_tokens = "CREATE TABLE {$tokens_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			credential_id BIGINT UNSIGNED NOT NULL,
			fcm_token VARCHAR(255) NOT NULL,
			updated_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY credential_id (credential_id)
		) {$charset_collate};";

		dbDelta( $sql_tokens );

		update_option( 'wcom_db_version', WCOM_DB_VERSION );
	}
}
