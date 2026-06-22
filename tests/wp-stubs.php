<?php
/**
 * Minimal WordPress function/class stubs for unit-testing BIA PSU ProfileSync
 * without a full WordPress install. Backed by in-memory global state that tests
 * manipulate via the bia_test_* helpers.
 *
 * @package BIAPSU\ProfileSync\Tests
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
if ( ! defined( 'AUTH_KEY' ) ) {
	define( 'AUTH_KEY', 'unit-test-auth-key' );
}
if ( ! defined( 'SECURE_AUTH_KEY' ) ) {
	define( 'SECURE_AUTH_KEY', 'unit-test-secure-auth-key' );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

$GLOBALS['__options']    = array();
$GLOBALS['__usermeta']   = array();
$GLOBALS['__users']      = array();
$GLOBALS['__transients'] = array();
$GLOBALS['__next_uid']   = 100;
$GLOBALS['__next_post_id'] = 1;

/** Reset all in-memory state between tests. */
function bia_test_reset() {
	$GLOBALS['__options']        = array();
	$GLOBALS['__usermeta']       = array();
	$GLOBALS['__users']          = array();
	$GLOBALS['__transients']     = array();
	$GLOBALS['__next_uid']       = 100;
	$GLOBALS['__next_post_id']   = 1;
	$GLOBALS['__mock_posts']     = array();
	$GLOBALS['__pages_by_path']  = array();
	$GLOBALS['__logged_in']      = false;
	$GLOBALS['__current_uid']    = 0;
	$GLOBALS['__is_admin']       = false;
	$GLOBALS['__doing_ajax']     = false;
	$GLOBALS['__current_page']   = 0;
	$GLOBALS['__http_queue']     = array();
	$GLOBALS['__http_log']       = array();
	$GLOBALS['__bia_core']       = null;
}

/** Register a fake user. */
function bia_test_make_user( $id, $email = '' ) {
	$u                         = new WP_User();
	$u->ID                     = $id;
	$u->user_email             = '' !== $email ? $email : 'user' . $id . '@example.test';
	$u->user_login             = 'user' . $id;
	$u->display_name           = 'User ' . $id;
	$GLOBALS['__users'][ $id ] = $u;
	return $u;
}

/** Mark a fake user as the current logged-in user. */
function bia_test_login( $id ) {
	$GLOBALS['__logged_in']   = true;
	$GLOBALS['__current_uid'] = (int) $id;
}

/** Queue an HTTP response that the next wp_remote_* call will return. */
function bia_test_http_push( $code, $body ) {
	$GLOBALS['__http_queue'][] = array(
		'response' => array( 'code' => (int) $code ),
		'body'     => is_string( $body ) ? $body : wp_json_encode( $body ),
	);
}

/** Register a fake page (with post_name) and return its id. */
function bia_test_make_page( $post_name ) {
	$id = $GLOBALS['__next_post_id']++;
	$p  = (object) array(
		'ID'          => $id,
		'post_name'   => $post_name,
		'post_type'   => 'page',
		'post_status' => 'publish',
		'post_title'  => ucfirst( $post_name ),
	);
	$GLOBALS['__mock_posts'][ $id ]         = $p;
	$GLOBALS['__pages_by_path'][ $post_name ] = $p;
	return $id;
}

// --- Error / user objects ---------------------------------------------------

class WP_Error {
	protected $code;
	protected $message;
	protected $data;
	public function __construct( $code = '', $message = '', $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}

class WP_User {
	public $ID = 0;
	public $user_email = '';
	public $display_name = '';
	public $user_login = '';
}

function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

// --- Options ----------------------------------------------------------------

function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $key ] : $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['__options'][ $key ] = $value;
	return true;
}
function add_option( $key, $value = '', $deprecated = '', $autoload = 'yes' ) {
	if ( array_key_exists( $key, $GLOBALS['__options'] ) ) {
		return false;
	}
	$GLOBALS['__options'][ $key ] = $value;
	return true;
}
function delete_option( $key ) {
	unset( $GLOBALS['__options'][ $key ] );
	return true;
}

// --- Transients -------------------------------------------------------------

function get_transient( $key ) {
	return array_key_exists( $key, $GLOBALS['__transients'] ) ? $GLOBALS['__transients'][ $key ] : false;
}
function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['__transients'][ $key ] = $value;
	return true;
}
function delete_transient( $key ) {
	unset( $GLOBALS['__transients'][ $key ] );
	return true;
}

// --- User meta --------------------------------------------------------------

function get_user_meta( $uid, $key = '', $single = false ) {
	$m = isset( $GLOBALS['__usermeta'][ $uid ][ $key ] ) ? $GLOBALS['__usermeta'][ $uid ][ $key ] : '';
	return $single ? $m : ( '' === $m ? array() : array( $m ) );
}
function update_user_meta( $uid, $key, $value ) {
	$GLOBALS['__usermeta'][ $uid ][ $key ] = $value;
	return true;
}
function delete_user_meta( $uid, $key, $value = '' ) {
	unset( $GLOBALS['__usermeta'][ $uid ][ $key ] );
	return true;
}
function delete_metadata( $type, $object_id, $key, $value = '', $delete_all = false ) {
	if ( $delete_all ) {
		foreach ( $GLOBALS['__usermeta'] as $uid => $meta ) {
			unset( $GLOBALS['__usermeta'][ $uid ][ $key ] );
		}
	}
	return true;
}

// --- Users ------------------------------------------------------------------

function get_user_by( $field, $value ) {
	foreach ( $GLOBALS['__users'] as $u ) {
		if ( 'id' === $field && (int) $u->ID === (int) $value ) {
			return $u;
		}
		if ( 'email' === $field && strtolower( $u->user_email ) === strtolower( (string) $value ) ) {
			return $u;
		}
	}
	return false;
}

function wp_update_user( $data ) {
	$id = isset( $data['ID'] ) ? (int) $data['ID'] : 0;
	if ( ! isset( $GLOBALS['__users'][ $id ] ) ) {
		return new WP_Error( 'invalid_user_id', 'Invalid user ID.' );
	}
	$u = $GLOBALS['__users'][ $id ];
	if ( isset( $data['first_name'] ) ) {
		$GLOBALS['__usermeta'][ $id ]['first_name'] = $data['first_name'];
	}
	if ( isset( $data['last_name'] ) ) {
		$GLOBALS['__usermeta'][ $id ]['last_name'] = $data['last_name'];
	}
	if ( isset( $data['display_name'] ) ) {
		$u->display_name = $data['display_name'];
	}
	if ( isset( $data['user_email'] ) ) {
		$u->user_email = $data['user_email'];
	}
	$GLOBALS['__users'][ $id ] = $u;
	return $id;
}

function get_current_user_id() { return (int) ( $GLOBALS['__current_uid'] ?? 0 ); }
function is_user_logged_in() { return ! empty( $GLOBALS['__logged_in'] ); }

// --- HTTP API ---------------------------------------------------------------

function _bia_http( $method, $url, $args ) {
	$GLOBALS['__http_log'][] = array( 'method' => $method, 'url' => $url, 'args' => $args );
	if ( ! empty( $GLOBALS['__http_queue'] ) ) {
		return array_shift( $GLOBALS['__http_queue'] );
	}
	return new WP_Error( 'http_no_mock', 'No mocked HTTP response queued.' );
}
function wp_remote_post( $url, $args = array() ) { return _bia_http( 'POST', $url, $args ); }
function wp_remote_get( $url, $args = array() ) { return _bia_http( 'GET', $url, $args ); }
function wp_remote_retrieve_response_code( $r ) { return is_wp_error( $r ) ? 0 : ( $r['response']['code'] ?? 0 ); }
function wp_remote_retrieve_body( $r ) { return is_wp_error( $r ) ? '' : ( $r['body'] ?? '' ); }

// --- Hooks ------------------------------------------------------------------

function add_filter() { return true; }
function add_action() { return true; }
function do_action( $tag, ...$args ) {}
function apply_filters( $tag, $value = null ) { return $value; }

// --- Redirect / nonce / request --------------------------------------------

function wp_safe_redirect( $location, $status = 302 ) {
	$GLOBALS['__last_redirect'] = $location;
	throw new Exception('wp_safe_redirect: ' . $location);
}
function wp_login_url( $redirect = '' ) { return 'https://example.test/wp-login.php'; }
function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ) { return true; }
function wp_create_nonce( $action = -1 ) { return 'nonce-' . $action; }
function wp_verify_nonce( $nonce, $action = -1 ) { return 1; }

// --- Conditionals / context -------------------------------------------------

function is_admin() { return ! empty( $GLOBALS['__is_admin'] ); }
function wp_doing_ajax() { return ! empty( $GLOBALS['__doing_ajax'] ); }
function is_ssl() { return false; }
function is_page( $page = '' ) {
	if ( '' === $page ) {
		return ! empty( $GLOBALS['__current_page'] );
	}
	return (int) $GLOBALS['__current_page'] === (int) $page;
}

// --- Pages / posts ----------------------------------------------------------

function get_post( $post = null ) {
	if ( is_object( $post ) ) {
		return $post;
	}
	$post = (int) $post;
	return isset( $GLOBALS['__mock_posts'][ $post ] ) ? $GLOBALS['__mock_posts'][ $post ] : null;
}
function get_page_by_path( $page_path, $output = 'OBJECT', $post_type = 'page' ) {
	return isset( $GLOBALS['__pages_by_path'][ $page_path ] ) ? $GLOBALS['__pages_by_path'][ $page_path ] : null;
}
function wp_insert_post( $postarr, $wp_error = false ) {
	$id                 = $GLOBALS['__next_post_id']++;
	$postarr['ID']      = $id;
	$GLOBALS['__mock_posts'][ $id ] = (object) $postarr;
	if ( isset( $postarr['post_name'] ) ) {
		$GLOBALS['__pages_by_path'][ $postarr['post_name'] ] = $GLOBALS['__mock_posts'][ $id ];
	}
	return $id;
}
function get_permalink( $post = 0, $leavename = false ) {
	$post = get_post( $post );
	if ( ! $post ) {
		return false;
	}
	return 'https://example.test/' . ( isset( $post->post_name ) ? $post->post_name : $post->ID ) . '/';
}
function get_pages( $args = array() ) {
	$out = array();
	foreach ( $GLOBALS['__mock_posts'] as $p ) {
		if ( isset( $p->post_type ) && 'page' === $p->post_type ) {
			$out[] = $p;
		}
	}
	return $out;
}

// --- i18n / escaping / sanitizers -------------------------------------------

function wp_json_encode( $data, $options = 0, $depth = 512 ) { return json_encode( $data, $options, $depth ); }
function __( $text, $domain = null ) { return $text; }
function esc_attr__( $text, $domain = null ) { return $text; }
function esc_html__( $text, $domain = null ) { return $text; }
function esc_html_e( $text, $domain = null ) { echo $text; }
function esc_attr_e( $text, $domain = null ) { echo $text; }
function esc_html( $s ) { return $s; }
function esc_url( $url ) { return $url; }
function esc_url_raw( $url ) { return $url; }
function esc_attr( $s ) { return $s; }
function sanitize_key( $key ) {
	$key = strtolower( (string) $key );
	return preg_replace( '/[^a-z0-9_\-]/', '', $key );
}
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function sanitize_email( $email ) {
	$email = trim( (string) $email );
	return filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : '';
}
function wp_unslash( $value ) {
	if ( is_array( $value ) ) {
		return array_map( 'wp_unslash', $value );
	}
	return is_string( $value ) ? stripslashes( $value ) : $value;
}
function trailingslashit( $string ) { return rtrim( (string) $string, '/\\' ) . '/'; }
function add_query_arg( $key, $value = null, $url = null ) {
	// Support add_query_arg( $key, $value, $url ).
	$query = is_array( $key ) ? http_build_query( $key ) : rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
	$url   = is_array( $key ) ? (string) $value : (string) $url;
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $query;
}
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' ); }
function home_url( $path = '/' ) { return 'https://example.test' . $path; }
function wp_enqueue_style( ...$args ) {}
function wp_enqueue_script( ...$args ) {}
function add_shortcode( $tag, $cb ) {}
function load_plugin_textdomain( ...$args ) { return true; }
function plugin_dir_path( $file ) { return rtrim( dirname( $file ), '/\\' ) . '/'; }
function plugin_dir_url( $file ) { return 'https://example.test/wp-content/plugins/' . basename( dirname( $file ) ) . '/'; }

// phpcs:enable

// --- Admin UI & Capabilities ---
function add_menu_page($page_title, $menu_title, $capability, $menu_slug, $function = '', $icon_url = '', $position = null) {}
function settings_errors($setting = '', $sanitize = false, $hide_on_update = false) {}
function add_settings_error($setting, $code, $message, $type = 'error') {}
function wp_die($message = '', $title = '', $args = array()) { throw new Exception('wp_die: ' . $message); }
function current_user_can($capability, ...$args) { return !empty($GLOBALS['__current_user_can']); }
function wp_nonce_field($action = -1, $name = '_wpnonce', $referer = true, $echo = true) {
    $field = '<input type="hidden" name="' . esc_attr($name) . '" value="' . wp_create_nonce($action) . '" />';
    if ($echo) { echo $field; }
    return $field;
}
function checked($checked, $current = true, $echo = true) {
    $r = '';
    if ((string) $checked === (string) $current) {
        $r = ' checked="checked"';
    }
    if ($echo) { echo $r; }
    return $r;
}
function selected($selected, $current = true, $echo = true) {
    $r = '';
    if ((string) $selected === (string) $current) {
        $r = ' selected="selected"';
    }
    if ($echo) { echo $r; }
    return $r;
}
