<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Sentinel: tells the theme's inc/post-types.php to NOT register its title-only
 * fallback up_header/up_footer CPTs, so THIS extension's builder-enabled
 * registration wins when active. Name preserved verbatim from the former
 * header-footer-builder extension — the theme checks defined('UP_HFBUILDER_OWNS_CPTS').
 * Defined at include time (before the `init` hook where the theme registers), so
 * the theme sees it in time.
 */
if ( ! defined( 'UP_HFBUILDER_OWNS_CPTS' ) ) {
	define( 'UP_HFBUILDER_OWNS_CPTS', true );
}

// The conditional assignment resolver (Use On / Exclude From).
require_once dirname( __FILE__ ) . '/includes/class-fw-theme-builder-resolver.php';

/**
 * Body Templates (Phase C1, static).
 *
 * When a Template assigns a Body (up_body) to the current request, load our
 * wrapper template (site/Template header + full-width body builder content +
 * footer) in place of the theme's normal template. Header/footer inside the
 * wrapper still resolve through the theme, so a Template can supply all three.
 *
 * Skipped when the queried post is itself a page-builder page — a deliberately
 * built page is never replaced. Body content is currently STATIC (the dynamic
 * Post Title / Content / Featured Image elements are Phase C2); body Templates are
 * therefore most useful for 404 / landing / archive layouts today.
 *
 * @internal
 */
function _filter_fw_theme_builder_body_template_include( $template ) {
	if ( is_admin() || ! class_exists( 'FW_Theme_Builder_Resolver' ) ) {
		return $template;
	}

	// Never replace a page the user explicitly built with the page builder.
	if ( is_singular()
	     && function_exists( 'fw_ext_page_builder_is_builder_post' )
	     && fw_ext_page_builder_is_builder_post( (int) get_queried_object_id() ) ) {
		return $template;
	}

	if ( (int) FW_Theme_Builder_Resolver::body_id() > 0 ) {
		$wrapper = dirname( __FILE__ ) . '/views/body-template.php';
		if ( is_readable( $wrapper ) ) {
			return $wrapper;
		}
	}

	return $template;
}
add_filter( 'template_include', '_filter_fw_theme_builder_body_template_include', 99 );

/**
 * Attach the page builder to the three private part CPTs (header/footer/body).
 * The builder's own discovery only sees public post types, so we add support
 * directly after the page-builder extension's init (priority 10000). Mirrors the
 * snippets extension.
 *
 * @internal
 */
function _action_fw_ext_theme_builder_force_builder_support() {
	if ( ! function_exists( 'fw_ext' ) || ! fw_ext( 'page-builder' ) ) {
		return;
	}
	$feature = fw_ext( 'page-builder' )->get_supports_feature_name();
	add_post_type_support( 'up_header', $feature );
	add_post_type_support( 'up_footer', $feature );
	add_post_type_support( 'up_body', $feature );
}
add_action( 'init', '_action_fw_ext_theme_builder_force_builder_support', 10000 );

/**
 * Expose the private part CPTs to anything asking "which post types support the
 * page builder?".
 *
 * @internal
 */
function _filter_fw_ext_theme_builder_supported_post_types( $post_types ) {
	foreach ( array( 'up_header', 'up_footer', 'up_body' ) as $pt ) {
		if ( ! isset( $post_types[ $pt ] ) ) {
			$obj = get_post_type_object( $pt );
			if ( $obj ) {
				$post_types[ $pt ] = $obj->labels->name;
			}
		}
	}
	return $post_types;
}
add_filter( 'fw_ext_page_builder_supported_post_types', '_filter_fw_ext_theme_builder_supported_post_types' );

/**
 * Best-effort detection of the post type being edited in wp-admin (screen, then
 * the post-new / post.php / save request params).
 *
 * @return string
 */
function up_theme_builder_current_admin_post_type() {
	if ( ! is_admin() ) {
		return '';
	}
	if ( function_exists( 'get_current_screen' ) ) {
		$screen = get_current_screen();
		if ( $screen && ! empty( $screen->post_type ) ) {
			return $screen->post_type;
		}
	}
	if ( ! empty( $_GET['post_type'] ) ) {
		return sanitize_key( wp_unslash( $_GET['post_type'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
	}
	if ( ! empty( $_GET['post'] ) ) {
		return (string) get_post_type( (int) $_GET['post'] ); // phpcs:ignore WordPress.Security.NonceVerification
	}
	if ( ! empty( $_REQUEST['post_ID'] ) ) {
		return (string) get_post_type( (int) $_REQUEST['post_ID'] ); // phpcs:ignore WordPress.Security.NonceVerification
	}
	return '';
}

/**
 * Trim the builder element palette on the HEADER/FOOTER edit screens: hide
 * elements that make no sense in a header/footer (accordion, tabs, posts grid, …).
 * Body Templates (up_body) get the FULL registry — a body can contain anything.
 * Scoped to admin + the up_header/up_footer CPTs only; front-end requests get the
 * full registry, so a preset that already contains a trimmed element still
 * renders. The list is filterable via `fw_ext_hfbuilder_disabled_elements`
 * (filter name kept for back-compat). Returns a LIST of tags.
 *
 * @internal
 */
function _filter_fw_ext_theme_builder_disable_shortcodes( $disabled ) {
	$pt = up_theme_builder_current_admin_post_type();
	if ( ! in_array( $pt, array( 'up_header', 'up_footer' ), true ) ) {
		return $disabled;
	}

	$deny = apply_filters(
		'fw_ext_hfbuilder_disabled_elements',
		array(
			'accordion',
			'tabs',
			'testimonials',
			'team_member',
			'posts',
			'masonry_section',
			'bleed_section',
			'text_expander',
			'calendar',
			'table',
			'map',
			'code_block',
			'notification',
		),
		$pt
	);

	return array_values( array_unique( array_merge( (array) $disabled, $deny ) ) );
}
add_filter( 'fw_ext_shortcodes_disable_shortcodes', '_filter_fw_ext_theme_builder_disable_shortcodes' );
