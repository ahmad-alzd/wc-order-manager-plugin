<?php
/**
 * Fires only when the plugin is deleted from the Plugins screen (not
 * on simple deactivation). By default we keep the credentials table
 * so reinstalling the plugin doesn't silently invalidate every device
 * that was already connected. A site owner who explicitly wants a
 * clean removal can opt in by defining WCOM_REMOVE_DATA_ON_UNINSTALL
 * as true in wp-config.php before deleting the plugin.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( defined( 'WCOM_REMOVE_DATA_ON_UNINSTALL' ) && WCOM_REMOVE_DATA_ON_UNINSTALL ) {
	global $wpdb;

	$table = $wpdb->prefix . 'wcom_api_credentials';
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

	delete_option( 'wcom_db_version' );
}
