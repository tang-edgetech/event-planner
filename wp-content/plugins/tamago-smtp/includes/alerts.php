<?php
/**
 * Failure alert: emails an address when sending fails, at most once per hour.
 *
 * Never triggered by its own alert (a failing alert does not alert again) or by test emails.
 * The alert goes through the same mailer, so when the mail server itself is down the alert may
 * fail too; it is then visible in the log like any other failure.
 *
 * Used by: every failed wp_mail() call (wp_mail_failed, priority 20, after the log row is written).
 *
 * @package TamagoSMTP
 */

defined( 'ABSPATH' ) || exit;

class TMG_SMTP_Alerts {

	const LAST_OPTION = 'tmg_smtp_alert_last';

	/** @var bool True while the alert itself is being sent. */
	private static $sending = false;

	public static function init(): void {
		add_action( 'wp_mail_failed', array( __CLASS__, 'maybe_alert' ), 20 );
	}

	public static function recipient(): string {
		$to = (string) TMG_SMTP_Settings::get( 'alert_email' );
		return is_email( $to ) ? $to : (string) get_option( 'admin_email' );
	}

	/** @param WP_Error $error */
	public static function maybe_alert( $error ): void {
		if ( self::$sending || TMG_SMTP_Tester::$running || ! TMG_SMTP_Settings::get( 'alert_enabled' ) ) {
			return;
		}
		$to = self::recipient();
		if ( ! is_email( $to ) ) {
			return;
		}
		$last = (int) get_option( self::LAST_OPTION, 0 );
		if ( $last > time() - HOUR_IN_SECONDS ) {
			return;
		}
		update_option( self::LAST_OPTION, time(), false );

		$data    = is_wp_error( $error ) ? (array) $error->get_error_data() : array();
		$failed  = TMG_SMTP_Logger::last_result();
		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$message = is_wp_error( $error ) ? $error->get_error_message() : '';
		$lines   = array(
			/* translators: %s: site name */
			sprintf( __( 'An email from %s could not be sent.', 'tamago-smtp' ), $site ),
			'',
			/* translators: %s: recipients */
			sprintf( __( 'To: %s', 'tamago-smtp' ), implode( ', ', (array) ( $data['to'] ?? array() ) ) ),
			/* translators: %s: subject */
			sprintf( __( 'Subject: %s', 'tamago-smtp' ), (string) ( $data['subject'] ?? '' ) ),
			/* translators: %s: error message */
			sprintf( __( 'Error: %s', 'tamago-smtp' ), $message ),
		);
		$hint = TMG_SMTP_Hints::for_error( $message, (string) ( $failed['transcript'] ?? '' ) );
		if ( '' !== $hint ) {
			$lines[] = '';
			$lines[] = $hint;
		}
		$lines[] = '';
		if ( ! empty( $failed['log_id'] ) ) {
			/* translators: %s: URL */
			$lines[] = sprintf( __( 'Details: %s', 'tamago-smtp' ), tmg_smtp_screen_url( 'logs', array( 'log' => (int) $failed['log_id'] ) ) );
		}
		$lines[] = __( 'You get at most one of these alerts per hour. Change this in Settings → SMTP.', 'tamago-smtp' );

		self::$sending = true;
		try {
			/* translators: %s: site name */
			wp_mail( $to, sprintf( __( '[%s] Email sending failed', 'tamago-smtp' ), $site ), implode( "\n", $lines ), array( 'Content-Type: text/plain; charset=' . get_bloginfo( 'charset' ) ) );
		} finally {
			self::$sending = false;
		}
	}
}
