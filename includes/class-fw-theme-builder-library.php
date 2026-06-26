<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Starter presets — a few ready-made Header / Body / Footer designs bundled with the
 * extension (one JSON per design under /library). They are seeded ONCE, as real
 * published up_header / up_body / up_footer posts, so they appear directly in the
 * Header / Body / Footer Presets lists for the user to edit, duplicate, or delete.
 * There is no manual-edit guard: a one-time option flag means a user's deletions or
 * edits are never undone (re-seeding never happens). A design JSON looks like:
 *
 *   { "name": "...", "kind": "header|body|footer", "description": "...",
 *     "meta": { "hf_type": "...", "hf_behavior": "...", "custom_css": "..." },
 *     "json": [ <page-builder tree> ] }
 *
 * Adding a starter = dropping another *.json in /library. Seeding reuses the same
 * storage as the seeder (the `page-builder` post option), so a seeded preset opens in
 * the builder like any other.
 */
class FW_Theme_Builder_Library {

	/** Legacy one-time flag (the original "all four seeded" boolean; now migrated). */
	const SEED_FLAG = 'fw_tb_starter_presets_seeded';

	/** Option: the starter slugs already seeded. Tracking per-slug lets a NEW bundled
	 *  starter seed once on existing sites, while a user's deletions stay deleted. */
	const SEEDED_OPT = 'fw_tb_seeded_starters';

	private static $cpt_map = array( 'header' => 'up_header', 'body' => 'up_body', 'footer' => 'up_footer' );

	/** @var FW_Extension|null */
	private $extension;

	public function __construct( $extension = null ) {
		$this->extension = $extension;
		add_action( 'admin_init', array( $this, '_maybe_seed_starters' ) );
		add_action( 'admin_enqueue_scripts', array( $this, '_action_import_assets' ) );
		add_action( 'wp_ajax_fw_tb_import_preset', array( $this, '_ajax_import' ) );
	}

	/**
	 * Seed the bundled starter presets ONCE, directly into the preset lists. Guarded
	 * by a one-time option (set BEFORE seeding so concurrent loads can't double-seed,
	 * and so a user's later deletions are never re-created). Only an editor of theme
	 * options triggers it.
	 *
	 * @internal
	 */
	public function _maybe_seed_starters() {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}
		$seeded = get_option( self::SEEDED_OPT, null );
		if ( ! is_array( $seeded ) ) {
			// Migrate from the legacy boolean: if it was set, the original four are
			// already seeded — record them so they are never re-created.
			$seeded = get_option( self::SEED_FLAG )
				? array( 'simple-header', 'centered-header', 'minimal-footer', 'page-content-body' )
				: array();
		}

		// Reserve the slugs we'll seed (write the option BEFORE inserting) so a
		// concurrent load can't double-seed.
		$to_seed = array();
		foreach ( self::items() as $it ) {
			if ( ! in_array( $it['slug'], $seeded, true ) ) {
				$to_seed[]  = $it['slug'];
				$seeded[]   = $it['slug'];
			}
		}
		if ( $to_seed || null === get_option( self::SEEDED_OPT, null ) ) {
			update_option( self::SEEDED_OPT, array_values( array_unique( $seeded ) ) );
		}
		foreach ( $to_seed as $slug ) {
			self::insert( $slug );
		}
	}

	/** Absolute path to the bundled /library directory. */
	public static function dir() {
		return dirname( dirname( __FILE__ ) ) . '/library';
	}

	/**
	 * All library items as lightweight rows (slug / name / kind / description),
	 * sorted by kind then name. Heavy `json` is not loaded here.
	 *
	 * @return array
	 */
	public static function items() {
		$items = array();
		foreach ( (array) glob( self::dir() . '/*.json' ) as $file ) {
			$data = json_decode( (string) file_get_contents( $file ), true );
			if ( ! is_array( $data ) || empty( $data['kind'] ) || ! isset( self::$cpt_map[ $data['kind'] ] ) ) {
				continue;
			}
			$items[] = array(
				'slug'        => basename( $file, '.json' ),
				'name'        => isset( $data['name'] ) ? (string) $data['name'] : basename( $file, '.json' ),
				'kind'        => (string) $data['kind'],
				'description' => isset( $data['description'] ) ? (string) $data['description'] : '',
			);
		}
		usort( $items, function ( $a, $b ) {
			return ( $a['kind'] === $b['kind'] ) ? strcasecmp( $a['name'], $b['name'] ) : strcmp( $a['kind'], $b['kind'] );
		} );
		return $items;
	}

	/** One library item's full data, or null. */
	public static function get( $slug ) {
		$file = self::dir() . '/' . sanitize_file_name( $slug ) . '.json';
		if ( ! is_readable( $file ) ) {
			return null;
		}
		$data = json_decode( (string) file_get_contents( $file ), true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Create a new editable preset post from a library item.
	 *
	 * @param string $slug
	 * @return array|WP_Error [ id, edit_url ]
	 */
	public static function insert( $slug ) {
		$data = self::get( $slug );
		if ( ! $data ) {
			return new WP_Error( 'fw_tb_lib_not_found', __( 'Library preset not found.', 'fw' ) );
		}
		return self::insert_data( $data );
	}

	/**
	 * Create a new editable preset post from a decoded design array — a bundled
	 * starter or an imported JSON. Shape: { name, kind, json:[tree], meta:{} }.
	 *
	 * @param array $data
	 * @return array|WP_Error [ id, edit_url ]
	 */
	public static function insert_data( $data ) {
		$kind = ( is_array( $data ) && isset( $data['kind'] ) ) ? $data['kind'] : '';
		if ( ! isset( self::$cpt_map[ $kind ] ) ) {
			return new WP_Error( 'fw_tb_lib_bad_kind', __( 'Unknown preset kind.', 'fw' ) );
		}

		$id = wp_insert_post( array(
			'post_type'   => self::$cpt_map[ $kind ],
			'post_status' => 'publish',
			'post_title'  => isset( $data['name'] ) ? (string) $data['name'] : __( 'New preset', 'fw' ),
		), true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$tree = ( isset( $data['json'] ) && is_array( $data['json'] ) ) ? $data['json'] : array();
		fw_set_db_post_option( (int) $id, 'page-builder', array(
			'json'           => wp_json_encode( $tree ),
			'builder_active' => true,
		) );

		if ( ! empty( $data['meta'] ) && is_array( $data['meta'] ) ) {
			foreach ( $data['meta'] as $k => $v ) {
				fw_set_db_post_option( (int) $id, (string) $k, $v );
			}
		}

		return array(
			'id'       => (int) $id,
			'edit_url' => get_edit_post_link( (int) $id, 'raw' ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Import a preset (upload JSON) from the Header/Body/Footer lists.    */
	/* ------------------------------------------------------------------ */

	/**
	 * Enqueue the import button + uploader on the part list screens. The button is
	 * injected next to "Add New"; selecting a JSON posts it to the import AJAX.
	 *
	 * @internal
	 */
	public function _action_import_assets( $hook ) {
		if ( 'edit.php' !== $hook || ! $this->extension ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$kind   = $screen ? array_search( $screen->post_type, self::$cpt_map, true ) : false;
		if ( ! $kind ) {
			return;
		}
		wp_enqueue_script(
			'fw-tb-import-preset',
			$this->extension->get_uri( '/static/js/import-preset.js' ),
			array(),
			$this->extension->manifest->get_version(),
			true
		);
		wp_localize_script( 'fw-tb-import-preset', 'fwTbImport', array(
			'ajaxurl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'fw_tb_import_preset' ),
			'kind'      => $kind,
			'label'     => __( 'Import Preset', 'fw' ),
			'importing' => __( 'Importing…', 'fw' ),
			'fail'      => __( 'Import failed.', 'fw' ),
		) );
	}

	/**
	 * AJAX: create a preset from an uploaded design JSON. Gated on the nonce +
	 * edit_theme_options. The JSON must declare the same `kind` as the list it was
	 * imported from.
	 *
	 * @internal
	 */
	public function _ajax_import() {
		check_ajax_referer( 'fw_tb_import_preset' );
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'fw' ) ) );
		}
		$kind = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : '';
		$data = json_decode( isset( $_POST['data'] ) ? (string) wp_unslash( $_POST['data'] ) : '', true );
		if ( ! is_array( $data ) || empty( $data['kind'] ) || ! isset( $data['json'] ) ) {
			wp_send_json_error( array( 'message' => __( 'That file is not a valid preset (expected name / kind / json).', 'fw' ) ) );
		}
		if ( $kind && $data['kind'] !== $kind && isset( self::$cpt_map[ $data['kind'] ] ) ) {
			wp_send_json_error( array( 'message' => sprintf( __( 'That is a %1$s preset — import it from the %1$s Presets list.', 'fw' ), esc_html( $data['kind'] ) ) ) );
		}
		$res = self::insert_data( $data );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success( $res );
	}

}
