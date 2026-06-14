<?php
/**
 * Plugin Name:       BIA PSU ProfileSync
 * Plugin URI:        https://github.com/wachiravit-thitagarn/BIAPSU-ProfileSync-WPPlugin
 * Description:       After a first-time login through Authorizenter, asks the user whether to sync their profile from the Buddhadhamma (พุทธธรรม) platform. On consent, fetches first name, last name, contact, affiliation and user-type data via a server-to-server OAuth2 (client_credentials) call and applies it to the new WordPress user. On decline, the normal Authorizenter flow is preserved.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            BIA PSU
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       biapsu-profilesync
 * Domain Path:       /languages
 *
 * @package BIAPSU\ProfileSync
 */

namespace BIAPSU\ProfileSync;

defined( 'ABSPATH' ) || exit;

define( 'BIAPSU_PROFILESYNC_VERSION', '0.1.0' );
define( 'BIAPSU_PROFILESYNC_FILE', __FILE__ );
define( 'BIAPSU_PROFILESYNC_DIR', plugin_dir_path( __FILE__ ) );
define( 'BIAPSU_PROFILESYNC_URL', plugin_dir_url( __FILE__ ) );

/**
 * Minimal PSR-4-ish autoloader for the plugin's classes.
 *
 * Maps BIAPSU\ProfileSync\Some_Class to includes/class-some-class.php.
 */
spl_autoload_register(
	static function ( $class ) {
		$prefix = __NAMESPACE__ . '\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$file     = 'class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';
		$path     = BIAPSU_PROFILESYNC_DIR . 'includes/' . $file;

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

/**
 * Load translations.
 *
 * @return void
 */
function load_textdomain() {
	load_plugin_textdomain( 'biapsu-profilesync', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'init', __NAMESPACE__ . '\\load_textdomain' );

/**
 * Boot the plugin once all plugins are loaded (so Authorizenter hooks exist).
 *
 * @return void
 */
function boot() {
	Plugin::instance()->hooks();
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\boot', 20 );

/**
 * Activation: ensure the choice page exists and defaults are set.
 *
 * @return void
 */
function activate() {
	require_once BIAPSU_PROFILESYNC_DIR . 'includes/class-settings.php';
	require_once BIAPSU_PROFILESYNC_DIR . 'includes/class-frontend.php';

	$settings = new Settings();
	$settings->maybe_set_defaults();

	( new Frontend( $settings ) )->ensure_page();

	flush_rewrite_rules();
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );

/**
 * Deactivation cleanup (non-destructive).
 *
 * @return void
 */
function deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\deactivate' );
