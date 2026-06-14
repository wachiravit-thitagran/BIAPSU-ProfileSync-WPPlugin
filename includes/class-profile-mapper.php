<?php
/**
 * Maps a platform profile array onto a WordPress user.
 *
 * @package BIAPSU\ProfileSync
 */

namespace BIAPSU\ProfileSync;

defined( 'ABSPATH' ) || exit;

/**
 * Applies selected platform fields to a WP_User (core fields + user meta).
 */
class Profile_Mapper {

	/** Prefix for user meta written by this plugin. */
	const META_PREFIX = 'biapsu_';

	/**
	 * Settings store.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Apply a platform profile to a WP user according to enabled field groups.
	 *
	 * @param \WP_User             $user    Target user.
	 * @param array<string,mixed>  $profile Platform profile data.
	 * @return array<string,mixed> The changes that were applied (for logging).
	 */
	public function apply( \WP_User $user, array $profile ) {
		$fields  = $this->settings->get( 'fields' );
		$applied = array();

		$first = isset( $profile['first_name'] ) ? sanitize_text_field( $profile['first_name'] ) : '';
		$last  = isset( $profile['last_name'] ) ? sanitize_text_field( $profile['last_name'] ) : '';

		// --- Name group: core first/last/display name. ---
		if ( ! empty( $fields['name'] ) && ( '' !== $first || '' !== $last ) ) {
			$userdata = array( 'ID' => $user->ID );
			if ( '' !== $first ) {
				$userdata['first_name'] = $first;
			}
			if ( '' !== $last ) {
				$userdata['last_name'] = $last;
			}
			$display = trim( $first . ' ' . $last );
			if ( '' !== $display ) {
				$userdata['display_name'] = $display;
			}
			wp_update_user( $userdata );
			$applied['name'] = $display;
		}

		// --- Contact group. ---
		if ( ! empty( $fields['contact'] ) ) {
			if ( isset( $profile['phone_number'] ) && '' !== $profile['phone_number'] ) {
				$phone = sanitize_text_field( $profile['phone_number'] );
				update_user_meta( $user->ID, self::META_PREFIX . 'phone_number', $phone );
				$applied['phone_number'] = $phone;
			}
			// Only set email if the WP account has none usable (avoid hijacking).
			if ( isset( $profile['email'] ) ) {
				$email = sanitize_email( $profile['email'] );
				if ( '' !== $email && empty( $user->user_email ) ) {
					wp_update_user(
						array(
							'ID'         => $user->ID,
							'user_email' => $email,
						)
					);
					$applied['email'] = $email;
				}
			}
		}

		// --- Affiliation group. ---
		if ( ! empty( $fields['affiliation'] ) ) {
			foreach ( array( 'affiliation', 'department', 'position', 'location' ) as $key ) {
				if ( isset( $profile[ $key ] ) && '' !== $profile[ $key ] ) {
					$value = sanitize_text_field( $profile[ $key ] );
					update_user_meta( $user->ID, self::META_PREFIX . $key, $value );
					$applied[ $key ] = $value;
				}
			}
		}

		// --- User-type group. ---
		if ( ! empty( $fields['user_type'] ) ) {
			foreach ( array( 'user_type', 'user_type_description', 'join_reason' ) as $key ) {
				if ( isset( $profile[ $key ] ) && '' !== $profile[ $key ] ) {
					$value = sanitize_text_field( $profile[ $key ] );
					update_user_meta( $user->ID, self::META_PREFIX . $key, $value );
					$applied[ $key ] = $value;
				}
			}
		}

		// Record provenance.
		update_user_meta( $user->ID, self::META_PREFIX . 'synced_at', time() );

		/**
		 * Fires after platform data has been applied to a user.
		 *
		 * @param \WP_User $user    The updated user.
		 * @param array    $profile Raw platform profile.
		 * @param array    $applied Fields that were applied.
		 */
		do_action( 'biapsu_profilesync_applied', $user, $profile, $applied );

		return $applied;
	}
}
