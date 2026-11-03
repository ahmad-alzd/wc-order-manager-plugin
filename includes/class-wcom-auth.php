<?php
/**
 * HMAC-SHA256 request authentication.
 *
 * The app never sends the raw API secret over the network. Instead,
 * each request is signed with it, and the server recomputes the same
 * signature to verify the request's authenticity and integrity.
 *
 * Expected request headers (REST endpoints wired up in Phase 2):
 *   X-WCOM-Api-Key    Public API key identifying the credential.
 *   X-WCOM-Timestamp  Unix timestamp (seconds) when the request was signed.
 *   X-WCOM-Nonce      Random per-request string, unique per api_key.
 *   X-WCOM-Signature  hex HMAC-SHA256 of the string-to-sign below.
 *
 * String to sign (newline-joined):
 *   HTTP_METHOD (uppercase)
 *   REQUEST_PATH (e.g. /wp-json/wcom/v1/orders)
 *   TIMESTAMP
 *   NONCE
 *   SHA256_HEX(raw request body)   -- empty body hashes to sha256('')
 *
 * The timestamp must be within WCOM_Auth::TIMESTAMP_TOLERANCE seconds
 * of the server's clock, and each (api_key, nonce) pair may be used
 * only once within that window — together these stop replay attacks.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCOM_Auth {

	/**
	 * Maximum allowed difference, in seconds, between the request's
	 * timestamp and the server's current time.
	 */
	const TIMESTAMP_TOLERANCE = 300;

	/**
	 * Verify a signed request's headers against a stored credential.
	 *
	 * @param string $api_key    X-WCOM-Api-Key header value.
	 * @param string $timestamp  X-WCOM-Timestamp header value.
	 * @param string $nonce      X-WCOM-Nonce header value.
	 * @param string $signature  X-WCOM-Signature header value (hex).
	 * @param string $method     HTTP method, e.g. "GET".
	 * @param string $path       Request path, e.g. "/wp-json/wcom/v1/orders".
	 * @param string $raw_body   Raw request body (empty string if none).
	 * @return array{id:int, label:string}|WP_Error Credential info on success.
	 */
	public static function verify( $api_key, $timestamp, $nonce, $signature, $method, $path, $raw_body ) {
		if ( '' === (string) $api_key || '' === (string) $timestamp || '' === (string) $nonce || '' === (string) $signature ) {
			return new WP_Error( 'wcom_auth_missing_headers', __( 'Missing authentication headers.', 'wc-order-manager' ), array( 'status' => 401 ) );
		}

		if ( ! self::is_timestamp_fresh( $timestamp ) ) {
			return new WP_Error( 'wcom_auth_stale_timestamp', __( 'Request timestamp is outside the allowed window.', 'wc-order-manager' ), array( 'status' => 401 ) );
		}

		if ( self::is_nonce_reused( $api_key, $nonce ) ) {
			return new WP_Error( 'wcom_auth_replayed', __( 'This request has already been used.', 'wc-order-manager' ), array( 'status' => 401 ) );
		}

		$credential = WCOM_Credentials::get_active_with_secret( $api_key );
		if ( null === $credential ) {
			return new WP_Error( 'wcom_auth_unknown_key', __( 'Unknown or revoked API key.', 'wc-order-manager' ), array( 'status' => 401 ) );
		}

		$expected_signature = self::compute_signature(
			$credential['secret'],
			$method,
			$path,
			$timestamp,
			$nonce,
			$raw_body
		);

		if ( ! hash_equals( $expected_signature, (string) $signature ) ) {
			return new WP_Error( 'wcom_auth_bad_signature', __( 'Invalid request signature.', 'wc-order-manager' ), array( 'status' => 401 ) );
		}

		// Signature is valid: lock in this nonce and record usage.
		self::remember_nonce( $api_key, $nonce );
		WCOM_Credentials::touch_last_used( $credential['id'] );

		return array(
			'id'    => $credential['id'],
			'label' => $credential['label'],
		);
	}

	/**
	 * Compute the HMAC-SHA256 signature for a request.
	 *
	 * @param string $secret    Plaintext API secret.
	 * @param string $method    HTTP method, e.g. "GET".
	 * @param string $path      Request path.
	 * @param string $timestamp Unix timestamp as string.
	 * @param string $nonce     Per-request nonce.
	 * @param string $raw_body  Raw request body (empty string if none).
	 * @return string Hex-encoded signature.
	 */
	public static function compute_signature( $secret, $method, $path, $timestamp, $nonce, $raw_body ) {
		$body_hash = hash( 'sha256', (string) $raw_body );

		$string_to_sign = implode(
			"\n",
			array(
				strtoupper( $method ),
				$path,
				(string) $timestamp,
				(string) $nonce,
				$body_hash,
			)
		);

		return hash_hmac( 'sha256', $string_to_sign, $secret );
	}

	/**
	 * Whether a timestamp is within the allowed tolerance of "now".
	 *
	 * @param string $timestamp Unix timestamp as string.
	 * @return bool
	 */
	private static function is_timestamp_fresh( $timestamp ) {
		if ( ! ctype_digit( (string) $timestamp ) ) {
			return false;
		}

		$diff = abs( time() - (int) $timestamp );
		return $diff <= self::TIMESTAMP_TOLERANCE;
	}

	/**
	 * Whether this (api_key, nonce) pair has already been seen within
	 * the replay window.
	 *
	 * @param string $api_key API key.
	 * @param string $nonce   Nonce value.
	 * @return bool
	 */
	private static function is_nonce_reused( $api_key, $nonce ) {
		return false !== get_transient( self::nonce_transient_key( $api_key, $nonce ) );
	}

	/**
	 * Record a (api_key, nonce) pair so it cannot be replayed again
	 * within the tolerance window.
	 *
	 * @param string $api_key API key.
	 * @param string $nonce   Nonce value.
	 */
	private static function remember_nonce( $api_key, $nonce ) {
		set_transient( self::nonce_transient_key( $api_key, $nonce ), 1, self::TIMESTAMP_TOLERANCE );
	}

	/**
	 * Build the transient key used to track a (api_key, nonce) pair.
	 *
	 * @param string $api_key API key.
	 * @param string $nonce   Nonce value.
	 * @return string
	 */
	private static function nonce_transient_key( $api_key, $nonce ) {
		return 'wcom_nonce_' . md5( $api_key . ':' . $nonce );
	}
}
