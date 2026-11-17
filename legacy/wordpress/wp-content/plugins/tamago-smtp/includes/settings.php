<?php
/**
 * Settings: defaults, stored values, wp-config.php overrides, saving rules, JSON export/import.
 *
 * All settings live in one option, tmg_smtp_settings (an array). Any key can be locked in wp-config.php
 * with TMG_SMTP_<KEY> (e.g. TMG_SMTP_HOST, TMG_SMTP_PASSWORD); a locked key always wins and the
 * screen shows it read-only. Defining TMG_SMTP_HOST also switches SMTP on unless TMG_SMTP_ENABLED says otherwise.
 *
 * Password rules: stored encrypted, never shown back; an empty input keeps the saved password, except
 * when the host or username changes in the same save, so a saved password is never sent to a new server.
 *
 * Used by: every other file.
 *
 * @package TamagoSMTP
 */

defined( 'ABSPATH' ) || exit;

class TMG_SMTP_Settings {

	const OPTION = 'tmg_smtp_settings';

	/** Largest settings file accepted by the import (bytes). */
	const IMPORT_MAX = 102400;

	/** Keys that are never exported, imported or printed. */
	const SECRET_KEYS = array( 'password' );

	/** Extra constant names accepted for a key (first match wins after TMG_SMTP_<KEY>). */
	const ALIASES = array(
		'username'   => array( 'TMG_SMTP_USER' ),
		'encryption' => array( 'TMG_SMTP_SECURE' ),
	);

	public static function defaults(): array {
		return array(
			// Mail server.
			'enabled'                => false,
			'provider'               => 'other',
			'host'                   => '',
			'port'                   => 587,
			'encryption'             => 'tls',
			'auto_tls'               => true,
			'auth'                   => true,
			'username'               => '',
			'password'               => '',
			'timeout'                => 20,
			// Sender.
			'from_email'             => '',
			'force_from_email'       => false,
			'from_name'              => '',
			'force_from_name'        => false,
			'return_path'            => false,
			'reply_to'               => '',
			'bcc'                    => '',
			// Email log.
			'log_enabled'            => true,
			'log_bodies'             => true,
			'log_transcript_success' => false,
			'log_retention_days'     => 30,
			'log_max_body_kb'        => 512,
			// Failure alert.
			'alert_enabled'          => false,
			'alert_email'            => '',
			// Uninstall.
			'delete_data'            => false,
		);
	}

	/** Value type of each key: bool, int, string, email, emails, host, enum, secret. */
	public static function types(): array {
		return array(
			'enabled'                => 'bool',
			'provider'               => 'enum',
			'host'                   => 'host',
			'port'                   => 'int',
			'encryption'             => 'enum',
			'auto_tls'               => 'bool',
			'auth'                   => 'bool',
			'username'               => 'string',
			'password'               => 'secret',
			'timeout'                => 'int',
			'from_email'             => 'email',
			'force_from_email'       => 'bool',
			'from_name'              => 'string',
			'force_from_name'        => 'bool',
			'return_path'            => 'bool',
			'reply_to'               => 'email',
			'bcc'                    => 'emails',
			'log_enabled'            => 'bool',
			'log_bodies'             => 'bool',
			'log_transcript_success' => 'bool',
			'log_retention_days'     => 'int',
			'log_max_body_kb'        => 'int',
			'alert_enabled'          => 'bool',
			'alert_email'            => 'email',
			'delete_data'            => 'bool',
		);
	}

	/** Stored values merged over the defaults (the password stays encrypted here). */
	public static function stored(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		return array_merge( self::defaults(), array_intersect_key( $saved, self::defaults() ) );
	}

	/** Name of the constant that locks a key, or '' when none is defined. */
	public static function constant_for( string $key ): string {
		$names = array_merge( array( 'TMG_SMTP_' . strtoupper( $key ) ), self::ALIASES[ $key ] ?? array() );
		foreach ( $names as $name ) {
			if ( defined( $name ) ) {
				return $name;
			}
		}
		return '';
	}

	public static function is_locked( string $key ): bool {
		return '' !== self::constant_for( $key );
	}

	/** Effective value: constant, else saved value, else default. The password comes back decrypted. */
	public static function get( string $key ) {
		$defaults = self::defaults();
		if ( ! array_key_exists( $key, $defaults ) ) {
			return null;
		}
		$const = self::constant_for( $key );
		if ( '' !== $const ) {
			return 'password' === $key ? (string) constant( $const ) : self::sanitize_value( $key, constant( $const ) );
		}
		if ( 'enabled' === $key && self::is_locked( 'host' ) ) {
			return true;
		}
		$stored = self::stored();
		if ( 'password' === $key ) {
			return TMG_SMTP_Crypto::decrypt( (string) $stored['password'] );
		}
		return $stored[ $key ];
	}

	/** SMTP is used only when it is switched on and a host is set. */
	public static function enabled(): bool {
		return (bool) self::get( 'enabled' ) && '' !== (string) self::get( 'host' );
	}

	public static function has_password(): bool {
		return self::is_locked( 'password' ) || '' !== (string) self::stored()['password'];
	}

	/** Cleans one value according to its type. */
	public static function sanitize_value( string $key, $value ) {
		$types = self::types();
		switch ( $types[ $key ] ?? 'string' ) {
			case 'bool':
				if ( is_string( $value ) ) {
					return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes', 'on' ), true );
				}
				return (bool) $value;
			case 'int':
				$value  = (int) $value;
				$limits = array(
					'port'               => array( 1, 65535, 587 ),
					'timeout'            => array( 1, 300, 20 ),
					'log_retention_days' => array( 0, 3650, 30 ),
					'log_max_body_kb'    => array( 1, 10240, 512 ),
				);
				if ( isset( $limits[ $key ] ) ) {
					list( $min, $max, $fallback ) = $limits[ $key ];
					if ( $value < $min || $value > $max ) {
						return 'port' === $key || 'timeout' === $key ? $fallback : max( $min, min( $max, $value ) );
					}
				}
				return $value;
			case 'enum':
				$value   = strtolower( trim( (string) $value ) );
				$allowed = 'provider' === $key ? TMG_SMTP_Providers::ids() : array( 'none', 'ssl', 'tls' );
				return in_array( $value, $allowed, true ) ? $value : self::defaults()[ $key ];
			case 'host':
				// Hostname or IP only: no scheme, spaces or paths.
				$value = strtolower( trim( (string) $value ) );
				$value = (string) preg_replace( '#^[a-z]+://#', '', $value );
				$value = (string) preg_replace( '#/.*$#', '', $value );
				return (string) preg_replace( '/[^a-z0-9.\-:\[\]_]/', '', $value );
			case 'email':
				$value = sanitize_email( (string) $value );
				return is_email( $value ) ? $value : '';
			case 'emails':
				$list = array();
				foreach ( preg_split( '/[,;\s]+/', (string) $value ) as $one ) {
					$one = sanitize_email( $one );
					if ( is_email( $one ) ) {
						$list[] = $one;
					}
				}
				return implode( ', ', array_unique( $list ) );
			case 'secret':
				return str_replace( array( "\r", "\n", "\0" ), '', (string) $value );
			default:
				return sanitize_text_field( (string) $value );
		}
	}

	/**
	 * Builds the array to store from submitted values.
	 *
	 * @param array $input   Submitted values (unslashed). Form: password, password_clear; import: no secrets.
	 * @param array $old     Currently stored values.
	 * @param bool  $partial True for imports: keys that are missing stay as they were (form checkboxes that are
	 *                       missing mean "off").
	 */
	public static function prepare_save( array $input, array $old, bool $partial = false ): array {
		$old   = array_merge( self::defaults(), array_intersect_key( $old, self::defaults() ) );
		$new   = $old;
		$types = self::types();
		foreach ( self::defaults() as $key => $default ) {
			if ( in_array( $key, self::SECRET_KEYS, true ) || self::is_locked( $key ) ) {
				continue;
			}
			if ( ! array_key_exists( $key, $input ) ) {
				if ( ! $partial && 'bool' === $types[ $key ] ) {
					$new[ $key ] = false;
				}
				continue;
			}
			$new[ $key ] = self::sanitize_value( $key, $input[ $key ] );
		}

		$server_changed = $new['host'] !== $old['host'] || $new['username'] !== $old['username'];
		$typed          = $partial ? '' : self::sanitize_value( 'password', $input['password'] ?? '' );
		if ( self::is_locked( 'password' ) ) {
			$new['password'] = $old['password'];
		} elseif ( ! $partial && ! empty( $input['password_clear'] ) ) {
			$new['password'] = '';
		} elseif ( '' !== $typed ) {
			$new['password'] = TMG_SMTP_Crypto::encrypt( $typed );
		} elseif ( $server_changed ) {
			$new['password'] = '';
		}
		return $new;
	}

	/**
	 * Saves submitted form values.
	 *
	 * @return array{settings:array,password_dropped:bool}
	 */
	public static function save( array $input, bool $partial = false ): array {
		$old = self::stored();
		$new = self::prepare_save( $input, $old, $partial );
		update_option( self::OPTION, $new, true );
		return array(
			'settings'         => $new,
			'password_dropped' => '' !== $old['password'] && '' === $new['password'] && empty( $input['password_clear'] ),
		);
	}

	/** Export shape shared by all Tamago plugins. Secrets are left out. */
	public static function export(): array {
		$settings = self::stored();
		foreach ( self::SECRET_KEYS as $secret ) {
			unset( $settings[ $secret ] );
		}
		return array(
			'plugin'   => 'tamago-smtp',
			'version'  => TMG_SMTP_VERSION,
			'exported' => gmdate( 'c' ),
			'settings' => $settings,
		);
	}

	/**
	 * Imports an export file's contents. Secrets in the file are ignored; when the host or username
	 * changes, the saved password is dropped (same rule as the form).
	 *
	 * @param mixed $data Decoded JSON.
	 * @return array|WP_Error List of imported keys.
	 */
	public static function import( $data ) {
		if ( ! is_array( $data ) || ( $data['plugin'] ?? '' ) !== 'tamago-smtp' || ! isset( $data['settings'] ) || ! is_array( $data['settings'] ) ) {
			return new WP_Error( 'tmg_smtp_import_shape', __( 'This is not a Tamago SMTP settings file.', 'tamago-smtp' ) );
		}
		$input = array_diff_key( array_intersect_key( $data['settings'], self::defaults() ), array_flip( self::SECRET_KEYS ) );
		self::save( $input, true );
		return array_keys( $input );
	}
}
