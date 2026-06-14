<?php
/**
 * Uninstall cleanup for BIA PSU ProfileSync.
 *
 * Removes the plugin option, cached token, and per-user sync meta. The
 * auto-created choice page is intentionally left in place to avoid surprising
 * content loss.
 *
 * @package BIAPSU\ProfileSync
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'biapsu_profilesync_settings' );
delete_transient( 'biapsu_profilesync_token' );
delete_transient( 'biapsu_profilesync_test_result' );

$meta_keys = array(
	'biapsu_sync_state',
	'biapsu_sync_email',
	'biapsu_sync_return',
	'biapsu_sync_error',
	'biapsu_synced_at',
	'biapsu_phone_number',
	'biapsu_affiliation',
	'biapsu_department',
	'biapsu_position',
	'biapsu_location',
	'biapsu_user_type',
	'biapsu_user_type_description',
	'biapsu_join_reason',
);

foreach ( $meta_keys as $key ) {
	delete_metadata( 'user', 0, $key, '', true );
}
