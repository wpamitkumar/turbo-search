<?php
/**
 * Shortcodes & Widgets:
 *   [wpts_search]    – Instant search bar with dropdown/grid/card
 *   [wpts_trending]  – Trending searches widget
 *   WPTS_Search_Widget – Classic widget
 */

use WPTS\Analytics\Trending;

defined( 'ABSPATH' ) || exit;

// Shortcodes
add_shortcode( 'wpts_search',   'wpts_render_search_shortcode' );
add_shortcode( 'wpts_trending', 'wpts_render_trending_shortcode' );

function wpts_render_trending_shortcode( $atts ): string {
	return Trending::render_html( (array) $atts );
}

function wpts_render_search_shortcode( $atts ): string {
	if ( is_array( $atts ) ) {
		if ( isset( $atts['post_types'] ) && ! isset( $atts['post_type'] ) ) {
			$atts['post_type'] = $atts['post_types'];
		}
	}

	$default_layout = class_exists( '\WPTS\Admin\Settings' ) ? \WPTS\Admin\Settings::get( 'results_layout', 'list' ) : 'list';
	$default_voice  = class_exists( '\WPTS\Admin\Settings' ) && ! \WPTS\Admin\Settings::get( 'enable_voice_search', true ) ? 0 : 1;

	$atts = shortcode_atts( [
		'placeholder'      => __( 'Search…', 'turbo-search' ),
		'post_type'        => '',
		'post_types'       => '',
		'per_page'         => 8,
		'debounce'         => 200,
		'throttle'         => 0,
		'min_chars'        => 2,
		'show_type'        => 1,
		'show_excerpt'     => 1,
		'show_voice'       => $default_voice,
		'enable_command_k' => 1,
		'category_tabs'    => 1,
		'quick_cart'       => 1,
		'layout'           => $default_layout, // list | grid | card
		'new_tab'          => 0,
		'theme'            => 'light',
		'input_size'       => 'medium',
		'max_width'        => 0,
		'max_height'       => 400,
		'primary_color'    => '#2563eb',
		'bg_color'         => '#ffffff',
		'text_color'       => '#1e293b',
		'border_color'     => '#e2e8f0',
		'highlight_color'  => '#fef08a',
		'border_radius'    => 10,
		'class'            => '',
	], $atts, 'wpts_search' );

	$atts = apply_filters( 'wpts_shortcode_atts', $atts );
	return wpts_build_search_html( $atts );
}

function wpts_build_search_html( array $a ): string {
	$raw_pt = ! empty( $a['post_types'] ) ? $a['post_types'] : ( $a['post_type'] ?? '' );
	if ( is_array( $raw_pt ) ) {
		$clean_pts = array_filter( array_map( 'sanitize_key', $raw_pt ) );
		$post_type_str = implode( ',', $clean_pts );
	} else {
		$pt_parts = array_filter( array_map( 'trim', explode( ',', (string) $raw_pt ) ) );
		$clean_pts = array_filter( array_map( 'sanitize_key', $pt_parts ) );
		$post_type_str = implode( ',', $clean_pts );
	}

	$primary   = sanitize_hex_color( $a['primary_color'] ) ?: '#2563eb';
	$bg        = sanitize_hex_color( $a['bg_color'] ) ?: '#ffffff';
	$text      = sanitize_hex_color( $a['text_color'] ) ?: '#1e293b';
	$border    = sanitize_hex_color( $a['border_color'] ) ?: '#e2e8f0';
	$highlight = sanitize_hex_color( $a['highlight_color'] ) ?: '#fef08a';
	$radius    = absint( $a['border_radius'] );

	$size_h  = [ 'small' => '42px', 'medium' => '52px', 'large' => '62px' ];
	$input_h = $size_h[ $a['input_size'] ] ?? '52px';
	$theme   = in_array( $a['theme'], [ 'light', 'dark', 'minimal', 'glass', 'custom' ], true ) ? $a['theme'] : 'light';

	$p_hex = ltrim( $primary, '#' );
	if ( strlen( $p_hex ) === 3 ) {
		$p_hex = $p_hex[0].$p_hex[0].$p_hex[1].$p_hex[1].$p_hex[2].$p_hex[2];
	}
	$pr    = hexdec( substr( $p_hex, 0, 2 ) );
	$pg    = hexdec( substr( $p_hex, 2, 2 ) );
	$pb    = hexdec( substr( $p_hex, 4, 2 ) );
	$p_rgb = "{$pr},{$pg},{$pb}";

	$css_vars = implode( ';', [
		'--wpts-primary:'       . $primary,
		'--wpts-primary-soft:'  . "rgba({$p_rgb},0.12)",
		'--wpts-primary-ring:'  . "rgba({$p_rgb},0.20)",
		'--wpts-primary-hover:' . "rgba({$p_rgb},0.08)",
		'--wpts-bg:'            . $bg,
		'--wpts-text:'          . $text,
		'--wpts-border:'        . $border,
		'--wpts-highlight:'     . $highlight,
		'--wpts-radius:'        . $radius . 'px',
		'--wpts-input-h:'       . $input_h,
		'--wpts-max-height:'    . absint( $a['max_height'] ) . 'px',
	] );

	$max_w = absint( $a['max_width'] );
	$style = ( $max_w > 0 ) ? 'width:100%;max-width:' . $max_w . 'px;' . $css_vars : 'width:100%;' . $css_vars;
	$extra_class = ! empty( $a['class'] ) ? ' ' . sanitize_html_class( $a['class'] ) : '';

	wpts_enqueue_search_assets();

	return '<div class="wpts-wrap" style="display:block;width:100%;">'
		. '<div'
		. ' class="wpts-search-block wpts-search wpts-theme-' . esc_attr( $theme ) . esc_attr( $extra_class ) . '"'
		. ' style="'        . esc_attr( $style )              . '"'
		. ' data-wpts-search'
		. ' data-placeholder="'      . esc_attr( $a['placeholder'] )   . '"'
		. ' data-post-type="'        . esc_attr( $post_type_str )      . '"'
		. ' data-per-page="'         . absint(   $a['per_page'] )      . '"'
		. ' data-debounce="'         . absint(   $a['debounce'] )      . '"'
		. ' data-throttle="'         . absint(   $a['throttle'] )      . '"'
		. ' data-min-chars="'        . absint(   $a['min_chars'] )     . '"'
		. ' data-show-type="'        . ( ! empty( $a['show_type'] )        ? '1' : '0' ) . '"'
		. ' data-show-excerpt="'     . ( ! empty( $a['show_excerpt'] )     ? '1' : '0' ) . '"'
		. ' data-show-voice="'       . ( ! empty( $a['show_voice'] )       ? '1' : '0' ) . '"'
		. ' data-enable-command-k="' . ( ! empty( $a['enable_command_k'] ) ? '1' : '0' ) . '"'
		. ' data-category-tabs="'    . ( ! empty( $a['category_tabs'] )    ? '1' : '0' ) . '"'
		. ' data-quick-cart="'       . ( ! empty( $a['quick_cart'] )       ? '1' : '0' ) . '"'
		. ' data-layout="'           . esc_attr( $a['layout'] )        . '"'
		. ' data-new-tab="'          . ( ! empty( $a['new_tab'] )          ? '1' : '0' ) . '"'
		. ' data-input-size="'       . esc_attr( $a['input_size'] )    . '"'
		. ' data-theme="'            . esc_attr( $theme )              . '"'
		. '></div></div>';
}

function wpts_enqueue_search_assets(): void {
	static $registered = false;

	if ( ! wp_script_is( 'wpts-search', 'registered' ) ) {
		$css_ver = file_exists( WPTS_DIR . 'assets/css/search.css' ) ? (string) filemtime( WPTS_DIR . 'assets/css/search.css' ) : WPTS_VERSION;
		$js_ver  = file_exists( WPTS_DIR . 'assets/js/search.js' ) ? (string) filemtime( WPTS_DIR . 'assets/js/search.js' ) : WPTS_VERSION;
		wp_register_style( 'wpts-search', WPTS_URL . 'assets/css/search.css', [], $css_ver );
		wp_register_script( 'wpts-search', WPTS_URL . 'assets/js/search.js', [], $js_ver, true );
	}

	wp_enqueue_style( 'wpts-search' );
	wp_enqueue_script( 'wpts-search' );

	if ( ! $registered ) {
		$registered = true;
		$settings   = class_exists( '\WPTS\Admin\Settings' ) ? \WPTS\Admin\Settings::get_all() : [];
		wp_localize_script( 'wpts-search', 'WPTS', [
			'root'           => esc_url_raw( rest_url() ),
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'ajax_url'       => admin_url( 'admin-ajax.php' ),
			'ajax_nonce'     => wp_create_nonce( 'wpts_search' ),
			'is_logged_in'   => is_user_logged_in(),
			'lang'           => class_exists( '\WPTS\Core' ) ? ( \WPTS\Core::instance()->current_lang() ?: get_locale() ) : get_locale(),
			'results_layout' => $settings['results_layout'] ?? 'list',
			'debounce_ms'    => (int) ( $settings['debounce_ms'] ?? 200 ),
			'i18n'           => [
				'voice_listening'   => __( 'Listening… Speak now', 'turbo-search' ),
				'voice_search'      => __( 'Voice search', 'turbo-search' ),
				'voice_denied'      => __( 'Microphone permission was denied. Please allow microphone access in your browser settings to use voice search.', 'turbo-search' ),
				'voice_https'       => __( 'Voice search requires a secure HTTPS connection. Please use HTTPS or localhost.', 'turbo-search' ),
				'voice_unsupported' => __( 'Voice search is not supported in this browser.', 'turbo-search' ),
				'voice_firefox'     => __( '🎙️ Voice search is supported in Chrome, Edge, Safari, and Opera. On Firefox, please type your query.', 'turbo-search' ),
				'clear'             => __( 'Clear search', 'turbo-search' ),
				'recent'            => __( 'Recent Searches', 'turbo-search' ),
				'favorites'         => __( 'Favorites', 'turbo-search' ),
				'no_favorites'      => __( 'No saved favorites yet.', 'turbo-search' ),
				'did_you_mean'      => __( 'Did you mean:', 'turbo-search' ),
				'view_all'          => __( 'View all results →', 'turbo-search' ),
				'add_to_cart'       => __( 'Add to Cart', 'turbo-search' ),
				'added_to_cart'     => __( 'Added to cart!', 'turbo-search' ),
				'no_results'        => __( 'No results found.', 'turbo-search' ),
				'searching'         => __( 'Searching…', 'turbo-search' ),
			],
		] );

		wp_set_script_translations( 'wpts-search', 'turbo-search', WPTS_DIR . 'languages' );
	}
}

add_action( 'wp_enqueue_scripts', 'wpts_maybe_enqueue_search_assets' );
function wpts_maybe_enqueue_search_assets(): void {
	if ( ! is_admin() ) {
		wpts_enqueue_search_assets();
	}
}

// Widget
add_action( 'widgets_init', function () {
	if ( class_exists( '\WP_Widget' ) ) {
		require_once WPTS_DIR . 'includes/class-widget.php';
		register_widget( 'WPTS_Search_Widget' );
	}
} );
