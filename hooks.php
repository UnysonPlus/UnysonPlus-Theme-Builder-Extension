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
 * Body class hints (mirrors Divi's et-tb-* classes). When a Template applies to
 * the request, tag <body> so themes / custom CSS / JS can target Theme-Builder
 * pages and know which areas the Template overrides. Cheap and very useful.
 *
 * @internal
 */
function _filter_fw_theme_builder_body_class( $classes ) {
	if ( is_admin() || ! class_exists( 'FW_Theme_Builder_Resolver' ) ) {
		return $classes;
	}
	$r = FW_Theme_Builder_Resolver::resolve();
	if ( ! $r ) {
		return $classes;
	}
	$classes[] = 'up-tb-template';
	if ( ! empty( $r['header_id'] ) ) { $classes[] = 'up-tb-has-header'; }
	if ( ! empty( $r['body_id'] ) )   { $classes[] = 'up-tb-has-body'; }
	if ( ! empty( $r['footer_id'] ) ) { $classes[] = 'up-tb-has-footer'; }
	return $classes;
}
add_filter( 'body_class', '_filter_fw_theme_builder_body_class' );

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

/**
 * Isolate the dynamic-content elements ("Dynamic Content" tab: Post Title /
 * Content / Excerpt / Featured Image / Author / Date / Terms / Meta) to the Theme
 * Builder. They are meaningful only against a queried post supplied by a Header /
 * Body / Footer template, so they are HIDDEN from the builder palette everywhere
 * else (Pages, Posts, CPTs).
 *
 * Admin-only: a front-end request returns the list untouched (empty $pt), so a
 * Template that already uses a dynamic element still renders normally. Composes
 * with the header/footer palette-trim above on the same filter.
 *
 * @internal
 */
function _filter_fw_theme_builder_dynamic_elements_scope( $disabled ) {
	// Front end (and any non-admin context): keep them registered so they render.
	if ( ! is_admin() ) {
		return $disabled;
	}

	// The Theme Builder part editors get the dynamic elements; nowhere else does.
	$pt = up_theme_builder_current_admin_post_type();
	if ( in_array( $pt, array( 'up_header', 'up_body', 'up_footer' ), true ) ) {
		return $disabled;
	}

	$dynamic = apply_filters(
		'fw_theme_builder_dynamic_elements',
		array(
			'post_title',
			'post_content',
			'post_excerpt',
			'featured_image',
			'post_author',
			'post_date',
			'post_terms',
			'post_meta',
		)
	);

	return array_values( array_unique( array_merge( (array) $disabled, (array) $dynamic ) ) );
}
add_filter( 'fw_ext_shortcodes_disable_shortcodes', '_filter_fw_theme_builder_dynamic_elements_scope' );

/**
 * Builder-only editing for the Header / Body / Footer preset editors.
 *
 * (1) A NEW preset opens straight into the Unyson Builder instead of the classic
 *     editor. The page builder hard-codes its default option value to
 *     builder_active => false, which makes its own `fw_page_builder_set_as_default`
 *     filter unreachable — so we instead override the page-builder option's
 *     default VALUE for these CPTs to flip builder_active on. Only the DEFAULT
 *     changes: a preset that already has saved builder meta keeps it (the box
 *     loads saved post meta over this default), so existing presets are never
 *     forced. Runs at priority 20, after the page builder adds its box at 10.
 * (2) The "Default Editor" toggle (.page-builder-hide-button) is hidden on these
 *     screens (see below), so a preset can't be switched back to the classic
 *     editor. The "Unyson Builder" button is left intact as a safety net for any
 *     legacy preset saved in classic mode (it only shows when the builder is off).
 *
 * Both scoped to up_header/up_footer/up_body only; every other post type untouched.
 *
 * @internal
 */
function _filter_fw_theme_builder_default_to_builder( $options, $post_type ) {
	if ( ! in_array( $post_type, array( 'up_header', 'up_body', 'up_footer' ), true ) ) {
		return $options;
	}
	if ( isset( $options['page-builder-box']['options']['page-builder'] ) ) {
		$value = isset( $options['page-builder-box']['options']['page-builder']['value'] )
			&& is_array( $options['page-builder-box']['options']['page-builder']['value'] )
			? $options['page-builder-box']['options']['page-builder']['value']
			: array( 'json' => '[]' );
		$value['builder_active'] = true;
		$options['page-builder-box']['options']['page-builder']['value'] = $value;
	}
	return $options;
}
add_filter( 'fw_post_options', '_filter_fw_theme_builder_default_to_builder', 20, 2 );

/**
 * @internal
 */
function _action_fw_theme_builder_hide_editor_toggle() {
	if ( ! in_array( up_theme_builder_current_admin_post_type(), array( 'up_header', 'up_body', 'up_footer' ), true ) ) {
		return;
	}
	echo '<style id="fw-theme-builder-no-editor-toggle">.page-builder-hide-button{display:none !important;}</style>' . "\n";
}
add_action( 'admin_head', '_action_fw_theme_builder_hide_editor_toggle' );
