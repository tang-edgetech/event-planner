<?php
/**
 * Mail provider presets: the host, port and encryption each common service expects.
 *
 * Picking a preset only fills the fields; everything stays editable.
 *
 * Used by: TMG_SMTP_Settings (valid values), TMG_SMTP_Admin (Settings tab + admin.js).
 *
 * @package TamagoSMTP
 */

defined( 'ABSPATH' ) || exit;

class TMG_SMTP_Providers {

	/** @return array<string, array{label:string,host:string,port:int,encryption:string,auth:bool,username:string,help:string}> */
	public static function all(): array {
		return array(
			'other'     => array(
				'label'      => __( 'Other SMTP', 'tamago-smtp' ),
				'host'       => '',
				'port'       => 587,
				'encryption' => 'tls',
				'auth'       => true,
				'username'   => '',
				'help'       => __( 'Use the server details from your host or email provider.', 'tamago-smtp' ),
			),
			'gmail'     => array(
				'label'      => __( 'Gmail / Google Workspace', 'tamago-smtp' ),
				'host'       => 'smtp.gmail.com',
				'port'       => 587,
				'encryption' => 'tls',
				'auth'       => true,
				'username'   => '',
				'help'       => __( 'Username is the full Gmail address. With 2-Step Verification on, create an App password and use it as the password.', 'tamago-smtp' ),
			),
			'microsoft' => array(
				'label'      => __( 'Microsoft 365 / Outlook', 'tamago-smtp' ),
				'host'       => 'smtp.office365.com',
				'port'       => 587,
				'encryption' => 'tls',
				'auth'       => true,
				'username'   => '',
				'help'       => __( 'Username is the mailbox address. "Authenticated SMTP" must be switched on for that mailbox in the Microsoft 365 admin center.', 'tamago-smtp' ),
			),
			'ses'       => array(
				'label'      => __( 'Amazon SES (SMTP)', 'tamago-smtp' ),
				'host'       => 'email-smtp.us-east-1.amazonaws.com',
				'port'       => 587,
				'encryption' => 'tls',
				'auth'       => true,
				'username'   => '',
				'help'       => __( 'Change "us-east-1" in the host to your SES region. Use the SMTP credentials created in the SES console, not your AWS access keys.', 'tamago-smtp' ),
			),
			'sendgrid'  => array(
				'label'      => __( 'SendGrid', 'tamago-smtp' ),
				'host'       => 'smtp.sendgrid.net',
				'port'       => 587,
				'encryption' => 'tls',
				'auth'       => true,
				'username'   => 'apikey',
				'help'       => __( 'Username is the word "apikey"; the password is your SendGrid API key.', 'tamago-smtp' ),
			),
			'mailgun'   => array(
				'label'      => __( 'Mailgun', 'tamago-smtp' ),
				'host'       => 'smtp.mailgun.org',
				'port'       => 587,
				'encryption' => 'tls',
				'auth'       => true,
				'username'   => '',
				'help'       => __( 'Use the SMTP login of your sending domain (e.g. postmaster@mg.example.com). EU domains use smtp.eu.mailgun.org.', 'tamago-smtp' ),
			),
			'brevo'     => array(
				'label'      => __( 'Brevo', 'tamago-smtp' ),
				'host'       => 'smtp-relay.brevo.com',
				'port'       => 587,
				'encryption' => 'tls',
				'auth'       => true,
				'username'   => '',
				'help'       => __( 'Use the SMTP login and SMTP key from Brevo → SMTP & API.', 'tamago-smtp' ),
			),
			'zoho'      => array(
				'label'      => __( 'Zoho Mail', 'tamago-smtp' ),
				'host'       => 'smtp.zoho.com',
				'port'       => 587,
				'encryption' => 'tls',
				'auth'       => true,
				'username'   => '',
				'help'       => __( 'Use smtp.zoho.eu, smtp.zoho.in, … if your account is in another data centre. With two-factor sign-in, use an application-specific password.', 'tamago-smtp' ),
			),
			'postmark'  => array(
				'label'      => __( 'Postmark', 'tamago-smtp' ),
				'host'       => 'smtp.postmarkapp.com',
				'port'       => 587,
				'encryption' => 'tls',
				'auth'       => true,
				'username'   => '',
				'help'       => __( 'Both the username and the password are your Server API token.', 'tamago-smtp' ),
			),
		);
	}

	public static function ids(): array {
		return array( 'other', 'gmail', 'microsoft', 'ses', 'sendgrid', 'mailgun', 'brevo', 'zoho', 'postmark' );
	}

	public static function get( string $id ): ?array {
		$all = self::all();
		return $all[ $id ] ?? null;
	}
}
