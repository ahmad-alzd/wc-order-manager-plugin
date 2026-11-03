<?php
/**
 * Small helper for consistent access to the plugin's database table.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCOM_DB {

	/**
	 * Fully-qualified (prefixed) credentials table name.
	 *
	 * @return string
	 */
	public static function credentials_table() {
		global $wpdb;
		return $wpdb->prefix . WCOM_TABLE_CREDENTIALS;
	}

	/**
	 * Fully-qualified (prefixed) device tokens table name.
	 *
	 * @return string
	 */
	public static function device_tokens_table() {
		global $wpdb;
		return $wpdb->prefix . WCOM_TABLE_DEVICE_TOKENS;
	}
}
