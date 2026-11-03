<?php
/**
 * Admin screen: create, view, revoke, regenerate and delete API
 * credentials used by the mobile app to authenticate with this site.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCOM_Admin {

	const MENU_SLUG = 'wc-order-manager';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );

		add_action( 'admin_post_wcom_create_credential', array( $this, 'handle_create' ) );
		add_action( 'admin_post_wcom_regenerate_secret', array( $this, 'handle_regenerate' ) );
		add_action( 'admin_post_wcom_revoke_credential', array( $this, 'handle_revoke' ) );
		add_action( 'admin_post_wcom_reactivate_credential', array( $this, 'handle_reactivate' ) );
		add_action( 'admin_post_wcom_delete_credential', array( $this, 'handle_delete' ) );

		add_action( 'admin_post_wcom_save_fcm_settings', array( $this, 'handle_save_fcm_settings' ) );
		add_action( 'admin_post_wcom_clear_fcm_settings', array( $this, 'handle_clear_fcm_settings' ) );
	}

	/**
	 * Capability required to manage credentials. Falls back to
	 * manage_options if WooCommerce (and its manage_woocommerce cap)
	 * is not active, so the screen is never completely unreachable.
	 *
	 * @return string
	 */
	private function capability() {
		return current_user_can( 'manage_woocommerce' ) ? 'manage_woocommerce' : 'manage_options';
	}

	public function register_menu() {
		add_menu_page(
			__( 'WC Order Manager', 'wc-order-manager' ),
			__( 'Order Manager', 'wc-order-manager' ),
			$this->capability(),
			self::MENU_SLUG,
			array( $this, 'render_page' ),
			'dashicons-admin-network',
			56
		);
	}

	/**
	 * Render the API Credentials screen.
	 */
	public function render_page() {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wc-order-manager' ) );
		}

		$just_created = $this->consume_reveal_transient( 'wcom_reveal_created' );
		$just_regened = $this->consume_reveal_transient( 'wcom_reveal_regenerated' );
		$credentials  = WCOM_Credentials::get_all();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WC Order Manager — API Credentials', 'wc-order-manager' ); ?></h1>
			<p>
				<?php esc_html_e( 'Create an API Key/Secret pair and enter it in the mobile app to connect it to this store. The secret is shown only once — store it safely.', 'wc-order-manager' ); ?>
			</p>

			<?php $this->render_notices(); ?>

			<?php if ( $just_created ) : ?>
				<div class="notice notice-success">
					<p><strong><?php esc_html_e( 'New credential created. Copy the secret now — it will not be shown again:', 'wc-order-manager' ); ?></strong></p>
					<p>
						<?php esc_html_e( 'API Key:', 'wc-order-manager' ); ?>
						<code><?php echo esc_html( $just_created['api_key'] ); ?></code>
					</p>
					<p>
						<?php esc_html_e( 'API Secret:', 'wc-order-manager' ); ?>
						<code><?php echo esc_html( $just_created['api_secret'] ); ?></code>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( $just_regened ) : ?>
				<div class="notice notice-success">
					<p><strong><?php esc_html_e( 'Secret regenerated. Copy it now — it will not be shown again:', 'wc-order-manager' ); ?></strong></p>
					<p>
						<?php esc_html_e( 'New API Secret:', 'wc-order-manager' ); ?>
						<code><?php echo esc_html( $just_regened['api_secret'] ); ?></code>
					</p>
				</div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Create new credential', 'wc-order-manager' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wcom_create_credential' ); ?>
				<input type="hidden" name="action" value="wcom_create_credential" />
				<table class="form-table">
					<tr>
						<th><label for="wcom_label"><?php esc_html_e( 'Label', 'wc-order-manager' ); ?></label></th>
						<td>
							<input type="text" id="wcom_label" name="label" class="regular-text"
								placeholder="<?php esc_attr_e( "e.g. Owner's phone", 'wc-order-manager' ); ?>" required />
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Generate Key & Secret', 'wc-order-manager' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Existing credentials', 'wc-order-manager' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Label', 'wc-order-manager' ); ?></th>
						<th><?php esc_html_e( 'API Key', 'wc-order-manager' ); ?></th>
						<th><?php esc_html_e( 'Status', 'wc-order-manager' ); ?></th>
						<th><?php esc_html_e( 'Created', 'wc-order-manager' ); ?></th>
						<th><?php esc_html_e( 'Last used', 'wc-order-manager' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'wc-order-manager' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $credentials ) ) : ?>
						<tr>
							<td colspan="6"><?php esc_html_e( 'No credentials yet.', 'wc-order-manager' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $credentials as $credential ) : ?>
							<tr>
								<td><?php echo esc_html( $credential->label ); ?></td>
								<td><code><?php echo esc_html( $credential->api_key ); ?></code></td>
								<td>
									<?php if ( '1' === (string) $credential->status ) : ?>
										<span style="color:#1a7a1a;"><?php esc_html_e( 'Active', 'wc-order-manager' ); ?></span>
									<?php else : ?>
										<span style="color:#a00;"><?php esc_html_e( 'Revoked', 'wc-order-manager' ); ?></span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $credential->created_at ); ?></td>
								<td><?php echo esc_html( $credential->last_used_at ? $credential->last_used_at : '—' ); ?></td>
								<td>
									<?php $this->render_row_actions( $credential ); ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Push notifications (Firebase)', 'wc-order-manager' ); ?></h2>
			<?php if ( WCOM_FCM_Settings::is_configured() ) : ?>
				<p>
					<?php
					printf(
						/* translators: %s: Firebase project id. */
						esc_html__( 'Connected to Firebase project: %s', 'wc-order-manager' ),
						'<code>' . esc_html( WCOM_FCM_Settings::get_project_id() ) . '</code>'
					);
					?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'wcom_clear_fcm_settings' ); ?>
					<input type="hidden" name="action" value="wcom_clear_fcm_settings" />
					<button type="submit" class="button" onclick="return confirm('<?php echo esc_js( __( 'Disconnect Firebase? New-order push notifications will stop until you reconnect.', 'wc-order-manager' ) ); ?>');">
						<?php esc_html_e( 'Disconnect Firebase', 'wc-order-manager' ); ?>
					</button>
				</form>
			<?php else : ?>
				<p>
					<?php esc_html_e( 'Not connected yet. In the Firebase Console, go to Project Settings → Service Accounts → Generate new private key, then paste the downloaded JSON file\'s contents below.', 'wc-order-manager' ); ?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'wcom_save_fcm_settings' ); ?>
					<input type="hidden" name="action" value="wcom_save_fcm_settings" />
					<p>
						<textarea name="service_account_json" rows="10" class="large-text code"
							placeholder='{"type": "service_account", "project_id": "...", ...}' required></textarea>
					</p>
					<?php submit_button( __( 'Save Firebase credentials', 'wc-order-manager' ) ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the small inline action forms (regenerate/revoke/reactivate/delete)
	 * for one credential row.
	 *
	 * @param object $credential Row from WCOM_Credentials::get_all().
	 */
	private function render_row_actions( $credential ) {
		$id = (int) $credential->id;
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
			<?php wp_nonce_field( 'wcom_regenerate_secret_' . $id ); ?>
			<input type="hidden" name="action" value="wcom_regenerate_secret" />
			<input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>" />
			<button type="submit" class="button button-small" onclick="return confirm('<?php echo esc_js( __( 'Regenerate the secret? The current secret will stop working immediately.', 'wc-order-manager' ) ); ?>');">
				<?php esc_html_e( 'Regenerate Secret', 'wc-order-manager' ); ?>
			</button>
		</form>

		<?php if ( '1' === (string) $credential->status ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
				<?php wp_nonce_field( 'wcom_revoke_credential_' . $id ); ?>
				<input type="hidden" name="action" value="wcom_revoke_credential" />
				<input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>" />
				<button type="submit" class="button button-small">
					<?php esc_html_e( 'Revoke', 'wc-order-manager' ); ?>
				</button>
			</form>
		<?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
				<?php wp_nonce_field( 'wcom_reactivate_credential_' . $id ); ?>
				<input type="hidden" name="action" value="wcom_reactivate_credential" />
				<input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>" />
				<button type="submit" class="button button-small">
					<?php esc_html_e( 'Reactivate', 'wc-order-manager' ); ?>
				</button>
			</form>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
			<?php wp_nonce_field( 'wcom_delete_credential_' . $id ); ?>
			<input type="hidden" name="action" value="wcom_delete_credential" />
			<input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>" />
			<button type="submit" class="button button-small button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Permanently delete this credential?', 'wc-order-manager' ) ); ?>');">
				<?php esc_html_e( 'Delete', 'wc-order-manager' ); ?>
			</button>
		</form>
		<?php
	}

	/**
	 * Show a plain admin notice for a ?wcom_notice= query flag set by
	 * the handlers below after a redirect.
	 */
	private function render_notices() {
		if ( empty( $_GET['wcom_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$messages = array(
			'revoked'     => __( 'Credential revoked.', 'wc-order-manager' ),
			'reactivated' => __( 'Credential reactivated.', 'wc-order-manager' ),
			'deleted'     => __( 'Credential deleted.', 'wc-order-manager' ),
			'fcm_saved'   => __( 'Firebase credentials saved. Push notifications are now active.', 'wc-order-manager' ),
			'fcm_cleared' => __( 'Firebase disconnected.', 'wc-order-manager' ),
			'error'       => __( 'Something went wrong. Please try again.', 'wc-order-manager' ),
		);

		$key = sanitize_key( wp_unslash( $_GET['wcom_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( isset( $messages[ $key ] ) ) {
			$class = ( 'error' === $key ) ? 'notice-error' : 'notice-info';
			printf( '<div class="notice %1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $messages[ $key ] ) );
		}
	}

	/**
	 * Settings page URL, optionally with a notice flag.
	 *
	 * @param string $notice Optional notice key for render_notices().
	 * @return string
	 */
	private function page_url( $notice = '' ) {
		$url = admin_url( 'admin.php?page=' . self::MENU_SLUG );
		return $notice ? add_query_arg( 'wcom_notice', $notice, $url ) : $url;
	}

	/**
	 * Store a one-time-reveal payload (plaintext secret) for the
	 * current admin user, read back by render_page() on the very next
	 * load and then discarded.
	 *
	 * @param string $key     Transient key prefix.
	 * @param array  $payload Data to reveal once.
	 */
	private function set_reveal_transient( $key, array $payload ) {
		set_transient( $key . '_' . get_current_user_id(), $payload, MINUTE_IN_SECONDS );
	}

	/**
	 * Read and delete a one-time-reveal payload set by set_reveal_transient().
	 *
	 * @param string $key Transient key prefix.
	 * @return array|null
	 */
	private function consume_reveal_transient( $key ) {
		$transient_key = $key . '_' . get_current_user_id();
		$value          = get_transient( $transient_key );

		if ( false === $value ) {
			return null;
		}

		delete_transient( $transient_key );
		return $value;
	}

	// -- admin-post.php handlers -------------------------------------------------

	public function handle_create() {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wc-order-manager' ) );
		}
		check_admin_referer( 'wcom_create_credential' );

		$label  = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
		$result = WCOM_Credentials::create( $label );

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( $this->page_url( 'error' ) );
			exit;
		}

		$this->set_reveal_transient( 'wcom_reveal_created', $result );
		wp_safe_redirect( $this->page_url() );
		exit;
	}

	public function handle_regenerate() {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wc-order-manager' ) );
		}

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		check_admin_referer( 'wcom_regenerate_secret_' . $id );

		$new_secret = WCOM_Credentials::regenerate_secret( $id );

		if ( is_wp_error( $new_secret ) ) {
			wp_safe_redirect( $this->page_url( 'error' ) );
			exit;
		}

		$this->set_reveal_transient( 'wcom_reveal_regenerated', array( 'api_secret' => $new_secret ) );
		wp_safe_redirect( $this->page_url() );
		exit;
	}

	public function handle_revoke() {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wc-order-manager' ) );
		}

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		check_admin_referer( 'wcom_revoke_credential_' . $id );

		WCOM_Credentials::revoke( $id );
		wp_safe_redirect( $this->page_url( 'revoked' ) );
		exit;
	}

	public function handle_reactivate() {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wc-order-manager' ) );
		}

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		check_admin_referer( 'wcom_reactivate_credential_' . $id );

		global $wpdb;
		$wpdb->update(
			WCOM_DB::credentials_table(),
			array( 'status' => 1 ),
			array( 'id' => $id ),
			array( '%d' ),
			array( '%d' )
		);

		wp_safe_redirect( $this->page_url( 'reactivated' ) );
		exit;
	}

	public function handle_delete() {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wc-order-manager' ) );
		}

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		check_admin_referer( 'wcom_delete_credential_' . $id );

		WCOM_Credentials::delete( $id );
		wp_safe_redirect( $this->page_url( 'deleted' ) );
		exit;
	}

	public function handle_save_fcm_settings() {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wc-order-manager' ) );
		}
		check_admin_referer( 'wcom_save_fcm_settings' );

		$json   = isset( $_POST['service_account_json'] ) ? wp_unslash( $_POST['service_account_json'] ) : '';
		$result = WCOM_FCM_Settings::save( $json );

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( $this->page_url( 'error' ) );
			exit;
		}

		wp_safe_redirect( $this->page_url( 'fcm_saved' ) );
		exit;
	}

	public function handle_clear_fcm_settings() {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wc-order-manager' ) );
		}
		check_admin_referer( 'wcom_clear_fcm_settings' );

		WCOM_FCM_Settings::clear();
		wp_safe_redirect( $this->page_url( 'fcm_cleared' ) );
		exit;
	}
}
