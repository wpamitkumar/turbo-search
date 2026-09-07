<?php
namespace WPTS\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Integrates Turbo Search status checks with WordPress Site Health (Tools → Site Health).
 */
class SiteHealth {

	public static function init(): void {
		add_filter( 'site_status_tests', [ self::class, 'register_tests' ] );
	}

	public static function register_tests( array $tests ): array {
		$tests['direct']['wpts_engine_status'] = [
			'label' => __( 'Turbo Search Engine Status', 'turbo-search' ),
			'test'  => [ self::class, 'test_engine_status' ],
		];
		$tests['direct']['wpts_index_coverage'] = [
			'label' => __( 'Turbo Search Index Coverage', 'turbo-search' ),
			'test'  => [ self::class, 'test_index_coverage' ],
		];
		return $tests;
	}

	public static function test_engine_status(): array {
		$engine = \WPTS\Core::instance()->get_engine();
		$driver = $engine->get_driver();
		$status = $engine->status();

		if ( 'mysql' === $driver || ! empty( $status['connected'] ) ) {
			return [
				'label'       => sprintf( __( 'Search Engine (%s) is healthy', 'turbo-search' ), strtoupper( $driver ) ),
				'status'      => 'good',
				'badge'       => [
					'label' => __( 'Turbo Search', 'turbo-search' ),
					'color' => 'blue',
				],
				'description' => sprintf(
					__( 'The active search engine (%s) and cache driver (%s) are connected and serving results.', 'turbo-search' ),
					strtoupper( $driver ),
					strtoupper( $status['driver'] ?? 'unknown' )
				),
				'actions'     => '',
				'test'        => 'wpts_engine_status',
			];
		}

		return [
			'label'       => sprintf( __( 'Search Engine (%s) is unreachable', 'turbo-search' ), strtoupper( $driver ) ),
			'status'      => 'critical',
			'badge'       => [
				'label' => __( 'Turbo Search', 'turbo-search' ),
				'color' => 'red',
			],
			'description' => __( 'The configured search engine is unreachable. Searches may be falling back to MySQL.', 'turbo-search' ),
			'actions'     => sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=wpts-settings' ) ),
				__( 'Check Engine Settings', 'turbo-search' )
			),
			'test'        => 'wpts_engine_status',
		];
	}

	public static function test_index_coverage(): array {
		$engine     = \WPTS\Core::instance()->get_engine();
		$count      = $engine->get_indexed_count();
		$post_types = (array) Settings::get( 'post_types', [ 'post', 'page' ] );
		if ( ! empty( Settings::get( 'index_attachments' ) ) && ! in_array( 'attachment', $post_types, true ) ) {
			$post_types[] = 'attachment';
		}

		$total_pub = 0;
		foreach ( $post_types as $pt ) {
			$cnt = wp_count_posts( $pt );
			if ( 'attachment' === $pt ) {
				$total_pub += (int) ( $cnt->inherit ?? 0 );
			} else {
				$total_pub += (int) ( $cnt->publish ?? 0 );
			}
		}

		if ( $total_pub === 0 || $count >= ( $total_pub * 0.9 ) ) {
			return [
				'label'       => sprintf( __( 'Search index is up to date (%d of %d posts)', 'turbo-search' ), $count, $total_pub ),
				'status'      => 'good',
				'badge'       => [
					'label' => __( 'Turbo Search', 'turbo-search' ),
					'color' => 'blue',
				],
				'description' => __( 'Search index coverage is optimal.', 'turbo-search' ),
				'actions'     => '',
				'test'        => 'wpts_index_coverage',
			];
		}

		return [
			'label'       => sprintf( __( 'Search index needs updating (%d of %d posts indexed)', 'turbo-search' ), $count, $total_pub ),
			'status'      => 'recommended',
			'badge'       => [
				'label' => __( 'Turbo Search', 'turbo-search' ),
				'color' => 'orange',
			],
			'description' => __( 'Some published posts may not be in the search index yet. Run a re-index to update search results.', 'turbo-search' ),
			'actions'     => sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=wpts-index' ) ),
				__( 'Go to Index Manager', 'turbo-search' )
			),
			'test'        => 'wpts_index_coverage',
		];
	}
}

