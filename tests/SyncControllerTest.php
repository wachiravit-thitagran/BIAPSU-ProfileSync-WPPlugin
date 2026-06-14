<?php
/**
 * Tests for Sync_Controller's state machine: provisioning flag, arming at login,
 * and arming after Authorizenter's question form completes.
 *
 * @package BIAPSU\ProfileSync\Tests
 */

namespace BIAPSU\ProfileSync\Tests;

use BIAPSU\ProfileSync\Platform_Client;
use BIAPSU\ProfileSync\Profile_Mapper;
use BIAPSU\ProfileSync\Settings;
use BIAPSU\ProfileSync\Sync_Controller;
use PHPUnit\Framework\TestCase;

/** Minimal stand-in for Authorizenter Core's Questions service. */
class Fake_Questions {
	public $pending;
	public function __construct( bool $pending ) {
		$this->pending = $pending;
	}
	public function has_pending_required( $user_id, $provider = '' ) {
		return $this->pending;
	}
}

class SyncControllerTest extends TestCase {

	protected function setUp(): void {
		bia_test_reset();
	}

	private function controller(): Sync_Controller {
		$settings = new Settings();
		return new Sync_Controller(
			$settings,
			new Platform_Client( $settings ),
			new Profile_Mapper( $settings )
		);
	}

	private function fake_core( bool $pending ): void {
		$core            = new \stdClass();
		$core->questions = new Fake_Questions( $pending );
		$GLOBALS['__bia_core'] = $core;
	}

	public function test_flag_new_user_sets_await_and_email(): void {
		$user = bia_test_make_user( 1, 'new@example.org' );
		$id   = (object) array( 'email' => 'new@example.org' );

		$this->controller()->flag_new_user( $user, $id );

		$this->assertSame( 'await', get_user_meta( 1, Sync_Controller::STATE_META, true ) );
		$this->assertSame( 'new@example.org', get_user_meta( 1, Sync_Controller::EMAIL_META, true ) );
	}

	public function test_arm_after_login_arms_when_no_questions(): void {
		$user = bia_test_make_user( 1, 'new@example.org' );
		update_user_meta( 1, Sync_Controller::STATE_META, 'await' );

		// No Authorizenter core present -> nothing to wait for.
		$GLOBALS['__bia_core'] = null;
		$this->controller()->arm_after_login( $user, 'google' );

		$this->assertSame( 'ready', get_user_meta( 1, Sync_Controller::STATE_META, true ) );
	}

	public function test_arm_after_login_waits_when_questions_pending(): void {
		$user = bia_test_make_user( 1, 'new@example.org' );
		update_user_meta( 1, Sync_Controller::STATE_META, 'await' );

		$this->fake_core( true ); // required questions still outstanding.
		$this->controller()->arm_after_login( $user, 'google' );

		// Must stay 'await' so Authorizenter's question form runs first.
		$this->assertSame( 'await', get_user_meta( 1, Sync_Controller::STATE_META, true ) );
	}

	public function test_arm_after_login_arms_when_questions_already_done(): void {
		$user = bia_test_make_user( 1, 'new@example.org' );
		update_user_meta( 1, Sync_Controller::STATE_META, 'await' );

		$this->fake_core( false ); // no required questions outstanding.
		$this->controller()->arm_after_login( $user, 'google' );

		$this->assertSame( 'ready', get_user_meta( 1, Sync_Controller::STATE_META, true ) );
	}

	public function test_arm_after_questions_promotes_await_to_ready(): void {
		bia_test_make_user( 1 );
		update_user_meta( 1, Sync_Controller::STATE_META, 'await' );

		$this->controller()->arm_after_questions( 1 );

		$this->assertSame( 'ready', get_user_meta( 1, Sync_Controller::STATE_META, true ) );
	}

	public function test_arm_after_questions_ignores_non_await_states(): void {
		bia_test_make_user( 1 );
		update_user_meta( 1, Sync_Controller::STATE_META, 'synced' );

		$this->controller()->arm_after_questions( 1 );

		// Already-decided users are never re-armed.
		$this->assertSame( 'synced', get_user_meta( 1, Sync_Controller::STATE_META, true ) );
	}

	public function test_disabled_plugin_does_not_flag_user(): void {
		$settings = new Settings();
		$all      = $settings->all();
		$all['enabled'] = false;
		$settings->save( $all );

		$controller = new Sync_Controller(
			new Settings(),
			new Platform_Client( new Settings() ),
			new Profile_Mapper( new Settings() )
		);

		$user = bia_test_make_user( 1, 'new@example.org' );
		$controller->flag_new_user( $user, (object) array( 'email' => 'new@example.org' ) );

		$this->assertSame( '', get_user_meta( 1, Sync_Controller::STATE_META, true ) );
	}
}
