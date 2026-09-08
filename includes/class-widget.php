<?php
/**
 * Turbo Search: Legacy Widget
 */

defined( 'ABSPATH' ) || exit;

class WPTS_Search_Widget extends \WP_Widget {
	public function __construct() {
		parent::__construct(
			'wpts_search_widget',
			__( 'Turbo Search Bar', 'turbo-search' ),
			[ 'description' => __( 'Instant search bar with multi-post-type support, debounce, voice search, and theme options.', 'turbo-search' ) ]
		);
	}

	public function widget( $args, $instance ): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $args['before_widget'];
		if ( ! empty( $instance['title'] ) ) {
			$title = apply_filters( 'widget_title', $instance['title'] );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $args['before_title'] . esc_html( $title ) . $args['after_title'];
		}

		$post_type = $instance['post_type'] ?? '';
		if ( is_array( $post_type ) ) {
			$post_type = implode( ',', array_filter( array_map( 'sanitize_key', $post_type ) ) );
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo wpts_build_search_html( [
			'placeholder'     => $instance['placeholder'] ?? __( 'Search…', 'turbo-search' ),
			'theme'           => $instance['theme'] ?? 'light',
			'post_type'       => $post_type,
			'per_page'        => (int) ( $instance['per_page'] ?? 8 ),
			'debounce'        => 200,
			'throttle'        => 0,
			'min_chars'       => 2,
			'show_type'       => 1,
			'show_excerpt'    => 1,
			'show_voice'      => 1,
			'layout'          => $instance['layout'] ?? ( class_exists( '\WPTS\Admin\Settings' ) ? \WPTS\Admin\Settings::get( 'results_layout', 'list' ) : 'list' ),
			'new_tab'         => 0,
			'input_size'      => 'medium',
			'max_width'       => 0,
			'max_height'      => 400,
			'primary_color'   => '#2563eb',
			'bg_color'        => '#ffffff',
			'text_color'      => '#1e293b',
			'border_color'    => '#e2e8f0',
			'highlight_color' => '#fef08a',
			'border_radius'   => 10,
			'class'           => '',
		] );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $args['after_widget'];
	}

	public function form( $instance ): void {
		$title       = $instance['title'] ?? __( 'Search', 'turbo-search' );
		$placeholder = $instance['placeholder'] ?? __( 'Search…', 'turbo-search' );
		$theme       = $instance['theme'] ?? 'light';
		$layout      = $instance['layout'] ?? 'list';
		$per_page    = (int) ( $instance['per_page'] ?? 8 );
		$raw_pt      = $instance['post_type'] ?? '';
		$selected_pts = is_array( $raw_pt ) ? $raw_pt : array_filter( array_map( 'trim', explode( ',', (string) $raw_pt ) ) );

		$public_pts = function_exists( 'get_post_types' )
			? get_post_types( [ 'public' => true ], 'objects' )
			: [];
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Widget Title:', 'turbo-search' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'placeholder' ) ); ?>"><?php esc_html_e( 'Placeholder Text:', 'turbo-search' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'placeholder' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'placeholder' ) ); ?>" type="text" value="<?php echo esc_attr( $placeholder ); ?>">
		</p>
		<p>
			<label><strong><?php esc_html_e( 'Filter by Post Types (leave blank for all):', 'turbo-search' ); ?></strong></label><br>
			<?php foreach ( $public_pts as $pt ) : ?>
				<label style="display:inline-block; margin-right:10px; margin-top:4px;">
					<input type="checkbox" name="<?php echo esc_attr( $this->get_field_name( 'post_type' ) ); ?>[]" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( in_array( $pt->name, $selected_pts, true ) ); ?>>
					<?php echo esc_html( $pt->label ); ?>
				</label>
			<?php endforeach; ?>
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'layout' ) ); ?>"><?php esc_html_e( 'Results Layout:', 'turbo-search' ); ?></label>
			<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'layout' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'layout' ) ); ?>">
				<option value="list" <?php selected( $layout, 'list' ); ?>><?php esc_html_e( 'List View', 'turbo-search' ); ?></option>
				<option value="grid" <?php selected( $layout, 'grid' ); ?>><?php esc_html_e( 'Grid Cards', 'turbo-search' ); ?></option>
				<option value="card" <?php selected( $layout, 'card' ); ?>><?php esc_html_e( 'Compact Card', 'turbo-search' ); ?></option>
			</select>
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'theme' ) ); ?>"><?php esc_html_e( 'Theme Style:', 'turbo-search' ); ?></label>
			<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'theme' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'theme' ) ); ?>">
				<option value="light" <?php selected( $theme, 'light' ); ?>><?php esc_html_e( 'Light (Default)', 'turbo-search' ); ?></option>
				<option value="dark" <?php selected( $theme, 'dark' ); ?>><?php esc_html_e( 'Dark', 'turbo-search' ); ?></option>
				<option value="minimal" <?php selected( $theme, 'minimal' ); ?>><?php esc_html_e( 'Minimalist', 'turbo-search' ); ?></option>
				<option value="glass" <?php selected( $theme, 'glass' ); ?>><?php esc_html_e( 'Glassmorphism', 'turbo-search' ); ?></option>
			</select>
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'per_page' ) ); ?>"><?php esc_html_e( 'Results per page:', 'turbo-search' ); ?></label>
			<input class="tiny-text" id="<?php echo esc_attr( $this->get_field_id( 'per_page' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'per_page' ) ); ?>" type="number" step="1" min="1" max="50" value="<?php echo esc_attr( (string) $per_page ); ?>" size="3">
		</p>
		<?php
	}

	public function update( $new_instance, $old_instance ): array {
		$instance = [];
		$instance['title']       = ! empty( $new_instance['title'] ) ? sanitize_text_field( $new_instance['title'] ) : '';
		$instance['placeholder'] = ! empty( $new_instance['placeholder'] ) ? sanitize_text_field( $new_instance['placeholder'] ) : '';
		$instance['theme']       = ! empty( $new_instance['theme'] ) ? sanitize_key( $new_instance['theme'] ) : 'light';
		$instance['layout']      = ! empty( $new_instance['layout'] ) ? sanitize_key( $new_instance['layout'] ) : 'list';
		$instance['per_page']    = ! empty( $new_instance['per_page'] ) ? absint( $new_instance['per_page'] ) : 8;

		if ( ! empty( $new_instance['post_type'] ) ) {
			$pts = is_array( $new_instance['post_type'] ) ? $new_instance['post_type'] : explode( ',', (string) $new_instance['post_type'] );
			$instance['post_type'] = implode( ',', array_filter( array_map( 'sanitize_key', $pts ) ) );
		} else {
			$instance['post_type'] = '';
		}

		return $instance;
	}
}

