<?php
/**
 * Everything that connects the theme to ACF Pro. Screens: ACF → Post Types, Options Pages, Field Groups.
 *
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

/* -------------------------------------------------------------------------
 * What ACF cannot set itself
 * ---------------------------------------------------------------------- */

/**
 * "Add new" permission: Events and Microsites use their own create_ep_* capability (granted in
 * Roles & Permissions); Applicants are never created by hand.
 */
add_filter( 'register_post_type_args', function ( array $args, string $post_type ): array {
	if ( EPC_Post_Types::APPLICANT === $post_type ) {
		$args['capabilities']['create_posts'] = 'do_not_allow';
	} elseif ( in_array( $post_type, array( EPC_Post_Types::EVENT, EPC_Post_Types::MICROSITE ), true ) ) {
		$args['capabilities']['create_posts'] = 'create_' . $post_type . 's';
	}
	return $args;
}, 10, 2 );

/** Custom HTML section: cleaned when saved by someone without "Custom HTML & embed codes" (see epc_clean_embed_html()). */
add_filter(
	'acf/update_value/key=field_epc_html_code',
	static function ( $value ) {
		return current_user_can( 'unfiltered_html' ) ? $value : epc_clean_embed_html( (string) $value );
	}
);

/* -------------------------------------------------------------------------
 * Event fields filled in by code (fields: ACF → Field Groups → Event)
 * ---------------------------------------------------------------------- */

/** Form builder "Type" choices come from code, so a new field type appears without editing the field group. */
add_filter( 'acf/load_field/key=field_epc_ff_type', function ( $field ) {
	$field['choices'] = epc_field_types();
	return $field;
} );


/** Populate the "Saved list" dropdown from Settings. */
add_filter( 'acf/load_field/key=field_epc_ff_preset', function ( $field ) {
	$field['choices'] = array();
	foreach ( epc_preset_lists() as $slug => $list ) {
		$field['choices'][ $slug ] = $list['name'] . ' (' . count( $list['options'] ) . ')';
	}
	return $field;
} );

/** Auto-generate date/time display text if left blank. */
add_filter( 'acf/load_value/name=event_date_text', function ( $value, $post_id ) {
	if ( $value ) {
		return $value;
	}
	$start = get_post_meta( (int) $post_id, 'event_start', true );
	return $start ? date_i18n( 'jS F Y', strtotime( $start ) ) : '';
}, 10, 2 );
add_filter( 'acf/load_value/name=event_time_text', function ( $value, $post_id ) {
	if ( $value ) {
		return $value;
	}
	$start = get_post_meta( (int) $post_id, 'event_start', true );
	$end   = get_post_meta( (int) $post_id, 'event_end', true );
	if ( ! $start ) {
		return '';
	}
	$out = date_i18n( 'g:ia', strtotime( $start ) );
	if ( $end ) {
		$out .= ' – ' . date_i18n( 'g:ia', strtotime( $end ) );
	}
	return $out;
}, 10, 2 );
