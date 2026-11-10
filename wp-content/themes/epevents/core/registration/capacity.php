<?php
/**
 * Capacity & registration status (auto / manual "Application Full").
 *
 * Screens: Event edit → Capacity & Status; the status badge on the event page; Events list counts.
 */

defined( 'ABSPATH' ) || exit;

class EPC_Capacity {

	public static function init(): void {
		add_filter( 'manage_' . EPC_Post_Types::EVENT . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . EPC_Post_Types::EVENT . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
	}

	/** Applicants counting towards capacity (cancelled & waitlisted excluded). */
	public static function count( int $event_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(1) FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = '_epc_event_id' AND e.meta_value = %d
				 LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_epc_status'
				 WHERE p.post_type = %s AND p.post_status = 'publish'
				 AND ( s.meta_value IS NULL OR s.meta_value NOT IN ('cancelled','waitlist') )",
				$event_id,
				EPC_Post_Types::APPLICANT
			)
		);
	}

	/** Total applicants of any status. */
	public static function count_all( int $event_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(1) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = '_epc_event_id' AND e.meta_value = %d WHERE p.post_type = %s AND p.post_status = 'publish'",
				$event_id,
				EPC_Post_Types::APPLICANT
			)
		);
	}

	/**
	 * Current registration state.
	 *
	 * @return array{state:string,count:int,max:int,min:int,remaining:?int,mode:string}
	 *   state: open | waitlist | full | closed | not_open
	 */
	public static function state( int $event_id ): array {
		$mode     = (string) ( get_field( 'reg_status', $event_id ) ?: 'auto' );
		$max      = (int) get_field( 'capacity_max', $event_id );
		$min      = (int) get_field( 'capacity_min', $event_id );
		$waitlist = (bool) get_field( 'enable_waitlist', $event_id );
		$count    = self::count( $event_id );
		$now      = current_time( 'timestamp' );

		$state = 'open';
		if ( 'closed' === $mode ) {
			$state = 'closed';
		} elseif ( 'full' === $mode ) {
			$state = 'full';
		} elseif ( 'auto' === $mode ) {
			$open_at  = get_field( 'reg_open_at', $event_id );
			$close_at = get_field( 'reg_close_at', $event_id );
			if ( $open_at && strtotime( $open_at ) > $now ) {
				$state = 'not_open';
			} elseif ( $close_at && strtotime( $close_at ) < $now ) {
				$state = 'closed';
			} elseif ( $max > 0 && $count >= $max ) {
				$state = $waitlist ? 'waitlist' : 'full';
			}
		}

		return array(
			'state'     => $state,
			'mode'      => $mode,
			'count'     => $count,
			'max'       => $max,
			'min'       => $min,
			'remaining' => $max > 0 ? max( 0, $max - $count ) : null,
		);
	}

	public static function is_accepting( int $event_id ): bool {
		return in_array( self::state( $event_id )['state'], array( 'open', 'waitlist' ), true );
	}

	public static function columns( array $cols ): array {
		$new = array();
		foreach ( $cols as $k => $v ) {
			if ( 'date' === $k ) {
				$new['epc_event_date'] = 'Event date';
				$new['epc_regs']       = 'Registrations';
				$new['epc_state']      = 'Status';
			}
			$new[ $k ] = $v;
		}
		return $new;
	}

	public static function column( string $col, int $post_id ): void {
		if ( 'epc_event_date' === $col ) {
			echo esc_html( (string) get_field( 'event_date_text', $post_id ) );
		}
		if ( 'epc_regs' === $col ) {
			$s   = self::state( $post_id );
			$url = admin_url( 'edit.php?post_type=' . EPC_Post_Types::APPLICANT . '&epc_event=' . $post_id );
			echo '<a href="' . esc_url( $url ) . '"><strong>' . (int) $s['count'] . '</strong>' . ( $s['max'] ? ' / ' . (int) $s['max'] : '' ) . '</a>';
			if ( $s['max'] ) {
				$pct = min( 100, round( $s['count'] / $s['max'] * 100 ) );
				echo '<div class="epc-bar"><span style="width:' . (int) $pct . '%"></span></div>';
			}
			if ( $s['min'] && $s['count'] < $s['min'] ) {
				echo '<small class="epc-muted">Min ' . (int) $s['min'] . ' not reached</small>';
			}
		}
		if ( 'epc_state' === $col ) {
			$s = self::state( $post_id );
			echo self::badge( $s['state'] ) . ( 'auto' !== $s['mode'] ? ' <small class="epc-muted">(manual)</small>' : '' ); // phpcs:ignore
		}
	}

	public static function badge( string $state ): string {
		$labels = array(
			'open'     => 'Open',
			'waitlist' => 'Waitlist',
			'full'     => 'Application Full',
			'closed'   => 'Closed',
			'not_open' => 'Not yet open',
		);
		return '<span class="epc-badge epc-badge--' . esc_attr( $state ) . '">' . esc_html( $labels[ $state ] ?? $state ) . '</span>';
	}
}
