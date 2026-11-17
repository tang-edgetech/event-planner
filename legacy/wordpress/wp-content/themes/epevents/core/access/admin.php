<?php
/**
 * Event Plus admin: the Event Plus menu and Overview screen, the read-only applicant view, admin styles,
 * the Duplicate / Applicants / Report links on the Events and Microsites lists, and where staff land
 * after logging in (login_redirect()).
 * Screens: Event Plus → Overview, Events, Microsites; the login page.
 */

defined( 'ABSPATH' ) || exit;

class EPC_Admin {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 5 );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 60, 3 ); // after Tamago Roles (50)
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

	/**
	 * Where each role lands after logging in: role => destination. A wp-admin page ("admin.php?page=epc",
	 * "edit.php?post_type=ep_event"), a site path ("/events/") or a full URL on this site.
	 * Change it in code with the `epc_login_redirects` filter; roles not listed keep WordPress's default
	 * (Dashboard, or Profile for users who cannot edit posts).
	 */
	public static function login_redirects(): array {
		$overview = 'admin.php?page=epc';
		return (array) apply_filters(
			'epc_login_redirects',
			array(
				'ep_super_admin' => $overview,
				'ep_manager'     => $overview,
				'ep_viewer'      => $overview,
			)
		);
	}

	/**
	 * Sends staff to their page after login instead of WordPress's Dashboard / Profile. Only when the
	 * user did not ask for a specific page, and only when Tamago Roles has no "After login go to" rule
	 * for them (its rule runs first and wins).
	 */
	public static function login_redirect( $redirect, $requested, $user ) {
		if ( ! $user instanceof WP_User ) {
			return $redirect;
		}
		$generic = array( '', admin_url(), admin_url( '/' ), admin_url( 'index.php' ), home_url( '/wp-admin/' ) );
		if ( ! in_array( (string) $requested, $generic, true ) || ! in_array( (string) $redirect, $generic, true ) ) {
			return $redirect; // A page was asked for, or Tamago Roles already chose one.
		}
		$map = self::login_redirects();
		foreach ( (array) $user->roles as $role ) {
			if ( empty( $map[ $role ] ) ) {
				continue;
			}
			$to = (string) $map[ $role ];
			if ( preg_match( '#^https?://#i', $to ) ) {
				return wp_validate_redirect( $to, $redirect );
			}
			return 0 === strpos( $to, '/' ) ? home_url( $to ) : admin_url( $to );
		}
		return $redirect;
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
		if ( is_admin() || ! is_singular( array( EPC_Post_Types::EVENT, EPC_Post_Types::MICROSITE ) ) || ! epc_report_url( get_queried_object_id() ) ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'epc-report',
				'title' => 'Page report',
				'href'  => epc_report_url( get_queried_object_id() ),
			)
		);
	}

	public static function applicant_view(): void {
		$id   = absint( $_GET['id'] ?? 0 ); // phpcs:ignore
		$post = get_post( $id );
		if ( ! $post || EPC_Post_Types::APPLICANT !== $post->post_type ) {
			wp_die( 'Applicant not found.', 'Not found', array( 'response' => 404, 'back_link' => true ) );
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
				<?php
				$mask  = EPC_Features::mask_personal_data();
				$types = $mask ? EPC_Features::field_types( $event ) : array();
				foreach ( $data as $k => $v ) :
					$v = is_array( $v ) ? implode( ', ', $v ) : (string) $v;
					if ( $mask ) {
						$v = EPC_Features::mask( $v, $types[ $k ] ?? '' );
					}
					?>
					<tr><th style="width:35%"><?php echo esc_html( $labels[ $k ] ?? $k ); ?></th><td><?php echo esc_html( $v ); ?></td></tr>
				<?php endforeach; ?>
				<?php foreach ( array_filter( $src ) as $k => $v ) : ?>
					<tr><th class="epc-muted">Source: <?php echo esc_html( $k ); ?></th><td class="epc-muted"><?php echo esc_html( $v ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<h2>Activity</h2>
			<div class="epc-card" style="max-width:900px"><?php echo EPC_Applicants_Admin::log_html( $id ); // phpcs:ignore -- escaped. ?></div>
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
		// Registrations come from the theme's own applicants; visits only when Tamago Reports is active.
		$reports = epc_plugin( 'reports' ) && function_exists( 'tmg_reports_query' );
		$stats   = $reports ? tmg_reports_query( array( 'range' => '7d', 'metrics' => array( 'views', 'visitors' ) ) ) : array();
		$week    = array(
			'v' => $stats['views'] ?? 0,
			'u' => $stats['visitors'] ?? 0,
			's' => count( get_posts( array( 'post_type' => EPC_Post_Types::APPLICANT, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'date_query' => array( array( 'after' => '7 days ago' ) ) ) ) ),
		);
		$recent = get_posts( array( 'post_type' => EPC_Post_Types::APPLICANT, 'numberposts' => 8 ) );
		?>
		<div class="wrap epc-dashboard">
			<h1>Event Plus</h1>
			<?php $missing = current_user_can( 'activate_plugins' ) ? epc_missing_helpers() : array(); ?>
			<?php if ( $missing ) : ?>
				<p class="description">Optional helper plugins not active: <?php echo esc_html( implode( ' · ', $missing ) ); ?>. The site works without them.</p>
			<?php endif; ?>
			<div class="epc-kpis">
				<?php if ( $reports ) : ?>
				<div class="epc-kpi"><span>Visits (7 days)</span><strong><?php echo esc_html( number_format_i18n( (int) $week['v'] ) ); ?></strong></div>
				<div class="epc-kpi"><span>Visitors (7 days)</span><strong><?php echo esc_html( number_format_i18n( (int) $week['u'] ) ); ?></strong></div>
				<?php endif; ?>
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
					<table class="widefat striped epc-dash-events">
						<thead><tr><th>Event</th><th>Date</th><th>Registrations</th><th>Status</th></tr></thead>
						<tbody>
						<?php foreach ( $events as $e ) : $s = EPC_Capacity::state( $e->ID ); ?>
							<tr>
								<td><strong><?php echo esc_html( $e->post_title ); ?></strong>
									<div class="row-actions">
										<?php if ( current_user_can( 'edit_post', $e->ID ) ) : ?><a href="<?php echo esc_url( get_edit_post_link( $e->ID ) ); ?>">Edit</a> | <?php endif; ?>
										<a href="<?php echo esc_url( get_permalink( $e->ID ) ); ?>" target="_blank">View</a>
										<?php if ( current_user_can( 'epc_view_applicants' ) ) : ?> | <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . EPC_Post_Types::APPLICANT . '&epc_event=' . $e->ID ) ); ?>">Applicants</a><?php endif; ?>
										<?php if ( epc_report_url( $e->ID ) ) : ?> | <a href="<?php echo esc_url( epc_report_url( $e->ID ) ); ?>">Report</a><?php endif; ?>
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

/* -------------------------------------------------------------------------
 * Events, Microsites, Applicants (post types registered by ACF → Post Types)
 * ---------------------------------------------------------------------- */

class EPC_Post_Types {

	const EVENT     = 'ep_event';
	const MICROSITE = 'ep_microsite';
	const APPLICANT = 'ep_applicant';

	public static function init(): void {
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_action( 'admin_action_epc_duplicate', array( __CLASS__, 'duplicate' ) );
	}

	/** "Duplicate" row action for events and microsites — reuse a form or layout quickly. */
	public static function row_actions( array $actions, WP_Post $post ): array {
		if ( in_array( $post->post_type, array( self::EVENT, self::MICROSITE ), true ) && current_user_can( get_post_type_object( $post->post_type )->cap->create_posts ) ) {
			$url                      = wp_nonce_url( admin_url( 'admin.php?action=epc_duplicate&post=' . $post->ID ), 'epc_duplicate_' . $post->ID );
			$actions['epc_duplicate'] = '<a href="' . esc_url( $url ) . '">Duplicate</a>';
		}
		if ( self::EVENT === $post->post_type && current_user_can( 'epc_view_applicants' ) ) {
			$actions['epc_applicants'] = '<a href="' . esc_url( admin_url( 'edit.php?post_type=' . self::APPLICANT . '&epc_event=' . $post->ID ) ) . '">Applicants</a>';
		}
		if ( in_array( $post->post_type, array( self::EVENT, self::MICROSITE ), true ) && epc_report_url( $post->ID ) ) {
			$actions['epc_report'] = '<a href="' . esc_url( epc_report_url( $post->ID ) ) . '">Report</a>';
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
