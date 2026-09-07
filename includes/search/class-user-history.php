<?php
namespace WPTS\Search;

defined( 'ABSPATH' ) || exit;

/**
 * Manages search history and bookmarked favorites for logged-in WordPress users.
 */
class UserHistory {

	public const META_HISTORY   = 'wpts_search_history';
	public const META_FAVORITES = 'wpts_search_favorites';

	/**
	 * Record a search query to the user's persisted search history.
	 */
	public static function add_history( int $user_id, string $query ): void {
		if ( $user_id <= 0 || '' === trim( $query ) ) {
			return;
		}

		$query   = sanitize_text_field( trim( $query ) );
		$history = (array) get_user_meta( $user_id, self::META_HISTORY, true );

		// Remove if existing to move to top
		$history = array_values( array_filter( $history, fn( $item ) => ( $item['q'] ?? '' ) !== $query ) );

		array_unshift( $history, [
			'q'  => $query,
			'ts' => current_time( 'timestamp' ),
		] );

		// Keep top 20 searches
		$history = array_slice( $history, 0, 20 );
		update_user_meta( $user_id, self::META_HISTORY, $history );
	}

	/**
	 * Retrieve a user's search history.
	 *
	 * @return array<int, array{q: string, ts: int}>
	 */
	public static function get_history( int $user_id, int $limit = 10 ): array {
		if ( $user_id <= 0 ) {
			return [];
		}
		$history = (array) get_user_meta( $user_id, self::META_HISTORY, true );
		return array_slice( $history, 0, $limit );
	}

	/**
	 * Clear search history for a user.
	 */
	public static function clear_history( int $user_id ): void {
		if ( $user_id > 0 ) {
			delete_user_meta( $user_id, self::META_HISTORY );
		}
	}

	/**
	 * Toggle a search query as a user favorite/bookmark.
	 *
	 * @return bool True if now favorited, false if removed.
	 */
	public static function toggle_favorite( int $user_id, string $query ): bool {
		if ( $user_id <= 0 || '' === trim( $query ) ) {
			return false;
		}

		$query     = sanitize_text_field( trim( $query ) );
		$favorites = (array) get_user_meta( $user_id, self::META_FAVORITES, true );

		$found_index = -1;
		foreach ( $favorites as $idx => $fav ) {
			if ( ( $fav['q'] ?? '' ) === $query ) {
				$found_index = $idx;
				break;
			}
		}

		if ( $found_index >= 0 ) {
			// Remove favorite
			unset( $favorites[ $found_index ] );
			$favorites = array_values( $favorites );
			update_user_meta( $user_id, self::META_FAVORITES, $favorites );
			return false;
		}

		// Add favorite
		array_unshift( $favorites, [
			'q'  => $query,
			'ts' => current_time( 'timestamp' ),
		] );
		update_user_meta( $user_id, self::META_FAVORITES, $favorites );
		return true;
	}

	/**
	 * Retrieve all favorite queries for a user.
	 *
	 * @return array<int, array{q: string, ts: int}>
	 */
	public static function get_favorites( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return [];
		}
		return (array) get_user_meta( $user_id, self::META_FAVORITES, true );
	}
}

