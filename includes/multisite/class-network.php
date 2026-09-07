<?php
namespace WPTS\Multisite;

defined( 'ABSPATH' ) || exit;

/**
 * Network admin features for WordPress Multisite.
 */
class Network {

	public function init(): void {
		if ( ! is_network_admin() ) return;

		add_action( 'network_admin_menu', [ $this, 'register_network_menu' ] );
		// admin_post_* handlers registered in turbo-search.php on init priority 1
	}

	public function register_network_menu(): void {
		add_menu_page(
			__( 'Turbo Search Network', 'turbo-search' ),
			__( 'Turbo Search', 'turbo-search' ),
			'manage_network_options',
			'wpts-network',
			[ $this, 'render_network_page' ],
			'dashicons-search',
			58
		);
	}

	public function render_network_page(): void {
		if ( ! current_user_can( 'manage_network_options' ) ) return;

		$sites = get_sites( [ 'number' => 0 ] );
		?>
		<div class="wrap wpts-wrap">
			<h1><?php esc_html_e( 'Turbo Search: Network', 'turbo-search' ); ?></h1>

			<h2><?php esc_html_e( 'Sites in this Network', 'turbo-search' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Site', 'turbo-search' ); ?></th>
						<th><?php esc_html_e( 'Blog ID', 'turbo-search' ); ?></th>
						<th><?php esc_html_e( 'DB Version', 'turbo-search' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'turbo-search' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $sites as $site ) :
						switch_to_blog( $site->blog_id );
						$db_ver = get_option( 'wpts_db_version', 'not installed' );
						restore_current_blog();
					?>
						<tr>
							<td><?php echo esc_html( get_site_url( $site->blog_id ) ); ?></td>
							<td><?php echo esc_html( $site->blog_id ); ?></td>
							<td><code><?php echo esc_html( $db_ver ); ?></code></td>
							<td>
								<a href="<?php echo esc_url( get_admin_url( $site->blog_id, 'admin.php?page=wpts-index' ) ); ?>"
								   class="button button-small">
									<?php esc_html_e( 'Manage Index', 'turbo-search' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<p style="margin-top:24px;">
				<a href="<?php echo esc_url( wp_nonce_url(
					network_admin_url( 'admin-post.php?action=wpts_network_reindex_all' ),
					'wpts_network_reindex'
				) ); ?>" class="button button-primary"
				   onclick="return confirm('<?php esc_attr_e( 'Re-index all sites? This may take a while.', 'turbo-search' ); ?>')">
					<?php esc_html_e( 'Re-index All Sites', 'turbo-search' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Perform global cross-network search across all blogs.
	 */
	public static function search_cross_network( string $query, array $filters = [], int $per_page = 10, int $page = 1 ): array {
		if ( ! is_multisite() ) {
			return \WPTS\Core::instance()->get_engine()->search( $query, $filters, $per_page, $page );
		}

		$sites      = get_sites( [ 'number' => 10, 'public' => 1 ] );
		$all_hits   = [];
		$total_hits = 0;

		foreach ( $sites as $site ) {
			switch_to_blog( (int) $site->blog_id );
			$site_name = get_bloginfo( 'name' );
			$engine    = \WPTS\Core::instance()->get_engine();
			$res       = $engine->search( $query, $filters, $per_page, 1 );

			if ( ! empty( $res['hits'] ) ) {
				foreach ( $res['hits'] as $hit ) {
					$hit['site_id']   = (int) $site->blog_id;
					$hit['site_name'] = $site_name;
					$all_hits[]       = $hit;
				}
				$total_hits += (int) ( $res['found'] ?? 0 );
			}
			restore_current_blog();
		}

		// Sort descending by relevance
		usort( $all_hits, function ( $a, $b ) {
			return ( (float) ( $b['relevance'] ?? 0 ) <=> (float) ( $a['relevance'] ?? 0 ) );
		} );

		$offset = ( $page - 1 ) * $per_page;
		return [
			'hits'  => array_slice( $all_hits, $offset, $per_page ),
			'found' => $total_hits,
			'page'  => $page,
		];
	}
}
