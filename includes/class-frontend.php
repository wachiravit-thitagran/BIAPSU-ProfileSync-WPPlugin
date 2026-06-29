<?php
/**
 * Front-end: choice page rendering, shortcode and auto-created page.
 *
 * @package BIAPSU\ProfileSync
 */

namespace BIAPSU\ProfileSync;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the "sync from platform?" prompt via the [biapsu_profilesync] shortcode.
 */
class Frontend {

	const SHORTCODE = 'biapsu_profilesync';
	const SLUG      = 'profile-sync';

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
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
	}

	/**
	 * Enqueue assets only on the choice page.
	 *
	 * @return void
	 */
	public function maybe_enqueue() {
		$page_id = (int) $this->settings->get( 'choice_page_id' );
		if ( $page_id <= 0 || ! is_page( $page_id ) ) {
			return;
		}

		wp_enqueue_style(
			'biapsu-profilesync',
			BIAPSU_PROFILESYNC_URL . 'assets/profilesync.css',
			array(),
			BIAPSU_PROFILESYNC_VERSION
		);
		wp_enqueue_script(
			'biapsu-profilesync',
			BIAPSU_PROFILESYNC_URL . 'assets/profilesync.js',
			array(),
			BIAPSU_PROFILESYNC_VERSION,
			true
		);
	}

	/**
	 * Shortcode renderer.
	 *
	 * @return string
	 */
	public function render() {
		if ( ! is_user_logged_in() ) {
			return '<div class="biapsu-card"><p>' . esc_html__( 'Please log in to continue.', 'biapsu-profilesync' ) . '</p></div>';
		}

		$user_id = get_current_user_id();
		$state   = get_user_meta( $user_id, Sync_Controller::STATE_META, true );

		// Not armed: send the user onward (or show a neutral message).
		if ( 'ready' !== $state ) {
			return '<div class="biapsu-card"><p>' . esc_html__( 'Your account is ready. You may continue.', 'biapsu-profilesync' ) . '</p>'
				. '<p><a class="biapsu-btn biapsu-btn--ghost" href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Continue', 'biapsu-profilesync' ) . '</a></p></div>';
		}

		$error    = '';
		$is_error = isset( $_GET['biapsu_sync'] ) && 'error' === sanitize_key( wp_unslash( $_GET['biapsu_sync'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $is_error ) {
			$error = (string) get_user_meta( $user_id, Sync_Controller::ERROR_META, true );
		}

		$action_url = admin_url( 'admin-post.php' );
		$nonce      = wp_create_nonce( Sync_Controller::ACTION . '_' . $user_id );

		ob_start();
		include BIAPSU_PROFILESYNC_DIR . 'templates/sync-choice.php';
		return (string) ob_get_clean();
	}

	/**
	 * Ensure the choice page exists; store its id in settings.
	 *
	 * @return int Page id (0 on failure).
	 */
	public function ensure_page() {
		$all     = $this->settings->all();
		$page_id = (int) ( $all['choice_page_id'] ?? 0 );

		if ( $page_id > 0 && get_post( $page_id ) ) {
			return $page_id;
		}

		// Reuse an existing page that already contains the shortcode.
		$existing = get_page_by_path( self::SLUG );
		if ( $existing ) {
			$page_id = $existing->ID;
		} else {
			$page_id = wp_insert_post(
				array(
					'post_title'   => __( 'Sync your profile', 'biapsu-profilesync' ),
					'post_name'    => self::SLUG,
					'post_status'  => 'publish',
					'post_type'    => 'page',
					'post_content' => '[' . self::SHORTCODE . ']',
				),
				true
			);
		}

		if ( ! is_wp_error( $page_id ) && $page_id > 0 ) {
			$all['choice_page_id'] = (int) $page_id;
			$this->settings->save( $all );
			return (int) $page_id;
		}

		if ( is_wp_error( $page_id ) ) {
			error_log( 'BIAPSU Profile Sync Error creating page: ' . $page_id->get_error_message() );
		}

		return 0;
	}
}
