<?php
/**
 * Tests for Profile_Mapper: field-group application and safeguards.
 *
 * @package BIAPSU\ProfileSync\Tests
 */

namespace BIAPSU\ProfileSync\Tests;

use BIAPSU\ProfileSync\Profile_Mapper;
use BIAPSU\ProfileSync\Settings;
use PHPUnit\Framework\TestCase;

class ProfileMapperTest extends TestCase {

	protected function setUp(): void {
		bia_test_reset();
	}

	private function full_profile(): array {
		return array(
			'first_name'            => 'สมชาย',
			'last_name'             => 'ใจดี',
			'email'                 => 'somchai@example.org',
			'phone_number'          => '0812345678',
			'affiliation'           => 'มหาวิทยาลัยสงขลานครินทร์',
			'department'            => 'พุทธศาสน์',
			'position'              => 'อาสาสมัคร',
			'location'              => 'สงขลา',
			'user_type'             => 'volunteer',
			'user_type_description' => 'ผู้ถอดความ',
			'join_reason'           => 'ทำบุญ',
		);
	}

	public function test_applies_all_groups_by_default(): void {
		$user   = bia_test_make_user( 1, 'somchai@example.org' );
		$mapper = new Profile_Mapper( new Settings() );

		$applied = $mapper->apply( $user, $this->full_profile() );

		$this->assertSame( 'สมชาย', get_user_meta( 1, 'first_name', true ) );
		$this->assertSame( 'ใจดี', get_user_meta( 1, 'last_name', true ) );
		$this->assertSame( 'สมชาย ใจดี', $GLOBALS['__users'][1]->display_name );
		$this->assertSame( '0812345678', get_user_meta( 1, 'biapsu_phone_number', true ) );
		$this->assertSame( 'พุทธศาสน์', get_user_meta( 1, 'biapsu_department', true ) );
		$this->assertSame( 'volunteer', get_user_meta( 1, 'biapsu_user_type', true ) );
		$this->assertArrayHasKey( 'name', $applied );
		$this->assertNotEmpty( get_user_meta( 1, 'biapsu_synced_at', true ) );
	}

	public function test_disabled_groups_are_skipped(): void {
		$settings = new Settings();
		$all      = $settings->all();
		$all['fields'] = array(
			'name'        => true,
			'contact'     => false,
			'affiliation' => false,
			'user_type'   => false,
		);
		$settings->save( $all );

		$user = bia_test_make_user( 2, 'a@example.org' );
		( new Profile_Mapper( new Settings() ) )->apply( $user, $this->full_profile() );

		$this->assertSame( 'สมชาย', get_user_meta( 2, 'first_name', true ) );
		$this->assertSame( '', get_user_meta( 2, 'biapsu_phone_number', true ) );
		$this->assertSame( '', get_user_meta( 2, 'biapsu_department', true ) );
	}

	public function test_does_not_overwrite_existing_email(): void {
		$user = bia_test_make_user( 3, 'existing@example.org' );
		( new Profile_Mapper( new Settings() ) )->apply( $user, $this->full_profile() );

		// Existing, non-empty email must be preserved (anti-hijack safeguard).
		$this->assertSame( 'existing@example.org', $GLOBALS['__users'][3]->user_email );
	}

	public function test_fills_empty_email_from_platform(): void {
		$user             = bia_test_make_user( 4, 'existing@example.org' );
		$user->user_email = '';
		$GLOBALS['__users'][4]->user_email = '';

		( new Profile_Mapper( new Settings() ) )->apply( $user, $this->full_profile() );

		$this->assertSame( 'somchai@example.org', $GLOBALS['__users'][4]->user_email );
	}
}
