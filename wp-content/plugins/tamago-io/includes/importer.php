<?php
/**
 * Importer: runs a package (or mapped rows, or a data source's rows) in resumable, batched
 * phases with ID remapping across the whole run. Acts as the current user: every item checks
 * the matching WordPress capability. Dry runs go through the same steps without writing.
 *
 * Phases: ACF structure → terms → users → media → posts (create / match) → post data (parents,
 * meta with remapped IDs, terms, comments) → term meta → user meta → menus → options → rows / source.
 *
 * Used by: Import tab (AJAX steps), WP-CLI `wp tamago-io import`, tests.
 */

defined( 'ABSPATH' ) || exit;

class TMG_IO_Importer {

	const PHASES = array( 'acf_structure', 'terms', 'users', 'media', 'posts', 'post_data', 'term_data', 'user_data', 'menus', 'options', 'rows', 'source' );

	/** Rows handed to a data source per call. */
	const SOURCE_CHUNK = 200;

	private $run_id = 0;
	private $opts   = array();
	private $work   = array();
	private $state  = array();
	private $paths  = array();
	private $notes  = array();
	private $items_cache = array();
	private $term_index  = null;

	/* ---------------------------------------------------------------------
	 * Options
	 * ------------------------------------------------------------------- */

	public static function default_opts(): array {
		return array(
			'match'          => 'slug',
			'match_key'      => '',
			'existing'       => 'skip',
			'empty_missing'  => 0,
			'media'          => 1,
			'comments'       => 1,
			'keep_ids'       => 0,
			'menu_locations' => 1,
			'dry_run'        => 0,
			'sections'       => array( 'acf_structure', 'terms', 'users', 'media', 'posts', 'menus', 'options' ),
			'post_type'      => 'post',
			'mapping'        => array(),
			'source'         => '',
			'choices'        => array(),
		);
	}

	public static function sanitize_opts( array $raw ): array {
		$d   = self::default_opts();
		$out = $d;
		$m   = sanitize_text_field( (string) ( $raw['match'] ?? $d['match'] ) );
		if ( 0 === strpos( $m, 'meta:' ) ) {
			$out['match']     = 'meta';
			$out['match_key'] = substr( $m, 5 );
		} else {
			$out['match']     = in_array( $m, array( 'id', 'slug', 'guid', 'meta', 'none' ), true ) ? $m : 'slug';
			$out['match_key'] = sanitize_text_field( (string) ( $raw['match_key'] ?? '' ) );
		}
		$out['match_key'] = preg_replace( '/[^A-Za-z0-9_\-:.]/', '', $out['match_key'] );
		if ( 'meta' === $out['match'] && '' === $out['match_key'] ) {
			$out['match'] = 'slug';
		}
		$out['existing'] = in_array( $raw['existing'] ?? '', array( 'skip', 'update', 'copy' ), true ) ? $raw['existing'] : 'skip';
		foreach ( array( 'empty_missing', 'media', 'comments', 'keep_ids', 'menu_locations', 'dry_run' ) as $k ) {
			$out[ $k ] = array_key_exists( $k, $raw ) ? ( empty( $raw[ $k ] ) ? 0 : 1 ) : $d[ $k ];
		}
		if ( isset( $raw['sections'] ) ) {
			$out['sections'] = array_values( array_intersect( array_map( 'sanitize_key', (array) $raw['sections'] ), $d['sections'] ) );
		}
		$out['post_type'] = sanitize_key( (string) ( $raw['post_type'] ?? $d['post_type'] ) );
		$out['source']    = sanitize_key( (string) ( $raw['source'] ?? '' ) );
		$map              = array();
		foreach ( (array) ( $raw['mapping'] ?? array() ) as $col => $target ) {
			$map[ sanitize_text_field( (string) $col ) ] = TMG_IO_Mapping::sanitize_target( (string) $target );
		}
		$out['mapping'] = $map;
		$out['choices'] = map_deep( (array) ( $raw['choices'] ?? array() ), 'sanitize_text_field' );
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * From a file to the work of a run
	 * ------------------------------------------------------------------- */

	/**
	 * Turn a read file into run work.
	 *
	 * @param array  $parsed TMG_IO_Files::read() result.
	 * @param string $file   The file (ZIP packages keep reading media from it).
	 * @param array  $o      'source', 'sheet' choices.
	 * @return array{kind: string, work: array, sheets: array, sheet: string, headers: array, sample: array}
	 */
	public static function work_from_parsed( array $parsed, string $file, array $o = array() ): array {
		$sheets = (array) ( $parsed['sheets'] ?? array() );
		$names  = array_keys( $sheets );
		$source = (string) ( $o['source'] ?? '' );
		if ( '' === $source ) {
			if ( isset( $parsed['package'] ) ) {
				return array(
					'kind'    => 'package',
					'work'    => array(
						'package' => $parsed['package'],
						'zip'     => 'zip' === ( $parsed['type'] ?? '' ) ? $file : '',
					),
					'sheets'  => array(),
					'sheet'   => '',
					'headers' => array(),
					'sample'  => array(),
				);
			}
			if ( TMG_IO_Flatten::has_json( $sheets ) ) {
				return array(
					'kind'    => 'package',
					'work'    => array( 'package' => TMG_IO_Flatten::to_package( $sheets ), 'zip' => '' ),
					'sheets'  => array(),
					'sheet'   => '',
					'headers' => array(),
					'sample'  => array(),
				);
			}
		}
		$sheet = (string) ( $o['sheet'] ?? '' );
		if ( ! isset( $sheets[ $sheet ] ) ) {
			$sheet = '';
			foreach ( $sheets as $n => $rows ) {
				if ( count( (array) $rows ) > 1 ) {
					$sheet = (string) $n;
					break;
				}
			}
			$sheet = '' !== $sheet ? $sheet : (string) ( $names[0] ?? '' );
		}
		$table   = (array) ( $sheets[ $sheet ] ?? array() );
		$records = TMG_IO_Files::table_to_records( $table );
		return array(
			'kind'    => 'rows',
			'work'    => array( 'rows' => $records ),
			'sheets'  => $names,
			'sheet'   => $sheet,
			'headers' => array_keys( (array) ( $records[0] ?? array() ) ) ? array_keys( $records[0] ) : array_map( 'strval', (array) ( $table[0] ?? array() ) ),
			'sample'  => (array) ( $records[0] ?? array() ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Runs
	 * ------------------------------------------------------------------- */

	/**
	 * Create a run.
	 *
	 * @param array  $work  ['package' => array, 'zip' => path] | ['rows' => records] (mapping / source).
	 * @param string $upload Uploaded file to delete when the run ends.
	 */
	public static function create_run( array $work, array $opts, string $label, string $format, string $upload = '' ): int {
		$opts      = self::sanitize_opts( $opts );
		$work_path = TMG_IO_Storage::new_path( 'json', 'work' );
		file_put_contents( $work_path, (string) wp_json_encode( $work, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$state_path = TMG_IO_Storage::new_path( 'json', 'state' );
		$self       = new self();
		$self->opts = $opts;
		$self->work = $work;
		$state      = array(
			'phase'   => 0,
			'cursor'  => 0,
			'done'    => 0,
			'total'   => $self->total(),
			'maps'    => array(
				'post'  => array(),
				'term'  => array(),
				'user'  => array(),
				'media' => array(),
			),
			'written' => array(
				'post' => array(),
				'term' => array(),
				'user' => array(),
			),
			'urls'    => array(),
			'counts'  => array(),
			'report'  => array(),
			'fake'    => 0,
			'flags'   => array(),
		);
		file_put_contents( $state_path, (string) wp_json_encode( $state ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return TMG_IO_Runs::create(
			array(
				'kind'    => 'import',
				'status'  => 'running',
				'dry_run' => $opts['dry_run'],
				'source'  => $label,
				'format'  => $format,
				'summary' => array(),
				'options' => array(
					'opts'  => $opts,
					'work'  => $work_path,
					'state' => $state_path,
					'file'  => $upload,
				),
			)
		);
	}

	/** Process items until the time budget or batch size is used, then save. */
	public static function step( int $run_id, float $seconds = 0 ): array {
		$run = TMG_IO_Runs::get( $run_id );
		if ( ! $run || 'import' !== $run['kind'] ) {
			return array( 'error' => __( 'Run not found.', 'tamago-io' ) );
		}
		if ( 'running' !== $run['status'] ) {
			return self::status_of( $run );
		}
		$lock = 'tmg_io_lock_' . $run_id;
		if ( get_transient( $lock ) ) {
			$s           = self::status_of( $run );
			$s['busy'] = true;
			return $s;
		}
		set_transient( $lock, 1, 120 );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore
		}
		wp_raise_memory_limit( 'admin' );
		$self = new self();
		try {
			if ( ! $self->load( $run ) ) {
				TMG_IO_Runs::update( $run_id, array( 'status' => 'failed', 'finished_at' => current_time( 'mysql', true ) ) );
				delete_transient( $lock );
				return array( 'error' => __( 'The working files of this run are missing. Please upload the file again.', 'tamago-io' ) );
			}
			$deadline = microtime( true ) + ( $seconds > 0 ? $seconds : (int) TMG_IO_Settings::get( 'step_seconds' ) );
			$max      = max( 1, (int) TMG_IO_Settings::get( 'batch_size' ) );
			$n        = 0;
			while ( ! $self->finished() && $n < $max && microtime( true ) < $deadline ) {
				if ( $self->tick() ) {
					++$n;
				}
			}
			if ( $self->finished() ) {
				$self->finish();
			} else {
				$self->save();
			}
		} catch ( Throwable $e ) {
			$self->report( 'run', '', '', 'error', '', $e->getMessage() );
			$self->save();
			TMG_IO_Runs::update( $run_id, array( 'status' => 'failed', 'report' => $self->state['report'], 'summary' => $self->state['counts'], 'finished_at' => current_time( 'mysql', true ) ) );
		}
		delete_transient( $lock );
		return $self->status();
	}

	/** Run every step (WP-CLI, tests, no-JS fallback). */
	public static function run_all( int $run_id, ?callable $progress = null ): array {
		$status = array();
		for ( $i = 0; $i < 100000; $i++ ) {
			$status = self::step( $run_id, 60 );
			if ( $progress ) {
				$progress( $status );
			}
			if ( ! empty( $status['error'] ) || ! empty( $status['finished'] ) ) {
				break;
			}
			if ( ! empty( $status['busy'] ) ) {
				delete_transient( 'tmg_io_lock_' . $run_id );
			}
		}
		return $status;
	}

	public static function cancel( int $run_id ): void {
		$run = TMG_IO_Runs::get( $run_id );
		if ( ! $run ) {
			return;
		}
		foreach ( array( 'work', 'state', 'file' ) as $k ) {
			TMG_IO_Storage::delete( (string) ( $run['options'][ $k ] ?? '' ) );
		}
		TMG_IO_Runs::update( $run_id, array( 'status' => 'cancelled', 'finished_at' => current_time( 'mysql', true ) ) );
	}

	private function load( array $run ): bool {
		$this->run_id = (int) $run['id'];
		$this->opts   = self::sanitize_opts( (array) ( $run['options']['opts'] ?? array() ) );
		$this->paths  = $run['options'];
		$work         = (string) ( $run['options']['work'] ?? '' );
		$state        = (string) ( $run['options']['state'] ?? '' );
		if ( ! TMG_IO_Storage::owns( $work ) || ! TMG_IO_Storage::owns( $state ) ) {
			return false;
		}
		$this->work  = (array) json_decode( (string) file_get_contents( $work ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->state = (array) json_decode( (string) file_get_contents( $state ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return isset( $this->state['phase'] );
	}

	private function save(): void {
		TMG_IO_ACF::flush_values();
		file_put_contents( (string) $this->paths['state'], (string) wp_json_encode( $this->state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		TMG_IO_Runs::update( $this->run_id, array( 'summary' => $this->summary() ) );
	}

	private function finish(): void {
		TMG_IO_ACF::flush_values();
		if ( ! empty( $this->state['flags']['structure'] ) && ! $this->dry() ) {
			delete_option( 'rewrite_rules' );
		}
		TMG_IO_Runs::update(
			$this->run_id,
			array(
				'status'      => 'done',
				'summary'     => $this->summary(),
				'report'      => $this->state['report'],
				'finished_at' => current_time( 'mysql', true ),
			)
		);
		foreach ( array( 'work', 'state', 'file' ) as $k ) {
			TMG_IO_Storage::delete( (string) ( $this->paths[ $k ] ?? '' ) );
		}
		$this->state['finished'] = true;
		do_action( 'tmg_io_import_finished', $this->run_id, $this->state['counts'], $this->opts );
	}

	private function summary(): array {
		return array(
			'counts' => $this->state['counts'],
			'done'   => (int) $this->state['done'],
			'total'  => (int) $this->state['total'],
		);
	}

	public function status(): array {
		$phase = self::PHASES[ $this->state['phase'] ] ?? 'done';
		return array(
			'run'      => $this->run_id,
			'phase'    => $phase,
			'label'    => self::phase_label( $phase ),
			'done'     => (int) $this->state['done'],
			'total'    => (int) $this->state['total'],
			'counts'   => $this->state['counts'],
			'finished' => ! empty( $this->state['finished'] ),
			'dry_run'  => (bool) $this->opts['dry_run'],
		);
	}

	public static function status_of( array $run ): array {
		$s = (array) $run['summary'];
		return array(
			'run'      => (int) $run['id'],
			'phase'    => $run['status'],
			'label'    => $run['status'],
			'done'     => (int) ( $s['done'] ?? 0 ),
			'total'    => (int) ( $s['total'] ?? 0 ),
			'counts'   => (array) ( $s['counts'] ?? array() ),
			'finished' => in_array( $run['status'], array( 'done', 'failed', 'cancelled' ), true ),
			'dry_run'  => (bool) $run['dry_run'],
		);
	}

	public static function phase_label( string $phase ): string {
		$l = array(
			'acf_structure' => __( 'ACF structure', 'tamago-io' ),
			'acf'           => __( 'ACF structure', 'tamago-io' ),
			'run'           => __( 'Run', 'tamago-io' ),
			'terms'         => __( 'Terms', 'tamago-io' ),
			'users'         => __( 'Users', 'tamago-io' ),
			'media'         => __( 'Media', 'tamago-io' ),
			'posts'         => __( 'Content', 'tamago-io' ),
			'post_data'     => __( 'Custom fields and links', 'tamago-io' ),
			'term_data'     => __( 'Term fields', 'tamago-io' ),
			'user_data'     => __( 'User fields', 'tamago-io' ),
			'menus'         => __( 'Menus', 'tamago-io' ),
			'options'       => __( 'Options', 'tamago-io' ),
			'rows'          => __( 'Rows', 'tamago-io' ),
			'source'        => __( 'Rows', 'tamago-io' ),
			'done'          => __( 'Finished', 'tamago-io' ),
		);
		return $l[ $phase ] ?? $phase;
	}

	/* ---------------------------------------------------------------------
	 * Phase engine
	 * ------------------------------------------------------------------- */

	private function finished(): bool {
		return ! empty( $this->state['finished'] ) || $this->state['phase'] >= count( self::PHASES );
	}

	private function section_on( string $s ): bool {
		return in_array( $s, (array) $this->opts['sections'], true );
	}

	private function items( string $phase ): array {
		if ( isset( $this->items_cache[ $phase ] ) ) {
			return $this->items_cache[ $phase ];
		}
		$pkg = (array) ( $this->work['package'] ?? array() );
		$out = array();
		switch ( $phase ) {
			case 'acf_structure':
				$out = $this->section_on( 'acf_structure' ) ? (array) ( $pkg['acf_structure'] ?? array() ) : array();
				break;
			case 'terms':
			case 'term_data':
				$out = $this->section_on( 'terms' ) ? (array) ( $pkg['terms'] ?? array() ) : array();
				break;
			case 'users':
			case 'user_data':
				$out = $this->section_on( 'users' ) ? (array) ( $pkg['users'] ?? array() ) : array();
				break;
			case 'media':
				$out = array_keys( (array) ( $pkg['media'] ?? array() ) );
				break;
			case 'posts':
			case 'post_data':
				$out = $this->section_on( 'posts' ) ? (array) ( $pkg['posts'] ?? array() ) : array();
				break;
			case 'menus':
				$out = $this->section_on( 'menus' ) ? (array) ( $pkg['menus'] ?? array() ) : array();
				break;
			case 'options':
				$out = $this->section_on( 'options' ) ? array_merge( array_keys( (array) ( $pkg['options'] ?? array() ) ), array_keys( (array) ( $pkg['options_raw'] ?? array() ) ) ) : array();
				break;
			case 'rows':
				$out = '' === $this->opts['source'] ? (array) ( $this->work['rows'] ?? array() ) : array();
				break;
			case 'source':
				$out = '' !== $this->opts['source'] ? range( 0, max( 0, (int) ceil( count( (array) ( $this->work['rows'] ?? array() ) ) / self::SOURCE_CHUNK ) - 1 ) ) : array();
				if ( '' !== $this->opts['source'] && ! ( $this->work['rows'] ?? array() ) ) {
					$out = array();
				}
				break;
		}
		$this->items_cache[ $phase ] = array_values( $out );
		return $this->items_cache[ $phase ];
	}

	private function total(): int {
		$n = 0;
		foreach ( self::PHASES as $p ) {
			$n += count( $this->items( $p ) );
		}
		return $n;
	}

	/** Process one item (true) or move to the next phase (false). */
	private function tick(): bool {
		$phase = self::PHASES[ $this->state['phase'] ];
		$items = $this->items( $phase );
		$i     = (int) $this->state['cursor'];
		if ( $i >= count( $items ) ) {
			if ( 'acf_structure' === $phase && ! empty( $this->state['flags']['structure'] ) && ! $this->dry() ) {
				TMG_IO_ACF::register_imported();
			}
			++$this->state['phase'];
			$this->state['cursor'] = 0;
			return false;
		}
		$item        = $items[ $i ];
		$this->notes = array();
		try {
			switch ( $phase ) {
				case 'acf_structure':
					$this->do_acf( (array) $item );
					break;
				case 'terms':
					$this->do_term( (array) $item );
					break;
				case 'users':
					$this->do_user( (array) $item );
					break;
				case 'media':
					$this->do_media( (string) $item );
					break;
				case 'posts':
					$this->do_post( (array) $item );
					break;
				case 'post_data':
					$this->do_post_data( (array) $item );
					break;
				case 'term_data':
					$this->do_object_data( 'term', (array) $item );
					break;
				case 'user_data':
					$this->do_object_data( 'user', (array) $item );
					break;
				case 'menus':
					$this->do_menu( (array) $item );
					break;
				case 'options':
					$this->do_option( (string) $item );
					break;
				case 'rows':
					TMG_IO_Mapping::import_row( $this, (array) $item, $i );
					break;
				case 'source':
					$this->do_source( (int) $item );
					break;
			}
		} catch ( Throwable $e ) {
			$this->report( $phase, is_array( $item ) ? ( $item['id'] ?? $item['key'] ?? $i ) : $item, '', 'error', '', $e->getMessage() );
		}
		++$this->state['cursor'];
		++$this->state['done'];
		return true;
	}

	/* ---------------------------------------------------------------------
	 * Context for conversions and mapping
	 * ------------------------------------------------------------------- */

	public function dry(): bool {
		return ! empty( $this->opts['dry_run'] );
	}

	public function opts(): array {
		return $this->opts;
	}

	public function work(): array {
		return $this->work;
	}

	/** A placeholder ID for items a dry run would create. */
	public function fake_id(): int {
		--$this->state['fake'];
		return (int) $this->state['fake'];
	}

	/** Remember a message for the next report line. */
	public function note( string $msg ): void {
		$this->notes[] = $msg;
	}

	public function may_download(): bool {
		return ! empty( $this->opts['media'] ) && (bool) TMG_IO_Settings::get( 'remote_media' );
	}

	public function report( string $section, $id, string $title, string $action, $new = '', string $msg = '' ): void {
		$msgs = array_filter( array_merge( array( $msg ), $this->notes ) );
		$this->notes = array();
		$this->state['report'][] = array(
			's'  => $section,
			'id' => is_scalar( $id ) ? (string) $id : '',
			't'  => mb_substr( wp_strip_all_tags( $title ), 0, 200 ),
			'a'  => $action,
			'n'  => is_scalar( $new ) ? (string) $new : '',
			'm'  => mb_substr( implode( ' ', array_unique( $msgs ) ), 0, 1000 ),
		);
		if ( ! isset( $this->state['counts'][ $section ][ $action ] ) ) {
			$this->state['counts'][ $section ][ $action ] = 0;
		}
		++$this->state['counts'][ $section ][ $action ];
	}

	public function map( string $kind, $old, int $new ): void {
		$this->state['maps'][ $kind ][ (string) $old ] = $new;
	}

	public function mark_written( string $kind, $old, int $new, string $action ): void {
		$this->state['written'][ $kind ][ (string) $old ] = array( $new, $action );
	}

	/* ---------------------------------------------------------------------
	 * ID resolution
	 * ------------------------------------------------------------------- */

	private function pkg(): array {
		return (array) ( $this->work['package'] ?? array() );
	}

	private function same_site(): bool {
		$site = (string) ( $this->work['package']['site'] ?? '' );
		return '' !== $site && untrailingslashit( $site ) === untrailingslashit( home_url() );
	}

	/** New ID for an old ID of a kind (0 when it cannot be found). */
	public function resolve( string $kind, int $old ): int {
		if ( $old <= 0 ) {
			return 0;
		}
		$map = $this->state['maps'][ $kind ][ (string) $old ] ?? null;
		if ( null !== $map ) {
			return (int) $map;
		}
		$pkg = $this->pkg();
		$new = 0;
		switch ( $kind ) {
			case 'media':
				$info = $pkg['media'][ (string) $old ] ?? null;
				$new  = $info ? TMG_IO_Media::find_existing( (array) $info ) : 0;
				if ( ! $new && ! $info && $this->same_site() && 'attachment' === get_post_type( $old ) ) {
					$new = $old;
				}
				break;
			case 'post':
				$l = $pkg['lookup']['posts'][ (string) $old ] ?? null;
				if ( $l ) {
					if ( 'attachment' === ( $l['type'] ?? '' ) ) {
						$new = $this->resolve( 'media', $old );
						break;
					}
					$new = $this->find_post_by( (string) $l['type'], 'slug', (string) $l['slug'] );
					if ( ! $new && ! empty( $l['guid'] ) ) {
						$new = $this->find_post_by( (string) $l['type'], 'guid', (string) $l['guid'] );
					}
				} elseif ( $this->same_site() && get_post( $old ) ) {
					$new = $old;
				}
				break;
			case 'term':
				$l = $pkg['lookup']['terms'][ (string) $old ] ?? null;
				if ( $l ) {
					$new = $this->ensure_term( (string) $l['taxonomy'], (string) $l['slug'], (string) ( $l['name'] ?? '' ) );
				} elseif ( $this->same_site() && get_term( $old ) && ! is_wp_error( get_term( $old ) ) ) {
					$new = $old;
				}
				break;
			case 'user':
				$l = $pkg['lookup']['users'][ (string) $old ] ?? null;
				if ( $l ) {
					$u   = get_user_by( 'login', (string) $l['login'] );
					$u   = $u ? $u : get_user_by( 'email', (string) $l['email'] );
					$new = $u ? (int) $u->ID : 0;
				} elseif ( $this->same_site() && get_userdata( $old ) ) {
					$new = $old;
				}
				break;
		}
		$this->state['maps'][ $kind ][ (string) $old ] = $new;
		return $new;
	}

	/** Remap IDs inside a value (scalar, list, or comma-separated string). Non-numeric values stay. */
	public function remap( $value, string $kind ) {
		if ( is_array( $value ) ) {
			$out    = array();
			$is_lst = array_is_list( $value );
			foreach ( $value as $k => $v ) {
				if ( is_numeric( $v ) && (int) $v > 0 ) {
					$n = $this->resolve( $kind, (int) $v );
					if ( $n ) {
						$out[ $k ] = is_string( $v ) ? (string) $n : $n;
					}
				} else {
					$out[ $k ] = $v;
				}
			}
			return $is_lst ? array_values( $out ) : $out;
		}
		if ( is_numeric( $value ) && (int) $value > 0 ) {
			$n = $this->resolve( $kind, (int) $value );
			return $n ? ( is_string( $value ) ? (string) $n : $n ) : '';
		}
		if ( is_string( $value ) && preg_match( '/^\d+(,\d+)+$/', $value ) ) {
			return implode( ',', array_filter( array_map( fn( $v ) => $this->resolve( $kind, (int) $v ), explode( ',', $value ) ) ) );
		}
		return $value;
	}

	/** Post of a type by slug / guid / id / meta value (0 when none). */
	public function find_post_by( string $type, string $how, $value, string $key = '' ): int {
		global $wpdb;
		if ( '' === (string) $value || null === $value ) {
			return 0;
		}
		switch ( $how ) {
			case 'id':
				$p = get_post( (int) $value );
				return $p && $p->post_type === $type && 'trash' !== $p->post_status ? (int) $p->ID : 0;
			case 'slug':
				return (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_name = %s AND post_status NOT IN ('trash','auto-draft') ORDER BY ID LIMIT 1", $type, sanitize_title( (string) $value ) ) );
			case 'guid':
				return (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND guid = %s AND post_status NOT IN ('trash','auto-draft') ORDER BY ID LIMIT 1", $type, (string) $value ) );
			case 'meta':
				if ( is_array( $value ) ) {
					return 0;
				}
				return (int) $wpdb->get_var( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE p.post_type = %s AND m.meta_key = %s AND m.meta_value = %s AND p.post_status NOT IN ('trash','auto-draft') ORDER BY p.ID LIMIT 1", $type, $key, (string) $value ) );
		}
		return 0;
	}

	/** Existing post for a package entry, by the chosen match rule. */
	private function match_post( array $p ): int {
		$type = (string) $p['type'];
		switch ( $this->opts['match'] ) {
			case 'id':
				return $this->find_post_by( $type, 'id', (int) $p['id'] );
			case 'guid':
				return $this->find_post_by( $type, 'guid', (string) ( $p['guid'] ?? '' ) );
			case 'meta':
				$k = $this->opts['match_key'];
				$v = $p['meta'][ $k ] ?? ( $p['acf'][ $k ]['value'] ?? '' );
				return is_scalar( $v ) ? $this->find_post_by( $type, 'meta', (string) $v, $k ) : 0;
			case 'none':
				return 0;
		}
		return $this->find_post_by( $type, 'slug', (string) ( $p['slug'] ?? '' ) );
	}

	/** Term ID by taxonomy + slug, created (with its parents) when missing and allowed. */
	public function ensure_term( string $tax, string $slug, string $name = '' ): int {
		if ( ! taxonomy_exists( $tax ) || '' === $slug ) {
			return 0;
		}
		$t = get_term_by( 'slug', $slug, $tax );
		if ( $t ) {
			return (int) $t->term_id;
		}
		$tx = get_taxonomy( $tax );
		if ( ! $tx || ! current_user_can( $tx->cap->manage_terms ) ) {
			return 0;
		}
		if ( null === $this->term_index ) {
			$this->term_index = array();
			foreach ( (array) ( $this->pkg()['lookup']['terms'] ?? array() ) as $id => $l ) {
				$this->term_index[ $l['taxonomy'] . '|' . $l['slug'] ] = $l + array( 'id' => (int) $id );
			}
		}
		$l      = $this->term_index[ $tax . '|' . $slug ] ?? array();
		$name   = '' !== $name ? $name : (string) ( $l['name'] ?? $slug );
		$parent = 0;
		if ( ! empty( $l['parent'] ) && is_taxonomy_hierarchical( $tax ) ) {
			$parent = $this->resolve( 'term', (int) $l['parent'] );
		}
		if ( $this->dry() ) {
			return $this->fake_id();
		}
		$r = wp_insert_term( $name, $tax, array( 'slug' => $slug, 'parent' => max( 0, $parent ) ) );
		if ( is_wp_error( $r ) ) {
			$existing = $r->get_error_data( 'term_exists' );
			return $existing ? (int) $existing : 0;
		}
		return (int) $r['term_id'];
	}

	/* ---------------------------------------------------------------------
	 * Items
	 * ------------------------------------------------------------------- */

	private function do_acf( array $item ): void {
		$title = TMG_IO_ACF::item_title( $item );
		if ( ! TMG_IO_ACF::active() ) {
			$this->report( 'acf', $item['key'] ?? '', $title, 'error', '', __( 'ACF is not active on this site.', 'tamago-io' ) );
			return;
		}
		if ( ! TMG_IO_Permissions::can_site_settings() ) {
			$this->report( 'acf', $item['key'] ?? '', $title, 'error', '', __( 'You are not allowed to change the ACF structure.', 'tamago-io' ) );
			return;
		}
		$r = TMG_IO_ACF::import_item( $item, $this->opts['existing'], $this->dry() );
		if ( in_array( $r['action'], array( 'created', 'updated' ), true ) ) {
			$this->state['flags']['structure'] = 1;
		}
		$this->report( 'acf', $item['key'] ?? '', $title, $r['action'], $r['id'], $r['message'] );
	}

	/** Types / taxonomies the package's ACF structure would add (for dry runs). */
	private function pending_names( string $kind ): array {
		$out = array();
		foreach ( (array) ( $this->pkg()['acf_structure'] ?? array() ) as $item ) {
			if ( 'post_type' === $kind && isset( $item['post_type'] ) && 0 === strpos( (string) ( $item['key'] ?? '' ), 'post_type_' ) ) {
				$out[] = (string) $item['post_type'];
			}
			if ( 'taxonomy' === $kind && isset( $item['taxonomy'] ) && 0 === strpos( (string) ( $item['key'] ?? '' ), 'taxonomy_' ) ) {
				$out[] = (string) $item['taxonomy'];
			}
		}
		return $out;
	}

	private function do_term( array $t ): void {
		$tax  = (string) ( $t['taxonomy'] ?? '' );
		$name = (string) ( $t['name'] ?? '' );
		$old  = (int) ( $t['id'] ?? 0 );
		if ( ! taxonomy_exists( $tax ) ) {
			if ( $this->dry() && in_array( $tax, $this->pending_names( 'taxonomy' ), true ) ) {
				$this->map( 'term', $old, $this->fake_id() );
				$this->report( 'terms', $old, $name, 'created', '', __( 'After its taxonomy is created.', 'tamago-io' ) );
				return;
			}
			/* translators: %s: taxonomy */
			$this->report( 'terms', $old, $name, 'error', '', sprintf( __( 'Taxonomy "%s" is not registered on this site.', 'tamago-io' ), $tax ) );
			return;
		}
		$tx       = get_taxonomy( $tax );
		$existing = 0;
		if ( 'id' === $this->opts['match'] ) {
			$e        = get_term( $old, $tax );
			$existing = $e && ! is_wp_error( $e ) ? (int) $e->term_id : 0;
		}
		if ( ! $existing ) {
			$e        = get_term_by( 'slug', (string) $t['slug'], $tax );
			$existing = $e ? (int) $e->term_id : 0;
		}
		$parent = ! empty( $t['parent'] ) ? $this->resolve( 'term', (int) $t['parent'] ) : 0;
		$args   = array(
			'description' => TMG_IO_Convert::clean_html( (string) ( $t['description'] ?? '' ) ),
			'parent'      => max( 0, $parent ),
		);
		if ( $existing ) {
			$this->map( 'term', $old, $existing );
			if ( 'update' !== $this->opts['existing'] ) {
				$this->report( 'terms', $old, $name, 'skipped', $existing, 'copy' === $this->opts['existing'] ? __( 'Terms are not duplicated; the existing term is used.', 'tamago-io' ) : __( 'Already exists.', 'tamago-io' ) );
				return;
			}
			if ( ! current_user_can( $tx->cap->edit_terms ) ) {
				$this->report( 'terms', $old, $name, 'error', $existing, __( 'You are not allowed to edit these terms.', 'tamago-io' ) );
				return;
			}
			if ( ! $this->dry() ) {
				$r = wp_update_term( $existing, $tax, wp_slash( $args + array( 'name' => $name ) ) );
				if ( is_wp_error( $r ) ) {
					$this->report( 'terms', $old, $name, 'error', $existing, $r->get_error_message() );
					return;
				}
			}
			$this->mark_written( 'term', $old, $existing, 'updated' );
			$this->report( 'terms', $old, $name, 'updated', $existing );
			return;
		}
		if ( ! current_user_can( $tx->cap->manage_terms ) ) {
			$this->report( 'terms', $old, $name, 'error', '', __( 'You are not allowed to create these terms.', 'tamago-io' ) );
			return;
		}
		if ( $this->dry() ) {
			$this->map( 'term', $old, $this->fake_id() );
			$this->report( 'terms', $old, $name, 'created' );
			return;
		}
		$r = wp_insert_term( wp_slash( $name ), $tax, wp_slash( $args + array( 'slug' => (string) $t['slug'] ) ) );
		if ( is_wp_error( $r ) ) {
			$this->report( 'terms', $old, $name, 'error', '', $r->get_error_message() );
			return;
		}
		$this->map( 'term', $old, (int) $r['term_id'] );
		$this->mark_written( 'term', $old, (int) $r['term_id'], 'created' );
		$this->report( 'terms', $old, $name, 'created', (int) $r['term_id'] );
	}

	private function do_user( array $u ): void {
		$old   = (int) ( $u['id'] ?? 0 );
		$login = (string) ( $u['login'] ?? '' );
		$email = sanitize_email( (string) ( $u['email'] ?? '' ) );
		$label = $login . ( $email ? ' <' . $email . '>' : '' );
		$found = $login ? get_user_by( 'login', $login ) : false;
		$found = $found ? $found : ( $email ? get_user_by( 'email', $email ) : false );
		$core  = array(
			'display_name' => sanitize_text_field( (string) ( $u['display_name'] ?? '' ) ),
			'first_name'   => sanitize_text_field( (string) ( $u['first_name'] ?? '' ) ),
			'last_name'    => sanitize_text_field( (string) ( $u['last_name'] ?? '' ) ),
			'nickname'     => sanitize_text_field( (string) ( $u['nickname'] ?? '' ) ),
			'description'  => TMG_IO_Convert::clean_html( (string) ( $u['description'] ?? '' ) ),
			'user_url'     => esc_url_raw( (string) ( $u['url'] ?? '' ) ),
		);
		$core  = array_filter( $core, 'strlen' );
		$role  = '';
		foreach ( (array) ( $u['roles'] ?? array() ) as $r ) {
			if ( TMG_IO_Permissions::can_assign_role( (string) $r ) ) {
				$role = (string) $r;
				break;
			}
		}
		$asked = (array) ( $u['roles'] ?? array() );
		if ( $found ) {
			$id = (int) $found->ID;
			$this->map( 'user', $old, $id );
			if ( 'update' !== $this->opts['existing'] ) {
				$this->report( 'users', $old, $label, 'skipped', $id, 'copy' === $this->opts['existing'] ? __( 'Users are not duplicated; the existing account is used.', 'tamago-io' ) : __( 'Already exists.', 'tamago-io' ) );
				return;
			}
			if ( ! current_user_can( 'edit_user', $id ) ) {
				$this->report( 'users', $old, $label, 'error', $id, __( 'You are not allowed to edit this user.', 'tamago-io' ) );
				return;
			}
			if ( ! $this->dry() ) {
				$r = wp_update_user( wp_slash( $core + array( 'ID' => $id ) ) );
				if ( is_wp_error( $r ) ) {
					$this->report( 'users', $old, $label, 'error', $id, $r->get_error_message() );
					return;
				}
				// Roles change only for others, and only to roles the importer may give.
				if ( $id !== get_current_user_id() && $role && current_user_can( 'promote_user', $id ) ) {
					$found->set_role( $role );
				}
			}
			if ( $asked && ! $role ) {
				$this->note( __( 'Role not changed: it is above your own or unknown here.', 'tamago-io' ) );
			}
			$this->mark_written( 'user', $old, $id, 'updated' );
			$this->report( 'users', $old, $label, 'updated', $id );
			return;
		}
		if ( ! current_user_can( 'create_users' ) ) {
			$this->report( 'users', $old, $label, 'error', '', __( 'You are not allowed to create users.', 'tamago-io' ) );
			return;
		}
		$clean_login = sanitize_user( $login, true );
		if ( '' === $clean_login || ! is_email( $email ) ) {
			$this->report( 'users', $old, $label, 'error', '', __( 'Missing or invalid login or email.', 'tamago-io' ) );
			return;
		}
		if ( $asked && ! $role ) {
			$default = (string) get_option( 'default_role', 'subscriber' );
			$role    = TMG_IO_Permissions::can_assign_role( $default ) ? $default : '';
			/* translators: %s: role */
			$this->note( sprintf( __( 'Requested role not allowed; given "%s" instead.', 'tamago-io' ), $role ? $role : __( 'no role', 'tamago-io' ) ) );
		}
		if ( $this->dry() ) {
			$this->map( 'user', $old, $this->fake_id() );
			$this->report( 'users', $old, $label, 'created' );
			return;
		}
		// Passwords are never imported: new accounts get a random one (reset by email).
		$args = $core + array(
			'user_login' => $clean_login,
			'user_email' => $email,
			'user_pass'  => wp_generate_password( 32, true, true ),
			'role'       => $role,
		);
		if ( ! empty( $u['registered'] ) && preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $u['registered'] ) ) {
			$args['user_registered'] = (string) $u['registered'];
		}
		$id = wp_insert_user( wp_slash( $args ) );
		if ( is_wp_error( $id ) ) {
			$this->report( 'users', $old, $label, 'error', '', $id->get_error_message() );
			return;
		}
		$this->map( 'user', $old, (int) $id );
		$this->mark_written( 'user', $old, (int) $id, 'created' );
		$this->report( 'users', $old, $label, 'created', (int) $id );
	}

	private function do_media( string $old ): void {
		$info  = (array) ( $this->pkg()['media'][ $old ] ?? array() );
		$title = (string) ( $info['filename'] ?? $info['url'] ?? $old );
		$found = TMG_IO_Media::find_existing( $info );
		if ( $found ) {
			$this->map( 'media', $old, $found );
			$this->state['urls'][ (string) ( $info['url'] ?? '' ) ] = (string) wp_get_attachment_url( $found );
			if ( 'update' === $this->opts['existing'] && $this->section_on( 'media' ) && current_user_can( 'edit_post', $found ) ) {
				if ( ! $this->dry() ) {
					TMG_IO_Media::update_texts( $found, $info );
				}
				$this->report( 'media', $old, $title, 'updated', $found );
			} else {
				$this->report( 'media', $old, $title, 'skipped', $found, __( 'Already in the Media Library.', 'tamago-io' ) );
			}
			return;
		}
		if ( ! $this->section_on( 'media' ) ) {
			$this->map( 'media', $old, 0 );
			$this->report( 'media', $old, $title, 'skipped', '', __( 'Media import switched off.', 'tamago-io' ) );
			return;
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			$this->map( 'media', $old, 0 );
			$this->report( 'media', $old, $title, 'error', '', __( 'You are not allowed to upload files.', 'tamago-io' ) );
			return;
		}
		$zip = (string) ( $this->work['zip'] ?? '' );
		if ( $this->dry() ) {
			$this->map( 'media', $old, $this->fake_id() );
			$this->report( 'media', $old, $title, 'created', '', ( '' !== $zip && ! empty( $info['file'] ) ) ? __( 'From the ZIP.', 'tamago-io' ) : __( 'Downloaded from its URL.', 'tamago-io' ) );
			return;
		}
		$zip = '' !== $zip && TMG_IO_Storage::owns( $zip ) ? $zip : '';
		$id  = TMG_IO_Media::import( $info, $zip, $this->may_download() );
		if ( is_wp_error( $id ) ) {
			$this->map( 'media', $old, 0 );
			$this->report( 'media', $old, $title, 'error', '', $id->get_error_message() );
			return;
		}
		$this->map( 'media', $old, (int) $id );
		$this->state['urls'][ (string) ( $info['url'] ?? '' ) ] = (string) wp_get_attachment_url( (int) $id );
		$this->report( 'media', $old, $title, 'created', (int) $id );
	}

	/** Apply readable-column edits from a spreadsheet to the core fields of an entry. */
	private function core_edits( array $p ): array {
		$edits = (array) ( $p['_edits'] ?? array() );
		$cols  = array(
			'post_title'     => 'title',
			'post_name'      => 'slug',
			'post_status'    => 'status',
			'post_date'      => 'date',
			'post_excerpt'   => 'excerpt',
			'post_content'   => 'content',
			'menu_order'     => 'menu_order',
			'comment_status' => 'comment_status',
		);
		foreach ( $cols as $col => $key ) {
			if ( array_key_exists( $col, $edits ) ) {
				$p[ $key ] = $edits[ $col ];
				if ( 'date' === $key ) {
					$p['date_gmt'] = '';
				}
			}
		}
		if ( array_key_exists( 'post_author', $edits ) ) {
			$p['author'] = array( 'login' => $edits['post_author'], 'email' => $edits['post_author'] );
		}
		return $p;
	}

	/** Status the importer may set; lower statuses when publishing is not allowed. */
	public static function allowed_status( WP_Post_Type $pto, string $status, ?TMG_IO_Importer $imp = null ): string {
		$status = sanitize_key( $status );
		if ( ! in_array( $status, get_post_stati(), true ) || in_array( $status, array( 'auto-draft', 'inherit', 'trash' ), true ) ) {
			$status = 'draft';
		}
		if ( in_array( $status, array( 'publish', 'future', 'private' ), true ) && ! current_user_can( $pto->cap->publish_posts ) ) {
			if ( $imp ) {
				$imp->note( __( 'Saved as pending review: you may not publish this type.', 'tamago-io' ) );
			}
			return 'pending';
		}
		return $status;
	}

	/** Author for imported content: the original user when found and allowed, else the importer. */
	private function author_for( array $p, WP_Post_Type $pto ): int {
		$me = get_current_user_id();
		$a  = (array) ( $p['author'] ?? array() );
		$id = ! empty( $a['id'] ) ? $this->resolve( 'user', (int) $a['id'] ) : 0;
		if ( ! $id && ! empty( $a['login'] ) ) {
			$u  = get_user_by( 'login', (string) $a['login'] );
			$u  = $u ? $u : ( ! empty( $a['email'] ) ? get_user_by( 'email', (string) $a['email'] ) : false );
			$id = $u ? (int) $u->ID : 0;
		}
		if ( $id > 0 && $id !== $me && ! current_user_can( $pto->cap->edit_others_posts ) ) {
			return $me;
		}
		return $id > 0 ? $id : $me;
	}

	private function do_post( array $p ): void {
		$p     = $this->core_edits( $p );
		$old   = (int) ( $p['id'] ?? 0 );
		$type  = (string) ( $p['type'] ?? '' );
		$title = (string) ( $p['title'] ?? '' );

		if ( 'attachment' === $type ) {
			$id = $this->resolve( 'media', $old );
			if ( ! $id ) {
				$this->report( 'posts', $old, $title, 'error', '', __( 'The media file could not be imported.', 'tamago-io' ) );
				return;
			}
			$this->map( 'post', $old, $id );
			$this->mark_written( 'post', $old, $id, 'updated' );
			$this->report( 'posts', $old, $title, 'skipped', $id, __( 'Media item; its fields are updated next.', 'tamago-io' ) );
			return;
		}
		$pto = get_post_type_object( $type );
		if ( ! $pto ) {
			if ( $this->dry() && in_array( $type, $this->pending_names( 'post_type' ), true ) ) {
				$this->map( 'post', $old, $this->fake_id() );
				$this->report( 'posts', $old, $title, 'created', '', __( 'After its post type is created.', 'tamago-io' ) );
				return;
			}
			/* translators: %s: post type */
			$this->report( 'posts', $old, $title, 'error', '', sprintf( __( 'Post type "%s" is not registered on this site.', 'tamago-io' ), $type ) );
			return;
		}
		$existing = 'copy' === $this->opts['existing'] ? 0 : $this->match_post( $p );
		if ( $existing && 'skip' === $this->opts['existing'] ) {
			$this->map( 'post', $old, $existing );
			$this->report( 'posts', $old, $title, 'skipped', $existing, __( 'Already exists.', 'tamago-io' ) );
			return;
		}
		if ( $existing && ! current_user_can( 'edit_post', $existing ) ) {
			$this->map( 'post', $old, $existing );
			$this->report( 'posts', $old, $title, 'error', $existing, __( 'You are not allowed to edit this item.', 'tamago-io' ) );
			return;
		}
		if ( ! $existing && ! current_user_can( $pto->cap->create_posts ) ) {
			$this->report( 'posts', $old, $title, 'error', '', __( 'You are not allowed to create this type of content.', 'tamago-io' ) );
			return;
		}
		$args = array(
			'post_type'      => $type,
			'post_title'     => $title,
			'post_content'   => (string) ( $p['content'] ?? '' ),
			'post_excerpt'   => (string) ( $p['excerpt'] ?? '' ),
			'post_status'    => self::allowed_status( $pto, (string) ( $p['status'] ?? 'draft' ), $this ),
			'menu_order'     => (int) ( $p['menu_order'] ?? 0 ),
			'post_author'    => $this->author_for( $p, $pto ),
			'comment_status' => in_array( $p['comment_status'] ?? '', array( 'open', 'closed' ), true ) ? $p['comment_status'] : get_default_comment_status( $type ),
			'ping_status'    => in_array( $p['ping_status'] ?? '', array( 'open', 'closed' ), true ) ? $p['ping_status'] : get_default_comment_status( $type, 'pingback' ),
			'post_password'  => (string) ( $p['password'] ?? '' ),
		);
		if ( ! empty( $p['date'] ) && false !== strtotime( (string) $p['date'] ) ) {
			$args['post_date'] = gmdate( 'Y-m-d H:i:s', strtotime( (string) $p['date'] . ' UTC' ) );
			if ( ! empty( $p['date_gmt'] ) && '0000-00-00 00:00:00' !== $p['date_gmt'] ) {
				$args['post_date_gmt'] = (string) $p['date_gmt'];
			}
		}
		if ( ! empty( $p['slug'] ) ) {
			$args['post_name'] = sanitize_title( (string) $p['slug'] );
		}
		if ( $existing ) {
			$args['ID'] = $existing;
			// Changing the author of an existing item needs edit_others_posts (checked in author_for).
		} else {
			// WordPress leaves draft slugs as they are; new items always get a unique one so a
			// copy is never confused with its original on the next import.
			if ( ! empty( $args['post_name'] ) ) {
				$parent            = is_post_type_hierarchical( $type ) && ! empty( $p['parent'] ) ? max( 0, (int) ( $this->state['maps']['post'][ (string) $p['parent'] ] ?? 0 ) ) : 0;
				$args['post_name'] = wp_unique_post_slug( $args['post_name'], 0, 'publish', $type, $parent );
			}
			if ( ! empty( $p['guid'] ) && 'guid' === $this->opts['match'] ) {
				$args['guid'] = esc_url_raw( (string) $p['guid'] );
			}
			if ( $this->opts['keep_ids'] && $old && ! get_post( $old ) ) {
				$args['import_id'] = $old;
			}
		}
		$args = apply_filters( 'tmg_io_import_post', $args, $p, $this->opts );
		if ( ! is_array( $args ) || ! $args ) {
			$this->report( 'posts', $old, $title, 'skipped', $existing, __( 'Skipped by a filter.', 'tamago-io' ) );
			return;
		}
		$action = $existing ? 'updated' : 'created';
		if ( $this->dry() ) {
			$id = $existing ? $existing : $this->fake_id();
			$this->map( 'post', $old, $id );
			$this->mark_written( 'post', $old, $id, $action );
			$this->report( 'posts', $old, $title, $action, $existing ? $existing : '' );
			return;
		}
		$id = $existing ? wp_update_post( wp_slash( $args ), true ) : wp_insert_post( wp_slash( $args ), true );
		if ( is_wp_error( $id ) || ! $id ) {
			$this->report( 'posts', $old, $title, 'error', '', is_wp_error( $id ) ? $id->get_error_message() : __( 'Could not be saved.', 'tamago-io' ) );
			return;
		}
		$this->map( 'post', $old, (int) $id );
		$this->mark_written( 'post', $old, (int) $id, $action );
		$this->report( 'posts', $old, $title, $action, (int) $id );
	}

	private function do_post_data( array $p ): void {
		$old = (int) ( $p['id'] ?? 0 );
		$w   = $this->state['written']['post'][ (string) $old ] ?? null;
		if ( ! $w || $this->dry() ) {
			return;
		}
		list( $id, $action ) = $w;
		$id                  = (int) $id;
		$is_update           = 'updated' === $action;
		$type                = (string) $p['type'];
		global $wpdb;

		// Parent.
		if ( 'attachment' !== $type ) {
			$parent = ! empty( $p['parent'] ) ? $this->resolve( 'post', (int) $p['parent'] ) : 0;
			if ( $parent > 0 || ( $is_update && empty( $p['parent'] ) ) ) {
				$wpdb->update( $wpdb->posts, array( 'post_parent' => max( 0, $parent ) ), array( 'ID' => $id ) );
				clean_post_cache( $id );
			}
		}

		// Meta (ACF values included: they are meta rows with `_name` → field key references).
		$this->write_meta( 'post', $id, $p, $is_update );

		// Terms.
		$taxes = get_object_taxonomies( $type );
		foreach ( (array) ( $p['terms'] ?? array() ) as $tax => $slugs ) {
			if ( ! in_array( $tax, $taxes, true ) ) {
				continue;
			}
			$tx = get_taxonomy( $tax );
			if ( ! current_user_can( $tx->cap->assign_terms ) ) {
				$this->note( __( 'Terms not assigned: not allowed.', 'tamago-io' ) );
				continue;
			}
			$ids = array();
			foreach ( (array) $slugs as $slug ) {
				$tid = $this->ensure_term( $tax, (string) $slug );
				if ( $tid > 0 ) {
					$ids[] = $tid;
				}
			}
			wp_set_object_terms( $id, $ids, $tax );
		}
		if ( $is_update && $this->opts['empty_missing'] ) {
			foreach ( $taxes as $tax ) {
				if ( ! isset( $p['terms'][ $tax ] ) && 'post_format' !== $tax ) {
					wp_set_object_terms( $id, array(), $tax );
				}
			}
		}

		// ACF values given only by name (hand-written packages) go through the field's conversion.
		foreach ( (array) ( $p['acf'] ?? array() ) as $name => $f ) {
			if ( array_key_exists( (string) $name, (array) ( $p['meta'] ?? array() ) ) || ! TMG_IO_ACF::active() ) {
				continue;
			}
			$field = TMG_IO_ACF::field( (string) ( $f['key'] ?? '' ) );
			if ( $field ) {
				update_field( $field['key'], TMG_IO_Convert::to_acf( $field, $f['value'] ?? '', $this ), $id );
			}
		}

		// Cells edited in a spreadsheet.
		foreach ( (array) ( $p['_edits'] ?? array() ) as $col => $cell ) {
			if ( preg_match( '/^(tax:|acf:|meta:|featured_image$)/', (string) $col ) ) {
				TMG_IO_Mapping::apply_target( $this, $id, $type, TMG_IO_Mapping::target_for_column( (string) $col, $type ), (string) $cell );
			}
		}

		// Comments.
		if ( $this->opts['comments'] && ! empty( $p['comments'] ) ) {
			$this->import_comments( $id, (array) $p['comments'] );
		}

		// Links to media inside the content.
		$this->fix_content( $id );

		do_action( 'tmg_io_imported_post', $id, $p, $action, $this->opts );
		if ( $this->notes ) {
			$this->report( 'posts', $old, (string) $p['title'], 'note', $id );
		}
	}

	/** Meta of a post / term / user: remapped IDs, cleaned HTML, optional removal of missing keys. */
	private function write_meta( string $type, int $id, array $entry, bool $is_update ): void {
		$skip    = TMG_IO_Exporter::skip_meta_keys( $type );
		$refs    = (array) ( $entry['refs'] ?? array() );
		$present = array();
		$manager = current_user_can( TMG_IO_Permissions::MANAGE );
		$meta    = (array) ( $entry['meta'] ?? array() );
		$blocked = function ( string $k ) use ( $type, $skip, $manager, $meta ): bool {
			if ( TMG_IO_Exporter::skip_meta( $k, $skip ) ) {
				return true;
			}
			if ( 'user' === $type && TMG_IO_Exporter::is_private_user_meta( $k ) ) {
				return true;
			}
			// Hidden (underscore) meta is plugin-internal data. Without the manage permission only
			// ACF field references and a few keys editors can set on screen are written.
			if ( ! $manager && '_' === ( $k[0] ?? '' ) ) {
				$safe = (array) apply_filters( 'tmg_io_allowed_hidden_meta', array( '_thumbnail_id', '_wp_page_template', '_wp_attachment_image_alt' ), $type );
				$ref  = $meta[ $k ] ?? null;
				return ! in_array( $k, $safe, true ) && ! ( is_string( $ref ) && 0 === strpos( $ref, 'field_' ) && array_key_exists( substr( $k, 1 ), $meta ) );
			}
			return false;
		};
		$hidden = array();
		foreach ( (array) ( $entry['meta'] ?? array() ) as $k => $v ) {
			$k = (string) $k;
			if ( '' === $k || $blocked( $k ) ) {
				if ( ! $manager && '_' === ( $k[0] ?? '' ) && ! TMG_IO_Exporter::skip_meta( $k, $skip ) ) {
					$hidden[] = $k;
				}
				continue;
			}
			$present[ $k ] = true;
			if ( isset( $refs[ $k ] ) ) {
				$v = $this->remap( $v, (string) $refs[ $k ] );
				if ( '_thumbnail_id' === $k && ( '' === $v || ! $v ) ) {
					delete_metadata( $type, $id, $k );
					continue;
				}
			}
			update_metadata( $type, $id, $k, wp_slash( TMG_IO_Convert::clean_deep( $v ) ) );
		}
		foreach ( (array) ( $entry['meta_multi'] ?? array() ) as $k => $values ) {
			$k = (string) $k;
			if ( '' === $k || $blocked( $k ) ) {
				continue;
			}
			$present[ $k ] = true;
			delete_metadata( $type, $id, $k );
			foreach ( (array) $values as $v ) {
				add_metadata( $type, $id, $k, wp_slash( TMG_IO_Convert::clean_deep( $v ) ) );
			}
		}
		if ( $hidden ) {
			/* translators: %s: meta keys */
			$this->note( sprintf( __( 'Hidden fields need the manage permission and were not imported: %s', 'tamago-io' ), implode( ', ', array_slice( $hidden, 0, 10 ) ) ) );
		}
		foreach ( array_keys( (array) ( $entry['meta_raw'] ?? array() ) ) as $k ) {
			$present[ (string) $k ] = true;
			/* translators: %s: meta key */
			$this->note( sprintf( __( 'Field "%s" holds PHP objects and was not imported (unsafe).', 'tamago-io' ), $k ) );
		}
		if ( $is_update && $this->opts['empty_missing'] ) {
			list( $cur, $cur_multi, $cur_raw ) = TMG_IO_Exporter::read_meta( $type, $id );
			foreach ( array_keys( $cur + $cur_multi + $cur_raw ) as $k ) {
				$k = (string) $k;
				if ( ! isset( $present[ $k ] ) && ! $blocked( $k ) && ! in_array( $k, array( '_wp_page_template' ), true ) ) {
					delete_metadata( $type, $id, $k );
				}
			}
		}
	}

	private function do_object_data( string $kind, array $item ): void {
		$w = $this->state['written'][ $kind ][ (string) ( $item['id'] ?? '' ) ] ?? null;
		if ( ! $w || $this->dry() ) {
			return;
		}
		$this->write_meta( $kind, (int) $w[0], $item, 'updated' === $w[1] );
		if ( $this->notes ) {
			$this->report( 'term' === $kind ? 'terms' : 'users', $item['id'] ?? '', (string) ( $item['name'] ?? $item['login'] ?? '' ), 'note', (int) $w[0] );
		}
	}

	private function import_comments( int $post_id, array $comments ): void {
		if ( ! current_user_can( 'moderate_comments' ) ) {
			$this->note( __( 'Comments not imported: not allowed.', 'tamago-io' ) );
			return;
		}
		$map = array();
		foreach ( $comments as $c ) {
			$date = (string) ( $c['date_gmt'] ?? '' );
			$dupe = get_comments(
				array(
					'post_id'      => $post_id,
					'author_email' => (string) ( $c['email'] ?? '' ),
					'date_query'   => $date ? array( array( 'column' => 'comment_date_gmt', 'after' => $date, 'before' => $date, 'inclusive' => true ) ) : array(),
					'count'        => false,
					'number'       => 1,
					'status'       => 'all',
				)
			);
			if ( $dupe ) {
				$map[ (int) $c['id'] ] = (int) $dupe[0]->comment_ID;
				continue;
			}
			$user = ! empty( $c['user'] ) ? get_user_by( 'login', (string) $c['user'] ) : false;
			$cid  = wp_insert_comment(
				wp_slash(
					array(
						'comment_post_ID'      => $post_id,
						'comment_parent'       => (int) ( $map[ (int) ( $c['parent'] ?? 0 ) ] ?? 0 ),
						'comment_author'       => sanitize_text_field( (string) ( $c['author'] ?? '' ) ),
						'comment_author_email' => sanitize_email( (string) ( $c['email'] ?? '' ) ),
						'comment_author_url'   => esc_url_raw( (string) ( $c['url'] ?? '' ) ),
						'comment_date'         => (string) ( $c['date'] ?? current_time( 'mysql' ) ),
						'comment_date_gmt'     => $date ? $date : current_time( 'mysql', true ),
						'comment_content'      => wp_kses_post( (string) ( $c['content'] ?? '' ) ),
						'comment_approved'     => in_array( (string) ( $c['approved'] ?? '' ), array( '0', '1', 'spam', 'trash' ), true ) ? (string) $c['approved'] : '0',
						'comment_type'         => sanitize_key( (string) ( $c['type'] ?? 'comment' ) ),
						'comment_agent'        => sanitize_text_field( (string) ( $c['agent'] ?? '' ) ),
						'user_id'              => $user ? (int) $user->ID : 0,
					)
				)
			);
			if ( $cid ) {
				$map[ (int) $c['id'] ] = (int) $cid;
				foreach ( (array) ( $c['meta'] ?? array() ) as $k => $v ) {
					if ( isset( $c['refs'][ $k ] ) ) {
						$v = $this->remap( $v, (string) $c['refs'][ $k ] );
					}
					update_comment_meta( (int) $cid, (string) $k, wp_slash( TMG_IO_Convert::clean_deep( $v ) ) );
				}
			}
		}
		wp_update_comment_count( $post_id );
	}

	/** Point media URLs and image IDs in the content to the imported files. */
	private function fix_content( int $id ): void {
		global $wpdb;
		$post = get_post( $id );
		if ( ! $post || '' === $post->post_content ) {
			return;
		}
		$content = $post->post_content;
		foreach ( (array) $this->state['urls'] as $old => $new ) {
			if ( '' === $old || '' === $new || $old === $new ) {
				continue;
			}
			$content = str_replace( $old, $new, $content );
			// Resized copies (photo-300x200.jpg) of the same file.
			$o_base = preg_replace( '/\.[a-z0-9]+$/i', '', $old );
			$n_base = preg_replace( '/\.[a-z0-9]+$/i', '', $new );
			if ( $o_base && $n_base && $o_base !== $old ) {
				$content = preg_replace( '#' . preg_quote( $o_base, '#' ) . '-(\d+x\d+)\.#', $n_base . '-$1.', $content );
			}
		}
		$maps    = $this->state['maps']['media'];
		$content = preg_replace_callback(
			'/(wp-image-|<!-- wp:(?:image|cover|media-text) \{"(?:id|mediaId)":)(\d+)/',
			function ( $m ) use ( $maps ) {
				$new = (int) ( $maps[ $m[2] ] ?? 0 );
				return $m[1] . ( $new > 0 ? $new : $m[2] );
			},
			(string) $content
		);
		if ( $content !== $post->post_content ) {
			$wpdb->update( $wpdb->posts, array( 'post_content' => $content ), array( 'ID' => $id ) );
			clean_post_cache( $id );
		}
	}

	private function do_menu( array $m ): void {
		$name = (string) ( $m['name'] ?? '' );
		$old  = (int) ( $m['id'] ?? 0 );
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			$this->report( 'menus', $old, $name, 'error', '', __( 'You are not allowed to edit menus.', 'tamago-io' ) );
			return;
		}
		$existing = wp_get_nav_menu_object( (string) ( $m['slug'] ?? '' ) );
		$existing = $existing ? $existing : wp_get_nav_menu_object( $name );
		if ( $existing && 'skip' === $this->opts['existing'] ) {
			$this->report( 'menus', $old, $name, 'skipped', (int) $existing->term_id, __( 'Already exists.', 'tamago-io' ) );
			return;
		}
		$action = $existing && 'update' === $this->opts['existing'] ? 'updated' : 'created';
		$items  = (array) ( $m['items'] ?? array() );
		if ( $this->dry() ) {
			/* translators: %d: number of items */
			$this->report( 'menus', $old, $name, $action, $existing ? (int) $existing->term_id : '', sprintf( __( '%d items.', 'tamago-io' ), count( $items ) ) );
			return;
		}
		if ( 'updated' === $action ) {
			$menu_id = (int) $existing->term_id;
			foreach ( (array) wp_get_nav_menu_items( $menu_id, array( 'post_status' => 'any' ) ) as $it ) {
				wp_delete_post( (int) $it->ID, true );
			}
			wp_update_nav_menu_object( $menu_id, wp_slash( array( 'menu-name' => $name, 'description' => (string) ( $m['description'] ?? '' ) ) ) );
		} else {
			$new_name = $name;
			$n        = 2;
			while ( wp_get_nav_menu_object( $new_name ) ) {
				$new_name = $name . ' (' . $n++ . ')';
			}
			$menu_id = wp_update_nav_menu_object( 0, wp_slash( array( 'menu-name' => $new_name, 'description' => (string) ( $m['description'] ?? '' ) ) ) );
			if ( is_wp_error( $menu_id ) ) {
				$this->report( 'menus', $old, $name, 'error', '', $menu_id->get_error_message() );
				return;
			}
		}
		// Parents before children.
		$by_id = array();
		foreach ( $items as $it ) {
			$by_id[ (int) $it['id'] ] = $it;
		}
		$depth = function ( $it ) use ( $by_id ) {
			$d = 0;
			while ( ! empty( $it['parent'] ) && isset( $by_id[ (int) $it['parent'] ] ) && $d < 20 ) {
				$it = $by_id[ (int) $it['parent'] ];
				++$d;
			}
			return $d;
		};
		usort( $items, fn( $a, $b ) => array( $depth( $a ), (int) $a['order'] ) <=> array( $depth( $b ), (int) $b['order'] ) );
		$imap    = array();
		$missing = 0;
		$site    = untrailingslashit( (string) ( $this->pkg()['site'] ?? '' ) );
		foreach ( $items as $it ) {
			$type   = (string) ( $it['type'] ?? 'custom' );
			$obj_id = 0;
			$url    = (string) ( $it['url'] ?? '' );
			if ( 'post_type' === $type ) {
				$obj_id = $this->resolve( 'post', (int) $it['object_id'] );
			} elseif ( 'taxonomy' === $type ) {
				$obj_id = $this->resolve( 'term', (int) $it['object_id'] );
			}
			if ( in_array( $type, array( 'post_type', 'taxonomy' ), true ) && $obj_id <= 0 ) {
				// Keep the link working as a custom link.
				$type = 'custom';
				++$missing;
			}
			if ( 'custom' === $type && '' !== $site && 0 === strpos( $url, $site ) ) {
				$url = untrailingslashit( home_url() ) . substr( $url, strlen( $site ) );
			}
			$args   = array(
				'menu-item-object-id'   => $obj_id,
				'menu-item-object'      => 'custom' === $type ? 'custom' : (string) $it['object'],
				'menu-item-type'        => $type,
				'menu-item-title'       => (string) ( $it['title'] ?? '' ),
				'menu-item-url'         => 'custom' === $type ? esc_url_raw( $url ) : '',
				'menu-item-parent-id'   => (int) ( $imap[ (int) ( $it['parent'] ?? 0 ) ] ?? 0 ),
				'menu-item-position'    => (int) ( $it['order'] ?? 0 ),
				'menu-item-target'      => '_blank' === ( $it['target'] ?? '' ) ? '_blank' : '',
				'menu-item-classes'     => implode( ' ', array_map( 'sanitize_html_class', (array) ( $it['classes'] ?? array() ) ) ),
				'menu-item-xfn'         => sanitize_text_field( (string) ( $it['xfn'] ?? '' ) ),
				'menu-item-attr-title'  => sanitize_text_field( (string) ( $it['attr_title'] ?? '' ) ),
				'menu-item-description' => sanitize_textarea_field( (string) ( $it['description'] ?? '' ) ),
				'menu-item-status'      => 'publish',
			);
			$new_id = wp_update_nav_menu_item( (int) $menu_id, 0, wp_slash( $args ) );
			if ( is_wp_error( $new_id ) ) {
				$this->note( $new_id->get_error_message() );
				continue;
			}
			$imap[ (int) $it['id'] ] = (int) $new_id;
			$this->write_meta( 'post', (int) $new_id, array( 'meta' => $it['meta'] ?? array(), 'refs' => $it['refs'] ?? array() ), false );
		}
		if ( $missing ) {
			/* translators: %d: number of items */
			$this->note( sprintf( __( '%d items point to content not found here and became custom links.', 'tamago-io' ), $missing ) );
		}
		if ( $this->opts['menu_locations'] && ! empty( $m['locations'] ) ) {
			$registered = get_registered_nav_menus();
			$locs       = (array) get_theme_mod( 'nav_menu_locations', array() );
			foreach ( (array) $m['locations'] as $loc ) {
				if ( isset( $registered[ $loc ] ) ) {
					$locs[ $loc ] = (int) $menu_id;
				}
			}
			set_theme_mod( 'nav_menu_locations', $locs );
		}
		/* translators: %d: number of items */
		$this->report( 'menus', $old, $name, $action, (int) $menu_id, sprintf( __( '%d items.', 'tamago-io' ), count( $imap ) ) );
	}

	private function do_option( string $name ): void {
		$pkg = $this->pkg();
		if ( ! TMG_IO_Permissions::can_site_settings() ) {
			$this->report( 'options', $name, $name, 'error', '', __( 'You are not allowed to change site options.', 'tamago-io' ) );
			return;
		}
		if ( TMG_IO_Exporter::is_protected_option( $name ) || '' === $name || strlen( $name ) > 191 ) {
			$this->report( 'options', $name, $name, 'skipped', '', __( 'Protected option.', 'tamago-io' ) );
			return;
		}
		if ( array_key_exists( $name, (array) ( $pkg['options_raw'] ?? array() ) ) && ! array_key_exists( $name, (array) ( $pkg['options'] ?? array() ) ) ) {
			$this->report( 'options', $name, $name, 'skipped', '', __( 'Holds PHP objects and was not imported (unsafe).', 'tamago-io' ) );
			return;
		}
		$sentinel = new stdClass();
		$exists   = get_option( $name, $sentinel ) !== $sentinel;
		if ( $exists && 'update' !== $this->opts['existing'] ) {
			$this->report( 'options', $name, $name, 'skipped', '', __( 'Already set.', 'tamago-io' ) );
			return;
		}
		$value = $pkg['options'][ $name ];
		$kind  = (string) ( $pkg['options_refs'][ $name ] ?? '' );
		if ( '' !== $kind ) {
			$value = $this->remap( $value, $kind );
		}
		$value = TMG_IO_Convert::clean_deep( $value );
		if ( ! $this->dry() ) {
			if ( $exists ) {
				update_option( $name, $value );
			} else {
				add_option( $name, $value, '', false );
			}
		}
		$this->report( 'options', $name, $name, $exists ? 'updated' : 'created' );
	}

	private function do_source( int $chunk ): void {
		$sources = TMG_IO_Sources::all();
		$id      = $this->opts['source'];
		$src     = $sources[ $id ] ?? null;
		if ( ! $src || ! is_callable( $src['import'] ) || ! current_user_can( $src['capability'] ) ) {
			$this->report( 'source', $chunk, '', 'error', '', __( 'This data source cannot import or you are not allowed to use it.', 'tamago-io' ) );
			return;
		}
		$rows = array_slice( (array) ( $this->work['rows'] ?? array() ), $chunk * self::SOURCE_CHUNK, self::SOURCE_CHUNK );
		foreach ( $rows as $n => $row ) {
			$rows[ $n ] = array_map( fn( $v ) => TMG_IO_Files::unescape_cell( (string) $v ), (array) $row );
		}
		// Column header => the source's own column key ("src:" prefix removed, unmapped columns dropped).
		$mapping = array();
		foreach ( (array) $this->opts['mapping'] as $col => $target ) {
			if ( 0 === strpos( (string) $target, 'src:' ) ) {
				$mapping[ $col ] = substr( (string) $target, 4 );
			}
		}
		$result = (array) call_user_func(
			$src['import'],
			$rows,
			$mapping,
			array(
				'choices'  => (array) $this->opts['choices'],
				'dry_run'  => $this->dry(),
				'existing' => $this->opts['existing'],
				'offset'   => $chunk * self::SOURCE_CHUNK,
				'user_id'  => get_current_user_id(),
			)
		);
		foreach ( array( 'created', 'updated', 'skipped' ) as $a ) {
			$n = (int) ( $result[ $a ] ?? 0 );
			if ( $n ) {
				$this->state['counts']['source'][ $a ] = (int) ( $this->state['counts']['source'][ $a ] ?? 0 ) + $n;
			}
		}
		foreach ( (array) ( $result['errors'] ?? array() ) as $row => $msg ) {
			$this->report( 'source', is_int( $row ) ? $row + 1 + $chunk * self::SOURCE_CHUNK : $row, '', 'error', '', (string) $msg );
		}
		foreach ( (array) ( $result['items'] ?? array() ) as $it ) {
			$this->state['report'][] = array(
				's'  => 'source',
				'id' => (string) ( $it['row'] ?? '' ),
				't'  => (string) ( $it['title'] ?? '' ),
				'a'  => (string) ( $it['action'] ?? '' ),
				'n'  => (string) ( $it['id'] ?? '' ),
				'm'  => (string) ( $it['message'] ?? '' ),
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * Preview
	 * ------------------------------------------------------------------- */

	/**
	 * What a run would do, without writing: section => [total, create, update, skip, other].
	 */
	public static function preview( array $work, array $opts ): array {
		$self       = new self();
		$self->opts = self::sanitize_opts( $opts );
		$self->work = $work;
		$self->state = array( 'maps' => array(), 'fake' => 0 );
		$pkg        = (array) ( $work['package'] ?? array() );
		$out        = array();
		$add        = function ( string $s, string $what ) use ( &$out ) {
			if ( ! isset( $out[ $s ] ) ) {
				$out[ $s ] = array( 'total' => 0, 'create' => 0, 'update' => 0, 'skip' => 0, 'other' => 0 );
			}
			++$out[ $s ]['total'];
			++$out[ $s ][ $what ];
		};
		$when = function ( bool $exists ) use ( $self ): string {
			if ( ! $exists ) {
				return 'create';
			}
			return 'update' === $self->opts['existing'] ? 'update' : ( 'copy' === $self->opts['existing'] ? 'create' : 'skip' );
		};
		if ( $pkg ) {
			foreach ( (array) ( $pkg['acf_structure'] ?? array() ) as $item ) {
				$pt = TMG_IO_ACF::item_post_type( (array) $item );
				$add( 'acf', $pt && TMG_IO_ACF::active() ? ( TMG_IO_ACF::existing_item( (array) $item, $pt ) ? ( 'skip' === $self->opts['existing'] ? 'skip' : 'update' ) : 'create' ) : 'other' );
			}
			foreach ( (array) ( $pkg['terms'] ?? array() ) as $t ) {
				$e = taxonomy_exists( (string) $t['taxonomy'] ) && get_term_by( 'slug', (string) $t['slug'], (string) $t['taxonomy'] );
				$add( 'terms', $e && 'copy' === $self->opts['existing'] ? 'skip' : $when( (bool) $e ) );
			}
			foreach ( (array) ( $pkg['users'] ?? array() ) as $u ) {
				$e = get_user_by( 'login', (string) $u['login'] ) || get_user_by( 'email', (string) $u['email'] );
				$add( 'users', $e && 'copy' === $self->opts['existing'] ? 'skip' : $when( $e ) );
			}
			foreach ( (array) ( $pkg['media'] ?? array() ) as $m ) {
				$add( 'media', TMG_IO_Media::find_existing( (array) $m ) ? 'skip' : 'create' );
			}
			foreach ( array_slice( (array) ( $pkg['posts'] ?? array() ), 0, 5000 ) as $p ) {
				if ( 'attachment' === ( $p['type'] ?? '' ) ) {
					$add( 'posts', 'other' );
					continue;
				}
				if ( ! post_type_exists( (string) $p['type'] ) && ! in_array( (string) $p['type'], $self->pending_names( 'post_type' ), true ) ) {
					$add( 'posts', 'other' );
					continue;
				}
				$add( 'posts', $when( 'copy' !== $self->opts['existing'] && post_type_exists( (string) $p['type'] ) ? (bool) $self->match_post( (array) $p ) : false ) );
			}
			foreach ( (array) ( $pkg['menus'] ?? array() ) as $m ) {
				$add( 'menus', $when( (bool) wp_get_nav_menu_object( (string) $m['slug'] ) ) );
			}
			$sentinel = new stdClass();
			foreach ( array_keys( (array) ( $pkg['options'] ?? array() ) ) as $name ) {
				if ( TMG_IO_Exporter::is_protected_option( (string) $name ) ) {
					$add( 'options', 'other' );
					continue;
				}
				$e = get_option( (string) $name, $sentinel ) !== $sentinel;
				$add( 'options', $e && 'copy' === $self->opts['existing'] ? 'skip' : $when( $e ) );
			}
			foreach ( array_keys( (array) ( $pkg['options_raw'] ?? array() ) ) as $name ) {
				$add( 'options', 'other' );
			}
		} elseif ( isset( $work['rows'] ) ) {
			$section = '' === $self->opts['source'] ? 'rows' : 'source';
			foreach ( array_slice( (array) $work['rows'], 0, 5000 ) as $r ) {
				if ( 'rows' === $section ) {
					$add( 'rows', $when( TMG_IO_Mapping::match_row( $self, (array) $r ) > 0 ) );
				} else {
					$add( 'source', 'other' );
				}
			}
		}
		return $out;
	}
}
