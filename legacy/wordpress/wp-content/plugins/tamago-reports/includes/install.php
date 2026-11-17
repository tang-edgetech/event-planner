<?php
/**
 * Database tables (dbDelta, versioned), capability grants, cron schedules,
 * activation / deactivation and upgrades.
 *
 * Used by: tamago-reports.php (activation hooks, plugins_loaded), uninstall.php.
 */

defined( 'ABSPATH' ) || exit;

class TMG_Reports_Install {

	const DB_OPTION = 'tmg_reports_db_version';

	public static function hits_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'tmg_reports_hits';
	}

	public static function daily_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'tmg_reports_daily';
	}

	public static function activate( $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::install_site();
				restore_current_blog();
			}
			return;
		}
		self::install_site();
	}

	public static function install_site(): void {
		self::create_tables();
		TMG_Reports_Permissions::grant_admin();
		TMG_Reports_Summary::schedule();
	}

	public static function deactivate(): void {
		TMG_Reports_Summary::unschedule();
	}

	public static function maybe_upgrade(): void {
		if ( get_option( self::DB_OPTION ) !== TMG_REPORTS_DB_VERSION ) {
			self::install_site();
		}
	}

	public static function load_textdomain(): void {
		load_plugin_textdomain( 'tamago-reports', false, dirname( plugin_basename( TMG_REPORTS_FILE ) ) . '/languages' );
	}

	/**
	 * The hits table keeps every column of the Event Plus `epc_visits` table with the
	 * same name and type (so old data copies with one INSERT … SELECT) and adds
	 * event_name, goal and value.
	 */
	public static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$hits    = self::hits_table();
		$daily   = self::daily_table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$hits} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			post_type varchar(20) NOT NULL DEFAULT '',
			event_type varchar(20) NOT NULL DEFAULT 'pageview',
			event_name varchar(64) NOT NULL DEFAULT '',
			label varchar(190) NOT NULL DEFAULT '',
			goal varchar(64) NOT NULL DEFAULT '',
			value decimal(15,2) NOT NULL DEFAULT 0.00,
			visitor_id varchar(32) NOT NULL DEFAULT '',
			session_id varchar(32) NOT NULL DEFAULT '',
			is_new tinyint(1) NOT NULL DEFAULT 0,
			path varchar(255) NOT NULL DEFAULT '',
			referrer varchar(255) NOT NULL DEFAULT '',
			ref_domain varchar(120) NOT NULL DEFAULT '',
			channel varchar(20) NOT NULL DEFAULT '',
			utm_source varchar(100) NOT NULL DEFAULT '',
			utm_medium varchar(100) NOT NULL DEFAULT '',
			utm_campaign varchar(100) NOT NULL DEFAULT '',
			utm_term varchar(100) NOT NULL DEFAULT '',
			utm_content varchar(100) NOT NULL DEFAULT '',
			device varchar(10) NOT NULL DEFAULT '',
			browser varchar(30) NOT NULL DEFAULT '',
			browser_version varchar(20) NOT NULL DEFAULT '',
			os varchar(30) NOT NULL DEFAULT '',
			os_version varchar(20) NOT NULL DEFAULT '',
			screen varchar(15) NOT NULL DEFAULT '',
			viewport varchar(15) NOT NULL DEFAULT '',
			language varchar(15) NOT NULL DEFAULT '',
			timezone varchar(50) NOT NULL DEFAULT '',
			country varchar(2) NOT NULL DEFAULT '',
			country_name varchar(60) NOT NULL DEFAULT '',
			region varchar(80) NOT NULL DEFAULT '',
			city varchar(80) NOT NULL DEFAULT '',
			ip_hash varchar(32) NOT NULL DEFAULT '',
			duration int(10) unsigned NOT NULL DEFAULT 0,
			scroll tinyint(3) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY post_created (post_id,created_at),
			KEY created_at (created_at),
			KEY visitor_id (visitor_id),
			KEY session_id (session_id),
			KEY type_created_visitor (event_type,created_at,visitor_id),
			KEY ip_created (ip_hash,created_at),
			KEY goal_created (goal,created_at)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$daily} (
			day date NOT NULL,
			dim varchar(24) NOT NULL,
			val varchar(190) NOT NULL DEFAULT '',
			views int(10) unsigned NOT NULL DEFAULT 0,
			visitors int(10) unsigned NOT NULL DEFAULT 0,
			sessions int(10) unsigned NOT NULL DEFAULT 0,
			new_visitors int(10) unsigned NOT NULL DEFAULT 0,
			entries int(10) unsigned NOT NULL DEFAULT 0,
			exits int(10) unsigned NOT NULL DEFAULT 0,
			bounces int(10) unsigned NOT NULL DEFAULT 0,
			dur_sum bigint(20) unsigned NOT NULL DEFAULT 0,
			dur_n int(10) unsigned NOT NULL DEFAULT 0,
			scroll_sum bigint(20) unsigned NOT NULL DEFAULT 0,
			scroll_n int(10) unsigned NOT NULL DEFAULT 0,
			events int(10) unsigned NOT NULL DEFAULT 0,
			conversions int(10) unsigned NOT NULL DEFAULT 0,
			value decimal(15,2) NOT NULL DEFAULT 0.00,
			PRIMARY KEY  (day,dim,val),
			KEY dim_day (dim,day)
			) {$charset};"
		);

		update_option( self::DB_OPTION, TMG_REPORTS_DB_VERSION, true );
	}
}
