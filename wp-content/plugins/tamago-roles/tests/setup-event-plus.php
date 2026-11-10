<?php
/**
 * Lab helper: recreate Event Plus's roles behaviour using only Tamago Roles' own API
 * (the same calls the admin screens make). Needs the lab mu-plugin that registers Event Plus's
 * permissions through tamago_permissions. Afterwards export with:
 *   wp tamago-roles settings export wp-content/plugins/tamago-roles/tests/fixtures/event-plus-like.json
 *
 * Used by: wp eval-file tests/setup-event-plus.php (lab sites only, never production).
 */

defined( 'ABSPATH' ) || exit;

// 1. Ranks: Developer = Administrator = 4, WordPress's own roles unranked (only Developers assign them).
TMG_Roles_Settings::update( array( 'ranks' => array( 'administrator' => 4, 'editor' => 0, 'author' => 0, 'contributor' => 0, 'subscriber' => 0 ) ), 'setup' );

// 2. Roles. Developer = full administrator + every registered permission.
$registered = array_keys( array_filter( TMG_Roles_Permissions::all(), fn( $p ) => 'registered' === $p['source'] ) );
$admin_caps = (array) get_role( 'administrator' )->capabilities;
foreach ( $registered as $perm ) {
	TMG_Roles_Role_Manager::grant( 'administrator', $perm );
}
$admin_caps = (array) get_role( 'administrator' )->capabilities;
$roles      = array(
	'ep_developer'   => array( 'Developer', 4, $admin_caps ),
	'ep_super_admin' => array( 'Super Admin', 3, array( 'read' => true ) ),
	'ep_manager'     => array( 'Manager', 2, array( 'read' => true ) ),
	'ep_viewer'      => array( 'Viewer', 1, array( 'read' => true ) ),
);
foreach ( $roles as $key => $r ) {
	if ( get_role( $key ) ) {
		TMG_Roles_Role_Manager::set_caps( $key, $r[2] );
		TMG_Roles_Role_Manager::set_rank( $key, $r[1] );
	} else {
		TMG_Roles_Role_Manager::create( $key, $r[0], $r[1], $r[2] );
	}
}

// 3. Matrix (Event Plus defaults).
$ep_perms = array_values( array_filter( $registered, fn( $k ) => 0 !== strpos( $k, 'tmg_roles_' ) ) );
$matrix   = array(
	'ep_super_admin' => $ep_perms,
	'ep_manager'     => array( 'dashboard', 'events_view', 'events_create', 'events_publish', 'events_status', 'applicants_view', 'applicants_edit', 'applicants_export', 'reports_view', 'reports_export', 'media' ),
	'ep_viewer'      => array( 'dashboard', 'events_view', 'applicants_view', 'reports_view' ),
);
foreach ( $matrix as $role => $on ) {
	$set = array();
	foreach ( $ep_perms as $perm ) {
		$set[ $perm ] = in_array( $perm, $on, true );
	}
	TMG_Roles_Role_Manager::apply_matrix( $role, $set );
}

// 4–8. Protected technical caps, rank rules, menus, admin bar, login redirect, staff privacy.
$hide_menu = array( 'index.php', 'edit.php', 'edit.php?post_type=page', 'edit-comments.php', 'themes.php', 'plugins.php', 'tools.php', 'options-general.php', 'edit.php?post_type=acf-field-group' );
$hide_bar  = array( 'comments', 'customize', 'updates', 'themes', 'widgets', 'dashboard', 'site-editor', 'menus', 'new-post', 'new-page', 'edit' );
$staff     = array( 'ep_super_admin', 'ep_manager', 'ep_viewer' );
TMG_Roles_Settings::update(
	array(
		'protected_caps'          => TMG_Roles_Settings::technical_preset(),
		'protected_min_rank'      => 4,
		'hide_higher_users'       => true,
		'hide_higher_min_rank'    => 4,
		'peer_management'         => true,
		'assign_unranked'         => false,
		'restrict_admins'         => false,
		'menu_rules'              => array_fill_keys( $staff, $hide_menu ),
		'adminbar_rules'          => array_fill_keys( $staff, $hide_bar ),
		'login_redirect'          => array_fill_keys( $staff, 'admin.php?page=epc' ),
		'public_name_roles'       => array( 'ep_developer', 'administrator' ),
		'public_name'             => '',
		'public_name_reveal_rank' => 4,
		'public_name_rest_hide'   => true,
	),
	'setup'
);
foreach ( array_keys( $roles ) as $key ) {
	TMG_Roles_Role_Manager::save_as_defaults( $key );
}
WP_CLI::success( 'Event Plus-like setup applied.' );
