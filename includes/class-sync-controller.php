<?php
/**
 * Login gate + sync decision handler.
 *
 * Flow (event-driven, chained after Authorizenter's question form)
 * ----------------------------------------------------------------
 * 1. authorizenter_user_provisioned  -> mark the new user "await" + store email.
 * 2. authorizenter_login_success     -> evaluate ONCE: if the user has no pending
 *                                       required questions, arm immediately
 *                                       ("ready"); otherwise stay "await".
 * 3. authorizenter_questions_completed -> the user just finished Authorizenter's
 *                                       question form, so arm ("ready"). This is
 *                                       the proper completion event — we never
 *                                       poll has_pending_required on every load.
 * 4. template_redirect gate          -> when state is "ready", divert to the
 *                                       choice page (reads our own flag only).
 * 5. decision (admin-post)           -> sync : fetch + apply the platform profile.
 *                                       skip : keep the Authorizenter data as-is.
 *                                       Then continue to the original destination.
 *
 * Because we only arm after the question form is complete, that form always runs
 * first and is never intercepted or broken. Only brand-new users reach the
 * prompt, so returning users are never interrupted.
 *
 * @package BIAPSU\ProfileSync
 */

namespace BIAPSU\ProfileSync;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates the post-login sync prompt and decision.
 */
class Sync_Controller {

	const STATE_META  = 'biapsu_sync_state';  // await | ready | synced | skipped.
	const EMAIL_META  = 'biapsu_sync_email';  // email captured at provisioning.
	const RETURN_META = 'biapsu_sync_return'; // original destination URL.
	const ERROR_META  = 'biapsu_sync_error';  // last sync error message.
	const ACTION      = 'biapsu_profilesync_decision';

	/** template_redirect priority. Must be AFTER Authorizenter UI's gate (10). */
	const GATE_PRIORITY = 20;

	/**
	 * Settings store.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Platform client.
	 *
	 * @var Platform_Client
	 */
	private $client;

	/**
	 * Profile mapper.
	 *
	 * @var Profile_Mapper
	 */
	private $mapper;

	/**
	 * Constructor.
	 *
	 * @param Settings        $settings Settings store.
	 * @param Platform_Client $client   Platform client.
	 * @param Profile_Mapper  $mapper   Profile mapper.
	 */
	public function __construct( Settings $settings, Platform_Client $client, Profile_Mapper $mapper ) {
		$this->settings = $settings;
		$this->client   = $client;
		$this->mapper   = $mapper;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'authorizenter_user_provisioned', array( $this, 'flag_new_user' ), 10, 2 );
		// Evaluate readiness once at login (after last_provider is set, priority 20).
		add_action( 'authorizenter_login_success', array( $this, 'arm_after_login' ), 20, 4 );
		// Arm as soon as Authorizenter reports the question form is complete.
		add_action( 'authorizenter_questions_completed', array( $this, 'arm_after_questions' ), 10, 1 );
		// Perform the actual redirect on the next front-end load (after UI gate @10).
		add_action( 'template_redirect', array( $this, 'enforce_sync_gate' ), self::GATE_PRIORITY );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_decision' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'handle_decision' ) );
	}

	/**
	 * Tag a freshly provisioned (brand-new) user as awaiting the sync prompt.
	 *
	 * @param \WP_User $user     The new user.
	 * @param object   $identity Authorizenter identity (has ->email).
	 * @return void
	 */
	public function flag_new_user( $user, $identity ) {
		if ( ! ( $user instanceof \WP_User ) ) {
			return;
		}
		$enabled = (bool) $this->settings->get( 'enabled' );
		/** This filter allows disabling the prompt per-user/runtime. */
		if ( ! apply_filters( 'biapsu_profilesync_should_prompt', $enabled, $user, $identity ) ) {
			return;
		}

		$email = '';
		if ( is_object( $identity ) && isset( $identity->email ) ) {
			$email = sanitize_email( $identity->email );
		}
		if ( '' === $email ) {
			$email = $user->user_email;
		}

		update_user_meta( $user->ID, self::STATE_META, 'await' );
		update_user_meta( $user->ID, self::EMAIL_META, $email );
	}

	/**
	 * Evaluate once at login: arm immediately when there are no required
	 * questions to complete first; otherwise wait for the completion event.
	 *
	 * @param \WP_User $user     Logged-in user.
	 * @param string   $provider Provider id.
	 * @param object   $identity Identity.
	 * @param array    $context  Login context.
	 * @return void
	 */
	public function arm_after_login( $user, $provider = '', $identity = null, $context = array() ) {
		if ( ! ( $user instanceof \WP_User ) ) {
			return;
		}
		if ( 'await' !== get_user_meta( $user->ID, self::STATE_META, true ) ) {
			return;
		}

		// If a question form is still pending, hold; arm_after_questions() will
		// promote us once it is submitted. This keeps Authorizenter's form first.
		if ( $this->questions_pending( $user->ID, (string) $provider ) ) {
			return;
		}

		update_user_meta( $user->ID, self::STATE_META, 'ready' );
	}

	/**
	 * Arm the prompt the moment Authorizenter's required questions are completed.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public function arm_after_questions( $user_id ) {
		$user_id = (int) $user_id;
		if ( 'await' === get_user_meta( $user_id, self::STATE_META, true ) ) {
			update_user_meta( $user_id, self::STATE_META, 'ready' );
		}
	}

	/**
	 * Front-end gate: divert armed ("ready") users to the choice page.
	 *
	 * Reads only our own state flag — no per-load question polling.
	 *
	 * @return void
	 */
	public function enforce_sync_gate() {
		if ( ! is_user_logged_in() || is_admin() || wp_doing_ajax() ) {
			return;
		}
		if ( ! (bool) $this->settings->get( 'enabled' ) ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( 'ready' !== get_user_meta( $user_id, self::STATE_META, true ) ) {
			return;
		}

		$choice_page_id = (int) $this->settings->get( 'choice_page_id' );
		if ( $choice_page_id <= 0 ) {
			return; // No page configured — do not trap the user.
		}

		// Never loop on the choice page itself.
		if ( is_page( $choice_page_id ) ) {
			return;
		}

		$choice_url = get_permalink( $choice_page_id );
		if ( ! $choice_url ) {
			return;
		}

		// Remember where the user was heading, the first time we divert them.
		if ( '' === (string) get_user_meta( $user_id, self::RETURN_META, true ) ) {
			update_user_meta( $user_id, self::RETURN_META, esc_url_raw( $this->current_url() ) );
		}

		wp_safe_redirect( $choice_url );
		exit;
	}

	/**
	 * Whether Authorizenter still has pending required questions for the user.
	 *
	 * Degrades to false when the question feature is unavailable.
	 *
	 * @param int    $user_id  User id.
	 * @param string $provider Provider id.
	 * @return bool
	 */
	private function questions_pending( $user_id, $provider = '' ) {
		// Resolve Authorizenter's accessor dynamically; it is an optional
		// cross-plugin dependency that is not present at analysis time.
		$accessor = 'Authorizenter\\Core\\authorizenter_core';
		if ( ! function_exists( $accessor ) ) {
			return false;
		}

		$core = call_user_func( $accessor );
		if ( ! is_object( $core ) ) {
			return false;
		}

		$vars      = get_object_vars( $core );
		$questions = isset( $vars['questions'] ) ? $vars['questions'] : null;
		if ( ! is_object( $questions ) || ! method_exists( $questions, 'has_pending_required' ) ) {
			return false;
		}

		if ( '' === $provider ) {
			$provider = (string) get_user_meta( $user_id, 'authorizenter_last_provider', true );
		}

		$pending = (bool) call_user_func( array( $questions, 'has_pending_required' ), $user_id, $provider );

		/**
		 * Allow overriding the defer-to-questions decision.
		 *
		 * @param bool $defer   Whether to wait for Authorizenter questions.
		 * @param int  $user_id User id.
		 */
		return (bool) apply_filters( 'biapsu_profilesync_defer_to_questions', $pending, $user_id );
	}

	/**
	 * Handle the user's sync/skip decision (admin-post.php).
	 *
	 * @return void
	 */
	public function handle_decision() {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url() );
			exit;
		}

		$user_id = get_current_user_id();

		check_admin_referer( self::ACTION . '_' . $user_id );

		$decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
		$user     = get_user_by( 'id', $user_id );

		// Only act while the user is genuinely awaiting a decision.
		if ( ! $user || 'ready' !== get_user_meta( $user_id, self::STATE_META, true ) ) {
			wp_safe_redirect( $this->finish_url( $user_id ) );
			exit;
		}

		delete_user_meta( $user_id, self::ERROR_META );

		if ( 'sync' === $decision ) {
			$email   = get_user_meta( $user_id, self::EMAIL_META, true );
			$profile = $this->client->fetch_profile( $email );

			if ( is_wp_error( $profile ) ) {
				// Stay pending so the user can retry or skip.
				update_user_meta( $user_id, self::ERROR_META, $profile->get_error_message() );
				wp_safe_redirect( add_query_arg( 'biapsu_sync', 'error', get_permalink( (int) $this->settings->get( 'choice_page_id' ) ) ) );
				exit;
			}

			$this->mapper->apply( $user, $profile );
			update_user_meta( $user_id, self::STATE_META, 'synced' );
			do_action( 'biapsu_profilesync_decided', $user, 'sync' );
		} else {
			// Skip: keep the original Authorizenter flow/data.
			update_user_meta( $user_id, self::STATE_META, 'skipped' );
			do_action( 'biapsu_profilesync_decided', $user, 'skip' );
		}

		wp_safe_redirect( $this->finish_url( $user_id ) );
		exit;
	}

	/**
	 * Resolve the URL to continue to after the decision.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	private function finish_url( $user_id ) {
		$return = get_user_meta( $user_id, self::RETURN_META, true );
		delete_user_meta( $user_id, self::RETURN_META );

		if ( ! $return ) {
			$return = home_url( '/' );
		}

		/**
		 * Filter the final destination after the sync decision.
		 *
		 * @param string $return  Destination URL.
		 * @param int    $user_id User id.
		 */
		return apply_filters( 'biapsu_profilesync_finish_url', $return, $user_id );
	}

	/**
	 * Best-effort current front-end URL (used to remember the destination).
	 *
	 * @return string
	 */
	private function current_url() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? wp_unslash( $_SERVER['HTTP_HOST'] ) : '';
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';

		if ( '' === $host ) {
			return home_url( '/' );
		}

		$scheme = is_ssl() ? 'https://' : 'http://';
		return esc_url_raw( $scheme . $host . $uri );
	}

	/**
	 * URL of the configured choice page.
	 *
	 * @return string
	 */
	public function choice_page_url() {
		$page_id = (int) $this->settings->get( 'choice_page_id' );
		if ( $page_id > 0 ) {
			$url = get_permalink( $page_id );
			if ( $url ) {
				return $url;
			}
		}
		return '';
	}
}
