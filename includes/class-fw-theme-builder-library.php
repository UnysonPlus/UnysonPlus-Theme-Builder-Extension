<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Preset Library — ready-made Header / Body / Footer designs bundled with the
 * extension (one JSON per design under /library). The "Preset Library" admin screen
 * lists them as cards; **Insert** creates an editable copy (a new up_header /
 * up_body / up_footer post) the user then customizes in the builder. A library JSON
 * looks like:
 *
 *   { "name": "...", "kind": "header|body|footer", "description": "...",
 *     "meta": { "hf_type": "...", "hf_behavior": "...", "custom_css": "..." },
 *     "json": [ <page-builder tree> ] }
 *
 * Adding a design = dropping another *.json in /library. The insert reuses the same
 * storage the seeder uses (the `page-builder` post option), so the copy opens in the
 * builder like any other preset.
 */
class FW_Theme_Builder_Library {

	const MENU_SLUG = 'fw-theme-builder-library';
	const CAP       = 'edit_theme_options';

	private static $cpt_map = array( 'header' => 'up_header', 'body' => 'up_body', 'footer' => 'up_footer' );

	public function __construct() {
		add_action( 'admin_menu', array( $this, '_action_menu' ), 20 );
		add_action( 'wp_ajax_fw_tb_library_insert', array( $this, '_ajax_insert' ) );
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
		$kind = isset( $data['kind'] ) ? $data['kind'] : '';
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

	/** Register the "Preset Library" submenu under Theme Builder. */
	public function _action_menu() {
		add_submenu_page(
			'fw-theme-builder',
			__( 'Preset Library', 'fw' ),
			__( 'Preset Library', 'fw' ),
			self::CAP,
			self::MENU_SLUG,
			array( $this, '_render' )
		);
	}

	/** The card-grid gallery screen. */
	public function _render() {
		$items = self::items();
		$badge = array(
			'header' => __( 'Header', 'fw' ),
			'body'   => __( 'Body', 'fw' ),
			'footer' => __( 'Footer', 'fw' ),
		);
		$nonce = wp_create_nonce( 'fw_tb_library_insert' );
		?>
		<div class="wrap fw-tb-library">
			<h1><?php esc_html_e( 'Preset Library', 'fw' ); ?></h1>
			<p class="description" style="max-width:760px">
				<?php esc_html_e( 'Ready-made Header, Body and Footer designs. Insert one to create an editable copy — it opens straight in the builder so you can customize it, then assign it from a Template. The original library design is never changed.', 'fw' ); ?>
			</p>

			<?php if ( empty( $items ) ) : ?>
				<p><?php esc_html_e( 'No library presets are available.', 'fw' ); ?></p>
			<?php else : ?>
				<div class="fw-tb-lib-grid">
					<?php foreach ( $items as $it ) : ?>
						<div class="fw-tb-lib-card" data-kind="<?php echo esc_attr( $it['kind'] ); ?>">
							<span class="fw-tb-lib-badge fw-tb-lib-badge--<?php echo esc_attr( $it['kind'] ); ?>"><?php echo esc_html( isset( $badge[ $it['kind'] ] ) ? $badge[ $it['kind'] ] : $it['kind'] ); ?></span>
							<h3><?php echo esc_html( $it['name'] ); ?></h3>
							<p><?php echo esc_html( $it['description'] ); ?></p>
							<button type="button" class="button button-primary fw-tb-lib-insert" data-slug="<?php echo esc_attr( $it['slug'] ); ?>"><?php esc_html_e( 'Insert', 'fw' ); ?></button>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>

		<style>
			.fw-tb-lib-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:18px;margin-top:20px}
			.fw-tb-lib-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px;position:relative;box-shadow:0 1px 1px rgba(0,0,0,.04)}
			.fw-tb-lib-card h3{margin:6px 0 6px;padding-right:70px}
			.fw-tb-lib-card p{color:#646970;min-height:42px;margin:0 0 14px}
			.fw-tb-lib-badge{position:absolute;top:16px;right:16px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#2271b1;background:#f0f6fc;border:1px solid #c5d9ed;border-radius:999px;padding:2px 10px}
			.fw-tb-lib-badge--body{color:#1a7f37;background:#edfaef;border-color:#bfe3c5}
			.fw-tb-lib-badge--footer{color:#8a5400;background:#fcf6e9;border-color:#e6d5af}
		</style>
		<script>
		( function ( $ ) {
			$( '.fw-tb-lib-insert' ).on( 'click', function () {
				var $b = $( this ), label = $b.text();
				$b.prop( 'disabled', true ).text( <?php echo wp_json_encode( __( 'Inserting…', 'fw' ) ); ?> );
				$.post( ajaxurl, { action: 'fw_tb_library_insert', slug: $b.data( 'slug' ), _wpnonce: <?php echo wp_json_encode( $nonce ); ?> } )
					.done( function ( r ) {
						if ( r && r.success && r.data && r.data.edit_url ) {
							window.location = r.data.edit_url;
						} else {
							window.alert( ( r && r.data && r.data.message ) || 'Error' );
							$b.prop( 'disabled', false ).text( label );
						}
					} )
					.fail( function () { window.alert( 'Error' ); $b.prop( 'disabled', false ).text( label ); } );
			} );
		}( jQuery ) );
		</script>
		<?php
	}

	/** AJAX: insert a library preset, return its edit URL. */
	public function _ajax_insert() {
		check_ajax_referer( 'fw_tb_library_insert' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'fw' ) ) );
		}
		$res = self::insert( isset( $_POST['slug'] ) ? sanitize_file_name( wp_unslash( $_POST['slug'] ) ) : '' );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success( $res );
	}
}
