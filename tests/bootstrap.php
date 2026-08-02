<?php
/**
 * PHPUnit bootstrap: load WP stubs, plugin constants, then the classes under
 * test. These are pure unit tests — no running WordPress required.
 *
 * @package BIAPSU\ProfileSync\Tests
 */

require __DIR__ . '/wp-stubs.php';
require __DIR__ . '/core-stub.php';

$root = dirname( __DIR__ );

if ( ! defined( 'BIAPSU_PROFILESYNC_VERSION' ) ) {
	define( 'BIAPSU_PROFILESYNC_VERSION', 'test' );
}
if ( ! defined( 'BIAPSU_PROFILESYNC_FILE' ) ) {
	define( 'BIAPSU_PROFILESYNC_FILE', $root . '/biapsu-profilesync.php' );
}
if ( ! defined( 'BIAPSU_PROFILESYNC_DIR' ) ) {
	define( 'BIAPSU_PROFILESYNC_DIR', $root . '/' );
}
if ( ! defined( 'BIAPSU_PROFILESYNC_URL' ) ) {
	define( 'BIAPSU_PROFILESYNC_URL', 'https://example.test/wp-content/plugins/biapsu-profilesync/' );
}
// A dummy update repository, so the updater wiring is exercised without ever
// naming the real one (the shipped default is asserted in GithubUpdaterTest).
if ( ! defined( 'BIAPSU_PROFILESYNC_GITHUB_REPO' ) ) {
	define( 'BIAPSU_PROFILESYNC_GITHUB_REPO', 'owner/repo' );
}

$inc = $root . '/includes/';
require $inc . 'class-settings.php';
require $inc . 'class-platform-client.php';
require $inc . 'class-profile-mapper.php';
require $inc . 'class-sync-controller.php';
require $inc . 'class-frontend.php';
require $inc . 'class-admin-settings.php';
require $inc . 'class-github-updater.php';
require $inc . 'class-plugin.php';
