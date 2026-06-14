<?php
/**
 * Tests for Platform_Client: token grant, caching, profile fetch outcomes.
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
		$all['platform']['base_url']      = 'https://platform.example.org';
		$all['platform']['client_id']     = 'client-abc';
		$all['platform']['client_secret'] = 'secret-xyz';
		$settings->save( $all );
		return new Settings();
	}

	public function test_get_access_token_returns_error_when_not_configured(): void {
		$client = new Platform_Client( new Settings() );
		$this->assertTrue( is_wp_error( $client->get_access_token() ) );
	}

	public function test_get_access_token_caches_token(): void {
		$client = new Platform_Client( $this->configured_settings() );

		bia_test_http_push( 200, array( 'access_token' => 'tok-1', 'expires_in' => 3600 ) );
		$this->assertSame( 'tok-1', $client->get_access_token() );

		// Second call must hit the cache (no new HTTP response queued).
		$this->assertSame( 'tok-1', $client->get_access_token() );
		$this->assertCount( 1, $GLOBALS['__http_log'] );
	}

	public function test_get_access_token_error_on_bad_response(): void {
		$client = new Platform_Client( $this->configured_settings() );
		bia_test_http_push( 401, array( 'error' => 'invalid_client' ) );

		$token = $client->get_access_token();
		$this->assertTrue( is_wp_error( $token ) );
		$this->assertSame( 'biapsu_token_failed', $token->get_error_code() );
	}

	public function test_fetch_profile_success(): void {
		$client = new Platform_Client( $this->configured_settings() );

		bia_test_http_push( 200, array( 'access_token' => 'tok-1', 'expires_in' => 3600 ) );
		bia_test_http_push(
			200,
			array(
				'found'   => true,
				'profile' => array( 'first_name' => 'สมหญิง', 'last_name' => 'รักธรรม' ),
			)
		);

		$profile = $client->fetch_profile( 'somying@example.org' );
		$this->assertIsArray( $profile );
		$this->assertSame( 'สมหญิง', $profile['first_name'] );

		// Profile request carried the bearer token and email query.
		$last = end( $GLOBALS['__http_log'] );
		$this->assertSame( 'GET', $last['method'] );
		$this->assertStringContainsString( 'email=', $last['url'] );
		$this->assertSame( 'Bearer tok-1', $last['args']['headers']['Authorization'] );
	}

	public function test_fetch_profile_not_found(): void {
		$client = new Platform_Client( $this->configured_settings() );
		bia_test_http_push( 200, array( 'access_token' => 'tok-1', 'expires_in' => 3600 ) );
		bia_test_http_push( 404, array( 'found' => false ) );

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

	public function test_flush_token_clears_cache(): void {
		$client = new Platform_Client( $this->configured_settings() );
		bia_test_http_push( 200, array( 'access_token' => 'tok-1', 'expires_in' => 3600 ) );
		$client->get_access_token();
		$client->flush_token();

		// A fresh token now requires another HTTP round-trip.
		bia_test_http_push( 200, array( 'access_token' => 'tok-2', 'expires_in' => 3600 ) );
		$this->assertSame( 'tok-2', $client->get_access_token() );
	}
}
