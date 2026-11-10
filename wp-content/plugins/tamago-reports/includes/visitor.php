<?php
/**
 * Visitor identity without raw IPs: client IP detection (optional trusted proxy header),
 * monthly-salted IP hash, the daily-rotating cookieless visitor ID, and IP / CIDR matching.
 *
 * Used by: TMG_Reports_Collect, TMG_Reports_Recorder, TMG_Reports_Targeting, settings sanitising.
 */

defined( 'ABSPATH' ) || exit;

class TMG_Reports_Visitor {

	const SALT_OPTION = 'tmg_reports_daily_salt';

	public static function client_ip(): string {
		$headers = array( 'REMOTE_ADDR' );
		$custom  = (string) TMG_Reports_Settings::get( 'ip_header' );
		if ( '' !== $custom ) {
			array_unshift( $headers, $custom );
		}
		foreach ( $headers as $h ) {
			if ( ! empty( $_SERVER[ $h ] ) ) {
				$ip = trim( explode( ',', sanitize_text_field( wp_unslash( (string) $_SERVER[ $h ] ) ) )[0] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}
		return '0.0.0.0';
	}

	public static function user_agent(): string {
		return isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ), 0, 512 ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	/** Same algorithm as Event Plus `epc_visits.ip_hash`, so hashes stay comparable. */
	public static function ip_hash( string $ip ): string {
		return substr( wp_hash( $ip . gmdate( 'Y-m' ) ), 0, 32 );
	}

	/** Random salt for the current UTC day; the previous day's salt is discarded. */
	public static function daily_salt(): string {
		$today = gmdate( 'Y-m-d' );
		$salt  = get_option( self::SALT_OPTION );
		if ( ! is_array( $salt ) || ( $salt['day'] ?? '' ) !== $today || empty( $salt['salt'] ) ) {
			$salt = array(
				'day'  => $today,
				'salt' => bin2hex( random_bytes( 16 ) ),
			);
			update_option( self::SALT_OPTION, $salt, true );
		}
		return (string) $salt['salt'];
	}

	/** Cookieless visitor ID: changes every day, cannot be reversed to an IP. */
	public static function daily_id( string $ip, string $ua ): string {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		return substr( hash_hmac( 'sha256', $ip . '|' . $ua . '|' . $host, self::daily_salt() ), 0, 32 );
	}

	public static function random_id(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	public static function valid_ip_or_cidr( string $entry ): bool {
		if ( false !== strpos( $entry, '/' ) ) {
			list( $net, $bits ) = explode( '/', $entry, 2 );
			if ( ! ctype_digit( $bits ) || ! filter_var( $net, FILTER_VALIDATE_IP ) ) {
				return false;
			}
			$max = filter_var( $net, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ? 128 : 32;
			return (int) $bits <= $max;
		}
		return (bool) filter_var( $entry, FILTER_VALIDATE_IP );
	}

	public static function ip_matches( string $ip, string $entry ): bool {
		$bin = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $bin ) {
			return false;
		}
		if ( false === strpos( $entry, '/' ) ) {
			$e = @inet_pton( $entry ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return false !== $e && $e === $bin;
		}
		list( $net, $bits ) = explode( '/', $entry, 2 );
		$net_bin            = @inet_pton( $net ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $net_bin || strlen( $net_bin ) !== strlen( $bin ) ) {
			return false;
		}
		$bits  = (int) $bits;
		$bytes = intdiv( $bits, 8 );
		if ( substr( $bin, 0, $bytes ) !== substr( $net_bin, 0, $bytes ) ) {
			return false;
		}
		$rest = $bits % 8;
		if ( 0 === $rest ) {
			return true;
		}
		$mask = chr( ( 0xff << ( 8 - $rest ) ) & 0xff );
		return ( $bin[ $bytes ] & $mask ) === ( $net_bin[ $bytes ] & $mask );
	}

	public static function ip_in_list( string $ip, array $list ): bool {
		foreach ( $list as $entry ) {
			if ( self::ip_matches( $ip, (string) $entry ) ) {
				return true;
			}
		}
		return false;
	}
}
