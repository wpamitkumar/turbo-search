<?php
namespace WPTS;

use WPTS\Analytics\ABTesting;

defined( 'ABSPATH' ) || exit;

/**
 * Tracker - records searches, result clicks, and system events.
 */
class Tracker {

	public const TABLE_SUFFIX = 'wpts_events';
	public const TABLE_SUFFIX_SEARCH = 'wpts_search_log';

	private static ?Tracker $instance = null;
	private bool $enabled = false;
	private bool $tables_checked = false;
	private string $events_table;
	private string $search_table;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		global $wpdb;
		$this->events_table = $wpdb->prefix . self::TABLE_SUFFIX;
		$this->search_table = $wpdb->prefix . self::TABLE_SUFFIX_SEARCH;
		$this->enabled      = (bool) Admin\Settings::get( 'tracking_enabled', true );
	}

	public static function create_tables(): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		$events = $wpdb->prefix . self::TABLE_SUFFIX;
		$search = $wpdb->prefix . self::TABLE_SUFFIX_SEARCH;

		$wpdb->query( "
			CREATE TABLE IF NOT EXISTS `{$events}` (
			  `id`          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			  `event_type`  VARCHAR(32) NOT NULL,
			  `engine`      VARCHAR(20) NOT NULL DEFAULT '',
			  `cache_driver`VARCHAR(20) NOT NULL DEFAULT '',
			  `post_id`     BIGINT(20) UNSIGNED NULL,
			  `post_type`   VARCHAR(50) NOT NULL DEFAULT '',
			  `site_id`     BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
			  `lang`        VARCHAR(10) NOT NULL DEFAULT '',
			  `meta`        TEXT NULL,
			  `happened_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			  PRIMARY KEY (`id`),
			  KEY `event_type` (`event_type`),
			  KEY `happened_at` (`happened_at`),
			  KEY `post_id` (`post_id`),
			  KEY `site_id` (`site_id`)
			) ENGINE=InnoDB {$charset}
		" ); // phpcs:ignore

		$wpdb->query( "
			CREATE TABLE IF NOT EXISTS `{$search}` (
			  `id`               BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			  `query`            VARCHAR(500) NOT NULL,
			  `query_hash`       CHAR(32) NOT NULL,
			  `results`          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			  `from_cache`       TINYINT(1) NOT NULL DEFAULT 0,
			  `engine`           VARCHAR(20) NOT NULL DEFAULT '',
			  `cache_driver`     VARCHAR(20) NOT NULL DEFAULT '',
			  `post_type`        VARCHAR(50) NOT NULL DEFAULT '',
			  `lang`             VARCHAR(10) NOT NULL DEFAULT '',
			  `site_id`          BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
			  `duration_ms`      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			  `clicked_position` SMALLINT UNSIGNED NULL DEFAULT 0,
			  `variant`          CHAR(1) NOT NULL DEFAULT 'A',
			  `user_id`          BIGINT(20) UNSIGNED NULL DEFAULT 0,
			  `searched_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			  PRIMARY KEY (`id`),
			  KEY `query_hash`  (`query_hash`),
			  KEY `searched_at` (`searched_at`),
			  KEY `site_id`     (`site_id`),
			  KEY `from_cache`  (`from_cache`),
			  KEY `variant`     (`variant`)
			) ENGINE=InnoDB {$charset}
		" ); // phpcs:ignore
	}

	public function record_search(
		string $query,
		int    $result_count,
		bool   $from_cache,
		string $engine,
		string $cache_driver,
		string $post_type = '',
		string $lang      = '',
		int    $duration_ms = 0
	): int {
		if ( ! $this->enabled ) return 0;
		if ( ! $this->ensure_tracker_tables() ) return 0;

		global $wpdb;
		$variant = class_exists( '\WPTS\Analytics\ABTesting' ) ? ABTesting::get_current_variant() : 'A';
		$user_id = get_current_user_id();

		$wpdb->insert(
			$this->search_table,
			[
				'query'        => mb_substr( $query, 0, 500 ),
				'query_hash'   => md5( strtolower( trim( $query ) ) ),
				'results'      => $result_count,
				'from_cache'   => $from_cache ? 1 : 0,
				'engine'       => $engine,
				'cache_driver' => $cache_driver,
				'post_type'    => $post_type,
				'lang'         => $lang,
				'site_id'      => get_current_blog_id(),
				'duration_ms'  => $duration_ms,
				'variant'      => $variant,
				'user_id'      => $user_id,
				'searched_at'  => current_time( 'mysql' ),
			],
			[ '%s','%s','%d','%d','%s','%s','%s','%s','%d','%d','%s','%d','%s' ]
		);

		$insert_id = (int) $wpdb->insert_id;

		// Record history for logged-in users if enabled
		if ( $user_id > 0 && Admin\Settings::get( 'enable_user_history', true ) ) {
			Search\UserHistory::add_history( $user_id, $query );
		}

		do_action( 'wpts_search_tracked', $query, $result_count, $duration_ms, $from_cache );

		return $insert_id;
	}

	/**
	 * Record a click on a search result item.
	 */
	public function record_click( string $query, int $post_id, int $position ): bool {
		if ( ! $this->enabled ) return false;
		if ( ! $this->ensure_tracker_tables() ) return false;

		global $wpdb;
		$query_hash = md5( strtolower( trim( $query ) ) );

		// Update the most recent matching search row
		$recent_id = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore
			"SELECT id FROM {$this->search_table}
			 WHERE query_hash = %s AND site_id = %d AND (clicked_position IS NULL OR clicked_position = 0)
			 ORDER BY searched_at DESC LIMIT 1",
			$query_hash, get_current_blog_id()
		) );

		if ( $recent_id <= 0 ) {
			$recent_id = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore
				"SELECT id FROM {$this->search_table}
				 WHERE query_hash = %s AND site_id = %d
				 ORDER BY searched_at DESC LIMIT 1",
				$query_hash, get_current_blog_id()
			) );
		}

		// Resolve post_type from post_id if available
		$post_type = '';
		if ( $post_id > 0 && function_exists( 'get_post_type' ) ) {
			$found_type = get_post_type( $post_id );
			if ( ! empty( $found_type ) ) {
				$post_type = (string) $found_type;
			}
		}

		if ( $recent_id > 0 ) {
			$update_data    = [ 'clicked_position' => $position ];
			$update_formats = [ '%d' ];
			if ( ! empty( $post_type ) ) {
				$update_data['post_type'] = $post_type;
				$update_formats[]         = '%s';
			}
			$wpdb->update(
				$this->search_table,
				$update_data,
				[ 'id' => $recent_id ],
				$update_formats,
				[ '%d' ]
			);
		}

		$this->record_event( 'result_click', '', '', $post_id, $post_type, '', [ 'query' => $query, 'position' => $position ] );
		do_action( 'wpts_click_tracked', $query, $post_id, $position );
		return true;
	}

	public function record_event(
		string $event_type,
		string $engine       = '',
		string $cache_driver = '',
		int    $post_id      = 0,
		string $post_type    = '',
		string $lang         = '',
		array  $meta         = []
	): void {
		if ( ! $this->enabled ) return;
		if ( ! $this->ensure_tracker_tables() ) return;

		global $wpdb;
		$wpdb->insert(
			$this->events_table,
			[
				'event_type'  => $event_type,
				'engine'      => $engine,
				'cache_driver'=> $cache_driver,
				'post_id'     => $post_id ?: null,
				'post_type'   => $post_type,
				'site_id'     => get_current_blog_id(),
				'lang'        => $lang,
				'meta'        => $meta ? wp_json_encode( $meta ) : null,
				'happened_at' => current_time( 'mysql' ),
			],
			[ '%s','%s','%s','%d','%s','%d','%s','%s','%s' ]
		);
	}

	private function ensure_tracker_tables(): bool {
		if ( $this->tables_checked ) return true;
		global $wpdb;
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->search_table ) );
		if ( ! $exists ) {
			self::create_tables();
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->search_table ) );
		}
		$this->tables_checked = (bool) $exists;
		return $this->tables_checked;
	}

	public function top_queries( int $limit = 20, int $days = 30 ): array {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		return (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
			"SELECT query, COUNT(*) as search_count,
					AVG(results) as avg_results,
					SUM(from_cache) as cache_hits,
					AVG(duration_ms) as avg_ms
			 FROM {$this->search_table}
			 WHERE searched_at >= %s AND site_id = %d
			 GROUP BY query_hash
			 ORDER BY search_count DESC
			 LIMIT %d",
			$since, get_current_blog_id(), $limit
		), ARRAY_A );
	}

	public function zero_result_queries( int $limit = 20, int $days = 30 ): array {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		return (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
			"SELECT query, COUNT(*) as search_count, MAX(searched_at) as last_searched
			 FROM {$this->search_table}
			 WHERE searched_at >= %s AND results = 0 AND site_id = %d
			 GROUP BY query_hash
			 ORDER BY search_count DESC
			 LIMIT %d",
			$since, get_current_blog_id(), $limit
		), ARRAY_A );
	}

	public function daily_volume( int $days = 14 ): array {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		return (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
			"SELECT DATE(searched_at) as day,
					COUNT(*) as total,
					SUM(from_cache) as cached,
					COUNT(*) - SUM(from_cache) as uncached,
					SUM(CASE WHEN results = 0 THEN 1 ELSE 0 END) as zero_results
			 FROM {$this->search_table}
			 WHERE searched_at >= %s AND site_id = %d
			 GROUP BY DATE(searched_at)
			 ORDER BY day ASC",
			$since, get_current_blog_id()
		), ARRAY_A );
	}

	public function recent_index_events( int $limit = 30 ): array {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
			"SELECT e.*, p.post_title
			 FROM {$this->events_table} e
			 LEFT JOIN {$wpdb->posts} p ON p.ID = e.post_id
			 WHERE e.site_id = %d AND e.event_type IN ('index_upsert','index_delete','reindex','error')
			 ORDER BY e.happened_at DESC
			 LIMIT %d",
			get_current_blog_id(), $limit
		), ARRAY_A );
	}

	public function indexed_by_type(): array {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
			"SELECT post_type, COUNT(*) as cnt
			 FROM {$wpdb->prefix}wpts_index
			 WHERE site_id = %d
			 GROUP BY post_type
			 ORDER BY cnt DESC",
			get_current_blog_id()
		), ARRAY_A );
	}

	public function prune( int $days = 90 ): void {
		global $wpdb;
		$before = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->search_table} WHERE searched_at < %s", $before ) ); // phpcs:ignore
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->events_table} WHERE happened_at < %s", $before ) );  // phpcs:ignore
	}

	public function reset_counters(): void {
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE `{$this->search_table}`" ); // phpcs:ignore
		$wpdb->query( "TRUNCATE TABLE `{$this->events_table}`" ); // phpcs:ignore
		update_option( 'wpts_tracking_reset_at', current_time( 'mysql' ), false );
	}

	public function is_enabled(): bool {
		return $this->enabled;
	}
}
