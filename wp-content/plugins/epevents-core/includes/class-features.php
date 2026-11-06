<?php
/**
 * Optional features, each switched on/off in Event Plus → Settings → Features (all off by default):
 * - Capacity alert email at a set percentage (and when full).
 * - Waiting list: email the team when a seat frees up, or offer it to the next person automatically.
 * - Calendar (.ics) invite in confirmation emails (see EPC_Mailer).
 * - Countdown switched on by default for new banners.
 * - Hide personal data (email, phone, NRIC) from users who cannot edit applicants (e.g. Viewers).
 *
 * Also the single place where an applicant's status changes, so every change is logged.
 */

defined( 'ABSPATH' ) || exit;

class EPC_Features {

	/** Statuses that take a seat. */
	const SEATED = array( 'registered', 'confirmed', 'attended' );

	public static function init(): void {
		add_action( 'epc_applicant_registered', array( __CLASS__, 'capacity_alert' ), 20, 2 );
		add_action( 'epc_applicant_status_changed', array( __CLASS__, 'after_status_change' ), 10, 4 );
		add_action( 'trashed_post', array( __CLASS__, 'after_trash' ) );
		add_action( 'acf/save_post', array( __CLASS__, 'after_event_save' ), 20 );
		add_filter( 'acf/load_field/key=field_epc_banner_countdown', array( __CLASS__, 'countdown_default' ) );
	}

	public static function on( string $feature ): bool {
		return (bool) epc_setting( 'epc_feature_' . $feature, false );
	}

	/* ------------------------------------------------------------------ */
	/* Status changes and activity log                                     */
	/* ------------------------------------------------------------------ */

	/** Add a line to an applicant's activity log. */
	public static function log( int $applicant_id, string $text ): void {
		$log   = (array) get_post_meta( $applicant_id, '_epc_log', true );
		$log   = array_values( array_filter( $log ) );
		$log[] = array(
			't' => time(),
			'u' => get_current_user_id(),
			'm' => wp_strip_all_tags( $text ),
		);
		update_post_meta( $applicant_id, '_epc_log', array_slice( $log, -200 ) );
	}

	/** Change status, log it and let features react. Returns false if nothing changed. */
	public static function set_status( int $applicant_id, string $status, string $why = '' ): bool {
		if ( ! isset( epc_applicant_statuses()[ $status ] ) ) {
			return false;
		}
		$old = get_post_meta( $applicant_id, '_epc_status', true ) ?: 'registered';
		if ( $old === $status ) {
			return false;
		}
		update_post_meta( $applicant_id, '_epc_status', $status );
		$labels = epc_applicant_statuses();
		self::log( $applicant_id, sprintf( 'Status: %s → %s%s', $labels[ $old ] ?? $old, $labels[ $status ], $why ? ' (' . $why . ')' : '' ) );
		do_action( 'epc_applicant_status_changed', $applicant_id, $old, $status, (int) get_post_meta( $applicant_id, '_epc_event_id', true ) );
		return true;
	}

	public static function after_status_change( int $applicant_id, string $old, string $new, int $event_id ): void {
		if ( in_array( $old, self::SEATED, true ) && ! in_array( $new, self::SEATED, true ) ) {
			self::seat_freed( $event_id );
		}
	}

	public static function after_trash( int $post_id ): void {
		if ( EPC_Post_Types::APPLICANT !== get_post_type( $post_id ) ) {
			return;
		}
		$status = get_post_meta( $post_id, '_epc_status', true ) ?: 'registered';
		if ( in_array( $status, self::SEATED, true ) ) {
			self::seat_freed( (int) get_post_meta( $post_id, '_epc_event_id', true ) );
		}
	}

	/** Raising the maximum capacity can free seats too. */
	public static function after_event_save( $post_id ): void {
		if ( is_numeric( $post_id ) && EPC_Post_Types::EVENT === get_post_type( (int) $post_id ) ) {
			self::seat_freed( (int) $post_id );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Waiting list                                                        */
	/* ------------------------------------------------------------------ */

	private static function waitlisted( int $event_id, int $limit = -1 ): array {
		return get_posts(
			array(
				'post_type'   => EPC_Post_Types::APPLICANT,
				'post_status' => 'publish',
				'numberposts' => $limit,
				'orderby'     => 'date',
				'order'       => 'ASC',
				'fields'      => 'ids',
				'meta_query'  => array(
					array( 'key' => '_epc_event_id', 'value' => $event_id ),
					array( 'key' => '_epc_status', 'value' => 'waitlist' ),
				),
			)
		);
	}

	public static function seat_freed( int $event_id ): void {
		$mode = (string) epc_setting( 'epc_feature_waitlist', 'manual' );
		if ( ! $event_id || 'manual' === $mode || ! get_field( 'enable_waitlist', $event_id ) ) {
			return;
		}
		$state = EPC_Capacity::state( $event_id );
		$free  = null === $state['remaining'] ? PHP_INT_MAX : (int) $state['remaining'];
		// Respect a manual "Application full" / "Closed" switch.
		if ( $free < 1 || ! in_array( $state['mode'], array( 'auto', 'open' ), true ) ) {
			return;
		}
		if ( 'notify' === $mode ) {
			$waiting = count( self::waitlisted( $event_id ) );
			if ( $waiting ) {
				$link = admin_url( 'edit.php?post_type=' . EPC_Post_Types::APPLICANT . '&epc_event=' . $event_id . '&epc_status=waitlist' );
				EPC_Mailer::send(
					EPC_Mailer::admin_recipients( $event_id ),
					'Seat available: ' . get_the_title( $event_id ),
					'<p>A seat is now available for <strong>' . esc_html( get_the_title( $event_id ) ) . '</strong>. ' . (int) $waiting . ' ' . _n( 'person is', 'people are', $waiting ) . ' on the waiting list.</p><p><a href="' . esc_url( $link ) . '">Open the waiting list</a></p>',
					'admin'
				);
			}
			return;
		}

		// Automatic: move the earliest waiting applicants into the free seats, one lock per event.
		global $wpdb;
		$lock = 'epc_event_' . $event_id;
		$wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock ) );
		$state = EPC_Capacity::state( $event_id );
		$free  = null === $state['remaining'] ? PHP_INT_MAX : (int) $state['remaining'];
		$moved = array();
		if ( $free > 0 ) {
			$target = get_field( 'default_status', $event_id ) ?: 'registered';
			$target = in_array( $target, self::SEATED, true ) ? $target : 'registered';
			foreach ( self::waitlisted( $event_id, min( $free, 50 ) ) as $id ) {
				if ( self::set_status( (int) $id, $target, 'seat became available' ) ) {
					$moved[] = (int) $id;
				}
			}
		}
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		foreach ( $moved as $id ) {
			if ( EPC_Mailer::confirmation( $id, 'offer' ) ) {
				self::log( $id, 'Seat offer email sent' );
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Capacity alert                                                      */
	/* ------------------------------------------------------------------ */

	public static function capacity_alert( int $applicant_id, int $event_id ): void {
		if ( ! self::on( 'capacity_alert' ) ) {
			return;
		}
		$state = EPC_Capacity::state( $event_id );
		if ( $state['max'] < 1 ) {
			return;
		}
		$pct       = (int) floor( $state['count'] / $state['max'] * 100 );
		$threshold = max( 1, min( 99, (int) epc_setting( 'epc_capacity_alert_pct', 90 ) ) );
		foreach ( array( 100, $threshold ) as $level ) {
			if ( $pct < $level ) {
				continue;
			}
			$flag = '_epc_alert_' . $level;
			if ( get_post_meta( $event_id, $flag, true ) ) {
				break;
			}
			update_post_meta( $event_id, $flag, time() );
			$title = get_the_title( $event_id );
			$what  = 100 === $level ? 'is now full' : 'has reached ' . $level . '% of capacity';
			$link  = admin_url( 'edit.php?post_type=' . EPC_Post_Types::APPLICANT . '&epc_event=' . $event_id );
			EPC_Mailer::send(
				EPC_Mailer::admin_recipients( $event_id ),
				ucfirst( $title ) . ' ' . $what,
				'<p><strong>' . esc_html( $title ) . '</strong> ' . esc_html( $what ) . ': ' . (int) $state['count'] . ' of ' . (int) $state['max'] . ' seats taken.</p><p><a href="' . esc_url( $link ) . '">View applicants</a></p>',
				'admin'
			);
			break; // One email per registration: the highest level reached.
		}
	}

	/* ------------------------------------------------------------------ */
	/* Countdown default and personal data                                 */
	/* ------------------------------------------------------------------ */

	public static function countdown_default( array $field ): array {
		$field['default_value'] = self::on( 'countdown' ) ? 1 : 0;
		return $field;
	}

	/** True when the current user should see masked personal data. */
	public static function mask_personal_data(): bool {
		return self::on( 'mask_viewers' ) && ! current_user_can( 'edit_others_ep_applicants' );
	}

	/** Mask a value by field type (email / phone / nric); other types are returned as is. */
	public static function mask( string $value, string $type ): string {
		if ( '' === $value ) {
			return $value;
		}
		if ( 'email' === $type && str_contains( $value, '@' ) ) {
			[ $user, $domain ] = explode( '@', $value, 2 );
			return mb_substr( $user, 0, 1 ) . str_repeat( '•', max( 2, min( 6, mb_strlen( $user ) - 1 ) ) ) . '@' . $domain;
		}
		if ( in_array( $type, array( 'phone', 'nric' ), true ) ) {
			$keep = 4;
			$len  = mb_strlen( $value );
			return $len <= $keep ? str_repeat( '•', $len ) : preg_replace( '/[0-9A-Za-z]/', '•', mb_substr( $value, 0, $len - $keep ) ) . mb_substr( $value, -$keep );
		}
		return $value;
	}

	/** Field types keyed by field name for an event (for masking). */
	public static function field_types( int $event_id ): array {
		$types = array();
		foreach ( epc_get_data_fields( $event_id ) as $f ) {
			$types[ $f['name'] ] = $f['type'];
		}
		return $types;
	}
}
