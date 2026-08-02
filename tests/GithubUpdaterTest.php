<?php
/**
 * Tests for Github_Updater: repository slug, version comparison, asset choice
 * and failure handling.
 *
 * @package BIAPSU\ProfileSync\Tests
 */

namespace BIAPSU\ProfileSync\Tests;

use BIAPSU\ProfileSync\Github_Updater;
use PHPUnit\Framework\TestCase;

class GithubUpdaterTest extends TestCase {

	/**
	 * Stand-in plugin file: only its basename/dirname matter to the updater.
	 */
	private const FILE = '/plugins/biapsu-profilesync/biapsu-profilesync.php';

	private const SLUG = 'biapsu-profilesync';

	private const REPO = 'owner/repo';

	protected function setUp(): void {
		parent::setUp();
		bia_test_reset();
	}

	private function updater( string $version, string $file = self::FILE ): Github_Updater {
		return new Github_Updater( $file, self::SLUG, self::REPO, $version, self::SLUG . '.zip' );
	}

	/**
	 * Call a private helper directly — the mapping from a release payload to a
	 * version/download URL is the logic worth pinning.
	 *
	 * @param object $object Updater instance.
	 * @param string $method Method name.
	 * @param array  $args   Arguments.
	 * @return mixed
	 */
	private function invoke( object $object, string $method, array $args = array() ) {
		$method = new \ReflectionMethod( $object, $method );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true ); // Required on PHP 8.0; a no-op (and deprecated) later.
		}
		return $method->invokeArgs( $object, $args );
	}

	private function seed_release( array $release ): void {
		set_transient( 'biapsu_profilesync_gh_' . md5( self::REPO ), $release );
	}

	/**
	 * A stand-in for the update_plugins site transient WordPress passes in.
	 */
	private function update_transient(): object {
		return (object) array(
			'response'  => array(),
			'no_update' => array(),
		);
	}

	/**
	 * Every install checks the shipped default repository for releases, and a typo
	 * there fails completely silently: the GitHub API answers 404 exactly as it
	 * would for a repository with no releases, so no update is ever offered. Pin
	 * the constant to the plugin's `Plugin URI` header so the two cannot drift.
	 */
	public function test_default_github_repo_matches_the_plugin_uri(): void {
		$main = dirname( __DIR__ ) . '/biapsu-profilesync.php';
		$php  = (string) file_get_contents( $main );

		preg_match( "/define\(\s*'BIAPSU_PROFILESYNC_GITHUB_REPO',\s*'([^']*)'/", $php, $constant );
		$repo = $constant[1] ?? '';

		$this->assertMatchesRegularExpression(
			'#^[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?/[A-Za-z0-9._-]+$#',
			$repo,
			'BIAPSU_PROFILESYNC_GITHUB_REPO must be a valid "owner/repo" slug.'
		);

		preg_match( '#Plugin URI:\s*https://github\.com/(\S+?)/?\s*$#m', $php, $uri );

		$this->assertSame(
			strtolower( $repo ),
			strtolower( $uri[1] ?? '' ),
			'The Plugin URI header must point at the repository the updater pulls releases from.'
		);
	}

	public function test_normalize_strips_tag_prefix(): void {
		$updater = $this->updater( '0.1.0' );
		$this->assertSame( '1.2.3', $this->invoke( $updater, 'normalize', array( 'v1.2.3' ) ) );
		$this->assertSame( '1.2.3', $this->invoke( $updater, 'normalize', array( '1.2.3' ) ) );
	}

	public function test_package_url_prefers_the_built_asset(): void {
		$release = array(
			'assets'      => array(
				array( 'name' => 'other.zip', 'browser_download_url' => 'https://dl.example.test/other.zip' ),
				array( 'name' => 'biapsu-profilesync.zip', 'browser_download_url' => 'https://dl.example.test/biapsu-profilesync.zip' ),
			),
			'zipball_url' => 'https://dl.example.test/zipball',
		);

		$this->assertSame(
			'https://dl.example.test/biapsu-profilesync.zip',
			$this->invoke( $this->updater( '0.1.0' ), 'package_url', array( $release ) )
		);
	}

	public function test_package_url_falls_back_to_the_zipball(): void {
		$release = array( 'assets' => array(), 'zipball_url' => 'https://dl.example.test/zipball' );

		$this->assertSame(
			'https://dl.example.test/zipball',
			$this->invoke( $this->updater( '0.1.0' ), 'package_url', array( $release ) )
		);
	}

	public function test_package_url_is_empty_without_asset_or_zipball(): void {
		$this->assertSame( '', $this->invoke( $this->updater( '0.1.0' ), 'package_url', array( array() ) ) );
	}

	public function test_inject_update_offers_a_newer_release(): void {
		$this->seed_release(
			array(
				'tag_name'    => 'v9.9.9',
				'assets'      => array( array( 'name' => 'biapsu-profilesync.zip', 'browser_download_url' => 'https://dl.example.test/biapsu-profilesync.zip' ) ),
				'zipball_url' => 'https://dl.example.test/zipball',
			)
		);

		$result = $this->updater( '0.1.0' )->inject_update( $this->update_transient() );

		$key = plugin_basename( self::FILE );
		$this->assertArrayHasKey( $key, $result->response );
		$this->assertSame( '9.9.9', $result->response[ $key ]->new_version );
		$this->assertSame( 'https://dl.example.test/biapsu-profilesync.zip', $result->response[ $key ]->package );
		$this->assertSame( self::SLUG, $result->response[ $key ]->slug );
	}

	public function test_inject_update_records_no_update_when_current(): void {
		$this->seed_release( array( 'tag_name' => 'v0.1.0', 'assets' => array(), 'zipball_url' => 'https://dl.example.test/zipball' ) );

		$result = $this->updater( '0.1.0' )->inject_update( $this->update_transient() );

		$key = plugin_basename( self::FILE );
		$this->assertArrayNotHasKey( $key, $result->response );
		$this->assertArrayHasKey( $key, $result->no_update );
	}

	public function test_inject_update_ignores_an_older_release(): void {
		$this->seed_release( array( 'tag_name' => 'v0.0.9', 'assets' => array(), 'zipball_url' => 'https://dl.example.test/zipball' ) );

		$result = $this->updater( '0.1.0' )->inject_update( $this->update_transient() );

		$this->assertArrayNotHasKey( plugin_basename( self::FILE ), $result->response );
	}

	public function test_inject_update_passes_through_a_non_object(): void {
		$this->assertFalse( $this->updater( '0.1.0' )->inject_update( false ) );
	}

	/**
	 * A 404 (misspelled owner, private repo, no releases yet) or a transport error
	 * must simply offer no update — never a notice or a fatal on the Plugins screen.
	 */
	public function test_inject_update_survives_a_failed_api_call(): void {
		bia_test_http_push( 404, array( 'message' => 'Not Found' ) );

		$result = $this->updater( '0.1.0' )->inject_update( $this->update_transient() );

		$key = plugin_basename( self::FILE );
		$this->assertArrayNotHasKey( $key, $result->response );
		$this->assertArrayNotHasKey( $key, $result->no_update );
		// The failure is negatively cached so the screen does not re-request it.
		$this->assertSame( array(), get_transient( 'biapsu_profilesync_gh_' . md5( self::REPO ) ) );
	}

	public function test_inject_update_survives_a_transport_error(): void {
		// No queued response: the HTTP stub returns a WP_Error.
		$result = $this->updater( '0.1.0' )->inject_update( $this->update_transient() );

		$this->assertArrayNotHasKey( plugin_basename( self::FILE ), $result->response );
	}

	public function test_inject_update_ignores_the_negative_cache(): void {
		$this->seed_release( array() );

		$result = $this->updater( '0.1.0' )->inject_update( $this->update_transient() );

		$key = plugin_basename( self::FILE );
		$this->assertArrayNotHasKey( $key, $result->response );
		$this->assertArrayNotHasKey( $key, $result->no_update );
		$this->assertSame( array(), $GLOBALS['__http_log'], 'A cached failure must not trigger another request.' );
	}

	public function test_latest_release_caches_the_api_response(): void {
		bia_test_http_push( 200, array( 'tag_name' => 'v1.0.0', 'assets' => array() ) );
		$updater = $this->updater( '0.1.0' );

		$first  = $this->invoke( $updater, 'latest_release' );
		$second = $this->invoke( $updater, 'latest_release' );

		$this->assertSame( 'v1.0.0', $first['tag_name'] );
		$this->assertSame( $first, $second );
		$this->assertCount( 1, $GLOBALS['__http_log'], 'The release should be fetched once and then read from the transient.' );
		$this->assertStringContainsString( 'api.github.com/repos/' . self::REPO . '/releases/latest', $GLOBALS['__http_log'][0]['url'] );
	}

	public function test_latest_release_returns_null_for_the_negative_cache(): void {
		$this->seed_release( array() );
		$this->assertNull( $this->invoke( $this->updater( '0.1.0' ), 'latest_release' ) );
	}

	public function test_hooks_registers_the_update_filters(): void {
		$this->updater( '0.1.0' )->hooks();

		$tags = array_column( $GLOBALS['__filters'], 'tag' );
		$this->assertContains( 'pre_set_site_transient_update_plugins', $tags );
		$this->assertContains( 'plugins_api', $tags );
		$this->assertContains( 'upgrader_source_selection', $tags );
	}

	/**
	 * @dataProvider unusable_repos
	 */
	public function test_hooks_are_skipped_without_a_valid_repo( string $repo ): void {
		( new Github_Updater( self::FILE, self::SLUG, $repo, '0.1.0' ) )->hooks();

		$this->assertSame( array(), $GLOBALS['__filters'], 'An unusable "owner/repo" must not register anything.' );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function unusable_repos(): array {
		return array(
			'empty'    => array( '' ),
			'blank'    => array( '  ' ),
			'no slash' => array( 'BIAPSU-ProfileSync-WPPlugin' ),
		);
	}

	public function test_asset_name_defaults_to_the_slug_zip(): void {
		$updater = new Github_Updater( self::FILE, self::SLUG, self::REPO, '0.1.0' );
		$release = array(
			'assets'      => array( array( 'name' => 'biapsu-profilesync.zip', 'browser_download_url' => 'https://dl.example.test/biapsu-profilesync.zip' ) ),
			'zipball_url' => 'https://dl.example.test/zipball',
		);

		$this->assertSame(
			'https://dl.example.test/biapsu-profilesync.zip',
			$this->invoke( $updater, 'package_url', array( $release ) )
		);
	}

	public function test_plugin_info_describes_this_plugin(): void {
		$this->seed_release(
			array(
				'tag_name'     => 'v2.0.0',
				'published_at' => '2026-01-02T03:04:05Z',
				'assets'       => array( array( 'name' => 'biapsu-profilesync.zip', 'browser_download_url' => 'https://dl.example.test/biapsu-profilesync.zip' ) ),
			)
		);

		// The real plugin file, so the bundled readme.txt is parsed.
		$updater = $this->updater( '0.1.0', dirname( __DIR__ ) . '/biapsu-profilesync.php' );
		$info    = $updater->plugin_info( false, 'plugin_information', (object) array( 'slug' => self::SLUG ) );

		$this->assertIsObject( $info );
		$this->assertSame( self::SLUG, $info->slug );
		$this->assertSame( '2.0.0', $info->version );
		$this->assertSame( 'https://dl.example.test/biapsu-profilesync.zip', $info->download_link );
		$this->assertSame( '2026-01-02T03:04:05Z', $info->last_updated );
		$this->assertStringContainsString( 'https://github.com/' . self::REPO, $info->homepage );
		$this->assertNotSame( '', $info->sections['changelog'], 'readme.txt should supply the changelog shown in the details modal.' );
	}

	public function test_plugin_info_passes_other_slugs_through(): void {
		$this->assertFalse(
			$this->updater( '0.1.0' )->plugin_info( false, 'plugin_information', (object) array( 'slug' => 'another-plugin' ) )
		);
	}

	public function test_plugin_info_passes_other_actions_through(): void {
		$this->assertFalse(
			$this->updater( '0.1.0' )->plugin_info( false, 'query_plugins', (object) array( 'slug' => self::SLUG ) )
		);
	}

	public function test_parse_readme_splits_headers_and_sections(): void {
		$raw = "=== BIA PSU ProfileSync ===\n"
			. "Contributors: biapsu\n"
			. "Tags: login, sync\n"
			. "Tested up to: 6.5\n"
			. "Requires PHP: 8.0\n"
			. "Stable tag: 0.1.0\n"
			. "\n"
			. "Short description, ignored.\n"
			. "\n"
			. "== Description ==\n"
			. "\n"
			. "Body text.\n"
			. "\n"
			. "== Changelog ==\n"
			. "\n"
			. "= 0.1.0 =\n"
			. "* Initial release.\n";

		$parsed = $this->invoke( $this->updater( '0.1.0' ), 'parse_readme', array( $raw ) );

		$this->assertSame( 'biapsu', $parsed['headers']['contributors'] );
		$this->assertSame( '6.5', $parsed['headers']['tested up to'] );
		$this->assertSame( '0.1.0', $parsed['headers']['stable tag'] );
		$this->assertSame( 'Body text.', $parsed['sections']['description'] );
		$this->assertStringContainsString( 'Initial release.', $parsed['sections']['changelog'] );
	}

	public function test_readme_html_renders_headings_and_lists(): void {
		$html = $this->invoke(
			$this->updater( '0.1.0' ),
			'readme_html',
			array( "= 0.1.0 =\n* First item.\n* Second item.\n" )
		);

		$this->assertStringContainsString( '<h4>0.1.0</h4>', $html );
		$this->assertStringContainsString( '<li>First item.</li>', $html );
		$this->assertStringContainsString( '<li>Second item.</li>', $html );
	}

	public function test_csv_list_builds_a_keyed_array(): void {
		$this->assertSame(
			array( 'login' => 'login', 'sync' => 'sync' ),
			$this->invoke( $this->updater( '0.1.0' ), 'csv_list', array( 'login, sync , ' ) )
		);
	}

	public function test_fix_source_dir_ignores_other_plugins(): void {
		$updater = $this->updater( '0.1.0' );

		$this->assertSame(
			'/tmp/source/',
			$updater->fix_source_dir( '/tmp/source/', '/tmp/', null, array( 'plugin' => 'other/other.php' ) )
		);
		$this->assertSame( '/tmp/source/', $updater->fix_source_dir( '/tmp/source/', '/tmp/', null, array() ) );
	}

	public function test_fix_source_dir_keeps_an_already_correct_folder(): void {
		$source = '/tmp/remote/' . self::SLUG . '/';

		$this->assertSame(
			$source,
			$this->updater( '0.1.0' )->fix_source_dir( $source, '/tmp/remote/', null, array( 'plugin' => plugin_basename( self::FILE ) ) )
		);
	}
}
