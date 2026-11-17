<?php
/**
 * Email log: records every wp_mail() call with its full details.
 *
 * Flow per email (a stack of "frames", so emails sent from inside another email's hooks are logged correctly):
 *   wp_mail filter (last)        push a frame: arguments, caller (plugin/theme/core + function), context, user, URL
 *   pre_wp_mail (last)           a short-circuited email is finished here
 *   phpmailer_init (last)        final recipients, From, mailer, content type, attachments; Message-ID; transcript capture
 *   wp_mail_succeeded / _failed  (first) pop the frame and write the row
 *   shutdown                     frames left by a request that died mid-send are logged as failed
 *
 * Filters: tmg_smtp_log_enabled( bool, $atts ), tmg_smtp_log_row( array $row ) (return false to skip).
 * Action:  tmg_smtp_logged( int $log_id, array $row ).
 *
 * Used by: every email the site sends; tester, resend, alerts, WP-CLI.
 *
 * @package TamagoSMTP
 */

defined( 'ABSPATH' ) || exit;

class TMG_SMTP_Logger {

	/** @var array[] Open frames, innermost last. */
	private static $stack = array();

	/** @var array|null The last finished frame (also when it was not logged), for the test tools. */
	private static $last = null;

	/** @var int Log id the next wp_mail() call resends (set by resend()). */
	private static $next_resend_of = 0;

	/** @var bool Capture the transcript even when logging is off (test email). */
	private static $force_capture = false;

	private static $seq = 0;

	public static function init(): void {
		add_filter( 'wp_mail', array( __CLASS__, 'start' ), PHP_INT_MAX );
		add_filter( 'pre_wp_mail', array( __CLASS__, 'short_circuit' ), PHP_INT_MAX, 2 );
		add_action( 'phpmailer_init', array( __CLASS__, 'capture' ), PHP_INT_MAX );
		add_action( 'wp_mail_succeeded', array( __CLASS__, 'succeeded' ), PHP_INT_MIN );
		add_action( 'wp_mail_failed', array( __CLASS__, 'failed' ), PHP_INT_MIN );
		add_action( 'shutdown', array( __CLASS__, 'flush' ), 0 );
		add_action( TMG_SMTP_Log_Store::CRON, array( __CLASS__, 'purge' ) );
	}

	public static function force_capture( bool $on ): void {
		self::$force_capture = $on;
	}

	public static function last_result(): ?array {
		return self::$last;
	}

	public static function reset_last(): void {
		self::$last = null;
	}

	public static function depth(): int {
		return count( self::$stack );
	}

	public static function purge(): void {
		TMG_SMTP_Log_Store::purge( (int) TMG_SMTP_Settings::get( 'log_retention_days' ) );
	}

	/* --------------------------------------------------------------------- */
	/* Frame lifecycle                                                        */
	/* --------------------------------------------------------------------- */

	/**
	 * wp_mail filter: opens a frame. Returns the arguments untouched.
	 *
	 * @param array $atts wp_mail() arguments.
	 */
	public static function start( $atts ) {
		$caller = self::detect_caller();
		if ( null === $caller ) {
			// Someone applied the wp_mail filter outside wp_mail(); nothing will finish this frame.
			return $atts;
		}
		$atts = is_array( $atts ) ? $atts : array();
		$log  = (bool) TMG_SMTP_Settings::get( 'log_enabled' );
		/**
		 * Whether this email is written to the log.
		 *
		 * @param bool  $log  Default: the "Log emails" setting.
		 * @param array $atts wp_mail() arguments.
		 */
		$log = (bool) apply_filters( 'tmg_smtp_log_enabled', $log, $atts );

		self::$stack[]        = array(
			'id'         => ++self::$seq,
			'started'    => microtime( true ),
			'atts'       => $atts,
			'log'        => $log,
			'capture'    => $log || self::$force_capture,
			'transcript' => new TMG_SMTP_Transcript(),
			'touched'    => false,
			'resend_of'  => self::$next_resend_of,
			'source'     => $caller['source'],
			'function'   => $caller['function'],
			'context'    => self::context(),
			'user_id'    => get_current_user_id(),
			'url'        => self::request_url(),
		);
		self::$next_resend_of = 0;
		return $atts;
	}

	/** pre_wp_mail: when another plugin short-circuits sending, close the frame here. */
	public static function short_circuit( $result, $atts = array() ) {
		if ( null === $result || ! self::$stack ) {
			return $result;
		}
		$frame           = array_pop( self::$stack );
		$frame['mailer'] = 'pre_wp_mail';
		self::finish( $frame, $result ? 'sent' : 'failed', $result ? '' : __( 'Sending was cancelled by the pre_wp_mail filter.', 'tamago-smtp' ) );
		return $result;
	}

	/**
	 * phpmailer_init (runs last): reads the final message and starts capturing the SMTP conversation.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer
	 */
	public static function capture( $phpmailer ): void {
		if ( ! self::$stack || ! is_object( $phpmailer ) || ! method_exists( $phpmailer, 'getToAddresses' ) ) {
			return;
		}
		$i     = array_key_last( self::$stack );
		$frame = &self::$stack[ $i ];
		if ( ! $frame['capture'] ) {
			return;
		}
		$frame['touched'] = true;
		$frame['prev']    = array(
			'debug'  => $phpmailer->SMTPDebug,
			'output' => $phpmailer->Debugoutput,
			'msgid'  => $phpmailer->MessageID,
		);

		// Our own Message-ID, so the log knows it even when another email is sent before this one finishes.
		if ( '' === (string) $phpmailer->MessageID ) {
			$phpmailer->MessageID = self::make_message_id( (string) $phpmailer->From );
		}
		$frame['message_id']   = (string) $phpmailer->MessageID;
		$frame['mailer']       = self::describe_mailer( $phpmailer );
		$frame['from']         = (string) $phpmailer->From;
		$frame['from_name']    = (string) $phpmailer->FromName;
		$frame['to']           = self::format_addresses( $phpmailer->getToAddresses() );
		$frame['cc']           = self::format_addresses( $phpmailer->getCcAddresses() );
		$frame['bcc']          = self::format_addresses( $phpmailer->getBccAddresses() );
		$frame['reply_to']     = self::format_addresses( array_values( $phpmailer->getReplyToAddresses() ) );
		$frame['content_type'] = (string) $phpmailer->ContentType;
		$frame['body']         = (string) $phpmailer->Body;
		$frame['alt_body']     = (string) $phpmailer->AltBody;
		$frame['attachments']  = array();
		foreach ( $phpmailer->getAttachments() as $attachment ) {
			$path                   = (string) ( $attachment[0] ?? '' );
			$is_string              = ! empty( $attachment[5] );
			$frame['attachments'][] = array(
				'name'   => (string) ( $attachment[2] ?? basename( $path ) ),
				'size'   => $is_string ? strlen( $path ) : ( is_file( $path ) ? (int) filesize( $path ) : 0 ),
				'inline' => 'inline' === ( $attachment[6] ?? '' ),
			);
		}

		$transcript             = $frame['transcript'];
		$phpmailer->SMTPDebug   = 3; // SMTP::DEBUG_CONNECTION: connection, client and server lines.
		$phpmailer->Debugoutput = static function ( $str ) use ( $transcript ) {
			$transcript->add( $str );
		};
		unset( $frame );
	}

	public static function succeeded( $mail_data = array() ): void {
		if ( self::$stack ) {
			self::finish( array_pop( self::$stack ), 'sent', '' );
		}
	}

	/** @param WP_Error $error */
	public static function failed( $error ): void {
		if ( self::$stack ) {
			$message = is_wp_error( $error ) ? $error->get_error_message() : __( 'Unknown error.', 'tamago-smtp' );
			self::finish( array_pop( self::$stack ), 'failed', $message );
		}
	}

	/** Shutdown: frames still open belong to a request that ended mid-send. */
	public static function flush(): void {
		while ( self::$stack ) {
			self::finish( array_pop( self::$stack ), 'failed', __( 'The request ended before wp_mail() finished (fatal error or exit).', 'tamago-smtp' ) );
		}
	}

	/* --------------------------------------------------------------------- */
	/* Writing the row                                                        */
	/* --------------------------------------------------------------------- */

	private static function finish( array $frame, string $status, string $error ): void {
		global $phpmailer;
		$duration = (int) round( ( microtime( true ) - $frame['started'] ) * 1000 );

		$headers = '';
		if ( $frame['touched'] && is_object( $phpmailer ) ) {
			// The generated headers are only valid if they belong to this email (same Message-ID).
			$mime = (string) $phpmailer->getSentMIMEMessage();
			$cut  = strpos( $mime, "\r\n\r\n" );
			$head = false === $cut ? '' : substr( $mime, 0, $cut );
			if ( '' !== $head && false !== strpos( $head, $frame['message_id'] ) ) {
				$headers = $head;
			}
			$phpmailer->SMTPDebug   = $frame['prev']['debug'];
			$phpmailer->Debugoutput = $frame['prev']['output'];
			$phpmailer->MessageID   = $frame['prev']['msgid'];
		}

		$parsed = self::parse_headers( $frame['atts']['headers'] ?? array() );
		if ( '' === $headers ) {
			$headers = $parsed['raw'];
		}

		$secrets    = array_filter( array( (string) TMG_SMTP_Settings::get( 'password' ) ) );
		$transcript = TMG_SMTP_Transcript::mask( $frame['transcript']->text(), $secrets );
		$error      = TMG_SMTP_Transcript::mask_secrets( $error, $secrets );

		if ( $frame['touched'] ) {
			$to           = $frame['to'];
			$cc           = $frame['cc'];
			$bcc          = $frame['bcc'];
			$reply_to     = $frame['reply_to'];
			$from         = $frame['from'];
			$from_name    = $frame['from_name'];
			$content_type = $frame['content_type'];
			$body         = $frame['body'];
			$alt_body     = $frame['alt_body'];
			$attachments  = $frame['attachments'];
		} else {
			// phpmailer_init never ran (early failure or short-circuit): use the wp_mail() arguments.
			$to           = implode( ', ', array_map( 'trim', is_array( $frame['atts']['to'] ?? '' ) ? $frame['atts']['to'] : explode( ',', (string) ( $frame['atts']['to'] ?? '' ) ) ) );
			$cc           = implode( ', ', $parsed['cc'] );
			$bcc          = implode( ', ', $parsed['bcc'] );
			$reply_to     = implode( ', ', $parsed['reply_to'] );
			$from         = $parsed['from'];
			$from_name    = $parsed['from_name'];
			$content_type = '' !== $parsed['content_type'] ? $parsed['content_type'] : (string) apply_filters( 'wp_mail_content_type', 'text/plain' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
			$body         = is_string( $frame['atts']['message'] ?? null ) ? $frame['atts']['message'] : '';
			$alt_body     = '';
			$attachments  = self::attachments_from_args( $frame['atts']['attachments'] ?? array() );
			if ( ! isset( $frame['mailer'] ) ) {
				$cfg             = TMG_SMTP_Mailer::server_config();
				$frame['mailer'] = TMG_SMTP_Settings::enabled() ? 'SMTP ' . $cfg['host'] . ':' . $cfg['port'] : 'PHP mail()';
			}
		}

		self::$last = array(
			'status'      => $status,
			'error'       => $error,
			'transcript'  => $transcript,
			'duration_ms' => $duration,
			'mailer'      => (string) ( $frame['mailer'] ?? '' ),
			'message_id'  => (string) ( $frame['message_id'] ?? '' ),
			'to'          => $to,
			'log_id'      => 0,
		);

		if ( ! $frame['log'] ) {
			return;
		}

		$row = array(
			'created_at'      => gmdate( 'Y-m-d H:i:s', (int) $frame['started'] ),
			'status'          => $status,
			'to_email'        => $to,
			'cc'              => $cc,
			'bcc'             => $bcc,
			'from_email'      => $from,
			'from_name'       => $from_name,
			'reply_to'        => $reply_to,
			'subject'         => (string) ( $frame['atts']['subject'] ?? '' ),
			'headers'         => $headers,
			'body_html'       => null,
			'body_text'       => null,
			'body_truncated'  => 0,
			'content_type'    => $content_type,
			'attachments'     => $attachments ? wp_json_encode( $attachments ) : '',
			'mailer'          => (string) ( $frame['mailer'] ?? '' ),
			'message_id'      => (string) ( $frame['message_id'] ?? '' ),
			'error'           => $error,
			'transcript'      => ( 'failed' === $status || TMG_SMTP_Settings::get( 'log_transcript_success' ) ) ? $transcript : '',
			'duration_ms'     => $duration,
			'source'          => $frame['source'],
			'source_function' => $frame['function'],
			'context'         => $frame['context'],
			'user_id'         => (int) $frame['user_id'],
			'request_url'     => $frame['url'],
			'resend_of'       => (int) $frame['resend_of'],
			'resend_count'    => 0,
		);

		if ( TMG_SMTP_Settings::get( 'log_bodies' ) ) {
			$max = max( 1, (int) TMG_SMTP_Settings::get( 'log_max_body_kb' ) ) * 1024;
			if ( false !== stripos( $content_type, 'html' ) ) {
				$row['body_html'] = $body;
				$row['body_text'] = '' !== $alt_body ? $alt_body : null;
			} else {
				$row['body_text'] = $body;
			}
			foreach ( array( 'body_html', 'body_text' ) as $field ) {
				if ( null !== $row[ $field ] && strlen( $row[ $field ] ) > $max ) {
					$row[ $field ]         = mb_strcut( $row[ $field ], 0, $max, 'UTF-8' );
					$row['body_truncated'] = 1;
				}
			}
		}

		/**
		 * Filters a log row before it is written. Return false to skip it.
		 *
		 * @param array $row Column => value.
		 */
		$row = apply_filters( 'tmg_smtp_log_row', $row );
		if ( ! is_array( $row ) ) {
			return;
		}

		$log_id = TMG_SMTP_Log_Store::insert( $row );
		if ( ! $log_id ) {
			return;
		}
		self::$last['log_id'] = $log_id;
		if ( ! empty( $row['resend_of'] ) ) {
			TMG_SMTP_Log_Store::increment_resend( (int) $row['resend_of'] );
		}

		/**
		 * Fires after an email was written to the log.
		 *
		 * @param int   $log_id Row id.
		 * @param array $row    Stored values.
		 */
		do_action( 'tmg_smtp_logged', $log_id, $row );
	}

	/* --------------------------------------------------------------------- */
	/* Resend                                                                 */
	/* --------------------------------------------------------------------- */

	/** Whether a log entry has enough stored content to be resent. */
	public static function can_resend( array $row ): bool {
		$html = false !== stripos( (string) $row['content_type'], 'html' );
		$body = $html ? $row['body_html'] : $row['body_text'];
		return null !== $body && empty( $row['body_truncated'] ) && '' !== trim( (string) $row['to_email'] );
	}

	/**
	 * Sends a logged email again, as a new log entry linked to the original. Attachments are not kept,
	 * so they are not resent.
	 *
	 * @return array|WP_Error {ok, log_id, error}
	 */
	public static function resend( int $id ) {
		$row = TMG_SMTP_Log_Store::get( $id );
		if ( ! $row ) {
			return new WP_Error( 'tmg_smtp_not_found', __( 'Log entry not found.', 'tamago-smtp' ) );
		}
		if ( ! self::can_resend( $row ) ) {
			return new WP_Error( 'tmg_smtp_no_body', __( 'This email cannot be resent because its content was not stored in full.', 'tamago-smtp' ) );
		}
		$html    = false !== stripos( (string) $row['content_type'], 'html' );
		$headers = array( 'Content-Type: ' . ( $html ? 'text/html' : 'text/plain' ) . '; charset=' . get_bloginfo( 'charset' ) );
		if ( '' !== $row['from_email'] ) {
			$headers[] = 'From: ' . ( '' !== $row['from_name'] ? '"' . str_replace( '"', '', $row['from_name'] ) . '" ' : '' ) . '<' . $row['from_email'] . '>';
		}
		if ( '' !== (string) $row['cc'] ) {
			$headers[] = 'Cc: ' . $row['cc'];
		}
		// The "always BCC" list is added again by the mailer; keep only the other BCC addresses.
		$auto = array_map( 'strtolower', array_filter( array_map( 'trim', explode( ',', (string) TMG_SMTP_Settings::get( 'bcc' ) ) ) ) );
		$bcc  = array();
		foreach ( array_filter( array_map( 'trim', explode( ',', (string) $row['bcc'] ) ) ) as $address ) {
			if ( ! in_array( strtolower( self::bare_address( $address ) ), $auto, true ) ) {
				$bcc[] = $address;
			}
		}
		if ( $bcc ) {
			$headers[] = 'Bcc: ' . implode( ', ', $bcc );
		}
		if ( '' !== (string) $row['reply_to'] ) {
			$headers[] = 'Reply-To: ' . $row['reply_to'];
		}

		self::$next_resend_of = $id;
		self::$last           = null;
		$ok                   = wp_mail( (string) $row['to_email'], (string) $row['subject'], (string) ( $html ? $row['body_html'] : $row['body_text'] ), $headers );
		self::$next_resend_of = 0;
		return array(
			'ok'     => (bool) $ok,
			'log_id' => (int) ( self::$last['log_id'] ?? 0 ),
			'error'  => (string) ( self::$last['error'] ?? '' ),
		);
	}

	/* --------------------------------------------------------------------- */
	/* Helpers                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Finds who called wp_mail(): the file it was called from and the function around that call.
	 *
	 * @return array{source:string,function:string}|null Null when not inside wp_mail().
	 */
	public static function detect_caller(): ?array {
		$trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 60 ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
		foreach ( $trace as $i => $frame ) {
			if ( 'wp_mail' !== ( $frame['function'] ?? '' ) || isset( $frame['class'] ) ) {
				continue;
			}
			$file = (string) ( $frame['file'] ?? '' );
			$line = (int) ( $frame['line'] ?? 0 );
			// wp_mail used directly as a hook callback has no file: use the next frame that has one.
			for ( $j = $i + 1; '' === $file && isset( $trace[ $j ] ); $j++ ) {
				$file = (string) ( $trace[ $j ]['file'] ?? '' );
				$line = (int) ( $trace[ $j ]['line'] ?? 0 );
			}
			$caller   = $trace[ $i + 1 ] ?? array();
			$function = isset( $caller['function'] ) ? ( isset( $caller['class'] ) ? $caller['class'] . ( $caller['type'] ?? '::' ) : '' ) . $caller['function'] . '()' : '';
			if ( in_array( $function, array( 'include()', 'require()', 'include_once()', 'require_once()' ), true ) || '' === $function ) {
				$function = '(file)';
			}
			$where = '' !== $file ? self::relative_path( $file ) . ( $line ? ':' . $line : '' ) : '';
			if ( false !== strpos( $file, "eval()'d code" ) ) {
				// Code run through eval() (e.g. wp eval / eval-file) has no real file.
				$where = "eval()'d code" . ( $line ? ':' . $line : '' );
			}
			return array(
				'source'   => self::classify_file( $file ),
				'function' => substr( $function . ( '' !== $where ? ' — ' . $where : '' ), 0, 255 ),
			);
		}
		return null;
	}

	/** "plugin:<slug>", "mu-plugin:<slug>", "theme:<slug>", "core" or "other". */
	public static function classify_file( string $file ): string {
		if ( '' === $file ) {
			return 'other';
		}
		$file = self::canonical_path( $file );
		$own  = wp_normalize_path( untrailingslashit( TMG_SMTP_DIR ) ) . '/';
		if ( self::path_starts_with( $file, $own ) ) {
			return 'plugin:tamago-smtp';
		}
		$bases = array(
			'mu-plugin' => wp_normalize_path( WPMU_PLUGIN_DIR ) . '/',
			'plugin'    => wp_normalize_path( WP_PLUGIN_DIR ) . '/',
			'theme'     => wp_normalize_path( get_theme_root() ) . '/',
		);
		foreach ( $bases as $type => $base ) {
			if ( self::path_starts_with( $file, $base ) ) {
				$slug = strtok( substr( $file, strlen( $base ) ), '/' );
				return $type . ':' . preg_replace( '/\.php$/', '', (string) $slug );
			}
		}
		$abspath = wp_normalize_path( ABSPATH );
		$in_root = self::path_starts_with( $file, $abspath ) && false === strpos( substr( $file, strlen( $abspath ) ), '/' );
		if ( $in_root || self::path_starts_with( $file, $abspath . 'wp-includes/' ) || self::path_starts_with( $file, $abspath . 'wp-admin/' ) ) {
			return 'core';
		}
		return 'other';
	}

	/** Maps symlinked plugin folders back to their wp-content/plugins path (as plugin_basename() does). */
	private static function canonical_path( string $file ): string {
		global $wp_plugin_paths;
		$file = wp_normalize_path( $file );
		foreach ( (array) $wp_plugin_paths as $dir => $realdir ) {
			$realdir = wp_normalize_path( (string) $realdir ) . '/';
			if ( self::path_starts_with( $file, $realdir ) ) {
				return wp_normalize_path( (string) $dir ) . '/' . substr( $file, strlen( $realdir ) );
			}
		}
		return $file;
	}

	private static function path_starts_with( string $path, string $base ): bool {
		return 'Windows' === PHP_OS_FAMILY ? 0 === strncasecmp( $path, $base, strlen( $base ) ) : 0 === strpos( $path, $base );
	}

	private static function relative_path( string $file ): string {
		$file    = self::canonical_path( $file );
		$abspath = wp_normalize_path( ABSPATH );
		return self::path_starts_with( $file, $abspath ) ? substr( $file, strlen( $abspath ) ) : $file;
	}

	/** admin / front / ajax / rest / cron / cli */
	public static function context(): string {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}
		if ( wp_doing_cron() ) {
			return 'cron';
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'rest';
		}
		if ( wp_doing_ajax() ) {
			return 'ajax';
		}
		return is_admin() ? 'admin' : 'front';
	}

	/** Current request URL with secret-looking query values (reset keys, nonces, tokens) removed. */
	public static function request_url(): string {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || empty( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$uri  = esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$url  = ( is_ssl() ? 'https://' : 'http://' ) . $host . $uri;
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		if ( '' !== $query ) {
			parse_str( $query, $args );
			foreach ( $args as $name => $value ) {
				if ( preg_match( '/key|pass|pwd|token|nonce|secret|code|auth|sig/i', (string) $name ) ) {
					$args[ $name ] = 'hidden';
				}
			}
			$url = strtok( $url, '?' ) . '?' . http_build_query( $args );
		}
		return substr( $url, 0, 2000 );
	}

	/** "SMTP host:port (tls)", "PHP mail()", "sendmail", … */
	public static function describe_mailer( $phpmailer ): string {
		switch ( (string) $phpmailer->Mailer ) {
			case 'smtp':
				$secure = (string) $phpmailer->SMTPSecure;
				return 'SMTP ' . $phpmailer->Host . ':' . $phpmailer->Port . ( '' !== $secure ? ' (' . $secure . ')' : '' );
			case 'mail':
				return 'PHP mail()';
			default:
				return (string) $phpmailer->Mailer;
		}
	}

	private static function make_message_id( string $from ): string {
		$domain = '';
		if ( false !== strpos( $from, '@' ) ) {
			$domain = substr( strrchr( $from, '@' ), 1 );
		}
		if ( ! preg_match( '/^[a-z0-9.-]+\.[a-z]{2,}$/i', $domain ) ) {
			$domain = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		}
		if ( ! preg_match( '/^[a-z0-9.-]+$/i', $domain ) ) {
			$domain = 'localhost';
		}
		return '<' . bin2hex( random_bytes( 12 ) ) . '.' . time() . '@' . $domain . '>';
	}

	/** PHPMailer address list ([[address, name], …]) to "Name <a@b>, c@d". */
	private static function format_addresses( array $list ): string {
		$out = array();
		foreach ( $list as $item ) {
			$address = (string) ( $item[0] ?? '' );
			$name    = (string) ( $item[1] ?? '' );
			if ( '' !== $address ) {
				$out[] = '' !== $name ? $name . ' <' . $address . '>' : $address;
			}
		}
		return implode( ', ', $out );
	}

	public static function bare_address( string $address ): string {
		return preg_match( '/<([^>]+)>/', $address, $m ) ? trim( $m[1] ) : trim( $address );
	}

	/**
	 * Reads From / Cc / Bcc / Reply-To / Content-Type out of wp_mail() headers.
	 *
	 * @param string|string[] $headers
	 */
	public static function parse_headers( $headers ): array {
		$lines = is_array( $headers ) ? $headers : preg_split( "/\r\n|\n|\r/", (string) $headers );
		$out   = array(
			'raw'          => '',
			'from'         => '',
			'from_name'    => '',
			'cc'           => array(),
			'bcc'          => array(),
			'reply_to'     => array(),
			'content_type' => '',
		);
		$raw   = array();
		foreach ( (array) $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line || false === strpos( $line, ':' ) ) {
				continue;
			}
			$raw[]           = $line;
			list( $name, $value ) = array_map( 'trim', explode( ':', $line, 2 ) );
			switch ( strtolower( $name ) ) {
				case 'from':
					$out['from']      = self::bare_address( $value );
					$out['from_name'] = trim( (string) preg_replace( '/<[^>]*>/', '', $value ), " \"'" );
					break;
				case 'cc':
				case 'bcc':
				case 'reply-to':
					$key         = 'reply-to' === strtolower( $name ) ? 'reply_to' : strtolower( $name );
					$out[ $key ] = array_merge( $out[ $key ], array_filter( array_map( 'trim', explode( ',', $value ) ) ) );
					break;
				case 'content-type':
					$out['content_type'] = trim( strtok( $value, ';' ) );
					break;
			}
		}
		$out['raw'] = implode( "\n", $raw );
		return $out;
	}

	private static function attachments_from_args( $attachments ): array {
		if ( ! is_array( $attachments ) ) {
			$attachments = '' === (string) $attachments ? array() : explode( "\n", str_replace( "\r\n", "\n", (string) $attachments ) );
		}
		$out = array();
		foreach ( $attachments as $name => $path ) {
			$path  = (string) $path;
			$out[] = array(
				'name'   => is_string( $name ) ? $name : basename( $path ),
				'size'   => is_file( $path ) ? (int) filesize( $path ) : 0,
				'inline' => false,
			);
		}
		return $out;
	}
}
