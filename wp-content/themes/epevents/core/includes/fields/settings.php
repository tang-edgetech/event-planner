<?php
/**
 * Event Plus → Settings: the options page and first-run defaults.
 * The fields on it are defined in ACF (ACF → Field Groups → "Event Plus Settings"), saved as
 * core/acf-json/group_epc_settings.json.
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

	// Seed default lists once (Salutation, Yes/No, Meal, Malaysia States, Specialty).
	if ( ! get_option( 'epc_seeded' ) ) {
		epc_seed_defaults();
		update_option( 'epc_seeded', 1 );
	}
} );
