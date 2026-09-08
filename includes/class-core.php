<?php
namespace WPTS;

use WPTS\Search\ContentExtractor;
use WPTS\Search\EngineInterface;
use WPTS\Cache\MySQL;
use WPTS\Cache\Typesense;
use WPTS\Cache\Elasticsearch;
use WPTS\Cache\CacheManager;
use WPTS\Universal\Utils;
use WPTS\Indexer\ScheduledSync;
use WPTS\Admin\SiteHealth;
use WPTS\Security\GDPR;
use WPTS\Security\RoleRestrictions;

defined( 'ABSPATH' ) || exit;

/**
 * Core singleton – orchestrates all subsystems.
 */
final class Core {

	private static ?Core $instance = null;
	private ?CacheManager $engine_manager = null;
	private string $engine_manager_hash   = '';

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function boot(): void {
		// Seed default options
		Admin\Settings::seed_defaults_if_missing();

		// Ensure database tables exist
		Installer::ensure_tables();

		// Load i18n
		( new I18n\Loader() )->load();

		// Tracker
		Tracker::instance();
		add_action( 'wpts_daily_cron', [ $this, 'run_daily_cron' ] );
		if ( ! wp_next_scheduled( 'wpts_daily_cron' ) ) {
			wp_schedule_event( time(), 'daily', 'wpts_daily_cron' );
		}

		// Scheduled Sync & Site Health
		ScheduledSync::init();
		SiteHealth::init();

		// Gutenberg Block & CPTs
		( new Block() )->register();
		( new CPT\Manager() )->register();

		// Hooks Manager
		( new Hooks\Manager() )->init();

		// REST API
		$rest       = new API\RestSearch();
		$rest_admin = new API\RestAdmin();
		add_action( 'rest_api_init', [ $rest, 'register_routes' ] );
		add_action( 'rest_api_init', [ $rest_admin, 'register_routes' ] );

		// Admin React SPA Application
		if ( is_admin() ) {
			( new Admin\Page() )->init();
		}

		// Multisite
		if ( is_multisite() ) {
			( new Multisite\Network() )->init();
		}

		// AJAX handlers & shortcode loader
		require_once WPTS_DIR . 'includes/ajax-handlers.php';

		// Post save and delete hooks
		add_action( 'save_post',                      [ $this, 'on_save_post' ], 20, 3 );
		add_action( 'delete_post',                    [ $this, 'on_delete_post' ] );
		add_action( 'add_attachment',                 [ $this, 'on_save_attachment' ], 20 );
		add_action( 'edit_attachment',                [ $this, 'on_save_attachment' ], 20 );
		add_action( 'wp_update_attachment_metadata',  [ $this, 'on_attachment_metadata_updated' ], 20, 2 );
		add_action( 'added_post_meta',                [ $this, 'on_attachment_meta_changed' ], 20, 4 );
		add_action( 'updated_post_meta',              [ $this, 'on_attachment_meta_changed' ], 20, 4 );

		// Main WordPress Search Query Interception with Graceful Core Fallback
		add_action( 'pre_get_posts',                  [ $this, 'intercept_core_search' ], 10 );

		do_action( 'wpts_booted', $this );
	}

	public function build_document( \WP_Post $post ): ?array {
		$post_id = (int) $post->ID;
		if ( 'publish' !== $post->post_status && 'inherit' !== $post->post_status ) {
			return null;
		}

		$excerpt = (string) $post->post_excerpt;
		if ( '' === trim( $excerpt ) ) {
			$excerpt = wp_trim_words( wp_strip_all_tags( $post->post_content ), 30, '…' );
		}

		$clean_content = Utils::clean_text( (string) $post->post_content );

		// Thumbnail
		$thumbnail_url = '';
		if ( Admin\Settings::get( 'index_thumbnails', true ) ) {
			$thumbnail_id = get_post_thumbnail_id( $post_id );
			if ( $thumbnail_id ) {
				$img = wp_get_attachment_image_src( $thumbnail_id, 'thumbnail' );
				if ( $img ) {
					$thumbnail_url = (string) $img[0];
				}
			} elseif ( 'attachment' === $post->post_type ) {
				$thumbnail_url = (string) ( wp_get_attachment_thumb_url( $post_id ) ?: '' );
			}
		}

		$doc_url = 'attachment' === $post->post_type
			? ( (string) ( wp_get_attachment_url( $post_id ) ?: get_permalink( $post_id ) ) )
			: (string) get_permalink( $post_id );

		$title = trim( (string) $post->post_title );
		if ( '' === $title ) {
			$title = (string) ( $post->post_name ?: sprintf(
				/* translators: %d: post ID */
				__( 'Post #%d', 'turbo-search' ),
				$post_id
			) );
		}

		$base_doc = [
			'id'            => (string) $post_id,
			'title'         => $title,
			'content'       => $clean_content,
			'excerpt'       => (string) $excerpt,
			'slug'          => (string) $post->post_name,
			'post_type'     => (string) $post->post_type,
			'url'           => $doc_url,
			'author_id'     => (string) $post->post_author,
			'date'          => (int) strtotime( $post->post_date ),
			'thumbnail_url' => $thumbnail_url,
			'lang'          => $this->current_lang(),
			'site_id'       => get_current_blog_id(),
		];

		// Enrich document with WooCommerce, comments, taxonomies, and attached docs
		$enriched_doc = ContentExtractor::enrich( $base_doc, $post, Admin\Settings::get_all() );
		$document     = apply_filters( 'wpts_indexable_document', $enriched_doc, $post );

		// Hybrid Vector Embeddings
		if ( \WPTS\Search\VectorSearch::instance()->is_enabled() ) {
			$cached_emb = get_post_meta( $post_id, '_wpts_embedding', true );
			if ( is_array( $cached_emb ) && ! empty( $cached_emb ) ) {
				$document['embedding'] = $cached_emb;
			} elseif ( empty( $GLOBALS['wpts_reindexing'] ) ) {
				$embed_text = ( $document['title'] ?? '' ) . ' ' . ( $document['excerpt'] ?? '' ) . ' ' . substr( (string) ( $document['content'] ?? '' ), 0, 500 );
				$embedding  = \WPTS\Search\VectorSearch::instance()->get_embedding( $embed_text, false );
				if ( is_array( $embedding ) ) {
					update_post_meta( $post_id, '_wpts_embedding', $embedding );
					$document['embedding'] = $embedding;
				}
			}
		}

		// Final sanitization of textual fields to guarantee valid UTF-8 and strip any null bytes
		if ( isset( $document['title'] ) ) {
			$document['title'] = Utils::clean_text( (string) $document['title'] );
		}
		if ( isset( $document['content'] ) ) {
			$document['content'] = Utils::clean_text( (string) $document['content'] );
		}
		if ( isset( $document['excerpt'] ) ) {
			$document['excerpt'] = Utils::clean_text( (string) $document['excerpt'] );
		}
		if ( isset( $document['attached_docs'] ) ) {
			$document['attached_docs'] = Utils::clean_text( (string) $document['attached_docs'] );
		}

		return $document;
	}

	public function on_save_post( int $post_id, \WP_Post $post, bool $update ): void {
		if ( wp_is_post_revision( $post_id ) ) return;
		if ( wp_is_post_autosave( $post_id ) ) return;
		if ( ! ( $post instanceof \WP_Post ) ) return;

		$post_types = (array) Admin\Settings::get( 'post_types', [ 'post', 'page' ] );
		$reindexing = ! empty( $GLOBALS['wpts_reindexing'] );

		if ( ! $reindexing && ! in_array( $post->post_type, $post_types, true ) ) {
			if ( 'attachment' === $post->post_type && ! empty( Admin\Settings::get( 'index_attachments' ) ) ) {
				// allow attachment indexing
			} else {
				return;
			}
		}

		// Delete unpublished posts from search index
		if ( 'publish' !== $post->post_status && 'inherit' !== $post->post_status ) {
			$this->get_engine()->delete( $post_id );
			return;
		}

		$document = $this->build_document( $post );
		if ( $document ) {
			$this->get_engine()->upsert( $document );
		}
	}

	public function on_save_attachment( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! ( $post instanceof \WP_Post ) ) {
			return;
		}

		$post_types = (array) Admin\Settings::get( 'post_types', [ 'post', 'page' ] );
		$index_att  = ! empty( Admin\Settings::get( 'index_attachments', true ) );

		if ( $index_att || in_array( 'attachment', $post_types, true ) ) {
			$document = $this->build_document( $post );
			if ( $document ) {
				$this->get_engine()->upsert( $document );
			}
		}

		// Also refresh parent post if this document is attached to a post
		if ( ! empty( $post->post_parent ) && (int) $post->post_parent > 0 ) {
			$parent_post = get_post( (int) $post->post_parent );
			if ( $parent_post instanceof \WP_Post ) {
				$this->on_save_post( (int) $post->post_parent, $parent_post, true );
			}
		}
	}

	public function on_attachment_metadata_updated( $data, int $post_id ): array {
		$this->on_save_attachment( $post_id );
		return (array) $data;
	}

	public function on_attachment_meta_changed( $mid, int $post_id, string $meta_key, $meta_value ): void {
		if ( '_wp_attached_file' === $meta_key ) {
			$this->on_save_attachment( $post_id );
		}
	}

	public function on_delete_post( int $post_id ): void {
		$this->get_engine()->delete( $post_id );
	}

	public function run_daily_cron(): void {
		$days = (int) Admin\Settings::get( 'tracking_retention_days', 90 );
		Tracker::instance()->prune( $days );
		GDPR::anonymize_old_logs();
	}

	/**
	 * Reset cached engine instance.
	 */
	public function reset_engine(): void {
		$this->engine_manager      = null;
		$this->engine_manager_hash = '';
	}

	/**
	 * Resolve and return the active CacheManager instance.
	 */
	public function get_engine(): CacheManager {
		$settings    = Admin\Settings::get_all();
		$engine_hash = md5( serialize( $settings ) );

		if ( null !== $this->engine_manager && $this->engine_manager_hash === $engine_hash ) {
			return $this->engine_manager;
		}

		$chosen_engine = $settings['search_engine'] ?? 'mysql';
		$engine        = new MySQL();

		if ( 'elasticsearch' === $chosen_engine ) {
			$engine = new Elasticsearch( $settings );
		} elseif ( 'typesense' === $chosen_engine ) {
			$ts = new Typesense( $settings );
			if ( method_exists( $ts, 'is_configured' ) && ! $ts->is_configured() ) {
				do_action( 'wpts_typesense_fallback', 'unreachable' );
			}
			$engine = $ts;
		}

		/**
		 * Filter: wpts_search_engine
		 */
		$engine = apply_filters( 'wpts_search_engine', $engine, $settings );

		$this->engine_manager      = new CacheManager( $engine, $settings );
		$this->engine_manager_hash = $engine_hash;
		return $this->engine_manager;
	}

	public function current_lang(): string {
		if ( defined( 'ICL_LANGUAGE_CODE' ) ) {
			return (string) ICL_LANGUAGE_CODE;
		}
		if ( function_exists( 'pll_current_language' ) ) {
			$lang = pll_current_language();
			return $lang ? (string) $lang : '';
		}
		return '';
	}

	/**
	 * Intercept standard WordPress search queries with Turbo Search.
	 * If the search engine or external servers encounter any error, it
	 * gracefully does nothing and allows WordPress Core to run its native search.
	 */
	public function intercept_core_search( \WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_search() ) {
			return;
		}

		$search_term = trim( (string) $query->get( 's' ) );
		if ( '' === $search_term ) {
			return;
		}

		try {
			$settings = Admin\Settings::get_all();
			if ( empty( $settings['enable_frontend_search'] ) && empty( $settings['enable_archive_live_filter'] ) ) {
				return;
			}

			$per_page = (int) ( $query->get( 'posts_per_page' ) ?: get_option( 'posts_per_page', 10 ) );
			$page     = max( 1, (int) ( $query->get( 'paged' ) ?: 1 ) );

			$filters = [];
			$pt = $query->get( 'post_type' );
			if ( ! empty( $pt ) ) {
				$filters['post_type'] = is_array( $pt ) ? $pt : [ $pt ];
			}

			$results = $this->get_engine()->search( $search_term, $filters, $per_page, $page );

			if ( ! empty( $results['hits'] ) ) {
				$post_ids  = array_values( array_filter( array_map( 'absint', wp_list_pluck( $results['hits'], 'post_id' ) ) ) );
				$hit_types = array_values( array_unique( array_filter( wp_list_pluck( $results['hits'], 'post_type' ) ) ) );
				if ( ! empty( $post_ids ) ) {
					$query->set( 'post__in', $post_ids );
					$query->set( 'orderby', 'post__in' );
					$query->set( 's', '' ); // Unset 's' so WP doesn't apply raw LIKE filtering on top of our ranked IDs

					// Ensure get_search_query() still returns the searched keyword in theme templates
					add_filter( 'get_search_query', function ( $val ) use ( $search_term ) {
						return ! empty( $val ) ? $val : $search_term;
					} );

					// If query post_type was empty or defaulted to 'post', update it so WP_Query allows all returned hit post_types
					$current_pt = $query->get( 'post_type' );
					if ( empty( $current_pt ) || 'post' === $current_pt ) {
						$target_pts = ! empty( $hit_types ) ? $hit_types : (array) ( $settings['post_types'] ?? [ 'post', 'page' ] );
						$query->set( 'post_type', count( $target_pts ) === 1 ? $target_pts[0] : $target_pts );
					}

					// Ensure post_status allows attachments if any hit is an attachment
					$active_pts = (array) $query->get( 'post_type' );
					if ( in_array( 'attachment', $active_pts, true ) || in_array( 'any', $active_pts, true ) ) {
						$query->set( 'post_status', [ 'publish', 'inherit' ] );
					}

					// Hook into found_posts filter so theme pagination matches total search engine hits
					if ( isset( $results['found'] ) && (int) $results['found'] > 0 ) {
						$total_found = (int) $results['found'];
						add_filter( 'found_posts', function ( $val, $q ) use ( $total_found, $query ) {
							if ( $q === $query ) {
								return $total_found;
							}
							return $val;
						}, 10, 2 );
					}
				}
			} else {
				// No results found in index: set post__in to [0] and s to '' so WP returns 0 results cleanly without raw LIKE search
				$query->set( 'post__in', [ 0 ] );
				$query->set( 's', '' );
				add_filter( 'get_search_query', function ( $val ) use ( $search_term ) {
					return ! empty( $val ) ? $val : $search_term;
				} );
			}
		} catch ( \Throwable $e ) {
			// On ANY server failure or missing dependency, do not halt execution.
			// WordPress Core will proceed with its native search query seamlessly.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[WPTS] Core search interception bypassed: ' . $e->getMessage() );
			}
		}
	}
}
