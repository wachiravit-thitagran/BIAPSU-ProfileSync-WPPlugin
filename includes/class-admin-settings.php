<?php
/**
 * Admin settings page (Settings -> ProfileSync).
 *
 * @package BIAPSU\ProfileSync
 */

namespace BIAPSU\ProfileSync;

defined( 'ABSPATH' ) || exit;

/**
 * Renders and saves the plugin's settings, plus a connection test.
 */
class Admin_Settings {

	const SLUG  = 'biapsu-profilesync';
	const NONCE = 'biapsu_profilesync_save';

	/**
	 * Settings store.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Platform client (for connection test).
	 *
	 * @var Platform_Client
	 */
	private $client;

	/**
	 * Constructor.
	 *
	 * @param Settings        $settings Settings store.
	 * @param Platform_Client $client   Platform client.
	 */
	public function __construct( Settings $settings, Platform_Client $client ) {
		$this->settings = $settings;
		$this->client   = $client;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_biapsu_profilesync_test', array( $this, 'handle_test' ) );
	}

	/**
	 * Add the settings page.
	 *
	 * @return void
	 */
	public function menu() {
		add_menu_page(
			__( 'BIA PSU Volunteer Check', 'biapsu-profilesync' ),
			__( 'Volunteer Check', 'biapsu-profilesync' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-update-alt'
		);
	}

	/**
	 * Save handler runs inside render() on POST.
	 *
	 * @return void
	 */
	private function maybe_save() {
		if ( empty( $_POST['biapsu_submit'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( self::NONCE );

		$all = $this->settings->all();

		$all['enabled'] = ! empty( $_POST['enabled'] );

		$platform                     = $all['platform'];
		$platform['base_url']         = isset( $_POST['base_url'] ) ? esc_url_raw( wp_unslash( $_POST['base_url'] ) ) : '';
		$platform['profile_endpoint'] = isset( $_POST['profile_endpoint'] ) ? esc_url_raw( wp_unslash( $_POST['profile_endpoint'] ) ) : '';
		$platform['timeout']          = isset( $_POST['timeout'] ) ? max( 1, (int) $_POST['timeout'] ) : 10;
		$platform['verify_ssl']       = ! empty( $_POST['verify_ssl'] );

		// Only overwrite the secret when a new value is typed.
		$new_secret = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
		if ( '' !== $new_secret ) {
			$platform['api_key'] = $new_secret; // Settings::save() encrypts it.
		}

		$all['platform'] = $platform;

		$this->settings->save( $all );

		add_settings_error( self::SLUG, 'saved', __( 'Settings saved.', 'biapsu-profilesync' ), 'updated' );
	}

	/**
	 * Connection test handler.
	 *
	 * @return void
	 */
	public function handle_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'biapsu-profilesync' ) );
		}
		check_admin_referer( 'biapsu_profilesync_test' );

		// Probe with an unregistered email. Only an explicit 'Volunteer not found'
		// response proves the API Key is accepted; other HTTP errors fail.
		$response = $this->client->fetch_profile( 'test_connection@example.com' );

		if ( is_wp_error( $response ) && 'biapsu_not_found' !== $response->get_error_code() ) {
			error_log( 'BIAPSU Profile Sync API Test Error: ' . $response->get_error_message() );
			$status = 'fail';
			$msg    = $response->get_error_message();
		} else {
			$status = 'ok';
			$msg    = __( 'API Key accepted successfully.', 'biapsu-profilesync' );
		}

		set_transient(
			'biapsu_profilesync_test_result',
			array(
				'status' => $status,
				'msg'    => $msg,
			),
			60
		);

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$this->maybe_save();

		$all      = $this->settings->all();
		$platform = $all['platform'];

		$test = get_transient( 'biapsu_profilesync_test_result' );
		if ( $test ) {
			delete_transient( 'biapsu_profilesync_test_result' );
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'BIA PSU Volunteer Check', 'biapsu-profilesync' ); ?></h1>
			<p><?php esc_html_e( 'Automatically records a Yes volunteer answer for Google accounts found in the BIA platform. If not found, Authorizenter asks the existing Yes/No question. API errors never block login. No personal profile data is synchronized.', 'biapsu-profilesync' ); ?></p>
			<?php settings_errors( self::SLUG ); ?>

			<?php if ( is_array( $test ) ) : ?>
				<div class="notice notice-<?php echo 'ok' === $test['status'] ? 'success' : 'error'; ?>">
					<p><strong><?php echo 'ok' === $test['status'] ? esc_html__( 'Connection OK:', 'biapsu-profilesync' ) : esc_html__( 'Connection failed:', 'biapsu-profilesync' ); ?></strong>
					<?php echo esc_html( $test['msg'] ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="">
				<?php wp_nonce_field( self::NONCE ); ?>

				<h2 class="title"><?php esc_html_e( 'General', 'biapsu-profilesync' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable volunteer status check', 'biapsu-profilesync' ); ?></th>
						<td><label><input type="checkbox" name="enabled" value="1" <?php checked( $all['enabled'] ); ?> />
							<?php esc_html_e( 'On Google login, check a verified email against the platform API before showing the existing Authorizenter question. No profile fields are imported.', 'biapsu-profilesync' ); ?></label></td>
					</tr>

				</table>

				<h2 class="title"><?php esc_html_e( 'Platform connection (server-to-server)', 'biapsu-profilesync' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="base_url"><?php esc_html_e( 'Platform base URL', 'biapsu-profilesync' ); ?></label></th>
						<td><input type="url" name="base_url" id="base_url" class="regular-text" value="<?php echo esc_attr( $platform['base_url'] ); ?>" placeholder="https://platform.example.org" />
							<p class="description"><?php esc_html_e( 'If Profile endpoint is blank, /api/profile/ is derived from this URL.', 'biapsu-profilesync' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="profile_endpoint"><?php esc_html_e( 'Volunteer lookup endpoint', 'biapsu-profilesync' ); ?></label></th>
						<td><input type="url" name="profile_endpoint" id="profile_endpoint" class="regular-text" value="<?php echo esc_attr( $platform['profile_endpoint'] ); ?>" placeholder="<?php echo esc_attr( $this->settings->profile_endpoint() ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="api_key"><?php esc_html_e( 'API Key', 'biapsu-profilesync' ); ?></label></th>
						<td><input type="password" name="api_key" id="api_key" class="regular-text" value="" autocomplete="new-password" placeholder="<?php echo $platform['api_key'] ? esc_attr__( '•••••• (stored — leave blank to keep)', 'biapsu-profilesync' ) : ''; ?>" />
							<p class="description"><?php esc_html_e( 'Stored encrypted. Leave blank to keep the existing key.', 'biapsu-profilesync' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Request timeout (s)', 'biapsu-profilesync' ); ?></th>
						<td><input type="number" name="timeout" min="1" max="60" value="<?php echo (int) $platform['timeout']; ?>" class="small-text" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Verify SSL', 'biapsu-profilesync' ); ?></th>
						<td><label><input type="checkbox" name="verify_ssl" value="1" <?php checked( $platform['verify_ssl'] ); ?> />
							<?php esc_html_e( 'Verify the platform TLS certificate (recommended).', 'biapsu-profilesync' ); ?></label></td>
					</tr>
				</table>



				<p class="submit">
					<button type="submit" name="biapsu_submit" value="1" class="button button-primary"><?php esc_html_e( 'Save changes', 'biapsu-profilesync' ); ?></button>
				</p>
			</form>

			<hr />
			<h2 class="title"><?php esc_html_e( 'Test connection', 'biapsu-profilesync' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'biapsu_profilesync_test' ); ?>
				<input type="hidden" name="action" value="biapsu_profilesync_test" />
				<p><button type="submit" class="button"><?php esc_html_e( 'Test connection to the platform', 'biapsu-profilesync' ); ?></button></p>
			</form>
		</div>
		<?php
	}
}
