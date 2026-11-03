<?php
/**
 * Stores each credential's current FCM registration token, so the
 * server knows where to send a push notification for that device.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCOM_Device_Tokens {

	/**
	 * Create or update the FCM token for a credential (one row per
	 * credential — the app re-registers whenever Firebase hands it a
	 * new token, which simply overwrites the old one here).
	 *
	 * @param int    $credential_id
	 * @param string $fcm_token
	 * @return bool
	 */
	public static function upsert( $credential_id, $fcm_token ) {
		global $wpdb;

		$table = WCOM_DB::device_tokens_table();
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table is a fixed, internal identifier.
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (credential_id, fcm_token, updated_at)
				 VALUES (%d, %s, %s)
				 ON DUPLICATE KEY UPDATE fcm_token = %s, updated_at = %s",
				$credential_id,
				$fcm_token,
				$now,
				$fcm_token,
				$now
			)
		);

		return false !== $result;
	}

	/**
	 * Remove a credential's stored token, e.g. after FCM reports it
	 * as invalid/unregistered.
	 *
	 * @param int $credential_id
	 */
	public static function delete_for_credential( $credential_id ) {
		global $wpdb;

		$wpdb->delete(
			WCOM_DB::device_tokens_table(),
			array( 'credential_id' => (int) $credential_id ),
			array( '%d' )
		);
	}

	/**
	 * All FCM tokens belonging to currently-active (non-revoked)
	 * credentials — i.e. every device that should receive new-order
	 * notifications.
	 *
	 * @return array<int, object> Rows with credential_id and fcm_token.
	 */
	public static function get_all_active() {
		global $wpdb;

		$tokens_table      = WCOM_DB::device_tokens_table();
		$credentials_table = WCOM_DB::credentials_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names are fixed, internal identifiers.
		return $wpdb->get_results(
			"SELECT t.credential_id, t.fcm_token
			 FROM {$tokens_table} t
			 INNER JOIN {$credentials_table} c ON c.id = t.credential_id
			 WHERE c.status = 1"
		);
	}
}
