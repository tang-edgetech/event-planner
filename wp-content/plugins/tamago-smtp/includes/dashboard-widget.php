<?php
/**
 * Dashboard widget "Email sending": sent / failed per day for the last 7 days (SVG bars) and the latest failure.
 *
 * Screens: Dashboard (users with tmg_smtp_view_logs).
 *
 * @package TamagoSMTP
 */

defined( 'ABSPATH' ) || exit;

class TMG_SMTP_Dashboard_Widget {

	public static function init(): void {
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'register' ) );
	}

	public static function register(): void {
		if ( TMG_SMTP_Permissions::can_view_logs() ) {
			wp_add_dashboard_widget( 'tmg_smtp_dashboard', __( 'Email sending', 'tamago-smtp' ), array( __CLASS__, 'render' ) );
		}
	}

	public static function render(): void {
		$days   = TMG_SMTP_Log_Store::daily_counts( 7 );
		$sent   = array_sum( wp_list_pluck( $days, 'sent' ) );
		$failed = array_sum( wp_list_pluck( $days, 'failed' ) );
		$max    = 1;
		foreach ( $days as $day ) {
			$max = max( $max, $day['sent'] + $day['failed'] );
		}
		$latest = TMG_SMTP_Log_Store::latest_failure();
		?>
		<style>
			.tmg-smtp-dash-totals{display:flex;gap:24px;margin:4px 0 12px}
			.tmg-smtp-dash-totals strong{display:block;font-size:24px;line-height:1.2}
			.tmg-smtp-dash-sent strong{color:#008a20}.tmg-smtp-dash-failed strong{color:#d63638}
			.tmg-smtp-dash-chart{width:100%;height:auto;display:block}
			.tmg-smtp-dash-chart text{font-size:10px;fill:#646970}
			.tmg-smtp-dash-latest{border-top:1px solid #f0f0f1;margin-top:12px;padding-top:8px}
		</style>
		<?php if ( ! TMG_SMTP_Settings::get( 'log_enabled' ) ) : ?>
			<p><?php esc_html_e( 'The email log is switched off, so there is nothing to show.', 'tamago-smtp' ); ?></p>
		<?php endif; ?>
		<div class="tmg-smtp-dash-totals">
			<div class="tmg-smtp-dash-sent"><strong><?php echo esc_html( number_format_i18n( $sent ) ); ?></strong><?php esc_html_e( 'sent (7 days)', 'tamago-smtp' ); ?></div>
			<div class="tmg-smtp-dash-failed"><strong><?php echo esc_html( number_format_i18n( $failed ) ); ?></strong><?php esc_html_e( 'failed (7 days)', 'tamago-smtp' ); ?></div>
		</div>
		<?php
		// Stacked bars: sent (green) under failed (red), one per day.
		$w     = 280;
		$h     = 90;
		$bar   = 28;
		$gap   = ( $w - 7 * $bar ) / 7;
		echo '<svg class="tmg-smtp-dash-chart" viewBox="0 0 ' . (int) $w . ' ' . (int) ( $h + 16 ) . '" role="img" aria-label="' . esc_attr__( 'Emails sent and failed per day', 'tamago-smtp' ) . '">';
		$i = 0;
		foreach ( $days as $date => $day ) {
			$x        = $gap / 2 + $i * ( $bar + $gap );
			$sent_h   = $day['sent'] / $max * $h;
			$failed_h = $day['failed'] / $max * $h;
			$label    = wp_date( 'D', strtotime( $date . ' 12:00:00' ) );
			/* translators: 1: date, 2: sent count, 3: failed count */
			$tip = sprintf( __( '%1$s: %2$d sent, %3$d failed', 'tamago-smtp' ), wp_date( get_option( 'date_format' ), strtotime( $date . ' 12:00:00' ) ), $day['sent'], $day['failed'] );
			echo '<g><title>' . esc_html( $tip ) . '</title>';
			echo '<rect x="' . esc_attr( (string) round( $x, 1 ) ) . '" y="0" width="' . (int) $bar . '" height="' . (int) $h . '" fill="#f6f7f7"></rect>';
			if ( $sent_h > 0 ) {
				echo '<rect x="' . esc_attr( (string) round( $x, 1 ) ) . '" y="' . esc_attr( (string) round( $h - $sent_h, 1 ) ) . '" width="' . (int) $bar . '" height="' . esc_attr( (string) round( $sent_h, 1 ) ) . '" fill="#00a32a"></rect>';
			}
			if ( $failed_h > 0 ) {
				echo '<rect x="' . esc_attr( (string) round( $x, 1 ) ) . '" y="' . esc_attr( (string) round( $h - $sent_h - $failed_h, 1 ) ) . '" width="' . (int) $bar . '" height="' . esc_attr( (string) round( $failed_h, 1 ) ) . '" fill="#d63638"></rect>';
			}
			echo '<text x="' . esc_attr( (string) round( $x + $bar / 2, 1 ) ) . '" y="' . (int) ( $h + 12 ) . '" text-anchor="middle">' . esc_html( $label ) . '</text></g>';
			++$i;
		}
		echo '</svg>';
		?>
		<div class="tmg-smtp-dash-latest">
			<?php if ( $latest ) : ?>
				<p>
					<strong><?php esc_html_e( 'Latest failure:', 'tamago-smtp' ); ?></strong>
					<?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $latest['created_at'] . ' UTC' ) ) ); ?><br>
					<?php echo esc_html( mb_strimwidth( (string) $latest['subject'], 0, 80, '…' ) ); ?> → <?php echo esc_html( mb_strimwidth( (string) $latest['to_email'], 0, 60, '…' ) ); ?><br>
					<span style="color:#d63638"><?php echo esc_html( mb_strimwidth( (string) $latest['error'], 0, 160, '…' ) ); ?></span>
					<?php if ( TMG_SMTP_Permissions::can_view_logs() ) : ?>
						<br><a href="<?php echo esc_url( tmg_smtp_screen_url( 'logs', array( 'log' => (int) $latest['id'] ) ) ); ?>"><?php esc_html_e( 'View details', 'tamago-smtp' ); ?></a>
					<?php endif; ?>
				</p>
			<?php else : ?>
				<p><?php esc_html_e( 'No failed emails in the log.', 'tamago-smtp' ); ?></p>
			<?php endif; ?>
			<p><a href="<?php echo esc_url( tmg_smtp_screen_url( 'logs' ) ); ?>"><?php esc_html_e( 'Open the email log', 'tamago-smtp' ); ?></a></p>
		</div>
		<?php
	}
}
