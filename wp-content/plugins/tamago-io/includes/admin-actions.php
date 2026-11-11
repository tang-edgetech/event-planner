<?php
/**
 * Admin handlers (admin-post.php and admin-ajax.php): exports, presets, data sources, uploads,
 * mapping, import options, starting runs, AJAX steps, reports, history, settings.
 * Every handler checks a nonce and its own capability.
 *
 * Screens: Import & Export (all tabs post here).
 */

defined( 'ABSPATH' ) || exit;

class TMG_IO_Admin_Actions {

	public static function init(): void {
		$posts = array( 'export', 'preset_run', 'preset_delete', 'source_export', 'upload', 'map', 'options', 'start', 'continue', 'report', 'run_delete', 'cancel', 'settings', 'settings_export', 'settings_import', 'template_delete' );
		foreach ( $posts as $a ) {
			add_action( 'admin_post_tmg_io_' . $a, array( __CLASS__, 'continue' === $a ? 'continue_run' : $a ) );
		}
		add_action( 'wp_ajax_tmg_io_step', array( __CLASS__, 'ajax_step' ) );
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	private static function deny(): void {
		wp_die( esc_html__( 'You are not allowed to do this.', 'tamago-io' ), esc_html__( 'Not allowed', 'tamago-io' ), array( 'response' => 403, 'back_link' => true ) );
	}

	private static function need( string $cap ): void {
		if ( ! current_user_can( $cap ) ) {
			self::deny();
		}
	}

	private static function fail( string $msg, array $args = array() ): void {
		self::notice( $msg, 'error' );
		wp_safe_redirect( TMG_IO_Admin_Screen::url( $args ) );
		exit;
	}

	/** One-time notice shown on the next screen load. */
	public static function notice( string $msg, string $type = 'success' ): void {
		set_transient( 'tmg_io_notice_' . get_current_user_id(), array( 'msg' => $msg, 'type' => $type ), 300 );
	}

	/** Upload run of the current user (status "uploaded"). */
	private static function upload_run( int $id ): array {
		$run = TMG_IO_Runs::get( $id );
		if ( ! $run || 'uploaded' !== $run['status'] || ! TMG_IO_Runs::can_access( $run ) ) {
			self::fail( __( 'The upload has expired or was already imported. Please upload the file again.', 'tamago-io' ), array( 'tab' => 'import' ) );
		}
		return $run;
	}

	/** POST field, unslashed. */
	private static function post( string $key, $default = '' ) {
		return isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : $default; // phpcs:ignore WordPress.Security
	}

	/* ---------------------------------------------------------------------
	 * Export
	 * ------------------------------------------------------------------- */

	public static function export(): void {
		check_admin_referer( 'tmg_io_export' );
		self::need( TMG_IO_Permissions::EXPORT );
		$choices = TMG_IO_Exporter::sanitize( (array) self::post( 'c', array() ) );
		if ( '' !== self::post( 'save_preset' ) ) {
			$name = TMG_IO_Presets::save( TMG_IO_Presets::EXPORT, (string) self::post( 'preset_name' ), $choices );
			if ( '' === $name ) {
				self::fail( __( 'Please give the preset a name.', 'tamago-io' ), array( 'tab' => 'export' ) );
			}
			/* translators: %s: preset name */
			self::notice( sprintf( __( 'Preset "%s" saved.', 'tamago-io' ), $name ) );
			wp_safe_redirect( TMG_IO_Admin_Screen::url( array( 'tab' => 'export' ) ) );
			exit;
		}
		if ( '' !== self::post( 'acf_only' ) ) {
			if ( ! TMG_IO_Permissions::can_site_settings() ) {
				self::deny();
			}
			$json = TMG_IO_ACF::export_structure( $choices['acf_structure'] );
			$path = TMG_IO_Storage::new_path( 'json', 'acf' );
			file_put_contents( $path, (string) wp_json_encode( $json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			TMG_IO_Files::send_file( $path, 'acf-export-' . wp_date( 'Y-m-d' ) . '.json', 'application/json' );
		}
		self::send_export( $choices, '' );
	}

	private static function send_export( array $choices, string $label ): void {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 600 ); // phpcs:ignore
		}
		wp_raise_memory_limit( 'admin' );
		try {
			$file = TMG_IO_Exporter::export_logged( $choices, $label );
		} catch ( Throwable $e ) {
			self::fail( $e->getMessage(), array( 'tab' => 'export' ) );
			return;
		}
		TMG_IO_Files::send_file( $file['path'], $file['filename'], $file['mime'] );
	}

	public static function preset_run(): void {
		check_admin_referer( 'tmg_io_preset' );
		self::need( TMG_IO_Permissions::EXPORT );
		$name   = sanitize_text_field( wp_unslash( $_GET['name'] ?? '' ) ); // phpcs:ignore WordPress.Security
		$preset = TMG_IO_Presets::get( TMG_IO_Presets::EXPORT, $name );
		if ( ! $preset ) {
			self::fail( __( 'Preset not found.', 'tamago-io' ), array( 'tab' => 'export' ) );
		}
		/* translators: %s: preset name */
		self::send_export( $preset, sprintf( __( 'Preset: %s', 'tamago-io' ), $name ) );
	}

	public static function preset_delete(): void {
		check_admin_referer( 'tmg_io_preset' );
		self::need( TMG_IO_Permissions::EXPORT );
		TMG_IO_Presets::delete( TMG_IO_Presets::EXPORT, sanitize_text_field( wp_unslash( $_GET['name'] ?? '' ) ) ); // phpcs:ignore WordPress.Security
		self::notice( __( 'Preset deleted.', 'tamago-io' ) );
		wp_safe_redirect( TMG_IO_Admin_Screen::url( array( 'tab' => 'export' ) ) );
		exit;
	}

	public static function source_export(): void {
		check_admin_referer( 'tmg_io_source_export' );
		self::need( TMG_IO_Permissions::EXPORT );
		$id      = sanitize_key( (string) self::post( 'source' ) );
		$sources = TMG_IO_Sources::available();
		if ( ! isset( $sources[ $id ] ) ) {
			self::deny();
		}
		$s       = $sources[ $id ];
		$format  = in_array( self::post( 'format' ), $s['formats'], true ) ? (string) self::post( 'format' ) : $s['formats'][0];
		$choices = (array) self::post( 'choices', array() );
		try {
			$sheets = TMG_IO_Sources::export( $s, $choices );
		} catch ( Throwable $e ) {
			self::fail( $e->getMessage(), array( 'tab' => 'export' ) );
			return;
		}
		$run = TMG_IO_Runs::create(
			array(
				'kind'    => 'export',
				'status'  => 'done',
				'source'  => $s['label'],
				'format'  => $format,
				'summary' => array( 'rows' => array_sum( array_map( fn( $r ) => max( 0, count( $r ) - 1 ), $sheets ) ) ),
			)
		);
		TMG_IO_Runs::update( $run, array( 'finished_at' => current_time( 'mysql', true ) ) );
		tmg_io_send_download( $sheets, sanitize_title( $s['label'] ) . '-' . wp_date( 'Ymd-His' ), $format );
	}

	/* ---------------------------------------------------------------------
	 * Import: upload → (mapping) → preview → run
	 * ------------------------------------------------------------------- */

	public static function upload(): void {
		check_admin_referer( 'tmg_io_upload' );
		self::need( TMG_IO_Permissions::IMPORT );
		$f = $_FILES['file'] ?? null; // phpcs:ignore WordPress.Security
		if ( ! $f || ! empty( $f['error'] ) || empty( $f['tmp_name'] ) || ! is_uploaded_file( $f['tmp_name'] ) ) {
			self::fail( __( 'No file was uploaded, or it is larger than the server allows.', 'tamago-io' ), array( 'tab' => 'import' ) );
		}
		$name   = sanitize_file_name( (string) $f['name'] );
		$source = sanitize_key( (string) self::post( 'into' ) );
		try {
			$type = TMG_IO_Files::validate_upload( (string) $f['tmp_name'], $name, (int) $f['size'] );
		} catch ( Throwable $e ) {
			self::fail( $e->getMessage(), array( 'tab' => 'import' ) );
			return;
		}
		if ( '' !== $source ) {
			$sources = TMG_IO_Sources::available( true );
			if ( ! isset( $sources[ $source ] ) ) {
				self::deny();
			}
		}
		$path = TMG_IO_Storage::new_path( $type, 'upload' );
		if ( ! move_uploaded_file( (string) $f['tmp_name'], $path ) ) {
			self::fail( __( 'The file could not be stored.', 'tamago-io' ), array( 'tab' => 'import' ) );
		}
		$o = array(
			'file'    => $path,
			'name'    => $name,
			'type'    => $type,
			'source'  => $source,
			'choices' => '' !== $source ? map_deep( (array) self::post( 'choices', array() ), 'sanitize_text_field' ) : array(),
		);
		$run = self::register_upload( $o );
		self::after_upload( $run );
	}

	/** Create the "uploaded" run for a stored file and check it can be read. */
	public static function register_upload( array $o ): int {
		try {
			$parsed = TMG_IO_Files::read( $o['file'] );
			$w      = TMG_IO_Importer::work_from_parsed( $parsed, $o['file'], $o );
		} catch ( Throwable $e ) {
			TMG_IO_Storage::delete( $o['file'] );
			self::fail( $e->getMessage(), array( 'tab' => 'import' ) );
			return 0;
		}
		if ( 'rows' === $w['kind'] && ! $w['work']['rows'] ) {
			TMG_IO_Storage::delete( $o['file'] );
			self::fail( __( 'The file has no data rows.', 'tamago-io' ), array( 'tab' => 'import' ) );
		}
		$o['kind']  = $w['kind'];
		$o['sheet'] = $w['sheet'];
		$o['opts']  = TMG_IO_Importer::default_opts();
		if ( 'rows' === $w['kind'] ) {
			$o['opts']['sections'] = array();
		}
		return TMG_IO_Runs::create(
			array(
				'kind'    => 'import',
				'status'  => 'uploaded',
				'source'  => $o['name'],
				'format'  => $o['type'],
				'options' => $o,
			)
		);
	}

	private static function after_upload( int $run ): void {
		$r    = TMG_IO_Runs::get( $run );
		$step = 'rows' === ( $r['options']['kind'] ?? '' ) ? 'map' : 'preview';
		wp_safe_redirect( TMG_IO_Admin_Screen::url( array( 'tab' => 'import', 'step' => $step, 'run' => $run ) ) );
		exit;
	}

	public static function map(): void {
		check_admin_referer( 'tmg_io_map' );
		self::need( TMG_IO_Permissions::IMPORT );
		$run  = self::upload_run( absint( self::post( 'run' ) ) );
		$o    = $run['options'];
		$map  = array();
		$cust = (array) self::post( 'custom', array() );
		foreach ( (array) self::post( 'map', array() ) as $i => $target ) {
			$col = (string) ( self::post( 'col', array() )[ $i ] ?? '' );
			if ( '' === $col ) {
				continue;
			}
			$target = (string) $target;
			if ( '__meta' === $target ) {
				$key    = preg_replace( '/[^A-Za-z0-9_\-.]/', '', (string) ( $cust[ $i ] ?? '' ) );
				$target = '' !== $key ? 'meta:' . $key : '';
			}
			$map[ $col ] = TMG_IO_Mapping::sanitize_target( $target );
		}
		$o['opts']['mapping'] = $map;
		if ( '' === $o['source'] ) {
			$pt = sanitize_key( (string) self::post( 'post_type' ) );
			if ( ! isset( TMG_IO_Exporter::exportable_types()[ $pt ] ) && ! post_type_exists( $pt ) ) {
				self::fail( __( 'Choose a content type.', 'tamago-io' ), array( 'tab' => 'import', 'step' => 'map', 'run' => $run['id'] ) );
			}
			$o['opts']['post_type'] = $pt;
		}
		$tpl = (string) self::post( 'template_name' );
		if ( '' !== trim( $tpl ) ) {
			TMG_IO_Presets::save(
				TMG_IO_Presets::MAPPING,
				$tpl,
				array(
					'post_type' => $o['opts']['post_type'],
					'source'    => $o['source'],
					'mapping'   => $map,
				)
			);
		}
		TMG_IO_Runs::update( $run['id'], array( 'options' => $o ) );
		wp_safe_redirect( TMG_IO_Admin_Screen::url( array( 'tab' => 'import', 'step' => 'preview', 'run' => $run['id'] ) ) );
		exit;
	}

	/** Import choices from the preview form. */
	private static function opts_from_post( array $o ): array {
		$raw = (array) self::post( 'o', array() );
		$cur = (array) ( $o['opts'] ?? array() );
		foreach ( array( 'empty_missing', 'media', 'comments', 'keep_ids', 'menu_locations' ) as $k ) {
			$raw[ $k ] = empty( $raw[ $k ] ) ? 0 : 1;
		}
		if ( 'package' === ( $o['kind'] ?? '' ) ) {
			$raw['sections'] = (array) ( $raw['sections'] ?? array() );
		} else {
			$raw['sections'] = array();
		}
		if ( 'meta' === ( $raw['match'] ?? '' ) ) {
			$raw['match'] = 'meta:' . (string) ( $raw['match_key'] ?? '' );
		}
		$raw['mapping']   = $cur['mapping'] ?? array();
		$raw['post_type'] = $cur['post_type'] ?? 'post';
		$raw['source']    = $o['source'] ?? '';
		$raw['choices']   = $o['choices'] ?? array();
		return TMG_IO_Importer::sanitize_opts( $raw );
	}

	public static function options(): void {
		check_admin_referer( 'tmg_io_options' );
		self::need( TMG_IO_Permissions::IMPORT );
		$run              = self::upload_run( absint( self::post( 'run' ) ) );
		$o                = $run['options'];
		$o['opts']        = self::opts_from_post( $o );
		TMG_IO_Runs::update( $run['id'], array( 'options' => $o ) );
		wp_safe_redirect( TMG_IO_Admin_Screen::url( array( 'tab' => 'import', 'step' => 'preview', 'run' => $run['id'] ) ) );
		exit;
	}

	public static function start(): void {
		check_admin_referer( 'tmg_io_options' );
		self::need( TMG_IO_Permissions::IMPORT );
		$run       = self::upload_run( absint( self::post( 'run' ) ) );
		$o         = $run['options'];
		$o['opts'] = self::opts_from_post( $o );
		$dry       = '' !== self::post( 'dry' );
		$o['opts']['dry_run'] = $dry ? 1 : 0;
		TMG_IO_Runs::update( $run['id'], array( 'options' => $o ) );
		$new = self::start_run( $run['id'] );
		wp_safe_redirect( TMG_IO_Admin_Screen::url( array( 'tab' => 'import', 'step' => 'run', 'run' => $new ) ) );
		exit;
	}

	/**
	 * Start a run from an upload. A dry run leaves the upload in place so the real import can
	 * follow; the real import takes over the file and the upload entry.
	 */
	public static function start_run( int $upload_id ): int {
		$run = TMG_IO_Runs::get( $upload_id );
		$o   = $run['options'];
		try {
			$parsed = TMG_IO_Files::read( (string) $o['file'] );
			$w      = TMG_IO_Importer::work_from_parsed( $parsed, (string) $o['file'], $o );
		} catch ( Throwable $e ) {
			self::fail( $e->getMessage(), array( 'tab' => 'import' ) );
			return 0;
		}
		$dry   = ! empty( $o['opts']['dry_run'] );
		$label = (string) $o['name'];
		$new   = TMG_IO_Importer::create_run( $w['work'], $o['opts'], $label, (string) $o['type'], $dry ? '' : (string) $o['file'] );
		if ( ! $dry ) {
			// The file now belongs to the real run; remove the upload entry without deleting it.
			global $wpdb;
			$wpdb->delete( TMG_IO_Runs::table(), array( 'id' => $upload_id ) );
		} else {
			$r                     = TMG_IO_Runs::get( $new );
			$r['options']['upload'] = $upload_id;
			TMG_IO_Runs::update( $new, array( 'options' => $r['options'] ) );
		}
		return $new;
	}

	public static function ajax_step(): void {
		check_ajax_referer( 'tmg_io_step' );
		if ( ! current_user_can( TMG_IO_Permissions::IMPORT ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'tamago-io' ) ), 403 );
		}
		$id  = absint( $_POST['run'] ?? 0 ); // phpcs:ignore WordPress.Security
		$run = TMG_IO_Runs::get( $id );
		if ( ! $run || ! TMG_IO_Runs::can_access( $run ) ) {
			wp_send_json_error( array( 'message' => __( 'Run not found.', 'tamago-io' ) ), 404 );
		}
		$status = TMG_IO_Importer::step( $id );
		if ( ! empty( $status['error'] ) ) {
			wp_send_json_error( array( 'message' => $status['error'] ) );
		}
		$status['percent'] = $status['total'] ? (int) floor( 100 * $status['done'] / $status['total'] ) : 100;
		wp_send_json_success( $status );
	}

	/** Without JavaScript: run steps for a while, then come back to the progress page. */
	public static function continue_run(): void {
		check_admin_referer( 'tmg_io_continue' );
		self::need( TMG_IO_Permissions::IMPORT );
		$id  = absint( self::post( 'run' ) );
		$run = TMG_IO_Runs::get( $id );
		if ( ! $run || ! TMG_IO_Runs::can_access( $run ) ) {
			self::deny();
		}
		$end = microtime( true ) + 20;
		do {
			$s = TMG_IO_Importer::step( $id );
		} while ( empty( $s['finished'] ) && empty( $s['error'] ) && microtime( true ) < $end );
		wp_safe_redirect( TMG_IO_Admin_Screen::url( array( 'tab' => 'import', 'step' => 'run', 'run' => $id ) ) );
		exit;
	}

	public static function cancel(): void {
		check_admin_referer( 'tmg_io_run' );
		self::need( TMG_IO_Permissions::IMPORT );
		$id  = absint( $_GET['run'] ?? 0 ); // phpcs:ignore WordPress.Security
		$run = TMG_IO_Runs::get( $id );
		if ( ! $run || ! TMG_IO_Runs::can_access( $run ) ) {
			self::deny();
		}
		if ( 'uploaded' === $run['status'] ) {
			TMG_IO_Runs::delete( $id );
		} else {
			TMG_IO_Importer::cancel( $id );
		}
		self::notice( __( 'Import cancelled.', 'tamago-io' ) );
		wp_safe_redirect( TMG_IO_Admin_Screen::url( array( 'tab' => 'history' ) ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * History and reports
	 * ------------------------------------------------------------------- */

	public static function report(): void {
		check_admin_referer( 'tmg_io_run' );
		if ( ! current_user_can( TMG_IO_Permissions::IMPORT ) && ! current_user_can( TMG_IO_Permissions::EXPORT ) ) {
			self::deny();
		}
		$id  = absint( $_GET['run'] ?? 0 ); // phpcs:ignore WordPress.Security
		$run = TMG_IO_Runs::get( $id );
		if ( ! $run || ! TMG_IO_Runs::can_access( $run ) ) {
			self::deny();
		}
		$format = 'xlsx' === ( $_GET['format'] ?? '' ) ? 'xlsx' : 'csv'; // phpcs:ignore WordPress.Security
		tmg_io_send_download( array( 'Report' => TMG_IO_Runs::report_table( $run ) ), 'import-report-' . $id, $format );
	}

	public static function run_delete(): void {
		check_admin_referer( 'tmg_io_run' );
		if ( ! current_user_can( TMG_IO_Permissions::IMPORT ) && ! current_user_can( TMG_IO_Permissions::EXPORT ) ) {
			self::deny();
		}
		$id  = absint( $_GET['run'] ?? 0 ); // phpcs:ignore WordPress.Security
		$run = TMG_IO_Runs::get( $id );
		if ( ! $run || ! TMG_IO_Runs::can_access( $run ) ) {
			self::deny();
		}
		TMG_IO_Runs::delete( $id );
		self::notice( __( 'History entry deleted.', 'tamago-io' ) );
		wp_safe_redirect( TMG_IO_Admin_Screen::url( array( 'tab' => 'history' ) ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------- */

	public static function settings(): void {
		check_admin_referer( 'tmg_io_settings' );
		self::need( TMG_IO_Permissions::MANAGE );
		TMG_IO_Settings::save( (array) self::post( 's', array() ), true );
		self::notice( __( 'Settings saved.', 'tamago-io' ) );
		wp_safe_redirect( TMG_IO_Admin_Screen::url( array( 'tab' => 'settings' ) ) );
		exit;
	}

	public static function settings_export(): void {
		check_admin_referer( 'tmg_io_settings' );
		self::need( TMG_IO_Permissions::MANAGE );
		$path = TMG_IO_Storage::new_path( 'json', 'settings' );
		file_put_contents( $path, (string) wp_json_encode( TMG_IO_Settings::export_data(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		TMG_IO_Files::send_file( $path, 'tamago-io-settings-' . wp_date( 'Ymd' ) . '.json', 'application/json' );
	}

	public static function settings_import(): void {
		check_admin_referer( 'tmg_io_settings' );
		self::need( TMG_IO_Permissions::MANAGE );
		$f = $_FILES['file'] ?? null; // phpcs:ignore WordPress.Security
		if ( ! $f || ! empty( $f['error'] ) || ! is_uploaded_file( (string) $f['tmp_name'] ) || (int) $f['size'] > MB_IN_BYTES || 'json' !== TMG_IO_Files::sniff( (string) $f['tmp_name'] ) ) {
			self::fail( __( 'Please choose a settings JSON file.', 'tamago-io' ), array( 'tab' => 'settings' ) );
		}
		$data = json_decode( (string) file_get_contents( (string) $f['tmp_name'] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$err  = TMG_IO_Settings::import_data( is_array( $data ) ? $data : array() );
		if ( '' !== $err ) {
			self::fail( $err, array( 'tab' => 'settings' ) );
		}
		self::notice( __( 'Settings imported.', 'tamago-io' ) );
		wp_safe_redirect( TMG_IO_Admin_Screen::url( array( 'tab' => 'settings' ) ) );
		exit;
	}

	public static function template_delete(): void {
		check_admin_referer( 'tmg_io_template' );
		self::need( TMG_IO_Permissions::IMPORT );
		TMG_IO_Presets::delete( TMG_IO_Presets::MAPPING, sanitize_text_field( wp_unslash( $_GET['name'] ?? '' ) ) ); // phpcs:ignore WordPress.Security
		self::notice( __( 'Mapping template deleted.', 'tamago-io' ) );
		wp_safe_redirect( TMG_IO_Admin_Screen::url( array( 'tab' => 'settings' ) ) );
		exit;
	}
}
