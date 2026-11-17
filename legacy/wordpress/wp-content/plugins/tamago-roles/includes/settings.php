<?php
/**
 * Settings storage: one option (tmg_roles_settings) with defaults in code.
 * Scalar settings may be locked from wp-config.php with TMG_ROLES_<KEY> constants.
 *
 * Used by: every module.
 */

defined( 'ABSPATH' ) || exit;

class TMG_Roles_Settings {

	const OPTION = 'tmg_roles_settings';

	/** Default ranks for WordPress's own roles (administrator is always forced to the top). */
	const DEFAULT_RANKS = array(
		'administrator' => 100,
		'editor'        => 40,
		'author'        => 30,
		'contributor'   => 20,
		'subscriber'    => 10,
	);

	private static $cache = null;

	public static function defaults(): array {
		return array(
			'ranks'                   => array(),
			'hide_higher_users'       => true,
			'hide_higher_min_rank'    => 0,
			'peer_management'         => false,
			'assign_unranked'         => false,
			'protected_caps'          => array(),
			'protected_min_rank'      => 0,
			'custom_caps'             => array(),
			'restrict_admins'         => false,
			'menu_rules'              => array(),
			'adminbar_rules'          => array(),
			'toolbar_front_hidden'    => array(),
			'widget_rules'            => array(),
			'login_redirect'          => array(),
			'logout_redirect'         => array(),
			'no_admin'                => array(),
			'content_defaults'        => array(),
			'content_message'         => '',
			'public_name_roles'       => array(),
			'public_name'             => '',
			'public_name_reveal_rank' => 0,
			'public_name_rest_hide'   => true,
			'log_limit'               => 5000,
			'delete_data'             => false,
		);
	}

	/** Keys that are plain scalars and may be locked with a TMG_ROLES_<KEY> constant. */
	public static function lockable(): array {
		return array( 'hide_higher_users', 'hide_higher_min_rank', 'peer_management', 'assign_unranked', 'protected_min_rank', 'restrict_admins', 'content_message', 'public_name', 'public_name_reveal_rank', 'public_name_rest_hide', 'log_limit', 'delete_data' );
	}

	public static function constant_name( string $key ): string {
		return 'TMG_ROLES_' . strtoupper( $key );
	}

	public static function is_locked( string $key ): bool {
		return in_array( $key, self::lockable(), true ) && defined( self::constant_name( $key ) );
	}

	public static function all(): array {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			$saved       = is_array( $saved ) ? $saved : array();
			self::$cache = array_merge( self::defaults(), array_intersect_key( $saved, self::defaults() ) );
		}
		$all = self::$cache;
		foreach ( self::lockable() as $key ) {
			if ( defined( self::constant_name( $key ) ) ) {
				$value       = constant( self::constant_name( $key ) );
				$all[ $key ] = is_bool( self::defaults()[ $key ] ) ? (bool) $value : ( is_int( self::defaults()[ $key ] ) ? (int) $value : (string) $value );
			}
		}
		return $all;
	}

	/** @return mixed */
	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? ( self::defaults()[ $key ] ?? null );
	}

	/**
	 * Save several keys at once. Locked keys are ignored. Logs the change.
	 *
	 * @param array  $values  key => value.
	 * @param string $context Log label.
	 */
	public static function update( array $values, string $context = 'settings' ): void {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$saved = array_merge( self::defaults(), array_intersect_key( $saved, self::defaults() ) );
		$diff  = array();
		foreach ( $values as $key => $value ) {
			if ( ! array_key_exists( $key, self::defaults() ) || self::is_locked( $key ) ) {
				continue;
			}
			$value = self::sanitize( $key, $value );
			if ( $saved[ $key ] !== $value ) {
				$diff[ $key ] = array( 'from' => $saved[ $key ], 'to' => $value );
			}
			$saved[ $key ] = $value;
		}
		if ( ! $diff ) {
			return;
		}
		update_option( self::OPTION, $saved, true );
		self::$cache = null;
		TMG_Roles_Capabilities::flush();
		TMG_Roles_Logger::log( $context, implode( ', ', array_keys( $diff ) ), $diff );
	}

	public static function flush(): void {
		self::$cache = null;
	}

	/** @return mixed */
	public static function sanitize( string $key, $value ) {
		$default = self::defaults()[ $key ];
		switch ( $key ) {
			case 'ranks':
				$out = array();
				foreach ( (array) $value as $role => $rank ) {
					$out[ sanitize_key( $role ) ] = max( 0, (int) $rank );
				}
				return $out;
			case 'protected_caps':
			case 'custom_caps':
				return self::cap_list( $value );
			case 'toolbar_front_hidden':
			case 'no_admin':
			case 'public_name_roles':
				return array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $value ) ) ) );
			case 'menu_rules':
			case 'adminbar_rules':
			case 'widget_rules':
				$out = array();
				foreach ( (array) $value as $role => $items ) {
					$items = array_values( array_unique( array_filter( array_map( array( __CLASS__, 'clean_id' ), (array) $items ) ) ) );
					if ( $items ) {
						$out[ sanitize_key( $role ) ] = $items;
					}
				}
				return $out;
			case 'login_redirect':
			case 'logout_redirect':
				$out = array();
				foreach ( (array) $value as $role => $url ) {
					$url = trim( (string) $url );
					if ( '' === $url ) {
						continue;
					}
					if ( preg_match( '#^(https?://|/)#i', $url ) ) {
						$url = esc_url_raw( $url, array( 'http', 'https' ) );
					} else {
						// Relative to wp-admin, e.g. "admin.php?page=x".
						$url = preg_replace( '/[^A-Za-z0-9_\-\.\?=&\/%]/', '', $url );
					}
					if ( '' !== $url ) {
						$out[ sanitize_key( $role ) ] = $url;
					}
				}
				return $out;
			case 'content_defaults':
				$out = array();
				foreach ( (array) $value as $type => $rule ) {
					$rule = TMG_Roles_Content_Access::sanitize_rule( (array) $rule );
					if ( 'inherit' !== $rule['mode'] && 'everyone' !== $rule['mode'] ) {
						$out[ sanitize_key( $type ) ] = $rule;
					}
				}
				return $out;
			case 'content_message':
				return wp_kses_post( (string) $value );
			case 'public_name':
				return sanitize_text_field( (string) $value );
		}
		if ( is_bool( $default ) ) {
			return (bool) $value;
		}
		if ( is_int( $default ) ) {
			return max( 0, (int) $value );
		}
		return is_array( $default ) ? (array) $value : sanitize_text_field( (string) $value );
	}

	/** Menu slugs, admin bar node IDs and widget IDs: keep URL-ish characters only. */
	public static function clean_id( $id ): string {
		return preg_replace( '/[^A-Za-z0-9_\-\.\?=&|\/:%]/', '', (string) $id );
	}

	/** Capability names: WordPress allows almost anything, keep it to safe characters. */
	public static function cap_list( $value ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\s,]+/', $value );
		}
		$out = array();
		foreach ( (array) $value as $cap ) {
			$cap = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $cap );
			if ( '' !== $cap ) {
				$out[] = $cap;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** First run: give WordPress's own roles sensible ranks (only those not ranked yet). */
	public static function maybe_seed_ranks(): void {
		$ranks = (array) self::get( 'ranks' );
		$add   = array();
		foreach ( self::DEFAULT_RANKS as $role => $rank ) {
			if ( ! isset( $ranks[ $role ] ) && get_role( $role ) ) {
				$add[ $role ] = $rank;
			}
		}
		if ( $add && ! get_option( self::OPTION ) ) {
			self::update( array( 'ranks' => array_merge( $add, $ranks ) ), 'ranks_seeded' );
		}
	}

	/** Event Plus's "developer-only" WordPress capabilities: the "Technical WordPress areas" preset. */
	public static function technical_preset(): array {
		return array(
			'edit_posts', 'edit_others_posts', 'edit_published_posts', 'edit_private_posts', 'read_private_posts', 'publish_posts',
			'delete_posts', 'delete_others_posts', 'delete_published_posts', 'delete_private_posts', 'manage_categories',
			'edit_pages', 'edit_others_pages', 'edit_published_pages', 'edit_private_pages', 'read_private_pages', 'publish_pages',
			'delete_pages', 'delete_others_pages', 'delete_published_pages', 'delete_private_pages',
			'moderate_comments', 'edit_comment',
			'edit_theme_options', 'switch_themes', 'edit_themes', 'install_themes', 'update_themes', 'delete_themes', 'customize',
			'activate_plugins', 'edit_plugins', 'install_plugins', 'update_plugins', 'delete_plugins',
			'manage_options', 'import', 'export', 'edit_files', 'update_core', 'edit_dashboard', 'manage_links',
		);
	}
}
