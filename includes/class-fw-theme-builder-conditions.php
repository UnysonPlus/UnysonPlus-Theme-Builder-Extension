<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Theme Builder — conditions editor schema + mapping.
 *
 * Single source of truth that translates between three shapes:
 *
 *   (1) The Unyson OPTION schema rendered in the Add/Edit Template form
 *       (flat checkboxes + populated multi-selects, one set per side).
 *   (2) The submitted/loaded option VALUES for one side.
 *   (3) The resolver's RULE list — rule = { type, sub_type, ids } — stored in the
 *       up_template `tb_conditions` meta and consumed by FW_Theme_Builder_Resolver.
 *
 * Plus (4) a short human "Used On" summary for the templates table.
 *
 * Deliberately flat (no multi-picker / addable-popup) so each control maps 1:1 to
 * a rule kind — robust and easy to round-trip. The two sides ("use_on" /
 * "exclude_from") share the exact same control set, prefixed by side.
 */
class FW_Theme_Builder_Conditions {

	/**
	 * Human labels for the scope tokens.
	 *
	 * @return array<string,string>
	 */
	private static function scope_choices() {
		$choices = array(
			'df'            => __( 'Entire site', 'fw' ),
			'ct:front_page' => __( 'Front page', 'fw' ),
			'ct:blog_index' => __( 'Blog (posts) index', 'fw' ),
			'ct:search'     => __( 'Search results', 'fw' ),
			'ct:error_404'  => __( '404 (not found)', 'fw' ),
		);
		if ( self::woo_active() ) {
			$choices['ct:woo_shop']     = __( 'Shop page (WooCommerce)', 'fw' );
			$choices['ct:woo_cart']     = __( 'Cart (WooCommerce)', 'fw' );
			$choices['ct:woo_checkout'] = __( 'Checkout (WooCommerce)', 'fw' );
			$choices['ct:woo_account']  = __( 'My Account (WooCommerce)', 'fw' );
		}
		return $choices;
	}

	/** True when WooCommerce is active (its conditional tags are available). */
	private static function woo_active() {
		return function_exists( 'is_shop' ) || class_exists( 'WooCommerce' );
	}

	/**
	 * "All of post type" checkbox choices (slug => "All <plural>").
	 *
	 * @return array<string,string>
	 */
	private static function post_type_choices() {
		$out = array();
		foreach ( self::public_post_types() as $slug => $obj ) {
			$out[ $slug ] = sprintf( __( 'All %s', 'fw' ), $obj->labels->name );
		}
		return $out;
	}

	/**
	 * Post-type archive checkbox choices (slug => "<plural> archive"), only for
	 * post types that actually have an archive.
	 *
	 * @return array<string,string>
	 */
	private static function archive_choices() {
		$out = array();
		foreach ( self::public_post_types() as $slug => $obj ) {
			if ( ! empty( $obj->has_archive ) ) {
				$out[ $slug ] = sprintf( __( '%s archive', 'fw' ), $obj->labels->name );
			}
		}
		return $out;
	}

	/**
	 * @return array<string,WP_Post_Type>
	 */
	private static function public_post_types() {
		$types = get_post_types( array( 'public' => true ), 'objects' );
		// Never offer the framework's own private parts (defensive; they aren't public).
		unset( $types['attachment'], $types['up_header'], $types['up_footer'], $types['up_body'], $types['up_template'] );
		return $types;
	}

	/**
	 * The source list for the "specific pages/posts" multi-select.
	 *
	 * @return string[]
	 */
	private static function singular_sources() {
		return array_keys( self::public_post_types() );
	}

	/**
	 * The source list for the "children of pages" multi-select — only hierarchical
	 * post types (page + hierarchical CPTs), since only those have descendants.
	 *
	 * @return string[]
	 */
	private static function hierarchical_sources() {
		$out = array();
		foreach ( self::public_post_types() as $slug => $obj ) {
			if ( ! empty( $obj->hierarchical ) ) {
				$out[] = $slug;
			}
		}
		return $out ? $out : array( 'page' );
	}

	/* ------------------------------------------------------------------ */
	/* (1) Option schema for one side                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * Build the option array for one side ("use_on" | "exclude_from"). Every
	 * option id is prefixed by the side so the two sets never collide.
	 *
	 * @param string $side
	 * @return array
	 */
	public static function side_options( $side ) {
		$p = $side . '_';

		$opts = array(
			$p . 'scope' => array(
				'type'    => 'checkboxes',
				'label'   => __( 'Scopes', 'fw' ),
				'desc'    => __( 'Whole-site and special views.', 'fw' ),
				'value'   => array(),
				'choices' => self::scope_choices(),
			),
			$p . 'post_types' => array(
				'type'    => 'checkboxes',
				'label'   => __( 'All of a post type', 'fw' ),
				'value'   => array(),
				'choices' => self::post_type_choices(),
			),
			$p . 'archives' => array(
				'type'    => 'checkboxes',
				'label'   => __( 'Post-type archives', 'fw' ),
				'value'   => array(),
				'choices' => self::archive_choices(),
			),
			$p . 'singulars' => array(
				'type'        => 'multi-select',
				'label'       => __( 'Specific pages / posts', 'fw' ),
				'population'  => 'posts',
				'source'      => self::singular_sources(),
				'prepopulate' => 10,
				'show-type'   => true,
				'value'       => array(),
			),
			$p . 'children_of' => array(
				'type'        => 'multi-select',
				'label'       => __( 'Children of pages', 'fw' ),
				'desc'        => __( 'All descendant pages of the chosen pages (a closer parent wins when several apply).', 'fw' ),
				'population'  => 'posts',
				'source'      => self::hierarchical_sources(),
				'prepopulate' => 10,
				'show-type'   => true,
				'value'       => array(),
			),
			$p . 'in_categories' => array(
				'type'        => 'multi-select',
				'label'       => __( 'Posts in categories', 'fw' ),
				'desc'        => __( 'Single posts that belong to the chosen categories.', 'fw' ),
				'population'  => 'taxonomy',
				'source'      => 'category',
				'prepopulate' => 10,
				'value'       => array(),
			),
			$p . 'category_archives' => array(
				'type'        => 'multi-select',
				'label'       => __( 'Category archives', 'fw' ),
				'desc'        => __( 'The category archive pages themselves.', 'fw' ),
				'population'  => 'taxonomy',
				'source'      => 'category',
				'prepopulate' => 10,
				'value'       => array(),
			),
		);

		// WooCommerce product categories (the product taxonomy), only when Woo is active.
		if ( self::woo_active() ) {
			$opts[ $p . 'in_product_cat' ] = array(
				'type'        => 'multi-select',
				'label'       => __( 'Products in product categories', 'fw' ),
				'desc'        => __( 'Single products that belong to the chosen product categories.', 'fw' ),
				'population'  => 'taxonomy',
				'source'      => 'product_cat',
				'prepopulate' => 10,
				'value'       => array(),
			);
			$opts[ $p . 'product_cat_archives' ] = array(
				'type'        => 'multi-select',
				'label'       => __( 'Product category archives', 'fw' ),
				'desc'        => __( 'The product category archive pages themselves.', 'fw' ),
				'population'  => 'taxonomy',
				'source'      => 'product_cat',
				'prepopulate' => 10,
				'value'       => array(),
			);
		}

		return $opts;
	}

	/**
	 * Clean a submitted sub_type WITHOUT destroying it.
	 *
	 * sanitize_key() would be wrong here: page-template qualifiers are filenames
	 * ('page-landing.php'), and stripping the dot silently turns a valid rule into
	 * one that never matches. Safety comes from the allowlist check the callers do
	 * against sub_choices() — validating against a known set is a stronger gate
	 * than character-stripping, not a weaker one.
	 *
	 * @param mixed $value
	 * @return string
	 */
	public static function sanitize_sub_type( $value ) {
		return trim( sanitize_text_field( (string) $value ) );
	}

	/* ------------------------------------------------------------------ */
	/* (2)+(3) values  <->  rules, per side                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Submitted option values (one side) -> resolver rule list.
	 *
	 * @param string $side
	 * @param array  $values full values array (all sides); we read this side's ids
	 * @return array rule list
	 */
	public static function side_values_to_rules( $side, $values ) {
		$p     = $side . '_';
		$rules = array();

		foreach ( (array) fw_akg( $p . 'scope', $values, array() ) as $token ) {
			if ( $token === 'df' ) {
				$rules[] = array( 'type' => 'df', 'sub_type' => '', 'ids' => array() );
			} elseif ( strpos( $token, 'ct:' ) === 0 ) {
				$rules[] = array( 'type' => 'ct', 'sub_type' => substr( $token, 3 ), 'ids' => array() );
			}
		}
		foreach ( (array) fw_akg( $p . 'post_types', $values, array() ) as $slug ) {
			$rules[] = array( 'type' => 'pt', 'sub_type' => (string) $slug, 'ids' => array() );
		}
		foreach ( (array) fw_akg( $p . 'archives', $values, array() ) as $slug ) {
			$rules[] = array( 'type' => 'ar', 'sub_type' => (string) $slug, 'ids' => array() );
		}
		$singulars = array_map( 'intval', (array) fw_akg( $p . 'singulars', $values, array() ) );
		if ( $singulars ) {
			$rules[] = array( 'type' => 'pt', 'sub_type' => '', 'ids' => $singulars );
		}
		$children_of = array_map( 'intval', (array) fw_akg( $p . 'children_of', $values, array() ) );
		if ( $children_of ) {
			$rules[] = array( 'type' => 'ptc', 'sub_type' => '', 'ids' => $children_of );
		}
		$in_cats = array_map( 'intval', (array) fw_akg( $p . 'in_categories', $values, array() ) );
		if ( $in_cats ) {
			$rules[] = array( 'type' => 'tx', 'sub_type' => 'category', 'ids' => $in_cats );
		}
		$cat_archives = array_map( 'intval', (array) fw_akg( $p . 'category_archives', $values, array() ) );
		if ( $cat_archives ) {
			$rules[] = array( 'type' => 'tax', 'sub_type' => 'category', 'ids' => $cat_archives );
		}
		$in_pcat = array_map( 'intval', (array) fw_akg( $p . 'in_product_cat', $values, array() ) );
		if ( $in_pcat ) {
			$rules[] = array( 'type' => 'tx', 'sub_type' => 'product_cat', 'ids' => $in_pcat );
		}
		$pcat_archives = array_map( 'intval', (array) fw_akg( $p . 'product_cat_archives', $values, array() ) );
		if ( $pcat_archives ) {
			$rules[] = array( 'type' => 'tax', 'sub_type' => 'product_cat', 'ids' => $pcat_archives );
		}

		return $rules;
	}

	/**
	 * Resolver rule list -> option values (one side), for prefilling the form.
	 *
	 * @param string $side
	 * @param array  $rules
	 * @return array option values keyed by the side-prefixed ids
	 */
	public static function side_rules_to_values( $side, $rules ) {
		$p = $side . '_';
		$v = array(
			$p . 'scope'             => array(),
			$p . 'post_types'        => array(),
			$p . 'archives'          => array(),
			$p . 'singulars'         => array(),
			$p . 'children_of'       => array(),
			$p . 'in_categories'     => array(),
			$p . 'category_archives' => array(),
			$p . 'in_product_cat'    => array(),
			$p . 'product_cat_archives' => array(),
		);

		foreach ( (array) $rules as $rule ) {
			$type = isset( $rule['type'] ) ? $rule['type'] : '';
			$sub  = isset( $rule['sub_type'] ) ? (string) $rule['sub_type'] : '';
			$ids  = isset( $rule['ids'] ) && is_array( $rule['ids'] ) ? array_map( 'intval', $rule['ids'] ) : array();

			switch ( $type ) {
				case 'df':
					$v[ $p . 'scope' ][] = 'df';
					break;
				case 'ct':
					$v[ $p . 'scope' ][] = 'ct:' . $sub;
					break;
				case 'pt':
					if ( $ids ) {
						$v[ $p . 'singulars' ] = array_merge( $v[ $p . 'singulars' ], $ids );
					} elseif ( $sub !== '' ) {
						$v[ $p . 'post_types' ][] = $sub;
					}
					break;
				case 'ptc':
					$v[ $p . 'children_of' ] = array_merge( $v[ $p . 'children_of' ], $ids );
					break;
				case 'ar':
					$v[ $p . 'archives' ][] = $sub;
					break;
				case 'tx':
					$tx_field = ( $sub === 'product_cat' ) ? 'in_product_cat' : 'in_categories';
					$v[ $p . $tx_field ] = array_merge( $v[ $p . $tx_field ], $ids );
					break;
				case 'tax':
					$tax_field = ( $sub === 'product_cat' ) ? 'product_cat_archives' : 'category_archives';
					$v[ $p . $tax_field ] = array_merge( $v[ $p . $tax_field ], $ids );
					break;
			}
		}

		// De-dupe the id lists so a hand-crafted/imported tb_conditions with repeated
		// ids doesn't prefill the multi-selects with duplicates.
		foreach ( $v as $k => $val ) {
			if ( is_array( $val ) ) {
				$v[ $k ] = array_values( array_unique( $val ) );
			}
		}

		return $v;
	}

	/* ------------------------------------------------------------------ */
	/* full conditions <-> values                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Submitted form values -> tb_conditions { use_on, exclude_from }.
	 *
	 * @param array $values
	 * @return array
	 */
	public static function values_to_conditions( $values ) {
		return array(
			'use_on'       => self::side_values_to_rules( 'use_on', $values ),
			'exclude_from' => self::side_values_to_rules( 'exclude_from', $values ),
		);
	}

	/**
	 * tb_conditions -> form values (both sides merged).
	 *
	 * @param array $conditions
	 * @return array
	 */
	public static function conditions_to_values( $conditions ) {
		$use_on  = ( isset( $conditions['use_on'] ) && is_array( $conditions['use_on'] ) ) ? $conditions['use_on'] : array();
		$exclude = ( isset( $conditions['exclude_from'] ) && is_array( $conditions['exclude_from'] ) ) ? $conditions['exclude_from'] : array();

		return array_merge(
			self::side_rules_to_values( 'use_on', $use_on ),
			self::side_rules_to_values( 'exclude_from', $exclude )
		);
	}

	/* ------------------------------------------------------------------ */
	/* (4) "Used On" summary                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Short human description of where a Template applies, for the table.
	 *
	 * @param array $conditions
	 * @return string
	 */
	public static function summarize( $conditions ) {
		$use_on  = ( isset( $conditions['use_on'] ) && is_array( $conditions['use_on'] ) ) ? $conditions['use_on'] : array();
		$exclude = ( isset( $conditions['exclude_from'] ) && is_array( $conditions['exclude_from'] ) ) ? $conditions['exclude_from'] : array();

		if ( empty( $use_on ) ) {
			return '<span class="fw-tb-muted">' . esc_html__( 'Not assigned', 'fw' ) . '</span>';
		}

		$parts        = array();
		$scope_labels = self::scope_choices();
		$pt_objs      = get_post_types( array(), 'objects' );

		foreach ( $use_on as $rule ) {
			$type = isset( $rule['type'] ) ? $rule['type'] : '';
			$sub  = isset( $rule['sub_type'] ) ? (string) $rule['sub_type'] : '';
			$ids  = isset( $rule['ids'] ) && is_array( $rule['ids'] ) ? $rule['ids'] : array();

			switch ( $type ) {
				case 'df':
					$parts[] = $scope_labels['df'];
					break;
				case 'ct':
					$key     = 'ct:' . $sub;
					$parts[] = isset( $scope_labels[ $key ] ) ? $scope_labels[ $key ] : $sub;
					break;
				case 'pt':
					if ( $ids ) {
						$parts[] = sprintf( _n( '%d item', '%d items', count( $ids ), 'fw' ), count( $ids ) );
					} else {
						$lbl     = isset( $pt_objs[ $sub ] ) ? $pt_objs[ $sub ]->labels->name : $sub;
						$parts[] = sprintf( __( 'All %s', 'fw' ), $lbl );
					}
					break;
				case 'ptc':
					$parts[] = sprintf( _n( 'Children of %d page', 'Children of %d pages', count( $ids ), 'fw' ), count( $ids ) );
					break;
				case 'ar':
					$lbl     = isset( $pt_objs[ $sub ] ) ? $pt_objs[ $sub ]->labels->name : $sub;
					$parts[] = sprintf( __( '%s archive', 'fw' ), $lbl );
					break;
				case 'au':
					$parts[] = sprintf( _n( 'By %d author', 'By %d authors', count( $ids ), 'fw' ), count( $ids ) );
					break;
				case 'aar':
					$parts[] = $ids
						? sprintf( _n( '%d author archive', '%d author archives', count( $ids ), 'fw' ), count( $ids ) )
						: __( 'Author archives', 'fw' );
					break;
				case 'tpl':
					$tpl_names = self::sub_choices( 'tpl' );
					$parts[]   = sprintf( __( 'Template: %s', 'fw' ), isset( $tpl_names[ $sub ] ) ? $tpl_names[ $sub ] : $sub );
					break;
				case 'tx':
					$parts[] = ( 'product_cat' === $sub )
						? sprintf( _n( '%d product category', '%d product categories', count( $ids ), 'fw' ), count( $ids ) )
						: sprintf( _n( '%d category', '%d categories', count( $ids ), 'fw' ), count( $ids ) );
					break;
				case 'tax':
					$parts[] = ( 'product_cat' === $sub )
						? sprintf( _n( '%d product category archive', '%d product category archives', count( $ids ), 'fw' ), count( $ids ) )
						: sprintf( _n( '%d category archive', '%d category archives', count( $ids ), 'fw' ), count( $ids ) );
					break;
			}
		}

		$summary = esc_html( implode( ', ', array_filter( $parts ) ) );
		if ( ! empty( $exclude ) ) {
			$summary .= ' <span class="fw-tb-muted">' . esc_html__( '(with exclusions)', 'fw' ) . '</span>';
		}
		return $summary;
	}


	/* ------------------------------------------------------------------ */
	/* (6) Rule-row editor — vocabulary, choices, round-trip              */
	/* ------------------------------------------------------------------ */

	/**
	 * The rule vocabulary the row editor offers, in menu order.
	 *
	 * Each entry says what the row needs after the type is chosen:
	 *   sub  — bool, does it take a qualifier (post type / taxonomy / which page)?
	 *   ids  — false | 'optional' | 'required', does it take a value list?
	 *
	 * The keys ARE the resolver's rule types, so a row maps to a rule with no
	 * translation layer. Adding a type here means teaching the resolver too.
	 *
	 * @return array<string,array>
	 */
	public static function rule_types() {
		return array(
			'df' => array(
				'label' => __( 'Entire site', 'fw' ),
				'sub'   => false,
				'ids'   => false,
				'hint'  => __( 'Every request. The broadest rule — anything more specific beats it.', 'fw' ),
			),
			'pt' => array(
				'label'     => __( 'Singular', 'fw' ),
				'sub'       => true,
				'ids'       => 'optional',
				'sub_label' => __( 'Post type', 'fw' ),
				'ids_label' => __( 'Specific items', 'fw' ),
				'hint'      => __( 'A single page/post. Pick items to narrow it, or leave empty for all of that post type.', 'fw' ),
			),
			'ptc' => array(
				'label'     => __( 'Children of', 'fw' ),
				'sub'       => true,
				'ids'       => 'required',
				'sub_label' => __( 'Post type', 'fw' ),
				'ids_label' => __( 'Parent pages', 'fw' ),
				'hint'      => __( 'Every descendant of the chosen pages. A closer parent wins when several apply.', 'fw' ),
			),
			'tx' => array(
				'label'     => __( 'Singular with term', 'fw' ),
				'sub'       => true,
				'ids'       => 'required',
				'sub_label' => __( 'Taxonomy', 'fw' ),
				'ids_label' => __( 'Terms', 'fw' ),
				'hint'      => __( 'A single post that belongs to one of the chosen terms.', 'fw' ),
			),
			'tax' => array(
				'label'     => __( 'Term archive', 'fw' ),
				'sub'       => true,
				'ids'       => 'optional',
				'sub_label' => __( 'Taxonomy', 'fw' ),
				'ids_label' => __( 'Terms', 'fw' ),
				'hint'      => __( 'The term archive page itself. Leave the terms empty for every archive of that taxonomy.', 'fw' ),
			),
			'au' => array(
				'label'     => __( 'Post by author', 'fw' ),
				'sub'       => false,
				'ids'       => 'required',
				'ids_label' => __( 'Authors', 'fw' ),
				'hint'      => __( 'A single page/post written by one of the chosen authors.', 'fw' ),
			),
			'tpl' => array(
				'label'     => __( 'Page template', 'fw' ),
				'sub'       => true,
				'ids'       => false,
				'sub_label' => __( 'Template', 'fw' ),
				'hint'      => __( 'Any single page/post assigned this page template in its editor.', 'fw' ),
			),
			'aar' => array(
				'label'     => __( 'Author archive', 'fw' ),
				'sub'       => false,
				'ids'       => 'optional',
				'ids_label' => __( 'Authors', 'fw' ),
				'hint'      => __( 'An author\'s archive page. Leave the authors empty for every author archive.', 'fw' ),
			),
			'ar' => array(
				'label'     => __( 'Post type archive', 'fw' ),
				'sub'       => true,
				'ids'       => false,
				'sub_label' => __( 'Post type', 'fw' ),
				'hint'      => __( 'The archive page for a post type (only post types that have one are listed).', 'fw' ),
			),
			'ct' => array(
				'label'     => __( 'Special page', 'fw' ),
				'sub'       => true,
				'ids'       => false,
				'sub_label' => __( 'Which page', 'fw' ),
				'hint'      => __( 'Front page, blog index, search results, 404 — and the WooCommerce pages when it is active.', 'fw' ),
			),
		);
	}

	/**
	 * The qualifier choices for one rule type (slug => label), i.e. what goes in the
	 * row's second dropdown.
	 *
	 * @param string $type
	 * @return array<string,string>
	 */
	public static function sub_choices( $type ) {
		switch ( $type ) {
			case 'ct':
				$out = array();
				foreach ( self::scope_choices() as $token => $label ) {
					if ( strpos( $token, 'ct:' ) === 0 ) {
						$out[ substr( $token, 3 ) ] = $label;
					}
				}
				return $out;

			case 'pt':
				$out = array();
				foreach ( self::public_post_types() as $slug => $obj ) {
					$out[ $slug ] = $obj->labels->name;
				}
				return $out;

			case 'ar':
				return self::archive_choices();

			case 'ptc':
				$out = array();
				foreach ( self::public_post_types() as $slug => $obj ) {
					if ( ! empty( $obj->hierarchical ) ) {
						$out[ $slug ] = $obj->labels->name;
					}
				}
				return $out ? $out : array( 'page' => __( 'Pages', 'fw' ) );

			case 'tpl':
				// Every page template the active theme declares, across every post
				// type that can use one. Keys are template FILENAMES, which is why
				// sub_type is validated against this list rather than character-
				// stripped — see sanitize_sub_type().
				$out   = array( 'default' => __( 'Default template', 'fw' ) );
				$theme = wp_get_theme();
				foreach ( self::public_post_types() as $pt_slug => $pt_obj ) {
					foreach ( (array) $theme->get_page_templates( null, $pt_slug ) as $file => $name ) {
						$out[ $file ] = $name;
					}
				}
				return $out;

			case 'tx':
			case 'tax':
				// EVERY public taxonomy, not just category/product_cat — the resolver's
				// tx/tax matching is taxonomy-agnostic, so a site with a custom taxonomy
				// can target it here without any further work.
				$out = array();
				foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $slug => $obj ) {
					$out[ $slug ] = $obj->labels->name;
				}
				return $out;
		}

		return array();
	}

	/**
	 * The value choices (ids) for a rule row — posts for pt/ptc, terms for tx/tax.
	 * Capped, with an optional search, because a big site has thousands of either.
	 *
	 * @param string $type
	 * @param string $sub_type
	 * @param string $search
	 * @param int    $limit
	 * @return array<int,array{id:int,label:string}>
	 */
	public static function id_choices( $type, $sub_type, $search = '', $limit = 100 ) {
		$out = array();

		if ( in_array( $type, array( 'pt', 'ptc' ), true ) ) {
			$post_types = self::public_post_types();
			if ( ! isset( $post_types[ $sub_type ] ) ) {
				return $out;
			}
			$posts = get_posts( array(
				'post_type'        => $sub_type,
				'post_status'      => 'publish',
				'numberposts'      => (int) $limit,
				'orderby'          => 'title',
				'order'            => 'ASC',
				's'                => $search,
				'suppress_filters' => false,
			) );
			foreach ( $posts as $post ) {
				$out[] = array(
					'id'    => (int) $post->ID,
					'label' => ( $post->post_title !== '' ) ? $post->post_title : sprintf( __( '(no title) #%d', 'fw' ), $post->ID ),
				);
			}
			return $out;
		}

		if ( in_array( $type, array( 'au', 'aar' ), true ) ) {
			$users = get_users( array(
				'number'  => (int) $limit,
				'orderby' => 'display_name',
				'order'   => 'ASC',
				'search'  => $search ? '*' . $search . '*' : '',
				'fields'  => array( 'ID', 'display_name' ),
			) );
			foreach ( $users as $user ) {
				$out[] = array( 'id' => (int) $user->ID, 'label' => $user->display_name );
			}
			return $out;
		}

		if ( in_array( $type, array( 'tx', 'tax' ), true ) ) {
			if ( ! taxonomy_exists( $sub_type ) ) {
				return $out;
			}
			$terms = get_terms( array(
				'taxonomy'   => $sub_type,
				'hide_empty' => false,
				'number'     => (int) $limit,
				'orderby'    => 'name',
				'order'      => 'ASC',
				'search'     => $search,
			) );
			if ( is_wp_error( $terms ) ) {
				return $out;
			}
			foreach ( $terms as $term ) {
				$out[] = array( 'id' => (int) $term->term_id, 'label' => $term->name );
			}
		}

		return $out;
	}

	/**
	 * Labels for a rule's already-selected ids, so the editor can show what is
	 * chosen without waiting on (or being limited by) the capped choice query.
	 *
	 * @param string $type
	 * @param string $sub_type
	 * @param int[]  $ids
	 * @return array<int,array{id:int,label:string}>
	 */
	public static function id_labels( $type, $sub_type, $ids ) {
		$out = array();
		foreach ( (array) $ids as $id ) {
			$id = (int) $id;
			if ( $id <= 0 ) {
				continue;
			}
			if ( in_array( $type, array( 'pt', 'ptc' ), true ) ) {
				$title = get_the_title( $id );
				$out[] = array( 'id' => $id, 'label' => ( $title !== '' ) ? $title : sprintf( __( '(deleted) #%d', 'fw' ), $id ) );
			} elseif ( in_array( $type, array( 'au', 'aar' ), true ) ) {
				$user  = get_userdata( $id );
				$out[] = array(
					'id'    => $id,
					'label' => $user ? $user->display_name : sprintf( __( '(deleted) #%d', 'fw' ), $id ),
				);
			} elseif ( in_array( $type, array( 'tx', 'tax' ), true ) ) {
				$term  = get_term( $id, $sub_type );
				$out[] = array(
					'id'    => $id,
					'label' => ( $term && ! is_wp_error( $term ) ) ? $term->name : sprintf( __( '(deleted) #%d', 'fw' ), $id ),
				);
			}
		}
		return $out;
	}

	/**
	 * How the Use On rules combine: 'or' (any rule matches — the default, and what
	 * every pre-existing Template gets) or 'and' (every rule must match).
	 *
	 * Exceptions are always OR: an exclusion should fire as soon as any of them
	 * matches, which is the safe reading and the one Divi uses.
	 *
	 * @param array $conditions
	 * @return string or|and
	 */
	public static function relation_of( $conditions ) {
		$rel = ( is_array( $conditions ) && isset( $conditions['relation'] ) ) ? strtolower( (string) $conditions['relation'] ) : 'or';
		return ( 'and' === $rel ) ? 'and' : 'or';
	}

	/* ---- rows  <->  tb_conditions ------------------------------------- */

	/**
	 * tb_conditions -> a flat row list for the editor. One row per rule, tagged with
	 * the side it came from, plus the labels for any ids it carries.
	 *
	 * @param array $conditions
	 * @return array
	 */
	public static function conditions_to_rows( $conditions ) {
		$rows = array();

		foreach ( array( 'use_on', 'exclude_from' ) as $side ) {
			$rules = ( isset( $conditions[ $side ] ) && is_array( $conditions[ $side ] ) ) ? $conditions[ $side ] : array();
			foreach ( $rules as $rule ) {
				if ( ! is_array( $rule ) || empty( $rule['type'] ) ) {
					continue;
				}
				$type = (string) $rule['type'];
				$sub  = isset( $rule['sub_type'] ) ? (string) $rule['sub_type'] : '';
				$ids  = ( isset( $rule['ids'] ) && is_array( $rule['ids'] ) ) ? array_map( 'intval', $rule['ids'] ) : array();

				// A legacy "specific items" rule was stored with an empty sub_type
				// (type 'pt', ids only). The row editor needs a post type to populate
				// its picker, so infer it from the first surviving id.
				if ( 'pt' === $type && '' === $sub && $ids ) {
					$guess = get_post_type( $ids[0] );
					$sub   = $guess ? $guess : 'page';
				}

				$rows[] = array(
					'side'     => $side,
					'type'     => $type,
					'sub_type' => $sub,
					'ids'      => $ids,
					'labels'   => self::id_labels( $type, $sub, $ids ),
				);
			}
		}

		return $rows;
	}

	/**
	 * Editor rows -> tb_conditions. Every field is re-derived from the vocabulary,
	 * so a hand-crafted POST cannot introduce a rule type the resolver does not
	 * know, a qualifier outside the offered choices, or a non-numeric id.
	 *
	 * @param array  $rows
	 * @param string $relation or|and
	 * @return array{use_on:array,exclude_from:array,relation:string}
	 */
	public static function rows_to_conditions( $rows, $relation = 'or' ) {
		$types = self::rule_types();
		$out   = array( 'use_on' => array(), 'exclude_from' => array() );

		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['type'] ) ) {
				continue;
			}

			$type = sanitize_key( $row['type'] );
			if ( ! isset( $types[ $type ] ) ) {
				continue; // not a rule the resolver understands
			}
			$spec = $types[ $type ];

			$side = ( isset( $row['side'] ) && 'exclude_from' === $row['side'] ) ? 'exclude_from' : 'use_on';

			$sub = '';
			if ( ! empty( $spec['sub'] ) ) {
				$sub     = isset( $row['sub_type'] ) ? self::sanitize_sub_type( $row['sub_type'] ) : '';
				$allowed = self::sub_choices( $type );
				if ( ! isset( $allowed[ $sub ] ) ) {
					continue; // no qualifier, or one that is not on offer — drop the row
				}
			}

			$ids = array();
			if ( ! empty( $spec['ids'] ) ) {
				$ids = ( isset( $row['ids'] ) && is_array( $row['ids'] ) ) ? array_values( array_unique( array_filter( array_map( 'intval', $row['ids'] ) ) ) ) : array();
				if ( 'required' === $spec['ids'] && ! $ids ) {
					continue; // a rule that needs values but has none would match nothing
				}
			}

			$out[ $side ][] = array( 'type' => $type, 'sub_type' => $sub, 'ids' => $ids );
		}

		$out['relation'] = ( 'and' === strtolower( (string) $relation ) ) ? 'and' : 'or';

		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* (5) Static specificity weight (no live request)                    */
	/* ------------------------------------------------------------------ */

	/**
	 * Score a rule list by SHAPE alone, mirroring FW_Theme_Builder_Resolver's
	 * specificity constants.
	 *
	 * The resolver's match_rule() can only score against a live front-end request
	 * (it calls is_singular(), is_tax(), …), so it is unusable in wp-admin. The card
	 * canvas still needs to order Templates by how specific they are, so this scores
	 * the same weights from the rule's type + whether it carries ids — no WordPress
	 * conditional tag is consulted, and nothing here depends on the current request.
	 *
	 * Keep the numbers in step with the W_* constants on the resolver.
	 *
	 * @param array $rules a rule list (normally the `use_on` side)
	 * @return int highest weight in the list, or -1 when the list is empty.
	 */
	public static function static_weight( $rules ) {
		$best = -1;

		foreach ( (array) $rules as $rule ) {
			if ( ! is_array( $rule ) || empty( $rule['type'] ) ) {
				continue;
			}
			$type    = (string) $rule['type'];
			$has_ids = ! empty( $rule['ids'] ) && is_array( $rule['ids'] );
			$w       = -1;

			switch ( $type ) {
				case 'pt':
					$w = $has_ids ? 100 : 50; // specific posts vs. all of a post type
					break;
				case 'tx':
					$w = 80;
					break;
				case 'tax':
					$w = $has_ids ? 80 : 60; // specific term archives vs. all of a taxonomy
					break;
				case 'au':
					$w = 80;
					break;
				case 'ptc':
					$w = 75;
					break;
				case 'tpl':
					$w = 70;
					break;
				case 'aar':
					$w = $has_ids ? 80 : 60;
					break;
				case 'ar':
					$w = 60;
					break;
				case 'ct':
					$w = 40;
					break;
				case 'df':
					$w = 10;
					break;
			}

			if ( $w > $best ) {
				$best = $w;
			}
		}

		return $best;
	}

	/**
	 * True when the Template applies site-wide (carries a `df` rule) — the
	 * “Default Website Template” in Divi's terms. The card canvas pins these first.
	 *
	 * @param array $conditions the full tb_conditions blob
	 * @return bool
	 */
	public static function is_default( $conditions ) {
		$use_on = ( isset( $conditions['use_on'] ) && is_array( $conditions['use_on'] ) ) ? $conditions['use_on'] : array();
		foreach ( $use_on as $rule ) {
			if ( is_array( $rule ) && isset( $rule['type'] ) && 'df' === $rule['type'] ) {
				return true;
			}
		}
		return false;
	}

	/* ------------------------------------------------------------------ */
	/* (7) Template hierarchy — the Tree view's spine                     */
	/* ------------------------------------------------------------------ */

	/**
	 * The WordPress template hierarchy as a tree of nodes, built from what this
	 * site actually registers (its post types and its public taxonomies) rather
	 * than a hard-coded list.
	 *
	 * A node is:
	 *   key      string  'df' | '<type>:<sub_type>' — matches node_key_for_rule(),
	 *                    or a '__' prefix for a structural grouping node that no
	 *                    rule can ever target.
	 *   label    string
	 *   children node[]
	 *
	 * Depth mirrors specificity: the deeper a node, the narrower the request it
	 * describes. That is what makes the tree readable as a precedence map.
	 *
	 * @return array node[]
	 */
	public static function hierarchy() {
		$node = static function ( $key, $label, $children = array() ) {
			return array( 'key' => $key, 'label' => $label, 'children' => $children );
		};

		$post_types = self::public_post_types();
		$taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );

		/* ---- Special pages ---- */
		$special = array();
		foreach ( self::sub_choices( 'ct' ) as $slug => $label ) {
			$special[] = $node( 'ct:' . $slug, $label );
		}

		/* ---- Singular, one branch per post type ---- */
		$singular = array();
		foreach ( $post_types as $slug => $obj ) {
			$children = array();

			if ( ! empty( $obj->hierarchical ) ) {
				/* translators: %s = plural post type name, e.g. "Pages" */
				$children[] = $node( 'ptc:' . $slug, sprintf( __( 'Children of specific %s', 'fw' ), $obj->labels->name ) );
			}

			foreach ( get_object_taxonomies( $slug, 'objects' ) as $tax_slug => $tax ) {
				if ( empty( $tax->public ) ) {
					continue;
				}
				/* translators: 1: singular post type, 2: taxonomy name */
				$children[] = $node( 'tx:' . $tax_slug, sprintf( __( '%1$s in a %2$s term', 'fw' ), $obj->labels->singular_name, $tax->labels->singular_name ) );
			}

			$singular[] = $node( 'pt:' . $slug, $obj->labels->name, $children );
		}

		// Page templates are a cross-cutting way to target singular content, so they
		// sit beside the post types rather than inside any one of them.
		$templates = array();
		foreach ( self::sub_choices( 'tpl' ) as $file => $name ) {
			if ( 'default' === $file ) {
				continue; // "no template chosen" is not a useful coverage target
			}
			$templates[] = $node( 'tpl:' . $file, $name );
		}
		if ( $templates ) {
			$singular[] = $node( '__templates', __( 'By page template', 'fw' ), $templates );
		}

		$singular[] = $node( 'au', __( 'By author', 'fw' ) );

		/* ---- Archives ---- */
		$archives = array();
		foreach ( $post_types as $slug => $obj ) {
			if ( ! empty( $obj->has_archive ) ) {
				/* translators: %s = plural post type name */
				$archives[] = $node( 'ar:' . $slug, sprintf( __( '%s archive', 'fw' ), $obj->labels->name ) );
			}
		}
		foreach ( $taxonomies as $tax_slug => $tax ) {
			/* translators: %s = taxonomy name, e.g. "Categories" */
			$archives[] = $node( 'tax:' . $tax_slug, sprintf( __( '%s archives', 'fw' ), $tax->labels->name ) );
		}
		$archives[] = $node( 'aar', __( 'Author archives', 'fw' ) );

		return array(
			$node( 'df', __( 'Entire Site', 'fw' ), array(
				$node( '__special', __( 'Special pages', 'fw' ), $special ),
				$node( '__singular', __( 'Singular content', 'fw' ), $singular ),
				$node( '__archives', __( 'Archives', 'fw' ), $archives ),
			) ),
		);
	}

	/**
	 * Which hierarchy node a single rule targets.
	 *
	 * @param array $rule
	 * @return string|null node key, or null when the rule cannot be placed.
	 */
	public static function node_key_for_rule( $rule ) {
		if ( ! is_array( $rule ) || empty( $rule['type'] ) ) {
			return null;
		}
		$type = (string) $rule['type'];
		$sub  = isset( $rule['sub_type'] ) ? (string) $rule['sub_type'] : '';

		if ( 'df' === $type ) {
			return 'df';
		}
		// Rules that take no qualifier are their own node.
		if ( in_array( $type, array( 'au', 'aar' ), true ) ) {
			return $type;
		}
		if ( '' === $sub ) {
			// A legacy "specific items" rule (type pt, ids only, no post type).
			// Place it by what those ids actually are.
			if ( 'pt' === $type && ! empty( $rule['ids'] ) ) {
				$guess = get_post_type( (int) $rule['ids'][0] );
				return $guess ? 'pt:' . $guess : null;
			}
			return null;
		}

		return in_array( $type, array( 'ct', 'pt', 'ptc', 'tx', 'tax', 'ar' ), true )
			? $type . ':' . $sub
			: null;
	}

	/**
	 * A starting condition row for a hierarchy node — what the Tree view's
	 * "add one" link hands to a brand-new Template so the user lands on the edit
	 * screen with the right condition already filled in.
	 *
	 * Validated against the same vocabulary the editor offers, so a hand-typed
	 * ?prefill= cannot inject a rule shape the resolver does not know.
	 *
	 * @param string $key node key, e.g. 'pt:page'
	 * @return array|null a single row, or null when the key is not targetable.
	 */
	public static function row_from_node_key( $key ) {
		$key = (string) $key;
		if ( '' === $key || 0 === strpos( $key, '__' ) ) {
			return null; // structural grouping node — nothing can target it
		}

		$types = self::rule_types();

		if ( 'df' === $key ) {
			return array( 'side' => 'use_on', 'type' => 'df', 'sub_type' => '', 'ids' => array(), 'labels' => array() );
		}

		// A qualifier-less rule ('au', 'aar') is its own node key.
		if ( isset( $types[ $key ] ) && empty( $types[ $key ]['sub'] ) ) {
			return array( 'side' => 'use_on', 'type' => $key, 'sub_type' => '', 'ids' => array(), 'labels' => array() );
		}

		$bits = explode( ':', $key, 2 );
		if ( count( $bits ) !== 2 ) {
			return null;
		}
		list( $type, $sub ) = $bits;
		$type = sanitize_key( $type );
		$sub  = self::sanitize_sub_type( $sub );

		if ( ! isset( $types[ $type ] ) ) {
			return null;
		}
		$allowed = self::sub_choices( $type );
		if ( ! isset( $allowed[ $sub ] ) ) {
			return null;
		}

		return array( 'side' => 'use_on', 'type' => $type, 'sub_type' => $sub, 'ids' => array(), 'labels' => array() );
	}

	/**
	 * The node a whole Template hangs off: the one its MOST SPECIFIC Use On rule
	 * targets, since that is the rule that decides where it outranks others.
	 *
	 * @param array $conditions
	 * @return string|null
	 */
	public static function best_node_key( $conditions ) {
		$rules = ( isset( $conditions['use_on'] ) && is_array( $conditions['use_on'] ) ) ? $conditions['use_on'] : array();

		$best     = null;
		$best_wgt = -1;
		foreach ( $rules as $rule ) {
			$key = self::node_key_for_rule( $rule );
			if ( null === $key ) {
				continue;
			}
			$wgt = self::static_weight( array( $rule ) );
			if ( $wgt > $best_wgt ) {
				$best_wgt = $wgt;
				$best     = $key;
			}
		}
		return $best;
	}


	/**
	 * A front-end URL this Template would actually apply to, derived from its most
	 * specific Use On rule.
	 *
	 * The card thumbnails use it so a Template that targets single posts is
	 * previewed on a single post rather than on the home page — otherwise the
	 * picture shows a layout the Template does not even govern there.
	 *
	 * @param array $conditions
	 * @return string absolute URL, or '' when nothing representative exists.
	 */
	public static function representative_url( $conditions ) {
		$rules = ( isset( $conditions['use_on'] ) && is_array( $conditions['use_on'] ) ) ? $conditions['use_on'] : array();

		$best     = null;
		$best_wgt = -1;
		foreach ( $rules as $rule ) {
			$w = self::static_weight( array( $rule ) );
			if ( $w > $best_wgt ) {
				$best_wgt = $w;
				$best     = $rule;
			}
		}
		if ( ! $best ) {
			return '';
		}

		$type = isset( $best['type'] ) ? (string) $best['type'] : '';
		$sub  = isset( $best['sub_type'] ) ? (string) $best['sub_type'] : '';
		$ids  = ( isset( $best['ids'] ) && is_array( $best['ids'] ) ) ? array_map( 'intval', $best['ids'] ) : array();

		$first_of = static function ( $args ) {
			$found = get_posts( array_merge( array(
				'post_status'      => 'publish',
				'numberposts'      => 1,
				'fields'           => 'ids',
				'suppress_filters' => false,
			), $args ) );
			return $found ? (int) $found[0] : 0;
		};

		switch ( $type ) {
			case 'df':
				return home_url( '/' );

			case 'pt':
				$id = $ids ? $ids[0] : ( $sub ? $first_of( array( 'post_type' => $sub ) ) : 0 );
				$url = $id ? get_permalink( $id ) : '';
				return $url ? $url : '';

			case 'ptc':
				if ( ! $ids ) {
					return '';
				}
				$child = $first_of( array(
					'post_type'   => $sub ? $sub : 'page',
					'post_parent' => $ids[0],
				) );
				$url = get_permalink( $child ? $child : $ids[0] );
				return $url ? $url : '';

			case 'tx':
				if ( ! $sub || ! $ids ) {
					return '';
				}
				$id = $first_of( array(
					'post_type' => 'any',
					'tax_query' => array( array( 'taxonomy' => $sub, 'field' => 'term_id', 'terms' => $ids ) ),
				) );
				$url = $id ? get_permalink( $id ) : '';
				return $url ? $url : '';

			case 'tax':
				if ( ! $sub ) {
					return '';
				}
				$term = $ids ? $ids[0] : 0;
				if ( ! $term ) {
					$terms = get_terms( array( 'taxonomy' => $sub, 'number' => 1, 'hide_empty' => false, 'fields' => 'ids' ) );
					$term  = ( ! is_wp_error( $terms ) && $terms ) ? (int) $terms[0] : 0;
				}
				if ( ! $term ) {
					return '';
				}
				$link = get_term_link( $term, $sub );
				return is_wp_error( $link ) ? '' : $link;

			case 'ar':
				$link = $sub ? get_post_type_archive_link( $sub ) : '';
				return $link ? $link : '';

			case 'au':
				$id = $ids ? $first_of( array( 'post_type' => 'any', 'author' => $ids[0] ) ) : 0;
				$url = $id ? get_permalink( $id ) : '';
				return $url ? $url : '';

			case 'aar':
				$user = $ids ? $ids[0] : 0;
				if ( ! $user ) {
					$found = get_users( array( 'number' => 1, 'fields' => 'ID' ) );
					$user  = $found ? (int) $found[0] : 0;
				}
				return $user ? get_author_posts_url( $user ) : '';

			case 'tpl':
				if ( '' === $sub ) {
					return '';
				}
				$id = $first_of( array(
					'post_type'  => 'any',
					'meta_key'   => '_wp_page_template',
					'meta_value' => $sub,
				) );
				$url = $id ? get_permalink( $id ) : '';
				return $url ? $url : '';

			case 'ct':
				switch ( $sub ) {
					case 'blog_index':
						$posts_page = (int) get_option( 'page_for_posts' );
						return $posts_page ? get_permalink( $posts_page ) : home_url( '/' );
					case 'search':
						return add_query_arg( 's', 'a', home_url( '/' ) );
					case 'error_404':
						// A slug nothing will ever match, so the request really 404s.
						return home_url( '/fw-tb-404-preview/' );
					case 'woo_shop':
						return function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : '';
					case 'woo_cart':
						return function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'cart' ) : '';
					case 'woo_checkout':
						return function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'checkout' ) : '';
					case 'woo_account':
						return function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : '';
				}
				return home_url( '/' ); // front_page and anything unrecognised
		}

		return '';
	}

}
