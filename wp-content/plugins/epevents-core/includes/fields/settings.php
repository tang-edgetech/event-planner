<?php
/**
 * Global settings (ACF options page): reusable option lists (Salutation etc.), email, tracking.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'acf/init', function () {
	acf_add_options_sub_page(
		array(
			'page_title'  => 'Event Plus Settings',
			'menu_title'  => 'Settings',
			'menu_slug'   => 'epc-settings',
			'parent_slug' => 'epc',
			'capability'  => 'epc_manage_settings',
			'position'    => 90,
		)
	);

	acf_add_local_field_group(
		array(
			'key'      => 'group_epc_settings',
			'title'    => 'Event Plus Settings',
			'location' => array( array( array( 'param' => 'options_page', 'operator' => '==', 'value' => 'epc-settings' ) ) ),
			'style'    => 'seamless',
			'fields'   => array(
				array( 'key' => 'field_epc_tab_lists', 'label' => 'Option lists', 'type' => 'tab' ),
				array(
					'key'          => 'field_epc_lists_msg',
					'type'         => 'message',
					'message'      => 'Reusable lists for dropdowns, radios and checkboxes in any event form. The list with slug <code>salutation</code> is used by every <strong>Salutation</strong> field.',
				),
				array(
					'key'          => 'field_epc_preset_lists',
					'label'        => 'Lists',
					'name'         => 'epc_preset_lists',
					'type'         => 'repeater',
					'layout'       => 'block',
					'collapsed'    => 'field_epc_pl_name',
					'button_label' => 'Add list',
					'sub_fields'   => array(
						array( 'key' => 'field_epc_pl_name', 'label' => 'Name', 'name' => 'name', 'type' => 'text', 'required' => 1, 'wrapper' => array( 'width' => 50 ) ),
						array( 'key' => 'field_epc_pl_slug', 'label' => 'Slug', 'name' => 'slug', 'type' => 'text', 'instructions' => 'Auto from name if empty. Do not change once in use.', 'wrapper' => array( 'width' => 50 ) ),
						array( 'key' => 'field_epc_pl_options', 'label' => 'Options', 'name' => 'options', 'type' => 'textarea', 'rows' => 6, 'instructions' => 'One option per line.' ),
					),
				),

				array( 'key' => 'field_epc_tab_mail', 'label' => 'Email', 'type' => 'tab' ),
				array( 'key' => 'field_epc_mail_from_name', 'label' => 'From name', 'name' => 'epc_mail_from_name', 'type' => 'text', 'wrapper' => array( 'width' => 50 ) ),
				array( 'key' => 'field_epc_mail_from_email', 'label' => 'From email', 'name' => 'epc_mail_from_email', 'type' => 'email', 'wrapper' => array( 'width' => 50 ) ),
				array( 'key' => 'field_epc_admin_emails', 'label' => 'Default notification recipients', 'name' => 'epc_admin_emails', 'type' => 'textarea', 'rows' => 3, 'instructions' => 'One email per line. Events can override this.' ),

				/* SMTP: send all site email through a mail server instead of PHP mail(). */
				array( 'key' => 'field_epc_smtp_enable', 'label' => 'Send email through SMTP', 'name' => 'epc_smtp_enable', 'type' => 'true_false', 'ui' => 1, 'default_value' => 0, 'instructions' => 'Recommended: hosting PHP mail often lands in spam. Applies to every email the site sends.' ),
				array( 'key' => 'field_epc_smtp_host', 'label' => 'SMTP host', 'name' => 'epc_smtp_host', 'type' => 'text', 'placeholder' => 'smtp.gmail.com / mail.yourdomain.com', 'wrapper' => array( 'width' => 40 ), 'conditional_logic' => array( array( array( 'field' => 'field_epc_smtp_enable', 'operator' => '==', 'value' => '1' ) ) ) ),
				array(
					'key'               => 'field_epc_smtp_secure',
					'label'             => 'Encryption',
					'name'              => 'epc_smtp_secure',
					'type'              => 'select',
					'choices'           => array( 'tls' => 'TLS (port 587)', 'ssl' => 'SSL (port 465)', 'none' => 'None (port 25)' ),
					'default_value'     => 'tls',
					'wrapper'           => array( 'width' => 30 ),
					'conditional_logic' => array( array( array( 'field' => 'field_epc_smtp_enable', 'operator' => '==', 'value' => '1' ) ) ),
				),
				array( 'key' => 'field_epc_smtp_port', 'label' => 'Port', 'name' => 'epc_smtp_port', 'type' => 'number', 'placeholder' => '587', 'min' => 1, 'max' => 65535, 'instructions' => 'Empty = usual port for the encryption.', 'wrapper' => array( 'width' => 30 ), 'conditional_logic' => array( array( array( 'field' => 'field_epc_smtp_enable', 'operator' => '==', 'value' => '1' ) ) ) ),
				array( 'key' => 'field_epc_smtp_auth', 'label' => 'Server requires login', 'name' => 'epc_smtp_auth', 'type' => 'true_false', 'ui' => 1, 'default_value' => 1, 'wrapper' => array( 'width' => 40 ), 'conditional_logic' => array( array( array( 'field' => 'field_epc_smtp_enable', 'operator' => '==', 'value' => '1' ) ) ) ),
				array( 'key' => 'field_epc_smtp_user', 'label' => 'Username', 'name' => 'epc_smtp_user', 'type' => 'text', 'placeholder' => 'Usually the full email address', 'wrapper' => array( 'width' => 30 ), 'conditional_logic' => array( array( array( 'field' => 'field_epc_smtp_enable', 'operator' => '==', 'value' => '1' ), array( 'field' => 'field_epc_smtp_auth', 'operator' => '==', 'value' => '1' ) ) ) ),
				array( 'key' => 'field_epc_smtp_pass', 'label' => 'Password', 'name' => 'epc_smtp_pass', 'type' => 'password', 'instructions' => 'Stored encrypted. Leave empty to keep the saved password. For Gmail / Microsoft 365 use an app password.', 'wrapper' => array( 'width' => 30 ), 'conditional_logic' => array( array( array( 'field' => 'field_epc_smtp_enable', 'operator' => '==', 'value' => '1' ), array( 'field' => 'field_epc_smtp_auth', 'operator' => '==', 'value' => '1' ) ) ) ),
				array( 'key' => 'field_epc_smtp_test', 'label' => 'Send a test email', 'name' => '', 'type' => 'message', 'message' => '<div id="epc-smtp-test"></div>', 'esc_html' => 0, 'instructions' => 'Save first, then send a test to check the settings.' ),

				array( 'key' => 'field_epc_tab_track', 'label' => 'Tracking', 'type' => 'tab' ),
				array( 'key' => 'field_epc_track_enabled', 'label' => 'Enable visitor tracking', 'name' => 'epc_track_enabled', 'type' => 'true_false', 'ui' => 1, 'default_value' => 1, 'wrapper' => array( 'width' => 33 ) ),
				array( 'key' => 'field_epc_track_admins', 'label' => 'Track logged-in editors', 'name' => 'epc_track_admins', 'type' => 'true_false', 'ui' => 1, 'default_value' => 0, 'wrapper' => array( 'width' => 33 ) ),
				array( 'key' => 'field_epc_track_dnt', 'label' => 'Respect "Do Not Track"', 'name' => 'epc_track_dnt', 'type' => 'true_false', 'ui' => 1, 'default_value' => 0, 'wrapper' => array( 'width' => 33 ) ),
				array(
					'key'           => 'field_epc_geo_provider',
					'label'         => 'Location (country / region / city) source',
					'name'          => 'epc_geo_provider',
					'type'          => 'select',
					'choices'       => array(
						'headers' => 'Cloudflare / proxy headers only (no external calls)',
						'ipapi'   => 'ipapi.co (free tier, ~1,000 lookups/day)',
						'ipinfo'  => 'ipinfo.io (token required)',
						'none'    => 'Disabled',
					),
					'default_value' => 'headers',
					'instructions'  => 'Results are cached per IP for 7 days. Raw IP addresses are never stored — only a salted hash.',
					'wrapper'       => array( 'width' => 50 ),
				),
				array( 'key' => 'field_epc_geo_token', 'label' => 'ipinfo.io token', 'name' => 'epc_geo_token', 'type' => 'text', 'wrapper' => array( 'width' => 50 ), 'conditional_logic' => array( array( array( 'field' => 'field_epc_geo_provider', 'operator' => '==', 'value' => 'ipinfo' ) ) ) ),
				array( 'key' => 'field_epc_track_retention', 'label' => 'Keep raw visit data for (days)', 'name' => 'epc_track_retention', 'type' => 'number', 'default_value' => 730, 'min' => 0, 'instructions' => '0 = forever.', 'wrapper' => array( 'width' => 50 ) ),

				/* Site access: maintenance page everywhere except direct links. */
				array( 'key' => 'field_epc_tab_access', 'label' => 'Site access', 'type' => 'tab' ),
				array(
					'key'           => 'field_epc_access_mode',
					'label'         => 'Who can see what',
					'name'          => 'epc_access_mode',
					'type'          => 'radio',
					'choices'       => array(
						'private' => 'Direct links only: the homepage and everything else show the maintenance page; event forms and microsites open only from their link',
						'public'  => 'Public website: homepage and listings are visible',
					),
					'default_value' => 'private',
					'instructions'  => 'In "Direct links only", events and microsites are also kept out of search, sitemaps and the public API, so their links cannot be discovered.',
				),
				array( 'key' => 'field_epc_access_posts', 'label' => 'News articles open by direct link', 'name' => 'epc_access_posts', 'type' => 'true_false', 'ui' => 1, 'default_value' => 1, 'instructions' => 'Needed when microsites show a "News" section that links to articles.', 'wrapper' => array( 'width' => 50 ), 'conditional_logic' => array( array( array( 'field' => 'field_epc_access_mode', 'operator' => '==', 'value' => 'private' ) ) ) ),
				array( 'key' => 'field_epc_access_pages', 'label' => 'Pages open by direct link', 'name' => 'epc_access_pages', 'type' => 'true_false', 'ui' => 1, 'default_value' => 0, 'instructions' => 'The privacy policy page always opens (consent checkboxes link to it).', 'wrapper' => array( 'width' => 50 ), 'conditional_logic' => array( array( array( 'field' => 'field_epc_access_mode', 'operator' => '==', 'value' => 'private' ) ) ) ),
				array( 'key' => 'field_epc_maint_title', 'label' => 'Maintenance page title', 'name' => 'epc_maint_title', 'type' => 'text', 'placeholder' => 'We will be back soon', 'wrapper' => array( 'width' => 50 ) ),
				array( 'key' => 'field_epc_maint_email', 'label' => 'Contact email (optional)', 'name' => 'epc_maint_email', 'type' => 'email', 'wrapper' => array( 'width' => 50 ) ),
				array( 'key' => 'field_epc_maint_message', 'label' => 'Maintenance page message', 'name' => 'epc_maint_message', 'type' => 'wysiwyg', 'toolbar' => 'basic', 'media_upload' => 0, 'delay' => 1, 'instructions' => 'Empty = standard text.', 'wrapper' => array( 'width' => 70 ) ),
				array( 'key' => 'field_epc_maint_bg', 'label' => 'Background image (optional)', 'name' => 'epc_maint_bg', 'type' => 'image', 'return_format' => 'array', 'preview_size' => 'medium', 'instructions' => 'Empty = brand colour.', 'wrapper' => array( 'width' => 30 ) ),

				/* Optional features: all off by default. */
				array( 'key' => 'field_epc_tab_features', 'label' => 'Features', 'type' => 'tab' ),
				array( 'key' => 'field_epc_features_intro', 'label' => '', 'type' => 'message', 'message' => 'Optional features. Each is off until you switch it on.' ),
				array( 'key' => 'field_epc_f_alert', 'label' => 'Capacity alert email', 'name' => 'epc_feature_capacity_alert', 'type' => 'true_false', 'ui' => 1, 'default_value' => 0, 'instructions' => 'Emails the event\'s notification recipients when registrations reach a percentage of capacity, and again when full.', 'wrapper' => array( 'width' => 50 ) ),
				array( 'key' => 'field_epc_f_alert_pct', 'label' => 'Alert at', 'name' => 'epc_capacity_alert_pct', 'type' => 'number', 'default_value' => 90, 'min' => 1, 'max' => 99, 'append' => '%', 'wrapper' => array( 'width' => 50 ), 'conditional_logic' => array( array( array( 'field' => 'field_epc_f_alert', 'operator' => '==', 'value' => '1' ) ) ) ),
				array(
					'key'           => 'field_epc_f_waitlist',
					'label'         => 'When a seat frees up (events with a waiting list)',
					'name'          => 'epc_feature_waitlist',
					'type'          => 'radio',
					'choices'       => array(
						'manual' => 'Do nothing (follow up manually)',
						'notify' => 'Email the team that a seat is available',
						'auto'   => 'Give the seat to the next person on the waiting list and email them',
					),
					'default_value' => 'manual',
					'instructions'  => 'A seat frees up when an applicant is cancelled or deleted, or the maximum capacity is raised.',
				),
				array( 'key' => 'field_epc_f_ics', 'label' => 'Calendar invite in confirmation emails', 'name' => 'epc_feature_ics', 'type' => 'true_false', 'ui' => 1, 'default_value' => 0, 'instructions' => 'Attaches an .ics file (Outlook, Apple Calendar) and an "Add to Google Calendar" link. Needs the event start date. You can also use {calendar_link} in email text.', 'wrapper' => array( 'width' => 50 ) ),
				array( 'key' => 'field_epc_f_countdown', 'label' => 'Countdown on new banners', 'name' => 'epc_feature_countdown', 'type' => 'true_false', 'ui' => 1, 'default_value' => 0, 'instructions' => 'Turns the countdown on by default when a banner is added. Each banner can still switch it off.', 'wrapper' => array( 'width' => 50 ) ),
				array( 'key' => 'field_epc_f_mask', 'label' => 'Hide personal data from Viewers', 'name' => 'epc_feature_mask_viewers', 'type' => 'true_false', 'ui' => 1, 'default_value' => 0, 'instructions' => 'Users who cannot edit applicants see emails, phone numbers and NRIC partly hidden (e.g. j•••@mail.com, ••••••-••-1234).', 'wrapper' => array( 'width' => 50 ) ),
				array( 'key' => 'field_epc_f_wl_subject', 'label' => 'Waiting list email subject', 'name' => 'epc_waitlist_subject', 'type' => 'text', 'placeholder' => 'You are on the waiting list: {event_title}', 'wrapper' => array( 'width' => 50 ) ),
				array( 'key' => 'field_epc_f_offer_subject', 'label' => 'Seat offer email subject', 'name' => 'epc_offer_subject', 'type' => 'text', 'placeholder' => 'A seat is now available: {event_title}', 'wrapper' => array( 'width' => 50 ), 'conditional_logic' => array( array( array( 'field' => 'field_epc_f_waitlist', 'operator' => '==', 'value' => 'auto' ) ) ) ),
				array( 'key' => 'field_epc_f_wl_body', 'label' => 'Waiting list email', 'name' => 'epc_waitlist_body', 'type' => 'wysiwyg', 'toolbar' => 'basic', 'media_upload' => 0, 'delay' => 1, 'instructions' => 'Sent when someone joins the waiting list. Empty = standard text. Placeholders: {fullname}, {event_title}, {event_date}, {registration_details}…', 'wrapper' => array( 'width' => 50 ) ),
				array( 'key' => 'field_epc_f_offer_body', 'label' => 'Seat offer email', 'name' => 'epc_offer_body', 'type' => 'wysiwyg', 'toolbar' => 'basic', 'media_upload' => 0, 'delay' => 1, 'instructions' => 'Sent when a waiting applicant gets a seat. Empty = standard text.', 'wrapper' => array( 'width' => 50 ), 'conditional_logic' => array( array( array( 'field' => 'field_epc_f_waitlist', 'operator' => '==', 'value' => 'auto' ) ) ) ),
			),
		)
	);

	// Seed default lists once (Salutation, Yes/No, Meal, Malaysia States, Specialty).
	if ( ! get_option( 'epc_seeded' ) ) {
		epc_seed_defaults();
		update_option( 'epc_seeded', 1 );
	}
} );
