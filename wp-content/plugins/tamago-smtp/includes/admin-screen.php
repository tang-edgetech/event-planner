<?php
/**
 * The plugin screen: menu entry, tabs, and the Settings, Test and Tools tabs (Logs: logs-screen.php).
 *
 * Handlers (admin-post.php): tmg_smtp_save_settings, tmg_smtp_export_settings, tmg_smtp_import_settings.
 * Each checks its nonce and the tmg_smtp_manage capability.
 *
 * Screens: Settings → SMTP (parent filterable with tamago_menu_parent( 'options-general.php', 'smtp' )).
 *
 * @package TamagoSMTP
 */

defined( 'ABSPATH' ) || exit;

class TMG_SMTP_Admin {

	const SLUG = 'tamago-smtp';

	/** @var string Page hook suffix. */
	private static $hook = '';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_post_tmg_smtp_save_settings', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_tmg_smtp_export_settings', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_tmg_smtp_import_settings', array( __CLASS__, 'handle_import' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( TMG_SMTP_FILE ), array( __CLASS__, 'action_links' ) );
		add_filter( 'removable_query_args', array( __CLASS__, 'removable_args' ) );
	}

	public static function page_url( string $tab = '', array $args = array() ): string {
		return tmg_smtp_screen_url( $tab, $args );
	}

	public static function add_menu(): void {
		if ( ! TMG_SMTP_Permissions::can_manage() && ! TMG_SMTP_Permissions::can_view_logs() ) {
			return;
		}
		$parent     = (string) apply_filters( 'tamago_menu_parent', 'options-general.php', 'smtp' );
		self::$hook = (string) add_submenu_page(
			$parent,
			__( 'SMTP', 'tamago-smtp' ),
			__( 'SMTP', 'tamago-smtp' ),
			TMG_SMTP_Permissions::menu_cap(),
			self::SLUG,
			array( __CLASS__, 'render' )
		);
		if ( self::$hook ) {
			add_action( 'load-' . self::$hook, array( __CLASS__, 'load' ) );
		}
	}

	public static function action_links( $links ): array {
		$links = (array) $links;
		if ( TMG_SMTP_Permissions::can_manage() ) {
			array_unshift( $links, '<a href="' . esc_url( self::page_url( 'settings' ) ) . '">' . esc_html__( 'Settings', 'tamago-smtp' ) . '</a>' );
		}
		return $links;
	}

	public static function removable_args( $args ): array {
		return array_merge( (array) $args, array( 'tmg_smtp_notice', 'tmg_smtp_count' ) );
	}

	/** Tabs the current user may open. */
	public static function tabs(): array {
		$tabs = array();
		if ( TMG_SMTP_Permissions::can_manage() ) {
			$tabs['settings'] = __( 'Settings', 'tamago-smtp' );
			$tabs['test']     = __( 'Test', 'tamago-smtp' );
		}
		if ( TMG_SMTP_Permissions::can_view_logs() ) {
			$tabs['logs'] = __( 'Logs', 'tamago-smtp' );
		}
		if ( TMG_SMTP_Permissions::can_manage() ) {
			$tabs['tools'] = __( 'Tools', 'tamago-smtp' );
		}
		return $tabs;
	}

	public static function current_tab(): string {
		$tabs = self::tabs();
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $tabs[ $tab ] ) ) {
			return $tab;
		}
		return (string) array_key_first( $tabs );
	}

	public static function load(): void {
		if ( 'logs' === self::current_tab() ) {
			TMG_SMTP_Logs_Screen::load();
		}
	}

	public static function enqueue( $hook ): void {
		if ( $hook !== self::$hook ) {
			return;
		}
		wp_enqueue_style( 'tmg-smtp-admin', TMG_SMTP_URL . 'assets/css/admin.css', array(), TMG_SMTP_VERSION );
		wp_enqueue_script( 'tmg-smtp-admin', TMG_SMTP_URL . 'assets/js/admin.js', array(), TMG_SMTP_VERSION, true );
		wp_localize_script(
			'tmg-smtp-admin',
			'tmgSmtp',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'tmg_smtp_test' ),
				'providers' => TMG_SMTP_Providers::all(),
				'i18n'      => array(
					'sending'       => __( 'Sending…', 'tamago-smtp' ),
					'checking'      => __( 'Checking…', 'tamago-smtp' ),
					'failed'        => __( 'The request failed. Please try again.', 'tamago-smtp' ),
					'transcript'    => __( 'SMTP conversation', 'tamago-smtp' ),
					'viewLog'       => __( 'View log entry', 'tamago-smtp' ),
					'hint'          => __( 'What to try:', 'tamago-smtp' ),
					'confirmAll'    => __( 'Delete every entry in the email log? This cannot be undone.', 'tamago-smtp' ),
					'confirmDelete' => __( 'Delete the selected log entries? This cannot be undone.', 'tamago-smtp' ),
					'confirmOne'    => __( 'Delete this log entry? This cannot be undone.', 'tamago-smtp' ),
				),
			)
		);
	}

	/* --------------------------------------------------------------------- */
	/* Rendering                                                              */
	/* --------------------------------------------------------------------- */

	public static function render(): void {
		$tabs = self::tabs();
		if ( ! $tabs ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'tamago-smtp' ), 403 );
		}
		$current = self::current_tab();
		echo '<div class="wrap tmg-smtp">';
		echo '<h1>' . esc_html__( 'SMTP', 'tamago-smtp' ) . '</h1>';
		self::render_notices();
		echo '<nav class="nav-tab-wrapper wp-clearfix" aria-label="' . esc_attr__( 'Secondary menu', 'tamago-smtp' ) . '">';
		foreach ( $tabs as $id => $label ) {
			printf(
				'<a href="%s" class="nav-tab%s"%s>%s</a>',
				esc_url( self::page_url( $id ) ),
				$id === $current ? ' nav-tab-active' : '',
				$id === $current ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';
		switch ( $current ) {
			case 'settings':
				self::render_settings();
				break;
			case 'test':
				self::render_test();
				break;
			case 'logs':
				TMG_SMTP_Logs_Screen::render();
				break;
			case 'tools':
				self::render_tools();
				break;
		}
		echo '</div>';
	}

	private static function render_notices(): void {
		$code  = isset( $_GET['tmg_smtp_notice'] ) ? sanitize_key( wp_unslash( $_GET['tmg_smtp_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$count = isset( $_GET['tmg_smtp_count'] ) ? absint( $_GET['tmg_smtp_count'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		/* translators: %d: number of entries */
		$deleted  = sprintf( _n( '%d log entry deleted.', '%d log entries deleted.', $count, 'tamago-smtp' ), $count );
		$messages = array(
			'saved'            => array( 'success', __( 'Settings saved.', 'tamago-smtp' ) ),
			'password_dropped' => array( 'warning', __( 'Settings saved. The host or username changed, so the saved password was removed: type the password for the new server.', 'tamago-smtp' ) ),
			'imported'         => array( 'success', __( 'Settings imported. Passwords are never part of a settings file: check the password on the Settings tab.', 'tamago-smtp' ) ),
			'import_file'      => array( 'error', __( 'Import failed: choose a .json settings file of at most 100 KB.', 'tamago-smtp' ) ),
			'import_json'      => array( 'error', __( 'Import failed: the file is not valid JSON.', 'tamago-smtp' ) ),
			'import_shape'     => array( 'error', __( 'Import failed: this is not a Tamago SMTP settings file.', 'tamago-smtp' ) ),
			'deleted'          => array( 'success', $deleted ),
			'resent'           => array( 'success', __( 'The email was sent again. This is its new log entry.', 'tamago-smtp' ) ),
			'resend_failed'    => array( 'error', __( 'Resending failed. This is the new log entry with the error.', 'tamago-smtp' ) ),
			'resend_error'     => array( 'error', __( 'This email cannot be resent because its content was not stored in full.', 'tamago-smtp' ) ),
		);
		if ( isset( $messages[ $code ] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $messages[ $code ][0] ), esc_html( $messages[ $code ][1] ) );
		}
		$others = self::other_smtp_plugins();
		if ( $others ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				/* translators: %s: plugin names */
				esc_html( sprintf( __( 'Another SMTP tool is also active: %s. Two tools changing the mailer can override each other; keep only one.', 'tamago-smtp' ), implode( ', ', $others ) ) )
			);
		}
	}

	/** Names of other active tools that configure the mailer. */
	public static function other_smtp_plugins(): array {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$known = array(
			'wp-mail-smtp/wp_mail_smtp.php'                   => 'WP Mail SMTP',
			'wp-mail-smtp-pro/wp_mail_smtp.php'               => 'WP Mail SMTP Pro',
			'post-smtp/postman-smtp.php'                      => 'Post SMTP',
			'easy-wp-smtp/easy-wp-smtp.php'                   => 'Easy WP SMTP',
			'fluent-smtp/fluent-smtp.php'                     => 'FluentSMTP',
			'smtp-mailer/main.php'                            => 'SMTP Mailer',
			'wp-smtp/wp-smtp.php'                             => 'WP SMTP',
			'gmail-smtp/main.php'                             => 'Gmail SMTP',
			'mailgun/mailgun.php'                             => 'Mailgun',
			'wp-ses/wp-ses.php'                               => 'WP Offload SES',
			'sendgrid-email-delivery-simplified/wpsendgrid.php' => 'SendGrid',
		);
		$active = array();
		foreach ( $known as $file => $name ) {
			if ( is_plugin_active( $file ) ) {
				$active[] = $name;
			}
		}
		if ( class_exists( 'EPC_SMTP' ) && method_exists( 'EPC_SMTP', 'enabled' ) && EPC_SMTP::enabled() ) {
			$active[] = __( 'the theme\'s own SMTP setting (Event Plus → Settings → Email)', 'tamago-smtp' );
		}
		return $active;
	}

	/** "Set in wp-config.php" note for a locked key, or ''. */
	private static function locked_note( string $key ): string {
		$const = TMG_SMTP_Settings::constant_for( $key );
		if ( '' === $const ) {
			return '';
		}
		/* translators: %s: constant name */
		return '<p class="description tmg-smtp-locked"><span class="dashicons dashicons-lock" aria-hidden="true"></span> ' . sprintf( esc_html__( 'Set in wp-config.php (%s). Change it there.', 'tamago-smtp' ), '<code>' . esc_html( $const ) . '</code>' ) . '</p>';
	}

	private static function field_name( string $key ): string {
		return 'tmg_smtp[' . $key . ']';
	}

	private static function row_start( string $key, string $label, string $class = '' ): void {
		printf( '<tr class="tmg-smtp-row-%1$s %2$s"><th scope="row"><label for="tmg-smtp-%1$s">%3$s</label></th><td>', esc_attr( str_replace( '_', '-', $key ) ), esc_attr( $class ), esc_html( $label ) );
	}

	private static function row_end( string $key, string $description = '' ): void {
		if ( '' !== $description ) {
			echo '<p class="description">' . wp_kses_post( $description ) . '</p>';
		}
		echo self::locked_note( $key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		echo '</td></tr>';
	}

	private static function text_row( string $key, string $label, string $type = 'text', string $description = '', array $attrs = array() ): void {
		self::row_start( $key, $label );
		$extra = '';
		foreach ( $attrs as $name => $value ) {
			$extra .= ' ' . esc_attr( $name ) . '="' . esc_attr( (string) $value ) . '"';
		}
		printf(
			'<input type="%1$s" id="tmg-smtp-%2$s" name="%3$s" value="%4$s" class="%5$s"%6$s%7$s>',
			esc_attr( $type ),
			esc_attr( str_replace( '_', '-', $key ) ),
			esc_attr( self::field_name( $key ) ),
			esc_attr( (string) TMG_SMTP_Settings::get( $key ) ),
			'number' === $type ? 'small-text' : 'regular-text',
			TMG_SMTP_Settings::is_locked( $key ) ? ' disabled' : '',
			$extra // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		);
		self::row_end( $key, $description );
	}

	private static function checkbox( string $key, string $label ): string {
		return sprintf(
			'<label for="tmg-smtp-%1$s"><input type="checkbox" id="tmg-smtp-%1$s" name="%2$s" value="1"%3$s%4$s> %5$s</label>',
			esc_attr( str_replace( '_', '-', $key ) ),
			esc_attr( self::field_name( $key ) ),
			checked( (bool) TMG_SMTP_Settings::get( $key ), true, false ),
			TMG_SMTP_Settings::is_locked( $key ) ? ' disabled' : '',
			esc_html( $label )
		);
	}

	private static function checkbox_row( string $key, string $title, string $label, string $description = '' ): void {
		echo '<tr class="tmg-smtp-row-' . esc_attr( str_replace( '_', '-', $key ) ) . '"><th scope="row">' . esc_html( $title ) . '</th><td>';
		echo self::checkbox( $key, $label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in checkbox().
		self::row_end( $key, $description );
	}

	private static function select_row( string $key, string $label, array $options, string $description = '' ): void {
		self::row_start( $key, $label );
		$current = (string) TMG_SMTP_Settings::get( $key );
		printf( '<select id="tmg-smtp-%1$s" name="%2$s"%3$s>', esc_attr( str_replace( '_', '-', $key ) ), esc_attr( self::field_name( $key ) ), TMG_SMTP_Settings::is_locked( $key ) ? ' disabled' : '' );
		foreach ( $options as $value => $text ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( (string) $value ), selected( $current, (string) $value, false ), esc_html( $text ) );
		}
		echo '</select>';
		self::row_end( $key, $description );
	}

	private static function render_settings(): void {
		$providers = array();
		foreach ( TMG_SMTP_Providers::all() as $id => $preset ) {
			$providers[ $id ] = $preset['label'];
		}
		$provider = TMG_SMTP_Providers::get( (string) TMG_SMTP_Settings::get( 'provider' ) );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tmg-smtp-settings" autocomplete="off">
			<input type="hidden" name="action" value="tmg_smtp_save_settings">
			<?php wp_nonce_field( 'tmg_smtp_save_settings' ); ?>

			<h2><?php esc_html_e( 'Mail server', 'tamago-smtp' ); ?></h2>
			<table class="form-table" role="presentation">
				<?php
				self::checkbox_row( 'enabled', __( 'SMTP', 'tamago-smtp' ), __( 'Send all email through this SMTP server', 'tamago-smtp' ), __( 'When off, WordPress uses the web server\'s PHP mail(); the sender rules and the log below still apply.', 'tamago-smtp' ) );
				self::select_row( 'provider', __( 'Provider', 'tamago-smtp' ), $providers, '<span id="tmg-smtp-provider-help">' . esc_html( $provider ? $provider['help'] : '' ) . '</span>' );
				self::text_row( 'host', __( 'Host', 'tamago-smtp' ), 'text', '', array( 'placeholder' => 'smtp.example.com', 'spellcheck' => 'false' ) );
				self::text_row( 'port', __( 'Port', 'tamago-smtp' ), 'number', __( '587 for TLS, 465 for SSL, 25 or 2525 without encryption.', 'tamago-smtp' ), array( 'min' => 1, 'max' => 65535 ) );
				self::select_row(
					'encryption',
					__( 'Encryption', 'tamago-smtp' ),
					array(
						'tls'  => __( 'TLS (STARTTLS)', 'tamago-smtp' ),
						'ssl'  => __( 'SSL', 'tamago-smtp' ),
						'none' => __( 'None', 'tamago-smtp' ),
					)
				);
				self::checkbox_row( 'auto_tls', __( 'Auto-TLS', 'tamago-smtp' ), __( 'Use TLS anyway when the server offers it', 'tamago-smtp' ), __( 'Only used when Encryption is "None". Turn off for servers with a broken certificate.', 'tamago-smtp' ) );
				self::checkbox_row( 'auth', __( 'Authentication', 'tamago-smtp' ), __( 'Log in with a username and password', 'tamago-smtp' ) );
				self::text_row( 'username', __( 'Username', 'tamago-smtp' ), 'text', '', array( 'autocomplete' => 'off', 'spellcheck' => 'false' ) );

				self::row_start( 'password', __( 'Password', 'tamago-smtp' ) );
				if ( TMG_SMTP_Settings::is_locked( 'password' ) ) {
					echo '<input type="password" id="tmg-smtp-password" class="regular-text" value="" placeholder="••••••••" disabled>';
				} else {
					$saved = TMG_SMTP_Settings::has_password();
					printf(
						'<input type="password" id="tmg-smtp-password" name="%1$s" class="regular-text" value="" autocomplete="new-password" placeholder="%2$s">',
						esc_attr( self::field_name( 'password' ) ),
						esc_attr( $saved ? __( 'Saved. Leave empty to keep it.', 'tamago-smtp' ) : '' )
					);
					if ( $saved ) {
						echo '<p><label><input type="checkbox" name="' . esc_attr( self::field_name( 'password_clear' ) ) . '" value="1"> ' . esc_html__( 'Remove the saved password', 'tamago-smtp' ) . '</label></p>';
					}
				}
				self::row_end( 'password', esc_html__( 'Stored encrypted and never shown again. If you change the host or username, type the password again: the old one is removed so it is never sent to a different server.', 'tamago-smtp' ) );

				self::text_row( 'timeout', __( 'Timeout', 'tamago-smtp' ), 'number', __( 'Seconds to wait for the server.', 'tamago-smtp' ), array( 'min' => 1, 'max' => 300 ) );
				?>
			</table>

			<h2><?php esc_html_e( 'Sender', 'tamago-smtp' ); ?></h2>
			<table class="form-table" role="presentation">
				<?php
				/* translators: %s: default WordPress sender address */
				$default_note = sprintf( __( 'Replaces WordPress\'s default sender (%s). Use an address your mail server is allowed to send from.', 'tamago-smtp' ), '<code>' . esc_html( TMG_SMTP_Mailer::default_from_email() ) . '</code>' );
				self::text_row( 'from_email', __( 'From email', 'tamago-smtp' ), 'email', $default_note );
				self::checkbox_row( 'force_from_email', '', __( 'Force for all emails (also emails where a plugin chose its own sender)', 'tamago-smtp' ) );
				self::text_row( 'from_name', __( 'From name', 'tamago-smtp' ), 'text', __( 'Replaces the default name "WordPress".', 'tamago-smtp' ) );
				self::checkbox_row( 'force_from_name', '', __( 'Force for all emails', 'tamago-smtp' ) );
				self::checkbox_row( 'return_path', __( 'Return-Path', 'tamago-smtp' ), __( 'Set the Return-Path (bounce address) to the From email', 'tamago-smtp' ) );
				self::text_row( 'reply_to', __( 'Default Reply-To', 'tamago-smtp' ), 'email', __( 'Used only when an email has no Reply-To of its own. Leave empty for none.', 'tamago-smtp' ) );
				self::text_row( 'bcc', __( 'Always BCC', 'tamago-smtp' ), 'text', __( 'Every email is also sent, hidden, to this address. Separate several with commas. Leave empty for none.', 'tamago-smtp' ) );
				?>
			</table>

			<h2><?php esc_html_e( 'Email log', 'tamago-smtp' ); ?></h2>
			<table class="form-table" role="presentation">
				<?php
				self::checkbox_row( 'log_enabled', __( 'Log', 'tamago-smtp' ), __( 'Keep a log of every email the site sends', 'tamago-smtp' ) );
				self::checkbox_row( 'log_bodies', __( 'Content', 'tamago-smtp' ), __( 'Store the email content', 'tamago-smtp' ), __( 'Needed to view and resend emails. Turn off when emails contain information you should not keep.', 'tamago-smtp' ) );
				self::checkbox_row( 'log_transcript_success', __( 'SMTP conversation', 'tamago-smtp' ), __( 'Keep the conversation for successful emails too', 'tamago-smtp' ), __( 'Always kept for failed emails. Passwords are hidden.', 'tamago-smtp' ) );
				self::text_row( 'log_retention_days', __( 'Keep entries for', 'tamago-smtp' ), 'number', __( 'Days. Older entries are deleted once a day. 0 keeps them forever.', 'tamago-smtp' ), array( 'min' => 0, 'max' => 3650 ) );
				self::text_row( 'log_max_body_kb', __( 'Largest stored content', 'tamago-smtp' ), 'number', __( 'KB per email. Longer content is cut (and can then not be resent).', 'tamago-smtp' ), array( 'min' => 1, 'max' => 10240 ) );
				?>
			</table>

			<h2><?php esc_html_e( 'Failure alert', 'tamago-smtp' ); ?></h2>
			<table class="form-table" role="presentation">
				<?php
				self::checkbox_row( 'alert_enabled', __( 'Alert', 'tamago-smtp' ), __( 'Email me when sending fails (at most once per hour)', 'tamago-smtp' ), __( 'The alert goes through the same mail server, so it cannot arrive when that server is down; the failure is still in the log.', 'tamago-smtp' ) );
				/* translators: %s: admin email */
				self::text_row( 'alert_email', __( 'Alert address', 'tamago-smtp' ), 'email', sprintf( __( 'Leave empty to use the site admin address (%s).', 'tamago-smtp' ), esc_html( (string) get_option( 'admin_email' ) ) ) );
				?>
			</table>

			<h2><?php esc_html_e( 'Uninstall', 'tamago-smtp' ); ?></h2>
			<table class="form-table" role="presentation">
				<?php self::checkbox_row( 'delete_data', __( 'Data', 'tamago-smtp' ), __( 'Delete all data when the plugin is deleted', 'tamago-smtp' ), __( 'Settings, the email log and the permissions are removed when you delete the plugin from the Plugins screen.', 'tamago-smtp' ) ); ?>
			</table>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	private static function render_test(): void {
		$me      = wp_get_current_user()->user_email;
		$enabled = TMG_SMTP_Settings::enabled();
		?>
		<div class="tmg-smtp-test">
			<?php if ( ! $enabled ) : ?>
				<div class="notice notice-info inline"><p><?php esc_html_e( 'SMTP is off: test emails go through the web server\'s PHP mail().', 'tamago-smtp' ); ?></p></div>
			<?php endif; ?>
			<p>
				<?php
				/* translators: %s: route, e.g. "SMTP smtp.example.com:587" */
				echo esc_html( sprintf( __( 'Email currently goes through %s. Tests use the saved settings.', 'tamago-smtp' ), TMG_SMTP_Tester::route() ) );
				?>
			</p>

			<h2><?php esc_html_e( 'Send a test email', 'tamago-smtp' ); ?></h2>
			<form id="tmg-smtp-test-form" class="tmg-smtp-inline-form">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="tmg-smtp-test-to"><?php esc_html_e( 'Send to', 'tamago-smtp' ); ?></label></th>
						<td><input type="email" id="tmg-smtp-test-to" class="regular-text" value="<?php echo esc_attr( $me ); ?>" required></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Format', 'tamago-smtp' ); ?></th>
						<td>
							<fieldset>
								<label><input type="radio" name="tmg_smtp_test_format" value="html" checked> <?php esc_html_e( 'HTML', 'tamago-smtp' ); ?></label>
								&nbsp; <label><input type="radio" name="tmg_smtp_test_format" value="text"> <?php esc_html_e( 'Plain text', 'tamago-smtp' ); ?></label>
							</fieldset>
						</td>
					</tr>
				</table>
				<p><button type="submit" class="button button-primary" id="tmg-smtp-test-send"><?php esc_html_e( 'Send test email', 'tamago-smtp' ); ?></button></p>
			</form>
			<div id="tmg-smtp-test-result" class="tmg-smtp-result" aria-live="polite"></div>

			<h2><?php esc_html_e( 'Check the connection', 'tamago-smtp' ); ?></h2>
			<p><?php esc_html_e( 'Connects to the server, starts encryption and logs in, without sending an email.', 'tamago-smtp' ); ?></p>
			<p><button type="button" class="button" id="tmg-smtp-check" <?php disabled( ! $enabled && '' === (string) TMG_SMTP_Settings::get( 'host' ) ); ?>><?php esc_html_e( 'Check connection', 'tamago-smtp' ); ?></button></p>
			<div id="tmg-smtp-check-result" class="tmg-smtp-result" aria-live="polite"></div>
			<noscript><p><?php esc_html_e( 'The test tools need JavaScript. You can also use: wp tamago-smtp test you@example.com', 'tamago-smtp' ); ?></p></noscript>
		</div>
		<?php
	}

	private static function render_tools(): void {
		$constants = array();
		foreach ( array_keys( TMG_SMTP_Settings::defaults() ) as $key ) {
			$const = TMG_SMTP_Settings::constant_for( $key );
			if ( '' !== $const ) {
				$constants[] = $const;
			}
		}
		$next = wp_next_scheduled( TMG_SMTP_Log_Store::CRON );
		$info = array(
			__( 'Plugin version', 'tamago-smtp' )       => TMG_SMTP_VERSION,
			__( 'WordPress', 'tamago-smtp' )            => get_bloginfo( 'version' ),
			__( 'PHP', 'tamago-smtp' )                  => PHP_VERSION,
			__( 'PHPMailer', 'tamago-smtp' )            => class_exists( 'PHPMailer\\PHPMailer\\PHPMailer' ) ? PHPMailer\PHPMailer\PHPMailer::VERSION : __( 'loaded on first email', 'tamago-smtp' ),
			__( 'Password encryption', 'tamago-smtp' )  => TMG_SMTP_Crypto::gcm_available() ? 'AES-256-GCM' : ( TMG_SMTP_Crypto::openssl_available() ? 'AES-256-CBC + HMAC' : __( 'NOT ENCRYPTED: the OpenSSL PHP extension is missing', 'tamago-smtp' ) ),
			__( 'Route', 'tamago-smtp' )                => TMG_SMTP_Tester::route(),
			__( 'wp-config.php overrides', 'tamago-smtp' ) => $constants ? implode( ', ', $constants ) : __( 'none', 'tamago-smtp' ),
			__( 'Log table', 'tamago-smtp' )            => TMG_SMTP_Log_Store::table() . ' (' . number_format_i18n( TMG_SMTP_Log_Store::count() ) . ' ' . __( 'entries', 'tamago-smtp' ) . ')',
			__( 'Next log clean-up', 'tamago-smtp' )    => $next ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next ) : __( 'not scheduled', 'tamago-smtp' ),
		);
		?>
		<h2><?php esc_html_e( 'Export settings', 'tamago-smtp' ); ?></h2>
		<p><?php esc_html_e( 'Download the settings as a JSON file, to copy them to another site. The password is never included.', 'tamago-smtp' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="tmg_smtp_export_settings">
			<?php wp_nonce_field( 'tmg_smtp_export_settings' ); ?>
			<?php submit_button( __( 'Export settings', 'tamago-smtp' ), 'secondary', 'submit', false ); ?>
		</form>

		<h2><?php esc_html_e( 'Import settings', 'tamago-smtp' ); ?></h2>
		<p><?php esc_html_e( 'Load a settings file exported from Tamago SMTP. Settings locked in wp-config.php stay as they are. If the host or username changes, the saved password is removed.', 'tamago-smtp' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<input type="hidden" name="action" value="tmg_smtp_import_settings">
			<?php wp_nonce_field( 'tmg_smtp_import_settings' ); ?>
			<input type="file" name="tmg_smtp_import" accept=".json,application/json" required>
			<?php submit_button( __( 'Import settings', 'tamago-smtp' ), 'secondary', 'submit', false ); ?>
		</form>

		<h2><?php esc_html_e( 'System information', 'tamago-smtp' ); ?></h2>
		<table class="widefat striped tmg-smtp-info">
			<tbody>
			<?php foreach ( $info as $label => $value ) : ?>
				<tr><th scope="row"><?php echo esc_html( $label ); ?></th><td><?php echo esc_html( (string) $value ); ?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description">
			<?php
			/* translators: %s: example constant */
			printf( esc_html__( 'Any setting can be locked in wp-config.php with a constant named after it, for example %s.', 'tamago-smtp' ), '<code>define( \'TMG_SMTP_PASSWORD\', \'…\' );</code>' );
			?>
		</p>
		<?php
	}

	/* --------------------------------------------------------------------- */
	/* Handlers                                                               */
	/* --------------------------------------------------------------------- */

	private static function redirect( string $tab, string $notice, array $extra = array() ): void {
		wp_safe_redirect( self::page_url( $tab, array_merge( array( 'tmg_smtp_notice' => $notice ), $extra ) ) );
		exit;
	}

	public static function handle_save(): void {
		check_admin_referer( 'tmg_smtp_save_settings' );
		TMG_SMTP_Permissions::require_cap( TMG_SMTP_Permissions::MANAGE );
		$input  = isset( $_POST['tmg_smtp'] ) && is_array( $_POST['tmg_smtp'] ) ? wp_unslash( $_POST['tmg_smtp'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per key in TMG_SMTP_Settings.
		$result = TMG_SMTP_Settings::save( $input );
		self::redirect( 'settings', $result['password_dropped'] ? 'password_dropped' : 'saved' );
	}

	public static function handle_export(): void {
		check_admin_referer( 'tmg_smtp_export_settings' );
		TMG_SMTP_Permissions::require_cap( TMG_SMTP_Permissions::MANAGE );
		$host = sanitize_file_name( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="tamago-smtp-settings-' . $host . '-' . gmdate( 'Y-m-d' ) . '.json"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo wp_json_encode( TMG_SMTP_Settings::export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	public static function handle_import(): void {
		check_admin_referer( 'tmg_smtp_import_settings' );
		TMG_SMTP_Permissions::require_cap( TMG_SMTP_Permissions::MANAGE );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- the upload array is checked field by field.
		$file = isset( $_FILES['tmg_smtp_import'] ) && is_array( $_FILES['tmg_smtp_import'] ) ? $_FILES['tmg_smtp_import'] : null;
		if ( ! $file || UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			self::redirect( 'tools', 'import_file' );
		}
		$tmp  = (string) $file['tmp_name'];
		$ext  = strtolower( pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) );
		$size = (int) filesize( $tmp );
		$raw  = ( 'json' === $ext && $size > 0 && $size <= TMG_SMTP_Settings::IMPORT_MAX ) ? (string) file_get_contents( $tmp ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		wp_delete_file( $tmp );
		if ( '' === $raw ) {
			self::redirect( 'tools', 'import_file' );
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			self::redirect( 'tools', 'import_json' );
		}
		$result = TMG_SMTP_Settings::import( $data );
		self::redirect( 'tools', is_wp_error( $result ) ? 'import_shape' : 'imported' );
	}
}
