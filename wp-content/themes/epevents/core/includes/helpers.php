<?php
/**
 * Shared helpers.
 */

defined( 'ABSPATH' ) || exit;

/** ACF returns false/null for empty repeaters; normalise to an array. */
function epc_list( $value ): array {
	return is_array( $value ) ? $value : array();
}

/**
 * Read a global setting (ACF options page) with a default.
 */
function epc_setting( string $name, $default = null ) {
	if ( ! function_exists( 'get_field' ) ) {
		return $default;
	}
	$value = get_field( $name, 'option' );
	return ( null === $value || '' === $value || false === $value && ! is_bool( $default ) ) ? $default : $value;
}

/**
 * All reusable option lists ("presets") configured in Event Plus → Settings.
 * Returns [ slug => [ 'name' => string, 'options' => string[] ] ].
 */
function epc_preset_lists(): array {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}
	$cache = array();
	$rows  = function_exists( 'get_field' ) ? get_field( 'epc_preset_lists', 'option' ) : array();
	foreach ( epc_list( $rows ) as $row ) {
		$name = trim( (string) ( $row['name'] ?? '' ) );
		if ( '' === $name ) {
			continue;
		}
		$slug           = sanitize_title( $row['slug'] ?? '' ) ?: sanitize_title( $name );
		$cache[ $slug ] = array(
			'name'    => $name,
			'options' => epc_lines( $row['options'] ?? '' ),
		);
	}
	return $cache;
}

/** Split a textarea into trimmed, non-empty lines. */
function epc_lines( $text ): array {
	$lines = preg_split( '/\r\n|\r|\n/', (string) $text );
	return array_values( array_filter( array_map( 'trim', $lines ), 'strlen' ) );
}

/**
 * Field types available in the form builder.
 */
function epc_field_types(): array {
	return array(
		'salutation' => 'Salutation (uses Settings list)',
		'text'       => 'Text',
		'textarea'   => 'Paragraph text',
		'email'      => 'Email address',
		'phone'      => 'Phone number',
		'nric'       => 'NRIC / ID number',
		'number'     => 'Number',
		'date'       => 'Date',
		'select'     => 'Dropdown',
		'radio'      => 'Radio buttons',
		'checkboxes' => 'Checkboxes (multiple)',
		'country'    => 'Country',
		'consent'    => 'Consent / agreement checkbox',
		'hidden'     => 'Hidden value',
		'heading'    => 'Section heading (layout only)',
		'html'       => 'Text / HTML block (layout only)',
	);
}

/** Types that only output layout and never collect data. */
function epc_layout_types(): array {
	return array( 'heading', 'html' );
}

/**
 * Normalised form-field definitions for an event.
 *
 * @return array<int, array> each with keys: name,type,label,placeholder,help,required,width,options,allow_other,format,unique,default,content,conditions
 * Dropdown answers that were never chosen can come back as false (ACF select default), so they fall back with ?: .
 */
function epc_get_form_fields( int $event_id ): array {
	$rows   = function_exists( 'get_field' ) ? get_field( 'form_fields', $event_id ) : array();
	$fields = array();
	$used   = array();

	foreach ( epc_list( $rows ) as $i => $row ) {
		$type  = ( $row['type'] ?? '' ) ?: 'text';
		$label = trim( (string) ( $row['label'] ?? '' ) );
		$name  = sanitize_key( str_replace( ' ', '-', strtolower( (string) ( $row['name'] ?? '' ) ) ) );

		if ( '' === $name ) {
			$name = sanitize_title( $label ) ?: $type . '-' . $i;
		}
		if ( 'salutation' === $type && '' === trim( (string) ( $row['name'] ?? '' ) ) ) {
			$name = 'salutation';
		}
		// Guarantee uniqueness.
		$base = $name;
		$n    = 2;
		while ( isset( $used[ $name ] ) ) {
			$name = $base . '-' . $n++;
		}
		$used[ $name ] = true;

		$options = array();
		if ( in_array( $type, array( 'select', 'radio', 'checkboxes' ), true ) ) {
			if ( 'preset' === ( ( $row['options_source'] ?? '' ) ?: 'custom' ) ) {
				$lists   = epc_preset_lists();
				$options = $lists[ (string) ( $row['preset'] ?? '' ) ]['options'] ?? array();
			} else {
				$options = epc_lines( $row['options'] ?? '' );
			}
		} elseif ( 'salutation' === $type ) {
			$lists   = epc_preset_lists();
			$options = $lists['salutation']['options'] ?? array( 'Mr.', 'Ms.', 'Mrs.', 'Dr.', 'Prof.' );
		} elseif ( 'country' === $type ) {
			$options = epc_countries();
		}

		$conditions = array();
		if ( ! empty( $row['conditional'] ) ) {
			$conditions = array(
				'field'    => sanitize_key( (string) ( $row['cond_field'] ?? '' ) ),
				'operator' => ( $row['cond_operator'] ?? '' ) ?: 'is',
				'value'    => (string) ( $row['cond_value'] ?? '' ),
			);
		}

		$format = '';
		if ( 'phone' === $type ) {
			$format = ( $row['phone_format'] ?? '' ) ?: 'my';
		} elseif ( 'nric' === $type ) {
			$format = ( $row['nric_format'] ?? '' ) ?: 'my';
		}

		$fields[] = array(
			'name'        => $name,
			'type'        => $type,
			'label'       => $label,
			'placeholder' => (string) ( $row['placeholder'] ?? '' ),
			'help'        => (string) ( $row['help'] ?? '' ),
			'required'    => ! empty( $row['required'] ),
			'width'       => (string) ( ( $row['width'] ?? '' ) ?: '100' ),
			'options'     => $options,
			'allow_other' => ! empty( $row['allow_other'] ) && in_array( $type, array( 'select', 'radio', 'checkboxes', 'salutation' ), true ),
			'format'      => $format,
			'unique'      => ! empty( $row['unique'] ),
			'default'     => (string) ( $row['default'] ?? '' ),
			'content'     => (string) ( $row['content'] ?? '' ),
			'conditions'  => $conditions,
		);
	}

	return apply_filters( 'epc_form_fields', $fields, $event_id );
}

/** Data-collecting fields only. */
function epc_get_data_fields( int $event_id ): array {
	return array_values( array_filter( epc_get_form_fields( $event_id ), fn( $f ) => ! in_array( $f['type'], epc_layout_types(), true ) ) );
}

/**
 * Replace {field-name} / {event_title} style placeholders.
 */
function epc_replace_placeholders( string $text, array $data, int $event_id ): string {
	$map = array(
		'{event_title}' => get_the_title( $event_id ),
		'{event_date}'  => (string) get_field( 'event_date_text', $event_id ),
		'{event_time}'  => (string) get_field( 'event_time_text', $event_id ),
		'{event_venue}' => (string) get_field( 'venue', $event_id ),
		'{event_url}'   => get_permalink( $event_id ),
		'{site_name}'   => get_bloginfo( 'name' ),
	);
	foreach ( $data as $key => $value ) {
		$map[ '{' . $key . '}' ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
	}
	$text = strtr( $text, $map );
	// Drop unknown placeholders (e.g. a field not present on this form).
	return (string) preg_replace( '/\{(?!registration_details\})[a-z0-9_\-]+\}/i', '', $text );
}

/**
 * Visitor IP. Uses REMOTE_ADDR, because proxy headers can be faked by anyone. Behind Cloudflare or
 * another proxy, name the header it sets in wp-config.php, e.g.
 * define( 'EPC_PROXY_IP_HEADER', 'HTTP_CF_CONNECTING_IP' );
 */
function epc_client_ip(): string {
	$headers = array( 'REMOTE_ADDR' );
	if ( defined( 'EPC_PROXY_IP_HEADER' ) && EPC_PROXY_IP_HEADER ) {
		array_unshift( $headers, (string) EPC_PROXY_IP_HEADER );
	}
	foreach ( $headers as $h ) {
		if ( ! empty( $_SERVER[ $h ] ) ) {
			$ip = trim( explode( ',', wp_unslash( $_SERVER[ $h ] ) )[0] );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}
	}
	return '0.0.0.0';
}

/** Applicant statuses. */
function epc_applicant_statuses(): array {
	return array(
		'registered' => 'Registered',
		'confirmed'  => 'Confirmed',
		'waitlist'   => 'Waitlist',
		'attended'   => 'Attended',
		'cancelled'  => 'Cancelled',
	);
}

/** Seed the default reusable lists on first run. */
function epc_seed_defaults(): void {
	if ( ! function_exists( 'update_field' ) ) {
		return;
	}
	$lists = array(
		array( 'name' => 'Salutation', 'slug' => 'salutation', 'options' => "Datuk Dr.\nDato' Dr.\nProf.\nAssoc. Prof.\nDr.\nMr.\nMs.\nMrs." ),
		array( 'name' => 'Yes / No', 'slug' => 'yes-no', 'options' => "Yes\nNo" ),
		array( 'name' => 'Meal Preference', 'slug' => 'meal', 'options' => "Chicken\nFish\nVegetarian" ),
		array( 'name' => 'Malaysia States', 'slug' => 'malaysia-states', 'options' => "Johor\nKedah\nKelantan\nMelaka\nNegeri Sembilan\nPahang\nPerak\nPerlis\nPulau Pinang\nSabah\nSarawak\nSelangor\nTerengganu\nW.P. Kuala Lumpur\nW.P. Labuan\nW.P. Putrajaya" ),
		array( 'name' => 'Medical Specialty', 'slug' => 'specialty', 'options' => "General Practitioner\nGeneral Physician\nMedical Officer\nPaediatrician\nCardiologist\nNephrologist\nHaematologist\nNeurologist\nEmergency Physician\nPharmacist" ),
	);
	update_field( 'epc_preset_lists', $lists, 'option' );
}

/** ISO country list (names). */
function epc_countries(): array {
	return array( 'Malaysia', 'Singapore', 'Brunei', 'Indonesia', 'Thailand', 'Philippines', 'Vietnam', 'Cambodia', 'Laos', 'Myanmar', 'Australia', 'New Zealand', 'China', 'Hong Kong', 'Macau', 'Taiwan', 'Japan', 'South Korea', 'India', 'Pakistan', 'Bangladesh', 'Sri Lanka', 'Nepal', 'Maldives', 'United Arab Emirates', 'Saudi Arabia', 'Qatar', 'Kuwait', 'Bahrain', 'Oman', 'Turkey', 'Egypt', 'South Africa', 'Nigeria', 'Kenya', 'United Kingdom', 'Ireland', 'France', 'Germany', 'Netherlands', 'Belgium', 'Switzerland', 'Austria', 'Italy', 'Spain', 'Portugal', 'Sweden', 'Norway', 'Denmark', 'Finland', 'Poland', 'Russia', 'United States', 'Canada', 'Mexico', 'Brazil', 'Argentina', 'Chile', 'Colombia', 'Peru' );
}

/**
 * Custom HTML section code, cleaned when it is SAVED: editors without "Custom HTML & embed codes"
 * (unfiltered_html) keep safe HTML and iframes only. The page then shows the code exactly as saved,
 * so who views it never matters.
 */
function epc_clean_embed_html( string $code ): string {
	$allowed           = wp_kses_allowed_html( 'post' );
	$allowed['iframe'] = array( 'src' => true, 'width' => true, 'height' => true, 'style' => true, 'allow' => true, 'allowfullscreen' => true, 'loading' => true, 'frameborder' => true, 'title' => true, 'referrerpolicy' => true );
	return wp_kses( $code, $allowed );
}

add_filter(
	'acf/update_value/key=field_epc_html_code',
	static function ( $value ) {
		return current_user_can( 'unfiltered_html' ) ? $value : epc_clean_embed_html( (string) $value );
	}
);
