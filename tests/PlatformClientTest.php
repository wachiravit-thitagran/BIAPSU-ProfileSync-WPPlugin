<?php
/**
 * Tests for Platform_Client: profile fetch outcomes with API Key.
 *
 * @package BIAPSU\ProfileSync\Tests
 */

namespace BIAPSU\ProfileSync\Tests;

use BIAPSU\ProfileSync\Platform_Client;
use BIAPSU\ProfileSync\Settings;
use PHPUnit\Framework\TestCase;

class PlatformClientTest extends TestCase {

	protected function setUp(): void {
		bia_test_reset();
	}

	private function configured_settings(): Settings {
		$settings = new Settings();
		$all      = $settings->all();
		$all['platform']['base_url'] = 'https://platform.example.org';
		$all['platform']['api_key']  = 'secret-api-key';
		$settings->save( $all );
		return new Settings();
	}

	public function test_fetch_profile_returns_error_when_not_configured(): void {
		$client = new Platform_Client( new Settings() );
		$this->assertTrue( is_wp_error( $client->fetch_profile( 'test@example.org' ) ) );
	}

	public function test_fetch_profile_success(): void {
		$client = new Platform_Client( $this->configured_settings() );

		bia_test_http_push(
			200,
			array(
				'success' => true,
				'data'    => array( 'first_name' => 'สมหญิง', 'last_name' => 'รักธรรม' ),
			)
		);

		$profile = $client->fetch_profile( 'somying@example.org' );
		$this->assertIsArray( $profile );
		$this->assertSame( 'สมหญิง', $profile['first_name'] );

		// Profile request carried the Api-Key token and email query.
		$last = end( $GLOBALS['__http_log'] );
		$this->assertSame( 'GET', $last['method'] );
		$this->assertStringContainsString( 'email=', $last['url'] );
		$this->assertSame( 'Api-Key secret-api-key', $last['args']['headers']['Authorization'] );
	}

	public function test_fetch_profile_not_found(): void {
		$client = new Platform_Client( $this->configured_settings() );
		bia_test_http_push( 400, array( 'success' => false, 'message' => 'Volunteer not found' ) );

		$result = $client->fetch_profile( 'missing@example.org' );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'biapsu_not_found', $result->get_error_code() );
	}

	public function test_fetch_profile_requires_email(): void {
		$client = new Platform_Client( $this->configured_settings() );
		$result = $client->fetch_profile( '' );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'biapsu_no_email', $result->get_error_code() );
	}
}
