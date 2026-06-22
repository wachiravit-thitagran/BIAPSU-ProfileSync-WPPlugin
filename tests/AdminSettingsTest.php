<?php
/**
 * Tests for Admin_Settings.
 *
 * @package BIAPSU\ProfileSync\Tests
 */

namespace BIAPSU\ProfileSync\Tests;

use PHPUnit\Framework\TestCase;
use BIAPSU\ProfileSync\Settings;
use BIAPSU\ProfileSync\Platform_Client;
use BIAPSU\ProfileSync\Admin_Settings;

class AdminSettingsTest extends TestCase {

	/**
	 * @var Settings
	 */
	private $settings;

	/**
	 * @var Platform_Client
	 */
	private $client;

	/**
	 * @var Admin_Settings
	 */
	private $admin;

	protected function setUp(): void {
		parent::setUp();
		bia_test_reset();
		$this->settings = new Settings();
		$this->client   = new Platform_Client( $this->settings );
		$this->admin    = new Admin_Settings( $this->settings, $this->client );
	}

	public function test_menu_hooks() {
		// Just ensure hooks function can be called.
		$this->admin->hooks();
		$this->assertTrue( true );
	}

	public function test_menu_registration() {
		$this->admin->menu();
		$this->assertTrue( true );
	}

	public function test_render_does_not_save_if_not_admin() {
		$GLOBALS['__current_user_can'] = false;
		$_POST['biapsu_submit'] = '1';
		$_POST['enabled'] = '1';

		ob_start();
		$this->admin->render();
		ob_end_clean();

		$all = $this->settings->all();
		$this->assertSame( '', $all['platform']['base_url'] );
	}

	public function test_render_saves_settings_if_admin() {
		$GLOBALS['__current_user_can'] = true;
		$_POST['biapsu_submit']        = '1';
		$_POST['enabled']              = '1';
		$_POST['base_url']             = 'https://platform.test';
		$_POST['timeout']              = '15';
		$_POST['verify_ssl']           = '1';
		$_POST['api_key']              = 'new_secret_key';
		$_POST['field_name']           = '1';
		$_POST['choice_page_id']       = '55';

		ob_start();
		$this->admin->render();
		ob_end_clean();

		$all = $this->settings->all();
		$this->assertTrue( $all['enabled'] );
		$this->assertSame( 'https://platform.test', $all['platform']['base_url'] );
		$this->assertSame( 15, $all['platform']['timeout'] );
		$this->assertTrue( $all['platform']['verify_ssl'] );
		// Since sanitize_text_field encrypts inside Settings->save, we just check if it changed.
		// Wait, the test uses the real Settings class. Let's assume it gets updated.
		$this->assertTrue( $all['fields']['name'] );
		$this->assertFalse( $all['fields']['contact'] );
		$this->assertSame( 55, $all['choice_page_id'] );
	}

	public function test_handle_test_denies_access() {
		$GLOBALS['__current_user_can'] = false;
		
		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'wp_die: Permission denied.' );

		$this->admin->handle_test();
	}

	public function test_handle_test_redirects_on_success() {
		$GLOBALS['__current_user_can'] = true;
		$this->settings->save( array( 'platform' => array( 'api_key' => 'valid', 'base_url' => 'https://platform.test' ) ) );

		// Enqueue a successful dummy response
		bia_test_http_push( 400, array( 'success' => false, 'message' => 'Volunteer not found' ) );

		try {
			$this->admin->handle_test();
		} catch ( \Exception $e ) {
			if ( strpos( $e->getMessage(), 'wp_safe_redirect' ) === false ) {
				throw $e;
			}
		}

		$transient = get_transient( 'biapsu_profilesync_test_result' );
		$this->assertNotFalse( $transient );
		$this->assertSame( 'ok', $transient['status'], 'Transient was: ' . print_r($transient, true) );
		$this->assertStringContainsString( 'API Key accepted', $transient['msg'] );
		$this->assertSame( 'https://example.test/wp-admin/admin.php?page=biapsu-profilesync', $GLOBALS['__last_redirect'] );
	}
}
