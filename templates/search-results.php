<?php
/**
 * Search Results Page Template & Shortcode: [wpts_search_results]
 * Provides a dedicated full-page results experience with faceted filtering, sorting, grid/list view, and pagination.
 * Accessible with landmarks, fieldsets, ARIA labels, and VoiceOver page announcement.
 */

defined( 'ABSPATH' ) || exit;

add_shortcode( 'wpts_search_results', 'wpts_render_search_results_page' );

function wpts_render_search_results_page( $atts ): string {
	$default_layout = class_exists( '\WPTS\Admin\Settings' ) ? \WPTS\Admin\Settings::get( 'results_layout', 'grid' ) : 'grid';
	$atts = shortcode_atts( [
		'per_page' => 12,
		'layout'   => $default_layout, // grid | list | card
	], $atts, 'wpts_search_results' );

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$query     = sanitize_text_field( wp_unslash( $_GET['s'] ?? ( $_GET['q'] ?? '' ) ) );
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$post_type = sanitize_text_field( wp_unslash( $_GET['post_type'] ?? '' ) );
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$page      = max( 1, absint( wp_unslash( $_GET['paged'] ?? ( $_GET['page'] ?? 1 ) ) ) );
	$per_page  = absint( $atts['per_page'] );
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$layout    = sanitize_key( wp_unslash( $_GET['layout'] ?? $atts['layout'] ) );
	if ( ! in_array( $layout, [ 'grid', 'list', 'card' ], true ) ) {
		$layout = $default_layout;
	}

	wp_enqueue_style( 'wpts-search-results', WPTS_URL . 'assets/css/search-results.css', [], WPTS_VERSION );
	if ( function_exists( 'wpts_enqueue_search_assets' ) ) {
		wpts_enqueue_search_assets();
	}

	$filters = [];
	if ( '' !== $post_type ) {
		$filters['post_type'] = explode( ',', $post_type );
	}

	$results = [];
	if ( '' !== $query ) {
		$engine  = \WPTS\Core::instance()->get_engine();
		$results = $engine->search( $query, $filters, $per_page, $page );
	}

	$hits         = $results['hits'] ?? [];
	$found        = (int) ( $results['found'] ?? 0 );
	$facets       = $results['facets'] ?? [ 'post_types' => [], 'taxonomies' => [], 'dates' => [] ];
	$did_you_mean = $results['did_you_mean'] ?? null;
	$pages        = ceil( $found / $per_page );

	ob_start();
	?>
	<div class="wpts-results-page-wrap" data-wpts-results-page>
		<!-- Search bar on results page -->
		<div class="wpts-results-search-bar">
			<form method="get" action="<?php echo esc_url( get_permalink() ); ?>" role="search" aria-label="<?php esc_attr_e( 'Search query form', 'turbo-search' ); ?>">
				<div class="wpts-input-wrap">
					<label for="wpts-results-query-input" class="wpts-sr-only"><?php esc_html_e( 'Search', 'turbo-search' ); ?></label>
					<input type="search" id="wpts-results-query-input" name="q" value="<?php echo esc_attr( $query ); ?>"
						   placeholder="<?php esc_attr_e( 'Search again…', 'turbo-search' ); ?>"
						   class="wpts-input"
						   aria-label="<?php esc_attr_e( 'Search query', 'turbo-search' ); ?>">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Search', 'turbo-search' ); ?></button>
				</div>
			</form>
		</div>

		<?php if ( '' !== $query ) : ?>
			<div class="wpts-results-layout">
				<!-- Sidebar Facets -->
				<aside class="wpts-facets-sidebar" role="region" aria-label="<?php esc_attr_e( 'Search Filter Options', 'turbo-search' ); ?>">
					<h3><?php esc_html_e( 'Filter Results', 'turbo-search' ); ?></h3>

					<!-- Post Types -->
					<?php if ( ! empty( $facets['post_types'] ) ) : ?>
					<fieldset class="wpts-facet-group">
						<legend><h4><?php esc_html_e( 'Content Type', 'turbo-search' ); ?></h4></legend>
						<?php foreach ( $facets['post_types'] as $pt ) : ?>
							<label class="wpts-facet-item">
								<input type="checkbox" name="facet_post_type" value="<?php echo esc_attr( $pt['name'] ); ?>">
								<span><?php echo esc_html( $pt['label'] ); ?></span>
								<span class="wpts-facet-count" aria-label="<?php /* translators: %d: matching items count */ printf( esc_attr__( '%d matching items', 'turbo-search' ), (int) $pt['count'] ); ?>">(<?php echo (int) $pt['count']; ?>)</span>
							</label>
						<?php endforeach; ?>
					</fieldset>
					<?php endif; ?>

					<!-- Taxonomies -->
					<?php if ( ! empty( $facets['taxonomies'] ) ) : ?>
						<?php foreach ( $facets['taxonomies'] as $tax ) : ?>
							<fieldset class="wpts-facet-group">
								<legend><h4><?php echo esc_html( $tax['label'] ); ?></h4></legend>
								<?php foreach ( $tax['terms'] as $term ) : ?>
									<label class="wpts-facet-item">
										<input type="checkbox" name="facet_tax" value="<?php echo esc_attr( $term['slug'] ); ?>">
										<span><?php echo esc_html( $term['name'] ); ?></span>
										<span class="wpts-facet-count" aria-label="<?php /* translators: %d: matching items count */ printf( esc_attr__( '%d matching items', 'turbo-search' ), (int) $term['count'] ); ?>">(<?php echo (int) $term['count']; ?>)</span>
									</label>
								<?php endforeach; ?>
							</fieldset>
						<?php endforeach; ?>
					<?php endif; ?>
				</aside>

				<!-- Results Main Column -->
				<main class="wpts-results-main" role="region" aria-label="<?php esc_attr_e( 'Search Results', 'turbo-search' ); ?>">
					<div class="wpts-results-top-bar" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
						<h2 style="margin:0;">
							<?php
							/* translators: 1: total results count, 2: search keyword */
							printf( esc_html__( '%1$d results for "%2$s"', 'turbo-search' ), absint( $found ), esc_html( $query ) );
							?>
						</h2>
						<div class="wpts-layout-toggle-btns" style="display:inline-flex; gap:6px;">
							<a href="<?php echo esc_url( add_query_arg( 'layout', 'grid' ) ); ?>" data-wpts-set-layout="grid" class="button <?php echo esc_attr( 'list' !== $layout ? 'button-primary' : '' ); ?>" title="<?php esc_attr_e( 'Grid View', 'turbo-search' ); ?>" aria-label="<?php esc_attr_e( 'Grid View', 'turbo-search' ); ?>" style="padding:4px 10px; font-size:12px; display:inline-flex; align-items:center;">
								⊞ <?php esc_html_e( 'Grid', 'turbo-search' ); ?>
							</a>
							<a href="<?php echo esc_url( add_query_arg( 'layout', 'list' ) ); ?>" data-wpts-set-layout="list" class="button <?php echo esc_attr( 'list' === $layout ? 'button-primary' : '' ); ?>" title="<?php esc_attr_e( 'List View', 'turbo-search' ); ?>" aria-label="<?php esc_attr_e( 'List View', 'turbo-search' ); ?>" style="padding:4px 10px; font-size:12px; display:inline-flex; align-items:center;">
								☰ <?php esc_html_e( 'List', 'turbo-search' ); ?>
							</a>
						</div>
					</div>

					<?php if ( $did_you_mean ) : ?>
					<div class="wpts-did-you-mean-banner" role="status">
						<span><?php esc_html_e( 'Did you mean:', 'turbo-search' ); ?></span>
						<a href="<?php echo esc_url( add_query_arg( 'q', $did_you_mean, get_permalink() ) ); ?>"
						   aria-label="<?php /* translators: %s: suggested search query */ printf( esc_attr__( 'Search for %s instead', 'turbo-search' ), esc_attr( $did_you_mean ) ); ?>">
							<strong><?php echo esc_html( $did_you_mean ); ?></strong>
						</a>?
					</div>
					<?php endif; ?>

					<?php if ( empty( $hits ) ) : ?>
						<div class="wpts-empty-state" role="status">
							<div class="wpts-empty-icon">
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" width="24" height="24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="8" y1="11" x2="14" y2="11"/></svg>
							</div>
							<div class="wpts-empty-title"><?php esc_html_e( 'No matching documents found', 'turbo-search' ); ?></div>
							<p class="wpts-empty-desc"><?php esc_html_e( 'Try broadening your search keywords or checking for spelling errors.', 'turbo-search' ); ?></p>
						</div>
					<?php else : ?>
						<div class="wpts-grid-results <?php echo esc_attr( 'list' === $layout ? 'is-list' : 'is-grid' ); ?>" role="feed" aria-label="<?php esc_attr_e( 'Matching Search Results List', 'turbo-search' ); ?>">
							<?php foreach ( $hits as $hit ) : ?>
							<article class="wpts-result-card" data-post-id="<?php echo esc_attr( $hit['post_id'] ); ?>">
								<?php if ( ! empty( $hit['thumbnail_url'] ) ) : ?>
									<div class="wpts-card-image">
										<a href="<?php echo esc_url( $hit['url'] ); ?>" tabindex="-1" aria-hidden="true">
											<img src="<?php echo esc_url( $hit['thumbnail_url'] ); ?>" alt="" loading="lazy">
										</a>
									</div>
								<?php endif; ?>
								<div class="wpts-card-content">
									<span class="wpts-card-badge"><?php echo esc_html( $hit['post_type'] ); ?></span>
									<h3 class="wpts-card-title">
										<a href="<?php echo esc_url( $hit['url'] ); ?>"><?php echo wp_kses_post( $hit['title'] ); ?></a>
									</h3>
									<?php if ( ! empty( $hit['excerpt'] ) ) : ?>
										<p class="wpts-card-excerpt"><?php echo wp_kses_post( $hit['excerpt'] ); ?></p>
									<?php endif; ?>
									<div class="wpts-card-footer">
										<?php if ( ! empty( $hit['price'] ) ) : ?>
											<span class="wpts-card-price" aria-label="<?php /* translators: %s: price amount */ printf( esc_attr__( 'Price: %s dollars', 'turbo-search' ), number_format( (float) $hit['price'], 2 ) ); ?>">$<?php echo number_format( (float) $hit['price'], 2 ); ?></span>
										<?php endif; ?>
										<?php if ( ! empty( $hit['date_formatted'] ) ) : ?>
											<span class="wpts-card-date"><?php echo esc_html( $hit['date_formatted'] ); ?></span>
										<?php endif; ?>
									</div>
								</div>
							</article>
							<?php endforeach; ?>
						</div>

						<!-- Pagination -->
						<?php if ( $pages > 1 ) : ?>
						<nav class="wpts-pagination-bar" aria-label="<?php esc_attr_e( 'Search results pagination', 'turbo-search' ); ?>">
							<?php if ( $page > 1 ) : ?>
								<a href="<?php echo esc_url( add_query_arg( [ 'paged' => $page - 1, 'page' => $page - 1 ] ) ); ?>"
								   class="wpts-page-btn wpts-page-prev"
								   aria-label="<?php esc_attr_e( 'Previous page', 'turbo-search' ); ?>">
									&larr; <?php esc_html_e( 'Prev', 'turbo-search' ); ?>
								</a>
							<?php endif; ?>

							<?php
							$start_page = max( 1, $page - 2 );
							$end_page   = min( $pages, $start_page + 4 );
							if ( $end_page - $start_page < 4 ) {
								$start_page = max( 1, $end_page - 4 );
							}
							for ( $p = $start_page; $p <= $end_page; $p++ ) : ?>
								<a href="<?php echo esc_url( add_query_arg( [ 'paged' => $p, 'page' => $p ] ) ); ?>"
								   class="wpts-page-btn <?php echo (int) $p === (int) $page ? 'is-active' : ''; ?>"
								   <?php if ( (int) $p === (int) $page ) echo 'aria-current="page"'; ?>
								   aria-label="<?php /* translators: %d: page number */ printf( esc_attr__( 'Go to page %d', 'turbo-search' ), absint( $p ) ); ?>">
									<?php echo (int) $p; ?>
								</a>
							<?php endfor; ?>

							<?php if ( $page < $pages ) : ?>
								<a href="<?php echo esc_url( add_query_arg( [ 'paged' => $page + 1, 'page' => $page + 1 ] ) ); ?>"
								   class="wpts-page-btn wpts-page-next"
								   aria-label="<?php esc_attr_e( 'Next page', 'turbo-search' ); ?>">
									<?php esc_html_e( 'Next', 'turbo-search' ); ?> &rarr;
								</a>
							<?php endif; ?>
						</nav>
						<?php endif; ?>
					<?php endif; ?>
				</main>
			</div>
		<?php endif; ?>
	</div>
	<?php
	return (string) ob_get_clean();
}
