<?php
/**
 * Regression tests for user-consented BIA OIDC account linking.
 *
 * @package BIAPSU\ProfileSync\Tests
 */

namespace BIAPSU\ProfileSync\Tests;

use BIAPSU\ProfileSync\Account_Linking;
use BIAPSU\ProfileSync\Settings;
use BIAPSU\ProfileSync\Volunteer_Status;
use PHPUnit\Framework\TestCase;

final class AccountLinkingTest extends TestCase {
	protected function setUp(): void {
		bia_test_reset();
	}

	public function test_google_question_is_available_without_platform_api_key(): void {
		bia_test_make_user( 17, 'google@example.org' );
		bia_test_login( 17 );
		update_user_meta( 17, 'authorizenter_last_provider', 'google' );
		$settings = new Settings();
		$bridge = new Volunteer_Status( $settings, new \BIAPSU\ProfileSync\Platform_Client( $settings ) );
		$bridge->on_login( get_user_by( 'id', 17 ), 'google', (object) array( 'email' => 'google@example.org', 'email_verified' => true ) );
		$questions = $bridge->filter_questions( array( array( 'id' => 'is_bia_volunteer' ) ) );
		$this->assertCount( 1, $questions );
		$this->assertSame( array(), $GLOBALS['__http_log'] );
		$this->assertSame( '', get_user_meta( 17, 'authorizenter_answer_is_bia_volunteer', true ) );
	}

	public function test_manual_yes_is_eligible_and_no_is_not(): void {
		bia_test_make_user( 17 );
		$service = new Account_Linking( new Settings() );
		$this->assertFalse( $service->eligible( 17 ) );
		update_user_meta( 17, 'authorizenter_answers', array( 'is_bia_volunteer' => 'ไม่ใช่' ) );
		$this->assertFalse( $service->eligible( 17 ) );
		update_user_meta( 17, 'authorizenter_answers', array( 'is_bia_volunteer' => 'ใช่' ) );
		$this->assertTrue( $service->eligible( 17 ) );
	}

	public function test_oidc_link_requires_verified_oauth_subject_not_an_answer(): void {
		bia_test_make_user( 17 );
		$service = new Account_Linking( new Settings() );
		update_user_meta( 17, 'authorizenter_answers', array( 'is_bia_volunteer' => 'ใช่' ) );
		$this->assertFalse( $service->is_linked( 17 ) );
		update_user_meta( 17, 'authorizenter_link_oidc', 'stable-platform-subject' );
		$this->assertTrue( $service->is_linked( 17 ) );
	}

	public function test_oidc_callback_without_matching_link_intent_never_links(): void {
		bia_test_make_user( 17 );
		bia_test_login( 17 );
		update_user_meta( 17, 'authorizenter_answers', array( 'is_bia_volunteer' => 'ใช่' ) );
		$service = new Account_Linking( new Settings() );
		$identity = (object) array( 'provider' => 'oidc', 'sub' => 'attacker-subject', 'email' => 'attacker@example.org' );
		$this->assertNull( $service->pre_resolve_user( null, $identity, array() ) );
		$service->after_login( get_user_by( 'id', 17 ), 'oidc', $identity );
		$this->assertFalse( $service->is_linked( 17 ) );
	}

	public function test_mcp_registers_read_only_link_status(): void {
		\BIAPSU\ProfileSync\MCP::register_abilities();
		$this->assertArrayHasKey( 'biapsu-profilesync/get-link-status', $GLOBALS['mock_abilities'] );
		$this->assertTrue( $GLOBALS['mock_abilities']['biapsu-profilesync/get-link-status']['meta']['annotations']['readonly'] );
	}
}
