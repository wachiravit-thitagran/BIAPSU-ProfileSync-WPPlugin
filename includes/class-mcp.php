<?php
/**
 * MCP / WordPress Abilities integration.
 *
 * @package BIAPSU\ProfileSync
 */

namespace BIAPSU\ProfileSync;

defined( 'ABSPATH' ) || exit;

/**
 * Registers ProfileSync abilities for MCP Adapter.
 */
final class MCP {

	/**
	 * Register hooks when the WordPress Abilities API is available.
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
	}

	/**
	 * Register the ability category.
	 *
	 * @return void
	 */
	public static function register_category() {
		wp_register_ability_category(
			'biapsu-profilesync',
			array(
				'label'       => 'BIA PSU ProfileSync',
				'description' => 'Consent-based BIA volunteer question and OIDC account-link status.',
			)
		);
	}

	/**
	 * Register all MCP-visible abilities.
	 *
	 * @return void
	 */
	public static function register_abilities() {
		wp_register_ability(
			'biapsu-profilesync/get-user-state',
			array(
				'label'               => 'Get ProfileSync User State',
				'description'         => 'Retrieves the synchronization state and latest synchronization error, if any, for a WordPress user.',
				'category'            => 'biapsu-profilesync',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'user_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					'required'   => array( 'user_id' ),
				),
				'execute_callback'    => array( __CLASS__, 'get_user_state' ),
				'permission_callback' => array( __CLASS__, 'can_read_user_state' ),
				'meta'                => self::meta(),
			)
		);

		wp_register_ability( 'biapsu-profilesync/get-link-status', array(
			'label' => 'Get BIA Platform Link Status',
			'description' => 'Read volunteer answer and verified OIDC link status without identities or secrets.',
			'category' => 'biapsu-profilesync',
			'input_schema' => array( 'type' => 'object', 'properties' => array(
				'user_id' => array( 'type' => 'integer', 'minimum' => 1 ),
			), 'required' => array( 'user_id' ) ),
			'execute_callback' => array( __CLASS__, 'get_link_status' ),
			'permission_callback' => array( __CLASS__, 'can_read_user_state' ),
			'meta' => self::meta(),
		) );

		wp_register_ability(
			'biapsu-profilesync/get-status',
			array(
				'label'               => 'Get ProfileSync Status',
				'description'         => 'Returns account-linking configuration without secrets or API key.',
				'category'            => 'biapsu-profilesync',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'execute_callback'    => array( __CLASS__, 'get_status' ),
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
				'meta'                => self::meta(),
			)
		);
	}

	/**
	 * Check whether the caller may inspect a user's sync state.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return bool
	 */
	public static function can_read_user_state( array $input ) {
		$user_id = (int) ( $input['user_id'] ?? 0 );

		return get_current_user_id() === $user_id || current_user_can( 'list_users' );
	}

	/**
	 * Return one user's ProfileSync state.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>
	 */
	public static function get_user_state( array $input ) {
		$user_id = (int) $input['user_id'];

		return array(
			'user_id' => $user_id,
			'state'   => (string) get_user_meta( $user_id, Sync_Controller::STATE_META, true ),
			'error'   => (string) get_user_meta( $user_id, Sync_Controller::ERROR_META, true ),
		);
	}

	/** Read a user's self-declared answer and linked identity status. */
	public static function get_link_status( array $input ) {
		$user_id = (int) ( $input['user_id'] ?? 0 );
		$answers = get_user_meta( $user_id, 'authorizenter_answers', true );
		$answer = is_array( $answers ) && isset( $answers['is_bia_volunteer'] ) ? (string) $answers['is_bia_volunteer'] : '';
		$link = new Account_Linking( Plugin::instance()->settings() );
		$status = $link->is_linked( $user_id ) ? 'linked' : ( 'ใช่' === $answer ? 'available' : ( '' === $answer ? 'unanswered' : 'not_applicable' ) );
		return array(
			'user_id' => $user_id,
			'volunteer_answer' => $answer,
			'link_status' => $status,
			'provider' => 'oidc',
		);
	}

	/**
	 * Return non-secret plugin configuration status.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_status() {
		$settings = Plugin::instance()->settings();
		return array(
			'enabled'                   => (bool) $settings->get( 'enabled' ),
			'flow'                      => 'google_question_then_oidc_link',
			'oidc_provider'             => 'oidc',
			'platform_api_key_required' => false,
			'choice_page_id'            => (int) $settings->get( 'choice_page_id' ),
		);
	}

	/**
	 * Shared MCP metadata.
	 *
	 * @return array<string,mixed>
	 */
	private static function meta() {
		return array(
			'mcp'         => array(
				'public' => true,
				'type'   => 'tool',
			),
			'annotations' => array(
				'readonly'      => true,
				'destructive'   => false,
				'idempotent'    => true,
				'openWorldHint' => false,
			),
		);
	}
}
