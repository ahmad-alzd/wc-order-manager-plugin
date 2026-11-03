<?php
/**
 * Sends push notifications through Firebase Cloud Messaging's HTTP v1
 * API.
 *
 * Google retired the old "legacy" FCM API (a single static server
 * key), so sending now requires an OAuth2 access token obtained by
 * signing a short-lived JWT with the service account's private key.
 * To avoid pulling in Google's full API client library (and its
 * Composer dependencies) just for this one call, the JWT is built
 * and signed by hand with openssl, which is bundled with PHP.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCOM_FCM {

	const TOKEN_TRANSIENT = 'wcom_fcm_access_token';
	const OAUTH_SCOPE     = 'https://www.googleapis.com/auth/firebase.messaging';
	const OAUTH_TOKEN_URL = 'https://oauth2.googleapis.com/token';

	/**
	 * Send a notification to every device currently registered for
	 * an active credential. Intended for the "new order" broadcast,
	 * but generic enough to reuse later.
	 *
	 * @param string $title
	 * @param string $body
	 * @param array  $data  Extra key/value payload (values are cast to strings; FCM requires string map data).
	 */
	public static function send_to_all_devices( $title, $body, array $data = array() ) {
		if ( ! WCOM_FCM_Settings::is_configured() ) {
			return;
		}

		foreach ( WCOM_Device_Tokens::get_all_active() as $row ) {
			self::send_to_token( $row->fcm_token, $title, $body, $data, (int) $row->credential_id );
		}
	}

	/**
	 * Send a notification to a single FCM registration token.
	 *
	 * @param string $fcm_token
	 * @param string $title
	 * @param string $body
	 * @param array  $data          Extra key/value payload.
	 * @param int    $credential_id Owning credential, so an invalid/unregistered token can be cleaned up.
	 * @return true|WP_Error
	 */
	public static function send_to_token( $fcm_token, $title, $body, array $data = array(), $credential_id = 0 ) {
		$access_token = self::get_access_token();
		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$project_id = WCOM_FCM_Settings::get_project_id();

		$string_data = array();
		foreach ( $data as $key => $value ) {
			$string_data[ (string) $key ] = (string) $value;
		}

		$payload = array(
			'message' => array(
				'token'        => $fcm_token,
				'notification' => array(
					'title' => $title,
					'body'  => $body,
				),
				'data'         => $string_data,
				'android'      => array(
					'priority' => 'high',
				),
			),
		);

		$response = wp_remote_post(
			"https://fcm.googleapis.com/v1/projects/{$project_id}/messages:send",
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );

		if ( 200 === $status ) {
			return true;
		}

		$body_data   = json_decode( wp_remote_retrieve_body( $response ), true );
		$error_status = $body_data['error']['status'] ?? '';

		// The token no longer corresponds to an installed app — stop
		// trying to notify it until the app registers a fresh one.
		if ( in_array( $error_status, array( 'NOT_FOUND', 'UNREGISTERED', 'INVALID_ARGUMENT' ), true ) && $credential_id ) {
			WCOM_Device_Tokens::delete_for_credential( $credential_id );
		}

		return new WP_Error(
			'wcom_fcm_send_failed',
			sprintf(
				/* translators: 1: HTTP status code, 2: FCM error status string. */
				__( 'FCM send failed (HTTP %1$d, %2$s).', 'wc-order-manager' ),
				$status,
				$error_status ? $error_status : 'unknown'
			)
		);
	}

	/**
	 * Get a cached OAuth2 access token, requesting a new one from
	 * Google if there isn't a valid cached one.
	 *
	 * @return string|WP_Error
	 */
	private static function get_access_token() {
		$cached = get_transient( self::TOKEN_TRANSIENT );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$service_account = WCOM_FCM_Settings::get_service_account();
		if ( null === $service_account ) {
			return new WP_Error( 'wcom_fcm_not_configured', __( 'Firebase is not connected.', 'wc-order-manager' ) );
		}

		$jwt = self::build_signed_jwt( $service_account );
		if ( is_wp_error( $jwt ) ) {
			return $jwt;
		}

		$response = wp_remote_post(
			self::OAUTH_TOKEN_URL,
			array(
				'timeout' => 15,
				'body'    => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $jwt,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $data['access_token'] ) ) {
			return new WP_Error(
				'wcom_fcm_oauth_failed',
				sprintf(
					/* translators: %s: raw error response from Google. */
					__( 'Could not obtain a Firebase access token: %s', 'wc-order-manager' ),
					wp_remote_retrieve_body( $response )
				)
			);
		}

		$expires_in = isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 3600;
		// Refresh a little early so we never use an access token that
		// expires mid-request.
		set_transient( self::TOKEN_TRANSIENT, $data['access_token'], max( 60, $expires_in - 60 ) );

		return $data['access_token'];
	}

	/**
	 * Build and sign the JWT assertion used to request an OAuth2
	 * access token on behalf of the service account.
	 *
	 * @param array $service_account Decoded service account JSON.
	 * @return string|WP_Error
	 */
	private static function build_signed_jwt( array $service_account ) {
		$now = time();

		$header = array(
			'alg' => 'RS256',
			'typ' => 'JWT',
		);

		$claims = array(
			'iss'   => $service_account['client_email'],
			'scope' => self::OAUTH_SCOPE,
			'aud'   => self::OAUTH_TOKEN_URL,
			'iat'   => $now,
			'exp'   => $now + 3600,
		);

		$signing_input = self::base64url_encode( wp_json_encode( $header ) ) . '.' . self::base64url_encode( wp_json_encode( $claims ) );

		$signature = '';
		$signed    = openssl_sign( $signing_input, $signature, $service_account['private_key'], OPENSSL_ALGO_SHA256 );

		if ( ! $signed ) {
			return new WP_Error( 'wcom_fcm_jwt_sign_failed', __( 'Could not sign the Firebase authentication request.', 'wc-order-manager' ) );
		}

		return $signing_input . '.' . self::base64url_encode( $signature );
	}

	/**
	 * Base64url-encode (no padding) a string, as required by JWT.
	 *
	 * @param string $data
	 * @return string
	 */
	private static function base64url_encode( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}
}
