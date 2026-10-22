<?php
/**
 * Admin page tests for OAuth-based account linking.
 *
 * @package BIAPSU\ProfileSync\Tests
 */

namespace BIAPSU\ProfileSync\Tests;

use BIAPSU\ProfileSync\Admin_Settings;
use BIAPSU\ProfileSync\Platform_Client;
use BIAPSU\ProfileSync\Settings;
use PHPUnit\Framework\TestCase;

final class AdminSettingsTest extends TestCase {
	/** @var Settings */
	private $settings;
	/** @var Admin_Settings */
	private $admin;

	protected function setUp(): void {
		bia_test_reset();
		unset( $_POST['biapsu_submit'] );
		$this->settings = new Settings();
		$this->admin = new Admin_Settings( $this->settings, new Platform_Client( $this->settings ) );
	}

	public function test_registration_adds_only_admin_menu_hook(): void {
		$this->admin->hooks();
		$this->assertContains( 'admin_menu', array_column( $GLOBALS['__actions'], 'tag' ) );
		$this->assertNotContains( 'admin_post_biapsu_profilesync_test', array_column( $GLOBALS['__actions'], 'tag' ) );
	}

	public function test_non_admin_cannot_change_settings(): void {
		$GLOBALS['__current_user_can'] = false;
		$_POST['biapsu_submit'] = '1';
		ob_start();
		$this->admin->render();
		ob_end_clean();
		$this->assertTrue( $this->settings->get( 'enabled' ) );
	}

	public function test_admin_can_disable_self_reported_linking(): void {
		$GLOBALS['__current_user_can'] = true;
		$_POST['biapsu_submit'] = '1';
		unset( $_POST['enabled'] );
		ob_start();
		$this->admin->render();
		ob_end_clean();
		$this->assertFalse( $this->settings->get( 'enabled' ) );
	}

	public function test_admin_page_does_not_expose_api_key_or_profile_sync(): void {
		$GLOBALS['__current_user_can'] = true;
		ob_start();
		$this->admin->render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'BIA PSU Account Linking', $html );
		$this->assertStringNotContainsString( 'name="api_key"', $html );
		$this->assertStringNotContainsString( 'Test connection', $html );
		$this->assertStringNotContainsString( 'Fields to sync', $html );
	}
}
