<?php
/**
 * Stub of Authorizenter Core's accessor so the sync controller's
 * defer-to-questions branch can be exercised in isolation.
 *
 * Tests set $GLOBALS['__bia_core'] to an object exposing a ->questions member
 * with a has_pending_required( $user_id, $provider ) method, or to null to
 * simulate Authorizenter (or its question feature) being absent.
 *
 * @package BIAPSU\ProfileSync\Tests
 */

namespace Authorizenter\Core {
	if ( ! function_exists( __NAMESPACE__ . '\\authorizenter_core' ) ) {
		function authorizenter_core() {
			return $GLOBALS['__bia_core'] ?? null;
		}
	}
}
