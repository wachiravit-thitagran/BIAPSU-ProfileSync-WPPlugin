<?php
/**
 * Server-to-server OAuth2 client for the Buddhadhamma (พุทธธรรม) platform.
 *
 * Uses the OAuth2 `client_credentials` grant to obtain an application access
 * token, then calls the platform's profile endpoint to look a member up by
 * email. The access token is cached in a transient until shortly before it
 * expires.
 *
 * Expected platform contract
 * --------------------------
 * Token endpoint (POST, application/x-www-form-urlencoded):
 *   grant_type=client_credentials&scope=<scope>
 *   Authorization: Basic base64(client_id:client_secret)
 *   -> 200 { "access_token": "...", "token_type": "Bearer", "expires_in": 3600 }
 *
 * Profile endpoint (GET):
 *   <profile_endpoint>?email=<urlencoded email>
 *   Authorization: Bearer <access_token>
 *   -> 200 {
 *            "found": true,
 *            "profile": {
 *              "first_name": "...", "last_name": "...", "email": "...",
 *              "phone_number": "...", "affiliation": "...", "department": "...",
 *              "position": "...", "location": "...", "user_type": "...",
 *              "user_type_description": "...", "join_reason": "..."
 *            }
 *          }
 *   -> 404 { "found": false }
 *
 * @package BIAPSU\ProfileSync
 */

namespace BIAPSU\ProfileSync;

defined( 'ABSPATH' ) || exit;

/**
 * Thin HTTP client wrapping the platform OAuth + profile API.
 */
class Platform_Client {

	const TOKEN_TRANSIENT = 'biapsu_profilesync_token';

	/**
	 * Settings store.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Fetch a member profile from the platform by email.
	 *
	 * @param string $email Member email.
	 * @return array<string,mixed>|\WP_Error Profile array on success, WP_Error on failure or not found.
	 */
	public function fetch_profile( $email ) {
		$email = sanitize_email( $email );
		if ( '' === $email ) {
			return new \WP_Error( 'biapsu_no_email', __( 'No email available to look up.', 'biapsu-profilesync' ) );
		}

		if ( ! $this->settings->is_configured() ) {
			return new \WP_Error( 'biapsu_not_configured', __( 'Platform connection is not configured.', 'biapsu-profilesync' ) );
		}

		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$platform = $this->settings->get( 'platform' );
		$url      = add_query_arg( 'email', rawurlencode( $email ), $this->settings->profile_endpoint() );

		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => (int) ( $platform['timeout'] ?? 10 ),
				'sslverify' => ! empty( $platform['verify_ssl'] ),
				'headers'   => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		/** @var mixed $body */
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 404 === $code ) {
			return new \WP_Error( 'biapsu_not_found', __( 'No matching profile was found on the platform.', 'biapsu-profilesync' ) );
		}

		if ( ! is_array( $body ) ) {
			return new \WP_Error(
				'biapsu_profile_http',
				/* translators: %d: HTTP status code. */
				sprintf( __( 'Profile request failed (HTTP %d).', 'biapsu-profilesync' ), $code )
			);
		}

		// A well-formed "not found" response.
		if ( isset( $body['found'] ) && empty( $body['found'] ) ) {
			return new \WP_Error( 'biapsu_not_found', __( 'No matching profile was found on the platform.', 'biapsu-profilesync' ) );
		}

		if ( 200 !== $code ) {
			return new \WP_Error(
				'biapsu_profile_http',
				/* translators: %d: HTTP status code. */
				sprintf( __( 'Profile request failed (HTTP %d).', 'biapsu-profilesync' ), $code )
			);
		}

		$profile = ( isset( $body['profile'] ) && is_array( $body['profile'] ) ) ? $body['profile'] : $body;

		/**
		 * Filter the raw profile array returned from the platform.
		 *
		 * @param array  $profile Raw profile data.
		 * @param string $email   Looked-up email.
		 */
		return apply_filters( 'biapsu_profilesync_platform_profile', $profile, $email );
	}

	/**
	 * Obtain (and cache) an application access token via client_credentials.
	 *
	 * @param bool $force Skip the cache and request a fresh token.
	 * @return string|\WP_Error Access token, or WP_Error.
	 */
	public function get_access_token( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::TOKEN_TRANSIENT );
			if ( is_string( $cached ) && '' !== $cached ) {
				return $cached;
			}
		}

		$platform = $this->settings->get( 'platform' );
		$endpoint = $this->settings->token_endpoint();

		if ( '' === $endpoint || empty( $platform['client_id'] ) || empty( $platform['client_secret'] ) ) {
			return new \WP_Error( 'biapsu_not_configured', __( 'Platform connection is not configured.', 'biapsu-profilesync' ) );
		}

		$body = array( 'grant_type' => 'client_credentials' );
		if ( ! empty( $platform['scope'] ) ) {
			$body['scope'] = $platform['scope'];
		}

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout'   => (int) ( $platform['timeout'] ?? 10 ),
				'sslverify' => ! empty( $platform['verify_ssl'] ),
				'headers'   => array(
					'Authorization' => 'Basic ' . base64_encode( $platform['client_id'] . ':' . $platform['client_secret'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth header per OAuth2 spec, not obfuscating code.
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'      => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		$data    = is_array( $decoded ) ? $decoded : array();

		if ( 200 !== $code || empty( $data['access_token'] ) ) {
			$detail = ! empty( $data['error_description'] )
				? (string) $data['error_description']
				: ( ! empty( $data['error'] ) ? (string) $data['error'] : '' );

			return new \WP_Error(
				'biapsu_token_failed',
				sprintf(
					/* translators: 1: HTTP status code, 2: error detail. */
					__( 'Could not obtain platform token (HTTP %1$d). %2$s', 'biapsu-profilesync' ),
					$code,
					$detail
				)
			);
		}

		$token   = (string) $data['access_token'];
		$expires = isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 3600;
		// Refresh a minute early to avoid edge expiry.
		$ttl = max( 60, $expires - 60 );

		set_transient( self::TOKEN_TRANSIENT, $token, $ttl );

		return $token;
	}

	/**
	 * Clear the cached token (used after config changes / connection tests).
	 *
	 * @return void
	 */
	public function flush_token() {
		delete_transient( self::TOKEN_TRANSIENT );
	}
}
