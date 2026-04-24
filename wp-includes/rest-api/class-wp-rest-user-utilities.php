<?php
/**
 * REST API: WP_REST_User_Utilities class
 *
 * @package WordPress
 * @subpackage REST_API
 * @since 6.8.0
 */

/**
 * Shared utility methods for user-related REST API controllers.
 *
 * Provides common user validation, resolution, and permission checking
 * functionality used across multiple REST API endpoint controllers that
 * operate on user resources.
 *
 * @since 6.8.0
 */
class WP_REST_User_Utilities {

	/**
	 * Validates a user ID and returns the corresponding WP_User object.
	 *
	 * Checks that the ID is a positive integer, that the user exists,
	 * and that the user is a member of the current site on multisite
	 * installations.
	 *
	 * @since 6.8.0
	 *
	 * @param int  $user_id                  User ID to validate.
	 * @param bool $allow_super_admin_bypass  Optional. Whether super admins bypass
	 *                                        the multisite blog membership check.
	 *                                        Default false.
	 * @return WP_User|WP_Error WP_User on success, WP_Error on failure.
	 */
	public static function validate_user_id( $user_id, $allow_super_admin_bypass = false ) {
		$error = new WP_Error(
			'rest_user_invalid_id',
			__( 'Invalid user ID.' ),
			array( 'status' => 404 )
		);

		if ( (int) $user_id <= 0 ) {
			return $error;
		}

		$user = get_userdata( (int) $user_id );

		if ( empty( $user ) || ! $user->exists() ) {
			return $error;
		}

		if ( ! self::check_multisite_membership( $user, $allow_super_admin_bypass ) ) {
			return $error;
		}

		return $user;
	}

	/**
	 * Resolves the current user for "me" endpoint requests.
	 *
	 * Returns the current authenticated user, or a WP_Error if the
	 * request is not authenticated.
	 *
	 * @since 6.8.0
	 *
	 * @return WP_User|WP_Error WP_User on success, WP_Error if not logged in.
	 */
	public static function resolve_current_user() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'You are not currently logged in.' ),
				array( 'status' => 401 )
			);
		}

		return wp_get_current_user();
	}

	/**
	 * Checks whether a user is a member of the current blog on multisite.
	 *
	 * On single-site installations, always returns true. On multisite,
	 * optionally allows super administrators to bypass the membership check.
	 *
	 * @since 6.8.0
	 *
	 * @param WP_User $user                      The user to check.
	 * @param bool    $allow_super_admin_bypass   Optional. Whether super admins
	 *                                            bypass the membership check.
	 *                                            Default false.
	 * @return bool True if the user passes the membership check.
	 */
	public static function check_multisite_membership( $user, $allow_super_admin_bypass = false ) {
		if ( ! is_multisite() ) {
			return true;
		}

		if ( $allow_super_admin_bypass && user_can( $user->ID, 'manage_sites' ) ) {
			return true;
		}

		return is_user_member_of_blog( $user->ID );
	}

	/**
	 * Checks whether the current user has a given capability, returning
	 * a WP_Error if the check fails.
	 *
	 * Convenience wrapper around current_user_can() that builds the
	 * appropriate WP_Error response for REST API permission callbacks.
	 *
	 * @since 6.8.0
	 *
	 * @param string $capability The capability to check.
	 * @param string $error_code WP_Error code to use on failure.
	 * @param string $message    Human-readable error message.
	 * @param mixed  ...$args    Optional. Additional arguments passed to current_user_can().
	 * @return true|WP_Error True if the user has the capability, WP_Error otherwise.
	 */
	public static function check_permission( $capability, $error_code, $message, ...$args ) {
		if ( current_user_can( $capability, ...$args ) ) {
			return true;
		}

		return new WP_Error(
			$error_code,
			$message,
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Checks whether the current user has a specific capability.
	 *
	 * A thin wrapper around current_user_can() for use in REST API
	 * permission callbacks that return boolean values rather than
	 * WP_Error objects.
	 *
	 * @since 6.8.0
	 *
	 * @param string $capability The capability to check.
	 * @param mixed  ...$args    Optional. Additional arguments passed to current_user_can().
	 * @return bool True if the current user has the capability.
	 */
	public static function has_capability( $capability, ...$args ) {
		return current_user_can( $capability, ...$args );
	}
}
