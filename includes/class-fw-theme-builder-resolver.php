<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Theme Builder — conditional assignment resolver (the "Use On / Exclude From"
 * engine, generalized from the sidebars extension's matching cascade).
 *
 * Given the current front-end request, it walks every published `up_template`,
 * keeps the ones whose `use_on` rules match, drops the ones whose `exclude_from`
 * rules match, ranks the survivors by specificity (newest wins ties), and returns
 * the winner's part references:
 *
 *   [ 'template_id' => int, 'header_id' => int, 'body_id' => int, 'footer_id' => int ]
 *   ( each part id is 0 when the Template inherits / sets no part )
 *
 * or null when no Template applies. Request-cached.
 *
 * ── Meta contract (what the Theme Builder admin grid writes, read here) ─────────
 *   tb_header_id   int   0 = inherit (fall through to per-page → site-wide → slots)
 *   tb_body_id     int   0 = none    (no body override → normal theme loop)
 *   tb_footer_id   int   0 = inherit
 *   tb_conditions  array [ 'use_on' => rule[], 'exclude_from' => rule[] ]
 *
 * A rule is [ 'type' => …, 'sub_type' => …, 'ids' => int[] ]:
 *   type 'df'  default / entire site                         (sub_type, ids ignored)
 *   type 'ct'  conditional tag — sub_type ∈ { front_page, blog_index, search,
 *              error_404, archive, author, date }
 *   type 'pt'  singular content — sub_type = post type; ids = specific post ids,
 *              or empty = all singular of that post type
 *   type 'tx'  a singular post that HAS a term — sub_type = taxonomy; ids = term ids
 *              (Divi's "Posts in Specific Categories"); ids required
 *   type 'tax' a term archive page — sub_type = taxonomy; ids = term ids, or empty
 *              = all archives of that taxonomy
 *   type 'ar'  a post-type archive
 *   type 'au'  a singular post BY an author  — ids = user ids; ids required
 *   type 'aar' an author archive page        — ids = user ids, or empty = any
 *   type 'tpl' a singular post using a page template — sub_type = template file — sub_type = post type
 *
 * Matching uses ONLY native WordPress conditionals (no eval, no request-derived
 * includes); the resolver maps a matched Template → its registered part ids.
 *
 * NOT yet wired into the front end — the header/footer consult and the body
 * template_include land with the render-wiring phase. This class is the pure,
 * testable core they will call.
 */
class FW_Theme_Builder_Resolver {

	/* Specificity weights — higher = more specific = wins. */
	const W_PT_ID       = 100; // a specific singular post
	const W_TX_ID       = 80;  // a singular post in a specific term
	const W_AU          = 80;  // a singular post by a specific author
	const W_AAR_ID      = 80;  // a specific author's archive
	const W_TPL         = 70;  // a singular post using a specific page template
	const W_AAR_ALL     = 60;  // any author archive
	const W_TAX_ID      = 80;  // a specific term archive
	const W_PT_CHILDREN = 75;  // a descendant of a specific page (+ closeness bonus)
	const W_AR          = 60;  // a post-type archive
	const W_TAX_ALL     = 60;  // all archives of a taxonomy
	const W_PT_ALL      = 50;  // all singular of a post type
	const W_CT          = 40;  // a conditional tag (front page / search / 404 / …)
	const W_DF          = 10;  // default / entire site

	/** @var array|null|false  false = not computed yet; null/array = computed result */
	private static $cache = false;

	/**
	 * Resolve the winning Template's part references for the current request.
	 *
	 * @return array|null [ template_id, header_id, body_id, footer_id ] or null.
	 */
	public static function resolve() {
		if ( self::$cache !== false ) {
			return self::$cache;
		}

		// Resolution is a front-end concern. Bail in wp-admin and on request types
		// a Template must never hijack — oEmbed responses and feeds. REST is
		// intentionally left alone so the block editor / front-end fetches still see a
		// normal page.
		if ( is_admin() || is_embed() || is_feed() || ! post_type_exists( 'up_template' ) ) {
			return self::$cache = null;
		}

		$ids = get_posts( array(
			'post_type'        => 'up_template',
			'post_status'      => 'publish',
			'numberposts'      => -1,
			'fields'           => 'ids',
			'orderby'          => 'date',
			'order'            => 'DESC', // newest first → first match at a score is the tie-break winner
			'suppress_filters' => false,
		) );

		$best          = null;
		$best_score    = -1;
		$best_priority = 0;

		foreach ( $ids as $tid ) {
			// Switched off in the admin — invisible to the front end, but kept intact.
			if ( ! self::is_enabled( $tid ) ) {
				continue;
			}

			$cond = self::get_conditions( $tid );

			// Exclusions win: if any exclude_from rule matches, skip this Template.
			if ( self::any_match( isset( $cond['exclude_from'] ) ? $cond['exclude_from'] : array() ) ) {
				continue;
			}

			$score = self::use_on_score( $cond );
			if ( $score < 0 ) {
				continue; // nothing in use_on matched this request
			}

			$priority = self::priority_of( $tid );

			// Rank: specificity first, then the author's Priority, then newest (the
			// posts are date DESC, so "first seen at this rank" IS the newest). Priority
			// only settles ties — it never lets a broad Template outrank a specific one,
			// which would make the weight table meaningless.
			if ( $score > $best_score || ( $score === $best_score && $priority > $best_priority ) ) {
				$best_score    = $score;
				$best_priority = $priority;
				$best          = array(
					'template_id' => (int) $tid,
					'header_id'   => (int) self::get_part_id( $tid, 'tb_header_id' ),
					'body_id'     => (int) self::get_part_id( $tid, 'tb_body_id' ),
					'footer_id'   => (int) self::get_part_id( $tid, 'tb_footer_id' ),
				);
			}
		}

		/**
		 * Filter the resolved Template parts for this request. The admin-gated live
		 * preview (see hooks.php) uses this to force a Template/preset onto a real page
		 * before it's published/assigned. `$best` is the normally-resolved array (or
		 * null when nothing matched); return an array of the same shape to override.
		 *
		 * @param array|null $best [ template_id, header_id, body_id, footer_id ] or null.
		 */
		return self::$cache = apply_filters( 'fw_theme_builder_resolved', $best );
	}

	/**
	 * Is this Template switched on?
	 *
	 * Stored INVERTED, as `tb_disabled`, on purpose: the meta is absent on every
	 * Template written before this existed, and absent must mean "active". An
	 * `tb_enabled` flag would have made missing == falsy == off, silently blanking
	 * the chrome on every existing site the moment they updated.
	 *
	 * @param int $tid
	 * @return bool
	 */
	public static function is_enabled( $tid ) {
		$off = function_exists( 'fw_get_db_post_option' )
			? fw_get_db_post_option( $tid, 'tb_disabled' )
			: get_post_meta( $tid, 'tb_disabled', true );

		return empty( $off );
	}

	/**
	 * The author's tie-break Priority (0 when unset). Higher wins among Templates
	 * that matched at the SAME specificity.
	 *
	 * @param int $tid
	 * @return int
	 */
	public static function priority_of( $tid ) {
		$val = function_exists( 'fw_get_db_post_option' )
			? fw_get_db_post_option( $tid, 'tb_priority' )
			: get_post_meta( $tid, 'tb_priority', true );

		return (int) $val;
	}

	/** Convenience: winning header part id for this request (0 = inherit). */
	public static function header_id() {
		$r = self::resolve();
		return $r ? (int) $r['header_id'] : 0;
	}

	/** Convenience: winning footer part id for this request (0 = inherit). */
	public static function footer_id() {
		$r = self::resolve();
		return $r ? (int) $r['footer_id'] : 0;
	}

	/** Convenience: winning body template id for this request (0 = none). */
	public static function body_id() {
		$r = self::resolve();
		return $r ? (int) $r['body_id'] : 0;
	}

	/**
	 * Debug data for the front-end "what renders here?" tool: every published
	 * Template with its exclusion / use-on score for THIS request, ranked most
	 * specific first, plus the resolved winner. Front-end only; null when there are
	 * no Templates.
	 *
	 * @return array|null  [ resolved => array|null, candidates => [ {id,name,excluded,score} ] ]
	 */
	public static function debug() {
		static $cache;
		static $done = false;
		if ( $done ) {
			return $cache;
		}
		$done  = true;
		$cache = null;
		if ( is_admin() || ! post_type_exists( 'up_template' ) ) {
			return $cache;
		}
		$ids = get_posts( array(
			'post_type'        => 'up_template',
			'post_status'      => 'publish',
			'numberposts'      => -1,
			'fields'           => 'ids',
			'suppress_filters' => false,
		) );
		if ( ! $ids ) {
			return $cache;
		}
		$candidates = array();
		foreach ( $ids as $tid ) {
			$cond     = self::get_conditions( $tid );
			$enabled  = self::is_enabled( $tid );
			$excluded = self::any_match( isset( $cond['exclude_from'] ) ? $cond['exclude_from'] : array() );
			$score    = ( ! $enabled || $excluded ) ? -1 : self::use_on_score( $cond );
			$candidates[] = array(
				'id'       => (int) $tid,
				'name'     => get_the_title( $tid ),
				'enabled'  => $enabled,
				'excluded' => $excluded,
				'priority' => self::priority_of( $tid ),
				'score'    => (int) $score, // -1 = disabled, excluded, or no use_on rule matched
			);
		}
		usort( $candidates, function ( $a, $b ) {
			return $b['score'] - $a['score'];
		} );
		$cache = array(
			'resolved'   => self::resolve(),
			'candidates' => $candidates,
		);
		return $cache;
	}

	/** Reset the request cache (tests / preview). */
	public static function flush() {
		self::$cache = false;
	}

	/* ------------------------------------------------------------------ */
	/* Internals                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * @param int $tid
	 * @return array{use_on:array,exclude_from:array}
	 */
	private static function get_conditions( $tid ) {
		$cond = function_exists( 'fw_get_db_post_option' )
			? fw_get_db_post_option( $tid, 'tb_conditions' )
			: get_post_meta( $tid, 'tb_conditions', true );

		if ( ! is_array( $cond ) ) {
			$cond = array();
		}
		$cond['use_on']       = isset( $cond['use_on'] ) && is_array( $cond['use_on'] ) ? $cond['use_on'] : array();
		$cond['exclude_from'] = isset( $cond['exclude_from'] ) && is_array( $cond['exclude_from'] ) ? $cond['exclude_from'] : array();
		return $cond;
	}

	/**
	 * @param int    $tid
	 * @param string $key tb_header_id|tb_body_id|tb_footer_id
	 * @return int
	 */
	private static function get_part_id( $tid, $key ) {
		$val = function_exists( 'fw_get_db_post_option' )
			? fw_get_db_post_option( $tid, $key )
			: get_post_meta( $tid, $key, true );
		return (int) $val;
	}

	/**
	 * True if ANY rule in the list matches the current request.
	 *
	 * @param array $rules
	 * @return bool
	 */
	private static function any_match( $rules ) {
		foreach ( (array) $rules as $rule ) {
			if ( self::match_rule( $rule ) >= 0 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The highest specificity weight among rules that match, or -1 if none match.
	 *
	 * @param array $rules
	 * @return int
	 */
	private static function best_match_score( $rules ) {
		$best = -1;
		foreach ( (array) $rules as $rule ) {
			$w = self::match_rule( $rule );
			if ( $w > $best ) {
				$best = $w;
			}
		}
		return $best;
	}

	/**
	 * Score the `use_on` side, honouring the Template's relation:
	 *
	 *   'or'  (default, and what every Template written before relations existed
	 *         gets) — ANY rule matching is enough.
	 *   'and' — EVERY rule must match, so "posts in Category X" + "by Author Y"
	 *         narrows instead of widening.
	 *
	 * Either way the returned score is the HIGHEST weight among the rules, so
	 * specificity ranking against other Templates is unchanged.
	 *
	 * @param array $cond the tb_conditions blob
	 * @return int weight, or -1 when the side does not match this request.
	 */
	private static function use_on_score( $cond ) {
		$rules = ( isset( $cond['use_on'] ) && is_array( $cond['use_on'] ) ) ? $cond['use_on'] : array();

		// Read the relation inline rather than through FW_Theme_Builder_Conditions:
		// that class is loaded only in wp-admin, while this resolver runs on every
		// front-end request. Keep this engine free of cross-class dependencies.
		$relation = isset( $cond['relation'] ) ? strtolower( (string) $cond['relation'] ) : 'or';

		if ( 'and' !== $relation ) {
			return self::best_match_score( $rules );
		}

		// AND: one failing rule disqualifies the Template outright.
		$best = -1;
		foreach ( $rules as $rule ) {
			$w = self::match_rule( $rule );
			if ( $w < 0 ) {
				return -1;
			}
			if ( $w > $best ) {
				$best = $w;
			}
		}
		return $best; // -1 when the list is empty, same as OR
	}

	/**
	 * Match one rule against the current request.
	 *
	 * @param array $rule [ type, sub_type, ids ]
	 * @return int specificity weight when it matches, or -1 when it does not.
	 */
	private static function match_rule( $rule ) {
		if ( ! is_array( $rule ) || empty( $rule['type'] ) ) {
			return -1;
		}
		$type = (string) $rule['type'];
		$sub  = isset( $rule['sub_type'] ) ? (string) $rule['sub_type'] : '';
		$ids  = isset( $rule['ids'] ) && is_array( $rule['ids'] ) ? array_map( 'intval', $rule['ids'] ) : array();

		switch ( $type ) {

			case 'df':
				return self::W_DF;

			case 'ct':
				return self::match_conditional_tag( $sub ) ? self::W_CT : -1;

			case 'pt':
				if ( ! is_singular( $sub ? $sub : null ) ) {
					return -1;
				}
				if ( $ids ) {
					return in_array( (int) get_queried_object_id(), $ids, true ) ? self::W_PT_ID : -1;
				}
				return self::W_PT_ALL;

			case 'ptc': // a descendant of one of the given pages ("children of")
				if ( ! is_singular() || ! $ids ) {
					return -1;
				}
				$ancestors = get_post_ancestors( (int) get_queried_object_id() ); // [parent, …, root]
				if ( empty( $ancestors ) ) {
					return -1;
				}
				$best = -1;
				$depth = count( $ancestors );
				foreach ( $ids as $pid ) {
					$idx = array_search( (int) $pid, $ancestors, true );
					if ( $idx !== false ) {
						// Closer ancestor (smaller index) → bigger bonus, so a template
						// targeting the immediate parent beats one targeting a grandparent.
						// Bonus capped so it never overtakes a specific-post (W_PT_ID) match.
						$closeness = min( 20, $depth - (int) $idx );
						$score     = self::W_PT_CHILDREN + $closeness;
						if ( $score > $best ) {
							$best = $score;
						}
					}
				}
				return $best;

			case 'tx':
				if ( ! is_singular() || ! $sub || ! $ids ) {
					return -1;
				}
				return has_term( $ids, $sub, get_queried_object_id() ) ? self::W_TX_ID : -1;

			case 'tax':
				if ( ! self::is_term_archive( $sub, $ids ) ) {
					return -1;
				}
				return $ids ? self::W_TAX_ID : self::W_TAX_ALL;

			case 'ar':
				return ( $sub && is_post_type_archive( $sub ) ) ? self::W_AR : -1;

			case 'au': // a singular post written by one of the given authors
				if ( ! is_singular() || ! $ids ) {
					return -1;
				}
				$post = get_post( get_queried_object_id() );
				return ( $post && in_array( (int) $post->post_author, $ids, true ) ) ? self::W_AU : -1;

			case 'aar': // an author archive page
				if ( ! is_author() ) {
					return -1;
				}
				if ( $ids ) {
					return in_array( (int) get_queried_object_id(), $ids, true ) ? self::W_AAR_ID : -1;
				}
				return self::W_AAR_ALL;

			case 'tpl': // a singular post using a specific page template
				if ( ! is_singular() || '' === $sub ) {
					return -1;
				}
				$slug = (string) get_page_template_slug( get_queried_object_id() );
				if ( '' === $slug ) {
					$slug = 'default'; // WP stores the default template as an empty string
				}
				return ( $slug === $sub ) ? self::W_TPL : -1;
		}

		return -1;
	}

	/**
	 * @param string $sub front_page|blog_index|search|error_404|archive|author|date
	 * @return bool
	 */
	private static function match_conditional_tag( $sub ) {
		switch ( $sub ) {
			case 'front_page':
				return is_front_page();
			case 'blog_index': // the posts page when it is NOT the front page
				return is_home() && ! is_front_page();
			case 'search':
				return is_search();
			case 'error_404':
				return is_404();
			case 'archive':
				return is_archive();
			case 'author':
				return is_author();
			case 'date':
				return is_date();
			// WooCommerce pages — guarded so they are simply false when Woo is inactive.
			case 'woo_shop':
				return function_exists( 'is_shop' ) && is_shop();
			case 'woo_cart':
				return function_exists( 'is_cart' ) && is_cart();
			case 'woo_checkout':
				return function_exists( 'is_checkout' ) && is_checkout();
			case 'woo_account':
				return function_exists( 'is_account_page' ) && is_account_page();
		}
		return false;
	}

	/**
	 * Is the current request a term-archive for the given taxonomy (optionally
	 * limited to specific term ids)? Handles the built-in category / tag specially
	 * (is_tax() returns false for those).
	 *
	 * @param string $taxonomy
	 * @param int[]  $ids
	 * @return bool
	 */
	private static function is_term_archive( $taxonomy, $ids ) {
		if ( ! $taxonomy ) {
			return false;
		}
		if ( $taxonomy === 'category' ) {
			return $ids ? is_category( $ids ) : is_category();
		}
		if ( $taxonomy === 'post_tag' ) {
			return $ids ? is_tag( $ids ) : is_tag();
		}
		return $ids ? is_tax( $taxonomy, $ids ) : is_tax( $taxonomy );
	}
}
