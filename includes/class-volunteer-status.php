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
		if ( 'google' !== $provider || ! ( $user instanceof \WP_User ) || ! $this->settings->get( 'enabled' ) ) {
			return;
		}

		$questions = $this->questions_service();
		if ( ! $questions || ! method_exists( $questions, 'get_answers' ) || ! method_exists( $questions, 'save_answers' ) ) {
			return;
		}

		$answers = $questions->get_answers( $user->ID );
		if ( is_array( $answers ) && array_key_exists( self::QUESTION_ID, $answers ) && '' !== (string) $answers[ self::QUESTION_ID ] ) {
			// Respect both Yes and No already recorded by Authorizenter.
			return;
		}

		// The email must come from Google's authenticated, verified identity.
		// Never accept an arbitrary typed email as proof of volunteering.
		$verified = is_object( $identity ) && ! empty( $identity->email_verified );
		$email    = $verified && isset( $identity->email ) ? sanitize_email( $identity->email ) : '';
		if ( '' === $email ) {
			update_user_meta( $user->ID, self::LOOKUP_META, 'unavailable' );
			return;
		}

		$found = $this->client->check_membership( $email );
		if ( is_wp_error( $found ) ) {
			// An API outage or missing API key is NOT a negative membership result.
			// Skip only this volunteer question, allow Tutor LMS entry and retry
			// on the next successful Google sign-in.
			update_user_meta( $user->ID, self::LOOKUP_META, 'unavailable' );
			return;
		}

		if ( ! $found ) {
			update_user_meta( $user->ID, self::LOOKUP_META, 'not_found' );
			// Authorizenter renders its configured question, then honors return_to.
			return;
		}

		// Save via Authorizenter's own validated questions service. This mirrors
		// the per-question meta key and ensures the question gate sees it as done.
		$saved = $questions->save_answers( $user->ID, array( self::QUESTION_ID => 'ใช่' ) );
		if ( is_wp_error( $saved ) ) {
			update_user_meta( $user->ID, self::LOOKUP_META, 'unavailable' );
			return;
		}

		update_user_meta( $user->ID, self::LOOKUP_META, 'matched' );
		update_user_meta( $user->ID, self::SOURCE_META, 'platform_api' );
	}

	/**
	 * Hide the volunteer question after a platform/API outage, and for non-Google
	 * sign-ins. Leave all other Authorizenter questions untouched.
	 *
	 * @param array $questions Authorizenter definitions.
	 * @return array Filtered definitions.
	 */
	public function filter_questions( $questions ) {
		if ( ! is_array( $questions ) || ! $this->settings->get( 'enabled' ) || ! is_user_logged_in() ) {
			return $questions;
		}

		$user_id  = get_current_user_id();
		$provider = (string) get_user_meta( $user_id, 'authorizenter_last_provider', true );
		$status   = (string) get_user_meta( $user_id, self::LOOKUP_META, true );

		if ( 'google' === $provider && 'unavailable' !== $status ) {
			return $questions;
		}

		// Provider mismatch or API unavailable: the user must not be stranded
		// at a required question unrelated to their usable login.
		return array_values(
			array_filter(
				$questions,
				static function ( $question ) {
					return ! is_array( $question ) || ! isset( $question['id'] ) || self::QUESTION_ID !== $question['id'];
				}
			)
		);
	}

	/**
	 * Authorizenter's public service accessor is an optional dependency.
	 *
	 * @return object|null Questions service.
	 */
	private function questions_service() {
		$accessor = 'Authorizenter\\Core\\authorizenter_core';
		if ( ! function_exists( $accessor ) ) {
			return null;
		}
		$core = call_user_func( $accessor );
		if ( ! is_object( $core ) ) {
			return null;
		}
		$members = get_object_vars( $core );
		return isset( $members['questions'] ) && is_object( $members['questions'] ) ? $members['questions'] : null;
	}
}
