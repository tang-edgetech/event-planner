<?php
/**
 * Saved export presets (all export choices under a name, rerun in one click) and saved
 * column-mapping templates (post type + column → target). Stored in `tmg_io_presets` and
 * `tmg_io_templates` (not autoloaded).
 *
 * Used by: Export tab, Import tab (mapping step), Settings tab, WP-CLI `--preset`.
 */

defined( 'ABSPATH' ) || exit;

class TMG_IO_Presets {

	const EXPORT  = 'tmg_io_presets';
	const MAPPING = 'tmg_io_templates';

	public static function all( string $kind ): array {
		$v = get_option( $kind, array() );
		return is_array( $v ) ? $v : array();
	}

	public static function get( string $kind, string $name ): ?array {
		$all = self::all( $kind );
		return isset( $all[ $name ] ) && is_array( $all[ $name ] ) ? $all[ $name ] : null;
	}

	public static function save( string $kind, string $name, array $data ): string {
		$name = mb_substr( sanitize_text_field( $name ), 0, 80 );
		if ( '' === $name ) {
			return '';
		}
		if ( self::EXPORT === $kind ) {
			$data = TMG_IO_Exporter::sanitize( $data );
		} else {
			$map = array();
			foreach ( (array) ( $data['mapping'] ?? array() ) as $col => $target ) {
				$map[ sanitize_text_field( (string) $col ) ] = TMG_IO_Mapping::sanitize_target( (string) $target );
			}
			$data = array(
				'post_type' => sanitize_key( (string) ( $data['post_type'] ?? '' ) ),
				'source'    => sanitize_key( (string) ( $data['source'] ?? '' ) ),
				'mapping'   => $map,
			);
		}
		$all          = self::all( $kind );
		$all[ $name ] = $data;
		update_option( $kind, $all, false );
		return $name;
	}

	public static function delete( string $kind, string $name ): void {
		$all = self::all( $kind );
		unset( $all[ $name ] );
		update_option( $kind, $all, false );
	}
}
