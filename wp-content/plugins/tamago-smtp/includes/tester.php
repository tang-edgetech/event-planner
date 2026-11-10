<?php
/**
 * Test tools: send a test email, and check the connection (connect, STARTTLS, log in) without sending.
 *
 * Both return the masked SMTP conversation and a plain-language hint when something fails.
 * Both use the saved settings (save first, then test).
 *
 * Screens: Settings → SMTP → Test (AJAX: tmg_smtp_send_test, tmg_smtp_check_connection). Also WP-CLI `test`.
 *
 * @package TamagoSMTP
 */

defined( 'ABSPATH' ) || exit;

class TMG_SMTP_Tester {

	/** @var bool True while a test email is being sent (failure alerts skip it). */
	public static $running = false;

	public static function init(): void {
		add_action( 'wp_ajax_tmg_smtp_send_test', array( __CLASS__, 'ajax_send_test' ) );
		add_action( 'wp_ajax_tmg_smtp_check_connection', array( __CLASS__, 'ajax_check_connection' ) );
	}

	public static function ajax_send_test(): void {
		check_ajax_referer( 'tmg_smtp_test' );
		TMG_SMTP_Permissions::require_cap( TMG_SMTP_Permissions::MANAGE );
		$to     = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( $_POST['to'] ) ) : '';
		$format = isset( $_POST['format'] ) && 'text' === $_POST['format'] ? 'text' : 'html';
		$result = self::send_test( $to, $format );
		if ( $result['ok'] ) {
			wp_send_json_success( $result );
		}
		wp_send_json_error( $result );
	}

	public static function ajax_check_connection(): void {
		check_ajax_referer( 'tmg_smtp_test' );
		TMG_SMTP_Permissions::require_cap( TMG_SMTP_Permissions::MANAGE );
		$result = self::check_connection();
		if ( $result['ok'] ) {
			wp_send_json_success( $result );
		}
		wp_send_json_error( $result );
	}

	/** Human description of where email goes now. */
	public static function route(): string {
		if ( ! TMG_SMTP_Settings::enabled() ) {
			return __( 'the web server\'s PHP mail() (SMTP is off)', 'tamago-smtp' );
		}
		$cfg = TMG_SMTP_Mailer::server_config();
		return sprintf( 'SMTP %s:%d', $cfg['host'], $cfg['port'] );
	}

	/**
	 * Sends a test email through the normal wp_mail() path.
	 *
	 * @return array{ok:bool,message:string,hint:string,transcript:string,log_id:int,log_url:string,duration_ms:int}
	 */
	public static function send_test( string $to, string $format = 'html' ): array {
		if ( ! is_email( $to ) ) {
			return array(
				'ok'          => false,
				'message'     => __( 'Please enter a valid email address.', 'tamago-smtp' ),
				'hint'        => '',
				'transcript'  => '',
				'log_id'      => 0,
				'log_url'     => '',
				'duration_ms' => 0,
			);
		}
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$via  = self::route();
		$when = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
		/* translators: %s: site name */
		$subject = sprintf( __( 'Test email from %s', 'tamago-smtp' ), $site );
		if ( 'text' === $format ) {
			/* translators: 1: site name, 2: route, 3: date and time */
			$body    = sprintf( __( "This is a test email from %1\$s.\n\nIt was sent through %2\$s on %3\$s.\nIf you can read this, email sending works.", 'tamago-smtp' ), $site, $via, $when );
			$headers = array( 'Content-Type: text/plain; charset=' . get_bloginfo( 'charset' ) );
		} else {
			$body    = '<!doctype html><html><body style="font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;font-size:15px;color:#1d2327">'
				/* translators: %s: site name */
				. '<p>' . sprintf( esc_html__( 'This is a test email from %s.', 'tamago-smtp' ), '<strong>' . esc_html( $site ) . '</strong>' ) . '</p>'
				/* translators: 1: route, 2: date and time */
				. '<p>' . esc_html( sprintf( __( 'It was sent through %1$s on %2$s.', 'tamago-smtp' ), $via, $when ) ) . '</p>'
				. '<p style="color:#008a20"><strong>' . esc_html__( 'If you can read this, email sending works.', 'tamago-smtp' ) . '</strong></p>'
				. '</body></html>';
			$headers = array( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
		}

		self::$running = true;
		TMG_SMTP_Logger::force_capture( true );
		TMG_SMTP_Logger::reset_last();
		try {
			$ok = wp_mail( $to, $subject, $body, $headers );
		} finally {
			self::$running = false;
			TMG_SMTP_Logger::force_capture( false );
		}
		$last       = TMG_SMTP_Logger::last_result() ?? array();
		$error      = (string) ( $last['error'] ?? '' );
		$transcript = (string) ( $last['transcript'] ?? '' );
		$log_id     = (int) ( $last['log_id'] ?? 0 );
		$duration   = (int) ( $last['duration_ms'] ?? 0 );

		if ( $ok ) {
			/* translators: 1: recipient, 2: route, 3: milliseconds */
			$message = sprintf( __( 'Sent to %1$s through %2$s in %3$d ms. Check the inbox (and the spam folder).', 'tamago-smtp' ), $to, $via, $duration );
			$hint    = '';
		} else {
			/* translators: %s: error message */
			$message = '' !== $error ? sprintf( __( 'Sending failed: %s', 'tamago-smtp' ), $error ) : __( 'Sending failed.', 'tamago-smtp' );
			$hint    = TMG_SMTP_Hints::for_error( $error, $transcript );
			if ( '' === $hint && ! TMG_SMTP_Settings::enabled() ) {
				$hint = __( 'Switch on SMTP, fill in the server details and save.', 'tamago-smtp' );
			}
		}
		return array(
			'ok'          => (bool) $ok,
			'message'     => $message,
			'hint'        => $hint,
			'transcript'  => $transcript,
			'log_id'      => $log_id,
			'log_url'     => $log_id ? tmg_smtp_screen_url( 'logs', array( 'log' => $log_id ) ) : '',
			'duration_ms' => $duration,
		);
	}

	/**
	 * Connects to the SMTP server, starts TLS and logs in without sending anything.
	 *
	 * @param array|null $cfg Server settings (default: saved settings).
	 * @return array{ok:bool,message:string,steps:array,transcript:string,hint:string}
	 */
	public static function check_connection( ?array $cfg = null ): array {
		$cfg   = $cfg ?? TMG_SMTP_Mailer::server_config();
		$steps = array();
		if ( '' === $cfg['host'] ) {
			return array(
				'ok'         => false,
				'message'    => __( 'No SMTP host is set. Fill in the Settings tab and save.', 'tamago-smtp' ),
				'steps'      => array(),
				'transcript' => '',
				'hint'       => '',
			);
		}
		if ( ! class_exists( 'PHPMailer\\PHPMailer\\SMTP' ) ) {
			require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
			require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
			require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
		}

		$transcript = new TMG_SMTP_Transcript();
		$smtp       = new PHPMailer\PHPMailer\SMTP();
		$smtp->setDebugLevel( 3 );
		$smtp->setDebugOutput(
			static function ( $str ) use ( $transcript ) {
				$transcript->add( $str );
			}
		);
		$smtp->setTimeout( max( 1, (int) $cfg['timeout'] ) );
		$helo   = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$helo   = '' !== $helo ? $helo : 'localhost';
		$failed = false;

		$add = static function ( string $label, bool $ok, string $detail ) use ( &$steps, &$failed ) {
			$steps[] = array(
				'label'  => $label,
				'ok'     => $ok,
				'detail' => $detail,
			);
			if ( ! $ok ) {
				$failed = true;
			}
		};
		$error_text = static function () use ( $smtp ): string {
			$e = $smtp->getError();
			return trim( (string) ( $e['error'] ?? '' ) . ' ' . (string) ( $e['detail'] ?? '' ) . ' ' . (string) ( $e['smtp_code'] ?? '' ) );
		};

		$host  = ( 'ssl' === $cfg['encryption'] ? 'ssl://' : '' ) . $cfg['host'];
		$start = microtime( true );
		/* translators: 1: host, 2: port */
		$label = sprintf( __( 'Connect to %1$s:%2$d', 'tamago-smtp' ), $cfg['host'], $cfg['port'] );
		if ( $smtp->connect( $host, (int) $cfg['port'], max( 1, (int) $cfg['timeout'] ) ) ) {
			/* translators: %d: milliseconds */
			$add( $label, true, sprintf( __( 'Connected in %d ms.', 'tamago-smtp' ), (int) round( ( microtime( true ) - $start ) * 1000 ) ) . ( 'ssl' === $cfg['encryption'] ? ' ' . __( 'SSL is on from the start.', 'tamago-smtp' ) : '' ) );
		} else {
			$add( $label, false, $error_text() );
		}

		if ( ! $failed ) {
			if ( $smtp->hello( $helo ) ) {
				$add( __( 'Say hello (EHLO)', 'tamago-smtp' ), true, __( 'The server answered.', 'tamago-smtp' ) );
			} else {
				$add( __( 'Say hello (EHLO)', 'tamago-smtp' ), false, $error_text() );
			}
		}

		if ( ! $failed ) {
			$offers_tls = (bool) $smtp->getServerExt( 'STARTTLS' );
			$want_tls   = 'tls' === $cfg['encryption'] || ( 'none' === $cfg['encryption'] && $cfg['auto_tls'] && $offers_tls );
			if ( $want_tls ) {
				if ( $smtp->startTLS() && $smtp->hello( $helo ) ) {
					$add( __( 'Start TLS encryption', 'tamago-smtp' ), true, __( 'The connection is encrypted.', 'tamago-smtp' ) );
				} else {
					$detail = $error_text();
					$add( __( 'Start TLS encryption', 'tamago-smtp' ), false, '' !== $detail ? $detail : __( 'STARTTLS failed.', 'tamago-smtp' ) );
				}
			} elseif ( 'none' === $cfg['encryption'] ) {
				$add( __( 'Encryption', 'tamago-smtp' ), true, $offers_tls ? __( 'Not used (auto-TLS is off). The server offers STARTTLS.', 'tamago-smtp' ) : __( 'Not used: the server does not offer STARTTLS. Passwords travel unencrypted.', 'tamago-smtp' ) );
			}
		}

		if ( ! $failed ) {
			if ( $cfg['auth'] ) {
				if ( $smtp->authenticate( (string) $cfg['username'], (string) $cfg['password'] ) ) {
					/* translators: %s: username */
					$add( __( 'Log in', 'tamago-smtp' ), true, sprintf( __( 'Logged in as %s.', 'tamago-smtp' ), $cfg['username'] ) );
				} else {
					$add( __( 'Log in', 'tamago-smtp' ), false, $error_text() );
				}
			} else {
				$add( __( 'Log in', 'tamago-smtp' ), true, __( 'Skipped: authentication is off.', 'tamago-smtp' ) );
			}
		}

		if ( $smtp->connected() ) {
			$smtp->quit();
			$smtp->close();
		}

		$secrets = array_filter( array( (string) $cfg['password'] ) );
		$text    = TMG_SMTP_Transcript::mask( $transcript->text(), $secrets );
		foreach ( $steps as $i => $step ) {
			$steps[ $i ]['detail'] = TMG_SMTP_Transcript::mask_secrets( $step['detail'], $secrets );
		}
		$errors = implode( "\n", wp_list_pluck( array_filter( $steps, static function ( $s ) { return ! $s['ok']; } ), 'detail' ) );
		return array(
			'ok'         => ! $failed,
			'message'    => $failed ? __( 'The connection check failed.', 'tamago-smtp' ) : __( 'Everything works: the server accepted the connection and the login. No email was sent.', 'tamago-smtp' ),
			'steps'      => $steps,
			'transcript' => $text,
			'hint'       => $failed ? TMG_SMTP_Hints::for_error( $errors, $text ) : '',
		);
	}
}
