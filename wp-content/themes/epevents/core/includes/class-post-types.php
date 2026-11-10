<?php
/**
 * Events (registration forms), Microsites, Applicants: capabilities and the Duplicate / Applicants / Report
 * row actions. The post types are registered by ACF (ACF → Post Types).
 */

defined( 'ABSPATH' ) || exit;

class EPC_Post_Types {

	const EVENT     = 'ep_event';
	const MICROSITE = 'ep_microsite';
	const APPLICANT = 'ep_applicant';

	public static function init(): void {
		add_filter( 'register_post_type_args', array( __CLASS__, 'create_cap' ), 10, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_action( 'admin_action_epc_duplicate', array( __CLASS__, 'duplicate' ) );
	}

	/**
	 * The post types themselves are defined in ACF → Post Types (core/acf-json/post_type_epc_*.json).
	 * ACF cannot set the "create" capability, so it is added here: Events and Microsites use their own
	 * create_ep_* capability (granted in Event Plus → Permissions); Applicants are never created by hand.
	 */
	public static function create_cap( array $args, string $post_type ): array {
		if ( self::APPLICANT === $post_type ) {
			$args['capabilities']['create_posts'] = 'do_not_allow';
		} elseif ( in_array( $post_type, array( self::EVENT, self::MICROSITE ), true ) ) {
			$args['capabilities']['create_posts'] = 'create_' . $post_type . 's';
		}
		return $args;
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
			wp_die( 'Not allowed.', 'Not allowed', array( 'response' => 403, 'back_link' => true ) );
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
			// Bookkeeping and per-event flags (e.g. capacity alerts already sent) start fresh on the copy.
			if ( in_array( $key, array( '_edit_lock', '_edit_last', '_wp_old_slug' ), true ) || str_starts_with( $key, '_epc_' ) ) {
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
