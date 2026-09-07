<?php
namespace WPTS\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Enforces per-role search restrictions, hiding sensitive post types or content from specific user roles or guests.
 */
class RoleRestrictions {

	/**
	 * Retrieve the current user's primary role or 'guest'.
	 */
	public static function get_current_role(): string {
		if ( ! is_user_logged_in() ) {
			return 'guest';
		}
		$user = wp_get_current_user();
		return ! empty( $user->roles ) ? (string) $user->roles[0] : 'guest';
	}

	/**
	 * Check if a specific post type is allowed for the current user's role.
	 */
	public static function is_post_type_allowed( string $post_type ): bool {
		$role = self::get_current_role();
		if ( 'administrator' === $role ) {
			return true;
		}

		$restrictions = (array) \WPTS\Admin\Settings::get( 'role_restrictions', [] );
		$hidden_types = (array) ( $restrictions[ $role ] ?? [] );

		return ! in_array( $post_type, $hidden_types, true );
	}

	/**
	 * Filter post types list to only allowed types for the current user.
	 *
	 * @param string[] $post_types
	 * @return string[]
	 */
	public static function filter_allowed_post_types( array $post_types ): array {
		$allowed = array_values( array_filter( $post_types, [ self::class, 'is_post_type_allowed' ] ) );
		return (array) apply_filters( 'wpts_role_allowed_post_types', $allowed, self::get_current_role() );
	}
}

