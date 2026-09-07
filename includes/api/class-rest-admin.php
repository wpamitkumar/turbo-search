<?php
namespace WPTS\API;

use WPTS\Admin\Settings;
use WPTS\Admin\SettingsExporter;
use WPTS\Search\Synonyms;
use WPTS\Search\Spelling;
use WPTS\Analytics\AnalyticsReport;
use WPTS\Analytics\ABTesting;
use WPTS\Analytics\Trending;
use WPTS\Hooks\Manager as HooksManager;
use WPTS\Cache\Typesense;
use WPTS\Cache\Elasticsearch;

defined( 'ABSPATH' ) || exit;

/**
 * REST API Admin Controller for the React-powered Admin SPA.
 * All endpoints strictly enforce current_user_can('manage_options') capability.
 */
class RestAdmin {

	private const NAMESPACE = 'wpts/v1/admin';

	public function register_routes(): void {
		// GET / POST Config & Settings
		register_rest_route( self::NAMESPACE, '/config', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_config' ],
				'permission_callback' => [ $this, 'check_admin_permissions' ],
			],
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'save_config' ],
				'permission_callback' => [ $this, 'check_admin_permissions' ],
			],
		] );

		// Custom Synonyms CRUD
		register_rest_route( self::NAMESPACE, '/synonyms', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_synonyms' ],
				'permission_callback' => [ $this, 'check_admin_permissions' ],
			],
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'save_synonym' ],
				'permission_callback' => [ $this, 'check_admin_permissions' ],
				'args'                => [
					'words' => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
					'id'    => [ 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ],
				],
			],
		] );

		register_rest_route( self::NAMESPACE, '/synonyms/(?P<id>\d+)', [
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'delete_synonym' ],
				'permission_callback' => [ $this, 'check_admin_permissions' ],
			],
		] );

		// Connection Testers
		register_rest_route( self::NAMESPACE, '/test-connection', [
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'test_connection' ],
				'permission_callback' => [ $this, 'check_admin_permissions' ],
				'args'                => [
					'target' => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_key' ],
				],
			],
		] );

		// Batch Chunked Reindex
		register_rest_route( self::NAMESPACE, '/reindex-chunk', [
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'reindex_chunk' ],
				'permission_callback' => [ $this, 'check_admin_permissions' ],
				'args'                => [
					'page'  => [ 'type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint' ],
					'batch' => [ 'type' => 'integer', 'default' => 50, 'sanitize_callback' => 'absint' ],
				],
			],
		] );

		// Single Post Reindex
		register_rest_route( self::NAMESPACE, '/reindex-post', [
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'reindex_single_post' ],
				'permission_callback' => [ $this, 'check_admin_permissions' ],
				'args'                => [
					'post_id' => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
				],
			],
		] );

		// Cache Flush
		register_rest_route( self::NAMESPACE, '/flush-cache', [
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'flush_cache' ],
				'permission_callback' => [ $this, 'check_admin_permissions' ],
			],
		] );

		// Engine Index Flush / Wipe
		register_rest_route( self::NAMESPACE, '/flush-engine', [
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'flush_engine' ],
				'permission_callback' => [ $this, 'check_admin_permissions' ],
			],
		] );

		// Tracking & Analytics Flush / Wipe
		register_rest_route( self::NAMESPACE, '/flush-tracking', [
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'flush_tracking' ],
				'permission_callback' => [ $this, 'check_admin_permissions' ],
			],
		] );

		// Reset Settings to Defaults
		register_rest_route( self::NAMESPACE, '/reset-settings', [
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'reset_settings' ],
				'permission_callback' => [ $this, 'check_admin_permissions' ],
			],
		] );

		// Analytics & Search Console Data
		register_rest_route( self::NAMESPACE, '/analytics', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_analytics' ],
				'permission_callback' => [ $this, 'check_admin_permissions' ],
				'args'                => [
					'days' => [ 'type' => 'integer', 'default' => 30, 'sanitize_callback' => 'absint' ],
				],
			],
		] );

		// Paginated Search Tracking Logs
		register_rest_route( self::NAMESPACE, '/analytics/logs', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_search_logs' ],
				'permission_callback' => [ $this, 'check_admin_permissions' ],
				'args'                => [
					'page'     => [ 'type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint' ],
					'per_page' => [ 'type' => 'integer', 'default' => 25, 'sanitize_callback' => 'absint' ],
					'search'   => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
					'status'   => [ 'type' => 'string', 'default' => 'all', 'sanitize_callback' => 'sanitize_key' ],
					'engine'   => [ 'type' => 'string', 'default' => 'all', 'sanitize_callback' => 'sanitize_key' ],
				],
			],
		] );

		// Developer Hooks Reference
		register_rest_route( self::NAMESPACE, '/hooks', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_hooks' ],
				'permission_callback' => [ $this, 'check_admin_permissions' ],
			],
		] );

		// Documentation Content
		register_rest_route( self::NAMESPACE, '/docs', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_docs' ],
				'permission_callback' => [ $this, 'check_admin_permissions' ],
			],
		] );
	}

	public function check_admin_permissions(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Get configuration and environment state array for admin SPA.
	 * Can be called statically by the admin page controller to bootstrap the frontend without REST latency.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_config_data(): array {
		$engine   = \WPTS\Core::instance()->get_engine();
		$settings = Settings::get_all();
		$status   = $engine->status();

		// Fetch all public post types + attachment
		$post_type_objs = get_post_types( [ 'public' => true ], 'objects' );
		if ( ! isset( $post_type_objs['attachment'] ) ) {
			$att_obj = get_post_type_object( 'attachment' );
			if ( $att_obj ) {
				$post_type_objs['attachment'] = $att_obj;
			}
		}

		$post_types = [];
		foreach ( $post_type_objs as $pt ) {
			$counts = wp_count_posts( $pt->name );
			$count  = 'attachment' === $pt->name
				? (int) ( $counts->inherit ?? 0 )
				: (int) ( $counts->publish ?? 0 );

			$post_types[] = [
				'name'        => $pt->name,
				'label'       => $pt->label,
				'count'       => $count,
				'description' => $pt->description,
			];
		}

		// Calculate total indexable documents based on configured post types
		$configured_pts = (array) ( $settings['post_types'] ?? [ 'post', 'page' ] );
		if ( ! empty( $settings['index_attachments'] ) && ! in_array( 'attachment', $configured_pts, true ) ) {
			$configured_pts[] = 'attachment';
		}

		$total_indexable = 0;
		foreach ( $configured_pts as $pt_name ) {
			$counts = wp_count_posts( $pt_name );
			if ( 'attachment' === $pt_name ) {
				$total_indexable += (int) ( $counts->inherit ?? 0 );
			} else {
				$total_indexable += (int) ( $counts->publish ?? 0 );
			}
		}

		// Fetch user roles
		$wp_roles = wp_roles();
		$roles    = [];
		if ( ! empty( $wp_roles->roles ) ) {
			foreach ( $wp_roles->roles as $slug => $r ) {
				$roles[] = [
					'slug' => $slug,
					'name' => $r['name'],
				];
			}
		}
		$roles[] = [ 'slug' => 'guest', 'name' => __( 'Guest / Logged-out', 'turbo-search' ) ];

		$indexed_count = $engine->get_indexed_count();
		$coverage_pct  = $total_indexable > 0
			? min( 100, round( ( $indexed_count / $total_indexable ) * 100 ) )
			: ( $indexed_count > 0 ? 100 : 0 );

		return [
			'settings'       => $settings,
			'post_types'     => $post_types,
			'roles'          => $roles,
			'indexed_count'  => $indexed_count,
			'total_posts'    => $total_indexable,
			'coverage_pct'   => $coverage_pct,
			'active_engine'  => method_exists( $engine, 'get_engine_driver' ) ? $engine->get_engine_driver() : 'mysql',
			'cache_driver'   => method_exists( $engine, 'get_cache_driver' ) ? $engine->get_cache_driver() : 'transient',
			'cache_status'   => $status,
			'version'        => WPTS_VERSION,
			'wp_version'     => get_bloginfo( 'version' ),
			'php_version'    => PHP_VERSION,
		];
	}

	public function get_config( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response( self::get_config_data(), 200 );
	}

	public function save_config( \WP_REST_Request $request ): \WP_REST_Response {
		$params = $request->get_json_params();
		if ( empty( $params ) || ! is_array( $params ) ) {
			$raw_body = $request->get_body();
			if ( ! empty( $raw_body ) ) {
				$decoded = json_decode( $raw_body, true );
				if ( is_array( $decoded ) ) {
					$params = $decoded;
				}
			}
		}
		if ( empty( $params ) || ! is_array( $params ) ) {
			$params = $request->get_body_params();
		}
		if ( empty( $params ) || ! is_array( $params ) ) {
			$params = $request->get_params();
		}
		if ( empty( $params ) || ! is_array( $params ) ) {
			return new \WP_REST_Response( [ 'success' => false, 'message' => __( 'Invalid or empty settings payload received.', 'turbo-search' ) ], 400 );
		}

		if ( isset( $params['settings'] ) && is_array( $params['settings'] ) ) {
			$params = array_merge( $params, $params['settings'] );
		}

		// Persist all settings to both primary store and individual options
		Settings::save( $params );

		if ( class_exists( '\WPTS\Core' ) ) {
			\WPTS\Core::instance()->reset_engine();
		}

		$engine        = \WPTS\Core::instance()->get_engine();
		$active_engine = method_exists( $engine, 'get_engine_driver' ) ? $engine->get_engine_driver() : Settings::get( 'search_engine', 'mysql' );
		$cache_driver  = method_exists( $engine, 'get_cache_driver' ) ? $engine->get_cache_driver() : Settings::get( 'cache_driver', 'transient' );

		$all_settings = Settings::get_all();

		return new \WP_REST_Response( [
			'success'       => true,
			'message'       => __( 'Settings saved successfully!', 'turbo-search' ),
			'settings'      => $all_settings,
			'active_engine' => $active_engine,
			'cache_driver'  => $cache_driver,
		], 200 );
	}

	public function reset_settings(): \WP_REST_Response {
		Settings::reset();

		return new \WP_REST_Response( [
			'success'  => true,
			'message'  => __( 'All settings restored to factory defaults.', 'turbo-search' ),
			'settings' => Settings::get_all(),
		], 200 );
	}

	public function get_synonyms(): \WP_REST_Response {
		return new \WP_REST_Response( [
			'synonyms' => Synonyms::get_custom_synonyms(),
		], 200 );
	}

	public function save_synonym( \WP_REST_Request $request ): \WP_REST_Response {
		$words = sanitize_text_field( (string) $request->get_param( 'words' ) );
		$id    = absint( $request->get_param( 'id' ) );

		if ( '' === $words ) {
			return new \WP_REST_Response( [ 'success' => false, 'message' => __( 'Synonym words cannot be empty.', 'turbo-search' ) ], 400 );
		}

		$ok = Synonyms::save_custom_synonym( $words, $id );
		if ( $ok ) {
			return new \WP_REST_Response( [
				'success'  => true,
				'message'  => __( 'Synonym group saved!', 'turbo-search' ),
				'synonyms' => Synonyms::get_custom_synonyms(),
			], 200 );
		}

		return new \WP_REST_Response( [ 'success' => false, 'message' => __( 'Could not save synonym group.', 'turbo-search' ) ], 500 );
	}

	public function delete_synonym( \WP_REST_Request $request ): \WP_REST_Response {
		$id = absint( $request->get_param( 'id' ) );
		if ( $id <= 0 ) {
			return new \WP_REST_Response( [ 'success' => false, 'message' => __( 'Invalid ID.', 'turbo-search' ) ], 400 );
		}

		Synonyms::delete_custom_synonym( $id );
		return new \WP_REST_Response( [
			'success'  => true,
			'message'  => __( 'Synonym group deleted.', 'turbo-search' ),
			'synonyms' => Synonyms::get_custom_synonyms(),
		], 200 );
	}

	public function test_connection( \WP_REST_Request $request ): \WP_REST_Response {
		$target = sanitize_key( (string) $request->get_param( 'target' ) );
		$params = $request->get_json_params() ?: [];

		switch ( $target ) {
			case 'typesense':
				$settings = Settings::get_all();
				if ( ! empty( $params['typesense_host'] ) )       $settings['typesense_host']       = sanitize_text_field( $params['typesense_host'] );
				if ( ! empty( $params['typesense_port'] ) )       $settings['typesense_port']       = sanitize_text_field( $params['typesense_port'] );
				if ( ! empty( $params['typesense_api_key'] ) )    $settings['typesense_api_key']    = sanitize_text_field( $params['typesense_api_key'] );
				if ( ! empty( $params['typesense_protocol'] ) )   $settings['typesense_protocol']   = sanitize_text_field( $params['typesense_protocol'] );
				if ( ! empty( $params['typesense_collection'] ) ) $settings['typesense_collection'] = sanitize_text_field( $params['typesense_collection'] );

				if ( ! class_exists( '\WPTS\Cache\Typesense' ) && defined( 'WPTS_DIR' ) && file_exists( WPTS_DIR . 'includes/cache/class-typesense.php' ) ) {
					require_once WPTS_DIR . 'includes/cache/class-typesense.php';
				}

				try {
					$ts  = new Typesense( $settings );
					$res = $ts->get_status();
					if ( ! empty( $res['ok'] ) ) {
						return new \WP_REST_Response( [
							'success' => true,
							'message' => sprintf( __( 'Connected! Collection: %s (%d docs)', 'turbo-search' ), $res['collection'], $res['num_documents'] ),
						], 200 );
					}
					return new \WP_REST_Response( [
						'success' => false,
						'message' => $res['error'] ?? __( 'Connection failed. Please check host, port, and API key.', 'turbo-search' ),
					], 200 );
				} catch ( \Throwable $e ) {
					return new \WP_REST_Response( [ 'success' => false, 'message' => $e->getMessage() ], 200 );
				}

			case 'elasticsearch':
				$settings = Settings::get_all();
				if ( ! empty( $params['elasticsearch_host'] ) )     $settings['elasticsearch_host']     = sanitize_text_field( $params['elasticsearch_host'] );
				if ( ! empty( $params['elasticsearch_port'] ) )     $settings['elasticsearch_port']     = sanitize_text_field( $params['elasticsearch_port'] );
				if ( ! empty( $params['elasticsearch_protocol'] ) ) $settings['elasticsearch_protocol'] = sanitize_text_field( $params['elasticsearch_protocol'] );
				if ( ! empty( $params['elasticsearch_api_key'] ) )  $settings['elasticsearch_api_key']  = sanitize_text_field( $params['elasticsearch_api_key'] );
				if ( ! empty( $params['elasticsearch_username'] ) ) $settings['elasticsearch_username'] = sanitize_text_field( $params['elasticsearch_username'] );
				if ( ! empty( $params['elasticsearch_password'] ) ) $settings['elasticsearch_password'] = sanitize_text_field( $params['elasticsearch_password'] );
				if ( ! empty( $params['elasticsearch_index'] ) )    $settings['elasticsearch_index']    = sanitize_text_field( $params['elasticsearch_index'] );

				if ( ! class_exists( '\WPTS\Cache\Elasticsearch' ) && defined( 'WPTS_DIR' ) && file_exists( WPTS_DIR . 'includes/cache/class-elasticsearch.php' ) ) {
					require_once WPTS_DIR . 'includes/cache/class-elasticsearch.php';
				}

				try {
					$es  = new Elasticsearch( $settings );
					$res = $es->get_status();
					if ( ! empty( $res['ok'] ) ) {
						return new \WP_REST_Response( [
							'success' => true,
							'message' => sprintf( __( 'Connected! Cluster: %s (v%s, status: %s)', 'turbo-search' ), $res['cluster_name'], $res['version'], $res['status'] ),
						], 200 );
					}
					return new \WP_REST_Response( [
						'success' => false,
						'message' => $res['error'] ?? __( 'Connection failed. Please verify endpoint and credentials.', 'turbo-search' ),
					], 200 );
				} catch ( \Throwable $e ) {
					return new \WP_REST_Response( [ 'success' => false, 'message' => $e->getMessage() ], 200 );
				}

			case 'redis':
				$host = ! empty( $params['redis_host'] ) ? sanitize_text_field( $params['redis_host'] ) : (string) Settings::get( 'redis_host', '127.0.0.1' );
				$port = ! empty( $params['redis_port'] ) ? absint( $params['redis_port'] ) : absint( Settings::get( 'redis_port', 6379 ) );
				$pass = ! empty( $params['redis_password'] ) ? sanitize_text_field( $params['redis_password'] ) : (string) Settings::get( 'redis_password', '' );
				$db   = ! empty( $params['redis_db'] ) ? absint( $params['redis_db'] ) : absint( Settings::get( 'redis_db', 0 ) );

				if ( ! extension_loaded( 'redis' ) ) {
					$errno  = 0;
					$errstr = '';
					$fp = @fsockopen( $host, $port, $errno, $errstr, 2.0 ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged, WordPressVIPMinimum.Functions.RestrictedFunctions.custom_fsockopen
					if ( is_resource( $fp ) ) {
						fclose( $fp );
						return new \WP_REST_Response( [
							'success' => true,
							'message' => sprintf( __( 'Redis service is reachable at %s:%d (Note: Install PHP ext-redis for native caching)', 'turbo-search' ), $host, $port ),
						], 200 );
					}
					return new \WP_REST_Response( [
						'success' => false,
						'message' => sprintf( __( 'PHP ext-redis is not loaded and Redis at %s:%d is unreachable (%s)', 'turbo-search' ), $host, $port, $errstr ?: 'Connection refused' ),
					], 200 );
				}

				try {
					$r = new \Redis();
					$connected = $r->connect( $host, $port, 2.5 );
					if ( ! $connected ) {
						return new \WP_REST_Response( [
							'success' => false,
							'message' => sprintf( __( 'Could not connect to Redis at %s:%d. Please check if the Redis service is running.', 'turbo-search' ), $host, $port ),
						], 200 );
					}
					if ( ! empty( $pass ) ) {
						$auth_ok = $r->auth( $pass );
						if ( ! $auth_ok ) {
							return new \WP_REST_Response( [
								'success' => false,
								'message' => __( 'Redis authentication failed: Invalid password.', 'turbo-search' ),
							], 200 );
						}
					}
					if ( $db > 0 ) {
						$r->select( $db );
					}
					$pong = $r->ping();
					$info = $r->info( 'server' );
					$v    = $info['redis_version'] ?? 'OK';
					$r->close();

					return new \WP_REST_Response( [
						'success' => true,
						'message' => sprintf( __( 'Connected successfully! Redis v%s (DB %d)', 'turbo-search' ), $v, $db ),
					], 200 );
				} catch ( \Throwable $e ) {
					return new \WP_REST_Response( [
						'success' => false,
						'message' => sprintf( __( 'Redis Error: %s', 'turbo-search' ), $e->getMessage() ),
					], 200 );
				}

			case 'memcached':
				$host = ! empty( $params['memcached_host'] ) ? sanitize_text_field( $params['memcached_host'] ) : (string) Settings::get( 'memcached_host', '127.0.0.1' );
				$port = ! empty( $params['memcached_port'] ) ? absint( $params['memcached_port'] ) : absint( Settings::get( 'memcached_port', 11211 ) );

				if ( ! extension_loaded( 'memcached' ) ) {
					$errno  = 0;
					$errstr = '';
					$fp = @fsockopen( $host, $port, $errno, $errstr, 2.0 ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged, WordPressVIPMinimum.Functions.RestrictedFunctions.custom_fsockopen
					if ( is_resource( $fp ) ) {
						fwrite( $fp, "version\r\n" );
						$resp = trim( (string) fgets( $fp, 128 ) );
						fclose( $fp );
						return new \WP_REST_Response( [
							'success' => true,
							'message' => sprintf( __( 'Memcached is reachable at %s:%d (%s). (Note: Install PHP ext-memcached for native caching)', 'turbo-search' ), $host, $port, $resp ?: 'Active' ),
						], 200 );
					}
					return new \WP_REST_Response( [
						'success' => false,
						'message' => sprintf( __( 'PHP ext-memcached is not loaded and Memcached at %s:%d is unreachable (%s)', 'turbo-search' ), $host, $port, $errstr ?: 'Connection refused' ),
					], 200 );
				}

				try {
					$m = new \Memcached( 'wpts_adm_' . uniqid() );
					$m->setOption( \Memcached::OPT_CONNECT_TIMEOUT, 2000 );
					$m->setOption( \Memcached::OPT_SEND_TIMEOUT, 2000 );
					$m->setOption( \Memcached::OPT_RECV_TIMEOUT, 2000 );
					$m->setOption( \Memcached::OPT_SERVER_FAILURE_LIMIT, 1 );
					$m->addServer( $host, $port );

					$stats = $m->getStats();
					$server_key = "{$host}:{$port}";
					$server_stat = $stats[$server_key] ?? null;

					if ( false === $server_stat || ( is_array( $server_stat ) && ( $server_stat['pid'] ?? -1 ) <= 0 && empty( $server_stat['version'] ) ) ) {
						$errno = 0;
						$errstr = '';
						$fp = @fsockopen( $host, $port, $errno, $errstr, 2.0 ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged, WordPressVIPMinimum.Functions.RestrictedFunctions.custom_fsockopen
						if ( ! is_resource( $fp ) ) {
							return new \WP_REST_Response( [
								'success' => false,
								'message' => sprintf( __( 'Could not connect to Memcached at %s:%d (%s)', 'turbo-search' ), $host, $port, $errstr ?: 'Connection refused' ),
							], 200 );
						}
						fclose( $fp );
					}

					$test_key = 'wpts_test_' . uniqid();
					$m->set( $test_key, 'pong', 5 );
					$val = $m->get( $test_key );
					$m->delete( $test_key );

					$v = ( is_array( $server_stat ) && ! empty( $server_stat['version'] ) ) ? $server_stat['version'] : 'OK';
					return new \WP_REST_Response( [
						'success' => true,
						'message' => sprintf( __( 'Connected successfully! Memcached v%s', 'turbo-search' ), $v ),
					], 200 );
				} catch ( \Throwable $e ) {
					return new \WP_REST_Response( [
						'success' => false,
						'message' => sprintf( __( 'Memcached Error: %s', 'turbo-search' ), $e->getMessage() ),
					], 200 );
				}

			default:
				return new \WP_REST_Response( [ 'success' => false, 'message' => __( 'Unknown target driver.', 'turbo-search' ) ], 200 );
		}
	}

	public function reindex_chunk( \WP_REST_Request $request ): \WP_REST_Response {
		\WPTS\Installer::ensure_tables();
		$page   = max( 1, absint( $request->get_param( 'page' ) ) );
		$batch  = min( 200, max( 10, absint( $request->get_param( 'batch' ) ?: 50 ) ) );
		$engine = \WPTS\Core::instance()->get_engine();

		$result = $engine->reindex_chunk( $page, $batch );
		return new \WP_REST_Response( $result, 200 );
	}

	public function reindex_single_post( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = absint( $request->get_param( 'post_id' ) );
		if ( ! $post_id ) {
			return new \WP_REST_Response( [
				'success' => false,
				'message' => __( 'Please provide a valid numeric Post or Document ID.', 'turbo-search' ),
			], 400 );
		}

		$post = get_post( $post_id );
		if ( ! ( $post instanceof \WP_Post ) ) {
			return new \WP_REST_Response( [
				'success' => false,
				'message' => sprintf( __( 'Post or Document #%d was not found in the database.', 'turbo-search' ), $post_id ),
			], 404 );
		}

		\WPTS\Installer::ensure_tables();
		$core   = \WPTS\Core::instance();
		$engine = $core->get_engine();

		$document = $core->build_document( $post );
		if ( ! $document ) {
			return new \WP_REST_Response( [
				'success' => false,
				'message' => sprintf( __( 'Item #%d (status: %s) is not eligible for indexing. Must be published or inherited.', 'turbo-search' ), $post_id, $post->post_status ),
			], 400 );
		}

		$ok = $engine->upsert( $document );
		if ( $ok ) {
			$has_attached_docs = ! empty( $document['attached_docs'] );
			$doc_length        = $has_attached_docs ? strlen( (string) $document['attached_docs'] ) : 0;
			$is_fallback       = method_exists( $engine, 'was_fallback' ) && $engine->was_fallback();

			if ( $is_fallback ) {
				$message = sprintf(
					__( 'Item #%d ("%s") successfully indexed into %s index fallback. %s', 'turbo-search' ),
					$post_id,
					esc_html( $post->post_title ?: 'Untitled' ),
					strtoupper( (string) $engine->get_fallback_engine() ),
					(string) $engine->get_last_error()
				);
			} else {
				$message = sprintf(
					__( 'Item #%d ("%s") successfully indexed into %s search engine!', 'turbo-search' ),
					$post_id,
					esc_html( $post->post_title ?: 'Untitled' ),
					strtoupper( $engine->get_engine_driver() )
				);
			}

			return new \WP_REST_Response( [
				'success'       => true,
				'fallback'      => $is_fallback,
				'message'       => $message,
				'post_id'       => $post_id,
				'post_title'    => $post->post_title ?: __( '(Untitled)', 'turbo-search' ),
				'post_type'     => $post->post_type,
				'post_status'   => $post->post_status,
				'url'           => $document['url'] ?? get_permalink( $post_id ),
				'doc_length'    => $doc_length,
				'indexed_count' => $engine->get_indexed_count(),
			], 200 );
		}

		$err_detail = method_exists( $engine, 'get_last_error' ) ? $engine->get_last_error() : null;
		$fail_msg   = $err_detail
			? sprintf( __( 'Failed to write Post #%d to search engine index: %s', 'turbo-search' ), $post_id, $err_detail )
			: sprintf( __( 'Failed to write Post #%d to search engine index.', 'turbo-search' ), $post_id );

		return new \WP_REST_Response( [
			'success' => false,
			'message' => $fail_msg,
			'error'   => $err_detail,
		], 500 );
	}

	public function flush_cache(): \WP_REST_Response {
		$engine = \WPTS\Core::instance()->get_engine();
		$engine->flush_all();
		$driver = method_exists( $engine, 'get_cache_driver' ) ? $engine->get_cache_driver() : 'transient';

		return new \WP_REST_Response( [
			'success' => true,
			'message' => sprintf( __( 'Search cache flushed successfully (cache driver: %s).', 'turbo-search' ), strtoupper( $driver ) ),
		], 200 );
	}

	public function flush_engine(): \WP_REST_Response {
		$engine = \WPTS\Core::instance()->get_engine();
		$ok     = $engine->flush_index();
		$driver = method_exists( $engine, 'get_engine_driver' ) ? $engine->get_engine_driver() : 'mysql';

		return new \WP_REST_Response( [
			'success'       => $ok,
			'message'       => sprintf( __( 'Search index & queries for engine "%s" wiped and flushed successfully.', 'turbo-search' ), strtoupper( $driver ) ),
			'indexed_count' => $engine->get_indexed_count(),
		], 200 );
	}

	public function flush_tracking( \WP_REST_Request $request ): \WP_REST_Response {
		$days = $request->get_param( 'days' );
		$tracker = \WPTS\Tracker::instance();

		if ( null !== $days && $days > 0 ) {
			$tracker->prune( absint( $days ) );
			$msg = sprintf( __( 'Pruned search logs older than %d days.', 'turbo-search' ), absint( $days ) );
		} else {
			$tracker->reset_counters();
			$msg = __( 'All search tracking logs, click data, and analytics events have been purged successfully.', 'turbo-search' );
		}

		// Invalidate cached analytics transients
		foreach ( [ 7, 14, 30, 90 ] as $d ) {
			delete_transient( 'wpts_analytics_' . get_current_blog_id() . '_' . $d );
		}

		return new \WP_REST_Response( [
			'success' => true,
			'message' => $msg,
		], 200 );
	}

	public function get_analytics( \WP_REST_Request $request ): \WP_REST_Response {
		$days    = absint( $request->get_param( 'days' ) ?: 30 );
		$refresh = (bool) ( $request->get_param( 'refresh' ) || $request->get_param( 'nocache' ) );
		$cache_key = 'wpts_analytics_' . get_current_blog_id() . '_' . $days;

		if ( ! $refresh ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) && ! empty( $cached ) ) {
				return new \WP_REST_Response( $cached, 200 );
			}
		}

		$t = \WPTS\Tracker::instance();

		$summary  = AnalyticsReport::get_summary( $days );
		$ctr_rows = AnalyticsReport::get_ctr_by_position( $days );
		$top      = $t->top_queries( 20, $days );
		$zero     = $t->zero_result_queries( 15, $days );
		$noclick  = AnalyticsReport::get_no_click_queries( 15, $days );
		$ab       = ABTesting::get_comparison();
		$daily    = $t->daily_volume( min( 30, max( 7, $days ) ) );
		$trending = class_exists( '\WPTS\Analytics\Trending' ) ? \WPTS\Analytics\Trending::get_trending( 10, $days ) : [];

		global $wpdb;
		$search_table = $wpdb->prefix . 'wpts_search_log';
		$search_log_items = [];
		$total_logs       = 0;

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $search_table ) ) ) {
			$total_logs = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$search_table}`" ); // phpcs:ignore
			$search_log_items = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
				"SELECT id, query, results, from_cache, engine, cache_driver, post_type, duration_ms, clicked_position, variant, searched_at
				 FROM `{$search_table}`
				 ORDER BY searched_at DESC
				 LIMIT %d", 25
			), ARRAY_A );
		}

		$ctr_keywords = AnalyticsReport::get_keyword_click_breakdown( 30, $days );

		$data = [
			'summary'      => $summary,
			'ctr_by_rank'  => $ctr_rows,
			'ctr_keywords' => $ctr_keywords,
			'home_url'     => home_url( '/' ),
			'top_queries'  => $top,
			'zero_queries' => $zero,
			'noclick'      => $noclick,
			'ab_testing'   => $ab,
			'trending'     => $trending,
			'daily_volume' => $daily,
			'search_log'   => [
				'items'        => $search_log_items,
				'total'        => $total_logs,
				'total_pages'  => $total_logs > 0 ? (int) ceil( $total_logs / 25 ) : 0,
				'current_page' => 1,
				'per_page'     => 25,
			],
		];

		// Cache aggregated report for 5 minutes (300 seconds)
		set_transient( $cache_key, $data, 300 );

		return new \WP_REST_Response( $data, 200 );
	}

	public function get_search_logs( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$search_table = $wpdb->prefix . 'wpts_search_log';

		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $search_table ) ) ) {
			return new \WP_REST_Response( [
				'items'        => [],
				'total'        => 0,
				'total_pages'  => 0,
				'current_page' => 1,
				'per_page'     => 25,
			], 200 );
		}

		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 200, max( 5, (int) $request->get_param( 'per_page' ) ?: 25 ) );
		$search   = trim( (string) $request->get_param( 'search' ) );
		$status   = sanitize_key( (string) $request->get_param( 'status' ) ?: 'all' );
		$engine   = sanitize_key( (string) $request->get_param( 'engine' ) ?: 'all' );

		$where_clauses = [ '1=1' ];
		$params        = [];

		if ( ! empty( $search ) ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$where_clauses[] = '(query LIKE %s OR engine LIKE %s OR post_type LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		if ( 'zero_hits' === $status ) {
			$where_clauses[] = 'results = 0';
		} elseif ( 'cached' === $status ) {
			$where_clauses[] = 'from_cache = 1';
		} elseif ( 'uncached' === $status ) {
			$where_clauses[] = 'from_cache = 0';
		} elseif ( 'clicked' === $status ) {
			$where_clauses[] = 'clicked_position IS NOT NULL AND clicked_position > 0';
		}

		if ( ! empty( $engine ) && 'all' !== $engine ) {
			$where_clauses[] = 'engine = %s';
			$params[] = $engine;
		}

		$where_sql = implode( ' AND ', $where_clauses );

		// 1. Total count
		$count_sql = "SELECT COUNT(*) FROM `{$search_table}` WHERE {$where_sql}";
		$total = ! empty( $params )
			? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) // phpcs:ignore
			: (int) $wpdb->get_var( $count_sql ); // phpcs:ignore

		$total_pages = $total > 0 ? (int) ceil( $total / $per_page ) : 0;
		$offset      = ( $page - 1 ) * $per_page;

		// 2. Paginated rows
		$items_sql = "SELECT id, query, results, from_cache, engine, cache_driver, post_type, duration_ms, clicked_position, variant, searched_at
					  FROM `{$search_table}`
					  WHERE {$where_sql}
					  ORDER BY searched_at DESC
					  LIMIT %d OFFSET %d";

		$fetch_params = array_merge( $params, [ $per_page, $offset ] );
		$items = (array) $wpdb->get_results( $wpdb->prepare( $items_sql, $fetch_params ), ARRAY_A ); // phpcs:ignore

		return new \WP_REST_Response( [
			'items'        => $items,
			'total'        => $total,
			'total_pages'  => $total_pages,
			'current_page' => $page,
			'per_page'     => $per_page,
		], 200 );
	}

	public function get_hooks(): \WP_REST_Response {
		return new \WP_REST_Response( [
			'hooks' => HooksManager::get_reference(),
		], 200 );
	}

	public function get_docs(): \WP_REST_Response {
		$candidate_dirs = [
			defined( 'WPTS_DIR' ) ? wp_normalize_path( WPTS_DIR ) . 'docs/' : '',
			wp_normalize_path( dirname( __DIR__, 2 ) ) . '/docs/',
		];

		$docs_dir = '';
		foreach ( $candidate_dirs as $dir ) {
			if ( ! empty( $dir ) && is_dir( $dir ) ) {
				$docs_dir = trailingslashit( $dir );
				break;
			}
		}

		$files = [
			'README.md'                          => [ 'id' => 'readme',      'title' => '⚡ Overview & Quick Start' ],
			'01-installation-and-setup.md'       => [ 'id' => 'setup',       'title' => '01. Installation & Engines' ],
			'02-search-features-and-shortcodes.md' => [ 'id' => 'features',    'title' => '02. Search & Shortcodes' ],
			'03-indexing-and-caching.md'         => [ 'id' => 'indexing',    'title' => '03. Indexing & Caching' ],
			'04-ai-vector-search.md'             => [ 'id' => 'vector',      'title' => '04. Hybrid AI Vector Search' ],
			'05-developer-hooks-api.md'          => [ 'id' => 'hooks',       'title' => '05. Developer Hooks API' ],
			'06-rest-api-reference.md'           => [ 'id' => 'rest',        'title' => '06. REST API Reference' ],
			'07-multisite-multilingual.md'       => [ 'id' => 'multisite',   'title' => '07. Multisite & Multilingual' ],
			'08-cli-commands.md'                 => [ 'id' => 'cli',         'title' => '08. WP-CLI Commands' ],
			'09-complete-settings-reference.md'  => [ 'id' => 'settings',    'title' => '09. Settings & Config Guide' ],
		];

		$docs = [];
		foreach ( $files as $filename => $meta ) {
			$raw_content = '';
			if ( ! empty( $docs_dir ) ) {
				$path = $docs_dir . $filename;
				if ( file_exists( $path ) && is_readable( $path ) ) {
					$raw_content = (string) file_get_contents( $path );
				}
			}

			// Resilient fallback if markdown file is missing on disk
			if ( empty( trim( $raw_content ) ) ) {
				$raw_content = "# " . $meta['title'] . "\n\n" .
					"> ⚠️ **Documentation Content Unavailable Locally**\n\n" .
					"The file `" . $filename . "` could not be loaded from your server's `docs/` folder.\n\n" .
					"Please verify that the `docs/` directory is present in your plugin directory (`turbo-search/docs/`).";
			}

			$docs[] = [
				'id'       => $meta['id'],
				'title'    => $meta['title'],
				'filename' => $filename,
				'content'  => $raw_content,
			];
		}

		return new \WP_REST_Response( [
			'docs' => $docs,
		], 200 );
	}
}

