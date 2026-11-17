<?php
/**
 * WP-CLI commands.
 *
 *   wp tamago-smtp test <email> [--text] [--transcript]
 *   wp tamago-smtp check
 *   wp tamago-smtp log list [--status=<sent|failed>] [--limit=<n>] [--format=<format>]
 *   wp tamago-smtp log purge [--days=<n>] [--all] [--yes]
 *   wp tamago-smtp settings export [<file>]
 *   wp tamago-smtp settings import <file>
 *
 * Used by: WP-CLI.
 *
 * @package TamagoSMTP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Send test emails and check the SMTP connection.
 */
class TMG_SMTP_CLI {

	/**
	 * Sends a test email through wp_mail() and prints the result.
	 *
	 * ## OPTIONS
	 *
	 * <email>
	 * : Recipient.
	 *
	 * [--text]
	 * : Send plain text instead of HTML.
	 *
	 * [--transcript]
	 * : Print the SMTP conversation (passwords hidden).
	 *
	 * ## EXAMPLES
	 *
	 *     wp tamago-smtp test you@example.com --transcript
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 */
	public function test( $args, $assoc_args ) {
		$result = TMG_SMTP_Tester::send_test( (string) $args[0], ! empty( $assoc_args['text'] ) ? 'text' : 'html' );
		if ( ! empty( $assoc_args['transcript'] ) && '' !== $result['transcript'] ) {
			WP_CLI::log( $result['transcript'] );
		}
		if ( '' !== $result['hint'] ) {
			WP_CLI::warning( $result['hint'] );
		}
		if ( $result['log_id'] ) {
			WP_CLI::log( 'Log entry: #' . $result['log_id'] );
		}
		if ( $result['ok'] ) {
			WP_CLI::success( $result['message'] );
		} else {
			WP_CLI::error( $result['message'] );
		}
	}

	/**
	 * Connects, starts TLS and logs in without sending an email.
	 *
	 * ## EXAMPLES
	 *
	 *     wp tamago-smtp check
	 *
	 * @param array $args       Unused.
	 * @param array $assoc_args Unused.
	 */
	public function check( $args, $assoc_args ) {
		$result = TMG_SMTP_Tester::check_connection();
		foreach ( $result['steps'] as $step ) {
			WP_CLI::log( ( $step['ok'] ? '[ok]   ' : '[fail] ' ) . $step['label'] . ': ' . $step['detail'] );
		}
		if ( '' !== $result['hint'] ) {
			WP_CLI::warning( $result['hint'] );
		}
		if ( $result['ok'] ) {
			WP_CLI::success( $result['message'] );
		} else {
			WP_CLI::error( $result['message'] );
		}
	}
}

/**
 * Read and clean the email log.
 */
class TMG_SMTP_CLI_Log {

	/**
	 * Lists log entries, newest first.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Only "sent" or "failed" entries.
	 *
	 * [--limit=<n>]
	 * : How many entries.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp tamago-smtp log list --status=failed
	 *
	 * @subcommand list
	 *
	 * @param array $args       Unused.
	 * @param array $assoc_args Flags.
	 */
	public function list_( $args, $assoc_args ) {
		$status = (string) ( $assoc_args['status'] ?? '' );
		if ( '' !== $status && ! in_array( $status, array( 'sent', 'failed' ), true ) ) {
			WP_CLI::error( '--status must be "sent" or "failed".' );
		}
		$result = TMG_SMTP_Log_Store::query(
			array(
				'status'   => $status,
				'per_page' => max( 1, (int) ( $assoc_args['limit'] ?? 20 ) ),
				'orderby'  => 'id',
				'order'    => 'DESC',
			)
		);
		$rows = array();
		foreach ( $result['rows'] as $row ) {
			$rows[] = array(
				'id'       => $row['id'],
				'date_utc' => $row['created_at'],
				'status'   => $row['status'],
				'to'       => $row['to_email'],
				'subject'  => $row['subject'],
				'source'   => $row['source'],
				'ms'       => $row['duration_ms'],
				'error'    => $row['error'],
			);
		}
		$format = (string) ( $assoc_args['format'] ?? 'table' );
		if ( 'count' === $format ) {
			WP_CLI::log( (string) $result['total'] );
			return;
		}
		WP_CLI\Utils\format_items( $format, $rows, array( 'id', 'date_utc', 'status', 'to', 'subject', 'source', 'ms', 'error' ) );
	}

	/**
	 * Deletes old log entries (older than the retention setting, or --days), or all of them.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<n>]
	 * : Delete entries older than this many days instead of the retention setting.
	 *
	 * [--all]
	 * : Delete every entry.
	 *
	 * [--yes]
	 * : Do not ask for confirmation with --all.
	 *
	 * ## EXAMPLES
	 *
	 *     wp tamago-smtp log purge
	 *     wp tamago-smtp log purge --days=7
	 *     wp tamago-smtp log purge --all --yes
	 *
	 * @param array $args       Unused.
	 * @param array $assoc_args Flags.
	 */
	public function purge( $args, $assoc_args ) {
		if ( ! empty( $assoc_args['all'] ) ) {
			WP_CLI::confirm( 'Delete every entry in the email log?', $assoc_args );
			WP_CLI::success( sprintf( '%d entries deleted.', TMG_SMTP_Log_Store::delete_all() ) );
			return;
		}
		$days = isset( $assoc_args['days'] ) ? (int) $assoc_args['days'] : (int) TMG_SMTP_Settings::get( 'log_retention_days' );
		if ( $days <= 0 ) {
			WP_CLI::warning( 'Retention is 0 (keep forever): nothing deleted. Use --days=<n> or --all.' );
			return;
		}
		WP_CLI::success( sprintf( '%d entries older than %d days deleted.', TMG_SMTP_Log_Store::purge( $days ), $days ) );
	}
}

/**
 * Export and import settings as JSON (passwords are never included).
 */
class TMG_SMTP_CLI_Settings {

	/**
	 * Exports the settings as JSON.
	 *
	 * ## OPTIONS
	 *
	 * [<file>]
	 * : Write to this file instead of printing.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Unused.
	 */
	public function export( $args, $assoc_args ) {
		$json = wp_json_encode( TMG_SMTP_Settings::export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( empty( $args[0] ) ) {
			WP_CLI::line( $json );
			return;
		}
		if ( false === file_put_contents( $args[0], $json . "\n" ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			WP_CLI::error( 'Could not write ' . $args[0] );
		}
		WP_CLI::success( 'Settings written to ' . $args[0] );
	}

	/**
	 * Imports settings from a JSON file made by "export". The saved password is kept unless the host
	 * or username changes.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Settings file.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Unused.
	 */
	public function import( $args, $assoc_args ) {
		$file = (string) $args[0];
		if ( ! is_readable( $file ) || filesize( $file ) > TMG_SMTP_Settings::IMPORT_MAX ) {
			WP_CLI::error( 'Cannot read ' . $file . ' (or it is larger than 100 KB).' );
		}
		$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $data ) ) {
			WP_CLI::error( 'The file is not valid JSON.' );
		}
		$result = TMG_SMTP_Settings::import( $data );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		WP_CLI::success( sprintf( 'Imported %d settings.', count( $result ) ) );
	}
}

WP_CLI::add_command( 'tamago-smtp', 'TMG_SMTP_CLI' );
WP_CLI::add_command( 'tamago-smtp log', 'TMG_SMTP_CLI_Log' );
WP_CLI::add_command( 'tamago-smtp settings', 'TMG_SMTP_CLI_Settings' );
