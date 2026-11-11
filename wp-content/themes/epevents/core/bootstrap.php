<?php
/**
 * EP Events core: the site's features (events, forms, microsites data, applicants, capacity,
 * event emails, site access).
 *
 * Loaded by the theme's functions.php. Everything here is business logic and data; nothing in
 * this folder decides how the public pages look (that is the rest of the theme).
 *
 * Requires: Advanced Custom Fields PRO. Optional helpers: the Tamago plugins for email sending,
 * reports, import / export and roles & permissions (core/tamago.php); the theme works without them.
 */

defined( 'ABSPATH' ) || exit;

define( 'EPC_VERSION', '3.0.0' );
define( 'EPC_FILE', __FILE__ );
define( 'EPC_DIR', trailingslashit( get_template_directory() ) . 'core/' );
define( 'EPC_URL', trailingslashit( get_template_directory_uri() ) . 'core/' );

// Each folder is one feature area; the file names say which screen or job they serve.
require_once EPC_DIR . 'helpers.php';
require_once EPC_DIR . 'tamago.php'; // optional Tamago helper plugins (email, reports, import / export, roles)
require_once EPC_DIR . 'registration/validator.php';
require_once EPC_DIR . 'registration/capacity.php';
require_once EPC_DIR . 'email/mailer.php';
require_once EPC_DIR . 'applicants/features.php';
require_once EPC_DIR . 'registration/form.php';
require_once EPC_DIR . 'applicants/applicants-admin.php';
require_once EPC_DIR . 'applicants/applicants-io.php';
require_once EPC_DIR . 'access/admin.php'; // also the Events / Microsites / Applicants list links
require_once EPC_DIR . 'access/site-access.php';

/**
 * Requirement: ACF Pro. Plugins load before the theme, so it can be checked right away.
 * ACF registers the post types, options pages and field groups, so without it events and
 * microsites do not exist.
 */
if ( class_exists( 'ACF' ) && function_exists( 'acf_add_local_field_group' ) ) {
	// Post types, options pages and field groups are ACF Local JSON files (core/acf-json/, acf-json/).
	// These files only set the JSON folders and fill in a few choices from code.
	require_once EPC_DIR . 'acf.php';
} else {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>The <strong>EP Events</strong> theme requires <strong>Advanced Custom Fields PRO</strong>. Please install and activate it.</p></div>';
		}
	);
}

EPC_Post_Types::init();
EPC_Capacity::init();
EPC_Form::init();
EPC_Features::init();
EPC_Applicants_Admin::init();
EPC_Applicants_IO::init();
EPC_Admin::init();
EPC_Site_Access::init();

/**
 * One-time setup (default option lists, Event Plus capabilities for Administrator and Developer,
 * permalinks). Runs when the theme is activated, and once after every core version change
 * (covers sites where the theme was already active, e.g. after uploading a new version).
 * Other roles get their permissions in Tamago Roles when it is active (or any role editor).
 */
function epc_setup(): void {
	foreach ( array( 'administrator', 'ep_developer' ) as $role_key ) {
		$role = get_role( $role_key );
		foreach ( $role ? epc_capabilities() : array() as $cap ) {
			$role->add_cap( $cap );
		}
	}
	if ( false === get_option( 'epc_seeded' ) ) {
		epc_seed_defaults();
		update_option( 'epc_seeded', 1 );
	}
	flush_rewrite_rules();
	update_option( 'epc_setup_version', EPC_VERSION );
}

add_action( 'after_switch_theme', 'epc_setup' );

add_action(
	'init',
	function () {
		if ( get_option( 'epc_setup_version' ) !== EPC_VERSION ) {
			epc_setup();
		}
	},
	20
);
