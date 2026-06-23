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
	private $part_post_types = array( 'up_header', 'up_footer', 'up_body' );

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

		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/includes/class-fw-theme-builder-conditions.php';
			require_once dirname( __FILE__ ) . '/includes/class-fw-theme-builder-admin-page.php';
			$this->admin_page = new FW_Theme_Builder_Admin_Page( $this );

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

		register_post_type( 'up_body', array_merge( $part_shared, array(
			'labels' => array(
				'name'               => __( 'Body Templates', 'fw' ),
				'singular_name'      => __( 'Body Template', 'fw' ),
				'menu_name'          => __( 'Body Templates', 'fw' ),
				'name_admin_bar'     => __( 'Body Template', 'fw' ),
				'add_new'            => __( 'Add New', 'fw' ),
				'add_new_item'       => __( 'Add New Body Template', 'fw' ),
				'new_item'           => __( 'New Body Template', 'fw' ),
				'edit_item'          => __( 'Edit Body Template', 'fw' ),
				'view_item'          => __( 'View Body Template', 'fw' ),
				'all_items'          => __( 'Body Templates', 'fw' ),
				'search_items'       => __( 'Search Body Templates', 'fw' ),
				'not_found'          => __( 'No body templates found.', 'fw' ),
				'not_found_in_trash' => __( 'No body templates found in Trash.', 'fw' ),
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
			echo '<li>' . esc_html__( 'A Body Template replaces the page content area for the requests a Template assigns it to.', 'fw' ) . '</li>';
		} else {
			echo '<li>' . esc_html__( 'Use the Header/Footer Elements (menu, logo, search, social, menu toggle) for navigation.', 'fw' ) . '</li>';
		}
		echo '<li>' . esc_html__( 'Assign it: Theme Builder grid (conditional rules), per page/post (Header & Footer box), or site-wide (Theme Settings → General → Pages).', 'fw' ) . '</li>';
		echo '</ul>';
	}
}
