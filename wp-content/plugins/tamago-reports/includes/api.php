<?php
/**
 * Public PHP API for themes and other plugins.
 *
 *   tmg_reports_track( 'registration', array( 'post_id' => 12, 'label' => 'Registration #5', 'value' => 50,
 *       'visitor_id' => $vid, 'source' => array( 'utm_source' => 'facebook', 'ref_domain' => 'm.facebook.com' ) ) );
 *
 *   tmg_reports_query( array( 'range' => '30d', 'dimension' => 'page', 'metrics' => array( 'views', 'visitors' ),
 *       'filters' => array( 'post_type' => 'event' ), 'limit' => 5 ) );
 *
 * Used by: themes / plugins (server-side events, custom dashboards).
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'tmg_reports_track' ) ) {
	/**
	 * Record a server-side event (e.g. a form registration). Goals of type "server event"
	 * with a matching name turn it into a conversion.
	 *
	 * @param string $event Event name (max 64 chars).
	 * @param array  $args  post_id, label, value, visitor_id, session_id, goal, source (array:
	 *                      referrer, ref_domain, utm_source, utm_medium, utm_campaign, utm_term, utm_content).
	 *                      Without visitor_id the plugin uses a posted `tmg_vid` field, or (cookieless mode)
	 *                      the daily visitor hash of the current request. Without source, attribution is
	 *                      inherited from the visitor's latest session.
	 * @return int Hit ID, or 0 when nothing was recorded.
	 */
	function tmg_reports_track( string $event, array $args = array() ): int {
		return TMG_Reports_Recorder::track_server( $event, $args );
	}
}

if ( ! function_exists( 'tmg_reports_query' ) ) {
	/**
	 * Query report data.
	 *
	 * @param array $args {
	 *     @type string $range      today|yesterday|7d|30d|90d|this_month|last_month|this_year|custom (default 30d).
	 *     @type string $from       Y-m-d in site time (with $to; implies range=custom).
	 *     @type string $to         Y-m-d in site time.
	 *     @type string $dimension  '' for totals, or page|post_type|ref_domain|channel|utm_source|utm_medium|utm_campaign|
	 *                              utm_term|utm_content|device|browser|browser_version|os|os_version|screen|country|region|
	 *                              city|language|timezone|new_returning|hour_weekday|event|goal|day. ('dimensions' => array( x ) also accepted; first is used.)
	 *     @type array  $metrics    Any of views, visitors, sessions, new_visitors, views_per_session, avg_time, avg_scroll,
	 *                              bounce_rate, entries, exits, events, conversions, conversion_rate, value (default all).
	 *     @type array  $filters    post_type, post (ID), path, channel, ref_domain, utm_source, utm_medium, utm_campaign,
	 *                              utm_term, utm_content, device, browser, os, country, region (CC|Region), city (CC|Region|City),
	 *                              language, goal (visitors who completed the goal).
	 *     @type string $order      Metric to sort rows by (default views; events / conversions for event / goal).
	 *     @type int    $limit      Rows to return (default 10, 0 = all).
	 *     @type int    $offset     Rows to skip.
	 *     @type string $source     auto (summaries + raw) or raw.
	 * }
	 * @return array Totals (metric => number) without a dimension; otherwise a list of rows
	 *               ( key, label, metric => number … ).
	 */
	function tmg_reports_query( array $args = array() ): array {
		return TMG_Reports_Query::run( $args );
	}
}
