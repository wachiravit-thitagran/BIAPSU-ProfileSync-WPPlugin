<?php
/**
 * Front-end: choice page rendering, shortcode and auto-created page.
 *
 * @package BIAPSU\ProfileSync
 */

namespace BIAPSU\ProfileSync;

defined( 'ABSPATH' ) || exit;

/**
 * Legacy shortcode retained as a safe Continue link (no sync consent form).
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
		add_action( 'template_redirect', array( $this, 'maybe_continue' ), 30 );
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
		$link    = new Account_Linking( $this->settings );
		$destination = isset( $_GET['return_to'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_GET['return_to'] ) ), home_url( '/' ) ) : home_url( '/' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Return destination only.
		$continue = '<p><a class="biapsu-btn biapsu-btn--ghost" href="' . esc_url( $destination ) . '">' . esc_html__( 'Continue', 'biapsu-profilesync' ) . '</a></p>';

		if ( $link->is_linked( $user_id ) ) {
			return '<div class="biapsu-card"><p>' . esc_html__( 'บัญชีแพลตฟอร์มพุทธธรรมเชื่อมต่อแล้ว', 'biapsu-profilesync' ) . '</p>' . $continue . '</div>';
		}
		if ( ! $link->eligible( $user_id ) || ! $this->settings->get( 'enabled' ) ) {
			return '<div class="biapsu-card">' . $continue . '</div>';
		}

		$action_url = admin_url( 'admin-post.php' );
		$nonce = wp_create_nonce( 'biapsu_profilesync_link_' . $user_id );
		return '<div class="biapsu-card">'
			. '<h2>' . esc_html__( 'เชื่อมต่อบัญชีแพลตฟอร์มพุทธธรรม', 'biapsu-profilesync' ) . '</h2>'
			. '<p>' . esc_html__( 'ระบบจะให้ท่านเข้าสู่ระบบแพลตฟอร์มพุทธธรรม เพื่อยืนยันและเชื่อมต่อกับบัญชี Google ที่ใช้อยู่', 'biapsu-profilesync' ) . '</p>'
			. '<form method="post" action="' . esc_url( $action_url ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( Account_Linking::START_ACTION ) . '" />'
			. '<input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '" />'
			. '<input type="hidden" name="return_to" value="' . esc_attr( $destination ) . '" />'
			. '<button type="submit" class="biapsu-btn biapsu-btn--primary">' . esc_html__( 'เชื่อมต่อบัญชีแพลตฟอร์มพุทธธรรม', 'biapsu-profilesync' ) . '</button>'
			. '</form>' . $continue . '</div>';
	}

	/**
	 * Keep the original destination for No answers or already-linked users.
	 * Google first-time Yes answers remain on the consent-based linking page.
	 */
	public function maybe_continue() {
		if ( ! is_user_logged_in() || ! is_page( (int) $this->settings->get( 'choice_page_id' ) ) || ! isset( $_GET['return_to'] ) ) {
			return;
		}
		$link = new Account_Linking( $this->settings );
		if ( $link->eligible( get_current_user_id() ) && ! $link->is_linked( get_current_user_id() ) && $this->settings->get( 'enabled' ) ) {
			return;
		}
		$destination = wp_validate_redirect( esc_url_raw( wp_unslash( $_GET['return_to'] ) ), home_url( '/' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Validated redirect.
		if ( get_permalink( (int) $this->settings->get( 'choice_page_id' ) ) === $destination ) {
			$destination = home_url( '/' );
		}
		wp_safe_redirect( $destination );
		exit;
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
