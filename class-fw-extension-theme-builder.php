<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Theme Builder.
 *
 * The UnysonPlus answer to Divi's Theme Builder. Owns four private CPTs:
 *
 *   up_header   — a header layout, authored with the visual page builder.
 *   up_footer   — a footer layout, authored with the visual page builder.
 *   up_body     — a full-page body layout, authored with the visual page builder.
 *   up_template — a "Template": references a header/body/footer part + a block of
 *                 conditional assignment rules (Use On / Exclude From). Pure data
 *                 store — it carries no builder content of its own and is managed
 *                 through the Theme Builder admin grid (added in a later phase),
 *                 NOT the standard post editor, hence show_ui => false.
 *
 * This extension ABSORBS the former `header-footer-builder` extension wholesale
 * (which was never used in production): the up_header / up_footer CPTs, the
 * Type/Behavior meta box, and the fw_ext_hfbuilder_render() render path all live
 * here now under the SAME names, so the theme's existing integration
 * (inc/includes/header-footer-presets.php, template-parts/header-builder.php,
 * footer-builder.php) keeps working with no edits. See hooks.php for the
 * UP_HFBUILDER_OWNS_CPTS sentinel and the page-builder attach.
 *
 * The Type/Behavior meta box (header only) is injected through the framework's
 * `fw_post_options` filter, so the meta-box engine renders + saves it to post
 * meta automatically (read at render via fw_get_db_post_option($id,'hf_type')).
 */
class FW_Extension_Theme_Builder extends FW_Extension {

	/** Part CPTs authored with the page builder. */
	private $part_post_types = array( 'up_header', 'up_body', 'up_footer' );

	/** The bundling Template CPT (data store, no builder content). */
	private $template_post_type = 'up_template';

	/**
	 * @return string[] The builder-authored part CPTs (header/footer/body).
	 */
	public function get_part_post_types() {
		return $this->part_post_types;
	}

	/**
	 * @return string The Template CPT slug.
	 */
	public function get_template_post_type() {
		return $this->template_post_type;
	}

	/**
	 * @internal
	 */
	/** @var FW_Theme_Builder_Admin_Page|null */
	private $admin_page = null;

	public function _init() {
		add_action( 'init', array( $this, '_action_register_post_types' ) );

		// Inject the Header Type / Behavior meta box (header preset only). The
		// framework applies this filter when building meta boxes AND when saving,
		// so values persist to post meta with no custom save handler.
		add_filter( 'fw_post_options', array( $this, '_filter_type_behavior_options' ), 10, 2 );

		// Loop Layout box (body only): columns + gap for when the Body is used as an
		// archive/blog post-loop card. Read at render by views/body-template.php.
		add_filter( 'fw_post_options', array( $this, '_filter_loop_layout_options' ), 10, 2 );

		// Custom CSS / JS box (all three part types): per-preset code that travels with
		// the preset and is output (head / before </body>) only when it renders — on
		// any theme. Read at render in hooks.php.
		add_filter( 'fw_post_options', array( $this, '_filter_custom_code_options' ), 10, 2 );

		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/includes/class-fw-theme-builder-conditions.php';
			require_once dirname( __FILE__ ) . '/includes/class-fw-theme-builder-admin-page.php';
			$this->admin_page = new FW_Theme_Builder_Admin_Page( $this );

			// Starter presets — seed a few ready-made Header/Body/Footer designs ONCE,
			// directly into the preset lists (no gallery; a one-time flag means a
			// user's deletions/edits are never undone).
			require_once dirname( __FILE__ ) . '/includes/class-fw-theme-builder-library.php';
			new FW_Theme_Builder_Library();

			foreach ( $this->part_post_types as $pt ) {
				add_action( 'add_meta_boxes_' . $pt, array( $this, '_action_add_usage_meta_box' ) );
			}
		}
	}

	/**
	 * Register all four CPTs. Parts (up_header / up_footer / up_body) are
	 * non-public, admin-managed (edit_theme_options), builder-authored (need
	 * 'editor' so the page-builder discovery + attach works). The Template CPT is
	 * a data store: title-only, show_ui => false (managed via the admin grid).
	 *
	 * @internal
	 */
	public function _action_register_post_types() {

		// Map only PRIMITIVE caps to edit_theme_options. With map_meta_cap => true
		// WordPress derives the meta caps (edit_post / delete_post / read_post)
		// against a specific post — remapping the meta caps directly triggers a
		// "map_meta_cap was called incorrectly" notice in WP 6.1+.
		$caps = array(
			'edit_posts'             => 'edit_theme_options',
			'edit_others_posts'      => 'edit_theme_options',
			'edit_published_posts'   => 'edit_theme_options',
			'publish_posts'          => 'edit_theme_options',
			'delete_posts'           => 'edit_theme_options',
			'delete_others_posts'    => 'edit_theme_options',
			'delete_published_posts' => 'edit_theme_options',
			'read_private_posts'     => 'edit_theme_options',
			'create_posts'           => 'edit_theme_options',
			'read'                   => 'read',
		);

		$part_shared = array(
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_in_nav_menus'   => false,
			'show_ui'             => true,
			'show_in_menu'        => 'fw-theme-builder', // grouped under the Theme Builder menu
			'show_in_rest'        => false,
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'hierarchical'        => false,
			'map_meta_cap'        => true,
			'capabilities'        => $caps,
			'supports'            => array( 'title', 'editor', 'revisions' ),
			'menu_icon'           => 'dashicons-layout',
		);

		register_post_type( 'up_header', array_merge( $part_shared, array(
			'labels' => array(
				'name'               => __( 'Header Presets', 'fw' ),
				'singular_name'      => __( 'Header Preset', 'fw' ),
				'menu_name'          => __( 'Header Presets', 'fw' ),
				'name_admin_bar'     => __( 'Header Preset', 'fw' ),
				'add_new'            => __( 'Add New', 'fw' ),
				'add_new_item'       => __( 'Add New Header Preset', 'fw' ),
				'new_item'           => __( 'New Header Preset', 'fw' ),
				'edit_item'          => __( 'Edit Header Preset', 'fw' ),
				'view_item'          => __( 'View Header Preset', 'fw' ),
				'all_items'          => __( 'Header Presets', 'fw' ),
				'search_items'       => __( 'Search Header Presets', 'fw' ),
				'not_found'          => __( 'No header presets found.', 'fw' ),
				'not_found_in_trash' => __( 'No header presets found in Trash.', 'fw' ),
			),
		) ) );

		// Body before Footer so the Theme Builder submenu reads Header → Body →
		// Footer → Templates (submenu order follows CPT registration order).
		register_post_type( 'up_body', array_merge( $part_shared, array(
			'labels' => array(
				'name'               => __( 'Body Presets', 'fw' ),
				'singular_name'      => __( 'Body Preset', 'fw' ),
				'menu_name'          => __( 'Body Presets', 'fw' ),
				'name_admin_bar'     => __( 'Body Preset', 'fw' ),
				'add_new'            => __( 'Add New', 'fw' ),
				'add_new_item'       => __( 'Add New Body Preset', 'fw' ),
				'new_item'           => __( 'New Body Preset', 'fw' ),
				'edit_item'          => __( 'Edit Body Preset', 'fw' ),
				'view_item'          => __( 'View Body Preset', 'fw' ),
				'all_items'          => __( 'Body Presets', 'fw' ),
				'search_items'       => __( 'Search Body Presets', 'fw' ),
				'not_found'          => __( 'No body presets found.', 'fw' ),
				'not_found_in_trash' => __( 'No body presets found in Trash.', 'fw' ),
			),
		) ) );

		register_post_type( 'up_footer', array_merge( $part_shared, array(
			'labels' => array(
				'name'               => __( 'Footer Presets', 'fw' ),
				'singular_name'      => __( 'Footer Preset', 'fw' ),
				'menu_name'          => __( 'Footer Presets', 'fw' ),
				'name_admin_bar'     => __( 'Footer Preset', 'fw' ),
				'add_new'            => __( 'Add New', 'fw' ),
				'add_new_item'       => __( 'Add New Footer Preset', 'fw' ),
				'new_item'           => __( 'New Footer Preset', 'fw' ),
				'edit_item'          => __( 'Edit Footer Preset', 'fw' ),
				'view_item'          => __( 'View Footer Preset', 'fw' ),
				'all_items'          => __( 'Footer Presets', 'fw' ),
				'search_items'       => __( 'Search Footer Presets', 'fw' ),
				'not_found'          => __( 'No footer presets found.', 'fw' ),
				'not_found_in_trash' => __( 'No footer presets found in Trash.', 'fw' ),
			),
		) ) );

		// The bundling Template CPT — a data store (refs + conditions in post
		// meta). show_ui => false: created/edited through the Theme Builder admin
		// grid (a later phase), never the standard post editor. Supports
		// 'revisions' so the grid can offer undo of condition/ref changes.
		register_post_type( $this->template_post_type, array(
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_in_nav_menus'   => false,
			'show_ui'             => false,
			'show_in_menu'        => false,
			'show_in_rest'        => false,
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'hierarchical'        => false,
			'map_meta_cap'        => true,
			'capabilities'        => $caps,
			'supports'            => array( 'title', 'revisions' ),
			'labels'              => array(
				'name'          => __( 'Templates', 'fw' ),
				'singular_name' => __( 'Template', 'fw' ),
			),
		) );
	}

	/**
	 * Header Type + Behavior options, injected as a side meta box on the
	 * up_header edit screen. Stored under leaf ids hf_type / hf_behavior — read at
	 * render time via fw_get_db_post_option(). Wrapped in box → group per the
	 * project's settings-layout convention. (Absorbed verbatim from the former
	 * header-footer-builder extension; the theme reads hf_type / hf_behavior.)
	 *
	 * @internal
	 */
	public function _filter_type_behavior_options( $options, $post_type ) {
		if ( $post_type !== 'up_header' ) {
			return $options;
		}

		$options['up_hf_type_behavior'] = array(
			'title'    => __( 'Header Type & Behavior', 'fw' ),
			'type'     => 'box',
			'context'  => 'side',
			'priority' => 'high',
			'options'  => array(
				'group_hf_type' => array(
					'type'    => 'group',
					'options' => array(
						'hf_type' => array(
							'label'   => __( 'Header Type', 'fw' ),
							'type'    => 'select',
							'value'   => 'standard-top',
							'choices' => array(
								'standard-top'       => __( 'Standard Top (horizontal)', 'fw' ),
								'sidebar'            => __( 'Sidebar (vertical)', 'fw' ),
								'off-canvas'         => __( 'Off-Canvas / Slide-Out', 'fw' ),
								'fullscreen-overlay' => __( 'Fullscreen Overlay', 'fw' ),
								'mega'               => __( 'Mega Menu', 'fw' ),
							),
							'desc'    => __( 'Structural layout. The theme supplies the CSS/JS for each type; your builder content fills it.', 'fw' ),
						),
						'hf_behavior' => array(
							'label'   => __( 'Scroll Behavior', 'fw' ),
							'type'    => 'select',
							'value'   => 'static',
							'choices' => array(
								'static'              => __( 'Static', 'fw' ),
								'sticky'              => __( 'Sticky', 'fw' ),
								'sticky-shrink'       => __( 'Sticky + Shrink', 'fw' ),
								'hide-on-scroll'      => __( 'Hide on scroll down', 'fw' ),
								'transparent-overlay' => __( 'Transparent over hero', 'fw' ),
							),
						),
					),
				),
			),
		);

		return $options;
	}

	/**
	 * Loop Layout — Columns + Gap, injected as a side meta box on the up_body edit
	 * screen. Applies ONLY when the Body is assigned to an archive/blog (it then
	 * renders one card per post): the wrapper reads tb_loop_columns / tb_loop_gap via
	 * fw_get_db_post_option() and lays the cards out N-across. Ignored for singular
	 * and 404/static bodies. Wrapped box → group per the settings-layout convention.
	 *
	 * @internal
	 */
	public function _filter_loop_layout_options( $options, $post_type ) {
		if ( $post_type !== 'up_body' ) {
			return $options;
		}

		$options['up_tb_loop_layout'] = array(
			'title'    => __( 'Loop Layout', 'fw' ),
			'type'     => 'box',
			'context'  => 'side',
			'priority' => 'default',
			'options'  => array(
				'group_tb_loop' => array(
					'type'    => 'group',
					'options' => array(
						'tb_loop_columns' => array(
							'label'   => __( 'Columns', 'fw' ),
							'type'    => 'select',
							'value'   => '3',
							'choices' => array(
								'auto' => __( 'Auto (responsive)', 'fw' ),
								'1'    => __( '1 — single column (list)', 'fw' ),
								'2'    => __( '2 columns', 'fw' ),
								'3'    => __( '3 columns', 'fw' ),
								'4'    => __( '4 columns', 'fw' ),
								'5'    => __( '5 columns', 'fw' ),
								'6'    => __( '6 columns', 'fw' ),
							),
							'desc'    => __( 'When this Body is assigned to a blog/archive, its card repeats per post in this many columns (collapses on smaller screens).', 'fw' ),
						),
						'tb_loop_gap' => array(
							'label'   => __( 'Gap', 'fw' ),
							'type'    => 'select',
							'value'   => 'medium',
							'choices' => array(
								'none'   => __( 'None', 'fw' ),
								'small'  => __( 'Small', 'fw' ),
								'medium' => __( 'Medium', 'fw' ),
								'large'  => __( 'Large', 'fw' ),
							),
						),
					),
				),
			),
		);

		return $options;
	}

	/**
	 * Custom CSS / JS box (Header / Body / Footer presets). Per-preset code stored
	 * under leaf ids custom_css / custom_js — output at render time (head / before
	 * </body>) only when the preset renders, on ANY theme. Because it lives in the
	 * preset's meta it travels with export/import and any bundled library. Read in
	 * hooks.php (_action_fw_tb_preset_custom_css / _js).
	 *
	 * @internal
	 */
	public function _filter_custom_code_options( $options, $post_type ) {
		if ( ! in_array( $post_type, array( 'up_header', 'up_body', 'up_footer' ), true ) ) {
			return $options;
		}

		$options['up_tb_custom_code'] = array(
			'title'    => __( 'Custom CSS & JS', 'fw' ),
			'type'     => 'box',
			'context'  => 'normal',
			'priority' => 'low',
			'options'  => array(
				'group_tb_custom_code' => array(
					'type'    => 'group',
					'options' => array(
						'custom_css' => array(
							'label'  => __( 'Custom CSS', 'fw' ),
							'type'   => 'code-editor',
							'mode'   => 'css',
							'height' => 180,
							'value'  => '',
							'desc'   => __( 'Output in the &lt;head&gt; only when this preset renders — on any theme. Target the CSS classes you used in the preset. No &lt;style&gt; tag needed.', 'fw' ),
						),
						'custom_js' => array(
							'label'  => __( 'Custom JavaScript', 'fw' ),
							'type'   => 'code-editor',
							'mode'   => 'javascript',
							'height' => 180,
							'value'  => '',
							'desc'   => __( 'Output before &lt;/body&gt; only when this preset renders. No &lt;script&gt; tag needed.', 'fw' ),
						),
					),
				),
			),
		);

		return $options;
	}

	/**
	 * @internal
	 */
	public function _action_add_usage_meta_box() {
		add_meta_box(
			'up_theme_builder_usage',
			__( 'How to use', 'fw' ),
			array( $this, '_render_usage_meta_box' ),
			get_current_screen() ? get_current_screen()->post_type : null,
			'side',
			'low'
		);
	}

	/**
	 * @internal
	 */
	public function _render_usage_meta_box( $post ) {
		$is_body = ( $post->post_type === 'up_body' );

		if ( $post->post_status !== 'publish' ) {
			echo '<p>' . esc_html__( 'Publish this, then assign it with a Template (Use On / Exclude From) in the Theme Builder.', 'fw' ) . '</p>';
		}

		echo '<ul style="list-style:disc;margin-left:1.2em;">';
		echo '<li>' . esc_html__( 'Build the content with the page builder.', 'fw' ) . '</li>';
		if ( $is_body ) {
			echo '<li>' . esc_html__( 'A Body Preset replaces the page content area for the requests a Template assigns it to.', 'fw' ) . '</li>';
		} else {
			echo '<li>' . esc_html__( 'Use the Header/Footer Elements (menu, logo, search, social, menu toggle) for navigation.', 'fw' ) . '</li>';
		}
		echo '<li>' . esc_html__( 'Assign it: Theme Builder grid (conditional rules), per page/post (Header & Footer box), or site-wide (Theme Settings → General → Pages).', 'fw' ) . '</li>';
		echo '</ul>';
	}
}
