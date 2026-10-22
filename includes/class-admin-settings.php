<?php
/**
 * Settings for consent-based OIDC account linking.
 *
 * @package BIAPSU\ProfileSync
 */

namespace BIAPSU\ProfileSync;

defined( 'ABSPATH' ) || exit;

/** WordPress Admin settings without server-side profile APIs or credentials. */
class Admin_Settings {
	const SLUG  = 'biapsu-profilesync';
	const NONCE = 'biapsu_profilesync_save';

	/** @var Settings */
	private $settings;

	/** @param Settings $settings Plugin settings. */
	public function __construct( Settings $settings, Platform_Client $client ) {
		$this->settings = $settings;
	}

	/** Register admin menu. */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
	}

	/** Register the settings page. */
	public function menu() {
		add_menu_page(
			__( 'BIA PSU Account Linking', 'biapsu-profilesync' ),
			__( 'Account Linking', 'biapsu-profilesync' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-admin-links'
		);
	}

	/** Render the switch; Authorizenter owns the question and provider config. */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! empty( $_POST['biapsu_submit'] ) ) {
			check_admin_referer( self::NONCE );
			$all = $this->settings->all();
			$all['enabled'] = ! empty( $_POST['enabled'] );
			$this->settings->save( $all );
			add_settings_error( self::SLUG, 'saved', __( 'Settings saved.', 'biapsu-profilesync' ), 'updated' );
		}

		$enabled = (bool) $this->settings->get( 'enabled' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'BIA PSU Account Linking', 'biapsu-profilesync' ); ?></h1>
			<p><?php esc_html_e( 'Google users answer the existing volunteer question. A Yes answer offers consent-based linking to the Buddhadhamma Platform via Authorizenter OIDC. No Platform API Key or profile data synchronization is used.', 'biapsu-profilesync' ); ?></p>
			<?php settings_errors( self::SLUG ); ?>
			<form method="post" action="">
				<?php wp_nonce_field( self::NONCE ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable volunteer status check and account linking', 'biapsu-profilesync' ); ?></th>
						<td><label><input type="checkbox" name="enabled" value="1" <?php checked( $enabled ); ?> />
							<?php esc_html_e( 'Use existing Authorizenter question is_bia_volunteer and OIDC provider oidc.', 'biapsu-profilesync' ); ?></label></td>
					</tr>
				</table>
				<p><?php esc_html_e( 'Configure the required Yes/No volunteer question and the Buddhadhamma Platform OIDC provider in Authorizenter settings.', 'biapsu-profilesync' ); ?></p>
				<p class="submit"><button type="submit" name="biapsu_submit" value="1" class="button button-primary"><?php esc_html_e( 'Save changes', 'biapsu-profilesync' ); ?></button></p>
			</form>
		</div>
		<?php
	}
}
