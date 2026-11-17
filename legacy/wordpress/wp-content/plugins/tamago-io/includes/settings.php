<?php
/**
 * Settings: one option (`tmg_io_settings`) with defaults in code, constant locks
 * (`TMG_IO_<NAME>` in wp-config.php) and the settings JSON export / import shape.
 *
 * Used by: every part of the plugin; Screens: Import & Export → Settings.
 */

defined( 'ABSPATH' ) || exit;

class TMG_IO_Settings {

	const OPTION = 'tmg_io_settings';

	/** Defaults. Keys are also the constant names (upper-cased, prefixed with TMG_IO_). */
	public static function defaults(): array {
		return array(
			'max_upload_mb'     => 64,
			'batch_size'        => 25,
			'step_seconds'      => 15,
			'remote_media'      => 1,
			'media_max_mb'      => 32,
			'history_days'      => 90,
			'skip_meta_keys'    => '',
			'protected_options' => '',
			'delete_data'       => 0,
		);
	}

	/** Field types used for sanitising and the settings screen. */
	public static function types(): array {
		return array(
			'max_upload_mb'     => 'int',
			'batch_size'        => 'int',
			'step_seconds'      => 'int',
			'remote_media'      => 'bool',
			'media_max_mb'      => 'int',
			'history_days'      => 'int',
			'skip_meta_keys'    => 'lines',
			'protected_options' => 'lines',
			'delete_data'       => 'bool',
		);
	}

	public static function all(): array {
		$saved = get_option( self::OPTION, array() );
		$out   = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		foreach ( array_keys( self::defaults() ) as $key ) {
			if ( self::locked( $key ) ) {
				$out[ $key ] = constant( self::constant_name( $key ) );
			}
		}
		return $out;
	}

	/**
	 * @return mixed
	 */
	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	public static function constant_name( string $key ): string {
		return 'TMG_IO_' . strtoupper( $key );
	}

	public static function locked( string $key ): bool {
		return defined( self::constant_name( $key ) );
	}

	/** Clean a raw settings array (from the form or a JSON file) against the defaults. */
	public static function sanitize( array $raw ): array {
		$clean = array();
		foreach ( self::types() as $key => $type ) {
			if ( ! array_key_exists( $key, $raw ) ) {
				// Unchecked checkboxes are missing from the form.
				if ( 'bool' === $type ) {
					$clean[ $key ] = 0;
				}
				continue;
			}
			$v = $raw[ $key ];
			switch ( $type ) {
				case 'int':
					$clean[ $key ] = max( 1, min( 100000, absint( $v ) ) );
					break;
				case 'bool':
					$clean[ $key ] = empty( $v ) ? 0 : 1;
					break;
				case 'lines':
					$lines         = preg_split( '/[\r\n,]+/', (string) $v );
					$lines         = array_filter( array_map( fn( $l ) => trim( sanitize_text_field( $l ) ), $lines ), 'strlen' );
					$clean[ $key ] = implode( "\n", array_unique( $lines ) );
					break;
			}
		}
		return $clean;
	}

	/**
	 * Save settings. From the settings form a missing checkbox means "off"; from code (and
	 * settings files) only the keys given change.
	 */
	public static function save( array $raw, bool $from_form = false ): void {
		$current = get_option( self::OPTION, array() );
		$current = is_array( $current ) ? $current : array();
		$clean   = self::sanitize( $raw );
		if ( ! $from_form ) {
			$clean = array_intersect_key( $clean, $raw );
		}
		foreach ( array_keys( $clean ) as $key ) {
			if ( self::locked( $key ) ) {
				unset( $clean[ $key ] );
			}
		}
		update_option( self::OPTION, array_merge( $current, $clean ), true );
	}

	/** Lines setting as an array. */
	public static function lines( string $key ): array {
		return array_values( array_filter( array_map( 'trim', explode( "\n", (string) self::get( $key ) ) ), 'strlen' ) );
	}

	/** Settings file contents (conventions shape). Presets and mapping templates travel with the settings. */
	public static function export_data(): array {
		$settings                      = self::all();
		$settings['presets']           = TMG_IO_Presets::all( TMG_IO_Presets::EXPORT );
		$settings['mapping_templates'] = TMG_IO_Presets::all( TMG_IO_Presets::MAPPING );
		return array(
			'plugin'   => 'tamago-io',
			'version'  => TMG_IO_VERSION,
			'exported' => gmdate( 'c' ),
			'settings' => $settings,
		);
	}

	/**
	 * Apply a settings file. Returns an error message or ''.
	 */
	public static function import_data( array $data ): string {
		if ( 'tamago-io' !== ( $data['plugin'] ?? '' ) || ! isset( $data['settings'] ) || ! is_array( $data['settings'] ) ) {
			return __( 'This is not a Tamago Import & Export settings file.', 'tamago-io' );
		}
		$s = $data['settings'];
		// Only keys present in the file change; a file from an older version leaves new keys alone.
		self::save( array_intersect_key( $s, self::defaults() ) );
		foreach ( array( 'presets' => TMG_IO_Presets::EXPORT, 'mapping_templates' => TMG_IO_Presets::MAPPING ) as $k => $kind ) {
			if ( isset( $s[ $k ] ) && is_array( $s[ $k ] ) ) {
				foreach ( $s[ $k ] as $name => $item ) {
					if ( is_array( $item ) ) {
						TMG_IO_Presets::save( $kind, (string) $name, $item );
					}
				}
			}
		}
		return '';
	}
}
