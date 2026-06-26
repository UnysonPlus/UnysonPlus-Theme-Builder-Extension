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

// The bundled-template seeder (up-templates/*.json → CPTs, manual-edit guarded).
require_once dirname( __FILE__ ) . '/includes/class-fw-theme-builder-seeder.php';

/**
 * Auto-import a theme's bundled Templates when it is activated. Idempotent: the
 * manual-edit guard means a re-activation never clobbers a part/template the user
 * has since edited.
 *
 * @internal
 */
function _action_fw_theme_builder_seed_on_switch() {
	if ( class_exists( 'FW_Theme_Builder_Seeder' ) ) {
		FW_Theme_Builder_Seeder::seed_all( false );
	}
}
add_action( 'after_switch_theme', '_action_fw_theme_builder_seed_on_switch' );

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

	$effective_body = _fw_tb_effective_body_id();

	if ( _fw_tb_is_native_theme() ) {
		// unysonplus-theme family: the theme renders the header/footer presets
		// through get_header()/get_footer(); the plugin only takes over the page to
		// render a Body. Established behavior — unchanged.
		if ( $effective_body > 0 ) {
			$wrapper = dirname( __FILE__ ) . '/views/body-template.php';
			if ( is_readable( $wrapper ) ) {
				return $wrapper;
			}
		}
		return $template;
	}

	// Theme-independent (any non-unysonplus theme): when a Body applies, bypass the
	// foreign theme's template and render the WHOLE page (header preset + body +
	// footer preset). Header/footer-only Templates keep the theme's page and swap
	// only its <header>/<footer> — see _action_fw_tb_surgical_swap().
	if ( $effective_body > 0 ) {
		$standalone = dirname( __FILE__ ) . '/views/standalone-template.php';
		if ( is_readable( $standalone ) ) {
			return $standalone;
		}
	}

	return $template;
}
add_filter( 'template_include', '_filter_fw_theme_builder_body_template_include', 99 );

/**
 * The Body id that should REPLACE the page for this request, or 0. A matched Body
 * applies unless it would hide a deliberately page-builder-built page: a full
 * replacement body (no [post_content]) never overrides such a page, but a
 * post_content-WRAPPING body does (it renders the page's own content inside the
 * Template layout — the Post Content pattern).
 *
 * @internal
 * @return int
 */
function _fw_tb_effective_body_id() {
	if ( ! class_exists( 'FW_Theme_Builder_Resolver' ) ) {
		return 0;
	}
	$body_id = (int) FW_Theme_Builder_Resolver::body_id();
	if ( $body_id <= 0 ) {
		return 0;
	}
	$wraps = _fw_tb_body_uses_post_content( $body_id );
	if ( is_singular()
	     && function_exists( 'fw_ext_page_builder_is_builder_post' )
	     && fw_ext_page_builder_is_builder_post( (int) get_queried_object_id() )
	     && ! $wraps ) {
		return 0;
	}
	return $body_id;
}

/**
 * True when the active theme ships the unysonplus-theme's Theme-Builder integration
 * (it renders header/footer presets itself through get_header()/get_footer()).
 * Detected by the integration function the theme defines, so the unysonplus-theme
 * AND its child themes count as native while any third-party theme does not. On a
 * non-native theme the plugin renders presets itself (full-page takeover for a
 * Body, surgical <header>/<footer> swap for header/footer-only Templates).
 * Filterable via `fw_theme_builder_native_theme`.
 *
 * @internal
 * @return bool
 */
function _fw_tb_is_native_theme() {
	return (bool) apply_filters(
		'fw_theme_builder_native_theme',
		function_exists( 'unysonplus_get_active_preset_id' )
	);
}

/**
 * Opening-tag attributes for a theme-independent <header> wrapper. Translates the
 * preset's Scroll Behavior into the `fw-tb-header--<behavior>` class + the
 * `data-hf-behavior` / `data-hf-type` attributes that header-behaviors.css/js key
 * off, so Sticky / Sticky-shrink / Hide-on-scroll / Transparent-overlay work under
 * any theme. Returns a leading-space attribute string.
 *
 * @internal
 * @param int $header_id
 * @return string
 */
function _fw_tb_header_wrapper_atts( $header_id ) {
	$classes   = array( 'fw-tb-header' );
	$attrs     = ' role="banner"';
	$header_id = (int) $header_id;
	if ( $header_id > 0 && function_exists( 'fw_get_db_post_option' ) ) {
		$behavior = (string) fw_get_db_post_option( $header_id, 'hf_behavior' );
		$type     = (string) fw_get_db_post_option( $header_id, 'hf_type' );
		if ( in_array( $behavior, array( 'sticky', 'sticky-shrink', 'hide-on-scroll', 'transparent-overlay' ), true ) ) {
			$classes[] = 'fw-tb-header--' . $behavior;
			$attrs    .= ' data-hf-behavior="' . esc_attr( $behavior ) . '"';
		}
		if ( $type !== '' ) {
			$attrs .= ' data-hf-type="' . esc_attr( $type ) . '"';
		}
	}
	return ' class="' . esc_attr( implode( ' ', $classes ) ) . '"' . $attrs;
}

/* ----------------------------------------------------------------------- */
/* Live preview (admin-gated) — see a Template/preset on a real page before  */
/* it is published or assigned.                                              */
/* ----------------------------------------------------------------------- */

/**
 * The parts a valid `?fw_tb_preview` front-end request wants forced, or null. Gated
 * on `edit_theme_options` + a nonce, so only an editor previewing from the admin can
 * trigger it. Shape matches the resolver.
 *
 * @internal
 * @return array|null
 */
function _fw_tb_preview_request() {
	if ( empty( $_GET['fw_tb_preview'] ) || is_admin() || ! current_user_can( 'edit_theme_options' ) ) {
		return null;
	}
	$nonce = isset( $_GET['fw_tb_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['fw_tb_nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'fw_tb_preview' ) ) {
		return null;
	}
	$tpl = isset( $_GET['fw_tb_template'] ) ? (int) $_GET['fw_tb_template'] : 0;
	if ( $tpl > 0 && function_exists( 'fw_get_db_post_option' ) ) {
		return array(
			'template_id' => $tpl,
			'header_id'   => (int) fw_get_db_post_option( $tpl, 'tb_header_id' ),
			'body_id'     => (int) fw_get_db_post_option( $tpl, 'tb_body_id' ),
			'footer_id'   => (int) fw_get_db_post_option( $tpl, 'tb_footer_id' ),
		);
	}
	return array(
		'template_id' => 0,
		'header_id'   => isset( $_GET['fw_tb_header'] ) ? (int) $_GET['fw_tb_header'] : 0,
		'body_id'     => isset( $_GET['fw_tb_body'] ) ? (int) $_GET['fw_tb_body'] : 0,
		'footer_id'   => isset( $_GET['fw_tb_footer'] ) ? (int) $_GET['fw_tb_footer'] : 0,
	);
}

/**
 * Override the resolver with the preview parts when a valid preview is requested.
 *
 * @internal
 */
function _filter_fw_tb_preview_resolve( $best ) {
	$preview = _fw_tb_preview_request();
	return ( null !== $preview ) ? $preview : $best;
}
add_filter( 'fw_theme_builder_resolved', '_filter_fw_tb_preview_resolve' );

/**
 * Build a front-end preview URL. `$args` accepts `template` => id, or any of
 * `header`/`body`/`footer` => id. `$against` is the page URL to preview on (defaults
 * to the site home).
 *
 * @param array  $args
 * @param string $against
 * @return string
 */
function fw_tb_preview_url( $args, $against = '' ) {
	$query = array(
		'fw_tb_preview' => 1,
		'fw_tb_nonce'   => wp_create_nonce( 'fw_tb_preview' ),
	);
	foreach ( array( 'template', 'header', 'body', 'footer' ) as $k ) {
		if ( ! empty( $args[ $k ] ) ) {
			$query[ 'fw_tb_' . $k ] = (int) $args[ $k ];
		}
	}
	return add_query_arg( $query, $against ? $against : home_url( '/' ) );
}

/**
 * A small fixed "Preview" badge during a live preview, so it's obvious the page is a
 * preview and not the published result. Rendered in wp_footer (fires in the theme
 * page, the surgical-swap page, and the standalone document alike).
 *
 * @internal
 */
function _action_fw_tb_preview_badge() {
	if ( null === _fw_tb_preview_request() ) {
		return;
	}
	echo '<div class="fw-tb-preview-badge" style="position:fixed;z-index:99999;left:50%;bottom:18px;'
		. 'transform:translateX(-50%);background:#1d2327;color:#fff;'
		. 'font:600 12px/1 -apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;'
		. 'padding:10px 18px;border-radius:999px;box-shadow:0 6px 20px rgba(0,0,0,.28)">'
		. esc_html__( 'Theme Builder — Preview', 'fw' ) . '</div>';
}
add_action( 'wp_footer', '_action_fw_tb_preview_badge', 99 );

/**
 * Add a **Preview** row action to the Header / Body / Footer preset lists.
 *
 * @internal
 */
function _filter_fw_tb_preset_row_actions( $actions, $post ) {
	$kinds = array( 'up_header' => 'header', 'up_body' => 'body', 'up_footer' => 'footer' );
	if ( isset( $kinds[ $post->post_type ] ) && current_user_can( 'edit_theme_options' ) ) {
		$url = fw_tb_preview_url( array( $kinds[ $post->post_type ] => $post->ID ) );
		$actions['fw_tb_preview'] = '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">'
			. esc_html__( 'Preview', 'fw' ) . '</a>';
	}
	return $actions;
}
add_filter( 'post_row_actions', '_filter_fw_tb_preset_row_actions', 10, 2 );

/* ----------------------------------------------------------------------- */
/* Per-preset Custom CSS / JS — travels with the preset, output only when it  */
/* renders, on any theme.                                                     */
/* ----------------------------------------------------------------------- */

/**
 * The part post ids (header/body/footer) that actually render for this request,
 * de-duped. Uses the theme's own resolution when native (so per-page header/footer
 * overrides are respected) and the resolver otherwise — and during a live preview,
 * which the resolver carries.
 *
 * @internal
 * @return int[]
 */
function _fw_tb_rendering_part_ids() {
	if ( ! class_exists( 'FW_Theme_Builder_Resolver' ) ) {
		return array();
	}
	$preview = function_exists( '_fw_tb_preview_request' ) && null !== _fw_tb_preview_request();
	$native  = function_exists( 'unysonplus_get_active_preset_id' ) && ! $preview;
	$header  = $native ? (int) unysonplus_get_active_preset_id( 'header' ) : (int) FW_Theme_Builder_Resolver::header_id();
	$footer  = $native ? (int) unysonplus_get_active_preset_id( 'footer' ) : (int) FW_Theme_Builder_Resolver::footer_id();
	$body    = (int) FW_Theme_Builder_Resolver::body_id();
	return array_values( array_unique( array_filter( array( $header, $body, $footer ) ) ) );
}

/**
 * Output each rendering preset's Custom CSS in the head (any theme). Editor-authored
 * (edit_theme_options), so emitted as-is.
 *
 * @internal
 */
function _action_fw_tb_preset_custom_css() {
	if ( is_admin() || ! function_exists( 'fw_get_db_post_option' ) ) {
		return;
	}
	foreach ( _fw_tb_rendering_part_ids() as $pid ) {
		$css = trim( (string) fw_get_db_post_option( $pid, 'custom_css' ) );
		if ( '' !== $css ) {
			echo "\n" . '<style id="fw-tb-preset-' . (int) $pid . '-css">' . "\n" . $css . "\n" . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput — editor CSS
		}
	}
}
add_action( 'wp_head', '_action_fw_tb_preset_custom_css', 99 );

/**
 * Output each rendering preset's Custom JS before </body> (any theme).
 *
 * @internal
 */
function _action_fw_tb_preset_custom_js() {
	if ( is_admin() || ! function_exists( 'fw_get_db_post_option' ) ) {
		return;
	}
	foreach ( _fw_tb_rendering_part_ids() as $pid ) {
		$js = trim( (string) fw_get_db_post_option( $pid, 'custom_js' ) );
		if ( '' !== $js ) {
			echo "\n" . '<script id="fw-tb-preset-' . (int) $pid . '-js">' . "\n" . $js . "\n" . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput — editor JS
		}
	}
}
add_action( 'wp_footer', '_action_fw_tb_preset_custom_js', 99 );

/**
 * Front-end "what renders here?" debugger — an admin-bar node (editors only) that
 * shows the resolved Theme Builder Template for the current page, its header / body /
 * footer, and every matching or excluded Template with its specificity score, so
 * overlapping conditions are easy to reason about. No-op when there are no Templates.
 *
 * @internal
 * @param WP_Admin_Bar $bar
 */
function _action_fw_tb_admin_bar( $bar ) {
	if ( is_admin() || ! current_user_can( 'edit_theme_options' ) || ! class_exists( 'FW_Theme_Builder_Resolver' ) ) {
		return;
	}
	$debug = FW_Theme_Builder_Resolver::debug();
	if ( null === $debug ) {
		return;
	}
	$resolved = $debug['resolved'];
	$win_id   = $resolved ? (int) $resolved['template_id'] : 0;

	$bar->add_node( array(
		'id'    => 'fw-tb-debug',
		'title' => $win_id
			? esc_html( sprintf( __( 'Theme Builder: %s', 'fw' ), get_the_title( $win_id ) ) )
			: esc_html__( 'Theme Builder: no Template (site header/footer)', 'fw' ),
	) );

	if ( $win_id ) {
		$parts = array( 'header_id' => __( 'Header', 'fw' ), 'body_id' => __( 'Body', 'fw' ), 'footer_id' => __( 'Footer', 'fw' ) );
		foreach ( $parts as $k => $label ) {
			$pid = (int) $resolved[ $k ];
			$val = $pid ? get_the_title( $pid ) : ( 'body_id' === $k ? __( '— none —', 'fw' ) : __( '— inherit —', 'fw' ) );
			$bar->add_node( array(
				'parent' => 'fw-tb-debug',
				'id'     => 'fw-tb-part-' . $k,
				'title'  => esc_html( $label . ': ' . $val ),
				'href'   => $pid ? get_edit_post_link( $pid ) : false,
			) );
		}
		$bar->add_node( array(
			'parent' => 'fw-tb-debug',
			'id'     => 'fw-tb-edit-tpl',
			'title'  => esc_html__( 'Edit this Template ↗', 'fw' ),
			'href'   => add_query_arg( array( 'page' => 'fw-theme-builder', 'view' => 'edit', 'id' => $win_id ), admin_url( 'admin.php' ) ),
		) );
	}

	$shown = 0;
	foreach ( $debug['candidates'] as $c ) {
		if ( $c['score'] < 0 && ! $c['excluded'] ) {
			continue;
		}
		$note = $c['excluded']
			? __( 'excluded', 'fw' )
			: ( $c['id'] === $win_id ? __( 'WINS', 'fw' ) : sprintf( __( 'score %d', 'fw' ), $c['score'] ) );
		$bar->add_node( array(
			'parent' => 'fw-tb-debug',
			'id'     => 'fw-tb-cand-' . $c['id'],
			'title'  => esc_html( '• ' . $c['name'] . ' — ' . $note ),
			'href'   => add_query_arg( array( 'page' => 'fw-theme-builder', 'view' => 'edit', 'id' => $c['id'] ), admin_url( 'admin.php' ) ),
		) );
		$shown++;
	}
	if ( ! $shown && ! $win_id ) {
		$bar->add_node( array(
			'parent' => 'fw-tb-debug',
			'id'     => 'fw-tb-nomatch',
			'title'  => esc_html__( 'No Template matched this request.', 'fw' ),
		) );
	}
}
add_action( 'admin_bar_menu', '_action_fw_tb_admin_bar', 100 );

/**
 * True when a body preset contains a [post_content] element. Such a body WRAPS the
 * queried page's own content (so it may apply even to page-builder pages); one
 * without it fully REPLACES the content (so it skips builder pages). Cheap string
 * probe of the stored builder JSON.
 *
 * @internal
 */
function _fw_tb_body_uses_post_content( $body_id ) {
	$pb   = function_exists( 'fw_get_db_post_option' ) ? fw_get_db_post_option( (int) $body_id, 'page-builder' ) : null;
	$json = ( is_array( $pb ) && isset( $pb['json'] ) ) ? (string) $pb['json'] : '';
	return strpos( $json, 'post_content' ) !== false;
}

/**
 * Enqueue the loop-grid stylesheet only when an archive/list request will render a
 * Body Template as a post loop (the wrapper's loop branch). Skipped on singular,
 * 404 and admin — those render the body once, no grid. Cheap conditional: the
 * resolver result is request-cached, so body_id() here reuses the same lookup the
 * template_include filter makes.
 *
 * @internal
 */
function _action_fw_theme_builder_enqueue_loop_assets() {
	if ( is_admin() || is_singular() || is_404() || ! class_exists( 'FW_Theme_Builder_Resolver' ) ) {
		return;
	}
	if ( (int) FW_Theme_Builder_Resolver::body_id() <= 0 ) {
		return;
	}
	$ext = function_exists( 'fw_ext' ) ? fw_ext( 'theme-builder' ) : null;
	if ( ! $ext ) {
		return;
	}
	wp_enqueue_style(
		'fw-theme-builder-loop',
		$ext->get_uri( '/static/css/loop.css' ),
		array(),
		$ext->manifest->get_version()
	);
}
add_action( 'wp_enqueue_scripts', '_action_fw_theme_builder_enqueue_loop_assets' );

/**
 * Theme-independent only: enqueue the matched Template's preset shortcode assets
 * (CSS/JS) into the head. The unysonplus-theme enqueues these itself; a foreign
 * theme does not, so without this the preset markup would render UNSTYLED. Reuses
 * the shortcodes extension's own per-content static enqueuer, so every shortcode a
 * preset uses pulls exactly the assets it needs — for the standalone takeover and
 * the surgical <header>/<footer> swap alike. No-op on native themes / admin / when
 * no Template matches.
 *
 * @internal
 */
function _action_fw_tb_enqueue_preset_assets() {
	if ( is_admin() || _fw_tb_is_native_theme() || ! class_exists( 'FW_Theme_Builder_Resolver' ) ) {
		return;
	}
	$r = FW_Theme_Builder_Resolver::resolve();
	if ( ! $r ) {
		return;
	}

	// Portable header assets (foreign theme): when a header preset applies, ship the
	// small bundles that the native theme would otherwise provide.
	$hid = isset( $r['header_id'] ) ? (int) $r['header_id'] : 0;
	if ( $hid > 0 ) {
		$tb_ext = function_exists( 'fw_ext' ) ? fw_ext( 'theme-builder' ) : null;
		if ( $tb_ext ) {
			$tb_ver  = $tb_ext->manifest->get_version();
			$tb_base = $tb_ext->get_uri( '/static' );

			// Scroll behaviors — only when the header has one (Sticky / Shrink /
			// Hide-on-scroll / Transparent).
			if ( function_exists( 'fw_get_db_post_option' )
			     && in_array( (string) fw_get_db_post_option( $hid, 'hf_behavior' ), array( 'sticky', 'sticky-shrink', 'hide-on-scroll', 'transparent-overlay' ), true ) ) {
				wp_enqueue_style( 'fw-tb-header-behaviors', $tb_base . '/css/header-behaviors.css', array(), $tb_ver );
				wp_enqueue_script( 'fw-tb-header-behaviors', $tb_base . '/js/header-behaviors.js', array(), $tb_ver, true );
			}

			// Mobile menu drawer so the Menu Toggle / Off-canvas / Fullscreen-overlay
			// header types work here too. The script no-ops if the header has no
			// Menu Toggle, so it is safe to ship for any header.
			wp_enqueue_style( 'fw-tb-portable-drawer', $tb_base . '/css/portable-drawer.css', array(), $tb_ver );
			wp_enqueue_script( 'fw-tb-portable-drawer', $tb_base . '/js/portable-drawer.js', array(), $tb_ver, true );
		}
	}

	$sc = function_exists( 'fw_ext' ) ? fw_ext( 'shortcodes' ) : null;
	if ( ! $sc || ! method_exists( $sc, 'enqueue_shortcodes_static' ) || ! function_exists( 'fw_ext_page_builder_get_post_content' ) ) {
		return;
	}
	foreach ( array( 'header_id', 'body_id', 'footer_id' ) as $k ) {
		$pid = isset( $r[ $k ] ) ? (int) $r[ $k ] : 0;
		if ( $pid <= 0 ) {
			continue;
		}
		$post = get_post( $pid );
		if ( $post ) {
			$sc->enqueue_shortcodes_static( fw_ext_page_builder_get_post_content( $post ) );
		}
	}
}
add_action( 'wp_enqueue_scripts', '_action_fw_tb_enqueue_preset_assets', 9 );

/**
 * Theme-independent only: for a Template that sets a header and/or footer but whose
 * Body does NOT replace the page, keep the foreign theme's page intact and swap
 * just its site <header> / <footer> for the preset(s) via output buffering — so the
 * theme's content, sidebars and layout are preserved while the page gets the
 * Template's chrome. Best-effort: targets the first top-level <header> and the last
 * <footer>; a theme with unconventional markup can be retargeted with the
 * `fw_theme_builder_swap_pattern` filter, and if no tag is found the preset is
 * injected at the matching <body> edge as a fallback.
 *
 * @internal
 */
function _action_fw_tb_surgical_swap() {
	if ( is_admin() || is_feed() || is_embed()
	     || ( function_exists( 'is_robots' ) && is_robots() )
	     || _fw_tb_is_native_theme() || ! class_exists( 'FW_Theme_Builder_Resolver' ) ) {
		return;
	}
	// A body that replaces the page is handled by the standalone takeover, which
	// renders the header/footer itself — don't also buffer-swap.
	if ( _fw_tb_effective_body_id() > 0 ) {
		return;
	}
	$header_id = (int) FW_Theme_Builder_Resolver::header_id();
	$footer_id = (int) FW_Theme_Builder_Resolver::footer_id();
	if ( $header_id <= 0 && $footer_id <= 0 ) {
		return;
	}

	// Pre-render the preset markup NOW, BEFORE output buffering starts. The preset
	// render runs through do_shortcode(), which itself calls ob_start() — and PHP
	// forbids ob_start() inside an output-buffering display handler ("Cannot use
	// output buffering in output buffering display handlers"). So all rendering
	// happens here and the flush callback only SPLICES the ready-made strings. This
	// also lands the presets' render-time enqueues before wp_head().
	$blocks = array( 'header' => '', 'footer' => '' );
	if ( $header_id > 0 && function_exists( 'fw_ext_hfbuilder_render' ) ) {
		$inner = fw_ext_hfbuilder_render( $header_id, 'header' );
		if ( is_string( $inner ) && $inner !== '' ) {
			$blocks['header'] = '<header' . _fw_tb_header_wrapper_atts( $header_id ) . '>' . $inner . '</header>';
		}
	}
	if ( $footer_id > 0 && function_exists( 'fw_ext_hfbuilder_render' ) ) {
		$inner = fw_ext_hfbuilder_render( $footer_id, 'footer' );
		if ( is_string( $inner ) && $inner !== '' ) {
			$blocks['footer'] = '<footer class="fw-tb-footer" role="contentinfo">' . $inner . '</footer>';
		}
	}
	if ( $blocks['header'] === '' && $blocks['footer'] === '' ) {
		return; // nothing rendered (empty/unpublished presets) — leave the theme alone
	}

	$GLOBALS['_fw_tb_swap_blocks'] = $blocks;
	ob_start( '_fw_tb_swap_buffer_cb' );
}
add_action( 'template_redirect', '_action_fw_tb_surgical_swap', 100 );

/**
 * Output-buffer callback (runs once at flush): splice the PRE-RENDERED preset
 * header/footer blocks (built in _action_fw_tb_surgical_swap, before buffering) over
 * the foreign theme's site <header>/<footer>. PURE STRING WORK ONLY — no rendering
 * and no ob_start(), both of which are illegal inside an output-buffering handler.
 *
 * @internal
 * @param string $html
 * @return string
 */
function _fw_tb_swap_buffer_cb( $html ) {
	if ( ! is_string( $html ) || $html === '' ) {
		return $html;
	}
	// We're now executing INSIDE an output-buffering display handler, where a
	// nested ob_start() is a FATAL ("Cannot use output buffering in output
	// buffering display handlers"). Flag it so framework view helpers
	// (fw_render_view) degrade to inline rendering instead of fataling the page
	// — should any swap-pattern filter or splice path ever render a view here.
	$GLOBALS['_fw_ob_handler_running'] = true;
	try {
		$blocks = isset( $GLOBALS['_fw_tb_swap_blocks'] ) ? $GLOBALS['_fw_tb_swap_blocks'] : array();
		$header = isset( $blocks['header'] ) ? (string) $blocks['header'] : '';
		$footer = isset( $blocks['footer'] ) ? (string) $blocks['footer'] : '';

		if ( $header !== '' ) {
			$html = _fw_tb_swap_region( $html, 'header', $header, false );
		}
		if ( $footer !== '' ) {
			$html = _fw_tb_swap_region( $html, 'footer', $footer, true );
		}
	} finally {
		$GLOBALS['_fw_ob_handler_running'] = false;
	}
	return $html;
}

/**
 * Replace ONE <$tag>…</$tag> region in $html with $replacement. $last picks the
 * LAST occurrence (site footer) over the FIRST (site header). substr-based splice
 * (not preg_replace) so `$`/`\` in the preset HTML are never mis-read as backrefs.
 * Falls back to injecting at the matching <body> edge when no such tag exists (e.g.
 * a block theme that emits no semantic <header>). Pattern is filterable.
 *
 * @internal
 * @param string $html
 * @param string $tag
 * @param string $replacement
 * @param bool   $last
 * @return string
 */
function _fw_tb_swap_region( $html, $tag, $replacement, $last ) {
	$pattern = apply_filters(
		'fw_theme_builder_swap_pattern',
		'~<' . $tag . '\b[^>]*>.*?</' . $tag . '>~is',
		$tag,
		$last
	);

	if ( preg_match_all( $pattern, $html, $m, PREG_OFFSET_CAPTURE ) && ! empty( $m[0] ) ) {
		// Prefer the element that actually looks like the SITE header/footer — by
		// landmark role, the common WP ids/classes, or the block-theme template-part
		// wrapper — so a page with an article <header> or a widget <footer> doesn't
		// get the wrong one swapped. Fall back to first (<header>) / last (<footer>).
		$hint = ( 'footer' === $tag )
			? '~role=["\']contentinfo|\b(?:colophon|site-footer|wp-block-template-part)\b~i'
			: '~role=["\']banner|\b(?:masthead|site-header|wp-block-template-part)\b~i';
		$chosen = null;
		foreach ( $m[0] as $hit ) {
			$gt   = strpos( $hit[0], '>' );
			$open = ( false === $gt ) ? $hit[0] : substr( $hit[0], 0, $gt + 1 );
			if ( preg_match( $hint, $open ) ) {
				$chosen = $hit;
				if ( ! $last ) {
					break; // header: first qualifying wins; footer: keep the last qualifying
				}
			}
		}
		if ( null === $chosen ) {
			$chosen = $last ? end( $m[0] ) : $m[0][0];
		}
		$start = (int) $chosen[1];
		$len   = strlen( $chosen[0] );
		return substr( $html, 0, $start ) . $replacement . substr( $html, $start + $len );
	}

	// Fallback: no <$tag> found — inject at the body edge so the preset still shows.
	if ( $last ) {
		$pos = strripos( $html, '</body>' );
		if ( $pos !== false ) {
			return substr( $html, 0, $pos ) . $replacement . substr( $html, $pos );
		}
	} elseif ( preg_match( '~<body\b[^>]*>~i', $html, $bm, PREG_OFFSET_CAPTURE ) ) {
		$pos = (int) $bm[0][1] + strlen( $bm[0][0] );
		return substr( $html, 0, $pos ) . $replacement . substr( $html, $pos );
	}
	return $html;
}

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
 * Isolate the "Structure" elements (the semantic Flexbox container) to the Theme
 * Builder, exactly like the Dynamic Content tab above. The Flexbox is the layout
 * primitive for building Header / Body / Footer parts, so its palette tab is HIDDEN
 * everywhere else (Pages, Posts, CPTs) and shown only in the part editors.
 *
 * NOTE: the flexbox ELEMENT itself lives in the CORE shortcodes extension
 * (shortcodes/shortcodes/flexbox/ — see its AGENTS.md for why it is there, not
 * here): it is a normal page-builder element and must render even when this
 * optional, download-only extension is absent. This filter only hides it from the
 * palette outside the part editors — it never unregisters it on the front end.
 *
 * Same admin-only contract: a front-end request returns the list untouched so any
 * preset/template already using a flexbox still renders. Disabling the 'flexbox'
 * tag makes the shortcodes loader skip it, which also unregisters its page-builder
 * item type — so the Structure tab disappears from the non-TB palette.
 *
 * @internal
 */
function _filter_fw_theme_builder_structure_elements_scope( $disabled ) {
	// Front end (and any non-admin context): keep it registered so it renders.
	if ( ! is_admin() ) {
		return $disabled;
	}

	// The Theme Builder part editors get the Structure tab; nowhere else does.
	$pt = up_theme_builder_current_admin_post_type();
	if ( in_array( $pt, array( 'up_header', 'up_body', 'up_footer' ), true ) ) {
		return $disabled;
	}

	$structure = apply_filters(
		'fw_theme_builder_structure_elements',
		array( 'flexbox' )
	);

	return array_values( array_unique( array_merge( (array) $disabled, (array) $structure ) ) );
}
add_filter( 'fw_ext_shortcodes_disable_shortcodes', '_filter_fw_theme_builder_structure_elements_scope' );

/**
 * Isolate the "Header/Footer Elements" site-chrome (Menu Toggle / Navigation Menu /
 * Search / Site Logo) to the Theme Builder, like the Dynamic Content and Structure
 * tabs above. These are header/footer furniture — meaningless and cluttering inside
 * a normal page/post — so their palette tab is HIDDEN everywhere except the part
 * editors. (Social Icons is deliberately NOT here: it is a general-purpose element
 * and lives in the Components tab, available on every post type.)
 *
 * Same admin-only contract: the front end gets the list untouched, so any page that
 * already uses one of these still renders. With all four disabled outside the TB,
 * the "Header/Footer Elements" tab simply disappears from the non-TB palette.
 *
 * @internal
 */
function _filter_fw_theme_builder_chrome_elements_scope( $disabled ) {
	// Front end (and any non-admin context): keep them registered so they render.
	if ( ! is_admin() ) {
		return $disabled;
	}

	// The Theme Builder part editors get the Header/Footer Elements; nowhere else.
	$pt = up_theme_builder_current_admin_post_type();
	if ( in_array( $pt, array( 'up_header', 'up_body', 'up_footer' ), true ) ) {
		return $disabled;
	}

	$chrome = apply_filters(
		'fw_theme_builder_chrome_elements',
		array( 'menu_toggle', 'nav_menu', 'site_search', 'site_logo' )
	);

	return array_values( array_unique( array_merge( (array) $disabled, (array) $chrome ) ) );
}
add_filter( 'fw_ext_shortcodes_disable_shortcodes', '_filter_fw_theme_builder_chrome_elements_scope' );

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
