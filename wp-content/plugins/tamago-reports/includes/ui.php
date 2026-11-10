<?php
/**
 * Small admin rendering helpers: metric labels and formatting, deltas, inline SVG sparklines.
 *
 * Used by: report screen, real-time panel, post columns / meta box, dashboard widget, CSV export.
 */

defined( 'ABSPATH' ) || exit;

class TMG_Reports_UI {

	public static function metric_labels(): array {
		return array(
			'views'             => __( 'Page views', 'tamago-reports' ),
			'visitors'          => __( 'Visitors', 'tamago-reports' ),
			'new_visitors'      => __( 'New visitors', 'tamago-reports' ),
			'sessions'          => __( 'Sessions', 'tamago-reports' ),
			'views_per_session' => __( 'Views / session', 'tamago-reports' ),
			'avg_time'          => __( 'Avg. engaged time', 'tamago-reports' ),
			'avg_scroll'        => __( 'Avg. scroll', 'tamago-reports' ),
			'bounce_rate'       => __( 'Bounce rate', 'tamago-reports' ),
			'entries'           => __( 'Entrances', 'tamago-reports' ),
			'exits'             => __( 'Exits', 'tamago-reports' ),
			'events'            => __( 'Events', 'tamago-reports' ),
			'conversions'       => __( 'Conversions', 'tamago-reports' ),
			'conversion_rate'   => __( 'Conversion rate', 'tamago-reports' ),
			'value'             => __( 'Value', 'tamago-reports' ),
		);
	}

	public static function duration( float $s ): string {
		$s = (int) round( $s );
		if ( $s >= 3600 ) {
			return sprintf( '%dh %dm', intdiv( $s, 3600 ), intdiv( $s % 3600, 60 ) );
		}
		return $s >= 60 ? sprintf( '%dm %02ds', intdiv( $s, 60 ), $s % 60 ) : $s . 's';
	}

	/** @param int|float $v */
	public static function format( string $metric, $v ): string {
		switch ( $metric ) {
			case 'avg_time':
				return self::duration( (float) $v );
			case 'avg_scroll':
			case 'bounce_rate':
			case 'conversion_rate':
				return number_format_i18n( (float) $v, 'avg_scroll' === $metric ? 0 : 1 ) . '%';
			case 'views_per_session':
				return number_format_i18n( (float) $v, 2 );
			case 'value':
				return number_format_i18n( (float) $v, 2 );
		}
		return number_format_i18n( (float) $v );
	}

	/** Delta vs previous period as HTML (lower is better for bounce rate). */
	public static function delta( string $metric, float $now, float $before ): string {
		if ( 0.0 === $before && 0.0 === $now ) {
			return '<span class="tmg-delta is-flat">—</span>';
		}
		if ( 0.0 === $before ) {
			return '<span class="tmg-delta is-up">' . esc_html__( 'new', 'tamago-reports' ) . '</span>';
		}
		$pct  = ( $now - $before ) / abs( $before ) * 100;
		$good = 'bounce_rate' === $metric ? $pct < 0 : $pct > 0;
		$cls  = abs( $pct ) < 0.5 ? 'is-flat' : ( $good ? 'is-up' : 'is-down' );
		$sign = $pct > 0 ? '+' : '';
		return '<span class="tmg-delta ' . esc_attr( $cls ) . '" title="' . esc_attr( sprintf( /* translators: %s: previous value */ __( 'Previous period: %s', 'tamago-reports' ), self::format( $metric, $before ) ) ) . '">' . esc_html( $sign . number_format_i18n( $pct, 1 ) . '%' ) . '</span>';
	}

	/** Inline SVG sparkline (escaped numbers only). */
	public static function sparkline( array $values, int $w = 160, int $h = 36, string $label = '' ): string {
		$values = array_map( 'floatval', array_values( $values ) );
		$n      = count( $values );
		if ( $n < 2 ) {
			$values = array_pad( $values, 2, 0.0 );
			$n      = 2;
		}
		$max = max( 1.0, max( $values ) );
		$pts = array();
		foreach ( $values as $i => $v ) {
			$pts[] = round( $i / ( $n - 1 ) * ( $w - 2 ) + 1, 1 ) . ',' . round( $h - 2 - $v / $max * ( $h - 4 ), 1 );
		}
		$line = implode( ' ', $pts );
		$area = '1,' . ( $h - 1 ) . ' ' . $line . ' ' . ( $w - 1 ) . ',' . ( $h - 1 );
		return '<svg class="tmg-spark" viewBox="0 0 ' . (int) $w . ' ' . (int) $h . '" width="' . (int) $w . '" height="' . (int) $h . '" role="img" aria-label="' . esc_attr( $label ) . '" preserveAspectRatio="none">'
			. '<polygon points="' . esc_attr( $area ) . '" class="tmg-spark-area"/>'
			. '<polyline points="' . esc_attr( $line ) . '" class="tmg-spark-line" fill="none"/></svg>';
	}
}
