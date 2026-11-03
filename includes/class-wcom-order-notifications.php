<?php
/**
 * Sends a push notification to every connected device as soon as a
 * new WooCommerce order comes in.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCOM_Order_Notifications {

	/**
	 * A guard flag stored on the order itself, so that if
	 * woocommerce_new_order ever fires more than once for the same
	 * order (some payment gateways re-save the order during
	 * checkout), the device doesn't get duplicate notifications.
	 */
	const NOTIFIED_META_KEY = '_wcom_new_order_notified';

	public static function init() {
		add_action( 'woocommerce_new_order', array( __CLASS__, 'on_new_order' ) );
	}

	/**
	 * @param int $order_id
	 */
	public static function on_new_order( $order_id ) {
		if ( ! WCOM_FCM_Settings::is_configured() ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( 'yes' === $order->get_meta( self::NOTIFIED_META_KEY ) ) {
			return;
		}

		$title = sprintf(
			/* translators: %s: order number. */
			__( 'New order #%s', 'wc-order-manager' ),
			$order->get_order_number()
		);

		$body = sprintf(
			/* translators: 1: customer name, 2: order total, 3: currency code. */
			__( '%1$s — %2$s %3$s', 'wc-order-manager' ),
			trim( $order->get_formatted_billing_full_name() ),
			number_format_i18n( $order->get_total(), 2 ),
			$order->get_currency()
		);

		WCOM_FCM::send_to_all_devices(
			$title,
			$body,
			array(
				'type'     => 'new_order',
				'order_id' => $order->get_id(),
				'status'   => WCOM_Order_Formatter::strip_status_prefix( $order->get_status() ),
			)
		);

		$order->update_meta_data( self::NOTIFIED_META_KEY, 'yes' );
		$order->save();
	}
}
