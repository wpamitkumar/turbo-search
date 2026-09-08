<?php
namespace WPTS\Admin;

use WPTS\Search\Synonyms;

defined( 'ABSPATH' ) || exit;

/**
 * One-Click Settings Export & Import between Staging and Production.
 */
class SettingsExporter {

	public static function export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden', 'turbo-search' ) );
		}

		$export_data = [
			'plugin'     => 'Turbo Search',
			'version'    => WPTS_VERSION,
			'exported_at'=> current_time( 'mysql' ),
			'settings'   => Settings::get_all(),
			'synonyms'   => Synonyms::get_custom_synonyms(),
		];

		$json     = wp_json_encode( $export_data, JSON_PRETTY_PRINT );
		$filename = sprintf( 'wpts-settings-backup-%s.json', gmdate( 'Y-m-d' ) );

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $json;
		exit;
	}

	/**
	 * Import settings from uploaded JSON file.
	 *
	 * @return array{success: bool, message: string}
	 */
	public static function import( array $file ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return [ 'success' => false, 'message' => __( 'Permission denied.', 'turbo-search' ) ];
		}

		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return [ 'success' => false, 'message' => __( 'Please choose a valid JSON file.', 'turbo-search' ) ];
		}

		$raw = file_get_contents( $file['tmp_name'] );
		if ( ! $raw ) {
			return [ 'success' => false, 'message' => __( 'Could not read uploaded file.', 'turbo-search' ) ];
		}

		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || empty( $data['settings'] ) ) {
			return [ 'success' => false, 'message' => __( 'Invalid backup file format.', 'turbo-search' ) ];
		}

		// Save settings
		Settings::save( (array) $data['settings'] );

		// Restore custom synonyms if provided
		if ( ! empty( $data['synonyms'] ) && is_array( $data['synonyms'] ) ) {
			foreach ( $data['synonyms'] as $s ) {
				if ( ! empty( $s['words'] ) ) {
					Synonyms::save_custom_synonym( (string) $s['words'] );
				}
			}
		}

		return [
			'success' => true,
			'message' => __( 'Settings imported successfully!', 'turbo-search' ),
		];
	}
}

