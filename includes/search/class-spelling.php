<?php
namespace WPTS\Search;

use WPTS\Universal\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Local spelling correction and "Did you mean...?" suggestion engine.
 * Zero external AI/API dependencies - uses vocabulary indexing + Levenshtein distance.
 */
class Spelling {

	/**
	 * Check if a query has a better spelling suggestion.
	 *
	 * @param string $query The user search query.
	 * @return string|null Suggested query string, or null if no suggestion.
	 */
	public static function suggest( string $query ): ?string {
		$clean = trim( $query );
		if ( '' === $clean || mb_strlen( $clean, 'UTF-8' ) < 3 ) {
			return null;
		}

		$tokens = Utils::tokenize( $clean );
		if ( empty( $tokens ) ) {
			return null;
		}

		$vocab = self::get_vocabulary();
		if ( empty( $vocab ) ) {
			return null;
		}

		$suggested_tokens = [];
		$has_changes      = false;

		foreach ( $tokens as $token ) {
			$token_lower = mb_strtolower( $token, 'UTF-8' );

			// If token already exists in vocabulary exactly, keep it
			if ( isset( $vocab[ $token_lower ] ) ) {
				$suggested_tokens[] = $token;
				continue;
			}

			// Find closest word in vocabulary
			$best_match    = null;
			$min_dist      = 999;
			$token_len     = mb_strlen( $token_lower, 'UTF-8' );
			$max_dist_allowed = ( $token_len <= 4 ) ? 1 : 2;

			// Prefix check: only compare with words having same first character if vocabulary is large
			$first_char = mb_substr( $token_lower, 0, 1, 'UTF-8' );

			foreach ( $vocab as $word => $freq ) {
				$word_len = mb_strlen( $word, 'UTF-8' );
				if ( abs( $word_len - $token_len ) > $max_dist_allowed ) {
					continue;
				}

				$dist = levenshtein( $token_lower, $word );
				if ( $dist <= $max_dist_allowed && $dist < $min_dist ) {
					$min_dist   = $dist;
					$best_match = $word;
				} elseif ( $dist === $min_dist && null !== $best_match ) {
					// Break ties by term frequency
					if ( ( $vocab[ $word ] ?? 0 ) > ( $vocab[ $best_match ] ?? 0 ) ) {
						$best_match = $word;
					}
				}
			}

			if ( null !== $best_match && $best_match !== $token_lower ) {
				$suggested_tokens[] = $best_match;
				$has_changes        = true;
			} else {
				$suggested_tokens[] = $token;
			}
		}

		if ( ! $has_changes ) {
			return null;
		}

		$suggestion = implode( ' ', $suggested_tokens );
		return ( strtolower( $suggestion ) !== strtolower( $clean ) ) ? $suggestion : null;
	}

	/**
	 * Retrieve or build the vocabulary word frequency map from indexed posts.
	 *
	 * @return array<string, int> Word => Frequency
	 */
	public static function get_vocabulary(): array {
		$cache_key = 'wpts_vocabulary_map';
		$vocab     = get_transient( $cache_key );

		if ( is_array( $vocab ) && ! empty( $vocab ) ) {
			return $vocab;
		}

		global $wpdb;
		if ( empty( $wpdb ) || ! is_object( $wpdb ) ) {
			return [];
		}
		$table  = $wpdb->prefix . 'wpts_index';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			return [];
		}

		// Fetch recent post titles and excerpts to build initial vocabulary
		$rows = $wpdb->get_results( "SELECT title, excerpt FROM `{$table}` LIMIT 1000", ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB
		$map  = [];

		foreach ( (array) $rows as $row ) {
			$text  = ( $row['title'] ?? '' ) . ' ' . ( $row['excerpt'] ?? '' );
			$words = Utils::tokenize( $text );
			foreach ( $words as $w ) {
				if ( mb_strlen( $w, 'UTF-8' ) >= 3 ) {
					$map[ $w ] = ( $map[ $w ] ?? 0 ) + 1;
				}
			}
		}

		// Also add popular search terms from log
		$log_table = $wpdb->prefix . 'wpts_search_log';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $log_table ) ) ) {
			$search_rows = $wpdb->get_results( "SELECT query FROM `{$log_table}` WHERE results > 0 LIMIT 500", ARRAY_A );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB
			foreach ( (array) $search_rows as $sr ) {
				$words = Utils::tokenize( $sr['query'] ?? '' );
				foreach ( $words as $w ) {
					if ( mb_strlen( $w, 'UTF-8' ) >= 3 ) {
						$map[ $w ] = ( $map[ $w ] ?? 0 ) + 2;
					}
				}
			}
		}

		set_transient( $cache_key, $map, 12 * HOUR_IN_SECONDS );
		return $map;
	}

	/**
	 * Clear the vocabulary cache (called on reindex).
	 */
	public static function flush_vocabulary(): void {
		delete_transient( 'wpts_vocabulary_map' );
	}
}

