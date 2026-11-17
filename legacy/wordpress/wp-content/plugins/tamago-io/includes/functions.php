<?php
/**
 * Public helper functions for themes and plugins: write Excel files, read any supported file
 * into sheets, send a download. Plus a small polyfill for PHP 7.4.
 *
 * Used by: site-specific code (for example data sources added through `tmg_io_sources`).
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'array_is_list' ) ) {
	/** PHP 8.1 array_is_list() for PHP 7.4 / 8.0 (WordPress < 6.5 has no polyfill). */
	function array_is_list( array $a ): bool { // phpcs:ignore
		$i = 0;
		foreach ( $a as $k => $v ) {
			if ( $k !== $i++ ) {
				return false;
			}
		}
		return true;
	}
}

/**
 * Write sheets to an .xlsx file.
 *
 * @param array $sheets Sheet name => rows (row 0 = headers). Cells starting with = + - @ are escaped.
 * @return string Path of a temporary file in the plugin's protected folder (delete it after use).
 */
function tmg_io_xlsx_write( array $sheets ): string {
	$escaped = array();
	foreach ( $sheets as $name => $rows ) {
		$escaped[ $name ] = TMG_IO_Files::escape_table( (array) $rows );
	}
	return TMG_IO_Xlsx::write( $escaped );
}

/**
 * Read a JSON, CSV, XLSX or ZIP file into sheets (sheet name => rows, row 0 = headers).
 * A Tamago package is returned flattened into its sheets.
 *
 * @throws RuntimeException When the file is unreadable or unsafe.
 */
function tmg_io_read_file( string $path ): array {
	$r = TMG_IO_Files::read( $path );
	if ( isset( $r['package'] ) ) {
		return TMG_IO_Flatten::sheets( $r['package'] );
	}
	return (array) ( $r['sheets'] ?? array() );
}

/**
 * Send sheets as a download (xlsx | csv | json) and exit. Several sheets as CSV become a ZIP.
 */
function tmg_io_send_download( array $sheets, string $basename, string $format ): void {
	$format = in_array( $format, array( 'xlsx', 'csv', 'json' ), true ) ? $format : 'xlsx';
	$file   = TMG_IO_Files::write_sheets( $sheets, $format );
	TMG_IO_Files::send_file( $file['path'], sanitize_file_name( $basename ) . '.' . $file['ext'], $file['mime'] );
}
