<?php
/**
 * Applicants in and out of Excel / CSV / JSON, through Tamago Import & Export.
 *
 * - "Applicants" data source: export one row per applicant with one column per form field, and
 *   import applicant lists from any system (old website, Google Forms, Excel) into an event, with
 *   the column-mapping step of Tamago Import & Export.
 * - The Export buttons above the Applicants list download the filtered list directly.
 *
 * Events, microsites, pages, settings and ACF structure are exported with Tamago Import & Export's
 * own options (it handles ACF fields and media), so nothing else is needed here.
 *
 * Screens: Event Plus → Import & Export (source "Applicants"); Applicants list (Export buttons).
 */

defined( 'ABSPATH' ) || exit;

class EPC_Applicants_IO {

	public static function init(): void {
		add_filter( 'tmg_io_sources', array( __CLASS__, 'source' ) );
		add_action( 'admin_post_epc_export', array( __CLASS__, 'list_export' ) );
	}

	public static function source( array $sources ): array {
		$sources['epc_applicants'] = array(
			'label'      => 'Applicants (one column per form field)',
			'capability' => 'epc_export_applicants',
			'formats'    => array( 'xlsx', 'csv', 'json' ),
			'fields'     => array( __CLASS__, 'fields' ),
			'export'     => fn( array $c ) => array( 'Applicants' => self::table( self::rows( absint( $c['event'] ?? 0 ), sanitize_key( $c['status'] ?? '' ) ) ) ),
			'import'     => array( __CLASS__, 'import' ),
			'columns'    => array( __CLASS__, 'columns' ),
		);
		return $sources;
	}

	/** Options form: event (+ status when exporting, duplicate rule when importing). */
	public static function fields( string $context ): string {
		$events = get_posts( array( 'post_type' => EPC_Post_Types::EVENT, 'numberposts' => -1, 'post_status' => 'any', 'orderby' => 'title', 'order' => 'ASC' ) );
		$html   = '<p><label>Event<br><select name="choices[event]"' . ( 'import' === $context ? ' required' : '' ) . '>';
		$html  .= '<option value="">' . ( 'import' === $context ? '— choose the event to import into —' : 'All events (one column per field)' ) . '</option>';
		foreach ( $events as $e ) {
			$html .= '<option value="' . (int) $e->ID . '">' . esc_html( $e->post_title ) . '</option>';
		}
		$html .= '</select></label></p>';
		if ( 'export' === $context ) {
			$html .= '<p><label>Status<br><select name="choices[status]"><option value="">Any</option>';
			foreach ( epc_applicant_statuses() as $k => $l ) {
				$html .= '<option value="' . esc_attr( $k ) . '">' . esc_html( $l ) . '</option>';
			}
			$html .= '</select></label></p>';
		} else {
			$html .= '<p><label><input type="checkbox" name="choices[skip_dupes]" value="1" checked> Skip rows that duplicate a "unique" field (e.g. email / NRIC) already registered</label></p>';
		}
		return $html;
	}

	/** Mapping targets: the event's form fields plus status, date and "extra data". */
	public static function columns( array $choices ): array {
		$cols = array(
			'__status' => 'Applicant status',
			'__date'   => 'Registration date',
			'__extra'  => 'Keep as extra data',
		);
		foreach ( epc_get_data_fields( absint( $choices['event'] ?? 0 ) ) as $f ) {
			$cols[ $f['name'] ] = ( wp_strip_all_tags( $f['label'] ) ?: $f['name'] ) . ' (' . $f['name'] . ')';
		}
		return $cols;
	}

	/**
	 * Import one chunk of mapped rows into the chosen event.
	 *
	 * @param array $rows    Records: column heading => value.
	 * @param array $mapping Column heading => target key from columns().
	 * @param array $opts    choices, dry_run, existing, offset, user_id.
	 */
	public static function import( array $rows, array $mapping, array $opts ): array {
		$event = absint( $opts['choices']['event'] ?? 0 );
		if ( ! $event || EPC_Post_Types::EVENT !== get_post_type( $event ) ) {
			return array( 'errors' => array( 'event' => 'Choose the event to import these applicants into.' ) );
		}
		$fields = epc_get_data_fields( $event );
		$names  = array_column( $fields, 'name' );
		$unique = array_column( array_filter( $fields, fn( $f ) => ! empty( $f['unique'] ) ), 'name' );
		$skip   = ! empty( $opts['choices']['skip_dupes'] );
		$report = array( 'created' => 0, 'skipped' => 0, 'errors' => array() );

		foreach ( array_values( $rows ) as $i => $r ) {
			if ( ! array_filter( (array) $r, fn( $v ) => '' !== trim( (string) $v ) ) ) {
				continue;
			}
			$data   = array();
			$labels = $fields;
			$status = 'registered';
			$date   = '';
			foreach ( (array) $r as $heading => $v ) {
				$to = (string) ( $mapping[ $heading ] ?? '' );
				$v  = sanitize_text_field( (string) $v );
				if ( '' === $to ) {
					continue;
				}
				if ( '__status' === $to ) {
					$s      = sanitize_key( strtolower( $v ) );
					$status = isset( epc_applicant_statuses()[ $s ] ) ? $s : $status;
				} elseif ( '__date' === $to ) {
					$ts   = is_numeric( $v ) && $v < 100000 ? (int) round( ( (float) $v - 25569 ) * 86400 ) : strtotime( $v ); // Excel serial or text date.
					$date = $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : '';
				} elseif ( '__extra' === $to || ! in_array( $to, $names, true ) ) {
					$key            = sanitize_title( (string) $heading ) ?: 'col-' . $i;
					$data[ $key ]   = $v;
					$labels[]       = array( 'name' => $key, 'label' => (string) $heading );
				} else {
					$data[ $to ] = $v;
				}
			}
			if ( $skip ) {
				foreach ( $unique as $u ) {
					if ( ! empty( $data[ $u ] ) && EPC_Form::exists( $event, $u, (string) $data[ $u ] ) ) {
						++$report['skipped'];
						continue 2;
					}
				}
			}
			if ( ! empty( $opts['dry_run'] ) ) {
				++$report['created'];
				continue;
			}
			if ( EPC_Form::store( $event, $data, $labels, $status, array(), $date ) ) {
				++$report['created'];
			} else {
				$report['errors'][ $i ] = 'Could not save this row.';
			}
		}
		return $report;
	}

	/** Export buttons above the Applicants list (filtered by event and status). */
	public static function list_export(): void {
		check_admin_referer( 'epc_export' );
		if ( ! current_user_can( 'epc_export_applicants' ) || ! function_exists( 'tmg_io_send_download' ) ) {
			wp_die( 'Not allowed.', 'Not allowed', array( 'response' => 403, 'back_link' => true ) );
		}
		$event  = absint( $_GET['event'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		$status = sanitize_key( $_GET['status'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		$format = sanitize_key( $_GET['format'] ?? 'xlsx' ); // phpcs:ignore WordPress.Security.NonceVerification
		$name   = 'applicants-' . ( $event ? sanitize_title( get_the_title( $event ) ) : 'all' ) . '-' . wp_date( 'Ymd-His' );
		tmg_io_send_download( array( 'Applicants' => self::table( self::rows( $event, $status ) ) ), $name, $format );
	}

	/** Applicant rows with one column per form field (union across events), masked like on screen. */
	public static function rows( int $event, string $status ): array {
		$meta = array();
		if ( $event ) {
			$meta[] = array( 'key' => '_epc_event_id', 'value' => $event );
		}
		if ( $status ) {
			$meta[] = array( 'key' => '_epc_status', 'value' => $status );
		}
		$ids = get_posts(
			array(
				'post_type'   => EPC_Post_Types::APPLICANT,
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_query'  => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'     => 'date',
				'order'       => 'ASC',
			)
		);

		// Column order: the event's current fields first, then anything else found.
		$cols = array();
		if ( $event ) {
			foreach ( epc_get_data_fields( $event ) as $f ) {
				$cols[ $f['name'] ] = wp_strip_all_tags( $f['label'] ) ?: $f['name'];
			}
		}
		$mask  = EPC_Features::mask_personal_data();
		$types = array();
		$found = array();
		foreach ( $ids as $id ) {
			$data   = (array) get_post_meta( $id, '_epc_data', true );
			$labels = (array) get_post_meta( $id, '_epc_labels', true );
			foreach ( $data as $k => $v ) {
				if ( ! isset( $cols[ $k ] ) ) {
					$cols[ $k ] = $labels[ $k ] ?? $k;
				}
			}
			$ev = (int) get_post_meta( $id, '_epc_event_id', true );
			if ( $mask ) {
				$types[ $ev ] = $types[ $ev ] ?? EPC_Features::field_types( $ev );
				foreach ( $data as $k => $v ) {
					if ( is_string( $v ) && isset( $types[ $ev ][ $k ] ) ) {
						$data[ $k ] = EPC_Features::mask( $v, $types[ $ev ][ $k ] );
					}
				}
			}
			$found[] = array( $id, $ev, $data, (array) get_post_meta( $id, '_epc_source', true ) );
		}
		$out = array();
		foreach ( $found as [ $id, $ev, $data, $src ] ) {
			$row = array(
				'Applicant ID' => $id,
				'Event'        => get_the_title( $ev ),
				'Event ID'     => $ev,
				'Status'       => get_post_meta( $id, '_epc_status', true ) ?: 'registered',
				'Registered'   => get_the_date( 'Y-m-d H:i:s', $id ),
			);
			foreach ( $cols as $k => $label ) {
				$v           = $data[ $k ] ?? '';
				$key         = isset( $row[ $label ] ) ? $label . ' (' . $k . ')' : $label; // Duplicate labels.
				$row[ $key ] = is_array( $v ) ? implode( ', ', $v ) : (string) $v;
			}
			$row['Source']       = $src['ref_domain'] ?? '';
			$row['UTM source']   = $src['utm_source'] ?? '';
			$row['UTM campaign'] = $src['utm_campaign'] ?? '';
			$out[]               = $row;
		}
		return $out;
	}

	/** Associative rows → header row + value rows. */
	public static function table( array $rows ): array {
		$cols = array();
		foreach ( $rows as $r ) {
			$cols += array_fill_keys( array_keys( $r ), true );
		}
		$cols  = array_keys( $cols );
		$table = array( $cols ?: array( 'Applicant ID' ) );
		foreach ( $rows as $r ) {
			$table[] = array_map( fn( $c ) => $r[ $c ] ?? '', $cols );
		}
		return $table;
	}
}
