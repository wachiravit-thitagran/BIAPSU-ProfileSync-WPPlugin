<?php
/**
 * Tests for Settings: defaults, endpoint derivation, configuration, encryption.
 *
 * @package BIAPSU\ProfileSync\Tests
 */

namespace BIAPSU\ProfileSync\Tests;

use BIAPSU\ProfileSync\Settings;
use PHPUnit\Framework\TestCase;

class SettingsTest extends TestCase {

	protected function setUp(): void {
		bia_test_reset();
	}

	public function test_defaults_enable_prompt_and_field_groups(): void {
		$settings = new Settings();
		$this->assertTrue( (bool) $settings->get( 'enabled' ) );
		$fields = $settings->get( 'fields' );
		$this->assertTrue( $fields['name'] );
		$this->assertTrue( $fields['contact'] );
		$this->assertTrue( $fields['affiliation'] );
		$this->assertTrue( $fields['user_type'] );
	}

	public function test_endpoints_derive_from_base_url(): void {
		$settings = new Settings();
		$all      = $settings->all();
		$all['platform']['base_url'] = 'https://platform.example.org';
		$settings->save( $all );

		$reloaded = new Settings();
		$this->assertSame( 'https://platform.example.org/o/token/', $reloaded->token_endpoint() );
		$this->assertSame( 'https://platform.example.org/api/profile/', $reloaded->profile_endpoint() );
	}

	public function test_explicit_endpoints_win_over_base_url(): void {
		$settings = new Settings();
		$all      = $settings->all();
		$all['platform']['base_url']         = 'https://platform.example.org';
		$all['platform']['token_endpoint']   = 'https://id.example.org/token';
		$all['platform']['profile_endpoint'] = 'https://id.example.org/me';
		$settings->save( $all );

		$reloaded = new Settings();
		$this->assertSame( 'https://id.example.org/token', $reloaded->token_endpoint() );
		$this->assertSame( 'https://id.example.org/me', $reloaded->profile_endpoint() );
	}

	public function test_is_configured_requires_credentials_and_endpoints(): void {
		$settings = new Settings();
		$this->assertFalse( $settings->is_configured() );

		$all = $settings->all();
		$all['platform']['base_url']      = 'https://platform.example.org';
		$all['platform']['client_id']     = 'abc';
		$all['platform']['client_secret'] = 'shhh';
		$settings->save( $all );

		$this->assertTrue( ( new Settings() )->is_configured() );
	}

	public function test_secret_is_stored_encrypted_and_roundtrips(): void {
		$settings = new Settings();
		$all      = $settings->all();
		$all['platform']['client_secret'] = 'super-secret-value';
		$settings->save( $all );

		// Raw stored option must not contain the plaintext.
		$raw = get_option( Settings::OPTION );
		$this->assertStringNotContainsString( 'super-secret-value', (string) $raw['platform']['client_secret'] );
		$this->assertStringStartsWith( 'enc:', (string) $raw['platform']['client_secret'] );

		// Reading back through Settings decrypts it.
		$this->assertSame( 'super-secret-value', ( new Settings() )->get( 'platform' )['client_secret'] );
	}

	public function test_encrypt_decrypt_roundtrip(): void {
		$settings = new Settings();
		$stored   = $settings->encrypt( 'token-123' );
		$this->assertNotSame( 'token-123', $stored );
		$this->assertSame( 'token-123', $settings->decrypt( $stored ) );
		$this->assertSame( '', $settings->encrypt( '' ) );
	}
}
