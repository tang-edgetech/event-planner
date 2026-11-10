<?php
/**
 * Tamago Roles self-test. Run on a LAB site (never production):
 *   wp eval-file wp-content/plugins/tamago-roles/tests/run.php
 * Expects the Event Plus-like setup (tests/setup-event-plus.php or the fixture imported) and the lab
 * mu-plugin that registers Event Plus's permissions. Creates temporary users, posts, terms and a menu,
 * makes a few HTTP requests to this site, then restores roles and settings and removes what it created.
 * Prints "PASS name" / "FAIL name" and a total.
 *
 * Used by: developers, CI.
 */

defined( 'ABSPATH' ) || exit;

require_once ABSPATH . 'wp-admin/includes/admin.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';

$GLOBALS['tmg_t'] = array( 'pass' => 0, 'fail' => 0 );

function tmg_t( string $name, $ok, string $info = '' ): void {
	if ( $ok ) {
		$GLOBALS['tmg_t']['pass']++;
		echo "PASS {$name}\n";
	} else {
		$GLOBALS['tmg_t']['fail']++;
		echo "FAIL {$name}" . ( '' !== $info ? " — {$info}" : '' ) . "\n";
	}
}

function tmg_as( int $user_id ): void {
	wp_set_current_user( $user_id );
	TMG_Roles_Capabilities::flush();
	TMG_Roles_Content_Access::flush();
}

function tmg_admin_context( bool $on ): void {
	if ( $on ) {
		set_current_screen( 'users' );
	} else {
		unset( $GLOBALS['current_screen'] );
	}
}

/** HTTP request to this site as a user (0 = visitor). */
function tmg_http( string $url, int $user_id = 0 ): array {
	$cookies = array();
	if ( $user_id ) {
		$exp       = time() + HOUR_IN_SECONDS;
		$cookies[] = new WP_Http_Cookie( array( 'name' => LOGGED_IN_COOKIE, 'value' => wp_generate_auth_cookie( $user_id, $exp, 'logged_in' ) ) );
		$cookies[] = new WP_Http_Cookie( array( 'name' => AUTH_COOKIE, 'value' => wp_generate_auth_cookie( $user_id, $exp, 'auth' ) ) );
		$cookies[] = new WP_Http_Cookie( array( 'name' => SECURE_AUTH_COOKIE, 'value' => wp_generate_auth_cookie( $user_id, $exp, 'secure_auth' ) ) );
	}
	$res = wp_remote_get( $url, array( 'redirection' => 0, 'cookies' => $cookies, 'timeout' => 30, 'sslverify' => false, 'reject_unsafe_urls' => false ) );
	if ( is_wp_error( $res ) ) {
		return array( 'code' => 0, 'body' => $res->get_error_message(), 'location' => '' );
	}
	return array( 'code' => (int) wp_remote_retrieve_response_code( $res ), 'body' => (string) wp_remote_retrieve_body( $res ), 'location' => (string) wp_remote_retrieve_header( $res, 'location' ) );
}

// ---------------------------------------------------------------------------------------------
// Backup and fixtures.
// ---------------------------------------------------------------------------------------------
global $wpdb;
$backup = array(
	'roles'    => get_option( $wpdb->prefix . 'user_roles' ),
	'settings' => get_option( TMG_Roles_Settings::OPTION ),
	'defaults' => get_option( TMG_Roles_Role_Manager::DEFAULTS_OPTION ),
	'default'  => get_option( 'default_role' ),
);
$restore = function () use ( $backup, $wpdb ) {
	update_option( $wpdb->prefix . 'user_roles', $backup['roles'] );
	update_option( TMG_Roles_Settings::OPTION, $backup['settings'] );
	update_option( TMG_Roles_Role_Manager::DEFAULTS_OPTION, $backup['defaults'] );
	update_option( 'default_role', $backup['default'] );
	wp_roles()->for_site();
	TMG_Roles_Capabilities::flush();
};

if ( ! get_role( 'ep_developer' ) || ! TMG_Roles_Permissions::get( 'events_view' ) ) {
	echo "FAIL setup — run tests/setup-event-plus.php (or import tests/fixtures/event-plus-like.json) with the lab mu-plugin first\n";
	return;
}

$created_users = array();
$mk            = function ( string $login, array $roles, string $display = '' ) use ( &$created_users ) {
	$existing = get_user_by( 'login', $login );
	if ( $existing ) {
		wp_delete_user( $existing->ID );
	}
	$id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password( 24 ), 'user_email' => $login . '@lab.test', 'display_name' => $display ? $display : $login, 'role' => $roles[0] ) );
	$u  = get_userdata( $id );
	foreach ( array_slice( $roles, 1 ) as $r ) {
		$u->add_role( $r );
	}
	$created_users[] = $id;
	return (int) $id;
};
$dev     = $mk( 'tmgtest_dev', array( 'ep_developer' ), 'Dev Real Name' );
$super   = $mk( 'tmgtest_super', array( 'ep_super_admin' ) );
$super2  = $mk( 'tmgtest_super2', array( 'ep_super_admin' ) );
$manager = $mk( 'tmgtest_manager', array( 'ep_manager' ) );
$viewer  = $mk( 'tmgtest_viewer', array( 'ep_viewer' ) );
$sub     = $mk( 'tmgtest_sub', array( 'subscriber' ) );
$multi   = $mk( 'tmgtest_multi', array( 'ep_viewer', 'ep_manager' ) );
$admin   = (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID', 'tmg_roles_all' => true ) )[0] ?? 0 );

$changed = array();
add_action( 'tmg_roles_changed', function ( $action ) use ( &$changed ) {
	$changed[] = $action;
} );

try {
	// -----------------------------------------------------------------------------------------
	// Ranks and who may touch whom.
	// -----------------------------------------------------------------------------------------
	tmg_t( 'rank: developer = administrator = top (4)', 4 === tmg_roles_rank( $dev ) && 4 === tmg_roles_rank( $admin ) && 4 === TMG_Roles_Ranks::top() );
	tmg_t( 'rank: multiple roles use the highest', 2 === tmg_roles_rank( $multi ) );
	tmg_as( $super );
	tmg_t( 'rank: super admin cannot edit a developer', ! current_user_can( 'edit_user', $dev ) );
	tmg_t( 'rank: super admin cannot delete a developer', ! current_user_can( 'delete_user', $dev ) );
	tmg_t( 'rank: super admin cannot promote a developer', ! current_user_can( 'promote_user', $dev ) );
	tmg_t( 'rank: super admin can edit a manager', current_user_can( 'edit_user', $manager ) );
	tmg_t( 'rank: peer management on lets super admin edit another super admin', current_user_can( 'edit_user', $super2 ) && tmg_roles_can_manage_user( $super2 ) );
	TMG_Roles_Settings::update( array( 'peer_management' => false ) );
	tmg_t( 'rank: peer management off blocks same rank', ! current_user_can( 'edit_user', $super2 ) && ! tmg_roles_can_manage_user( $super2 ) );
	$editable = array_keys( get_editable_roles() );
	tmg_t( 'rank: editable roles strictly below (peers off)', array( 'ep_manager', 'ep_viewer' ) === array_values( array_intersect( array( 'ep_developer', 'ep_super_admin', 'ep_manager', 'ep_viewer', 'administrator', 'editor' ), $editable ) ), implode( ',', $editable ) );
	TMG_Roles_Settings::update( array( 'peer_management' => true ) );
	$editable = array_keys( get_editable_roles() );
	tmg_t( 'rank: editable roles include own rank with peers, never higher or unranked', in_array( 'ep_super_admin', $editable, true ) && ! in_array( 'ep_developer', $editable, true ) && ! in_array( 'administrator', $editable, true ) && ! in_array( 'editor', $editable, true ) );
	tmg_t( 'rank: super admin may change roles below only', TMG_Roles_Ranks::can_edit_role( 'ep_manager' ) && ! TMG_Roles_Ranks::can_edit_role( 'ep_super_admin' ) && ! TMG_Roles_Ranks::can_edit_role( 'ep_developer' ) );
	tmg_as( $manager );
	tmg_t( 'rank: manager cannot edit a super admin', ! current_user_can( 'edit_user', $super ) );
	tmg_as( $dev );
	tmg_t( 'rank: developer (top) can edit administrators', current_user_can( 'edit_user', $admin ) );

	// Hidden higher users in wp-admin.
	tmg_admin_context( true );
	tmg_as( $super );
	$seen = wp_list_pluck( get_users( array( 'fields' => array( 'ID' ) ) ), 'ID' );
	$seen = array_map( 'intval', $seen );
	tmg_t( 'hide: super admin does not see developers/administrators', ! in_array( $dev, $seen, true ) && ! in_array( $admin, $seen, true ) && in_array( $manager, $seen, true ) );
	tmg_as( $manager );
	$seen = array_map( 'intval', wp_list_pluck( get_users( array( 'fields' => array( 'ID' ) ) ), 'ID' ) );
	tmg_t( 'hide: with min rank 4, manager still sees super admins', in_array( $super, $seen, true ) && ! in_array( $dev, $seen, true ) );
	TMG_Roles_Settings::update( array( 'hide_higher_min_rank' => 0 ) );
	$seen = array_map( 'intval', wp_list_pluck( get_users( array( 'fields' => array( 'ID' ) ) ), 'ID' ) );
	tmg_t( 'hide: with min rank 0, manager does not see super admins', ! in_array( $super, $seen, true ) );
	TMG_Roles_Settings::update( array( 'hide_higher_min_rank' => 4 ) );
	$views = apply_filters( 'views_users', array( 'all' => 'All <span class="count">(9)</span>', 'ep_developer' => 'x', 'ep_viewer' => 'y', 'editor' => 'z' ) );
	tmg_t( 'hide: role views drop hidden roles and counts', ! isset( $views['ep_developer'] ) && isset( $views['ep_viewer'] ) && false === strpos( $views['all'], 'count' ) && ! isset( $views['editor'] ) );
	tmg_as( $dev );
	$seen = array_map( 'intval', wp_list_pluck( get_users( array( 'fields' => array( 'ID' ) ) ), 'ID' ) );
	tmg_t( 'hide: developer sees everyone', in_array( $admin, $seen, true ) && in_array( $super, $seen, true ) );
	tmg_admin_context( false );

	// -----------------------------------------------------------------------------------------
	// Matrix grant / deny.
	// -----------------------------------------------------------------------------------------
	tmg_t( 'matrix: manager lacks events_delete', ! user_can( $manager, 'delete_others_ep_events' ) );
	TMG_Roles_Role_Manager::grant( 'ep_manager', 'events_delete' );
	tmg_as( 0 );
	tmg_t( 'matrix: grant gives every capability', TMG_Roles_Permissions::role_has( 'ep_manager', 'events_delete' ) && user_can( get_userdata( $manager ), 'delete_others_ep_events' ) );
	TMG_Roles_Role_Manager::deny( 'ep_manager', 'events_delete' );
	tmg_t( 'matrix: deny removes them', ! TMG_Roles_Permissions::role_has( 'ep_manager', 'events_delete' ) && ! user_can( get_userdata( $manager ), 'delete_ep_events' ) );
	TMG_Roles_Role_Manager::deny( 'ep_manager', 'media' );
	tmg_t( 'matrix: deny of a permission sharing its caps with another (media / wp_media)', ! TMG_Roles_Permissions::role_has( 'ep_manager', 'wp_media' ) && ! user_can( get_userdata( $manager ), 'upload_files' ) );
	TMG_Roles_Role_Manager::grant( 'ep_manager', 'media' );
	TMG_Roles_Role_Manager::deny( 'ep_manager', 'dashboard' );
	tmg_t( 'matrix: deny never removes read', user_can( get_userdata( $manager ), 'read' ) && ! user_can( get_userdata( $manager ), 'epc_view_dashboard' ) );
	TMG_Roles_Role_Manager::grant( 'ep_manager', 'dashboard' );
	TMG_Roles_Role_Manager::deny( 'administrator', 'events_view' );
	tmg_t( 'matrix: administrator is never reduced', TMG_Roles_Permissions::role_has( 'administrator', 'events_view' ) );
	TMG_Roles_Role_Manager::apply_matrix( 'ep_viewer', array( 'events_publish' => true, 'reports_view' => false ) );
	tmg_t( 'matrix: apply_matrix switches several at once', TMG_Roles_Permissions::role_has( 'ep_viewer', 'events_publish' ) && ! TMG_Roles_Permissions::role_has( 'ep_viewer', 'reports_view' ) && TMG_Roles_Permissions::role_has( 'ep_viewer', 'events_view' ) );
	TMG_Roles_Role_Manager::apply_matrix( 'ep_viewer', array( 'events_publish' => false, 'reports_view' => true ) );

	// Permission sources: automatic groups for post types / taxonomies with their own caps.
	register_post_type( 'tmg_book', array( 'label' => 'Books', 'public' => true, 'show_ui' => true, 'capability_type' => array( 'tmg_book', 'tmg_books' ), 'map_meta_cap' => true ) );
	register_taxonomy( 'tmg_genre', 'tmg_book', array( 'label' => 'Genres', 'show_ui' => true, 'capabilities' => array( 'manage_terms' => 'manage_tmg_genres', 'edit_terms' => 'edit_tmg_genres', 'delete_terms' => 'delete_tmg_genres', 'assign_terms' => 'assign_tmg_genres' ) ) );
	TMG_Roles_Permissions::flush();
	$all = TMG_Roles_Permissions::all();
	tmg_t( 'sources: automatic post type group', isset( $all['pt_tmg_book_view'], $all['pt_tmg_book_publish'], $all['pt_tmg_book_delete'] ) && in_array( 'publish_tmg_books', $all['pt_tmg_book_publish']['caps'], true ) );
	tmg_t( 'sources: automatic taxonomy group', isset( $all['tax_tmg_genre_manage'] ) && array( 'manage_tmg_genres' ) === $all['tax_tmg_genre_manage']['caps'] );
	tmg_t( 'sources: registered post types covered by tamago_permissions get no duplicate group', ! isset( $all['pt_ep_event_view'] ) );
	tmg_t( 'sources: built-in WordPress groups and own permissions', isset( $all['wp_plugins'], $all['wp_settings'], $all['tmg_roles_manage'], $all['tmg_roles_manage_users'] ) );
	tmg_t( 'sources: known capabilities include custom post type caps', in_array( 'edit_tmg_books', TMG_Roles_Permissions::known_caps(), true ) );
	unregister_taxonomy( 'tmg_genre' );
	unregister_post_type( 'tmg_book' );
	TMG_Roles_Permissions::flush();

	// -----------------------------------------------------------------------------------------
	// Per-user overrides.
	// -----------------------------------------------------------------------------------------
	TMG_Roles_Capabilities::set_override( $viewer, 'events_delete', 'grant' );
	tmg_t( 'override: grant adds the permission', user_can( get_userdata( $viewer ), 'delete_others_ep_events' ) );
	tmg_t( 'override: nothing written into the user\'s own caps', ! array_key_exists( 'delete_others_ep_events', (array) get_userdata( $viewer )->caps ) );
	TMG_Roles_Capabilities::set_override( $viewer, 'reports_view', 'deny' );
	tmg_t( 'override: deny removes a role permission', ! user_can( get_userdata( $viewer ), 'epc_view_reports' ) && user_can( get_userdata( $viewer ), 'edit_ep_events' ) );
	tmg_t( 'override: effective result helper', TMG_Roles_Permissions::user_has( $viewer, 'events_delete' ) && ! TMG_Roles_Permissions::user_has( $viewer, 'reports_view' ) && TMG_Roles_Permissions::roles_grant( get_userdata( $viewer ), 'reports_view' ) );
	TMG_Roles_Capabilities::set_overrides( $viewer, array() );
	tmg_t( 'override: clearing restores the role', user_can( get_userdata( $viewer ), 'epc_view_reports' ) && ! user_can( get_userdata( $viewer ), 'delete_ep_events' ) );
	tmg_as( $super );
	tmg_t( 'override: super admin may override a manager, not a developer or self', TMG_Roles_User_Profile::can_override( $manager ) && ! TMG_Roles_User_Profile::can_override( $dev ) && ! TMG_Roles_User_Profile::can_override( $super ) );
	tmg_as( $manager );
	tmg_t( 'override: manager without the permission cannot override', ! TMG_Roles_User_Profile::can_override( $viewer ) );
	tmg_as( 0 );

	// -----------------------------------------------------------------------------------------
	// Protected capabilities.
	// -----------------------------------------------------------------------------------------
	$mrole = get_role( 'ep_manager' );
	$mrole->add_cap( 'manage_options' );
	TMG_Roles_Capabilities::flush();
	tmg_t( 'protected: role-held technical cap is stripped below rank 4', ! user_can( get_userdata( $manager ), 'manage_options' ) );
	TMG_Roles_Capabilities::set_override( $manager, 'wp_settings', 'grant' );
	tmg_t( 'protected: an override grant cannot bring it back', ! user_can( get_userdata( $manager ), 'manage_options' ) );
	TMG_Roles_Capabilities::set_overrides( $manager, array() );
	get_userdata( $super )->add_cap( 'activate_plugins' );
	TMG_Roles_Capabilities::flush();
	tmg_t( 'protected: user-level cap is stripped too', ! user_can( get_userdata( $super ), 'activate_plugins' ) );
	get_userdata( $super )->remove_cap( 'activate_plugins' );
	tmg_t( 'protected: developers keep them', user_can( get_userdata( $dev ), 'manage_options' ) && user_can( get_userdata( $dev ), 'activate_plugins' ) );
	$filter = fn( $caps ) => array_merge( $caps, array( 'epc_export_reports' ) );
	add_filter( 'tmg_roles_protected_caps', $filter );
	TMG_Roles_Capabilities::flush();
	tmg_t( 'protected: tmg_roles_protected_caps filter adds caps', ! user_can( get_userdata( $manager ), 'epc_export_reports' ) );
	remove_filter( 'tmg_roles_protected_caps', $filter );
	TMG_Roles_Capabilities::flush();
	$mrole->remove_cap( 'manage_options' );
	tmg_t( 'protected: preset equals Event Plus developer_only_caps (42 caps)', 42 === count( TMG_Roles_Settings::technical_preset() ) && in_array( 'manage_options', TMG_Roles_Settings::technical_preset(), true ) );

	// Lock-out guard.
	$arole = get_role( 'administrator' );
	$arole->remove_cap( 'tmg_roles_manage' );
	TMG_Roles_Capabilities::flush();
	tmg_t( 'safety: administrators always keep tmg_roles_manage', user_can( get_userdata( $admin ), 'tmg_roles_manage' ) );
	$arole->add_cap( 'tmg_roles_manage' );

	// -----------------------------------------------------------------------------------------
	// Admin menus, blocking, admin bar, redirects.
	// -----------------------------------------------------------------------------------------
	$hidden = TMG_Roles_Admin_Experience::hidden_menu( $manager );
	tmg_t( 'menu: manager has Dashboard hidden', in_array( 'index.php', $hidden, true ) );
	tmg_t( 'menu: administrator is never restricted', array() === TMG_Roles_Admin_Experience::hidden_menu( $admin ) );
	tmg_t( 'menu: direct URL index.php blocked for manager', TMG_Roles_Admin_Experience::is_blocked( 'index.php', array(), $manager ) );
	tmg_t( 'menu: Event Plus page allowed for manager', ! TMG_Roles_Admin_Experience::is_blocked( 'admin.php', array( 'page' => 'epc' ), $manager ) );
	tmg_t( 'menu: slug matching (posts vs pages vs post type)', TMG_Roles_Admin_Experience::slug_matches( 'edit.php', 'edit.php', array( 'post_status' => 'draft' ) ) && ! TMG_Roles_Admin_Experience::slug_matches( 'edit.php', 'edit.php', array( 'post_type' => 'page' ) ) && TMG_Roles_Admin_Experience::slug_matches( 'edit.php?post_type=page', 'post-new.php', array( 'post_type' => 'page' ) ) );
	$s                             = TMG_Roles_Settings::all();
	$s['menu_rules']['ep_viewer'][] = 'edit.php?post_type=ep_event';
	$s['menu_rules']['ep_viewer'][] = 'epc|epc-reports';
	TMG_Roles_Settings::update( array( 'menu_rules' => $s['menu_rules'] ) );
	$event = wp_insert_post( array( 'post_type' => 'ep_event', 'post_title' => 'tmgtest event', 'post_status' => 'publish' ) );
	tmg_t( 'menu: hidden post type blocks post.php of its items', TMG_Roles_Admin_Experience::is_blocked( 'post.php', array( 'post' => $event, 'action' => 'edit' ), $viewer ) );
	tmg_t( 'menu: hidden submenu blocks its page', TMG_Roles_Admin_Experience::is_blocked( 'admin.php', array( 'page' => 'epc-reports' ), $viewer ) && ! TMG_Roles_Admin_Experience::is_blocked( 'admin.php', array( 'page' => 'epc-reports' ), $manager ) );
	tmg_t( 'menu: multi-role user only loses items hidden for all roles', ! in_array( 'epc|epc-reports', TMG_Roles_Admin_Experience::hidden_menu( $multi ), true ) && in_array( 'index.php', TMG_Roles_Admin_Experience::hidden_menu( $multi ), true ) );
	$mf = function ( $h, $u ) use ( $manager ) {
		return ( $u && $u->ID === $manager ) ? array_merge( $h, array( 'profile.php' ) ) : $h;
	};
	add_filter( 'tmg_roles_menu_hidden', $mf, 10, 2 );
	tmg_t( 'menu: tmg_roles_menu_hidden filter', TMG_Roles_Admin_Experience::is_blocked( 'profile.php', array(), $manager ) );
	remove_filter( 'tmg_roles_menu_hidden', $mf, 10 );
	tmg_t( 'redirect: login goes to the role page', admin_url( 'admin.php?page=epc' ) === apply_filters( 'login_redirect', admin_url(), '', get_userdata( $manager ) ) );
	tmg_t( 'redirect: a requested page is respected', admin_url( 'profile.php' ) === apply_filters( 'login_redirect', admin_url( 'profile.php' ), admin_url( 'profile.php' ), get_userdata( $manager ) ) );
	tmg_t( 'redirect: administrators unaffected', admin_url() === apply_filters( 'login_redirect', admin_url(), '', get_userdata( $admin ) ) );
	$s                                 = TMG_Roles_Settings::all();
	$s['logout_redirect']['ep_viewer'] = '/goodbye/';
	TMG_Roles_Settings::update( array( 'logout_redirect' => $s['logout_redirect'], 'no_admin' => array( 'subscriber' ), 'toolbar_front_hidden' => array( 'subscriber' ) ) );
	tmg_t( 'redirect: logout per role', home_url( '/goodbye/' ) === apply_filters( 'logout_redirect', 'x', '', get_userdata( $viewer ) ) );
	tmg_as( $sub );
	tmg_t( 'toolbar: hidden on the front end per role', false === apply_filters( 'show_admin_bar', true ) );
	tmg_as( 0 );

	// HTTP: real wp-admin requests.
	$r = tmg_http( admin_url( 'index.php' ), $manager );
	tmg_t( 'http: manager opening the dashboard is redirected to the Event Plus page', 302 === $r['code'] && false !== strpos( $r['location'], 'page=epc' ) && false !== strpos( $r['location'], 'tmg_roles_blocked=1' ), $r['code'] . ' ' . $r['location'] );
	$r = tmg_http( admin_url( 'admin.php?page=epc' ), $manager );
	tmg_t( 'http: manager sees Event Plus without Dashboard / Posts / Settings in the menu', 200 === $r['code'] && false !== strpos( $r['body'], 'epc-dashboard-ok' ) && false === strpos( $r['body'], 'id="menu-dashboard"' ) && false === strpos( $r['body'], 'id="menu-posts"' ) && false === strpos( $r['body'], 'id="menu-settings"' ), (string) $r['code'] );
	tmg_t( 'http: admin bar items removed for manager', false === strpos( $r['body'], 'wp-admin-bar-new-post' ) && false === strpos( $r['body'], 'wp-admin-bar-comments' ) );
	$r = tmg_http( admin_url( 'admin.php?page=epc-reports' ), $viewer );
	tmg_t( 'http: viewer opening a hidden submenu page is redirected', 302 === $r['code'] && false !== strpos( $r['location'], 'tmg_roles_blocked=1' ), $r['code'] . ' ' . $r['location'] );
	$r = tmg_http( admin_url( 'post.php?post=' . $event . '&action=edit' ), $viewer );
	tmg_t( 'http: viewer opening a hidden post type item is redirected', 302 === $r['code'], (string) $r['code'] );
	$r = tmg_http( admin_url( 'options-general.php' ), $super );
	tmg_t( 'http: super admin cannot open WordPress settings (protected cap)', in_array( $r['code'], array( 302, 403 ), true ), (string) $r['code'] );
	$r = tmg_http( admin_url( 'index.php' ), $admin );
	tmg_t( 'http: administrator opens the dashboard', 200 === $r['code'] && false !== strpos( $r['body'], 'id="menu-dashboard"' ) );
	$r = tmg_http( admin_url( 'users.php?page=tamago-roles' ), $super );
	tmg_t( 'http: super admin opens Roles & Permissions', 200 === $r['code'] && false !== strpos( $r['body'], 'nav-tab-wrapper' ), (string) $r['code'] );
	$r = tmg_http( admin_url( 'users.php' ), $super );
	tmg_t( 'http: users list hides higher users for super admin', 200 === $r['code'] && false === strpos( $r['body'], 'tmgtest_dev' ) && false !== strpos( $r['body'], 'tmgtest_manager' ) );
	$r = tmg_http( admin_url( 'profile.php' ), $sub );
	tmg_t( 'http: no wp-admin access sends the role home', 302 === $r['code'] && 0 === strpos( $r['location'], home_url() ) && false === strpos( $r['location'], 'wp-admin' ), $r['code'] . ' ' . $r['location'] );
	TMG_Roles_Settings::update( array( 'no_admin' => array(), 'toolbar_front_hidden' => array() ) );

	// -----------------------------------------------------------------------------------------
	// Content access.
	// -----------------------------------------------------------------------------------------
	$p_public  = wp_insert_post( array( 'post_title' => 'tmgtest public', 'post_content' => 'tmgtest body public', 'post_status' => 'publish', 'post_author' => $dev ) );
	$p_members = wp_insert_post( array( 'post_title' => 'tmgtest members', 'post_content' => 'tmgtest body secret-members', 'post_excerpt' => 'tmgtest teaser', 'post_status' => 'publish' ) );
	$p_roles   = wp_insert_post( array( 'post_title' => 'tmgtest managers', 'post_content' => 'tmgtest body secret-managers', 'post_status' => 'publish' ) );
	$p_login   = wp_insert_post( array( 'post_title' => 'tmgtest login', 'post_content' => 'tmgtest body secret-login', 'post_status' => 'publish' ) );
	$p_term    = wp_insert_post( array( 'post_title' => 'tmgtest term', 'post_content' => 'tmgtest body secret-term', 'post_status' => 'publish' ) );
	$term      = wp_insert_term( 'tmgtest-secret', 'category' );
	$term_id   = (int) $term['term_id'];
	wp_set_post_terms( $p_term, array( $term_id ), 'category' );
	TMG_Roles_Content_Admin::store( 'post', $p_members, TMG_Roles_Content_Access::sanitize_rule( array( 'mode' => 'logged_in', 'action' => 'message', 'message' => 'Members only here', 'show_excerpt' => 1 ) ) );
	TMG_Roles_Content_Admin::store( 'post', $p_roles, TMG_Roles_Content_Access::sanitize_rule( array( 'mode' => 'roles', 'roles' => array( 'ep_manager' ), 'action' => '404' ) ) );
	TMG_Roles_Content_Admin::store( 'post', $p_login, TMG_Roles_Content_Access::sanitize_rule( array( 'mode' => 'logged_in', 'action' => 'login' ) ) );
	TMG_Roles_Content_Admin::store( 'term', $term_id, TMG_Roles_Content_Access::sanitize_rule( array( 'mode' => 'roles', 'roles' => array( 'ep_viewer' ), 'action' => 'message' ) ) );
	TMG_Roles_Settings::update( array( 'content_defaults' => array( 'ep_event' => array( 'mode' => 'logged_in', 'action' => '404' ) ) ) );
	tmg_as( 0 );

	tmg_t( 'content: visitor may view public, not members/roles/term/post-type default', tmg_roles_user_can_view( $p_public ) && ! tmg_roles_user_can_view( $p_members ) && ! tmg_roles_user_can_view( $p_roles ) && ! tmg_roles_user_can_view( $p_term ) && ! tmg_roles_user_can_view( $event ) );
	tmg_t( 'content: viewer may view members + term, not managers', tmg_roles_user_can_view( $p_members, $viewer ) && tmg_roles_user_can_view( $p_term, $viewer ) && ! tmg_roles_user_can_view( $p_roles, $viewer ) && tmg_roles_user_can_view( $event, $viewer ) );
	tmg_t( 'content: manager may view managers, not viewer-only term', tmg_roles_user_can_view( $p_roles, $manager ) && ! tmg_roles_user_can_view( $p_term, $manager ) );
	tmg_t( 'content: administrators always may', tmg_roles_user_can_view( $p_roles, $admin ) && tmg_roles_user_can_view( $p_term, $admin ) );
	$cvf = fn( $can, $id ) => $id === $p_public ? false : $can;
	add_filter( 'tmg_roles_can_view_post', $cvf, 10, 2 );
	tmg_t( 'content: tmg_roles_can_view_post filter', ! tmg_roles_user_can_view( $p_public ) );
	remove_filter( 'tmg_roles_can_view_post', $cvf, 10 );

	$q   = new WP_Query( array( 's' => 'tmgtest body', 'post_type' => 'any', 'posts_per_page' => 50, 'fields' => 'ids' ) );
	$ids = array_map( 'intval', $q->posts );
	tmg_t( 'content: search hides restricted posts from visitors', in_array( $p_public, $ids, true ) && ! array_intersect( array( $p_members, $p_roles, $p_term, $p_login ), $ids ), implode( ',', $ids ) );
	tmg_as( $viewer );
	$q   = new WP_Query( array( 's' => 'tmgtest body', 'post_type' => 'any', 'posts_per_page' => 50, 'fields' => 'ids' ) );
	$ids = array_map( 'intval', $q->posts );
	tmg_t( 'content: search shows what the viewer may see', in_array( $p_members, $ids, true ) && in_array( $p_term, $ids, true ) && ! in_array( $p_roles, $ids, true ) );
	tmg_as( 0 );
	$q   = new WP_Query( array( 'cat' => $term_id, 'fields' => 'ids' ) );
	tmg_t( 'content: term archive hides the restricted post', ! in_array( $p_term, array_map( 'intval', $q->posts ), true ) );

	$req = new WP_REST_Request( 'GET', '/wp/v2/posts' );
	$req->set_param( 'search', 'tmgtest body' );
	$req->set_param( 'per_page', 50 );
	$ids = array_map( 'intval', wp_list_pluck( (array) rest_do_request( $req )->get_data(), 'id' ) );
	tmg_t( 'content: REST list hides restricted posts', in_array( $p_public, $ids, true ) && ! in_array( $p_members, $ids, true ) && ! in_array( $p_roles, $ids, true ) );
	$res = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $p_roles ) );
	tmg_t( 'content: REST single restricted post is 404', 404 === $res->get_status(), (string) $res->get_status() );
	tmg_as( $manager );
	$res = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $p_roles ) );
	tmg_t( 'content: REST single allowed for the role', 200 === $res->get_status(), (string) $res->get_status() );
	tmg_as( 0 );

	$r = tmg_http( home_url( '/?feed=rss2&s=tmgtest' ) );
	tmg_t( 'content: feed hides restricted posts', 200 === $r['code'] && false !== strpos( $r['body'], 'tmgtest public' ) && false === strpos( $r['body'], 'tmgtest members' ) && false === strpos( $r['body'], 'secret-managers' ), (string) $r['code'] );
	$r = tmg_http( home_url( '/?s=tmgtest' ) );
	tmg_t( 'content: front-end search page hides restricted posts', false !== strpos( $r['body'], 'tmgtest public' ) && false === strpos( $r['body'], 'secret-members' ) && false === strpos( $r['body'], 'tmgtest managers' ) );
	$r = tmg_http( get_permalink( $p_roles ) );
	tmg_t( 'content: single (404 action) returns 404', 404 === $r['code'] && false === strpos( $r['body'], 'secret-managers' ), (string) $r['code'] );
	$r = tmg_http( get_permalink( $p_members ) );
	tmg_t( 'content: single (message action) shows message + excerpt, not content', 200 === $r['code'] && false !== strpos( $r['body'], 'Members only here' ) && false !== strpos( $r['body'], 'tmgtest teaser' ) && false === strpos( $r['body'], 'secret-members' ), (string) $r['code'] );
	$r = tmg_http( get_permalink( $p_members ), $viewer );
	tmg_t( 'content: single shows content to a logged-in user', false !== strpos( $r['body'], 'secret-members' ) );
	$r = tmg_http( get_permalink( $p_login ) );
	tmg_t( 'content: single (login action) redirects to login', 302 === $r['code'] && false !== strpos( $r['location'], 'wp-login.php' ), $r['code'] . ' ' . $r['location'] );
	$r = tmg_http( get_permalink( $event ) );
	tmg_t( 'content: post type default applies (ep_event 404)', 404 === $r['code'], (string) $r['code'] );
	$r = tmg_http( home_url( '/?sitemap=posts&sitemap-subtype=post&paged=1' ) );
	tmg_t( 'content: sitemap hides restricted posts', 200 === $r['code'] && false !== strpos( $r['body'], (string) get_permalink( $p_public ) ) && false === strpos( $r['body'], (string) get_permalink( $p_members ) ), (string) $r['code'] );

	// -----------------------------------------------------------------------------------------
	// Menu item visibility.
	// -----------------------------------------------------------------------------------------
	$menu_id = wp_create_nav_menu( 'tmgtest menu ' . wp_rand() );
	$add     = fn( $title, $parent = 0 ) => wp_update_nav_menu_item( $menu_id, 0, array( 'menu-item-title' => $title, 'menu-item-url' => home_url( '/' . sanitize_title( $title ) ), 'menu-item-status' => 'publish', 'menu-item-parent-id' => $parent ) );
	$m_all   = $add( 'All' );
	$m_in    = $add( 'Members' );
	$m_out   = $add( 'Guests' );
	$m_role  = $add( 'Managers' );
	$m_child = $add( 'Managers child', $m_role );
	TMG_Roles_Nav_Menus::set_rule( $m_in, array( 'mode' => 'logged_in' ) );
	TMG_Roles_Nav_Menus::set_rule( $m_out, array( 'mode' => 'logged_out' ) );
	TMG_Roles_Nav_Menus::set_rule( $m_role, array( 'mode' => 'roles', 'roles' => array( 'ep_manager' ) ) );
	$titles = fn() => wp_list_pluck( wp_get_nav_menu_items( $menu_id ), 'title' );
	tmg_as( 0 );
	$t = $titles();
	tmg_t( 'nav menu: visitor sees All + Guests only', array( 'All', 'Guests' ) === array_values( $t ), implode( ',', $t ) );
	tmg_as( $manager );
	$t = $titles();
	tmg_t( 'nav menu: manager sees members + role items with children', array( 'All', 'Members', 'Managers', 'Managers child' ) === array_values( $t ), implode( ',', $t ) );
	tmg_as( $viewer );
	$t = $titles();
	tmg_t( 'nav menu: viewer loses the role item and its child', array( 'All', 'Members' ) === array_values( $t ), implode( ',', $t ) );
	tmg_as( 0 );
	wp_delete_nav_menu( $menu_id );

	// -----------------------------------------------------------------------------------------
	// Shortcode.
	// -----------------------------------------------------------------------------------------
	$sc = '[tamago_restrict roles="ep_manager,editor"]SECRET[/tamago_restrict]';
	tmg_t( 'shortcode: visitor gets nothing', '' === do_shortcode( $sc ) );
	tmg_as( $manager );
	tmg_t( 'shortcode: matching role sees content', 'SECRET' === do_shortcode( $sc ) );
	tmg_as( $viewer );
	tmg_t( 'shortcode: other role gets the message', false !== strpos( do_shortcode( '[tamago_restrict roles="ep_manager" message="No"]S[/tamago_restrict]' ), 'No' ) );
	tmg_t( 'shortcode: logged_in="yes" / "no"', 'S' === do_shortcode( '[tamago_restrict logged_in="yes"]S[/tamago_restrict]' ) && '' === do_shortcode( '[tamago_restrict logged_in="no"]S[/tamago_restrict]' ) );
	tmg_as( 0 );
	tmg_t( 'shortcode: logged_in="no" shows to visitors', 'S' === do_shortcode( '[tamago_restrict logged_in="no"]S[/tamago_restrict]' ) );

	// -----------------------------------------------------------------------------------------
	// Save handlers of the editing screens (meta box, term fields, menu item fields, profile).
	// -----------------------------------------------------------------------------------------
	tmg_as( $admin );
	$_POST = array(
		'tmg_roles_access_nonce' => wp_create_nonce( 'tmg_roles_access_' . $p_public ),
		'tmg_roles_access'       => array( 'mode' => 'roles', 'roles' => array( 'ep_viewer' ), 'action' => 'message' ),
	);
	wp_update_post( array( 'ID' => $p_public, 'post_title' => 'tmgtest public' ) );
	$rule = TMG_Roles_Content_Access::post_rule( $p_public );
	tmg_t( 'editor: "Who can view" box saves the rule', 'roles' === $rule['mode'] && array( 'ep_viewer' ) === $rule['roles'] );
	$_POST['tmg_roles_access'] = array( 'mode' => 'inherit', 'action' => 'inherit' );
	wp_update_post( array( 'ID' => $p_public, 'post_title' => 'tmgtest public' ) );
	tmg_t( 'editor: back to default removes the meta', '' === get_post_meta( $p_public, TMG_Roles_Content_Access::META, true ) );
	$_POST = array(
		'tmg_roles_term_nonce' => wp_create_nonce( 'tmg_roles_term_' . $term_id ),
		'tmg_roles_access'     => array( 'mode' => 'logged_in', 'action' => 'login' ),
	);
	TMG_Roles_Content_Admin::save_term( $term_id );
	tmg_t( 'editor: term fields save the rule', 'logged_in' === TMG_Roles_Content_Access::term_rule( $term_id )['mode'] );
	$nav    = wp_create_nav_menu( 'tmgtest save ' . wp_rand() );
	$nav_it = wp_update_nav_menu_item( $nav, 0, array( 'menu-item-title' => 'X', 'menu-item-url' => home_url( '/' ), 'menu-item-status' => 'publish' ) );
	$_POST  = array(
		'update-nav-menu-nonce' => wp_create_nonce( 'update-nav_menu' ),
		'tmg_roles_vis'         => array( $nav_it => array( 'mode' => 'roles', 'roles' => array( 'ep_manager' ) ) ),
	);
	do_action( 'wp_update_nav_menu_item', $nav, $nav_it, array() );
	tmg_t( 'editor: menu item fields save visibility', 'roles' === TMG_Roles_Nav_Menus::rule( $nav_it )['mode'] );
	wp_delete_nav_menu( $nav );
	$_POST = array(
		'user_id'                 => $viewer,
		'tmg_roles_profile_nonce' => wp_create_nonce( 'tmg_roles_profile_' . $viewer ),
		'tmg_roles_extra_sent'    => '1',
		'tmg_roles_extra'         => array( 'ep_manager' ),
		'tmg_roles_ov_sent'       => '1',
		'tmg_roles_ov'            => array( 'events_delete' => 'grant', 'reports_view' => 'deny' ),
	);
	do_action( 'profile_update', $viewer, get_userdata( $viewer ), array() );
	$_POST = array();
	tmg_t( 'profile: form saves extra roles and overrides', in_array( 'ep_manager', get_userdata( $viewer )->roles, true ) && array( 'events_delete' => 'grant', 'reports_view' => 'deny' ) === TMG_Roles_Capabilities::overrides( $viewer ) );
	get_userdata( $viewer )->remove_role( 'ep_manager' );
	TMG_Roles_Capabilities::set_overrides( $viewer, array() );
	tmg_as( 0 );

	// -----------------------------------------------------------------------------------------
	// Staff public name.
	// -----------------------------------------------------------------------------------------
	$site = get_bloginfo( 'name' );
	tmg_as( 0 );
	tmg_t( 'privacy: helper returns the public name to visitors', $site === tmg_roles_public_name( $dev ) && $site === tmg_roles_public_name() );
	tmg_t( 'privacy: author name filtered', $site === apply_filters( 'get_the_author_display_name', 'Dev Real Name', $dev, $dev ) );
	tmg_t( 'privacy: non-staff authors keep their name', 'tmgtest_manager' === tmg_roles_public_name( $manager ) );
	$o = get_oembed_response_data( get_post( $p_public ), 600 );
	tmg_t( 'privacy: oEmbed author is the public name', is_array( $o ) && $site === $o['author_name'] && home_url( '/' ) === $o['author_url'] );
	$ids = array_map( 'intval', wp_list_pluck( (array) rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/users' ) )->get_data(), 'id' ) );
	tmg_t( 'privacy: REST user list hides staff', ! in_array( $dev, $ids, true ) );
	$res = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/users/' . $dev ) );
	tmg_t( 'privacy: REST single staff user is 404', 404 === $res->get_status(), (string) $res->get_status() );
	TMG_Roles_Settings::update( array( 'public_name_rest_hide' => false ) );
	$res  = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/users/' . $dev ) );
	$data = $res->get_data();
	tmg_t( 'privacy: REST single shows the public name when not hidden', 200 === $res->get_status() && $site === ( $data['name'] ?? '' ) && '' === ( $data['slug'] ?? 'x' ), (string) $res->get_status() );
	TMG_Roles_Settings::update( array( 'public_name_rest_hide' => true ) );
	$r = tmg_http( home_url( '/?author=' . $dev ) );
	tmg_t( 'privacy: staff author archive is 404', 404 === $r['code'], (string) $r['code'] );
	$r = tmg_http( home_url( '/?author=' . $manager ) );
	tmg_t( 'privacy: other author archives still work', in_array( $r['code'], array( 200, 301, 302 ), true ), (string) $r['code'] );
	$r = tmg_http( home_url( '/?p=' . $p_public . '&tmg=1' ), 0 );
	tmg_t( 'privacy: real name not in the public page', false === strpos( $r['body'], 'Dev Real Name' ) );
	tmg_as( $dev );
	tmg_t( 'privacy: top rank sees real names', 'Dev Real Name' === tmg_roles_public_name( $dev ) );
	tmg_as( $super );
	tmg_t( 'privacy: lower rank sees the public name', $site === tmg_roles_public_name( $dev ) );
	tmg_as( 0 );

	// -----------------------------------------------------------------------------------------
	// Safe mode.
	// -----------------------------------------------------------------------------------------
	$mrole->add_cap( 'manage_options' );
	add_filter( 'tmg_roles_safe_mode', '__return_true' );
	TMG_Roles_Capabilities::flush();
	tmg_t( 'safe mode: protected caps no longer stripped', user_can( get_userdata( $manager ), 'manage_options' ) );
	tmg_t( 'safe mode: no menu hiding', array() === TMG_Roles_Admin_Experience::hidden_menu( $manager ) );
	tmg_t( 'safe mode: content visible', tmg_roles_user_can_view( $p_roles ) );
	tmg_as( $super );
	tmg_t( 'safe mode: no rank rules', current_user_can( 'edit_user', $dev ) || ! current_user_can( 'edit_users' ) );
	tmg_t( 'safe mode: shortcode shows content', 'SECRET' === do_shortcode( $sc ) );
	tmg_as( 0 );
	remove_filter( 'tmg_roles_safe_mode', '__return_true' );
	$mrole->remove_cap( 'manage_options' );
	TMG_Roles_Capabilities::flush();
	tmg_t( 'safe mode: off again restores rules', ! user_can( get_userdata( $manager ), 'manage_options' ) && ! tmg_roles_user_can_view( $p_roles ) );

	// -----------------------------------------------------------------------------------------
	// Role management.
	// -----------------------------------------------------------------------------------------
	$ok = TMG_Roles_Role_Manager::create( 'tmgtest_role', 'Test Role', 1, array( 'read' => true, 'edit_ep_events' => true ) );
	tmg_t( 'roles: create', true === $ok && get_role( 'tmgtest_role' ) && 1 === TMG_Roles_Ranks::role_rank( 'tmgtest_role' ) );
	tmg_t( 'roles: duplicate key refused', is_wp_error( TMG_Roles_Role_Manager::create( 'tmgtest_role', 'Again' ) ) );
	TMG_Roles_Role_Manager::clone_role( 'tmgtest_role', 'tmgtest_clone', 'Test Clone' );
	tmg_t( 'roles: clone copies caps and rank', get_role( 'tmgtest_clone' ) && ! empty( get_role( 'tmgtest_clone' )->capabilities['edit_ep_events'] ) && 1 === TMG_Roles_Ranks::role_rank( 'tmgtest_clone' ) );
	TMG_Roles_Role_Manager::rename( 'tmgtest_clone', 'Renamed Clone' );
	tmg_t( 'roles: rename', 'Renamed Clone' === wp_roles()->roles['tmgtest_clone']['name'] );
	get_role( 'tmgtest_role' )->add_cap( 'epc_view_reports' );
	TMG_Roles_Role_Manager::reset( 'tmgtest_role' );
	tmg_t( 'roles: reset to defaults', empty( get_role( 'tmgtest_role' )->capabilities['epc_view_reports'] ) );
	get_role( 'tmgtest_role' )->add_cap( 'epc_view_reports' );
	TMG_Roles_Role_Manager::save_as_defaults( 'tmgtest_role' );
	TMG_Roles_Role_Manager::reset( 'tmgtest_role' );
	tmg_t( 'roles: save current as defaults', ! empty( get_role( 'tmgtest_role' )->capabilities['epc_view_reports'] ) );
	$rt = $mk( 'tmgtest_roleuser', array( 'tmgtest_clone' ) );
	tmg_t( 'roles: delete without a target refused while it has users', is_wp_error( TMG_Roles_Role_Manager::delete( 'tmgtest_clone', '' ) ) );
	TMG_Roles_Role_Manager::delete( 'tmgtest_clone', 'tmgtest_role' );
	tmg_t( 'roles: delete reassigns users', ! get_role( 'tmgtest_clone' ) && in_array( 'tmgtest_role', get_userdata( $rt )->roles, true ) );
	tmg_t( 'roles: administrator cannot be deleted', is_wp_error( TMG_Roles_Role_Manager::delete( 'administrator', 'subscriber' ) ) );
	TMG_Roles_Role_Manager::set_caps( 'administrator', array( 'read' => true ) );
	tmg_t( 'roles: administrator cannot be reduced', ! empty( get_role( 'administrator' )->capabilities['manage_options'] ) );
	TMG_Roles_Role_Manager::set_default_role( 'ep_viewer' );
	tmg_t( 'roles: default role for new users', 'ep_viewer' === get_option( 'default_role' ) );
	TMG_Roles_Role_Manager::set_default_role( (string) $backup['default'] );
	TMG_Roles_Role_Manager::delete( 'tmgtest_role', 'subscriber' );

	// Extra roles on the profile.
	tmg_as( $super );
	TMG_Roles_User_Profile::save_extra_roles( $viewer, array( 'ep_manager', 'ep_developer' ) );
	$vr = get_userdata( $viewer )->roles;
	tmg_t( 'profile: extra roles added only when assignable', in_array( 'ep_manager', $vr, true ) && ! in_array( 'ep_developer', $vr, true ) );
	TMG_Roles_User_Profile::save_extra_roles( $viewer, array() );
	tmg_t( 'profile: extra roles removed', array( 'ep_viewer' ) === array_values( get_userdata( $viewer )->roles ) );
	tmg_as( 0 );

	// -----------------------------------------------------------------------------------------
	// Export / import.
	// -----------------------------------------------------------------------------------------
	$before = TMG_Roles_Transfer::export();
	unset( $before['exported'] );
	TMG_Roles_Role_Manager::rename( 'ep_manager', 'Changed Manager' );
	TMG_Roles_Role_Manager::set_rank( 'ep_viewer', 9 );
	TMG_Roles_Role_Manager::grant( 'ep_viewer', 'events_delete' );
	TMG_Roles_Settings::update( array( 'public_name' => 'Somebody', 'adminbar_rules' => array() ) );
	$plan = TMG_Roles_Transfer::plan( TMG_Roles_Transfer::decode( wp_json_encode( $before ) ), null );
	tmg_t( 'import: diff lists the changes', count( $plan['changes'] ) >= 4, implode( ' | ', $plan['changes'] ) );
	TMG_Roles_Transfer::apply( $plan );
	$after = TMG_Roles_Transfer::export();
	unset( $after['exported'] );
	tmg_t( 'import: round trip restores everything', $before === $after, wp_json_encode( array_diff_assoc( (array) wp_json_encode( $after ), (array) wp_json_encode( $before ) ) ) );
	$plan = TMG_Roles_Transfer::plan( $before, null );
	tmg_t( 'import: nothing left to change after the round trip', array() === $plan['changes'], implode( ' | ', $plan['changes'] ) );
	tmg_t( 'import: bad files are refused', is_wp_error( TMG_Roles_Transfer::decode( '{"plugin":"other"}' ) ) );

	// Cannot remove own access via import (developer = top rank but not administrator).
	$evil = $before;
	unset( $evil['settings']['roles']['ep_developer']['caps']['tmg_roles_manage'] );
	$evil['settings']['roles']['ep_developer']['rank']           = 1;
	$evil['settings']['roles']['administrator']['caps']          = array( 'read' => true );
	$evil['settings']['options']['menu_rules']['ep_developer']   = array( 'users.php', 'users.php|tamago-roles' );
	$evil['settings']['options']['no_admin']                     = array( 'ep_developer' );
	$evil['settings']['options']['protected_caps']               = array( 'tmg_roles_manage' );
	$evil['settings']['options']['protected_min_rank']           = 99;
	$plan = TMG_Roles_Transfer::plan( $evil, get_userdata( $dev ) );
	TMG_Roles_Transfer::apply( $plan );
	TMG_Roles_Capabilities::flush();
	tmg_t( 'import: own access kept (manage cap, rank, menu, wp-admin)', user_can( get_userdata( $dev ), 'tmg_roles_manage' ) && 4 === tmg_roles_rank( $dev ) && ! in_array( 'users.php|tamago-roles', TMG_Roles_Admin_Experience::hidden_menu( $dev ), true ) && ! in_array( 'ep_developer', (array) TMG_Roles_Settings::get( 'no_admin' ), true ), implode( ' | ', $plan['notes'] ) );
	tmg_t( 'import: notes explain what was kept', count( $plan['notes'] ) >= 3 );
	tmg_t( 'import: administrator not reduced by a file', ! empty( get_role( 'administrator' )->capabilities['manage_options'] ) && user_can( get_userdata( $admin ), 'tmg_roles_manage' ) );
	TMG_Roles_Transfer::apply( TMG_Roles_Transfer::plan( $before, null ) );

	// -----------------------------------------------------------------------------------------
	// Log.
	// -----------------------------------------------------------------------------------------
	$log = TMG_Roles_Logger::rows( 1, 5 );
	tmg_t( 'log: changes recorded', $log['total'] > 0 && ! empty( $log['rows'][0]['action'] ) );
	tmg_t( 'log: tmg_roles_changed fired', in_array( 'override', $changed, true ) && in_array( 'role_created', $changed, true ) && in_array( 'import', $changed, true ) );

	// Clean up content.
	foreach ( array( $p_public, $p_members, $p_roles, $p_login, $p_term, $event ) as $pid ) {
		wp_delete_post( $pid, true );
	}
	wp_delete_term( $term_id, 'category' );
} catch ( Throwable $e ) {
	tmg_t( 'no exceptions', false, get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
}

// Restore.
tmg_as( 0 );
foreach ( $created_users as $uid ) {
	wp_delete_user( $uid );
}
$restore();

$t = $GLOBALS['tmg_t'];
echo "\nTOTAL: {$t['pass']} passed, {$t['fail']} failed\n";
