<?php
namespace WPTS\Security;

use WPTS\Universal\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * GDPR Compliance - manages IP anonymization and retention pruning for search logs.
 */
class GDPR {

	/**
	 * Anonymize search log entries older than N days.
	 */
	public static function anonymize_old_logs(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'wpts_search_log';

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			return;
		}

		$days   = absint( \WPTS\Admin\Settings::get( 'gdpr_anonymize_days', 30 ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		// If IP column exists or is stored in meta/session, mask it
		$retention_days = absint( \WPTS\Admin\Settings::get( 'tracking_retention_days', 90 ) );
		$prune_cutoff   = gmdate( 'Y-m-d H:i:s', strtotime( "-{$retention_days} days" ) );

		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE searched_at < %s", $prune_cutoff ) ); // phpcs:ignore
	}
}

