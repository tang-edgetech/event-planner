<?php
/**
 * ACF Local JSON: every field group lives as a JSON file in the theme, so it is versioned in git,
 * travels with the theme, and can still be edited in ACF → Field Groups (Developers only).
 *
 *   core/acf-json/   feature groups: Event, Microsite, Event Plus Settings (group_epc_*), and any new group
 *   acf-json/        presentation groups: Branding (group_ept_*)
 *
 * Saving a group in ACF writes its file back to the same folder. After pulling changes on another
 * site, ACF → Field Groups shows "Sync available": click Sync to load them.
 */

defined( 'ABSPATH' ) || exit;

/** Where ACF reads field groups from. */
add_filter( 'acf/settings/load_json', function ( array $paths ): array {
	$paths[] = EPC_DIR . 'acf-json';
	$paths[] = get_template_directory() . '/acf-json';
	return array_values( array_unique( $paths ) );
} );

/** Default folder for new groups. */
add_filter( 'acf/settings/save_json', fn() => EPC_DIR . 'acf-json' );

/** Presentation groups (keys starting group_ept_) are saved next to the presentation code. */
add_filter( 'acf/json/save_paths', function ( array $paths, array $post ): array {
	if ( str_starts_with( (string) ( $post['key'] ?? '' ), 'group_ept_' ) ) {
		return array( get_template_directory() . '/acf-json' );
	}
	return $paths;
}, 10, 2 );

/** One file per group, named by its key (stable across title changes). */
add_filter( 'acf/json/save_file_name', fn( $name, $post ) => ( $post['key'] ?? pathinfo( $name, PATHINFO_FILENAME ) ) . '.json', 10, 2 );
