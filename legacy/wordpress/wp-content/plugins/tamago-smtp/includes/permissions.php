<?php
/**
 * Capabilities: tmg_smtp_manage (settings, test, tools) and tmg_smtp_view_logs (log, resend, delete).
 *
 * Both are granted to administrators on activation and offered to Tamago Roles through the
 * tamago_permissions filter. Every screen and handler checks these, never manage_options.
 *
 * Used by: every screen and handler.
 *
 * @package TamagoSMTP
 */

defined( 'ABSPATH' ) || exit;

class TMG_SMTP_Permissions {

	const MANAGE    = 'tmg_smtp_manage';
	const VIEW_LOGS = 'tmg_smtp_view_logs';

	public static function init(): void {
		add_filter( 'tamago_permissions', array( __CLASS__, 'register' ) );
	}

	public static function register( $permissions ): array {
		$permissions                  = is_array( $permissions ) ? $permissions : array();
		$permissions['smtp_settings'] = array(
			'group'       => __( 'Email (SMTP)', 'tamago-smtp' ),
			'label'       => __( 'Manage email settings', 'tamago-smtp' ),
			'description' => __( 'Change the mail server, send test emails, import and export settings.', 'tamago-smtp' ),
			'caps'        => array( self::MANAGE ),
		);
		$permissions['smtp_logs']     = array(
			'group'       => __( 'Email (SMTP)', 'tamago-smtp' ),
			'label'       => __( 'View the email log', 'tamago-smtp' ),
			'description' => __( 'See sent and failed emails with their content, resend or delete them.', 'tamago-smtp' ),
			'caps'        => array( self::VIEW_LOGS ),
		);
		return $permissions;
	}

	public static function caps(): array {
		return array( self::MANAGE, self::VIEW_LOGS );
	}

	public static function grant(): void {
		$role = get_role( 'administrator' );
		if ( $role ) {
			foreach ( self::caps() as $cap ) {
				$role->add_cap( $cap );
			}
		}
	}

	public static function revoke(): void {
		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( self::caps() as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}

	public static function can_manage(): bool {
		return current_user_can( self::MANAGE );
	}

	public static function can_view_logs(): bool {
		return current_user_can( self::VIEW_LOGS );
	}

	/** Capability for the menu entry: whichever of the two the user has. */
	public static function menu_cap(): string {
		return self::can_manage() ? self::MANAGE : self::VIEW_LOGS;
	}

	/** Stops the request unless the current user has $cap (JSON for AJAX). */
	public static function require_cap( string $cap ): void {
		if ( current_user_can( $cap ) ) {
			return;
		}
		$message = __( 'Sorry, you are not allowed to do that.', 'tamago-smtp' );
		if ( wp_doing_ajax() ) {
			wp_send_json_error( array( 'message' => $message ), 403 );
		}
		wp_die( esc_html( $message ), esc_html__( 'Not allowed', 'tamago-smtp' ), array( 'response' => 403 ) );
	}
}
