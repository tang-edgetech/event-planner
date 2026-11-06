<?php
/**
 * Microsite fields: flexible-content page builder.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Per-section appearance settings (background colour / gradient / image / video, overlay, text colour, spacing, nav anchor).
 * Generated per layout so every field key stays unique.
 */
function epc_section_settings( string $p ): array {
	$bg = "field_epc_{$p}_bg_type";
	return array(
		'key'        => "field_epc_{$p}_settings",
		'label'      => 'Section appearance',
		'name'       => 'settings',
		'type'       => 'group',
		'layout'     => 'block',
		'sub_fields' => array(
			array(
				'key'           => $bg,
				'label'         => 'Background',
				'name'          => 'bg_type',
				'type'          => 'button_group',
				'choices'       => array( 'none' => 'Default', 'color' => 'Colour', 'gradient' => 'Gradient', 'image' => 'Image', 'video' => 'Video' ),
				'default_value' => 'banner' === $p ? 'color' : 'none',
				'wrapper'       => array( 'width' => 50 ),
			),
			array(
				'key'           => "field_epc_{$p}_text_scheme",
				'label'         => 'Text colour',
				'name'          => 'text_scheme',
				'type'          => 'button_group',
				'choices'       => array( 'auto' => 'Auto', 'light' => 'Light', 'dark' => 'Dark', 'custom' => 'Custom' ),
				'default_value' => 'auto',
				'wrapper'       => array( 'width' => 30 ),
			),
			array( 'key' => "field_epc_{$p}_text_color", 'label' => 'Custom text', 'name' => 'text_color', 'type' => 'color_picker', 'wrapper' => array( 'width' => 20 ), 'conditional_logic' => epc_when( "field_epc_{$p}_text_scheme", 'custom' ) ),
			array( 'key' => "field_epc_{$p}_bg_color", 'label' => 'Colour', 'name' => 'bg_color', 'type' => 'color_picker', 'default_value' => '', 'wrapper' => array( 'width' => 25 ), 'conditional_logic' => epc_when( $bg, array( 'color', 'gradient', 'image', 'video' ) ) ),
			array( 'key' => "field_epc_{$p}_bg_color2", 'label' => 'Gradient to', 'name' => 'bg_color2', 'type' => 'color_picker', 'wrapper' => array( 'width' => 25 ), 'conditional_logic' => epc_when( $bg, 'gradient' ) ),
			array( 'key' => "field_epc_{$p}_bg_angle", 'label' => 'Angle', 'name' => 'bg_angle', 'type' => 'range', 'min' => 0, 'max' => 360, 'default_value' => 135, 'append' => '°', 'wrapper' => array( 'width' => 25 ), 'conditional_logic' => epc_when( $bg, 'gradient' ) ),
			array( 'key' => "field_epc_{$p}_bg_image", 'label' => 'Image', 'name' => 'bg_image', 'type' => 'image', 'return_format' => 'array', 'preview_size' => 'medium', 'wrapper' => array( 'width' => 25 ), 'conditional_logic' => epc_when( $bg, 'image' ) ),
			array( 'key' => "field_epc_{$p}_bg_image_m", 'label' => 'Mobile image (optional)', 'name' => 'bg_image_mobile', 'type' => 'image', 'return_format' => 'array', 'preview_size' => 'thumbnail', 'wrapper' => array( 'width' => 25 ), 'conditional_logic' => epc_when( $bg, 'image' ) ),
			array(
				'key'               => "field_epc_{$p}_bg_fit",
				'label'             => 'Image fit',
				'name'              => 'bg_fit',
				'type'              => 'select',
				'choices'           => array( 'cover' => 'Cover', 'contain' => 'Contain', 'repeat' => 'Tile', 'fixed' => 'Parallax (fixed)' ),
				'default_value'     => 'cover',
				'wrapper'           => array( 'width' => 25 ),
				'conditional_logic' => epc_when( $bg, 'image' ),
			),
			array( 'key' => "field_epc_{$p}_bg_video", 'label' => 'Video (MP4)', 'name' => 'bg_video', 'type' => 'file', 'return_format' => 'url', 'mime_types' => 'mp4,webm', 'wrapper' => array( 'width' => 50 ), 'conditional_logic' => epc_when( $bg, 'video' ) ),
			array( 'key' => "field_epc_{$p}_overlay", 'label' => 'Overlay colour', 'name' => 'overlay', 'type' => 'color_picker', 'enable_opacity' => 1, 'return_format' => 'string', 'instructions' => 'Improves text readability on images/video.', 'wrapper' => array( 'width' => 25 ), 'conditional_logic' => epc_when( $bg, array( 'image', 'video' ) ) ),
			array(
				'key'           => "field_epc_{$p}_padding",
				'label'         => 'Spacing',
				'name'          => 'padding',
				'type'          => 'select',
				'choices'       => array( 'none' => 'None', 'sm' => 'Small', 'md' => 'Medium', 'lg' => 'Large' ),
				'default_value' => 'md',
				'wrapper'       => array( 'width' => 20 ),
			),
			array(
				'key'           => "field_epc_{$p}_container",
				'label'         => 'Content width',
				'name'          => 'container',
				'type'          => 'select',
				'choices'       => array( 'narrow' => 'Narrow', 'default' => 'Default', 'wide' => 'Wide', 'full' => 'Full width' ),
				'default_value' => 'default',
				'wrapper'       => array( 'width' => 20 ),
			),
			array( 'key' => "field_epc_{$p}_anchor", 'label' => 'Anchor ID', 'name' => 'anchor', 'type' => 'text', 'placeholder' => 'program', 'instructions' => 'For #links.', 'wrapper' => array( 'width' => 20 ) ),
			array( 'key' => "field_epc_{$p}_nav", 'label' => 'Menu label', 'name' => 'nav_label', 'type' => 'text', 'instructions' => 'Shows in the top menu if set.', 'wrapper' => array( 'width' => 20 ) ),
			array( 'key' => "field_epc_{$p}_hide", 'label' => 'Hide section', 'name' => 'hidden', 'type' => 'true_false', 'ui' => 1, 'wrapper' => array( 'width' => 20 ) ),
		),
	);
}

/** Buttons repeater (link / registration event / pop-up list). */
function epc_buttons_field( string $p ): array {
	$a = "field_epc_{$p}_btn_action";
	return array(
		'key'          => "field_epc_{$p}_buttons",
		'label'        => 'Buttons',
		'name'         => 'buttons',
		'type'         => 'repeater',
		'layout'       => 'block',
		'collapsed'    => "field_epc_{$p}_btn_label",
		'button_label' => 'Add button',
		'sub_fields'   => array(
			array( 'key' => "field_epc_{$p}_btn_label", 'label' => 'Label', 'name' => 'label', 'type' => 'text', 'wrapper' => array( 'width' => 30 ) ),
			array(
				'key'           => $a,
				'label'         => 'Action',
				'name'          => 'action',
				'type'          => 'select',
				'choices'       => array( 'event' => 'Open registration form (Event)', 'url' => 'Link (URL / microsite / Zoom)', 'modal' => 'Pop-up with list of links', 'anchor' => 'Scroll to section' ),
				'default_value' => 'event',
				'wrapper'       => array( 'width' => 25 ),
			),
			array( 'key' => "field_epc_{$p}_btn_event", 'label' => 'Event', 'name' => 'event', 'type' => 'post_object', 'post_type' => array( EPC_Post_Types::EVENT ), 'return_format' => 'id', 'ui' => 1, 'wrapper' => array( 'width' => 30 ), 'conditional_logic' => epc_when( $a, 'event' ) ),
			array( 'key' => "field_epc_{$p}_btn_url", 'label' => 'URL', 'name' => 'url', 'type' => 'text', 'wrapper' => array( 'width' => 30 ), 'conditional_logic' => epc_when( $a, array( 'url', 'anchor' ) ), 'placeholder' => 'https://… or #section' ),
			array( 'key' => "field_epc_{$p}_btn_newtab", 'label' => 'New tab', 'name' => 'new_tab', 'type' => 'true_false', 'ui' => 1, 'wrapper' => array( 'width' => 15 ), 'conditional_logic' => epc_when( $a, 'url' ) ),
			array(
				'key'           => "field_epc_{$p}_btn_style",
				'label'         => 'Style',
				'name'          => 'style',
				'type'          => 'select',
				'choices'       => array( 'solid' => 'Solid', 'outline' => 'Outline', 'light' => 'Light' ),
				'default_value' => 'outline',
				'wrapper'       => array( 'width' => 15 ),
			),
			array( 'key' => "field_epc_{$p}_btn_modal_title", 'label' => 'Pop-up title', 'name' => 'modal_title', 'type' => 'text', 'conditional_logic' => epc_when( $a, 'modal' ) ),
			array(
				'key'               => "field_epc_{$p}_btn_modal_links",
				'label'             => 'Pop-up links',
				'name'              => 'modal_links',
				'type'              => 'repeater',
				'layout'            => 'table',
				'button_label'      => 'Add link',
				'conditional_logic' => epc_when( $a, 'modal' ),
				'sub_fields'        => array(
					array( 'key' => "field_epc_{$p}_btn_ml_label", 'label' => 'Label', 'name' => 'label', 'type' => 'text' ),
					array( 'key' => "field_epc_{$p}_btn_ml_url", 'label' => 'URL', 'name' => 'url', 'type' => 'url' ),
				),
			),
		),
	);
}

function epc_heading_fields( string $p, bool $intro = true ): array {
	$f = array(
		array( 'key' => "field_epc_{$p}_eyebrow", 'label' => 'Small label', 'name' => 'eyebrow', 'type' => 'text', 'placeholder' => 'e.g. Day 1', 'instructions' => 'Optional, above the heading.', 'wrapper' => array( 'width' => 25 ) ),
		array( 'key' => "field_epc_{$p}_heading", 'label' => 'Heading', 'name' => 'heading', 'type' => 'text', 'wrapper' => array( 'width' => 50 ) ),
		array( 'key' => "field_epc_{$p}_align", 'label' => 'Heading align', 'name' => 'align', 'type' => 'button_group', 'choices' => array( 'center' => 'Center', 'left' => 'Left' ), 'default_value' => 'center', 'wrapper' => array( 'width' => 25 ) ),
	);
	if ( $intro ) {
		$f[] = array( 'key' => "field_epc_{$p}_intro", 'label' => 'Intro', 'name' => 'intro', 'type' => 'wysiwyg', 'toolbar' => 'basic', 'media_upload' => 0, 'tabs' => 'all', 'delay' => 1 );
	}
	return $f;
}

function epc_layout( string $name, string $label, array $fields ): array {
	$fields[] = epc_section_settings( $name );
	return array(
		'key'        => 'layout_epc_' . $name,
		'name'       => $name,
		'label'      => $label,
		'display'    => 'block',
		'sub_fields' => $fields,
	);
}

add_action( 'acf/init', function () {
	$layouts = array(
		epc_layout(
			'banner',
			'Introduction banner',
			array(
				array( 'key' => 'field_epc_banner_style', 'label' => 'Banner style', 'name' => 'style', 'type' => 'button_group', 'choices' => array( 'center' => 'Centred', 'split' => 'Text + image', 'card' => 'Card on background' ), 'default_value' => 'center', 'instructions' => 'Text + image: key visual beside the text. Card: text in a white panel over the background image.', 'wrapper' => array( 'width' => 60 ) ),
				array( 'key' => 'field_epc_banner_side_image', 'label' => 'Key visual', 'name' => 'side_image', 'type' => 'image', 'return_format' => 'array', 'preview_size' => 'medium', 'wrapper' => array( 'width' => 40 ), 'conditional_logic' => epc_when( 'field_epc_banner_style', 'split' ) ),
				array( 'key' => 'field_epc_banner_title', 'label' => 'Title', 'name' => 'title', 'type' => 'text', 'instructions' => 'Empty = microsite title.', 'wrapper' => array( 'width' => 60 ) ),
				array( 'key' => 'field_epc_banner_eyebrow', 'label' => 'Small text above title', 'name' => 'eyebrow', 'type' => 'text', 'wrapper' => array( 'width' => 40 ) ),
				array( 'key' => 'field_epc_banner_logo', 'label' => 'Logo / key visual', 'name' => 'logo', 'type' => 'image', 'return_format' => 'array', 'preview_size' => 'thumbnail', 'wrapper' => array( 'width' => 30 ) ),
				array( 'key' => 'field_epc_banner_desc', 'label' => 'Description', 'name' => 'description', 'type' => 'wysiwyg', 'toolbar' => 'basic', 'media_upload' => 0, 'delay' => 1, 'wrapper' => array( 'width' => 70 ) ),
				array( 'key' => 'field_epc_banner_show_meta', 'label' => 'Show date / time / venue', 'name' => 'show_meta', 'type' => 'true_false', 'ui' => 1, 'default_value' => 1, 'wrapper' => array( 'width' => 25 ) ),
				array( 'key' => 'field_epc_banner_date', 'label' => 'Date', 'name' => 'date', 'type' => 'text', 'instructions' => 'Empty = from linked event.', 'wrapper' => array( 'width' => 25 ), 'conditional_logic' => epc_when( 'field_epc_banner_show_meta', 1 ) ),
				array( 'key' => 'field_epc_banner_time', 'label' => 'Time', 'name' => 'time', 'type' => 'text', 'wrapper' => array( 'width' => 25 ), 'conditional_logic' => epc_when( 'field_epc_banner_show_meta', 1 ) ),
				array( 'key' => 'field_epc_banner_venue', 'label' => 'Venue', 'name' => 'venue', 'type' => 'text', 'wrapper' => array( 'width' => 25 ), 'conditional_logic' => epc_when( 'field_epc_banner_show_meta', 1 ) ),
				array( 'key' => 'field_epc_banner_countdown', 'label' => 'Countdown', 'name' => 'countdown', 'type' => 'true_false', 'ui' => 1, 'wrapper' => array( 'width' => 25 ) ),
				array( 'key' => 'field_epc_banner_count_to', 'label' => 'Count down to', 'name' => 'countdown_to', 'type' => 'date_time_picker', 'return_format' => 'Y-m-d H:i:s', 'instructions' => 'Empty = event start.', 'wrapper' => array( 'width' => 25 ), 'conditional_logic' => epc_when( 'field_epc_banner_countdown', 1 ) ),
				array( 'key' => 'field_epc_banner_height', 'label' => 'Height', 'name' => 'height', 'type' => 'select', 'choices' => array( 'auto' => 'Fit content', 'md' => 'Medium (60vh)', 'full' => 'Full screen' ), 'default_value' => 'md', 'wrapper' => array( 'width' => 25 ) ),
				array( 'key' => 'field_epc_banner_text_align', 'label' => 'Align', 'name' => 'text_align', 'type' => 'button_group', 'choices' => array( 'center' => 'Center', 'left' => 'Left' ), 'default_value' => 'center', 'wrapper' => array( 'width' => 25 ) ),
				epc_buttons_field( 'banner' ),
			)
		),
		epc_layout(
			'text',
			'Text / essay',
			array_merge(
				epc_heading_fields( 'text', false ),
				array(
					array( 'key' => 'field_epc_text_content', 'label' => 'Content', 'name' => 'content', 'type' => 'wysiwyg', 'tabs' => 'all', 'toolbar' => 'full', 'media_upload' => 1, 'delay' => 1 ),
					array( 'key' => 'field_epc_text_columns', 'label' => 'Columns', 'name' => 'columns', 'type' => 'button_group', 'choices' => array( '1' => '1', '2' => '2' ), 'default_value' => '1' ),
				)
			)
		),
		epc_layout(
			'image_text',
			'Image + text',
			array_merge(
				epc_heading_fields( 'image_text', false ),
				array(
					array( 'key' => 'field_epc_image_text_image', 'label' => 'Image', 'name' => 'image', 'type' => 'image', 'return_format' => 'array', 'preview_size' => 'medium', 'wrapper' => array( 'width' => 35 ) ),
					array( 'key' => 'field_epc_image_text_content', 'label' => 'Text', 'name' => 'content', 'type' => 'wysiwyg', 'toolbar' => 'full', 'media_upload' => 0, 'delay' => 1, 'wrapper' => array( 'width' => 65 ) ),
					array( 'key' => 'field_epc_image_text_pos', 'label' => 'Image position', 'name' => 'image_position', 'type' => 'button_group', 'choices' => array( 'left' => 'Left', 'right' => 'Right' ), 'default_value' => 'left', 'wrapper' => array( 'width' => 50 ) ),
					array( 'key' => 'field_epc_image_text_ratio', 'label' => 'Image size', 'name' => 'image_ratio', 'type' => 'button_group', 'choices' => array( '40' => 'Small', '50' => 'Half' ), 'default_value' => '50', 'wrapper' => array( 'width' => 50 ) ),
					epc_buttons_field( 'image_text' ),
				)
			)
		),
		epc_layout(
			'image',
			'Image',
			array(
				array( 'key' => 'field_epc_image_image', 'label' => 'Image', 'name' => 'image', 'type' => 'image', 'return_format' => 'array', 'preview_size' => 'medium', 'wrapper' => array( 'width' => 40 ) ),
				array( 'key' => 'field_epc_image_image_m', 'label' => 'Mobile image (optional)', 'name' => 'image_mobile', 'type' => 'image', 'return_format' => 'array', 'preview_size' => 'thumbnail', 'wrapper' => array( 'width' => 30 ) ),
				array( 'key' => 'field_epc_image_link', 'label' => 'Link', 'name' => 'link', 'type' => 'url', 'wrapper' => array( 'width' => 30 ) ),
				array( 'key' => 'field_epc_image_caption', 'label' => 'Caption', 'name' => 'caption', 'type' => 'text' ),
			)
		),
		epc_layout(
			'gallery',
			'Image gallery',
			array_merge(
				epc_heading_fields( 'gallery' ),
				array(
					array( 'key' => 'field_epc_gallery_images', 'label' => 'Images', 'name' => 'images', 'type' => 'gallery', 'return_format' => 'array', 'preview_size' => 'thumbnail' ),
					array( 'key' => 'field_epc_gallery_style', 'label' => 'Style', 'name' => 'style', 'type' => 'button_group', 'choices' => array( 'grid' => 'Grid', 'masonry' => 'Masonry', 'carousel' => 'Slider' ), 'default_value' => 'grid', 'wrapper' => array( 'width' => 50 ) ),
					array( 'key' => 'field_epc_gallery_cols', 'label' => 'Columns', 'name' => 'columns', 'type' => 'button_group', 'choices' => array( '2' => '2', '3' => '3', '4' => '4', '5' => '5' ), 'default_value' => '3', 'wrapper' => array( 'width' => 50 ) ),
				)
			)
		),
		epc_layout(
			'speakers',
			'Speakers / people',
			array_merge(
				epc_heading_fields( 'speakers' ),
				array(
					array( 'key' => 'field_epc_speakers_style', 'label' => 'Style', 'name' => 'style', 'type' => 'button_group', 'choices' => array( 'cards' => 'Cards (bio in pop-up)', 'list' => 'List with full bio' ), 'default_value' => 'cards' ),
					array(
						'key'          => 'field_epc_speakers_items',
						'label'        => 'People',
						'name'         => 'items',
						'type'         => 'repeater',
						'layout'       => 'block',
						'collapsed'    => 'field_epc_speakers_name',
						'button_label' => 'Add person',
						'sub_fields'   => array(
							array( 'key' => 'field_epc_speakers_photo', 'label' => 'Photo', 'name' => 'photo', 'type' => 'image', 'return_format' => 'array', 'preview_size' => 'thumbnail', 'wrapper' => array( 'width' => 20 ) ),
							array( 'key' => 'field_epc_speakers_name', 'label' => 'Name', 'name' => 'name', 'type' => 'text', 'wrapper' => array( 'width' => 40 ) ),
							array( 'key' => 'field_epc_speakers_role', 'label' => 'Title / organisation', 'name' => 'role', 'type' => 'text', 'wrapper' => array( 'width' => 40 ) ),
							array( 'key' => 'field_epc_speakers_bio', 'label' => 'Bio', 'name' => 'bio', 'type' => 'wysiwyg', 'toolbar' => 'basic', 'media_upload' => 0, 'delay' => 1 ),
						),
					),
				)
			)
		),
		epc_layout(
			'agenda',
			'Programme / agenda',
			array_merge(
				epc_heading_fields( 'agenda' ),
				array(
					array(
						'key'          => 'field_epc_agenda_items',
						'label'        => 'Items',
						'name'         => 'items',
						'type'         => 'repeater',
						'layout'       => 'table',
						'button_label' => 'Add item',
						'sub_fields'   => array(
							array( 'key' => 'field_epc_agenda_time', 'label' => 'Time', 'name' => 'time', 'type' => 'text', 'wrapper' => array( 'width' => 18 ) ),
							array( 'key' => 'field_epc_agenda_title', 'label' => 'Title', 'name' => 'title', 'type' => 'text', 'wrapper' => array( 'width' => 32 ) ),
							array( 'key' => 'field_epc_agenda_desc', 'label' => 'Details / speaker', 'name' => 'description', 'type' => 'textarea', 'rows' => 2, 'new_lines' => 'br' ),
						),
					),
				)
			)
		),
		epc_layout(
			'cards',
			'Cards (hotels, info boxes…)',
			array_merge(
				epc_heading_fields( 'cards' ),
				array(
					array( 'key' => 'field_epc_cards_cols', 'label' => 'Columns', 'name' => 'columns', 'type' => 'button_group', 'choices' => array( '1' => '1', '2' => '2', '3' => '3', '4' => '4' ), 'default_value' => '3' ),
					array(
						'key'          => 'field_epc_cards_items',
						'label'        => 'Cards',
						'name'         => 'items',
						'type'         => 'repeater',
						'layout'       => 'block',
						'collapsed'    => 'field_epc_cards_title',
						'button_label' => 'Add card',
						'sub_fields'   => array(
							array( 'key' => 'field_epc_cards_image', 'label' => 'Image', 'name' => 'image', 'type' => 'image', 'return_format' => 'array', 'preview_size' => 'thumbnail', 'wrapper' => array( 'width' => 20 ) ),
							array( 'key' => 'field_epc_cards_title', 'label' => 'Title', 'name' => 'title', 'type' => 'text', 'wrapper' => array( 'width' => 40 ) ),
							array( 'key' => 'field_epc_cards_link', 'label' => 'Link', 'name' => 'link', 'type' => 'link', 'wrapper' => array( 'width' => 40 ) ),
							array( 'key' => 'field_epc_cards_content', 'label' => 'Content', 'name' => 'content', 'type' => 'wysiwyg', 'toolbar' => 'basic', 'media_upload' => 0, 'delay' => 1 ),
						),
					),
				)
			)
		),
		epc_layout(
			'posts',
			'News / blog / articles',
			array_merge(
				epc_heading_fields( 'posts' ),
				array(
					array( 'key' => 'field_epc_posts_source', 'label' => 'Show', 'name' => 'source', 'type' => 'button_group', 'choices' => array( 'latest' => 'Latest posts', 'category' => 'From category', 'manual' => 'Pick posts' ), 'default_value' => 'latest' ),
					array( 'key' => 'field_epc_posts_cat', 'label' => 'Category', 'name' => 'category', 'type' => 'taxonomy', 'taxonomy' => 'category', 'field_type' => 'select', 'return_format' => 'id', 'add_term' => 0, 'conditional_logic' => epc_when( 'field_epc_posts_source', 'category' ) ),
					array( 'key' => 'field_epc_posts_manual', 'label' => 'Posts', 'name' => 'posts', 'type' => 'relationship', 'post_type' => array( 'post' ), 'return_format' => 'id', 'filters' => array( 'search', 'taxonomy' ), 'conditional_logic' => epc_when( 'field_epc_posts_source', 'manual' ) ),
					array( 'key' => 'field_epc_posts_count', 'label' => 'Number of posts', 'name' => 'count', 'type' => 'number', 'default_value' => 3, 'min' => 1, 'max' => 24, 'conditional_logic' => epc_when( 'field_epc_posts_source', 'manual', '!=' ) ),
				)
			)
		),
		epc_layout(
			'logos',
			'Logos / sponsors',
			array_merge(
				epc_heading_fields( 'logos', false ),
				array( array( 'key' => 'field_epc_logos_images', 'label' => 'Logos', 'name' => 'images', 'type' => 'gallery', 'return_format' => 'array', 'preview_size' => 'thumbnail' ) )
			)
		),
		epc_layout(
			'video',
			'Video',
			array_merge(
				epc_heading_fields( 'video' ),
				array( array( 'key' => 'field_epc_video_embed', 'label' => 'Video URL', 'name' => 'embed', 'type' => 'oembed', 'instructions' => 'YouTube, Vimeo, etc.' ) )
			)
		),
		epc_layout(
			'contact',
			'Contact persons',
			array_merge(
				epc_heading_fields( 'contact' ),
				array(
					array(
						'key'          => 'field_epc_contact_items',
						'label'        => 'Contacts',
						'name'         => 'items',
						'type'         => 'repeater',
						'layout'       => 'table',
						'button_label' => 'Add contact',
						'sub_fields'   => array(
							array( 'key' => 'field_epc_contact_type', 'label' => 'Type', 'name' => 'type', 'type' => 'select', 'choices' => array( 'whatsapp' => 'WhatsApp', 'phone' => 'Phone', 'email' => 'Email', 'link' => 'Link' ) ),
							array( 'key' => 'field_epc_contact_name', 'label' => 'Name / label', 'name' => 'name', 'type' => 'text' ),
							array( 'key' => 'field_epc_contact_value', 'label' => 'Number / email / URL', 'name' => 'value', 'type' => 'text' ),
						),
					),
				)
			)
		),
		epc_layout(
			'form',
			'Registration form (embed)',
			array_merge(
				epc_heading_fields( 'form' ),
				array( array( 'key' => 'field_epc_form_event', 'label' => 'Event', 'name' => 'event', 'type' => 'post_object', 'post_type' => array( EPC_Post_Types::EVENT ), 'return_format' => 'id', 'ui' => 1, 'required' => 1 ) )
			)
		),
		epc_layout(
			'html',
			'Custom HTML / embed',
			array( array( 'key' => 'field_epc_html_code', 'label' => 'HTML', 'name' => 'code', 'type' => 'textarea', 'rows' => 6, 'instructions' => 'Maps, iframes, widgets. Scripts are stripped for non-admin editors.' ) )
		),
	);

	acf_add_local_field_group(
		array(
			'key'            => 'group_epc_microsite',
			'title'          => 'Microsite',
			'location'       => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => EPC_Post_Types::MICROSITE ) ) ),
			'position'       => 'acf_after_title',
			'style'          => 'seamless',
			'hide_on_screen' => array( 'the_content', 'excerpt', 'discussion', 'comments', 'author', 'format' ),
			'fields'         => array(
				array( 'key' => 'field_epc_ms_tab_sections', 'label' => 'Page sections', 'type' => 'tab' ),
				array(
					'key'          => 'field_epc_ms_sections',
					'label'        => 'Sections',
					'name'         => 'sections',
					'type'         => 'flexible_content',
					'button_label' => 'Add section',
					'layouts'      => $layouts,
				),
				array( 'key' => 'field_epc_ms_tab_general', 'label' => 'General', 'type' => 'tab' ),
				array( 'key' => 'field_epc_ms_event', 'label' => 'Linked event', 'name' => 'linked_event', 'type' => 'post_object', 'post_type' => array( EPC_Post_Types::EVENT ), 'return_format' => 'id', 'ui' => 1, 'allow_null' => 1, 'instructions' => 'Banner date/time/venue fall back to this event. Visits & registrations are reported together.', 'wrapper' => array( 'width' => 50 ) ),
				array( 'key' => 'field_epc_ms_accent', 'label' => 'Accent colour', 'name' => 'accent_color', 'type' => 'color_picker', 'instructions' => 'Empty = brand colour.', 'wrapper' => array( 'width' => 25 ) ),
				array( 'key' => 'field_epc_ms_page_bg', 'label' => 'Page background', 'name' => 'page_bg', 'type' => 'color_picker', 'instructions' => 'Empty = brand page colour.', 'wrapper' => array( 'width' => 25 ) ),
				array( 'key' => 'field_epc_ms_nav', 'label' => 'Show top menu', 'name' => 'show_nav', 'type' => 'true_false', 'ui' => 1, 'default_value' => 1, 'instructions' => 'Menu items come from each section\'s "Menu label".', 'wrapper' => array( 'width' => 25 ) ),
				array( 'key' => 'field_epc_ms_logo', 'label' => 'Menu logo', 'name' => 'nav_logo', 'type' => 'image', 'return_format' => 'array', 'preview_size' => 'thumbnail', 'wrapper' => array( 'width' => 25 ) ),
				array( 'key' => 'field_epc_ms_cta', 'label' => 'Menu button', 'name' => 'nav_cta', 'type' => 'link', 'instructions' => 'e.g. Register Now.', 'wrapper' => array( 'width' => 25 ) ),
				array( 'key' => 'field_epc_ms_footer', 'label' => 'Footer text', 'name' => 'footer_text', 'type' => 'text', 'default_value' => '© Copyright Protected. All Rights Reserved.', 'wrapper' => array( 'width' => 25 ) ),
				array( 'key' => 'field_epc_ms_anim', 'label' => 'Scroll animations', 'name' => 'animations', 'type' => 'true_false', 'ui' => 1, 'default_value' => 1, 'wrapper' => array( 'width' => 25 ) ),
				array( 'key' => 'field_epc_ms_css', 'label' => 'Custom CSS', 'name' => 'custom_css', 'type' => 'textarea', 'rows' => 4, 'instructions' => 'Optional, applies to this microsite only.' ),
			),
		)
	);
} );
