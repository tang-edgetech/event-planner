<?php

/**
 * Step 1 of the WordPress import: run inside the legacy WordPress site with WP-CLI.
 *
 *   php tools/wp-cli.phar --path=legacy/wordpress eval-file apps/api/database/import/wordpress-export.php > wp-export.json
 *
 * Writes one JSON document with settings, media, events (with their registration forms), microsites and applicants.
 * Images become "wpmedia:<id>" and linked posts "wppost:<id>"; the Laravel side (php artisan import:wordpress) maps them.
 * Read-only: nothing in WordPress is changed.
 */
if (! defined('ABSPATH') || ! function_exists('get_field')) {
    fwrite(STDERR, "Run this with WP-CLI eval-file inside the WordPress site (ACF must be active).\n");
    exit(1);
}

$media = [];

/** Replace ACF image arrays / attachment posts / linked posts with portable markers, recursively. */
$portable = function ($value) use (&$portable, &$media) {
    if ($value instanceof WP_Post) {
        if ($value->post_type === 'attachment') {
            $media[$value->ID] = true;

            return 'wpmedia:'.$value->ID;
        }

        return 'wppost:'.$value->ID;
    }
    if (is_array($value)) {
        if (isset($value['ID'], $value['url'], $value['mime_type'])) {
            $media[(int) $value['ID']] = true;

            return 'wpmedia:'.(int) $value['ID'];
        }
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = $portable($v);
        }

        return $out;
    }

    return $value;
};

$site = home_url();

// Settings worth carrying over.
$lists = [];
foreach ((array) get_field('epc_preset_lists', 'option') as $row) {
    if (! empty($row['name'])) {
        $lists[] = [
            'name' => $row['name'],
            'slug' => $row['slug'] ?: sanitize_title($row['name']),
            'options' => array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $row['options'])), 'strlen')),
        ];
    }
}
$settings = [
    'option_lists' => $lists,
    'admin_recipients' => (string) get_field('epc_admin_emails', 'option'),
    'from_name' => (string) get_field('epc_from_name', 'option'),
    'from_address' => (string) get_field('epc_from_email', 'option'),
    'maintenance_title' => (string) get_field('epc_maint_title', 'option'),
    'maintenance_message' => wp_strip_all_tags((string) get_field('epc_maint_message', 'option')),
    'contact_email' => (string) get_field('epc_maint_email', 'option'),
    'capacity_alert' => (bool) get_field('epc_feature_capacity_alert', 'option'),
    'capacity_alert_pct' => (int) (get_field('epc_capacity_alert_pct', 'option') ?: 90),
    'waitlist_mode' => (string) (get_field('epc_feature_waitlist', 'option') ?: 'manual'),
    'mask_viewers' => (bool) get_field('epc_feature_mask_viewers', 'option'),
];

// Events.
$events = [];
$eventFields = ['venue', 'event_date_text', 'event_time_text', 'venue_map', 'banner_desktop', 'banner_mobile', 'accent_color', 'page_bg', 'show_title', 'page_layout', 'summary', 'form_intro', 'submit_label', 'reg_status', 'capacity_min', 'capacity_max', 'enable_waitlist', 'default_status', 'full_title', 'closed_title', 'full_message', 'closed_message', 'waitlist_message', 'waitlist_confirmation', 'confirmation_type', 'confirmation_message', 'redirect_url', 'notify_admin', 'admin_emails', 'notify_applicant', 'email_subject', 'email_body'];
foreach (get_posts(['post_type' => 'ep_event', 'post_status' => ['publish', 'draft', 'private', 'future', 'pending'], 'numberposts' => -1]) as $p) {
    $values = [];
    foreach ($eventFields as $f) {
        $values[$f] = $portable(get_field($f, $p->ID));
    }
    foreach (['event_start', 'event_end', 'reg_open_at', 'reg_close_at'] as $d) {
        $values[$d] = get_field($d, $p->ID, false) ?: null; // raw "Y-m-d H:i:s"
    }
    $events[] = [
        'wp_id' => $p->ID,
        'title' => $p->post_title,
        'slug' => $p->post_name,
        'status' => $p->post_status === 'publish' ? 'published' : 'draft',
        'created_at' => $p->post_date,
        'fields' => $values,
        // Normalised by the theme itself, so the field keys match the applicants' stored answers.
        'form_fields' => function_exists('epc_get_form_fields') ? array_values(epc_get_form_fields($p->ID)) : [],
    ];
}

// Microsites.
$microsites = [];
foreach (get_posts(['post_type' => 'ep_microsite', 'post_status' => ['publish', 'draft', 'private', 'future', 'pending'], 'numberposts' => -1]) as $p) {
    $microsites[] = [
        'wp_id' => $p->ID,
        'title' => $p->post_title,
        'slug' => $p->post_name,
        'status' => $p->post_status === 'publish' ? 'published' : 'draft',
        'created_at' => $p->post_date,
        'linked_event' => $portable(get_field('linked_event', $p->ID)),
        'settings' => [
            'accent_color' => get_field('accent_color', $p->ID),
            'page_bg' => get_field('page_bg', $p->ID),
            'show_nav' => get_field('show_nav', $p->ID),
            'nav_logo' => $portable(get_field('nav_logo', $p->ID)),
            'nav_cta' => get_field('nav_cta', $p->ID),
            'footer_text' => get_field('footer_text', $p->ID),
            'animations' => get_field('animations', $p->ID),
            'custom_css' => get_field('custom_css', $p->ID),
        ],
        'sections' => $portable((array) get_field('sections', $p->ID)),
    ];
}

// Applicants.
$applicants = [];
foreach (get_posts(['post_type' => 'ep_applicant', 'post_status' => 'any', 'numberposts' => -1, 'orderby' => 'ID', 'order' => 'ASC']) as $p) {
    $applicants[] = [
        'wp_id' => $p->ID,
        'title' => $p->post_title,
        'created_at' => $p->post_date,
        'event_wp_id' => (int) get_post_meta($p->ID, '_epc_event_id', true),
        'status' => get_post_meta($p->ID, '_epc_status', true) ?: 'registered',
        'data' => (array) get_post_meta($p->ID, '_epc_data', true),
        'labels' => (array) get_post_meta($p->ID, '_epc_labels', true),
        'source' => (array) get_post_meta($p->ID, '_epc_source', true),
        'visitor' => (string) get_post_meta($p->ID, '_epc_visitor', true),
        'ip_hash' => (string) get_post_meta($p->ID, '_epc_ip_hash', true),
        'log' => array_values(array_filter((array) get_post_meta($p->ID, '_epc_log', true))),
        'notes' => (string) get_post_meta($p->ID, '_epc_notes', true),
    ];
}

// Media files used anywhere above.
$files = [];
$uploads = wp_upload_dir();
foreach (array_keys($media) as $id) {
    $path = get_attached_file($id);
    if (! $path || ! file_exists($path)) {
        continue;
    }
    $meta = wp_get_attachment_metadata($id);
    $files[] = [
        'wp_id' => $id,
        'path' => wp_normalize_path($path),
        'name' => basename($path),
        'mime' => get_post_mime_type($id),
        'alt' => (string) get_post_meta($id, '_wp_attachment_image_alt', true),
        'width' => $meta['width'] ?? null,
        'height' => $meta['height'] ?? null,
    ];
}

echo wp_json_encode([
    'source' => $site,
    'exported_at' => gmdate('c'),
    'uploads_dir' => wp_normalize_path($uploads['basedir']),
    'settings' => $settings,
    'media' => $files,
    'events' => $events,
    'microsites' => $microsites,
    'applicants' => $applicants,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
