<?php
/**
 * Extension point for site-specific data sources (`tmg_io_sources` filter). A theme or plugin
 * adds a source with its own options form, export (sheets) and optional import (mapped rows).
 *
 * Contract (see README.md):
 *   $sources['my_id'] = array(
 *     'label'      => 'Applicants of an event',
 *     'capability' => 'my_cap',                       // checked for export and import
 *     'formats'    => array( 'xlsx', 'csv', 'json' ), // export formats offered
 *     'fields'     => callable( string $context ): string,  // options form HTML ('export' | 'import'), inputs named choices[...]
 *     'export'     => callable( array $choices ): array,    // sheet name => rows, row 0 = headers
 *     'import'     => callable|null( array $rows, array $mapping, array $opts ): array, // report
 *     'columns'    => callable|null( array $choices ): array, // target key => label, for the mapping step
 *   );
 *
 * Used by: Export and Import tabs, importer (source phase), WP-CLI.
 */

defined( 'ABSPATH' ) || exit;

class TMG_IO_Sources {

	/** Registered sources, normalised. Invalid entries are dropped. */
	public static function all(): array {
		$raw = apply_filters( 'tmg_io_sources', array() );
		$out = array();
		foreach ( (array) $raw as $id => $s ) {
			$id = sanitize_key( (string) $id );
			if ( '' === $id || ! is_array( $s ) || empty( $s['export'] ) || ! is_callable( $s['export'] ) ) {
				continue;
			}
			$formats  = array_values( array_intersect( (array) ( $s['formats'] ?? array( 'xlsx', 'csv' ) ), array( 'xlsx', 'csv', 'json' ) ) );
			$out[ $id ] = array(
				'label'      => (string) ( $s['label'] ?? $id ),
				'capability' => (string) ( $s['capability'] ?? TMG_IO_Permissions::MANAGE ),
				'formats'    => $formats ? $formats : array( 'xlsx', 'csv' ),
				'fields'     => isset( $s['fields'] ) && is_callable( $s['fields'] ) ? $s['fields'] : null,
				'export'     => $s['export'],
				'import'     => isset( $s['import'] ) && is_callable( $s['import'] ) ? $s['import'] : null,
				'columns'    => isset( $s['columns'] ) && is_callable( $s['columns'] ) ? $s['columns'] : null,
			);
		}
		return $out;
	}

	/** Sources the current user may use, optionally only those that import. */
	public static function available( bool $importing = false ): array {
		return array_filter(
			self::all(),
			fn( $s ) => current_user_can( $s['capability'] ) && ( ! $importing || $s['import'] )
		);
	}

	/** The options form HTML of a source (already escaped by the source). */
	public static function fields_html( array $s, string $context ): string {
		if ( ! $s['fields'] ) {
			return '';
		}
		ob_start();
		$html = call_user_func( $s['fields'], $context );
		$echo = ob_get_clean();
		return (string) $echo . ( is_string( $html ) ? $html : '' );
	}

	/** Target columns for the mapping step: key => label. */
	public static function columns( array $s, array $choices ): array {
		if ( ! $s['columns'] ) {
			return array();
		}
		$out = array();
		foreach ( (array) call_user_func( $s['columns'], $choices ) as $k => $label ) {
			$out[ sanitize_text_field( (string) $k ) ] = (string) $label;
		}
		return $out;
	}

	/** Export a source to sheets (validated). */
	public static function export( array $s, array $choices ): array {
		$sheets = (array) call_user_func( $s['export'], $choices );
		$out    = array();
		foreach ( $sheets as $name => $rows ) {
			if ( ! is_array( $rows ) ) {
				continue;
			}
			// Associative rows are accepted too.
			if ( $rows && ! array_is_list( (array) reset( $rows ) ) ) {
				$rows = TMG_IO_Files::to_table( $rows );
			}
			$out[ (string) $name ] = array_values( array_map( fn( $r ) => array_values( (array) $r ), $rows ) );
		}
		return $out;
	}
}
