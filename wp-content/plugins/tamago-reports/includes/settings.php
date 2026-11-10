<?php
/**
 * Settings store: defaults, reading (with wp-config constant locks), sanitising,
 * saving, and JSON export / import. All settings live in one option array.
 *
 * Used by: every part of the plugin; Reports → Settings; `wp tamago-reports settings`.
 */

defined( 'ABSPATH' ) || exit;

class TMG_Reports_Settings {

	const OPTION = 'tmg_reports_settings';

	/** Keys that are secrets: stored encrypted, never exported, never printed. */
	const SECRETS = array( 'geo_token' );

	/** Special (non post type) content kinds that can be tracked. */
	const KINDS = array( 'front', 'blog', 'archive', 'tax', 'author', 'date', 'search', '404' );

	/** @var array|null */
	private static $cache = null;

	public static function defaults(): array {
		return array(
			'enabled'              => true,
			// Stored as the types NOT tracked, so post types registered later are tracked by default.
			'untracked_types'      => array( 'attachment', '404' ),
			'include_ids'          => array(),
			'exclude_ids'          => array(),
			'include_paths'        => array(),
			'exclude_paths'        => array(),
			'query_strip'          => 'all',
			'query_strip_params'   => array( 'fbclid', 'gclid', 'msclkid', 'dclid', '_ga', '_gl', 'mc_cid', 'mc_eid', 'ref' ),
			'exclude_roles'        => self::default_roles(),
			'exclude_ips'          => array(),
			'exclude_bots'         => true,
			'respect_dnt'          => false,
			'respect_gpc'          => true,
			'consent_mode'         => 'none',
			'consent_cookie'       => '',
			'consent_cookie_value' => '',
			'ev_outbound'          => true,
			'ev_downloads'         => true,
			'download_ext'         => array( 'pdf', 'zip', 'rar', '7z', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'ppt', 'pptx', 'txt', 'rtf', 'odt', 'ods', 'epub', 'mp3', 'mp4', 'mov', 'dmg', 'exe', 'apk', 'ics' ),
			'ev_mailto'            => true,
			'ev_tel'               => true,
			'ev_attr'              => true,
			'ev_forms'             => false,
			'form_selector'        => 'form',
			'ev_scroll'            => true,
			'ev_time'              => true,
			'goals'                => array(),
			'geo_provider'         => 'headers',
			'geo_token'            => '',
			'ip_header'            => '',
			'rate_limit'           => 300,
			'retention_days'       => 730,
			'delete_data'          => false,
		);
	}

	/** Roles that can edit posts (the default "don't track staff" list). */
	public static function default_roles(): array {
		$out = array();
		if ( ! function_exists( 'wp_roles' ) ) {
			return array( 'administrator', 'editor', 'author', 'contributor' );
		}
		foreach ( wp_roles()->roles as $slug => $role ) {
			if ( ! empty( $role['capabilities']['edit_posts'] ) ) {
				$out[] = (string) $slug;
			}
		}
		return $out;
	}

	public static function constant_name( string $key ): string {
		return 'TMG_REPORTS_' . strtoupper( $key );
	}

	public static function locked( string $key ): bool {
		return array_key_exists( $key, self::defaults() ) && defined( self::constant_name( $key ) );
	}

	/** All settings, merged over defaults, secrets decrypted, constants applied. */
	public static function all(): array {
		if ( null === self::$cache ) {
			$stored = get_option( self::OPTION, array() );
			$stored = is_array( $stored ) ? $stored : array();
			$all    = array_merge( self::defaults(), array_intersect_key( $stored, self::defaults() ) );
			foreach ( self::SECRETS as $secret ) {
				$all[ $secret ] = '' !== (string) $all[ $secret ] ? TMG_Reports_Crypto::decrypt( (string) $all[ $secret ] ) : '';
			}
			foreach ( array_keys( $all ) as $key ) {
				if ( self::locked( $key ) ) {
					$all[ $key ] = self::sanitize_value( $key, constant( self::constant_name( $key ) ) );
				}
			}
			self::$cache = $all;
		}
		return self::$cache;
	}

	/** @return mixed */
	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	public static function flush(): void {
		self::$cache = null;
	}

	/**
	 * Save a (partial) settings array. Unknown and locked keys are ignored.
	 * An empty secret keeps the stored one unless "$clear_secrets" names it.
	 */
	public static function save( array $input, array $clear_secrets = array() ): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		foreach ( self::defaults() as $key => $default ) {
			if ( self::locked( $key ) || ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$value = self::sanitize_value( $key, $input[ $key ] );
			if ( in_array( $key, self::SECRETS, true ) ) {
				if ( '' === $value ) {
					if ( in_array( $key, $clear_secrets, true ) ) {
						$stored[ $key ] = '';
					}
					continue;
				}
				$value = TMG_Reports_Crypto::encrypt( $value );
			}
			$stored[ $key ] = $value;
		}
		foreach ( $clear_secrets as $key ) {
			if ( in_array( $key, self::SECRETS, true ) && ! self::locked( $key ) ) {
				$stored[ $key ] = '';
			}
		}
		update_option( self::OPTION, $stored, true );
		self::flush();
		return self::all();
	}

	/** @param mixed $v */
	public static function sanitize_value( string $key, $v ) {
		$bools = array( 'enabled', 'exclude_bots', 'respect_dnt', 'respect_gpc', 'ev_outbound', 'ev_downloads', 'ev_mailto', 'ev_tel', 'ev_attr', 'ev_forms', 'ev_scroll', 'ev_time', 'delete_data' );
		if ( in_array( $key, $bools, true ) ) {
			return (bool) ( is_string( $v ) ? ! in_array( strtolower( $v ), array( '', '0', 'false', 'no', 'off' ), true ) : $v );
		}
		switch ( $key ) {
			case 'untracked_types':
			case 'exclude_roles':
				return array_values( array_unique( array_filter( array_map( 'sanitize_key', self::to_list( $v ) ) ) ) );
			case 'include_ids':
			case 'exclude_ids':
				return array_values( array_unique( array_filter( array_map( 'absint', self::to_list( $v ) ) ) ) );
			case 'include_paths':
			case 'exclude_paths':
				$out = array();
				foreach ( self::to_list( $v ) as $p ) {
					$p = trim( preg_replace( '/[^\w\-\/\.\*~%@:+]/u', '', (string) $p ) );
					if ( '' !== $p ) {
						$out[] = '/' === $p[0] || '*' === $p[0] ? $p : '/' . $p;
					}
				}
				return array_slice( array_values( array_unique( $out ) ), 0, 200 );
			case 'query_strip':
				return in_array( $v, array( 'all', 'listed' ), true ) ? $v : 'all';
			case 'query_strip_params':
				return array_slice( array_values( array_unique( array_filter( array_map( fn( $p ) => preg_replace( '/[^\w\-\[\]\.]/', '', (string) $p ), self::to_list( $v ) ) ) ) ), 0, 100 );
			case 'exclude_ips':
				$out = array();
				foreach ( self::to_list( $v ) as $ip ) {
					$ip = trim( (string) $ip );
					if ( TMG_Reports_Visitor::valid_ip_or_cidr( $ip ) ) {
						$out[] = $ip;
					}
				}
				return array_slice( array_values( array_unique( $out ) ), 0, 500 );
			case 'consent_mode':
				return in_array( $v, array( 'none', 'wait' ), true ) ? $v : 'none';
			case 'consent_cookie':
				return substr( preg_replace( '/[^\w\-\.]/', '', (string) $v ), 0, 100 );
			case 'consent_cookie_value':
				return substr( sanitize_text_field( (string) $v ), 0, 200 );
			case 'download_ext':
				return array_slice( array_values( array_unique( array_filter( array_map( fn( $e ) => strtolower( preg_replace( '/[^a-z0-9]/i', '', (string) $e ) ), self::to_list( $v ) ) ) ) ), 0, 100 );
			case 'form_selector':
				return substr( wp_strip_all_tags( (string) $v ), 0, 300 );
			case 'goals':
				return TMG_Reports_Goals::sanitize( is_array( $v ) ? $v : array() );
			case 'geo_provider':
				return in_array( $v, array( 'none', 'headers', 'ipapi', 'ipinfo' ), true ) ? $v : 'headers';
			case 'geo_token':
				return substr( preg_replace( '/[^\w\-\.]/', '', (string) $v ), 0, 200 );
			case 'ip_header':
				$v = strtoupper( preg_replace( '/[^A-Za-z0-9_]/', '', (string) $v ) );
				return '' === $v || 'REMOTE_ADDR' === $v ? '' : $v;
			case 'rate_limit':
				return max( 0, min( 100000, (int) $v ) );
			case 'retention_days':
				return max( 0, min( 36500, (int) $v ) );
		}
		return $v;
	}

	/** Accept arrays, comma or newline separated strings. @param mixed $v */
	public static function to_list( $v ): array {
		if ( is_array( $v ) ) {
			return array_values( $v );
		}
		$v = trim( (string) $v );
		return '' === $v ? array() : array_map( 'trim', preg_split( '/[\r\n,]+/', $v ) );
	}

	/* ---------------------------------------------------------------- export / import */

	public static function export(): array {
		$settings = self::all();
		foreach ( self::SECRETS as $secret ) {
			unset( $settings[ $secret ] );
		}
		return array(
			'plugin'   => 'tamago-reports',
			'version'  => TMG_REPORTS_VERSION,
			'exported' => gmdate( 'c' ),
			'settings' => $settings,
		);
	}

	/** @return true|WP_Error */
	public static function import( array $data ) {
		if ( ( $data['plugin'] ?? '' ) !== 'tamago-reports' || ! isset( $data['settings'] ) || ! is_array( $data['settings'] ) ) {
			return new WP_Error( 'tmg_reports_bad_import', __( 'This is not a Tamago Reports settings file.', 'tamago-reports' ) );
		}
		$in = $data['settings'];
		foreach ( self::SECRETS as $secret ) {
			unset( $in[ $secret ] );
		}
		self::save( $in );
		return true;
	}
}
