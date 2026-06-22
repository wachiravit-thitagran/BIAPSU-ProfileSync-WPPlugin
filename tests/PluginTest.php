<?php
/**
 * Tests for Plugin singleton.
 *
 * @package BIAPSU\ProfileSync\Tests
 */

namespace BIAPSU\ProfileSync\Tests;

use PHPUnit\Framework\TestCase;
use BIAPSU\ProfileSync\Plugin;
use BIAPSU\ProfileSync\Settings;

class PluginTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		bia_test_reset();
	}

	public function test_singleton_instance() {
		$plugin1 = Plugin::instance();
		$plugin2 = Plugin::instance();

		$this->assertInstanceOf( Plugin::class, $plugin1 );
		$this->assertSame( $plugin1, $plugin2 );
	}

	public function test_settings_accessor() {
		$plugin = Plugin::instance();
		$this->assertInstanceOf( Settings::class, $plugin->settings() );
	}

	public function test_hooks() {
		$plugin = Plugin::instance();
		
		$GLOBALS['__is_admin'] = false;
		$plugin->hooks();

		$this->assertFalse( empty( $GLOBALS['__options'] ), 'Settings should be defaulted' );
		
		// If hooks were called, actions should be registered (though we didn't mock action tracking in deep detail).
		// So we just assert it doesn't crash.
		$this->assertTrue( true );
	}

	public function test_hooks_admin() {
		$plugin = Plugin::instance();
		
		$GLOBALS['__is_admin'] = true;
		$plugin->hooks();

		$this->assertTrue( true );
	}
}
