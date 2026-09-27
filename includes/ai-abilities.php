<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * AI Assistant abilities for the Theme Builder.
 *
 * Header / Body / Footer PARTS (up_header, up_body, up_footer) are ordinary page-builder posts, so the
 * AI builds and edits them with the AI Assistant's page abilities (create_page with that post_type,
 * insert_items, update_element …). What those cannot do is decide WHERE a part shows — that is a
 * Template (up_template): header + body + footer + display conditions. These abilities cover it:
 *
 *   theme-builder-list           parts and templates, what each template applies to, and the rule vocabulary
 *   theme-builder-save-template  create or update a template (parts, conditions, enabled, priority)
 *
 * Conditions go through FW_Theme_Builder_Conditions::rows_to_conditions(), which re-derives every rule
 * from its vocabulary (the same guard the admin editor uses). Writes are snapshotted first (a new
 * template is trashed on undo) — see the AI Assistant's undo_change.
 */

if ( ! function_exists( 'fw_ext_theme_builder_ai_register' ) ) :

	/** Loads the conditions helper outside wp-admin (REST / MCP requests). */
	function fw_ext_theme_builder_ai_conditions() {
		if ( ! class_exists( 'FW_Theme_Builder_Conditions' ) ) {
			require_once dirname( __FILE__ ) . '/class-fw-theme-builder-conditions.php';
		}
	}

	/** @return array part post type => kind */
	function fw_ext_theme_builder_ai_parts() {
		return array( 'up_header' => 'header', 'up_body' => 'body', 'up_footer' => 'footer' );
	}

	/**
	 * @param int $id
	 * @return array|null { id, title }
	 */
	function fw_ext_theme_builder_ai_part_ref( $id ) {
		$id = (int) $id;
		return $id ? array( 'id' => $id, 'title' => get_the_title( $id ) ) : null;
	}

	function fw_ext_theme_builder_ai_register() {
		if ( ! function_exists( 'fw_ai_register_ability' ) ) {
			return;
		}

		fw_ai_register_ability( 'theme-builder-list', array(
			'label'       => __( 'List Theme Builder parts and templates', 'fw' ),
			'description' => 'The site\'s Theme Builder: header / body / footer PARTS (builder posts — edit their content with get_page / insert_items / update_element using the part id; create a new one with create_page and post_type up_header, up_body or up_footer, status publish), and TEMPLATES that combine parts with display conditions (where they apply, e.g. "Entire site", "Singular: page", "Front page"). Also returns the condition rule vocabulary theme_builder_save_template accepts. A template with header_id 0 inherits the site default header (same for footer); body 0 means the normal page content.',
			'permission'  => 'edit_theme_options',
			'readonly'    => true,
			'execute'     => function () {
				fw_ext_theme_builder_ai_conditions();
				$parts = array();
				foreach ( fw_ext_theme_builder_ai_parts() as $pt => $kind ) {
					$parts[ $kind . 's' ] = array();
					foreach ( get_posts( array( 'post_type' => $pt, 'post_status' => array( 'publish', 'draft' ), 'numberposts' => 100, 'orderby' => 'title', 'order' => 'ASC' ) ) as $p ) {
						$parts[ $kind . 's' ][] = array( 'id' => $p->ID, 'title' => $p->post_title, 'status' => $p->post_status );
					}
				}
				$templates = array();
				foreach ( get_posts( array( 'post_type' => 'up_template', 'post_status' => 'publish', 'numberposts' => 100 ) ) as $t ) {
					$cond        = (array) fw_get_db_post_option( $t->ID, 'tb_conditions', array() );
					$templates[] = array(
						'id'        => $t->ID,
						'title'     => $t->post_title,
						'header'    => fw_ext_theme_builder_ai_part_ref( fw_get_db_post_option( $t->ID, 'tb_header_id', 0 ) ),
						'body'      => fw_ext_theme_builder_ai_part_ref( fw_get_db_post_option( $t->ID, 'tb_body_id', 0 ) ),
						'footer'    => fw_ext_theme_builder_ai_part_ref( fw_get_db_post_option( $t->ID, 'tb_footer_id', 0 ) ),
						'applies'   => wp_strip_all_tags( (string) FW_Theme_Builder_Conditions::summarize( $cond ) ),
						'rules'     => FW_Theme_Builder_Conditions::conditions_to_rows( $cond ),
						'enabled'   => ! fw_get_db_post_option( $t->ID, 'tb_disabled', 0 ),
						'priority'  => (int) fw_get_db_post_option( $t->ID, 'tb_priority', 0 ),
					);
				}
				$vocab = array();
				foreach ( FW_Theme_Builder_Conditions::rule_types() as $type => $spec ) {
					$row = array( 'type' => $type, 'label' => (string) $spec['label'], 'hint' => wp_strip_all_tags( (string) ( $spec['hint'] ?? '' ) ) );
					if ( ! empty( $spec['sub'] ) ) {
						$row['sub_types'] = array_map( 'wp_strip_all_tags', (array) FW_Theme_Builder_Conditions::sub_choices( $type ) );
					}
					if ( ! empty( $spec['ids'] ) ) {
						$row['ids'] = $spec['ids']; // optional | required — post / term / user ids
					}
					$vocab[] = $row;
				}
				return array( 'parts' => $parts, 'templates' => $templates, 'rule_types' => $vocab );
			},
		) );

		fw_ai_register_ability( 'theme-builder-save-template', array(
			'label'       => __( 'Create or update a Theme Builder template', 'fw' ),
			'description' => 'Creates a template (omit template_id) or updates one: which header / body / footer part it uses (ids from theme_builder_list; 0 = inherit the default header / footer, or the normal content for body) and WHERE it applies — rules is a list of { side: "use_on" | "exclude_from", type, sub_type?, ids? } using the rule_types vocabulary from theme_builder_list (e.g. { side: "use_on", type: "df" } = entire site; { side: "use_on", type: "pt", sub_type: "page", ids: [42] } = one page). When several templates match a request the most specific wins, then the higher priority (-100…100). Changes apply to the live site immediately; undo with undo_change.',
			'input'       => array(
				'template_id' => array( 'type' => 'integer' ),
				'title'       => array( 'type' => 'string' ),
				'header_id'   => array( 'type' => 'integer', 'minimum' => 0 ),
				'body_id'     => array( 'type' => 'integer', 'minimum' => 0 ),
				'footer_id'   => array( 'type' => 'integer', 'minimum' => 0 ),
				'rules'       => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
				'relation'    => array( 'type' => 'string', 'enum' => array( 'or', 'and' ) ),
				'enabled'     => array( 'type' => 'boolean' ),
				'priority'    => array( 'type' => 'integer', 'minimum' => -100, 'maximum' => 100 ),
			),
			'permission'  => 'edit_theme_options',
			'execute'     => 'fw_ext_theme_builder_ai_save_template',
		) );
	}
	add_action( 'fw_ai_assistant_register_abilities', 'fw_ext_theme_builder_ai_register' );

	/**
	 * @param array $in
	 * @return array|WP_Error
	 */
	function fw_ext_theme_builder_ai_save_template( $in ) {
		fw_ext_theme_builder_ai_conditions();
		$id = isset( $in['template_id'] ) ? (int) $in['template_id'] : 0;
		if ( $id && get_post_type( $id ) !== 'up_template' ) {
			return new WP_Error( 'fw_tb_ai_bad_template', 'template_id is not a Theme Builder template.' );
		}
		if ( ! $id && empty( $in['rules'] ) ) {
			return new WP_Error( 'fw_tb_ai_no_rules', 'A new template needs rules saying where it applies (e.g. [{ side: "use_on", type: "df" }] for the entire site).' );
		}

		// Parts must exist and be the right kind.
		foreach ( array( 'header_id' => 'up_header', 'body_id' => 'up_body', 'footer_id' => 'up_footer' ) as $key => $pt ) {
			if ( ! empty( $in[ $key ] ) && get_post_type( (int) $in[ $key ] ) !== $pt ) {
				return new WP_Error( 'fw_tb_ai_bad_part', sprintf( '%s %d is not a %s part (see theme_builder_list).', $key, (int) $in[ $key ], str_replace( 'up_', '', $pt ) ) );
			}
		}

		$conditions = null;
		if ( isset( $in['rules'] ) ) {
			$conditions = FW_Theme_Builder_Conditions::rows_to_conditions( (array) json_decode( wp_json_encode( $in['rules'] ), true ), (string) ( $in['relation'] ?? 'or' ) );
			if ( empty( $conditions['use_on'] ) ) {
				return new WP_Error( 'fw_tb_ai_bad_rules', 'None of the rules was valid, so the template would apply nowhere. Use the rule_types from theme_builder_list (a type, and a sub_type where the type needs one).' );
			}
		}

		if ( $id ) {
			$keys = array();
			foreach ( array_keys( (array) get_post_meta( $id ) ) as $k ) {
				if ( strpos( $k, 'fw' ) === 0 ) {
					$keys[] = $k;
				}
			}
			$rev = fw_ai_snapshot( array( 'post_meta' => array( $id => $keys ) ), 'unysonplus/theme-builder-save-template', sprintf( 'Changed Theme Builder template "%s"', get_the_title( $id ) ) );
			if ( isset( $in['title'] ) && trim( (string) $in['title'] ) !== '' ) {
				wp_update_post( array( 'ID' => $id, 'post_title' => sanitize_text_field( (string) $in['title'] ) ) );
			}
		} else {
			$id = wp_insert_post( array(
				'post_type'   => 'up_template',
				'post_status' => 'publish',
				'post_title'  => sanitize_text_field( (string) ( $in['title'] ?? __( 'Untitled Template', 'fw' ) ) ),
			), true );
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			$rev = fw_ai_snapshot( array( 'created_posts' => array( $id ) ), 'unysonplus/theme-builder-save-template', sprintf( 'Created Theme Builder template "%s"', get_the_title( $id ) ) );
			foreach ( array( 'tb_header_id', 'tb_body_id', 'tb_footer_id' ) as $k ) {
				fw_set_db_post_option( $id, $k, 0 );
			}
			fw_set_db_post_option( $id, 'tb_disabled', 0 );
			fw_set_db_post_option( $id, 'tb_priority', 0 );
		}

		foreach ( array( 'header_id', 'body_id', 'footer_id' ) as $key ) {
			if ( isset( $in[ $key ] ) ) {
				fw_set_db_post_option( $id, 'tb_' . $key, (int) $in[ $key ] );
			}
		}
		if ( $conditions !== null ) {
			fw_set_db_post_option( $id, 'tb_conditions', $conditions );
		}
		if ( isset( $in['enabled'] ) ) {
			fw_set_db_post_option( $id, 'tb_disabled', $in['enabled'] ? 0 : 1 ); // stored inverted, like the admin
		}
		if ( isset( $in['priority'] ) ) {
			fw_set_db_post_option( $id, 'tb_priority', max( -100, min( 100, (int) $in['priority'] ) ) );
		}
		if ( class_exists( 'FW_Theme_Builder_Resolver' ) ) {
			FW_Theme_Builder_Resolver::flush();
		}

		$cond = (array) fw_get_db_post_option( $id, 'tb_conditions', array() );
		return array(
			'ok'               => true,
			'message'          => sprintf( 'Saved Theme Builder template "%s".', get_the_title( $id ) ),
			'template_id'      => (int) $id,
			'applies'          => wp_strip_all_tags( (string) FW_Theme_Builder_Conditions::summarize( $cond ) ),
			'header'           => fw_ext_theme_builder_ai_part_ref( fw_get_db_post_option( $id, 'tb_header_id', 0 ) ),
			'body'             => fw_ext_theme_builder_ai_part_ref( fw_get_db_post_option( $id, 'tb_body_id', 0 ) ),
			'footer'           => fw_ext_theme_builder_ai_part_ref( fw_get_db_post_option( $id, 'tb_footer_id', 0 ) ),
			'enabled'          => ! fw_get_db_post_option( $id, 'tb_disabled', 0 ),
			'undo_revision_id' => $rev,
		);
	}

endif;
