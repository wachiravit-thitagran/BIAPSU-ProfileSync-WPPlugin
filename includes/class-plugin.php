<?php
/**
 * Plugin bootstrap / dependency wiring.
 *
 * @package BIAPSU\ProfileSync
 */

namespace BIAPSU\ProfileSync;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton that constructs collaborators and registers their hooks.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Settings store.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Admin settings page.
	 *
	 * @var Admin_Settings
	 */
	private $admin;

	/**
	 * Sync controller (login gate + decision handler).
	 *
	 * @var Sync_Controller
	 */
	private $controller;

	/**
	 * Front-end (choice page renderer).
	 *
	 * @var Frontend
	 */
	private $frontend;

	/**
	 * Get the shared instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Build collaborators.
	 */
	private function __construct() {
		$this->settings   = new Settings();
		$client           = new Platform_Client( $this->settings );
		$mapper           = new Profile_Mapper( $this->settings );
		$this->controller = new Sync_Controller( $this->settings, $client, $mapper );
		$this->frontend   = new Frontend( $this->settings );
		$this->admin      = new Admin_Settings( $this->settings, $client );
	}

	/**
	 * Register all WordPress hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		$this->settings->maybe_set_defaults();
		$this->controller->hooks();
		$this->frontend->hooks();
		$this->init_updater();

		if ( is_admin() ) {
			$this->admin->hooks();
		}
	}

	/**
	 * Register self-hosted updates from the plugin's GitHub releases.
	 *
	 * @return void
	 */
	private function init_updater() {
		$repo = defined( 'BIAPSU_PROFILESYNC_GITHUB_REPO' ) ? BIAPSU_PROFILESYNC_GITHUB_REPO : '';

		/**
		 * Filter the GitHub repository ("owner/repo") used for self-hosted updates.
		 *
		 * Return '' to disable the update check entirely.
		 *
		 * @param string $repo GitHub repository as "owner/repo".
		 */
		$repo = (string) apply_filters( 'biapsu_profilesync_github_repo', $repo );

		if ( '' === trim( $repo ) ) {
			return;
		}

		$updater = new Github_Updater(
			BIAPSU_PROFILESYNC_FILE,
			'biapsu-profilesync',
			$repo,
			BIAPSU_PROFILESYNC_VERSION,
			'biapsu-profilesync.zip'
		);
		$updater->hooks();
	}

	/**
	 * Expose settings (used by tests / external code).
	 *
	 * @return Settings
	 */
	public function settings() {
		return $this->settings;
	}
}
