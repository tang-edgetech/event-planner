<?php
/**
 * Field format validation + normalisation (NRIC, phone, email ...).
 * Each validator returns [ bool $ok, string $normalised, string $error ].
 *
 * Used by: the registration form and the applicant edit screen.
 */

defined( 'ABSPATH' ) || exit;

class EPC_Validator {

	/** Malaysian NRIC place-of-birth codes considered valid. */
	private static function valid_pb( int $pb ): bool {
		return ( $pb >= 1 && $pb <= 16 ) || ( $pb >= 21 && $pb <= 59 ) || ( $pb >= 60 && $pb <= 68 )
			|| in_array( $pb, array( 71, 72, 74, 75, 76, 77, 78, 79, 82, 83, 84, 85, 86, 87, 88, 89, 90, 91, 92, 93, 98, 99 ), true );
	}

	/**
	 * NRIC. Formats: my (Malaysian MyKad), my_or_passport, any.
	 */
	public static function nric( string $value, string $format = 'my' ): array {
		$raw = strtoupper( trim( $value ) );

		$digits = preg_replace( '/[\s\-]/', '', $raw );
		$is_my  = (bool) preg_match( '/^\d{12}$/', $digits );

		if ( $is_my ) {
			$yy = (int) substr( $digits, 0, 2 );
			$mm = (int) substr( $digits, 2, 2 );
			$dd = (int) substr( $digits, 4, 2 );
			$pb = (int) substr( $digits, 6, 2 );
			// Century is ambiguous; check day/month validity against a leap-year-safe year.
			$year  = 2000 + $yy;
			$valid = checkdate( $mm, $dd, $year ) || checkdate( $mm, $dd, 1900 + $yy );
			if ( $valid && self::valid_pb( $pb ) ) {
				return array( true, substr( $digits, 0, 6 ) . '-' . substr( $digits, 6, 2 ) . '-' . substr( $digits, 8 ), '' );
			}
			if ( 'any' !== $format ) {
				return array( false, $raw, 'Please enter a valid Malaysian NRIC (e.g. 900101-14-5678).' );
			}
		}

		if ( 'my' === $format ) {
			return array( false, $raw, 'Please enter a valid 12-digit Malaysian NRIC (e.g. 900101-14-5678).' );
		}
		if ( 'my_or_passport' === $format ) {
			if ( preg_match( '/^[A-Z0-9]{6,12}$/', $digits ) ) {
				return array( true, $digits, '' );
			}
			return array( false, $raw, 'Please enter a valid NRIC or passport number.' );
		}
		// any
		if ( strlen( $digits ) >= 4 && strlen( $digits ) <= 30 ) {
			return array( true, $raw, '' );
		}
		return array( false, $raw, 'Please enter a valid ID number.' );
	}

	/**
	 * Phone. Formats: my (Malaysia, normalised to +60…), intl (E.164 with country code), any.
	 */
	public static function phone( string $value, string $format = 'my' ): array {
		$raw    = trim( $value );
		$plus   = str_starts_with( $raw, '+' );
		$digits = preg_replace( '/\D/', '', $raw );

		if ( 'my' === $format ) {
			if ( str_starts_with( $digits, '60' ) ) {
				$digits = substr( $digits, 2 );
			} elseif ( str_starts_with( $digits, '0' ) ) {
				$digits = substr( $digits, 1 );
			}
			// Mobile 1X + 7-8 digits; landline 3-9 + 7-8 digits.
			if ( preg_match( '/^(1\d{8,9}|[3-9]\d{7,8})$/', $digits ) ) {
				return array( true, '+60' . $digits, '' );
			}
			return array( false, $raw, 'Please enter a valid Malaysian phone number (e.g. 012-345 6789).' );
		}

		if ( 'intl' === $format ) {
			if ( ( $plus || strlen( $digits ) >= 10 ) && preg_match( '/^[1-9]\d{7,14}$/', $digits ) ) {
				return array( true, '+' . $digits, '' );
			}
			return array( false, $raw, 'Please include the country code (e.g. +60 12-345 6789).' );
		}

		if ( preg_match( '/^\d{6,20}$/', $digits ) ) {
			return array( true, $raw, '' );
		}
		return array( false, $raw, 'Please enter a valid phone number.' );
	}

	public static function email( string $value ): array {
		$v = sanitize_email( trim( $value ) );
		if ( $v && is_email( $v ) ) {
			return array( true, strtolower( $v ), '' );
		}
		return array( false, $value, 'Please enter a valid email address.' );
	}

	public static function number( string $value ): array {
		$v = trim( $value );
		return is_numeric( $v ) ? array( true, $v, '' ) : array( false, $v, 'Please enter a number.' );
	}

	public static function date( string $value ): array {
		$v = trim( $value );
		$d = DateTime::createFromFormat( 'Y-m-d', $v );
		return ( $d && $d->format( 'Y-m-d' ) === $v ) ? array( true, $v, '' ) : array( false, $v, 'Please enter a valid date.' );
	}

	/** Client-side patterns mirroring the rules above (for instant feedback). */
	public static function js_patterns(): array {
		return array(
			'nric'  => array(
				'my'             => '^\s*\d{6}[\s-]?\d{2}[\s-]?\d{4}\s*$',
				'my_or_passport' => '^\s*([0-9]{6}[\s-]?[0-9]{2}[\s-]?[0-9]{4}|[A-Za-z0-9]{6,12})\s*$',
				'any'            => '.{4,30}',
			),
			'phone' => array(
				'my'   => '^\s*(\+?6?0)?[\s-]?1\d[\s-]?\d{3,4}[\s-]?\d{4}\s*$|^\s*(\+?6?0)?[3-9][\s-]?\d{3,4}[\s-]?\d{4}\s*$',
				'intl' => '^\s*\+?[1-9][\d\s-]{7,18}\s*$',
				'any'  => '^[\d\s+()-]{6,25}$',
			),
		);
	}
}
