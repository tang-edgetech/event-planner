<?php
/**
 * Custom post types: Events (registration forms), Microsites, Applicants.
 */

defined( 'ABSPATH' ) || exit;

class EPC_Post_Types {

	const EVENT     = 'ep_event';
	const MICROSITE = 'ep_microsite';
	const APPLICANT = 'ep_applicant';

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_action( 'admin_action_epc_duplicate', array( __CLASS__, 'duplicate' ) );
	}

	public static function register(): void {
		register_post_type(
			self::EVENT,
			array(
				'labels'        => self::labels( 'Event', 'Events' ),
				'public'        => true,
				'show_in_rest'  => true,
				'menu_icon'     => 'dashicons-tickets-alt',
				'menu_position' => 21,
				'supports'      => array( 'title', 'thumbnail', 'revisions' ),
				'has_archive'   => false,
				'rewrite'       => array( 'slug' => 'event', 'with_front' => false ),
				'capabilities'  => EPC_Roles::cpt_caps( 'ep_event', 'ep_events' ),
				'map_meta_cap'  => true,
				'show_in_menu'  => 'epc',
			)
		);

		register_post_type(
			self::MICROSITE,
			array(
				'labels'        => self::labels( 'Microsite', 'Microsites' ),
				'public'        => true,
				'show_in_rest'  => true,
				'menu_icon'     => 'dashicons-layout',
				'supports'      => array( 'title', 'thumbnail', 'revisions' ),
				'has_archive'   => false,
				'rewrite'       => array( 'slug' => 'e', 'with_front' => false ),
				'capabilities'  => EPC_Roles::cpt_caps( 'ep_microsite', 'ep_microsites' ),
				'map_meta_cap'  => true,
				'show_in_menu'  => 'epc',
			)
		);

		register_post_type(
			self::APPLICANT,
			array(
				'labels'              => self::labels( 'Applicant', 'Applicants' ),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => 'epc',
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'supports'            => array( 'title' ),
				'capabilities'        => array( 'create_posts' => 'do_not_allow' ) + EPC_Roles::cpt_caps( 'ep_applicant', 'ep_applicants' ),
				'map_meta_cap'        => true,
			)
		);
	}

	private static function labels( string $one, string $many ): array {
		return array(
			'name'               => $many,
			'singular_name'      => $one,
			'add_new'            => "Add {$one}",
			'add_new_item'       => "Add new {$one}",
			'edit_item'          => "Edit {$one}",
			'new_item'           => "New {$one}",
			'view_item'          => "View {$one}",
			'search_items'       => "Search {$many}",
			'not_found'          => "No {$many} found",
			'not_found_in_trash' => "No {$many} found in Trash",
			'all_items'          => $many,
			'menu_name'          => $many,
		);
	}

	/** "Duplicate" row action for events and microsites — reuse a form or layout quickly. */
	public static function row_actions( array $actions, WP_Post $post ): array {
		if ( in_array( $post->post_type, array( self::EVENT, self::MICROSITE ), true ) && current_user_can( get_post_type_object( $post->post_type )->cap->create_posts ) ) {
			$url                      = wp_nonce_url( admin_url( 'admin.php?action=epc_duplicate&post=' . $post->ID ), 'epc_duplicate_' . $post->ID );
			$actions['epc_duplicate'] = '<a href="' . esc_url( $url ) . '">Duplicate</a>';
		}
		if ( self::EVENT === $post->post_type && current_user_can( 'epc_view_applicants' ) ) {
			$actions['epc_applicants'] = '<a href="' . esc_url( admin_url( 'edit.php?post_type=' . self::APPLICANT . '&epc_event=' . $post->ID ) ) . '">Applicants</a>';
			$actions['epc_report']     = '<a href="' . esc_url( admin_url( 'admin.php?page=epc-analytics&post_id=' . $post->ID ) ) . '">Report</a>';
			if ( ! current_user_can( 'epc_view_reports' ) ) {
				unset( $actions['epc_report'] );
			}
		}
		if ( self::MICROSITE === $post->post_type && current_user_can( 'epc_view_reports' ) ) {
			$actions['epc_report'] = '<a href="' . esc_url( admin_url( 'admin.php?page=epc-analytics&post_id=' . $post->ID ) ) . '">Report</a>';
		}
		return $actions;
	}

	public static function duplicate(): void {
		$id = absint( $_GET['post'] ?? 0 );
		check_admin_referer( 'epc_duplicate_' . $id );
		$post = get_post( $id );
		if ( ! $post || ! current_user_can( 'edit_post', $id ) || ! current_user_can( get_post_type_object( $post->post_type )->cap->create_posts ) ) {
			wp_die( 'Not allowed.' );
		}
		$new_id = wp_insert_post(
			array(
				'post_type'   => $post->post_type,
				'post_title'  => $post->post_title . ' (Copy)',
				'post_status' => 'draft',
				'post_author' => get_current_user_id(),
			)
		);
		foreach ( get_post_meta( $id ) as $key => $values ) {
			if ( in_array( $key, array( '_edit_lock', '_edit_last', '_wp_old_slug' ), true ) ) {
				continue;
			}
			foreach ( $values as $v ) {
				add_post_meta( $new_id, $key, maybe_unserialize( $v ) );
			}
		}
		wp_safe_redirect( admin_url( 'post.php?action=edit&post=' . $new_id ) );
		exit;
	}
}
