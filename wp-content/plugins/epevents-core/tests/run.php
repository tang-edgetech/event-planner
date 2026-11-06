<?php
/**
 * EP Events Core – automated tests (no PHPUnit needed).
 *
 * Run from the WordPress root:
 *   php tools/wp-cli.phar --path=. eval-file wp-content/plugins/epevents-core/tests/run.php
 *
 * Creates a temporary event (copying the form of the first published event), runs the checks,
 * then deletes everything it created and restores the settings it touched. Emails are captured,
 * never sent. Exits with code 1 when any check fails.
 */

defined( 'ABSPATH' ) || exit( "Run through WP-CLI eval-file.\n" );

$GLOBALS['epc_t'] = array( 'pass' => 0, 'fail' => 0, 'mail' => array() );

function t_ok( bool $cond, string $name, $detail = '' ): void {
	if ( $cond ) {
		++$GLOBALS['epc_t']['pass'];
		echo "  ✔ {$name}\n";
	} else {
		++$GLOBALS['epc_t']['fail'];
		echo "  ✘ {$name}" . ( '' !== $detail ? ' — ' . ( is_string( $detail ) ? $detail : wp_json_encode( $detail ) ) : '' ) . "\n";
	}
}
function t_section( string $s ): void {
	echo "\n{$s}\n";
}
function t_mails( string $subject_contains = '' ): array {
	return array_values( array_filter( $GLOBALS['epc_t']['mail'], fn( $m ) => '' === $subject_contains || str_contains( (string) $m['subject'], $subject_contains ) ) );
}

// Capture all email.
add_filter(
	'pre_wp_mail',
	function ( $null, $atts ) {
		$GLOBALS['epc_t']['mail'][] = $atts;
		return true;
	},
	10,
	2
);

/* ---------------------------------------------------------------------- */
t_section( 'Validator' );

[ $ok, $v ] = EPC_Validator::nric( '900101145678' );
t_ok( $ok && '900101-14-5678' === $v, 'MyKad without dashes is accepted and formatted', $v );
[ $ok ] = EPC_Validator::nric( '901301-14-5678' );
t_ok( ! $ok, 'MyKad with month 13 is rejected' );
[ $ok ] = EPC_Validator::nric( '900101-14-567' );
t_ok( ! $ok, 'MyKad with 11 digits is rejected' );
[ $ok ] = EPC_Validator::nric( 'A1234567', 'any' );
t_ok( $ok, 'Free-format ID is accepted when format is "any"' );

[ $ok, $v ] = EPC_Validator::phone( '012-345 6789', 'my' );
t_ok( $ok && '+60123456789' === $v, 'Malaysian mobile is saved as +60…', $v );
[ $ok ] = EPC_Validator::phone( '12345', 'my' );
t_ok( ! $ok, 'Too-short Malaysian number is rejected' );
[ $ok ] = EPC_Validator::phone( '0123456789', 'intl' );
t_ok( ! $ok, 'International format requires a country code' );

[ $ok, $v ] = EPC_Validator::email( 'Mei.Ling@Hospital.MY' );
t_ok( $ok && 'mei.ling@hospital.my' === $v, 'Email is lower-cased', $v );
[ $ok ] = EPC_Validator::email( 'mei@' );
t_ok( ! $ok, 'Broken email is rejected' );

/* ---------------------------------------------------------------------- */
// Temporary event with a copy of a real form.
// Copy the published form with the most fields (best coverage).
$source = get_posts( array( 'post_type' => EPC_Post_Types::EVENT, 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids' ) );
usort( $source, fn( $a, $b ) => count( epc_get_data_fields( $b ) ) <=> count( epc_get_data_fields( $a ) ) );
if ( ! $source ) {
	echo "No published event to copy the form from.\n";
	exit( 1 );
}
$event = wp_insert_post( array( 'post_type' => EPC_Post_Types::EVENT, 'post_status' => 'publish', 'post_title' => 'Automated test event' ) );
update_field( 'form_fields', get_field( 'form_fields', $source[0], false ), $event );
update_field( 'reg_status', 'auto', $event );
update_field( 'capacity_max', 0, $event );
update_field( 'enable_waitlist', 0, $event );
update_field( 'notify_admin', 1, $event );
update_field( 'notify_applicant', 1, $event );
update_field( 'admin_emails', 'team@example.com', $event );
update_field( 'event_start', '2030-03-01 09:00:00', $event );
update_field( 'event_end', '2030-03-01 17:00:00', $event );
update_field( 'venue', 'Test Hall', $event );

$fields = epc_get_data_fields( $event );
$types  = wp_list_pluck( $fields, 'type', 'name' );

/** A valid submission built from the form's own field definitions. */
function t_post( array $fields, int $n, array $over = array() ): array {
	$p = array( 'epc_ts' => time() - 30, 'epc_hp' => '' );
	foreach ( $fields as $f ) {
		switch ( $f['type'] ) {
			case 'email':
				$v = "tester{$n}@example.com";
				break;
			case 'phone':
				$v = 'intl' === $f['format'] ? '+60 12-345 67' . sprintf( '%02d', $n % 100 ) : '012-345 67' . sprintf( '%02d', $n % 100 );
				break;
			case 'nric':
				$v = '900101-14-' . sprintf( '%04d', $n );
				break;
			case 'country':
				$v = 'Malaysia';
				break;
			case 'consent':
				$v = 'Agreed';
				break;
			case 'checkboxes':
				$v = array( $f['options'][0] ?? 'x' );
				break;
			case 'number':
				$v = '1';
				break;
			case 'date':
				$v = '2030-01-01';
				break;
			case 'select':
			case 'radio':
			case 'salutation':
				$v = $f['options'][0] ?? 'x';
				break;
			case 'heading':
			case 'html':
				continue 2;
			default:
				$v = 'Test ' . $n;
		}
		$p[ $f['name'] ] = $v;
	}
	return array_merge( $p, $over );
}
$email_field = array_search( 'email', $types, true );
$nric_field  = array_search( 'nric', $types, true );
$country     = array_search( 'country', $types, true );

/* ---------------------------------------------------------------------- */
t_section( 'Registration form' );

$r = EPC_Form::process( $event, t_post( $fields, 1, array( 'epc_hp' => 'bot' ) ) );
t_ok( ! $r['ok'], 'Honeypot submission is rejected' );
$r = EPC_Form::process( $event, t_post( $fields, 1, array( 'epc_ts' => time() ) ) );
t_ok( ! $r['ok'], 'Submission faster than 3 seconds is rejected' );

$missing = t_post( $fields, 1 );
unset( $missing[ $email_field ] );
$r = EPC_Form::process( $event, $missing );
t_ok( ! $r['ok'] && isset( $r['errors'][ $email_field ] ), 'Missing required field returns a field error' );

if ( $nric_field ) {
	$r = EPC_Form::process( $event, t_post( $fields, 1, array( $nric_field => '901301-14-0001' ) ) );
	t_ok( ! $r['ok'] && isset( $r['errors'][ $nric_field ] ), 'Invalid NRIC returns a field error' );
}
if ( $nric_field && $country ) {
	$other = t_post( $fields, 50, array( $country => 'Singapore' ) );
	unset( $other[ $nric_field ] );
	$r = EPC_Form::process( $event, $other );
	t_ok( $r['ok'] || ! isset( $r['errors'][ $nric_field ] ), 'NRIC is not required when its condition (Malaysia) is not met', $r['errors'] );
}

$GLOBALS['epc_t']['mail'] = array();
$r = EPC_Form::process( $event, t_post( $fields, 2, array( $email_field => 'Tester2@Example.COM' ) ) );
t_ok( $r['ok'], 'Valid submission is accepted', $r['errors'] ?: $r['message'] );
$ids = get_posts( array( 'post_type' => EPC_Post_Types::APPLICANT, 'numberposts' => 1, 'fields' => 'ids', 'meta_query' => array( array( 'key' => '_epc_event_id', 'value' => $event ), array( 'key' => 'f_' . $email_field, 'value' => 'tester2@example.com' ) ) ) );
$a1  = (int) ( $ids[0] ?? 0 );
t_ok( 'tester2@example.com' === get_post_meta( $a1, 'f_' . $email_field, true ), 'Email is stored lower-cased' );
t_ok( 2 === count( $GLOBALS['epc_t']['mail'] ), 'Admin notification and confirmation are sent', count( $GLOBALS['epc_t']['mail'] ) );

if ( ! empty( wp_list_pluck( $fields, 'unique', 'name' )[ $email_field ] ) ) {
	$r = EPC_Form::process( $event, t_post( $fields, 3, array( $email_field => 'tester2@example.com' ) ) );
	t_ok( ! $r['ok'] && isset( $r['errors'][ $email_field ] ), 'Duplicate email is blocked (field is unique)' );
}

/* ---------------------------------------------------------------------- */
t_section( 'Capacity' );

$seated = EPC_Capacity::count( $event );
update_field( 'capacity_max', $seated + 1, $event );
$r = EPC_Form::process( $event, t_post( $fields, 4 ) );
t_ok( $r['ok'], 'Last seat can be taken' );
t_ok( 'full' === EPC_Capacity::state( $event )['state'], 'Event is "full" when max is reached (no waiting list)' );
$r = EPC_Form::process( $event, t_post( $fields, 5 ) );
t_ok( ! $r['ok'], 'Registration is refused when full' );

update_field( 'enable_waitlist', 1, $event );
t_ok( 'waitlist' === EPC_Capacity::state( $event )['state'], 'State becomes "waitlist" when the waiting list is enabled' );
$GLOBALS['epc_t']['mail'] = array();
$r = EPC_Form::process( $event, t_post( $fields, 6 ) );
$w = get_posts( array( 'post_type' => EPC_Post_Types::APPLICANT, 'numberposts' => 1, 'fields' => 'ids', 'meta_query' => array( array( 'key' => '_epc_event_id', 'value' => $event ), array( 'key' => '_epc_status', 'value' => 'waitlist' ) ) ) );
t_ok( $r['ok'] && $w, 'Extra registration joins the waiting list' );
t_ok( (bool) t_mails( 'waiting list' ), 'Waiting list email is sent (not the normal confirmation)', wp_list_pluck( $GLOBALS['epc_t']['mail'], 'subject' ) );

update_field( 'reg_status', 'closed', $event );
t_ok( 'closed' === EPC_Capacity::state( $event )['state'], 'Manual "Closed" overrides automatic state' );
update_field( 'reg_status', 'auto', $event );

/* ---------------------------------------------------------------------- */
t_section( 'Waiting list features' );

$saved = array();
foreach ( array( 'epc_feature_waitlist', 'epc_feature_capacity_alert', 'epc_capacity_alert_pct', 'epc_feature_ics', 'epc_feature_mask_viewers' ) as $opt ) {
	$saved[ $opt ] = get_field( $opt, 'option', false );
}

update_field( 'epc_feature_waitlist', 'notify', 'option' );
$GLOBALS['epc_t']['mail'] = array();
EPC_Features::set_status( $a1, 'cancelled', 'test' );
t_ok( (bool) t_mails( 'Seat available' ), '"Email the team" mode sends a seat-available email' );
t_ok( 'waitlist' === get_post_meta( $w[0], '_epc_status', true ), '"Email the team" mode leaves the applicant waiting' );

EPC_Features::set_status( $a1, 'registered', 'test' ); // Take the seat back.
update_field( 'epc_feature_waitlist', 'auto', 'option' );
$GLOBALS['epc_t']['mail'] = array();
EPC_Features::set_status( $a1, 'cancelled', 'test' );
t_ok( 'waitlist' !== get_post_meta( $w[0], '_epc_status', true ), 'Automatic mode gives the freed seat to the waiting applicant' );
t_ok( (bool) t_mails( 'seat is now available' ), 'Automatic mode emails the applicant', wp_list_pluck( $GLOBALS['epc_t']['mail'], 'subject' ) );
$log = (array) get_post_meta( $w[0], '_epc_log', true );
t_ok( count( $log ) >= 2, 'Status change and email are written to the activity log', count( $log ) );

/* ---------------------------------------------------------------------- */
t_section( 'Capacity alert' );

update_field( 'epc_feature_waitlist', 'manual', 'option' );
update_field( 'enable_waitlist', 0, $event );
update_field( 'epc_feature_capacity_alert', 1, 'option' );
update_field( 'epc_capacity_alert_pct', 50, 'option' );
update_field( 'capacity_max', EPC_Capacity::count( $event ) + 2, $event );
delete_post_meta( $event, '_epc_alert_50' );
delete_post_meta( $event, '_epc_alert_100' );
$GLOBALS['epc_t']['mail'] = array();
EPC_Form::process( $event, t_post( $fields, 7 ) );
t_ok( (bool) t_mails( '50%' ), 'Alert email when 50% is reached', wp_list_pluck( $GLOBALS['epc_t']['mail'], 'subject' ) );
EPC_Form::process( $event, t_post( $fields, 8 ) );
t_ok( (bool) t_mails( 'is now full' ), 'Alert email when full' );
$before = count( t_mails( '50%' ) );
EPC_Features::capacity_alert( 0, $event );
t_ok( count( t_mails( '50%' ) ) === $before, 'Each alert is sent only once' );

/* ---------------------------------------------------------------------- */
t_section( 'Calendar invite and personal data' );

$ics = EPC_Mailer::ics( $event );
t_ok( str_contains( $ics, 'BEGIN:VEVENT' ) && str_contains( $ics, 'DTSTART:20300301T' ), 'Calendar file has the event start', substr( $ics, 0, 80 ) );
update_field( 'epc_feature_ics', 1, 'option' );
$GLOBALS['epc_t']['mail'] = array();
EPC_Mailer::confirmation( $a1, 'registered' );
$att = $GLOBALS['epc_t']['mail'][0]['attachments'] ?? array();
t_ok( 1 === count( (array) $att ) && str_ends_with( (string) ( (array) $att )[0], '.ics' ), 'Confirmation carries the .ics when the feature is on' );

t_ok( 'm••••••@hospital.my' === EPC_Features::mask( 'meiling@hospital.my', 'email' ), 'Email is masked', EPC_Features::mask( 'meiling@hospital.my', 'email' ) );
t_ok( '••••••-••-5678' === EPC_Features::mask( '900101-14-5678', 'nric' ), 'NRIC is masked except the last 4 digits', EPC_Features::mask( '900101-14-5678', 'nric' ) );
t_ok( 'Dr.' === EPC_Features::mask( 'Dr.', 'salutation' ), 'Other fields are not masked' );

/* ---------------------------------------------------------------------- */
// Clean up.
foreach ( $saved as $opt => $val ) {
	if ( null === $val || false === $val || '' === $val ) {
		delete_field( $opt, 'option' );
	} else {
		update_field( $opt, $val, 'option' );
	}
}
foreach ( get_posts( array( 'post_type' => EPC_Post_Types::APPLICANT, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_epc_event_id', 'meta_value' => $event ) ) as $id ) {
	wp_delete_post( $id, true );
}
wp_delete_post( $event, true );
global $wpdb;
$wpdb->delete( EPC_Analytics::table(), array( 'post_id' => $event ) ); // Conversions recorded by the test registrations.

$t = $GLOBALS['epc_t'];
echo "\n{$t['pass']} passed, {$t['fail']} failed\n";
if ( $t['fail'] ) {
	exit( 1 );
}
