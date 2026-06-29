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
			__( 'BIA PSU ProfileSync', 'biapsu-profilesync' ),
			__( 'ProfileSync', 'biapsu-profilesync' ),
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

		$all['fields'] = array(
			'name'        => ! empty( $_POST['field_name'] ),
			'contact'     => ! empty( $_POST['field_contact'] ),
			'affiliation' => ! empty( $_POST['field_affiliation'] ),
			'user_type'   => ! empty( $_POST['field_user_type'] ),
		);

		$all['choice_page_id'] = isset( $_POST['choice_page_id'] ) ? (int) $_POST['choice_page_id'] : 0;

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

		// To test the API key, we make a profile request with a dummy email.
		// A 400 or 200 means the API key was accepted, even if the user isn't found.
		$response = $this->client->fetch_profile( 'test_connection@example.com' );

		if ( is_wp_error( $response ) && $response->get_error_code() !== 'biapsu_not_found' && strpos( $response->get_error_code(), '_http' ) === false ) {
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
		$fields   = $all['fields'];

		$test = get_transient( 'biapsu_profilesync_test_result' );
		if ( $test ) {
			delete_transient( 'biapsu_profilesync_test_result' );
		}

		$pages = get_pages();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'BIA PSU ProfileSync', 'biapsu-profilesync' ); ?></h1>
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
						<th scope="row"><?php esc_html_e( 'Enable sync prompt', 'biapsu-profilesync' ); ?></th>
						<td><label><input type="checkbox" name="enabled" value="1" <?php checked( $all['enabled'] ); ?> />
							<?php esc_html_e( 'Show the sync choice to first-time users after login.', 'biapsu-profilesync' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="choice_page_id"><?php esc_html_e( 'Choice page', 'biapsu-profilesync' ); ?></label></th>
						<td>
							<select name="choice_page_id" id="choice_page_id">
								<option value="0"><?php esc_html_e( '— Select —', 'biapsu-profilesync' ); ?></option>
								<?php foreach ( $pages as $page ) : ?>
									<option value="<?php echo (int) $page->ID; ?>" <?php selected( (int) $all['choice_page_id'], (int) $page->ID ); ?>>
										<?php echo esc_html( $page->post_title ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Page containing the [biapsu_profilesync] shortcode. Auto-created on activation.', 'biapsu-profilesync' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Platform connection (server-to-server)', 'biapsu-profilesync' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="base_url"><?php esc_html_e( 'Platform base URL', 'biapsu-profilesync' ); ?></label></th>
						<td><input type="url" name="base_url" id="base_url" class="regular-text" value="<?php echo esc_attr( $platform['base_url'] ); ?>" placeholder="https://platform.example.org" />
							<p class="description"><?php esc_html_e( 'Token/profile endpoints are derived from this if left blank below.', 'biapsu-profilesync' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="profile_endpoint"><?php esc_html_e( 'Profile endpoint', 'biapsu-profilesync' ); ?></label></th>
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

				<h2 class="title"><?php esc_html_e( 'Fields to sync', 'biapsu-profilesync' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Field groups', 'biapsu-profilesync' ); ?></th>
						<td>
							<label><input type="checkbox" name="field_name" value="1" <?php checked( $fields['name'] ); ?> /> <?php esc_html_e( 'Name (first name, last name, display name)', 'biapsu-profilesync' ); ?></label><br />
							<label><input type="checkbox" name="field_contact" value="1" <?php checked( $fields['contact'] ); ?> /> <?php esc_html_e( 'Contact (phone, email if empty)', 'biapsu-profilesync' ); ?></label><br />
							<label><input type="checkbox" name="field_affiliation" value="1" <?php checked( $fields['affiliation'] ); ?> /> <?php esc_html_e( 'Affiliation (affiliation, department, position, location)', 'biapsu-profilesync' ); ?></label><br />
							<label><input type="checkbox" name="field_user_type" value="1" <?php checked( $fields['user_type'] ); ?> /> <?php esc_html_e( 'User type (user type, description, join reason)', 'biapsu-profilesync' ); ?></label>
						</td>
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
