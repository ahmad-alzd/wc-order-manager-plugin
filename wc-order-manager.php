<?php
/**
 * Plugin Name:       WC Order Manager
 * Plugin URI:        https://github.com/ahmad-alzd/wc-order-manager-plugin
 * Description:       Connects a WooCommerce store to the WC Order Manager mobile app: secure API Key/Secret credentials, HMAC-authenticated REST endpoints, and real-time order notifications.
 * Version:           0.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            WC Order Manager
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wc-order-manager
 */

// Block direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WCOM_VERSION', '0.2.0' );
define( 'WCOM_DB_VERSION', '1.1.0' );
define( 'WCOM_PLUGIN_FILE', __FILE__ );
define( 'WCOM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCOM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WCOM_TABLE_CREDENTIALS', 'wcom_api_credentials' );
define( 'WCOM_TABLE_DEVICE_TOKENS', 'wcom_device_tokens' );

/**
 * Simple autoloader for the plugin's own classes.
 * Converts class-wcom-foo-bar.php naming into includes/ or admin/ lookups.
 */
spl_autoload_register(
	function ( $class_name ) {
		if ( strpos( $class_name, 'WCOM_' ) !== 0 ) {
			return;
		}

		$file_name = 'class-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';

		$candidates = array(
			WCOM_PLUGIN_DIR . 'includes/' . $file_name,
			WCOM_PLUGIN_DIR . 'admin/' . $file_name,
		);

		foreach ( $candidates as $path ) {
			if ( file_exists( $path ) ) {
				require_once $path;
				return;
			}
		}
	}
);

/**
 * Activation: create/upgrade the database schema.
 */
function wcom_activate_plugin() {
	WCOM_Activator::activate();
}
register_activation_hook( __FILE__, 'wcom_activate_plugin' );

/**
 * Deactivation: currently a no-op. Data is preserved so the admin
 * can reactivate without losing issued API credentials.
 */
function wcom_deactivate_plugin() {
	// Intentionally left blank for now.
}
register_deactivation_hook( __FILE__, 'wcom_deactivate_plugin' );

/**
 * Bootstraps the plugin once all plugins are loaded, so we can safely
 * check for WooCommerce and wire up admin screens.
 */
function wcom_bootstrap() {
	// Keep the DB schema in sync if the plugin was updated in place
	// without a fresh activation (e.g. during development).
	if ( get_option( 'wcom_db_version' ) !== WCOM_DB_VERSION ) {
		WCOM_Activator::activate();
	}

	if ( is_admin() ) {
		new WCOM_Admin();
	}

	// REST routes are registered on rest_api_init regardless of
	// is_admin(), since the app calls them from outside wp-admin.
	new WCOM_REST_API();

	if ( class_exists( 'WooCommerce' ) ) {
		WCOM_Order_Notifications::init();
	}
}
add_action( 'plugins_loaded', 'wcom_bootstrap' );

/**
 * Warn the admin (without hard-blocking activation) if WooCommerce
 * is missing, since order data comes entirely from it.
 */
function wcom_admin_notice_missing_woocommerce() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'WC Order Manager requires WooCommerce to be installed and active in order to manage orders.', 'wc-order-manager' );
		echo '</p></div>';
	}
}
add_action( 'admin_notices', 'wcom_admin_notice_missing_woocommerce' );
