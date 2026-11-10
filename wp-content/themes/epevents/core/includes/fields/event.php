<?php
/**
 * Event fields: code that fills in or adjusts fields at runtime.
 * The fields themselves are defined in ACF (ACF → Field Groups → "Event"), saved as
 * core/acf-json/group_epc_event.json.
 */

defined( 'ABSPATH' ) || exit;

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
