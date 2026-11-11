<?php
/**
 * Import tab steps: upload → column mapping (CSV / Excel / plain JSON) → preview with options →
 * progress (AJAX steps, resumable) → report.
 *
 * Screens: Import & Export → Import.
 */

defined( 'ABSPATH' ) || exit;

class TMG_IO_Admin_Import_Screen {

	public static function render(): void {
		$step = sanitize_key( wp_unslash( $_GET['step'] ?? '' ) ); // phpcs:ignore WordPress.Security
		$id   = absint( $_GET['run'] ?? 0 ); // phpcs:ignore WordPress.Security
		$run  = $id ? TMG_IO_Runs::get( $id ) : null;
		if ( $run && ! TMG_IO_Runs::can_access( $run ) ) {
			$run = null;
		}
		if ( in_array( $step, array( 'map', 'preview' ), true ) ) {
			if ( ! $run || 'uploaded' !== $run['status'] ) {
				echo '<div class="notice notice-warning"><p>' . esc_html__( 'The upload has expired or was already imported. Please upload the file again.', 'tamago-io' ) . '</p></div>';
				self::render_upload();
				return;
			}
			'map' === $step ? self::render_map( $run ) : self::render_preview( $run );
			return;
		}
		if ( 'run' === $step && $run ) {
			self::render_run( $run );
			return;
		}
		self::render_upload();
	}

	/* ---------------------------------------------------------------------
	 * Upload
	 * ------------------------------------------------------------------- */

	private static function render_upload(): void {
		$max     = min( (int) TMG_IO_Settings::get( 'max_upload_mb' ) * MB_IN_BYTES, (int) wp_max_upload_size() );
		$sources = TMG_IO_Sources::available( true );
		?>
		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tmg-io-upload">
			<input type="hidden" name="action" value="tmg_io_upload">
			<?php wp_nonce_field( 'tmg_io_upload' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="tmg-io-file"><?php esc_html_e( 'File', 'tamago-io' ); ?></label></th>
					<td>
						<input type="file" id="tmg-io-file" name="file" accept=".json,.zip,.csv,.tsv,.txt,.xlsx" required>
						<p class="description">
							<?php
							/* translators: %s: size limit */
							echo esc_html( sprintf( __( 'JSON package, ZIP (package with media), CSV or Excel (.xlsx). Up to %s. Nothing changes until you confirm on the next screens.', 'tamago-io' ), size_format( $max ) ) );
							?>
						</p>
						<p class="description"><?php esc_html_e( 'Files exported by this plugin import directly. Other spreadsheets get a column-mapping step. An ACF export (.json) imports its field groups, post types, taxonomies and options pages.', 'tamago-io' ); ?></p>
					</td>
				</tr>
				<?php if ( $sources ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Import into', 'tamago-io' ); ?></th>
					<td class="tmg-io-into">
						<label><input type="radio" name="into" value="" checked> <?php esc_html_e( 'WordPress content (posts, pages, custom post types…)', 'tamago-io' ); ?></label>
						<?php foreach ( $sources as $sid => $s ) : ?>
							<div class="tmg-io-source">
								<label><input type="radio" name="into" value="<?php echo esc_attr( $sid ); ?>"> <?php echo esc_html( $s['label'] ); ?></label>
								<div class="tmg-io-source-fields"><?php echo TMG_IO_Sources::fields_html( $s, 'import' ); // phpcs:ignore WordPress.Security.EscapeOutput -- built and escaped by the source. ?></div>
							</div>
						<?php endforeach; ?>
					</td>
				</tr>
				<?php endif; ?>
			</table>
			<p class="submit"><button class="button button-primary"><?php esc_html_e( 'Upload and continue', 'tamago-io' ); ?></button></p>
		</form>
		<p class="description"><?php esc_html_e( 'Large sites: wp tamago-io import <file> runs the same import from the command line.', 'tamago-io' ); ?></p>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Mapping
	 * ------------------------------------------------------------------- */

	private static function render_map( array $run ): void {
		$o       = $run['options'];
		$source  = (string) ( $o['source'] ?? '' );
		$sources = TMG_IO_Sources::available( true );
		$sheet   = sanitize_text_field( wp_unslash( $_GET['sheet'] ?? (string) ( $o['sheet'] ?? '' ) ) ); // phpcs:ignore WordPress.Security
		try {
			$w = TMG_IO_Importer::work_from_parsed( TMG_IO_Files::read( (string) $o['file'] ), (string) $o['file'], array( 'sheet' => $sheet, 'source' => $source ) );
		} catch ( Throwable $e ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $e->getMessage() ) . '</p></div>';
			return;
		}
		if ( $w['sheet'] !== ( $o['sheet'] ?? '' ) ) {
			$o['sheet'] = $w['sheet'];
			TMG_IO_Runs::update( $run['id'], array( 'options' => $o ) );
		}
		$types   = TMG_IO_Exporter::exportable_types();
		unset( $types['attachment'] );
		$pt      = sanitize_key( wp_unslash( $_GET['import_type'] ?? (string) ( $o['opts']['post_type'] ?? 'post' ) ) ); // phpcs:ignore WordPress.Security
		$pt      = isset( $types[ $pt ] ) ? $pt : (string) array_key_first( $types );
		$current = (array) ( $o['opts']['mapping'] ?? array() );
		$tname   = sanitize_text_field( wp_unslash( $_GET['template'] ?? '' ) ); // phpcs:ignore WordPress.Security
		$tpl     = '' !== $tname ? TMG_IO_Presets::get( TMG_IO_Presets::MAPPING, $tname ) : null;
		if ( $tpl ) {
			$current = (array) $tpl['mapping'];
			if ( '' === $source && ! empty( $tpl['post_type'] ) && isset( $types[ $tpl['post_type'] ] ) && ! isset( $_GET['import_type'] ) ) { // phpcs:ignore WordPress.Security
				$pt = $tpl['post_type'];
			}
		}
		if ( '' !== $source && isset( $sources[ $source ] ) ) {
			$cols = array();
			foreach ( TMG_IO_Sources::columns( $sources[ $source ], (array) ( $o['choices'] ?? array() ) ) as $k => $label ) {
				$cols[ 'src:' . $k ] = $label;
			}
			$groups = array( $sources[ $source ]['label'] => $cols );
		} else {
			$groups = TMG_IO_Mapping::targets( $pt );
		}
		$base = array( 'tab' => 'import', 'step' => 'map', 'run' => $run['id'] );
		?>
		<h2><?php esc_html_e( 'Map the columns', 'tamago-io' ); ?></h2>
		<p>
			<?php
			/* translators: 1: file name, 2: number of rows */
			echo esc_html( sprintf( __( '%1$s — %2$d rows.', 'tamago-io' ), $o['name'], count( $w['work']['rows'] ) ) );
			?>
		</p>
		<form method="get" class="tmg-io-inline">
			<?php foreach ( array( 'page' => TMG_IO_Admin_Screen::SLUG ) + $base as $k => $v ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( (string) $v ); ?>">
			<?php endforeach; ?>
			<?php if ( count( $w['sheets'] ) > 1 ) : ?>
				<label><?php esc_html_e( 'Sheet', 'tamago-io' ); ?>
					<select name="sheet" onchange="this.form.submit()">
						<?php foreach ( $w['sheets'] as $s ) : ?>
							<option value="<?php echo esc_attr( (string) $s ); ?>" <?php selected( (string) $s, $w['sheet'] ); ?>><?php echo esc_html( (string) $s ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			<?php else : ?>
				<input type="hidden" name="sheet" value="<?php echo esc_attr( $w['sheet'] ); ?>">
			<?php endif; ?>
			<?php if ( '' === $source ) : ?>
				<label><?php esc_html_e( 'Import as', 'tamago-io' ); ?>
					<select name="import_type" onchange="this.form.submit()">
						<?php foreach ( $types as $k => $label ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $k, $pt ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			<?php endif; ?>
			<?php $templates = TMG_IO_Presets::all( TMG_IO_Presets::MAPPING ); ?>
			<?php if ( $templates ) : ?>
				<label><?php esc_html_e( 'Template', 'tamago-io' ); ?>
					<select name="template" onchange="this.form.submit()">
						<option value=""><?php esc_html_e( '— none —', 'tamago-io' ); ?></option>
						<?php foreach ( array_keys( $templates ) as $n ) : ?>
							<option value="<?php echo esc_attr( (string) $n ); ?>" <?php selected( (string) $n, $tname ); ?>><?php echo esc_html( (string) $n ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			<?php endif; ?>
			<noscript><button class="button"><?php esc_html_e( 'Apply', 'tamago-io' ); ?></button></noscript>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="tmg_io_map">
			<input type="hidden" name="run" value="<?php echo (int) $run['id']; ?>">
			<input type="hidden" name="post_type" value="<?php echo esc_attr( $pt ); ?>">
			<?php wp_nonce_field( 'tmg_io_map' ); ?>
			<table class="widefat striped tmg-io-map">
				<thead><tr><th><?php esc_html_e( 'Column in the file', 'tamago-io' ); ?></th><th><?php esc_html_e( 'Example', 'tamago-io' ); ?></th><th><?php esc_html_e( 'Save as', 'tamago-io' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $w['headers'] as $i => $h ) : ?>
					<?php
					$h     = (string) $h;
					$guess = array_key_exists( $h, $current ) ? (string) $current[ $h ] : ( '' === $source ? TMG_IO_Mapping::guess( $h, $pt, $groups ) : self::guess_source( $h, $groups ) );
					$is_custom_meta = 0 === strpos( $guess, 'meta:' ) && ! self::in_groups( $guess, $groups );
					?>
					<tr>
						<td><strong><?php echo esc_html( $h ); ?></strong><input type="hidden" name="col[<?php echo (int) $i; ?>]" value="<?php echo esc_attr( $h ); ?>"></td>
						<td class="tmg-io-sample"><?php echo esc_html( mb_substr( (string) ( $w['sample'][ $h ] ?? '' ), 0, 80 ) ); ?></td>
						<td>
							<select name="map[<?php echo (int) $i; ?>]" class="tmg-io-target">
								<option value=""><?php esc_html_e( '— do not import —', 'tamago-io' ); ?></option>
								<?php foreach ( $groups as $glabel => $list ) : ?>
									<optgroup label="<?php echo esc_attr( (string) $glabel ); ?>">
										<?php foreach ( $list as $t => $label ) : ?>
											<option value="<?php echo esc_attr( (string) $t ); ?>" <?php selected( (string) $t, $guess ); ?>><?php echo esc_html( (string) $label ); ?></option>
										<?php endforeach; ?>
									</optgroup>
								<?php endforeach; ?>
								<?php if ( '' === $source ) : ?>
									<option value="__meta" <?php selected( $is_custom_meta ); ?>><?php esc_html_e( 'Other custom field…', 'tamago-io' ); ?></option>
								<?php endif; ?>
							</select>
							<?php if ( '' === $source ) : ?>
								<input type="text" class="tmg-io-custom" name="custom[<?php echo (int) $i; ?>]" placeholder="<?php esc_attr_e( 'meta key', 'tamago-io' ); ?>" value="<?php echo esc_attr( $is_custom_meta ? substr( $guess, 5 ) : '' ); ?>">
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'Values are converted to each field\'s type: dates (any format or Excel dates), yes/no, choice labels or values, images and files by URL or ID, related content by ID, slug or title, users by login or email, repeaters and groups from JSON.', 'tamago-io' ); ?></p>
			<p class="submit">
				<button class="button button-primary"><?php esc_html_e( 'Continue to preview', 'tamago-io' ); ?></button>
				<input type="text" name="template_name" placeholder="<?php esc_attr_e( 'Save mapping as template (optional)', 'tamago-io' ); ?>" class="regular-text">
				<a class="button-link" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tmg_io_cancel&run=' . (int) $run['id'] ), 'tmg_io_run' ) ); ?>"><?php esc_html_e( 'Cancel', 'tamago-io' ); ?></a>
			</p>
		</form>
		<?php
	}

	private static function in_groups( string $target, array $groups ): bool {
		foreach ( $groups as $list ) {
			if ( isset( $list[ $target ] ) ) {
				return true;
			}
		}
		return false;
	}

	private static function guess_source( string $h, array $groups ): string {
		$n = sanitize_title( $h );
		foreach ( $groups as $list ) {
			foreach ( $list as $t => $label ) {
				if ( $n === sanitize_title( substr( (string) $t, 4 ) ) || $n === sanitize_title( (string) $label ) ) {
					return (string) $t;
				}
			}
		}
		return '';
	}

	/* ---------------------------------------------------------------------
	 * Preview
	 * ------------------------------------------------------------------- */

	private static function render_preview( array $run ): void {
		$o    = $run['options'];
		$opts = TMG_IO_Importer::sanitize_opts( (array) ( $o['opts'] ?? array() ) );
		try {
			$w = TMG_IO_Importer::work_from_parsed( TMG_IO_Files::read( (string) $o['file'] ), (string) $o['file'], $o );
		} catch ( Throwable $e ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $e->getMessage() ) . '</p></div>';
			return;
		}
		$preview  = TMG_IO_Importer::preview( $w['work'], $opts );
		$pkg      = (array) ( $w['work']['package'] ?? array() );
		$is_pkg   = 'package' === $w['kind'];
		$labels   = array(
			'acf'     => __( 'ACF structure', 'tamago-io' ),
			'terms'   => __( 'Terms', 'tamago-io' ),
			'users'   => __( 'Users', 'tamago-io' ),
			'media'   => __( 'Media files', 'tamago-io' ),
			'posts'   => __( 'Content', 'tamago-io' ),
			'menus'   => __( 'Menus', 'tamago-io' ),
			'options' => __( 'Options', 'tamago-io' ),
			'rows'    => __( 'Rows', 'tamago-io' ),
			'source'  => __( 'Rows', 'tamago-io' ),
		);
		$sections = array(
			'acf_structure' => array( __( 'ACF structure', 'tamago-io' ), ! empty( $pkg['acf_structure'] ), TMG_IO_Permissions::can_site_settings() ),
			'terms'         => array( __( 'Terms', 'tamago-io' ), ! empty( $pkg['terms'] ), true ),
			'users'         => array( __( 'Users', 'tamago-io' ), ! empty( $pkg['users'] ), current_user_can( 'create_users' ) || current_user_can( 'edit_users' ) ),
			'media'         => array( __( 'Media files', 'tamago-io' ), ! empty( $pkg['media'] ), current_user_can( 'upload_files' ) ),
			'posts'         => array( __( 'Content', 'tamago-io' ), ! empty( $pkg['posts'] ), true ),
			'menus'         => array( __( 'Menus', 'tamago-io' ), ! empty( $pkg['menus'] ), current_user_can( 'edit_theme_options' ) ),
			'options'       => array( __( 'Options', 'tamago-io' ), ! empty( $pkg['options'] ) || ! empty( $pkg['options_raw'] ), TMG_IO_Permissions::can_site_settings() ),
		);
		$types = array();
		foreach ( (array) ( $pkg['posts'] ?? array() ) as $p ) {
			$types[ $p['type'] ] = ( $types[ $p['type'] ] ?? 0 ) + 1;
		}
		?>
		<h2><?php esc_html_e( 'Preview', 'tamago-io' ); ?></h2>
		<p>
			<strong><?php echo esc_html( (string) $o['name'] ); ?></strong>
			<?php if ( $is_pkg && ! empty( $pkg['site'] ) ) : ?>
				— <?php /* translators: 1: site, 2: date */ echo esc_html( sprintf( __( 'exported from %1$s on %2$s', 'tamago-io' ), $pkg['site'], $pkg['exported'] ?? '' ) ); ?>
			<?php endif; ?>
		</p>
		<?php if ( $types ) : ?>
			<p class="description">
				<?php
				$parts = array();
				foreach ( $types as $t => $n ) {
					$obj     = get_post_type_object( $t );
					$parts[] = ( $obj ? $obj->labels->name : $t ) . ': ' . $n;
				}
				echo esc_html( implode( ' · ', $parts ) );
				?>
			</p>
		<?php endif; ?>
		<table class="widefat striped tmg-io-preview">
			<thead><tr>
				<th><?php esc_html_e( 'Section', 'tamago-io' ); ?></th>
				<th><?php esc_html_e( 'In the file', 'tamago-io' ); ?></th>
				<th><?php esc_html_e( 'Would be created', 'tamago-io' ); ?></th>
				<th><?php esc_html_e( 'Would be updated', 'tamago-io' ); ?></th>
				<th><?php esc_html_e( 'Would be skipped', 'tamago-io' ); ?></th>
				<th><?php esc_html_e( 'Other', 'tamago-io' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $preview as $s => $c ) : ?>
				<tr>
					<td><?php echo esc_html( $labels[ $s ] ?? $s ); ?></td>
					<td><?php echo (int) $c['total']; ?></td>
					<td><?php echo (int) $c['create']; ?></td>
					<td><?php echo (int) $c['update']; ?></td>
					<td><?php echo (int) $c['skip']; ?></td>
					<td><?php echo (int) $c['other']; ?></td>
				</tr>
			<?php endforeach; ?>
			<?php if ( ! $preview ) : ?>
				<tr><td colspan="6"><?php esc_html_e( 'Nothing to import in this file.', 'tamago-io' ); ?></td></tr>
			<?php endif; ?>
			</tbody>
		</table>
		<p class="description"><?php esc_html_e( '"Other": items of a type that does not exist here, protected options, or media / attachments handled with their files. Run a dry run for the exact per-item result.', 'tamago-io' ); ?></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tmg-io-options">
			<input type="hidden" name="action" value="tmg_io_start">
			<input type="hidden" name="run" value="<?php echo (int) $run['id']; ?>">
			<?php wp_nonce_field( 'tmg_io_options' ); ?>
			<table class="form-table" role="presentation">
				<?php if ( $is_pkg ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Import', 'tamago-io' ); ?></th>
					<td>
						<?php foreach ( $sections as $k => list( $label, $present, $allowed ) ) : ?>
							<?php
							if ( ! $present ) {
								continue;
							}
							?>
							<label class="tmg-io-check"><input type="checkbox" name="o[sections][]" value="<?php echo esc_attr( $k ); ?>" <?php checked( in_array( $k, $opts['sections'], true ) && $allowed ); ?> <?php disabled( ! $allowed ); ?>> <?php echo esc_html( $label ); ?></label>
							<?php if ( ! $allowed ) : ?><span class="description"><?php esc_html_e( '(not allowed for your account)', 'tamago-io' ); ?></span><?php endif; ?>
						<?php endforeach; ?>
					</td>
				</tr>
				<?php endif; ?>
				<?php if ( '' === (string) ( $o['source'] ?? '' ) ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Find existing items by', 'tamago-io' ); ?></th>
					<td>
						<select name="o[match]">
							<option value="slug" <?php selected( $opts['match'], 'slug' ); ?>><?php esc_html_e( 'Slug', 'tamago-io' ); ?></option>
							<option value="id" <?php selected( $opts['match'], 'id' ); ?>><?php esc_html_e( 'ID', 'tamago-io' ); ?></option>
							<?php if ( $is_pkg ) : ?><option value="guid" <?php selected( $opts['match'], 'guid' ); ?>><?php esc_html_e( 'GUID', 'tamago-io' ); ?></option><?php endif; ?>
							<option value="meta" <?php selected( $opts['match'], 'meta' ); ?>><?php esc_html_e( 'Custom / ACF field…', 'tamago-io' ); ?></option>
							<option value="none" <?php selected( $opts['match'], 'none' ); ?>><?php esc_html_e( 'Do not look (always create)', 'tamago-io' ); ?></option>
						</select>
						<input type="text" name="o[match_key]" value="<?php echo esc_attr( $opts['match_key'] ); ?>" placeholder="<?php esc_attr_e( 'field name, e.g. sku', 'tamago-io' ); ?>">
						<p class="description"><?php esc_html_e( 'Terms are matched by slug, users by login or email, menus by slug.', 'tamago-io' ); ?></p>
					</td>
				</tr>
				<?php endif; ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'When an item exists', 'tamago-io' ); ?></th>
					<td>
						<label><input type="radio" name="o[existing]" value="skip" <?php checked( $opts['existing'], 'skip' ); ?>> <?php esc_html_e( 'Skip it', 'tamago-io' ); ?></label>
						<label><input type="radio" name="o[existing]" value="update" <?php checked( $opts['existing'], 'update' ); ?>> <?php esc_html_e( 'Update it', 'tamago-io' ); ?></label>
						<label><input type="radio" name="o[existing]" value="copy" <?php checked( $opts['existing'], 'copy' ); ?>> <?php esc_html_e( 'Create a copy', 'tamago-io' ); ?></label>
						<p><label><input type="checkbox" name="o[empty_missing]" value="1" <?php checked( $opts['empty_missing'] ); ?>> <?php esc_html_e( 'When updating, empty fields that are missing or blank in the file (otherwise they are kept)', 'tamago-io' ); ?></label></p>
					</td>
				</tr>
				<?php if ( '' === (string) ( $o['source'] ?? '' ) ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'More', 'tamago-io' ); ?></th>
					<td>
						<label><input type="checkbox" name="o[media]" value="1" <?php checked( $opts['media'] ); ?> <?php disabled( ! TMG_IO_Settings::get( 'remote_media' ) ); ?>> <?php esc_html_e( 'Download media from their original URLs when they are not in the file', 'tamago-io' ); ?></label><br>
						<?php if ( $is_pkg ) : ?>
							<label><input type="checkbox" name="o[comments]" value="1" <?php checked( $opts['comments'] ); ?>> <?php esc_html_e( 'Import comments', 'tamago-io' ); ?></label><br>
							<label><input type="checkbox" name="o[menu_locations]" value="1" <?php checked( $opts['menu_locations'] ); ?>> <?php esc_html_e( 'Assign menus to their theme locations', 'tamago-io' ); ?></label><br>
						<?php endif; ?>
						<label><input type="checkbox" name="o[keep_ids]" value="1" <?php checked( $opts['keep_ids'] ); ?>> <?php esc_html_e( 'Keep the original IDs of new items when they are free', 'tamago-io' ); ?></label>
					</td>
				</tr>
				<?php endif; ?>
			</table>
			<p class="submit">
				<button class="button" name="dry" value="1"><?php esc_html_e( 'Dry run (change nothing)', 'tamago-io' ); ?></button>
				<button class="button button-primary"><?php esc_html_e( 'Start import', 'tamago-io' ); ?></button>
				<button class="button-link" formaction="<?php echo esc_url( admin_url( 'admin-post.php?action=tmg_io_options' ) ); ?>" name="action" value="tmg_io_options"><?php esc_html_e( 'Refresh preview with these options', 'tamago-io' ); ?></button>
				<?php if ( 'rows' === $w['kind'] ) : ?>
					<a class="button-link" href="<?php echo esc_url( TMG_IO_Admin_Screen::url( array( 'tab' => 'import', 'step' => 'map', 'run' => $run['id'] ) ) ); ?>"><?php esc_html_e( 'Back to mapping', 'tamago-io' ); ?></a>
				<?php endif; ?>
				<a class="button-link" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tmg_io_cancel&run=' . (int) $run['id'] ), 'tmg_io_run' ) ); ?>"><?php esc_html_e( 'Cancel', 'tamago-io' ); ?></a>
			</p>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Progress and report
	 * ------------------------------------------------------------------- */

	private static function render_run( array $run ): void {
		$s       = TMG_IO_Importer::status_of( $run );
		$percent = $s['total'] ? (int) floor( 100 * $s['done'] / $s['total'] ) : ( $s['finished'] ? 100 : 0 );
		$running = 'running' === $run['status'];
		?>
		<h2>
			<?php echo esc_html( $run['dry_run'] ? __( 'Dry run', 'tamago-io' ) : __( 'Import', 'tamago-io' ) ); ?>:
			<?php echo esc_html( (string) $run['source'] ); ?>
		</h2>
		<div class="tmg-io-progress" data-run="<?php echo (int) $run['id']; ?>" data-running="<?php echo $running ? '1' : '0'; ?>">
			<progress max="100" value="<?php echo (int) $percent; ?>"></progress>
			<p class="tmg-io-status">
				<?php
				if ( $running ) {
					/* translators: 1: done, 2: total */
					echo esc_html( sprintf( __( '%1$d of %2$d items', 'tamago-io' ), $s['done'], $s['total'] ) );
				} else {
					echo esc_html( 'done' === $run['status'] ? __( 'Finished.', 'tamago-io' ) : ucfirst( (string) $run['status'] ) );
				}
				?>
			</p>
			<?php if ( $running ) : ?>
				<noscript>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="tmg_io_continue">
						<input type="hidden" name="run" value="<?php echo (int) $run['id']; ?>">
						<?php wp_nonce_field( 'tmg_io_continue' ); ?>
						<button class="button button-primary"><?php esc_html_e( 'Continue', 'tamago-io' ); ?></button>
					</form>
				</noscript>
			<?php endif; ?>
		</div>
		<div class="tmg-io-result" <?php echo $running ? 'hidden' : ''; ?>>
			<?php self::render_counts( (array) $s['counts'], (bool) $run['dry_run'] ); ?>
			<p>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tmg_io_report&format=csv&run=' . (int) $run['id'] ), 'tmg_io_run' ) ); ?>"><?php esc_html_e( 'Download report (CSV)', 'tamago-io' ); ?></a>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tmg_io_report&format=xlsx&run=' . (int) $run['id'] ), 'tmg_io_run' ) ); ?>"><?php esc_html_e( 'Download report (Excel)', 'tamago-io' ); ?></a>
				<?php if ( $run['dry_run'] && ! empty( $run['options']['upload'] ) && TMG_IO_Runs::get( (int) $run['options']['upload'] ) ) : ?>
					<a class="button button-primary" href="<?php echo esc_url( TMG_IO_Admin_Screen::url( array( 'tab' => 'import', 'step' => 'preview', 'run' => (int) $run['options']['upload'] ) ) ); ?>"><?php esc_html_e( 'Back to the import options', 'tamago-io' ); ?></a>
				<?php endif; ?>
				<a href="<?php echo esc_url( TMG_IO_Admin_Screen::url( array( 'tab' => 'history' ) ) ); ?>"><?php esc_html_e( 'History', 'tamago-io' ); ?></a>
			</p>
			<?php if ( ! $running ) : ?>
				<?php self::render_report_items( (array) $run['report'], (bool) $run['dry_run'] ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function render_counts( array $counts, bool $dry ): void {
		if ( ! $counts ) {
			return;
		}
		echo '<table class="widefat striped tmg-io-counts"><thead><tr><th>' . esc_html__( 'Section', 'tamago-io' ) . '</th>';
		$actions = array( 'created', 'updated', 'skipped', 'error' );
		foreach ( $actions as $a ) {
			echo '<th>' . esc_html( ( $dry ? __( 'would be', 'tamago-io' ) . ' ' : '' ) . $a ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $counts as $section => $c ) {
			echo '<tr><td>' . esc_html( TMG_IO_Importer::phase_label( (string) $section ) ) . '</td>';
			foreach ( $actions as $a ) {
				echo '<td>' . (int) ( $c[ $a ] ?? 0 ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_report_items( array $items, bool $dry ): void {
		$show = array_values( array_filter( $items, fn( $i ) => in_array( $i['a'] ?? '', array( 'error', 'note' ), true ) || '' !== ( $i['m'] ?? '' ) ) );
		if ( ! $show ) {
			return;
		}
		echo '<h3>' . esc_html__( 'Errors and notes', 'tamago-io' ) . '</h3>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Section', 'tamago-io' ) . '</th><th>' . esc_html__( 'Item', 'tamago-io' ) . '</th><th>' . esc_html__( 'Result', 'tamago-io' ) . '</th><th>' . esc_html__( 'Message', 'tamago-io' ) . '</th></tr></thead><tbody>';
		foreach ( array_slice( $show, 0, 300 ) as $i ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( TMG_IO_Importer::phase_label( (string) $i['s'] ) ),
				esc_html( trim( (string) $i['t'] . ' #' . (string) $i['id'] ) ),
				esc_html( ( $dry ? __( 'would be', 'tamago-io' ) . ' ' : '' ) . (string) $i['a'] ),
				esc_html( (string) $i['m'] )
			);
		}
		echo '</tbody></table>';
		if ( count( $show ) > 300 ) {
			echo '<p class="description">' . esc_html__( 'More lines in the downloadable report.', 'tamago-io' ) . '</p>';
		}
	}
}
