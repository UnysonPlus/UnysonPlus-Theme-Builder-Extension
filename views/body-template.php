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

if ( $fw_tb_body_id && function_exists( 'fw_ext_theme_builder_print_body_region' ) ) {
	// The 3-mode body render (singular / archive-loop / static) + Loop Layout lives
	// in fw_ext_theme_builder_print_body_region() (helpers.php) so the theme wrapper
	// and the theme-independent standalone document share one implementation.
	fw_ext_theme_builder_print_body_region( $fw_tb_body_id );
}

get_footer();
