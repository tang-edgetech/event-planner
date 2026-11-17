<?php
/**
 * Traffic channel rules: direct / search / social / email / paid / qr / campaign / referral.
 * Rules are an ordered list (filterable with `tmg_reports_channels`); the first match wins.
 *
 * Used by: TMG_Reports_Recorder, tests.
 */

defined( 'ABSPATH' ) || exit;

class TMG_Reports_Channels {

	const SOCIAL_HOSTS = '/(^|\.)(facebook\.com|fb\.me|fb\.com|instagram\.com|linkedin\.com|lnkd\.in|t\.co|twitter\.com|x\.com|threads\.net|threads\.com|tiktok\.com|youtube\.com|youtu\.be|pinterest\.[a-z.]+|reddit\.com|whatsapp\.com|wa\.me|telegram\.org|t\.me|line\.me|weibo\.com|xiaohongshu\.com|xhslink\.com|wechat\.com|snapchat\.com|tumblr\.com|vk\.com|quora\.com|discord\.com|mastodon\.social|bsky\.app)$/';

	const SOCIAL_SOURCES = '/^(facebook|fb|ig|instagram|linkedin|twitter|x|threads|tiktok|youtube|pinterest|reddit|whatsapp|telegram|line|wechat|xiaohongshu|xhs|snapchat|weibo|discord|bluesky|social)\b/';

	const SEARCH_HOSTS = '/(^|\.)(google\.[a-z.]+|bing\.com|yahoo\.[a-z.]+|duckduckgo\.com|baidu\.com|yandex\.[a-z.]+|ecosia\.org|naver\.com|seznam\.cz|qwant\.com|search\.brave\.com|startpage\.com|sogou\.com|so\.com|ask\.com|aol\.com)$|^search\./';

	/**
	 * Each rule: channel + any of medium / source / referrer regexes. A rule matches
	 * when at least one of its patterns matches.
	 */
	public static function rules(): array {
		$rules = array(
			array( 'channel' => 'qr', 'medium' => '/^qr(code)?$/', 'source' => '/^qr(code)?$/' ),
			array( 'channel' => 'paid', 'medium' => '/cpc|ppc|cpm|cpv|paid|^ads?$|display|banner|retargeting/' ),
			array( 'channel' => 'email', 'medium' => '/e-?mail|newsletter|edm/', 'source' => '/e-?mail|newsletter|edm|mailchimp|sendinblue|brevo/' ),
			array( 'channel' => 'social', 'medium' => '/^social|^sm$/', 'source' => self::SOCIAL_SOURCES, 'referrer' => self::SOCIAL_HOSTS ),
			array( 'channel' => 'search', 'medium' => '/^organic$/', 'referrer' => self::SEARCH_HOSTS ),
		);
		return (array) apply_filters( 'tmg_reports_channels', $rules );
	}

	public static function channel( string $ref_host, array $utm ): string {
		$medium   = strtolower( trim( (string) ( $utm['medium'] ?? '' ) ) );
		$source   = strtolower( trim( (string) ( $utm['source'] ?? '' ) ) );
		$ref_host = strtolower( preg_replace( '/^www\./', '', $ref_host ) );
		$fields   = array(
			'medium'   => $medium,
			'source'   => $source,
			'referrer' => $ref_host,
		);
		foreach ( self::rules() as $rule ) {
			if ( empty( $rule['channel'] ) ) {
				continue;
			}
			foreach ( $fields as $field => $value ) {
				if ( '' !== $value && ! empty( $rule[ $field ] ) && @preg_match( $rule[ $field ], $value ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- tolerate bad filter regexes.
					return substr( sanitize_key( $rule['channel'] ), 0, 20 );
				}
			}
		}
		if ( '' !== $source || '' !== $medium ) {
			return 'campaign';
		}
		return '' !== $ref_host ? 'referral' : 'direct';
	}

	public static function labels(): array {
		return array(
			'direct'   => __( 'Direct', 'tamago-reports' ),
			'search'   => __( 'Search', 'tamago-reports' ),
			'social'   => __( 'Social', 'tamago-reports' ),
			'email'    => __( 'Email', 'tamago-reports' ),
			'paid'     => __( 'Paid', 'tamago-reports' ),
			'qr'       => __( 'QR code', 'tamago-reports' ),
			'campaign' => __( 'Campaign', 'tamago-reports' ),
			'referral' => __( 'Referral', 'tamago-reports' ),
		);
	}
}
