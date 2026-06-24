<?php if ( ! defined( 'ABSPATH' ) ) {
	die( 'Forbidden' );
}

/**
 * Theme Builder — Body Template wrapper.
 *
 * Loaded via template_include (see hooks.php) when a Template assigns a Body
 * (up_body) to the current request and the queried post is not itself a builder
 * page. Renders the theme/Template header, the body builder content, then the
 * footer. The body content is already do_shortcode()'d by
 * fw_ext_theme_builder_render_body(), so it is echoed directly (no wpautop).
 *
 * THREE render modes, decided from the request:
 *
 *   1. Singular (is_singular)            → the queried post is set up once and the
 *                                          body renders once; dynamic elements
 *                                          (Post Title / Content / Featured Image)
 *                                          read that post.
 *   2. Archive / list (has main posts,   → the WordPress LOOP runs and the body is
 *      not singular, not 404)              rendered ONCE PER POST (whole-body-per-
 *                                          post, like a Divi post-loop layout), so
 *                                          a Blog / "All Products" / category /
 *                                          search Body Template becomes a real list
 *                                          of cards. Pagination follows the loop.
 *   3. Static (404 / empty archive)      → the body renders once with no post (for
 *                                          404 / landing layouts).
 */

get_header();

$fw_tb_body_id = class_exists( 'FW_Theme_Builder_Resolver' ) ? (int) FW_Theme_Builder_Resolver::body_id() : 0;

if ( $fw_tb_body_id && function_exists( 'fw_ext_theme_builder_render_body' ) ) {

	// A "loop" request is an archive/list of posts (blog index, category/tag/term
	// archive, post-type archive, author/date archive, search results) — anything
	// that is NOT a single post and is NOT a 404, and that actually has posts.
	$fw_tb_is_loop = ! is_singular() && ! is_404() && have_posts();

	if ( $fw_tb_is_loop ) {

		// Loop Layout (Body meta): columns class + gap inline. Defaults: 3 cols,
		// medium gap. Unknown/legacy values fall back safely.
		$fw_tb_cols = (string) fw_get_db_post_option( $fw_tb_body_id, 'tb_loop_columns' );
		if ( ! in_array( $fw_tb_cols, array( 'auto', '1', '2', '3', '4', '5', '6' ), true ) ) {
			$fw_tb_cols = '3';
		}
		$fw_tb_gap_map = array( 'none' => '0', 'small' => '0.75rem', 'medium' => '1.5rem', 'large' => '2.5rem' );
		$fw_tb_gap_key = (string) fw_get_db_post_option( $fw_tb_body_id, 'tb_loop_gap' );
		$fw_tb_gap     = isset( $fw_tb_gap_map[ $fw_tb_gap_key ] ) ? $fw_tb_gap_map[ $fw_tb_gap_key ] : '1.5rem';

		printf(
			'<div class="fw-tb-body fw-tb-body--loop fw-tb-cols-%s fw-page-builder-content" style="gap:%s">',
			esc_attr( $fw_tb_cols ),
			esc_attr( $fw_tb_gap )
		);
		while ( have_posts() ) {
			the_post();
			echo '<div class="fw-tb-body__item">';
			echo fw_ext_theme_builder_render_body( $fw_tb_body_id ); // phpcs:ignore WordPress.Security.EscapeOutput — builder HTML
			echo '</div>';
		}
		echo '</div>';

		// Prev/next pages of the archive (the main query is already paginated).
		if ( function_exists( 'the_posts_pagination' ) ) {
			the_posts_pagination();
		}

		wp_reset_postdata();

	} else {

		// Singular: set up the queried post so dynamic elements read it. Static
		// (404 / empty archive): render once with no post.
		if ( is_singular() && have_posts() ) {
			the_post();
		}

		echo '<div class="fw-tb-body fw-page-builder-content">';
		echo fw_ext_theme_builder_render_body( $fw_tb_body_id ); // phpcs:ignore WordPress.Security.EscapeOutput — builder HTML
		echo '</div>';

		if ( is_singular() ) {
			wp_reset_postdata();
		}
	}
}

get_footer();
