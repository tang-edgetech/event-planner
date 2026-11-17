<?php
/**
 * WP-CLI commands: `wp tamago-io export`, `wp tamago-io import <file>`, `wp tamago-io settings
 * export|import`, `wp tamago-io runs`. Commands act as the --user given (or the first
 * administrator) and go through the same capability checks as the screens.
 *
 * Used by: WP-CLI.
 */

defined( 'ABSPATH' ) || exit;

class TMG_IO_CLI {

	/** Act as --user, or as the first administrator holding our capabilities. */
	private static function ensure_user(): void {
		if ( get_current_user_id() ) {
			return;
		}
		$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID' ) );
		if ( ! $admins ) {
			WP_CLI::error( 'No user to act as. Pass --user=<login>.' );
		}
		wp_set_current_user( $admins[0]->ID );
		WP_CLI::debug( 'Acting as ' . $admins[0]->user_login . ' (pass --user to choose).' );
	}

	private static function list_arg( array $a, string $key ): array {
		return isset( $a[ $key ] ) && true !== $a[ $key ] ? array_values( array_filter( array_map( 'trim', explode( ',', (string) $a[ $key ] ) ) ) ) : array();
	}

	/**
	 * Export content, options, menus and ACF structure.
	 *
	 * ## OPTIONS
	 *
	 * [--types=<types>]
	 * : Comma-separated post types (post,page,attachment,…).
	 *
	 * [--format=<format>]
	 * : json, zip, csv or xlsx.
	 * ---
	 * default: json
	 * ---
	 *
	 * [--file=<file>]
	 * : Where to write. Default: a file in the current directory.
	 *
	 * [--status=<status>]
	 * : Comma-separated statuses.
	 *
	 * [--date-from=<date>]
	 * : Y-m-d.
	 *
	 * [--date-to=<date>]
	 * : Y-m-d.
	 *
	 * [--author=<id>]
	 * : Author ID.
	 *
	 * [--terms=<terms>]
	 * : taxonomy:slug pairs, comma-separated.
	 *
	 * [--ids=<ids>]
	 * : Comma-separated IDs.
	 *
	 * [--limit=<n>]
	 * : Maximum number of posts.
	 *
	 * [--comments]
	 * : Include comments.
	 *
	 * [--taxonomies=<taxonomies>]
	 * : Export all terms of these taxonomies.
	 *
	 * [--users]
	 * : Export users.
	 *
	 * [--roles=<roles>]
	 * : Only users with these roles.
	 *
	 * [--options=<names>]
	 * : Option names or prefixes (ending with * or _), comma-separated.
	 *
	 * [--options-pages=<slugs>]
	 * : ACF options page slugs.
	 *
	 * [--menus=<menus>]
	 * : Menu IDs or slugs, or "all".
	 *
	 * [--acf-structure[=<kinds>]]
	 * : ACF structure: field_groups,post_types,taxonomies,options_pages (default all).
	 *
	 * [--preset=<name>]
	 * : Use a saved export preset (other options are ignored except --format and --file).
	 *
	 * ## EXAMPLES
	 *
	 *     wp tamago-io export --types=post,page --format=zip --acf-structure --file=site.zip
	 *     wp tamago-io export --options=options_ --format=json
	 *
	 * @when after_wp_load
	 */
	public function export( $args, $a ) {
		self::ensure_user();
		if ( ! current_user_can( TMG_IO_Permissions::EXPORT ) ) {
			WP_CLI::error( 'This user may not export (tmg_io_export).' );
		}
		if ( ! empty( $a['preset'] ) ) {
			$c = TMG_IO_Presets::get( TMG_IO_Presets::EXPORT, (string) $a['preset'] );
			if ( ! $c ) {
				WP_CLI::error( 'Preset not found.' );
			}
		} else {
			$acf = $a['acf-structure'] ?? null;
			$c   = array(
				'types'         => self::list_arg( $a, 'types' ),
				'status'        => self::list_arg( $a, 'status' ),
				'date_from'     => $a['date-from'] ?? '',
				'date_to'       => $a['date-to'] ?? '',
				'author'        => (int) ( $a['author'] ?? 0 ),
				'terms'         => $a['terms'] ?? '',
				'ids'           => $a['ids'] ?? '',
				'limit'         => (int) ( $a['limit'] ?? 0 ),
				'comments'      => ! empty( $a['comments'] ) ? 1 : 0,
				'taxonomies'    => self::list_arg( $a, 'taxonomies' ),
				'users'         => ! empty( $a['users'] ) ? 1 : 0,
				'user_roles'    => self::list_arg( $a, 'roles' ),
				'options'       => str_replace( ',', "\n", (string) ( $a['options'] ?? '' ) ),
				'options_pages' => self::list_arg( $a, 'options-pages' ),
				'menus'         => self::list_arg( $a, 'menus' ),
				'acf_structure' => null === $acf ? array() : ( true === $acf ? array_keys( TMG_IO_ACF::structure_kinds() ) : self::list_arg( $a, 'acf-structure' ) ),
			);
		}
		if ( isset( $a['format'] ) ) {
			$c['format'] = $a['format'];
		}
		$file = TMG_IO_Exporter::export_logged( $c, 'WP-CLI' );
		$dest = ! empty( $a['file'] ) ? (string) $a['file'] : getcwd() . DIRECTORY_SEPARATOR . $file['filename'];
		if ( ! @rename( $file['path'], $dest ) ) { // phpcs:ignore
			if ( ! copy( $file['path'], $dest ) ) {
				WP_CLI::error( 'Could not write ' . $dest );
			}
			TMG_IO_Storage::delete( $file['path'] );
		}
		$run = TMG_IO_Runs::get( $file['run'] );
		$sum = array();
		foreach ( (array) $run['summary'] as $k => $n ) {
			if ( $n ) {
				$sum[] = "$k: $n";
			}
		}
		WP_CLI::success( 'Exported to ' . $dest . ' (' . implode( ', ', $sum ) . ')' );
	}

	/**
	 * Import a JSON, ZIP, CSV or XLSX file from the server.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the file.
	 *
	 * [--match=<rule>]
	 * : slug, id, guid, none, or meta:<key>.
	 * ---
	 * default: slug
	 * ---
	 *
	 * [--existing=<action>]
	 * : skip, update or copy.
	 * ---
	 * default: skip
	 * ---
	 *
	 * [--dry-run]
	 * : Report what would happen without changing anything.
	 *
	 * [--empty-missing]
	 * : When updating, empty fields missing from the file.
	 *
	 * [--no-media]
	 * : Do not download media from URLs.
	 *
	 * [--no-comments]
	 * : Do not import comments.
	 *
	 * [--keep-ids]
	 * : Keep original IDs of new items when free.
	 *
	 * [--sections=<sections>]
	 * : acf_structure,terms,users,media,posts,menus,options (default all).
	 *
	 * [--post-type=<type>]
	 * : For CSV / Excel without a _tmg_json column: the content type to create.
	 *
	 * [--mapping=<template>]
	 * : Saved mapping template (otherwise columns are matched by name).
	 *
	 * [--sheet=<name>]
	 * : Sheet to import from a workbook.
	 *
	 * [--report=<file>]
	 * : Write the report as CSV.
	 *
	 * ## EXAMPLES
	 *
	 *     wp tamago-io import site.zip --existing=update --user=admin
	 *     wp tamago-io import products.csv --post-type=product --match=meta:sku --existing=update --dry-run
	 *
	 * @when after_wp_load
	 */
	public function import( $args, $a ) {
		self::ensure_user();
		if ( ! current_user_can( TMG_IO_Permissions::IMPORT ) ) {
			WP_CLI::error( 'This user may not import (tmg_io_import).' );
		}
		$src = (string) $args[0];
		if ( ! is_file( $src ) || ! is_readable( $src ) ) {
			WP_CLI::error( 'File not found: ' . $src );
		}
		$type = TMG_IO_Files::sniff( $src );
		if ( '' === $type ) {
			WP_CLI::error( 'Unsupported file type.' );
		}
		// Work on a copy in the protected folder; the original stays untouched.
		$copy = TMG_IO_Storage::new_path( $type, 'cli' );
		copy( $src, $copy );
		try {
			$o = array(
				'sheet'  => $a['sheet'] ?? '',
				'source' => '',
			);
			$w = TMG_IO_Importer::work_from_parsed( TMG_IO_Files::read( $copy ), $copy, $o );
		} catch ( Throwable $e ) {
			TMG_IO_Storage::delete( $copy );
			WP_CLI::error( $e->getMessage() );
			return;
		}
		$opts = array(
			'match'         => $a['match'] ?? 'slug',
			'existing'      => $a['existing'] ?? 'skip',
			'dry_run'       => ! empty( $a['dry-run'] ) ? 1 : 0,
			'empty_missing' => ! empty( $a['empty-missing'] ) ? 1 : 0,
			'media'         => isset( $a['media'] ) && false === $a['media'] ? 0 : 1,
			'comments'      => isset( $a['comments'] ) && false === $a['comments'] ? 0 : 1,
			'keep_ids'      => ! empty( $a['keep-ids'] ) ? 1 : 0,
		);
		if ( ! empty( $a['sections'] ) ) {
			$opts['sections'] = self::list_arg( $a, 'sections' );
		}
		if ( 'rows' === $w['kind'] ) {
			$pt = (string) ( $a['post-type'] ?? '' );
			$tp = ! empty( $a['mapping'] ) ? TMG_IO_Presets::get( TMG_IO_Presets::MAPPING, (string) $a['mapping'] ) : null;
			if ( ! empty( $a['mapping'] ) && ! $tp ) {
				TMG_IO_Storage::delete( $copy );
				WP_CLI::error( 'Mapping template not found.' );
			}
			$pt = '' !== $pt ? $pt : (string) ( $tp['post_type'] ?? '' );
			if ( ! post_type_exists( $pt ) ) {
				TMG_IO_Storage::delete( $copy );
				WP_CLI::error( 'This file needs --post-type=<type> (it has no _tmg_json column).' );
			}
			$map = $tp ? (array) $tp['mapping'] : array();
			if ( ! $map ) {
				$groups = TMG_IO_Mapping::targets( $pt );
				foreach ( $w['headers'] as $h ) {
					$map[ $h ] = TMG_IO_Mapping::guess( (string) $h, $pt, $groups );
				}
			}
			$opts['post_type'] = $pt;
			$opts['mapping']   = $map;
			$opts['sections']  = array();
			WP_CLI::log( 'Mapping: ' . wp_json_encode( array_filter( $map ) ) );
		}
		$run = TMG_IO_Importer::create_run( $w['work'], $opts, basename( $src ), $type, $copy );
		if ( $opts['dry_run'] ) {
			// Dry runs do not own the copy.
			$r = TMG_IO_Runs::get( $run );
			$r['options']['file'] = '';
			TMG_IO_Runs::update( $run, array( 'options' => $r['options'] ) );
		}
		$total    = (int) ( TMG_IO_Runs::get( $run )['summary']['total'] ?? 0 );
		$progress = $total > 0 ? \WP_CLI\Utils\make_progress_bar( 'Importing', $total ) : null;
		$last     = 0;
		$status   = TMG_IO_Importer::run_all(
			$run,
			function ( $s ) use ( $progress, &$last ) {
				if ( $progress && isset( $s['done'] ) ) {
					$progress->tick( max( 0, (int) $s['done'] - $last ) );
					$last = (int) $s['done'];
				}
			}
		);
		if ( $progress ) {
			$progress->finish();
		}
		if ( $opts['dry_run'] ) {
			TMG_IO_Storage::delete( $copy );
		}
		if ( ! empty( $status['error'] ) ) {
			WP_CLI::error( $status['error'] );
		}
		$r = TMG_IO_Runs::get( $run );
		if ( ! empty( $a['report'] ) ) {
			file_put_contents( (string) $a['report'], TMG_IO_Files::csv_string( TMG_IO_Runs::report_table( $r ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		$rows = array();
		foreach ( (array) ( $r['summary']['counts'] ?? array() ) as $section => $c ) {
			$rows[] = array(
				'section' => $section,
				'created' => (int) ( $c['created'] ?? 0 ),
				'updated' => (int) ( $c['updated'] ?? 0 ),
				'skipped' => (int) ( $c['skipped'] ?? 0 ),
				'errors'  => (int) ( $c['error'] ?? 0 ),
			);
		}
		if ( $rows ) {
			\WP_CLI\Utils\format_items( 'table', $rows, array( 'section', 'created', 'updated', 'skipped', 'errors' ) );
		}
		foreach ( (array) $r['report'] as $item ) {
			if ( 'error' === $item['a'] ) {
				WP_CLI::warning( $item['s'] . ' ' . $item['id'] . ' ' . $item['t'] . ': ' . $item['m'] );
			}
		}
		WP_CLI::success( ( $opts['dry_run'] ? 'Dry run finished' : 'Import finished' ) . ' (run #' . $run . ').' );
	}

	/**
	 * Export or import this plugin's settings, presets and mapping templates.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : export or import.
	 *
	 * [<file>]
	 * : File to write (export, default stdout) or read (import).
	 *
	 * ## EXAMPLES
	 *
	 *     wp tamago-io settings export io-settings.json
	 *     wp tamago-io settings import io-settings.json
	 *
	 * @when after_wp_load
	 */
	public function settings( $args, $a ) {
		self::ensure_user();
		if ( ! current_user_can( TMG_IO_Permissions::MANAGE ) ) {
			WP_CLI::error( 'This user may not manage the plugin (tmg_io_manage).' );
		}
		$action = $args[0] ?? '';
		$file   = $args[1] ?? '';
		if ( 'export' === $action ) {
			$json = (string) wp_json_encode( TMG_IO_Settings::export_data(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( '' === $file ) {
				WP_CLI::line( $json );
				return;
			}
			file_put_contents( $file, $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			WP_CLI::success( 'Settings written to ' . $file );
			return;
		}
		if ( 'import' === $action ) {
			if ( '' === $file || ! is_readable( $file ) ) {
				WP_CLI::error( 'Give the settings file to import.' );
			}
			$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$err  = TMG_IO_Settings::import_data( is_array( $data ) ? $data : array() );
			if ( '' !== $err ) {
				WP_CLI::error( $err );
			}
			WP_CLI::success( 'Settings imported.' );
			return;
		}
		WP_CLI::error( 'Use: wp tamago-io settings export [<file>] | import <file>' );
	}

	/**
	 * List recent imports and exports.
	 *
	 * [--limit=<n>]
	 * : How many.
	 * ---
	 * default: 20
	 * ---
	 *
	 * @when after_wp_load
	 */
	public function runs( $args, $a ) {
		list( $rows ) = TMG_IO_Runs::query( array( 'per_page' => (int) ( $a['limit'] ?? 20 ) ) );
		$out          = array();
		foreach ( $rows as $r ) {
			$out[] = array(
				'id'      => $r['id'],
				'date'    => $r['created_at'],
				'kind'    => $r['kind'] . ( $r['dry_run'] ? ' (dry)' : '' ),
				'status'  => $r['status'],
				'source'  => $r['source'],
				'summary' => wp_json_encode( $r['summary'] ),
			);
		}
		\WP_CLI\Utils\format_items( 'table', $out, array( 'id', 'date', 'kind', 'status', 'source', 'summary' ) );
	}
}

WP_CLI::add_command( 'tamago-io', 'TMG_IO_CLI' );
