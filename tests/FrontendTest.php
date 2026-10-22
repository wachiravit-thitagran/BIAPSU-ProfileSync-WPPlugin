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


	public function test_legacy_ready_state_cannot_show_sync_form_after_migration(): void {
		bia_test_make_user( 1 );
		bia_test_login( 1 );
		update_user_meta( 1, Sync_Controller::STATE_META, 'ready' );

		$out = ( new Frontend( new Settings() ) )->render();
		$this->assertStringNotContainsString( 'name="decision"', $out );
		$this->assertStringNotContainsString( 'ต้องการซิงค์ข้อมูล', $out );
		$this->assertStringContainsString( 'Continue', $out );
	}

	public function test_legacy_sync_error_is_not_shown_to_users(): void {
		bia_test_make_user( 1 );
		bia_test_login( 1 );
		update_user_meta( 1, Sync_Controller::STATE_META, 'ready' );
		update_user_meta( 1, Sync_Controller::ERROR_META, 'Legacy profile sync failed' );
		$out = ( new Frontend( new Settings() ) )->render();
		$this->assertStringNotContainsString( 'Legacy profile sync failed', $out );
	}

}
