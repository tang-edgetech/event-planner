<?php
/**
 * Logs tab: the list, the detail view of one email, and the log actions.
 *
 * Handlers (admin-post.php), each with a nonce and the tmg_smtp_view_logs capability:
 *   tmg_smtp_log_delete (one entry), tmg_smtp_log_delete_all, tmg_smtp_log_resend, tmg_smtp_log_export (CSV).
 * Bulk delete runs on the screen's load hook (nonce bulk-tmg_smtp_logs).
 *
 * The body is shown in a sandboxed iframe (srcdoc, no scripts, no same-origin) with a Content-Security-Policy
 * that blocks remote images until the viewer asks for them (tracking pixels).
 *
 * Screens: SMTP → Logs.
 *
 * @package TamagoSMTP
 */

defined( 'ABSPATH' ) || exit;

class TMG_SMTP_Logs_Screen {

	/** @var TMG_SMTP_Logs_Table|null */
	private static $table = null;

	public static function init(): void {
		add_action( 'admin_post_tmg_smtp_log_delete', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_tmg_smtp_log_delete_all', array( __CLASS__, 'handle_delete_all' ) );
		add_action( 'admin_post_tmg_smtp_log_resend', array( __CLASS__, 'handle_resend' ) );
		add_action( 'admin_post_tmg_smtp_log_export', array( __CLASS__, 'handle_export' ) );
		add_filter( 'set_screen_option_tmg_smtp_logs_per_page', array( __CLASS__, 'save_per_page' ), 10, 3 );
	}

	public static function save_per_page( $keep, $option, $value ) {
		$value = (int) $value;
		return ( $value >= 1 && $value <= 500 ) ? $value : $keep;
	}

	/* --------------------------------------------------------------------- */
	/* Filters                                                                */
	/* --------------------------------------------------------------------- */

	/** List filters from the query string (local dates converted to UTC for the query). */
	public static function filters( ?array $source = null ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$src    = null === $source ? wp_unslash( $_GET ) : $source;
		$get    = static function ( string $key ) use ( $src ): string {
			return isset( $src[ $key ] ) && is_scalar( $src[ $key ] ) ? (string) $src[ $key ] : '';
		};
		$status = sanitize_key( $get( 'status' ) );
		$from   = sanitize_text_field( $get( 'date_from' ) );
		$to     = sanitize_text_field( $get( 'date_to' ) );
		$from   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ? $from : '';
		$to     = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ? $to : '';
		return array(
			'status'          => in_array( $status, array( 'sent', 'failed' ), true ) ? $status : '',
			'source'          => sanitize_text_field( $get( 'source' ) ),
			'search'          => sanitize_text_field( $get( 's' ) ),
			'date_from_local' => $from,
			'date_to_local'   => $to,
			'date_from'       => '' !== $from ? self::local_to_utc( $from . ' 00:00:00' ) : '',
			'date_to'         => '' !== $to ? self::local_to_utc( $to . ' 23:59:59' ) : '',
			'orderby'         => '' !== $get( 'orderby' ) ? sanitize_key( $get( 'orderby' ) ) : 'created_at',
			'order'           => 'asc' === strtolower( $get( 'order' ) ) ? 'ASC' : 'DESC',
		);
	}

	private static function local_to_utc( string $local ): string {
		try {
			$date = new DateTimeImmutable( $local, wp_timezone() );
			return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		} catch ( Exception $e ) {
			return '';
		}
	}

	/** Filter arguments to carry over to links (export, redirects). */
	private static function filter_args( array $f ): array {
		return array_filter(
			array(
				'status'    => $f['status'],
				'source'    => $f['source'],
				's'         => $f['search'],
				'date_from' => $f['date_from_local'],
				'date_to'   => $f['date_to_local'],
			),
			'strlen'
		);
	}

	/* --------------------------------------------------------------------- */
	/* Labels                                                                 */
	/* --------------------------------------------------------------------- */

	public static function status_badge( string $status ): string {
		$label = 'sent' === $status ? __( 'Sent', 'tamago-smtp' ) : ( 'failed' === $status ? __( 'Failed', 'tamago-smtp' ) : $status );
		return '<span class="tmg-smtp-badge tmg-smtp-badge-' . esc_attr( $status ) . '">' . esc_html( $label ) . '</span>';
	}

	public static function source_label( string $source ): string {
		if ( 'core' === $source ) {
			return __( 'WordPress', 'tamago-smtp' );
		}
		if ( 'other' === $source || '' === $source ) {
			return __( 'Other', 'tamago-smtp' );
		}
		list( $type, $slug ) = array_pad( explode( ':', $source, 2 ), 2, '' );
		$types               = array(
			'plugin'    => __( 'Plugin', 'tamago-smtp' ),
			'mu-plugin' => __( 'Must-use plugin', 'tamago-smtp' ),
			'theme'     => __( 'Theme', 'tamago-smtp' ),
		);
		return ( $types[ $type ] ?? $type ) . ': ' . $slug;
	}

	public static function context_label( string $context ): string {
		$labels = array(
			'admin' => __( 'Admin', 'tamago-smtp' ),
			'front' => __( 'Front end', 'tamago-smtp' ),
			'ajax'  => __( 'AJAX', 'tamago-smtp' ),
			'rest'  => __( 'REST API', 'tamago-smtp' ),
			'cron'  => __( 'Cron', 'tamago-smtp' ),
			'cli'   => __( 'WP-CLI', 'tamago-smtp' ),
		);
		return $labels[ $context ] ?? $context;
	}

	public static function delete_url( int $id ): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=tmg_smtp_log_delete&id=' . $id ), 'tmg_smtp_log_delete_' . $id );
	}

	/* --------------------------------------------------------------------- */
	/* Screen                                                                 */
	/* --------------------------------------------------------------------- */

	/** Load hook of the Logs tab: screen options and bulk delete (before any output). */
	public static function load(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['log'] ) ) {
			return;
		}
		// WP_List_Table reads these straight from the request; drop non-scalar values (?s[]=…) so it never sees arrays.
		foreach ( array( 's', 'orderby', 'order', 'status', 'source', 'date_from', 'date_to', 'paged' ) as $key ) {
			if ( isset( $_GET[ $key ] ) && ! is_scalar( $_GET[ $key ] ) ) {
				unset( $_GET[ $key ], $_REQUEST[ $key ] );
			}
		}
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Entries per page', 'tamago-smtp' ),
				'default' => 20,
				'option'  => 'tmg_smtp_logs_per_page',
			)
		);
		require_once TMG_SMTP_DIR . 'includes/logs-table.php';
		$filters     = self::filters();
		self::$table = new TMG_SMTP_Logs_Table( $filters );
		if ( 'delete' === self::$table->current_action() ) {
			check_admin_referer( 'bulk-tmg_smtp_logs' );
			TMG_SMTP_Permissions::require_cap( TMG_SMTP_Permissions::VIEW_LOGS );
			$ids     = isset( $_GET['log_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_GET['log_ids'] ) ) : array();
			$deleted = TMG_SMTP_Log_Store::delete( $ids );
			wp_safe_redirect(
				TMG_SMTP_Admin::page_url(
					'logs',
					array_merge(
						self::filter_args( $filters ),
						array(
							'tmg_smtp_notice' => 'deleted',
							'tmg_smtp_count'  => $deleted,
						)
					)
				)
			);
			exit;
		}
	}

	public static function render(): void {
		TMG_SMTP_Permissions::require_cap( TMG_SMTP_Permissions::VIEW_LOGS );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id = isset( $_GET['log'] ) ? absint( $_GET['log'] ) : 0;
		if ( $id ) {
			self::render_detail( $id );
			return;
		}
		self::render_list();
	}

	private static function render_list(): void {
		if ( ! self::$table ) {
			require_once TMG_SMTP_DIR . 'includes/logs-table.php';
			self::$table = new TMG_SMTP_Logs_Table( self::filters() );
		}
		$filters = self::filters();
		self::$table->prepare_items();
		$export_url = wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'tmg_smtp_log_export' ), self::filter_args( $filters ) ), admin_url( 'admin-post.php' ) ), 'tmg_smtp_log_export' );
		?>
		<div class="tmg-smtp-logs-head">
			<p>
				<?php
				$retention = (int) TMG_SMTP_Settings::get( 'log_retention_days' );
				echo esc_html(
					$retention > 0
						/* translators: %d: days */
						? sprintf( _n( 'Entries are kept for %d day.', 'Entries are kept for %d days.', $retention, 'tamago-smtp' ), $retention )
						: __( 'Entries are kept until you delete them.', 'tamago-smtp' )
				);
				if ( ! TMG_SMTP_Settings::get( 'log_enabled' ) ) {
					echo ' <strong>' . esc_html__( 'Logging is switched off.', 'tamago-smtp' ) . '</strong>';
				}
				?>
			</p>
			<div class="tmg-smtp-logs-buttons">
				<a class="button" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export CSV', 'tamago-smtp' ); ?></a>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tmg-smtp-delete-all">
					<input type="hidden" name="action" value="tmg_smtp_log_delete_all">
					<?php wp_nonce_field( 'tmg_smtp_log_delete_all' ); ?>
					<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Delete all', 'tamago-smtp' ); ?></button>
				</form>
			</div>
		</div>
		<form method="get" class="tmg-smtp-logs-form">
			<input type="hidden" name="page" value="<?php echo esc_attr( TMG_SMTP_Admin::SLUG ); ?>">
			<input type="hidden" name="tab" value="logs">
			<?php
			self::$table->search_box( __( 'Search to / subject', 'tamago-smtp' ), 'tmg-smtp-search' );
			self::$table->display();
			?>
		</form>
		<?php
	}

	private static function render_detail( int $id ): void {
		$row  = TMG_SMTP_Log_Store::get( $id );
		$back = TMG_SMTP_Admin::page_url( 'logs' );
		echo '<p><a href="' . esc_url( $back ) . '">&larr; ' . esc_html__( 'Back to the log', 'tamago-smtp' ) . '</a></p>';
		if ( ! $row ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Log entry not found. It may have been deleted.', 'tamago-smtp' ) . '</p></div>';
			return;
		}
		$time        = strtotime( $row['created_at'] . ' UTC' );
		$attachments = json_decode( (string) $row['attachments'], true );
		$user        = $row['user_id'] ? get_userdata( (int) $row['user_id'] ) : null;
		$hint        = 'failed' === $row['status'] ? TMG_SMTP_Hints::for_error( (string) $row['error'], (string) $row['transcript'] ) : '';
		?>
		<h2 class="tmg-smtp-detail-title"><?php echo self::status_badge( (string) $row['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php echo esc_html( '' !== (string) $row['subject'] ? $row['subject'] : __( '(no subject)', 'tamago-smtp' ) ); ?></h2>

		<?php if ( 'failed' === $row['status'] ) : ?>
			<div class="notice notice-error inline">
				<p><strong><?php esc_html_e( 'Error:', 'tamago-smtp' ); ?></strong> <?php echo esc_html( (string) $row['error'] ); ?></p>
				<?php if ( '' !== $hint ) : ?>
					<p><strong><?php esc_html_e( 'What to try:', 'tamago-smtp' ); ?></strong> <?php echo esc_html( $hint ); ?></p>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<div class="tmg-smtp-detail-actions">
			<?php if ( TMG_SMTP_Logger::can_resend( $row ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="tmg_smtp_log_resend">
					<input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
					<?php wp_nonce_field( 'tmg_smtp_log_resend_' . (int) $row['id'] ); ?>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Resend', 'tamago-smtp' ); ?></button>
					<?php if ( $attachments ) : ?>
						<span class="description"><?php esc_html_e( 'Attachments are not stored, so they are not resent.', 'tamago-smtp' ); ?></span>
					<?php endif; ?>
				</form>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'This email cannot be resent because its content was not stored in full.', 'tamago-smtp' ); ?></p>
			<?php endif; ?>
			<a class="button button-link-delete tmg-smtp-confirm-one" href="<?php echo esc_url( self::delete_url( (int) $row['id'] ) ); ?>"><?php esc_html_e( 'Delete', 'tamago-smtp' ); ?></a>
		</div>

		<table class="widefat striped tmg-smtp-detail">
			<tbody>
			<?php
			$fields = array(
				__( 'Date', 'tamago-smtp' )        => wp_date( get_option( 'date_format' ) . ' H:i:s', $time ) . ' (' . $row['created_at'] . ' UTC)',
				__( 'Status', 'tamago-smtp' )      => 'sent' === $row['status'] ? __( 'Sent (accepted by the mail server)', 'tamago-smtp' ) : __( 'Failed', 'tamago-smtp' ),
				__( 'To', 'tamago-smtp' )          => $row['to_email'],
				__( 'Cc', 'tamago-smtp' )          => $row['cc'],
				__( 'Bcc', 'tamago-smtp' )         => $row['bcc'],
				__( 'From', 'tamago-smtp' )        => trim( ( '' !== (string) $row['from_name'] ? $row['from_name'] . ' ' : '' ) . ( '' !== (string) $row['from_email'] ? '<' . $row['from_email'] . '>' : '' ) ),
				__( 'Reply-To', 'tamago-smtp' )    => $row['reply_to'],
				__( 'Content type', 'tamago-smtp' ) => $row['content_type'],
				__( 'Mailer', 'tamago-smtp' )      => $row['mailer'],
				__( 'Message-ID', 'tamago-smtp' )  => $row['message_id'],
				__( 'Duration', 'tamago-smtp' )    => number_format_i18n( (int) $row['duration_ms'] ) . ' ms',
				__( 'Sent by', 'tamago-smtp' )     => self::source_label( (string) $row['source'] ),
				__( 'Function', 'tamago-smtp' )    => $row['source_function'],
				__( 'Context', 'tamago-smtp' )     => self::context_label( (string) $row['context'] ),
				__( 'User', 'tamago-smtp' )        => $user ? $user->user_login . ' (#' . $user->ID . ')' : ( $row['user_id'] ? '#' . $row['user_id'] : __( 'nobody logged in', 'tamago-smtp' ) ),
				__( 'Request', 'tamago-smtp' )     => $row['request_url'],
			);
			foreach ( $fields as $label => $value ) {
				if ( null === $value || '' === (string) $value ) {
					continue;
				}
				echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( (string) $value ) . '</td></tr>';
			}

			echo '<tr><th scope="row">' . esc_html__( 'Attachments', 'tamago-smtp' ) . '</th><td>';
			if ( is_array( $attachments ) && $attachments ) {
				echo '<ul class="tmg-smtp-plain-list">';
				foreach ( $attachments as $file ) {
					printf(
						'<li>%1$s <span class="tmg-smtp-muted">(%2$s%3$s)</span></li>',
						esc_html( (string) ( $file['name'] ?? '' ) ),
						esc_html( size_format( (int) ( $file['size'] ?? 0 ), 1 ) ? size_format( (int) ( $file['size'] ?? 0 ), 1 ) : '0 B' ),
						! empty( $file['inline'] ) ? esc_html__( ', inline', 'tamago-smtp' ) : ''
					);
				}
				echo '</ul>';
			} else {
				esc_html_e( 'None', 'tamago-smtp' );
			}
			echo '</td></tr>';

			echo '<tr><th scope="row">' . esc_html__( 'Resends', 'tamago-smtp' ) . '</th><td>';
			if ( (int) $row['resend_of'] ) {
				/* translators: %s: link to the original entry */
				printf( esc_html__( 'This is a resend of %s.', 'tamago-smtp' ), '<a href="' . esc_url( TMG_SMTP_Admin::page_url( 'logs', array( 'log' => (int) $row['resend_of'] ) ) ) . '">#' . (int) $row['resend_of'] . '</a>' );
				echo ' ';
			}
			$resends = TMG_SMTP_Log_Store::resends_of( (int) $row['id'] );
			if ( $resends ) {
				/* translators: %d: count */
				echo esc_html( sprintf( _n( 'Resent %d time:', 'Resent %d times:', count( $resends ), 'tamago-smtp' ), count( $resends ) ) ) . ' ';
				$links = array();
				foreach ( $resends as $resend ) {
					$links[] = '<a href="' . esc_url( TMG_SMTP_Admin::page_url( 'logs', array( 'log' => (int) $resend['id'] ) ) ) . '">#' . (int) $resend['id'] . '</a> (' . esc_html( 'sent' === $resend['status'] ? __( 'sent', 'tamago-smtp' ) : __( 'failed', 'tamago-smtp' ) ) . ')';
				}
				echo implode( ', ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			} elseif ( ! (int) $row['resend_of'] ) {
				esc_html_e( 'Not resent', 'tamago-smtp' );
			}
			echo '</td></tr>';
			?>
			</tbody>
		</table>

		<h2><?php esc_html_e( 'Content', 'tamago-smtp' ); ?></h2>
		<?php
		self::render_body( $row );
		?>

		<h2><?php esc_html_e( 'Headers', 'tamago-smtp' ); ?></h2>
		<?php if ( '' !== (string) $row['headers'] ) : ?>
			<pre class="tmg-smtp-pre"><?php echo esc_html( (string) $row['headers'] ); ?></pre>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'No headers were recorded (the email failed before it was built).', 'tamago-smtp' ); ?></p>
		<?php endif; ?>

		<h2><?php esc_html_e( 'SMTP conversation', 'tamago-smtp' ); ?></h2>
		<?php if ( '' !== (string) $row['transcript'] ) : ?>
			<pre class="tmg-smtp-pre tmg-smtp-transcript"><?php echo esc_html( (string) $row['transcript'] ); ?></pre>
		<?php else : ?>
			<p class="description">
				<?php
				echo esc_html(
					'sent' === $row['status']
						? __( 'Not kept for successful emails. Switch on "Keep the conversation for successful emails too" on the Settings tab to keep it.', 'tamago-smtp' )
						: __( 'No conversation took place (the email was not sent through SMTP, or failed before connecting).', 'tamago-smtp' )
				);
				?>
			</p>
		<?php endif; ?>
		<?php
	}

	private static function render_body( array $row ): void {
		$html = $row['body_html'];
		$text = $row['body_text'];
		if ( null === $html && null === $text ) {
			echo '<p class="description">' . esc_html__( 'The content was not stored (the "Store the email content" setting was off).', 'tamago-smtp' ) . '</p>';
			return;
		}
		if ( ! empty( $row['body_truncated'] ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The content was longer than the "Largest stored content" setting and was cut.', 'tamago-smtp' ) . '</p></div>';
		}
		$views = array();
		if ( null !== $html ) {
			$csp_blocked      = "default-src 'none'; style-src 'unsafe-inline'; img-src data: cid:; font-src data:";
			$csp_allowed      = "default-src 'none'; style-src 'unsafe-inline' https: http:; img-src data: cid: https: http:; font-src data: https: http:";
			$doc              = static function ( string $csp ) use ( $html ): string {
				return '<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="' . esc_attr( $csp ) . '"><base target="_blank"></head><body>' . $html . '</body></html>';
			};
			$views['preview'] = array(
				__( 'Preview', 'tamago-smtp' ),
				'<iframe class="tmg-smtp-preview" sandbox="allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer" title="' . esc_attr__( 'Email preview', 'tamago-smtp' ) . '" srcdoc="' . esc_attr( $doc( $csp_blocked ) ) . '" data-remote="' . esc_attr( $doc( $csp_allowed ) ) . '"></iframe>'
				. '<p><button type="button" class="button button-small tmg-smtp-remote-images">' . esc_html__( 'Load remote images', 'tamago-smtp' ) . '</button> <span class="description">' . esc_html__( 'Remote images are blocked so viewing the log does not tell the sender that the email was opened.', 'tamago-smtp' ) . '</span></p>',
			);
			$views['source']  = array( __( 'HTML source', 'tamago-smtp' ), '<pre class="tmg-smtp-pre">' . esc_html( $html ) . '</pre>' );
		}
		if ( null !== $text && '' !== $text ) {
			$views['text'] = array( null !== $html ? __( 'Plain-text version', 'tamago-smtp' ) : __( 'Text', 'tamago-smtp' ), '<pre class="tmg-smtp-pre tmg-smtp-wrap">' . esc_html( $text ) . '</pre>' );
		}
		if ( ! $views ) {
			echo '<p class="description">' . esc_html__( 'The email was empty.', 'tamago-smtp' ) . '</p>';
			return;
		}
		echo '<div class="tmg-smtp-views">';
		echo '<div class="tmg-smtp-view-tabs" role="tablist">';
		$first = true;
		foreach ( $views as $key => $view ) {
			printf(
				'<button type="button" role="tab" class="button%1$s" aria-selected="%2$s" aria-controls="tmg-smtp-view-%3$s" data-view="%3$s">%4$s</button> ',
				$first ? ' button-primary' : '',
				$first ? 'true' : 'false',
				esc_attr( $key ),
				esc_html( $view[0] )
			);
			$first = false;
		}
		echo '</div>';
		$first = true;
		foreach ( $views as $key => $view ) {
			printf( '<div class="tmg-smtp-view" role="tabpanel" id="tmg-smtp-view-%1$s"%2$s>', esc_attr( $key ), $first ? '' : ' hidden' );
			echo $view[1]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
			echo '</div>';
			$first = false;
		}
		echo '</div>';
	}

	/* --------------------------------------------------------------------- */
	/* Handlers                                                               */
	/* --------------------------------------------------------------------- */

	public static function handle_delete(): void {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'tmg_smtp_log_delete_' . $id );
		TMG_SMTP_Permissions::require_cap( TMG_SMTP_Permissions::VIEW_LOGS );
		$deleted = TMG_SMTP_Log_Store::delete( array( $id ) );
		wp_safe_redirect(
			TMG_SMTP_Admin::page_url(
				'logs',
				array(
					'tmg_smtp_notice' => 'deleted',
					'tmg_smtp_count'  => $deleted,
				)
			)
		);
		exit;
	}

	public static function handle_delete_all(): void {
		check_admin_referer( 'tmg_smtp_log_delete_all' );
		TMG_SMTP_Permissions::require_cap( TMG_SMTP_Permissions::VIEW_LOGS );
		$deleted = TMG_SMTP_Log_Store::delete_all();
		wp_safe_redirect(
			TMG_SMTP_Admin::page_url(
				'logs',
				array(
					'tmg_smtp_notice' => 'deleted',
					'tmg_smtp_count'  => $deleted,
				)
			)
		);
		exit;
	}

	public static function handle_resend(): void {
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		check_admin_referer( 'tmg_smtp_log_resend_' . $id );
		TMG_SMTP_Permissions::require_cap( TMG_SMTP_Permissions::VIEW_LOGS );
		$result = TMG_SMTP_Logger::resend( $id );
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect(
				TMG_SMTP_Admin::page_url(
					'logs',
					array(
						'log'             => $id,
						'tmg_smtp_notice' => 'resend_error',
					)
				)
			);
			exit;
		}
		wp_safe_redirect(
			TMG_SMTP_Admin::page_url(
				'logs',
				array(
					'log'             => $result['log_id'] ? $result['log_id'] : $id,
					'tmg_smtp_notice' => $result['ok'] ? 'resent' : 'resend_failed',
				)
			)
		);
		exit;
	}

	/** CSV of the filtered list (no bodies, headers or transcripts: those are in the detail view). */
	public static function handle_export(): void {
		check_admin_referer( 'tmg_smtp_log_export' );
		TMG_SMTP_Permissions::require_cap( TMG_SMTP_Permissions::VIEW_LOGS );
		$filters = self::filters();
		$columns = array( 'id', 'created_at', 'status', 'to_email', 'cc', 'bcc', 'from_email', 'from_name', 'reply_to', 'subject', 'content_type', 'attachments', 'mailer', 'message_id', 'error', 'duration_ms', 'source', 'source_function', 'context', 'user_id', 'request_url', 'resend_of', 'resend_count' );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="email-log-' . gmdate( 'Y-m-d' ) . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- BOM so Excel reads UTF-8.
		self::csv_line( $out, array_merge( array( 'created_at_utc' ), array_diff( $columns, array( 'created_at' ) ) ) );
		$page = 1;
		do {
			$result = TMG_SMTP_Log_Store::query(
				array_merge(
					$filters,
					array(
						'per_page' => 500,
						'page'     => $page,
					)
				)
			);
			foreach ( $result['rows'] as $row ) {
				$line = array( $row['created_at'] );
				foreach ( $columns as $column ) {
					if ( 'created_at' !== $column ) {
						$line[] = (string) ( $row[ $column ] ?? '' );
					}
				}
				self::csv_line( $out, $line );
			}
			++$page;
		} while ( count( $result['rows'] ) === 500 );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/** Writes one CSV line; cells that a spreadsheet would run as a formula are prefixed with a quote. */
	public static function csv_line( $handle, array $cells ): void {
		foreach ( $cells as $i => $cell ) {
			$cell = (string) $cell;
			if ( '' !== $cell && in_array( $cell[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
				$cell = "'" . $cell;
			}
			$cells[ $i ] = $cell;
		}
		fputcsv( $handle, $cells, ',', '"', '\\' );
	}
}
