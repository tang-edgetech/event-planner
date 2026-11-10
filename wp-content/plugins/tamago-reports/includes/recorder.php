<?php
/**
 * Writes hit rows: column whitelist and length limits, attribution (channel from referrer /
 * UTM, inherited from the session's first hit when a hit has none), goal matching, the
 * `tmg_reports_hit_row` / `tmg_reports_hit` / `tmg_reports_goal_completed` hooks, and
 * server-side events (`tmg_reports_track()`).
 *
 * Used by: TMG_Reports_Collect, tmg_reports_track(), WP-CLI seed, tests.
 */

defined( 'ABSPATH' ) || exit;

class TMG_Reports_Recorder {

	/** Column => max length (strings) or type marker. */
	public static function columns(): array {
		return array(
			'created_at'      => 'datetime',
			'post_id'         => 'int',
			'post_type'       => 20,
			'event_type'      => 20,
			'event_name'      => 64,
			'label'           => 190,
			'goal'            => 64,
			'value'           => 'float',
			'visitor_id'      => 32,
			'session_id'      => 32,
			'is_new'          => 'int',
			'path'            => 255,
			'referrer'        => 255,
			'ref_domain'      => 120,
			'channel'         => 20,
			'utm_source'      => 100,
			'utm_medium'      => 100,
			'utm_campaign'    => 100,
			'utm_term'        => 100,
			'utm_content'     => 100,
			'device'          => 10,
			'browser'         => 30,
			'browser_version' => 20,
			'os'              => 30,
			'os_version'      => 20,
			'screen'          => 15,
			'viewport'        => 15,
			'language'        => 15,
			'timezone'        => 50,
			'country'         => 2,
			'country_name'    => 60,
			'region'          => 80,
			'city'            => 80,
			'ip_hash'         => 32,
			'duration'        => 'int',
			'scroll'          => 'int',
		);
	}

	/** Keep known columns only, cast and length-limit every field. */
	public static function clean( array $row ): array {
		$out = array();
		foreach ( self::columns() as $col => $type ) {
			$v = $row[ $col ] ?? null;
			if ( 'int' === $type ) {
				$out[ $col ] = max( 0, (int) $v );
			} elseif ( 'float' === $type ) {
				$out[ $col ] = round( max( -9999999999999.0, min( 9999999999999.0, (float) $v ) ), 2 );
			} elseif ( 'datetime' === $type ) {
				$out[ $col ] = is_string( $v ) && preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v ) ? $v : current_time( 'mysql', true );
			} else {
				$out[ $col ] = mb_substr( str_replace( array( "\r", "\n", "\t", "\0" ), ' ', (string) $v ), 0, (int) $type );
			}
		}
		$out['duration'] = min( 86400, $out['duration'] );
		$out['scroll']   = min( 100, $out['scroll'] );
		$out['is_new']   = $out['is_new'] ? 1 : 0;
		if ( '' === $out['event_type'] ) {
			$out['event_type'] = 'pageview';
		}
		return $out;
	}

	/**
	 * Insert one hit. Returns the new row ID, or 0 when a filter dropped it.
	 *
	 * @param array $context Passed to the filters (source, request data).
	 */
	public static function insert( array $row, array $context = array() ): int {
		global $wpdb;
		$row = self::clean( $row );

		$goal = null;
		if ( '' === $row['goal'] ) {
			$goal = TMG_Reports_Goals::match( $row );
			if ( $goal ) {
				$row['goal'] = $goal['id'];
				if ( 0.0 === (float) $row['value'] ) {
					$row['value'] = (float) $goal['value'];
				}
			}
		} else {
			$all  = TMG_Reports_Goals::all();
			$goal = $all[ $row['goal'] ] ?? array(
				'id'    => $row['goal'],
				'name'  => $row['goal'],
				'type'  => 'server',
				'match' => $row['event_name'],
				'value' => 0,
			);
		}

		$row = apply_filters( 'tmg_reports_hit_row', $row, $context );
		if ( ! is_array( $row ) ) {
			return 0;
		}
		$row = self::clean( $row );

		$formats = array();
		foreach ( self::columns() as $col => $type ) {
			$formats[] = 'int' === $type ? '%d' : ( 'float' === $type ? '%f' : '%s' );
		}
		if ( ! $wpdb->insert( TMG_Reports_Install::hits_table(), $row, $formats ) ) {
			return 0;
		}
		$id = (int) $wpdb->insert_id;
		do_action( 'tmg_reports_hit', $id, $row, $context );
		if ( '' !== $row['goal'] ) {
			if ( ! $goal || $goal['id'] !== $row['goal'] ) {
				$all  = TMG_Reports_Goals::all();
				$goal = $all[ $row['goal'] ] ?? array( 'id' => $row['goal'], 'name' => $row['goal'] );
			}
			do_action( 'tmg_reports_goal_completed', $goal, $row, $id );
		}
		return $id;
	}

	/**
	 * Fill channel + source fields. A hit without its own external referrer or UTM
	 * inherits attribution from the first hit of its session (or the visitor's latest
	 * session for server events), so internal page views keep the original source.
	 */
	public static function attribute( array $row, bool $by_visitor = false ): array {
		global $wpdb;
		$own = '' !== (string) ( $row['ref_domain'] ?? '' ) || '' !== (string) ( $row['utm_source'] ?? '' ) || '' !== (string) ( $row['utm_medium'] ?? '' ) || '' !== (string) ( $row['utm_campaign'] ?? '' );
		if ( ! $own ) {
			$t    = TMG_Reports_Install::hits_table();
			$cols = 'session_id, referrer, ref_domain, channel, utm_source, utm_medium, utm_campaign, utm_term, utm_content';
			$prev = null;
			if ( '' !== (string) ( $row['session_id'] ?? '' ) ) {
				$prev = $wpdb->get_row( $wpdb->prepare( "SELECT {$cols} FROM {$t} WHERE session_id = %s ORDER BY id ASC LIMIT 1", $row['session_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			} elseif ( $by_visitor && '' !== (string) ( $row['visitor_id'] ?? '' ) ) {
				$last = $wpdb->get_var( $wpdb->prepare( "SELECT session_id FROM {$t} WHERE visitor_id = %s AND session_id <> '' ORDER BY id DESC LIMIT 1", $row['visitor_id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( $last ) {
					$prev = $wpdb->get_row( $wpdb->prepare( "SELECT {$cols} FROM {$t} WHERE session_id = %s ORDER BY id ASC LIMIT 1", $last ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
			}
			if ( $prev ) {
				foreach ( $prev as $k => $v ) {
					if ( 'session_id' === $k ) {
						if ( '' === (string) ( $row['session_id'] ?? '' ) ) {
							$row['session_id'] = $v;
						}
						continue;
					}
					$row[ $k ] = $v;
				}
				return $row;
			}
		}
		$row['channel'] = TMG_Reports_Channels::channel(
			(string) ( $row['ref_domain'] ?? '' ),
			array(
				'source' => (string) ( $row['utm_source'] ?? '' ),
				'medium' => (string) ( $row['utm_medium'] ?? '' ),
			)
		);
		return $row;
	}

	/** Device, browser, OS and location for the current request. */
	public static function request_details( string $ip, string $ua ): array {
		return array_merge( TMG_Reports_UA::parse( $ua ), TMG_Reports_Geo::lookup( $ip ) );
	}

	/**
	 * Server-side event (e.g. a registration saved by the theme).
	 *
	 * @param array $args post_id, label, value, visitor_id, session_id, goal, source (array of
	 *                    ref_domain|referrer|utm_source|utm_medium|utm_campaign|utm_term|utm_content).
	 * @return int New hit ID, or 0 when not recorded.
	 */
	public static function track_server( string $event, array $args = array() ): int {
		global $wpdb;
		$event = mb_substr( sanitize_text_field( $event ), 0, 64 );
		if ( '' === $event ) {
			return 0;
		}
		$post_id = absint( $args['post_id'] ?? 0 );
		$post    = $post_id ? get_post( $post_id ) : null;
		$post_id = $post ? $post_id : 0;
		$ip      = TMG_Reports_Visitor::client_ip();
		$ua      = TMG_Reports_Visitor::user_agent();

		$ctx = array(
			'kind'      => $post ? 'singular' : 'other',
			'post_id'   => $post_id,
			'post_type' => $post ? $post->post_type : '',
			'path'      => '',
			'source'    => 'server',
			'event'     => $event,
		);
		if ( ! TMG_Reports_Targeting::should_track( $ctx ) ) {
			return 0;
		}

		$vid = (string) ( $args['visitor_id'] ?? '' );
		if ( '' === $vid && isset( $_POST['tmg_vid'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- read-only hint from the visitor's own form.
			$vid = sanitize_text_field( wp_unslash( (string) $_POST['tmg_vid'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		$vid = strtolower( preg_replace( '/[^a-fA-F0-9]/', '', $vid ) );
		if ( '' === $vid && 'none' === TMG_Reports_Settings::get( 'consent_mode' ) && ! TMG_Reports_UA::is_bot( $ua ) ) {
			$vid = TMG_Reports_Visitor::daily_id( $ip, $ua );
		}

		$src = (array) ( $args['source'] ?? array() );
		$ref = esc_url_raw( (string) ( $src['referrer'] ?? '' ) );
		$dom = (string) ( $src['ref_domain'] ?? ( $ref ? wp_parse_url( $ref, PHP_URL_HOST ) : '' ) );
		$row = array(
			'post_id'      => $post_id,
			'post_type'    => $post ? $post->post_type : '',
			'event_type'   => 'server',
			'event_name'   => $event,
			'label'        => sanitize_text_field( (string) ( $args['label'] ?? '' ) ),
			'goal'         => sanitize_key( (string) ( $args['goal'] ?? '' ) ),
			'value'        => (float) ( $args['value'] ?? 0 ),
			'visitor_id'   => substr( $vid, 0, 32 ),
			'session_id'   => substr( preg_replace( '/[^a-f0-9]/', '', strtolower( (string) ( $args['session_id'] ?? '' ) ) ), 0, 32 ),
			'path'         => $post ? (string) wp_parse_url( (string) get_permalink( $post ), PHP_URL_PATH ) : '',
			'referrer'     => $ref,
			'ref_domain'   => preg_replace( '/^www\./', '', strtolower( sanitize_text_field( (string) $dom ) ) ),
			'utm_source'   => sanitize_text_field( (string) ( $src['utm_source'] ?? $src['source'] ?? '' ) ),
			'utm_medium'   => sanitize_text_field( (string) ( $src['utm_medium'] ?? $src['medium'] ?? '' ) ),
			'utm_campaign' => sanitize_text_field( (string) ( $src['utm_campaign'] ?? $src['campaign'] ?? '' ) ),
			'utm_term'     => sanitize_text_field( (string) ( $src['utm_term'] ?? $src['term'] ?? '' ) ),
			'utm_content'  => sanitize_text_field( (string) ( $src['utm_content'] ?? $src['content'] ?? '' ) ),
			'ip_hash'      => TMG_Reports_Visitor::ip_hash( $ip ),
		);
		$row = self::attribute( $row, true );

		// Device and location: from the visitor's last page view when known (the call may run
		// in a webhook or cron), otherwise from the current request.
		$prev = null;
		if ( '' !== $row['visitor_id'] ) {
			$prev = $wpdb->get_row( $wpdb->prepare( 'SELECT device, browser, browser_version, os, os_version, screen, viewport, language, timezone, country, country_name, region, city FROM ' . TMG_Reports_Install::hits_table() . " WHERE visitor_id = %s AND event_type = 'pageview' ORDER BY id DESC LIMIT 1", $row['visitor_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$row = array_merge( $row, $prev ? $prev : self::request_details( $ip, $ua ) );

		return self::insert( $row, $ctx );
	}
}
