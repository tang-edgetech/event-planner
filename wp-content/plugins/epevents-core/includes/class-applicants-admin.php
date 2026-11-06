<?php
/**
 * Applicants list & edit screen: event filter with summary bar, status counts, search in answers,
 * bulk status, export, notes + activity log, resend confirmation, validated edits.
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
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 20, 2 );
		add_action( 'add_meta_boxes_' . $pt, array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_' . $pt, array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'manage_posts_extra_tablenav', array( __CLASS__, 'export_button' ) );
		add_filter( 'views_edit-' . $pt, array( __CLASS__, 'status_views' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_post_epc_resend', array( __CLASS__, 'resend' ) );
	}

	private static function current_event(): int {
		return absint( $_GET['epc_event'] ?? 0 ); // phpcs:ignore
	}

	/* ------------------------------------------------------------------ */
	/* List screen                                                         */
	/* ------------------------------------------------------------------ */

	public static function columns( array $cols ): array {
		$event = self::current_event();
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
		static $types = array();
		if ( 'epc_event' === $col ) {
			$e = (int) get_post_meta( $id, '_epc_event_id', true );
			echo $e ? '<a href="' . esc_url( add_query_arg( 'epc_event', $e ) ) . '">' . esc_html( get_the_title( $e ) ) . '</a>' : '—';
		} elseif ( 'epc_status' === $col ) {
			$s = get_post_meta( $id, '_epc_status', true ) ?: 'registered';
			echo '<span class="epc-badge epc-badge--' . esc_attr( $s ) . '">' . esc_html( epc_applicant_statuses()[ $s ] ?? $s ) . '</span>';
		} elseif ( str_starts_with( $col, 'f_' ) ) {
			$value = (string) get_post_meta( $id, $col, true );
			if ( EPC_Features::mask_personal_data() ) {
				$e = (int) get_post_meta( $id, '_epc_event_id', true );
				if ( ! isset( $types[ $e ] ) ) {
					$types[ $e ] = EPC_Features::field_types( $e );
				}
				$name  = substr( $col, 2 );
				$value = EPC_Features::mask( $value, $types[ $e ][ $name ] ?? ( 'email' === $name ? 'email' : '' ) );
			}
			echo esc_html( $value );
		}
	}

	public static function row_actions( array $actions, WP_Post $post ): array {
		if ( EPC_Post_Types::APPLICANT === $post->post_type && current_user_can( 'edit_post', $post->ID ) ) {
			$actions['epc_resend'] = '<a href="' . esc_url( self::resend_url( $post->ID ) ) . '" onclick="return confirm(\'Send the confirmation email to this applicant again?\');">Resend email</a>';
		}
		return $actions;
	}

	public static function filters( string $post_type ): void {
		if ( EPC_Post_Types::APPLICANT !== $post_type ) {
			return;
		}
		$current = self::current_event();
		$events  = get_posts( array( 'post_type' => EPC_Post_Types::EVENT, 'numberposts' => -1, 'post_status' => 'any', 'orderby' => 'date', 'order' => 'DESC' ) );
		echo '<label class="screen-reader-text" for="epc-event-filter">Event</label><select name="epc_event" id="epc-event-filter"><option value="">All events</option>';
		foreach ( $events as $e ) {
			echo '<option value="' . (int) $e->ID . '"' . selected( $current, $e->ID, false ) . '>' . esc_html( $e->post_title ) . '</option>';
		}
		echo '</select>';
	}

	/** Number of applicants per status (optionally for one event). */
	public static function status_counts( int $event = 0 ): array {
		global $wpdb;
		$join  = $event ? $wpdb->prepare( " INNER JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = '_epc_event_id' AND e.meta_value = %d", $event ) : '';
		$rows  = $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				"SELECT COALESCE(s.meta_value, 'registered') AS st, COUNT(1) AS n FROM {$wpdb->posts} p {$join}
				 LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_epc_status'
				 WHERE p.post_type = %s AND p.post_status = 'publish' GROUP BY st",
				EPC_Post_Types::APPLICANT
			)
		);
		$out = array_fill_keys( array_keys( epc_applicant_statuses() ), 0 );
		foreach ( $rows as $r ) {
			$out[ $r->st ] = (int) $r->n;
		}
		return $out;
	}

	public static function status_views( array $views ): array {
		$base   = remove_query_arg( array( 'epc_status', 'paged', 'post_status' ) );
		$cur    = sanitize_key( $_GET['epc_status'] ?? '' ); // phpcs:ignore
		$counts = self::status_counts( self::current_event() );
		$all    = array_sum( $counts );
		self::summary();
		$out    = array( 'all' => '<a href="' . esc_url( $base ) . '"' . ( '' === $cur && empty( $_GET['post_status'] ) ? ' class="current" aria-current="page"' : '' ) . '>All <span class="count">(' . $all . ')</span></a>' ); // phpcs:ignore
		foreach ( epc_applicant_statuses() as $k => $label ) {
			$out[ 'epc_' . $k ] = '<a href="' . esc_url( add_query_arg( 'epc_status', $k, $base ) ) . '"' . ( $cur === $k ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html( $label ) . ' <span class="count">(' . (int) $counts[ $k ] . ')</span></a>';
		}
		if ( isset( $views['trash'] ) ) {
			$out['trash'] = $views['trash'];
		}
		return $out;
	}

	/** Event summary (seats, status counts), printed above the status links when one event is selected. */
	private static function summary(): void {
		$event = self::current_event();
		if ( $event && get_post( $event ) ) {
			$state  = EPC_Capacity::state( $event );
			$counts = self::status_counts( $event );
			$pct    = $state['max'] > 0 ? min( 100, (int) round( $state['count'] / $state['max'] * 100 ) ) : 0;
			echo '<div class="epc-summary">';
			echo '<div class="epc-summary__title"><strong>' . esc_html( get_the_title( $event ) ) . '</strong> ' . EPC_Capacity::badge( $state['state'] ) . '<br><a href="' . esc_url( get_permalink( $event ) ) . '" target="_blank">View form</a>'; // phpcs:ignore
			if ( current_user_can( 'edit_post', $event ) ) {
				echo ' · <a href="' . esc_url( get_edit_post_link( $event ) ) . '">Edit event</a>';
			}
			echo '</div>';
			echo '<div class="epc-summary__seats"><span class="epc-summary__num">' . (int) $state['count'] . ( $state['max'] ? ' / ' . (int) $state['max'] : '' ) . '</span> seats taken';
			if ( $state['max'] ) {
				echo '<span class="epc-progress"><span style="width:' . (int) $pct . '%"></span></span>';
			}
			if ( $state['min'] && $state['count'] < $state['min'] ) {
				echo '<span class="epc-muted">Minimum ' . (int) $state['min'] . ' not reached</span>';
			}
			echo '</div><ul class="epc-summary__counts">';
			foreach ( epc_applicant_statuses() as $k => $label ) {
				echo '<li><span class="epc-badge epc-badge--' . esc_attr( $k ) . '">' . esc_html( $label ) . '</span> <strong>' . (int) $counts[ $k ] . '</strong></li>';
			}
			echo '</ul></div>';
		}
	}

	/** Result notices. */
	public static function notices(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}
		if ( isset( $_GET['epc_resent'] ) ) { // phpcs:ignore
			$ok = '1' === $_GET['epc_resent']; // phpcs:ignore
			echo '<div class="notice notice-' . ( $ok ? 'success' : 'error' ) . ' is-dismissible"><p>' . ( $ok ? 'Confirmation email sent.' : 'The email could not be sent (no valid email address, or mail sending failed).' ) . '</p></div>';
		}
		if ( 'post' === $screen->base && EPC_Post_Types::APPLICANT === $screen->post_type ) {
			$warn = get_transient( 'epc_applicant_warn_' . get_current_user_id() );
			if ( $warn ) {
				delete_transient( 'epc_applicant_warn_' . get_current_user_id() );
				echo '<div class="notice notice-warning"><p><strong>Saved, but please check:</strong></p><ul style="list-style:disc;margin-left:20px">';
				foreach ( (array) $warn as $w ) {
					echo '<li>' . esc_html( $w ) . '</li>';
				}
				echo '</ul></div>';
			}
		}
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
			$status = sanitize_key( $_GET['epc_status'] ); // phpcs:ignore
			if ( 'registered' === $status ) {
				// Older or imported applicants may have no status stored: they count as Registered.
				$meta[] = array(
					'relation' => 'OR',
					array( 'key' => '_epc_status', 'value' => 'registered' ),
					array( 'key' => '_epc_status', 'compare' => 'NOT EXISTS' ),
				);
			} else {
				$meta[] = array( 'key' => '_epc_status', 'value' => $status );
			}
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
		$actions['epc_resend'] = 'Resend confirmation email';
		return $actions;
	}

	public static function handle_bulk( string $redirect, string $action, array $ids ): string {
		if ( str_starts_with( $action, 'epc_set_' ) ) {
			$status = substr( $action, 8 );
			if ( isset( epc_applicant_statuses()[ $status ] ) ) {
				foreach ( $ids as $id ) {
					if ( current_user_can( 'edit_post', (int) $id ) ) {
						EPC_Features::set_status( (int) $id, $status, 'bulk action' );
					}
				}
			}
		} elseif ( 'epc_resend' === $action ) {
			$sent = 0;
			foreach ( array_slice( $ids, 0, 200 ) as $id ) {
				if ( current_user_can( 'edit_post', (int) $id ) && self::send_again( (int) $id ) ) {
					++$sent;
				}
			}
			$redirect = add_query_arg( 'epc_resent', $sent ? '1' : '0', $redirect );
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
			'event'  => self::current_event(),
			'status' => sanitize_key( $_GET['epc_status'] ?? '' ), // phpcs:ignore
		);
		echo '<div class="alignleft actions epc-export-buttons">';
		foreach ( array( 'xlsx' => 'Excel', 'csv' => 'CSV', 'json' => 'JSON' ) as $fmt => $label ) {
			$url = wp_nonce_url( add_query_arg( $args + array( 'format' => $fmt ), admin_url( 'admin-post.php' ) ), 'epc_export' );
			echo '<a class="button" href="' . esc_url( $url ) . '">Export ' . esc_html( $label ) . '</a> ';
		}
		echo '</div>';
	}

	/* ------------------------------------------------------------------ */
	/* Resend confirmation                                                 */
	/* ------------------------------------------------------------------ */

	private static function resend_url( int $id ): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=epc_resend&id=' . $id ), 'epc_resend_' . $id );
	}

	/** Re-send the email matching the applicant's current status. */
	public static function send_again( int $id ): bool {
		$status = get_post_meta( $id, '_epc_status', true ) ?: 'registered';
		if ( 'cancelled' === $status ) {
			return false;
		}
		$ok = EPC_Mailer::confirmation( $id, 'waitlist' === $status ? 'waitlist' : 'registered' );
		EPC_Features::log( $id, $ok ? 'Confirmation email re-sent' : 'Confirmation email could not be sent' );
		return $ok;
	}

	public static function resend(): void {
		$id = absint( $_GET['id'] ?? 0 ); // phpcs:ignore
		check_admin_referer( 'epc_resend_' . $id );
		if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
			wp_die( 'Not allowed.' );
		}
		$ok   = self::send_again( $id );
		$back = wp_get_referer() ?: admin_url( 'edit.php?post_type=' . EPC_Post_Types::APPLICANT );
		wp_safe_redirect( add_query_arg( 'epc_resent', $ok ? '1' : '0', remove_query_arg( 'epc_resent', $back ) ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Edit screen                                                         */
	/* ------------------------------------------------------------------ */

	public static function meta_boxes(): void {
		add_meta_box( 'epc_applicant', 'Registration details', array( __CLASS__, 'render_box' ), EPC_Post_Types::APPLICANT, 'normal', 'high' );
		add_meta_box( 'epc_applicant_meta', 'Status', array( __CLASS__, 'render_side' ), EPC_Post_Types::APPLICANT, 'side', 'high' );
		add_meta_box( 'epc_applicant_log', 'Notes & activity', array( __CLASS__, 'render_log' ), EPC_Post_Types::APPLICANT, 'normal', 'default' );
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

		$src = array_filter( (array) get_post_meta( $post->ID, '_epc_source', true ) );
		if ( $src ) {
			echo '<h4>Traffic source</h4><p>';
			foreach ( $src as $k => $v ) {
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
		if ( 'auto-draft' !== $post->post_status ) {
			echo '<p><a class="button" href="' . esc_url( self::resend_url( $post->ID ) ) . '" onclick="return confirm(\'Send the confirmation email to this applicant again?\');">Resend confirmation email</a></p>';
			echo '<p class="description">Registered ' . esc_html( get_the_date( 'j M Y, g:ia', $post ) ) . '</p>';
		}
	}

	/** Activity lines, newest first. */
	public static function log_html( int $id ): string {
		$log = array_reverse( array_filter( (array) get_post_meta( $id, '_epc_log', true ) ) );
		$out = '<ol class="epc-log">';
		foreach ( $log as $l ) {
			$who  = ! empty( $l['u'] ) ? ( get_userdata( (int) $l['u'] )->display_name ?? 'User' ) : 'System';
			$out .= '<li><span class="epc-log__when">' . esc_html( wp_date( 'j M Y, g:ia', (int) $l['t'] ) ) . ' · ' . esc_html( $who ) . '</span>' . esc_html( (string) $l['m'] ) . '</li>';
		}
		$post = get_post( $id );
		$out .= '<li><span class="epc-log__when">' . esc_html( get_the_date( 'j M Y, g:ia', $post ) ) . '</span>Registered</li>';
		return $out . '</ol>';
	}

	public static function render_log( WP_Post $post ): void {
		echo '<p><label for="epc_note"><strong>Add a note</strong> <span class="description">(staff only, saved with Update)</span></label><textarea id="epc_note" name="epc_note" rows="2" class="large-text" placeholder="e.g. Called to confirm dietary needs"></textarea></p>';
		echo self::log_html( $post->ID ); // phpcs:ignore -- escaped above.
	}

	/** Validate / normalise an answer by its field type. Returns [value, warning]. */
	private static function clean( string $v, array $f ): array {
		if ( '' === $v ) {
			return array( $v, '' );
		}
		$label = wp_strip_all_tags( $f['label'] ) ?: $f['name'];
		switch ( $f['type'] ) {
			case 'email':
				[ $ok, $out, $msg ] = EPC_Validator::email( $v );
				break;
			case 'phone':
				[ $ok, $out, $msg ] = EPC_Validator::phone( $v, $f['format'] ?: 'my' );
				break;
			case 'nric':
				[ $ok, $out, $msg ] = EPC_Validator::nric( $v, $f['format'] ?: 'my' );
				break;
			default:
				return array( $v, '' );
		}
		return $ok ? array( (string) $out, '' ) : array( $v, $label . ': ' . $msg );
	}

	public static function save( int $post_id, WP_Post $post ): void {
		if ( ! isset( $_POST['epc_applicant_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['epc_applicant_nonce'] ) ), 'epc_applicant_save' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$event = ! empty( $_POST['epc_event_id'] ) ? absint( $_POST['epc_event_id'] ) : (int) get_post_meta( $post_id, '_epc_event_id', true );
		$defs  = array();
		foreach ( epc_get_data_fields( $event ) as $f ) {
			$defs[ $f['name'] ] = $f;
		}
		$data    = (array) get_post_meta( $post_id, '_epc_data', true );
		$warn    = array();
		$changed = array();
		foreach ( (array) wp_unslash( $_POST['epc_f'] ?? array() ) as $k => $v ) {
			$k = sanitize_key( $k );
			$v = sanitize_text_field( $v );
			if ( isset( $defs[ $k ] ) ) {
				[ $v, $w ] = self::clean( $v, $defs[ $k ] );
				if ( $w ) {
					$warn[] = $w;
				}
			}
			$old = $data[ $k ] ?? '';
			$old = is_array( $old ) ? implode( ', ', $old ) : (string) $old;
			if ( $old !== $v ) {
				$changed[] = $defs[ $k ]['label'] ?? $k;
			}
			$data[ $k ] = $v;
			update_post_meta( $post_id, 'f_' . $k, $v );
		}
		update_post_meta( $post_id, '_epc_data', $data );
		if ( $changed ) {
			EPC_Features::log( $post_id, 'Edited: ' . implode( ', ', array_map( 'wp_strip_all_tags', $changed ) ) );
		}
		if ( $warn ) {
			set_transient( 'epc_applicant_warn_' . get_current_user_id(), $warn, 120 );
		}
		if ( $event && (int) get_post_meta( $post_id, '_epc_event_id', true ) !== $event ) {
			update_post_meta( $post_id, '_epc_event_id', $event );
			EPC_Features::log( $post_id, 'Moved to event: ' . get_the_title( $event ) );
		}
		$status = sanitize_key( $_POST['epc_status'] ?? '' );
		if ( isset( epc_applicant_statuses()[ $status ] ) ) {
			EPC_Features::set_status( $post_id, $status );
		}
		$note = trim( sanitize_textarea_field( wp_unslash( $_POST['epc_note'] ?? '' ) ) );
		if ( '' !== $note ) {
			EPC_Features::log( $post_id, 'Note: ' . $note );
		}
		// Keep the list title in step with edited names.
		$title = EPC_Form::applicant_title( $data );
		if ( $title && $title !== $post->post_title ) {
			remove_action( 'save_post_' . EPC_Post_Types::APPLICANT, array( __CLASS__, 'save' ), 10 );
			wp_update_post( array( 'ID' => $post_id, 'post_title' => $title ) );
			add_action( 'save_post_' . EPC_Post_Types::APPLICANT, array( __CLASS__, 'save' ), 10, 2 );
		}
	}
}
