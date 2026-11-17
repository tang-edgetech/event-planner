<?php
/**
 * Front-end: decides on page render whether the beacon script loads (targeting rules,
 * excluded roles) and prints its small config. Nothing is counted in PHP on page load,
 * so pages served from a page cache are still counted by the script.
 *
 * Used by: front-end pages (wp_enqueue_scripts).
 */

defined( 'ABSPATH' ) || exit;

class TMG_Reports_Tracker {

	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function enqueue(): void {
		if ( is_admin() || is_preview() || is_customize_preview() || is_feed() || is_robots() || is_embed() || is_trackback() ) {
			return;
		}
		$ctx         = TMG_Reports_Targeting::current_context();
		$ctx['user'] = wp_get_current_user();
		if ( ! TMG_Reports_Targeting::should_track( $ctx ) ) {
			return;
		}
		wp_enqueue_script(
			'tamago-reports',
			TMG_REPORTS_URL . 'assets/js/track.js',
			array(),
			TMG_REPORTS_VERSION,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
		wp_add_inline_script( 'tamago-reports', 'window.tamagoReportsConfig=' . wp_json_encode( self::config( $ctx ) ) . ';', 'before' );
	}

	public static function config( array $ctx ): array {
		$s   = TMG_Reports_Settings::all();
		$cfg = array(
			'endpoint' => esc_url_raw( rest_url( TMG_Reports_Collect::NS . TMG_Reports_Collect::ROUTE ) ),
			'p'        => (int) $ctx['post_id'],
			'k'        => (string) $ctx['kind'],
			'consent'  => (string) $s['consent_mode'],
			'cookie'   => (string) $s['consent_cookie'],
			'cookieV'  => (string) $s['consent_cookie_value'],
			'dnt'      => (bool) $s['respect_dnt'],
			'gpc'      => (bool) $s['respect_gpc'],
			'out'      => (bool) $s['ev_outbound'],
			'dl'       => $s['ev_downloads'] ? implode( '|', (array) $s['download_ext'] ) : '',
			'mail'     => (bool) $s['ev_mailto'],
			'tel'      => (bool) $s['ev_tel'],
			'attr'     => (bool) $s['ev_attr'],
			'forms'    => $s['ev_forms'] ? (string) $s['form_selector'] : '',
			'scroll'   => (bool) $s['ev_scroll'],
			'time'     => (bool) $s['ev_time'],
		);
		return (array) apply_filters( 'tmg_reports_script_config', $cfg, $ctx );
	}
}
