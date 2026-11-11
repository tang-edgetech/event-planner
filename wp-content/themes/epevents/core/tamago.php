<?php
/**
 * Connects the theme to the four Tamago plugins, which provide email sending, visitor reports,
 * import / export and roles & permissions. The theme only tells them what Event Plus has:
 *
 * - its permissions (shown in Roles & Permissions, the Tamago Roles screen);
 * - that their screens belong under the Event Plus menu;
 * - registrations, recorded as the "registration" goal in Tamago Reports;
 * - the "Applicants" data source in Tamago Import & Export (see applicants/applicants-io.php).
 *
 * Every setting of the plugins is made on their own screens (see docs/event-plus-settings.md).
 *
 * Used by: core/bootstrap.php.
 */

defined( 'ABSPATH' ) || exit;

/** The Tamago plugins the site needs: main file => name. */
function epc_tamago_plugins(): array {
	return array(
		'tamago-smtp/tamago-smtp.php'     => 'Tamago SMTP',
		'tamago-reports/tamago-reports.php' => 'Tamago Reports',
		'tamago-io/tamago-io.php'         => 'Tamago Import & Export',
		'tamago-roles/tamago-roles.php'   => 'Tamago Roles',
	);
}

/**
 * Event Plus permissions. Each one is a switch in Tamago Roles that grants these capabilities.
 * Reports, import / export, email and the permissions screen itself are registered by the plugins.
 * Keys stay the same as before the switch, so saved per-user overrides keep working.
 */
function epc_permissions(): array {
	$p = array(
		'dashboard'          => array( 'General', 'Access Event Plus dashboard', 'See the Event Plus overview (required for any access).', array( 'read', 'epc_view_dashboard' ) ),
		'events_view'        => array( 'Events', 'View events', 'See the events list, registration counts and status.', array( 'edit_ep_events' ) ),
		'events_create'      => array( 'Events', 'Create & edit events', 'Create events, build forms, change capacity and messages.', array( 'create_ep_events', 'edit_others_ep_events', 'edit_published_ep_events', 'edit_private_ep_events', 'read_private_ep_events' ) ),
		'events_publish'     => array( 'Events', 'Publish events', 'Make an event live / take it offline.', array( 'publish_ep_events' ) ),
		'events_status'      => array( 'Events', 'Open / close registration', 'Switch an event to Application Full or Closed manually.', array( 'epc_change_reg_status' ) ),
		'events_delete'      => array( 'Events', 'Delete events', 'Move events to Trash.', array( 'delete_ep_events', 'delete_others_ep_events', 'delete_published_ep_events', 'delete_private_ep_events' ) ),
		'microsites_view'    => array( 'Microsites', 'View microsites', 'See the microsite list.', array( 'edit_ep_microsites' ) ),
		'microsites_create'  => array( 'Microsites', 'Create & edit microsites', 'Build microsite pages and sections.', array( 'create_ep_microsites', 'edit_others_ep_microsites', 'edit_published_ep_microsites', 'edit_private_ep_microsites', 'read_private_ep_microsites' ) ),
		'microsites_publish' => array( 'Microsites', 'Publish microsites', 'Make a microsite live.', array( 'publish_ep_microsites' ) ),
		'microsites_delete'  => array( 'Microsites', 'Delete microsites', 'Move microsites to Trash.', array( 'delete_ep_microsites', 'delete_others_ep_microsites', 'delete_published_ep_microsites', 'delete_private_ep_microsites' ) ),
		'applicants_view'    => array( 'Applicants', 'View applicants', 'See applicant lists and details (personal data).', array( 'edit_ep_applicants', 'epc_view_applicants' ) ),
		'applicants_edit'    => array( 'Applicants', 'Edit applicants & status', 'Change answers and status (confirmed, attended, cancelled…).', array( 'edit_others_ep_applicants', 'edit_published_ep_applicants', 'edit_private_ep_applicants', 'read_private_ep_applicants', 'publish_ep_applicants' ) ),
		'applicants_delete'  => array( 'Applicants', 'Delete applicants', 'Move applicants to Trash / delete permanently.', array( 'delete_ep_applicants', 'delete_others_ep_applicants', 'delete_published_ep_applicants', 'delete_private_ep_applicants' ) ),
		'applicants_export'  => array( 'Applicants', 'Export applicants', 'Download applicant data as Excel / CSV / JSON.', array( 'epc_export_applicants' ) ),
		'media'              => array( 'Content', 'Upload media', 'Upload images, videos and documents.', array( 'upload_files' ) ),
		'html'               => array( 'Content', 'Custom HTML & embed codes', 'Keep scripts and embeds in the Custom HTML section (without it only safe HTML and iframes are kept).', array( 'unfiltered_html' ) ),
		'settings'           => array( 'Settings', 'Manage settings', 'Event Plus → Settings: option lists, notification emails, site access, features.', array( 'epc_manage_settings' ) ),
		'users'              => array( 'Users', 'Manage users', 'Add / edit / remove accounts at their own level or below (never higher roles).', array( 'list_users', 'create_users', 'edit_users', 'promote_users', 'delete_users', 'remove_users' ) ),
	);
	$out = array();
	foreach ( $p as $key => $row ) {
		$out[ $key ] = array( 'group' => 'Event Plus: ' . $row[0], 'label' => $row[1], 'description' => $row[2], 'caps' => $row[3] );
	}
	return $out;
}

/** Every capability the Event Plus permissions use (granted to Administrator and Developer). */
function epc_capabilities(): array {
	$caps = array();
	foreach ( epc_permissions() as $p ) {
		$caps = array_merge( $caps, $p['caps'] );
	}
	return array_values( array_unique( $caps ) );
}

add_filter( 'tamago_permissions', fn( array $p ) => array_merge( $p, epc_permissions() ) );


/** Registrations count as the "registration" goal in Tamago Reports. */
add_filter(
	'tmg_reports_goals',
	function ( array $goals ): array {
		foreach ( $goals as $g ) {
			if ( 'registration' === ( $g['id'] ?? '' ) ) {
				return $goals;
			}
		}
		$goals[] = array( 'id' => 'registration', 'name' => 'Registration', 'type' => 'server', 'match' => 'registration', 'value' => 0 );
		return $goals;
	}
);

add_action(
	'epc_applicant_registered',
	function ( int $applicant_id, int $event_id ) {
		if ( ! function_exists( 'tmg_reports_track' ) ) {
			return;
		}
		$source = array_filter( (array) get_post_meta( $applicant_id, '_epc_source', true ) );
		$hit    = tmg_reports_track(
			'registration',
			array(
				'post_id'    => $event_id,
				'label'      => 'Registration #' . $applicant_id,
				'visitor_id' => (string) get_post_meta( $applicant_id, '_epc_visitor', true ),
				'source'     => $source,
			)
		);
		// Without a source from the form, keep the one Tamago Reports found for the visit, so the
		// applicant screen still shows where the registration came from.
		if ( $hit && ! $source ) {
			global $wpdb;
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT ref_domain, utm_source, utm_medium, utm_campaign FROM {$wpdb->prefix}tmg_reports_hits WHERE id = %d", $hit ), ARRAY_A );
			if ( $row && array_filter( $row ) ) {
				update_post_meta( $applicant_id, '_epc_source', array_filter( $row ) );
			}
		}
	},
	10,
	2
);

/**
 * Tamago Reports runs in "wait for consent" mode with consent given at once, which keeps a visitor
 * ID in the browser exactly as the old Event Plus tracker did (returning visitors, registrations
 * linked to their visit). Visitors from before the switch keep their old ID.
 */
add_action(
	'wp_head',
	function () {
		echo "<script>try{if(!localStorage.getItem('tmg_vid')&&localStorage.getItem('epc_vid')){localStorage.setItem('tmg_vid',localStorage.getItem('epc_vid'));}}catch(e){}window.tamagoReports=window.tamagoReports||{q:[]};if(window.tamagoReports.q){window.tamagoReports.q.push(['consent',true]);}</script>\n";
	},
	2
);

/** Name shown for a staff member in applicant history (Tamago Roles hides Developers' names). */
function epc_public_name( int $user_id ): string {
	if ( function_exists( 'tmg_roles_public_name' ) ) {
		return tmg_roles_public_name( $user_id );
	}
	$user = get_userdata( $user_id );
	return $user ? $user->display_name : 'User';
}

/** Missing Tamago plugins: tell whoever can install plugins. */
add_action(
	'admin_notices',
	function () {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		$missing = array();
		foreach ( epc_tamago_plugins() as $file => $name ) {
			if ( ! is_plugin_active( $file ) ) {
				$missing[] = $name;
			}
		}
		if ( $missing ) {
			echo '<div class="notice notice-warning"><p>The <strong>EP Events</strong> theme uses these plugins, which are not active: <strong>' . esc_html( implode( ', ', $missing ) ) . '</strong>. Install and activate them (Plugins screen).</p></div>';
		}
	}
);
