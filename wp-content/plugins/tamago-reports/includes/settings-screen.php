<?php
/**
 * Settings tab: targeting (content types, include / exclude posts and paths, query strings),
 * visitor exclusions (roles, IPs, bots, DNT, GPC), consent, events, goals, location, data
 * retention, and the Export / Import settings box. Includes the searchable post picker (AJAX).
 *
 * Screens: Reports → Settings.
 */

defined( 'ABSPATH' ) || exit;

class TMG_Reports_Settings_Screen {

	public static function init(): void {
		add_action( 'admin_post_tmg_reports_save_settings', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_tmg_reports_export_settings', array( __CLASS__, 'export' ) );
		add_action( 'admin_post_tmg_reports_import_settings', array( __CLASS__, 'import' ) );
		add_action( 'wp_ajax_tmg_reports_find_posts', array( __CLASS__, 'find_posts' ) );
	}

	private static function back( array $args ): void {
		wp_safe_redirect( TMG_Reports_Admin_Screen::url( array_merge( array( 'tab' => 'settings' ), $args ) ) );
		exit;
	}

	private static function guard( string $nonce ): void {
		if ( ! current_user_can( TMG_Reports_Permissions::MANAGE ) ) {
			wp_die( esc_html__( 'You are not allowed to change report settings.', 'tamago-reports' ), 403 );
		}
		check_admin_referer( $nonce );
	}

	/* ---------------------------------------------------------------- handlers */

	public static function save(): void {
		self::guard( 'tmg_reports_settings' );
		$in = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each key sanitised in TMG_Reports_Settings::sanitize_value().

		$data  = array();
		$bools = array( 'enabled', 'exclude_bots', 'respect_dnt', 'respect_gpc', 'ev_outbound', 'ev_downloads', 'ev_mailto', 'ev_tel', 'ev_attr', 'ev_forms', 'ev_scroll', 'ev_time', 'delete_data' );
		foreach ( $bools as $b ) {
			$data[ $b ] = ! empty( $in[ $b ] );
		}
		$tracked                 = array_map( 'sanitize_key', (array) ( $in['tracked_types'] ?? array() ) );
		$data['untracked_types'] = array_values( array_diff( array_keys( TMG_Reports_Targeting::types() ), $tracked ) );
		foreach ( array( 'include_ids', 'exclude_ids', 'exclude_roles' ) as $k ) {
			$data[ $k ] = (array) ( $in[ $k ] ?? array() );
		}
		foreach ( array( 'include_paths', 'exclude_paths', 'query_strip', 'query_strip_params', 'exclude_ips', 'consent_mode', 'consent_cookie', 'consent_cookie_value', 'download_ext', 'form_selector', 'geo_provider', 'geo_token', 'ip_header', 'rate_limit', 'retention_days' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$data[ $k ] = $in[ $k ];
			}
		}
		$data['goals'] = array_values( (array) ( $in['goals'] ?? array() ) );
		$clear         = ! empty( $in['clear_geo_token'] ) ? array( 'geo_token' ) : array();
		TMG_Reports_Settings::save( $data, $clear );
		self::back( array( 'updated' => '1' ) );
	}

	public static function export(): void {
		self::guard( 'tmg_reports_export_settings' );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=tamago-reports-settings-' . gmdate( 'Y-m-d' ) . '.json' );
		echo wp_json_encode( TMG_Reports_Settings::export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	public static function import(): void {
		self::guard( 'tmg_reports_import_settings' );
		$file = $_FILES['settings_file'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! $file || ! empty( $file['error'] ) || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			self::back( array( 'error' => 'upload' ) );
		}
		$name = sanitize_file_name( (string) $file['name'] );
		if ( 'json' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) || (int) $file['size'] > 200 * KB_IN_BYTES ) {
			self::back( array( 'error' => 'type' ) );
		}
		$raw = (string) file_get_contents( (string) $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		wp_delete_file( (string) $file['tmp_name'] );
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			self::back( array( 'error' => 'json' ) );
		}
		$res = TMG_Reports_Settings::import( $data );
		self::back( is_wp_error( $res ) ? array( 'error' => 'json' ) : array( 'imported' => '1' ) );
	}

	public static function find_posts(): void {
		check_ajax_referer( 'tmg_reports_find_posts' );
		if ( ! current_user_can( TMG_Reports_Permissions::MANAGE ) ) {
			wp_send_json_error( null, 403 );
		}
		$q    = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$args = array(
			'post_type'      => array_values( get_post_types( array( 'public' => true ) ) ),
			'post_status'    => array( 'publish', 'private', 'future', 'draft' ),
			'posts_per_page' => 20,
			'no_found_rows'  => true,
		);
		if ( ctype_digit( $q ) ) {
			$args['post__in'] = array( (int) $q );
		} else {
			$args['s'] = $q;
		}
		$out = array();
		foreach ( get_posts( $args ) as $p ) {
			$out[] = array(
				'id'    => $p->ID,
				'title' => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
				'type'  => TMG_Reports_Labels::post_type( $p->post_type ),
			);
		}
		wp_send_json_success( $out );
	}

	/* ---------------------------------------------------------------- rendering helpers */

	private static function lock( string $key ): string {
		if ( ! TMG_Reports_Settings::locked( $key ) ) {
			return '';
		}
		return '<p class="description tmg-locked">' . esc_html( sprintf( /* translators: %s: constant name */ __( 'Set in wp-config.php (%s); read-only here.', 'tamago-reports' ), TMG_Reports_Settings::constant_name( $key ) ) ) . '</p>';
	}

	private static function dis( string $key ): string {
		return TMG_Reports_Settings::locked( $key ) ? ' disabled' : '';
	}

	private static function checkbox( string $key, string $label, string $help = '' ): void {
		printf(
			'<label class="tmg-check"><input type="checkbox" name="%1$s" value="1"%2$s%3$s> %4$s</label>%5$s%6$s',
			esc_attr( $key ),
			checked( (bool) TMG_Reports_Settings::get( $key ), true, false ),
			esc_attr( self::dis( $key ) ),
			esc_html( $label ),
			$help ? '<span class="description tmg-help">' . esc_html( $help ) . '</span>' : '',
			self::lock( $key ) // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in lock().
		);
	}

	private static function textarea( string $key, $value, string $placeholder = '' ): void {
		printf(
			'<textarea id="%1$s" name="%1$s" rows="4" class="large-text code" placeholder="%2$s"%3$s>%4$s</textarea>%5$s',
			esc_attr( $key ),
			esc_attr( $placeholder ),
			esc_attr( self::dis( $key ) ),
			esc_textarea( is_array( $value ) ? implode( "\n", $value ) : (string) $value ),
			self::lock( $key ) // phpcs:ignore WordPress.Security.EscapeOutput
		);
	}

	private static function picker( string $key ): void {
		$ids = (array) TMG_Reports_Settings::get( $key );
		echo '<div class="tmg-picker" data-name="' . esc_attr( $key ) . '[]">';
		echo '<ul class="tmg-picked">';
		foreach ( $ids as $id ) {
			$post = get_post( (int) $id );
			printf(
				'<li><input type="hidden" name="%1$s[]" value="%2$d"%5$s>%3$s <small>#%2$d · %4$s</small> <button type="button" class="button-link tmg-unpick" aria-label="%6$s">×</button></li>',
				esc_attr( $key ),
				(int) $id,
				esc_html( $post ? get_the_title( $post ) : __( '(deleted)', 'tamago-reports' ) ),
				esc_html( $post ? TMG_Reports_Labels::post_type( $post->post_type ) : '' ),
				esc_attr( self::dis( $key ) ),
				esc_attr__( 'Remove', 'tamago-reports' )
			);
		}
		echo '</ul>';
		if ( ! TMG_Reports_Settings::locked( $key ) ) {
			echo '<input type="search" class="regular-text tmg-pick-search" placeholder="' . esc_attr__( 'Search by title or ID…', 'tamago-reports' ) . '" autocomplete="off"><ul class="tmg-pick-results" hidden></ul>';
		}
		echo self::lock( $key ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</div>';
	}

	public static function render(): void {
		if ( ! current_user_can( TMG_Reports_Permissions::MANAGE ) ) {
			wp_die( esc_html__( 'You are not allowed to change report settings.', 'tamago-reports' ), 403 );
		}
		$s = TMG_Reports_Settings::all();
		// phpcs:disable WordPress.Security.NonceVerification
		if ( isset( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'tamago-reports' ) . '</p></div>';
		}
		if ( isset( $_GET['imported'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings imported.', 'tamago-reports' ) . '</p></div>';
		}
		if ( isset( $_GET['error'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The file could not be imported. Upload a Tamago Reports settings .json file (max 200 KB).', 'tamago-reports' ) . '</p></div>';
		}
		// phpcs:enable
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tmg-settings">
			<input type="hidden" name="action" value="tmg_reports_save_settings">
			<?php wp_nonce_field( 'tmg_reports_settings' ); ?>

			<h2><?php esc_html_e( 'What is tracked', 'tamago-reports' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><?php esc_html_e( 'Tracking', 'tamago-reports' ); ?></th><td><?php self::checkbox( 'enabled', __( 'Collect visitor data', 'tamago-reports' ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Content types', 'tamago-reports' ); ?></th><td>
					<fieldset class="tmg-columns">
					<?php foreach ( TMG_Reports_Targeting::types() as $type => $label ) : ?>
						<label class="tmg-check"><input type="checkbox" name="tracked_types[]" value="<?php echo esc_attr( $type ); ?>" <?php checked( ! in_array( $type, (array) $s['untracked_types'], true ) ); ?><?php echo esc_attr( self::dis( 'untracked_types' ) ); ?>> <?php echo esc_html( $label ); ?></label>
					<?php endforeach; ?>
					</fieldset>
					<?php echo self::lock( 'untracked_types' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Always track these', 'tamago-reports' ); ?></th><td><?php self::picker( 'include_ids' ); ?><p class="description"><?php esc_html_e( 'Tracked even when their content type is off.', 'tamago-reports' ); ?></p></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Never track these', 'tamago-reports' ); ?></th><td><?php self::picker( 'exclude_ids' ); ?></td></tr>
				<tr><th scope="row"><label for="include_paths"><?php esc_html_e( 'Include URL paths', 'tamago-reports' ); ?></label></th><td><?php self::textarea( 'include_paths', $s['include_paths'], "/landing/*\n/promo" ); ?><p class="description"><?php esc_html_e( 'One path per line, relative to the site address. * matches anything. Exclude rules win over include rules, which win over content types.', 'tamago-reports' ); ?></p></td></tr>
				<tr><th scope="row"><label for="exclude_paths"><?php esc_html_e( 'Exclude URL paths', 'tamago-reports' ); ?></label></th><td><?php self::textarea( 'exclude_paths', $s['exclude_paths'], "/checkout/*\n/my-account*" ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Query strings', 'tamago-reports' ); ?></th><td>
					<label class="tmg-check"><input type="radio" name="query_strip" value="all" <?php checked( $s['query_strip'], 'all' ); ?><?php echo esc_attr( self::dis( 'query_strip' ) ); ?>> <?php esc_html_e( 'Store the path only (remove every query string)', 'tamago-reports' ); ?></label>
					<label class="tmg-check"><input type="radio" name="query_strip" value="listed" <?php checked( $s['query_strip'], 'listed' ); ?><?php echo esc_attr( self::dis( 'query_strip' ) ); ?>> <?php esc_html_e( 'Keep query strings, but remove UTM tags and these parameters:', 'tamago-reports' ); ?></label>
					<?php self::textarea( 'query_strip_params', $s['query_strip_params'] ); ?>
					<?php echo self::lock( 'query_strip' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</td></tr>
			</table>

			<h2><?php esc_html_e( 'Who is not tracked', 'tamago-reports' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><?php esc_html_e( 'Logged-in roles', 'tamago-reports' ); ?></th><td>
					<fieldset class="tmg-columns">
					<?php foreach ( wp_roles()->get_names() as $role => $name ) : ?>
						<label class="tmg-check"><input type="checkbox" name="exclude_roles[]" value="<?php echo esc_attr( $role ); ?>" <?php checked( in_array( $role, (array) $s['exclude_roles'], true ) ); ?><?php echo esc_attr( self::dis( 'exclude_roles' ) ); ?>> <?php echo esc_html( translate_user_role( $name ) ); ?></label>
					<?php endforeach; ?>
					</fieldset>
					<?php echo self::lock( 'exclude_roles' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</td></tr>
				<tr><th scope="row"><label for="exclude_ips"><?php esc_html_e( 'IP addresses', 'tamago-reports' ); ?></label></th><td><?php self::textarea( 'exclude_ips', $s['exclude_ips'], "203.0.113.7\n198.51.100.0/24\n2001:db8::/32" ); ?><p class="description"><?php esc_html_e( 'One IP or CIDR range per line (IPv4 and IPv6).', 'tamago-reports' ); ?></p></td></tr>
				<tr><th scope="row"><label for="ip_header"><?php esc_html_e( 'Client IP header', 'tamago-reports' ); ?></label></th><td>
					<input type="text" id="ip_header" name="ip_header" class="regular-text code" value="<?php echo esc_attr( $s['ip_header'] ); ?>" placeholder="REMOTE_ADDR"<?php echo esc_attr( self::dis( 'ip_header' ) ); ?>>
					<p class="description"><?php esc_html_e( 'Only behind a trusted proxy / CDN, e.g. HTTP_CF_CONNECTING_IP. Leave empty to use REMOTE_ADDR.', 'tamago-reports' ); ?></p><?php echo self::lock( 'ip_header' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Bots and privacy signals', 'tamago-reports' ); ?></th><td>
					<?php self::checkbox( 'exclude_bots', __( 'Ignore bots, crawlers, link previews and monitoring tools', 'tamago-reports' ) ); ?><br>
					<?php self::checkbox( 'respect_dnt', __( 'Respect Do Not Track', 'tamago-reports' ) ); ?><br>
					<?php self::checkbox( 'respect_gpc', __( 'Respect Global Privacy Control', 'tamago-reports' ) ); ?>
				</td></tr>
			</table>

			<h2><?php esc_html_e( 'Consent', 'tamago-reports' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><?php esc_html_e( 'Mode', 'tamago-reports' ); ?></th><td>
					<label class="tmg-check"><input type="radio" name="consent_mode" value="none" <?php checked( $s['consent_mode'], 'none' ); ?><?php echo esc_attr( self::dis( 'consent_mode' ) ); ?>> <?php esc_html_e( 'Track without consent (cookieless): nothing is stored in the browser; the visitor ID is a hash that changes every day.', 'tamago-reports' ); ?></label>
					<label class="tmg-check"><input type="radio" name="consent_mode" value="wait" <?php checked( $s['consent_mode'], 'wait' ); ?><?php echo esc_attr( self::dis( 'consent_mode' ) ); ?>> <?php esc_html_e( 'Wait for consent, then keep an anonymous visitor ID in localStorage (returning visitors are recognised).', 'tamago-reports' ); ?></label>
					<p class="description"><?php echo wp_kses_post( __( 'Consent is given by calling <code>window.tamagoReports.consent(true)</code> (and withdrawn with <code>consent(false)</code>) or by the cookie below.', 'tamago-reports' ) ); ?></p>
					<?php echo self::lock( 'consent_mode' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</td></tr>
				<tr><th scope="row"><label for="consent_cookie"><?php esc_html_e( 'Consent cookie', 'tamago-reports' ); ?></label></th><td>
					<input type="text" id="consent_cookie" name="consent_cookie" class="regular-text code" value="<?php echo esc_attr( $s['consent_cookie'] ); ?>" placeholder="cookie_name"<?php echo esc_attr( self::dis( 'consent_cookie' ) ); ?>>
					<input type="text" name="consent_cookie_value" class="regular-text code" value="<?php echo esc_attr( $s['consent_cookie_value'] ); ?>" placeholder="<?php esc_attr_e( 'value contains (optional)', 'tamago-reports' ); ?>" aria-label="<?php esc_attr_e( 'Consent cookie value', 'tamago-reports' ); ?>"<?php echo esc_attr( self::dis( 'consent_cookie_value' ) ); ?>>
					<p class="description"><?php esc_html_e( 'Tracking starts when this cookie exists (and contains the value, if given). Checked on load and every second for 10 minutes.', 'tamago-reports' ); ?></p>
				</td></tr>
			</table>

			<h2><?php esc_html_e( 'Events', 'tamago-reports' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><?php esc_html_e( 'Automatic events', 'tamago-reports' ); ?></th><td>
					<?php self::checkbox( 'ev_outbound', __( 'Outbound link clicks', 'tamago-reports' ) ); ?><br>
					<?php self::checkbox( 'ev_downloads', __( 'File downloads', 'tamago-reports' ) ); ?>
					<input type="text" name="download_ext" class="large-text code" value="<?php echo esc_attr( implode( ', ', (array) $s['download_ext'] ) ); ?>" aria-label="<?php esc_attr_e( 'Download file extensions', 'tamago-reports' ); ?>"<?php echo esc_attr( self::dis( 'download_ext' ) ); ?>><br>
					<?php self::checkbox( 'ev_mailto', __( 'Email (mailto:) link clicks', 'tamago-reports' ) ); ?><br>
					<?php self::checkbox( 'ev_tel', __( 'Phone (tel:) link clicks', 'tamago-reports' ) ); ?><br>
					<?php self::checkbox( 'ev_attr', __( 'Clicks on elements with data-tmg-event="name" (optional data-tmg-label, data-tmg-value)', 'tamago-reports' ) ); ?><br>
					<?php self::checkbox( 'ev_forms', __( 'Form submissions matching this CSS selector:', 'tamago-reports' ) ); ?>
					<input type="text" name="form_selector" class="regular-text code" value="<?php echo esc_attr( $s['form_selector'] ); ?>" aria-label="<?php esc_attr_e( 'Form CSS selector', 'tamago-reports' ); ?>"<?php echo esc_attr( self::dis( 'form_selector' ) ); ?>><br>
					<?php self::checkbox( 'ev_scroll', __( 'Scroll depth', 'tamago-reports' ) ); ?><br>
					<?php self::checkbox( 'ev_time', __( 'Engaged time (time the page was visible)', 'tamago-reports' ) ); ?>
					<p class="description"><?php echo wp_kses_post( __( 'Custom events from JavaScript: <code>window.tamagoReports.track("name", "label", value)</code>. Server-side from PHP: <code>tmg_reports_track( "name", array( … ) )</code>.', 'tamago-reports' ) ); ?></p>
				</td></tr>
			</table>

			<h2><?php esc_html_e( 'Goals', 'tamago-reports' ); ?></h2>
			<p class="description"><?php esc_html_e( 'A goal turns a page visit, an event or a server-side event into a conversion. Page goals match a post ID or a URL path (* wildcard); event goals match the event name (* wildcard; built-in names: outbound, download, mailto, tel, form).', 'tamago-reports' ); ?></p>
			<?php echo self::lock( 'goals' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<table class="widefat tmg-goals" id="tmg-goals">
				<thead><tr><th><?php esc_html_e( 'Name', 'tamago-reports' ); ?></th><th><?php esc_html_e( 'Type', 'tamago-reports' ); ?></th><th><?php esc_html_e( 'Matches', 'tamago-reports' ); ?></th><th><?php esc_html_e( 'Value', 'tamago-reports' ); ?></th><th></th></tr></thead>
				<tbody>
				<?php
				$goals   = (array) $s['goals'];
				$goals[] = array( 'id' => '', 'name' => '', 'type' => 'page', 'match' => '', 'value' => '' );
				foreach ( array_values( $goals ) as $i => $g ) {
					self::goal_row( (string) $i, $g );
				}
				?>
				</tbody>
			</table>
			<template id="tmg-goal-template"><?php self::goal_row( '__i__', array( 'id' => '', 'name' => '', 'type' => 'page', 'match' => '', 'value' => '' ) ); ?></template>
			<p><button type="button" class="button" id="tmg-add-goal"<?php echo esc_attr( self::dis( 'goals' ) ); ?>><?php esc_html_e( 'Add goal', 'tamago-reports' ); ?></button></p>

			<h2><?php esc_html_e( 'Location and data', 'tamago-reports' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><?php esc_html_e( 'Location source', 'tamago-reports' ); ?></th><td>
					<?php
					$providers = array(
						'none'    => __( 'None', 'tamago-reports' ),
						'headers' => __( 'CDN / proxy headers (Cloudflare, CloudFront…) — no outside requests', 'tamago-reports' ),
						'ipapi'   => __( 'ipapi.co lookup (sends visitor IPs to ipapi.co; cached 7 days)', 'tamago-reports' ),
						'ipinfo'  => __( 'ipinfo.io lookup (needs a token; sends visitor IPs to ipinfo.io; cached 7 days)', 'tamago-reports' ),
					);
					foreach ( $providers as $k => $label ) {
						printf( '<label class="tmg-check"><input type="radio" name="geo_provider" value="%s" %s%s> %s</label>', esc_attr( $k ), checked( $s['geo_provider'], $k, false ), esc_attr( self::dis( 'geo_provider' ) ), esc_html( $label ) );
					}
					echo self::lock( 'geo_provider' ); // phpcs:ignore WordPress.Security.EscapeOutput
					?>
				</td></tr>
				<tr><th scope="row"><label for="geo_token"><?php esc_html_e( 'ipinfo.io token', 'tamago-reports' ); ?></label></th><td>
					<input type="password" id="geo_token" name="geo_token" class="regular-text" value="" autocomplete="new-password" placeholder="<?php echo '' !== $s['geo_token'] ? esc_attr__( 'Saved — leave empty to keep', 'tamago-reports' ) : ''; ?>"<?php echo esc_attr( self::dis( 'geo_token' ) ); ?>>
					<?php if ( '' !== $s['geo_token'] && ! TMG_Reports_Settings::locked( 'geo_token' ) ) : ?>
						<label class="tmg-check"><input type="checkbox" name="clear_geo_token" value="1"> <?php esc_html_e( 'Remove the saved token', 'tamago-reports' ); ?></label>
					<?php endif; ?>
					<?php echo self::lock( 'geo_token' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</td></tr>
				<tr><th scope="row"><label for="retention_days"><?php esc_html_e( 'Keep raw hits for', 'tamago-reports' ); ?></label></th><td>
					<input type="number" min="0" max="36500" id="retention_days" name="retention_days" class="small-text" value="<?php echo (int) $s['retention_days']; ?>"<?php echo esc_attr( self::dis( 'retention_days' ) ); ?>> <?php esc_html_e( 'days (0 = forever). Daily summaries contain no personal data and are kept.', 'tamago-reports' ); ?>
					<?php echo self::lock( 'retention_days' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</td></tr>
				<tr><th scope="row"><label for="rate_limit"><?php esc_html_e( 'Rate limit', 'tamago-reports' ); ?></label></th><td>
					<input type="number" min="0" max="100000" id="rate_limit" name="rate_limit" class="small-text" value="<?php echo (int) $s['rate_limit']; ?>"<?php echo esc_attr( self::dis( 'rate_limit' ) ); ?>> <?php esc_html_e( 'hits per IP per 10 minutes (0 = no limit)', 'tamago-reports' ); ?>
					<?php echo self::lock( 'rate_limit' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Uninstall', 'tamago-reports' ); ?></th><td><?php self::checkbox( 'delete_data', __( 'Delete all data when the plugin is deleted', 'tamago-reports' ) ); ?></td></tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<div class="tmg-card tmg-io">
			<h2><?php esc_html_e( 'Export / Import settings', 'tamago-reports' ); ?></h2>
			<p><?php esc_html_e( 'Copy these settings to another site. Secrets (the ipinfo.io token) are never exported.', 'tamago-reports' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tmg-inline">
				<input type="hidden" name="action" value="tmg_reports_export_settings">
				<?php wp_nonce_field( 'tmg_reports_export_settings' ); ?>
				<button class="button"><?php esc_html_e( 'Export settings', 'tamago-reports' ); ?></button>
			</form>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tmg-inline">
				<input type="hidden" name="action" value="tmg_reports_import_settings">
				<?php wp_nonce_field( 'tmg_reports_import_settings' ); ?>
				<input type="file" name="settings_file" accept=".json,application/json" required aria-label="<?php esc_attr_e( 'Settings file', 'tamago-reports' ); ?>">
				<button class="button"><?php esc_html_e( 'Import settings', 'tamago-reports' ); ?></button>
			</form>
		</div>
		<?php
	}

	private static function goal_row( string $i, array $g ): void {
		$types = array(
			'page'   => __( 'Visits a page', 'tamago-reports' ),
			'event'  => __( 'Custom event', 'tamago-reports' ),
			'server' => __( 'Server-side event', 'tamago-reports' ),
		);
		$dis   = self::dis( 'goals' );
		$n     = 'goals[' . $i . ']';
		?>
		<tr>
			<td><input type="hidden" name="<?php echo esc_attr( $n ); ?>[id]" value="<?php echo esc_attr( $g['id'] ); ?>"><input type="text" name="<?php echo esc_attr( $n ); ?>[name]" value="<?php echo esc_attr( $g['name'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. Registration', 'tamago-reports' ); ?>" aria-label="<?php esc_attr_e( 'Goal name', 'tamago-reports' ); ?>"<?php echo esc_attr( $dis ); ?>></td>
			<td><select name="<?php echo esc_attr( $n ); ?>[type]" aria-label="<?php esc_attr_e( 'Goal type', 'tamago-reports' ); ?>"<?php echo esc_attr( $dis ); ?>>
				<?php foreach ( $types as $k => $label ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $g['type'], $k ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select></td>
			<td><input type="text" class="code" name="<?php echo esc_attr( $n ); ?>[match]" value="<?php echo esc_attr( $g['match'] ); ?>" placeholder="/thank-you/ · 42 · signup · registration" aria-label="<?php esc_attr_e( 'Goal match', 'tamago-reports' ); ?>"<?php echo esc_attr( $dis ); ?>></td>
			<td><input type="number" step="0.01" min="0" class="small-text" name="<?php echo esc_attr( $n ); ?>[value]" value="<?php echo esc_attr( (string) $g['value'] ); ?>" aria-label="<?php esc_attr_e( 'Goal value', 'tamago-reports' ); ?>"<?php echo esc_attr( $dis ); ?>></td>
			<td><button type="button" class="button-link tmg-remove-goal"<?php echo esc_attr( $dis ); ?>><?php esc_html_e( 'Remove', 'tamago-reports' ); ?></button></td>
		</tr>
		<?php
	}
}
