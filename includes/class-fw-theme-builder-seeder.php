<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Theme Builder — template seeder (FSE-style file/DB hybrid).
 *
 * Reads up-templates/*.json from the active theme (child overrides parent) and
 * seeds each into the CPTs: a JSON file describes a Template (name + conditions)
 * plus optional header / body / footer builder trees.
 *
 *   { "name": "Shop", "conditions": { "use_on": [ rule… ], "exclude_from": [] },
 *     "header": <builder tree | null>, "body": <builder tree | null>,
 *     "footer": <builder tree | null> }
 *
 * MANUAL-EDIT GUARD (mirrors the marketing/demo importers' _upw_import_hash): each
 * seeded post is fingerprinted with the content we wrote. On a later (re)seed, a
 * post whose current content no longer matches its fingerprint = the user edited it
 * → it is SKIPPED, never overwritten. UPW_FORCE=1 overrides the guard.
 *
 * SECURITY: the .json files are read-only THEME assets (trusted, never user input).
 * Nothing here writes a PHP file or includes a request-derived path; only basename'd
 * *.json under the fixed up-templates/ folder are read, and the builder tree is
 * re-encoded (not eval'd) before storage.
 */
class FW_Theme_Builder_Seeder {

	const SEED_META = '_up_tb_seed_key';   // identifies a seeded post + its source slug:part
	const HASH_META = '_upw_import_hash';  // content fingerprint for the manual-edit guard

	/** JSON part key → CPT. */
	private static function part_cpts() {
		return array( 'header' => 'up_header', 'body' => 'up_body', 'footer' => 'up_footer' );
	}

	/** Directories scanned (child first, so it overrides the parent's same-slug file). */
	public static function seed_dirs() {
		$dirs   = array();
		$child  = trailingslashit( get_stylesheet_directory() ) . 'up-templates';
		$parent = trailingslashit( get_template_directory() ) . 'up-templates';
		if ( is_dir( $child ) ) {
			$dirs[] = $child;
		}
		if ( $parent !== $child && is_dir( $parent ) ) {
			$dirs[] = $parent;
		}
		return $dirs;
	}

	/** @return array slug => absolute path (child wins over parent for a shared slug). */
	public static function seed_files() {
		$files = array();
		// Parent first then child, so a child file overwrites the parent's slug entry.
		foreach ( array_reverse( self::seed_dirs() ) as $dir ) {
			foreach ( (array) glob( $dir . '/*.json' ) as $path ) {
				$slug = sanitize_title( basename( $path, '.json' ) );
				if ( $slug !== '' ) {
					$files[ $slug ] = $path;
				}
			}
		}
		return $files;
	}

	public static function has_seeds() {
		return ! empty( self::seed_files() );
	}

	/**
	 * Seed every bundled template. @return array slug => result.
	 */
	public static function seed_all( $force = false ) {
		$force  = ( $force === true ) || self::force_requested();
		$report = array();
		foreach ( self::seed_files() as $slug => $path ) {
			$report[ $slug ] = self::seed_file( $path, $force );
		}
		return $report;
	}

	private static function force_requested() {
		return ( defined( 'UPW_FORCE' ) && UPW_FORCE ) || getenv( 'UPW_FORCE' ) === '1';
	}

	/**
	 * Seed one up-templates/*.json file. @return array
	 */
	public static function seed_file( $path, $force = false ) {
		if ( ! is_readable( $path ) ) {
			return array( 'status' => 'unreadable' );
		}
		$data = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $data ) ) {
			return array( 'status' => 'invalid-json' );
		}

		$slug = sanitize_title( basename( $path, '.json' ) );
		$name = ( isset( $data['name'] ) && $data['name'] !== '' )
			? sanitize_text_field( $data['name'] )
			: ucwords( str_replace( '-', ' ', $slug ) );

		// Seed the parts that carry a builder tree; missing/null → 0 (inherit/none).
		$part_ids    = array( 'header' => 0, 'body' => 0, 'footer' => 0 );
		$part_status = array();
		foreach ( self::part_cpts() as $key => $cpt ) {
			if ( isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
				$res                 = self::seed_part( $cpt, $data[ $key ], $slug . ':' . $key, $name . ' — ' . ucfirst( $key ), $force );
				$part_ids[ $key ]    = $res['id'];
				$part_status[ $key ] = $res['status'];
			}
		}

		$tpl = self::seed_template( $slug, $name, $part_ids, isset( $data['conditions'] ) ? $data['conditions'] : array(), $force );

		return array(
			'status'          => 'ok',
			'template_id'     => $tpl['id'],
			'template_status' => $tpl['status'],
			'parts'           => $part_status,
		);
	}

	/** Create/update a part from a builder tree, honoring the manual-edit guard.
	 *
	 * The fingerprint is taken from the READ-BACK stored JSON (not the JSON we hand
	 * to fw_set_db_post_option), because the page-builder option storage re-encodes
	 * on save — so a freshly-seeded, unedited part must hash identically on the next
	 * read or every re-seed would falsely look "edited". */
	private static function seed_part( $cpt, $tree, $seed_key, $title, $force ) {
		$json = (string) wp_json_encode( $tree );

		$existing = self::find_by_seed_key( $cpt, $seed_key );
		if ( $existing ) {
			$stored = (string) get_post_meta( $existing, self::HASH_META, true );
			$edited = ( $stored === '' ) || ( md5( self::part_builder_json( $existing ) ) !== $stored );
			if ( $edited && ! $force ) {
				return array( 'id' => $existing, 'status' => 'skipped-edited' );
			}
			self::write_part_builder( $existing, $json );
			update_post_meta( $existing, self::HASH_META, md5( self::part_builder_json( $existing ) ) );
			return array( 'id' => $existing, 'status' => ( $edited ? 'forced' : 'updated' ) );
		}

		$id = wp_insert_post( array(
			'post_type'   => $cpt,
			'post_status' => 'publish',
			'post_title'  => $title,
		), true );
		if ( is_wp_error( $id ) || ! $id ) {
			return array( 'id' => 0, 'status' => 'insert-failed' );
		}
		self::write_part_builder( $id, $json );
		update_post_meta( $id, self::SEED_META, $seed_key );
		update_post_meta( $id, self::HASH_META, md5( self::part_builder_json( $id ) ) );
		return array( 'id' => (int) $id, 'status' => 'created' );
	}

	/** Create/update the Template (refs + conditions), honoring the guard. Like the
	 * parts, the fingerprint is taken from the READ-BACK signature so an unedited
	 * re-seed matches. */
	private static function seed_template( $slug, $name, $part_ids, $conditions, $force ) {
		$seed_key   = $slug . ':template';
		$conditions = self::normalize_conditions( $conditions );

		$existing = self::find_by_seed_key( 'up_template', $seed_key );
		if ( $existing ) {
			$stored = (string) get_post_meta( $existing, self::HASH_META, true );
			$edited = ( $stored === '' ) || ( md5( self::existing_template_sig( $existing ) ) !== $stored );
			if ( $edited && ! $force ) {
				return array( 'id' => $existing, 'status' => 'skipped-edited' );
			}
			self::write_template( $existing, $name, $part_ids, $conditions );
			update_post_meta( $existing, self::HASH_META, md5( self::existing_template_sig( $existing ) ) );
			return array( 'id' => $existing, 'status' => ( $edited ? 'forced' : 'updated' ) );
		}

		$id = wp_insert_post( array(
			'post_type'   => 'up_template',
			'post_status' => 'publish',
			'post_title'  => $name,
		), true );
		if ( is_wp_error( $id ) || ! $id ) {
			return array( 'id' => 0, 'status' => 'insert-failed' );
		}
		self::write_template( $id, $name, $part_ids, $conditions );
		update_post_meta( $id, self::SEED_META, $seed_key );
		update_post_meta( $id, self::HASH_META, md5( self::existing_template_sig( $id ) ) );
		return array( 'id' => (int) $id, 'status' => 'created' );
	}

	/* ---------------------------------------------------------------- */

	private static function find_by_seed_key( $cpt, $seed_key ) {
		$q = get_posts( array(
			'post_type'        => $cpt,
			'post_status'      => 'any',
			'numberposts'      => 1,
			'fields'           => 'ids',
			'meta_key'         => self::SEED_META,
			'meta_value'       => $seed_key,
			'suppress_filters' => false,
		) );
		return $q ? (int) $q[0] : 0;
	}

	private static function part_builder_json( $post_id ) {
		$v = fw_get_db_post_option( $post_id, 'page-builder' );
		return ( is_array( $v ) && isset( $v['json'] ) ) ? (string) $v['json'] : '';
	}

	private static function write_part_builder( $post_id, $json ) {
		fw_set_db_post_option( $post_id, 'page-builder', array( 'json' => $json, 'builder_active' => true ) );
	}

	private static function write_template( $id, $name, $part_ids, $conditions ) {
		wp_update_post( array( 'ID' => $id, 'post_title' => $name ) );
		fw_set_db_post_option( $id, 'tb_header_id', (int) $part_ids['header'] );
		fw_set_db_post_option( $id, 'tb_body_id', (int) $part_ids['body'] );
		fw_set_db_post_option( $id, 'tb_footer_id', (int) $part_ids['footer'] );
		fw_set_db_post_option( $id, 'tb_conditions', $conditions );
	}

	private static function template_sig( $name, $part_ids, $conditions ) {
		return (string) wp_json_encode( array(
			'name' => $name,
			'h'    => (int) $part_ids['header'],
			'b'    => (int) $part_ids['body'],
			'f'    => (int) $part_ids['footer'],
			'c'    => $conditions,
		) );
	}

	private static function existing_template_sig( $id ) {
		return self::template_sig(
			get_the_title( $id ),
			array(
				'header' => (int) fw_get_db_post_option( $id, 'tb_header_id' ),
				'body'   => (int) fw_get_db_post_option( $id, 'tb_body_id' ),
				'footer' => (int) fw_get_db_post_option( $id, 'tb_footer_id' ),
			),
			self::normalize_conditions( fw_get_db_post_option( $id, 'tb_conditions' ) )
		);
	}

	private static function normalize_conditions( $c ) {
		$c     = is_array( $c ) ? $c : array();
		$clean = static function ( $rules ) {
			$out = array();
			foreach ( (array) $rules as $r ) {
				if ( ! is_array( $r ) || empty( $r['type'] ) ) {
					continue;
				}
				$out[] = array(
					'type'     => sanitize_key( $r['type'] ),
					'sub_type' => isset( $r['sub_type'] ) ? sanitize_key( $r['sub_type'] ) : '',
					'ids'      => ( isset( $r['ids'] ) && is_array( $r['ids'] ) ) ? array_map( 'intval', $r['ids'] ) : array(),
				);
			}
			return $out;
		};
		return array(
			'use_on'       => $clean( isset( $c['use_on'] ) ? $c['use_on'] : array() ),
			'exclude_from' => $clean( isset( $c['exclude_from'] ) ? $c['exclude_from'] : array() ),
		);
	}
}
