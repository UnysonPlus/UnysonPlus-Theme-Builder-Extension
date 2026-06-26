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

	/** @var array<string,array{cb_id:string,token:string}>  scope token registry */
	private static $scope_defs = array(
		'df'             => array( 'label_key' => 'entire_site' ),
		'ct:front_page'  => array( 'label_key' => 'front_page' ),
		'ct:blog_index'  => array( 'label_key' => 'blog_index' ),
		'ct:search'      => array( 'label_key' => 'search' ),
		'ct:error_404'   => array( 'label_key' => 'error_404' ),
	);

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
				case 'tx':
					$parts[] = sprintf( _n( '%d category', '%d categories', count( $ids ), 'fw' ), count( $ids ) );
					break;
				case 'tax':
					$parts[] = sprintf( _n( '%d category archive', '%d category archives', count( $ids ), 'fw' ), count( $ids ) );
					break;
			}
		}

		$summary = esc_html( implode( ', ', array_filter( $parts ) ) );
		if ( ! empty( $exclude ) ) {
			$summary .= ' <span class="fw-tb-muted">' . esc_html__( '(with exclusions)', 'fw' ) . '</span>';
		}
		return $summary;
	}
}
