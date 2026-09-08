<?php
namespace WPTS\I18n;

defined( 'ABSPATH' ) || exit;

/**
 * Loads plugin text domain and wires multilingual plugin compatibility.
 */
class Loader {

	public function load(): void {
		// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound
		load_plugin_textdomain(
			'turbo-search',
			false,
			dirname( WPTS_BASENAME ) . '/languages'
		);

		// WPML: register strings for WPML String Translation
		add_action( 'init', [ $this, 'register_wpml_strings' ] );

		// Polylang: tell it which post types to make translatable
		add_filter( 'pll_get_post_types', [ $this, 'pll_post_types' ], 10, 2 );
	}

	public function register_wpml_strings(): void {
		if ( ! function_exists( 'icl_register_string' ) ) return;

		icl_register_string( 'turbo-search', 'search_placeholder', __( 'Search…', 'turbo-search' ) );
		icl_register_string( 'turbo-search', 'no_results',         __( 'No results found.', 'turbo-search' ) );
		/* translators: %d: results count */
		icl_register_string( 'turbo-search', 'results_count',      __( '%d results', 'turbo-search' ) );

		/**
		 * Action: wpts_register_wpml_strings
		 * Register additional plugin strings with WPML String Translation.
		 */
		do_action( 'wpts_register_wpml_strings' );
	}

	public function pll_post_types( array $types, bool $is_settings ): array {
		$types['wpts_resource'] = 'wpts_resource';
		return $types;
	}
}
