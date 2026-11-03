<?php
/**
 * Converts WooCommerce order objects into the plain arrays returned
 * by the REST API, and validates order status values coming in from
 * the app.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCOM_Order_Formatter {

	/**
	 * WooCommerce stores statuses internally as "wc-processing" etc.
	 * The API (and the app) always use the short form "processing".
	 *
	 * @param string $status_key Internal status key, e.g. "wc-processing".
	 * @return string Short form, e.g. "processing".
	 */
	public static function strip_status_prefix( $status_key ) {
		return ( 0 === strpos( $status_key, 'wc-' ) ) ? substr( $status_key, 3 ) : $status_key;
	}

	/**
	 * Whether a short-form status (as sent by the app) is one this
	 * store actually has registered.
	 *
	 * @param string $status Short form, e.g. "processing".
	 * @return bool
	 */
	public static function is_valid_status( $status ) {
		foreach ( array_keys( wc_get_order_statuses() ) as $key ) {
			if ( self::strip_status_prefix( $key ) === $status ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Compact representation used in list views.
	 *
	 * @param WC_Order $order
	 * @return array
	 */
	public static function summary( WC_Order $order ) {
		return array(
			'id'                    => $order->get_id(),
			'number'                => $order->get_order_number(),
			'status'                => self::strip_status_prefix( $order->get_status() ),
			'status_label'          => wc_get_order_status_name( $order->get_status() ),
			'currency'              => $order->get_currency(),
			'total'                 => (float) $order->get_total(),
			'total_formatted'       => wp_strip_all_tags( $order->get_formatted_order_total() ),
			'date_created'          => self::format_date( $order->get_date_created() ),
			'customer_name'         => trim( $order->get_formatted_billing_full_name() ),
			'payment_method_title'  => $order->get_payment_method_title(),
			'item_count'            => $order->get_item_count(),
		);
	}

	/**
	 * Full representation used in the order detail view: everything
	 * in summary(), plus line items and addresses.
	 *
	 * @param WC_Order $order
	 * @return array
	 */
	public static function full( WC_Order $order ) {
		$data = self::summary( $order );

		$data['customer_note'] = $order->get_customer_note();

		$data['billing'] = array(
			'name'    => trim( $order->get_formatted_billing_full_name() ),
			'company' => $order->get_billing_company(),
			'address' => trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() ),
			'city'    => $order->get_billing_city(),
			'state'   => $order->get_billing_state(),
			'postcode'=> $order->get_billing_postcode(),
			'country' => $order->get_billing_country(),
			'phone'   => $order->get_billing_phone(),
			'email'   => $order->get_billing_email(),
		);

		$data['shipping'] = array(
			'name'    => trim( $order->get_formatted_shipping_full_name() ),
			'company' => $order->get_shipping_company(),
			'address' => trim( $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2() ),
			'city'    => $order->get_shipping_city(),
			'state'   => $order->get_shipping_state(),
			'postcode'=> $order->get_shipping_postcode(),
			'country' => $order->get_shipping_country(),
		);

		$line_items = array();
		foreach ( $order->get_items() as $item ) {
			/** @var WC_Order_Item_Product $item */
			$product = $item->get_product();

			$line_items[] = array(
				'name'     => $item->get_name(),
				'sku'      => $product ? $product->get_sku() : '',
				'quantity' => $item->get_quantity(),
				'total'    => (float) $item->get_total(),
			);
		}
		$data['line_items'] = $line_items;

		return $data;
	}

	/**
	 * Format a WC_DateTime (or null) as an ISO 8601 string in the
	 * site's local timezone, which is easier for a mobile app to
	 * parse and display consistently than WordPress' mixed formats.
	 *
	 * @param WC_DateTime|null $date
	 * @return string|null
	 */
	private static function format_date( $date ) {
		if ( ! $date instanceof WC_DateTime ) {
			return null;
		}

		return $date->date( DATE_ATOM );
	}
}
