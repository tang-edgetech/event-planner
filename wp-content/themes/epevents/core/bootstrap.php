<?php
/**
 * EP Events core: the site's features (events, forms, microsites data, applicants, capacity,
 * emails, SMTP, analytics, import/export, roles & permissions, site access).
 *
 * Loaded by the theme's functions.php. Everything here is business logic and data; nothing in
 * this folder decides how the public pages look (that is the rest of the theme).
 *
 * Requires: Advanced Custom Fields PRO (the only plugin the site needs).
 */

defined( 'ABSPATH' ) || exit;

define( 'EPC_VERSION', '2.1.0' );
define( 'EPC_FILE', __FILE__ );
define( 'EPC_DIR', trailingslashit( get_template_directory() ) . 'core/' );
define( 'EPC_URL', trailingslashit( get_template_directory_uri() ) . 'core/' );
define( 'EPC_DB_VERSION', '1.0' );

// Each folder is one feature area; the file names say which screen or job they serve.
require_once EPC_DIR . 'helpers.php';
require_once EPC_DIR . 'access/roles.php';
require_once EPC_DIR . 'registration/validator.php';
require_once EPC_DIR . 'registration/capacity.php';
require_once EPC_DIR . 'email/mailer.php';
require_once EPC_DIR . 'email/smtp.php';
require_once EPC_DIR . 'applicants/features.php';
require_once EPC_DIR . 'registration/form.php';
require_once EPC_DIR . 'applicants/applicants-admin.php';
require_once EPC_DIR . 'reports/analytics.php';
require_once EPC_DIR . 'reports/reports-screen.php';
require_once EPC_DIR . 'import-export/import-export.php';
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

EPC_Roles::init();
EPC_Post_Types::init();
EPC_Capacity::init();
EPC_Form::init();
EPC_Features::init();
EPC_SMTP::init();
EPC_Applicants_Admin::init();
EPC_Analytics::init();
EPC_Analytics_Admin::init();
EPC_Import_Export::init();
EPC_Admin::init();
EPC_Site_Access::init();

/**
 * One-time setup (default option lists, analytics table, permalinks). Runs when the theme is activated, and once after every core version change
 * (covers sites where the theme was already active, e.g. after uploading a new version or
 * moving from the old EP Events Core plugin). Roles install themselves on `init` already.
 */
function epc_setup(): void {
	EPC_Analytics::install_table();
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
		} elseif ( get_option( 'epc_db_version' ) !== EPC_DB_VERSION ) {
			EPC_Analytics::install_table();
		}
	},
	20
);

/** Switching to another theme: stop the scheduled analytics clean-up. */
add_action(
	'switch_theme',
	function () {
		wp_clear_scheduled_hook( 'epc_analytics_cleanup' );
	}
);
