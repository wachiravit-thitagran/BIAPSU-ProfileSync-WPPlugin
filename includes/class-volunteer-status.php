<?php
/**
 * Auto-detect BIA platform volunteer membership after Google sign-in.
 *
 * The Authorizenter questions engine owns question display, user answers and
 * the original return_to redirect. This plugin only supplies a verified
 * positive answer when the platform API finds the same Google email.
 *
 * @package BIAPSU\ProfileSync
 */

namespace BIAPSU\ProfileSync;

defined( 'ABSPATH' ) || exit;

/**
 * Conditional volunteer answer bridge, never a profile synchronization flow.
 */
class Volunteer_Status {

	const QUESTION_ID = 'is_bia_volunteer';
	const LOOKUP_META = 'biapsu_volunteer_lookup_status'; // matched | not_found | unavailable.
	const SOURCE_META = 'biapsu_volunteer_answer_source'; // platform_api for auto-confirmed matches.

	/**
	 * Settings storage.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Platform membership API client.
	 *
	 * @var Platform_Client
	 */
	private $client;

	/**
	 * Construct the volunteer status bridge.
	 *
	 * @param Settings        $settings Settings service.
	 * @param Platform_Client $client   Platform API client.
	 */
	public function __construct( Settings $settings, Platform_Client $client ) {
		$this->settings = $settings;
		$this->client   = $client;
	}

	/**
	 * Subscribe to the Authorizenter lifecycle; do not register the old
	 * profile-sync consent, post handler or redirect gate.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'authorizenter_login_success', array( $this, 'on_login' ), 20, 3 );
		add_filter( 'authorizenter_questions', array( $this, 'filter_questions' ), 10, 1 );
	}

	/**
	 * A successful Google sign-in happens BEFORE Authorizenter redirects to
	 * pending questions. Existing manual answers are never overwritten.
	 *
	 * @param \WP_User $user     Logged-in WP user.
	 * @param string   $provider Authorizenter provider id.
	 * @param object   $identity Verified identity from the provider.
	 * @return void
	 */
	public function on_login( $user, $provider = '', $identity = null ) {
		// Questions are now self-declared; do not call the platform API.
	}

	/**
	 * Hide the volunteer question after a platform/API outage, and for non-Google
	 * sign-ins. Leave all other Authorizenter questions untouched.
	 *
	 * @param array $questions Authorizenter definitions.
	 * @return array Filtered definitions.
	 */
	public function filter_questions( $questions ) {
		if ( ! is_array( $questions ) || ! is_user_logged_in() ) {
			return $questions;
		}
		$provider = (string) get_user_meta( get_current_user_id(), 'authorizenter_last_provider', true );
		if ( 'google' === $provider && $this->settings->get( 'enabled' ) ) {
			return $questions;
		}
		return array_values(
			array_filter(
				$questions,
				static function ( $question ) {
					return ! is_array( $question ) || ! isset( $question['id'] ) || self::QUESTION_ID !== $question['id'];
				}
			)
		);
	}

}
