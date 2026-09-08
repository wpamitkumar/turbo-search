<?php
namespace WPTS;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the wpts/search-bar Gutenberg block.
 *
 * Script registration is done entirely in PHP (not via block.json file: paths)
 * so we have full control over handles, dependencies, and localization order.
 */
class Block {

	// Script/style handle constants - used in both register and localize calls
	public const EDITOR_SCRIPT_HANDLE = 'wpts-block-editor';
	public const EDITOR_STYLE_HANDLE  = 'wpts-block-editor-style';
	public const FRONTEND_STYLE_HANDLE = 'wpts-block-style';

	public function register(): void {
		add_action( 'init', [ $this, 'register_assets' ] );
		add_action( 'init', [ $this, 'register_block'  ], 20 ); // after assets at default priority
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_editor_assets' ] );
	}

	public function enqueue_editor_assets(): void {
		wp_enqueue_script( self::EDITOR_SCRIPT_HANDLE );
		wp_enqueue_style( self::EDITOR_STYLE_HANDLE );
		$this->localize_editor_script();
	}

	// Step 1: Register all scripts/styles

	public function register_assets(): void {
		$js_ver  = file_exists( WPTS_DIR . 'blocks/search-block/build/index.js' )  ? (string) filemtime( WPTS_DIR . 'blocks/search-block/build/index.js' )  : WPTS_VERSION;
		$css_ver = file_exists( WPTS_DIR . 'blocks/search-block/build/index.css' ) ? (string) filemtime( WPTS_DIR . 'blocks/search-block/build/index.css' ) : WPTS_VERSION;

		// Editor script - depends on all required WP block editor packages
		wp_register_script(
			self::EDITOR_SCRIPT_HANDLE,
			WPTS_URL . 'blocks/search-block/build/index.js',
			[
				'wp-blocks',
				'wp-element',
				'wp-block-editor',
				'wp-components',
				'wp-i18n',
			],
			$js_ver,
			true
		);

		// Localize editor data immediately after registration
		$this->localize_editor_script();

		// Editor style
		wp_register_style(
			self::EDITOR_STYLE_HANDLE,
			WPTS_URL . 'blocks/search-block/build/index.css',
			[ 'wp-block-editor' ],
			$css_ver
		);

		// Frontend block wrapper style (tiny - just box-sizing)
		wp_register_style(
			self::FRONTEND_STYLE_HANDLE,
			WPTS_URL . 'blocks/search-block/build/style.css',
			[],
			WPTS_VERSION
		);
	}

	// Step 2: Register block type with explicit script/style handles

	public function register_block(): void {
		if ( ! function_exists( 'register_block_type' ) ) return;

		register_block_type(
			WPTS_DIR . 'blocks/search-block/block.json',
			[
				'render_callback' => [ $this, 'render' ],
				'editor_script'   => self::EDITOR_SCRIPT_HANDLE,
				'editor_style'    => self::EDITOR_STYLE_HANDLE,
				'style'           => self::FRONTEND_STYLE_HANDLE,
			]
		);
	}

	// Localize editor data

	private function localize_editor_script(): void {
		$settings   = Admin\Settings::get_all();
		$post_types = get_post_types( [ 'public' => true ], 'objects' );
		$pt_list    = [];

		foreach ( $post_types as $pt ) {
			$pt_list[] = [ 'slug' => $pt->name, 'label' => $pt->label ];
		}
		if ( ! empty( $settings['index_attachments'] ) && ! isset( $post_types['attachment'] ) ) {
			$pt_list[] = [ 'slug' => 'attachment', 'label' => __( 'Media & Documents', 'turbo-search' ) ];
		}

		wp_localize_script(
			self::EDITOR_SCRIPT_HANDLE,
			'wptsBlock',
			[
				'postTypes' => $pt_list,
				'root'      => esc_url_raw( rest_url() ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'defaults'  => [
					'debounce'      => (int) ( $settings['debounce_ms']      ?? 200 ),
					'perPage'       => (int) ( $settings['results_per_page'] ?? 8   ),
					'defaultLayout' => (string) ( $settings['results_layout'] ?? 'list' ),
				],
			]
		);
	}

	// Server-side render callback

	/**
	 * Renders the search widget HTML with all styling as CSS variables
	 * and behaviour config as data-* attributes read by search.js.
	 *
	 * @param array $attrs Block attributes from the editor.
	 * @return string HTML output.
	 */
	public function render( array $attrs ): string {
		$defaults = [
			'placeholder'       => __( 'Search…', 'turbo-search' ),
			'postType'          => '',
			'perPage'           => 8,
			'debounce'          => 200,
			'throttle'          => 0,
			'minChars'          => 2,
			'showPostType'      => true,
			'showExcerpt'       => true,
			'showVoice'         => class_exists( '\WPTS\Admin\Settings' ) ? (bool) \WPTS\Admin\Settings::get( 'enable_voice_search', true ) : true,
			'enableCommandK'    => true,
			'categoryTabs'      => true,
			'quickAddToCart'    => true,
			'layout'            => class_exists( '\WPTS\Admin\Settings' ) ? \WPTS\Admin\Settings::get( 'results_layout', 'list' ) : 'list',
			'openInNewTab'      => false,
			'primaryColor'      => '#2563eb',
			'backgroundColor'   => '#ffffff',
			'textColor'         => '#1e293b',
			'borderColor'       => '#e2e8f0',
			'borderRadius'      => 10,
			'inputSize'         => 'medium',
			'maxWidth'          => 640,
			'dropdownMaxHeight' => 400,
			'highlightColor'    => '#fef08a',
			'theme'             => 'light',
		];

		$a = wp_parse_args( $attrs, $defaults );

		// Map input size to height
		$size_h = [ 'small' => '42px', 'medium' => '52px', 'large' => '62px' ];

		// Build inline CSS variables (no color-mix - full browser compat)
		$primary = sanitize_hex_color( $a['primaryColor']    ) ?: '#2563eb';
		$bg      = sanitize_hex_color( $a['backgroundColor'] ) ?: '#ffffff';

		// Inline hex → R,G,B conversion for rgba() CSS values
		$p_hex = ltrim( $primary, '#' );
		if ( strlen( $p_hex ) === 3 ) $p_hex = $p_hex[0].$p_hex[0].$p_hex[1].$p_hex[1].$p_hex[2].$p_hex[2];
		$pr    = hexdec( substr( $p_hex, 0, 2 ) );
		$pg    = hexdec( substr( $p_hex, 2, 2 ) );
		$pb    = hexdec( substr( $p_hex, 4, 2 ) );
		$p_rgb = "{$pr},{$pg},{$pb}";

		// Subtle bg: slightly lighter/darker based on brightness
		$bg_hex = ltrim( $bg, '#' );
		if ( strlen( $bg_hex ) === 3 ) $bg_hex = $bg_hex[0].$bg_hex[0].$bg_hex[1].$bg_hex[1].$bg_hex[2].$bg_hex[2];
		$sr        = hexdec( substr( $bg_hex, 0, 2 ) );
		$sg        = hexdec( substr( $bg_hex, 2, 2 ) );
		$sbv       = hexdec( substr( $bg_hex, 4, 2 ) );
		$adj       = 8;
		$bg_subtle = sprintf( '#%02x%02x%02x',
			(int) min( 255, max( 0, $sr  + ( $sr  < 128 ? $adj : -$adj ) ) ),
			(int) min( 255, max( 0, $sg  + ( $sg  < 128 ? $adj : -$adj ) ) ),
			(int) min( 255, max( 0, $sbv + ( $sbv < 128 ? $adj : -$adj ) ) )
		);

		$css_vars = implode( ';', [
			'--wpts-primary:'       . $primary,
			'--wpts-primary-soft:'  . "rgba({$p_rgb},0.12)",
			'--wpts-primary-ring:'  . "rgba({$p_rgb},0.20)",
			'--wpts-primary-hover:' . "rgba({$p_rgb},0.08)",
			'--wpts-bg:'            . $bg,
			'--wpts-bg-subtle:'     . $bg_subtle,
			'--wpts-text:'          . sanitize_hex_color( $a['textColor']      ),
			'--wpts-border:'        . sanitize_hex_color( $a['borderColor']    ),
			'--wpts-highlight:'     . sanitize_hex_color( $a['highlightColor'] ),
			'--wpts-radius:'        . absint( $a['borderRadius'] ) . 'px',
			'--wpts-input-h:'       . ( $size_h[ $a['inputSize'] ] ?? '52px' ),
			'--wpts-max-height:'    . absint( $a['dropdownMaxHeight'] ) . 'px',
		] );

		$max_w = absint( $a['maxWidth'] );
		$style = ( $max_w > 0 )
			? 'width:100%;max-width:' . $max_w . 'px;' . $css_vars
			: 'width:100%;' . $css_vars;

		/**
		 * Filter: wpts_block_render_attrs
		 * Modify block attributes before the HTML is rendered.
		 *
		 * @param array $a     Merged block attributes.
		 * @param array $attrs Original raw attributes from the editor.
		 */
		$a = apply_filters( 'wpts_block_render_attrs', $a, $attrs );

		// Enqueue frontend search assets on demand
		$this->enqueue_frontend();

		$raw_pt = ! empty( $a['postTypes'] ) ? $a['postTypes'] : ( $a['postType'] ?? '' );
		if ( is_array( $raw_pt ) ) {
			$clean_pts = array_filter( array_map( 'sanitize_key', $raw_pt ) );
			$post_type_str = implode( ',', $clean_pts );
		} else {
			$pt_parts = array_filter( array_map( 'trim', explode( ',', (string) $raw_pt ) ) );
			$clean_pts = array_filter( array_map( 'sanitize_key', $pt_parts ) );
			$post_type_str = implode( ',', $clean_pts );
		}

		return '<div'
			. ' class="wpts-search-block wpts-search wpts-theme-' . esc_attr( $a['theme'] ) . '"'
			. ' style="'        . esc_attr( $style )                     . '"'
			. ' data-wpts-search'
			. ' data-placeholder="'      . esc_attr( $a['placeholder'] )     . '"'
			. ' data-post-type="'        . esc_attr( $post_type_str )        . '"'
			. ' data-per-page="'         . absint(   $a['perPage'] )         . '"'
			. ' data-debounce="'         . absint(   $a['debounce'] )        . '"'
			. ' data-throttle="'         . absint(   $a['throttle'] )        . '"'
			. ' data-min-chars="'        . absint(   $a['minChars'] )        . '"'
			. ' data-show-type="'        . ( ! empty( $a['showPostType'] )   ? '1' : '0' ) . '"'
			. ' data-show-excerpt="'     . ( ! empty( $a['showExcerpt'] )    ? '1' : '0' ) . '"'
			. ' data-show-voice="'       . ( ! empty( $a['showVoice'] )      ? '1' : '0' ) . '"'
			. ' data-enable-command-k="' . ( ! empty( $a['enableCommandK'] ) ? '1' : '0' ) . '"'
			. ' data-category-tabs="'    . ( ! empty( $a['categoryTabs'] )   ? '1' : '0' ) . '"'
			. ' data-quick-cart="'       . ( ! empty( $a['quickAddToCart'] ) ? '1' : '0' ) . '"'
			. ' data-layout="'           . esc_attr( ! empty( $a['layout'] ) ? $a['layout'] : ( class_exists( '\WPTS\Admin\Settings' ) ? \WPTS\Admin\Settings::get( 'results_layout', 'list' ) : 'list' ) ) . '"'
			. ' data-new-tab="'          . ( ! empty( $a['openInNewTab'] )   ? '1' : '0' ) . '"'
			. ' data-input-size="'       . esc_attr( $a['inputSize'] )       . '"'
			. ' data-theme="'            . esc_attr( $a['theme'] )           . '"'
			. '></div>';
	}

	// Frontend asset enqueue (called from render)

	private function enqueue_frontend(): void {
		if ( function_exists( 'wpts_enqueue_search_assets' ) ) {
			wpts_enqueue_search_assets();
		}
	}
}
