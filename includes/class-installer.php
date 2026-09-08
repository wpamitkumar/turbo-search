<?php
namespace WPTS;

use WPTS\Search\Synonyms;

defined( 'ABSPATH' ) || exit;

/**
 * Handles plugin activation, deactivation, table creations, and migrations.
 */
class Installer {

	public static function activate( bool $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites( [ 'number' => 0, 'fields' => 'ids' ] ) as $blog_id ) {
				switch_to_blog( $blog_id );
				self::run_for_site();
				restore_current_blog();
			}
		} else {
			self::run_for_site();
		}
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		flush_rewrite_rules();
	}

	public static function on_new_blog( \WP_Site $new_site ): void {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( is_plugin_active_for_network( WPTS_BASENAME ) ) {
			switch_to_blog( (int) $new_site->blog_id );
			self::run_for_site();
			restore_current_blog();
		}
	}

	public static function run_for_site(): void {
		self::create_tables();
		Tracker::create_tables();
		Synonyms::create_table();
		Admin\Settings::seed_defaults_if_missing();
		update_option( 'wpts_db_version', WPTS_VERSION );
	}

	private static bool $ensured = false;

	public static function ensure_tables(): void {
		if ( self::$ensured ) {
			return;
		}
		self::$ensured = true;

		// If DB tables have already been created and migrated for this version, skip heavy DDL checks
		if ( WPTS_VERSION === get_option( 'wpts_db_version' ) ) {
			return;
		}

		global $wpdb;
		$table  = $wpdb->prefix . 'wpts_index';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB
		if ( ! $exists ) {
			self::create_tables();
			Tracker::create_tables();
			Synonyms::create_table();
			Admin\Settings::seed_defaults_if_missing();
		} else {
			Synonyms::create_table();
			Tracker::create_tables();
			self::maybe_migrate_columns();
		}
		update_option( 'wpts_db_version', WPTS_VERSION );
	}

	public static function create_tables(): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$table   = $wpdb->prefix . 'wpts_index';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB
		$wpdb->query( "
			CREATE TABLE IF NOT EXISTS `{$table}` (
			  `id`         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			  `post_id`    BIGINT(20) UNSIGNED NOT NULL,
			  `post_type`  VARCHAR(50) NOT NULL DEFAULT 'post',
			  `lang`       VARCHAR(10) NOT NULL DEFAULT '',
			  `site_id`    BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
			  `title`      TEXT NOT NULL,
			  `content`    LONGTEXT NOT NULL,
			  `excerpt`    TEXT NULL,
			  `meta_json`  LONGTEXT NULL,
			  `indexed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			  PRIMARY KEY (`id`),
			  UNIQUE KEY `post_site` (`post_id`, `site_id`),
			  KEY `idx_post_id` (`post_id`),
			  KEY `idx_site_id` (`site_id`),
			  KEY `idx_lang`    (`lang`)
			) ENGINE=InnoDB {$charset}
		" );

		// FULLTEXT index
		$wpdb->suppress_errors( true );
		$ft = $wpdb->get_var( "SHOW INDEX FROM `{$table}` WHERE Index_type = 'FULLTEXT' AND Key_name = 'ft_all'" );
		if ( ! $ft ) {
			$wpdb->query( "ALTER TABLE `{$table}` ADD FULLTEXT KEY `ft_all` (`title`,`content`,`excerpt`)" );
		}
		$wpdb->suppress_errors( false );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB
	}

	private static function maybe_migrate_columns(): void {
		global $wpdb;

		$index_table = $wpdb->prefix . 'wpts_index';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB
		$existing_cols = $wpdb->get_col( "DESCRIBE `{$index_table}`", 0 );
		if ( is_array( $existing_cols ) ) {
			$cols_to_check = [
				'post_id'    => "ALTER TABLE `{$index_table}` ADD COLUMN `post_id` BIGINT(20) UNSIGNED NOT NULL AFTER `id`",
				'post_type'  => "ALTER TABLE `{$index_table}` ADD COLUMN `post_type` VARCHAR(50) NOT NULL DEFAULT 'post' AFTER `post_id`",
				'lang'       => "ALTER TABLE `{$index_table}` ADD COLUMN `lang` VARCHAR(10) NOT NULL DEFAULT '' AFTER `post_type`",
				'site_id'    => "ALTER TABLE `{$index_table}` ADD COLUMN `site_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 1 AFTER `lang`",
				'title'      => "ALTER TABLE `{$index_table}` ADD COLUMN `title` TEXT NOT NULL AFTER `site_id`",
				'content'    => "ALTER TABLE `{$index_table}` ADD COLUMN `content` LONGTEXT NOT NULL AFTER `title`",
				'excerpt'    => "ALTER TABLE `{$index_table}` ADD COLUMN `excerpt` TEXT NULL AFTER `content`",
				'meta_json'  => "ALTER TABLE `{$index_table}` ADD COLUMN `meta_json` LONGTEXT NULL AFTER `excerpt`",
				'indexed_at' => "ALTER TABLE `{$index_table}` ADD COLUMN `indexed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `meta_json`",
			];

			foreach ( $cols_to_check as $col_name => $alter_sql ) {
				if ( ! in_array( $col_name, $existing_cols, true ) ) {
					$wpdb->query( $alter_sql );
				}
			}

			$wpdb->suppress_errors( true );
			$ft = $wpdb->get_var( "SHOW INDEX FROM `{$index_table}` WHERE Index_type = 'FULLTEXT' AND Key_name = 'ft_all'" );
			if ( ! $ft ) {
				$wpdb->query( "ALTER TABLE `{$index_table}` ADD FULLTEXT KEY `ft_all` (`title`,`content`,`excerpt`)" );
			}
			$wpdb->suppress_errors( false );
		}

		$search_table = $wpdb->prefix . Tracker::TABLE_SUFFIX_SEARCH;

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $search_table ) ) ) {
			// Check if clicked_position exists
			$col = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$search_table}` LIKE %s", 'clicked_position' ) );
			if ( ! $col ) {
				$wpdb->query( "ALTER TABLE `{$search_table}` ADD COLUMN `clicked_position` SMALLINT UNSIGNED NULL DEFAULT 0 AFTER `duration_ms`" );
				$wpdb->query( "ALTER TABLE `{$search_table}` ADD COLUMN `variant` CHAR(1) NOT NULL DEFAULT 'A' AFTER `clicked_position`" );
				$wpdb->query( "ALTER TABLE `{$search_table}` ADD COLUMN `user_id` BIGINT(20) UNSIGNED NULL DEFAULT 0 AFTER `variant`" );
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB
	}
}

add_action( 'wp_initialize_site', [ 'WPTS\\Installer', 'on_new_blog' ] );
