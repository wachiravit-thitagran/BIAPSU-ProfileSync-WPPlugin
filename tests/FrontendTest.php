<?php
/**
 * Tests for Frontend::render() — the choice-page shortcode output.
 *
 * @package BIAPSU\ProfileSync\Tests
 */

namespace BIAPSU\ProfileSync\Tests;

use BIAPSU\ProfileSync\Frontend;
use BIAPSU\ProfileSync\Settings;
use BIAPSU\ProfileSync\Sync_Controller;
use PHPUnit\Framework\TestCase;

class FrontendTest extends TestCase {

	protected function setUp(): void {
		bia_test_reset();
	}

	public function test_render_prompts_login_when_logged_out(): void {
		$out = ( new Frontend( new Settings() ) )->render();
		$this->assertStringContainsString( 'Please log in', $out );
	}

	public function test_render_shows_continue_when_not_ready(): void {
		bia_test_make_user( 1 );
		bia_test_login( 1 );
		// No state set -> not armed.
		$out = ( new Frontend( new Settings() ) )->render();
		$this->assertStringNotContainsString( 'name="decision"', $out );
		$this->assertStringContainsString( 'Continue', $out );
	}

	public function test_render_shows_choice_form_when_ready(): void {
		bia_test_make_user( 1 );
		bia_test_login( 1 );
		update_user_meta( 1, Sync_Controller::STATE_META, 'ready' );

		$out = ( new Frontend( new Settings() ) )->render();

		// The Thai prompt and both decision buttons are present.
		$this->assertStringContainsString( 'ต้องการซิงค์ข้อมูลจากแพลตฟอร์มหรือไม่', $out );
		$this->assertStringContainsString( 'value="sync"', $out );
		$this->assertStringContainsString( 'value="skip"', $out );
		$this->assertStringContainsString( Sync_Controller::ACTION, $out );
		$this->assertStringContainsString( 'admin-post.php', $out );
	}

	public function test_render_shows_error_banner_on_failed_sync(): void {
		bia_test_make_user( 1 );
		bia_test_login( 1 );
		update_user_meta( 1, Sync_Controller::STATE_META, 'ready' );
		update_user_meta( 1, Sync_Controller::ERROR_META, 'No matching profile was found.' );
		$_GET['biapsu_sync'] = 'error';

		$out = ( new Frontend( new Settings() ) )->render();
		unset( $_GET['biapsu_sync'] );

		$this->assertStringContainsString( 'No matching profile was found.', $out );
	}
}
