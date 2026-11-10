<?php
/**
 * Self-test. Run with: wp eval-file wp-content/plugins/tamago-reports/tests/run.php
 * Prints "PASS name" / "FAIL name" and a total. Everything runs inside a database transaction
 * that is rolled back at the end, so the site's data and settings are left untouched.
 *
 * Used by: developers / CI (WP-CLI).
 */

defined( 'ABSPATH' ) || exit;

final class TMG_Reports_Test {
	public static $pass = 0;
	public static $fail = 0;
	public static $section_start = 0.0;

	public static function check( string $name, bool $ok, string $detail = '' ): void {
		if ( $ok ) {
			++self::$pass;
			echo 'PASS ' . $name . "\n";
		} else {
			++self::$fail;
			echo 'FAIL ' . $name . ( '' !== $detail ? ' — ' . $detail : '' ) . "\n";
		}
	}

	public static function section( string $title ): void {
		if ( self::$section_start ) {
			printf( "     (%.0f ms)\n", ( microtime( true ) - self::$section_start ) * 1000 );
		}
		self::$section_start = microtime( true );
		echo "\n== {$title}\n";
	}

	public static function settings( array $s ): void {
		TMG_Reports_Settings::save( $s );
	}

	/** POST a beacon through the real REST server. */
	public static function collect( array $payload, array $server = array() ): WP_REST_Response {
		foreach ( array( 'HTTP_DNT', 'HTTP_SEC_GPC', 'HTTP_CF_IPCOUNTRY' ) as $h ) {
			unset( $_SERVER[ $h ] );
		}
		$_SERVER = array_merge(
			$_SERVER,
			array(
				'REMOTE_ADDR'     => '198.51.100.10',
				'HTTP_USER_AGENT' => self::UA_CHROME,
			),
			$server
		);
		$req = new WP_REST_Request( 'POST', '/tamago-reports/v1/collect' );
		$req->set_header( 'content-type', 'text/plain' );
		$req->set_body( (string) wp_json_encode( $payload ) );
		return rest_do_request( $req );
	}

	public static function last_row(): array {
		global $wpdb;
		return (array) $wpdb->get_row( 'SELECT * FROM ' . TMG_Reports_Install::hits_table() . ' ORDER BY id DESC LIMIT 1', ARRAY_A );
	}

	public static function count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . TMG_Reports_Install::hits_table() );
	}

	const UA_CHROME  = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
	const UA_IPHONE  = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1';
	const UA_SAMSUNG = 'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/25.0 Chrome/121.0.0.0 Mobile Safari/537.36';
	const UA_EDGE    = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 Edg/124.0.2478.67';
	const UA_FIREFOX = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14.4; rv:125.0) Gecko/20100101 Firefox/125.0';
	const UA_IPAD    = 'Mozilla/5.0 (iPad; CPU OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1';
	const UA_FBAPP   = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 [FBAN/FBIOS;FBAV/460.0.0.40.104;FBBV/123]';
}

global $wpdb;
$tmg_t0 = microtime( true );
$wpdb->query( 'START TRANSACTION' );
$tmg_old_server = $_SERVER;
wp_cache_flush();
TMG_Reports_Settings::flush();
// Known baseline settings for the run (rolled back at the end).
update_option( TMG_Reports_Settings::OPTION, array(), true );
TMG_Reports_Settings::flush();

$T     = 'TMG_Reports_Test';
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
$admin = $admin ? $admin[0] : null;
$post  = wp_insert_post( array( 'post_title' => 'TMG test post', 'post_status' => 'publish', 'post_type' => 'post' ) );
$page  = wp_insert_post( array( 'post_title' => 'TMG test page', 'post_status' => 'publish', 'post_type' => 'page' ) );
$draft = wp_insert_post( array( 'post_title' => 'TMG draft', 'post_status' => 'draft', 'post_type' => 'post' ) );
$home  = home_url( '/' );

/* ------------------------------------------------------------------ */
$T::section( 'User-agent parsing' );
$ua = TMG_Reports_UA::parse( $T::UA_IPHONE );
$T::check( 'iPhone → mobile / Safari 17 / iOS 17', 'mobile' === $ua['device'] && 'Safari' === $ua['browser'] && '17' === $ua['browser_version'] && 'iOS' === $ua['os'] && '17' === $ua['os_version'], wp_json_encode( $ua ) );
$ua = TMG_Reports_UA::parse( $T::UA_CHROME );
$T::check( 'Windows Chrome → desktop / Chrome 124 / Windows 10/11', 'desktop' === $ua['device'] && 'Chrome' === $ua['browser'] && '124' === $ua['browser_version'] && 'Windows' === $ua['os'] && '10/11' === $ua['os_version'] );
$ua = TMG_Reports_UA::parse( $T::UA_SAMSUNG );
$T::check( 'Samsung Internet on Android 14 (mobile)', 'Samsung Internet' === $ua['browser'] && 'Android' === $ua['os'] && '14' === $ua['os_version'] && 'mobile' === $ua['device'] );
$T::check( 'Edge detected before Chrome', 'Edge' === TMG_Reports_UA::parse( $T::UA_EDGE )['browser'] );
$ua = TMG_Reports_UA::parse( $T::UA_FIREFOX );
$T::check( 'Firefox on macOS 14.4', 'Firefox' === $ua['browser'] && 'macOS' === $ua['os'] && '14.4' === $ua['os_version'] );
$ua = TMG_Reports_UA::parse( $T::UA_IPAD );
$T::check( 'iPad → tablet / iPadOS', 'tablet' === $ua['device'] && 'iPadOS' === $ua['os'] );
$T::check( 'Facebook in-app browser', 'Facebook app' === TMG_Reports_UA::parse( $T::UA_FBAPP )['browser'] );

/* ------------------------------------------------------------------ */
$T::section( 'Bot filter' );
foreach ( array( 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', 'facebookexternalhit/1.1', 'curl/8.4.0', 'python-requests/2.31', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/120.0.0.0 Safari/537.36', 'Mozilla/5.0 (compatible; AhrefsBot/7.0)', 'GPTBot/1.0', '' ) as $bot ) {
	$T::check( 'bot: ' . ( '' === $bot ? '(empty UA)' : substr( $bot, 0, 40 ) ), TMG_Reports_UA::is_bot( $bot ) );
}
foreach ( array( $T::UA_CHROME, $T::UA_IPHONE, $T::UA_SAMSUNG, $T::UA_FBAPP ) as $human ) {
	$T::check( 'human: ' . substr( $human, 13, 30 ), ! TMG_Reports_UA::is_bot( $human ) );
}

/* ------------------------------------------------------------------ */
$T::section( 'Channel rules' );
$cases = array(
	array( 'm.facebook.com', array(), 'social' ),
	array( 'www.google.com', array(), 'search' ),
	array( 'google.com.my', array(), 'search' ),
	array( 'duckduckgo.com', array(), 'search' ),
	array( 't.co', array(), 'social' ),
	array( 'reddit.com', array(), 'social' ),
	array( 'partner.example', array(), 'referral' ),
	array( 'mylot.com', array(), 'referral' ),
	array( '', array(), 'direct' ),
	array( '', array( 'source' => 'newsletter', 'medium' => 'email' ), 'email' ),
	array( '', array( 'source' => 'qr', 'medium' => 'print' ), 'qr' ),
	array( '', array( 'source' => 'google', 'medium' => 'cpc' ), 'paid' ),
	array( '', array( 'source' => 'facebook', 'medium' => 'paid_social' ), 'paid' ),
	array( '', array( 'source' => 'instagram', 'medium' => 'story' ), 'social' ),
	array( '', array( 'source' => 'partner', 'medium' => 'banner_x' ), 'paid' ),
	array( '', array( 'source' => 'partner' ), 'campaign' ),
);
foreach ( $cases as $c ) {
	$got = TMG_Reports_Channels::channel( $c[0], $c[1] );
	$T::check( sprintf( 'channel %s %s → %s', $c[0] ? $c[0] : '(none)', $c[1] ? wp_json_encode( $c[1] ) : '', $c[2] ), $got === $c[2], 'got ' . $got );
}
$tmg_rule = function ( $rules ) {
	array_unshift( $rules, array( 'channel' => 'partners', 'referrer' => '/(^|\.)partner\.example$/' ) );
	return $rules;
};
add_filter( 'tmg_reports_channels', $tmg_rule );
$T::check( 'tmg_reports_channels filter adds a rule', 'partners' === TMG_Reports_Channels::channel( 'partner.example', array() ) );
remove_filter( 'tmg_reports_channels', $tmg_rule );

/* ------------------------------------------------------------------ */
$T::section( 'Targeting rules' );
$ctx = fn( $over ) => array_merge( array( 'kind' => 'singular', 'post_id' => $post, 'post_type' => 'post', 'path' => '/tmg-test-post/', 'source' => 'page' ), $over );
$T::settings( array( 'untracked_types' => array( 'attachment', '404' ), 'include_ids' => array(), 'exclude_ids' => array(), 'include_paths' => array(), 'exclude_paths' => array() ) );
$T::check( 'post type tracked by default', TMG_Reports_Targeting::should_track( $ctx( array() ) ) );
$T::check( '404 not tracked by default', ! TMG_Reports_Targeting::should_track( $ctx( array( 'kind' => '404', 'post_id' => 0, 'post_type' => '' ) ) ) );
$T::check( 'search results tracked by default', TMG_Reports_Targeting::should_track( $ctx( array( 'kind' => 'search', 'post_id' => 0, 'post_type' => '' ) ) ) );
$T::settings( array( 'untracked_types' => array( 'post', 'search' ) ) );
$T::check( 'untracked post type is skipped', ! TMG_Reports_Targeting::should_track( $ctx( array() ) ) );
$T::check( 'other post type still tracked', TMG_Reports_Targeting::should_track( $ctx( array( 'post_id' => $page, 'post_type' => 'page' ) ) ) );
$T::check( 'untracked search kind skipped', ! TMG_Reports_Targeting::should_track( $ctx( array( 'kind' => 'search', 'post_id' => 0, 'post_type' => '' ) ) ) );
$T::settings( array( 'include_ids' => array( $post ) ) );
$T::check( 'include ID overrides untracked type', TMG_Reports_Targeting::should_track( $ctx( array() ) ) );
$T::settings( array( 'untracked_types' => array(), 'include_ids' => array(), 'exclude_ids' => array( $post ) ) );
$T::check( 'exclude ID', ! TMG_Reports_Targeting::should_track( $ctx( array() ) ) );
$T::settings( array( 'exclude_ids' => array(), 'exclude_paths' => array( '/members/*', '/checkout' ) ) );
$rel_base = rtrim( (string) wp_parse_url( $home, PHP_URL_PATH ), '/' );
$T::check( 'exclude path wildcard', ! TMG_Reports_Targeting::should_track( $ctx( array( 'post_id' => 0, 'kind' => 'other', 'path' => $rel_base . '/members/area/x' ) ) ) );
$T::check( 'exclude exact path (trailing slash optional)', ! TMG_Reports_Targeting::should_track( $ctx( array( 'post_id' => 0, 'kind' => 'other', 'path' => $rel_base . '/checkout/' ) ) ) );
$T::check( 'non-matching path tracked', TMG_Reports_Targeting::should_track( $ctx( array( 'post_id' => 0, 'kind' => 'other', 'path' => $rel_base . '/membership' ) ) ) );
$T::settings( array( 'untracked_types' => array( 'post' ), 'exclude_paths' => array(), 'include_paths' => array( '/promo/*' ) ) );
$T::check( 'include path overrides untracked type', TMG_Reports_Targeting::should_track( $ctx( array( 'path' => $rel_base . '/promo/summer' ) ) ) );
$T::settings( array( 'untracked_types' => array(), 'include_paths' => array( '/members/*' ), 'exclude_paths' => array( '/members/*' ) ) );
$T::check( 'exclude wins over include', ! TMG_Reports_Targeting::should_track( $ctx( array( 'path' => $rel_base . '/members/x' ) ) ) );
$T::settings( array( 'include_paths' => array(), 'exclude_paths' => array(), 'exclude_roles' => array( 'administrator', 'editor' ) ) );
$T::check( 'administrator excluded by role', $admin && ! TMG_Reports_Targeting::should_track( $ctx( array( 'user' => $admin ) ) ) );
$sub_id = wp_insert_user( array( 'user_login' => 'tmg_test_sub_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
$sub    = get_user_by( 'id', $sub_id );
$T::check( 'subscriber tracked', TMG_Reports_Targeting::should_track( $ctx( array( 'user' => $sub ) ) ) );
$T::check( 'logged-out visitor tracked', TMG_Reports_Targeting::should_track( $ctx( array( 'user' => new WP_User( 0 ) ) ) ) );
$T::check( 'default excluded roles = roles that can edit posts', in_array( 'administrator', TMG_Reports_Settings::default_roles(), true ) && in_array( 'contributor', TMG_Reports_Settings::default_roles(), true ) && ! in_array( 'subscriber', TMG_Reports_Settings::default_roles(), true ) );
$T::settings( array( 'exclude_ips' => "203.0.113.7\n198.51.100.0/25\n2001:db8::/32\nnot-an-ip" ) );
$T::check( 'invalid IP entries dropped on save', 3 === count( (array) TMG_Reports_Settings::get( 'exclude_ips' ) ) );
$T::check( 'IP exact match excluded', TMG_Reports_Targeting::ip_excluded( '203.0.113.7' ) );
$T::check( 'IPv4 CIDR match excluded', TMG_Reports_Targeting::ip_excluded( '198.51.100.99' ) );
$T::check( 'IPv4 outside CIDR allowed', ! TMG_Reports_Targeting::ip_excluded( '198.51.100.200' ) );
$T::check( 'IPv6 CIDR match excluded', TMG_Reports_Targeting::ip_excluded( '2001:db8:1::5' ) );
$T::check( 'IPv6 outside CIDR allowed', ! TMG_Reports_Targeting::ip_excluded( '2001:db9::1' ) );
$tmg_veto = fn( $track, $c ) => 'veto' === ( $c['path'] ?? '' ) ? false : $track;
add_filter( 'tmg_reports_should_track', $tmg_veto, 10, 2 );
$T::check( 'tmg_reports_should_track filter can veto', ! TMG_Reports_Targeting::should_track( $ctx( array( 'path' => 'veto' ) ) ) );
remove_filter( 'tmg_reports_should_track', $tmg_veto, 10 );
$T::settings( array( 'exclude_ips' => array(), 'exclude_roles' => TMG_Reports_Settings::default_roles() ) );

/* ------------------------------------------------------------------ */
$T::section( 'REST collect' );
$T::settings( array( 'consent_mode' => 'none', 'rate_limit' => 300, 'respect_dnt' => false, 'respect_gpc' => true, 'query_strip' => 'all' ) );
$before = $T::count();
$res    = $T::collect( array( 't' => 'pageview', 'p' => $post, 'k' => 'singular', 'u' => add_query_arg( array( 'p' => $post, 'utm_source' => 'newsletter', 'utm_medium' => 'email', 'utm_campaign' => 'nov' ), $home ), 'r' => 'https://mail.example.com/', 'sw' => '1920x1080', 'vp' => '1200x800', 'l' => 'en-MY', 'z' => 'Asia/Kuala_Lumpur' ), array( 'HTTP_CF_IPCOUNTRY' => 'MY' ) );
$data   = $res->get_data();
$row    = $T::last_row();
$T::check( 'pageview → 200 with id + token', 200 === $res->get_status() && ! empty( $data['id'] ) && TMG_Reports_Collect::token( (int) $data['id'] ) === $data['tok'] );
$T::check( 'row stored with post + type', (int) $row['post_id'] === $post && 'post' === $row['post_type'] && 'pageview' === $row['event_type'] );
$T::check( 'UTM parsed from URL, channel email', 'newsletter' === $row['utm_source'] && 'nov' === $row['utm_campaign'] && 'email' === $row['channel'] );
$T::check( 'referrer domain stored', 'mail.example.com' === $row['ref_domain'] );
$T::check( 'query string stripped from stored path', false === strpos( $row['path'], '?' ) );
$T::check( 'country from CDN header', 'MY' === $row['country'] && 'Malaysia' === $row['country_name'] );
$T::check( 'no raw IP stored', false === strpos( implode( '|', $row ), '198.51.100.10' ) && 32 === strlen( $row['ip_hash'] ) );
$T::check( 'cookieless visitor ID = daily hash', TMG_Reports_Visitor::daily_id( '198.51.100.10', $T::UA_CHROME ) === $row['visitor_id'] );
$T::check( 'first hit is a new visitor with a session', 1 === (int) $row['is_new'] && 32 === strlen( $row['session_id'] ) );
$sid = $row['session_id'];

$res = $T::collect( array( 't' => 'pageview', 'p' => $page, 'k' => 'singular', 'u' => add_query_arg( 'page_id', $page, $home ), 'r' => $home ) );
$row = $T::last_row();
$T::check( 'second view: same session, not new, internal referrer dropped', $row['session_id'] === $sid && 0 === (int) $row['is_new'] && $home !== $row['referrer'] && 'mail.example.com' === $row['ref_domain'] );
$T::check( 'second view inherits session attribution', 'email' === $row['channel'] && 'nov' === $row['utm_campaign'] );

$res = $T::collect( array( 't' => 'ping', 'id' => (int) $data['id'], 'tok' => $data['tok'], 'd' => 95, 'sc' => 140 ) );
$dur = $wpdb->get_row( $wpdb->prepare( 'SELECT duration, scroll FROM ' . TMG_Reports_Install::hits_table() . ' WHERE id = %d', $data['id'] ), ARRAY_A );
$T::check( 'ping with valid token updates duration + scroll (capped 100)', 204 === $res->get_status() && 95 === (int) $dur['duration'] && 100 === (int) $dur['scroll'] );
$T::collect( array( 't' => 'ping', 'id' => (int) $data['id'], 'tok' => $data['tok'], 'd' => 10, 'sc' => 5 ) );
$dur = $wpdb->get_row( $wpdb->prepare( 'SELECT duration, scroll FROM ' . TMG_Reports_Install::hits_table() . ' WHERE id = %d', $data['id'] ), ARRAY_A );
$T::check( 'ping never lowers values', 95 === (int) $dur['duration'] );
$res = $T::collect( array( 't' => 'ping', 'id' => (int) $data['id'], 'tok' => 'forged', 'd' => 9999 ) );
$T::check( 'ping with bad token → 403, unchanged', 403 === $res->get_status() && 95 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT duration FROM ' . TMG_Reports_Install::hits_table() . ' WHERE id = %d', $data['id'] ) ) );

$n   = $T::count();
$res = $T::collect( array( 't' => 'pageview', 'p' => $post, 'k' => 'singular', 'u' => add_query_arg( 'p', $post, $home ) ), array( 'HTTP_USER_AGENT' => 'Mozilla/5.0 (compatible; Googlebot/2.1)' ) );
$T::check( 'bot dropped (204, nothing stored)', 204 === $res->get_status() && $T::count() === $n );
$res = $T::collect( array( 't' => 'pageview', 'p' => $post, 'k' => 'singular', 'u' => 'https://evil.example/?p=1' ) );
$T::check( 'foreign URL rejected (400)', 400 === $res->get_status() && $T::count() === $n );
$res = $T::collect( array( 't' => 'hack' ) );
$T::check( 'unknown type rejected (400)', 400 === $res->get_status() );
$res = $T::collect( array( 't' => 'pageview', 'p' => 9999999, 'k' => 'singular', 'u' => $home . '?p=9999999' ) );
$T::check( 'unknown post ID ignored (stored as 0)', 200 === $res->get_status() && 0 === (int) $T::last_row()['post_id'] );
$res = $T::collect( array( 't' => 'pageview', 'p' => $draft, 'k' => 'singular', 'u' => $home . '?p=' . $draft ) );
$T::check( 'draft post ID ignored', 0 === (int) $T::last_row()['post_id'] );
$n   = $T::count();
$res = $T::collect( array( 't' => 'click', 'p' => $post, 'k' => 'singular', 'u' => $home . '?p=' . $post, 'en' => str_repeat( 'n', 300 ), 'el' => str_repeat( 'é', 300 ) ) );
$row = $T::last_row();
$T::check( 'long fields length-limited', $T::count() === $n + 1 && 64 === mb_strlen( $row['event_name'] ) && 190 === mb_strlen( $row['label'] ) );
$res = $T::collect( array( 't' => 'pageview', 'u' => $home, 'pad' => str_repeat( 'x', 5000 ) ) );
$T::check( 'oversized body rejected (413)', 413 === $res->get_status() );
$res = $T::collect( array( 't' => 'pageview', 'p' => $post, 'k' => 'singular', 'u' => $home . '?p=' . $post, 'sw' => '<script>', 'l' => 'en"><b>', 'z' => 'Asia/KL;DROP' ) );
$row = $T::last_row();
$T::check( 'screen / language / timezone sanitised', '' === $row['screen'] && 'enb' === $row['language'] && 'Asia/KLDROP' === $row['timezone'] );

$n = $T::count();
$T::collect( array( 't' => 'pageview', 'p' => $post, 'k' => 'singular', 'u' => $home . '?p=' . $post ), array( 'HTTP_SEC_GPC' => '1' ) );
$T::check( 'Global Privacy Control respected', $T::count() === $n );
$T::collect( array( 't' => 'pageview', 'p' => $post, 'k' => 'singular', 'u' => $home . '?p=' . $post ), array( 'HTTP_DNT' => '1' ) );
$T::check( 'DNT ignored while switch is off', $T::count() === $n + 1 );
$T::settings( array( 'respect_dnt' => true ) );
$T::collect( array( 't' => 'pageview', 'p' => $post, 'k' => 'singular', 'u' => $home . '?p=' . $post ), array( 'HTTP_DNT' => '1' ) );
$T::check( 'DNT respected when switched on', $T::count() === $n + 1 );
$T::settings( array( 'exclude_ips' => array( '198.51.100.0/24' ) ) );
$T::collect( array( 't' => 'pageview', 'p' => $post, 'k' => 'singular', 'u' => $home . '?p=' . $post ) );
$T::check( 'excluded IP range not stored', $T::count() === $n + 1 );
$T::settings( array( 'exclude_ips' => array(), 'exclude_paths' => array( '/secret*' ) ) );
$T::collect( array( 't' => 'pageview', 'k' => 'other', 'u' => rtrim( $home, '/' ) . '/secret/page' ) );
$T::check( 'collect re-checks path rules (cached pages)', $T::count() === $n + 1 );
$T::settings( array( 'exclude_paths' => array(), 'untracked_types' => array( 'post' ) ) );
$T::collect( array( 't' => 'pageview', 'p' => $post, 'k' => 'singular', 'u' => $home . '?p=' . $post ) );
$T::check( 'collect re-checks content type rules', $T::count() === $n + 1 );
$T::settings( array( 'untracked_types' => array( 'attachment', '404' ) ) );

$T::settings( array( 'rate_limit' => 3 ) );
$codes = array();
for ( $i = 0; $i < 5; $i++ ) {
	$codes[] = $T::collect( array( 't' => 'pageview', 'p' => $post, 'k' => 'singular', 'u' => $home . '?p=' . $post ), array( 'REMOTE_ADDR' => '192.0.2.77' ) )->get_status();
}
$T::check( 'rate limit per IP hash → 429', array( 200, 200, 200, 429, 429 ) === $codes, implode( ',', $codes ) );
$other = $T::collect( array( 't' => 'pageview', 'p' => $post, 'k' => 'singular', 'u' => $home . '?p=' . $post ), array( 'REMOTE_ADDR' => '192.0.2.78' ) )->get_status();
$T::check( 'rate limit is per IP', 200 === $other );
$T::settings( array( 'rate_limit' => 300 ) );

$T::settings( array( 'query_strip' => 'listed', 'query_strip_params' => array( 'fbclid', 'ref' ) ) );
$T::check( 'listed mode keeps other params, strips utm_* + listed', '/a/?color=red&page=2' === TMG_Reports_Collect::stored_path( 'http://x.test/a/?utm_source=x&page=2&fbclid=1&color=red&ref=z' ) );
$T::settings( array( 'query_strip' => 'all' ) );
$T::check( 'all mode keeps the path only', '/a/' === TMG_Reports_Collect::stored_path( 'http://x.test/a/?page=2' ) );

$res = $T::collect( array( 't' => 'pageview', 'k' => 'spa', 'u' => get_permalink( $page ) ) );
$T::check( 'SPA navigation resolves the post from the URL', (int) $T::last_row()['post_id'] === $page );

/* ------------------------------------------------------------------ */
$T::section( 'Consent modes' );
$T::settings( array( 'consent_mode' => 'none' ) );
$cfg = TMG_Reports_Tracker::config( array( 'post_id' => $post, 'kind' => 'singular' ) );
$T::check( 'config: cookieless mode', 'none' === $cfg['consent'] );
$T::collect( array( 't' => 'pageview', 'p' => $post, 'k' => 'singular', 'u' => $home . '?p=' . $post, 'v' => str_repeat( 'a', 32 ), 's' => str_repeat( 'b', 32 ) ), array( 'REMOTE_ADDR' => '198.51.100.20' ) );
$T::check( 'cookieless: client IDs ignored, daily hash used', TMG_Reports_Visitor::daily_id( '198.51.100.20', $T::UA_CHROME ) === $T::last_row()['visitor_id'] );
$salt = get_option( TMG_Reports_Visitor::SALT_OPTION );
update_option( TMG_Reports_Visitor::SALT_OPTION, array( 'day' => '2000-01-01', 'salt' => $salt['salt'] ) );
$T::check( 'cookieless ID rotates daily (new salt each day)', TMG_Reports_Visitor::daily_id( '198.51.100.20', $T::UA_CHROME ) !== $T::last_row()['visitor_id'] );

$T::settings( array( 'consent_mode' => 'wait', 'consent_cookie' => 'cmplz_statistics', 'consent_cookie_value' => 'allow' ) );
$cfg = TMG_Reports_Tracker::config( array( 'post_id' => $post, 'kind' => 'singular' ) );
$T::check( 'config: wait mode + cookie passed to script', 'wait' === $cfg['consent'] && 'cmplz_statistics' === $cfg['cookie'] && 'allow' === $cfg['cookieV'] );
$n = $T::count();
$T::collect( array( 't' => 'pageview', 'p' => $post, 'k' => 'singular', 'u' => $home . '?p=' . $post ) );
$T::check( 'wait mode: no visitor ID (no consent) → nothing stored', $T::count() === $n );
$vid = str_repeat( 'c', 32 );
$T::collect( array( 't' => 'pageview', 'p' => $post, 'k' => 'singular', 'u' => $home . '?p=' . $post, 'v' => $vid, 's' => str_repeat( 'd', 32 ), 'n' => 1 ) );
$row = $T::last_row();
$T::check( 'wait mode with consent: browser visitor + session IDs stored', $T::count() === $n + 1 && $vid === $row['visitor_id'] && str_repeat( 'd', 32 ) === $row['session_id'] && 1 === (int) $row['is_new'] );
$T::collect( array( 't' => 'pageview', 'p' => $post, 'k' => 'singular', 'u' => $home . '?p=' . $post, 'v' => 'not-hex!', 's' => 'x' ) );
$T::check( 'wait mode: malformed visitor ID rejected', $T::count() === $n + 1 );
$T::settings( array( 'consent_mode' => 'none', 'consent_cookie' => '', 'consent_cookie_value' => '' ) );

/* ------------------------------------------------------------------ */
$T::section( 'Goals' );
$T::settings(
	array(
		'goals' => array(
			array( 'name' => 'Thank you page', 'type' => 'page', 'match' => '/thanks*', 'value' => 5 ),
			array( 'name' => 'Viewed test page', 'type' => 'page', 'match' => (string) $page ),
			array( 'name' => 'Brochure download', 'type' => 'event', 'match' => 'download' ),
			array( 'name' => 'Signup click', 'type' => 'event', 'match' => 'signup-*' ),
			array( 'name' => 'Registration', 'type' => 'server', 'match' => 'registration', 'value' => 50 ),
			array( 'name' => '', 'type' => 'page', 'match' => 'x' ),
			array( 'name' => 'Bad type', 'type' => 'nope', 'match' => 'x' ),
		),
	)
);
$goals = TMG_Reports_Goals::all();
$T::check( 'invalid goals dropped, ids generated', 5 === count( $goals ) && isset( $goals['thank-you-page'], $goals['registration'] ) );
$completed = array();
$tmg_goal_cb = function ( $goal, $row, $id ) use ( &$completed ) {
	$completed[] = $goal['id'];
};
add_action( 'tmg_reports_goal_completed', $tmg_goal_cb, 10, 3 );
$T::collect( array( 't' => 'pageview', 'k' => 'other', 'u' => rtrim( $home, '/' ) . '/thanks/' ), array( 'REMOTE_ADDR' => '198.51.100.30' ) );
$row = $T::last_row();
$T::check( 'page goal by path wildcard + value', 'thank-you-page' === $row['goal'] && 5.0 === (float) $row['value'] );
$T::collect( array( 't' => 'pageview', 'p' => $page, 'k' => 'singular', 'u' => get_permalink( $page ) ), array( 'REMOTE_ADDR' => '198.51.100.30' ) );
$T::check( 'page goal by post ID', 'viewed-test-page' === $T::last_row()['goal'] );
$T::collect( array( 't' => 'download', 'p' => $post, 'k' => 'singular', 'u' => $home . '?p=' . $post, 'en' => 'download', 'el' => 'https://x.test/a.pdf' ), array( 'REMOTE_ADDR' => '198.51.100.30' ) );
$T::check( 'event goal by built-in name', 'brochure-download' === $T::last_row()['goal'] );
$T::collect( array( 't' => 'click', 'p' => $post, 'k' => 'singular', 'u' => $home . '?p=' . $post, 'en' => 'signup-hero', 'el' => 'Sign up' ), array( 'REMOTE_ADDR' => '198.51.100.30' ) );
$T::check( 'event goal by wildcard name', 'signup-click' === $T::last_row()['goal'] );
$T::collect( array( 't' => 'click', 'p' => $post, 'k' => 'singular', 'u' => $home . '?p=' . $post, 'en' => 'other', 'el' => 'x' ), array( 'REMOTE_ADDR' => '198.51.100.30' ) );
$T::check( 'non-matching event has no goal', '' === $T::last_row()['goal'] );
$T::check( 'tmg_reports_goal_completed fired for each conversion', array( 'thank-you-page', 'viewed-test-page', 'brochure-download', 'signup-click' ) === $completed, implode( ',', $completed ) );
$tmg_code_goal = fn( $g ) => array_merge( $g, array( array( 'id' => 'code-goal', 'name' => 'From code', 'type' => 'event', 'match' => 'code-event' ) ) );
add_filter( 'tmg_reports_goals', $tmg_code_goal );
$T::check( 'goals can be registered in code (tmg_reports_goals)', 'code-goal' === ( TMG_Reports_Goals::match( array( 'event_type' => 'custom', 'event_name' => 'code-event' ) )['id'] ?? '' ) );
remove_filter( 'tmg_reports_goals', $tmg_code_goal );

/* ------------------------------------------------------------------ */
$T::section( 'Server-side tmg_reports_track()' );
$_SERVER['REMOTE_ADDR']     = '198.51.100.40';
$_SERVER['HTTP_USER_AGENT'] = $T::UA_IPHONE;
$T::collect( array( 't' => 'pageview', 'p' => $post, 'k' => 'singular', 'u' => add_query_arg( array( 'p' => $post, 'utm_source' => 'facebook', 'utm_medium' => 'social', 'utm_campaign' => 'launch' ), $home ), 'r' => 'https://m.facebook.com/' ), array( 'REMOTE_ADDR' => '198.51.100.40', 'HTTP_USER_AGENT' => $T::UA_IPHONE, 'HTTP_CF_IPCOUNTRY' => 'SG' ) );
$view      = $T::last_row();
$completed = array();
$id        = tmg_reports_track( 'registration', array( 'post_id' => $post, 'label' => 'Registration #12', 'visitor_id' => $view['visitor_id'] ) );
$row       = $T::last_row();
$T::check( 'server event stored', $id > 0 && (int) $row['id'] === $id && 'server' === $row['event_type'] && 'registration' === $row['event_name'] && (int) $row['post_id'] === $post && 'Registration #12' === $row['label'] );
$T::check( 'server event becomes a conversion with goal value', 'registration' === $row['goal'] && 50.0 === (float) $row['value'] && array( 'registration' ) === $completed );
$T::check( 'server event inherits visitor attribution + session', 'social' === $row['channel'] && 'launch' === $row['utm_campaign'] && $row['session_id'] === $view['session_id'] );
$T::check( 'server event inherits device + country', 'mobile' === $row['device'] && 'SG' === $row['country'] );
$id  = tmg_reports_track( 'registration', array( 'post_id' => $post, 'value' => 80, 'visitor_id' => 'abc123', 'source' => array( 'utm_source' => 'qr', 'utm_medium' => 'print', 'utm_campaign' => 'poster' ) ) );
$row = $T::last_row();
$T::check( 'explicit source + value used', 'qr' === $row['channel'] && 'poster' === $row['utm_campaign'] && 80.0 === (float) $row['value'] );
$id = tmg_reports_track( 'registration', array( 'post_id' => 99999999 ) );
$T::check( 'unknown post_id stored as 0', $id > 0 && 0 === (int) $T::last_row()['post_id'] );
$T::check( 'empty event name rejected', 0 === tmg_reports_track( '' ) );
$tmg_drop = fn( $r ) => 'drop-me' === $r['event_name'] ? false : $r;
add_filter( 'tmg_reports_hit_row', $tmg_drop );
$T::check( 'tmg_reports_hit_row can drop a row', 0 === tmg_reports_track( 'drop-me' ) );
remove_filter( 'tmg_reports_hit_row', $tmg_drop );
$seen      = 0;
$tmg_after = function ( $id ) use ( &$seen ) {
	$seen = $id;
};
add_action( 'tmg_reports_hit', $tmg_after );
$id = tmg_reports_track( 'after-hook' );
$T::check( 'tmg_reports_hit fires with the new ID', $id > 0 && $seen === $id );
remove_action( 'tmg_reports_hit', $tmg_after );
remove_action( 'tmg_reports_goal_completed', $tmg_goal_cb, 10 );

/* ------------------------------------------------------------------ */
$T::section( 'Summaries vs raw' );
$day1 = TMG_Reports_Query::shift( TMG_Reports_Query::today(), -6 );
$day2 = TMG_Reports_Query::shift( TMG_Reports_Query::today(), -5 );
// Only test rows on these two days, so the comparison is exact (rolled back at the end).
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . TMG_Reports_Install::hits_table() . ' WHERE created_at BETWEEN %s AND %s', TMG_Reports_Query::utc( $day1 ), TMG_Reports_Query::utc( $day2, true ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
foreach ( array( $day1, $day2 ) as $di => $day ) {
	for ( $s = 0; $s < 6; $s++ ) {
		$vid   = substr( md5( 'tv' . ( $s % 4 ) ), 0, 32 );
		$sess  = substr( md5( 'ts' . $di . $s ), 0, 32 );
		$views = 1 + $s % 3;
		for ( $v = 0; $v < $views; $v++ ) {
			$ts = strtotime( $day . ' 09:00:00 ' . wp_timezone_string() ) + $s * 3600 + $v * 60;
			TMG_Reports_Recorder::insert(
				array(
					'created_at' => gmdate( 'Y-m-d H:i:s', $ts ),
					'post_id'    => 0 === $v ? $post : $page,
					'post_type'  => 0 === $v ? 'post' : 'page',
					'path'       => '/t/' . $v,
					'visitor_id' => $vid,
					'session_id' => $sess,
					'is_new'     => 0 === $s && 0 === $di ? 1 : 0,
					'channel'    => 0 === $s % 2 ? 'search' : 'direct',
					'ref_domain' => 0 === $s % 2 ? 'google.com' : '',
					'device'     => 'mobile',
					'country'    => 'MY',
					'region'     => 'Selangor',
					'duration'   => 10 * ( $v + 1 ),
					'scroll'     => 50,
				)
			);
		}
		if ( 0 === $s % 3 ) {
			TMG_Reports_Recorder::insert( array( 'created_at' => gmdate( 'Y-m-d H:i:s', strtotime( $day . ' 10:00:00 ' . wp_timezone_string() ) ), 'post_id' => $post, 'event_type' => 'server', 'event_name' => 'registration', 'visitor_id' => $vid, 'session_id' => $sess, 'channel' => 'search' ) );
		}
	}
}
$t0 = microtime( true );
TMG_Reports_Summary::build_day( $day1 );
TMG_Reports_Summary::build_day( $day2 );
$build_ms = ( microtime( true ) - $t0 ) * 1000;
update_option( TMG_Reports_Summary::UNTIL, $day2 );
$ok = true;
foreach ( array( '', 'page', 'channel', 'device', 'region', 'event', 'goal', 'hour_weekday', 'new_returning', 'post_type' ) as $dim ) {
	foreach ( array( $day1, $day2 ) as $day ) {
		$raw = TMG_Reports_Query::raw_rows( $dim, TMG_Reports_Query::utc( $day ), TMG_Reports_Query::utc( $day, true ), array() );
		$sum = TMG_Reports_Query::summary_rows( $dim, $day, $day );
		ksort( $raw );
		ksort( $sum );
		$norm = fn( $rows ) => array_map( fn( $r ) => array_map( fn( $v ) => round( (float) $v, 2 ), $r ), $rows );
		if ( $norm( $raw ) !== $norm( $sum ) ) {
			$ok = false;
			echo "     mismatch dim={$dim} day={$day}\n";
		}
	}
}
$T::check( 'summary rows equal raw rows for every dimension and day', $ok );
$a = TMG_Reports_Query::totals( array( 'from' => $day1, 'to' => $day2 ) );
$b = TMG_Reports_Query::totals( array( 'from' => $day1, 'to' => $day2, 'source' => 'raw' ) );
$T::check( 'totals from summaries = totals from raw (2 days)', $a == $b, wp_json_encode( array_diff_assoc( $a, $b ) ) . ' vs ' . wp_json_encode( array_diff_assoc( $b, $a ) ) ); // phpcs:ignore Universal.Operators.StrictComparisons
$T::check( 'range plan uses summaries, then raw for recent days', array( 'summary', 'raw' ) === array_column( TMG_Reports_Query::plan( TMG_Reports_Query::normalize( array( 'from' => $day1, 'to' => TMG_Reports_Query::today() ) ) ), 0 ) );
$T::check( 'filters force raw', array( 'raw' ) === array_column( TMG_Reports_Query::plan( TMG_Reports_Query::normalize( array( 'from' => $day1, 'to' => $day2, 'filters' => array( 'device' => 'mobile' ) ) ) ), 0 ) );
$tp = TMG_Reports_Query::table( array( 'from' => $day1, 'to' => $day2, 'dimension' => 'page', 'limit' => 0 ) );
$tr = TMG_Reports_Query::table( array( 'from' => $day1, 'to' => $day2, 'dimension' => 'page', 'limit' => 0, 'source' => 'raw' ) );
$sum_views = fn( $t ) => array_sum( array_column( $t['rows'], 'views' ) );
$T::check( 'page table views equal (summary vs raw)', $sum_views( $tp ) === $sum_views( $tr ) && $sum_views( $tp ) === $a['views'] );
$series = TMG_Reports_Query::series( array( 'from' => $day1, 'to' => $day2 ), 'day' );
$T::check( 'daily series sums to total views', array_sum( $series['views'] ) === $a['views'] );
$T::check( 'goal conversions counted', $a['conversions'] >= 4 );
printf( "     (summarised 2 days in %.0f ms)\n", $build_ms );

/* ------------------------------------------------------------------ */
$T::section( 'tmg_reports_query()' );
$q = tmg_reports_query( array( 'from' => $day1, 'to' => $day2, 'metrics' => array( 'views', 'visitors' ) ) );
$T::check( 'totals with chosen metrics only', array( 'views', 'visitors' ) === array_keys( $q ) );
$q = tmg_reports_query( array( 'from' => $day1, 'to' => $day2, 'dimension' => 'channel', 'metrics' => array( 'views' ), 'limit' => 1 ) );
$T::check( 'dimension rows with key + label', 1 === count( $q ) && isset( $q[0]['key'], $q[0]['label'], $q[0]['views'] ) && ! isset( $q[0]['visitors'] ) );
$q = tmg_reports_query( array( 'from' => $day1, 'to' => $day2, 'dimensions' => array( 'device' ), 'filters' => array( 'channel' => 'search' ), 'metrics' => array( 'views' ) ) );
$T::check( 'filters applied', 'mobile' === ( $q[0]['key'] ?? '' ) );
$q = tmg_reports_query( array( 'from' => $day1, 'to' => $day2, 'dimension' => 'day' ) );
$T::check( 'day dimension returns one row per day', 2 === count( $q ) );
$q = tmg_reports_query( array( 'from' => $day1, 'to' => $day2, 'filters' => array( 'goal' => 'registration' ), 'metrics' => array( 'visitors' ) ) );
$T::check( 'goal filter keeps converting visitors only', $q['visitors'] > 0 && $q['visitors'] <= $a['visitors'] );

/* ------------------------------------------------------------------ */
$T::section( 'Retention purge' );
$old = TMG_Reports_Recorder::insert( array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 800 * DAY_IN_SECONDS ), 'post_id' => $post ) );
$new = TMG_Reports_Recorder::insert( array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ), 'post_id' => $post ) );
$T::settings( array( 'retention_days' => 730 ) );
$deleted = TMG_Reports_Summary::purge();
$exists  = fn( $id ) => (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . TMG_Reports_Install::hits_table() . ' WHERE id = %d', $id ) );
$T::check( 'old hit purged, recent hit kept', $deleted >= 1 && ! $exists( $old ) && $exists( $new ) );
$T::settings( array( 'retention_days' => 0 ) );
$T::check( 'retention 0 keeps everything', 0 === TMG_Reports_Summary::purge() );

/* ------------------------------------------------------------------ */
$T::section( 'Settings export / import' );
TMG_Reports_Settings::save( array( 'geo_token' => 'tok_SECRET_123', 'rate_limit' => 111 ) );
$stored = get_option( TMG_Reports_Settings::OPTION );
$T::check( 'secret stored encrypted', false === strpos( (string) $stored['geo_token'], 'SECRET' ) && 'tok_SECRET_123' === TMG_Reports_Settings::get( 'geo_token' ) );
TMG_Reports_Settings::save( array( 'geo_token' => '' ) );
$T::check( 'empty secret keeps the saved one', 'tok_SECRET_123' === TMG_Reports_Settings::get( 'geo_token' ) );
$export = TMG_Reports_Settings::export();
$T::check( 'export shape + no secrets', 'tamago-reports' === $export['plugin'] && isset( $export['version'], $export['exported'] ) && ! isset( $export['settings']['geo_token'] ) && 111 === $export['settings']['rate_limit'] );
$export['settings']['rate_limit']    = 222;
$export['settings']['geo_token']     = 'injected';
$export['settings']['exclude_ips']   = array( '10.0.0.0/8', 'garbage' );
$export['settings']['unknown_key']   = 'x';
$res = TMG_Reports_Settings::import( json_decode( (string) wp_json_encode( $export ), true ) );
$T::check( 'import applies + sanitises values', true === $res && 222 === TMG_Reports_Settings::get( 'rate_limit' ) && array( '10.0.0.0/8' ) === TMG_Reports_Settings::get( 'exclude_ips' ) );
$T::check( 'import never overwrites secrets', 'tok_SECRET_123' === TMG_Reports_Settings::get( 'geo_token' ) );
$T::check( 'import rejects other files', is_wp_error( TMG_Reports_Settings::import( array( 'plugin' => 'tamago-smtp', 'settings' => array() ) ) ) );
TMG_Reports_Settings::save( array(), array( 'geo_token' ) );
$T::check( 'secret can be cleared', '' === TMG_Reports_Settings::get( 'geo_token' ) );
$T::check( 'crypto round trip', 'hello' === TMG_Reports_Crypto::decrypt( TMG_Reports_Crypto::encrypt( 'hello' ) ) );

/* ------------------------------------------------------------------ */
$T::section( 'Capabilities' );
$perms = apply_filters( 'tamago_permissions', array() );
$T::check( 'tamago_permissions lists view / export / manage', array( 'tmg_reports_view' ) === ( $perms['reports_view']['caps'] ?? null ) && array( 'tmg_reports_export' ) === ( $perms['reports_export']['caps'] ?? null ) && array( 'tmg_reports_manage' ) === ( $perms['reports_manage']['caps'] ?? null ) );
$role = get_role( 'administrator' );
$T::check( 'administrator role has all three caps', $role && $role->has_cap( 'tmg_reports_view' ) && $role->has_cap( 'tmg_reports_export' ) && $role->has_cap( 'tmg_reports_manage' ) );
$ed_id = wp_insert_user( array( 'user_login' => 'tmg_test_ed_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'editor' ) );
wp_set_current_user( $sub_id );
$T::check( 'subscriber cannot view / export / manage', ! current_user_can( 'tmg_reports_view' ) && ! TMG_Reports_Export::can_export() && ! current_user_can( 'tmg_reports_manage' ) );
wp_set_current_user( $ed_id );
$T::check( 'editor without the caps cannot view', ! current_user_can( 'tmg_reports_view' ) );
$ed = new WP_User( $ed_id );
$ed->add_cap( 'tmg_reports_view' );
wp_set_current_user( 0 );
wp_set_current_user( $ed_id );
$T::check( 'granting tmg_reports_view gives view but not export / manage', current_user_can( 'tmg_reports_view' ) && ! TMG_Reports_Export::can_export() && ! current_user_can( 'tmg_reports_manage' ) );
$T::check( 'settings tab hidden without manage cap', ! isset( TMG_Reports_Admin_Screen::tabs()['settings'] ) );
if ( $admin ) {
	wp_set_current_user( $admin->ID );
	$T::check( 'admin sees settings tab', isset( TMG_Reports_Admin_Screen::tabs()['settings'] ) );
}
wp_set_current_user( 0 );
$T::check( 'collect endpoint is public (no capability)', 200 === $T::collect( array( 't' => 'pageview', 'p' => $post, 'k' => 'singular', 'u' => $home . '?p=' . $post ), array( 'REMOTE_ADDR' => '198.51.100.90' ) )->get_status() );
$routes = rest_get_server()->get_routes();
$T::check( 'REST route registered', isset( $routes['/tamago-reports/v1/collect'] ) );

/* ------------------------------------------------------------------ */
$T::section( 'Front-end script' );
$js  = (string) file_get_contents( TMG_REPORTS_DIR . 'assets/js/track.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$min = preg_replace( array( '#/\*.*?\*/#s', '#^\s*//.*$#m', '/\s+/' ), array( '', '', ' ' ), $js );
$min = preg_replace( '/\s*([{}();,:=+\-<>!&|?\[\]])\s*/', '$1', $min );
$T::check( sprintf( 'track.js under 6 KB minified-equivalent (%d bytes, %d raw)', strlen( $min ), strlen( $js ) ), strlen( $min ) < 6144 );
$T::check( 'uses sendBeacon + fetch keepalive + pushState', false !== strpos( $js, 'sendBeacon' ) && false !== strpos( $js, 'keepalive' ) && false !== strpos( $js, 'pushState' ) );

/* ------------------------------------------------------------------ */
$T::section( 'wp-config constant locks' );
if ( ! defined( 'TMG_REPORTS_RATE_LIMIT' ) ) {
	define( 'TMG_REPORTS_RATE_LIMIT', '42' );
}
TMG_Reports_Settings::flush();
$T::check( 'constant overrides the stored value (sanitised)', 42 === TMG_Reports_Settings::get( 'rate_limit' ) && TMG_Reports_Settings::locked( 'rate_limit' ) );
TMG_Reports_Settings::save( array( 'rate_limit' => 7 ) );
$stored = get_option( TMG_Reports_Settings::OPTION );
$T::check( 'saving ignores a locked key', 42 === TMG_Reports_Settings::get( 'rate_limit' ) && 7 !== ( $stored['rate_limit'] ?? null ) );

/* ------------------------------------------------------------------ */
$T::section( 'done' );
$wpdb->query( 'ROLLBACK' );
$_SERVER = $tmg_old_server;
wp_cache_flush();
TMG_Reports_Settings::flush();
printf( "\nTOTAL: %d passed, %d failed (%.2f s)\n", TMG_Reports_Test::$pass, TMG_Reports_Test::$fail, microtime( true ) - $tmg_t0 );
if ( TMG_Reports_Test::$fail > 0 && class_exists( 'WP_CLI' ) ) {
	WP_CLI::halt( 1 );
}
