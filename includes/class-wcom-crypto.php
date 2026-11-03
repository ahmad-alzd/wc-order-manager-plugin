<?php
/**
 * Encrypts/decrypts the API secret for storage at rest.
 *
 * The secret must be recoverable in plaintext (not just hashed) because
 * verifying an HMAC signature from the app requires the server to
 * recompute the same signature with the original secret. To avoid
 * storing it in plaintext in the database, it is encrypted with a key
 * derived from this site's own WordPress salts, using AES-256-GCM
 * (authenticated encryption, so tampering with the stored value is
 * also detected).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCOM_Crypto {

	const CIPHER = 'aes-256-gcm';

	/**
	 * Derive a stable 32-byte encryption key from this site's WordPress
	 * salts. These are unique per site and already treated as secrets
	 * (defined in wp-config.php), so they are a reasonable local source
	 * of key material without introducing new configuration.
	 *
	 * @return string Raw 32-byte key.
	 */
	private static function get_key() {
		$material = wp_salt( 'auth' ) . wp_salt( 'secure_auth' );
		return hash( 'sha256', $material, true );
	}

	/**
	 * Encrypt a plaintext secret.
	 *
	 * @param string $plaintext Secret to encrypt.
	 * @return array{cipher_text:string, iv:string} Base64-encoded cipher text and "iv:tag" bundle.
	 */
	public static function encrypt( $plaintext ) {
		$key     = self::get_key();
		$iv_len  = openssl_cipher_iv_length( self::CIPHER );
		$iv      = random_bytes( $iv_len );
		$tag     = '';
		$encrypted = openssl_encrypt( $plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag );

		if ( false === $encrypted ) {
			return false;
		}

		return array(
			'cipher_text' => base64_encode( $encrypted ),
			// Store iv and auth tag together; both are required to decrypt.
			'iv'          => base64_encode( $iv ) . ':' . base64_encode( $tag ),
		);
	}

	/**
	 * Decrypt a value previously produced by self::encrypt().
	 *
	 * @param string $cipher_text_b64 Base64 cipher text.
	 * @param string $iv_bundle       "base64(iv):base64(tag)" string.
	 * @return string|false Plaintext secret, or false on failure.
	 */
	public static function decrypt( $cipher_text_b64, $iv_bundle ) {
		$parts = explode( ':', (string) $iv_bundle, 2 );
		if ( 2 !== count( $parts ) ) {
			return false;
		}

		$iv  = base64_decode( $parts[0] );
		$tag = base64_decode( $parts[1] );
		$key = self::get_key();
		$encrypted = base64_decode( $cipher_text_b64 );

		if ( false === $iv || false === $tag || false === $encrypted ) {
			return false;
		}

		$plaintext = openssl_decrypt( $encrypted, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag );

		return false === $plaintext ? false : $plaintext;
	}
}
