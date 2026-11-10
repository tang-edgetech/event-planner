<?php
/**
 * Overview report: date range + compare, filter chips, KPI tiles with deltas, time-series
 * chart, real-time mini panel, top-10 tables (with "show all" and CSV), hour × weekday
 * heatmap, and the single-content report (when a post filter is set).
 *
 * Screens: Reports → Overview.
 */

defined( 'ABSPATH' ) || exit;

class TMG_Reports_Report_Screen {

	public static function init(): void {
		add_action( 'wp_ajax_tmg_reports_section', array( __CLASS__, 'ajax_section' ) );
	}

	/** Report state from the request. */
	public static function state(): array {
		// phpcs:disable WordPress.Security.NonceVerification -- read-only report view.
		$range = isset( $_GET['range'] ) ? sanitize_key( wp_unslash( $_GET['range'] ) ) : '30d';
		$range = in_array( $range, TMG_Reports_Query::RANGES, true ) ? $range : '30d';
		$from  = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
		$to    = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';
		$f     = isset( $_GET['f'] ) && is_array( $_GET['f'] ) ? TMG_Reports_Query::clean_filters( wp_unslash( $_GET['f'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$group = isset( $_GET['group'] ) ? sanitize_key( wp_unslash( $_GET['group'] ) ) : 'day';
		$cmp   = ! isset( $_GET['range'] ) || ! empty( $_GET['compare'] );
		// phpcs:enable
		list( $from, $to ) = TMG_Reports_Query::range_dates( $range, $from, $to );
		return array(
			'range'   => $range,
			'from'    => $from,
			'to'      => $to,
			'filters' => $f,
			'group'   => in_array( $group, array( 'day', 'week', 'month' ), true ) ? $group : 'day',
			'compare' => $cmp,
		);
	}

	/** URL args that reproduce a state (with overrides). */
	public static function url_args( array $st, array $over = array() ): array {
		$args = array(
			'range'   => $st['range'],
			'from'    => 'custom' === $st['range'] ? $st['from'] : '',
			'to'      => 'custom' === $st['range'] ? $st['to'] : '',
			'compare' => $st['compare'] ? '1' : '0',
			'group'   => 'day' === $st['group'] ? '' : $st['group'],
			'f'       => $st['filters'],
		);
		return array_merge( $args, $over );
	}

	public static function query_args( array $st ): array {
		return array(
			'range'   => 'custom',
			'from'    => $st['from'],
			'to'      => $st['to'],
			'filters' => $st['filters'],
		);
	}

	public static function sections(): array {
		return array(
			'content'     => __( 'Content', 'tamago-reports' ),
			'acquisition' => __( 'Acquisition', 'tamago-reports' ),
			'campaigns'   => __( 'Campaigns (UTM)', 'tamago-reports' ),
			'engagement'  => __( 'Events and goals', 'tamago-reports' ),
			'technology'  => __( 'Technology', 'tamago-reports' ),
			'location'    => __( 'Location and language', 'tamago-reports' ),
			'visitors'    => __( 'Visitors', 'tamago-reports' ),
		);
	}

	/** Report tables: key => title, dimension, columns, section, options. */
	public static function tables(): array {
		$tv  = array( 'views', 'visitors' );
		$tvc = array( 'views', 'visitors', 'conversions' );
		$t   = array(
			'pages'            => array( __( 'Pages', 'tamago-reports' ), 'page', array( 'views', 'visitors', 'avg_time', 'avg_scroll', 'conversions' ), 'content' ),
			'entries'          => array( __( 'Entry pages', 'tamago-reports' ), 'page', array( 'entries', 'bounce_rate' ), 'content', array( 'order' => 'entries', 'having' => 'entries' ) ),
			'exits'            => array( __( 'Exit pages', 'tamago-reports' ), 'page', array( 'exits' ), 'content', array( 'order' => 'exits', 'having' => 'exits' ) ),
			'post_types'       => array( __( 'Content types', 'tamago-reports' ), 'post_type', $tvc, 'content' ),
			'channels'         => array( __( 'Channels', 'tamago-reports' ), 'channel', array( 'views', 'visitors', 'bounce_rate', 'conversions' ), 'acquisition' ),
			'referrers'        => array( __( 'Referrers', 'tamago-reports' ), 'ref_domain', $tvc, 'acquisition', array( 'skip_empty' => true ) ),
			'utm_source'       => array( __( 'UTM source', 'tamago-reports' ), 'utm_source', $tvc, 'campaigns', array( 'skip_empty' => true ) ),
			'utm_medium'       => array( __( 'UTM medium', 'tamago-reports' ), 'utm_medium', $tvc, 'campaigns', array( 'skip_empty' => true ) ),
			'utm_campaign'     => array( __( 'UTM campaign', 'tamago-reports' ), 'utm_campaign', $tvc, 'campaigns', array( 'skip_empty' => true ) ),
			'utm_term'         => array( __( 'UTM term', 'tamago-reports' ), 'utm_term', $tvc, 'campaigns', array( 'skip_empty' => true ) ),
			'utm_content'      => array( __( 'UTM content', 'tamago-reports' ), 'utm_content', $tvc, 'campaigns', array( 'skip_empty' => true ) ),
			'events'           => array( __( 'Events and clicks', 'tamago-reports' ), 'event', array( 'events', 'visitors' ), 'engagement' ),
			'goals'            => array( __( 'Goals', 'tamago-reports' ), 'goal', array( 'conversions', 'visitors', 'value' ), 'engagement' ),
			'devices'          => array( __( 'Devices', 'tamago-reports' ), 'device', $tv, 'technology' ),
			'browsers'         => array( __( 'Browsers', 'tamago-reports' ), 'browser', $tv, 'technology' ),
			'browser_versions' => array( __( 'Browser versions', 'tamago-reports' ), 'browser_version', $tv, 'technology' ),
			'os'               => array( __( 'Operating systems', 'tamago-reports' ), 'os', $tv, 'technology' ),
			'os_versions'      => array( __( 'OS versions', 'tamago-reports' ), 'os_version', $tv, 'technology' ),
			'screens'          => array( __( 'Screen sizes', 'tamago-reports' ), 'screen', $tv, 'technology', array( 'skip_empty' => true ) ),
			'countries'        => array( __( 'Countries', 'tamago-reports' ), 'country', $tvc, 'location' ),
			'regions'          => array( __( 'Regions', 'tamago-reports' ), 'region', $tv, 'location', array( 'skip_empty' => true ) ),
			'cities'           => array( __( 'Cities', 'tamago-reports' ), 'city', $tv, 'location', array( 'skip_empty' => true ) ),
			'languages'        => array( __( 'Languages', 'tamago-reports' ), 'language', $tv, 'location' ),
			'timezones'        => array( __( 'Time zones', 'tamago-reports' ), 'timezone', $tv, 'location' ),
			'new_returning'    => array( __( 'New vs returning', 'tamago-reports' ), 'new_returning', array( 'views', 'visitors', 'conversions' ), 'visitors' ),
		);
		$out = array();
		foreach ( $t as $key => $def ) {
			$out[ $key ] = array_merge(
				array(
					'title'   => $def[0],
					'dim'     => $def[1],
					'cols'    => $def[2],
					'section' => $def[3],
				),
				$def[4] ?? array()
			);
		}
		return $out;
	}

	public static function table_args( array $def, array $st, int $limit = 10, int $offset = 0 ): array {
		$cols = $def['cols'];
		if ( 'page' === $def['dim'] ) {
			$cols = array_unique( array_merge( $cols, array( 'views' ) ) );
		}
		return array_merge(
			self::query_args( $st ),
			array(
				'dimension'  => $def['dim'],
				'metrics'    => array_unique( array_merge( $cols, array( 'visitors' ) ) ),
				'order'      => $def['order'] ?? '',
				'having'     => $def['having'] ?? '',
				'skip_empty' => ! empty( $def['skip_empty'] ),
				'limit'      => $limit,
				'offset'     => $offset,
			)
		);
	}

	public static function render(): void {
		$st = self::state();
		self::toolbar( $st );
		self::chips( $st );

		$table = isset( $_GET['table'] ) ? sanitize_key( wp_unslash( $_GET['table'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' !== $table && isset( self::tables()[ $table ] ) ) {
			self::full_table( $table, $st );
			return;
		}
		if ( ! empty( $st['filters']['post'] ) ) {
			self::single_header( (int) $st['filters']['post'], $st );
		}
		self::kpis( $st );
		self::chart( $st );
		self::all_tables( $st );
		self::footer_note();
	}

	private static function toolbar( array $st ): void {
		$ranges = array(
			'today'      => __( 'Today', 'tamago-reports' ),
			'yesterday'  => __( 'Yesterday', 'tamago-reports' ),
			'7d'         => __( 'Last 7 days', 'tamago-reports' ),
			'30d'        => __( 'Last 30 days', 'tamago-reports' ),
			'90d'        => __( 'Last 90 days', 'tamago-reports' ),
			'this_month' => __( 'This month', 'tamago-reports' ),
			'last_month' => __( 'Last month', 'tamago-reports' ),
			'this_year'  => __( 'This year', 'tamago-reports' ),
			'custom'     => __( 'Custom…', 'tamago-reports' ),
		);
		?>
		<form method="get" class="tmg-toolbar" id="tmg-toolbar">
			<input type="hidden" name="page" value="<?php echo esc_attr( TMG_Reports_Admin_Screen::SLUG ); ?>">
			<?php foreach ( $st['filters'] as $k => $v ) : ?>
				<input type="hidden" name="f[<?php echo esc_attr( $k ); ?>]" value="<?php echo esc_attr( $v ); ?>">
			<?php endforeach; ?>
			<?php if ( 'day' !== $st['group'] ) : ?>
				<input type="hidden" name="group" value="<?php echo esc_attr( $st['group'] ); ?>">
			<?php endif; ?>
			<label class="screen-reader-text" for="tmg-range"><?php esc_html_e( 'Date range', 'tamago-reports' ); ?></label>
			<select name="range" id="tmg-range">
				<?php foreach ( $ranges as $k => $label ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $st['range'], $k ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<span class="tmg-custom-dates" <?php echo 'custom' === $st['range'] ? '' : 'hidden'; ?>>
				<input type="date" name="from" value="<?php echo esc_attr( $st['from'] ); ?>" aria-label="<?php esc_attr_e( 'From', 'tamago-reports' ); ?>">
				<input type="date" name="to" value="<?php echo esc_attr( $st['to'] ); ?>" aria-label="<?php esc_attr_e( 'To', 'tamago-reports' ); ?>">
			</span>
			<input type="hidden" name="compare" value="0">
			<label><input type="checkbox" name="compare" value="1" <?php checked( $st['compare'] ); ?>> <?php esc_html_e( 'Compare with previous period', 'tamago-reports' ); ?></label>
			<button class="button"><?php esc_html_e( 'Apply', 'tamago-reports' ); ?></button>
			<span class="tmg-range-label"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $st['from'] . ' 12:00:00' ) ) . ' – ' . date_i18n( get_option( 'date_format' ), strtotime( $st['to'] . ' 12:00:00' ) ) ); ?></span>
			<?php if ( current_user_can( TMG_Reports_Permissions::EXPORT ) ) : ?>
				<a class="button tmg-export-raw" href="<?php echo esc_url( TMG_Reports_Export::url( 'raw', $st ) ); ?>"><?php esc_html_e( 'Export raw hits (CSV)', 'tamago-reports' ); ?></a>
			<?php endif; ?>
		</form>
		<?php
	}

	public static function filter_labels(): array {
		return array(
			'post_type'    => __( 'Content type', 'tamago-reports' ),
			'post'         => __( 'Content', 'tamago-reports' ),
			'path'         => __( 'Path', 'tamago-reports' ),
			'channel'      => __( 'Channel', 'tamago-reports' ),
			'ref_domain'   => __( 'Referrer', 'tamago-reports' ),
			'utm_source'   => __( 'UTM source', 'tamago-reports' ),
			'utm_medium'   => __( 'UTM medium', 'tamago-reports' ),
			'utm_campaign' => __( 'UTM campaign', 'tamago-reports' ),
			'utm_term'     => __( 'UTM term', 'tamago-reports' ),
			'utm_content'  => __( 'UTM content', 'tamago-reports' ),
			'device'       => __( 'Device', 'tamago-reports' ),
			'browser'      => __( 'Browser', 'tamago-reports' ),
			'os'           => __( 'OS', 'tamago-reports' ),
			'country'      => __( 'Country', 'tamago-reports' ),
			'region'       => __( 'Region', 'tamago-reports' ),
			'city'         => __( 'City', 'tamago-reports' ),
			'language'     => __( 'Language', 'tamago-reports' ),
			'goal'         => __( 'Completed goal', 'tamago-reports' ),
		);
	}

	private static function chip_value( string $key, string $val ): string {
		$dim = array(
			'post'      => 'page',
			'post_type' => 'post_type',
			'channel'   => 'channel',
			'country'   => 'country',
			'region'    => 'region',
			'city'      => 'city',
			'goal'      => 'goal',
			'device'    => 'device',
		);
		return isset( $dim[ $key ] ) ? TMG_Reports_Labels::label( $dim[ $key ], $val ) : $val;
	}

	private static function chips( array $st ): void {
		$labels = self::filter_labels();
		echo '<div class="tmg-chips">';
		foreach ( $st['filters'] as $k => $v ) {
			$rest = $st['filters'];
			unset( $rest[ $k ] );
			printf(
				'<span class="tmg-chip"><b>%s:</b> %s <a href="%s" class="tmg-chip-x" aria-label="%s">×</a></span>',
				esc_html( $labels[ $k ] ?? $k ),
				esc_html( self::chip_value( $k, (string) $v ) ),
				esc_url( TMG_Reports_Admin_Screen::url( self::url_args( $st, array( 'f' => $rest ) ) ) ),
				esc_attr__( 'Remove filter', 'tamago-reports' )
			);
		}
		?>
		<form method="get" class="tmg-add-filter">
			<input type="hidden" name="page" value="<?php echo esc_attr( TMG_Reports_Admin_Screen::SLUG ); ?>">
			<?php
			foreach ( self::url_args( $st ) as $k => $v ) {
				if ( 'f' === $k ) {
					foreach ( $v as $fk => $fv ) {
						printf( '<input type="hidden" name="f[%s]" value="%s">', esc_attr( $fk ), esc_attr( $fv ) );
					}
				} elseif ( '' !== $v ) {
					printf( '<input type="hidden" name="%s" value="%s">', esc_attr( $k ), esc_attr( $v ) );
				}
			}
			?>
			<select id="tmg-filter-key" aria-label="<?php esc_attr_e( 'Filter by', 'tamago-reports' ); ?>">
				<option value=""><?php esc_html_e( 'Add filter…', 'tamago-reports' ); ?></option>
				<?php foreach ( $labels as $k => $label ) : ?>
					<?php
					if ( isset( $st['filters'][ $k ] ) ) {
						continue;
					}
					?>
					<option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="text" id="tmg-filter-value" placeholder="<?php esc_attr_e( 'Value (e.g. social, mobile, MY, post ID)', 'tamago-reports' ); ?>" hidden>
			<button class="button" hidden id="tmg-filter-add"><?php esc_html_e( 'Add', 'tamago-reports' ); ?></button>
		</form>
		<?php
		echo '</div>';
	}

	private static function single_header( int $post_id, array $st ): void {
		$post = get_post( $post_id );
		$rest = $st['filters'];
		unset( $rest['post'] );
		echo '<div class="tmg-single">';
		echo '<h2>' . esc_html( $post ? get_the_title( $post ) : '#' . $post_id ) . '</h2>';
		if ( $post ) {
			echo '<p>' . esc_html( TMG_Reports_Labels::post_type( $post->post_type ) ) . ' · ';
			printf( '<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url( (string) get_permalink( $post ) ), esc_html__( 'View', 'tamago-reports' ) );
			if ( current_user_can( 'edit_post', $post_id ) ) {
				printf( ' · <a href="%s">%s</a>', esc_url( (string) get_edit_post_link( $post_id ) ), esc_html__( 'Edit', 'tamago-reports' ) );
			}
			echo ' · <a href="' . esc_url( TMG_Reports_Admin_Screen::url( self::url_args( $st, array( 'f' => $rest ) ) ) ) . '">' . esc_html__( '← All content', 'tamago-reports' ) . '</a></p>';
		}
		echo '</div>';
	}

	private static function kpis( array $st ): void {
		$now    = TMG_Reports_Query::totals( self::query_args( $st ) );
		$before = array();
		if ( $st['compare'] ) {
			list( $pf, $pt ) = TMG_Reports_Query::previous( $st['from'], $st['to'] );
			$before          = TMG_Reports_Query::totals( array_merge( self::query_args( $st ), array( 'from' => $pf, 'to' => $pt ) ) );
		}
		$labels = TMG_Reports_UI::metric_labels();
		echo '<div class="tmg-kpis">';
		foreach ( array( 'visitors', 'new_visitors', 'sessions', 'views', 'views_per_session', 'avg_time', 'bounce_rate', 'events', 'conversions', 'conversion_rate' ) as $m ) {
			echo '<div class="tmg-kpi"><span class="tmg-kpi-label">' . esc_html( $labels[ $m ] ) . '</span>';
			echo '<strong>' . esc_html( TMG_Reports_UI::format( $m, $now[ $m ] ) ) . '</strong>';
			if ( $before ) {
				echo TMG_Reports_UI::delta( $m, (float) $now[ $m ], (float) $before[ $m ] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in delta().
			}
			echo '</div>';
		}
		echo '</div>';
	}

	private static function chart( array $st ): void {
		$series = TMG_Reports_Query::series( self::query_args( $st ), $st['group'] );
		echo '<div class="tmg-row">';
		echo '<div class="tmg-card tmg-card-chart"><div class="tmg-card-head"><h2>' . esc_html__( 'Page views and visitors', 'tamago-reports' ) . '</h2><span class="tmg-card-actions">';
		foreach ( array( 'day' => __( 'Day', 'tamago-reports' ), 'week' => __( 'Week', 'tamago-reports' ), 'month' => __( 'Month', 'tamago-reports' ) ) as $g => $label ) {
			$cls = $g === $st['group'] ? 'tmg-seg is-active' : 'tmg-seg';
			printf( '<a class="%s" href="%s">%s</a>', esc_attr( $cls ), esc_url( TMG_Reports_Admin_Screen::url( self::url_args( $st, array( 'group' => 'day' === $g ? '' : $g ) ) ) ), esc_html( $label ) );
		}
		echo '</span></div>';
		echo '<div class="tmg-chart" data-series="' . esc_attr( (string) wp_json_encode( $series ) ) . '"><noscript>' . esc_html__( 'The chart needs JavaScript.', 'tamago-reports' ) . '</noscript></div>';
		echo '</div>';
		echo '<div class="tmg-card tmg-card-rt"><div class="tmg-card-head"><h2>' . esc_html__( 'Right now', 'tamago-reports' ) . '</h2><span class="tmg-card-actions"><a href="' . esc_url( TMG_Reports_Admin_Screen::url( array( 'tab' => 'realtime' ) ) ) . '">' . esc_html__( 'Details', 'tamago-reports' ) . '</a></span></div>';
		TMG_Reports_Realtime::panel( 5 );
		echo '</div></div>';
	}

	/**
	 * True when tables must be computed from many days of raw hits (filters set, or summaries
	 * not built). Those sections are then loaded in parallel after the page renders.
	 */
	public static function is_heavy( array $st ): bool {
		$plan = TMG_Reports_Query::plan( TMG_Reports_Query::normalize( self::query_args( $st ) ) );
		foreach ( $plan as $part ) {
			if ( 'raw' === $part[0] && TMG_Reports_Query::days_between( $part[1], $part[2] ) >= 3 ) {
				return true;
			}
		}
		return false;
	}

	private static function all_tables( array $st ): void {
		$lazy  = self::is_heavy( $st );
		$nonce = wp_create_nonce( 'tmg_reports_section' );
		foreach ( self::sections() as $section => $title ) {
			echo '<h2 class="tmg-section">' . esc_html( $title ) . '</h2>';
			if ( $lazy ) {
				$url = admin_url( 'admin-ajax.php?' . http_build_query( array_merge( array_filter( self::url_args( $st ), fn( $v ) => '' !== $v && array() !== $v ), array( 'action' => 'tmg_reports_section', 'section' => $section, '_ajax_nonce' => $nonce ) ), '', '&', PHP_QUERY_RFC3986 ) );
				echo '<div class="tmg-grid" data-tmg-section="' . esc_url( $url ) . '"><p class="tmg-loading">' . esc_html__( 'Loading…', 'tamago-reports' ) . '</p></div>';
				continue;
			}
			echo '<div class="tmg-grid">';
			self::section_cards( $section, $st );
			echo '</div>';
		}
	}

	public static function section_cards( string $section, array $st ): void {
		foreach ( self::tables() as $key => $def ) {
			if ( $def['section'] !== $section || ( 'pages' === $key && ! empty( $st['filters']['post'] ) ) ) {
				continue;
			}
			self::card( $key, $def, TMG_Reports_Query::table( self::table_args( $def, $st ) ), $st );
		}
		if ( 'visitors' === $section ) {
			self::heatmap( $st );
		}
	}

	/** AJAX: one section's cards as HTML (nonce + view capability). */
	public static function ajax_section(): void {
		check_ajax_referer( 'tmg_reports_section' );
		if ( ! current_user_can( TMG_Reports_Permissions::VIEW ) ) {
			wp_send_json_error( null, 403 );
		}
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
		if ( ! isset( self::sections()[ $section ] ) ) {
			wp_send_json_error( null, 400 );
		}
		ob_start();
		self::section_cards( $section, self::state() );
		wp_send_json_success( array( 'html' => ob_get_clean() ) );
	}

	/** Link a row to a filter (pages open the single-content report). */
	private static function row_url( array $def, string $key, array $st ): string {
		$filter = TMG_Reports_Query::dims()[ $def['dim'] ]['filter'] ?? '';
		if ( '' === $filter ) {
			return '';
		}
		if ( 'page' === $def['dim'] ) {
			$filter = ctype_digit( $key ) ? 'post' : 'path';
		}
		$f            = $st['filters'];
		$f[ $filter ] = $key;
		return TMG_Reports_Admin_Screen::url( self::url_args( $st, array( 'f' => $f ) ) );
	}

	private static function card( string $key, array $def, array $res, array $st ): void {
		$labels = TMG_Reports_UI::metric_labels();
		echo '<div class="tmg-card"><div class="tmg-card-head"><h3>' . esc_html( $def['title'] ) . '</h3><span class="tmg-card-actions">';
		if ( $res['total'] > count( $res['rows'] ) ) {
			printf( '<a href="%s">%s</a>', esc_url( TMG_Reports_Admin_Screen::url( self::url_args( $st, array( 'table' => $key ) ) ) ), esc_html( sprintf( /* translators: %s: number of rows */ __( 'Show all (%s)', 'tamago-reports' ), number_format_i18n( $res['total'] ) ) ) );
		}
		if ( $res['rows'] && current_user_can( TMG_Reports_Permissions::EXPORT ) ) {
			printf( ' <a href="%s">CSV</a>', esc_url( TMG_Reports_Export::url( 'table', $st, $key ) ) );
		}
		echo '</span></div>';
		self::rows_table( $def, $res['rows'], $st, $labels );
		echo '</div>';
	}

	private static function rows_table( array $def, array $rows, array $st, array $labels ): void {
		if ( ! $rows ) {
			echo '<p class="tmg-empty">' . esc_html__( 'No data for this period.', 'tamago-reports' ) . '</p>';
			return;
		}
		$main = $def['cols'][0];
		$max  = max( 1, max( array_map( fn( $r ) => (float) $r[ $main ], $rows ) ) );
		echo '<table class="widefat striped tmg-table"><thead><tr><th scope="col">' . esc_html__( 'Name', 'tamago-reports' ) . '</th>';
		foreach ( $def['cols'] as $c ) {
			echo '<th scope="col" class="num">' . esc_html( $labels[ $c ] ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$url = self::row_url( $def, (string) $r['key'], $st );
			$pct = round( (float) $r[ $main ] / $max * 100 );
			echo '<tr><td class="tmg-name"><span class="tmg-bar" style="width:' . (int) $pct . '%"></span>';
			echo $url ? '<a href="' . esc_url( $url ) . '">' . esc_html( $r['label'] ) . '</a>' : '<span>' . esc_html( $r['label'] ) . '</span>';
			if ( 'page' === $def['dim'] ) {
				// Numeric keys are post IDs; others are stored URL paths (already include any sub-folder).
				$home = wp_parse_url( home_url() );
				$link = ctype_digit( (string) $r['key'] ) ? get_permalink( (int) $r['key'] ) : ( $home['scheme'] ?? 'https' ) . '://' . ( $home['host'] ?? '' ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' ) . $r['key'];
				if ( $link ) {
					echo ' <a class="tmg-ext" href="' . esc_url( $link ) . '" target="_blank" rel="noopener" aria-label="' . esc_attr__( 'Open page', 'tamago-reports' ) . '">↗</a>';
				}
			}
			echo '</td>';
			foreach ( $def['cols'] as $c ) {
				echo '<td class="num">' . esc_html( TMG_Reports_UI::format( $c, $r[ $c ] ) ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private static function full_table( string $key, array $st ): void {
		$def    = self::tables()[ $key ];
		$per    = 50;
		$paged  = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ); // phpcs:ignore WordPress.Security.NonceVerification
		$res    = TMG_Reports_Query::table( self::table_args( $def, $st, $per, ( $paged - 1 ) * $per ) );
		$pages  = max( 1, (int) ceil( $res['total'] / $per ) );
		echo '<p><a href="' . esc_url( TMG_Reports_Admin_Screen::url( self::url_args( $st ) ) ) . '">' . esc_html__( '← Back to overview', 'tamago-reports' ) . '</a></p>';
		echo '<div class="tmg-card tmg-card-full"><div class="tmg-card-head"><h2>' . esc_html( $def['title'] ) . '</h2><span class="tmg-card-actions">';
		if ( current_user_can( TMG_Reports_Permissions::EXPORT ) ) {
			printf( '<a class="button" href="%s">%s</a>', esc_url( TMG_Reports_Export::url( 'table', $st, $key ) ), esc_html__( 'Export CSV', 'tamago-reports' ) );
		}
		echo '</span></div>';
		self::rows_table( $def, $res['rows'], $st, TMG_Reports_UI::metric_labels() );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				(string) paginate_links(
					array(
						'base'    => TMG_Reports_Admin_Screen::url( self::url_args( $st, array( 'table' => $key ) ) ) . '%_%',
						'format'  => '&paged=%#%',
						'current' => $paged,
						'total'   => $pages,
					)
				)
			);
			echo '</div></div>';
		}
		echo '</div>';
	}

	private static function heatmap( array $st ): void {
		$res  = TMG_Reports_Query::table( array_merge( self::query_args( $st ), array( 'dimension' => 'hour_weekday', 'limit' => 0, 'metrics' => array( 'views' ) ) ) );
		$grid = array_fill( 0, 7, array_fill( 0, 24, 0 ) );
		foreach ( $res['rows'] as $r ) {
			$p = explode( '|', (string) $r['key'] );
			if ( 2 === count( $p ) ) {
				$grid[ (int) $p[0] ][ (int) $p[1] ] += (int) $r['views'];
			}
		}
		$max  = max( 1, max( array_map( 'max', $grid ) ) );
		$days = array( __( 'Mon', 'tamago-reports' ), __( 'Tue', 'tamago-reports' ), __( 'Wed', 'tamago-reports' ), __( 'Thu', 'tamago-reports' ), __( 'Fri', 'tamago-reports' ), __( 'Sat', 'tamago-reports' ), __( 'Sun', 'tamago-reports' ) );
		echo '<div class="tmg-card tmg-card-wide"><div class="tmg-card-head"><h3>' . esc_html__( 'Page views by hour and weekday', 'tamago-reports' ) . '</h3></div>';
		echo '<div class="tmg-heat" role="table" aria-label="' . esc_attr__( 'Page views by hour and weekday', 'tamago-reports' ) . '">';
		echo '<div class="tmg-heat-row" role="row"><span role="columnheader"></span>';
		for ( $h = 0; $h < 24; $h++ ) {
			echo '<span class="tmg-heat-h" role="columnheader">' . ( 0 === $h % 3 ? (int) $h : '' ) . '</span>';
		}
		echo '</div>';
		foreach ( $grid as $d => $hours ) {
			echo '<div class="tmg-heat-row" role="row"><span class="tmg-heat-d" role="rowheader">' . esc_html( $days[ $d ] ) . '</span>';
			foreach ( $hours as $h => $v ) {
				$a = $v ? 0.12 + 0.88 * $v / $max : 0;
				printf( '<span role="cell" class="tmg-heat-c" style="--a:%s" title="%s"></span>', esc_attr( (string) round( $a, 2 ) ), esc_attr( sprintf( '%s %02d:00 — %s', $days[ $d ], $h, number_format_i18n( $v ) ) ) );
			}
			echo '</div>';
		}
		echo '</div></div>';
	}

	private static function footer_note(): void {
		$geo = array(
			'none'    => __( 'off', 'tamago-reports' ),
			'headers' => __( 'CDN / proxy headers', 'tamago-reports' ),
			'ipapi'   => 'ipapi.co',
			'ipinfo'  => 'ipinfo.io',
		);
		$until = TMG_Reports_Summary::until();
		echo '<p class="description tmg-note">';
		echo esc_html(
			sprintf(
				/* translators: 1: geo provider, 2: date */
				__( 'Location source: %1$s. Raw IP addresses are never stored. Days up to %2$s are read from daily summaries (visitor counts in tables are summed per day there); the visitors tile is always exact.', 'tamago-reports' ),
				$geo[ (string) TMG_Reports_Settings::get( 'geo_provider' ) ] ?? '',
				$until ? $until : '—'
			)
		);
		echo '</p>';
	}
}
