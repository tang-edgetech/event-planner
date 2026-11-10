<?php
/**
 * ACF Local JSON: field groups, post types and options pages live as JSON files in the theme, so they
 * are versioned in git, travel with the theme, and can still be edited in ACF (Developers only).
 *
 *   core/acf-json/   features: Event, Microsite, Applicant post types; Event / Microsite / Settings
 *                    field groups; Event Plus Settings options page; and anything new
 *   acf-json/        presentation: Branding field group and options page (keys containing _ept_)
 *
 * Saving an item in ACF writes its file back to the same folder. After pulling changes on another
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

/** Presentation items (keys containing _ept_, e.g. group_ept_branding) are saved next to the presentation code. */
add_filter( 'acf/json/save_paths', function ( array $paths, array $post ): array {
	if ( str_contains( (string) ( $post['key'] ?? '' ), '_ept_' ) ) {
		return array( get_template_directory() . '/acf-json' );
	}
	return $paths;
}, 10, 2 );

/** One file per item, named by its key (stable across title changes). */
add_filter( 'acf/json/save_file_name', fn( $name, $post ) => ( $post['key'] ?? pathinfo( $name, PATHINFO_FILENAME ) ) . '.json', 10, 2 );
