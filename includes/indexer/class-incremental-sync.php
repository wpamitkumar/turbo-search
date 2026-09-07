<?php
namespace WPTS\Indexer;

defined( 'ABSPATH' ) || exit;

/**
 * Handles incremental syncing - only re-indexes posts that have been modified since the last index timestamp.
 */
class IncrementalSync {

	public const OPTION_LAST_SYNC = 'wpts_last_incremental_sync';

	/**
	 * Run incremental sync for all tracked post types.
	 *
	 * @return array{processed: int, total_modified: int}
	 */
	public static function run(): array {
		$last_sync = (string) get_option( self::OPTION_LAST_SYNC, '2000-01-01 00:00:00' );
		$current_sync = current_time( 'mysql', true );

		$post_types = (array) \WPTS\Admin\Settings::get( 'post_types', [ 'post', 'page' ] );

		$query = new \WP_Query( [
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => 200,
			'fields'         => 'ids',
			'no_found_rows'  => false,
			'date_query'     => [
				[
					'column' => 'post_modified_gmt',
					'after'  => $last_sync,
				],
			],
		] );

		$processed = 0;
		$total     = (int) $query->found_posts;
		$core      = \WPTS\Core::instance();

		foreach ( $query->posts as $post_id ) {
			$post = get_post( (int) $post_id );
			if ( $post instanceof \WP_Post ) {
				$core->on_save_post( (int) $post_id, $post, true );
				$processed++;
			}
		}

		update_option( self::OPTION_LAST_SYNC, $current_sync, false );

		return [
			'processed'      => $processed,
			'total_modified' => $total,
		];
	}
}

