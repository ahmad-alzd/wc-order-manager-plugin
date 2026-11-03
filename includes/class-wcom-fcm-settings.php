<?php
/**
 * Stores the Firebase service account credentials used to send push
 * notifications. The service account JSON contains a private key, so
 * it is encrypted at rest the same way API secrets are (WCOM_Crypto),
 * and the plaintext is only ever reconstructed in memory when a
 * notification actually needs to be sent.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCOM_FCM_Settings {

	const OPTION_CIPHER     = 'wcom_fcm_service_account_enc';
	const OPTION_IV         = 'wcom_fcm_service_account_iv';
	const OPTION_PROJECT_ID = 'wcom_fcm_project_id';

	/**
	 * Validate and store a pasted Firebase service account JSON.
	 *
	 * @param string $json_string Raw JSON text pasted by the admin.
	 * @return true|WP_Error
	 */
	public static function save( $json_string ) {
		$data = json_decode( trim( (string) $json_string ), true );

		if ( ! is_array( $data )
			|| 'service_account' !== ( $data['type'] ?? '' )
			|| empty( $data['project_id'] )
			|| empty( $data['private_key'] )
			|| empty( $data['client_email'] )
		) {
			return new WP_Error(
				'wcom_invalid_service_account',
				__( 'This does not look like a valid Firebase service account JSON file. Download it from Firebase Console → Project Settings → Service Accounts → Generate new private key.', 'wc-order-manager' )
			);
		}

		$encrypted = WCOM_Crypto::encrypt( wp_json_encode( $data ) );
		if ( false === $encrypted ) {
			return new WP_Error( 'wcom_encrypt_failed', __( 'Could not securely store the service account. Please try again.', 'wc-order-manager' ) );
		}

		update_option( self::OPTION_CIPHER, $encrypted['cipher_text'], false );
		update_option( self::OPTION_IV, $encrypted['iv'], false );
		update_option( self::OPTION_PROJECT_ID, sanitize_text_field( $data['project_id'] ), false );

		// A previously cached OAuth2 token would belong to whichever
		// service account was configured before.
		delete_transient( 'wcom_fcm_access_token' );

		return true;
	}

	/**
	 * Remove the stored service account entirely.
	 */
	public static function clear() {
		delete_option( self::OPTION_CIPHER );
		delete_option( self::OPTION_IV );
		delete_option( self::OPTION_PROJECT_ID );
		delete_transient( 'wcom_fcm_access_token' );
	}

	/**
	 * Whether a service account is currently configured.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return (bool) get_option( self::OPTION_CIPHER, '' );
	}

	/**
	 * Firebase project id (not secret — safe to display in the admin
	 * screen as confirmation of which project is connected).
	 *
	 * @return string
	 */
	public static function get_project_id() {
		return (string) get_option( self::OPTION_PROJECT_ID, '' );
	}

	/**
	 * Decrypt and return the full service account as an associative
	 * array (contains the private key — handle with care, keep it out
	 * of logs).
	 *
	 * @return array|null
	 */
	public static function get_service_account() {
		$cipher_text = get_option( self::OPTION_CIPHER, '' );
		$iv          = get_option( self::OPTION_IV, '' );

		if ( '' === $cipher_text || '' === $iv ) {
			return null;
		}

		$json = WCOM_Crypto::decrypt( $cipher_text, $iv );
		if ( false === $json ) {
			return null;
		}

		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : null;
	}
}
