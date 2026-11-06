<?php
/**
 * SMTP sending (Event Plus → Settings → Email) for every email the site sends.
 *
 * - The password is stored encrypted (AES-256 with the site's secret keys) and never printed back
 *   into the settings page; leaving the field empty keeps the saved one.
 * - wp-config.php can override: EPC_SMTP_HOST, EPC_SMTP_PORT, EPC_SMTP_SECURE, EPC_SMTP_USER, EPC_SMTP_PASSWORD.
 * - "Send test email" reports the mail server's error message when sending fails.
 */

defined( 'ABSPATH' ) || exit;

class EPC_SMTP {

	const PASS_FIELD = 'field_epc_smtp_pass';

	public static function init(): void {
		add_action( 'phpmailer_init', array( __CLASS__, 'configure' ) );
		add_filter( 'wp_mail_from', array( __CLASS__, 'from_email' ) );
		add_filter( 'wp_mail_from_name', array( __CLASS__, 'from_name' ) );
		add_filter( 'acf/update_value/key=field_epc_smtp_host', array( __CLASS__, 'watch_server' ), 10, 3 );
		add_filter( 'acf/update_value/key=field_epc_smtp_user', array( __CLASS__, 'watch_server' ), 10, 3 );
		add_filter( 'acf/update_value/key=' . self::PASS_FIELD, array( __CLASS__, 'save_password' ), 10, 3 );
		add_filter( 'acf/load_value/key=' . self::PASS_FIELD, '__return_empty_string', 99 );
		add_action( 'wp_ajax_epc_smtp_test', array( __CLASS__, 'test' ) );
		add_action( 'admin_footer', array( __CLASS__, 'test_ui' ) );
	}

	/** Setting, unless wp-config.php defines it. */
	private static function opt( string $name, $default = '' ) {
		$const = 'EPC_SMTP_' . strtoupper( $name );
		if ( defined( $const ) ) {
			return constant( $const );
		}
		return epc_setting( 'epc_smtp_' . $name, $default );
	}

	public static function enabled(): bool {
		return defined( 'EPC_SMTP_HOST' ) || ( epc_setting( 'epc_smtp_enable', false ) && '' !== trim( (string) epc_setting( 'epc_smtp_host', '' ) ) );
	}

	/* ------------------------------------------------------------------ */
	/* Password encryption                                                  */
	/* ------------------------------------------------------------------ */

	private static function key(): string {
		return hash( 'sha256', ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : '' ) . 'epc-smtp', true );
	}

	public static function encrypt( string $plain ): string {
		if ( '' === $plain || ! function_exists( 'openssl_encrypt' ) ) {
			return $plain;
		}
		$iv = random_bytes( 16 );
		return 'enc:' . base64_encode( $iv . openssl_encrypt( $plain, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv ) );
	}

	public static function decrypt( string $stored ): string {
		if ( ! str_starts_with( $stored, 'enc:' ) || ! function_exists( 'openssl_decrypt' ) ) {
			return $stored;
		}
		$raw = base64_decode( substr( $stored, 4 ), true );
		if ( false === $raw || strlen( $raw ) < 17 ) {
			return '';
		}
		return (string) openssl_decrypt( substr( $raw, 16 ), 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) );
	}

	/** Set when the host or username changes in this save (both are saved before the password). */
	private static $server_changed = false;

	public static function watch_server( $value, $post_id, $field ) {
		if ( trim( (string) $value ) !== trim( (string) get_option( 'options_' . $field['name'], '' ) ) ) {
			self::$server_changed = true;
		}
		return $value;
	}

	/**
	 * Empty input keeps the saved password; anything else is stored encrypted. When the host or
	 * username changes, the old password is dropped unless a new one is typed, so the saved password
	 * can never be sent to a different server.
	 */
	public static function save_password( $value, $post_id, $field ) {
		if ( '' === (string) $value && self::$server_changed ) {
			return '';
		}
		if ( '' === (string) $value ) {
			return get_option( 'options_epc_smtp_pass', '' );
		}
		return self::encrypt( (string) $value );
	}

	private static function password(): string {
		if ( defined( 'EPC_SMTP_PASSWORD' ) ) {
			return (string) EPC_SMTP_PASSWORD;
		}
		return self::decrypt( (string) get_option( 'options_epc_smtp_pass', '' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Sending                                                             */
	/* ------------------------------------------------------------------ */

	public static function configure( $mailer ): void {
		if ( ! self::enabled() ) {
			return;
		}
		$secure = (string) self::opt( 'secure', 'tls' );
		$port   = (int) self::opt( 'port', 0 ) ?: ( 'ssl' === $secure ? 465 : ( 'none' === $secure ? 25 : 587 ) );
		$mailer->isSMTP();
		$mailer->Host       = trim( (string) self::opt( 'host' ) );
		$mailer->Port       = $port;
		$mailer->SMTPSecure = in_array( $secure, array( 'tls', 'ssl' ), true ) ? $secure : '';
		$mailer->SMTPAutoTLS = 'none' !== $secure;
		$mailer->Timeout    = 20;
		$auth               = defined( 'EPC_SMTP_USER' ) || epc_setting( 'epc_smtp_auth', true );
		$mailer->SMTPAuth   = (bool) $auth;
		if ( $auth ) {
			$mailer->Username = (string) self::opt( 'user' );
			$mailer->Password = self::password();
		}
	}

	/** Site-wide From address and name (Settings → Email), so WordPress's own emails match. */
	public static function from_email( string $email ): string {
		$from = (string) epc_setting( 'epc_mail_from_email', '' );
		return is_email( $from ) ? $from : $email;
	}

	public static function from_name( string $name ): string {
		$from = (string) epc_setting( 'epc_mail_from_name', '' );
		return '' !== $from ? $from : $name;
	}

	/* ------------------------------------------------------------------ */
	/* Test email                                                          */
	/* ------------------------------------------------------------------ */

	public static function test(): void {
		check_ajax_referer( 'epc_smtp_test' );
		if ( ! current_user_can( 'epc_manage_settings' ) ) {
			wp_send_json_error( array( 'message' => 'Not allowed.' ), 403 );
		}
		$to = sanitize_email( wp_unslash( $_POST['to'] ?? '' ) );
		if ( ! is_email( $to ) ) {
			wp_send_json_error( array( 'message' => 'Please enter a valid email address.' ) );
		}
		$error = '';
		$catch = function ( WP_Error $e ) use ( &$error ) {
			$error = $e->get_error_message();
		};
		add_action( 'wp_mail_failed', $catch );
		$via  = self::enabled() ? 'SMTP (' . self::opt( 'host' ) . ')' : 'the server\'s PHP mail (SMTP is off)';
		$body = '<p>This is a test email from <strong>' . esc_html( get_bloginfo( 'name' ) ) . '</strong>.</p><p>It was sent through ' . esc_html( $via ) . ' on ' . esc_html( wp_date( 'j M Y, g:ia' ) ) . '. If you can read this, email sending works.</p>';
		$ok   = EPC_Mailer::send( $to, 'Test email from ' . get_bloginfo( 'name' ), $body, 'admin' );
		remove_action( 'wp_mail_failed', $catch );
		if ( $ok ) {
			wp_send_json_success( array( 'message' => 'Sent to ' . $to . ' through ' . $via . '. Check the inbox (and spam folder).' ) );
		}
		$hint = str_contains( $error, '(From)' ) ? ' Fill in "From email" above (an address on your domain) and save.' : ( self::enabled() ? '' : ' Switch on SMTP, fill in the server details and save.' );
		wp_send_json_error( array( 'message' => 'Sending failed' . ( $error ? ': ' . $error : '.' ) . $hint ) );
	}

	/** Small form inside the "Send a test email" field on the settings page. */
	public static function test_ui(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, 'epc-settings' ) || ! current_user_can( 'epc_manage_settings' ) ) {
			return;
		}
		$me = wp_get_current_user()->user_email;
		?>
		<script>
		(function () {
			var box = document.getElementById('epc-smtp-test');
			if (!box) return;
			box.innerHTML = '<div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">' +
				'<input type="email" id="epc-smtp-to" class="regular-text" placeholder="name@example.com" value="<?php echo esc_js( $me ); ?>">' +
				'<button type="button" class="button" id="epc-smtp-send">Send test email</button></div>' +
				'<p id="epc-smtp-result" style="margin:8px 0 0"></p>';
			var btn = document.getElementById('epc-smtp-send'), out = document.getElementById('epc-smtp-result');
			btn.addEventListener('click', function () {
				btn.disabled = true; out.style.color = ''; out.textContent = 'Sending…';
				var data = new FormData();
				data.append('action', 'epc_smtp_test');
				data.append('_ajax_nonce', '<?php echo esc_js( wp_create_nonce( 'epc_smtp_test' ) ); ?>');
				data.append('to', document.getElementById('epc-smtp-to').value);
				fetch(ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function (r) { out.textContent = (r.data && r.data.message) || 'Unknown response.'; out.style.color = r.success ? '#146c2e' : '#b32d2e'; })
					.catch(function () { out.textContent = 'Request failed. Please try again.'; out.style.color = '#b32d2e'; })
					.finally(function () { btn.disabled = false; });
			});
		})();
		</script>
		<?php
	}
}
