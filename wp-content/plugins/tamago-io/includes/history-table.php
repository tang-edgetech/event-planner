<?php
/**
 * History list (WP_List_Table): imports, dry runs and exports with their results, report
 * downloads, "Continue" for unfinished imports. Users see their own runs; managers see all.
 *
 * Screens: Import & Export → History.
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class TMG_IO_History_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'tmg-io-run',
				'plural'   => 'tmg-io-runs',
				'ajax'     => false,
			)
		);
	}

	public function get_columns(): array {
		return array(
			'created_at' => __( 'Date', 'tamago-io' ),
			'kind'       => __( 'Type', 'tamago-io' ),
			'source'     => __( 'File / source', 'tamago-io' ),
			'user'       => __( 'By', 'tamago-io' ),
			'status'     => __( 'Status', 'tamago-io' ),
			'summary'    => __( 'Result', 'tamago-io' ),
			'actions'    => '',
		);
	}

	public function prepare_items(): void {
		$per  = 20;
		$page = $this->get_pagenum();
		list( $rows, $total ) = TMG_IO_Runs::query(
			array(
				'user_id'  => current_user_can( TMG_IO_Permissions::MANAGE ) ? 0 : get_current_user_id(),
				'per_page' => $per,
				'page'     => $page,
			)
		);
		$this->items           = $rows;
		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per,
			)
		);
	}

	public function no_items(): void {
		esc_html_e( 'No imports or exports yet.', 'tamago-io' );
	}

	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'created_at':
				return esc_html( get_date_from_gmt( (string) $item['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) );
			case 'kind':
				if ( 'export' === $item['kind'] ) {
					return esc_html__( 'Export', 'tamago-io' ) . ' <code>' . esc_html( strtoupper( (string) $item['format'] ) ) . '</code>';
				}
				return esc_html( $item['dry_run'] ? __( 'Dry run', 'tamago-io' ) : __( 'Import', 'tamago-io' ) ) . ' <code>' . esc_html( strtoupper( (string) $item['format'] ) ) . '</code>';
			case 'source':
				return esc_html( (string) $item['source'] );
			case 'user':
				$u = get_userdata( (int) $item['user_id'] );
				return esc_html( $u ? $u->display_name : '#' . (int) $item['user_id'] );
			case 'status':
				$labels = array(
					'uploaded'  => __( 'Uploaded, not started', 'tamago-io' ),
					'running'   => __( 'Unfinished', 'tamago-io' ),
					'done'      => __( 'Done', 'tamago-io' ),
					'failed'    => __( 'Failed', 'tamago-io' ),
					'cancelled' => __( 'Cancelled', 'tamago-io' ),
				);
				return esc_html( $labels[ $item['status'] ] ?? (string) $item['status'] );
			case 'summary':
				return $this->summary( $item );
			case 'actions':
				return $this->actions( $item );
		}
		return '';
	}

	private function summary( array $item ): string {
		$s = (array) $item['summary'];
		if ( 'export' === $item['kind'] ) {
			$parts = array();
			foreach ( $s as $k => $n ) {
				if ( (int) $n ) {
					$parts[] = esc_html( $k . ': ' . (int) $n );
				}
			}
			return implode( ', ', $parts );
		}
		$tot = array();
		foreach ( (array) ( $s['counts'] ?? array() ) as $c ) {
			foreach ( (array) $c as $a => $n ) {
				if ( 'note' !== $a ) {
					$tot[ $a ] = ( $tot[ $a ] ?? 0 ) + (int) $n;
				}
			}
		}
		$parts = array();
		foreach ( $tot as $a => $n ) {
			$parts[] = esc_html( $a . ': ' . $n );
		}
		if ( 'running' === $item['status'] && ! empty( $s['total'] ) ) {
			/* translators: 1: done, 2: total */
			$parts[] = esc_html( sprintf( __( '%1$d of %2$d', 'tamago-io' ), (int) $s['done'], (int) $s['total'] ) );
		}
		return implode( ', ', $parts );
	}

	private function actions( array $item ): string {
		$id  = (int) $item['id'];
		$out = array();
		if ( 'import' === $item['kind'] ) {
			if ( 'uploaded' === $item['status'] ) {
				$out[] = sprintf( '<a class="button button-small" href="%s">%s</a>', esc_url( TMG_IO_Admin_Screen::url( array( 'tab' => 'import', 'step' => 'preview', 'run' => $id ) ) ), esc_html__( 'Continue', 'tamago-io' ) );
			} elseif ( 'running' === $item['status'] ) {
				$out[] = sprintf( '<a class="button button-small" href="%s">%s</a>', esc_url( TMG_IO_Admin_Screen::url( array( 'tab' => 'import', 'step' => 'run', 'run' => $id ) ) ), esc_html__( 'Continue', 'tamago-io' ) );
				$out[] = sprintf( '<a href="%s">%s</a>', esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tmg_io_cancel&run=' . $id ), 'tmg_io_run' ) ), esc_html__( 'Cancel', 'tamago-io' ) );
			} else {
				$out[] = sprintf( '<a href="%s">%s</a>', esc_url( TMG_IO_Admin_Screen::url( array( 'tab' => 'import', 'step' => 'run', 'run' => $id ) ) ), esc_html__( 'View', 'tamago-io' ) );
				$out[] = sprintf( '<a href="%s">%s</a>', esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tmg_io_report&format=csv&run=' . $id ), 'tmg_io_run' ) ), esc_html__( 'Report', 'tamago-io' ) );
			}
		}
		if ( 'running' !== $item['status'] ) {
			$out[] = sprintf( '<a class="submitdelete" href="%s">%s</a>', esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tmg_io_run_delete&run=' . $id ), 'tmg_io_run' ) ), esc_html__( 'Delete', 'tamago-io' ) );
		}
		return implode( ' ', $out );
	}
}
