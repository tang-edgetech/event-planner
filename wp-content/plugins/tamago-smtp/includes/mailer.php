<?php
/**
 * Sending: points PHPMailer at the SMTP server and applies the sender rules to every wp_mail() call.
 *
 * - SMTP host, port, encryption, auto-TLS, authentication and timeout (phpmailer_init, priority 10).
 * - From email / From name: replace WordPress's default wordpress@… sender, or every sender when "Force" is on.
 * - Return-Path = From, a default Reply-To (only when the email has none) and an "always BCC" list.
 * - Fires tmg_smtp_before_send( $phpmailer ) last, so code can adjust the final PHPMailer object.
 *
 * Used by: every email the site sends; TMG_SMTP_Tester (server_config).
 *
 * @package TamagoSMTP
 */

defined( 'ABSPATH' ) || exit;

class TMG_SMTP_Mailer {

	public static function init(): void {
		add_action( 'phpmailer_init', array( __CLASS__, 'configure' ), 10 );
		add_filter( 'wp_mail_from', array( __CLASS__, 'filter_from_email' ), 999 );
		add_filter( 'wp_mail_from_name', array( __CLASS__, 'filter_from_name' ), 999 );
	}

	/** Effective server settings, wp-config.php overrides included. */
	public static function server_config(): array {
		$encryption = (string) TMG_SMTP_Settings::get( 'encryption' );
		$port       = (int) TMG_SMTP_Settings::get( 'port' );
		if ( $port <= 0 ) {
			$port = 'ssl' === $encryption ? 465 : ( 'none' === $encryption ? 25 : 587 );
		}
		$auth = (bool) TMG_SMTP_Settings::get( 'auth' );
		return array(
			'host'       => (string) TMG_SMTP_Settings::get( 'host' ),
			'port'       => $port,
			'encryption' => $encryption,
			'auto_tls'   => (bool) TMG_SMTP_Settings::get( 'auto_tls' ),
			'auth'       => $auth,
			'username'   => $auth ? (string) TMG_SMTP_Settings::get( 'username' ) : '',
			'password'   => $auth ? (string) TMG_SMTP_Settings::get( 'password' ) : '',
			'timeout'    => (int) TMG_SMTP_Settings::get( 'timeout' ),
		);
	}

	/**
	 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer Passed by reference by wp_mail().
	 */
	public static function configure( $phpmailer ): void {
		if ( ! is_object( $phpmailer ) ) {
			return;
		}
		if ( TMG_SMTP_Settings::enabled() ) {
			self::apply_server( $phpmailer, self::server_config() );
		}

		if ( TMG_SMTP_Settings::get( 'return_path' ) && '' !== (string) $phpmailer->From ) {
			$phpmailer->Sender = $phpmailer->From;
		}

		$reply_to = (string) TMG_SMTP_Settings::get( 'reply_to' );
		if ( '' !== $reply_to && method_exists( $phpmailer, 'getReplyToAddresses' ) && ! $phpmailer->getReplyToAddresses() ) {
			try {
				$phpmailer->addReplyTo( $reply_to );
			} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// An invalid address is skipped; the email still goes out.
			}
		}

		$bcc = (string) TMG_SMTP_Settings::get( 'bcc' );
		if ( '' !== $bcc && method_exists( $phpmailer, 'addBCC' ) ) {
			$present = array();
			foreach ( array_merge( $phpmailer->getToAddresses(), $phpmailer->getCcAddresses(), $phpmailer->getBccAddresses() ) as $address ) {
				$present[] = strtolower( (string) $address[0] );
			}
			foreach ( array_map( 'trim', explode( ',', $bcc ) ) as $address ) {
				if ( '' !== $address && ! in_array( strtolower( $address ), $present, true ) ) {
					try {
						$phpmailer->addBCC( $address );
					} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
						// Skipped, see above.
					}
				}
			}
		}

		/**
		 * Last chance to change the PHPMailer object before the email is sent.
		 *
		 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer
		 */
		do_action( 'tmg_smtp_before_send', $phpmailer );
	}

	/** Applies server settings to a PHPMailer object. */
	public static function apply_server( $phpmailer, array $cfg ): void {
		$phpmailer->isSMTP();
		$phpmailer->Host        = $cfg['host'];
		$phpmailer->Port        = (int) $cfg['port'];
		$phpmailer->SMTPSecure  = in_array( $cfg['encryption'], array( 'ssl', 'tls' ), true ) ? $cfg['encryption'] : '';
		$phpmailer->SMTPAutoTLS = 'none' === $cfg['encryption'] && $cfg['auto_tls'];
		$phpmailer->Timeout     = max( 1, (int) $cfg['timeout'] );
		$phpmailer->SMTPAuth    = (bool) $cfg['auth'];
		$phpmailer->Username    = $cfg['auth'] ? $cfg['username'] : '';
		$phpmailer->Password    = $cfg['auth'] ? $cfg['password'] : '';
	}

	/** The sender wp_mail() uses when nobody chose one: wordpress@ + the site's domain without www. */
	public static function default_from_email(): string {
		$sitename = wp_parse_url( network_home_url(), PHP_URL_HOST );
		$from     = 'wordpress@';
		if ( null !== $sitename && false !== $sitename ) {
			if ( 0 === strpos( $sitename, 'www.' ) ) {
				$sitename = substr( $sitename, 4 );
			}
			$from .= $sitename;
		}
		return $from;
	}

	public static function filter_from_email( $email ): string {
		$email = (string) $email;
		$mine  = (string) TMG_SMTP_Settings::get( 'from_email' );
		if ( ! is_email( $mine ) ) {
			return $email;
		}
		if ( TMG_SMTP_Settings::get( 'force_from_email' ) || '' === $email || 0 === strcasecmp( $email, self::default_from_email() ) ) {
			return $mine;
		}
		return $email;
	}

	public static function filter_from_name( $name ): string {
		$name = (string) $name;
		$mine = (string) TMG_SMTP_Settings::get( 'from_name' );
		if ( '' === $mine ) {
			return $name;
		}
		if ( TMG_SMTP_Settings::get( 'force_from_name' ) || '' === $name || 'WordPress' === $name ) {
			return $mine;
		}
		return $name;
	}
}
