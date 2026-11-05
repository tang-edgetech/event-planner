<?php
/**
 * Applicants list & edit screen.
 */

defined( 'ABSPATH' ) || exit;

class EPC_Applicants_Admin {

	public static function init(): void {
		$pt = EPC_Post_Types::APPLICANT;
		add_filter( "manage_{$pt}_posts_columns", array( __CLASS__, 'columns' ) );
		add_action( "manage_{$pt}_posts_custom_column", array( __CLASS__, 'column' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'filters' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'apply_filters' ) );
		add_filter( 'posts_search', array( __CLASS__, 'search' ), 10, 2 );
		add_filter( "bulk_actions-edit-{$pt}", array( __CLASS__, 'bulk_actions' ) );
		add_filter( "handle_bulk_actions-edit-{$pt}", array( __CLASS__, 'handle_bulk' ), 10, 3 );
		add_action( 'add_meta_boxes_' . $pt, array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_' . $pt, array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'manage_posts_extra_tablenav', array( __CLASS__, 'export_button' ) );
		add_filter( 'views_edit-' . $pt, array( __CLASS__, 'status_views' ) );
	}

	public static function columns( array $cols ): array {
		$event = absint( $_GET['epc_event'] ?? 0 ); // phpcs:ignore
		$new   = array(
			'cb'    => $cols['cb'],
			'title' => 'Name',
		);
		if ( $event ) {
			// Show the event's own fields (first 5) when filtered.
			$i = 0;
			foreach ( epc_get_data_fields( $event ) as $f ) {
				if ( in_array( $f['name'], array( 'salutation', 'fullname' ), true ) || 'consent' === $f['type'] ) {
					continue;
				}
				$new[ 'f_' . $f['name'] ] = esc_html( wp_strip_all_tags( $f['label'] ) );
				if ( ++$i >= 5 ) {
					break;
				}
			}
		} else {
			$new['epc_event'] = 'Event';
			$new['f_email']   = 'Email';
		}
		$new['epc_status'] = 'Status';
		$new['date']       = 'Registered';
		return $new;
	}

	public static function column( string $col, int $id ): void {
		if ( 'epc_event' === $col ) {
			$e = (int) get_post_meta( $id, '_epc_event_id', true );
			echo $e ? '<a href="' . esc_url( add_query_arg( 'epc_event', $e ) ) . '">' . esc_html( get_the_title( $e ) ) . '</a>' : '—';
		} elseif ( 'epc_status' === $col ) {
			$s = get_post_meta( $id, '_epc_status', true ) ?: 'registered';
			echo '<span class="epc-badge epc-badge--' . esc_attr( $s ) . '">' . esc_html( epc_applicant_statuses()[ $s ] ?? $s ) . '</span>';
		} elseif ( str_starts_with( $col, 'f_' ) ) {
			echo esc_html( (string) get_post_meta( $id, $col, true ) );
		}
	}

	public static function filters( string $post_type ): void {
		if ( EPC_Post_Types::APPLICANT !== $post_type ) {
			return;
		}
		$current = absint( $_GET['epc_event'] ?? 0 ); // phpcs:ignore
		$events  = get_posts( array( 'post_type' => EPC_Post_Types::EVENT, 'numberposts' => -1, 'post_status' => 'any', 'orderby' => 'date', 'order' => 'DESC' ) );
		echo '<select name="epc_event"><option value="">All events</option>';
		foreach ( $events as $e ) {
			echo '<option value="' . (int) $e->ID . '"' . selected( $current, $e->ID, false ) . '>' . esc_html( $e->post_title ) . '</option>';
		}
		echo '</select>';
	}

	public static function status_views( array $views ): array {
		$base = remove_query_arg( array( 'epc_status', 'paged' ) );
		$cur  = sanitize_key( $_GET['epc_status'] ?? '' ); // phpcs:ignore
		$out  = array( 'all' => $views['all'] ?? '' );
		foreach ( epc_applicant_statuses() as $k => $label ) {
			$out[ 'epc_' . $k ] = '<a href="' . esc_url( add_query_arg( 'epc_status', $k, $base ) ) . '"' . ( $cur === $k ? ' class="current"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		if ( isset( $views['trash'] ) ) {
			$out['trash'] = $views['trash'];
		}
		return $out;
	}

	public static function apply_filters( WP_Query $q ): void {
		if ( ! is_admin() || ! $q->is_main_query() || EPC_Post_Types::APPLICANT !== $q->get( 'post_type' ) ) {
			return;
		}
		$meta = array();
		if ( ! empty( $_GET['epc_event'] ) ) { // phpcs:ignore
			$meta[] = array( 'key' => '_epc_event_id', 'value' => absint( $_GET['epc_event'] ) ); // phpcs:ignore
		}
		if ( ! empty( $_GET['epc_status'] ) ) { // phpcs:ignore
			$meta[] = array( 'key' => '_epc_status', 'value' => sanitize_key( $_GET['epc_status'] ) ); // phpcs:ignore
		}
		if ( $meta ) {
			$q->set( 'meta_query', $meta );
		}
	}

	/** Search inside answers (email, NRIC, phone…) as well as the name. */
	public static function search( string $search, WP_Query $q ): string {
		global $wpdb;
		if ( ! is_admin() || ! $q->is_main_query() || EPC_Post_Types::APPLICANT !== $q->get( 'post_type' ) || '' === $q->get( 's' ) ) {
			return $search;
		}
		$like = '%' . $wpdb->esc_like( $q->get( 's' ) ) . '%';
		return $wpdb->prepare(
			" AND ( {$wpdb->posts}.post_title LIKE %s OR {$wpdb->posts}.ID IN ( SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key LIKE 'f\\_%%' AND meta_value LIKE %s ) ) ",
			$like,
			$like
		);
	}

	public static function bulk_actions( array $actions ): array {
		if ( ! current_user_can( 'edit_others_ep_applicants' ) ) {
			return array_intersect_key( $actions, array( 'trash' => 1, 'delete' => 1 ) );
		}
		foreach ( epc_applicant_statuses() as $k => $label ) {
			$actions[ 'epc_set_' . $k ] = 'Mark as ' . $label;
		}
		return $actions;
	}

	public static function handle_bulk( string $redirect, string $action, array $ids ): string {
		if ( str_starts_with( $action, 'epc_set_' ) ) {
			$status = substr( $action, 8 );
			if ( isset( epc_applicant_statuses()[ $status ] ) ) {
				foreach ( $ids as $id ) {
					if ( ! current_user_can( 'edit_post', (int) $id ) ) {
						continue;
					}
					update_post_meta( (int) $id, '_epc_status', $status );
				}
			}
		}
		return $redirect;
	}

	public static function export_button( string $which ): void {
		global $typenow;
		if ( 'top' !== $which || EPC_Post_Types::APPLICANT !== $typenow || ! current_user_can( 'epc_export_applicants' ) ) {
			return;
		}
		$args = array(
			'action' => 'epc_export',
			'type'   => 'applicants',
			'event'  => absint( $_GET['epc_event'] ?? 0 ), // phpcs:ignore
			'status' => sanitize_key( $_GET['epc_status'] ?? '' ), // phpcs:ignore
		);
		echo '<div class="alignleft actions">';
		foreach ( array( 'xlsx' => 'Excel', 'csv' => 'CSV', 'json' => 'JSON' ) as $fmt => $label ) {
			$url = wp_nonce_url( add_query_arg( $args + array( 'format' => $fmt ), admin_url( 'admin-post.php' ) ), 'epc_export' );
			echo '<a class="button" href="' . esc_url( $url ) . '">Export ' . esc_html( $label ) . '</a> ';
		}
		echo '</div>';
	}

	public static function meta_boxes(): void {
		add_meta_box( 'epc_applicant', 'Registration details', array( __CLASS__, 'render_box' ), EPC_Post_Types::APPLICANT, 'normal', 'high' );
		add_meta_box( 'epc_applicant_meta', 'Status', array( __CLASS__, 'render_side' ), EPC_Post_Types::APPLICANT, 'side', 'high' );
	}

	public static function render_box( WP_Post $post ): void {
		$data   = (array) get_post_meta( $post->ID, '_epc_data', true );
		$labels = (array) get_post_meta( $post->ID, '_epc_labels', true );
		$event  = (int) get_post_meta( $post->ID, '_epc_event_id', true );
		// Current event fields first (so newly added fields can be filled), then any legacy keys.
		$keys = array();
		foreach ( epc_get_data_fields( $event ) as $f ) {
			$keys[ $f['name'] ] = wp_strip_all_tags( $f['label'] ) ?: $f['name'];
		}
		foreach ( $data as $k => $v ) {
			if ( ! isset( $keys[ $k ] ) ) {
				$keys[ $k ] = $labels[ $k ] ?? $k;
			}
		}
		wp_nonce_field( 'epc_applicant_save', 'epc_applicant_nonce' );
		echo '<table class="form-table epc-applicant-table"><tbody>';
		foreach ( $keys as $k => $label ) {
			$v = $data[ $k ] ?? '';
			$v = is_array( $v ) ? implode( ', ', $v ) : $v;
			echo '<tr><th><label for="epc_f_' . esc_attr( $k ) . '">' . esc_html( $label ) . '</label><br><code>' . esc_html( $k ) . '</code></th><td><input type="text" class="large-text" id="epc_f_' . esc_attr( $k ) . '" name="epc_f[' . esc_attr( $k ) . ']" value="' . esc_attr( (string) $v ) . '"></td></tr>';
		}
		echo '</tbody></table>';

		$src = get_post_meta( $post->ID, '_epc_source', true );
		if ( $src ) {
			echo '<h4>Traffic source</h4><p>';
			foreach ( (array) $src as $k => $v ) {
				echo '<strong>' . esc_html( $k ) . ':</strong> ' . esc_html( $v ) . '&nbsp;&nbsp; ';
			}
			echo '</p>';
		}
	}

	public static function render_side( WP_Post $post ): void {
		$status = get_post_meta( $post->ID, '_epc_status', true ) ?: 'registered';
		$event  = (int) get_post_meta( $post->ID, '_epc_event_id', true );
		echo '<p><label><strong>Status</strong><br><select name="epc_status" style="width:100%">';
		foreach ( epc_applicant_statuses() as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $status, $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label></p>';
		echo '<p><label><strong>Event</strong><br><select name="epc_event_id" style="width:100%">';
		foreach ( get_posts( array( 'post_type' => EPC_Post_Types::EVENT, 'numberposts' => -1, 'post_status' => 'any' ) ) as $e ) {
			echo '<option value="' . (int) $e->ID . '"' . selected( $event, $e->ID, false ) . '>' . esc_html( $e->post_title ) . '</option>';
		}
		echo '</select></label></p>';
	}

	public static function save( int $post_id, WP_Post $post ): void {
		if ( ! isset( $_POST['epc_applicant_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['epc_applicant_nonce'] ) ), 'epc_applicant_save' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$data = (array) get_post_meta( $post_id, '_epc_data', true );
		foreach ( (array) wp_unslash( $_POST['epc_f'] ?? array() ) as $k => $v ) {
			$k          = sanitize_key( $k );
			$data[ $k ] = sanitize_text_field( $v );
			update_post_meta( $post_id, 'f_' . $k, $data[ $k ] );
		}
		update_post_meta( $post_id, '_epc_data', $data );
		$status = sanitize_key( $_POST['epc_status'] ?? '' );
		if ( isset( epc_applicant_statuses()[ $status ] ) ) {
			update_post_meta( $post_id, '_epc_status', $status );
		}
		if ( ! empty( $_POST['epc_event_id'] ) ) {
			update_post_meta( $post_id, '_epc_event_id', absint( $_POST['epc_event_id'] ) );
		}
	}
}
