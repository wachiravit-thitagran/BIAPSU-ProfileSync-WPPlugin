<?php
/**
 * Consent-based link of an existing Google WordPress account to BIA Platform OIDC.
 *
 * @package BIAPSU\ProfileSync
 */

namespace BIAPSU\ProfileSync;

defined( 'ABSPATH' ) || exit;

/**
 * Uses Authorizenter's own state/nonce/PKCE-protected OIDC login, without API keys.
 * A second identity is attached only to the logged-in, nonce-authorized WP user.
 */
final class Account_Linking {
	const INTENT_SESSION_KEY = 'biapsu_profilesync_link_intent';
	const START_ACTION       = 'biapsu_profilesync_start_link';
	const LINK_META          = 'authorizenter_link_oidc';
	const INTENT_TTL         = 600;

	/**
	 * Plugin settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * WordPress user validated for this exact OAuth callback.
	 *
	 * @var int
	 */
	private $validated_user_id = 0;

	/**
	 * Verified OIDC subject held until Authorizenter login succeeds.
	 *
	 * @var string
	 */
	private $validated_subject = '';

	/**
	 * Construct the account linking service.
	 *
	 * @param Settings $settings Plugin settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/** Hook into documented Authorizenter extension points. */
	public function hooks() {
		add_action( 'admin_post_' . self::START_ACTION, array( $this, 'start' ) );
		add_filter( 'authorizenter_login_return_to', array( $this, 'filter_return_to' ), 10, 3 );
		add_filter( 'authorizenter_pre_resolve_user', array( $this, 'pre_resolve_user' ), 10, 3 );
		add_action( 'authorizenter_login_success', array( $this, 'after_login' ), 5, 3 );
	}

	/**
	 * Check whether the user answered Yes.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return bool
	 */
	public function eligible( $user_id ) {
		$answers = get_user_meta( (int) $user_id, 'authorizenter_answers', true );
		return is_array( $answers ) && isset( $answers['is_bia_volunteer'] ) && 'ใช่' === $answers['is_bia_volunteer'];
	}

	/**
	 * Check whether the user has a linked OIDC subject.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return bool
	 */
	public function is_linked( $user_id ) {
		return '' !== (string) get_user_meta( (int) $user_id, self::LINK_META, true );
	}

	/**
	 * Start OIDC linking via a nonce-protected browser POST, not MCP.
	 * WordPress login cookies are checked again at callback to prevent session
	 * swapping or stale authenticated sessions.
	 */
	public function start() {
		$user_id = get_current_user_id();
		if ( ! is_user_logged_in() || ! $user_id || ! $this->settings->get( 'enabled' ) || ! $this->eligible( $user_id ) || $this->is_linked( $user_id ) ) {
			wp_die( esc_html__( 'Account linking is not available.', 'biapsu-profilesync' ) );
		}
		check_admin_referer( 'biapsu_profilesync_link_' . $user_id );

		$cookie = defined( 'LOGGED_IN_COOKIE' ) && isset( $_COOKIE[ LOGGED_IN_COOKIE ] ) ? (string) wp_unslash( $_COOKIE[ LOGGED_IN_COOKIE ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WordPress authenticates the exact raw cookie.
		if ( '' === $cookie || (int) wp_validate_auth_cookie( $cookie, 'logged_in' ) !== $user_id || ! $this->start_session() ) {
			wp_die( esc_html__( 'Please sign in again to connect your account.', 'biapsu-profilesync' ) );
		}

		$destination = isset( $_POST['return_to'] ) ? esc_url_raw( wp_unslash( $_POST['return_to'] ) ) : '';
		$destination = wp_validate_redirect( esc_url_raw( $destination ), home_url( '/' ) );
		$token       = bin2hex( random_bytes( 32 ) );
		$page        = $this->choice_url();
		$return_to   = add_query_arg(
			array(
				'biapsu_link' => $token,
				'return_to'   => rawurlencode( $destination ),
			),
			$page
		);

		$_SESSION[ self::INTENT_SESSION_KEY ] = array(
			'user_id'       => $user_id,
			'cookie_digest' => hash( 'sha256', $cookie ),
			'return_to'     => $return_to,
			'created'       => time(),
		);

		$authorize_url = add_query_arg(
			array(
				'context'   => 'default',
				'return_to' => rawurlencode( $return_to ),
			),
			rest_url( 'authorizenter/v1/authorize/oidc' )
		);
		wp_safe_redirect( $authorize_url );
		exit;
	}

	/**
	 * Route Google sign-ins through the existing choice page after questions.
	 * For OIDC, verify that the callback belongs to the exact initiated
	 * Authorizenter flow (return_to is restored from its secured OAuth state).
	 *
	 * @param string $return_to Original Authorizenter destination.
	 * @param array  $context Login context.
	 * @param object $provider Authorizenter provider.
	 * @return string
	 */
	public function filter_return_to( $return_to, $context, $provider ) {
		$provider_id = is_object( $provider ) && method_exists( $provider, 'id' ) ? $provider->id() : '';
		if ( 'google' === $provider_id && $this->settings->get( 'enabled' ) ) {
			$destination = wp_validate_redirect( (string) $return_to, home_url( '/' ) );
			return add_query_arg( 'return_to', rawurlencode( $destination ), $this->choice_url() );
		}
		if ( 'oidc' !== $provider_id || ! $this->start_session() ) {
			return $return_to;
		}

		$intent = isset( $_SESSION[ self::INTENT_SESSION_KEY ] ) ? $_SESSION[ self::INTENT_SESSION_KEY ] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Session data written by this plugin and checked below.
		if ( ! is_array( $intent ) || ! isset( $intent['return_to'] ) || ! hash_equals( (string) $intent['return_to'], (string) $return_to ) ) {
			return $return_to;
		}
		if ( ! $this->valid_intent( $intent ) ) {
			unset( $_SESSION[ self::INTENT_SESSION_KEY ] );
			return $return_to;
		}

		$this->validated_user_id = (int) $intent['user_id'];
		return $return_to;
	}

	/**
	 * Resolve to the original Google account only after a matching consented
	 * OIDC flow. Reject subjects claimed by other WordPress accounts.
	 *
	 * @param mixed  $resolved Existing short-circuit value.
	 * @param object $identity Authorizenter-verified OIDC identity.
	 * @param array  $context Login context.
	 * @return mixed
	 */
	public function pre_resolve_user( $resolved, $identity, $context ) {
		if ( null !== $resolved || 0 === $this->validated_user_id ) {
			return $resolved;
		}
		if ( ! is_object( $identity ) || 'oidc' !== (string) $identity->provider || empty( $identity->sub ) ) {
			return new \WP_Error( 'biapsu_link_invalid_identity', __( 'OIDC identity could not be verified for linking.', 'biapsu-profilesync' ) );
		}

		$user_id = $this->validated_user_id;
		$subject = (string) $identity->sub;
		if ( ! $this->eligible( $user_id ) || ! $this->settings->get( 'enabled' ) ) {
			return new \WP_Error( 'biapsu_link_no_consent', __( 'Account linking requires your volunteer answer.', 'biapsu-profilesync' ) );
		}
		$existing = (string) get_user_meta( $user_id, self::LINK_META, true );
		if ( '' !== $existing && ! hash_equals( $existing, $subject ) ) {
			return new \WP_Error( 'biapsu_link_conflict', __( 'This account is already connected to a different platform identity.', 'biapsu-profilesync' ) );
		}
		if ( $this->subject_owned_by_other( $user_id, $subject ) ) {
			return new \WP_Error( 'biapsu_link_taken', __( 'This platform identity is already connected to another user.', 'biapsu-profilesync' ) );
		}
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return new \WP_Error( 'biapsu_link_user_missing', __( 'The original account is unavailable.', 'biapsu-profilesync' ) );
		}
		$this->validated_subject = $subject;
		return $user;
	}

	/**
	 * Persist subject only after Authorizenter has completed the OIDC login.
	 * Never store a profile, token, or email from the second provider.
	 *
	 * @param \WP_User $user     Authenticated WordPress user.
	 * @param string   $provider Authorizenter provider ID.
	 * @param object   $identity Verified identity.
	 * @return void
	 */
	public function after_login( $user, $provider = '', $identity = null ) {
		if ( 0 === $this->validated_user_id || 'oidc' !== $provider || ! ( $user instanceof \WP_User ) || $user->ID !== $this->validated_user_id ) {
			return;
		}
		if ( ! is_object( $identity ) || ! isset( $identity->sub ) || ! hash_equals( $this->validated_subject, (string) $identity->sub ) ) {
			return;
		}
		if ( $this->subject_owned_by_other( $user->ID, $this->validated_subject ) ) {
			return;
		}
		update_user_meta( $user->ID, self::LINK_META, $this->validated_subject );
		do_action( 'authorizenter_user_linked', $user->ID, $identity );
		if ( isset( $_SESSION ) ) {
			unset( $_SESSION[ self::INTENT_SESSION_KEY ] );
		}
		$this->validated_user_id = 0;
		$this->validated_subject = '';
	}

	/**
	 * Check whether a different user owns the same OIDC subject.
	 *
	 * @param int    $user_id The current user ID.
	 * @param string $subject Verified provider subject.
	 * @return bool
	 */
	private function subject_owned_by_other( $user_id, $subject ) {
		$users = get_users(
			array(
				'meta_key'   => self::LINK_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $subject, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'fields'     => 'ids',
				'number'     => 2,
			)
		);
		foreach ( $users as $id ) {
			if ( (int) $id !== (int) $user_id ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Validate source browser session, original login cookie, and expiry.
	 *
	 * @param array $intent Session-local pending link request.
	 * @return bool
	 */
	private function valid_intent( array $intent ) {
		$user_id = (int) ( $intent['user_id'] ?? 0 );
		$created = (int) ( $intent['created'] ?? 0 );
		$cookie  = defined( 'LOGGED_IN_COOKIE' ) && isset( $_COOKIE[ LOGGED_IN_COOKIE ] ) ? (string) wp_unslash( $_COOKIE[ LOGGED_IN_COOKIE ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WordPress authenticates the exact raw cookie.
		return $user_id > 0
			&& $created > 0
			&& time() - $created <= self::INTENT_TTL
			&& '' !== $cookie
			&& isset( $intent['cookie_digest'] )
			&& hash_equals( (string) $intent['cookie_digest'], hash( 'sha256', $cookie ) )
			&& (int) wp_validate_auth_cookie( $cookie, 'logged_in' ) === $user_id;
	}

	/** Share PHP session storage with Authorizenter OIDC. */
	private function start_session() {
		if ( PHP_SESSION_NONE === session_status() && ! headers_sent() ) {
			session_start();
		}
		return PHP_SESSION_ACTIVE === session_status();
	}

	/** Choice page URL, reusing the plugin's existing page. */
	private function choice_url() {
		$page_id = (int) $this->settings->get( 'choice_page_id' );
		$url     = $page_id ? get_permalink( $page_id ) : '';
		return $url ? $url : home_url( '/profile-sync/' );
	}
}
