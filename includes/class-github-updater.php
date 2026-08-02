<?php
/**
 * Self-hosted plugin updates from this repository's GitHub Releases.
 *
 * The WordPress Plugins screen shows an available update whenever a release tag
 * newer than the installed version exists, and installs the release's built ZIP
 * asset (`biapsu-profilesync.zip`, produced by .github/workflows/release.yml).
 * The source zipball is only a fallback — it extracts to a commit-hashed folder
 * name, so the built asset is always preferred.
 *
 * A missing repository, a rate-limited API or a repo with no releases must all
 * be non-events: every failure path returns early and is negatively cached, so
 * the Plugins screen never warns or fatals.
 *
 * @package BIAPSU\ProfileSync
 */

namespace BIAPSU\ProfileSync;

defined( 'ABSPATH' ) || exit;

/**
 * Bridges the GitHub Releases API to WordPress's plugin update machinery.
 */
class Github_Updater {

	/**
	 * Absolute path to the plugin's main file.
	 *
	 * @var string
	 */
	private $file;

	/**
	 * Plugin slug / installed folder name.
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * GitHub repository as "owner/repo".
	 *
	 * @var string
	 */
	private $repo;

	/**
	 * Installed plugin version.
	 *
	 * @var string
	 */
	private $version;

	/**
	 * Preferred release asset filename.
	 *
	 * @var string
	 */
	private $asset;

	/**
	 * Constructor.
	 *
	 * @param string $file    Absolute path to the plugin's main file.
	 * @param string $slug    Plugin slug / installed folder name.
	 * @param string $repo    GitHub repository as "owner/repo".
	 * @param string $version Installed plugin version.
	 * @param string $asset   Preferred release asset filename. Defaults to "<slug>.zip".
	 */
	public function __construct( $file, $slug, $repo, $version, $asset = '' ) {
		$this->file    = $file;
		$this->slug    = $slug;
		$this->repo    = trim( (string) $repo, '/ ' );
		$this->version = $version;
		$this->asset   = '' !== $asset ? $asset : $slug . '.zip';
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		if ( '' === $this->repo || false === strpos( $this->repo, '/' ) ) {
			return; // No usable "owner/repo" configured.
		}

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
	}

	/**
	 * Plugin basename, e.g. "biapsu-profilesync/biapsu-profilesync.php".
	 *
	 * @return string
	 */
	private function basename() {
		return plugin_basename( $this->file );
	}

	/**
	 * Fetch the repository's latest release, caching the decoded response.
	 *
	 * @return array<string,mixed>|null Decoded release, or null when unavailable.
	 */
	private function latest_release() {
		$cache_key = 'biapsu_profilesync_gh_' . md5( $this->repo );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			// An empty array is the negative cache written after a failed or
			// non-200 request; treat it as "no release", not a malformed one.
			return empty( $cached['tag_name'] ) ? null : $cached;
		}

		$defaults = array(
			'timeout' => 15,
			'headers' => array(
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'BIAPSU-ProfileSync-Updater',
			),
		);

		/**
		 * Filter the GitHub API request arguments, e.g. to add an authorization
		 * token for a private repository or a higher rate limit.
		 *
		 * @param array<string,mixed> $args Arguments for wp_remote_get().
		 * @param string              $repo GitHub repository as "owner/repo".
		 */
		$args = apply_filters( 'biapsu_profilesync_github_request_args', $defaults, $this->repo );

		$response = wp_remote_get(
			'https://api.github.com/repos/' . $this->repo . '/releases/latest',
			is_array( $args ) ? $args : $defaults
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			set_transient( $cache_key, array(), HOUR_IN_SECONDS ); // Brief negative cache.
			return null;
		}

		/**
		 * Decoded release payload.
		 *
		 * @var mixed $release
		 */
		$release = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $release ) || empty( $release['tag_name'] ) ) {
			set_transient( $cache_key, array(), HOUR_IN_SECONDS );
			return null;
		}

		set_transient( $cache_key, $release, 6 * HOUR_IN_SECONDS );

		return $release;
	}

	/**
	 * Normalize a tag or version string (release tags carry a leading "v").
	 *
	 * @param string $version Tag or version.
	 * @return string
	 */
	private function normalize( $version ) {
		return ltrim( (string) $version, 'vV' );
	}

	/**
	 * Resolve the download URL for a release: the built plugin ZIP if the release
	 * has one, else GitHub's source zipball.
	 *
	 * @param array<string,mixed> $release Release data.
	 * @return string Download URL, or '' when the release offers neither.
	 */
	private function package_url( array $release ) {
		if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				if ( isset( $asset['name'], $asset['browser_download_url'] ) && $asset['name'] === $this->asset ) {
					return (string) $asset['browser_download_url'];
				}
			}
		}

		return isset( $release['zipball_url'] ) ? (string) $release['zipball_url'] : '';
	}

	/**
	 * Read the plugin's bundled readme.txt (it sits next to the main file).
	 *
	 * @return string Raw contents, or '' when absent or unreadable.
	 */
	private function read_readme() {
		$path = dirname( $this->file ) . '/readme.txt';

		if ( ! is_readable( $path ) ) {
			return '';
		}

		return (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Bundled plugin file, not a remote resource.
	}

	/**
	 * Parse a WordPress-format readme.txt into its header fields and sections.
	 *
	 * Header keys and section names are lowercased so callers can read them
	 * case-insensitively, e.g. $parsed['headers']['tested up to'] and
	 * $parsed['sections']['changelog'].
	 *
	 * @param string $raw Raw readme.txt contents.
	 * @return array{headers:array<string,string>,sections:array<string,string>}
	 */
	private function parse_readme( $raw ) {
		$raw      = str_replace( "\r\n", "\n", (string) $raw );
		$headers  = array();
		$sections = array();

		// Split off the header block (everything before the first "== Section ==").
		// Requiring whitespace inside keeps the "=== Title ===" line out of the matches.
		$parts = preg_split( '/^==[ \t]+(.+?)[ \t]+==[ \t]*$/m', $raw, -1, PREG_SPLIT_DELIM_CAPTURE );
		$head  = (string) array_shift( $parts );

		foreach ( explode( "\n", $head ) as $line ) {
			if ( preg_match( '/^([A-Za-z][A-Za-z .]+?):\s*(.*)$/', trim( $line ), $matches ) ) {
				$headers[ strtolower( $matches[1] ) ] = trim( $matches[2] );
			}
		}

		$count = count( $parts );
		for ( $i = 0; $i + 1 < $count; $i += 2 ) {
			$sections[ strtolower( trim( $parts[ $i ] ) ) ] = trim( $parts[ $i + 1 ] );
		}

		return array(
			'headers'  => $headers,
			'sections' => $sections,
		);
	}

	/**
	 * Convert a readme section's markup to HTML for the plugin details modal:
	 * "= Subheading =" becomes an <h4>, runs of "* item" lines become a <ul>, and
	 * the remaining prose is wrapped into paragraphs.
	 *
	 * @param string $text Section body.
	 * @return string Sanitized HTML.
	 */
	private function readme_html( $text ) {
		$lines = explode( "\n", str_replace( "\r\n", "\n", (string) $text ) );
		$html  = '';
		$items = array();

		foreach ( $lines as $line ) {
			$trimmed = trim( $line );

			if ( preg_match( '/^\*\s+(.*)$/', $trimmed, $matches ) ) {
				$items[] = $matches[1]; // Start of a new bullet.
				continue;
			}
			if ( $items && '' !== $trimmed && ! preg_match( '/^=.*=$/', $trimmed ) ) {
				$items[ count( $items ) - 1 ] .= ' ' . $trimmed; // Wrapped continuation of the current bullet.
				continue;
			}
			if ( $items ) { // Any other line closes an open list.
				$html .= '<ul><li>' . implode( '</li><li>', $items ) . "</li></ul>\n";
				$items = array();
			}
			if ( preg_match( '/^=\s*(.+?)\s*=$/', $trimmed, $matches ) ) {
				$html .= '<h4>' . $matches[1] . "</h4>\n";
				continue;
			}

			$html .= $line . "\n";
		}

		if ( $items ) {
			$html .= '<ul><li>' . implode( '</li><li>', $items ) . "</li></ul>\n";
		}

		return wp_kses_post( wpautop( $html ) );
	}

	/**
	 * Turn a comma-separated readme header value into the key => value array the
	 * details modal expects for contributors and tags.
	 *
	 * @param string $csv Comma-separated list.
	 * @return array<string,string>
	 */
	private function csv_list( $csv ) {
		$out = array();

		foreach ( array_filter( array_map( 'trim', explode( ',', (string) $csv ) ) ) as $item ) {
			$out[ $item ] = $item;
		}

		return $out;
	}

	/**
	 * Inject an available update into the update_plugins transient.
	 *
	 * @param mixed $transient Update transient object, or a falsy placeholder.
	 * @return mixed
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$release = $this->latest_release();
		if ( null === $release ) {
			return $transient;
		}

		$new_version = $this->normalize( $release['tag_name'] );

		if ( '' === $new_version || version_compare( $new_version, $this->normalize( $this->version ), '<=' ) ) {
			// Up to date — record that so WordPress stops asking this run.
			if ( isset( $transient->no_update ) ) {
				$transient->no_update[ $this->basename() ] = $this->build_item( $new_version, '' );
			}
			return $transient;
		}

		$package = $this->package_url( $release );
		if ( '' === $package ) {
			return $transient;
		}

		$transient->response[ $this->basename() ] = $this->build_item( $new_version, $package );

		return $transient;
	}

	/**
	 * Build the update item object WordPress expects in the transient.
	 *
	 * @param string $new_version Version offered by the release.
	 * @param string $package     Download URL, or '' when there is nothing to install.
	 * @return object
	 */
	private function build_item( $new_version, $package ) {
		return (object) array(
			'slug'         => $this->slug,
			'plugin'       => $this->basename(),
			'new_version'  => $new_version,
			'url'          => 'https://github.com/' . $this->repo,
			'package'      => $package,
			'icons'        => array(),
			'banners'      => array(),
			'tested'       => '',
			'requires_php' => '8.0',
		);
	}

	/**
	 * Provide the "View details" modal content for this plugin.
	 *
	 * @param false|object|array $result Result from a previous filter.
	 * @param string             $action API action being performed.
	 * @param object             $args   Request arguments (expects ->slug).
	 * @return false|object|array
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$data     = get_plugin_data( $this->file, false, false );
		$readme   = $this->parse_readme( $this->read_readme() );
		$headers  = $readme['headers'];
		$sections = $readme['sections'];

		// A reachable release is optional here: it only enriches version, date
		// and download link. The bundled readme.txt carries the rest.
		$release = $this->latest_release();

		$version = ! empty( $headers['stable tag'] ) ? $headers['stable tag'] : $this->normalize( $this->version );
		if ( null !== $release ) {
			$version = $this->normalize( $release['tag_name'] );
		}

		$description = ! empty( $sections['description'] )
			? $this->readme_html( $sections['description'] )
			: ( isset( $data['Description'] ) ? $data['Description'] : '' );

		$changelog = ! empty( $sections['changelog'] )
			? $this->readme_html( $sections['changelog'] )
			: ( ( null !== $release && ! empty( $release['body'] ) ) ? wp_kses_post( wpautop( $release['body'] ) ) : '' );

		$info = array(
			'name'          => isset( $data['Name'] ) ? $data['Name'] : $this->slug,
			'slug'          => $this->slug,
			'version'       => $version,
			'author'        => isset( $data['Author'] ) ? $data['Author'] : '',
			'homepage'      => 'https://github.com/' . $this->repo,
			'download_link' => null !== $release ? $this->package_url( $release ) : '',
			'requires'      => ! empty( $headers['requires at least'] ) ? $headers['requires at least'] : '6.0',
			'requires_php'  => ! empty( $headers['requires php'] ) ? $headers['requires php'] : '8.0',
			'tested'        => ! empty( $headers['tested up to'] ) ? $headers['tested up to'] : '',
			'sections'      => array(
				'description' => $description,
				'changelog'   => $changelog,
			),
		);

		if ( ! empty( $headers['contributors'] ) ) {
			$info['contributors'] = $this->csv_list( $headers['contributors'] );
		}
		if ( ! empty( $headers['tags'] ) ) {
			$info['tags'] = $this->csv_list( $headers['tags'] );
		}
		if ( null !== $release && ! empty( $release['published_at'] ) ) {
			$info['last_updated'] = $release['published_at'];
		}

		return (object) $info;
	}

	/**
	 * Rename the extracted source folder to the plugin slug so WordPress installs
	 * the update over the existing plugin folder instead of beside it.
	 *
	 * @param string $source        Extracted source directory.
	 * @param string $remote_source Remote source directory.
	 * @param object $upgrader      WP_Upgrader instance.
	 * @param array  $hook_extra    Extra arguments passed by the upgrader.
	 * @return string|\WP_Error
	 */
	public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->basename() ) {
			return $source;
		}

		$desired = trailingslashit( $remote_source ) . $this->slug;
		if ( untrailingslashit( $source ) === $desired ) {
			return $source;
		}

		global $wp_filesystem;

		if ( $wp_filesystem && $wp_filesystem->move( untrailingslashit( $source ), $desired, true ) ) {
			return trailingslashit( $desired );
		}

		return $source;
	}
}
