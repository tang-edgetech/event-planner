<?php
/**
 * Plugin Name:       EP Events Core
 * Description:       Events, registration forms, microsites, applicants, capacity control, visitor analytics and import/export for Event Plus. Requires ACF Pro.
 * Version:           1.5.0
 * Requires PHP:      8.0
 * Requires at least: 6.4
 * Author:            Event Plus
 * Text Domain:       epevents
 */

defined( 'ABSPATH' ) || exit;

define( 'EPC_VERSION', '1.5.0' );
define( 'EPC_FILE', __FILE__ );
define( 'EPC_DIR', plugin_dir_path( __FILE__ ) );
define( 'EPC_URL', plugin_dir_url( __FILE__ ) );
define( 'EPC_DB_VERSION', '1.0' );

require_once EPC_DIR . 'includes/helpers.php';
require_once EPC_DIR . 'includes/class-roles.php';
require_once EPC_DIR . 'includes/class-post-types.php';
require_once EPC_DIR . 'includes/class-validator.php';
require_once EPC_DIR . 'includes/class-capacity.php';
require_once EPC_DIR . 'includes/class-mailer.php';
require_once EPC_DIR . 'includes/class-smtp.php';
require_once EPC_DIR . 'includes/class-features.php';
require_once EPC_DIR . 'includes/class-form.php';
require_once EPC_DIR . 'includes/class-applicants-admin.php';
require_once EPC_DIR . 'includes/class-analytics.php';
require_once EPC_DIR . 'includes/class-analytics-admin.php';
require_once EPC_DIR . 'includes/class-xlsx.php';
require_once EPC_DIR . 'includes/class-import-export.php';
require_once EPC_DIR . 'includes/class-admin.php';
require_once EPC_DIR . 'includes/class-site-access.php';

/**
 * ACF Pro is required for all editing screens. Front-end degrades gracefully.
 */
add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'ACF' ) || ! function_exists( 'acf_add_local_field_group' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p><strong>EP Events Core</strong> requires <strong>Advanced Custom Fields PRO</strong>. Please install and activate it.</p></div>';
		} );
		return;
	}
	require_once EPC_DIR . 'includes/fields/settings.php';
	require_once EPC_DIR . 'includes/fields/event.php';
	require_once EPC_DIR . 'includes/fields/microsite.php';
} );

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

register_activation_hook( __FILE__, function () {
	EPC_Post_Types::register();
	EPC_Roles::install();
	EPC_Analytics::install_table();
	if ( false === get_option( 'epc_seeded' ) ) {
		epc_seed_defaults();
		update_option( 'epc_seeded', 1 );
	}
	flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'epc_analytics_cleanup' );
	flush_rewrite_rules();
} );

add_action( 'plugins_loaded', function () {
	if ( get_option( 'epc_db_version' ) !== EPC_DB_VERSION ) {
		EPC_Analytics::install_table();
	}
} );
