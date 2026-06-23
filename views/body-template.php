<?php if ( ! defined( 'ABSPATH' ) ) {
	die( 'Forbidden' );
}

/**
 * Theme Builder — Body Template wrapper.
 *
 * Loaded via template_include (see hooks.php) when a Template assigns a Body
 * (up_body) to the current request and the queried post is not itself a builder
 * page. Renders the theme/Template header, the body builder content full-width,
 * then the footer. The body content is already do_shortcode()'d by
 * fw_ext_theme_builder_render_body(), so it is echoed directly (no wpautop).
 */

get_header();

$fw_tb_body_id = class_exists( 'FW_Theme_Builder_Resolver' ) ? (int) FW_Theme_Builder_Resolver::body_id() : 0;

if ( $fw_tb_body_id && function_exists( 'fw_ext_theme_builder_render_body' ) ) {
	echo '<div class="fw-tb-body fw-page-builder-content">';
	echo fw_ext_theme_builder_render_body( $fw_tb_body_id ); // phpcs:ignore WordPress.Security.EscapeOutput — builder HTML
	echo '</div>';
}

get_footer();
