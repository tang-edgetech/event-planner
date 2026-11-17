<?php
/**
 * WordPress privacy tools: personal data exporter and eraser for email log entries.
 *
 * An entry matches when the address appears in To, Cc, Bcc, From or Reply-To (exact address match).
 *
 * Screens: Tools → Export Personal Data, Tools → Erase Personal Data.
 *
 * @package TamagoSMTP
 */

defined( 'ABSPATH' ) || exit;

class TMG_SMTP_Privacy {

	const PER_PAGE = 50;

	public static function init(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		add_action( 'admin_init', array( __CLASS__, 'policy_text' ) );
	}

	public static function register_exporter( $exporters ): array {
		$exporters                = (array) $exporters;
		$exporters['tamago-smtp'] = array(
			'exporter_friendly_name' => __( 'Email log (Tamago SMTP)', 'tamago-smtp' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	public static function register_eraser( $erasers ): array {
		$erasers                = (array) $erasers;
		$erasers['tamago-smtp'] = array(
			'eraser_friendly_name' => __( 'Email log (Tamago SMTP)', 'tamago-smtp' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	public static function export( $email, $page = 1 ): array {
		$found = TMG_SMTP_Log_Store::find_by_email( (string) $email, (int) $page, self::PER_PAGE );
		$items = array();
		foreach ( $found['rows'] as $row ) {
			$body = null !== $row['body_text'] && '' !== $row['body_text'] ? $row['body_text'] : wp_strip_all_tags( (string) $row['body_html'] );
			$data = array(
				array( 'name' => __( 'Date (UTC)', 'tamago-smtp' ), 'value' => $row['created_at'] ),
				array( 'name' => __( 'Status', 'tamago-smtp' ), 'value' => $row['status'] ),
				array( 'name' => __( 'To', 'tamago-smtp' ), 'value' => $row['to_email'] ),
				array( 'name' => __( 'Cc', 'tamago-smtp' ), 'value' => $row['cc'] ),
				array( 'name' => __( 'Bcc', 'tamago-smtp' ), 'value' => $row['bcc'] ),
				array( 'name' => __( 'From', 'tamago-smtp' ), 'value' => trim( $row['from_name'] . ' <' . $row['from_email'] . '>' ) ),
				array( 'name' => __( 'Reply-To', 'tamago-smtp' ), 'value' => $row['reply_to'] ),
				array( 'name' => __( 'Subject', 'tamago-smtp' ), 'value' => $row['subject'] ),
				array( 'name' => __( 'Message', 'tamago-smtp' ), 'value' => $body ),
			);
			$items[] = array(
				'group_id'    => 'tamago-smtp-log',
				'group_label' => __( 'Email log', 'tamago-smtp' ),
				'item_id'     => 'tmg-smtp-log-' . $row['id'],
				'data'        => array_values(
					array_filter(
						$data,
						static function ( $d ) {
							return null !== $d['value'] && '' !== (string) $d['value'];
						}
					)
				),
			);
		}
		return array(
			'data' => $items,
			'done' => ! $found['more'],
		);
	}

	public static function erase( $email, $page = 1 ): array {
		$ids     = TMG_SMTP_Log_Store::ids_by_email( (string) $email );
		$removed = TMG_SMTP_Log_Store::delete( $ids );
		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	public static function policy_text(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content(
			'Tamago SMTP',
			'<p>' . esc_html__( 'When this site sends an email, a copy of its details (recipients, sender, subject and, unless switched off, its content) is kept in an email log for troubleshooting. Entries are deleted automatically after the number of days set by the site owner.', 'tamago-smtp' ) . '</p>'
		);
	}
}
