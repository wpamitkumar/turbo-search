<?php
namespace WPTS\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Main Admin Controller for Turbo Search.
 * Enqueues React dependencies (wp-element, wp-components, wp-api-fetch) and mounts the React SPA.
 */
class Page {

	public function init(): void {
		add_action( 'admin_menu', [ $this, 'register_menus' ], 4 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
		add_filter( 'plugin_action_links_' . WPTS_BASENAME, [ $this, 'add_settings_link' ] );
	}

	public function register_menus(): void {
		add_menu_page(
			__( 'Turbo Search', 'turbo-search' ),
			__( 'Turbo Search', 'turbo-search' ),
			'manage_options',
			'wpts-dashboard',
			[ $this, 'render_react_app_page' ],
			'dashicons-search',
			58
		);

		// 1. Dashboard
		add_submenu_page(
			'wpts-dashboard',
			__( 'Dashboard & Analytics', 'turbo-search' ),
			__( 'Dashboard', 'turbo-search' ),
			'manage_options',
			'wpts-dashboard',
			[ $this, 'render_react_app_page' ]
		);

		// 2. Index Manager
		add_submenu_page(
			'wpts-dashboard',
			__( 'Index Manager', 'turbo-search' ),
			__( 'Index Manager', 'turbo-search' ),
			'manage_options',
			'wpts-index',
			[ $this, 'render_react_app_page' ]
		);

		// 3. Settings
		add_submenu_page(
			'wpts-dashboard',
			__( 'Search Settings', 'turbo-search' ),
			__( 'Settings', 'turbo-search' ),
			'manage_options',
			'wpts-settings',
			[ $this, 'render_react_app_page' ]
		);

		// 4. Cache
		add_submenu_page(
			'wpts-dashboard',
			__( 'Cache Settings', 'turbo-search' ),
			__( 'Cache', 'turbo-search' ),
			'manage_options',
			'wpts-cache',
			[ $this, 'render_react_app_page' ]
		);

		// 5. Tracking and Analytics
		add_submenu_page(
			'wpts-dashboard',
			__( 'Tracking & Search Log', 'turbo-search' ),
			__( 'Tracking and Analytics', 'turbo-search' ),
			'manage_options',
			'wpts-tracking',
			[ $this, 'render_react_app_page' ]
		);

		// 6. Dev Hooks
		add_submenu_page(
			'wpts-dashboard',
			__( 'Developer Hooks Reference', 'turbo-search' ),
			__( 'Dev Hooks', 'turbo-search' ),
			'manage_options',
			'wpts-hooks',
			[ $this, 'render_react_app_page' ]
		);

		// 7. Documentation
		add_submenu_page(
			'wpts-dashboard',
			__( 'Documentation & User Guide', 'turbo-search' ),
			__( 'Documentation', 'turbo-search' ),
			'manage_options',
			'wpts-docs',
			[ $this, 'render_react_app_page' ]
		);
	}

	public function enqueue_admin_assets( string $hook ): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
		if ( false === strpos( $hook, 'wpts' ) && 0 !== strpos( $page, 'wpts' ) ) {
			return;
		}

		$current_page = sanitize_key( wp_unslash( $_GET['page'] ?? 'wpts-dashboard' ) );

		// Styles with auto cache-busting
		$js_ver  = file_exists( WPTS_DIR . 'assets/js/admin-app.js' ) ? (string) filemtime( WPTS_DIR . 'assets/js/admin-app.js' ) : WPTS_VERSION;
		$css_ver = file_exists( WPTS_DIR . 'assets/css/admin-app.css' ) ? (string) filemtime( WPTS_DIR . 'assets/css/admin-app.css' ) : WPTS_VERSION;

		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style( 'wpts-admin', WPTS_URL . 'assets/css/admin.css', [], $css_ver );
		wp_enqueue_style( 'wpts-admin-app', WPTS_URL . 'assets/css/admin-app.css', [ 'wp-components' ], $css_ver );

		$js_deps = [ 'wp-element', 'wp-components', 'wp-i18n', 'wp-api-fetch' ];

		// Chart.js Library (Bundled locally) - ONLY enqueue on pages that render charts (Dashboard & Tracking)
		$needs_chart = in_array( $current_page, [ 'wpts-dashboard', 'wpts-tracking' ], true );
		if ( $needs_chart ) {
			wp_enqueue_script(
				'wpts-chartjs',
				WPTS_URL . 'assets/js/chart.min.js',
				[],
				'4.4.1',
				true
			);
			$js_deps[] = 'wpts-chartjs';
		}

		// React Dependencies from WordPress Core
		wp_enqueue_script(
			'wpts-admin-app',
			WPTS_URL . 'assets/js/admin-app.js',
			$js_deps,
			$js_ver,
			true
		);

		// Bootstrap initial config server-side so React mounts with 0ms latency and 0 loading spinner
		$bootstrap_config = class_exists( '\WPTS\API\RestAdmin' ) ? \WPTS\API\RestAdmin::get_config_data() : [];

		$admin_app_data = [
			'rest_url'       => rest_url( 'wpts/v1/admin/' ),
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'admin_post_url'        => admin_url( 'admin-post.php' ),
			'export_nonce'          => wp_create_nonce( 'wpts_export_settings' ),
			'export_settings_nonce' => wp_create_nonce( 'wpts_export_settings' ),
			'export_searches_nonce' => wp_create_nonce( 'wpts_export_searches' ),
			'import_nonce'          => wp_create_nonce( 'wpts_import_settings' ),
			'current_page'   => $current_page,
			'initial_tab'    => $this->get_current_page_tab( $current_page ),
			'bootstrap'      => [
				'config' => $bootstrap_config,
			],
		];

		wp_add_inline_script(
			'wpts-admin-app',
			'window.WPTS_ADMIN_APP = ' . wp_json_encode( $admin_app_data ) . ';',
			'before'
		);

		wp_set_script_translations( 'wpts-admin-app', 'turbo-search', WPTS_DIR . 'languages' );
	}

	private function get_current_page_tab( string $page ): string {
		switch ( $page ) {
			case 'wpts-settings': return sanitize_key( wp_unslash( $_GET['tab'] ?? 'general' ) );
			case 'wpts-tracking': return sanitize_key( wp_unslash( $_GET['tab'] ?? 'searches' ) );
			case 'wpts-cache':    return sanitize_key( wp_unslash( $_GET['tab'] ?? 'general' ) );
			case 'wpts-index':    return 'indexer';
			case 'wpts-hooks':    return 'hooks';
			case 'wpts-docs':     return 'docs';
			default:              return 'dashboard';
		}
	}

	public function render_react_app_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'turbo-search' ) );
		}
		?>
		<div class="wrap wpts-wrap">
			<div id="wpts-admin-root">
				<div style="padding: 40px; text-align: center; color: #64748b;">
					<span class="spinner is-active" style="float: none; margin: 0 auto 10px;"></span>
					<p><?php esc_html_e( 'Loading Turbo Search React Application…', 'turbo-search' ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}

	public function add_settings_link( array $links ): array {
		$settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=wpts-settings' ) ) . '">' . esc_html__( 'Settings', 'turbo-search' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}
}
