<?php
/**
 * Global settings (ACF options page): reusable option lists (Salutation etc.), email, tracking.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'acf/init', function () {
	acf_add_options_sub_page(
		array(
			'page_title'  => 'Event Plus Settings',
			'menu_title'  => 'Settings',
			'menu_slug'   => 'epc-settings',
			'parent_slug' => 'epc',
			'capability'  => 'epc_manage_settings',
			'position'    => 90,
		)
	);

	acf_add_local_field_group(
		array(
			'key'      => 'group_epc_settings',
			'title'    => 'Event Plus Settings',
			'location' => array( array( array( 'param' => 'options_page', 'operator' => '==', 'value' => 'epc-settings' ) ) ),
			'style'    => 'seamless',
			'fields'   => array(
				array( 'key' => 'field_epc_tab_lists', 'label' => 'Option lists', 'type' => 'tab' ),
				array(
					'key'          => 'field_epc_lists_msg',
					'type'         => 'message',
					'message'      => 'Reusable lists for dropdowns, radios and checkboxes in any event form. The list with slug <code>salutation</code> is used by every <strong>Salutation</strong> field.',
				),
				array(
					'key'          => 'field_epc_preset_lists',
					'label'        => 'Lists',
					'name'         => 'epc_preset_lists',
					'type'         => 'repeater',
					'layout'       => 'block',
					'collapsed'    => 'field_epc_pl_name',
					'button_label' => 'Add list',
					'sub_fields'   => array(
						array( 'key' => 'field_epc_pl_name', 'label' => 'Name', 'name' => 'name', 'type' => 'text', 'required' => 1, 'wrapper' => array( 'width' => 50 ) ),
						array( 'key' => 'field_epc_pl_slug', 'label' => 'Slug', 'name' => 'slug', 'type' => 'text', 'instructions' => 'Auto from name if empty. Do not change once in use.', 'wrapper' => array( 'width' => 50 ) ),
						array( 'key' => 'field_epc_pl_options', 'label' => 'Options', 'name' => 'options', 'type' => 'textarea', 'rows' => 6, 'instructions' => 'One option per line.' ),
					),
				),

				array( 'key' => 'field_epc_tab_mail', 'label' => 'Email', 'type' => 'tab' ),
				array( 'key' => 'field_epc_mail_from_name', 'label' => 'From name', 'name' => 'epc_mail_from_name', 'type' => 'text', 'wrapper' => array( 'width' => 50 ) ),
				array( 'key' => 'field_epc_mail_from_email', 'label' => 'From email', 'name' => 'epc_mail_from_email', 'type' => 'email', 'wrapper' => array( 'width' => 50 ) ),
				array( 'key' => 'field_epc_admin_emails', 'label' => 'Default notification recipients', 'name' => 'epc_admin_emails', 'type' => 'textarea', 'rows' => 3, 'instructions' => 'One email per line. Events can override this.' ),

				array( 'key' => 'field_epc_tab_track', 'label' => 'Tracking', 'type' => 'tab' ),
				array( 'key' => 'field_epc_track_enabled', 'label' => 'Enable visitor tracking', 'name' => 'epc_track_enabled', 'type' => 'true_false', 'ui' => 1, 'default_value' => 1, 'wrapper' => array( 'width' => 33 ) ),
				array( 'key' => 'field_epc_track_admins', 'label' => 'Track logged-in editors', 'name' => 'epc_track_admins', 'type' => 'true_false', 'ui' => 1, 'default_value' => 0, 'wrapper' => array( 'width' => 33 ) ),
				array( 'key' => 'field_epc_track_dnt', 'label' => 'Respect "Do Not Track"', 'name' => 'epc_track_dnt', 'type' => 'true_false', 'ui' => 1, 'default_value' => 0, 'wrapper' => array( 'width' => 33 ) ),
				array(
					'key'           => 'field_epc_geo_provider',
					'label'         => 'Location (country / region / city) source',
					'name'          => 'epc_geo_provider',
					'type'          => 'select',
					'choices'       => array(
						'headers' => 'Cloudflare / proxy headers only (no external calls)',
						'ipapi'   => 'ipapi.co (free tier, ~1,000 lookups/day)',
						'ipinfo'  => 'ipinfo.io (token required)',
						'none'    => 'Disabled',
					),
					'default_value' => 'headers',
					'instructions'  => 'Results are cached per IP for 7 days. Raw IP addresses are never stored — only a salted hash.',
					'wrapper'       => array( 'width' => 50 ),
				),
				array( 'key' => 'field_epc_geo_token', 'label' => 'ipinfo.io token', 'name' => 'epc_geo_token', 'type' => 'text', 'wrapper' => array( 'width' => 50 ), 'conditional_logic' => array( array( array( 'field' => 'field_epc_geo_provider', 'operator' => '==', 'value' => 'ipinfo' ) ) ) ),
				array( 'key' => 'field_epc_track_retention', 'label' => 'Keep raw visit data for (days)', 'name' => 'epc_track_retention', 'type' => 'number', 'default_value' => 730, 'min' => 0, 'instructions' => '0 = forever.', 'wrapper' => array( 'width' => 50 ) ),
			),
		)
	);

	// Seed default lists once (Salutation, Yes/No, Meal, Malaysia States, Specialty).
	if ( ! get_option( 'epc_seeded' ) ) {
		epc_seed_defaults();
		update_option( 'epc_seeded', 1 );
	}
} );
