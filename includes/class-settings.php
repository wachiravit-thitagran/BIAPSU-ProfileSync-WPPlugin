<?php
/**
 * Settings store with at-rest encryption for the OAuth client secret.
 *
 * @package BIAPSU\ProfileSync
 */

namespace BIAPSU\ProfileSync;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the plugin option, applying defaults and secret encryption.
 */
class Settings {

	const OPTION = 'biapsu_profilesync_settings';

	/**
	 * Default settings shape.
	 *
	 * @return array<string,mixed>
	 */
	public function defaults() {
		return array(
			// Master switch for the post-login sync prompt.
			'enabled'          => true,

			// Server-to-server OAuth2 (client_credentials) configuration.
			'platform'         => array(
				'base_url'         => '',
				'token_endpoint'   => '', // Absolute URL, or leave blank to derive base_url . /o/token/.
				'profile_endpoint' => '', // Absolute URL, or leave blank to derive base_url . /api/profile/.
				'client_id'        => '',
				'client_secret'    => '', // Stored encrypted.
				'scope'            => 'profile:read',
				'timeout'          => 10,
				'verify_ssl'       => true,
			),

			// Which fields to copy from the platform onto the WP user.
			'fields'           => array(
				'name'      => true, // first_name, last_name (+ display_name).
				'contact'   => true, // phone_number (user meta), email (only if WP email is empty).
				'affiliation' => true, // affiliation, department, position, location (user meta).
				'user_type' => true, // user_type, user_type_description, join_reason (user meta).
			),

			// Slug of the page that hosts the [biapsu_profilesync] shortcode.
			'choice_page_id'   => 0,
		);
	}

	/**
	 * Get all settings merged with defaults; secret decrypted for use.
	 *
	 * @return array<string,mixed>
	 */
	public function all() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$merged = $this->merge_defaults( $stored );

		// Decrypt secret for in-memory use.
		$merged['platform']['client_secret'] = $this->decrypt( $merged['platform']['client_secret'] );

		return $merged;
	}

	/**
	 * Get a top-level section.
	 *
	 * @param string $section Section key.
	 * @return mixed
	 */
	public function get( $section ) {
		$all = $this->all();
		return $all[ $section ] ?? null;
	}

	/**
	 * Recursively merge stored settings over defaults (one level of nesting).
	 *
	 * @param array<string,mixed> $stored Stored option.
	 * @return array<string,mixed>
	 */
	private function merge_defaults( array $stored ) {
		$defaults = $this->defaults();
		$out      = $defaults;

		foreach ( $defaults as $key => $value ) {
			if ( is_array( $value ) && isset( $stored[ $key ] ) && is_array( $stored[ $key ] ) ) {
				$out[ $key ] = array_merge( $value, $stored[ $key ] );
			} elseif ( array_key_exists( $key, $stored ) ) {
				$out[ $key ] = $stored[ $key ];
			}
		}

		return $out;
	}

	/**
	 * Persist settings. Encrypts the client secret before saving.
	 *
	 * @param array<string,mixed> $settings Full settings array (secret in plaintext).
	 * @return bool
	 */
	public function save( array $settings ) {
		if ( isset( $settings['platform']['client_secret'] ) ) {
			$settings['platform']['client_secret'] = $this->encrypt( (string) $settings['platform']['client_secret'] );
		}
		return update_option( self::OPTION, $settings );
	}

	/**
	 * Seed defaults on first run.
	 *
	 * @return void
	 */
	public function maybe_set_defaults() {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $this->defaults() );
		}
	}

	/**
	 * Resolve the token endpoint (explicit or derived from base URL).
	 *
	 * @return string
	 */
	public function token_endpoint() {
		$p = $this->get( 'platform' );
		if ( ! empty( $p['token_endpoint'] ) ) {
			return $p['token_endpoint'];
		}
		if ( ! empty( $p['base_url'] ) ) {
			return trailingslashit( $p['base_url'] ) . 'o/token/';
		}
		return '';
	}

	/**
	 * Resolve the profile endpoint (explicit or derived from base URL).
	 *
	 * @return string
	 */
	public function profile_endpoint() {
		$p = $this->get( 'platform' );
		if ( ! empty( $p['profile_endpoint'] ) ) {
			return $p['profile_endpoint'];
		}
		if ( ! empty( $p['base_url'] ) ) {
			return trailingslashit( $p['base_url'] ) . 'api/profile/';
		}
		return '';
	}

	/**
	 * True when the integration has the minimum config to run.
	 *
	 * @return bool
	 */
	public function is_configured() {
		$p = $this->get( 'platform' );
		return ! empty( $p['client_id'] )
			&& ! empty( $p['client_secret'] )
			&& '' !== $this->token_endpoint()
			&& '' !== $this->profile_endpoint();
	}

	/**
	 * Encrypt a secret for storage. Uses AES-256-CBC keyed off WP salts.
	 *
	 * @param string $plaintext Secret value.
	 * @return string Base64 "iv:ciphertext", or '' when empty.
	 */
	public function encrypt( $plaintext ) {
		$plaintext = (string) $plaintext;
		if ( '' === $plaintext ) {
			return '';
		}
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			// Last resort: avoid storing plaintext silently; base64 marker.
			return 'b64:' . base64_encode( $plaintext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding a secret for storage, not obfuscating code.
		}

		$key    = $this->derive_key();
		$iv     = openssl_random_pseudo_bytes( 16 );
		$cipher = openssl_encrypt( $plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

		if ( false === $cipher ) {
			return '';
		}

		return 'enc:' . base64_encode( $iv . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding ciphertext for storage, not obfuscating code.
	}

	/**
	 * Decrypt a stored secret.
	 *
	 * @param string $stored Stored value.
	 * @return string Plaintext.
	 */
	public function decrypt( $stored ) {
		$stored = (string) $stored;
		if ( '' === $stored ) {
			return '';
		}
		if ( 0 === strpos( $stored, 'b64:' ) ) {
			return (string) base64_decode( substr( $stored, 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding a stored secret, not obfuscating code.
		}
		if ( 0 !== strpos( $stored, 'enc:' ) ) {
			// Legacy/plaintext value stored before encryption was available.
			return $stored;
		}
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}

		$raw = base64_decode( substr( $stored, 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding stored ciphertext, not obfuscating code.
		if ( false === $raw || strlen( $raw ) <= 16 ) {
			return '';
		}

		$iv     = substr( $raw, 0, 16 );
		$cipher = substr( $raw, 16 );
		$key    = $this->derive_key();

		$plain = openssl_decrypt( $cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

		return false === $plain ? '' : $plain;
	}

	/**
	 * Derive a 32-byte key from WordPress salts.
	 *
	 * @return string
	 */
	private function derive_key() {
		$material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : 'biapsu' )
			. ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : 'profilesync' );
		return hash( 'sha256', $material, true );
	}
}
