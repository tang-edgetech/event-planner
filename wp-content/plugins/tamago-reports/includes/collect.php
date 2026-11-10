<?php
/**
 * REST endpoint `POST /wp-json/tamago-reports/v1/collect` that receives the beacon.
 * Body is JSON sent as text/plain (no CORS pre-flight). Validates and length-limits every
 * field, drops bots / DNT / GPC / excluded IPs, rate-limits per IP hash, ignores unknown
 * post IDs, re-checks targeting, and handles engagement pings (duration, scroll) for a
 * page view through its ID + signed token.
 *
 * Used by: assets/js/track.js, tests.
 */

defined( 'ABSPATH' ) || exit;

class TMG_Reports_Collect {

	const NS    = 'tamago-reports/v1';
	const ROUTE = '/collect';

	const EVENT_TYPES = array( 'pageview', 'outbound', 'download', 'mailto', 'tel', 'click', 'form', 'custom' );

	const KINDS = array( 'singular', 'front', 'blog', 'archive', 'tax', 'author', 'date', 'search', '404', 'other', 'spa' );

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		register_rest_route(
			self::NS,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => '__return_true', // Public beacon; every field is validated below.
			)
		);
	}

	public static function token( int $id ): string {
		return substr( hash_hmac( 'sha256', 'tmg_reports_hit|' . $id, wp_salt( 'nonce' ) ), 0, 20 );
	}

	private static function done( int $status = 204, $data = null ): WP_REST_Response {
		$res = new WP_REST_Response( $data, $status );
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}

	/** @return WP_REST_Response */
	public static function handle( WP_REST_Request $req ) {
		global $wpdb;
		if ( ! TMG_Reports_Settings::get( 'enabled' ) ) {
			return self::done();
		}
		$body = (string) $req->get_body();
		if ( strlen( $body ) > 4096 ) {
			return self::done( 413 );
		}
		$p = json_decode( $body, true );
		if ( ! is_array( $p ) ) {
			$p = (array) $req->get_body_params();
		}
		$type = isset( $p['t'] ) && is_string( $p['t'] ) ? $p['t'] : '';

		if ( 'ping' === $type ) {
			return self::ping( $p );
		}
		if ( ! in_array( $type, self::EVENT_TYPES, true ) ) {
			return self::done( 400 );
		}

		$ua = TMG_Reports_Visitor::user_agent();
		$ip = TMG_Reports_Visitor::client_ip();
		$s  = TMG_Reports_Settings::all();

		if ( $s['exclude_bots'] && TMG_Reports_UA::is_bot( $ua ) ) {
			return self::done();
		}
		if ( ( $s['respect_dnt'] && '1' === ( $_SERVER['HTTP_DNT'] ?? '' ) ) || ( $s['respect_gpc'] && '1' === ( $_SERVER['HTTP_SEC_GPC'] ?? '' ) ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return self::done();
		}

		// The page must belong to this site.
		$url  = esc_url_raw( self::str( $p['u'] ?? '', 2000 ) );
		$home = wp_parse_url( home_url() );
		$host = $url ? strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) : '';
		if ( '' === $host || strtolower( (string) ( $home['host'] ?? '' ) ) !== $host ) {
			return self::done( 400 );
		}

		$ip_hash = TMG_Reports_Visitor::ip_hash( $ip );
		if ( self::rate_limited( $ip_hash, (int) $s['rate_limit'] ) ) {
			return self::done( 429 );
		}

		// What was viewed.
		$kind    = in_array( $p['k'] ?? '', self::KINDS, true ) ? (string) $p['k'] : 'other';
		$post_id = absint( $p['p'] ?? 0 );
		if ( 'spa' === $kind ) {
			$post_id = url_to_postid( $url );
			$kind    = $post_id ? 'singular' : 'other';
		}
		$post = $post_id ? get_post( $post_id ) : null;
		if ( ! $post || ! is_post_publicly_viewable( $post ) ) {
			$post_id = 0;
			$post    = null;
			if ( 'singular' === $kind ) {
				$kind = 'other';
			}
		}
		$url_path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$ctx      = array(
			'kind'      => $kind,
			'post_id'   => $post_id,
			'post_type' => $post ? $post->post_type : '',
			'path'      => '' !== $url_path ? $url_path : '/',
			'source'    => 'collect',
			'ip'        => $ip,
			'type'      => $type,
		);
		if ( ! TMG_Reports_Targeting::should_track( $ctx ) ) {
			return self::done();
		}

		// Referrer: only external hosts count.
		$ref      = esc_url_raw( self::str( $p['r'] ?? '', 1000 ) );
		$ref_host = $ref ? strtolower( (string) wp_parse_url( $ref, PHP_URL_HOST ) ) : '';
		if ( $ref_host === $host ) {
			$ref      = '';
			$ref_host = '';
		}

		// UTM + click IDs from the page URL.
		$query = array();
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$utm = array();
		foreach ( array( 'source', 'medium', 'campaign', 'term', 'content' ) as $k ) {
			$utm[ $k ] = isset( $query[ 'utm_' . $k ] ) && is_string( $query[ 'utm_' . $k ] ) ? sanitize_text_field( $query[ 'utm_' . $k ] ) : '';
		}
		if ( '' === $utm['source'] && isset( $query['gclid'] ) ) {
			$utm['source'] = 'google';
			$utm['medium'] = $utm['medium'] ? $utm['medium'] : 'cpc';
		} elseif ( '' === $utm['source'] && isset( $query['msclkid'] ) ) {
			$utm['source'] = 'bing';
			$utm['medium'] = $utm['medium'] ? $utm['medium'] : 'cpc';
		} elseif ( '' === $utm['source'] && isset( $query['fbclid'] ) && '' === $ref_host ) {
			$utm['source'] = 'facebook';
			$utm['medium'] = $utm['medium'] ? $utm['medium'] : 'social';
		}

		// Visitor + session.
		$is_new = 0;
		if ( 'wait' === $s['consent_mode'] ) {
			$vid    = self::hex( $p['v'] ?? '' );
			$sid    = self::hex( $p['s'] ?? '' );
			$is_new = empty( $p['n'] ) ? 0 : 1;
			if ( '' === $vid ) {
				return self::done(); // No consent → nothing is recorded.
			}
			if ( '' === $sid ) {
				$sid = substr( md5( $vid . gmdate( 'Y-m-d' ) ), 0, 32 );
			}
		} else {
			$vid  = TMG_Reports_Visitor::daily_id( $ip, $ua );
			$last = $wpdb->get_row( $wpdb->prepare( 'SELECT session_id, created_at FROM ' . TMG_Reports_Install::hits_table() . ' WHERE visitor_id = %s ORDER BY id DESC LIMIT 1', $vid ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( ! $last ) {
				$is_new = 1;
				$sid    = TMG_Reports_Visitor::random_id();
			} elseif ( strtotime( $last['created_at'] . ' UTC' ) >= time() - 30 * MINUTE_IN_SECONDS && '' !== $last['session_id'] ) {
				$sid = $last['session_id'];
			} else {
				$sid = TMG_Reports_Visitor::random_id();
			}
		}

		$details = TMG_Reports_Recorder::request_details( $ip, $ua );
		if ( 'desktop' === $details['device'] && ! empty( $p['tc'] ) && 'macOS' === $details['os'] ) {
			// iPadOS reports itself as a Mac; touch support gives it away.
			$details['device'] = 'tablet';
			$details['os']     = 'iPadOS';
		}

		$row = array_merge(
			$details,
			array(
				'post_id'      => $post_id,
				'post_type'    => $post ? $post->post_type : ( 'other' === $kind ? '' : $kind ),
				'event_type'   => $type,
				'event_name'   => 'pageview' === $type ? '' : self::event_name( $type, $p ),
				'label'        => 'pageview' === $type ? '' : sanitize_text_field( self::str( $p['el'] ?? '', 500 ) ),
				'value'        => 'custom' === $type || 'click' === $type ? (float) ( is_numeric( $p['ev'] ?? null ) ? $p['ev'] : 0 ) : 0,
				'visitor_id'   => $vid,
				'session_id'   => $sid,
				'is_new'       => $is_new,
				'path'         => self::stored_path( $url ),
				'referrer'     => $ref,
				'ref_domain'   => preg_replace( '/^www\./', '', $ref_host ),
				'utm_source'   => $utm['source'],
				'utm_medium'   => $utm['medium'],
				'utm_campaign' => $utm['campaign'],
				'utm_term'     => $utm['term'],
				'utm_content'  => $utm['content'],
				'screen'       => self::size( $p['sw'] ?? '' ),
				'viewport'     => self::size( $p['vp'] ?? '' ),
				'language'     => preg_replace( '/[^A-Za-z0-9\-]/', '', self::str( $p['l'] ?? '', 15 ) ),
				'timezone'     => preg_replace( '/[^A-Za-z0-9_\/\-+]/', '', self::str( $p['z'] ?? '', 50 ) ),
				'ip_hash'      => $ip_hash,
			)
		);
		$row = TMG_Reports_Recorder::attribute( $row );
		$id  = TMG_Reports_Recorder::insert( $row, $ctx );

		if ( $id && 'pageview' === $type ) {
			return self::done(
				200,
				array(
					'id'  => $id,
					'tok' => self::token( $id ),
				)
			);
		}
		return self::done();
	}

	/** Engagement update for an existing page view (visible seconds, max scroll %). */
	private static function ping( array $p ): WP_REST_Response {
		global $wpdb;
		$id  = absint( $p['id'] ?? 0 );
		$tok = self::str( $p['tok'] ?? '', 40 );
		if ( ! $id || ! hash_equals( self::token( $id ), $tok ) ) {
			return self::done( 403 );
		}
		$s        = TMG_Reports_Settings::all();
		$duration = $s['ev_time'] ? min( 86400, absint( $p['d'] ?? 0 ) ) : 0;
		$scroll   = $s['ev_scroll'] ? min( 100, absint( $p['sc'] ?? 0 ) ) : 0;
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . TMG_Reports_Install::hits_table() . " SET duration = GREATEST(duration, %d), scroll = GREATEST(scroll, %d) WHERE id = %d AND event_type = 'pageview' AND created_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$duration,
				$scroll,
				$id,
				gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS )
			)
		);
		return self::done();
	}

	private static function rate_limited( string $ip_hash, int $limit ): bool {
		global $wpdb;
		if ( $limit <= 0 ) {
			return false;
		}
		if ( wp_using_ext_object_cache() ) {
			$key = 'rl_' . $ip_hash . '_' . (int) floor( time() / 600 );
			wp_cache_add( $key, 0, 'tmg_reports', 600 );
			return (int) wp_cache_incr( $key, 1, 'tmg_reports' ) > $limit;
		}
		$n = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . TMG_Reports_Install::hits_table() . ' WHERE ip_hash = %s AND created_at >= %s', $ip_hash, gmdate( 'Y-m-d H:i:s', time() - 600 ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $n >= $limit;
	}

	/** Path as stored: path only, or path + query without UTM / listed parameters. */
	public static function stored_path( string $url ): string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$path = '' === $path ? '/' : $path;
		if ( 'listed' === TMG_Reports_Settings::get( 'query_strip' ) ) {
			$query = array();
			wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
			$strip = array_map( 'strtolower', (array) TMG_Reports_Settings::get( 'query_strip_params' ) );
			foreach ( array_keys( $query ) as $k ) {
				if ( 0 === strpos( strtolower( (string) $k ), 'utm_' ) || in_array( strtolower( (string) $k ), $strip, true ) ) {
					unset( $query[ $k ] );
				}
			}
			if ( $query ) {
				ksort( $query );
				$path .= '?' . http_build_query( $query );
			}
		}
		return mb_substr( sanitize_text_field( rawurldecode( $path ) ), 0, 255 );
	}

	private static function event_name( string $type, array $p ): string {
		if ( in_array( $type, array( 'click', 'custom', 'form' ), true ) ) {
			$name = sanitize_text_field( self::str( $p['en'] ?? '', 64 ) );
			return '' !== $name ? $name : $type;
		}
		return $type;
	}

	/** @param mixed $v */
	private static function str( $v, int $max ): string {
		return is_scalar( $v ) ? mb_substr( (string) $v, 0, $max ) : '';
	}

	/** @param mixed $v */
	private static function hex( $v ): string {
		$v = is_string( $v ) ? strtolower( $v ) : '';
		return preg_match( '/^[a-f0-9]{16,32}$/', $v ) ? $v : '';
	}

	/** @param mixed $v */
	private static function size( $v ): string {
		$v = self::str( $v, 15 );
		return preg_match( '/^\d{1,5}x\d{1,5}$/', $v ) ? $v : '';
	}
}
