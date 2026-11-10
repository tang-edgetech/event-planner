<?php
/**
 * All outgoing emails: admin notification, applicant confirmation (registered / waiting list /
 * seat offered), capacity alerts, and the optional calendar (.ics) attachment.
 * Every body passes through the `epc_email_html` filter, which the theme uses for branding.
 *
 * Screens: Event edit → After Submit (notification and confirmation emails); Event Plus → Settings → Features (waiting-list and seat-offer texts).
 */

defined( 'ABSPATH' ) || exit;

class EPC_Mailer {

	public static function headers(): array {
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$from    = epc_setting( 'epc_mail_from_name', get_bloginfo( 'name' ) );
		$from_em = epc_setting( 'epc_mail_from_email', '' );
		if ( $from_em ) {
			$headers[] = 'From: ' . $from . ' <' . $from_em . '>';
		}
		return $headers;
	}

	/** Event notification recipients (event override, else Settings default, else site admin). */
	public static function admin_recipients( int $event_id ): array {
		return epc_lines( str_replace( ',', "\n", (string) ( get_field( 'admin_emails', $event_id ) ?: epc_setting( 'epc_admin_emails', get_option( 'admin_email' ) ) ) ) );
	}

	public static function send( $to, string $subject, string $html, string $kind, array $attachments = array() ): bool {
		if ( ! $to ) {
			return false;
		}
		return (bool) wp_mail( $to, wp_strip_all_tags( $subject ), apply_filters( 'epc_email_html', $html, $kind ), self::headers(), $attachments );
	}

	/** Answers as an HTML table, in form order. */
	public static function details_table( array $data, array $fields ): string {
		$rows = '';
		foreach ( $fields as $f ) {
			if ( array_key_exists( $f['name'], $data ) ) {
				$v     = $data[ $f['name'] ];
				$rows .= '<tr><th align="left" style="padding:4px 12px 4px 0;vertical-align:top">' . esc_html( wp_strip_all_tags( $f['label'] ) ) . '</th><td style="padding:4px 0">' . esc_html( is_array( $v ) ? implode( ', ', $v ) : (string) $v ) . '</td></tr>';
			}
		}
		return '<table cellspacing="0" cellpadding="0">' . $rows . '</table>';
	}

	/** First email answer on the form. */
	public static function applicant_email( array $data, array $fields ): string {
		foreach ( $fields as $f ) {
			if ( 'email' === $f['type'] && ! empty( $data[ $f['name'] ] ) && is_email( $data[ $f['name'] ] ) ) {
				return (string) $data[ $f['name'] ];
			}
		}
		return '';
	}

	/** Stored answers of an applicant + the event's current data fields. */
	private static function applicant( int $applicant_id ): array {
		$event  = (int) get_post_meta( $applicant_id, '_epc_event_id', true );
		$data   = (array) get_post_meta( $applicant_id, '_epc_data', true );
		$fields = epc_get_data_fields( $event );
		return array( $event, $data, $fields );
	}

	/** Called after a public registration. */
	public static function registered( int $applicant_id ): void {
		[ $event, $data, $fields ] = self::applicant( $applicant_id );
		if ( get_field( 'notify_admin', $event ) ) {
			$link = admin_url( 'post.php?action=edit&post=' . $applicant_id );
			$body = '<p>A new registration was received for <strong>' . esc_html( get_the_title( $event ) ) . '</strong>.</p>' . self::details_table( $data, $fields ) . '<p><a href="' . esc_url( $link ) . '">View applicant</a></p>';
			self::send( self::admin_recipients( $event ), 'New registration: ' . get_the_title( $event ), $body, 'admin' );
		}
		if ( get_field( 'notify_applicant', $event ) ) {
			// At most 5 automatic confirmations per address per hour, so the public form cannot be used to
			// flood someone's inbox. Staff can still resend from the applicant screen.
			$key  = 'epc_mail_' . md5( strtolower( (string) self::applicant_email( $data, $fields ) ) );
			$sent = (int) get_transient( $key );
			if ( $sent >= 5 ) {
				return;
			}
			set_transient( $key, $sent + 1, HOUR_IN_SECONDS );
			$status = get_post_meta( $applicant_id, '_epc_status', true );
			self::confirmation( $applicant_id, 'waitlist' === $status ? 'waitlist' : 'registered' );
		}
	}

	/**
	 * Email the applicant. $kind: registered | waitlist | offer (a seat became free).
	 * Returns true when an email was handed to wp_mail.
	 */
	public static function confirmation( int $applicant_id, string $kind = 'registered' ): bool {
		[ $event, $data, $fields ] = self::applicant( $applicant_id );
		$email = self::applicant_email( $data, $fields );
		if ( ! $email ) {
			return false;
		}
		$defaults = array(
			'registered' => array( 'Registration received: {event_title}', '<p>Dear {salutation} {fullname},</p><p>Thank you for registering for <strong>{event_title}</strong>.</p><p>{event_date} {event_time}<br>{event_venue}</p>{registration_details}' ),
			'waitlist'   => array( 'You are on the waiting list: {event_title}', '<p>Dear {salutation} {fullname},</p><p>Thank you for your interest in <strong>{event_title}</strong>. The event is currently full, so you have been placed on the waiting list. We will email you if a seat becomes available.</p>{registration_details}' ),
			'offer'      => array( 'A seat is now available: {event_title}', '<p>Dear {salutation} {fullname},</p><p>Good news: a seat has become available for <strong>{event_title}</strong> and your registration is now confirmed.</p><p>{event_date} {event_time}<br>{event_venue}</p>{registration_details}' ),
		);
		$kind = isset( $defaults[ $kind ] ) ? $kind : 'registered';
		if ( 'registered' === $kind ) {
			$subject = (string) ( get_field( 'email_subject', $event ) ?: $defaults['registered'][0] );
			$body    = (string) ( get_field( 'email_body', $event ) ?: $defaults['registered'][1] );
		} elseif ( 'waitlist' === $kind ) {
			$subject = (string) epc_setting( 'epc_waitlist_subject', $defaults['waitlist'][0] );
			$body    = (string) epc_setting( 'epc_waitlist_body', $defaults['waitlist'][1] );
		} else {
			$subject = (string) epc_setting( 'epc_offer_subject', $defaults['offer'][0] );
			$body    = (string) epc_setting( 'epc_offer_body', $defaults['offer'][1] );
		}
		$data_ph                  = $data;
		$data_ph['calendar_link'] = self::google_calendar_url( $event );
		$subject                  = epc_replace_placeholders( $subject, $data_ph, $event );
		$body                     = epc_replace_placeholders( $body, $data_ph, $event );
		$body                     = str_replace( '{registration_details}', self::details_table( $data, $fields ), $body );

		$attachments = array();
		if ( 'waitlist' !== $kind && epc_setting( 'epc_feature_ics', false ) ) {
			$ics = self::ics_file( $event );
			if ( $ics ) {
				$attachments[] = $ics;
				$body         .= '<p><a href="' . esc_url( $data_ph['calendar_link'] ) . '">Add to Google Calendar</a> · the attached file works with Outlook and Apple Calendar.</p>';
			}
		}
		$sent = self::send( $email, $subject, $body, 'applicant', $attachments );
		foreach ( $attachments as $a ) {
			wp_delete_file( $a );
		}
		return $sent;
	}

	/* ------------------------------------------------------------------ */
	/* Calendar                                                            */
	/* ------------------------------------------------------------------ */

	/** [start, end] timestamps (UTC) of an event, or null without a start date. */
	private static function times( int $event_id ): ?array {
		$start = (string) get_field( 'event_start', $event_id );
		if ( ! $start ) {
			return null;
		}
		$tz  = wp_timezone();
		$s   = ( new DateTime( $start, $tz ) )->getTimestamp();
		$end = (string) get_field( 'event_end', $event_id );
		$e   = $end ? ( new DateTime( $end, $tz ) )->getTimestamp() : $s + 3 * HOUR_IN_SECONDS;
		return array( $s, max( $e, $s + 1800 ) );
	}

	public static function google_calendar_url( int $event_id ): string {
		$t = self::times( $event_id );
		if ( ! $t ) {
			return '';
		}
		return add_query_arg(
			array(
				'action'   => 'TEMPLATE',
				'text'     => rawurlencode( get_the_title( $event_id ) ),
				'dates'    => gmdate( 'Ymd\THis\Z', $t[0] ) . '/' . gmdate( 'Ymd\THis\Z', $t[1] ),
				'location' => rawurlencode( (string) get_field( 'venue', $event_id ) ),
				'details'  => rawurlencode( get_permalink( $event_id ) ),
			),
			'https://calendar.google.com/calendar/render'
		);
	}

	public static function ics( int $event_id ): string {
		$t = self::times( $event_id );
		if ( ! $t ) {
			return '';
		}
		$esc   = fn( string $s ) => str_replace( array( '\\', ';', ',', "\r\n", "\n" ), array( '\\\\', '\;', '\,', '\n', '\n' ), $s );
		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//' . $esc( get_bloginfo( 'name' ) ) . '//Event Plus//EN',
			'METHOD:PUBLISH',
			'BEGIN:VEVENT',
			'UID:event-' . $event_id . '@' . wp_parse_url( home_url(), PHP_URL_HOST ),
			'DTSTAMP:' . gmdate( 'Ymd\THis\Z' ),
			'DTSTART:' . gmdate( 'Ymd\THis\Z', $t[0] ),
			'DTEND:' . gmdate( 'Ymd\THis\Z', $t[1] ),
			'SUMMARY:' . $esc( get_the_title( $event_id ) ),
			'LOCATION:' . $esc( (string) get_field( 'venue', $event_id ) ),
			'URL:' . get_permalink( $event_id ),
			'END:VEVENT',
			'END:VCALENDAR',
		);
		return implode( "\r\n", $lines ) . "\r\n";
	}

	/** Write the .ics to a temporary file for wp_mail; caller deletes it. */
	private static function ics_file( int $event_id ): string {
		$ics = self::ics( $event_id );
		if ( ! $ics ) {
			return '';
		}
		$dir = trailingslashit( get_temp_dir() ) . 'epc-' . wp_generate_password( 8, false );
		wp_mkdir_p( $dir );
		$file = $dir . '/' . sanitize_file_name( get_post_field( 'post_name', $event_id ) ?: 'event' ) . '.ics';
		return false !== file_put_contents( $file, $ics ) ? $file : ''; // phpcs:ignore
	}
}
