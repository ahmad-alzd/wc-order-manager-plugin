<?php
/**
 * CRUD operations for API Key/Secret credentials.
 *
 * Each row represents one issued credential (typically one mobile
 * device/app installation). The secret is shown to the admin exactly
 * once, at creation or regeneration time, and is never retrievable
 * again in plaintext through the admin UI afterwards.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCOM_Credentials {

	/**
	 * Create a new credential.
	 *
	 * @param string $label Human-readable label (e.g. "Owner's phone").
	 * @return array{id:int, label:string, api_key:string, api_secret:string}|WP_Error
	 */
	public static function create( $label ) {
		global $wpdb;

		$api_key    = self::generate_token( 32 ); // 64 hex chars.
		$api_secret = self::generate_token( 32 ); // 64 hex chars, shown once.

		$encrypted = WCOM_Crypto::encrypt( $api_secret );
		if ( false === $encrypted ) {
			return new WP_Error( 'wcom_encrypt_failed', __( 'Could not secure the new credential. Please try again.', 'wc-order-manager' ) );
		}

		$inserted = $wpdb->insert(
			WCOM_DB::credentials_table(),
			array(
				'label'            => sanitize_text_field( $label ),
				'api_key'          => $api_key,
				'secret_encrypted' => $encrypted['cipher_text'],
				'secret_iv'        => $encrypted['iv'],
				'status'           => 1,
				'created_at'       => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'wcom_db_insert_failed', __( 'Could not save the new credential.', 'wc-order-manager' ) );
		}

		return array(
			'id'         => (int) $wpdb->insert_id,
			'label'      => $label,
			'api_key'    => $api_key,
			'api_secret' => $api_secret,
		);
	}

	/**
	 * Regenerate the secret for an existing credential. The api_key
	 * stays the same; only the secret changes (e.g. if it may have
	 * leaked).
	 *
	 * @param int $id Credential row id.
	 * @return string|WP_Error New plaintext secret, shown once.
	 */
	public static function regenerate_secret( $id ) {
		global $wpdb;

		$new_secret = self::generate_token( 32 );
		$encrypted  = WCOM_Crypto::encrypt( $new_secret );

		if ( false === $encrypted ) {
			return new WP_Error( 'wcom_encrypt_failed', __( 'Could not secure the regenerated credential. Please try again.', 'wc-order-manager' ) );
		}

		$updated = $wpdb->update(
			WCOM_DB::credentials_table(),
			array(
				'secret_encrypted' => $encrypted['cipher_text'],
				'secret_iv'        => $encrypted['iv'],
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'wcom_db_update_failed', __( 'Could not regenerate the secret.', 'wc-order-manager' ) );
		}

		return $new_secret;
	}

	/**
	 * Revoke a credential so it can no longer authenticate requests.
	 * The row is kept (not deleted) for an audit trail.
	 *
	 * @param int $id Credential row id.
	 * @return bool
	 */
	public static function revoke( $id ) {
		global $wpdb;

		$updated = $wpdb->update(
			WCOM_DB::credentials_table(),
			array( 'status' => 0 ),
			array( 'id' => (int) $id ),
			array( '%d' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Permanently delete a credential row.
	 *
	 * @param int $id Credential row id.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;

		$deleted = $wpdb->delete(
			WCOM_DB::credentials_table(),
			array( 'id' => (int) $id ),
			array( '%d' )
		);

		return false !== $deleted;
	}

	/**
	 * List all credentials (metadata only, never the secret) for the
	 * admin screen.
	 *
	 * @return array<int, object>
	 */
	public static function get_all() {
		global $wpdb;

		$table = WCOM_DB::credentials_table();

		return $wpdb->get_results(
			"SELECT id, label, api_key, status, created_at, last_used_at FROM {$table} ORDER BY created_at DESC"
		);
	}

	/**
	 * Look up an active credential by its API key and decrypt its
	 * secret, for use when verifying an incoming HMAC-signed request.
	 *
	 * @param string $api_key API key presented by the client.
	 * @return array{id:int, label:string, secret:string}|null
	 */
	public static function get_active_with_secret( $api_key ) {
		global $wpdb;

		$table = WCOM_DB::credentials_table();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, label, secret_encrypted, secret_iv FROM {$table} WHERE api_key = %s AND status = 1 LIMIT 1",
				$api_key
			)
		);

		if ( ! $row ) {
			return null;
		}

		$secret = WCOM_Crypto::decrypt( $row->secret_encrypted, $row->secret_iv );
		if ( false === $secret ) {
			return null;
		}

		return array(
			'id'     => (int) $row->id,
			'label'  => $row->label,
			'secret' => $secret,
		);
	}

	/**
	 * Record that a credential was just used to authenticate a request.
	 *
	 * @param int $id Credential row id.
	 */
	public static function touch_last_used( $id ) {
		global $wpdb;

		$wpdb->update(
			WCOM_DB::credentials_table(),
			array( 'last_used_at' => current_time( 'mysql', true ) ),
			array( 'id' => (int) $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Generate a cryptographically secure random hex token.
	 *
	 * @param int $bytes Number of random bytes (hex output is 2x this length).
	 * @return string
	 */
	private static function generate_token( $bytes = 32 ) {
		return bin2hex( random_bytes( $bytes ) );
	}
}
