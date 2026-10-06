<?php
/**
 * MCP / WordPress Abilities integration.
 *
 * @package BIAPSU\ProfileSync
 */

namespace BIAPSU\ProfileSync;

defined( 'ABSPATH' ) || exit;

final class MCP {
	public static function register() {
		if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
	}

	public static function register_category() {
		wp_register_ability_category(
			'biapsu-profilesync',
			array(
				'label'       => 'BIA PSU ProfileSync',
				'description' => 'Profile synchronization status and configuration diagnostics.',
			)
		);
	}

	public static function register_abilities() {
		wp_register_ability(
			'biapsu-profilesync/get-user-state',
			array(
				'label'               => 'Get ProfileSync User State',
				'description'         => 'Return the ProfileSync state for a WordPress user.',
				'category'            => 'biapsu-profilesync',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'user_id' => array( 'type' => 'integer', 'minimum' => 1 ),
					),
					'required' => array( 'user_id' ),
				),
				'execute_callback'    => array( __CLASS__, 'get_user_state' ),
				'permission_callback' => array( __CLASS__, 'can_read_user_state' ),
				'meta'                => self::meta(),
			)
		);

		wp_register_ability(
			'biapsu-profilesync/get-status',
			array(
				'label'               => 'Get ProfileSync Status',
				'description'         => 'Return non-secret configuration and readiness information.',
				'category'            => 'biapsu-profilesync',
				'input_schema'        => array( 'type' => 'object', 'properties' => array() ),
				'execute_callback'    => array( __CLASS__, 'get_status' ),
				'permission_callback' => static function () { return current_user_can( 'manage_options' ); },
				'meta'                => self::meta(),
			)
		);
	}

	public static function can_read_user_state( array $input ) {
		$user_id = (int) $input['user_id'];
		return get_current_user_id() === $user_id || current_user_can( 'list_users' );
	}

	public static function get_user_state( array $input ) {
		$user_id = (int) $input['user_id'];

		return array(
			'user_id' => $user_id,
			'state'   => (string) get_user_meta( $user_id, Sync_Controller::STATE_META, true ),
			'error'   => (string) get_user_meta( $user_id, Sync_Controller::ERROR_META, true ),
		);
	}

	public static function get_status() {
		$settings = Plugin::instance()->settings();
		$platform = (array) $settings->get( 'platform' );
		unset( $platform['api_key'] );

		return array(
			'enabled'        => (bool) $settings->get( 'enabled' ),
			'configured'     => (bool) $settings->is_configured(),
			'choice_page_id' => (int) $settings->get( 'choice_page_id' ),
			'platform'       => $platform,
			'fields'         => (array) $settings->get( 'fields' ),
		);
	}

	private static function meta() {
		return array(
			'mcp'         => array( 'public' => true, 'type' => 'tool' ),
			'annotations' => array(
				'readonly' => true, 'destructive' => false, 'idempotent' => true, 'openWorldHint' => false,
			),
		);
	}
}
