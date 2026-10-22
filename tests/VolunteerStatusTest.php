<?php
/**
 * Google sign-in volunteer detection without profile synchronization.
 *
 * @package BIAPSU\ProfileSync\Tests
 */

namespace BIAPSU\ProfileSync\Tests;

use BIAPSU\ProfileSync\Platform_Client;
use BIAPSU\ProfileSync\Settings;
use BIAPSU\ProfileSync\Volunteer_Status;
use PHPUnit\Framework\TestCase;

if ( is_readable( dirname( __DIR__ ) . '/includes/class-volunteer-status.php' ) ) {
	require_once dirname( __DIR__ ) . '/includes/class-volunteer-status.php';
}

class Volunteer_Questions_Fake {
	public $save_calls = 0;

	public function get_answers( $user_id ) {
		$answers = get_user_meta( $user_id, 'authorizenter_answers', true );
		return is_array( $answers ) ? $answers : array();
	}

	public function save_answers( $user_id, array $answers ) {
		++$this->save_calls;
		$merged = array_merge( $this->get_answers( $user_id ), $answers );
		update_user_meta( $user_id, 'authorizenter_answers', $merged );
		update_user_meta( $user_id, 'authorizenter_answer_is_bia_volunteer', $merged['is_bia_volunteer'] );
		return true;
	}
}

class VolunteerStatusTest extends TestCase {
	private $questions;

	protected function setUp(): void {
		bia_test_reset();
		$settings = new Settings();
		$all = $settings->all();
		$all['enabled'] = true;
		$all['platform']['base_url'] = 'https://bia.psu.ac.th';
		$all['platform']['api_key'] = 'test-key';
		$settings->save( $all );
		$this->questions = new Volunteer_Questions_Fake();
		$GLOBALS['__bia_core'] = (object) array( 'questions' => $this->questions );
	}

	private function controller() {
		$settings = new Settings();
		return new Volunteer_Status( $settings, new Platform_Client( $settings ) );
	}

	private function identity( $email = 'google@example.org', $verified = true ) {
		return (object) array( 'email' => $email, 'email_verified' => $verified );
	}

	private function volunteer_question() {
		return array(
			array( 'id' => 'is_bia_volunteer', 'type' => 'radio', 'required' => true, 'options' => array( 'ใช่', 'ไม่ใช่' ) ),
			array( 'id' => 'unrelated', 'type' => 'text', 'required' => false ),
		);
	}

	public function test_class_exists_for_google_signin_volunteer_check(): void {
		$this->assertTrue( class_exists( Volunteer_Status::class ), 'Implement volunteer check in existing ProfileSync plugin.' );
	}

	public function test_api_match_marks_existing_google_account_yes_without_profile_sync(): void {
		$user = bia_test_make_user( 14, 'google@example.org' );
		bia_test_login( 14 );
		update_user_meta( 14, 'authorizenter_last_provider', 'google' );
		bia_test_http_push( 200, array( 'success' => true, 'data' => array(
			'email' => 'google@example.org',
			'first_name' => 'Private',
			'phone_number' => '000000000',
		) ) );

		$this->controller()->on_login( $user, 'google', $this->identity() );

		$this->assertSame( 'ใช่', get_user_meta( 14, 'authorizenter_answer_is_bia_volunteer', true ) );
		$this->assertSame( 'platform_api', get_user_meta( 14, Volunteer_Status::SOURCE_META, true ) );
		$this->assertSame( 1, $this->questions->save_calls );
		$this->assertSame( '', get_user_meta( 14, 'biapsu_phone_number', true ) );
		$this->assertSame( '', get_user_meta( 14, 'first_name', true ) );
	}

	public function test_not_found_defers_to_existing_authorizenter_question(): void {
		$user = bia_test_make_user( 14, 'google@example.org' );
		bia_test_login( 14 );
		update_user_meta( 14, 'authorizenter_last_provider', 'google' );
		bia_test_http_push( 400, array( 'success' => false, 'message' => 'Volunteer not found' ) );

		$this->controller()->on_login( $user, 'google', $this->identity() );

		$this->assertSame( '', get_user_meta( 14, 'authorizenter_answers', true ) );
		$this->assertSame( 'not_found', get_user_meta( 14, Volunteer_Status::LOOKUP_META, true ) );
		$this->assertCount( 2, $this->controller()->filter_questions( $this->volunteer_question() ) );
	}

	public function test_api_error_does_not_invent_answer_or_block_user_with_question(): void {
		$user = bia_test_make_user( 14, 'google@example.org' );
		bia_test_login( 14 );
		update_user_meta( 14, 'authorizenter_last_provider', 'google' );
		$GLOBALS['__http_queue'][] = new \WP_Error( 'http_request_failed', 'Connection timeout' );

		$this->controller()->on_login( $user, 'google', $this->identity() );

		$this->assertSame( '', get_user_meta( 14, 'authorizenter_answers', true ) );
		$this->assertSame( 'unavailable', get_user_meta( 14, Volunteer_Status::LOOKUP_META, true ) );
		$this->assertSame( 'unrelated', $this->controller()->filter_questions( $this->volunteer_question() )[0]['id'] );
	}

	public function test_missing_api_key_fails_open_and_retries_after_configuration(): void {
		$user = bia_test_make_user( 14, 'google@example.org' );
		bia_test_login( 14 );
		update_user_meta( 14, 'authorizenter_last_provider', 'google' );
		$settings = new Settings();
		$all = $settings->all();
		$all['platform']['api_key'] = '';
		$settings->save( $all );

		$this->controller()->on_login( $user, 'google', $this->identity() );
		$this->assertSame( 'unavailable', get_user_meta( 14, Volunteer_Status::LOOKUP_META, true ) );

		$all['platform']['api_key'] = 'new-key';
		$settings->save( $all );
		bia_test_http_push( 200, array( 'success' => true, 'data' => array( 'email' => 'google@example.org' ) ) );
		$this->controller()->on_login( $user, 'google', $this->identity() );

		$this->assertSame( 'ใช่', get_user_meta( 14, 'authorizenter_answer_is_bia_volunteer', true ) );
	}

	public function test_manual_yes_or_no_is_not_overwritten_or_queried_again(): void {
		$user = bia_test_make_user( 14, 'google@example.org' );
		update_user_meta( 14, 'authorizenter_answers', array( 'is_bia_volunteer' => 'ไม่ใช่' ) );

		$this->controller()->on_login( $user, 'google', $this->identity() );
		$this->assertSame( array( 'is_bia_volunteer' => 'ไม่ใช่' ), get_user_meta( 14, 'authorizenter_answers', true ) );
		$this->assertCount( 0, $GLOBALS['__http_log'] );
	}

	public function test_non_google_login_does_not_call_api_or_show_volunteer_question(): void {
		$user = bia_test_make_user( 14, 'google@example.org' );
		bia_test_login( 14 );
		update_user_meta( 14, 'authorizenter_last_provider', 'oidc' );

		$this->controller()->on_login( $user, 'oidc', $this->identity() );

		$this->assertCount( 0, $GLOBALS['__http_log'] );
		$this->assertSame( 'unrelated', $this->controller()->filter_questions( $this->volunteer_question() )[0]['id'] );
	}

	public function test_unverified_or_mismatched_email_never_auto_marks_volunteer(): void {
		$user = bia_test_make_user( 14, 'google@example.org' );
		bia_test_login( 14 );
		update_user_meta( 14, 'authorizenter_last_provider', 'google' );

		$this->controller()->on_login( $user, 'google', $this->identity( 'google@example.org', false ) );
		$this->assertCount( 0, $GLOBALS['__http_log'] );

		bia_test_http_push( 200, array( 'success' => true, 'data' => array( 'email' => 'other@example.org' ) ) );
		$this->controller()->on_login( $user, 'google', $this->identity() );
		$this->assertSame( '', get_user_meta( 14, 'authorizenter_answers', true ) );
		$this->assertSame( 'unavailable', get_user_meta( 14, Volunteer_Status::LOOKUP_META, true ) );
	}

	public function test_no_legacy_profile_sync_actions_or_redirect_gate_registered(): void {
		$this->controller()->hooks();
		$hooks = array_column( $GLOBALS['__actions'], 'tag' );
		$this->assertContains( 'authorizenter_login_success', $hooks );
		$this->assertNotContains( 'template_redirect', $hooks );
		$this->assertNotContains( 'authorizenter_user_provisioned', $hooks );
		$this->assertNotContains( 'admin_post_biapsu_profilesync_decision', $hooks );
	}
}
