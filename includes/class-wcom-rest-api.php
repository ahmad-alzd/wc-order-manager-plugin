<?php
/**
 * REST API: wcom/v1
 *
 * All routes below require a valid HMAC-signed request (see
 * WCOM_Auth) — there is no cookie/session auth here, since the only
 * client is the mobile app using its API Key/Secret.
 *
 * The path used in the signature is the literal route the app
 * requested, WITHOUT the /wp-json prefix and WITHOUT any query
 * string — e.g. "/wcom/v1/orders" or "/wcom/v1/orders/123". This
 * keeps the signed path independent of whether pretty permalinks are
 * enabled on the site (which changes how /wp-json is exposed).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCOM_REST_API {

	const NAMESPACE_V1 = 'wcom/v1';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/verify',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'verify' ),
				'permission_callback' => array( $this, 'check_auth' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/order-statuses',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_order_statuses' ),
				'permission_callback' => array( $this, 'check_auth' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/orders',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_orders' ),
				'permission_callback' => array( $this, 'check_auth' ),
				'args'                => array(
					'status'   => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_key',
					),
					'page'     => array(
						'type'              => 'integer',
						'required'          => false,
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page' => array(
						'type'              => 'integer',
						'required'          => false,
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/orders/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_order' ),
				'permission_callback' => array( $this, 'check_auth' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/device-token',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'register_device_token' ),
				'permission_callback' => array( $this, 'check_auth' ),
				'args'                => array(
					'fcm_token' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/orders/(?P<id>\d+)/status',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'update_order_status' ),
				'permission_callback' => array( $this, 'check_auth' ),
				'args'                => array(
					'status' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Shared permission_callback for every wcom/v1 route: verifies the
	 * HMAC signature and, on success, stashes the authenticated
	 * credential on the request for handlers that want it.
	 *
	 * @param WP_REST_Request $request
	 * @return true|WP_Error
	 */
	public function check_auth( WP_REST_Request $request ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return new WP_Error( 'wcom_woocommerce_inactive', __( 'WooCommerce is not active on this site.', 'wc-order-manager' ), array( 'status' => 503 ) );
		}

		$api_key   = $request->get_header( 'x_wcom_api_key' );
		$timestamp = $request->get_header( 'x_wcom_timestamp' );
		$nonce     = $request->get_header( 'x_wcom_nonce' );
		$signature = $request->get_header( 'x_wcom_signature' );

		// Literal matched route, e.g. "/wcom/v1/orders/123" — see the
		// file docblock for why this (and not the /wp-json URL) is
		// the canonical signed path.
		$path = $request->get_route();

		$result = WCOM_Auth::verify(
			$api_key,
			$timestamp,
			$nonce,
			$signature,
			$request->get_method(),
			$path,
			$request->get_body()
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$request->set_param( '_wcom_credential', $result );

		return true;
	}

	/**
	 * GET /wcom/v1/verify
	 *
	 * Called right after the app's user enters a Key/Secret, so it can
	 * confirm which store it just connected to.
	 */
	public function verify( WP_REST_Request $request ) {
		$credential = $request->get_param( '_wcom_credential' );

		return new WP_REST_Response(
			array(
				'site_name'     => get_bloginfo( 'name' ),
				'site_url'      => home_url(),
				'currency'      => get_woocommerce_currency(),
				'currency_symbol' => get_woocommerce_currency_symbol(),
				'credential_label' => $credential['label'],
			),
			200
		);
	}

	/**
	 * GET /wcom/v1/order-statuses
	 *
	 * Lets the app populate a status dropdown with whatever statuses
	 * this store actually has registered (core + any added by other
	 * plugins), instead of hardcoding them client-side.
	 */
	public function get_order_statuses() {
		$statuses = array();

		foreach ( wc_get_order_statuses() as $key => $label ) {
			$statuses[] = array(
				'status' => WCOM_Order_Formatter::strip_status_prefix( $key ),
				'label'  => $label,
			);
		}

		return new WP_REST_Response( array( 'statuses' => $statuses ), 200 );
	}

	/**
	 * POST /wcom/v1/device-token
	 * Body (JSON): { "fcm_token": "..." }
	 *
	 * Called by the app right after Firebase hands it a registration
	 * token (on first launch, and again whenever Firebase rotates the
	 * token), so this device can receive new-order push notifications.
	 */
	public function register_device_token( WP_REST_Request $request ) {
		$credential = $request->get_param( '_wcom_credential' );
		$fcm_token  = trim( (string) $request->get_param( 'fcm_token' ) );

		if ( '' === $fcm_token ) {
			return new WP_Error( 'wcom_missing_token', __( 'fcm_token is required.', 'wc-order-manager' ), array( 'status' => 400 ) );
		}

		$saved = WCOM_Device_Tokens::upsert( $credential['id'], $fcm_token );

		if ( ! $saved ) {
			return new WP_Error( 'wcom_token_save_failed', __( 'Could not save the device token.', 'wc-order-manager' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( array( 'registered' => true ), 200 );
	}

	/**
	 * GET /wcom/v1/orders
	 *
	 * Query args: status (optional), page (default 1), per_page (default 20, max 50).
	 */
	public function get_orders( WP_REST_Request $request ) {
		$status   = $request->get_param( 'status' );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 50, max( 1, (int) $request->get_param( 'per_page' ) ) );

		$query_args = array(
			'paginate' => true,
			'page'     => $page,
			'limit'    => $per_page,
			'orderby'  => 'date',
			'order'    => 'DESC',
		);

		if ( ! empty( $status ) ) {
			if ( ! WCOM_Order_Formatter::is_valid_status( $status ) ) {
				return new WP_Error( 'wcom_invalid_status', __( 'Unknown order status.', 'wc-order-manager' ), array( 'status' => 400 ) );
			}
			$query_args['status'] = $status;
		}

		$results = wc_get_orders( $query_args );

		$orders = array();
		foreach ( $results->orders as $order ) {
			$orders[] = WCOM_Order_Formatter::summary( $order );
		}

		return new WP_REST_Response(
			array(
				'orders'      => $orders,
				'page'        => $page,
				'per_page'    => $per_page,
				'total'       => (int) $results->total,
				'total_pages' => (int) $results->max_num_pages,
			),
			200
		);
	}

	/**
	 * GET /wcom/v1/orders/{id}
	 */
	public function get_order( WP_REST_Request $request ) {
		$order = wc_get_order( (int) $request->get_param( 'id' ) );

		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'wcom_order_not_found', __( 'Order not found.', 'wc-order-manager' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( WCOM_Order_Formatter::full( $order ), 200 );
	}

	/**
	 * POST /wcom/v1/orders/{id}/status
	 * Body (JSON): { "status": "processing" }
	 */
	public function update_order_status( WP_REST_Request $request ) {
		$order = wc_get_order( (int) $request->get_param( 'id' ) );

		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'wcom_order_not_found', __( 'Order not found.', 'wc-order-manager' ), array( 'status' => 404 ) );
		}

		$new_status = sanitize_key( $request->get_param( 'status' ) );

		if ( ! WCOM_Order_Formatter::is_valid_status( $new_status ) ) {
			return new WP_Error( 'wcom_invalid_status', __( 'Unknown order status.', 'wc-order-manager' ), array( 'status' => 400 ) );
		}

		$credential = $request->get_param( '_wcom_credential' );
		$note       = sprintf(
			/* translators: %s: label of the API credential that made the change. */
			__( 'Status changed via WC Order Manager app (%s).', 'wc-order-manager' ),
			$credential['label']
		);

		$order->update_status( $new_status, $note );

		return new WP_REST_Response( WCOM_Order_Formatter::summary( $order ), 200 );
	}
}
