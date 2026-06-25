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

	/** @var FW_Extension_Theme_Builder */
	private $extension;

	/** @var string|false */
	private $hook_suffix = false;

	public function __construct( $extension ) {
		$this->extension = $extension;

		add_action( 'admin_menu', array( $this, '_action_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, '_action_enqueue' ) );

		// Inline "＋ New Header/Body/Footer" creation from the Template screen.
		add_action( 'wp_ajax_fw_tb_create_part', array( $this, '_ajax_create_part' ) );
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

	/** @return string */
	public function get_menu_slug() {
		return self::MENU_SLUG;
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
		wp_localize_script( 'fw-theme-builder-admin', 'fwThemeBuilder', array(
			'confirmDelete' => __( 'Delete this Template? Its header/body/footer designs are not deleted.', 'fw' ),
			'createNonce'   => wp_create_nonce( self::NONCE_PART ),
			'editPartBase'  => admin_url( 'post.php' ),
			// Which dropdown maps to which part CPT (drives the inline "＋ New" button).
			'parts'         => array(
				'tb_header_id' => array( 'cpt' => 'up_header', 'noun' => __( 'header', 'fw' ) ),
				'tb_body_id'   => array( 'cpt' => 'up_body',   'noun' => __( 'body', 'fw' ) ),
				'tb_footer_id' => array( 'cpt' => 'up_footer', 'noun' => __( 'footer', 'fw' ) ),
			),
			'i18n'          => array(
				'newPart'    => __( '＋ New', 'fw' ),
				'create'     => __( 'Create', 'fw' ),
				'cancel'     => __( 'Cancel', 'fw' ),
				'creating'   => __( 'Creating…', 'fw' ),
				'editDesign' => __( 'Edit design ↗', 'fw' ),
				/* translators: %s = part type, e.g. "body" */
				'namePH'     => __( 'New %s name', 'fw' ),
				'createdTip' => __( 'Created — now click “Edit design” to build it, then Save the Template.', 'fw' ),
				'error'      => __( 'Could not create. Please try again.', 'fw' ),
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
			fw_set_db_post_option( $id, 'tb_conditions', FW_Theme_Builder_Conditions::values_to_conditions( $values ) );
		}

		$this->redirect_list( 'saved' );
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
			foreach ( array( 'tb_header_id', 'tb_body_id', 'tb_footer_id', 'tb_conditions' ) as $k ) {
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

	private function redirect_list( $notice ) {
		wp_safe_redirect( add_query_arg(
			array( 'page' => self::MENU_SLUG, 'fw_tb_notice' => $notice ),
			admin_url( 'admin.php' )
		) );
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
			'use_on_box' => array(
				'title'   => __( 'Step 2 — Where to show it', 'fw' ),
				'type'    => 'box',
				'options' => array(
					'group_use_on' => array(
						'type'    => 'group',
						'options' => FW_Theme_Builder_Conditions::side_options( 'use_on' ),
					),
				),
			),
			'exclude_box' => array(
				'title'   => __( 'Exceptions — where to hide it (optional)', 'fw' ),
				'type'    => 'box',
				'options' => array(
					'group_exclude' => array(
						'type'    => 'group',
						'options' => FW_Theme_Builder_Conditions::side_options( 'exclude_from' ),
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
		$conditions = fw_get_db_post_option( $id, 'tb_conditions' );
		if ( ! is_array( $conditions ) ) {
			$conditions = array();
		}
		return array_merge(
			array(
				'name'         => get_the_title( $id ),
				'tb_header_id' => (string) (int) fw_get_db_post_option( $id, 'tb_header_id' ),
				'tb_body_id'   => (string) (int) fw_get_db_post_option( $id, 'tb_body_id' ),
				'tb_footer_id' => (string) (int) fw_get_db_post_option( $id, 'tb_footer_id' ),
			),
			FW_Theme_Builder_Conditions::conditions_to_values( $conditions )
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
		} else {
			$this->render_list();
		}
		echo '</div>';
	}

	private function notice_text( $key ) {
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

	private function render_list() {
		$notice = isset( $_GET['fw_tb_notice'] ) ? sanitize_key( $_GET['fw_tb_notice'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$templates = get_posts( array(
			'post_type'        => 'up_template',
			'post_status'      => 'publish',
			'numberposts'      => -1,
			'orderby'          => 'date',
			'order'            => 'DESC',
			'suppress_filters' => false,
		) );
		?>
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Theme Builder', 'fw' ); ?></h1>
		<a href="<?php echo esc_url( $this->add_url() ); ?>" class="page-title-action"><?php esc_html_e( 'Add Template', 'fw' ); ?></a>
		<?php if ( class_exists( 'FW_Theme_Builder_Seeder' ) && FW_Theme_Builder_Seeder::has_seeds() ) : ?>
			<a href="<?php echo esc_url( $this->row_action_url( 'import_seeds', 0 ) ); ?>" class="page-title-action"><?php esc_html_e( 'Import bundled templates', 'fw' ); ?></a>
		<?php endif; ?>
		<hr class="wp-header-end">

		<?php if ( $notice && ( $msg = $this->notice_text( $notice ) ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div>
		<?php endif; ?>

		<p class="description"><?php esc_html_e( 'Build global Headers, Bodies and Footers, bundle them into a Template, and assign each Template to parts of your site. When no Template matches, your Theme Settings header/footer is used.', 'fw' ); ?></p>

		<?php if ( empty( $templates ) ) : ?>
			<div class="fw-tb-empty">
				<p><?php esc_html_e( 'No Templates yet.', 'fw' ); ?></p>
				<p>
					<a href="<?php echo esc_url( $this->add_url() ); ?>" class="button button-primary"><?php esc_html_e( 'Add your first Template', 'fw' ); ?></a>
					<a href="<?php echo esc_url( $this->row_action_url( 'seed_default', 0 ) ); ?>" class="button"><?php esc_html_e( 'Create a Default Website Template', 'fw' ); ?></a>
				</p>
			</div>
			<?php return; ?>
		<?php endif; ?>

		<table class="wp-list-table widefat fixed striped fw-tb-table">
			<thead>
				<tr>
					<th class="column-primary"><?php esc_html_e( 'Name', 'fw' ); ?></th>
					<th><?php esc_html_e( 'Header', 'fw' ); ?></th>
					<th><?php esc_html_e( 'Body', 'fw' ); ?></th>
					<th><?php esc_html_e( 'Footer', 'fw' ); ?></th>
					<th><?php esc_html_e( 'Used On', 'fw' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $templates as $tpl ) :
					$edit = $this->add_url( array( 'id' => $tpl->ID ) );
					$conditions = fw_get_db_post_option( $tpl->ID, 'tb_conditions' );
					$conditions = is_array( $conditions ) ? $conditions : array();
					?>
					<tr>
						<td class="column-primary" data-colname="<?php esc_attr_e( 'Name', 'fw' ); ?>">
							<strong><a href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( $tpl->post_title !== '' ? $tpl->post_title : __( '(no title)', 'fw' ) ); ?></a></strong>
							<div class="row-actions">
								<span class="edit"><a href="<?php echo esc_url( $edit ); ?>"><?php esc_html_e( 'Edit', 'fw' ); ?></a> | </span>
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
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
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
			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Template', 'fw' ); ?></button>
				<a href="<?php echo esc_url( $back ); ?>" class="button"><?php esc_html_e( 'Cancel', 'fw' ); ?></a>
			</p>
		</form>
		<?php
	}
}
