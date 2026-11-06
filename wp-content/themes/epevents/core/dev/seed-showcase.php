<?php
/**
 * Seeds a private "All sections" microsite that uses every section layout.
 * Run: php tools/wp-cli.phar --path=. eval-file wp-content/themes/epevents/core/dev/seed-showcase.php
 * Uses demo images by ID from the local seed; adjust the IDs on another site.
 */
$existing = get_page_by_path( 'all-sections', OBJECT, 'ep_microsite' );
$id       = $existing ? $existing->ID : wp_insert_post( array( 'post_type' => 'ep_microsite', 'post_title' => 'All sections showcase', 'post_name' => 'all-sections', 'post_status' => 'private' ) );

$set = fn( array $extra = array() ) => array_merge( array( 'bg_type' => 'none', 'text_scheme' => 'auto', 'padding' => 'md', 'container' => 'default', 'anchor' => '', 'nav_label' => '', 'hidden' => 0 ), $extra );
$img = fn( int $aid ) => $aid;
$p   = '<p>Join leading clinicians for a full day of evidence updates, case discussions and practical take-aways for daily practice.</p>';

$sections = array(
	array( 'acf_fc_layout' => 'banner', 'style' => 'split', 'side_image' => 14, 'eyebrow' => 'Kuala Lumpur · 2026', 'title' => 'Malaysia Scientific Summit 2026', 'description' => '<p>A one-day meeting for healthcare professionals.</p>', 'show_meta' => 1, 'date' => '21st November 2026', 'time' => '8:30am – 5:00pm', 'venue' => 'Hilton Kuala Lumpur', 'countdown' => 1, 'countdown_to' => '2026-11-21 08:30:00', 'height' => 'md', 'text_align' => 'left',
		'buttons' => array( array( 'label' => 'Register to attend', 'action' => 'event', 'event' => 8, 'style' => 'light' ), array( 'label' => 'Breakout rooms', 'action' => 'modal', 'style' => 'outline', 'modal_title' => 'Choose a room', 'modal_links' => array( array( 'label' => 'Room A – Cardiology', 'url' => 'https://example.com/a' ), array( 'label' => 'Room B – Nephrology', 'url' => 'https://example.com/b' ) ) ) ),
		'settings' => $set( array( 'bg_type' => 'color', 'padding' => 'lg' ) ) ),
	array( 'acf_fc_layout' => 'text', 'eyebrow' => 'Welcome', 'heading' => 'A message from the organising chair', 'align' => 'center', 'intro' => '', 'content' => $p . $p, 'columns' => '1', 'settings' => $set( array( 'container' => 'narrow', 'anchor' => 'welcome', 'nav_label' => 'Welcome' ) ) ),
	array( 'acf_fc_layout' => 'image_text', 'eyebrow' => 'Venue', 'heading' => 'Hilton Kuala Lumpur', 'align' => 'left', 'image' => 14, 'content' => $p . '<p><a href="#">Get directions →</a></p>', 'image_position' => 'left', 'image_ratio' => '50', 'settings' => $set( array( 'bg_type' => 'color', 'bg_color' => '#ffffff' ) ) ),
	array( 'acf_fc_layout' => 'speakers', 'eyebrow' => 'Faculty', 'heading' => 'Speakers', 'align' => 'center', 'intro' => '', 'style' => 'cards', 'items' => array(
		array( 'photo' => 11, 'name' => 'Dr. Aisyah Rahman', 'role' => 'Consultant Haematologist, Hospital Kuala Lumpur', 'bio' => $p ),
		array( 'photo' => 12, 'name' => 'Prof. Tan Wei Ming', 'role' => 'Nephrologist, University Malaya Medical Centre', 'bio' => $p ),
		array( 'photo' => 13, 'name' => 'Dr. Rajesh Kumar', 'role' => 'Neurologist, Institut Jantung Negara', 'bio' => $p ),
	), 'settings' => $set( array( 'anchor' => 'speakers', 'nav_label' => 'Speakers' ) ) ),
	array( 'acf_fc_layout' => 'agenda', 'eyebrow' => 'Day 1', 'heading' => 'Programme', 'align' => 'center', 'intro' => '', 'items' => array(
		array( 'time' => '8:30am', 'title' => 'Registration and breakfast', 'description' => 'Foyer, level 3' ),
		array( 'time' => '9:15am', 'title' => 'Opening address', 'description' => 'Organising chair' ),
		array( 'time' => '10:00am', 'title' => 'Plenary: what is new in 2026', 'description' => 'Prof. Tan Wei Ming' ),
	), 'settings' => $set( array( 'bg_type' => 'gradient', 'bg_color' => '#2b0a1c', 'bg_color2' => '#6d1a45', 'bg_angle' => 135, 'anchor' => 'programme', 'nav_label' => 'Programme' ) ) ),
	array( 'acf_fc_layout' => 'gallery', 'eyebrow' => '2025 edition', 'heading' => 'Gallery', 'align' => 'center', 'intro' => '', 'images' => array( 14, 16, 17, 18, 9, 6 ), 'style' => 'grid', 'columns' => '3', 'settings' => $set() ),
	array( 'acf_fc_layout' => 'cards', 'eyebrow' => 'Logistics', 'heading' => 'Where to stay', 'align' => 'center', 'intro' => '', 'columns' => '3', 'items' => array(
		array( 'image' => 17, 'title' => 'Hilton Kuala Lumpur', 'link' => array( 'url' => 'https://example.com', 'title' => 'Book', 'target' => '_blank' ), 'content' => '<p>Event venue. Special rate RM 520 per night.</p>' ),
		array( 'image' => 18, 'title' => 'Le Méridien', 'link' => '', 'content' => '<p>Five minutes on foot via the covered walkway.</p>' ),
		array( 'image' => 16, 'title' => 'Aloft KL Sentral', 'link' => '', 'content' => '<p>Ten minutes on foot, close to the train station.</p>' ),
	), 'settings' => $set( array( 'bg_type' => 'color', 'bg_color' => '#ffffff' ) ) ),
	array( 'acf_fc_layout' => 'posts', 'eyebrow' => 'Updates', 'heading' => 'News', 'align' => 'center', 'intro' => '', 'source' => 'latest', 'count' => 3, 'settings' => $set( array( 'anchor' => 'news', 'nav_label' => 'News' ) ) ),
	array( 'acf_fc_layout' => 'video', 'heading' => 'Highlights from 2025', 'align' => 'center', 'intro' => '', 'embed' => 'https://www.youtube.com/watch?v=aqz-KE-bpKQ', 'settings' => $set( array( 'bg_type' => 'color', 'bg_color' => '#1c1a1f' ) ) ),
	array( 'acf_fc_layout' => 'image', 'image' => 9, 'image_mobile' => '', 'link' => '', 'caption' => 'Key visual of the 2025 edition', 'settings' => $set( array( 'container' => 'wide' ) ) ),
	array( 'acf_fc_layout' => 'logos', 'heading' => 'Supported by', 'align' => 'center', 'intro' => '', 'images' => array( 21, 24, 27 ), 'settings' => $set( array( 'padding' => 'sm' ) ) ),
	array( 'acf_fc_layout' => 'contact', 'eyebrow' => 'Need help?', 'heading' => 'Contact the secretariat', 'align' => 'center', 'intro' => '', 'items' => array(
		array( 'type' => 'whatsapp', 'name' => 'Ms. Lee (WhatsApp)', 'value' => '+60123456789' ),
		array( 'type' => 'email', 'name' => 'Secretariat', 'value' => 'secretariat@example.com' ),
	), 'settings' => $set( array( 'anchor' => 'contact', 'nav_label' => 'Contact' ) ) ),
	array( 'acf_fc_layout' => 'form', 'heading' => 'Register now', 'align' => 'center', 'intro' => '', 'event' => 8, 'settings' => $set( array( 'bg_type' => 'color', 'bg_color' => '#efe9ec', 'container' => 'narrow' ) ) ),
	array( 'acf_fc_layout' => 'html', 'code' => '<div style="text-align:center;padding:12px;border:1px dashed #ccc;border-radius:8px">Custom HTML block (maps, widgets, embeds)</div>', 'settings' => $set( array( 'padding' => 'sm', 'container' => 'narrow' ) ) ),
);
update_field( 'sections', $sections, $id );
update_field( 'linked_event', 8, $id );
update_field( 'show_nav', 1, $id );
update_field( 'nav_cta', array( 'url' => get_permalink( 8 ), 'title' => 'Register', 'target' => '' ), $id );
echo "Showcase microsite: " . get_permalink( $id ) . "\n";
