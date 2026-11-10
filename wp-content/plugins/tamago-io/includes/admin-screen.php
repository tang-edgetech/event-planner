<?php
/**
 * Admin screen: menu entry (parent filterable through `tamago_menu_parent`), tabs Export ·
 * Import · History · Settings, assets. The Import tab steps live in admin-import-screen.php.
 *
 * Screens: Tools → Import & Export.
 */

defined( 'ABSPATH' ) || exit;

require_once TMG_IO_DIR . 'includes/admin-import-screen.php';

class TMG_IO_Admin_Screen {

	const SLUG = 'tamago-io';

	private static $hook = '';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function parent(): string {
		return (string) apply_filters( 'tamago_menu_parent', 'tools.php', 'io' );
	}

	/** URL of the screen with query args. Works outside the admin menu too (admin-post). */
	public static function url( array $args = array() ): string {
		$parent = self::parent();
		$base   = '.php' === substr( $parent, -4 ) && false === strpos( $parent, '?' ) ? admin_url( $parent . '?page=' . self::SLUG ) : admin_url( 'admin.php?page=' . self::SLUG );
		return add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $args ) ), $base );
	}

	public static function menu(): void {
		$cap = TMG_IO_Permissions::EXPORT;
		foreach ( TMG_IO_Permissions::caps() as $c ) {
			if ( current_user_can( $c ) ) {
				$cap = $c;
				break;
			}
		}
		self::$hook = (string) add_submenu_page( self::parent(), __( 'Import & Export', 'tamago-io' ), __( 'Import & Export', 'tamago-io' ), $cap, self::SLUG, array( __CLASS__, 'render' ) );
	}

	public static function assets( string $hook ): void {
		if ( $hook !== self::$hook ) {
			return;
		}
		wp_enqueue_style( 'tmg-io-admin', TMG_IO_URL . 'assets/css/admin.css', array(), TMG_IO_VERSION );
		wp_enqueue_script( 'tmg-io-admin', TMG_IO_URL . 'assets/js/admin.js', array(), TMG_IO_VERSION, true );
		wp_localize_script(
			'tmg-io-admin',
			'tmgIo',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'tmg_io_step' ),
				'i18n'  => array(
					'working'  => __( 'Working…', 'tamago-io' ),
					'done'     => __( 'Finished.', 'tamago-io' ),
					'error'    => __( 'The import stopped with an error:', 'tamago-io' ),
					'retry'    => __( 'Connection problem, retrying…', 'tamago-io' ),
					'resume'   => __( 'Paused. Reload this page or use History → Continue to resume.', 'tamago-io' ),
				),
			)
		);
	}

	/** Tabs the current user may open. */
	public static function tabs(): array {
		$tabs = array();
		if ( current_user_can( TMG_IO_Permissions::EXPORT ) ) {
			$tabs['export'] = __( 'Export', 'tamago-io' );
		}
		if ( current_user_can( TMG_IO_Permissions::IMPORT ) ) {
			$tabs['import'] = __( 'Import', 'tamago-io' );
		}
		if ( current_user_can( TMG_IO_Permissions::EXPORT ) || current_user_can( TMG_IO_Permissions::IMPORT ) ) {
			$tabs['history'] = __( 'History', 'tamago-io' );
		}
		if ( current_user_can( TMG_IO_Permissions::MANAGE ) ) {
			$tabs['settings'] = __( 'Settings', 'tamago-io' );
		}
		return $tabs;
	}

	public static function render(): void {
		$tabs = self::tabs();
		if ( ! $tabs ) {
			wp_die( esc_html__( 'You are not allowed to use Import & Export.', 'tamago-io' ) );
		}
		$tab = sanitize_key( wp_unslash( $_GET['tab'] ?? '' ) ); // phpcs:ignore WordPress.Security
		$tab = isset( $tabs[ $tab ] ) ? $tab : (string) array_key_first( $tabs );
		echo '<div class="wrap tmg-io">';
		echo '<h1>' . esc_html__( 'Import & Export', 'tamago-io' ) . '</h1>';
		$notice = get_transient( 'tmg_io_notice_' . get_current_user_id() );
		if ( is_array( $notice ) ) {
			delete_transient( 'tmg_io_notice_' . get_current_user_id() );
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( 'error' === $notice['type'] ? 'error' : 'success' ), esc_html( (string) $notice['msg'] ) );
		}
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $k => $label ) {
			printf( '<a class="nav-tab %s" href="%s">%s</a>', $k === $tab ? 'nav-tab-active' : '', esc_url( self::url( array( 'tab' => $k ) ) ), esc_html( $label ) );
		}
		echo '</nav>';
		switch ( $tab ) {
			case 'export':
				self::render_export();
				break;
			case 'import':
				TMG_IO_Admin_Import_Screen::render();
				break;
			case 'history':
				self::render_history();
				break;
			case 'settings':
				self::render_settings();
				break;
		}
		echo '</div>';
	}

	/* ---------------------------------------------------------------------
	 * Export tab
	 * ------------------------------------------------------------------- */

	private static function checkboxes( string $name, array $options, array $checked = array() ): void {
		echo '<fieldset class="tmg-io-checks">';
		foreach ( $options as $value => $label ) {
			printf(
				'<label><input type="checkbox" name="%s[]" value="%s" %s> %s</label>',
				esc_attr( $name ),
				esc_attr( (string) $value ),
				checked( in_array( (string) $value, array_map( 'strval', $checked ), true ), true, false ),
				esc_html( (string) $label )
			);
		}
		echo '</fieldset>';
	}

	private static function render_export(): void {
		$types    = TMG_IO_Exporter::exportable_types();
		$site     = TMG_IO_Permissions::can_site_settings();
		$acf      = TMG_IO_ACF::structure_kinds();
		$taxes    = array();
		foreach ( get_taxonomies( array(), 'objects' ) as $t ) {
			if ( 'nav_menu' !== $t->name && 'link_category' !== $t->name && 'wp_theme' !== $t->name && 'wp_template_part_area' !== $t->name && current_user_can( $t->cap->manage_terms ) ) {
				$taxes[ $t->name ] = $t->labels->name;
			}
		}
		$statuses = array();
		foreach ( get_post_stati( array( 'show_in_admin_status_list' => true ), 'objects' ) as $s ) {
			$statuses[ $s->name ] = $s->label;
		}
		$statuses['inherit'] = __( 'Inherit (media)', 'tamago-io' );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tmg-io-export">
			<input type="hidden" name="action" value="tmg_io_export">
			<?php wp_nonce_field( 'tmg_io_export' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Content', 'tamago-io' ); ?></th>
					<td>
						<?php self::checkboxes( 'c[types]', $types, array( 'post', 'page' ) ); ?>
						<p><label><input type="checkbox" name="c[comments]" value="1"> <?php esc_html_e( 'Include comments', 'tamago-io' ); ?></label></p>
						<details class="tmg-io-filters"><summary><?php esc_html_e( 'Filters', 'tamago-io' ); ?></summary>
							<p><strong><?php esc_html_e( 'Status', 'tamago-io' ); ?></strong> <span class="description"><?php esc_html_e( '(none ticked = all except trash)', 'tamago-io' ); ?></span></p>
							<?php self::checkboxes( 'c[status]', $statuses ); ?>
							<p>
								<label><?php esc_html_e( 'From', 'tamago-io' ); ?> <input type="date" name="c[date_from]"></label>
								<label><?php esc_html_e( 'to', 'tamago-io' ); ?> <input type="date" name="c[date_to]"></label>
							</p>
							<p><label><?php esc_html_e( 'Author', 'tamago-io' ); ?>
								<?php
								wp_dropdown_users(
									array(
										'name'              => 'c[author]',
										'show_option_none'  => __( 'Any', 'tamago-io' ),
										'option_none_value' => '0',
										'capability'        => array( 'edit_posts' ),
									)
								);
								?>
							</label></p>
							<p><label><?php esc_html_e( 'Terms', 'tamago-io' ); ?> <input type="text" class="regular-text" name="c[terms]" placeholder="category:news, post_tag:featured"></label></p>
							<p><label><?php esc_html_e( 'Only these IDs', 'tamago-io' ); ?> <input type="text" class="regular-text" name="c[ids]" placeholder="12, 34, 56"></label></p>
							<p><label><?php esc_html_e( 'Limit', 'tamago-io' ); ?> <input type="number" min="0" class="small-text" name="c[limit]" value="0"></label> <span class="description"><?php esc_html_e( '0 = no limit', 'tamago-io' ); ?></span></p>
						</details>
					</td>
				</tr>
				<?php if ( $taxes ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'All terms of', 'tamago-io' ); ?></th>
					<td><?php self::checkboxes( 'c[taxonomies]', $taxes ); ?>
						<p class="description"><?php esc_html_e( 'With hierarchy, term meta and ACF term fields. Terms used by exported content travel with it anyway.', 'tamago-io' ); ?></p></td>
				</tr>
				<?php endif; ?>
				<?php if ( current_user_can( 'list_users' ) ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Users', 'tamago-io' ); ?></th>
					<td>
						<label><input type="checkbox" name="c[users]" value="1"> <?php esc_html_e( 'Export users', 'tamago-io' ); ?></label>
						<?php self::checkboxes( 'c[user_roles]', wp_list_pluck( wp_roles()->roles, 'name' ) ); ?>
						<p class="description"><?php esc_html_e( 'No roles ticked = all users. Passwords, sessions and capability internals are never exported.', 'tamago-io' ); ?></p>
					</td>
				</tr>
				<?php endif; ?>
				<?php if ( current_user_can( 'edit_theme_options' ) && wp_get_nav_menus() ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Menus', 'tamago-io' ); ?></th>
					<td><?php self::checkboxes( 'c[menus]', wp_list_pluck( wp_get_nav_menus(), 'name', 'term_id' ) ); ?></td>
				</tr>
				<?php endif; ?>
				<?php if ( $site ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Options', 'tamago-io' ); ?></th>
					<td>
						<textarea name="c[options]" rows="3" class="large-text code" placeholder="blogname&#10;options_*&#10;my_plugin_"></textarea>
						<p class="description"><?php esc_html_e( 'One per line: an exact option name, or a prefix ending with * or _ (for example options_ for ACF options pages). Site URLs, keys, active plugins and roles are never included.', 'tamago-io' ); ?></p>
						<?php
						$pages = TMG_IO_ACF::options_pages();
						if ( $pages ) {
							echo '<p><strong>' . esc_html__( 'ACF options pages', 'tamago-io' ) . '</strong></p>';
							$list = array();
							foreach ( $pages as $slug => $p ) {
								$list[ $slug ] = $p['title'] . ' (' . $p['post_id'] . ')';
							}
							self::checkboxes( 'c[options_pages]', $list );
						}
						?>
					</td>
				</tr>
				<?php if ( $acf ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'ACF structure', 'tamago-io' ); ?></th>
					<td>
						<?php self::checkboxes( 'c[acf_structure]', $acf ); ?>
						<p class="description">
							<?php
							/* translators: %s: ACF edition */
							echo esc_html( sprintf( __( 'Detected: ACF %s. Saved in ACF\'s own JSON format, so the file also imports with ACF → Tools.', 'tamago-io' ), 'pro' === TMG_IO_ACF::edition() ? 'PRO' : __( '(free)', 'tamago-io' ) ) );
							?>
						</p>
						<p><button class="button" name="acf_only" value="1"><?php esc_html_e( 'Download ACF JSON only', 'tamago-io' ); ?></button></p>
					</td>
				</tr>
				<?php endif; ?>
				<?php endif; ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Format', 'tamago-io' ); ?></th>
					<td class="tmg-io-formats">
						<label><input type="radio" name="c[format]" value="json" checked> <?php esc_html_e( 'JSON package — full fidelity, re-downloads media by URL', 'tamago-io' ); ?></label><br>
						<?php if ( class_exists( 'ZipArchive' ) ) : ?>
							<label><input type="radio" name="c[format]" value="zip"> <?php esc_html_e( 'ZIP — JSON package plus the media files', 'tamago-io' ); ?></label><br>
							<label><input type="radio" name="c[format]" value="xlsx"> <?php esc_html_e( 'Excel (.xlsx) — one sheet per content type, editable', 'tamago-io' ); ?></label><br>
						<?php endif; ?>
						<label><input type="radio" name="c[format]" value="csv"> <?php esc_html_e( 'CSV — one file per content type (ZIP when several)', 'tamago-io' ); ?></label>
						<p class="description"><?php esc_html_e( 'Excel and CSV files keep a _tmg_json column, so they import back completely; cells you edit are applied on import.', 'tamago-io' ); ?></p>
					</td>
				</tr>
			</table>
			<p class="submit">
				<button class="button button-primary"><?php esc_html_e( 'Download export', 'tamago-io' ); ?></button>
				<span class="tmg-io-preset-save">
					<input type="text" name="preset_name" placeholder="<?php esc_attr_e( 'Preset name', 'tamago-io' ); ?>">
					<button class="button" name="save_preset" value="1"><?php esc_html_e( 'Save as preset', 'tamago-io' ); ?></button>
				</span>
			</p>
		</form>
		<?php
		$presets = TMG_IO_Presets::all( TMG_IO_Presets::EXPORT );
		if ( $presets ) :
			?>
			<h2><?php esc_html_e( 'Saved presets', 'tamago-io' ); ?></h2>
			<table class="widefat striped tmg-io-presets">
				<thead><tr><th><?php esc_html_e( 'Name', 'tamago-io' ); ?></th><th><?php esc_html_e( 'Contents', 'tamago-io' ); ?></th><th><?php esc_html_e( 'Format', 'tamago-io' ); ?></th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $presets as $name => $p ) : ?>
					<tr>
						<td><strong><?php echo esc_html( (string) $name ); ?></strong></td>
						<td><?php echo esc_html( self::preset_summary( (array) $p ) ); ?></td>
						<td><?php echo esc_html( strtoupper( (string) ( $p['format'] ?? 'json' ) ) ); ?></td>
						<td class="tmg-io-actions">
							<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tmg_io_preset_run&name=' . rawurlencode( (string) $name ) ), 'tmg_io_preset' ) ); ?>"><?php esc_html_e( 'Download', 'tamago-io' ); ?></a>
							<a class="submitdelete" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tmg_io_preset_delete&name=' . rawurlencode( (string) $name ) ), 'tmg_io_preset' ) ); ?>"><?php esc_html_e( 'Delete', 'tamago-io' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php
		endif;

		foreach ( TMG_IO_Sources::available() as $id => $s ) :
			?>
			<div class="tmg-io-card">
				<h2><?php echo esc_html( $s['label'] ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="tmg_io_source_export">
					<input type="hidden" name="source" value="<?php echo esc_attr( $id ); ?>">
					<?php wp_nonce_field( 'tmg_io_source_export' ); ?>
					<?php echo TMG_IO_Sources::fields_html( $s, 'export' ); // phpcs:ignore WordPress.Security.EscapeOutput -- built and escaped by the source. ?>
					<p>
						<?php foreach ( $s['formats'] as $i => $f ) : ?>
							<button class="button <?php echo 0 === $i ? 'button-primary' : ''; ?>" name="format" value="<?php echo esc_attr( $f ); ?>"><?php echo esc_html( 'xlsx' === $f ? 'Excel' : strtoupper( $f ) ); ?></button>
						<?php endforeach; ?>
					</p>
				</form>
			</div>
			<?php
		endforeach;
	}

	public static function preset_summary( array $p ): string {
		$parts = array();
		if ( ! empty( $p['types'] ) ) {
			$parts[] = implode( ', ', (array) $p['types'] );
		}
		if ( ! empty( $p['taxonomies'] ) ) {
			/* translators: %s: taxonomies */
			$parts[] = sprintf( __( 'terms of %s', 'tamago-io' ), implode( ', ', (array) $p['taxonomies'] ) );
		}
		if ( ! empty( $p['users'] ) ) {
			$parts[] = __( 'users', 'tamago-io' );
		}
		if ( ! empty( $p['options'] ) || ! empty( $p['options_pages'] ) ) {
			$parts[] = __( 'options', 'tamago-io' );
		}
		if ( ! empty( $p['menus'] ) ) {
			$parts[] = __( 'menus', 'tamago-io' );
		}
		if ( ! empty( $p['acf_structure'] ) ) {
			$parts[] = __( 'ACF structure', 'tamago-io' );
		}
		return implode( ' · ', $parts );
	}

	/* ---------------------------------------------------------------------
	 * History tab
	 * ------------------------------------------------------------------- */

	private static function render_history(): void {
		$table = new TMG_IO_History_Table();
		$table->prepare_items();
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="tab" value="history">';
		$table->display();
		echo '</form>';
	}

	/* ---------------------------------------------------------------------
	 * Settings tab
	 * ------------------------------------------------------------------- */

	private static function render_settings(): void {
		$s      = TMG_IO_Settings::all();
		$labels = array(
			'max_upload_mb'     => array( __( 'Largest upload (MB)', 'tamago-io' ), __( 'Also limited by the server: ', 'tamago-io' ) . size_format( wp_max_upload_size() ) ),
			'batch_size'        => array( __( 'Items per step', 'tamago-io' ), __( 'How many items one background step imports.', 'tamago-io' ) ),
			'step_seconds'      => array( __( 'Seconds per step', 'tamago-io' ), __( 'Each step stops after this long, so large files never time out.', 'tamago-io' ) ),
			'remote_media'      => array( __( 'Download media by URL', 'tamago-io' ), __( 'Allow imports to fetch images and files from their original address (http/https only).', 'tamago-io' ) ),
			'media_max_mb'      => array( __( 'Largest media file (MB)', 'tamago-io' ), '' ),
			'history_days'      => array( __( 'Keep history (days)', 'tamago-io' ), '' ),
			'skip_meta_keys'    => array( __( 'Custom fields never exported or imported', 'tamago-io' ), __( 'One key per line; end with * for a prefix.', 'tamago-io' ) ),
			'protected_options' => array( __( 'Extra protected options', 'tamago-io' ), __( 'Never exported or imported. One per line; end with * for a prefix.', 'tamago-io' ) ),
			'delete_data'       => array( __( 'Delete all data when the plugin is deleted', 'tamago-io' ), __( 'Settings, presets, templates, history, capabilities and the working folder.', 'tamago-io' ) ),
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="tmg_io_settings">
			<?php wp_nonce_field( 'tmg_io_settings' ); ?>
			<table class="form-table" role="presentation">
			<?php
			foreach ( TMG_IO_Settings::types() as $key => $type ) :
				$locked = TMG_IO_Settings::locked( $key );
				$name   = 's[' . $key . ']';
				$value  = $s[ $key ];
				?>
				<tr>
					<th scope="row"><label for="tmg-io-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $labels[ $key ][0] ); ?></label></th>
					<td>
						<?php if ( 'bool' === $type ) : ?>
							<label><input type="checkbox" id="tmg-io-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( (int) $value, 1 ); ?> <?php disabled( $locked ); ?>> <?php echo esc_html( $labels[ $key ][1] ); ?></label>
						<?php elseif ( 'lines' === $type ) : ?>
							<textarea id="tmg-io-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $name ); ?>" rows="4" class="large-text code" <?php disabled( $locked ); ?>><?php echo esc_textarea( (string) $value ); ?></textarea>
							<p class="description"><?php echo esc_html( $labels[ $key ][1] ); ?></p>
						<?php else : ?>
							<input type="number" min="1" id="tmg-io-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" class="small-text" <?php disabled( $locked ); ?>>
							<?php if ( $labels[ $key ][1] ) : ?><p class="description"><?php echo esc_html( $labels[ $key ][1] ); ?></p><?php endif; ?>
						<?php endif; ?>
						<?php if ( $locked ) : ?>
							<?php if ( 'bool' === $type && $value ) : ?><input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="1"><?php endif; ?>
							<p class="description tmg-io-locked">
								<?php
								/* translators: %s: constant name */
								echo esc_html( sprintf( __( 'Set by %s in wp-config.php.', 'tamago-io' ), TMG_IO_Settings::constant_name( $key ) ) );
								?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</table>
			<?php submit_button(); ?>
		</form>

		<div class="tmg-io-card">
			<h2><?php esc_html_e( 'Export settings / Import settings', 'tamago-io' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Settings, export presets and mapping templates as JSON, to copy them to another site.', 'tamago-io' ); ?></p>
			<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tmg_io_settings_export' ), 'tmg_io_settings' ) ); ?>"><?php esc_html_e( 'Export settings', 'tamago-io' ); ?></a></p>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="tmg_io_settings_import">
				<?php wp_nonce_field( 'tmg_io_settings' ); ?>
				<input type="file" name="file" accept=".json,application/json" required>
				<button class="button"><?php esc_html_e( 'Import settings', 'tamago-io' ); ?></button>
			</form>
		</div>

		<?php $templates = TMG_IO_Presets::all( TMG_IO_Presets::MAPPING ); ?>
		<?php if ( $templates ) : ?>
			<h2><?php esc_html_e( 'Mapping templates', 'tamago-io' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Name', 'tamago-io' ); ?></th><th><?php esc_html_e( 'Imports into', 'tamago-io' ); ?></th><th><?php esc_html_e( 'Columns', 'tamago-io' ); ?></th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $templates as $name => $t ) : ?>
					<tr>
						<td><?php echo esc_html( (string) $name ); ?></td>
						<td><?php echo esc_html( (string) ( $t['source'] ? $t['source'] : $t['post_type'] ) ); ?></td>
						<td><?php echo (int) count( array_filter( (array) $t['mapping'] ) ); ?></td>
						<td><a class="submitdelete" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tmg_io_template_delete&name=' . rawurlencode( (string) $name ) ), 'tmg_io_template' ) ); ?>"><?php esc_html_e( 'Delete', 'tamago-io' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}
}
