<?php
/**
 * File formats: detect and read JSON / ZIP / CSV / XLSX by content (not by name), write CSV,
 * send downloads, guard against formula injection and zip slip, validate uploads.
 *
 * Used by: admin uploads, importer, exporter, WP-CLI, public helpers in functions.php.
 */

defined( 'ABSPATH' ) || exit;

class TMG_IO_Files {

	const PACKAGE = 'tamago-io-package';

	/** Uncompressed size we accept for one ZIP entry we read into memory (package.json, CSV, XLSX). */
	const ENTRY_MAX = 268435456;

	/* ---------------------------------------------------------------------
	 * Detection and reading
	 * ------------------------------------------------------------------- */

	/** Real type of a file from its first bytes: json | zip | xlsx | csv | '' (unknown / binary). */
	public static function sniff( string $path ): string {
		$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $fh ) {
			return '';
		}
		$head = (string) fread( $fh, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( '' === $head ) {
			return '';
		}
		if ( 0 === strpos( $head, "PK\x03\x04" ) ) {
			if ( ! class_exists( 'ZipArchive' ) ) {
				return 'zip';
			}
			$zip = new ZipArchive();
			if ( true !== $zip->open( $path ) ) {
				return '';
			}
			$is_xlsx = false !== $zip->locateName( 'xl/workbook.xml' ) && false !== $zip->locateName( '[Content_Types].xml' );
			$zip->close();
			return $is_xlsx ? 'xlsx' : 'zip';
		}
		$text = (string) preg_replace( '/^\xEF\xBB\xBF/', '', $head );
		if ( false !== strpos( $text, "\0" ) ) {
			return '';
		}
		$first = ltrim( $text )[0] ?? '';
		if ( '{' === $first || '[' === $first ) {
			return 'json';
		}
		return 'csv';
	}

	/**
	 * Read a file into either a package or sheets.
	 *
	 * @return array{type: string, package?: array, sheets?: array, zip?: string}
	 * @throws RuntimeException On unreadable or unsafe files.
	 */
	public static function read( string $path ): array {
		$type = self::sniff( $path );
		switch ( $type ) {
			case 'json':
				return self::read_json( (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			case 'xlsx':
				return array( 'type' => 'xlsx', 'sheets' => TMG_IO_Xlsx::read( $path ) );
			case 'csv':
				return array( 'type' => 'csv', 'sheets' => array( 'Sheet1' => self::parse_csv( (string) file_get_contents( $path ) ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			case 'zip':
				return self::read_zip( $path );
		}
		throw new RuntimeException( __( 'Unsupported or unreadable file. Use JSON, ZIP, CSV or Excel (.xlsx).', 'tamago-io' ) );
	}

	public static function read_json( string $raw ): array {
		$raw  = (string) preg_replace( '/^\xEF\xBB\xBF/', '', $raw );
		$json = json_decode( $raw, true );
		if ( ! is_array( $json ) ) {
			throw new RuntimeException( __( 'The JSON file is not valid.', 'tamago-io' ) );
		}
		if ( self::PACKAGE === ( $json['format'] ?? '' ) ) {
			return array( 'type' => 'json', 'package' => $json );
		}
		// An ACF export file (array of field groups / post types / taxonomies / options pages).
		if ( TMG_IO_ACF::looks_like_acf_export( $json ) ) {
			$pkg                  = self::empty_package();
			$pkg['acf_structure'] = isset( $json['key'] ) ? array( $json ) : $json;
			return array( 'type' => 'json', 'package' => $pkg );
		}
		// Plain JSON: a list of records (or {data|rows|items: [...]}) becomes one sheet; sheet => rows stays as is.
		if ( ! array_is_list( $json ) ) {
			foreach ( array( 'data', 'rows', 'items', 'records' ) as $k ) {
				if ( isset( $json[ $k ] ) && is_array( $json[ $k ] ) ) {
					$json = $json[ $k ];
					break;
				}
			}
		}
		if ( ! array_is_list( $json ) ) {
			$sheets = array();
			foreach ( $json as $name => $list ) {
				if ( is_array( $list ) && array_is_list( $list ) ) {
					$sheets[ (string) $name ] = self::records_to_table( $list );
				}
			}
			if ( $sheets ) {
				return array( 'type' => 'json', 'sheets' => $sheets );
			}
			$json = array( $json );
		}
		return array( 'type' => 'json', 'sheets' => array( 'Data' => self::records_to_table( $json ) ) );
	}

	/** List of records (assoc arrays, or already rows) → table with header row. */
	public static function records_to_table( array $list ): array {
		$list = array_values( array_filter( $list, 'is_array' ) );
		if ( $list && array_is_list( $list[0] ) ) {
			return $list;
		}
		$flat = array();
		foreach ( $list as $r ) {
			$flat[] = array_map(
				function ( $v ) {
					if ( is_array( $v ) ) {
						return array_is_list( $v ) && ! array_filter( $v, 'is_array' ) ? implode( ', ', $v ) : wp_json_encode( $v );
					}
					return is_bool( $v ) ? (int) $v : $v;
				},
				$r
			);
		}
		return self::to_table( $flat );
	}

	/** Read a ZIP: a package (package.json + media/) or a bundle of CSV / XLSX / JSON files. */
	public static function read_zip( string $path ): array {
		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new RuntimeException( __( 'ZIP files need the PHP zip extension, which this server does not have.', 'tamago-io' ) );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			throw new RuntimeException( __( 'The ZIP file could not be opened.', 'tamago-io' ) );
		}
		try {
			$names = array();
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$names[] = (string) $zip->getNameIndex( $i );
			}
			if ( in_array( 'package.json', $names, true ) ) {
				$out        = self::read_json( self::zip_entry( $zip, 'package.json' ) );
				$out['type'] = 'zip';
				$out['zip']  = $path;
				return $out;
			}
			$sheets = array();
			foreach ( $names as $name ) {
				if ( ! self::safe_zip_entry( $name ) ) {
					continue;
				}
				$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
				$base = pathinfo( $name, PATHINFO_FILENAME );
				if ( 'csv' === $ext ) {
					$sheets[ $base ] = self::parse_csv( self::zip_entry( $zip, $name ) );
				} elseif ( 'xlsx' === $ext || 'json' === $ext ) {
					$tmp = TMG_IO_Storage::new_path( $ext, 'zipentry' );
					file_put_contents( $tmp, self::zip_entry( $zip, $name ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
					try {
						$inner = self::read( $tmp );
					} finally {
						TMG_IO_Storage::delete( $tmp );
					}
					if ( isset( $inner['package'] ) ) {
						return array( 'type' => 'zip', 'package' => $inner['package'] );
					}
					foreach ( (array) ( $inner['sheets'] ?? array() ) as $sn => $rows ) {
						$sheets[ 'xlsx' === $ext ? $sn : $base ] = $rows;
					}
				}
			}
			if ( ! $sheets ) {
				throw new RuntimeException( __( 'The ZIP file has no package.json, CSV, Excel or JSON file inside.', 'tamago-io' ) );
			}
			return array( 'type' => 'zip', 'sheets' => $sheets );
		} finally {
			$zip->close();
		}
	}

	/** Read one ZIP entry into memory after the name and size checks. */
	public static function zip_entry( ZipArchive $zip, string $name ): string {
		if ( ! self::safe_zip_entry( $name ) ) {
			throw new RuntimeException( __( 'The ZIP file contains an unsafe path.', 'tamago-io' ) );
		}
		$stat = $zip->statName( $name );
		if ( ! $stat || $stat['size'] > self::ENTRY_MAX ) {
			throw new RuntimeException( __( 'A file inside the ZIP is missing or too large.', 'tamago-io' ) );
		}
		$data = $zip->getFromName( $name );
		if ( false === $data ) {
			throw new RuntimeException( __( 'A file inside the ZIP could not be read.', 'tamago-io' ) );
		}
		return $data;
	}

	/**
	 * Zip slip guard: a relative path made of plain segments only. We never extract a ZIP as a
	 * whole; entries are read by name and written to paths we choose.
	 */
	public static function safe_zip_entry( string $name ): bool {
		if ( '' === $name || strlen( $name ) > 255 || false !== strpos( $name, "\0" ) || false !== strpos( $name, '\\' ) ) {
			return false;
		}
		if ( '/' === $name[0] || preg_match( '#^[a-zA-Z]:#', $name ) ) {
			return false;
		}
		foreach ( explode( '/', $name ) as $seg ) {
			if ( '..' === $seg || '.' === $seg ) {
				return false;
			}
		}
		return true;
	}

	/** CSV text → rows. Strips BOM, detects the delimiter, normalises line endings in quoted cells. */
	public static function parse_csv( string $raw ): array {
		$raw = (string) preg_replace( '/^\xEF\xBB\xBF/', '', $raw );
		if ( ! mb_check_encoding( $raw, 'UTF-8' ) ) {
			$raw = mb_convert_encoding( $raw, 'UTF-8', 'Windows-1252' );
		}
		$first = (string) strtok( $raw, "\n" );
		$delim = ',';
		foreach ( array( ';', "\t", '|' ) as $d ) {
			if ( substr_count( $first, $d ) > substr_count( $first, $delim ) ) {
				$delim = $d;
			}
		}
		$rows = array();
		$fh   = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fwrite( $fh, $raw ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		rewind( $fh );
		while ( false !== ( $r = fgetcsv( $fh, 0, $delim, '"', '' ) ) ) { // phpcs:ignore
			if ( array( null ) !== $r ) {
				$rows[] = array_map( fn( $v ) => str_replace( "\r\n", "\n", (string) $v ), $r );
			}
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $rows;
	}

	/* ---------------------------------------------------------------------
	 * Upload checks
	 * ------------------------------------------------------------------- */

	/**
	 * Validate an uploaded file: extension allowed, content matches the extension, size limit.
	 * Returns the real type, or throws.
	 */
	public static function validate_upload( string $tmp, string $name, int $size ): string {
		$max = (int) TMG_IO_Settings::get( 'max_upload_mb' ) * MB_IN_BYTES;
		$max = min( $max, (int) wp_max_upload_size() ?: $max );
		if ( $size <= 0 || $size > $max ) {
			/* translators: %s: size limit */
			throw new RuntimeException( sprintf( __( 'The file is empty or larger than %s.', 'tamago-io' ), size_format( $max ) ) );
		}
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		$real = self::sniff( $tmp );
		$ok   = array(
			'json' => array( 'json' ),
			'txt'  => array( 'json', 'csv' ),
			'csv'  => array( 'csv' ),
			'tsv'  => array( 'csv' ),
			'xlsx' => array( 'xlsx' ),
			'zip'  => array( 'zip', 'xlsx' ),
		);
		if ( ! isset( $ok[ $ext ] ) ) {
			throw new RuntimeException( __( 'Only JSON, ZIP, CSV and Excel (.xlsx) files can be imported.', 'tamago-io' ) );
		}
		if ( ! in_array( $real, $ok[ $ext ], true ) ) {
			throw new RuntimeException( __( 'The file content does not match its extension.', 'tamago-io' ) );
		}
		return $real;
	}

	/* ---------------------------------------------------------------------
	 * Writing
	 * ------------------------------------------------------------------- */

	/** Formula injection guard for spreadsheet cells: prefix =, +, -, @ (and tab / CR) with a quote. */
	public static function escape_cell( $v ) {
		if ( is_string( $v ) && '' !== $v && preg_match( '/^[=+\-@\t\r]/', $v ) && ! is_numeric( $v ) ) {
			return "'" . $v;
		}
		return $v;
	}

	/** Undo escape_cell when reading back a file we may have written. */
	public static function unescape_cell( string $v ): string {
		if ( preg_match( "/^'[=+\-@\t\r]/", $v ) ) {
			return substr( $v, 1 );
		}
		return $v;
	}

	public static function escape_table( array $rows ): array {
		foreach ( $rows as $i => $row ) {
			foreach ( (array) $row as $c => $v ) {
				$rows[ $i ][ $c ] = self::escape_cell( is_array( $v ) ? wp_json_encode( $v ) : $v );
			}
		}
		return $rows;
	}

	/** Associative rows → table (header + values), columns in first-seen order. */
	public static function to_table( array $rows ): array {
		$cols = array();
		foreach ( $rows as $r ) {
			foreach ( array_keys( (array) $r ) as $k ) {
				$cols[ $k ] = true;
			}
		}
		$cols  = array_keys( $cols );
		$table = array( $cols );
		foreach ( $rows as $r ) {
			$line = array();
			foreach ( $cols as $c ) {
				$line[] = $r[ $c ] ?? '';
			}
			$table[] = $line;
		}
		return $table;
	}

	/** Table → associative rows keyed by header (duplicate headers get " (2)"). */
	public static function table_to_records( array $table ): array {
		$header = array();
		foreach ( (array) ( $table[0] ?? array() ) as $i => $h ) {
			$h = trim( (string) $h );
			$h = '' === $h ? 'Column ' . ( $i + 1 ) : $h;
			$k = $h;
			$n = 2;
			while ( in_array( $k, $header, true ) ) {
				$k = $h . ' (' . $n++ . ')';
			}
			$header[ $i ] = $k;
		}
		$out = array();
		foreach ( array_slice( $table, 1 ) as $row ) {
			if ( ! array_filter( (array) $row, fn( $v ) => '' !== trim( (string) $v ) ) ) {
				continue;
			}
			$rec = array();
			foreach ( $header as $i => $h ) {
				$rec[ $h ] = (string) ( $row[ $i ] ?? '' );
			}
			$out[] = $rec;
		}
		return $out;
	}

	public static function csv_string( array $rows ): string {
		$fh = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fwrite( $fh, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- BOM so Excel reads UTF-8.
		foreach ( self::escape_table( $rows ) as $row ) {
			fputcsv( $fh, array_map( fn( $v ) => is_scalar( $v ) || null === $v ? (string) $v : wp_json_encode( $v ), (array) $row ), ',', '"', '' );
		}
		rewind( $fh );
		$out = (string) stream_get_contents( $fh );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $out;
	}

	/**
	 * Write sheets to a file in the requested format.
	 *
	 * @return array{path: string, ext: string, mime: string}
	 */
	public static function write_sheets( array $sheets, string $format ): array {
		$sheets = $sheets ?: array( 'Sheet1' => array() );
		if ( 'xlsx' === $format && TMG_IO_Xlsx::available() ) {
			$escaped = array();
			foreach ( $sheets as $n => $rows ) {
				$escaped[ $n ] = self::escape_table( $rows );
			}
			return array(
				'path' => TMG_IO_Xlsx::write( $escaped ),
				'ext'  => 'xlsx',
				'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			);
		}
		if ( 'json' === $format ) {
			$out = array();
			foreach ( $sheets as $n => $rows ) {
				$out[ $n ] = self::table_to_records( $rows );
			}
			$path = TMG_IO_Storage::new_path( 'json', 'export' );
			file_put_contents( $path, wp_json_encode( 1 === count( $out ) ? reset( $out ) : $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return array( 'path' => $path, 'ext' => 'json', 'mime' => 'application/json' );
		}
		// CSV: one sheet → one file; several sheets → a ZIP of CSV files when possible.
		if ( count( $sheets ) > 1 && class_exists( 'ZipArchive' ) ) {
			$path = TMG_IO_Storage::new_path( 'zip', 'export' );
			$zip  = new ZipArchive();
			$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
			$used = array();
			foreach ( $sheets as $n => $rows ) {
				$file = sanitize_file_name( (string) $n ) ?: 'sheet';
				$base = $file;
				$k    = 2;
				while ( isset( $used[ $file ] ) ) {
					$file = $base . '-' . $k++;
				}
				$used[ $file ] = true;
				$zip->addFromString( $file . '.csv', self::csv_string( $rows ) );
			}
			$zip->close();
			return array( 'path' => $path, 'ext' => 'zip', 'mime' => 'application/zip' );
		}
		$path = TMG_IO_Storage::new_path( 'csv', 'export' );
		file_put_contents( $path, self::csv_string( (array) reset( $sheets ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return array( 'path' => $path, 'ext' => 'csv', 'mime' => 'text/csv; charset=utf-8' );
	}

	/** Stream a file to the browser and delete it. */
	public static function send_file( string $path, string $filename, string $mime ): void {
		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'Content-Type: ' . $mime );
			header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
			header( 'Content-Length: ' . filesize( $path ) );
			header( 'X-Content-Type-Options: nosniff' );
		}
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		TMG_IO_Storage::delete( $path );
		exit;
	}

	public static function empty_package(): array {
		return array(
			'format'        => self::PACKAGE,
			'version'       => 1,
			'plugin'        => TMG_IO_VERSION,
			'site'          => home_url(),
			'exported'      => gmdate( 'c' ),
			'acf'           => TMG_IO_ACF::edition(),
			'posts'         => array(),
			'terms'         => array(),
			'users'         => array(),
			'options'       => array(),
			'options_refs'  => array(),
			'menus'         => array(),
			'acf_structure' => array(),
			'media'         => array(),
			'lookup'        => array( 'posts' => array(), 'terms' => array(), 'users' => array() ),
		);
	}
}
