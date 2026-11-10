<?php
/**
 * Email log list (WP_List_Table): search, filters (status, date range, source), sorting, paging, bulk delete.
 *
 * Screens: Settings → SMTP → Logs.
 *
 * @package TamagoSMTP
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class TMG_SMTP_Logs_Table extends WP_List_Table {

	/** @var array Filters read from the request. */
	private $filters;

	public function __construct( array $filters ) {
		$this->filters = $filters;
		parent::__construct(
			array(
				'singular' => 'tmg_smtp_log',
				'plural'   => 'tmg_smtp_logs',
				'ajax'     => false,
			)
		);
	}

	public function get_columns(): array {
		return array(
			'cb'          => '<input type="checkbox">',
			'created_at'  => __( 'Date', 'tamago-smtp' ),
			'status'      => __( 'Status', 'tamago-smtp' ),
			'to_email'    => __( 'To', 'tamago-smtp' ),
			'subject'     => __( 'Subject', 'tamago-smtp' ),
			'source'      => __( 'Sent by', 'tamago-smtp' ),
			'mailer'      => __( 'Mailer', 'tamago-smtp' ),
			'duration_ms' => __( 'Time', 'tamago-smtp' ),
		);
	}

	protected function get_sortable_columns(): array {
		return array(
			'created_at'  => array( 'created_at', true ),
			'status'      => array( 'status', false ),
			'to_email'    => array( 'to_email', false ),
			'subject'     => array( 'subject', false ),
			'source'      => array( 'source', false ),
			'mailer'      => array( 'mailer', false ),
			'duration_ms' => array( 'duration_ms', false ),
		);
	}

	protected function get_default_primary_column_name(): string {
		return 'subject';
	}

	protected function get_bulk_actions(): array {
		return array( 'delete' => __( 'Delete', 'tamago-smtp' ) );
	}

	public function prepare_items(): void {
		$per_page = $this->get_items_per_page( 'tmg_smtp_logs_per_page', 20 );
		$result   = TMG_SMTP_Log_Store::query(
			array_merge(
				$this->filters,
				array(
					'per_page' => $per_page,
					'page'     => $this->get_pagenum(),
				)
			)
		);
		$this->items           = $result['rows'];
		$this->_column_headers = array( $this->get_columns(), get_hidden_columns( $this->screen ), $this->get_sortable_columns(), 'subject' );
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $result['total'] / $per_page ),
			)
		);
	}

	public function no_items(): void {
		if ( ! TMG_SMTP_Settings::get( 'log_enabled' ) ) {
			esc_html_e( 'No emails found. Logging is switched off on the Settings tab.', 'tamago-smtp' );
			return;
		}
		esc_html_e( 'No emails found.', 'tamago-smtp' );
	}

	protected function column_cb( $item ): string {
		return sprintf(
			'<label class="screen-reader-text" for="tmg-smtp-log-%1$d">%2$s</label><input type="checkbox" id="tmg-smtp-log-%1$d" name="log_ids[]" value="%1$d">',
			(int) $item['id'],
			esc_html__( 'Select entry', 'tamago-smtp' )
		);
	}

	protected function column_created_at( $item ): string {
		$time = strtotime( $item['created_at'] . ' UTC' );
		return sprintf(
			'<time datetime="%1$s" title="%2$s">%3$s</time>',
			esc_attr( gmdate( 'c', $time ) ),
			esc_attr( $item['created_at'] . ' UTC' ),
			esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) )
		);
	}

	protected function column_status( $item ): string {
		return TMG_SMTP_Logs_Screen::status_badge( (string) $item['status'] ) . ( (int) $item['resend_of'] ? ' <span class="tmg-smtp-muted">' . esc_html__( 'resend', 'tamago-smtp' ) . '</span>' : '' );
	}

	protected function column_to_email( $item ): string {
		$to = (string) $item['to_email'];
		return '<span title="' . esc_attr( $to ) . '">' . esc_html( mb_strimwidth( $to, 0, 60, '…' ) ) . '</span>';
	}

	protected function column_subject( $item ): string {
		$url     = TMG_SMTP_Admin::page_url( 'logs', array( 'log' => (int) $item['id'] ) );
		$subject = '' !== (string) $item['subject'] ? (string) $item['subject'] : __( '(no subject)', 'tamago-smtp' );
		$out     = '<strong><a class="row-title" href="' . esc_url( $url ) . '">' . esc_html( mb_strimwidth( $subject, 0, 120, '…' ) ) . '</a></strong>';
		if ( 'failed' === $item['status'] && '' !== (string) $item['error'] ) {
			$out .= '<div class="tmg-smtp-error-line">' . esc_html( mb_strimwidth( (string) $item['error'], 0, 160, '…' ) ) . '</div>';
		}
		$actions = array(
			'view'   => '<a href="' . esc_url( $url ) . '">' . esc_html__( 'View', 'tamago-smtp' ) . '</a>',
			'delete' => '<a class="tmg-smtp-confirm-one" href="' . esc_url( TMG_SMTP_Logs_Screen::delete_url( (int) $item['id'] ) ) . '">' . esc_html__( 'Delete', 'tamago-smtp' ) . '</a>',
		);
		return $out . $this->row_actions( $actions );
	}

	protected function column_source( $item ): string {
		$source = (string) $item['source'];
		return '<span title="' . esc_attr( (string) $item['source_function'] ) . '">' . esc_html( TMG_SMTP_Logs_Screen::source_label( $source ) ) . '</span><br><span class="tmg-smtp-muted">' . esc_html( TMG_SMTP_Logs_Screen::context_label( (string) $item['context'] ) ) . '</span>';
	}

	protected function column_mailer( $item ): string {
		return esc_html( (string) $item['mailer'] );
	}

	protected function column_duration_ms( $item ): string {
		/* translators: %s: milliseconds */
		return esc_html( sprintf( __( '%s ms', 'tamago-smtp' ), number_format_i18n( (int) $item['duration_ms'] ) ) );
	}

	protected function column_default( $item, $column_name ): string {
		return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
	}

	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}
		$f = $this->filters;
		echo '<div class="alignleft actions tmg-smtp-filters">';
		echo '<label class="screen-reader-text" for="tmg-smtp-filter-status">' . esc_html__( 'Status', 'tamago-smtp' ) . '</label>';
		echo '<select name="status" id="tmg-smtp-filter-status">';
		echo '<option value="">' . esc_html__( 'All statuses', 'tamago-smtp' ) . '</option>';
		foreach ( array( 'sent' => __( 'Sent', 'tamago-smtp' ), 'failed' => __( 'Failed', 'tamago-smtp' ) ) as $value => $label ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $value ), selected( $f['status'], $value, false ), esc_html( $label ) );
		}
		echo '</select>';

		echo '<label class="screen-reader-text" for="tmg-smtp-filter-source">' . esc_html__( 'Sent by', 'tamago-smtp' ) . '</label>';
		echo '<select name="source" id="tmg-smtp-filter-source">';
		echo '<option value="">' . esc_html__( 'All senders', 'tamago-smtp' ) . '</option>';
		foreach ( TMG_SMTP_Log_Store::sources() as $source ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $source ), selected( $f['source'], $source, false ), esc_html( TMG_SMTP_Logs_Screen::source_label( $source ) ) );
		}
		echo '</select>';

		printf(
			'<label for="tmg-smtp-filter-from">%1$s</label> <input type="date" id="tmg-smtp-filter-from" name="date_from" value="%2$s"> <label for="tmg-smtp-filter-to">%3$s</label> <input type="date" id="tmg-smtp-filter-to" name="date_to" value="%4$s">',
			esc_html__( 'From', 'tamago-smtp' ),
			esc_attr( $f['date_from_local'] ),
			esc_html__( 'to', 'tamago-smtp' ),
			esc_attr( $f['date_to_local'] )
		);
		submit_button( __( 'Filter', 'tamago-smtp' ), '', 'filter_action', false );
		echo '</div>';
	}
}
