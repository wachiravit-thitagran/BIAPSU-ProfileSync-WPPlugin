<?php
/**
 * MCP abilities tests.
 *
 * @package BIAPSU\ProfileSync\Tests
 */

use BIAPSU\ProfileSync\MCP;
use BIAPSU\ProfileSync\Sync_Controller;
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'wp_register_ability_category' ) ) {
	function wp_register_ability_category( $name, $args ) {
		$GLOBALS['mock_ability_categories'][ $name ] = $args;
	}
}

if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( $name, $args ) {
		$GLOBALS['mock_abilities'][ $name ] = $args;
	}
}

final class MCPTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['mock_ability_categories'] = array();
		$GLOBALS['mock_abilities']          = array();
		$GLOBALS['wp_user_meta']            = array();
	}

	public function test_registers_profilesync_abilities(): void {
		MCP::register_category();
		MCP::register_abilities();

		$this->assertArrayHasKey( 'biapsu-profilesync', $GLOBALS['mock_ability_categories'] );
		$this->assertArrayHasKey( 'biapsu-profilesync/get-user-state', $GLOBALS['mock_abilities'] );
		$this->assertArrayHasKey( 'biapsu-profilesync/get-status', $GLOBALS['mock_abilities'] );
	}

	public function test_get_user_state_does_not_expose_secrets(): void {
		$GLOBALS['wp_user_meta'][42][ Sync_Controller::STATE_META ] = 'ready';
		$GLOBALS['wp_user_meta'][42][ Sync_Controller::ERROR_META ] = '';

		$result = MCP::get_user_state( array( 'user_id' => 42 ) );

		$this->assertSame( 42, $result['user_id'] );
		$this->assertSame( 'ready', $result['state'] );
		$this->assertArrayNotHasKey( 'api_key', $result );
	}
}
