<?php
namespace WPTS\Cache;

use WPTS\Search\EngineInterface;

defined( 'ABSPATH' ) || exit;

require_once WPTS_DIR . 'includes/search/interface-engine.php';

/**
 * CacheManager - wraps any search engine (MySQL, Typesense, Elasticsearch)
 * with a pluggable result-cache layer (Redis, Memcached, WP Object Cache, Transients).
 */
class CacheManager {

	public const DRIVER_NONE      = 'none';
	public const DRIVER_TRANSIENT = 'transient';
	public const DRIVER_WP_CACHE  = 'wp_cache';
	public const DRIVER_MEMCACHED = 'memcached';
	public const DRIVER_REDIS     = 'redis';

	public const CACHE_GROUP = 'wpts_search';
	public const KEY_PREFIX  = 'wpts_';

	private string           $driver;
	private int              $ttl;
	private array            $settings;
	private ?\Redis          $redis     = null;
	private ?\Memcached      $memcached = null;
	private EngineInterface  $engine;
	private ?string          $last_error           = null;
	private bool             $last_fallback        = false;
	private ?string          $last_fallback_engine = null;

	public function __construct( EngineInterface $engine, array $settings ) {
		$this->engine   = $engine;
		$this->settings = $settings;
		$this->ttl      = absint( $settings['cache_ttl'] ?? 300 );
		$this->driver   = $this->resolve_driver( $settings['cache_driver'] ?? 'auto' );
		$this->connect();
	}

	public function search( string $query, array $filters = [], int $per_page = 10, int $page = 1 ): array {
		$start = microtime( true );

		// Try cache first
		$cache_key = $this->make_key( $query, $filters, $per_page, $page );
		$cached    = $this->cache_get( $cache_key );

		if ( false !== $cached && is_array( $cached ) && ! empty( $cached['hits'] ) ) {
			$duration = (int) round( ( microtime( true ) - $start ) * 1000 );
			$this->track_search( $query, $cached, true, $this->engine->get_driver(), $filters, $duration );
			do_action( 'wpts_cache_hit', $cache_key, $this->driver );
			return $cached;
		}

		// Run search via engine
		$results = null;
		try {
			$results = $this->engine->search( $query, $filters, $per_page, $page );
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[WPTS] Engine error: ' . $e->getMessage() ); // phpcs:ignore
			}
		}

		$actual_engine = $this->engine->get_driver();
		$is_mysql      = $this->engine instanceof MySQL;
		$has_result    = is_array( $results ) && ! empty( $results['hits'] );

		// Fallback to MySQL if primary remote engine failed or returned 0 hits
		if ( ! $has_result && ! $is_mysql ) {
			try {
				$fallback = new MySQL();
				$fallback_res = $fallback->search( $query, $filters, $per_page, $page );
				if ( ! empty( $fallback_res['hits'] ) ) {
					$results       = $fallback_res;
					$actual_engine = 'mysql';
					$has_result    = true;
					do_action( 'wpts_engine_fallback', 'mysql', get_class( $this->engine ) );
				}
			} catch ( \Throwable $e ) {
				// Ignore fallback error
			}
		}

		// Ultimate Fallback to native WordPress Core WP_Query if all engines failed or returned 0 hits
		if ( ! $has_result ) {
			try {
				if ( class_exists( '\WPTS\Search\WPCoreFallback' ) ) {
					$core_res = \WPTS\Search\WPCoreFallback::search( $query, $filters, $per_page, $page );
					if ( ! empty( $core_res['hits'] ) ) {
						$results       = $core_res;
						$actual_engine = 'wp_core';
						$has_result    = true;
					}
				}
			} catch ( \Throwable $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[WPTS] Core fallback error: ' . $e->getMessage() );
				}
			}
		}

		$results  = wp_parse_args( (array) $results, [ 'hits' => [], 'found' => 0, 'page' => $page ] );
		$duration = (int) round( ( microtime( true ) - $start ) * 1000 );

		// Cache only non-empty results so 0-result temporary states never pollute cache
		if ( $this->ttl > 0 && ! empty( $results['hits'] ) ) {
			$this->cache_set( $cache_key, $results, $this->ttl );
		}

		$this->track_search( $query, $results, false, $actual_engine, $filters, $duration );
		do_action( 'wpts_cache_miss', $cache_key, $this->driver );

		return $results;
	}

	public function upsert( array $document ): bool {
		$this->last_error           = null;
		$this->last_fallback        = false;
		$this->last_fallback_engine = null;

		$ok = false;
		try {
			$ok = $this->engine->upsert( $document );
			if ( ! $ok && method_exists( $this->engine, 'get_last_error' ) ) {
				$this->last_error = $this->engine->get_last_error();
			}
		} catch ( \Throwable $e ) {
			$ok               = false;
			$this->last_error = $e->getMessage();
		}

		// If remote engine failed, automatically fallback to MySQL so document is preserved
		if ( ! $ok && ! ( $this->engine instanceof MySQL ) ) {
			$primary_driver = $this->engine->get_driver();
			$primary_err    = $this->last_error ?: __( 'Remote engine was unreachable or returned an error.', 'turbo-search' );

			try {
				$mysql_engine = new MySQL();
				$fallback_ok  = $mysql_engine->upsert( $document );
				if ( $fallback_ok ) {
					$this->last_fallback        = true;
					$this->last_fallback_engine = 'mysql';
					$this->last_error           = sprintf(
						__( 'Indexed to MySQL fallback (%s error: %s).', 'turbo-search' ),
						strtoupper( $primary_driver ),
						$primary_err
					);
					return true;
				} else {
					$mysql_err = $mysql_engine->get_last_error() ?: __( 'MySQL index table write failed.', 'turbo-search' );
					$this->last_error = sprintf(
						__( '%s error: %s | MySQL fallback error: %s', 'turbo-search' ),
						strtoupper( $primary_driver ),
						$primary_err,
						$mysql_err
					);
				}
			} catch ( \Throwable $e ) {
				$this->last_error = sprintf(
					__( '%s error: %s | MySQL fallback exception: %s', 'turbo-search' ),
					strtoupper( $primary_driver ),
					$primary_err,
					$e->getMessage()
				);
			}
		}

		return $ok;
	}

	public function upsert_bulk( array $documents ): bool {
		$this->last_error           = null;
		$this->last_fallback        = false;
		$this->last_fallback_engine = null;

		$ok = false;
		try {
			$ok = method_exists( $this->engine, 'upsert_bulk' )
				? $this->engine->upsert_bulk( $documents )
				: true;

			if ( ! method_exists( $this->engine, 'upsert_bulk' ) ) {
				foreach ( $documents as $doc ) {
					if ( ! $this->engine->upsert( $doc ) ) {
						$ok = false;
					}
				}
			}
			if ( ! $ok && method_exists( $this->engine, 'get_last_error' ) ) {
				$this->last_error = $this->engine->get_last_error();
			}
		} catch ( \Throwable $e ) {
			$ok               = false;
			$this->last_error = $e->getMessage();
		}

		if ( ! $ok && ! ( $this->engine instanceof MySQL ) ) {
			$primary_driver = $this->engine->get_driver();
			$primary_err    = $this->last_error ?: __( 'Remote engine was unreachable or returned an error.', 'turbo-search' );

			try {
				$mysql_engine = new MySQL();
				$fallback_ok  = $mysql_engine->upsert_bulk( $documents );
				if ( $fallback_ok ) {
					$this->last_fallback        = true;
					$this->last_fallback_engine = 'mysql';
					$this->last_error           = sprintf(
						__( 'Indexed to MySQL fallback (%s error: %s).', 'turbo-search' ),
						strtoupper( $primary_driver ),
						$primary_err
					);
					return true;
				}
			} catch ( \Throwable $e ) {
				// Fallback failed
			}
		}

		return $ok;
	}

	public function delete( int $post_id ): bool {
		$ok = $this->engine->delete( $post_id );
		$this->flush_all();
		return $ok;
	}

	public function reindex_all(): void {
		$this->engine->reindex_all();
		$this->flush_all();
	}

	public function reindex_chunk( int $paged = 1, int $batch = 50 ): array {
		$res = [];
		try {
			$res = $this->engine->reindex_chunk( $paged, $batch );
		} catch ( \Throwable $e ) {
			if ( ! ( $this->engine instanceof MySQL ) ) {
				$mysql_engine = new MySQL();
				$res          = $mysql_engine->reindex_chunk( $paged, $batch );
				$res['fallback'] = 'mysql';
			}
		}

		if ( empty( $res ) && ! ( $this->engine instanceof MySQL ) ) {
			$mysql_engine    = new MySQL();
			$res             = $mysql_engine->reindex_chunk( $paged, $batch );
			$res['fallback'] = 'mysql';
		}

		if ( ! empty( $res['done'] ) ) {
			$this->flush_all();
		}
		return $res;
	}

	public function get_driver(): string {
		return $this->engine->get_driver();
	}

	public function get_engine_driver(): string {
		return $this->engine->get_driver();
	}

	public function get_cache_driver(): string {
		return $this->driver;
	}

	public function get_indexed_count(): int {
		$count = $this->engine->get_indexed_count();
		if ( 0 === $count && ! ( $this->engine instanceof MySQL ) ) {
			$fallback       = new MySQL();
			$fallback_count = $fallback->get_indexed_count();
			if ( $fallback_count > 0 ) {
				return $fallback_count;
			}
		}
		return $count;
	}

	public function was_fallback(): bool {
		return $this->last_fallback;
	}

	public function get_fallback_engine(): ?string {
		return $this->last_fallback_engine;
	}

	public function get_last_error(): ?string {
		return $this->last_error ?: ( method_exists( $this->engine, 'get_last_error' ) ? $this->engine->get_last_error() : null );
	}

	public function flush_index(): bool {
		$ok = method_exists( $this->engine, 'flush_index' ) ? $this->engine->flush_index() : true;
		$this->flush_all();
		return $ok;
	}

	public function flush_all(): void {
		switch ( $this->driver ) {
			case self::DRIVER_REDIS:
				if ( $this->redis ) {
					try {
						$keys = $this->redis->keys( self::KEY_PREFIX . '*' );
						if ( ! empty( $keys ) ) {
							$this->redis->del( $keys );
						}
					} catch ( \Exception $e ) {}
				}
				break;

			case self::DRIVER_MEMCACHED:
				if ( $this->memcached ) {
					try {
						$this->memcached->flush();
					} catch ( \Exception $e ) {}
				}
				break;

			case self::DRIVER_WP_CACHE:
				wp_cache_flush_group( self::CACHE_GROUP );
				break;

			case self::DRIVER_TRANSIENT:
			default:
				global $wpdb;
				$prefix = $wpdb->esc_like( '_transient_wpts_' ) . '%';
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $prefix ) ); // phpcs:ignore
				$prefix_to = $wpdb->esc_like( '_transient_timeout_wpts_' ) . '%';
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $prefix_to ) ); // phpcs:ignore
				break;
		}

		do_action( 'wpts_cache_flushed', $this->driver );
	}

	public function flush_post( int $post_id ): void {
		do_action( 'wpts_before_cache_flush_post', $post_id, $this->driver );
		$this->flush_all();
		do_action( 'wpts_after_cache_flush_post', $post_id, $this->driver );
	}

	public function status(): array {
		$connected = true;
		$note      = '';

		switch ( $this->driver ) {
			case self::DRIVER_REDIS:
				$connected = null !== $this->redis;
				$note      = $connected ? 'Redis connected' : 'Redis connection failed';
				break;
			case self::DRIVER_MEMCACHED:
				$connected = null !== $this->memcached;
				$note      = $connected ? 'Memcached connected' : 'Memcached connection failed';
				break;
			case self::DRIVER_WP_CACHE:
				$note = $this->is_using_ext_object_cache() ? 'WP Object Cache (persistent)' : 'WP Object Cache (in-memory)';
				break;
			case self::DRIVER_TRANSIENT:
				$note = 'WordPress Transients (Database backed)';
				break;
			case self::DRIVER_NONE:
				$note = 'Cache is disabled';
				break;
		}

		return [
			'driver'    => $this->driver,
			'connected' => $connected,
			'note'      => $note,
			'ttl'       => $this->ttl,
		];
	}

	private function is_using_ext_object_cache(): bool {
		if ( function_exists( 'wp_using_ext_object_cache' ) ) {
			return (bool) wp_using_ext_object_cache();
		}
		global $_wp_using_ext_object_cache;
		return ! empty( $_wp_using_ext_object_cache );
	}

	private function resolve_driver( string $requested ): string {
		if ( self::DRIVER_NONE === $requested ) {
			return self::DRIVER_NONE;
		}

		if ( 'auto' === $requested ) {
			if ( extension_loaded( 'redis' ) && ( defined( 'WPTS_REDIS_HOST' ) || ! empty( $this->settings['redis_host'] ) ) ) {
				return self::DRIVER_REDIS;
			}
			if ( extension_loaded( 'memcached' ) && ( defined( 'WPTS_MEMCACHED_HOST' ) || ! empty( $this->settings['memcached_host'] ) ) ) {
				return self::DRIVER_MEMCACHED;
			}
			if ( $this->is_using_ext_object_cache() ) {
				return self::DRIVER_WP_CACHE;
			}
			return self::DRIVER_TRANSIENT;
		}

		// Graceful degradation if requested driver extension is missing
		if ( self::DRIVER_REDIS === $requested && ! extension_loaded( 'redis' ) ) {
			return $this->is_using_ext_object_cache() ? self::DRIVER_WP_CACHE : self::DRIVER_TRANSIENT;
		}

		if ( self::DRIVER_MEMCACHED === $requested && ! extension_loaded( 'memcached' ) ) {
			return $this->is_using_ext_object_cache() ? self::DRIVER_WP_CACHE : self::DRIVER_TRANSIENT;
		}

		return $requested;
	}

	private function connect(): void {
		if ( self::DRIVER_REDIS === $this->driver && extension_loaded( 'redis' ) ) {
			$host = defined( 'WPTS_REDIS_HOST' ) ? WPTS_REDIS_HOST : ( $this->settings['redis_host'] ?? '127.0.0.1' );
			$port = defined( 'WPTS_REDIS_PORT' ) ? WPTS_REDIS_PORT : ( $this->settings['redis_port'] ?? 6379 );
			$pass = defined( 'WPTS_REDIS_PASSWORD' ) ? WPTS_REDIS_PASSWORD : ( $this->settings['redis_password'] ?? '' );
			$db   = defined( 'WPTS_REDIS_DB' ) ? WPTS_REDIS_DB : ( $this->settings['redis_db'] ?? 0 );

			try {
				$r = new \Redis();
				if ( $r->connect( $host, (int) $port, 1.5 ) ) {
					if ( $pass ) $r->auth( $pass );
					if ( $db )   $r->select( (int) $db );
					$this->redis = $r;
				} else {
					$this->driver = self::DRIVER_TRANSIENT;
					do_action( 'wpts_cache_connect_error', 'redis', 'Failed to connect to Redis server' );
				}
			} catch ( \Throwable $e ) {
				$this->driver = self::DRIVER_TRANSIENT;
				do_action( 'wpts_cache_connect_error', 'redis', $e->getMessage() );
			}
		} elseif ( self::DRIVER_MEMCACHED === $this->driver && extension_loaded( 'memcached' ) ) {
			$host = defined( 'WPTS_MEMCACHED_HOST' ) ? WPTS_MEMCACHED_HOST : ( $this->settings['memcached_host'] ?? '127.0.0.1' );
			$port = defined( 'WPTS_MEMCACHED_PORT' ) ? WPTS_MEMCACHED_PORT : ( $this->settings['memcached_port'] ?? 11211 );

			try {
				$m = new \Memcached( 'wpts' );
				if ( $m->addServer( $host, (int) $port ) ) {
					$this->memcached = $m;
				} else {
					$this->driver = self::DRIVER_TRANSIENT;
					do_action( 'wpts_cache_connect_error', 'memcached', 'Failed to connect to Memcached server' );
				}
			} catch ( \Throwable $e ) {
				$this->driver = self::DRIVER_TRANSIENT;
				do_action( 'wpts_cache_connect_error', 'memcached', $e->getMessage() );
			}
		}
	}

	private function make_key( string $query, array $filters, int $per_page, int $page ): string {
		$norm_filters = $filters;
		if ( isset( $norm_filters['post_type'] ) ) {
			$pts = is_array( $norm_filters['post_type'] ) ? $norm_filters['post_type'] : explode( ',', (string) $norm_filters['post_type'] );
			$pts = array_values( array_filter( array_map( 'trim', $pts ) ) );
			sort( $pts );
			$norm_filters['post_type'] = $pts;
		}
		ksort( $norm_filters );
		$filter_str  = http_build_query( $norm_filters );
		$clean_query = function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( $query ) ) : strtolower( trim( $query ) );
		$raw_key     = "{$clean_query}|{$filter_str}|{$per_page}|{$page}|" . get_current_blog_id();
		$raw_key     = (string) apply_filters( 'wpts_cache_key', $raw_key, $query, $filters, $per_page, $page );
		return self::KEY_PREFIX . md5( $raw_key );
	}

	private function cache_get( string $key ) {
		switch ( $this->driver ) {
			case self::DRIVER_REDIS:
				if ( $this->redis ) {
					$raw = $this->redis->get( $key );
					return false !== $raw ? json_decode( (string) $raw, true ) : false;
				}
				return false;

			case self::DRIVER_MEMCACHED:
				if ( $this->memcached ) {
					$raw = $this->memcached->get( $key );
					return false !== $raw ? json_decode( (string) $raw, true ) : false;
				}
				return false;

			case self::DRIVER_WP_CACHE:
				return wp_cache_get( $key, self::CACHE_GROUP );

			case self::DRIVER_TRANSIENT:
				return get_transient( $key );

			default:
				return false;
		}
	}

	private function cache_set( string $key, array $data, int $ttl ): bool {
		$ttl = (int) apply_filters( 'wpts_cache_ttl', $ttl, $key, $this->driver );
		switch ( $this->driver ) {
			case self::DRIVER_REDIS:
				if ( $this->redis ) {
					return $this->redis->setex( $key, $ttl, wp_json_encode( $data ) );
				}
				return false;

			case self::DRIVER_MEMCACHED:
				if ( $this->memcached ) {
					return $this->memcached->set( $key, wp_json_encode( $data ), $ttl );
				}
				return false;

			case self::DRIVER_WP_CACHE:
				return wp_cache_set( $key, $data, self::CACHE_GROUP, $ttl );

			case self::DRIVER_TRANSIENT:
				return set_transient( $key, $data, $ttl );

			default:
				return false;
		}
	}

	private function track_search( string $query, array $results, bool $from_cache, string $engine, array $filters, int $duration ): void {
		if ( class_exists( '\WPTS\Tracker' ) ) {
			\WPTS\Tracker::instance()->record_search(
				$query,
				(int) ( $results['found'] ?? ( isset( $results['hits'] ) ? count( $results['hits'] ) : 0 ) ),
				$from_cache,
				$engine,
				$this->driver,
				is_array( $filters['post_type'] ?? '' ) ? implode( ',', $filters['post_type'] ) : (string) ( $filters['post_type'] ?? '' ),
				(string) ( $filters['lang'] ?? '' ),
				$duration
			);
		}
	}

	public function is_configured(): bool {
		return method_exists( $this->engine, 'is_configured' ) ? $this->engine->is_configured() : true;
	}
}
