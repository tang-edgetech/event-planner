<?php
/**
 * Event (registration form) fields.
 */

defined( 'ABSPATH' ) || exit;

/** Shorthand for ACF conditional logic: show when $key {op} $value. */
function epc_when( string $key, $value, string $op = '==' ): array {
	$rules = array();
	foreach ( (array) $value as $v ) {
		$rules[] = array( array( 'field' => $key, 'operator' => $op, 'value' => $v ) );
	}
	return $rules;
}

add_action( 'acf/init', function () {
	$t = 'field_epc_ff_type';

	acf_add_local_field_group(
		array(
			'key'             => 'group_epc_event',
			'title'           => 'Event',
			'location'        => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => EPC_Post_Types::EVENT ) ) ),
			'position'        => 'acf_after_title',
			'style'           => 'seamless',
			'hide_on_screen'  => array( 'the_content', 'excerpt', 'discussion', 'comments', 'author', 'format', 'send-trackbacks' ),
			'fields'          => array(

				/* ---------------- Details ---------------- */
				array( 'key' => 'field_epc_ev_tab_details', 'label' => 'Details', 'type' => 'tab' ),
				array( 'key' => 'field_epc_ev_date', 'label' => 'Event start', 'name' => 'event_start', 'type' => 'date_time_picker', 'display_format' => 'j F Y g:i a', 'return_format' => 'Y-m-d H:i:s', 'wrapper' => array( 'width' => 33 ) ),
				array( 'key' => 'field_epc_ev_end', 'label' => 'Event end', 'name' => 'event_end', 'type' => 'date_time_picker', 'display_format' => 'j F Y g:i a', 'return_format' => 'Y-m-d H:i:s', 'wrapper' => array( 'width' => 33 ) ),
				array( 'key' => 'field_epc_ev_venue', 'label' => 'Venue', 'name' => 'venue', 'type' => 'text', 'wrapper' => array( 'width' => 34 ) ),
				array( 'key' => 'field_epc_ev_date_text', 'label' => 'Date (display text)', 'name' => 'event_date_text', 'type' => 'text', 'placeholder' => '13th September 2026', 'instructions' => 'Leave blank to generate from Event start.', 'wrapper' => array( 'width' => 33 ) ),
				array( 'key' => 'field_epc_ev_time_text', 'label' => 'Time (display text)', 'name' => 'event_time_text', 'type' => 'text', 'placeholder' => '8:30am – 5:00pm', 'wrapper' => array( 'width' => 33 ) ),
				array( 'key' => 'field_epc_ev_map', 'label' => 'Venue map link', 'name' => 'venue_map', 'type' => 'url', 'wrapper' => array( 'width' => 34 ) ),
				array( 'key' => 'field_epc_ev_banner', 'label' => 'Banner (desktop)', 'name' => 'banner_desktop', 'type' => 'image', 'return_format' => 'array', 'preview_size' => 'medium', 'instructions' => 'Recommended 1920×600.', 'wrapper' => array( 'width' => 50 ) ),
				array( 'key' => 'field_epc_ev_banner_m', 'label' => 'Banner (mobile)', 'name' => 'banner_mobile', 'type' => 'image', 'return_format' => 'array', 'preview_size' => 'medium', 'instructions' => 'Optional. Recommended 800×1000.', 'wrapper' => array( 'width' => 50 ) ),
				array( 'key' => 'field_epc_ev_accent', 'label' => 'Accent colour', 'name' => 'accent_color', 'type' => 'color_picker', 'wrapper' => array( 'width' => 33 ) ),
				array( 'key' => 'field_epc_ev_page_bg', 'label' => 'Page background', 'name' => 'page_bg', 'type' => 'color_picker', 'wrapper' => array( 'width' => 33 ) ),
				array( 'key' => 'field_epc_ev_show_title', 'label' => 'Show title above form', 'name' => 'show_title', 'type' => 'true_false', 'ui' => 1, 'default_value' => 1, 'wrapper' => array( 'width' => 34 ) ),

				/* ---------------- Form builder ---------------- */
				array( 'key' => 'field_epc_ev_tab_form', 'label' => 'Form', 'type' => 'tab' ),
				array( 'key' => 'field_epc_ev_form_intro', 'label' => 'Text above form', 'name' => 'form_intro', 'type' => 'wysiwyg', 'tabs' => 'all', 'toolbar' => 'basic', 'media_upload' => 0 ),
				array(
					'key'          => 'field_epc_form_fields',
					'label'        => 'Form fields',
					'name'         => 'form_fields',
					'type'         => 'repeater',
					'layout'       => 'block',
					'collapsed'    => 'field_epc_ff_label',
					'button_label' => 'Add field',
					'instructions' => 'Drag to reorder. Use the width setting to place fields side-by-side.',
					'sub_fields'   => array(
						array( 'key' => $t, 'label' => 'Type', 'name' => 'type', 'type' => 'select', 'choices' => epc_field_types(), 'default_value' => 'text', 'wrapper' => array( 'width' => 25 ) ),
						array( 'key' => 'field_epc_ff_label', 'label' => 'Label', 'name' => 'label', 'type' => 'text', 'wrapper' => array( 'width' => 35 ) ),
						array( 'key' => 'field_epc_ff_name', 'label' => 'Field key', 'name' => 'name', 'type' => 'text', 'instructions' => 'Auto from label. Used in exports, emails ({key}) and conditions.', 'wrapper' => array( 'width' => 20 ), 'conditional_logic' => array( array( array( 'field' => $t, 'operator' => '!=', 'value' => 'heading' ), array( 'field' => $t, 'operator' => '!=', 'value' => 'html' ) ) ) ),
						array(
							'key'           => 'field_epc_ff_width',
							'label'         => 'Width',
							'name'          => 'width',
							'type'          => 'select',
							'choices'       => array( '100' => 'Full', '66' => '2/3', '50' => '1/2', '33' => '1/3', '25' => '1/4' ),
							'default_value' => '50',
							'wrapper'       => array( 'width' => 10 ),
						),
						array( 'key' => 'field_epc_ff_required', 'label' => 'Required', 'name' => 'required', 'type' => 'true_false', 'ui' => 1, 'wrapper' => array( 'width' => 10 ), 'conditional_logic' => array( array( array( 'field' => $t, 'operator' => '!=', 'value' => 'heading' ), array( 'field' => $t, 'operator' => '!=', 'value' => 'html' ), array( 'field' => $t, 'operator' => '!=', 'value' => 'hidden' ) ) ) ),

						array( 'key' => 'field_epc_ff_placeholder', 'label' => 'Placeholder', 'name' => 'placeholder', 'type' => 'text', 'wrapper' => array( 'width' => 35 ), 'conditional_logic' => epc_when( $t, array( 'text', 'textarea', 'email', 'phone', 'nric', 'number', 'select', 'salutation', 'country' ) ) ),
						array( 'key' => 'field_epc_ff_help', 'label' => 'Help text', 'name' => 'help', 'type' => 'text', 'wrapper' => array( 'width' => 35 ), 'conditional_logic' => array( array( array( 'field' => $t, 'operator' => '!=', 'value' => 'heading' ), array( 'field' => $t, 'operator' => '!=', 'value' => 'html' ), array( 'field' => $t, 'operator' => '!=', 'value' => 'hidden' ) ) ) ),
						array( 'key' => 'field_epc_ff_default', 'label' => 'Default value', 'name' => 'default', 'type' => 'text', 'wrapper' => array( 'width' => 30 ), 'conditional_logic' => array( array( array( 'field' => $t, 'operator' => '!=', 'value' => 'heading' ), array( 'field' => $t, 'operator' => '!=', 'value' => 'html' ), array( 'field' => $t, 'operator' => '!=', 'value' => 'consent' ) ) ) ),

						// Format options.
						array(
							'key'               => 'field_epc_ff_phone_format',
							'label'             => 'Phone format',
							'name'              => 'phone_format',
							'type'              => 'select',
							'choices'           => array( 'my' => 'Malaysia (saved as +60…)', 'intl' => 'International – country code required', 'any' => 'Any phone number' ),
							'default_value'     => 'my',
							'wrapper'           => array( 'width' => 35 ),
							'conditional_logic' => epc_when( $t, 'phone' ),
						),
						array(
							'key'               => 'field_epc_ff_nric_format',
							'label'             => 'ID format',
							'name'              => 'nric_format',
							'type'              => 'select',
							'choices'           => array( 'my' => 'Malaysian NRIC only (YYMMDD-PB-####)', 'my_or_passport' => 'Malaysian NRIC or Passport', 'any' => 'Any ID number' ),
							'default_value'     => 'my',
							'wrapper'           => array( 'width' => 35 ),
							'conditional_logic' => epc_when( $t, 'nric' ),
						),

						// Options.
						array(
							'key'               => 'field_epc_ff_source',
							'label'             => 'Options from',
							'name'              => 'options_source',
							'type'              => 'button_group',
							'choices'           => array( 'custom' => 'Type options', 'preset' => 'Saved list' ),
							'default_value'     => 'custom',
							'wrapper'           => array( 'width' => 25 ),
							'conditional_logic' => epc_when( $t, array( 'select', 'radio', 'checkboxes' ) ),
						),
						array(
							'key'               => 'field_epc_ff_options',
							'label'             => 'Options',
							'name'              => 'options',
							'type'              => 'textarea',
							'rows'              => 4,
							'instructions'      => 'One per line.',
							'wrapper'           => array( 'width' => 50 ),
							'conditional_logic' => array(
								array( array( 'field' => $t, 'operator' => '==', 'value' => 'select' ), array( 'field' => 'field_epc_ff_source', 'operator' => '!=', 'value' => 'preset' ) ),
								array( array( 'field' => $t, 'operator' => '==', 'value' => 'radio' ), array( 'field' => 'field_epc_ff_source', 'operator' => '!=', 'value' => 'preset' ) ),
								array( array( 'field' => $t, 'operator' => '==', 'value' => 'checkboxes' ), array( 'field' => 'field_epc_ff_source', 'operator' => '!=', 'value' => 'preset' ) ),
							),
						),
						array(
							'key'               => 'field_epc_ff_preset',
							'label'             => 'Saved list',
							'name'              => 'preset',
							'type'              => 'select',
							'choices'           => array(),
							'instructions'      => 'Manage lists in Event Plus → Settings.',
							'wrapper'           => array( 'width' => 50 ),
							'conditional_logic' => array(
								array( array( 'field' => $t, 'operator' => '==', 'value' => 'select' ), array( 'field' => 'field_epc_ff_source', 'operator' => '==', 'value' => 'preset' ) ),
								array( array( 'field' => $t, 'operator' => '==', 'value' => 'radio' ), array( 'field' => 'field_epc_ff_source', 'operator' => '==', 'value' => 'preset' ) ),
								array( array( 'field' => $t, 'operator' => '==', 'value' => 'checkboxes' ), array( 'field' => 'field_epc_ff_source', 'operator' => '==', 'value' => 'preset' ) ),
							),
						),
						array( 'key' => 'field_epc_ff_other', 'label' => 'Add "Others – please specify"', 'name' => 'allow_other', 'type' => 'true_false', 'ui' => 1, 'wrapper' => array( 'width' => 25 ), 'conditional_logic' => epc_when( $t, array( 'select', 'radio', 'checkboxes', 'salutation' ) ) ),
						array( 'key' => 'field_epc_ff_unique', 'label' => 'Must be unique per event', 'name' => 'unique', 'type' => 'true_false', 'ui' => 1, 'instructions' => 'Blocks duplicate registrations (e.g. same email or NRIC).', 'wrapper' => array( 'width' => 30 ), 'conditional_logic' => epc_when( $t, array( 'email', 'phone', 'nric', 'text', 'number' ) ) ),

						array( 'key' => 'field_epc_ff_content', 'label' => 'Content', 'name' => 'content', 'type' => 'wysiwyg', 'toolbar' => 'basic', 'media_upload' => 0, 'tabs' => 'all', 'instructions' => 'For consent fields this is the agreement text (links allowed).', 'conditional_logic' => epc_when( $t, array( 'html', 'consent', 'heading' ) ) ),

						// Conditional display.
						array( 'key' => 'field_epc_ff_cond', 'label' => 'Show only when…', 'name' => 'conditional', 'type' => 'true_false', 'ui' => 1, 'wrapper' => array( 'width' => 16 ) ),
						array( 'key' => 'field_epc_ff_cond_field', 'label' => 'Field key', 'name' => 'cond_field', 'type' => 'text', 'placeholder' => 'country', 'wrapper' => array( 'width' => 28 ), 'conditional_logic' => epc_when( 'field_epc_ff_cond', 1 ) ),
						array(
							'key'               => 'field_epc_ff_cond_op',
							'label'             => 'Operator',
							'name'              => 'cond_operator',
							'type'              => 'select',
							'choices'           => array( 'is' => 'is', 'is_not' => 'is not', 'contains' => 'contains', 'not_empty' => 'has any value', 'empty' => 'is empty' ),
							'wrapper'           => array( 'width' => 20 ),
							'conditional_logic' => epc_when( 'field_epc_ff_cond', 1 ),
						),
						array( 'key' => 'field_epc_ff_cond_value', 'label' => 'Value', 'name' => 'cond_value', 'type' => 'text', 'placeholder' => 'Malaysia', 'wrapper' => array( 'width' => 36 ), 'conditional_logic' => epc_when( 'field_epc_ff_cond', 1 ) ),
					),
				),
				array( 'key' => 'field_epc_ev_submit', 'label' => 'Submit button label', 'name' => 'submit_label', 'type' => 'text', 'default_value' => 'Submit', 'wrapper' => array( 'width' => 50 ) ),

				/* ---------------- Capacity ---------------- */
				array( 'key' => 'field_epc_ev_tab_cap', 'label' => 'Capacity & Status', 'type' => 'tab' ),
				array(
					'key'           => 'field_epc_ev_reg_status',
					'label'         => 'Registration status',
					'name'          => 'reg_status',
					'type'          => 'button_group',
					'choices'       => array(
						'auto'   => 'Automatic',
						'open'   => 'Force open',
						'full'   => 'Application Full',
						'closed' => 'Closed',
					),
					'default_value' => 'auto',
					'instructions'  => '<strong>Automatic</strong> opens/closes by the dates below and shows "Application Full" once maximum capacity is reached. The other options override it manually.',
				),
				array( 'key' => 'field_epc_ev_cap_min', 'label' => 'Minimum capacity', 'name' => 'capacity_min', 'type' => 'number', 'min' => 0, 'instructions' => 'Target needed for the event to run (shown in admin).', 'wrapper' => array( 'width' => 25 ) ),
				array( 'key' => 'field_epc_ev_cap_max', 'label' => 'Maximum capacity', 'name' => 'capacity_max', 'type' => 'number', 'min' => 0, 'instructions' => '0 or empty = unlimited.', 'wrapper' => array( 'width' => 25 ) ),
				array( 'key' => 'field_epc_ev_open_at', 'label' => 'Registration opens', 'name' => 'reg_open_at', 'type' => 'date_time_picker', 'return_format' => 'Y-m-d H:i:s', 'display_format' => 'j M Y g:i a', 'wrapper' => array( 'width' => 25 ) ),
				array( 'key' => 'field_epc_ev_close_at', 'label' => 'Registration closes', 'name' => 'reg_close_at', 'type' => 'date_time_picker', 'return_format' => 'Y-m-d H:i:s', 'display_format' => 'j M Y g:i a', 'wrapper' => array( 'width' => 25 ) ),
				array( 'key' => 'field_epc_ev_waitlist', 'label' => 'Waiting list when full', 'name' => 'enable_waitlist', 'type' => 'true_false', 'ui' => 1, 'instructions' => 'Keep accepting registrations as "Waitlist" after capacity is reached (Automatic mode).', 'wrapper' => array( 'width' => 50 ) ),
				array(
					'key'           => 'field_epc_ev_default_status',
					'label'         => 'Status for new applicants',
					'name'          => 'default_status',
					'type'          => 'select',
					'choices'       => array( 'registered' => 'Registered', 'confirmed' => 'Confirmed' ),
					'default_value' => 'registered',
					'wrapper'       => array( 'width' => 50 ),
				),
				array( 'key' => 'field_epc_ev_full_title', 'label' => '"Application Full" heading', 'name' => 'full_title', 'type' => 'text', 'placeholder' => 'Thank you for your interest in {event_title}', 'wrapper' => array( 'width' => 50 ) ),
				array( 'key' => 'field_epc_ev_closed_title', 'label' => '"Closed" heading', 'name' => 'closed_title', 'type' => 'text', 'placeholder' => 'Registration Closed', 'wrapper' => array( 'width' => 50 ) ),
				array( 'key' => 'field_epc_ev_full_msg', 'label' => '"Application Full" message', 'name' => 'full_message', 'type' => 'wysiwyg', 'toolbar' => 'basic', 'media_upload' => 0, 'wrapper' => array( 'width' => 50 ) ),
				array( 'key' => 'field_epc_ev_closed_msg', 'label' => '"Closed" message', 'name' => 'closed_message', 'type' => 'wysiwyg', 'toolbar' => 'basic', 'media_upload' => 0, 'wrapper' => array( 'width' => 50 ) ),
				array( 'key' => 'field_epc_ev_wl_msg', 'label' => 'Waitlist notice (above form)', 'name' => 'waitlist_message', 'type' => 'textarea', 'rows' => 2, 'conditional_logic' => epc_when( 'field_epc_ev_waitlist', 1 ), 'wrapper' => array( 'width' => 50 ) ),
				array( 'key' => 'field_epc_ev_wl_conf', 'label' => 'Waitlist confirmation', 'name' => 'waitlist_confirmation', 'type' => 'wysiwyg', 'toolbar' => 'basic', 'media_upload' => 0, 'conditional_logic' => epc_when( 'field_epc_ev_waitlist', 1 ), 'wrapper' => array( 'width' => 50 ) ),

				/* ---------------- After submit ---------------- */
				array( 'key' => 'field_epc_ev_tab_after', 'label' => 'After Submit', 'type' => 'tab' ),
				array(
					'key'           => 'field_epc_ev_conf_type',
					'label'         => 'On success',
					'name'          => 'confirmation_type',
					'type'          => 'button_group',
					'choices'       => array( 'message' => 'Show message', 'redirect' => 'Redirect to URL' ),
					'default_value' => 'message',
				),
				array( 'key' => 'field_epc_ev_conf_msg', 'label' => 'Thank-you message', 'name' => 'confirmation_message', 'type' => 'wysiwyg', 'toolbar' => 'basic', 'instructions' => 'Placeholders: {event_title}, {event_date}, {event_time}, {event_venue} and any field key, e.g. {fullname}.', 'conditional_logic' => epc_when( 'field_epc_ev_conf_type', 'message' ) ),
				array( 'key' => 'field_epc_ev_redirect', 'label' => 'Redirect URL', 'name' => 'redirect_url', 'type' => 'url', 'conditional_logic' => epc_when( 'field_epc_ev_conf_type', 'redirect' ) ),
				array( 'key' => 'field_epc_ev_notify_admin', 'label' => 'Email admin on each registration', 'name' => 'notify_admin', 'type' => 'true_false', 'ui' => 1, 'default_value' => 1, 'wrapper' => array( 'width' => 30 ) ),
				array( 'key' => 'field_epc_ev_admin_emails', 'label' => 'Recipients', 'name' => 'admin_emails', 'type' => 'textarea', 'rows' => 2, 'instructions' => 'One per line. Empty = Settings default.', 'wrapper' => array( 'width' => 70 ), 'conditional_logic' => epc_when( 'field_epc_ev_notify_admin', 1 ) ),
				array( 'key' => 'field_epc_ev_notify_app', 'label' => 'Send confirmation email to applicant', 'name' => 'notify_applicant', 'type' => 'true_false', 'ui' => 1, 'instructions' => 'Sent to the first Email field.' ),
				array( 'key' => 'field_epc_ev_email_subject', 'label' => 'Email subject', 'name' => 'email_subject', 'type' => 'text', 'placeholder' => 'Registration received: {event_title}', 'conditional_logic' => epc_when( 'field_epc_ev_notify_app', 1 ) ),
				array( 'key' => 'field_epc_ev_email_body', 'label' => 'Email body', 'name' => 'email_body', 'type' => 'wysiwyg', 'toolbar' => 'full', 'instructions' => 'Same placeholders as above, plus {registration_details} for a table of all answers.', 'conditional_logic' => epc_when( 'field_epc_ev_notify_app', 1 ) ),
			),
		)
	);
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
