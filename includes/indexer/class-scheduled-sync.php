<?php
namespace WPTS\Indexer;

defined( 'ABSPATH' ) || exit;

/**
 * Manages WP-Cron automated scheduled re-indexing (nightly / weekly / daily).
 */
class ScheduledSync {

	public const CRON_HOOK = 'wpts_scheduled_sync_event';

	public static function init(): void {
		add_action( self::CRON_HOOK, [ self::class, 'handle_cron' ] );
		self::reschedule();
	}

	/**
	 * Reschedule cron event based on settings.
	 */
	public static function reschedule(): void {
		$enabled  = (bool) \WPTS\Admin\Settings::get( 'scheduled_reindex_enabled', false );
		$schedule = (string) \WPTS\Admin\Settings::get( 'scheduled_reindex_interval', 'daily' );

		$timestamp = wp_next_scheduled( self::CRON_HOOK );

		if ( ! $enabled ) {
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, self::CRON_HOOK );
			}
			return;
		}

		if ( ! $timestamp ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, $schedule, self::CRON_HOOK );
		}
	}

	/**
	 * Executes the scheduled reindex sync.
	 */
	public static function handle_cron(): void {
		$mode = (string) \WPTS\Admin\Settings::get( 'scheduled_reindex_mode', 'incremental' );

		if ( 'full' === $mode ) {
			\WPTS\Core::instance()->get_engine()->reindex_all();
		} else {
			IncrementalSync::run();
		}
	}
}

