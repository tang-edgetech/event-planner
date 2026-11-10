<?php
/**
 * First-party visitor analytics: page views, engagement, clicks, conversions,
 * device / browser / OS, country / region / city, timezone, language, referrer & UTM.
 *
 * Collected through a cache-friendly JS beacon → REST endpoint. No raw IPs are stored.
 *
 * Screens: none of its own (records visits); results appear in Event Plus → Reports. Settings → Tracking.
 */

defined( 'ABSPATH' ) || exit;

class EPC_Analytics {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'epc_visits';
	}

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'epc_applicant_registered', array( __CLASS__, 'record_conversion' ), 10, 3 );
		add_action( 'epc_analytics_cleanup', array( __CLASS__, 'cleanup' ) );
		add_action( 'init', function () {
			if ( ! wp_next_scheduled( 'epc_analytics_cleanup' ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'epc_analytics_cleanup' );
			}
		} );
	}

	public static function install_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t       = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$t} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			post_type varchar(20) NOT NULL DEFAULT '',
			event_type varchar(20) NOT NULL DEFAULT 'pageview',
			label varchar(190) NOT NULL DEFAULT '',
			visitor_id varchar(32) NOT NULL DEFAULT '',
			session_id varchar(32) NOT NULL DEFAULT '',
			is_new tinyint(1) NOT NULL DEFAULT 0,
			path varchar(255) NOT NULL DEFAULT '',
			referrer varchar(255) NOT NULL DEFAULT '',
			ref_domain varchar(120) NOT NULL DEFAULT '',
			channel varchar(20) NOT NULL DEFAULT '',
			utm_source varchar(100) NOT NULL DEFAULT '',
			utm_medium varchar(100) NOT NULL DEFAULT '',
			utm_campaign varchar(100) NOT NULL DEFAULT '',
			utm_term varchar(100) NOT NULL DEFAULT '',
			utm_content varchar(100) NOT NULL DEFAULT '',
			device varchar(10) NOT NULL DEFAULT '',
			browser varchar(30) NOT NULL DEFAULT '',
			browser_version varchar(20) NOT NULL DEFAULT '',
			os varchar(30) NOT NULL DEFAULT '',
			os_version varchar(20) NOT NULL DEFAULT '',
			screen varchar(15) NOT NULL DEFAULT '',
			viewport varchar(15) NOT NULL DEFAULT '',
			language varchar(15) NOT NULL DEFAULT '',
			timezone varchar(50) NOT NULL DEFAULT '',
			country varchar(2) NOT NULL DEFAULT '',
			country_name varchar(60) NOT NULL DEFAULT '',
			region varchar(80) NOT NULL DEFAULT '',
			city varchar(80) NOT NULL DEFAULT '',
			ip_hash varchar(32) NOT NULL DEFAULT '',
			duration int(10) unsigned NOT NULL DEFAULT 0,
			scroll tinyint(3) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY post_created (post_id,created_at),
			KEY created_at (created_at),
			KEY visitor_id (visitor_id),
			KEY session_id (session_id)
			) {$charset};"
		);
		update_option( 'epc_db_version', EPC_DB_VERSION );
	}

	/* ------------------------------------------------------------------ */

	public static function enabled(): bool {
		if ( ! epc_setting( 'epc_track_enabled', true ) ) {
			return false;
		}
		if ( is_user_logged_in() && current_user_can( 'edit_posts' ) && ! epc_setting( 'epc_track_admins', false ) ) {
			return false;
		}
		return true;
	}

	public static function enqueue(): void {
		if ( is_admin() || is_preview() || ! self::enabled() ) {
			return;
		}
		$post_id = is_singular() ? get_queried_object_id() : 0;
		wp_enqueue_script( 'epc-track', EPC_URL . 'assets/js/track.js', array(), EPC_VERSION, array( 'strategy' => 'defer', 'in_footer' => true ) );
		wp_localize_script(
			'epc-track',
			'EPTrackConfig',
			array(
				'endpoint' => esc_url_raw( rest_url( 'epc/v1/t' ) ),
				'postId'   => $post_id,
				'postType' => $post_id ? get_post_type( $post_id ) : ( is_front_page() ? 'home' : 'other' ),
				'dnt'      => (bool) epc_setting( 'epc_track_dnt', false ),
			)
		);
	}

	public static function routes(): void {
		register_rest_route(
			'epc/v1',
			'/t',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'collect' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/** Beacons are sent as text/plain JSON to avoid CORS pre-flight. */
	private static function payload( WP_REST_Request $req ): array {
		$json = json_decode( $req->get_body(), true );
		return is_array( $json ) ? $json : (array) $req->get_params();
	}

	public static function collect( WP_REST_Request $req ) {
		global $wpdb;
		if ( ! epc_setting( 'epc_track_enabled', true ) ) {
			return new WP_REST_Response( null, 204 );
		}
		$p  = self::payload( $req );
		$ua = (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' );
		if ( self::is_bot( $ua ) ) {
			return new WP_REST_Response( null, 204 );
		}
		$type = sanitize_key( $p['type'] ?? 'pageview' );

		// Engagement update for an existing page view.
		if ( 'ping' === $type ) {
			$id  = absint( $p['id'] ?? 0 );
			$tok = (string) ( $p['tok'] ?? '' );
			if ( $id && hash_equals( self::token( $id ), $tok ) ) {
				$wpdb->query(
					$wpdb->prepare(
						'UPDATE ' . self::table() . ' SET duration = GREATEST(duration, %d), scroll = GREATEST(scroll, %d) WHERE id = %d',
						min( 86400, absint( $p['duration'] ?? 0 ) ),
						min( 100, absint( $p['scroll'] ?? 0 ) ),
						$id
					)
				);
			}
			return new WP_REST_Response( null, 204 );
		}

		if ( ! in_array( $type, array( 'pageview', 'click', 'outbound' ), true ) ) {
			return new WP_REST_Response( null, 204 );
		}

		// Light rate limit per IP (120 hits / 10 min).
		$ip     = epc_client_ip();
		$iphash = substr( wp_hash( $ip . gmdate( 'Y-m' ) ), 0, 32 );
		$rl     = 'epc_rl_' . $iphash;
		$hits   = (int) get_transient( $rl );
		if ( $hits > 120 ) {
			return new WP_REST_Response( null, 429 );
		}
		set_transient( $rl, $hits + 1, 10 * MINUTE_IN_SECONDS );

		$post_id = absint( $p['postId'] ?? 0 );
		if ( $post_id && ! get_post( $post_id ) ) {
			$post_id = 0;
		}
		$ua_info = self::parse_ua( $ua );
		$geo     = self::geo( $ip );
		$url     = esc_url_raw( (string) ( $p['url'] ?? '' ) );
		$ref     = esc_url_raw( (string) ( $p['ref'] ?? '' ) );
		$utm     = (array) ( $p['utm'] ?? array() );
		$refhost = $ref ? (string) wp_parse_url( $ref, PHP_URL_HOST ) : '';
		$site    = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		if ( $refhost === $site ) {
			$refhost = '';
			$ref     = '';
		}
		$s = fn( $v, $len ) => mb_substr( sanitize_text_field( (string) $v ), 0, $len );

		$row = array(
			'created_at'      => current_time( 'mysql', true ),
			'post_id'         => $post_id,
			'post_type'       => $s( $p['postType'] ?? ( $post_id ? get_post_type( $post_id ) : '' ), 20 ),
			'event_type'      => $type,
			'label'           => $s( $p['label'] ?? '', 190 ),
			'visitor_id'      => substr( sanitize_key( $p['vid'] ?? '' ), 0, 32 ),
			'session_id'      => substr( sanitize_key( $p['sid'] ?? '' ), 0, 32 ),
			'is_new'          => empty( $p['returning'] ) ? 1 : 0,
			'path'            => $s( (string) wp_parse_url( $url, PHP_URL_PATH ), 255 ),
			'referrer'        => mb_substr( $ref, 0, 255 ),
			'ref_domain'      => $s( preg_replace( '/^www\./', '', $refhost ), 120 ),
			'channel'         => self::channel( $refhost, $utm ),
			'utm_source'      => $s( $utm['source'] ?? '', 100 ),
			'utm_medium'      => $s( $utm['medium'] ?? '', 100 ),
			'utm_campaign'    => $s( $utm['campaign'] ?? '', 100 ),
			'utm_term'        => $s( $utm['term'] ?? '', 100 ),
			'utm_content'     => $s( $utm['content'] ?? '', 100 ),
			'device'          => $ua_info['device'],
			'browser'         => $ua_info['browser'],
			'browser_version' => $ua_info['browser_version'],
			'os'              => $ua_info['os'],
			'os_version'      => $ua_info['os_version'],
			'screen'          => $s( $p['screen'] ?? '', 15 ),
			'viewport'        => $s( $p['viewport'] ?? '', 15 ),
			'language'        => $s( $p['lang'] ?? '', 15 ),
			'timezone'        => $s( $p['tz'] ?? '', 50 ),
			'country'         => $geo['country'],
			'country_name'    => $geo['country_name'],
			'region'          => $geo['region'],
			'city'            => $geo['city'],
			'ip_hash'         => $iphash,
		);
		// Tablet heuristic from client screen when UA is ambiguous (iPadOS reports as Mac).
		if ( 'desktop' === $row['device'] && ! empty( $p['touch'] ) && 'macOS' === $row['os'] ) {
			$row['device'] = 'tablet';
			$row['os']     = 'iPadOS';
		}

		$wpdb->insert( self::table(), $row );
		$id = (int) $wpdb->insert_id;

		return new WP_REST_Response( array( 'id' => $id, 'tok' => self::token( $id ) ), 200 );
	}

	private static function token( int $id ): string {
		return substr( wp_hash( 'epc_visit_' . $id ), 0, 16 );
	}

	/** Server-side conversion record when a registration is saved. */
	public static function record_conversion( int $applicant_id, int $event_id, array $data ): void {
		global $wpdb;
		$src = (array) get_post_meta( $applicant_id, '_epc_source', true );
		$ip  = epc_client_ip();
		$ua  = self::parse_ua( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );
		$geo = self::geo( $ip );
		$wpdb->insert(
			self::table(),
			array(
				'created_at'   => current_time( 'mysql', true ),
				'post_id'      => $event_id,
				'post_type'    => EPC_Post_Types::EVENT,
				'event_type'   => 'submit',
				'label'        => 'Registration #' . $applicant_id,
				'visitor_id'   => (string) get_post_meta( $applicant_id, '_epc_visitor', true ),
				'ref_domain'   => mb_substr( (string) ( $src['ref_domain'] ?? '' ), 0, 120 ),
				'channel'      => self::channel( (string) ( $src['ref_domain'] ?? '' ), array( 'source' => $src['utm_source'] ?? '', 'medium' => $src['utm_medium'] ?? '' ) ),
				'utm_source'   => mb_substr( (string) ( $src['utm_source'] ?? '' ), 0, 100 ),
				'utm_medium'   => mb_substr( (string) ( $src['utm_medium'] ?? '' ), 0, 100 ),
				'utm_campaign' => mb_substr( (string) ( $src['utm_campaign'] ?? '' ), 0, 100 ),
				'device'       => $ua['device'],
				'browser'      => $ua['browser'],
				'os'           => $ua['os'],
				'country'      => $geo['country'],
				'country_name' => $geo['country_name'],
				'region'       => $geo['region'],
				'city'         => $geo['city'],
				'ip_hash'      => substr( wp_hash( $ip . gmdate( 'Y-m' ) ), 0, 32 ),
			)
		);
	}

	public static function cleanup(): void {
		global $wpdb;
		$days = (int) epc_setting( 'epc_track_retention', 730 );
		if ( $days > 0 ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE created_at < %s', gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
		}
	}

	/* ------------------------------------------------------------------ */

	public static function is_bot( string $ua ): bool {
		return '' === $ua || (bool) preg_match( '/bot|crawl|spider|slurp|facebookexternalhit|whatsapp|telegrambot|preview|headless|lighthouse|pingdom|uptime|monitor|curl|wget|python-requests|go-http|axios|scrapy|semrush|ahrefs|bingpreview/i', $ua );
	}

	public static function parse_ua( string $ua ): array {
		$r = array( 'device' => 'desktop', 'browser' => 'Other', 'browser_version' => '', 'os' => 'Other', 'os_version' => '' );

		if ( preg_match( '/iPad|Tablet|PlayBook|Silk|(Android(?!.*Mobile))/i', $ua ) ) {
			$r['device'] = 'tablet';
		} elseif ( preg_match( '/Mobi|iPhone|iPod|Android.*Mobile|Windows Phone|BlackBerry|Opera Mini/i', $ua ) ) {
			$r['device'] = 'mobile';
		}

		$os = array(
			'/Windows NT 10\.0/'            => array( 'Windows', '10/11' ),
			'/Windows NT ([\d.]+)/'         => array( 'Windows', '' ),
			'/iPhone OS ([\d_]+)/'          => array( 'iOS', '' ),
			'/CPU OS ([\d_]+)/'             => array( 'iPadOS', '' ),
			'/Android ([\d.]+)/'            => array( 'Android', '' ),
			'/Mac OS X ([\d_.]+)/'          => array( 'macOS', '' ),
			'/CrOS/'                        => array( 'ChromeOS', '' ),
			'/HarmonyOS/'                   => array( 'HarmonyOS', '' ),
			'/Linux/'                       => array( 'Linux', '' ),
		);
		foreach ( $os as $re => $info ) {
			if ( preg_match( $re, $ua, $m ) ) {
				$r['os']         = $info[0];
				$r['os_version'] = $info[1] ?: str_replace( '_', '.', $m[1] ?? '' );
				break;
			}
		}

		$browsers = array(
			'/Edg(?:e|A|iOS)?\/([\d.]+)/'     => 'Edge',
			'/OPR\/([\d.]+)/'                 => 'Opera',
			'/SamsungBrowser\/([\d.]+)/'      => 'Samsung Internet',
			'/UCBrowser\/([\d.]+)/'           => 'UC Browser',
			'/(?:FBAN|FBAV)\/([\d.]+)/'       => 'Facebook app',
			'/Instagram ([\d.]+)/'            => 'Instagram app',
			'/Line\/([\d.]+)/'                => 'LINE app',
			'/MicroMessenger\/([\d.]+)/'      => 'WeChat',
			'/LinkedInApp/'                   => 'LinkedIn app',
			'/(?:Chrome|CriOS)\/([\d.]+)/'    => 'Chrome',
			'/(?:Firefox|FxiOS)\/([\d.]+)/'   => 'Firefox',
			'/Version\/([\d.]+).*Safari/'     => 'Safari',
		);
		foreach ( $browsers as $re => $name ) {
			if ( preg_match( $re, $ua, $m ) ) {
				$r['browser']         = $name;
				$r['browser_version'] = isset( $m[1] ) ? explode( '.', $m[1] )[0] : '';
				break;
			}
		}
		return $r;
	}

	public static function channel( string $refhost, array $utm ): string {
		$medium = strtolower( (string) ( $utm['medium'] ?? '' ) );
		$source = strtolower( (string) ( $utm['source'] ?? '' ) );
		if ( 'qr' === $source || 'qr' === $medium ) {
			return 'qr';
		}
		if ( preg_match( '/cpc|ppc|paid|ads?$|display|banner/', $medium ) ) {
			return 'paid';
		}
		if ( preg_match( '/e-?mail|newsletter|edm/', $medium . ' ' . $source ) ) {
			return 'email';
		}
		$social = '/facebook|fb\.|instagram|linkedin|lnkd\.in|t\.co|twitter|x\.com|whatsapp|wa\.me|telegram|t\.me|youtube|tiktok|threads|xiaohongshu|wechat|line\.me/';
		if ( preg_match( $social, $refhost . ' ' . $source ) || 'social' === $medium ) {
			return 'social';
		}
		if ( preg_match( '/google\.|bing\.|yahoo\.|duckduckgo|baidu|yandex|ecosia|search\./', $refhost ) ) {
			return 'search';
		}
		if ( $source || $medium ) {
			return 'campaign';
		}
		return $refhost ? 'referral' : 'direct';
	}

	/**
	 * Resolve location for an IP using the configured provider. Cached 7 days.
	 */
	public static function geo( string $ip ): array {
		$out = array( 'country' => '', 'country_name' => '', 'region' => '', 'city' => '' );

		// Proxy / CDN headers (Cloudflare "visitor location headers", etc.).
		$cc = strtoupper( (string) ( $_SERVER['HTTP_CF_IPCOUNTRY'] ?? $_SERVER['HTTP_X_COUNTRY_CODE'] ?? $_SERVER['GEOIP_COUNTRY_CODE'] ?? '' ) );
		if ( preg_match( '/^[A-Z]{2}$/', $cc ) && 'XX' !== $cc && 'T1' !== $cc ) {
			$out['country']      = $cc;
			$out['country_name'] = self::country_name( $cc );
			$out['region']       = sanitize_text_field( (string) ( $_SERVER['HTTP_CF_REGION'] ?? '' ) );
			$out['city']         = sanitize_text_field( (string) ( $_SERVER['HTTP_CF_IPCITY'] ?? '' ) );
		}

		$provider = epc_setting( 'epc_geo_provider', 'headers' );
		if ( ( $out['city'] && $out['country'] ) || in_array( $provider, array( 'none', 'headers' ), true ) ) {
			return $out;
		}
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return $out;
		}

		$key    = 'epc_geo_' . md5( $ip );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$url = 'ipinfo' === $provider
			? 'https://ipinfo.io/' . rawurlencode( $ip ) . '/json?token=' . rawurlencode( (string) epc_setting( 'epc_geo_token', '' ) )
			: 'https://ipapi.co/' . rawurlencode( $ip ) . '/json/';
		$res = wp_remote_get( $url, array( 'timeout' => 2, 'user-agent' => 'EPEvents/' . EPC_VERSION ) );
		if ( ! is_wp_error( $res ) && 200 === wp_remote_retrieve_response_code( $res ) ) {
			$j = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( is_array( $j ) ) {
				$code                = strtoupper( (string) ( $j['country_code'] ?? $j['country'] ?? '' ) );
				$out['country']      = substr( $code, 0, 2 );
				$out['country_name'] = sanitize_text_field( (string) ( $j['country_name'] ?? self::country_name( $code ) ) );
				$out['region']       = sanitize_text_field( (string) ( $j['region'] ?? '' ) );
				$out['city']         = sanitize_text_field( (string) ( $j['city'] ?? '' ) );
			}
		}
		set_transient( $key, $out, 7 * DAY_IN_SECONDS );
		return $out;
	}

	public static function country_name( string $cc ): string {
		$map = array( 'MY' => 'Malaysia', 'SG' => 'Singapore', 'BN' => 'Brunei', 'ID' => 'Indonesia', 'TH' => 'Thailand', 'PH' => 'Philippines', 'VN' => 'Vietnam', 'KH' => 'Cambodia', 'LA' => 'Laos', 'MM' => 'Myanmar', 'AU' => 'Australia', 'NZ' => 'New Zealand', 'CN' => 'China', 'HK' => 'Hong Kong', 'MO' => 'Macau', 'TW' => 'Taiwan', 'JP' => 'Japan', 'KR' => 'South Korea', 'IN' => 'India', 'PK' => 'Pakistan', 'BD' => 'Bangladesh', 'LK' => 'Sri Lanka', 'AE' => 'United Arab Emirates', 'SA' => 'Saudi Arabia', 'QA' => 'Qatar', 'GB' => 'United Kingdom', 'IE' => 'Ireland', 'FR' => 'France', 'DE' => 'Germany', 'NL' => 'Netherlands', 'CH' => 'Switzerland', 'IT' => 'Italy', 'ES' => 'Spain', 'SE' => 'Sweden', 'US' => 'United States', 'CA' => 'Canada', 'BR' => 'Brazil', 'MX' => 'Mexico' );
		return $map[ $cc ] ?? $cc;
	}
}
