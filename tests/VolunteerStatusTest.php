<?php
/**
 * Self-reported volunteer question tests (no Platform API requests).
 *
 * @package BIAPSU\ProfileSync\Tests
 */

namespace BIAPSU\ProfileSync\Tests;

use BIAPSU\ProfileSync\Platform_Client;
use BIAPSU\ProfileSync\Settings;
use BIAPSU\ProfileSync\Volunteer_Status;
use PHPUnit\Framework\TestCase;

final class VolunteerStatusTest extends TestCase {
	protected function setUp(): void {
		bia_test_reset();
	}

	private function controller() {
		$settings = new Settings();
		return new Volunteer_Status( $settings, new Platform_Client( $settings ) );
	}

	public function test_google_login_leaves_existing_answer_unchanged_without_http(): void {
		$user = bia_test_make_user( 14, 'google@example.org' );
		bia_test_login( 14 );
		update_user_meta( 14, 'authorizenter_last_provider', 'google' );
		update_user_meta( 14, 'authorizenter_answers', array( 'is_bia_volunteer' => 'ไม่ใช่' ) );
		$this->controller()->on_login( $user, 'google', (object) array( 'email' => 'google@example.org', 'email_verified' => true ) );
		$this->assertSame( array(), $GLOBALS['__http_log'] );
		$this->assertSame( array( 'is_bia_volunteer' => 'ไม่ใช่' ), get_user_meta( 14, 'authorizenter_answers', true ) );
	}

	public function test_google_login_with_no_key_exposes_existing_required_question(): void {
		bia_test_make_user( 14 );
		bia_test_login( 14 );
		update_user_meta( 14, 'authorizenter_last_provider', 'google' );
		$questions = array(
			array( 'id' => 'is_bia_volunteer', 'required' => true, 'type' => 'radio' ),
			array( 'id' => 'other' ),
		);
		$this->assertSame( $questions, $this->controller()->filter_questions( $questions ) );
	}

	public function test_oidc_signin_does_not_ask_google_question(): void {
		bia_test_make_user( 14 );
		bia_test_login( 14 );
		update_user_meta( 14, 'authorizenter_last_provider', 'oidc' );
		$questions = array( array( 'id' => 'is_bia_volunteer' ), array( 'id' => 'other' ) );
		$this->assertSame( array( array( 'id' => 'other' ) ), $this->controller()->filter_questions( $questions ) );
	}

	public function test_disabling_feature_hides_volunteer_question(): void {
		bia_test_make_user( 14 );
		bia_test_login( 14 );
		update_user_meta( 14, 'authorizenter_last_provider', 'google' );
		$settings = new Settings();
		$all = $settings->all();
		$all['enabled'] = false;
		$settings->save( $all );
		$bridge = new Volunteer_Status( $settings, new Platform_Client( $settings ) );
		$this->assertSame( array(), $bridge->filter_questions( array( array( 'id' => 'is_bia_volunteer' ) ) ) );
	}

	public function test_never_registers_legacy_profile_sync_gate(): void {
		$this->controller()->hooks();
		$actions = array_column( $GLOBALS['__actions'], 'tag' );
		$this->assertContains( 'authorizenter_login_success', $actions );
		$this->assertNotContains( 'admin_post_biapsu_profilesync_decision', $actions );
	}
}
