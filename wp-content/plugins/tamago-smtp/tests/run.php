<?php
/**
 * Self-test. Prints "PASS name" / "FAIL name" lines and a total.
 *
 *   wp eval-file wp-content/plugins/tamago-smtp/tests/run.php
 *
 * Needs a local SMTP sink at 127.0.0.1:2525 (override: TMG_SMTP_TEST_PORT environment variable) that
 * accepts any login except username "reject" / password "wrong", rejects senders "reject@…" and
 * recipients "bounce@…". Settings are restored and test log rows / users removed at the end.
 *
 * Used by: developers, before a release.
 *
 * @package TamagoSMTP
 */

defined( 'ABSPATH' ) || exit;

final class TMG_SMTP_Test_Runner {

	public static $pass = 0;
	public static $fail = 0;
	public static $port = 2525;

	public static function check( string $name, bool $ok, string $detail = '' ): void {
		if ( $ok ) {
			++self::$pass;
			echo 'PASS ' . $name . "\n";
		} else {
			++self::$fail;
			echo 'FAIL ' . $name . ( '' !== $detail ? ' — ' . $detail : '' ) . "\n";
		}
	}

	/** Base settings that send to the sink. */
	public static function base( array $over = array() ): void {
		update_option( TMG_SMTP_Settings::OPTION, array(), true );
		TMG_SMTP_Settings::save(
			array_merge(
				array(
					'enabled'            => 1,
					'host'               => '127.0.0.1',
					'port'               => self::$port,
					'encryption'         => 'none',
					'auto_tls'           => 0,
					'auth'               => 1,
					'username'           => 'tester',
					'password'           => 'S3cret-pass!',
					'timeout'            => 5,
					'from_email'         => 'site@example.test',
					'from_name'          => 'Lab Site',
					'log_enabled'        => 1,
					'log_bodies'         => 1,
					'log_retention_days' => 30,
					'log_max_body_kb'    => 512,
				),
				$over
			)
		);
	}

	public static function last_row(): ?array {
		global $wpdb;
		$row = $wpdb->get_row( 'SELECT * FROM ' . TMG_SMTP_Log_Store::table() . ' ORDER BY id DESC LIMIT 1', ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ? $row : null;
	}

	public static function max_id(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . TMG_SMTP_Log_Store::table() ); // phpcs:ignore WordPress.DB
	}

	/** Runs a handler that should stop; returns the text it printed or died with. */
	public static function run_handler( callable $handler ): array {
		$died = false;
		$text = '';
		ob_start();
		try {
			$handler();
		} catch ( TMG_SMTP_Test_Died $e ) {
			$died = true;
			$text = $e->getMessage();
		}
		$text .= (string) ob_get_clean();
		return array( $died, $text );
	}
}

final class TMG_SMTP_Test_Died extends Exception {}

$tmg_die = static function () {
	return static function ( $message ) {
		if ( is_wp_error( $message ) ) {
			$message = $message->get_error_message();
		}
		throw new TMG_SMTP_Test_Died( wp_strip_all_tags( (string) $message ) );
	};
};
foreach ( array( 'wp_die_handler', 'wp_die_ajax_handler', 'wp_die_json_handler', 'wp_die_jsonp_handler', 'wp_die_xml_handler', 'wp_die_xmlrpc_handler' ) as $tmg_filter ) {
	add_filter( $tmg_filter, $tmg_die, 999 );
}

$tmg_port = getenv( 'TMG_SMTP_TEST_PORT' );
if ( $tmg_port ) {
	TMG_SMTP_Test_Runner::$port = (int) $tmg_port;
}
$t           = 'TMG_SMTP_Test_Runner';
$backup      = get_option( TMG_SMTP_Settings::OPTION, null );
$alert_last  = get_option( TMG_SMTP_Alerts::LAST_OPTION, null );
$start_id    = $t::max_id();
$admin_user  = get_current_user_id();
$tmp_file    = '';
$test_users  = array();

try {
	/* ------------------------------------------------------------- */
	/* Sink                                                           */
	/* ------------------------------------------------------------- */
	$sock = @fsockopen( '127.0.0.1', $t::$port, $errno, $errstr, 2 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$t::check( 'smtp sink reachable at 127.0.0.1:' . $t::$port, (bool) $sock, (string) $errstr );
	if ( $sock ) {
		fclose( $sock ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/* ------------------------------------------------------------- */
	/* Encryption                                                     */
	/* ------------------------------------------------------------- */
	$secret = 'p@ss wörd/with "quotes" & ünïcode';
	$gcm    = TMG_SMTP_Crypto::encrypt( $secret, 'gcm' );
	$cbc    = TMG_SMTP_Crypto::encrypt( $secret, 'cbc' );
	$t::check( 'crypto: gcm round-trip', TMG_SMTP_Crypto::gcm_available() ? ( 0 === strpos( $gcm, 'tmg1:g:' ) && TMG_SMTP_Crypto::decrypt( $gcm ) === $secret ) : true );
	$t::check( 'crypto: cbc+hmac round-trip', 0 === strpos( $cbc, 'tmg1:c:' ) && TMG_SMTP_Crypto::decrypt( $cbc ) === $secret );
	$t::check( 'crypto: random iv (two encryptions differ)', TMG_SMTP_Crypto::encrypt( $secret ) !== TMG_SMTP_Crypto::encrypt( $secret ) );
	$t::check( 'crypto: ciphertext does not contain the secret', false === strpos( $gcm . $cbc, 'with' ) );
	$tampered = substr( $cbc, 0, -4 ) . ( 'AAAA' === substr( $cbc, -4 ) ? 'BBBB' : 'AAAA' );
	$t::check( 'crypto: tampered cbc value gives empty string', '' === TMG_SMTP_Crypto::decrypt( $tampered ) );
	$tampered = substr( $gcm, 0, -4 ) . ( 'AAAA' === substr( $gcm, -4 ) ? 'BBBB' : 'AAAA' );
	$t::check( 'crypto: tampered gcm value gives empty string', '' === TMG_SMTP_Crypto::decrypt( $tampered ) );
	$t::check( 'crypto: empty stays empty, plain text passes through', '' === TMG_SMTP_Crypto::encrypt( '' ) && 'legacy' === TMG_SMTP_Crypto::decrypt( 'legacy' ) );

	/* ------------------------------------------------------------- */
	/* Password rules                                                 */
	/* ------------------------------------------------------------- */
	$old = array_merge(
		TMG_SMTP_Settings::defaults(),
		array(
			'host'     => 'smtp.one.test',
			'username' => 'user1',
			'password' => TMG_SMTP_Crypto::encrypt( 'oldpass' ),
		)
	);
	$form = array(
		'host'     => 'smtp.one.test',
		'username' => 'user1',
		'password' => '',
	);
	$new  = TMG_SMTP_Settings::prepare_save( $form, $old );
	$t::check( 'password: empty input keeps the saved password', 'oldpass' === TMG_SMTP_Crypto::decrypt( $new['password'] ) );
	$new = TMG_SMTP_Settings::prepare_save( array_merge( $form, array( 'host' => 'smtp.two.test' ) ), $old );
	$t::check( 'password: dropped when the host changes', '' === $new['password'] );
	$new = TMG_SMTP_Settings::prepare_save( array_merge( $form, array( 'username' => 'user2' ) ), $old );
	$t::check( 'password: dropped when the username changes', '' === $new['password'] );
	$new = TMG_SMTP_Settings::prepare_save( array_merge( $form, array( 'host' => 'smtp.two.test', 'password' => 'newpass' ) ), $old );
	$t::check( 'password: a typed password is stored encrypted, even with a new host', 'newpass' === TMG_SMTP_Crypto::decrypt( $new['password'] ) && TMG_SMTP_Crypto::is_encrypted( $new['password'] ) );
	$new = TMG_SMTP_Settings::prepare_save( array_merge( $form, array( 'password_clear' => '1' ) ), $old );
	$t::check( 'password: "remove" checkbox clears it', '' === $new['password'] );
	$new = TMG_SMTP_Settings::prepare_save( array( 'password' => 'injected', 'timeout' => 9 ), $old, true );
	$t::check( 'password: import never sets the password', 'oldpass' === TMG_SMTP_Crypto::decrypt( $new['password'] ) && 9 === $new['timeout'] );
	$new = TMG_SMTP_Settings::prepare_save( array( 'host' => 'smtp.other.test' ), $old, true );
	$t::check( 'password: import with a new host drops the password', '' === $new['password'] );
	$t::base();
	$t::check( 'password: get() returns the decrypted password', 'S3cret-pass!' === TMG_SMTP_Settings::get( 'password' ) );
	$t::check( 'password: stored value is encrypted', TMG_SMTP_Crypto::is_encrypted( (string) TMG_SMTP_Settings::stored()['password'] ) );

	/* ------------------------------------------------------------- */
	/* Constant override                                              */
	/* ------------------------------------------------------------- */
	if ( ! defined( 'TMG_SMTP_TIMEOUT' ) ) {
		define( 'TMG_SMTP_TIMEOUT', 7 );
	}
	$t::check( 'constant: TMG_SMTP_TIMEOUT locks the value', TMG_SMTP_Settings::is_locked( 'timeout' ) && (int) TMG_SMTP_TIMEOUT === TMG_SMTP_Settings::get( 'timeout' ) );
	$new = TMG_SMTP_Settings::prepare_save( array( 'timeout' => 33 ), TMG_SMTP_Settings::stored() );
	$t::check( 'constant: saving cannot change a locked value', 5 === (int) $new['timeout'] && 7 === TMG_SMTP_Mailer::server_config()['timeout'] );
	$t::check( 'constant: unlocked keys are not locked', ! TMG_SMTP_Settings::is_locked( 'host' ) && '' === TMG_SMTP_Settings::constant_for( 'host' ) );

	/* ------------------------------------------------------------- */
	/* From rules                                                     */
	/* ------------------------------------------------------------- */
	$default_from = TMG_SMTP_Mailer::default_from_email();
	$t::check( 'from: default wordpress@ address is replaced', 'site@example.test' === apply_filters( 'wp_mail_from', $default_from ) );
	$t::check( 'from: an address chosen by a plugin is kept', 'shop@example.test' === apply_filters( 'wp_mail_from', 'shop@example.test' ) );
	$t::check( 'from: default name "WordPress" is replaced, custom kept', 'Lab Site' === apply_filters( 'wp_mail_from_name', 'WordPress' ) && 'Shop' === apply_filters( 'wp_mail_from_name', 'Shop' ) );
	$t::base( array( 'force_from_email' => 1, 'force_from_name' => 1 ) );
	$t::check( 'from: forced address replaces every sender', 'site@example.test' === apply_filters( 'wp_mail_from', 'shop@example.test' ) );
	$t::check( 'from: forced name replaces every name', 'Lab Site' === apply_filters( 'wp_mail_from_name', 'Shop' ) );
	$t::base();

	/* ------------------------------------------------------------- */
	/* Logging: sent                                                  */
	/* ------------------------------------------------------------- */
	$tmp_file = wp_tempnam( 'tmg-smtp-test' );
	file_put_contents( $tmp_file, str_repeat( 'x', 1234 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$t::base( array( 'bcc' => 'audit@example.test', 'reply_to' => 'default-reply@example.test' ) );
	$logged_hook = array();
	$on_logged   = static function ( $id, $row ) use ( &$logged_hook ) {
		$logged_hook = array( $id, $row['subject'] );
	};
	add_action( 'tmg_smtp_logged', $on_logged, 10, 2 );
	$ok  = wp_mail( 'Alice <alice@example.test>', 'Sent test ü', '<p>Hello <b>Alice</b></p>', array( 'Content-Type: text/html; charset=UTF-8', 'Cc: carol@example.test', 'Reply-To: replies@example.test' ), array( 'report.txt' => $tmp_file ) );
	remove_action( 'tmg_smtp_logged', $on_logged, 10 );
	$row = $t::last_row();
	$t::check( 'log sent: wp_mail returned true', true === $ok );
	$t::check( 'log sent: row written with status sent', $row && 'sent' === $row['status'] && 'Sent test ü' === $row['subject'] );
	$t::check( 'log sent: to / cc / reply-to recorded', $row && false !== strpos( $row['to_email'], 'alice@example.test' ) && false !== strpos( $row['cc'], 'carol@example.test' ) && false !== strpos( $row['reply_to'], 'replies@example.test' ) );
	$t::check( 'log sent: own Reply-To wins over the default', $row && false === strpos( $row['reply_to'], 'default-reply@' ) );
	$t::check( 'log sent: always-BCC added and recorded', $row && false !== strpos( $row['bcc'], 'audit@example.test' ) );
	$t::check( 'log sent: from address and name', $row && 'site@example.test' === $row['from_email'] && 'Lab Site' === $row['from_name'] );
	$t::check( 'log sent: html body and content type', $row && 'text/html' === $row['content_type'] && false !== strpos( (string) $row['body_html'], '<b>Alice</b>' ) );
	$att = $row ? json_decode( (string) $row['attachments'], true ) : array();
	$t::check( 'log sent: attachment name and size, no copy', is_array( $att ) && 'report.txt' === ( $att[0]['name'] ?? '' ) && 1234 === ( $att[0]['size'] ?? 0 ) );
	$t::check( 'log sent: mailer is SMTP host:port', $row && 'SMTP 127.0.0.1:' . $t::$port === $row['mailer'] );
	$t::check( 'log sent: Message-ID recorded and found in the headers', $row && preg_match( '/^<[^>]+@[^>]+>$/', $row['message_id'] ) && false !== strpos( (string) $row['headers'], $row['message_id'] ) );
	$t::check( 'log sent: full headers include Subject and Cc', $row && false !== stripos( (string) $row['headers'], 'Cc: carol@example.test' ) );
	$t::check( 'log sent: caller recorded (eval-file code is "other")', $row && 'other' === $row['source'] && false !== strpos( $row['source_function'], "eval()'d code" ) );
	$t::check( 'source: own plugin file (symlinked folder)', 'plugin:tamago-smtp' === TMG_SMTP_Logger::classify_file( TMG_SMTP_DIR . 'includes/tester.php' ) );
	$t::check( 'source: other plugin', 'plugin:woocommerce' === TMG_SMTP_Logger::classify_file( WP_PLUGIN_DIR . '/woocommerce/includes/class-wc-emails.php' ) );
	$t::check( 'source: single-file plugin', 'plugin:hello' === TMG_SMTP_Logger::classify_file( WP_PLUGIN_DIR . '/hello.php' ) );
	$t::check( 'source: theme', 'theme:lab' === TMG_SMTP_Logger::classify_file( get_theme_root() . '/lab/functions.php' ) );
	$t::check( 'source: mu-plugin', 'mu-plugin:site-tools' === TMG_SMTP_Logger::classify_file( WPMU_PLUGIN_DIR . '/site-tools.php' ) );
	$t::check( 'source: core (wp-includes and wp-login.php)', 'core' === TMG_SMTP_Logger::classify_file( ABSPATH . WPINC . '/pluggable.php' ) && 'core' === TMG_SMTP_Logger::classify_file( ABSPATH . 'wp-login.php' ) );
	$t::check( 'log sent: context is cli, duration recorded', $row && 'cli' === $row['context'] && (int) $row['duration_ms'] >= 0 );
	$t::check( 'log sent: no transcript kept for success by default', $row && '' === (string) $row['transcript'] );
	$t::check( 'log sent: tmg_smtp_logged fired with id and row', $row && (int) $row['id'] === (int) ( $logged_hook[0] ?? 0 ) && 'Sent test ü' === ( $logged_hook[1] ?? '' ) );
	$t::check( 'log sent: debug settings restored after sending', 0 === $GLOBALS['phpmailer']->SMTPDebug && '' === $GLOBALS['phpmailer']->MessageID );

	$t::base( array( 'log_transcript_success' => 1 ) );
	wp_mail( 'bob@example.test', 'Text with transcript', "Line 1\nLine 2" );
	$row = $t::last_row();
	$t::check( 'log sent: plain text body stored as text', $row && "Line 1\nLine 2" === $row['body_text'] && null === $row['body_html'] );
	$t::check( 'log sent: transcript kept when the setting is on', $row && false !== strpos( (string) $row['transcript'], '250' ) );
	$t::check( 'log sent: transcript hides the login lines and password', $row && false !== strpos( (string) $row['transcript'], 'AUTH LOGIN' ) && false === strpos( (string) $row['transcript'], base64_encode( 'S3cret-pass!' ) ) && false === strpos( (string) $row['transcript'], base64_encode( 'tester' ) ) && false === strpos( (string) $row['transcript'], 'S3cret-pass!' ) );
	$t::check( 'log sent: transcript does not repeat the message content', $row && false === strpos( (string) $row['transcript'], 'Line 2' ) && false !== strpos( (string) $row['transcript'], 'message content not shown' ) );

	/* Body storage switches. */
	$t::base( array( 'log_bodies' => 0 ) );
	wp_mail( 'bob@example.test', 'No body stored', 'secret content' );
	$row = $t::last_row();
	$t::check( 'log privacy: body not stored when switched off', $row && null === $row['body_text'] && null === $row['body_html'] && ! TMG_SMTP_Logger::can_resend( $row ) );
	$t::base( array( 'log_max_body_kb' => 1 ) );
	wp_mail( 'bob@example.test', 'Long body', str_repeat( 'abcdefghij', 300 ) );
	$row = $t::last_row();
	$t::check( 'log privacy: long body cut at the maximum size', $row && 1024 === strlen( (string) $row['body_text'] ) && 1 === (int) $row['body_truncated'] );
	$t::base();

	/* Filters. */
	$before = $t::max_id();
	$off    = static function ( $log, $atts ) {
		return 'Do not log me' === ( $atts['subject'] ?? '' ) ? false : $log;
	};
	add_filter( 'tmg_smtp_log_enabled', $off, 10, 2 );
	wp_mail( 'bob@example.test', 'Do not log me', 'x' );
	remove_filter( 'tmg_smtp_log_enabled', $off, 10 );
	$t::check( 'filter: tmg_smtp_log_enabled false skips the row', $before === $t::max_id() );
	$change = static function ( $row ) {
		$row['subject'] = '[changed] ' . $row['subject'];
		return $row;
	};
	add_filter( 'tmg_smtp_log_row', $change );
	wp_mail( 'bob@example.test', 'Row filter', 'x' );
	remove_filter( 'tmg_smtp_log_row', $change );
	$row = $t::last_row();
	$t::check( 'filter: tmg_smtp_log_row can change the row', $row && '[changed] Row filter' === $row['subject'] );
	$before_send = 0;
	$count_hook  = static function ( $phpmailer ) use ( &$before_send ) {
		$before_send += is_object( $phpmailer ) ? 1 : 0;
	};
	add_action( 'tmg_smtp_before_send', $count_hook );
	wp_mail( 'bob@example.test', 'Before send hook', 'x' );
	remove_action( 'tmg_smtp_before_send', $count_hook );
	$t::check( 'action: tmg_smtp_before_send receives PHPMailer', 1 === $before_send );

	/* Short-circuit. */
	$short = static function () {
		return true;
	};
	add_filter( 'pre_wp_mail', $short );
	wp_mail( 'bob@example.test', 'Short-circuited', 'x' );
	remove_filter( 'pre_wp_mail', $short );
	$row = $t::last_row();
	$t::check( 'log: pre_wp_mail short-circuit is logged and closes its frame', $row && 'Short-circuited' === $row['subject'] && 'pre_wp_mail' === $row['mailer'] && 0 === TMG_SMTP_Logger::depth() );

	/* ------------------------------------------------------------- */
	/* Logging: failed                                                */
	/* ------------------------------------------------------------- */
	$t::base( array( 'port' => 2599 ) );
	$ok  = wp_mail( 'bob@example.test', 'Refused connection', 'x' );
	$row = $t::last_row();
	$t::check( 'log failed: wp_mail returned false', false === $ok );
	$t::check( 'log failed: row with status failed and error', $row && 'failed' === $row['status'] && '' !== (string) $row['error'] );
	$t::check( 'log failed: transcript always kept on failure', $row && '' !== (string) $row['transcript'] );
	$t::check( 'log failed: connection hint', $row && false !== stripos( TMG_SMTP_Hints::for_error( (string) $row['error'], (string) $row['transcript'] ), 'could not reach' ) );

	$t::base( array( 'password' => 'wrong' ) );
	$ok  = wp_mail( 'bob@example.test', 'Bad password', 'x' );
	$row = $t::last_row();
	$t::check( 'log failed: authentication failure logged', false === $ok && $row && 'failed' === $row['status'] );
	$t::check( 'log failed: authentication hint', $row && false !== stripos( TMG_SMTP_Hints::for_error( (string) $row['error'], (string) $row['transcript'] ), 'username or password' ) );
	$t::check( 'log failed: transcript hides the wrong password', $row && false === strpos( (string) $row['transcript'], base64_encode( 'wrong' ) ) && false !== strpos( (string) $row['transcript'], '535' ) );

	$t::base( array( 'from_email' => 'reject@example.test', 'force_from_email' => 1 ) );
	wp_mail( 'bob@example.test', 'Rejected sender', 'x' );
	$row = $t::last_row();
	$t::check( 'log failed: From rejected hint', $row && 'failed' === $row['status'] && false !== stripos( TMG_SMTP_Hints::for_error( (string) $row['error'], (string) $row['transcript'] ), 'From address' ) );

	$t::base( array( 'from_email' => '' ) );
	$bad_from = static function () {
		return 'not-an-address';
	};
	add_filter( 'wp_mail_from', $bad_from, 1000 );
	wp_mail( 'bob@example.test', 'Invalid from', 'x' );
	remove_filter( 'wp_mail_from', $bad_from, 1000 );
	$row = $t::last_row();
	$t::check( 'log failed: early failure (before phpmailer_init) still logged', $row && 'Invalid from' === $row['subject'] && 'failed' === $row['status'] && 0 === TMG_SMTP_Logger::depth() );
	$t::base();

	/* ------------------------------------------------------------- */
	/* Nested sends                                                   */
	/* ------------------------------------------------------------- */
	$before = $t::max_id();
	$done   = false;
	$nest   = static function ( $atts ) use ( &$done ) {
		if ( ! $done && 'Outer (filter)' === ( $atts['subject'] ?? '' ) ) {
			$done = true;
			wp_mail( 'inner@example.test', 'Inner (filter)', 'inner body' );
		}
		return $atts;
	};
	add_filter( 'wp_mail', $nest, PHP_INT_MAX );
	wp_mail( 'outer@example.test', 'Outer (filter)', 'outer body' );
	remove_filter( 'wp_mail', $nest, PHP_INT_MAX );
	$result = TMG_SMTP_Log_Store::query( array( 'after_id' => $before, 'orderby' => 'id', 'order' => 'ASC', 'full' => true ) );
	$rows   = $result['rows'];
	$t::check( 'nested (inside wp_mail filter): two rows', 2 === count( $rows ) );
	$t::check(
		'nested (inside wp_mail filter): each row has its own subject, recipient and body',
		2 === count( $rows ) && 'Inner (filter)' === $rows[0]['subject'] && false !== strpos( $rows[0]['to_email'], 'inner@' ) && 'inner body' === $rows[0]['body_text']
			&& 'Outer (filter)' === $rows[1]['subject'] && false !== strpos( $rows[1]['to_email'], 'outer@' ) && 'outer body' === $rows[1]['body_text']
	);
	$t::check( 'nested (inside wp_mail filter): different Message-IDs', 2 === count( $rows ) && $rows[0]['message_id'] !== $rows[1]['message_id'] && '' !== $rows[1]['message_id'] );

	$before = $t::max_id();
	$done   = false;
	$nest2  = static function () use ( &$done ) {
		if ( ! $done ) {
			$done = true;
			wp_mail( 'inner2@example.test', 'Inner (succeeded hook)', 'inner' );
		}
	};
	add_action( 'wp_mail_succeeded', $nest2, 10 );
	wp_mail( 'outer2@example.test', 'Outer (succeeded hook)', 'outer' );
	remove_action( 'wp_mail_succeeded', $nest2, 10 );
	$rows = TMG_SMTP_Log_Store::query( array( 'after_id' => $before, 'orderby' => 'id', 'order' => 'ASC' ) )['rows'];
	$t::check( 'nested (inside wp_mail_succeeded): both logged correctly', 2 === count( $rows ) && 'Outer (succeeded hook)' === $rows[0]['subject'] && 'Inner (succeeded hook)' === $rows[1]['subject'] && false !== strpos( $rows[1]['to_email'], 'inner2@' ) );
	$t::check( 'nested: stack empty afterwards', 0 === TMG_SMTP_Logger::depth() );

	/* ------------------------------------------------------------- */
	/* Transcript masking (unit)                                      */
	/* ------------------------------------------------------------- */
	$raw    = implode(
		"\n",
		array(
			'SERVER -> CLIENT: 250-AUTH LOGIN PLAIN',
			'CLIENT -> SERVER: AUTH LOGIN',
			'SERVER -> CLIENT: 334 VXNlcm5hbWU6',
			'CLIENT -> SERVER: ' . base64_encode( 'user@example.test' ),
			'SERVER -> CLIENT: 334 UGFzc3dvcmQ6',
			'CLIENT -> SERVER: ' . base64_encode( 'TopSecret9' ),
			'SERVER -> CLIENT: 235 ok',
			'CLIENT -> SERVER: AUTH PLAIN ' . base64_encode( "\0user\0TopSecret9" ),
			'SERVER -> CLIENT: 235 ok',
			'CLIENT -> SERVER: MAIL FROM:<a@b.test>',
			'Some error mentioning TopSecret9 in plain text',
		)
	);
	$masked = TMG_SMTP_Transcript::mask( $raw, array( 'TopSecret9' ) );
	$t::check( 'mask: base64 lines after AUTH LOGIN removed', false === strpos( $masked, base64_encode( 'user@example.test' ) ) && false === strpos( $masked, base64_encode( 'TopSecret9' ) ) );
	$t::check( 'mask: AUTH PLAIN argument removed', false === strpos( $masked, base64_encode( "\0user\0TopSecret9" ) ) && false !== strpos( $masked, 'AUTH PLAIN [hidden]' ) );
	$t::check( 'mask: password removed everywhere', false === strpos( $masked, 'TopSecret9' ) );
	$t::check( 'mask: normal commands kept', false !== strpos( $masked, 'MAIL FROM:<a@b.test>' ) && false !== strpos( $masked, '334 VXNlcm5hbWU6' ) );
	$early  = TMG_SMTP_Transcript::mask( "CLIENT -> SERVER: AUTH LOGIN\nSERVER -> CLIENT: 535 no\nCLIENT -> SERVER: QUIT", array() );
	$t::check( 'mask: masking stops when the server ends the exchange', false !== strpos( $early, 'CLIENT -> SERVER: QUIT' ) );

	/* ------------------------------------------------------------- */
	/* Hints (unit)                                                   */
	/* ------------------------------------------------------------- */
	$t::check( 'hint: authentication', false !== strpos( TMG_SMTP_Hints::for_error( 'SMTP Error: Could not authenticate.' ), 'username or password' ) );
	$t::check( 'hint: connection refused / timed out', false !== strpos( TMG_SMTP_Hints::for_error( 'SMTP Error: Could not connect to SMTP host. Connection timed out' ), 'could not reach' ) );
	$t::check( 'hint: certificate', false !== strpos( TMG_SMTP_Hints::for_error( 'SMTP Error: Could not connect', 'stream_socket_enable_crypto(): SSL operation failed with code 1. certificate verify failed' ), 'certificate' ) );
	$t::check( 'hint: From rejected', false !== strpos( TMG_SMTP_Hints::for_error( 'Invalid address:  (From): wordpress@localhost' ), 'From address' ) );
	$t::check( 'hint: nothing for unknown errors', '' === TMG_SMTP_Hints::for_error( 'Something odd' ) );
	$t::check( 'hint: EHLO capability lines do not cause false matches', '' === TMG_SMTP_Hints::for_error( 'Something odd', "CLIENT -> SERVER: AUTH LOGIN\nSERVER -> CLIENT: 250-AUTH LOGIN" ) );

	/* ------------------------------------------------------------- */
	/* Resend                                                         */
	/* ------------------------------------------------------------- */
	wp_mail( 'resend@example.test', 'To be resent', '<p>Again</p>', array( 'Content-Type: text/html' ) );
	$orig   = $t::last_row();
	$result = TMG_SMTP_Logger::resend( (int) $orig['id'] );
	$copy   = $t::last_row();
	$orig2  = TMG_SMTP_Log_Store::get( (int) $orig['id'] );
	$t::check( 'resend: new entry linked to the original', ! is_wp_error( $result ) && $result['ok'] && (int) $copy['resend_of'] === (int) $orig['id'] && (int) $copy['id'] === (int) $result['log_id'] );
	$t::check( 'resend: same recipient, subject and body', $copy && $copy['to_email'] === $orig['to_email'] && 'To be resent' === $copy['subject'] && '<p>Again</p>' === $copy['body_html'] );
	$t::check( 'resend: original resend count increased', 1 === (int) $orig2['resend_count'] );

	/* ------------------------------------------------------------- */
	/* Failure alert                                                  */
	/* ------------------------------------------------------------- */
	$t::base( array( 'port' => 2599, 'alert_enabled' => 1, 'alert_email' => 'alerts@example.test' ) );
	delete_option( TMG_SMTP_Alerts::LAST_OPTION );
	$before = $t::max_id();
	wp_mail( 'bob@example.test', 'Fails and alerts', 'x' );
	wp_mail( 'bob@example.test', 'Fails again within the hour', 'x' );
	$rows   = TMG_SMTP_Log_Store::query( array( 'after_id' => $before, 'per_page' => 50 ) )['rows'];
	$alerts = array_filter(
		$rows,
		static function ( $r ) {
			return false !== strpos( (string) $r['subject'], 'Email sending failed' );
		}
	);
	$t::check( 'alert: exactly one alert for two failures (once per hour, no loop on its own failure)', 1 === count( $alerts ) && 3 === count( $rows ) );
	$t::check( 'alert: sent to the alert address', $alerts && false !== strpos( reset( $alerts )['to_email'], 'alerts@example.test' ) );
	$t::base();

	/* ------------------------------------------------------------- */
	/* Retention purge                                                */
	/* ------------------------------------------------------------- */
	$old_id = TMG_SMTP_Log_Store::insert( array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ), 'status' => 'sent', 'subject' => 'old', 'to_email' => 'x@example.test' ) );
	$new_id = TMG_SMTP_Log_Store::insert( array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 1 * DAY_IN_SECONDS ), 'status' => 'sent', 'subject' => 'new', 'to_email' => 'x@example.test' ) );
	do_action( TMG_SMTP_Log_Store::CRON );
	$t::check( 'purge: entries older than the retention are deleted', null === TMG_SMTP_Log_Store::get( $old_id ) );
	$t::check( 'purge: recent entries are kept', null !== TMG_SMTP_Log_Store::get( $new_id ) );
	$t::check( 'purge: daily event scheduled', (bool) wp_next_scheduled( TMG_SMTP_Log_Store::CRON ) );
	$t::check( 'purge: retention 0 keeps everything', 0 === TMG_SMTP_Log_Store::purge( 0 ) );

	/* ------------------------------------------------------------- */
	/* Settings export / import                                       */
	/* ------------------------------------------------------------- */
	$t::base( array( 'reply_to' => 'export@example.test' ) );
	$export = TMG_SMTP_Settings::export();
	$t::check( 'export: shape (plugin, version, exported, settings)', 'tamago-smtp' === $export['plugin'] && TMG_SMTP_VERSION === $export['version'] && ! empty( $export['exported'] ) && is_array( $export['settings'] ) );
	$t::check( 'export: password never included', ! array_key_exists( 'password', $export['settings'] ) && false === strpos( wp_json_encode( $export ), 'tmg1:' ) );
	$t::check( 'export: values included', 'export@example.test' === $export['settings']['reply_to'] && '127.0.0.1' === $export['settings']['host'] );
	$file                          = $export;
	$file['settings']['reply_to']  = 'imported@example.test';
	$file['settings']['password']  = 'from-file';
	$file['settings']['not_a_key'] = 'x';
	$result                        = TMG_SMTP_Settings::import( json_decode( wp_json_encode( $file ), true ) );
	$t::check( 'import: values applied', ! is_wp_error( $result ) && 'imported@example.test' === TMG_SMTP_Settings::get( 'reply_to' ) );
	$t::check( 'import: password in the file ignored, saved one kept', 'S3cret-pass!' === TMG_SMTP_Settings::get( 'password' ) );
	$t::check( 'import: unknown keys ignored', ! array_key_exists( 'not_a_key', get_option( TMG_SMTP_Settings::OPTION ) ) );
	$t::check( 'import: wrong file rejected', is_wp_error( TMG_SMTP_Settings::import( array( 'plugin' => 'other', 'settings' => array() ) ) ) );
	$t::base();

	/* ------------------------------------------------------------- */
	/* Privacy exporter / eraser                                      */
	/* ------------------------------------------------------------- */
	wp_mail( 'privacy.person@example.test', 'Privacy test', 'personal' );
	TMG_SMTP_Log_Store::insert( array( 'status' => 'sent', 'subject' => 'lookalike', 'to_email' => 'xprivacy.person@example.test' ) );
	$exported = TMG_SMTP_Privacy::export( 'privacy.person@example.test' );
	$t::check( 'privacy: exporter finds the entry (exact address only)', 1 === count( $exported['data'] ) && $exported['done'] );
	$erased = TMG_SMTP_Privacy::erase( 'privacy.person@example.test' );
	$t::check( 'privacy: eraser removes it', $erased['items_removed'] && array() === TMG_SMTP_Log_Store::ids_by_email( 'privacy.person@example.test' ) );
	$t::check( 'privacy: look-alike address untouched', array() !== TMG_SMTP_Log_Store::ids_by_email( 'xprivacy.person@example.test' ) );

	/* ------------------------------------------------------------- */
	/* Capabilities                                                   */
	/* ------------------------------------------------------------- */
	$admin_role = get_role( 'administrator' );
	$t::check( 'caps: administrator has both capabilities', $admin_role && $admin_role->has_cap( 'tmg_smtp_manage' ) && $admin_role->has_cap( 'tmg_smtp_view_logs' ) );
	$perms = apply_filters( 'tamago_permissions', array() );
	$t::check( 'caps: registered through tamago_permissions', isset( $perms['smtp_settings'], $perms['smtp_logs'] ) && array( 'tmg_smtp_manage' ) === $perms['smtp_settings']['caps'] && array( 'tmg_smtp_view_logs' ) === $perms['smtp_logs']['caps'] );

	require_once TMG_SMTP_DIR . 'includes/admin-screen.php';
	require_once TMG_SMTP_DIR . 'includes/logs-screen.php';
	$sub_id       = wp_insert_user(
		array(
			'user_login' => 'tmg_smtp_test_' . wp_generate_password( 6, false ),
			'user_pass'  => wp_generate_password( 24 ),
			'user_email' => 'tmg-smtp-sub-' . wp_generate_password( 6, false ) . '@example.test',
			'role'       => 'subscriber',
		)
	);
	$test_users[] = $sub_id;
	wp_set_current_user( $sub_id );
	$count_before    = TMG_SMTP_Log_Store::count();
	$settings_before = get_option( TMG_SMTP_Settings::OPTION );
	$any_id          = (int) $t::last_row()['id'];
	$handlers        = array(
		'save settings'      => array( 'tmg_smtp_save_settings', array( 'TMG_SMTP_Admin', 'handle_save' ), array( 'tmg_smtp' => array( 'host' => 'evil.test' ) ) ),
		'export settings'    => array( 'tmg_smtp_export_settings', array( 'TMG_SMTP_Admin', 'handle_export' ), array() ),
		'import settings'    => array( 'tmg_smtp_import_settings', array( 'TMG_SMTP_Admin', 'handle_import' ), array() ),
		'delete all logs'    => array( 'tmg_smtp_log_delete_all', array( 'TMG_SMTP_Logs_Screen', 'handle_delete_all' ), array() ),
		'delete one log'     => array( 'tmg_smtp_log_delete_' . $any_id, array( 'TMG_SMTP_Logs_Screen', 'handle_delete' ), array( 'id' => $any_id ) ),
		'resend'             => array( 'tmg_smtp_log_resend_' . $any_id, array( 'TMG_SMTP_Logs_Screen', 'handle_resend' ), array( 'id' => $any_id ) ),
		'export logs csv'    => array( 'tmg_smtp_log_export', array( 'TMG_SMTP_Logs_Screen', 'handle_export' ), array() ),
		'ajax test email'    => array( 'tmg_smtp_test', array( 'TMG_SMTP_Tester', 'ajax_send_test' ), array( 'to' => 'x@example.test' ) ),
		'ajax check connect' => array( 'tmg_smtp_test', array( 'TMG_SMTP_Tester', 'ajax_check_connection' ), array() ),
	);
	$before_max = $t::max_id();
	foreach ( $handlers as $label => $spec ) {
		list( $nonce_action, $callback, $fields ) = $spec;
		$is_ajax                                  = 0 === strpos( $callback[1], 'ajax_' );
		$nonce                                    = wp_create_nonce( $nonce_action );
		$_POST                                    = array_merge( $fields, array( '_wpnonce' => $nonce, '_ajax_nonce' => $nonce ) );
		$_GET                                     = $_POST;
		$_REQUEST                                 = $_POST;
		$ajax_filter                              = static function () use ( $is_ajax ) {
			return $is_ajax;
		};
		add_filter( 'wp_doing_ajax', $ajax_filter );
		list( $died, $text ) = $t::run_handler( $callback );
		remove_filter( 'wp_doing_ajax', $ajax_filter );
		$t::check( 'caps: subscriber refused by handler "' . $label . '"', $died && false !== stripos( $text, 'not allowed' ), $text );
	}
	$_POST    = array();
	$_GET     = array();
	$_REQUEST = array();
	$t::check( 'caps: nothing changed by refused handlers', $count_before === TMG_SMTP_Log_Store::count() && $settings_before === get_option( TMG_SMTP_Settings::OPTION ) && $before_max === $t::max_id() );
	$t::check( 'caps: subscriber has neither capability', ! current_user_can( 'tmg_smtp_manage' ) && ! current_user_can( 'tmg_smtp_view_logs' ) );

	// A user with only the log capability can view logs but not manage settings.
	$sub = get_userdata( $sub_id );
	$sub->add_cap( 'tmg_smtp_view_logs' );
	wp_set_current_user( 0 );
	wp_set_current_user( $sub_id );
	$tabs = TMG_SMTP_Admin::tabs();
	$t::check( 'caps: log-only user sees just the Logs tab', array( 'logs' ) === array_keys( $tabs ) && 'tmg_smtp_view_logs' === TMG_SMTP_Permissions::menu_cap() );
	$nonce    = wp_create_nonce( 'tmg_smtp_save_settings' );
	$_POST    = array( '_wpnonce' => $nonce, 'tmg_smtp' => array( 'host' => 'evil.test' ) );
	$_REQUEST = $_POST;
	list( $died, $text ) = $t::run_handler( array( 'TMG_SMTP_Admin', 'handle_save' ) );
	$t::check( 'caps: log-only user refused by settings save', $died && false !== stripos( $text, 'not allowed' ) && $settings_before === get_option( TMG_SMTP_Settings::OPTION ) );
	$_POST    = array();
	$_REQUEST = array();
	wp_set_current_user( $admin_user );
} catch ( Throwable $e ) {
	$t::check( 'no unexpected exception', false, get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
} finally {
	// Clean up: settings, alert timestamp, test rows, users, temp file.
	if ( null === $backup ) {
		delete_option( TMG_SMTP_Settings::OPTION );
	} else {
		update_option( TMG_SMTP_Settings::OPTION, $backup, true );
	}
	if ( null === $alert_last ) {
		delete_option( TMG_SMTP_Alerts::LAST_OPTION );
	} else {
		update_option( TMG_SMTP_Alerts::LAST_OPTION, $alert_last, false );
	}
	global $wpdb;
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . TMG_SMTP_Log_Store::table() . ' WHERE id > %d', $start_id ) ); // phpcs:ignore WordPress.DB
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $test_users as $uid ) {
		if ( $uid && ! is_wp_error( $uid ) ) {
			wp_delete_user( (int) $uid );
		}
	}
	if ( $tmp_file && file_exists( $tmp_file ) ) {
		wp_delete_file( $tmp_file );
	}
	wp_set_current_user( $admin_user );
}

printf( "\nTOTAL: %d passed, %d failed\n", TMG_SMTP_Test_Runner::$pass, TMG_SMTP_Test_Runner::$fail );
