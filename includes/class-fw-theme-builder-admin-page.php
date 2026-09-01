<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Theme Builder — admin management page.
 *
 * A bespoke management dashboard (exempt from the metabox-holder convention, like
 * the Shortcodes settings page): a WordPress-native table of Templates, with an
 * Add/Edit sub-screen built from Unyson options (rendered via render_options) and
 * saved with the standard PRG pattern. No card-grid, no Divi assets — our own UI.
 *
 * A Template is an up_template post: post_title = name; meta tb_header_id /
 * tb_body_id / tb_footer_id (part refs) + tb_conditions (Use On / Exclude From),
 * read at render time by FW_Theme_Builder_Resolver.
 */
class FW_Theme_Builder_Admin_Page {

	const MENU_SLUG  = 'fw-theme-builder';
	const CAPABILITY = 'edit_theme_options';
	const NONCE_SAVE = 'fw_theme_builder_save';
	const NONCE_ROW  = 'fw_theme_builder_row';
	const NONCE_PART = 'fw_theme_builder_part';
	const NONCE_BULK = 'fw_theme_builder_bulk';
	const PER_PAGE   = 20;

	/** @var FW_Extension_Theme_Builder */
	private $extension;

	/** @var string|false */
	private $hook_suffix = false;

	public function __construct( $extension ) {
		$this->extension = $extension;

		add_action( 'admin_menu', array( $this, '_action_admin_menu' ) );
		// Pull Templates to the top of the submenu (it's the hub most people edit, and
		// becomes the menu's default landing page). Runs late, after the part CPT
		// submenus have been appended by core.
		add_action( 'admin_menu', array( $this, '_action_reorder_submenu' ), 100 );
		add_action( 'admin_enqueue_scripts', array( $this, '_action_enqueue' ) );

		// Inline "＋ New Header/Body/Footer" creation from the Template screen.
		add_action( 'wp_ajax_fw_tb_create_part', array( $this, '_ajax_create_part' ) );
		// Assign / clear one Header|Body|Footer slot straight from a canvas card.
		add_action( 'wp_ajax_fw_tb_set_part', array( $this, '_ajax_set_part' ) );
		// Values (posts / terms) for one condition row's picker.
		add_action( 'wp_ajax_fw_tb_rule_choices', array( $this, '_ajax_rule_choices' ) );
		// Switch a Template on/off straight from its card.
		add_action( 'wp_ajax_fw_tb_set_enabled', array( $this, '_ajax_set_enabled' ) );
		add_action( 'wp_ajax_fw_tb_import_design', array( $this, '_ajax_import_design' ) );
	}

	/**
	 * AJAX: create a published Header/Body/Footer part inline (from the Template
	 * edit screen's "＋ New" button) and return its id + edit URL, so the dropdown
	 * can select it immediately and the user can open it in the builder. The part
	 * is created builder-active (page-builder json '[]') so it opens straight into
	 * the page builder, matching the part editors' default-to-builder behavior.
	 *
	 * @internal
	 */
	public function _ajax_create_part() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'fw' ) ) );
		}
		check_ajax_referer( self::NONCE_PART, 'nonce' );

		$cpt = isset( $_POST['cpt'] ) ? sanitize_key( $_POST['cpt'] ) : '';
		if ( ! in_array( $cpt, $this->extension->get_part_post_types(), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown part type.', 'fw' ) ) );
		}

		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		if ( $name === '' ) {
			$obj  = get_post_type_object( $cpt );
			$name = $obj ? $obj->labels->singular_name : __( 'Untitled', 'fw' );
		}

		$id = wp_insert_post( array(
			'post_type'   => $cpt,
			'post_status' => 'publish',
			'post_title'  => $name,
		), true );

		if ( is_wp_error( $id ) || ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Could not create the part.', 'fw' ) ) );
		}

		// Open straight into the page builder when edited (mirrors the part editors).
		fw_set_db_post_option( $id, 'page-builder', array( 'json' => '[]', 'builder_active' => true ) );

		wp_send_json_success( array(
			'id'       => (int) $id,
			'title'    => get_the_title( $id ),
			'edit_url' => admin_url( 'post.php?post=' . (int) $id . '&action=edit' ),
		) );
	}

	/**
	 * AJAX: point ONE slot of a Template at a part — or clear it (part id 0). This is
	 * what the canvas cards use, so a header can be swapped without opening the
	 * two-step edit form. Writes exactly the same meta key the form writes, so both
	 * screens stay interchangeable.
	 *
	 * @internal
	 */
	public function _ajax_set_part() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'fw' ) ) );
		}
		check_ajax_referer( self::NONCE_PART, 'nonce' );

		$tpl = isset( $_POST['template'] ) ? (int) $_POST['template'] : 0;
		if ( ! $tpl || get_post_type( $tpl ) !== 'up_template' ) {
			wp_send_json_error( array( 'message' => __( 'Unknown Template.', 'fw' ) ) );
		}

		$key = isset( $_POST['key'] ) ? sanitize_key( $_POST['key'] ) : '';
		$map = $this->slot_map();
		if ( ! isset( $map[ $key ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown slot.', 'fw' ) ) );
		}

		$part = isset( $_POST['part'] ) ? (int) $_POST['part'] : 0;
		// 0 clears the slot; anything else must be a published part of THIS slot's CPT,
		// so a footer can never be assigned to the header slot.
		if ( $part > 0 && ( get_post_type( $part ) !== $map[ $key ]['cpt'] || get_post_status( $part ) !== 'publish' ) ) {
			wp_send_json_error( array( 'message' => __( 'That design cannot go in this slot.', 'fw' ) ) );
		}

		fw_set_db_post_option( $tpl, $key, $part );

		wp_send_json_success( array(
			'part'     => $part,
			'title'    => $part ? get_the_title( $part ) : '',
			'edit_url' => $part ? admin_url( 'post.php?post=' . $part . '&action=edit' ) : '',
		) );
	}

	/**
	 * AJAX: switch a Template on or off from its card. Stored inverted as
	 * `tb_disabled` so that an absent flag always reads as ON — see
	 * FW_Theme_Builder_Resolver::is_enabled().
	 *
	 * @internal
	 */
	public function _ajax_set_enabled() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'fw' ) ) );
		}
		check_ajax_referer( self::NONCE_PART, 'nonce' );

		$tpl = isset( $_POST['template'] ) ? (int) $_POST['template'] : 0;
		if ( ! $tpl || get_post_type( $tpl ) !== 'up_template' ) {
			wp_send_json_error( array( 'message' => __( 'Unknown Template.', 'fw' ) ) );
		}

		$enabled = ! empty( $_POST['enabled'] ) && 'false' !== $_POST['enabled'];
		fw_set_db_post_option( $tpl, 'tb_disabled', $enabled ? 0 : 1 );

		wp_send_json_success( array( 'enabled' => $enabled ) );
	}

	/**
	 * AJAX: the selectable values for one condition row — posts for a Singular /
	 * Children-of rule, terms for a taxonomy rule. Read-only and capped, with an
	 * optional search, so a site with thousands of posts still answers quickly.
	 *
	 * @internal
	 */
	public function _ajax_rule_choices() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'fw' ) ) );
		}
		check_ajax_referer( self::NONCE_PART, 'nonce' );

		$type = isset( $_POST['type'] ) ? sanitize_key( $_POST['type'] ) : '';
		$sub  = isset( $_POST['sub_type'] ) ? sanitize_key( $_POST['sub_type'] ) : '';
		$term = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';

		wp_send_json_success( array(
			'items' => FW_Theme_Builder_Conditions::id_choices( $type, $sub, $term ),
		) );
	}

	/**
	 * AJAX: import a design bundle (uploaded JSON from a row Export) as a new Template
	 * + fresh Header/Body/Footer presets. Gated on the nonce + cap.
	 *
	 * @internal
	 */
	public function _ajax_import_design() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'fw' ) ) );
		}
		check_ajax_referer( 'fw_tb_import_design' );
		if ( ! class_exists( 'FW_Theme_Builder_Seeder' ) ) {
			wp_send_json_error( array( 'message' => __( 'Importer unavailable.', 'fw' ) ) );
		}
		$data = json_decode( isset( $_POST['data'] ) ? (string) wp_unslash( $_POST['data'] ) : '', true );
		if ( ! is_array( $data ) ) {
			wp_send_json_error( array( 'message' => __( 'That file is not a valid design export.', 'fw' ) ) );
		}
		$res = FW_Theme_Builder_Seeder::import_bundle( $data );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success( array(
			'id'       => (int) $res,
			'edit_url' => $this->add_url( array( 'id' => (int) $res ) ),
		) );
	}

	/** @return string */
	public function get_menu_slug() {
		return self::MENU_SLUG;
	}

	/**
	 * Are card thumbnails on? Per-user, remembered, default ON.
	 *
	 * Previews are live front-end renders in an iframe, so each one costs a page
	 * load. They are lazy (only cards scrolled into view load) — but a very large
	 * or very slow site may still want them off, hence the switch.
	 *
	 * @return bool
	 */
	private function previews_enabled() {
		static $on = null;
		if ( null !== $on ) {
			return $on;
		}

		$asked = isset( $_GET['previews'] ) ? sanitize_key( $_GET['previews'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'on' === $asked || 'off' === $asked ) {
			$on = ( 'on' === $asked );
			update_user_meta( get_current_user_id(), 'fw_tb_previews', $on ? '1' : '0' );
			return $on;
		}

		$saved = get_user_meta( get_current_user_id(), 'fw_tb_previews', true );
		$on    = ( '0' !== $saved ); // unset => on
		return $on;
	}

	/**
	 * The embedded-preview URL for a Template's thumbnail, or '' when there is
	 * nothing worth showing (no Header/Body/Footer assigned yet — the frame would
	 * just be the ordinary theme page).
	 *
	 * Previewed against a URL the Template's own conditions match, so a Template
	 * for single posts is shown on a single post rather than on the home page.
	 *
	 * @param int   $tpl_id
	 * @param array $conditions
	 * @return string
	 */
	private function card_preview_url( $tpl_id, $conditions ) {
		if ( ! function_exists( 'fw_tb_preview_url' ) ) {
			return '';
		}

		$has_part = false;
		foreach ( array_keys( $this->slot_map() ) as $key ) {
			if ( (int) fw_get_db_post_option( $tpl_id, $key ) > 0 ) {
				$has_part = true;
				break;
			}
		}
		if ( ! $has_part ) {
			return '';
		}

		return fw_tb_preview_url(
			array( 'template' => (int) $tpl_id, 'embed' => 1 ),
			FW_Theme_Builder_Conditions::representative_url( $conditions )
		);
	}

	/**
	 * The three Template slots, in render order. Single source of truth shared by the
	 * canvas cards, the set-part endpoint and the script localization — meta key =>
	 * its part CPT plus the labels each surface needs.
	 *
	 * @return array<string,array>
	 */
	private function slot_map() {
		return array(
			'tb_header_id' => array(
				'cpt'   => 'up_header',
				'noun'  => __( 'header', 'fw' ),
				'label' => __( 'Header', 'fw' ),
				'empty' => __( 'Override site header', 'fw' ),
				'zero'  => __( 'Inherit', 'fw' ),
			),
			'tb_body_id' => array(
				'cpt'   => 'up_body',
				'noun'  => __( 'body', 'fw' ),
				'label' => __( 'Body', 'fw' ),
				'empty' => __( 'Add body layout', 'fw' ),
				'zero'  => __( 'None', 'fw' ),
			),
			'tb_footer_id' => array(
				'cpt'   => 'up_footer',
				'noun'  => __( 'footer', 'fw' ),
				'label' => __( 'Footer', 'fw' ),
				'empty' => __( 'Override site footer', 'fw' ),
				'zero'  => __( 'Inherit', 'fw' ),
			),
		);
	}

	/**
	 * Published parts of one CPT as a JS-friendly list, for the canvas slot picker.
	 *
	 * @param string $cpt
	 * @return array<int,array{id:int,title:string}>
	 */
	private function part_list( $cpt ) {
		$out   = array();
		$posts = get_posts( array(
			'post_type'        => $cpt,
			'post_status'      => 'publish',
			'numberposts'      => -1,
			'orderby'          => 'title',
			'order'            => 'ASC',
			'suppress_filters' => false,
		) );
		foreach ( $posts as $post ) {
			$out[] = array(
				'id'    => (int) $post->ID,
				'title' => ( $post->post_title !== '' ) ? $post->post_title : sprintf( __( '(no title) #%d', 'fw' ), $post->ID ),
			);
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Menu                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * @internal
	 */
	public function _action_admin_menu() {
		$this->hook_suffix = add_menu_page(
			__( 'Theme Builder', 'fw' ),
			__( 'Theme Builder', 'fw' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_page' ),
			'dashicons-layout',
			59 // just under Appearance (60)
		);

		// Rename the auto-created first submenu to "Templates".
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Templates', 'fw' ),
			__( 'Templates', 'fw' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);

		if ( $this->hook_suffix ) {
			add_action( 'load-' . $this->hook_suffix, array( $this, '_maybe_handle' ) );
		}
	}

	/**
	 * Move the **Templates** submenu item to the top of the Theme Builder menu so it
	 * leads (and is the menu's default landing page). The part CPTs (Header / Body /
	 * Footer Presets) keep their existing order beneath it. Core appends the CPT
	 * submenus before our same-slug Templates item, so without this Templates lands
	 * last; this runs on `admin_menu` priority 100, after everything is registered.
	 *
	 * @internal
	 */
	public function _action_reorder_submenu() {
		global $submenu;
		if ( empty( $submenu[ self::MENU_SLUG ] ) || ! is_array( $submenu[ self::MENU_SLUG ] ) ) {
			return;
		}
		$templates = null;
		$rest      = array();
		foreach ( $submenu[ self::MENU_SLUG ] as $item ) {
			// $item[2] is the submenu slug; the Templates page reuses the parent slug.
			if ( isset( $item[2] ) && $item[2] === self::MENU_SLUG ) {
				$templates = $item;
			} else {
				$rest[] = $item;
			}
		}
		if ( $templates !== null ) {
			array_unshift( $rest, $templates );
			$submenu[ self::MENU_SLUG ] = array_values( $rest );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Assets                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * @internal
	 */
	public function _action_enqueue( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}
		$ver = $this->extension->manifest->get( 'version' );

		wp_enqueue_style(
			'fw-theme-builder-admin',
			$this->extension->get_uri( '/static/css/admin.css' ),
			array(),
			$ver
		);
		wp_enqueue_script(
			'fw-theme-builder-admin',
			$this->extension->get_uri( '/static/js/admin.js' ),
			array( 'jquery' ),
			$ver,
			true
		);
		// Slot metadata + the published parts each slot can take. Only the canvas view
		// needs the choice lists, so the three queries are skipped on the other views.
		$slots        = array();
		$part_choices = array();
		$is_canvas    = ( $this->current_view() === 'list' && $this->current_mode() === 'cards' );
		$is_edit      = ( $this->current_view() === 'edit' );
		foreach ( $this->slot_map() as $key => $slot ) {
			$slots[ $key ]        = array( 'cpt' => $slot['cpt'], 'noun' => $slot['noun'], 'label' => $slot['label'], 'empty' => $slot['empty'] );
			$part_choices[ $key ] = $is_canvas ? $this->part_list( $slot['cpt'] ) : array();
		}

		$sub_choices = array();
		if ( $is_edit ) {
			foreach ( array_keys( FW_Theme_Builder_Conditions::rule_types() ) as $type ) {
				$sub_choices[ $type ] = FW_Theme_Builder_Conditions::sub_choices( $type );
			}
		}

		wp_localize_script( 'fw-theme-builder-admin', 'fwThemeBuilder', array(
			'confirmDelete' => __( 'Delete this Template? Its header/body/footer designs are not deleted.', 'fw' ),
			'createNonce'   => wp_create_nonce( self::NONCE_PART ),
			'importNonce'   => wp_create_nonce( 'fw_tb_import_design' ),
			'importFail'    => __( 'Import failed — is the file a Theme Builder design export?', 'fw' ),
			'editPartBase'  => admin_url( 'post.php' ),
			// Which dropdown/slot maps to which part CPT (drives the inline "＋ New"
			// button on the edit form AND the slot picker on the canvas cards).
			'parts'         => $slots,
			// Published parts per slot, so the canvas picker needs no extra round-trip.
			'partChoices'   => $part_choices,
			// The condition-row vocabulary. Only the edit screen draws rows, so the
			// qualifier lists (post types / taxonomies / special pages) are built there.
			'ruleTypes'     => $is_edit ? FW_Theme_Builder_Conditions::rule_types() : array(),
			'subChoices'    => $sub_choices,
			'i18n'          => array(
				'newPart'    => __( '＋ New', 'fw' ),
				'create'     => __( 'Create', 'fw' ),
				'cancel'     => __( 'Cancel', 'fw' ),
				'deleteBtn'  => __( 'Delete', 'fw' ),
				'remove'     => __( 'Remove', 'fw' ),
				'creating'   => __( 'Creating…', 'fw' ),
				'saving'     => __( 'Saving…', 'fw' ),
				'editDesign' => __( 'Edit design ↗', 'fw' ),
				/* translators: %s = part type, e.g. "body" */
				'namePH'     => __( 'New %s name', 'fw' ),
				'createdTip' => __( 'Created — now click “Edit design” to build it, then Save the Template.', 'fw' ),
				'error'      => __( 'Could not create. Please try again.', 'fw' ),
				'editTitle'  => __( 'Edit design', 'fw' ),
				'pick'       => __( '— Choose a design —', 'fw' ),
				'pickNew'    => __( '＋ New design…', 'fw' ),
				/* translators: %s = part type, e.g. "footer" */
				'clearAsk'   => __( 'Remove this %s from the Template? The design itself is not deleted.', 'fw' ),
				'include'    => __( 'Include', 'fw' ),
				'exclude'    => __( 'Exclude', 'fw' ),
				'pickType'   => __( '— Choose —', 'fw' ),
				'removeRow'  => __( 'Remove this condition', 'fw' ),
				'chooseVals' => __( 'Choose…', 'fw' ),
				'searchPH'   => __( 'Search…', 'fw' ),
				'loading'    => __( 'Loading…', 'fw' ),
				'noItems'    => __( 'Nothing found.', 'fw' ),
				'allOf'      => __( 'All (leave empty)', 'fw' ),
				'noRows'     => __( 'No conditions yet — this Template will not be applied anywhere until you add one.', 'fw' ),
				'done'       => __( 'Done', 'fw' ),
				'toggleFail' => __( 'Could not change the status. Please try again.', 'fw' ),
			),
		) );

		// The edit sub-screen needs the option-type assets (multi-select, etc.).
		if ( $this->current_view() === 'edit' ) {
			fw()->backend->enqueue_options_static( $this->form_options() );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Routing + actions (PRG)                                            */
	/* ------------------------------------------------------------------ */

	/** @return string list|edit */
	private function current_view() {
		return ( isset( $_GET['view'] ) && $_GET['view'] === 'edit' ) ? 'edit' : 'list'; // phpcs:ignore WordPress.Security.NonceVerification
	}

	/**
	 * How the Templates screen is drawn: 'cards' (the canvas — default), 'tree'
	 * (the template hierarchy, which also shows the gaps) or 'list' (the compact
	 * table). An explicit ?view_mode= wins and is remembered per user, so the choice
	 * survives the PRG redirects the row actions perform.
	 *
	 * @return string cards|tree|list
	 */
	private function current_mode() {
		static $mode = null;
		if ( null !== $mode ) {
			return $mode;
		}

		$valid = array( 'cards', 'tree', 'list' );
		$asked = isset( $_GET['view_mode'] ) ? sanitize_key( $_GET['view_mode'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		if ( in_array( $asked, $valid, true ) ) {
			$mode = $asked;
			update_user_meta( get_current_user_id(), 'fw_tb_view_mode', $mode );
			return $mode;
		}

		$saved = get_user_meta( get_current_user_id(), 'fw_tb_view_mode', true );
		$mode  = in_array( $saved, $valid, true ) ? $saved : 'cards';
		return $mode;
	}

	/**
	 * Handle POST save + GET row actions before any output.
	 *
	 * @internal
	 */
	public function _maybe_handle() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		// Save (POST).
		if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['fw_theme_builder_save'] ) ) {
			check_admin_referer( self::NONCE_SAVE );
			$this->handle_save();
			return;
		}

		// Bulk actions (POST from the List view).
		if ( $_SERVER['REQUEST_METHOD'] === 'POST' && ! empty( $_POST['fw_tb_bulk'] ) ) {
			check_admin_referer( self::NONCE_BULK );
			$this->handle_bulk();
			return;
		}

		// Row actions (GET with nonce).
		$action = isset( $_GET['fw_tb_action'] ) ? sanitize_key( $_GET['fw_tb_action'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $action ) {
			return;
		}
		check_admin_referer( self::NONCE_ROW );
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

		if ( $action === 'delete' && $id ) {
			wp_trash_post( $id );
			$this->redirect_list( 'deleted' );
		} elseif ( $action === 'duplicate' && $id ) {
			$this->duplicate( $id );
			$this->redirect_list( 'duplicated' );
		} elseif ( $action === 'seed_default' ) {
			$this->seed_default_template();
			$this->redirect_list( 'seeded' );
		} elseif ( $action === 'import_seeds' && class_exists( 'FW_Theme_Builder_Seeder' ) ) {
			// Manual-edit guard ON: edited parts/templates are never overwritten.
			FW_Theme_Builder_Seeder::seed_all( false );
			$this->redirect_list( 'imported' );
		} elseif ( $action === 'export' && $id ) {
			$this->export_download( $id ); // streams a download + exit(); falls through if invalid
		}
	}

	private function handle_save() {
		$id     = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$values = fw_get_options_values_from_input( $this->form_options() );

		$name = trim( (string) fw_akg( 'name', $values, '' ) );
		if ( $name === '' ) {
			$name = __( 'Untitled Template', 'fw' );
		}

		$postarr = array(
			'post_type'   => 'up_template',
			'post_status' => 'publish',
			'post_title'  => $name,
		);
		if ( $id && get_post_type( $id ) === 'up_template' ) {
			$postarr['ID'] = $id;
			wp_update_post( $postarr );
		} else {
			$id = wp_insert_post( $postarr );
		}

		if ( $id && ! is_wp_error( $id ) ) {
			fw_set_db_post_option( $id, 'tb_header_id', (int) fw_akg( 'tb_header_id', $values, 0 ) );
			fw_set_db_post_option( $id, 'tb_body_id', (int) fw_akg( 'tb_body_id', $values, 0 ) );
			fw_set_db_post_option( $id, 'tb_footer_id', (int) fw_akg( 'tb_footer_id', $values, 0 ) );
			// Conditions come from the row editor as JSON, not from the options form —
			// rows_to_conditions() re-derives every field from the rule vocabulary, so a
			// forged payload cannot smuggle in a type or qualifier we do not offer.
			$rows = json_decode( isset( $_POST['fw_tb_conditions'] ) ? (string) wp_unslash( $_POST['fw_tb_conditions'] ) : '[]', true );
			$rel  = isset( $_POST['fw_tb_relation'] ) ? sanitize_key( $_POST['fw_tb_relation'] ) : 'or';
			fw_set_db_post_option( $id, 'tb_conditions', FW_Theme_Builder_Conditions::rows_to_conditions( is_array( $rows ) ? $rows : array(), $rel ) );

			// Status + tie-break priority (both outside the options form, like the rows).
			fw_set_db_post_option( $id, 'tb_disabled', empty( $_POST['fw_tb_enabled'] ) ? 1 : 0 );
			$priority = isset( $_POST['fw_tb_priority'] ) ? (int) $_POST['fw_tb_priority'] : 0;
			fw_set_db_post_option( $id, 'tb_priority', max( -100, min( 100, $priority ) ) );
		}

		$this->redirect_list( 'saved' );
	}

	/**
	 * Apply a bulk action to the checked Templates. Every id is re-checked against
	 * the Template post type, so a forged payload cannot trash arbitrary posts.
	 */
	private function handle_bulk() {
		$action = sanitize_key( wp_unslash( $_POST['fw_tb_bulk'] ) );
		$ids    = isset( $_POST['templates'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['templates'] ) ) : array();

		if ( ! in_array( $action, array( 'enable', 'disable', 'delete' ), true ) || ! $ids ) {
			$this->redirect_list( '' );
		}

		$done = 0;
		foreach ( $ids as $id ) {
			if ( $id <= 0 || get_post_type( $id ) !== 'up_template' ) {
				continue; // not ours — ignore rather than act on it
			}
			if ( 'delete' === $action ) {
				wp_trash_post( $id );
			} else {
				fw_set_db_post_option( $id, 'tb_disabled', ( 'disable' === $action ) ? 1 : 0 );
			}
			$done++;
		}

		$this->redirect_list( 'bulk_' . $action, $done );
	}

	private function duplicate( $id ) {
		$src = get_post( $id );
		if ( ! $src || $src->post_type !== 'up_template' ) {
			return;
		}
		$new_id = wp_insert_post( array(
			'post_type'   => 'up_template',
			'post_status' => 'publish',
			'post_title'  => sprintf( __( '%s (copy)', 'fw' ), $src->post_title ),
		) );
		if ( $new_id && ! is_wp_error( $new_id ) ) {
			foreach ( array( 'tb_header_id', 'tb_body_id', 'tb_footer_id', 'tb_conditions', 'tb_disabled', 'tb_priority' ) as $k ) {
				fw_set_db_post_option( $new_id, $k, fw_get_db_post_option( $id, $k ) );
			}
		}
	}

	/**
	 * Stream a Template as an up-templates/<slug>.json download (the seeder's inverse).
	 * The dev drops the file into a (child) theme's up-templates/ folder to ship it.
	 * No server file is written — we only send a download — so there is no file-write
	 * attack surface. Exits on success; returns (falls through) for an invalid id.
	 */
	private function export_download( $id ) {
		if ( ! class_exists( 'FW_Theme_Builder_Seeder' ) ) {
			return;
		}
		$data = FW_Theme_Builder_Seeder::export_template( (int) $id );
		if ( ! is_array( $data ) ) {
			return;
		}
		$slug = sanitize_title( get_the_title( $id ) );
		if ( $slug === '' ) {
			$slug = 'template-' . (int) $id;
		}
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $slug . '.json"' );
		echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	private function seed_default_template() {
		$id = wp_insert_post( array(
			'post_type'   => 'up_template',
			'post_status' => 'publish',
			'post_title'  => __( 'Default Website Template', 'fw' ),
		) );
		if ( $id && ! is_wp_error( $id ) ) {
			fw_set_db_post_option( $id, 'tb_header_id', 0 );
			fw_set_db_post_option( $id, 'tb_body_id', 0 );
			fw_set_db_post_option( $id, 'tb_footer_id', 0 );
			fw_set_db_post_option( $id, 'tb_conditions', array(
				'use_on'       => array( array( 'type' => 'df', 'sub_type' => '', 'ids' => array() ) ),
				'exclude_from' => array(),
			) );
		}
	}

	private function redirect_list( $notice, $count = 0 ) {
		$args = array( 'page' => self::MENU_SLUG );
		if ( $notice ) {
			$args['fw_tb_notice'] = $notice;
		}
		if ( $count ) {
			$args['fw_tb_n'] = (int) $count;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Option schema (parts + conditions)                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * id => title choices for a part CPT, with a leading inherit/none option.
	 *
	 * @param string $cpt
	 * @param string $zero_label
	 * @return array
	 */
	private function part_choices( $cpt, $zero_label ) {
		$choices = array( '0' => $zero_label );
		$posts   = get_posts( array(
			'post_type'        => $cpt,
			'post_status'      => 'publish',
			'numberposts'      => -1,
			'orderby'          => 'title',
			'order'            => 'ASC',
			'suppress_filters' => false,
		) );
		foreach ( $posts as $p ) {
			$choices[ (string) $p->ID ] = ( $p->post_title !== '' ) ? $p->post_title : sprintf( __( '(no title) #%d', 'fw' ), $p->ID );
		}
		return $choices;
	}

	/**
	 * The full Add/Edit options schema (box -> group per the house style).
	 *
	 * @return array
	 */
	public function form_options() {
		$options = array(
			'parts_box' => array(
				'title'   => __( 'Step 1 — What to show', 'fw' ),
				'type'    => 'box',
				'options' => array(
					'group_parts' => array(
						'type'    => 'group',
						'options' => array(
							'name' => array(
								'label' => __( 'Name', 'fw' ),
								'type'  => 'text',
								'value' => '',
								'desc'  => __( 'A label just for you — e.g. “Shop pages”, “Landing”, “Blog”.', 'fw' ),
							),
							'tb_header_id' => array(
								'label'   => __( 'Header', 'fw' ),
								'type'    => 'select',
								'value'   => '0',
								'choices' => $this->part_choices( 'up_header', __( '— Inherit (use the normal site header) —', 'fw' ) ),
								'desc'    => __( 'Pick a header design, or click ＋ New to make one. “Inherit” keeps your normal site header.', 'fw' ),
							),
							'tb_body_id' => array(
								'label'   => __( 'Body', 'fw' ),
								'type'    => 'select',
								'value'   => '0',
								'choices' => $this->part_choices( 'up_body', __( '— None (keep the normal page content) —', 'fw' ) ),
								'desc'    => __( 'Replaces the page’s main content. Pick a body design or click ＋ New. “None” keeps the normal content.', 'fw' ),
							),
							'tb_footer_id' => array(
								'label'   => __( 'Footer', 'fw' ),
								'type'    => 'select',
								'value'   => '0',
								'choices' => $this->part_choices( 'up_footer', __( '— Inherit (use the normal site footer) —', 'fw' ) ),
								'desc'    => __( 'Pick a footer design, or click ＋ New to make one. “Inherit” keeps your normal site footer.', 'fw' ),
							),
						),
					),
				),
			),
		);

		return self::without_dynamic_content( $options );
	}

	/**
	 * Recursively force `dynamic_content => false` on every option in a schema, so
	 * no Theme-Builder Template field shows the Dynamic Content picker (the
	 * dashicons-database trigger). A Template is global — its Name and its where /
	 * where-not conditions are not post-contextual — so dynamic {{tokens}} have no
	 * meaning here and the picker is only noise. Containers (box/group/…) recurse
	 * through their nested `options`; leaves (anything with a `type`) get the flag.
	 *
	 * @param array $options
	 * @return array
	 */
	private static function without_dynamic_content( array $options ) {
		foreach ( $options as $id => $opt ) {
			if ( ! is_array( $opt ) ) {
				continue;
			}
			if ( isset( $opt['type'] ) ) {
				$opt['dynamic_content'] = false;
			}
			if ( isset( $opt['options'] ) && is_array( $opt['options'] ) ) {
				$opt['options'] = self::without_dynamic_content( $opt['options'] );
			}
			$options[ $id ] = $opt;
		}
		return $options;
	}

	/**
	 * Assemble current values for the edit form from an existing Template.
	 *
	 * @param int $id
	 * @return array
	 */
	private function form_values( $id ) {
		if ( ! $id || get_post_type( $id ) !== 'up_template' ) {
			return array();
		}
		// Conditions are NOT part of this options form any more — the row editor
		// renders them separately (see render_conditions_editor).
		return array(
			'name'         => get_the_title( $id ),
			'tb_header_id' => (string) (int) fw_get_db_post_option( $id, 'tb_header_id' ),
			'tb_body_id'   => (string) (int) fw_get_db_post_option( $id, 'tb_body_id' ),
			'tb_footer_id' => (string) (int) fw_get_db_post_option( $id, 'tb_footer_id' ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Render                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * @internal
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'fw' ) );
		}
		echo '<div class="wrap fw-tb-wrap">';
		if ( $this->current_view() === 'edit' ) {
			$this->render_edit();
		} elseif ( $this->current_mode() === 'cards' ) {
			$this->render_cards();
		} elseif ( $this->current_mode() === 'tree' ) {
			$this->render_tree();
		} else {
			$this->render_list();
		}
		echo '</div>';
	}

	private function notice_text( $key ) {
		$count = isset( $_GET['fw_tb_n'] ) ? max( 1, (int) $_GET['fw_tb_n'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification

		$bulk = array(
			/* translators: %d = number of Templates */
			'bulk_enable'  => _n( '%d Template enabled.', '%d Templates enabled.', $count, 'fw' ),
			'bulk_disable' => _n( '%d Template disabled.', '%d Templates disabled.', $count, 'fw' ),
			'bulk_delete'  => _n( '%d Template deleted.', '%d Templates deleted.', $count, 'fw' ),
		);
		if ( isset( $bulk[ $key ] ) ) {
			return sprintf( $bulk[ $key ], $count );
		}

		$map = array(
			'saved'      => __( 'Template saved.', 'fw' ),
			'deleted'    => __( 'Template deleted.', 'fw' ),
			'duplicated' => __( 'Template duplicated.', 'fw' ),
			'seeded'     => __( 'Default Website Template created.', 'fw' ),
			'imported'   => __( 'Bundled templates imported. Edited templates were left untouched.', 'fw' ),
		);
		return isset( $map[ $key ] ) ? $map[ $key ] : '';
	}

	private function add_url( $extra = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::MENU_SLUG, 'view' => 'edit' ), $extra ), admin_url( 'admin.php' ) );
	}

	private function row_action_url( $action, $id ) {
		return wp_nonce_url(
			add_query_arg( array( 'page' => self::MENU_SLUG, 'fw_tb_action' => $action, 'id' => $id ), admin_url( 'admin.php' ) ),
			self::NONCE_ROW
		);
	}

	/**
	 * Shared chrome for both Templates views: title, the page-title actions, the
	 * Canvas/List switch, the notice and the intro line. Both render_cards() and
	 * render_list() open with this so the two views are interchangeable.
	 */
	private function render_screen_header() {
		$notice = isset( $_GET['fw_tb_notice'] ) ? sanitize_key( $_GET['fw_tb_notice'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$mode   = $this->current_mode();
		$modes  = array(
			'cards' => __( 'Canvas', 'fw' ),
			'tree'  => __( 'Tree', 'fw' ),
			'list'  => __( 'List', 'fw' ),
		);
		?>
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Theme Builder', 'fw' ); ?></h1>
		<a href="<?php echo esc_url( $this->add_url() ); ?>" class="page-title-action"><?php esc_html_e( 'Add Template', 'fw' ); ?></a>
		<a href="#" class="page-title-action fw-tb-import-design" title="<?php esc_attr_e( 'Import a design exported with the row Export action (Template + its header/body/footer)', 'fw' ); ?>"><?php esc_html_e( 'Import Design', 'fw' ); ?></a>
		<?php if ( class_exists( 'FW_Theme_Builder_Seeder' ) && FW_Theme_Builder_Seeder::has_seeds() ) : ?>
			<a href="<?php echo esc_url( $this->row_action_url( 'import_seeds', 0 ) ); ?>" class="page-title-action"><?php esc_html_e( 'Import bundled templates', 'fw' ); ?></a>
		<?php endif; ?>

		<span class="fw-tb-viewswitch" role="group" aria-label="<?php esc_attr_e( 'View', 'fw' ); ?>">
			<?php foreach ( $modes as $key => $label ) :
				$url = add_query_arg( array( 'page' => self::MENU_SLUG, 'view_mode' => $key ), admin_url( 'admin.php' ) );
				?>
				<a href="<?php echo esc_url( $url ); ?>"
				   class="fw-tb-viewswitch__btn<?php echo ( $mode === $key ) ? ' is-active' : ''; ?>"
				   <?php echo ( $mode === $key ) ? 'aria-current="true"' : ''; ?>><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</span>

		<?php if ( $mode === 'cards' ) :
			$pv  = $this->previews_enabled();
			$url = add_query_arg(
				array( 'page' => self::MENU_SLUG, 'view_mode' => 'cards', 'previews' => $pv ? 'off' : 'on' ),
				admin_url( 'admin.php' )
			);
			?>
			<a class="fw-tb-previews-toggle<?php echo $pv ? ' is-on' : ''; ?>" href="<?php echo esc_url( $url ); ?>">
				<span class="dashicons <?php echo $pv ? 'dashicons-visibility' : 'dashicons-hidden'; ?>" aria-hidden="true"></span>
				<?php echo $pv ? esc_html__( 'Previews on', 'fw' ) : esc_html__( 'Previews off', 'fw' ); ?>
			</a>
		<?php endif; ?>

		<hr class="wp-header-end">

		<?php if ( $notice && ( $msg = $this->notice_text( $notice ) ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div>
		<?php endif; ?>

		<p class="description"><?php esc_html_e( 'Build global Headers, Bodies and Footers, bundle them into a Template, and assign each Template to parts of your site. When no Template matches, your Theme Settings header/footer is used.', 'fw' ); ?></p>
		<?php
	}

	/**
	 * The zero-state, shared by both views.
	 */
	private function render_empty_state() {
		?>
		<div class="fw-tb-empty">
			<p><?php esc_html_e( 'No Templates yet.', 'fw' ); ?></p>
			<p>
				<a href="<?php echo esc_url( $this->add_url() ); ?>" class="button button-primary"><?php esc_html_e( 'Add your first Template', 'fw' ); ?></a>
				<a href="<?php echo esc_url( $this->row_action_url( 'seed_default', 0 ) ); ?>" class="button"><?php esc_html_e( 'Create a Default Website Template', 'fw' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Every published Template, ordered the way the CANVAS wants them: the site-wide
	 * "Default" Templates first, then most-specific first, newest breaking ties — the
	 * same precedence the resolver applies at render time, so reading the grid top to
	 * bottom tells you which Template beats which.
	 *
	 * @return WP_Post[]
	 */
	private function get_templates_ranked( $search = '' ) {
		// Still unbounded on purpose: the ranking below is a whole-set operation (a
		// Template's position depends on every other Template), so it cannot be done
		// a page at a time. What the List view paginates is the RENDERING, which is
		// what actually falls over on a big site. A search narrows the set in SQL
		// before any of this runs.
		$templates = get_posts( array(
			'post_type'        => 'up_template',
			'post_status'      => 'publish',
			'numberposts'      => -1,
			'orderby'          => 'date',
			'order'            => 'DESC', // newest first — preserved as the tie-break
			's'                => $search,
			'suppress_filters' => false,
		) );

		$rank = array();
		foreach ( $templates as $i => $tpl ) {
			$cond = fw_get_db_post_option( $tpl->ID, 'tb_conditions' );
			$cond = is_array( $cond ) ? $cond : array();
			$rank[ $tpl->ID ] = array(
				'enabled'  => FW_Theme_Builder_Resolver::is_enabled( $tpl->ID ) ? 1 : 0,
				'default'  => FW_Theme_Builder_Conditions::is_default( $cond ) ? 1 : 0,
				'weight'   => FW_Theme_Builder_Conditions::static_weight( isset( $cond['use_on'] ) ? $cond['use_on'] : array() ),
				'priority' => FW_Theme_Builder_Resolver::priority_of( $tpl->ID ),
				'order'    => $i, // the date DESC position, so usort stays deterministic
			);
		}

		usort( $templates, function ( $a, $b ) use ( $rank ) {
			$ra = $rank[ $a->ID ];
			$rb = $rank[ $b->ID ];
			if ( $ra['enabled'] !== $rb['enabled'] ) {
				return $rb['enabled'] - $ra['enabled']; // switched-off Templates sink
			}
			if ( $ra['default'] !== $rb['default'] ) {
				return $rb['default'] - $ra['default']; // site-wide Templates pinned first
			}
			if ( $ra['weight'] !== $rb['weight'] ) {
				return $rb['weight'] - $ra['weight']; // then most specific
			}
			if ( $ra['priority'] !== $rb['priority'] ) {
				return $rb['priority'] - $ra['priority']; // then the author's tie-break
			}
			return $ra['order'] - $rb['order'];
		} );

		return $templates;
	}

	private function render_list() {
		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$paged  = isset( $_REQUEST['paged'] ) ? max( 1, (int) $_REQUEST['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification

		$all   = $this->get_templates_ranked( $search );
		$total = count( $all );
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged = min( $paged, $pages );

		// Rank the whole set, then render only this page of it.
		$templates = array_slice( $all, ( $paged - 1 ) * self::PER_PAGE, self::PER_PAGE );

		$this->render_screen_header();

		if ( empty( $templates ) && '' === $search ) {
			$this->render_empty_state();
			return;
		}

		$base = add_query_arg(
			array_filter( array(
				'page'      => self::MENU_SLUG,
				'view_mode' => 'list',
				's'         => $search !== '' ? $search : null,
			) ),
			admin_url( 'admin.php' )
		);
		?>
		<form method="post" action="<?php echo esc_url( $base ); ?>">
		<?php wp_nonce_field( self::NONCE_BULK ); ?>

		<p class="search-box">
			<label class="screen-reader-text" for="fw-tb-search"><?php esc_html_e( 'Search Templates', 'fw' ); ?></label>
			<input type="search" id="fw-tb-search" name="s" value="<?php echo esc_attr( $search ); ?>">
			<button type="submit" class="button"><?php esc_html_e( 'Search Templates', 'fw' ); ?></button>
		</p>

		<div class="tablenav top">
			<div class="alignleft actions bulkactions">
				<label class="screen-reader-text" for="fw-tb-bulk"><?php esc_html_e( 'Bulk actions', 'fw' ); ?></label>
				<select name="fw_tb_bulk" id="fw-tb-bulk">
					<option value=""><?php esc_html_e( 'Bulk actions', 'fw' ); ?></option>
					<option value="enable"><?php esc_html_e( 'Enable', 'fw' ); ?></option>
					<option value="disable"><?php esc_html_e( 'Disable', 'fw' ); ?></option>
					<option value="delete"><?php esc_html_e( 'Delete', 'fw' ); ?></option>
				</select>
				<button type="submit" class="button action"><?php esc_html_e( 'Apply', 'fw' ); ?></button>
			</div>
			<?php $this->render_pagination( $total, $pages, $paged, $base ); ?>
			<br class="clear">
		</div>

		<?php if ( '' !== $search ) : ?>
			<p class="fw-tb-searchnote description">
				<?php echo esc_html( sprintf(
					/* translators: 1: number of results, 2: search term */
					_n( '%1$d Template matching “%2$s”.', '%1$d Templates matching “%2$s”.', $total, 'fw' ),
					$total,
					$search
				) ); ?>
				<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::MENU_SLUG, 'view_mode' => 'list' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Clear', 'fw' ); ?></a>
			</p>
		<?php endif; ?>

		<table class="wp-list-table widefat fixed striped fw-tb-table">
			<thead>
				<tr>
					<td id="cb" class="manage-column column-cb check-column">
						<label class="screen-reader-text" for="cb-select-all-1"><?php esc_html_e( 'Select all', 'fw' ); ?></label>
						<input id="cb-select-all-1" type="checkbox">
					</td>
					<th class="column-primary"><?php esc_html_e( 'Name', 'fw' ); ?></th>
					<th><?php esc_html_e( 'Header', 'fw' ); ?></th>
					<th><?php esc_html_e( 'Body', 'fw' ); ?></th>
					<th><?php esc_html_e( 'Footer', 'fw' ); ?></th>
					<th><?php esc_html_e( 'Used On', 'fw' ); ?></th>
					<th><?php esc_html_e( 'Status', 'fw' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $templates as $tpl ) :
					$edit = $this->add_url( array( 'id' => $tpl->ID ) );
					$conditions = fw_get_db_post_option( $tpl->ID, 'tb_conditions' );
					$conditions = is_array( $conditions ) ? $conditions : array();
					?>
					<tr>
						<th scope="row" class="check-column">
							<label class="screen-reader-text" for="cb-select-<?php echo (int) $tpl->ID; ?>">
								<?php echo esc_html( sprintf( __( 'Select %s', 'fw' ), $tpl->post_title ) ); ?>
							</label>
							<input id="cb-select-<?php echo (int) $tpl->ID; ?>" type="checkbox" name="templates[]" value="<?php echo (int) $tpl->ID; ?>">
						</th>
						<td class="column-primary" data-colname="<?php esc_attr_e( 'Name', 'fw' ); ?>">
							<strong><a href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( $tpl->post_title !== '' ? $tpl->post_title : __( '(no title)', 'fw' ) ); ?></a></strong>
							<div class="row-actions">
								<span class="edit"><a href="<?php echo esc_url( $edit ); ?>"><?php esc_html_e( 'Edit', 'fw' ); ?></a> | </span><?php if ( function_exists( 'fw_tb_preview_url' ) ) : ?><span class="fw-tb-preview-act"><a href="<?php echo esc_url( fw_tb_preview_url( array( 'template' => $tpl->ID ) ) ); ?>" target="_blank" rel="noopener noreferrer" title="<?php esc_attr_e( 'Preview this Template on the front page', 'fw' ); ?>"><?php esc_html_e( 'Preview', 'fw' ); ?></a> | </span><?php endif; ?>
								<span class="duplicate"><a href="<?php echo esc_url( $this->row_action_url( 'duplicate', $tpl->ID ) ); ?>"><?php esc_html_e( 'Duplicate', 'fw' ); ?></a> | </span>
								<span class="export"><a href="<?php echo esc_url( $this->row_action_url( 'export', $tpl->ID ) ); ?>" title="<?php esc_attr_e( 'Download as up-templates/*.json to ship in a theme', 'fw' ); ?>"><?php esc_html_e( 'Export', 'fw' ); ?></a> | </span>
								<span class="trash"><a href="<?php echo esc_url( $this->row_action_url( 'delete', $tpl->ID ) ); ?>" class="fw-tb-delete submitdelete"><?php esc_html_e( 'Delete', 'fw' ); ?></a></span>
							</div>
							<button type="button" class="toggle-row"><span class="screen-reader-text"><?php esc_html_e( 'Show more details', 'fw' ); ?></span></button>
						</td>
						<td data-colname="<?php esc_attr_e( 'Header', 'fw' ); ?>"><?php echo $this->part_label( $tpl->ID, 'tb_header_id', __( 'Inherit', 'fw' ) ); ?></td>
						<td data-colname="<?php esc_attr_e( 'Body', 'fw' ); ?>"><?php echo $this->part_label( $tpl->ID, 'tb_body_id', __( 'None', 'fw' ) ); ?></td>
						<td data-colname="<?php esc_attr_e( 'Footer', 'fw' ); ?>"><?php echo $this->part_label( $tpl->ID, 'tb_footer_id', __( 'Inherit', 'fw' ) ); ?></td>
						<td data-colname="<?php esc_attr_e( 'Used On', 'fw' ); ?>"><?php echo FW_Theme_Builder_Conditions::summarize( $conditions ); // already escaped ?></td>
						<td data-colname="<?php esc_attr_e( 'Status', 'fw' ); ?>"><?php
							if ( FW_Theme_Builder_Resolver::is_enabled( $tpl->ID ) ) {
								$p = FW_Theme_Builder_Resolver::priority_of( $tpl->ID );
								echo esc_html__( 'Enabled', 'fw' );
								if ( $p ) {
									echo ' <span class="fw-tb-muted">' . esc_html( sprintf( __( '(priority %d)', 'fw' ), $p ) ) . '</span>';
								}
							} else {
								echo '<span class="fw-tb-muted">' . esc_html__( 'Disabled', 'fw' ) . '</span>';
							}
						?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<div class="tablenav bottom">
			<?php $this->render_pagination( $total, $pages, $paged, $base ); ?>
			<br class="clear">
		</div>
		</form>
		<?php
	}

	/**
	 * The WP-standard count + page links, shown above and below the table.
	 *
	 * @param int    $total
	 * @param int    $pages
	 * @param int    $paged
	 * @param string $base URL carrying the current search
	 */
	private function render_pagination( $total, $pages, $paged, $base ) {
		?>
		<div class="tablenav-pages<?php echo ( $pages < 2 ) ? ' one-page' : ''; ?>">
			<span class="displaying-num"><?php
				echo esc_html( sprintf(
					/* translators: %s = number of Templates */
					_n( '%s item', '%s items', $total, 'fw' ),
					number_format_i18n( $total )
				) );
			?></span>
			<?php if ( $pages > 1 ) : ?>
				<span class="pagination-links"><?php
					echo wp_kses_post( paginate_links( array(
						// NOT add_query_arg(): it percent-encodes the '#' in paginate_links'
						// own %#% placeholder, which yields dead page links.
						'base'      => $base . '%_%',
						'format'    => ( false === strpos( $base, '?' ) ? '?' : '&' ) . 'paged=%#%',
						'prev_text' => '&laquo;',
						'next_text' => '&raquo;',
						'total'     => $pages,
						'current'   => $paged,
						'type'      => 'plain',
					) ) );
				?></span>
			<?php endif; ?>
		</div>
		<?php
	}

	private function part_label( $tpl_id, $meta_key, $zero_label ) {
		$pid = (int) fw_get_db_post_option( $tpl_id, $meta_key );
		if ( $pid <= 0 ) {
			return '<span class="fw-tb-muted">' . esc_html( $zero_label ) . '</span>';
		}
		$title = get_the_title( $pid );
		if ( $title === '' || get_post_status( $pid ) === false ) {
			return '<span class="fw-tb-muted">' . esc_html__( '(missing)', 'fw' ) . '</span>';
		}
		return esc_html( $title );
	}

	/**
	 * Step 2 — the condition rows. A shell the script fills in: the rows live as
	 * JSON in a hidden field (written on every change), so one row = one rule with
	 * no translation layer, and Include / Exclude is a per-row choice instead of two
	 * duplicated halves of a form.
	 *
	 * @param int $id Template id (0 for a new one)
	 */
	private function render_conditions_editor( $id ) {
		$conditions = $id ? fw_get_db_post_option( $id, 'tb_conditions' ) : array();
		$conditions = is_array( $conditions ) ? $conditions : array();
		$relation   = FW_Theme_Builder_Conditions::relation_of( $conditions );
		$rows       = FW_Theme_Builder_Conditions::conditions_to_rows( $conditions );

		// Arrived from the Tree view's "Add a Template here": open with that node's
		// condition already in place. Only for a NEW Template, and only for a node key
		// the vocabulary actually recognises.
		if ( ! $id && ! $rows && isset( $_GET['prefill'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$row = FW_Theme_Builder_Conditions::row_from_node_key( sanitize_text_field( wp_unslash( $_GET['prefill'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
			if ( $row ) {
				$rows[] = $row;
			}
		}
		?>
		<div class="fw-tb-cond postbox">
			<div class="postbox-header">
				<h2 class="hndle"><?php esc_html_e( 'Step 2 — Where to show it', 'fw' ); ?></h2>
			</div>
			<div class="inside">
				<p class="fw-tb-cond__intro">
					<?php esc_html_e( 'One row per condition. Include says where the Template applies; Exclude carves out exceptions, and an exception always wins.', 'fw' ); ?>
				</p>

				<div class="fw-tb-cond__rows"
				     data-rows="<?php echo esc_attr( wp_json_encode( $rows ) ); ?>"></div>

				<div class="fw-tb-cond__foot">
					<button type="button" class="button fw-tb-cond__add">
						<span aria-hidden="true">＋</span> <?php esc_html_e( 'Add Condition', 'fw' ); ?>
					</button>
					<span class="fw-tb-cond__relation">
						<label for="fw-tb-relation"><?php esc_html_e( 'Relation Type', 'fw' ); ?></label>
						<select id="fw-tb-relation" name="fw_tb_relation">
							<option value="or" <?php selected( $relation, 'or' ); ?>><?php esc_html_e( 'OR — any Include row matches', 'fw' ); ?></option>
							<option value="and" <?php selected( $relation, 'and' ); ?>><?php esc_html_e( 'AND — every Include row must match', 'fw' ); ?></option>
						</select>
					</span>
				</div>

				<p class="fw-tb-cond__note description">
					<?php esc_html_e( 'Relation Type applies to the Include rows. Exclude rows always apply on their own — if any one of them matches, the Template is skipped.', 'fw' ); ?>
				</p>

				<input type="hidden" name="fw_tb_conditions" class="fw-tb-cond__json" value="">
			</div>
		</div>
		<?php
	}

	/**
	 * Status + Priority. Kept out of the options form (like the condition rows) so
	 * it can sit AFTER Step 2 and post plain fields.
	 *
	 * @param int $id Template id (0 for a new one)
	 */
	private function render_status_box( $id ) {
		$enabled  = $id ? FW_Theme_Builder_Resolver::is_enabled( $id ) : true;
		$priority = $id ? FW_Theme_Builder_Resolver::priority_of( $id ) : 0;
		?>
		<div class="fw-tb-status postbox">
			<div class="postbox-header">
				<h2 class="hndle"><?php esc_html_e( 'Status & priority', 'fw' ); ?></h2>
			</div>
			<div class="inside">
				<p class="fw-tb-status__row">
					<label>
						<input type="checkbox" name="fw_tb_enabled" value="1" <?php checked( $enabled ); ?>>
						<strong><?php esc_html_e( 'Enabled', 'fw' ); ?></strong>
					</label>
					<span class="description"><?php esc_html_e( 'Switch a Template off to stop it applying without deleting it or losing its designs.', 'fw' ); ?></span>
				</p>
				<p class="fw-tb-status__row">
					<label for="fw-tb-priority"><strong><?php esc_html_e( 'Priority', 'fw' ); ?></strong></label>
					<input type="number" id="fw-tb-priority" name="fw_tb_priority" class="small-text"
					       value="<?php echo esc_attr( $priority ); ?>" min="-100" max="100" step="1">
					<span class="description"><?php esc_html_e( 'Only settles ties. When two Templates match a page equally specifically, the higher Priority wins (otherwise the newest does). It never lets a broad Template beat a more specific one.', 'fw' ); ?></span>
				</p>
			</div>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Tree (the template hierarchy)                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * The Tree view: this site's own template hierarchy, with each Template hung
	 * off the node its most specific Use On rule targets.
	 *
	 * The canvas answers "what do I have?"; this answers the two questions a grid
	 * cannot — which Template wins for a given kind of request (depth = specificity,
	 * so deeper beats shallower), and WHERE THE GAPS ARE, because a node with no
	 * Template attached is visible as such.
	 */
	private function render_tree() {
		$templates = $this->get_templates_ranked();
		$this->render_screen_header();

		// Bucket every Template by the node its most specific rule targets. Anything
		// with no usable Use On rule is listed separately rather than dropped.
		$by_node   = array();
		$unplaced  = array();
		foreach ( $templates as $tpl ) {
			$cond = fw_get_db_post_option( $tpl->ID, 'tb_conditions' );
			$cond = is_array( $cond ) ? $cond : array();
			$key  = FW_Theme_Builder_Conditions::best_node_key( $cond );
			if ( null === $key ) {
				$unplaced[] = $tpl;
			} else {
				$by_node[ $key ][] = $tpl;
			}
		}
		?>
		<div class="fw-tb-tree">
			<p class="fw-tb-tree__legend">
				<?php esc_html_e( 'Your site\'s template hierarchy. A Template is listed against the most specific thing it targets, and deeper always beats shallower — so anything further down this tree overrides what is above it.', 'fw' ); ?>
			</p>

			<ul class="fw-tb-tree__root">
				<?php foreach ( FW_Theme_Builder_Conditions::hierarchy() as $node ) {
					$this->render_tree_node( $node, $by_node );
				} ?>
			</ul>

			<?php if ( $unplaced ) : ?>
				<div class="fw-tb-tree__unplaced">
					<h2><?php esc_html_e( 'Not assigned anywhere', 'fw' ); ?></h2>
					<p class="description"><?php esc_html_e( 'These Templates have no usable Use On condition, so they never render. Open one and add a condition in Step 2.', 'fw' ); ?></p>
					<ul>
						<?php foreach ( $unplaced as $tpl ) : ?>
							<li><?php $this->render_tree_chip( $tpl ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * One hierarchy node plus its descendants.
	 *
	 * @param array $node    from FW_Theme_Builder_Conditions::hierarchy()
	 * @param array $by_node node key => WP_Post[]
	 */
	private function render_tree_node( $node, $by_node ) {
		$key      = $node['key'];
		$is_group = ( 0 === strpos( $key, '__' ) ); // structural — nothing targets it
		$attached = isset( $by_node[ $key ] ) ? $by_node[ $key ] : array();

		$classes = 'fw-tb-tree__node';
		if ( $is_group ) {
			$classes .= ' is-group';
		} elseif ( $attached ) {
			$classes .= ' is-covered';
		} else {
			$classes .= ' is-gap';
		}
		?>
		<li class="<?php echo esc_attr( $classes ); ?>">
			<div class="fw-tb-tree__row">
				<span class="fw-tb-tree__label"><?php echo esc_html( $node['label'] ); ?></span>
				<?php if ( ! $is_group ) : ?>
					<span class="fw-tb-tree__templates">
						<?php if ( $attached ) : ?>
							<?php foreach ( $attached as $tpl ) {
								$this->render_tree_chip( $tpl );
							} ?>
						<?php else : ?>
							<a class="fw-tb-tree__add" href="<?php echo esc_url( $this->add_url( array( 'prefill' => $key ) ) ); ?>">
								<span aria-hidden="true">＋</span> <?php esc_html_e( 'Add a Template here', 'fw' ); ?>
							</a>
						<?php endif; ?>
					</span>
				<?php endif; ?>
			</div>

			<?php if ( ! empty( $node['children'] ) ) : ?>
				<ul>
					<?php foreach ( $node['children'] as $child ) {
						$this->render_tree_node( $child, $by_node );
					} ?>
				</ul>
			<?php endif; ?>
		</li>
		<?php
	}

	/**
	 * A Template as it appears in the tree: name, link, and whether it is switched
	 * off (an off Template still hangs where it would apply, greyed, so its slot in
	 * the hierarchy is not silently lost).
	 *
	 * @param WP_Post $tpl
	 */
	private function render_tree_chip( $tpl ) {
		$enabled = FW_Theme_Builder_Resolver::is_enabled( $tpl->ID );
		$title   = ( $tpl->post_title !== '' ) ? $tpl->post_title : __( '(no title)', 'fw' );
		?>
		<a class="fw-tb-tree__chip<?php echo $enabled ? '' : ' is-off'; ?>"
		   href="<?php echo esc_url( $this->add_url( array( 'id' => $tpl->ID ) ) ); ?>"
		   title="<?php echo esc_attr( $enabled ? $title : sprintf( __( '%s (disabled)', 'fw' ), $title ) ); ?>">
			<?php echo esc_html( $title ); ?>
			<?php if ( ! $enabled ) : ?>
				<span class="fw-tb-tree__chip-off"><?php esc_html_e( 'off', 'fw' ); ?></span>
			<?php endif; ?>
		</a>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Canvas (cards)                                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * The canvas: one card per Template, each showing its three slots (Header /
	 * Body / Footer) as individually actionable rows — assign, open in the page
	 * builder, or clear, without leaving the screen. Ordered by real precedence
	 * (see get_templates_ranked()), so the first card is the one that wins most
	 * broadly and the last is the most specific override.
	 */
	private function render_cards() {
		$templates = $this->get_templates_ranked();
		$this->render_screen_header();
		?>
		<div class="fw-tb-cards">
			<a class="fw-tb-card fw-tb-card--new" href="<?php echo esc_url( $this->add_url() ); ?>">
				<span class="fw-tb-card--new__plus" aria-hidden="true">+</span>
				<span class="fw-tb-card--new__label"><?php esc_html_e( 'Create new page template', 'fw' ); ?></span>
			</a>
			<?php foreach ( $templates as $tpl ) {
				$this->render_card( $tpl );
			} ?>
		</div>

		<?php if ( empty( $templates ) ) : ?>
			<p class="fw-tb-cards__hint">
				<?php esc_html_e( 'Nothing assigned yet. Start from scratch, or create a site-wide Template you can narrow down later.', 'fw' ); ?>
				<a href="<?php echo esc_url( $this->row_action_url( 'seed_default', 0 ) ); ?>" class="button button-small"><?php esc_html_e( 'Create a Default Website Template', 'fw' ); ?></a>
			</p>
		<?php endif;
	}

	/**
	 * One Template card: title + badge + actions menu, the three slots, and the
	 * "Used On" line the table already summarizes.
	 *
	 * @param WP_Post $tpl
	 */
	private function render_card( $tpl ) {
		$edit = $this->add_url( array( 'id' => $tpl->ID ) );
		$cond = fw_get_db_post_option( $tpl->ID, 'tb_conditions' );
		$cond = is_array( $cond ) ? $cond : array();
		$title    = ( $tpl->post_title !== '' ) ? $tpl->post_title : __( '(no title)', 'fw' );
		$enabled  = FW_Theme_Builder_Resolver::is_enabled( $tpl->ID );
		$priority = FW_Theme_Builder_Resolver::priority_of( $tpl->ID );
		?>
		<div class="fw-tb-card<?php echo $enabled ? '' : ' is-disabled'; ?>" data-template="<?php echo (int) $tpl->ID; ?>">
			<div class="fw-tb-card__head">
				<label class="fw-tb-switch" title="<?php esc_attr_e( 'Enable / disable this Template', 'fw' ); ?>">
					<input type="checkbox" class="fw-tb-switch__input" <?php checked( $enabled ); ?>>
					<span class="fw-tb-switch__track" aria-hidden="true"></span>
					<span class="screen-reader-text"><?php esc_html_e( 'Enabled', 'fw' ); ?></span>
				</label>
				<?php if ( FW_Theme_Builder_Conditions::is_default( $cond ) ) : ?>
					<span class="fw-tb-card__badge fw-tb-card__badge--default"><?php esc_html_e( 'Default', 'fw' ); ?></span>
				<?php endif; ?>
				<?php if ( $priority ) : ?>
					<span class="fw-tb-card__badge fw-tb-card__badge--priority" title="<?php esc_attr_e( 'Tie-break priority', 'fw' ); ?>"><?php echo esc_html( sprintf( __( 'P%d', 'fw' ), $priority ) ); ?></span>
				<?php endif; ?>
				<h2 class="fw-tb-card__title"><a href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( $title ); ?></a></h2>
				<div class="fw-tb-card__menu">
					<button type="button" class="fw-tb-menu-toggle" aria-expanded="false" aria-haspopup="true">
						<span class="screen-reader-text"><?php esc_html_e( 'Template actions', 'fw' ); ?></span>
						<span class="dashicons dashicons-ellipsis" aria-hidden="true"></span>
					</button>
					<ul class="fw-tb-menu" hidden>
						<li><a href="<?php echo esc_url( $edit ); ?>"><?php esc_html_e( 'Edit conditions', 'fw' ); ?></a></li>
						<?php if ( function_exists( 'fw_tb_preview_url' ) ) : ?>
							<li><a href="<?php echo esc_url( fw_tb_preview_url( array( 'template' => $tpl->ID ) ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Preview', 'fw' ); ?></a></li>
						<?php endif; ?>
						<li><a href="<?php echo esc_url( $this->row_action_url( 'duplicate', $tpl->ID ) ); ?>"><?php esc_html_e( 'Duplicate', 'fw' ); ?></a></li>
						<li><a href="<?php echo esc_url( $this->row_action_url( 'export', $tpl->ID ) ); ?>"><?php esc_html_e( 'Export', 'fw' ); ?></a></li>
						<li><a href="<?php echo esc_url( $this->row_action_url( 'delete', $tpl->ID ) ); ?>" class="fw-tb-delete fw-tb-menu__danger"><?php esc_html_e( 'Delete', 'fw' ); ?></a></li>
					</ul>
				</div>
			</div>

			<?php if ( $this->previews_enabled() ) :
				$preview = $this->card_preview_url( $tpl->ID, $cond );
				?>
				<div class="fw-tb-card__preview<?php echo $preview ? '' : ' is-empty'; ?>"
					<?php if ( $preview ) : ?>data-src="<?php echo esc_url( $preview ); ?>"<?php endif; ?>>
					<?php if ( $preview ) : ?>
						<span class="fw-tb-card__preview-wait" aria-hidden="true"></span>
					<?php else : ?>
						<span class="fw-tb-card__preview-none"><?php esc_html_e( 'No design assigned yet', 'fw' ); ?></span>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="fw-tb-card__slots">
				<?php foreach ( $this->slot_map() as $key => $slot ) {
					$this->render_slot( $tpl->ID, $key, $slot );
				} ?>
			</div>

			<div class="fw-tb-card__cond">
				<?php if ( ! $enabled ) : ?>
					<span class="fw-tb-card__off"><?php esc_html_e( 'Disabled — not applied anywhere', 'fw' ); ?></span>
				<?php else : ?>
					<span class="fw-tb-card__cond-label"><?php esc_html_e( 'Used on', 'fw' ); ?></span>
					<?php echo FW_Theme_Builder_Conditions::summarize( $cond ); // already escaped ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * One slot row inside a card. Filled: the part name plus open-in-builder and
	 * clear actions. Empty: the dashed "override" affordance that opens the picker.
	 * A dangling id (the part was deleted, or is the wrong CPT) degrades to the empty
	 * state with a warning rather than rendering a broken row.
	 *
	 * The markup here is mirrored by admin.js when it re-draws a slot after an
	 * assign/clear — keep the two in step.
	 *
	 * @param int    $tpl_id
	 * @param string $key  tb_header_id|tb_body_id|tb_footer_id
	 * @param array  $slot the slot_map() entry
	 */
	private function render_slot( $tpl_id, $key, $slot ) {
		$pid     = (int) fw_get_db_post_option( $tpl_id, $key );
		$missing = ( $pid > 0 && ( get_post_status( $pid ) === false || get_post_type( $pid ) !== $slot['cpt'] ) );
		$filled  = ( $pid > 0 && ! $missing );
		?>
		<div class="fw-tb-slot <?php echo $filled ? 'is-filled' : 'is-empty'; ?>"
			 data-key="<?php echo esc_attr( $key ); ?>"
			 data-cpt="<?php echo esc_attr( $slot['cpt'] ); ?>"
			 data-part="<?php echo $filled ? (int) $pid : 0; ?>">
			<span class="fw-tb-slot__type"><?php echo esc_html( $slot['label'] ); ?></span>
			<div class="fw-tb-slot__body">
				<?php if ( $filled ) : ?>
					<span class="fw-tb-slot__name"><?php echo esc_html( get_the_title( $pid ) ); ?></span>
					<span class="fw-tb-slot__acts">
						<a class="fw-tb-slot__act fw-tb-slot-edit" href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $pid . '&action=edit' ) ); ?>" target="_blank" rel="noopener noreferrer" title="<?php esc_attr_e( 'Edit design', 'fw' ); ?>"><span class="dashicons dashicons-edit" aria-hidden="true"></span><span class="screen-reader-text"><?php esc_html_e( 'Edit design', 'fw' ); ?></span></a>
						<button type="button" class="fw-tb-slot__act fw-tb-slot-clear" title="<?php esc_attr_e( 'Remove', 'fw' ); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span><span class="screen-reader-text"><?php esc_html_e( 'Remove', 'fw' ); ?></span></button>
					</span>
				<?php else : ?>
					<button type="button" class="fw-tb-slot-add">
						<span aria-hidden="true">＋</span>
						<?php echo esc_html( $missing ? __( 'Design missing — pick another', 'fw' ) : $slot['empty'] ); ?>
					</button>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	private function render_edit() {
		$id     = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$values = $this->form_values( $id );
		$title  = $id ? __( 'Edit Template', 'fw' ) : __( 'Add Template', 'fw' );
		$back   = add_query_arg( array( 'page' => self::MENU_SLUG ), admin_url( 'admin.php' ) );
		$action = add_query_arg(
			array_filter( array( 'page' => self::MENU_SLUG, 'view' => 'edit', 'id' => $id ?: null ) ),
			admin_url( 'admin.php' )
		);
		?>
		<h1 class="wp-heading-inline"><?php echo esc_html( $title ); ?></h1>
		<a href="<?php echo esc_url( $back ); ?>" class="page-title-action"><?php esc_html_e( 'Back to Templates', 'fw' ); ?></a>
		<hr class="wp-header-end">

		<div class="fw-tb-intro">
			<p><strong><?php esc_html_e( 'Two simple steps:', 'fw' ); ?></strong>
			<?php esc_html_e( '1) choose which Header, Body and Footer to show, then 2) choose where on your site to show them.', 'fw' ); ?></p>
			<p><?php esc_html_e( 'No design yet? Click “＋ New” next to a dropdown to create one without leaving this page — then “Edit design” to build it in the page builder.', 'fw' ); ?></p>
		</div>

		<form method="post" action="<?php echo esc_url( $action ); ?>" class="fw-tb-form">
			<?php wp_nonce_field( self::NONCE_SAVE ); ?>
			<input type="hidden" name="fw_theme_builder_save" value="1">
			<?php echo fw()->backend->render_options( $this->form_options(), $values ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php $this->render_conditions_editor( $id ); ?>
			<?php $this->render_status_box( $id ); ?>
			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Template', 'fw' ); ?></button>
				<a href="<?php echo esc_url( $back ); ?>" class="button"><?php esc_html_e( 'Cancel', 'fw' ); ?></a>
			</p>
		</form>
		<?php
	}
}
