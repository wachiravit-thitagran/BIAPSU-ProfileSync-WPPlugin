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
 * Profile endpoint (GET):
 *   <profile_endpoint>?email=<urlencoded email>
 *   Authorization: Api-Key <api_key>
 *   -> 200 {
 *            "success": true,
 *            "profile": {
 *              "first_name": "...", "last_name": "...", "email": "...",
 *              "phone_number": "...", "affiliation": "...", "department": "...",
 *              "position": "...", "location": "...", "user_type": "...",
 *              "user_type_description": "...", "join_reason": "..."
 *            }
 *          }
 *   -> 400 { "success": false, "message": "Volunteer not found" }
 *
 * @package BIAPSU\ProfileSync
 */

namespace BIAPSU\ProfileSync;

defined( 'ABSPATH' ) || exit;

/**
 * Thin HTTP client wrapping the platform OAuth + profile API.
 */
class Platform_Client {

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

		$platform = $this->settings->get( 'platform' );
		$api_key  = $platform['api_key'] ?? '';

		if ( '' === $api_key ) {
			return new \WP_Error( 'biapsu_not_configured', __( 'API Key is not configured.', 'biapsu-profilesync' ) );
		}

		$url = add_query_arg( 'email', rawurlencode( $email ), $this->settings->profile_endpoint() );

		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => (int) ( $platform['timeout'] ?? 10 ),
				'sslverify' => ! empty( $platform['verify_ssl'] ),
				'headers'   => array(
					'Authorization' => 'Api-Key ' . $api_key,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		/**
		 * Decoded response body.
		 *
		 * @var mixed $body
		 */
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 400 === $code ) {
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
		if ( isset( $body['success'] ) && empty( $body['success'] ) ) {
			return new \WP_Error( 'biapsu_not_found', __( 'No matching profile was found on the platform.', 'biapsu-profilesync' ) );
		}

		if ( 200 !== $code ) {
			return new \WP_Error(
				'biapsu_profile_http',
				/* translators: %d: HTTP status code. */
				sprintf( __( 'Profile request failed (HTTP %d).', 'biapsu-profilesync' ), $code )
			);
		}

		$profile = ( isset( $body['data'] ) && is_array( $body['data'] ) ) ? $body['data'] : $body;

		/**
		 * Filter the raw profile array returned from the platform.
		 *
		 * @param array  $profile Raw profile data.
		 * @param string $email   Looked-up email.
		 */
		return apply_filters( 'biapsu_profilesync_platform_profile', $profile, $email );
	}
}
