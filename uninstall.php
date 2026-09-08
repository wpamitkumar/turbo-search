<?php
/**
 * Uninstall script - runs when the plugin is deleted via WP admin.
 * Removes all plugin options and custom DB tables from every site.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$wpts_option_keys = [
	'wpts_post_types',
	'wpts_enable_frontend_search',
	'wpts_debounce_ms',
	'wpts_results_per_page',
	'wpts_typesense_host',
	'wpts_typesense_port',
	'wpts_typesense_protocol',
	'wpts_typesense_api_key',
	'wpts_typesense_collection',
	'wpts_highlight_results',
	'wpts_search_in_meta',
	'wpts_exclude_post_ids',
	'wpts_db_version',
	'wpts_tracking_enabled',
	'wpts_tracking_retention_days',
	'wpts_counter_total_searches',
	'wpts_counter_cache_hits',
	'wpts_counter_cache_misses',
	'wpts_counter_zero_result_searches',
	'wpts_counter_index_upserts',
	'wpts_counter_index_deletes',
	'wpts_counter_cache_flushes',
	'wpts_tracking_reset_at',
	'wpts_transient_ring',
];

function wpts_uninstall_site(): void {
	global $wpdb;

	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB
	// Drop custom tables
	$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}wpts_index`" );
	$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}wpts_events`" );
	$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}wpts_search_log`" );
	$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}wpts_synonyms`" );
	$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}wpts_analytics_summary`" );

	// Remove all wpts_ options
	$wpdb->query( "DELETE FROM `{$wpdb->options}` WHERE option_name LIKE 'wpts_%'" );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB
}

if ( is_multisite() ) {
	foreach ( get_sites( [ 'number' => 0, 'fields' => 'ids' ] ) as $blog_id ) {
		switch_to_blog( $blog_id );
		wpts_uninstall_site();
		restore_current_blog();
	}
} else {
	wpts_uninstall_site();
}
