<?php
/**
 * Human labels for report keys: page titles, post types, channels, countries, regions,
 * cities, events, goals, devices, heatmap cells.
 *
 * Used by: TMG_Reports_Query (row labels), report screens, CSV export, TMG_Reports_Geo.
 */

defined( 'ABSPATH' ) || exit;

class TMG_Reports_Labels {

	const COUNTRIES = array(
		'AE' => 'United Arab Emirates', 'AR' => 'Argentina', 'AT' => 'Austria', 'AU' => 'Australia', 'BD' => 'Bangladesh', 'BE' => 'Belgium',
		'BN' => 'Brunei', 'BR' => 'Brazil', 'CA' => 'Canada', 'CH' => 'Switzerland', 'CL' => 'Chile', 'CN' => 'China', 'CO' => 'Colombia',
		'CZ' => 'Czechia', 'DE' => 'Germany', 'DK' => 'Denmark', 'EG' => 'Egypt', 'ES' => 'Spain', 'FI' => 'Finland', 'FR' => 'France',
		'GB' => 'United Kingdom', 'GR' => 'Greece', 'HK' => 'Hong Kong', 'HU' => 'Hungary', 'ID' => 'Indonesia', 'IE' => 'Ireland',
		'IL' => 'Israel', 'IN' => 'India', 'IT' => 'Italy', 'JP' => 'Japan', 'KE' => 'Kenya', 'KH' => 'Cambodia', 'KR' => 'South Korea',
		'KW' => 'Kuwait', 'LA' => 'Laos', 'LK' => 'Sri Lanka', 'MM' => 'Myanmar', 'MO' => 'Macau', 'MX' => 'Mexico', 'MY' => 'Malaysia',
		'NG' => 'Nigeria', 'NL' => 'Netherlands', 'NO' => 'Norway', 'NP' => 'Nepal', 'NZ' => 'New Zealand', 'OM' => 'Oman', 'PE' => 'Peru',
		'PH' => 'Philippines', 'PK' => 'Pakistan', 'PL' => 'Poland', 'PT' => 'Portugal', 'QA' => 'Qatar', 'RO' => 'Romania', 'RU' => 'Russia',
		'SA' => 'Saudi Arabia', 'SE' => 'Sweden', 'SG' => 'Singapore', 'TH' => 'Thailand', 'TR' => 'Türkiye', 'TW' => 'Taiwan',
		'UA' => 'Ukraine', 'US' => 'United States', 'VN' => 'Vietnam', 'ZA' => 'South Africa',
	);

	public static function country( string $cc ): string {
		$cc = strtoupper( $cc );
		if ( '' === $cc ) {
			return '';
		}
		if ( class_exists( 'Locale' ) ) {
			$name = Locale::getDisplayRegion( '-' . $cc, 'en' );
			if ( is_string( $name ) && '' !== $name && $name !== $cc ) {
				return $name;
			}
		}
		return self::COUNTRIES[ $cc ] ?? $cc;
	}

	public static function post_type( string $type ): string {
		if ( '' === $type ) {
			return __( '(other pages)', 'tamago-reports' );
		}
		$obj = get_post_type_object( $type );
		if ( $obj ) {
			return (string) $obj->labels->name;
		}
		$types = TMG_Reports_Targeting::types();
		return $types[ $type ] ?? $type;
	}

	public static function event_type( string $type ): string {
		$map = array(
			'outbound' => __( 'Outbound link', 'tamago-reports' ),
			'download' => __( 'Download', 'tamago-reports' ),
			'mailto'   => __( 'Email link', 'tamago-reports' ),
			'tel'      => __( 'Phone link', 'tamago-reports' ),
			'click'    => __( 'Click', 'tamago-reports' ),
			'form'     => __( 'Form submit', 'tamago-reports' ),
			'custom'   => __( 'Event', 'tamago-reports' ),
			'server'   => __( 'Server event', 'tamago-reports' ),
			'submit'   => __( 'Submission', 'tamago-reports' ),
		);
		return $map[ $type ] ?? $type;
	}

	/** Label for a key of a dimension. */
	public static function label( string $dim, string $key ): string {
		switch ( $dim ) {
			case 'page':
				if ( ctype_digit( $key ) && '0' !== $key ) {
					$title = get_the_title( (int) $key );
					return '' !== $title ? html_entity_decode( $title, ENT_QUOTES, 'UTF-8' ) : '#' . $key;
				}
				return '' === $key ? '/' : $key;
			case 'post_type':
				return self::post_type( $key );
			case 'channel':
				$c = TMG_Reports_Channels::labels();
				return $c[ $key ] ?? ( '' === $key ? __( '(none)', 'tamago-reports' ) : $key );
			case 'country':
				return '' === $key ? __( '(unknown)', 'tamago-reports' ) : self::country( $key );
			case 'region':
			case 'city':
				$parts = explode( '|', $key );
				$cc    = array_shift( $parts );
				$parts = array_reverse( array_filter( $parts, 'strlen' ) );
				if ( ! $parts ) {
					return __( '(unknown)', 'tamago-reports' ) . ( $cc ? ', ' . self::country( $cc ) : '' );
				}
				$parts[] = self::country( $cc );
				return implode( ', ', array_filter( $parts, 'strlen' ) );
			case 'event':
				$parts = explode( '|', $key, 3 );
				$type  = $parts[0] ?? '';
				$name  = $parts[1] ?? '';
				$label = $parts[2] ?? '';
				$head  = in_array( $name, array( '', $type ), true ) ? self::event_type( $type ) : $name;
				return $head . ( '' !== $label ? ' — ' . $label : '' );
			case 'goal':
				return TMG_Reports_Goals::name( $key );
			case 'new_returning':
				return '1' === $key || 'new' === $key ? __( 'New visitors', 'tamago-reports' ) : __( 'Returning visitors', 'tamago-reports' );
			case 'device':
				$map = array(
					'desktop' => __( 'Desktop', 'tamago-reports' ),
					'mobile'  => __( 'Mobile', 'tamago-reports' ),
					'tablet'  => __( 'Tablet', 'tamago-reports' ),
				);
				return $map[ $key ] ?? ( '' === $key ? __( '(unknown)', 'tamago-reports' ) : $key );
			case 'ref_domain':
				return '' === $key ? __( '(direct / none)', 'tamago-reports' ) : $key;
		}
		return '' === trim( $key ) ? __( '(not set)', 'tamago-reports' ) : $key;
	}
}
