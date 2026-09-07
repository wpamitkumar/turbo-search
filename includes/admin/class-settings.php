<?php
namespace WPTS\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Centralised settings store for Turbo Search.
 * Manages all option keys, typed casting, defaults, and sanitization.
 */
class Settings {

	public const KEYS = [
		'post_types',
		'enable_frontend_search',
		'debounce_ms',
		'results_per_page',
		'highlight_results',
		'search_in_meta',
		'index_thumbnails',
		'index_comments',
		'index_attachments',
		'index_woocommerce',
		'weight_title',
		'weight_excerpt',
		'weight_content',
		'weight_taxonomies',
		'weight_meta',
		'weight_comments',
		'enable_spelling_suggestions',
		'enable_synonyms',
		'enable_boolean_search',
		'enable_phrase_search',
		'search_engine',
		'typesense_host',
		'typesense_port',
		'typesense_protocol',
		'typesense_api_key',
		'typesense_collection',
		'elasticsearch_host',
		'elasticsearch_port',
		'elasticsearch_protocol',
		'elasticsearch_username',
		'elasticsearch_password',
		'elasticsearch_api_key',
		'elasticsearch_index',
		'cache_driver',
		'cache_ttl',
		'cache_stats',
		'redis_host',
		'redis_port',
		'redis_password',
		'redis_db',
		'memcached_host',
		'memcached_port',
		'cdn_cache_headers',
		'cdn_cache_ttl',
		'scheduled_reindex_enabled',
		'scheduled_reindex_interval',
		'scheduled_reindex_mode',
		'enable_voice_search',
		'results_layout',
		'enable_user_history',
		'enable_user_favorites',
		'enable_archive_live_filter',
		'tracking_enabled',
		'tracking_retention_days',
		'track_clicks',
		'ab_testing_enabled',
		'ab_debounce_b',
		'ab_theme_b',
		'enable_honeypot',
		'role_restrictions',
		'gdpr_anonymize_days',
		'enable_command_k_modal',
		'woocommerce_quick_add_to_cart',
		'enable_category_tabs_dropdown',
		'enable_vector_search',
		'vector_provider',
		'vector_api_key',
		'vector_model',
		'vector_endpoint',
		'vector_weight',
		'multisite_cross_search',
		'admin_auto_refresh',
	];

	public const DEFAULTS = [
		'post_types'                  => [ 'post', 'page' ],
		'enable_frontend_search'      => true,
		'debounce_ms'                 => 200,
		'results_per_page'            => 10,
		'admin_auto_refresh'          => 30,
		'highlight_results'           => true,
		'search_in_meta'              => false,
		'index_thumbnails'            => true,
		'index_comments'              => false,
		'index_attachments'           => true,
		'index_woocommerce'           => true,
		'weight_title'                => 10,
		'weight_excerpt'              => 5,
		'weight_content'              => 1,
		'weight_taxonomies'           => 4,
		'weight_meta'                 => 3,
		'weight_comments'             => 1,
		'enable_spelling_suggestions' => true,
		'enable_synonyms'             => true,
		'enable_boolean_search'       => true,
		'enable_phrase_search'        => true,
		'search_engine'               => 'mysql',
		'typesense_host'              => '',
		'typesense_port'              => '8108',
		'typesense_protocol'          => 'http',
		'typesense_api_key'           => '',
		'typesense_collection'        => 'wpts_posts',
		'elasticsearch_host'          => '',
		'elasticsearch_port'          => '9200',
		'elasticsearch_protocol'      => 'http',
		'elasticsearch_username'      => '',
		'elasticsearch_password'      => '',
		'elasticsearch_api_key'       => '',
		'elasticsearch_index'         => 'wpts_posts',
		'cache_driver'                => 'auto',
		'cache_ttl'                   => 300,
		'cache_stats'                 => false,
		'redis_host'                  => '',
		'redis_port'                  => 6379,
		'redis_password'              => '',
		'redis_db'                    => 0,
		'memcached_host'              => '',
		'memcached_port'              => 11211,
		'cdn_cache_headers'           => false,
		'cdn_cache_ttl'               => 300,
		'scheduled_reindex_enabled'   => false,
		'scheduled_reindex_interval'  => 'daily',
		'scheduled_reindex_mode'      => 'incremental',
		'enable_voice_search'         => true,
		'results_layout'              => 'list',
		'enable_user_history'         => true,
		'enable_user_favorites'       => true,
		'enable_archive_live_filter'  => false,
		'tracking_enabled'            => true,
		'tracking_retention_days'     => 90,
		'track_clicks'                => true,
		'ab_testing_enabled'          => false,
		'ab_debounce_b'               => 350,
		'ab_theme_b'                  => 'minimal',
		'enable_honeypot'             => true,
		'role_restrictions'           => [],
		'gdpr_anonymize_days'         => 30,
		'enable_command_k_modal'      => true,
		'woocommerce_quick_add_to_cart' => true,
		'enable_category_tabs_dropdown' => true,
		'enable_vector_search'        => false,
		'vector_provider'             => 'openai',
		'vector_api_key'              => '',
		'vector_model'                => 'text-embedding-3-small',
		'vector_endpoint'             => 'http://localhost:11434/api/embeddings',
		'vector_weight'               => 0.3,
		'multisite_cross_search'      => false,
	];

	public const OPTION_NAME = 'wpts_settings';

	public static function get_all(): array {
		$saved          = get_option( self::OPTION_NAME, null );
		$is_saved_array = is_array( $saved );
		$out            = [];

		foreach ( self::KEYS as $key ) {
			if ( $is_saved_array && array_key_exists( $key, $saved ) ) {
				$out[ $key ] = self::cast( $key, $saved[ $key ] );
			} else {
				$raw = get_option( "wpts_{$key}", '__WPTS_UNSET__' );
				if ( '__WPTS_UNSET__' !== $raw ) {
					$out[ $key ] = self::cast( $key, $raw );
				} else {
					$out[ $key ] = self::DEFAULTS[ $key ] ?? null;
				}
			}
		}
		return $out;
	}

	public static function get( string $key, $default = null ) {
		$default = null !== $default ? $default : ( self::DEFAULTS[ $key ] ?? null );
		$saved   = get_option( self::OPTION_NAME, null );

		if ( is_array( $saved ) && array_key_exists( $key, $saved ) ) {
			return self::cast( $key, $saved[ $key ] );
		}

		$raw = get_option( "wpts_{$key}", '__WPTS_UNSET__' );
		if ( '__WPTS_UNSET__' === $raw ) {
			return $default;
		}

		return self::cast( $key, $raw );
	}

	public static function seed_defaults_if_missing(): void {
		$existing = get_option( self::OPTION_NAME, null );
		if ( null === $existing || ! is_array( $existing ) ) {
			add_option( self::OPTION_NAME, self::DEFAULTS, '', 'yes' );
		}

		foreach ( self::DEFAULTS as $key => $value ) {
			add_option( "wpts_{$key}", is_bool( $value ) ? ( $value ? 1 : 0 ) : $value );
		}
	}

	public static function reset(): void {
		update_option( self::OPTION_NAME, self::DEFAULTS, true );

		foreach ( self::DEFAULTS as $key => $value ) {
			update_option( "wpts_{$key}", is_bool( $value ) ? ( $value ? 1 : 0 ) : $value );
		}

		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( self::OPTION_NAME, 'options' );
		}

		if ( class_exists( '\WPTS\Indexer\ScheduledSync' ) ) {
			\WPTS\Indexer\ScheduledSync::reschedule();
		}

		do_action( 'wpts_settings_reset' );
	}

	public static function save( array $data ): void {
		$current = self::get_all();

		foreach ( self::KEYS as $key ) {
			if ( ! array_key_exists( $key, $data ) ) {
				continue;
			}
			$value           = self::sanitize( $key, $data[ $key ] );
			$current[ $key ] = $value;
			update_option( "wpts_{$key}", is_bool( $value ) ? ( $value ? 1 : 0 ) : $value );
		}

		update_option( self::OPTION_NAME, $current, true );

		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( self::OPTION_NAME, 'options' );
		}

		if ( class_exists( '\WPTS\Indexer\ScheduledSync' ) ) {
			\WPTS\Indexer\ScheduledSync::reschedule();
		}

		// Reset in-memory engine singleton so latest options take effect immediately.
		if ( class_exists( '\WPTS\Core' ) ) {
			\WPTS\Core::instance()->reset_engine();
		}

		// Only flush search result cache if search-altering index or engine configurations changed.
		// UI/display layout settings (like results_layout, theme, debounce) should NEVER invalidate search cache.
		$needs_flush = false;
		$cache_altering_keys = [
			'search_engine',
			'post_types',
			'index_attachments',
			'index_woocommerce',
			'index_comments',
			'search_in_meta',
			'weight_title',
			'weight_excerpt',
			'weight_content',
			'weight_taxonomies',
			'weight_meta',
			'weight_comments',
			'enable_synonyms',
			'enable_spelling_suggestions',
			'typesense_host',
			'typesense_port',
			'typesense_protocol',
			'typesense_api_key',
			'typesense_collection',
			'elasticsearch_host',
			'elasticsearch_port',
			'elasticsearch_protocol',
			'elasticsearch_username',
			'elasticsearch_password',
			'elasticsearch_api_key',
			'elasticsearch_index',
			'cache_driver',
			'cache_ttl',
			'redis_host',
			'redis_port',
			'redis_password',
			'redis_db',
			'memcached_host',
			'memcached_port',
		];

		foreach ( $cache_altering_keys as $ck ) {
			if ( array_key_exists( $ck, $data ) ) {
				$needs_flush = true;
				break;
			}
		}

		if ( $needs_flush && class_exists( '\WPTS\Core' ) ) {
			try {
				\WPTS\Core::instance()->get_engine()->flush_all();
			} catch ( \Throwable $e ) { // phpcs:ignore
			}
		}

		do_action( 'wpts_settings_saved', $data );
	}

	private static function cast( string $key, $value ) {
		$default = self::DEFAULTS[ $key ] ?? null;

		if ( is_array( $default ) ) {
			if ( ! is_array( $value ) ) {
				return ! empty( $value ) ? (array) $value : $default;
			}
			return $value;
		}

		if ( is_bool( $default ) ) {
			return (bool) $value;
		}

		if ( is_int( $default ) ) {
			return absint( $value );
		}

		return $value;
	}

	public static function sanitize( string $key, $value ) {
		switch ( $key ) {
			case 'post_types':
				return array_values( array_filter( array_map( 'sanitize_text_field', (array) $value ) ) );

			case 'role_restrictions':
				return is_array( $value ) ? $value : [];

			case 'debounce_ms':
			case 'results_per_page':
			case 'cache_ttl':
			case 'cdn_cache_ttl':
			case 'redis_db':
			case 'tracking_retention_days':
			case 'weight_title':
			case 'weight_excerpt':
			case 'weight_content':
			case 'weight_taxonomies':
			case 'weight_meta':
			case 'weight_comments':
			case 'ab_debounce_b':
			case 'gdpr_anonymize_days':
			case 'admin_auto_refresh':
				return absint( $value );

			case 'redis_port':
				$port = absint( $value );
				return $port > 0 ? $port : 6379;

			case 'memcached_port':
				$port = absint( $value );
				return $port > 0 ? $port : 11211;

			case 'typesense_port':
				$port = absint( $value );
				return $port > 0 ? (string) $port : '8108';

			case 'elasticsearch_port':
				$port = absint( $value );
				return $port > 0 ? (string) $port : '9200';

			case 'typesense_collection':
				$col = sanitize_text_field( (string) $value );
				return '' !== $col ? $col : 'wpts_posts';

			case 'elasticsearch_index':
				$idx = sanitize_text_field( (string) $value );
				return '' !== $idx ? $idx : 'wpts_posts';

			case 'enable_frontend_search':
			case 'highlight_results':
			case 'search_in_meta':
			case 'index_thumbnails':
			case 'index_comments':
			case 'index_attachments':
			case 'index_woocommerce':
			case 'enable_spelling_suggestions':
			case 'enable_synonyms':
			case 'enable_boolean_search':
			case 'enable_phrase_search':
			case 'cache_stats':
			case 'cdn_cache_headers':
			case 'scheduled_reindex_enabled':
			case 'enable_voice_search':
			case 'enable_user_history':
			case 'enable_user_favorites':
			case 'enable_archive_live_filter':
			case 'tracking_enabled':
			case 'track_clicks':
			case 'ab_testing_enabled':
			case 'enable_honeypot':
			case 'enable_command_k_modal':
			case 'woocommerce_quick_add_to_cart':
			case 'enable_category_tabs_dropdown':
			case 'enable_vector_search':
			case 'multisite_cross_search':
				return ! empty( $value ) ? 1 : 0;

			case 'vector_weight':
				return (float) max( 0.0, min( 1.0, (float) $value ) );

			case 'vector_provider':
				$val = strtolower( trim( (string) $value ) );
				return in_array( $val, [ 'openai', 'ollama', 'custom' ], true ) ? $val : 'openai';

			case 'cache_driver':
				$val = strtolower( trim( (string) $value ) );
				return in_array( $val, [ 'none', 'auto', 'transient', 'wp_cache', 'memcached', 'redis' ], true ) ? $val : 'auto';

			case 'search_engine':
				$val = strtolower( trim( (string) $value ) );
				return in_array( $val, [ 'mysql', 'typesense', 'elasticsearch' ], true ) ? $val : 'mysql';

			case 'results_layout':
				$val = strtolower( trim( (string) $value ) );
				return in_array( $val, [ 'list', 'grid', 'card' ], true ) ? $val : 'list';

			case 'scheduled_reindex_interval':
				$val = strtolower( trim( (string) $value ) );
				return in_array( $val, [ 'hourly', 'twicedaily', 'daily', 'weekly' ], true ) ? $val : 'daily';

			case 'scheduled_reindex_mode':
				$val = strtolower( trim( (string) $value ) );
				return in_array( $val, [ 'incremental', 'full' ], true ) ? $val : 'incremental';

			case 'typesense_protocol':
			case 'elasticsearch_protocol':
				$val = strtolower( trim( (string) $value ) );
				return in_array( $val, [ 'http', 'https' ], true ) ? $val : 'http';

			default:
				return sanitize_text_field( (string) $value );
		}
	}
}
