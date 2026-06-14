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

		if ( is_admin() ) {
			$this->admin->hooks();
		}
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
