<?php
/**
 * SMTP conversation transcripts: collects PHPMailer debug output and hides credentials.
 *
 * Masking removes the base64 lines that follow AUTH LOGIN / AUTH PLAIN / CRAM-MD5 / XOAUTH2 (and the
 * inline argument of "AUTH PLAIN <base64>"), then replaces any known secret (plain or base64) with ********.
 * The message content sent after DATA is collapsed into one summary line: it repeats the stored body
 * and must not leak it when "Store bodies" is off.
 *
 * Used by: TMG_SMTP_Logger, TMG_SMTP_Tester.
 *
 * @package TamagoSMTP
 */

defined( 'ABSPATH' ) || exit;

class TMG_SMTP_Transcript {

	const MAX_BYTES = 262144;

	/** @var string[] */
	private $lines = array();

	private $in_data    = false;
	private $data_lines = 0;
	private $data_bytes = 0;
	private $bytes      = 0;
	private $cut        = false;

	/** Debugoutput callback: PHPMailer passes one chunk (one or more lines) at a time. */
	public function add( $chunk ): void {
		foreach ( preg_split( "/\r\n|\n|\r/", rtrim( (string) $chunk, "\r\n" ) ) as $line ) {
			if ( $this->in_data && 0 === strpos( $line, 'CLIENT -> SERVER: ' ) ) {
				$payload = substr( $line, 18 );
				if ( '.' === rtrim( $payload ) ) {
					$this->in_data = false;
					/* translators: 1: number of lines, 2: size in bytes */
					$this->push( sprintf( 'CLIENT -> SERVER: [message content not shown: %1$d lines, %2$d bytes]', $this->data_lines, $this->data_bytes ) );
					$this->push( $line );
				} else {
					++$this->data_lines;
					$this->data_bytes += strlen( $payload ) + 2;
				}
				continue;
			}
			$this->push( $line );
			if ( preg_match( '/^SERVER -> CLIENT: 354/', $line ) ) {
				$this->in_data    = true;
				$this->data_lines = 0;
				$this->data_bytes = 0;
			}
		}
	}

	private function push( string $line ): void {
		if ( $this->cut ) {
			return;
		}
		$this->bytes += strlen( $line ) + 1;
		if ( $this->bytes > self::MAX_BYTES ) {
			$this->cut     = true;
			$this->lines[] = '[transcript cut: too long]';
			return;
		}
		$this->lines[] = $line;
	}

	public function text(): string {
		return implode( "\n", $this->lines );
	}

	/**
	 * Hides credentials in a transcript (or any text).
	 *
	 * @param string   $text    Transcript.
	 * @param string[] $secrets Passwords (and similar) to hide wherever they appear.
	 */
	public static function mask( string $text, array $secrets = array() ): string {
		if ( '' === $text ) {
			return '';
		}
		$lines     = preg_split( "/\r\n|\n|\r/", $text );
		$mask_next = 0;
		foreach ( $lines as $i => $line ) {
			if ( preg_match( '/^(.*?SERVER -> CLIENT:\s*)(\d{3})/', $line, $m ) ) {
				// Anything but "334 continue" ends an authentication exchange.
				if ( '334' !== $m[2] ) {
					$mask_next = 0;
				}
				continue;
			}
			if ( ! preg_match( '/^(.*?CLIENT -> SERVER:\s?)(.*)$/', $line, $m ) ) {
				continue;
			}
			$prefix  = $m[1];
			$payload = trim( $m[2] );
			if ( $mask_next > 0 ) {
				$lines[ $i ] = $prefix . '[hidden]';
				--$mask_next;
				continue;
			}
			if ( preg_match( '/^AUTH\s+(LOGIN|PLAIN|CRAM-MD5|XOAUTH2|OAUTHBEARER|NTLM)\b\s*(.*)$/i', $payload, $a ) ) {
				$mech = strtoupper( $a[1] );
				$arg  = trim( $a[2] );
				if ( '' !== $arg ) {
					$lines[ $i ] = $prefix . 'AUTH ' . $mech . ' [hidden]';
				}
				if ( 'LOGIN' === $mech ) {
					$mask_next = '' === $arg ? 2 : 1;
				} elseif ( 'NTLM' === $mech ) {
					$mask_next = 2;
				} elseif ( '' === $arg ) {
					$mask_next = 1;
				}
			}
		}
		$text = implode( "\n", $lines );
		return self::mask_secrets( $text, $secrets );
	}

	/** Replaces each secret, and its base64 form, with ********. */
	public static function mask_secrets( string $text, array $secrets ): string {
		$search = array();
		foreach ( $secrets as $secret ) {
			$secret = (string) $secret;
			if ( strlen( $secret ) < 3 ) {
				continue;
			}
			$search[] = $secret;
			$search[] = base64_encode( $secret ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
		if ( ! $search ) {
			return $text;
		}
		// Longest first so a secret that contains another is replaced whole.
		usort(
			$search,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		return str_replace( $search, '********', $text );
	}
}
