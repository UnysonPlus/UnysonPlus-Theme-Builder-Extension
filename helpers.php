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
