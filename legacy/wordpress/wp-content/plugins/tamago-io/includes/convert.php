<?php
/**
 * Type-aware value conversion between spreadsheet cells and ACF raw values.
 * format(): raw ACF value → readable cell (URL for images, slugs for posts, Y-m-d dates, JSON for
 * repeaters / groups / flexible content). to_acf(): cell (or decoded JSON) → raw value for
 * update_field(), resolving IDs / slugs / titles / URLs / logins on this site.
 *
 * Used by: flatten (export cells), mapping import, edited cells in re-imported spreadsheets.
 */

defined( 'ABSPATH' ) || exit;

class TMG_IO_Convert {

	/* ---------------------------------------------------------------------
	 * Raw → cell
	 * ------------------------------------------------------------------- */

	/** A raw ACF value as a readable cell. */
	public static function format( array $field, $raw ): string {
		if ( null === $raw || '' === $raw || false === $raw ) {
			return 'true_false' === ( $field['type'] ?? '' ) && false === $raw ? '0' : '';
		}
		switch ( $field['type'] ?? '' ) {
			case 'image':
			case 'file':
				return is_numeric( $raw ) ? (string) wp_get_attachment_url( (int) $raw ) : ( is_string( $raw ) ? $raw : '' );
			case 'gallery':
				return implode( ', ', array_filter( array_map( fn( $id ) => (string) wp_get_attachment_url( (int) $id ), (array) $raw ) ) );
			case 'post_object':
			case 'relationship':
			case 'page_link':
				$out = array();
				foreach ( (array) $raw as $id ) {
					$p     = is_numeric( $id ) ? get_post( (int) $id ) : null;
					$out[] = $p ? $p->post_name : (string) $id;
				}
				return implode( ', ', $out );
			case 'taxonomy':
				$out = array();
				foreach ( (array) $raw as $id ) {
					$t = get_term( (int) $id );
					if ( $t && ! is_wp_error( $t ) ) {
						$out[] = $t->name;
					}
				}
				return implode( ', ', $out );
			case 'user':
				$out = array();
				foreach ( (array) $raw as $id ) {
					$u = get_userdata( (int) $id );
					if ( $u ) {
						$out[] = $u->user_login;
					}
				}
				return implode( ', ', $out );
			case 'true_false':
				return $raw ? '1' : '0';
			case 'date_picker':
				return preg_match( '/^(\d{4})(\d{2})(\d{2})$/', (string) $raw, $m ) ? "{$m[1]}-{$m[2]}-{$m[3]}" : (string) $raw;
			case 'select':
			case 'checkbox':
			case 'radio':
			case 'button_group':
				return is_array( $raw ) ? implode( ', ', array_map( 'strval', $raw ) ) : (string) $raw;
			case 'repeater':
			case 'group':
			case 'flexible_content':
			case 'clone':
				return (string) wp_json_encode( self::named( $field, $raw ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}
		return is_array( $raw ) || is_object( $raw ) ? (string) wp_json_encode( $raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : (string) $raw;
	}

	/** Replace sub field keys by names (readable JSON for repeaters, groups, flexible content). */
	public static function named( array $field, $raw ) {
		if ( ! is_array( $raw ) ) {
			return $raw;
		}
		$type = $field['type'] ?? '';
		if ( 'repeater' === $type ) {
			$out = array();
			foreach ( $raw as $row ) {
				$out[] = self::named_row( (array) ( $field['sub_fields'] ?? array() ), (array) $row );
			}
			return $out;
		}
		if ( 'flexible_content' === $type ) {
			$out = array();
			foreach ( $raw as $row ) {
				$row    = (array) $row;
				$layout = self::layout( $field, (string) ( $row['acf_fc_layout'] ?? '' ) );
				$out[]  = array( 'acf_fc_layout' => $row['acf_fc_layout'] ?? '' ) + self::named_row( (array) ( $layout['sub_fields'] ?? array() ), $row );
			}
			return $out;
		}
		if ( 'group' === $type || 'clone' === $type ) {
			return self::named_row( (array) ( $field['sub_fields'] ?? array() ), $raw );
		}
		return $raw;
	}

	private static function named_row( array $subs, array $row ): array {
		$out = array();
		foreach ( $subs as $sub ) {
			$name = (string) ( $sub['_name'] ?? $sub['name'] ?? '' );
			if ( array_key_exists( $sub['key'], $row ) ) {
				$out[ $name ] = self::named( $sub, $row[ $sub['key'] ] );
			} elseif ( '' !== $name && array_key_exists( $name, $row ) ) {
				$out[ $name ] = self::named( $sub, $row[ $name ] );
			}
		}
		return $out;
	}

	private static function layout( array $field, string $name ): array {
		foreach ( (array) ( $field['layouts'] ?? array() ) as $layout ) {
			if ( ( $layout['name'] ?? '' ) === $name ) {
				return $layout;
			}
		}
		return array();
	}

	/* ---------------------------------------------------------------------
	 * Cell → raw
	 * ------------------------------------------------------------------- */

	/**
	 * Convert a cell (string) or decoded JSON into the raw value update_field() expects.
	 *
	 * @param TMG_IO_Importer|null $imp Run context (dry run, media downloads, notes).
	 * @return mixed
	 */
	public static function to_acf( array $field, $value, $imp = null ) {
		$type = $field['type'] ?? 'text';
		if ( is_string( $value ) ) {
			$value = TMG_IO_Files::unescape_cell( $value );
		}
		switch ( $type ) {
			case 'true_false':
				return self::bool( $value ) ? 1 : 0;
			case 'number':
			case 'range':
				$value = is_string( $value ) ? str_replace( array( ' ', ',' ), array( '', '.' ), trim( $value ) ) : $value;
				return is_numeric( $value ) ? $value + 0 : '';
			case 'date_picker':
				return self::date( $value, 'Ymd' );
			case 'date_time_picker':
				return self::date( $value, 'Y-m-d H:i:s' );
			case 'time_picker':
				$ts = strtotime( '1970-01-01 ' . trim( (string) $value ) . ' UTC' );
				return false === $ts ? '' : gmdate( 'H:i:s', $ts );
			case 'select':
			case 'checkbox':
			case 'radio':
			case 'button_group':
				$multi = 'checkbox' === $type || ( 'select' === $type && ! empty( $field['multiple'] ) );
				$out   = array();
				foreach ( self::tokens( $value ) as $t ) {
					$out[] = self::choice( $field, $t );
				}
				$out = array_values( array_unique( array_filter( $out, 'strlen' ) ) );
				return $multi ? $out : ( $out[0] ?? '' );
			case 'image':
			case 'file':
				$t = self::tokens( $value );
				return $t ? self::media( $t[0], $imp ) : '';
			case 'gallery':
				return array_values( array_filter( array_map( fn( $t ) => self::media( $t, $imp ), self::tokens( $value ) ) ) );
			case 'post_object':
			case 'relationship':
			case 'page_link':
				$multi = 'relationship' === $type || ! empty( $field['multiple'] );
				$types = array_filter( (array) ( $field['post_type'] ?? array() ) );
				$out   = array();
				foreach ( self::tokens( $value ) as $t ) {
					$id = self::find_post( $t, $types );
					if ( $id ) {
						$out[] = 'relationship' === $type ? (string) $id : $id;
					} elseif ( 'page_link' === $type && preg_match( '#^https?://#i', $t ) ) {
						$out[] = esc_url_raw( $t );
					} elseif ( $imp ) {
						/* translators: 1: value, 2: field label */
						$imp->note( sprintf( __( '"%1$s" not found for %2$s.', 'tamago-io' ), $t, $field['label'] ?? $field['name'] ?? '' ) );
					}
				}
				return $multi ? $out : ( $out[0] ?? '' );
			case 'taxonomy':
				$tax   = (string) ( $field['taxonomy'] ?? 'category' );
				$multi = in_array( $field['field_type'] ?? 'checkbox', array( 'checkbox', 'multi_select' ), true );
				$out   = array();
				foreach ( self::tokens( $value ) as $t ) {
					// Missing terms are created (when the user may manage terms), as for taxonomy columns.
					$id = self::find_term( $t, $tax, true, $imp );
					if ( $id ) {
						$out[] = $id;
					}
				}
				return $multi ? $out : ( $out[0] ?? '' );
			case 'user':
				$multi = ! empty( $field['multiple'] );
				$out   = array_values( array_filter( array_map( array( __CLASS__, 'find_user' ), self::tokens( $value ) ) ) );
				return $multi ? $out : ( $out[0] ?? '' );
			case 'link':
				$d = self::json( $value );
				if ( is_array( $d ) ) {
					return array(
						'title'  => sanitize_text_field( (string) ( $d['title'] ?? '' ) ),
						'url'    => esc_url_raw( (string) ( $d['url'] ?? '' ) ),
						'target' => '_blank' === ( $d['target'] ?? '' ) ? '_blank' : '',
					);
				}
				$v = trim( (string) $value );
				return '' === $v ? '' : array( 'title' => '', 'url' => esc_url_raw( $v ), 'target' => '' );
			case 'google_map':
				$d = self::json( $value );
				return is_array( $d ) ? $d : '';
			case 'repeater':
				$rows = self::json( $value );
				$out  = array();
				foreach ( is_array( $rows ) ? $rows : array() as $row ) {
					$out[] = self::sub_values( (array) ( $field['sub_fields'] ?? array() ), (array) $row, $imp );
				}
				return $out;
			case 'flexible_content':
				$rows = self::json( $value );
				$out  = array();
				foreach ( is_array( $rows ) ? $rows : array() as $row ) {
					$row    = (array) $row;
					$layout = self::layout( $field, (string) ( $row['acf_fc_layout'] ?? '' ) );
					if ( ! $layout ) {
						continue;
					}
					$out[] = array( 'acf_fc_layout' => $layout['name'] ) + self::sub_values( (array) ( $layout['sub_fields'] ?? array() ), $row, $imp );
				}
				return $out;
			case 'group':
			case 'clone':
				$d = self::json( $value );
				return is_array( $d ) ? self::sub_values( (array) ( $field['sub_fields'] ?? array() ), $d, $imp ) : array();
			case 'wysiwyg':
			case 'textarea':
			case 'text':
			case 'email':
			case 'url':
			case 'oembed':
				$v = is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
				return self::clean_html( $v );
		}
		$d = is_string( $value ) ? self::json( $value ) : $value;
		return is_array( $d ) ? $d : self::clean_html( (string) $value );
	}

	/** Sub values keyed by sub field key (accepts rows keyed by name or key). */
	private static function sub_values( array $subs, array $row, $imp ): array {
		$out = array();
		foreach ( $subs as $sub ) {
			$name = (string) ( $sub['_name'] ?? $sub['name'] ?? '' );
			if ( array_key_exists( $sub['key'], $row ) ) {
				$out[ $sub['key'] ] = self::to_acf( $sub, $row[ $sub['key'] ], $imp );
			} elseif ( '' !== $name && array_key_exists( $name, $row ) ) {
				$out[ $sub['key'] ] = self::to_acf( $sub, $row[ $name ], $imp );
			}
		}
		return $out;
	}

	/** Raw HTML only for users allowed to post it. */
	public static function clean_html( string $v ): string {
		if ( false !== strpos( $v, '<' ) && ! current_user_can( 'unfiltered_html' ) ) {
			return wp_kses_post( $v );
		}
		return $v;
	}

	/** Clean any value (strings deep inside arrays too) for users without unfiltered_html. */
	public static function clean_deep( $v ) {
		if ( current_user_can( 'unfiltered_html' ) ) {
			return $v;
		}
		if ( is_string( $v ) ) {
			return false !== strpos( $v, '<' ) ? wp_kses_post( $v ) : $v;
		}
		if ( is_array( $v ) ) {
			foreach ( $v as $k => $item ) {
				$v[ $k ] = self::clean_deep( $item );
			}
		}
		return $v;
	}

	/** JSON text → array, or the value itself when already an array. */
	public static function json( $value ) {
		if ( is_array( $value ) ) {
			return $value;
		}
		$s = trim( (string) $value );
		if ( '' === $s || ( '[' !== $s[0] && '{' !== $s[0] ) ) {
			return null;
		}
		$d = json_decode( $s, true );
		return is_array( $d ) ? $d : null;
	}

	/** Split a cell into tokens: JSON array, or comma / semicolon / newline separated text. */
	public static function tokens( $value ): array {
		if ( is_array( $value ) ) {
			$list = $value;
		} else {
			$d    = self::json( $value );
			$list = is_array( $d ) && array_is_list( $d ) ? $d : preg_split( '/\s*[,;\n|]\s*/', trim( (string) $value ) );
		}
		$out = array();
		foreach ( (array) $list as $t ) {
			if ( is_scalar( $t ) && '' !== trim( (string) $t ) ) {
				$out[] = trim( (string) $t );
			}
		}
		return $out;
	}

	public static function bool( $v ): bool {
		if ( is_bool( $v ) || is_int( $v ) ) {
			return (bool) $v;
		}
		$v = strtolower( trim( (string) $v ) );
		return in_array( $v, array( '1', 'true', 'yes', 'y', 'on', 'x', 'checked', 'ya' ), true );
	}

	/** Any date (Excel serial, Ymd, ISO, text) → format. '' when unreadable. */
	public static function date( $v, string $format ): string {
		$v = trim( (string) $v );
		if ( '' === $v ) {
			return '';
		}
		if ( preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $v, $m ) ) {
			$ts = gmmktime( 0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1] );
		} elseif ( is_numeric( $v ) && (float) $v > 0 && (float) $v < 100000 ) {
			$ts = (int) round( ( (float) $v - 25569 ) * 86400 ); // Excel serial date.
		} elseif ( preg_match( '#^(\d{1,2})/(\d{1,2})/(\d{4})(.*)$#', $v, $m ) && (int) $m[1] > 12 ) {
			$ts = strtotime( "{$m[3]}-{$m[2]}-{$m[1]}{$m[4]} UTC" ); // Day first when it cannot be a month.
		} else {
			$ts = strtotime( $v . ( preg_match( '/[a-z]{3,}|[+-]\d{2}:?\d{2}$|Z$/i', $v ) ? '' : ' UTC' ) );
		}
		return false === $ts || null === $ts ? '' : gmdate( $format, (int) $ts );
	}

	/** Choice value for a label or value (case-insensitive); unknown values are kept. */
	private static function choice( array $field, string $t ): string {
		$choices = (array) ( $field['choices'] ?? array() );
		if ( isset( $choices[ $t ] ) ) {
			return $t;
		}
		foreach ( $choices as $k => $label ) {
			if ( 0 === strcasecmp( (string) $label, $t ) || 0 === strcasecmp( (string) $k, $t ) ) {
				return (string) $k;
			}
		}
		return sanitize_text_field( $t );
	}

	/* ---------------------------------------------------------------------
	 * Lookups on this site
	 * ------------------------------------------------------------------- */

	/** Post by ID, slug or title (limited to post types when given). */
	public static function find_post( string $t, array $types = array() ): int {
		$t     = trim( $t );
		$types = $types ? array_values( $types ) : array_values( get_post_types( array(), 'names' ) );
		if ( '' === $t ) {
			return 0;
		}
		if ( ctype_digit( $t ) ) {
			$p = get_post( (int) $t );
			if ( $p && in_array( $p->post_type, $types, true ) ) {
				return (int) $p->ID;
			}
		}
		$common = array(
			'post_type'              => $types,
			'post_status'            => array_diff( array_values( get_post_stati() ), array( 'trash', 'auto-draft' ) ),
			'posts_per_page'         => 1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'suppress_filters'       => true,
		);
		$ids = get_posts( $common + array( 'name' => sanitize_title( $t ) ) );
		if ( ! $ids ) {
			$ids = get_posts( $common + array( 'title' => $t ) );
		}
		return $ids ? (int) $ids[0] : 0;
	}

	/** Term by ID, slug or name; created when missing and allowed. */
	public static function find_term( string $t, string $tax, bool $create, $imp = null ): int {
		$t = trim( $t );
		if ( '' === $t || ! taxonomy_exists( $tax ) ) {
			return 0;
		}
		if ( ctype_digit( $t ) ) {
			$term = get_term( (int) $t, $tax );
			if ( $term && ! is_wp_error( $term ) ) {
				return (int) $term->term_id;
			}
		}
		$term = get_term_by( 'slug', sanitize_title( $t ), $tax );
		if ( ! $term ) {
			$term = get_term_by( 'name', $t, $tax );
		}
		if ( $term ) {
			return (int) $term->term_id;
		}
		$tx = get_taxonomy( $tax );
		if ( ! $create || ! $tx || ! current_user_can( $tx->cap->manage_terms ) ) {
			return 0;
		}
		if ( $imp && $imp->dry() ) {
			return $imp->fake_id();
		}
		$r = wp_insert_term( sanitize_text_field( $t ), $tax );
		return is_wp_error( $r ) ? 0 : (int) $r['term_id'];
	}

	/** User by ID, login or email. */
	public static function find_user( string $t ): int {
		$t = trim( $t );
		if ( '' === $t ) {
			return 0;
		}
		$u = ctype_digit( $t ) ? get_userdata( (int) $t ) : false;
		if ( ! $u ) {
			$u = get_user_by( 'login', $t );
		}
		if ( ! $u && is_email( $t ) ) {
			$u = get_user_by( 'email', $t );
		}
		return $u ? (int) $u->ID : 0;
	}

	/** Attachment by ID or URL (an existing local file, or downloaded when allowed). */
	public static function media( string $t, $imp = null ): int {
		$t = trim( $t );
		if ( '' === $t ) {
			return 0;
		}
		if ( ctype_digit( $t ) ) {
			return 'attachment' === get_post_type( (int) $t ) ? (int) $t : 0;
		}
		if ( ! preg_match( '#^https?://#i', $t ) ) {
			return 0;
		}
		$found = TMG_IO_Media::find_existing( array( 'url' => $t ) );
		if ( $found ) {
			return $found;
		}
		if ( $imp && $imp->dry() ) {
			return $imp->fake_id();
		}
		if ( $imp && ! $imp->may_download() ) {
			$imp->note( __( 'Media download is switched off; image URL not imported.', 'tamago-io' ) );
			return 0;
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			return 0;
		}
		$id = TMG_IO_Media::from_url( $t );
		if ( is_wp_error( $id ) ) {
			if ( $imp ) {
				$imp->note( $id->get_error_message() );
			}
			return 0;
		}
		return $id;
	}
}
