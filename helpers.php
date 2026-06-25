<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Render a header/footer preset's page-builder content by ID, returning the
 * INNER HTML only (the theme wraps it in <header>/<footer> so header chrome,
 * type/behavior classes and hooks stay theme-side).
 *
 * Strips the auto-generated <section> wrappers the items-corrector adds around
 * root rows/columns, then do_shortcode(). Returns '' for invalid/unpublished
 * posts, wrong post type, re-entrant (cyclic) calls, or when the page-builder
 * extension is inactive.
 *
 * NOTE: name + signature preserved verbatim from the former header-footer-builder
 * extension — the parent theme calls fw_ext_hfbuilder_render() directly
 * (inc/includes/header-footer-presets.php), so keeping the name means the theme
 * needs no edits after the absorb. function_exists guard keeps a stale copy of
 * the old extension (if any) from fataling on redeclare.
 *
 * @param int    $post_id
 * @param string $kind 'header'|'footer'
 * @return string
 */
if ( ! function_exists( 'fw_ext_hfbuilder_render' ) ) :
function fw_ext_hfbuilder_render( $post_id, $kind = 'header' ) {
	static $stack = array();

	$post_id = (int) $post_id;
	if ( ! $post_id || isset( $stack[ $post_id ] ) ) {
		return '';
	}

	$cpt  = ( $kind === 'footer' ) ? 'up_footer' : 'up_header';
	$post = get_post( $post_id );
	if ( ! $post || $post->post_type !== $cpt || $post->post_status !== 'publish' ) {
		return '';
	}

	if ( ! function_exists( 'fw_ext_page_builder_get_post_content' ) ) {
		return '';
	}

	$stack[ $post_id ] = true;

	$shortcodes = fw_ext_page_builder_get_post_content( $post );
	if ( apply_filters( 'fw_ext_hfbuilder_strip_auto_sections', true, $post_id, $kind ) ) {
		$shortcodes = fw_ext_hfbuilder_unwrap_auto_sections( $shortcodes );
	}
	$html = do_shortcode( $shortcodes );

	unset( $stack[ $post_id ] );

	return $html;
}
endif;

/**
 * Strip [section auto_generated="true"]…[/section] wrappers (user-authored
 * sections are preserved). Reuses the snippets implementation when present so the
 * two stay in lockstep. Name preserved from the former header-footer-builder
 * extension (also reused by fw_ext_hfbuilder_render above).
 *
 * @param string $shortcodes
 * @return string
 */
if ( ! function_exists( 'fw_ext_hfbuilder_unwrap_auto_sections' ) ) :
function fw_ext_hfbuilder_unwrap_auto_sections( $shortcodes ) {
	if ( function_exists( 'fw_ext_snippets_unwrap_auto_sections' ) ) {
		return fw_ext_snippets_unwrap_auto_sections( $shortcodes );
	}
	return preg_replace_callback(
		'/\[section\b([^\]]*)\](.*?)\[\/section\]/s',
		function ( $m ) {
			if ( preg_match( '/\bauto_generated\s*=\s*(["\'])(?:true|1)\1/i', $m[1] ) ) {
				return $m[2];
			}
			return $m[0];
		},
		$shortcodes
	);
}
endif;

/**
 * Render an up_body Body Template's page-builder content by ID, returning the
 * full HTML (sections preserved — unlike a header/footer, a body owns its own
 * sections). Recursion-guarded; returns '' for invalid/unpublished posts, the
 * wrong post type, or when the page-builder extension is inactive.
 *
 * Not yet wired into the front end (the body template_include path lands with the
 * render-wiring phase) — exposed now so the resolver + future wiring share one
 * render entry point.
 *
 * @param int $post_id
 * @return string
 */
if ( ! function_exists( 'fw_ext_theme_builder_render_body' ) ) :
function fw_ext_theme_builder_render_body( $post_id ) {
	static $stack = array();

	$post_id = (int) $post_id;
	if ( ! $post_id || isset( $stack[ $post_id ] ) ) {
		return '';
	}

	$post = get_post( $post_id );
	if ( ! $post || $post->post_type !== 'up_body' || $post->post_status !== 'publish' ) {
		return '';
	}

	if ( ! function_exists( 'fw_ext_page_builder_get_post_content' ) ) {
		return '';
	}

	$stack[ $post_id ] = true;
	$html = do_shortcode( fw_ext_page_builder_get_post_content( $post ) );
	unset( $stack[ $post_id ] );

	return $html;
}
endif;

/**
 * ECHO a Body Template's content region for the current request, handling the
 * three render modes (singular / archive-loop / static) and the Loop Layout meta.
 * Single source of truth shared by the unysonplus-theme body wrapper
 * (views/body-template.php) and the theme-independent standalone document
 * (views/standalone-template.php) so both stay in lockstep. Caller supplies the
 * surrounding chrome (header/footer); this only prints the body region.
 *
 * @param int $body_id
 * @return void
 */
if ( ! function_exists( 'fw_ext_theme_builder_print_body_region' ) ) :
function fw_ext_theme_builder_print_body_region( $body_id ) {
	$body_id = (int) $body_id;
	if ( ! $body_id || ! function_exists( 'fw_ext_theme_builder_render_body' ) ) {
		return;
	}

	// A "loop" request is an archive/list of posts — not a single post, not a 404,
	// and it actually has posts. The body renders once PER POST (Divi post-loop).
	$is_loop = ! is_singular() && ! is_404() && have_posts();

	if ( $is_loop ) {
		$cols = function_exists( 'fw_get_db_post_option' ) ? (string) fw_get_db_post_option( $body_id, 'tb_loop_columns' ) : '';
		if ( ! in_array( $cols, array( 'auto', '1', '2', '3', '4', '5', '6' ), true ) ) {
			$cols = '3';
		}
		$gap_map = array( 'none' => '0', 'small' => '0.75rem', 'medium' => '1.5rem', 'large' => '2.5rem' );
		$gap_key = function_exists( 'fw_get_db_post_option' ) ? (string) fw_get_db_post_option( $body_id, 'tb_loop_gap' ) : '';
		$gap     = isset( $gap_map[ $gap_key ] ) ? $gap_map[ $gap_key ] : '1.5rem';

		printf(
			'<div class="fw-tb-body fw-tb-body--loop fw-tb-cols-%s fw-page-builder-content" style="gap:%s">',
			esc_attr( $cols ),
			esc_attr( $gap )
		);
		while ( have_posts() ) {
			the_post();
			echo '<div class="fw-tb-body__item">';
			echo fw_ext_theme_builder_render_body( $body_id ); // phpcs:ignore WordPress.Security.EscapeOutput — builder HTML
			echo '</div>';
		}
		echo '</div>';

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
		echo fw_ext_theme_builder_render_body( $body_id ); // phpcs:ignore WordPress.Security.EscapeOutput — builder HTML
		echo '</div>';

		if ( is_singular() ) {
			wp_reset_postdata();
		}
	}
}
endif;
