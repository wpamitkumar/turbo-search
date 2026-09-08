<?php
namespace WPTS\Search;

defined( 'ABSPATH' ) || exit;

/**
 * Manages built-in and user-defined synonyms, expanding search queries automatically.
 */
class Synonyms {

	public const TABLE_SUFFIX = 'wpts_synonyms';

	/**
   * Built-in dictionary of common synonym sets.
   *
   * @var string[][]
   */
	private const BUILTIN = [
		[ 'car', 'automobile', 'auto', 'vehicle' ],
		[ 'phone', 'cellphone', 'mobile', 'smartphone' ],
		[ 'laptop', 'notebook', 'pc', 'computer' ],
		[ 'buy', 'purchase', 'order', 'checkout' ],
		[ 'store', 'shop', 'ecommerce', 'market' ],
		[ 'photo', 'picture', 'image', 'pic' ],
		[ 'movie', 'film', 'video', 'clip' ],
		[ 'fast', 'quick', 'rapid', 'speedy', 'turbo' ],
		[ 'doc', 'document', 'file', 'attachment', 'pdf' ],
		[ 'help', 'support', 'assistance', 'guide', 'manual' ],
		[ 'pricing', 'price', 'cost', 'fee', 'rate' ],
		[ 'discount', 'coupon', 'promo', 'deal', 'sale', 'voucher' ],
		[ 'app', 'application', 'software', 'program' ],
		[ 'client', 'customer', 'buyer', 'user' ],
		[ 'start', 'begin', 'launch', 'initiate' ],
		[ 'end', 'finish', 'complete', 'terminate' ],
		[ 'job', 'career', 'employment', 'position', 'vacancy' ],
		[ 'home', 'house', 'residence', 'apartment', 'property' ],
		[ 'contact', 'email', 'touch', 'reach', 'message' ],
		[ 'shipping', 'delivery', 'dispatch', 'freight' ],
	];

	/**
	 * Expand an array of search terms with all matched synonyms.
	 *
	 * @param string[] $terms
	 * @return string[]
	 */
	public static function expand_terms( array $terms ): array {
		if ( empty( $terms ) ) {
			return [];
		}

		$all_groups = self::get_all_groups();
		$expanded   = $terms;

		foreach ( $terms as $term ) {
			$term_lower = mb_strtolower( trim( $term ), 'UTF-8' );
			if ( '' === $term_lower ) {
				continue;
			}

			foreach ( $all_groups as $group ) {
				$group_lower = array_map( fn( $w ) => mb_strtolower( trim( $w ), 'UTF-8' ), $group );
				if ( in_array( $term_lower, $group_lower, true ) ) {
					foreach ( $group_lower as $syn ) {
						if ( '' !== $syn && ! in_array( $syn, $expanded, true ) ) {
							$expanded[] = $syn;
						}
					}
				}
			}
		}

		return array_values( array_unique( $expanded ) );
	}

	/**
	 * Retrieve all synonym groups (built-in + custom from database).
	 *
	 * @return string[][]
	 */
	public static function get_all_groups(): array {
		static $cached = null;
		if ( null !== $cached ) {
			return $cached;
		}

		$groups = self::BUILTIN;

		// Fetch custom from DB
		$custom = self::get_custom_synonyms();
		foreach ( $custom as $row ) {
			$words = preg_split( '/[\s,;|]+/u', (string) ( $row['words'] ?? '' ), -1, PREG_SPLIT_NO_EMPTY );
			if ( ! empty( $words ) && count( $words ) > 1 ) {
				$groups[] = array_map( 'trim', $words );
			}
		}

		$cached = apply_filters( 'wpts_synonym_groups', $groups );
		return $cached;
	}

	/**
	 * Fetch custom synonyms from database table.
	 */
	public static function get_custom_synonyms(): array {
		global $wpdb;
		if ( empty( $wpdb ) || ! is_object( $wpdb ) ) {
			return [];
		}
		$table = $wpdb->prefix . self::TABLE_SUFFIX;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			return (array) get_option( 'wpts_custom_synonyms_list', [] );
		}

		$results = $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY id DESC", ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB
		return is_array( $results ) ? $results : [];
	}

	/**
	 * Add or update a custom synonym pair/group.
	 */
	public static function save_custom_synonym( string $words, int $id = 0 ): bool {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_SUFFIX;
		$words = sanitize_text_field( trim( $words ) );

		if ( '' === $words ) {
			return false;
		}

		if ( $id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return false !== $wpdb->update(
				$table,
				[ 'words' => $words ],
				[ 'id' => $id ],
				[ '%s' ],
				[ '%d' ]
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false !== $wpdb->insert(
			$table,
			[ 'words' => $words ],
			[ '%s' ]
		);
	}

	/**
	 * Delete a custom synonym row.
	 */
	public static function delete_custom_synonym( int $id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_SUFFIX;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false !== $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );
	}

	/**
	 * Create DB table for synonyms.
	 */
	public static function create_table(): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$table   = $wpdb->prefix . self::TABLE_SUFFIX;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB
		$wpdb->query( "
			CREATE TABLE IF NOT EXISTS `{$table}` (
				`id`         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				`words`      TEXT NOT NULL,
				`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (`id`)
			) ENGINE=InnoDB {$charset}
		" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB
	}
}

