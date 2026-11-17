<?php
/**
 * Plain-language hints for common sending errors (authentication, connection, certificate, sender, recipient).
 *
 * Only the error message and the server's replies in the transcript are read, never the client lines
 * (they contain the EHLO capabilities, which mention AUTH and would cause false matches).
 *
 * Used by: TMG_SMTP_Tester (test email, connection check), log detail view, WP-CLI test.
 *
 * @package TamagoSMTP
 */

defined( 'ABSPATH' ) || exit;

class TMG_SMTP_Hints {

	/**
	 * @param string $error      Error message (PHPMailer / WordPress).
	 * @param string $transcript SMTP transcript, if any.
	 * @return string Hint, or '' when nothing matches.
	 */
	public static function for_error( string $error, string $transcript = '' ): string {
		$server = array();
		foreach ( preg_split( "/\r\n|\n|\r/", $transcript ) as $line ) {
			if ( false === strpos( $line, 'CLIENT -> SERVER' ) ) {
				$server[] = $line;
			}
		}
		$text = $error . "\n" . implode( "\n", $server );
		if ( '' === trim( $text ) ) {
			return '';
		}

		if ( preg_match( '/\(From\)|following From address failed|Sender address rejected|not owned by|SendAsDenied|send as denied|sender .{0,40}(rejected|denied|not allowed)|MAIL FROM command failed/i', $text ) ) {
			return __( 'The server refused the From address. Most providers only send from addresses or domains you have verified with them: set "From email" to such an address (usually the SMTP username or an address on your own domain) and turn on "Force From email".', 'tamago-smtp' );
		}
		if ( preg_match( '/could not authenticate|authentication (failed|unsuccessful)|SERVER -> CLIENT: 53[45]|\b535[ -]5\.7|Username and Password not accepted|invalid credentials|SmtpClientAuthentication is disabled/i', $text ) ) {
			return __( 'The server refused the username or password. Check both (the username is often the full email address). Gmail and Zoho need an app password when two-step sign-in is on; Microsoft 365 needs "Authenticated SMTP" switched on for the mailbox; SendGrid uses the username "apikey".', 'tamago-smtp' );
		}
		if ( preg_match( '/certificate|ssl3_get|SSL operation failed|routines:|self[- ]signed|verify failed|crypto enabling|STARTTLS command failed|Unable to set up TLS/i', $text ) ) {
			return __( 'The secure connection could not be set up (certificate or TLS problem). Check that the host name matches the server\'s certificate (use the provider\'s host name, not an IP address), and try TLS on port 587 or SSL on port 465. Very old PHP/OpenSSL versions on the web server can also cause this.', 'tamago-smtp' );
		}
		if ( preg_match( '/could not connect|connection refused|actively refused|timed out|timeout|failed to connect|getaddrinfo|no such host|name or service not known|network is unreachable|unable to connect|Connection failed/i', $text ) ) {
			return __( 'The web server could not reach the mail server. Check the host and port. Many hosting companies block outgoing ports 25, 465 or 587: try 587 with TLS or 465 with SSL, or ask the host to open the port.', 'tamago-smtp' );
		}
		if ( preg_match( '/following recipients failed|SMTP Error: The following recipients|Recipient address rejected|relay (access )?denied|relaying denied/i', $text ) ) {
			return __( 'The server refused a recipient. Check the address; if the server says relaying is denied, it does not accept mail from you for other domains, which usually means authentication is off or the wrong server is used.', 'tamago-smtp' );
		}
		if ( preg_match( '/Could not instantiate mail function|mail\(\) .*failed/i', $text ) ) {
			return __( 'This server cannot send email with PHP\'s mail() function. Switch on SMTP and enter your mail server\'s details.', 'tamago-smtp' );
		}
		if ( preg_match( '/Invalid address/i', $text ) ) {
			return __( 'One of the email addresses is not valid. Check the recipient and the From address.', 'tamago-smtp' );
		}
		return '';
	}
}
