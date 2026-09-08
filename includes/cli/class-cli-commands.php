<?php
namespace WPTS\CLI;

defined( 'ABSPATH' ) || exit;

/**
 * WP-CLI commands for Turbo Search.
 *
 * All commands are namespaced under `wp turbo-search`. Run `wp turbo-search` with no
 * arguments (or `wp help wpts`) to see the full list.
 */
class Commands {

	/**
	 * Render tabular output with an explicit border style.
	 *
	 * `\WP_CLI\Utils\format_items('table', ...)` delegates to the
	 * `cli\Table` library, whose default renderer varies by environment
	 * and version - some setups fall back to plain space-separated columns
	 * instead of the classic `+----+----+` bordered box style. We force
	 * the ASCII renderer explicitly here so output is always consistent,
	 * matching standard MySQL CLI / WP-CLI table output everywhere.
	 *
	 * Non-table formats (json/yaml/csv) pass straight through to WP-CLI's
	 * normal formatter, which handles those correctly already.
	 *
	 * @param string $format Output format: table|json|yaml|csv.
	 * @param array  $rows   Associative rows, keyed by column name.
	 * @param array  $fields Column names, in display order.
	 */
	private function render_table( string $format, array $rows, array $fields ): void {
		if ( 'table' !== $format ) {
			\WP_CLI\Utils\format_items( $format, $rows, $fields );
			return;
		}

		$table = new \cli\Table();
		$table->setRenderer( new \cli\table\Ascii() );
		$table->setHeaders( $fields );

		foreach ( $rows as $row ) {
			$line = [];
			foreach ( $fields as $field ) {
				$line[] = $row[ $field ] ?? '';
			}
			$table->addRow( $line );
		}

		$table->display();
	}

	/**
	 * Re-index posts into the active search engine (MySQL or Typesense).
	 *
	 * Processes posts in batches (same chunking logic as the admin UI's
	 * "Re-index All Posts" button) or indexes an individual post by ID.
	 *
	 * ## OPTIONS
	 *
	 * [--id=<number>]
	 * : Re-index a single specific post, page, product, or media attachment by its ID.
	 *
	 * [<id>]
	 * : Optional post ID to index.
	 *
	 * [--batch=<number>]
	 * : Posts processed per batch. Larger batches are faster but use more
	 *   memory. Default 100.
	 * ---
	 * default: 100
	 * ---
	 *
	 * [--batch-size=<number>]
	 * : Alias for --batch.
	 *
	 * [--quiet]
	 * : Suppress output.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     # Re-index all posts with default batch size
	 *     $ wp turbo-search reindex
	 *
	 *     # Re-index a single post or document by ID
	 *     $ wp turbo-search reindex --id=17
	 *     $ wp turbo-search index 17
	 *
	 *     # Re-index with a larger batch size for faster completion
	 *     $ wp turbo-search reindex --batch=200
	 *
	 *     # Skip confirmation (useful in deploy scripts / cron)
	 *     $ wp turbo-search reindex --yes
	 *
	 * @when after_wp_load
	 */
	public function reindex( array $args, array $assoc_args ): void {
		$single_id = absint( $assoc_args['id'] ?? ( ! empty( $args[0] ) && is_numeric( $args[0] ) ? $args[0] : 0 ) );

		if ( $single_id > 0 ) {
			$post = get_post( $single_id );
			if ( ! ( $post instanceof \WP_Post ) ) {
				\WP_CLI::error( sprintf( 'Post or Document #%d not found in database.', $single_id ) );
				return;
			}
			\WPTS\Installer::ensure_tables();
			$core     = \WPTS\Core::instance();
			$engine   = $core->get_engine();
			$document = $core->build_document( $post );
			if ( ! $document ) {
				\WP_CLI::error( sprintf( 'Item #%d (status: %s) is not eligible for indexing.', $single_id, $post->post_status ) );
				return;
			}
			$ok = $engine->upsert( $document );
			if ( $ok ) {
				$doc_bytes = ! empty( $document['attached_docs'] ) ? strlen( (string) $document['attached_docs'] ) : 0;
				\WP_CLI::success( sprintf(
					'Indexed single item #%d: "%s" (%s) into %s search engine!%s',
					$single_id,
					$post->post_title ?: 'Untitled',
					$post->post_type,
					strtoupper( $engine->get_engine_driver() ),
					$doc_bytes > 0 ? sprintf( ' [Extracted %d bytes from attached/embedded document]', $doc_bytes ) : ''
				) );
				return;
			}
			\WP_CLI::error( sprintf( 'Failed to write Item #%d to search engine index.', $single_id ) );
			return;
		}

		$batch = max( 5, min( 500, (int) ( $assoc_args['batch'] ?? $assoc_args['batch-size'] ?? 50 ) ) );

		\WP_CLI::confirm(
			'This will re-index ALL published posts into the search index. Continue?',
			$assoc_args
		);

		$start_time = microtime( true );
		\WPTS\Installer::ensure_tables();
		$engine      = \WPTS\Core::instance()->get_engine();
		$engine_name = $engine->get_engine_driver();

		$post_types = \WPTS\Admin\Settings::get( 'post_types', [ 'post', 'page' ] );
		$post_types = ( is_array( $post_types ) && ! empty( $post_types ) ) ? array_values( $post_types ) : [ 'post', 'page' ];
		if ( ! empty( \WPTS\Admin\Settings::get( 'index_attachments' ) ) && ! in_array( 'attachment', $post_types, true ) ) {
			$post_types[] = 'attachment';
		}

		$initial_count = $engine->get_indexed_count();
		\WP_CLI::log( sprintf( '🔍 Search Engine: %s | Post Types: [%s] | Batch Size: %d', strtoupper( $engine_name ), implode( ', ', $post_types ), $batch ) );
		\WP_CLI::log( sprintf( '📊 Current documents in index before run: %d', $initial_count ) );

		$page     = 1;
		$total    = 0;
		$bar      = null;
		$is_quiet = isset( $assoc_args['quiet'] );

		do {
			$result = $engine->reindex_chunk( $page, $batch );

			if ( null === $bar ) {
				$total_items = (int) ( $result['total'] ?? 0 );
				if ( $total_items > 0 ) {
					\WP_CLI::log( sprintf( '🚀 Found %d published item(s) to index across %d total batch(es)...', $total_items, (int) ( $result['max_pages'] ?? 1 ) ) );
					if ( ! $is_quiet ) {
						$bar = \WP_CLI\Utils\make_progress_bar( 'Indexing documents', $total_items );
					}
				} else {
					\WP_CLI::warning( sprintf( 'No published items found matching post types [%s]. Make sure content is published.', implode( ', ', $post_types ) ) );
					break;
				}
			}

			$processed = (int) ( $result['processed'] ?? 0 );
			$max_pages = (int) ( $result['max_pages'] ?? 1 );

			if ( $bar && $processed > 0 ) {
				for ( $i = 0; $i < $processed; $i++ ) {
					$bar->tick();
				}
			}

			$total += $processed;
			\WP_CLI::log( sprintf( '  ↳ [Batch %d/%d] Indexed %d posts (Total processed so far: %d/%d)', $page, $max_pages, $processed, $total, (int) ( $result['total'] ?? $total ) ) );

			$page++;

		} while ( ! ( $result['done'] ?? true ) );

		if ( $bar ) {
			$bar->finish();
		}

		$elapsed       = round( microtime( true ) - $start_time, 2 );
		$indexed_count = $engine->get_indexed_count();

		\WP_CLI::success( sprintf(
			'Re-indexing complete in %ss! %d posts processed. Database now contains %d indexed documents.',
			$elapsed,
			$total,
			$indexed_count
		) );
	}

	/**
	 * Index a single specific post, page, product, or attachment by ID.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The WordPress Post or Document ID to index.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp turbo-search index 17
	 *
	 * @when after_wp_load
	 */
	public function index( array $args, array $assoc_args ): void {
		$id = absint( $args[0] ?? 0 );
		if ( ! $id ) {
			\WP_CLI::error( 'Please provide a valid Post or Document ID: wp turbo-search index <id>' );
			return;
		}
		$this->reindex( [ $id ], [ 'id' => $id, 'yes' => true ] );
	}

	/**
	 * Flush the search-result cache (Redis / Memcached / WP Object Cache / Transients).
	 *
	 * Does NOT touch the search index itself - use `wp turbo-search flush-index`
	 * to clear indexed documents instead.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp turbo-search flush-cache
	 *     $ wp turbo-search flush-cache --yes
	 *
	 * @when after_wp_load
	 */
	public function flush_cache( array $args, array $assoc_args ): void {
		\WP_CLI::confirm( 'Flush all cached search results?', $assoc_args );

		$engine = \WPTS\Core::instance()->get_engine();
		$driver = $engine->get_driver();
		$engine->flush_all();

		\WP_CLI::success( sprintf( 'Cache flushed (driver: %s).', $driver ) );
	}

	/**
	 * Flush the search index itself - deletes all indexed documents.
	 *
	 * For MySQL: truncates the wpts_index table.
	 * For Typesense: drops and lets the collection auto-recreate on next use.
	 * WordPress posts are never affected - only the search index is cleared.
	 * Run `wp turbo-search reindex` afterward to rebuild it.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp turbo-search flush-index --yes
	 *
	 * @when after_wp_load
	 */
	public function flush_index( array $args, array $assoc_args ): void {
		\WP_CLI::confirm(
			'This will PERMANENTLY delete every document from the search index. WordPress posts are NOT affected. Continue?',
			$assoc_args
		);

		$engine      = \WPTS\Core::instance()->get_engine();
		$engine_name = $engine->get_engine_driver();
		$success     = $engine->flush_index();

		if ( ! $success ) {
			\WP_CLI::error( 'Failed to flush the search index. Check the debug log for details.' );
			return;
		}

		\WP_CLI::success( sprintf( 'Search index flushed (engine: %s). Run `wp turbo-search reindex` to rebuild.', $engine_name ) );
	}

	/**
	 * Show the plugin's current status - engine, cache driver, index health.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp turbo-search status
	 *     $ wp turbo-search status --format=json
	 *
	 * @when after_wp_load
	 */
	public function status( array $args, array $assoc_args ): void {
		\WPTS\Installer::ensure_tables();
		$engine   = \WPTS\Core::instance()->get_engine();
		$settings = \WPTS\Admin\Settings::get_all();

		$post_types = (array) ( $settings['post_types'] ?? [ 'post', 'page' ] );
		if ( ! empty( $settings['index_attachments'] ) && ! in_array( 'attachment', $post_types, true ) ) {
			$post_types[] = 'attachment';
		}

		$total_pub  = 0;
		foreach ( $post_types as $pt ) {
			$counts = wp_count_posts( $pt );
			if ( 'attachment' === $pt ) {
				$total_pub += (int) ( $counts->inherit ?? 0 );
			} else {
				$total_pub += (int) ( $counts->publish ?? 0 );
			}
		}

		$indexed  = $engine->get_indexed_count();
		$coverage = $total_pub > 0 ? round( $indexed / $total_pub * 100, 1 ) : 0;

		$rows = [
			[ 'Field' => 'Search engine',        'Value' => $engine->get_engine_driver() ],
			[ 'Field' => 'Cache driver',          'Value' => $engine->get_driver() ],
			[ 'Field' => 'Indexed documents',     'Value' => number_format( $indexed ) ],
			[ 'Field' => 'Published posts',       'Value' => number_format( $total_pub ) ],
			[ 'Field' => 'Coverage',              'Value' => $coverage . '%' ],
			[ 'Field' => 'Tracked post types',    'Value' => implode( ', ', $post_types ) ],
			[ 'Field' => 'Tracking enabled',      'Value' => ! empty( $settings['tracking_enabled'] ) ? 'yes' : 'no' ],
			[ 'Field' => 'Frontend search',       'Value' => ! empty( $settings['enable_frontend_search'] ) ? 'enabled' : 'disabled' ],
		];

		if ( 'typesense' === $engine->get_engine_driver() ) {
			$typesense = new \WPTS\Cache\Typesense( $settings );
			$ts_status = $typesense->get_status();
			$rows[]    = [ 'Field' => 'Typesense reachable', 'Value' => ! empty( $ts_status['reachable'] ) ? 'yes' : 'no' ];
			$rows[]    = [ 'Field' => 'Typesense collection', 'Value' => $ts_status['collection'] ?? ' - ' ];
		}

		$this->render_table(
			$assoc_args['format'] ?? 'table',
			$rows,
			[ 'Field', 'Value' ]
		);
	}

	/**
	 * Run a test search from the command line and print the results.
	 *
	 * Useful for verifying the search engine returns expected results
	 * without needing to open a browser - especially handy for confirming
	 * Typesense connectivity after a configuration change.
	 *
	 * ## OPTIONS
	 *
	 * <query>
	 * : The search term to look up.
	 *
	 * [--per-page=<number>]
	 * : Number of results to return.
	 * ---
	 * default: 10
	 * ---
	 *
	 * [--post-type=<type>]
	 * : Restrict results to a specific post type.
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp turbo-search search "hello world"
	 *     $ wp turbo-search search "hello world" --per-page=5 --post-type=post
	 *
	 * @when after_wp_load
	 */
	public function search( array $args, array $assoc_args ): void {
		$query    = $args[0] ?? '';
		$per_page = max( 1, min( 100, (int) ( $assoc_args['per-page'] ?? 10 ) ) );
		$filters  = [];

		if ( ! empty( $assoc_args['post-type'] ) ) {
			$filters['post_type'] = sanitize_key( $assoc_args['post-type'] );
		}

		if ( '' === $query ) {
			\WP_CLI::error( 'Please provide a search query, e.g. `wp turbo-search search "hello world"`.' );
			return;
		}

		$engine = \WPTS\Core::instance()->get_engine();
		$start  = microtime( true );
		$result = $engine->search( $query, $filters, $per_page, 1 );
		$ms     = round( ( microtime( true ) - $start ) * 1000, 1 );

		\WP_CLI::log( sprintf(
			'Found %d result(s) for "%s" in %sms (engine: %s)',
			$result['found'] ?? 0,
			$query,
			$ms,
			$engine->get_engine_driver()
		) );

		if ( empty( $result['hits'] ) ) {
			return;
		}

		$rows = [];
		foreach ( $result['hits'] as $hit ) {
			$rows[] = [
				'ID'    => $hit['post_id']   ?? '',
				'Title' => wp_strip_all_tags( (string) ( $hit['title'] ?? '' ) ),
				'Type'  => $hit['post_type'] ?? '',
				'URL'   => $hit['url']       ?? '',
			];
		}

		$this->render_table(
			$assoc_args['format'] ?? 'table',
			$rows,
			[ 'ID', 'Title', 'Type', 'URL' ]
		);
	}

	/**
	 * Show cumulative search & indexing statistics (same data as the
	 * Dashboard admin page KPI cards).
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp turbo-search stats
	 *
	 * @when after_wp_load
	 */
	public function stats( array $args, array $assoc_args ): void {
		global $wpdb;

		$engine  = \WPTS\Core::instance()->get_engine();
		$indexed = $engine->get_indexed_count();

		$log_table  = $wpdb->prefix . 'wpts_search_log';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB
		$log_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $log_table ) );

		$total_searches = $cache_hits = $cache_misses = $zero_results = 0;
		if ( $log_exists ) {
			$row = $wpdb->get_row(
				"SELECT COUNT(*) AS t, SUM(from_cache=1) AS h, SUM(from_cache=0) AS m, SUM(results=0) AS z
				 FROM `{$log_table}`",
				ARRAY_A
			);
			if ( $row ) {
				$total_searches = (int) $row['t'];
				$cache_hits     = (int) $row['h'];
				$cache_misses   = (int) $row['m'];
				$zero_results   = (int) $row['z'];
			}
		}

		$evt_table  = $wpdb->prefix . 'wpts_events';
		$evt_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $evt_table ) );

		$upserts = $deletes = $flushes = 0;
		if ( $evt_exists ) {
			$evts = $wpdb->get_results(
				"SELECT event_type, COUNT(*) AS cnt FROM `{$evt_table}`
				 WHERE event_type IN ('index_upsert','index_delete','cache_flush_all')
				 GROUP BY event_type",
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB
			foreach ( (array) $evts as $e ) {
				if     ( 'index_upsert'    === $e['event_type'] ) $upserts = (int) $e['cnt'];
				elseif ( 'index_delete'    === $e['event_type'] ) $deletes = (int) $e['cnt'];
				elseif ( 'cache_flush_all' === $e['event_type'] ) $flushes = (int) $e['cnt'];
			}
		}

		$total    = $cache_hits + $cache_misses;
		$hit_rate = $total > 0 ? round( $cache_hits / $total * 100, 1 ) : 0;

		$rows = [
			[ 'Metric' => 'Indexed documents (live)',      'Value' => number_format( $indexed ) ],
			[ 'Metric' => 'Total searches (all-time)',      'Value' => number_format( $total_searches ) ],
			[ 'Metric' => 'Cache hit rate',                 'Value' => $hit_rate . '%' ],
			[ 'Metric' => 'Cache hits',                     'Value' => number_format( $cache_hits ) ],
			[ 'Metric' => 'Cache misses',                   'Value' => number_format( $cache_misses ) ],
			[ 'Metric' => 'Zero-result searches',           'Value' => number_format( $zero_results ) ],
			[ 'Metric' => 'Posts indexed (all-time events)','Value' => number_format( $upserts ) ],
			[ 'Metric' => 'Posts removed (all-time events)','Value' => number_format( $deletes ) ],
			[ 'Metric' => 'Cache flushes (all-time)',       'Value' => number_format( $flushes ) ],
		];

		$this->render_table(
			$assoc_args['format'] ?? 'table',
			$rows,
			[ 'Metric', 'Value' ]
		);
	}

	/**
	 * Get or set plugin settings from the command line.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : Action to perform.
	 * ---
	 * options:
	 *   - get
	 *   - set
	 *   - list
	 * ---
	 *
	 * [<key>]
	 * : Setting key (required for get/set).
	 *
	 * [<value>]
	 * : Setting value (required for set). Booleans: use "1"/"0" or "true"/"false".
	 *
	 * ## EXAMPLES
	 *
	 *     # List every setting and its current value
	 *     $ wp turbo-search settings list
	 *
	 *     # Get a single setting
	 *     $ wp turbo-search settings get debounce_ms
	 *
	 *     # Change a setting
	 *     $ wp turbo-search settings set debounce_ms 300
	 *     $ wp turbo-search settings set tracking_enabled 0
	 *
	 * @when after_wp_load
	 */
	public function settings( array $args, array $assoc_args ): void {
		$action = $args[0] ?? '';
		$key    = $args[1] ?? '';
		$value  = $args[2] ?? null;

		switch ( $action ) {
			case 'list':
				$all  = \WPTS\Admin\Settings::get_all();
				$rows = [];
				foreach ( $all as $k => $v ) {
					$rows[] = [
						'Key'   => $k,
						'Value' => is_array( $v ) ? implode( ',', $v ) : (string) $v,
					];
				}
				$this->render_table( $assoc_args['format'] ?? 'table', $rows, [ 'Key', 'Value' ] );
				break;

			case 'get':
				if ( '' === $key ) {
					\WP_CLI::error( 'Please provide a setting key, e.g. `wp turbo-search settings get debounce_ms`.' );
					return;
				}
				$val = \WPTS\Admin\Settings::get( $key );
				if ( null === $val ) {
					\WP_CLI::error( sprintf( 'Unknown setting key: %s', $key ) );
					return;
				}
				\WP_CLI::log( is_array( $val ) ? implode( ',', $val ) : (string) $val );
				break;

			case 'set':
				if ( '' === $key || null === $value ) {
					\WP_CLI::error( 'Please provide both a key and a value, e.g. `wp turbo-search settings set debounce_ms 300`.' );
					return;
				}

				// Normalize common boolean-ish CLI input. Settings::save()
				// eventually casts booleans via (bool) $value - but PHP
				// treats any non-empty string (including the literal text
				// "false") as truthy, so `--value=false` would otherwise
				// be silently saved as TRUE. Handle the common spellings
				// explicitly so CLI usage matches user expectation.
				$normalized = $value;
				if ( in_array( strtolower( (string) $value ), [ 'false', 'no', 'off' ], true ) ) {
					$normalized = '0';
				} elseif ( in_array( strtolower( (string) $value ), [ 'true', 'yes', 'on' ], true ) ) {
					$normalized = '1';
				}

				\WPTS\Admin\Settings::save( [ $key => $normalized ] );
				\WP_CLI::success( sprintf( '%s set to: %s', $key, $normalized ) );
				break;

			default:
				\WP_CLI::error( 'Action must be one of: get, set, list.' );
		}
	}

	/**
	 * Flush and wipe search tracking and analytics logs.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<number>]
	 * : Optional retention cutoff in days. If omitted, all analytics logs are truncated.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     # Wipe all tracking and analytics logs
	 *     $ wp turbo-search flush-tracking --yes
	 *
	 *     # Prune logs older than 30 days
	 *     $ wp turbo-search flush-tracking --days=30 --yes
	 *
	 * @when after_wp_load
	 */
	public function flush_tracking( array $args, array $assoc_args ): void {
		\WP_CLI::confirm(
			'This will delete search tracking logs and analytics data. Continue?',
			$assoc_args
		);

		$tracker = \WPTS\Tracker::instance();
		if ( isset( $assoc_args['days'] ) ) {
			$days = max( 1, (int) $assoc_args['days'] );
			$tracker->prune( $days );
			\WP_CLI::success( sprintf( 'Pruned search tracking logs older than %d days.', $days ) );
		} else {
			$tracker->reset_counters();
			\WP_CLI::success( 'All search tracking logs and analytics data have been flushed.' );
		}
	}

	/**
	 * Prune search tracking analytics logs older than N days (alias for flush-tracking --days=N).
	 *
	 * ## OPTIONS
	 *
	 * [--days=<number>]
	 * : Number of days of analytics data to retain. Default 30.
	 * ---
	 * default: 30
	 * ---
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp turbo-search prune-logs --days=30
	 *     $ wp turbo-search prune-logs --days=30 --yes
	 *
	 * @when after_wp_load
	 */
	public function prune_logs( array $args, array $assoc_args ): void {
		if ( ! isset( $assoc_args['days'] ) ) {
			$assoc_args['days'] = 30;
		}
		$this->flush_tracking( $args, $assoc_args );
	}

	/**
	 * Run automated system health checks and diagnostics.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp turbo-search health
	 *     $ wp turbo-search health --format=json
	 *
	 * @when after_wp_load
	 */
	public function health( array $args, array $assoc_args ): void {
		\WPTS\Installer::ensure_tables();

		$engine_test = \WPTS\Admin\SiteHealth::test_engine_status();
		$index_test  = \WPTS\Admin\SiteHealth::test_index_coverage();

		$rows = [
			[
				'Check'   => 'Search Engine Status',
				'Status'  => 'good' === $engine_test['status'] ? 'PASS' : 'FAIL',
				'Details' => $engine_test['label'],
			],
			[
				'Check'   => 'Index Coverage',
				'Status'  => 'good' === $index_test['status'] ? 'PASS' : ( 'recommended' === $index_test['status'] ? 'WARN' : 'FAIL' ),
				'Details' => $index_test['label'],
			],
		];

		// Database Tables Check
		global $wpdb;
		$tables = [
			$wpdb->prefix . 'wpts_index',
			$wpdb->prefix . 'wpts_search_log',
			$wpdb->prefix . 'wpts_synonyms',
			$wpdb->prefix . 'wpts_analytics_summary',
		];
		$missing = [];
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB
		foreach ( $tables as $tbl ) {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) );
			if ( ! $exists ) $missing[] = $tbl;
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB

		$rows[] = [
			'Check'   => 'Database Tables',
			'Status'  => empty( $missing ) ? 'PASS' : 'FAIL',
			'Details' => empty( $missing ) ? 'All 4 required tables exist' : 'Missing: ' . implode( ', ', $missing ),
		];

		$this->render_table(
			$assoc_args['format'] ?? 'table',
			$rows,
			[ 'Check', 'Status', 'Details' ]
		);

		if ( 'good' === $engine_test['status'] && empty( $missing ) ) {
			\WP_CLI::success( 'System health check completed. All critical checks passed.' );
		} else {
			\WP_CLI::warning( 'System health check detected issues requiring attention.' );
		}
	}
}
