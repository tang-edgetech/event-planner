<?php
/**
 * Admin menu, dashboard overview, read-only applicant view, assets.
 */

defined( 'ABSPATH' ) || exit;

class EPC_Admin {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 5 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'post_row_actions', array( __CLASS__, 'applicant_row_actions' ), 20, 2 );
		add_filter( 'acf/prepare_field/key=field_epc_ev_reg_status', array( __CLASS__, 'guard_reg_status' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 80 );
		// Server-side enforcement: keep the stored status if the user may not change it.
		add_filter( 'acf/update_value/key=field_epc_ev_reg_status', function ( $value, $post_id ) {
			// System contexts (WP-CLI, importer, cron) have no user and are trusted.
			if ( ! is_user_logged_in() || current_user_can( 'epc_change_reg_status' ) ) {
				return $value;
			}
			return get_post_meta( (int) $post_id, 'reg_status', true ) ?: 'auto';
		}, 10, 2 );
	}

	public static function menu(): void {
		add_menu_page( 'Event Plus', 'Event Plus', 'epc_view_dashboard', 'epc', array( __CLASS__, 'dashboard' ), 'dashicons-tickets-alt', 3 );
		add_submenu_page( 'epc', 'Overview', 'Overview', 'epc_view_dashboard', 'epc', array( __CLASS__, 'dashboard' ) );
		// Hidden: read-only applicant view (for Viewers).
		add_submenu_page( 'epc', 'Applicant', 'Applicant', 'epc_view_applicants', 'epc-applicant', array( __CLASS__, 'applicant_view' ) );
	}

	public static function assets( string $hook ): void {
		wp_enqueue_style( 'epc-admin', EPC_URL . 'assets/css/admin.css', array(), EPC_VERSION );
	}

	/** Only users with "Open / close registration" may change the manual status. */
	public static function guard_reg_status( $field ) {
		if ( ! current_user_can( 'epc_change_reg_status' ) ) {
			$field['disabled']     = 1;
			$field['instructions'] = 'You do not have permission to change the registration status.';		}
		return $field;
	}

	public static function applicant_row_actions( array $actions, WP_Post $post ): array {
		if ( EPC_Post_Types::APPLICANT === $post->post_type ) {
			unset( $actions['inline hide-if-no-js'], $actions['view'] );
			$actions = array( 'epc_view' => '<a href="' . esc_url( admin_url( 'admin.php?page=epc-applicant&id=' . $post->ID ) ) . '">View</a>' ) + $actions;
		}
		return $actions;
	}

	public static function admin_bar( WP_Admin_Bar $bar ): void {
		if ( is_admin() || ! is_singular( array( EPC_Post_Types::EVENT, EPC_Post_Types::MICROSITE ) ) || ! current_user_can( 'epc_view_reports' ) ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'epc-report',
				'title' => 'Page report',
				'href'  => admin_url( 'admin.php?page=epc-analytics&post_id=' . get_queried_object_id() ),
			)
		);
	}

	public static function applicant_view(): void {
		$id   = absint( $_GET['id'] ?? 0 ); // phpcs:ignore
		$post = get_post( $id );
		if ( ! $post || EPC_Post_Types::APPLICANT !== $post->post_type ) {
			wp_die( 'Applicant not found.' );
		}
		$data   = (array) get_post_meta( $id, '_epc_data', true );
		$labels = (array) get_post_meta( $id, '_epc_labels', true );
		$event  = (int) get_post_meta( $id, '_epc_event_id', true );
		$status = get_post_meta( $id, '_epc_status', true ) ?: 'registered';
		$src    = (array) get_post_meta( $id, '_epc_source', true );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $post->post_title ); ?>
				<span class="epc-badge epc-badge--<?php echo esc_attr( $status ); ?>"><?php echo esc_html( epc_applicant_statuses()[ $status ] ?? $status ); ?></span>
			</h1>
			<p>
				<strong>Event:</strong> <?php echo esc_html( get_the_title( $event ) ); ?> &nbsp;·&nbsp;
				<strong>Registered:</strong> <?php echo esc_html( get_the_date( 'j M Y, g:ia', $post ) ); ?>
				<?php if ( current_user_can( 'edit_post', $id ) ) : ?>
					&nbsp;·&nbsp;<a href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>">Edit</a>
				<?php endif; ?>
			</p>
			<table class="widefat striped" style="max-width:900px">
				<tbody>
				<?php foreach ( $data as $k => $v ) : ?>
					<tr><th style="width:35%"><?php echo esc_html( $labels[ $k ] ?? $k ); ?></th><td><?php echo esc_html( is_array( $v ) ? implode( ', ', $v ) : (string) $v ); ?></td></tr>
				<?php endforeach; ?>
				<?php foreach ( array_filter( $src ) as $k => $v ) : ?>
					<tr><th class="epc-muted">Source: <?php echo esc_html( $k ); ?></th><td class="epc-muted"><?php echo esc_html( $v ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . EPC_Post_Types::APPLICANT . '&epc_event=' . $event ) ); ?>">← Back to applicants</a></p>
		</div>
		<?php
	}

	public static function dashboard(): void {
		global $wpdb;
		$events = get_posts(
			array(
				'post_type'   => EPC_Post_Types::EVENT,
				'numberposts' => 12,
				'post_status' => array( 'publish', 'draft', 'future', 'private' ),
				'meta_key'    => 'event_start',
				'orderby'     => 'meta_value',
				'order'       => 'DESC',
			)
		);
		if ( ! $events ) {
			$events = get_posts( array( 'post_type' => EPC_Post_Types::EVENT, 'numberposts' => 12, 'post_status' => 'any' ) );
		}
		$t      = EPC_Analytics::table();
		$since  = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
		$week   = $wpdb->get_row( $wpdb->prepare( "SELECT SUM(event_type='pageview') v, COUNT(DISTINCT CASE WHEN event_type='pageview' THEN visitor_id END) u, SUM(event_type='submit') s FROM {$t} WHERE created_at >= %s", $since ), ARRAY_A );
		$recent = get_posts( array( 'post_type' => EPC_Post_Types::APPLICANT, 'numberposts' => 8 ) );
		?>
		<div class="wrap epc-dashboard">
			<h1>Event Plus</h1>
			<div class="epc-kpis">
				<div class="epc-kpi"><span>Visits (7 days)</span><strong><?php echo esc_html( number_format_i18n( (int) $week['v'] ) ); ?></strong></div>
				<div class="epc-kpi"><span>Visitors (7 days)</span><strong><?php echo esc_html( number_format_i18n( (int) $week['u'] ) ); ?></strong></div>
				<div class="epc-kpi"><span>Registrations (7 days)</span><strong><?php echo esc_html( number_format_i18n( (int) $week['s'] ) ); ?></strong></div>
				<div class="epc-kpi"><span>Events</span><strong><?php echo (int) wp_count_posts( EPC_Post_Types::EVENT )->publish; ?></strong></div>
			</div>

			<div class="epc-grid-2">
				<div class="epc-card">
					<h2>Events
						<?php if ( current_user_can( 'create_ep_events' ) ) : ?>
							<a class="page-title-action" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . EPC_Post_Types::EVENT ) ); ?>">Add event</a>
						<?php endif; ?>
					</h2>
					<table class="widefat striped">
						<thead><tr><th>Event</th><th>Date</th><th>Registrations</th><th>Status</th></tr></thead>
						<tbody>
						<?php foreach ( $events as $e ) : $s = EPC_Capacity::state( $e->ID ); ?>
							<tr>
								<td><strong><?php echo esc_html( $e->post_title ); ?></strong>
									<div class="row-actions">
										<?php if ( current_user_can( 'edit_post', $e->ID ) ) : ?><a href="<?php echo esc_url( get_edit_post_link( $e->ID ) ); ?>">Edit</a> | <?php endif; ?>
										<a href="<?php echo esc_url( get_permalink( $e->ID ) ); ?>" target="_blank">View</a>
										<?php if ( current_user_can( 'epc_view_applicants' ) ) : ?> | <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . EPC_Post_Types::APPLICANT . '&epc_event=' . $e->ID ) ); ?>">Applicants</a><?php endif; ?>
										<?php if ( current_user_can( 'epc_view_reports' ) ) : ?> | <a href="<?php echo esc_url( admin_url( 'admin.php?page=epc-analytics&post_id=' . $e->ID ) ); ?>">Report</a><?php endif; ?>
									</div>
								</td>
								<td><?php echo esc_html( (string) get_field( 'event_date_text', $e->ID ) ); ?></td>
								<td>
									<?php echo (int) $s['count'] . ( $s['max'] ? ' / ' . (int) $s['max'] : '' ); ?>
									<?php if ( $s['max'] ) : ?><div class="epc-bar"><span style="width:<?php echo (int) min( 100, $s['count'] / $s['max'] * 100 ); ?>%"></span></div><?php endif; ?>
									<?php if ( $s['min'] && $s['count'] < $s['min'] ) : ?><small class="epc-muted">Min <?php echo (int) $s['min']; ?> not reached</small><?php endif; ?>
								</td>
								<td><?php echo EPC_Capacity::badge( $s['state'] ); // phpcs:ignore ?></td>
							</tr>
						<?php endforeach; ?>
						<?php if ( ! $events ) : ?><tr><td colspan="4">No events yet.</td></tr><?php endif; ?>
						</tbody>
					</table>
				</div>
				<?php if ( current_user_can( 'epc_view_applicants' ) ) : ?>
				<div class="epc-card">
					<h2>Latest registrations</h2>
					<table class="widefat striped">
						<tbody>
						<?php foreach ( $recent as $r ) : ?>
							<tr>
								<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=epc-applicant&id=' . $r->ID ) ); ?>"><?php echo esc_html( $r->post_title ); ?></a><br><small class="epc-muted"><?php echo esc_html( get_the_title( (int) get_post_meta( $r->ID, '_epc_event_id', true ) ) ); ?></small></td>
								<td><small><?php echo esc_html( human_time_diff( get_post_timestamp( $r ) ) ); ?> ago</small></td>
							</tr>
						<?php endforeach; ?>
						<?php if ( ! $recent ) : ?><tr><td>No registrations yet.</td></tr><?php endif; ?>
						</tbody>
					</table>
				</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
