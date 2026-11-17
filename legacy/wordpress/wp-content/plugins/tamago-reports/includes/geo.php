<?php
/**
 * Visitor location: CDN / proxy headers (Cloudflare, CloudFront, generic GeoIP), or an
 * optional lookup provider (ipapi.co, ipinfo.io) that the admin must turn on. Lookups are
 * cached for 7 days per IP hash and time out after 2 seconds.
 *
 * Used by: TMG_Reports_Collect, TMG_Reports_Recorder (server events).
 */

defined( 'ABSPATH' ) || exit;

class TMG_Reports_Geo {

	/** @return array{country:string,country_name:string,region:string,city:string} */
	public static function lookup( string $ip ): array {
		$out      = array( 'country' => '', 'country_name' => '', 'region' => '', 'city' => '' );
		$provider = (string) TMG_Reports_Settings::get( 'geo_provider' );
		if ( 'none' === $provider ) {
			return $out;
		}

		$h  = static function ( string $key ): string {
			return isset( $_SERVER[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		};
		$cc = strtoupper( $h( 'HTTP_CF_IPCOUNTRY' ) ?: $h( 'HTTP_CLOUDFRONT_VIEWER_COUNTRY' ) ?: $h( 'HTTP_X_COUNTRY_CODE' ) ?: $h( 'GEOIP_COUNTRY_CODE' ) ?: $h( 'HTTP_X_GEO_COUNTRY' ) );
		if ( preg_match( '/^[A-Z]{2}$/', $cc ) && ! in_array( $cc, array( 'XX', 'T1', 'ZZ' ), true ) ) {
			$out['country']      = $cc;
			$out['country_name'] = TMG_Reports_Labels::country( $cc );
			$out['region']       = mb_substr( $h( 'HTTP_CF_REGION' ) ?: $h( 'HTTP_CLOUDFRONT_VIEWER_COUNTRY_REGION_NAME' ) ?: $h( 'HTTP_X_GEO_REGION' ) ?: $h( 'GEOIP_REGION_NAME' ), 0, 80 );
			$out['city']         = mb_substr( $h( 'HTTP_CF_IPCITY' ) ?: $h( 'HTTP_CLOUDFRONT_VIEWER_CITY' ) ?: $h( 'HTTP_X_GEO_CITY' ) ?: $h( 'GEOIP_CITY' ), 0, 80 );
		}

		if ( 'headers' === $provider || ( '' !== $out['city'] && '' !== $out['country'] ) ) {
			return $out;
		}
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return $out;
		}

		$key    = 'tmg_reports_geo_' . md5( wp_hash( $ip ) );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return array_merge( $out, $cached );
		}
		if ( 'ipinfo' === $provider ) {
			$token = (string) TMG_Reports_Settings::get( 'geo_token' );
			$url   = 'https://ipinfo.io/' . rawurlencode( $ip ) . '/json' . ( '' !== $token ? '?token=' . rawurlencode( $token ) : '' );
		} else {
			$url = 'https://ipapi.co/' . rawurlencode( $ip ) . '/json/';
		}
		$res = wp_safe_remote_get(
			$url,
			array(
				'timeout'    => 2,
				'user-agent' => 'TamagoReports/' . TMG_REPORTS_VERSION,
			)
		);
		if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
			$j = json_decode( (string) wp_remote_retrieve_body( $res ), true );
			if ( is_array( $j ) ) {
				$code = strtoupper( substr( (string) ( $j['country_code'] ?? $j['country'] ?? '' ), 0, 2 ) );
				if ( preg_match( '/^[A-Z]{2}$/', $code ) ) {
					$out['country']      = $code;
					$out['country_name'] = mb_substr( sanitize_text_field( (string) ( $j['country_name'] ?? TMG_Reports_Labels::country( $code ) ) ), 0, 60 );
					$out['region']       = mb_substr( sanitize_text_field( (string) ( $j['region'] ?? '' ) ), 0, 80 );
					$out['city']         = mb_substr( sanitize_text_field( (string) ( $j['city'] ?? '' ) ), 0, 80 );
				}
			}
		}
		set_transient( $key, $out, 7 * DAY_IN_SECONDS );
		return $out;
	}
}
