<?php
/**
 * Branding (white label): logo, colours, fonts, shapes, login page, admin and email branding.
 * Appearance → Branding. Every value has a default so the site looks finished before anything is set.
 */

defined( 'ABSPATH' ) || exit;

/** Default brand settings. Change here to change the out-of-the-box look. */
function ept_brand_defaults(): array {
	return array(
		'brand_logo'           => null,
		'brand_logo_light'     => null,
		'brand_logo_height'    => 40,
		'brand_favicon'        => null,
		'brand_copyright'      => '© {year} {site}. All rights reserved.',
		'brand_primary'        => '#6d1a45', // Plum: confident, not tied to one pharma client.
		'brand_secondary'      => '#e0a23a', // Amber: highlights and countdowns.
		'brand_page'           => '#f7f5f2',
		'brand_surface'        => '#ffffff',
		'brand_text'           => '#1c1a1f',
		'brand_muted'          => '#6b6570',
		'brand_border'         => '#e4dfe3',
		'brand_footer_bg'      => '#1c1a1f',
		'brand_footer_text'    => '#d9d4da',
		'brand_font_heading'   => 'Plus Jakarta Sans',
		'brand_font_body'      => 'Plus Jakarta Sans',
		'brand_font_custom'    => '',
		'brand_font_custom_url' => '',
		'brand_font_size'      => '16',
		'brand_heading_weight' => '700',
		'brand_corners'        => 'soft',
		'brand_buttons'        => 'pill',
		'login_logo'           => null,
		'login_bg_color'       => '',
		'login_bg_image'       => null,
		'login_message'        => '',
		'wl_enable'            => 1,
		'wl_footer'            => '{site} · Event registration platform',
		'wl_welcome'           => '',
		'email_branding'       => 1,
		'email_footer'         => 'You received this email because you registered on {site}.',
	);
}

/** A brand setting with its default. */
function ept_brand( string $name ) {
	$defaults = ept_brand_defaults();
	$value    = function_exists( 'get_field' ) ? get_field( $name, 'option' ) : null;
	if ( null === $value || '' === $value || ( false === $value && ! in_array( $name, array( 'wl_enable', 'email_branding' ), true ) ) ) {
		return $defaults[ $name ] ?? null;
	}
	return $value;
}

/** Replace {site} and {year} in brand texts. */
function ept_brand_text( string $name ): string {
	return strtr( (string) ept_brand( $name ), array( '{site}' => get_bloginfo( 'name' ), '{year}' => wp_date( 'Y' ) ) );
}

/** Curated Google Fonts with the weights each one supports. */
function ept_font_catalogue(): array {
	return array(
		'Plus Jakarta Sans' => array( 'w' => '400;600;700;800', 'kind' => 'sans' ),
		'Manrope'           => array( 'w' => '400;600;700;800', 'kind' => 'sans' ),
		'DM Sans'           => array( 'w' => '400;600;700;800', 'kind' => 'sans' ),
		'Outfit'            => array( 'w' => '400;600;700;800', 'kind' => 'sans' ),
		'Lexend'            => array( 'w' => '400;600;700;800', 'kind' => 'sans' ),
		'Poppins'           => array( 'w' => '400;600;700;800', 'kind' => 'sans' ),
		'Montserrat'        => array( 'w' => '400;600;700;800', 'kind' => 'sans' ),
		'Work Sans'         => array( 'w' => '400;600;700;800', 'kind' => 'sans' ),
		'Nunito Sans'       => array( 'w' => '400;600;700;800', 'kind' => 'sans' ),
		'Source Sans 3'     => array( 'w' => '400;600;700;800', 'kind' => 'sans' ),
		'IBM Plex Sans'     => array( 'w' => '400;600;700', 'kind' => 'sans' ),
		'Noto Sans'         => array( 'w' => '400;600;700;800', 'kind' => 'sans' ),
		'Open Sans'         => array( 'w' => '400;600;700;800', 'kind' => 'sans' ),
		'Roboto'            => array( 'w' => '400;500;700;900', 'kind' => 'sans' ),
		'Lato'              => array( 'w' => '400;700;900', 'kind' => 'sans' ),
		'Playfair Display'  => array( 'w' => '400;600;700;800', 'kind' => 'serif' ),
		'Lora'              => array( 'w' => '400;600;700', 'kind' => 'serif' ),
		'Merriweather'      => array( 'w' => '400;700;900', 'kind' => 'serif' ),
	);
}

function ept_font_choices(): array {
	$choices = array();
	foreach ( array_keys( ept_font_catalogue() ) as $name ) {
		$choices[ $name ] = $name;
	}
	$choices['system'] = 'System font (fastest, no download)';
	$choices['custom'] = 'Custom font (enter below)';
	return $choices;
}

/** CSS font-family value for a chosen font. */
function ept_font_stack( string $font ): string {
	$sans  = "system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif";
	$serif = "Georgia, 'Times New Roman', serif";
	if ( 'system' === $font ) {
		return $sans;
	}
	if ( 'custom' === $font ) {
		$name = trim( preg_replace( '/[^A-Za-z0-9 \-]/', '', (string) ept_brand( 'brand_font_custom' ) ) );
		return $name ? "'" . $name . "', " . $sans : $sans;
	}
	$cat = ept_font_catalogue();
	return "'" . $font . "', " . ( 'serif' === ( $cat[ $font ]['kind'] ?? 'sans' ) ? $serif : $sans );
}

/** Stylesheet URLs needed for the chosen fonts. */
function ept_font_urls(): array {
	$cat      = ept_font_catalogue();
	$families = array();
	$urls     = array();
	foreach ( array_unique( array( (string) ept_brand( 'brand_font_heading' ), (string) ept_brand( 'brand_font_body' ) ) ) as $font ) {
		if ( isset( $cat[ $font ] ) ) {
			$families[] = 'family=' . str_replace( ' ', '+', $font ) . ':wght@' . $cat[ $font ]['w'];
		} elseif ( 'custom' === $font && ept_brand( 'brand_font_custom_url' ) ) {
			$urls[] = esc_url_raw( (string) ept_brand( 'brand_font_custom_url' ) );
		}
	}
	if ( $families ) {
		array_unshift( $urls, 'https://fonts.googleapis.com/css2?' . implode( '&', $families ) . '&display=swap' );
	}
	return array_unique( $urls );
}

/** The CSS custom properties that skin the whole site. */
function ept_brand_css(): string {
	$c = function ( string $name ): string {
		return sanitize_hex_color( (string) ept_brand( $name ) ) ?: (string) ept_brand_defaults()[ $name ];
	};
	$corners = array(
		'square' => array( '4px', '4px' ),
		'soft'   => array( '14px', '8px' ),
		'round'  => array( '22px', '12px' ),
	)[ ept_brand( 'brand_corners' ) ] ?? array( '14px', '8px' );
	$buttons = array(
		'pill'    => '999px',
		'rounded' => '10px',
		'square'  => '4px',
	)[ ept_brand( 'brand_buttons' ) ] ?? '999px';
	$size    = in_array( (string) ept_brand( 'brand_font_size' ), array( '15', '16', '17', '18' ), true ) ? ept_brand( 'brand_font_size' ) : '16';
	$weight  = in_array( (string) ept_brand( 'brand_heading_weight' ), array( '600', '700', '800' ), true ) ? ept_brand( 'brand_heading_weight' ) : '700';

	$vars = array(
		'--ep-accent'          => $c( 'brand_primary' ),
		'--ep-accent-contrast' => ept_contrast( $c( 'brand_primary' ) ),
		'--ep-secondary'       => $c( 'brand_secondary' ),
		'--ep-page'            => $c( 'brand_page' ),
		'--ep-surface'         => $c( 'brand_surface' ),
		'--ep-text'            => $c( 'brand_text' ),
		'--ep-muted'           => $c( 'brand_muted' ),
		'--ep-border'          => $c( 'brand_border' ),
		'--ep-footer-bg'       => $c( 'brand_footer_bg' ),
		'--ep-footer-text'     => $c( 'brand_footer_text' ),
		'--ep-font'            => ept_font_stack( (string) ept_brand( 'brand_font_body' ) ),
		'--ep-font-heading'    => ept_font_stack( (string) ept_brand( 'brand_font_heading' ) ),
		'--ep-heading-weight'  => $weight,
		'--ep-radius'          => $corners[0],
		'--ep-input-radius'    => $corners[1],
		'--ep-btn-radius'      => $buttons,
		'--ep-logo-height'     => max( 20, min( 120, (int) ept_brand( 'brand_logo_height' ) ) ) . 'px',
	);
	$css = ':root{';
	foreach ( $vars as $k => $v ) {
		$css .= $k . ':' . $v . ';';
	}
	return $css . '}html{font-size:' . $size . 'px}';
}

/** Logo markup; falls back to the WordPress custom logo, then the site name. */
function ept_logo_html( bool $on_dark = false, string $class = 'ep-brand__logo' ): string {
	$logo = $on_dark ? ( ept_brand( 'brand_logo_light' ) ?: ept_brand( 'brand_logo' ) ) : ept_brand( 'brand_logo' );
	if ( is_array( $logo ) && ! empty( $logo['ID'] ) ) {
		return wp_get_attachment_image( $logo['ID'], 'medium', false, array( 'class' => $class, 'alt' => get_bloginfo( 'name' ) ) );
	}
	if ( has_custom_logo() ) {
		return wp_get_attachment_image( (int) get_theme_mod( 'custom_logo' ), 'medium', false, array( 'class' => $class, 'alt' => get_bloginfo( 'name' ) ) );
	}
	return '<span class="ep-brand__text">' . esc_html( get_bloginfo( 'name' ) ) . '</span>';
}

function ept_logo_url(): string {
	$logo = ept_brand( 'login_logo' ) ?: ept_brand( 'brand_logo' );
	if ( is_array( $logo ) && ! empty( $logo['url'] ) ) {
		return $logo['url'];
	}
	return has_custom_logo() ? (string) wp_get_attachment_image_url( (int) get_theme_mod( 'custom_logo' ), 'medium' ) : '';
}

/* -------------------------------------------------------------------------
 * Options page + fields
 * ---------------------------------------------------------------------- */

add_action( 'acf/init', function () {
	if ( ! function_exists( 'acf_add_options_sub_page' ) ) {
		return;
	}
	acf_add_options_sub_page(
		array(
			'page_title'  => 'Branding',
			'menu_title'  => 'Branding',
			'menu_slug'   => 'ept-branding',
			'parent_slug' => 'themes.php',
			'capability'  => 'edit_theme_options',
			'position'    => 1,
		)
	);

	$d     = ept_brand_defaults();
	$color = fn( $key, $label, $help = '', $w = 33 ) => array( 'key' => 'field_ept_' . $key, 'label' => $label, 'name' => $key, 'type' => 'color_picker', 'default_value' => $d[ $key ], 'instructions' => $help, 'wrapper' => array( 'width' => $w ) );
	$image = fn( $key, $label, $help = '', $w = 33 ) => array( 'key' => 'field_ept_' . $key, 'label' => $label, 'name' => $key, 'type' => 'image', 'return_format' => 'array', 'preview_size' => 'medium', 'library' => 'all', 'instructions' => $help, 'wrapper' => array( 'width' => $w ) );

	acf_add_local_field_group(
		array(
			'key'      => 'group_ept_branding',
			'title'    => 'Branding',
			'location' => array( array( array( 'param' => 'options_page', 'operator' => '==', 'value' => 'ept-branding' ) ) ),
			'style'    => 'seamless',
			'fields'   => array(
				array( 'key' => 'field_ept_preview', 'label' => '', 'type' => 'message', 'message' => '<div id="ept-brand-preview"></div>', 'esc_html' => 0 ),

				array( 'key' => 'field_ept_tab_identity', 'label' => 'Logo', 'type' => 'tab' ),
				$image( 'brand_logo', 'Logo', 'SVG or PNG with a transparent background, at least 400 px wide. Empty = site name in text.' ),
				$image( 'brand_logo_light', 'Logo for dark backgrounds', 'Optional white version, used in the footer.' ),
				$image( 'brand_favicon', 'Browser icon (favicon)', 'Square, at least 512 × 512 px. Used when no Site Icon is set in Settings.' ),
				array( 'key' => 'field_ept_brand_logo_height', 'label' => 'Logo height in the header', 'name' => 'brand_logo_height', 'type' => 'range', 'min' => 20, 'max' => 120, 'step' => 2, 'default_value' => $d['brand_logo_height'], 'append' => 'px', 'wrapper' => array( 'width' => 50 ) ),
				array( 'key' => 'field_ept_brand_copyright', 'label' => 'Footer copyright', 'name' => 'brand_copyright', 'type' => 'text', 'default_value' => $d['brand_copyright'], 'instructions' => '{year} and {site} are filled in automatically. Microsites can override this.', 'wrapper' => array( 'width' => 50 ) ),

				array( 'key' => 'field_ept_tab_colours', 'label' => 'Colours', 'type' => 'tab' ),
				array( 'key' => 'field_ept_colours_msg', 'type' => 'message', 'message' => 'Events and microsites can still set their own accent colour; these are the defaults for everything else.' ),
				$color( 'brand_primary', 'Primary', 'Buttons, links, highlights. Button text turns white or black automatically.' ),
				$color( 'brand_secondary', 'Secondary', 'Small highlights and badges.' ),
				$color( 'brand_page', 'Page background' ),
				$color( 'brand_surface', 'Cards and forms' ),
				$color( 'brand_text', 'Text' ),
				$color( 'brand_muted', 'Secondary text' ),
				$color( 'brand_border', 'Lines and borders' ),
				$color( 'brand_footer_bg', 'Footer background' ),
				$color( 'brand_footer_text', 'Footer text' ),

				array( 'key' => 'field_ept_tab_type', 'label' => 'Fonts', 'type' => 'tab' ),
				array( 'key' => 'field_ept_brand_font_heading', 'label' => 'Headings', 'name' => 'brand_font_heading', 'type' => 'select', 'choices' => ept_font_choices(), 'default_value' => $d['brand_font_heading'], 'ui' => 1, 'wrapper' => array( 'width' => 50 ) ),
				array( 'key' => 'field_ept_brand_font_body', 'label' => 'Body text', 'name' => 'brand_font_body', 'type' => 'select', 'choices' => ept_font_choices(), 'default_value' => $d['brand_font_body'], 'ui' => 1, 'wrapper' => array( 'width' => 50 ) ),
				array(
					'key'               => 'field_ept_brand_font_custom',
					'label'             => 'Custom font name',
					'name'              => 'brand_font_custom',
					'type'              => 'text',
					'placeholder'       => 'Brand Sans',
					'instructions'      => 'Exactly as written in the font\'s CSS (font-family).',
					'wrapper'           => array( 'width' => 50 ),
					'conditional_logic' => array( array( array( 'field' => 'field_ept_brand_font_heading', 'operator' => '==', 'value' => 'custom' ) ), array( array( 'field' => 'field_ept_brand_font_body', 'operator' => '==', 'value' => 'custom' ) ) ),
				),
				array(
					'key'               => 'field_ept_brand_font_custom_url',
					'label'             => 'Custom font stylesheet URL',
					'name'              => 'brand_font_custom_url',
					'type'              => 'url',
					'placeholder'       => 'https://use.typekit.net/xxxxxxx.css',
					'instructions'      => 'A CSS file that loads the font (Adobe Fonts, Google Fonts, or your own host).',
					'wrapper'           => array( 'width' => 50 ),
					'conditional_logic' => array( array( array( 'field' => 'field_ept_brand_font_heading', 'operator' => '==', 'value' => 'custom' ) ), array( array( 'field' => 'field_ept_brand_font_body', 'operator' => '==', 'value' => 'custom' ) ) ),
				),
				array( 'key' => 'field_ept_brand_font_size', 'label' => 'Text size', 'name' => 'brand_font_size', 'type' => 'button_group', 'choices' => array( '15' => 'Small', '16' => 'Normal', '17' => 'Large', '18' => 'Extra large' ), 'default_value' => $d['brand_font_size'], 'wrapper' => array( 'width' => 50 ) ),
				array( 'key' => 'field_ept_brand_heading_weight', 'label' => 'Heading weight', 'name' => 'brand_heading_weight', 'type' => 'button_group', 'choices' => array( '600' => 'Semibold', '700' => 'Bold', '800' => 'Extra bold' ), 'default_value' => $d['brand_heading_weight'], 'wrapper' => array( 'width' => 50 ) ),

				array( 'key' => 'field_ept_tab_shape', 'label' => 'Shapes', 'type' => 'tab' ),
				array( 'key' => 'field_ept_brand_corners', 'label' => 'Corners on cards, images and fields', 'name' => 'brand_corners', 'type' => 'button_group', 'choices' => array( 'square' => 'Square', 'soft' => 'Soft', 'round' => 'Round' ), 'default_value' => $d['brand_corners'], 'wrapper' => array( 'width' => 50 ) ),
				array( 'key' => 'field_ept_brand_buttons', 'label' => 'Buttons', 'name' => 'brand_buttons', 'type' => 'button_group', 'choices' => array( 'pill' => 'Pill', 'rounded' => 'Rounded', 'square' => 'Square' ), 'default_value' => $d['brand_buttons'], 'wrapper' => array( 'width' => 50 ) ),

				array( 'key' => 'field_ept_tab_login', 'label' => 'Login page', 'type' => 'tab' ),
				$image( 'login_logo', 'Login logo', 'Empty = main logo.' ),
				$image( 'login_bg_image', 'Background image', 'Optional. Covers the whole login page.' ),
				$color( 'login_bg_color', 'Background colour', 'Empty = page background.' ),
				array( 'key' => 'field_ept_login_message', 'label' => 'Message above the form', 'name' => 'login_message', 'type' => 'textarea', 'rows' => 2, 'placeholder' => 'Event Plus staff only. Contact your Super Admin for access.' ),

				array( 'key' => 'field_ept_tab_admin', 'label' => 'Admin', 'type' => 'tab' ),
				array( 'key' => 'field_ept_wl_enable', 'label' => 'Hide WordPress branding in the admin', 'name' => 'wl_enable', 'type' => 'true_false', 'ui' => 1, 'default_value' => $d['wl_enable'], 'instructions' => 'Replaces the WordPress logo in the top bar with your logo, removes WordPress news and the welcome panel, hides the version number.' ),
				array( 'key' => 'field_ept_wl_footer', 'label' => 'Admin footer text', 'name' => 'wl_footer', 'type' => 'text', 'default_value' => $d['wl_footer'], 'conditional_logic' => array( array( array( 'field' => 'field_ept_wl_enable', 'operator' => '==', 'value' => '1' ) ) ) ),
				array( 'key' => 'field_ept_wl_welcome', 'label' => 'Dashboard welcome note', 'name' => 'wl_welcome', 'type' => 'wysiwyg', 'toolbar' => 'basic', 'media_upload' => 0, 'instructions' => 'Optional. Shown at the top of the dashboard for every user (e.g. support contact).', 'conditional_logic' => array( array( array( 'field' => 'field_ept_wl_enable', 'operator' => '==', 'value' => '1' ) ) ) ),

				array( 'key' => 'field_ept_tab_email', 'label' => 'Emails', 'type' => 'tab' ),
				array( 'key' => 'field_ept_email_branding', 'label' => 'Brand registration emails', 'name' => 'email_branding', 'type' => 'true_false', 'ui' => 1, 'default_value' => $d['email_branding'], 'instructions' => 'Adds your logo, primary colour and a footer to confirmation and notification emails.' ),
				array( 'key' => 'field_ept_email_footer', 'label' => 'Email footer', 'name' => 'email_footer', 'type' => 'text', 'default_value' => $d['email_footer'], 'conditional_logic' => array( array( array( 'field' => 'field_ept_email_branding', 'operator' => '==', 'value' => '1' ) ) ) ),
			),
		)
	);
} );

/** Live preview on the Branding page. */
add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( false === strpos( (string) $hook, 'ept-branding' ) ) {
		return;
	}
	wp_enqueue_style( 'ept-branding-admin', get_template_directory_uri() . '/assets/css/branding-admin.css', array(), EPT_VERSION );
	wp_enqueue_script( 'ept-branding-admin', get_template_directory_uri() . '/assets/js/branding-admin.js', array(), EPT_VERSION, true );
	wp_localize_script(
		'ept-branding-admin',
		'EPTBrand',
		array(
			'fonts'    => ept_font_catalogue(),
			'defaults' => ept_brand_defaults(),
			'site'     => get_bloginfo( 'name' ),
			'logo'     => ept_logo_url(),
		)
	);
} );

/* -------------------------------------------------------------------------
 * Front end
 * ---------------------------------------------------------------------- */

add_action( 'wp_enqueue_scripts', function () {
	foreach ( ept_font_urls() as $i => $url ) {
		wp_enqueue_style( 'ep-fonts-' . $i, $url, array(), null );
	}
}, 5 );

add_action( 'wp_head', function () {
	echo '<style id="ep-brand-vars">' . ept_brand_css() . '</style>' . "\n"; // phpcs:ignore -- built from sanitised values.
	$fav = ept_brand( 'brand_favicon' );
	if ( ! has_site_icon() && is_array( $fav ) && ! empty( $fav['ID'] ) ) {
		$url = wp_get_attachment_image_url( $fav['ID'], array( 512, 512 ) );
		echo '<link rel="icon" href="' . esc_url( $url ) . '" sizes="any">' . "\n";
		echo '<link rel="apple-touch-icon" href="' . esc_url( $url ) . '">' . "\n";
	}
}, 8 );

/* -------------------------------------------------------------------------
 * Login page
 * ---------------------------------------------------------------------- */

add_action( 'login_enqueue_scripts', function () {
	foreach ( ept_font_urls() as $i => $url ) {
		wp_enqueue_style( 'ep-fonts-' . $i, $url, array(), null );
	}
	$logo   = ept_logo_url();
	$bg     = sanitize_hex_color( (string) ept_brand( 'login_bg_color' ) ) ?: sanitize_hex_color( (string) ept_brand( 'brand_page' ) );
	$img    = ept_brand( 'login_bg_image' );
	$height = max( 20, min( 120, (int) ept_brand( 'brand_logo_height' ) ) ) * 1.6;
	$css    = ept_brand_css();
	$css   .= 'body.login{background:' . $bg . ( is_array( $img ) && ! empty( $img['url'] ) ? ' url(' . esc_url_raw( $img['url'] ) . ') center/cover no-repeat' : '' ) . ';font-family:var(--ep-font);color:var(--ep-text)}';
	$css   .= '.login h1 a{' . ( $logo ? 'background-image:url(' . esc_url_raw( $logo ) . ');background-size:contain;background-position:center;width:100%;height:' . (int) $height . 'px;' : 'background:none;text-indent:0;width:auto;height:auto;font:var(--ep-heading-weight) 28px/1.2 var(--ep-font-heading);color:var(--ep-text);' ) . '}';
	$css   .= '.login form{border:1px solid var(--ep-border);border-radius:var(--ep-radius);box-shadow:0 10px 30px rgba(0,0,0,.06);background:var(--ep-surface)}';
	$css   .= '.login input[type=text],.login input[type=password],.login input[type=email]{border-radius:var(--ep-input-radius);border-color:var(--ep-border)}';
	$css   .= '.login input:focus{border-color:var(--ep-accent)!important;box-shadow:0 0 0 1px var(--ep-accent)!important}';
	$css   .= '.wp-core-ui .button-primary{background:var(--ep-accent)!important;border-color:var(--ep-accent)!important;color:var(--ep-accent-contrast)!important;border-radius:var(--ep-btn-radius)!important;padding:0 18px!important;text-shadow:none!important;box-shadow:none!important}';
	$css   .= '.login #nav a,.login #backtoblog a,.login .privacy-policy-link{color:var(--ep-muted)!important}.login #nav a:hover,.login #backtoblog a:hover{color:var(--ep-accent)!important}';
	$css   .= '.login .message,.login .notice{border-left-color:var(--ep-accent);border-radius:var(--ep-input-radius)}.login .language-switcher{display:none}';
	wp_add_inline_style( 'login', $css );
} );
add_filter( 'login_headerurl', fn() => home_url( '/' ) );
add_filter( 'login_headertext', fn() => get_bloginfo( 'name' ) );
add_filter( 'login_message', function ( $message ) {
	$text = trim( (string) ept_brand( 'login_message' ) );
	return $text ? $message . '<p class="message">' . esc_html( $text ) . '</p>' : $message;
} );

/* -------------------------------------------------------------------------
 * Admin white label
 * ---------------------------------------------------------------------- */

add_action( 'admin_bar_menu', function ( WP_Admin_Bar $bar ) {
	if ( ! ept_brand( 'wl_enable' ) ) {
		return;
	}
	$bar->remove_node( 'wp-logo' );
	$logo  = ept_logo_url();
	$title = $logo ? '<img src="' . esc_url( $logo ) . '" alt="" style="height:20px;width:auto;vertical-align:middle;margin-top:-3px;filter:brightness(0) invert(1)">' : esc_html( get_bloginfo( 'name' ) );
	$bar->add_node(
		array(
			'id'    => 'ept-brand',
			'title' => $title,
			'href'  => admin_url( current_user_can( 'epc_view_dashboard' ) ? 'admin.php?page=epc' : '' ),
			'meta'  => array( 'title' => get_bloginfo( 'name' ) ),
		)
	);
}, 11 );

add_filter( 'admin_footer_text', function ( $text ) {
	return ept_brand( 'wl_enable' ) ? esc_html( ept_brand_text( 'wl_footer' ) ) : $text;
} );
add_filter( 'update_footer', function ( $text ) {
	return ept_brand( 'wl_enable' ) ? '' : $text;
}, 20 );

add_action( 'wp_dashboard_setup', function () {
	if ( ! ept_brand( 'wl_enable' ) ) {
		return;
	}
	remove_meta_box( 'dashboard_primary', 'dashboard', 'side' );
	remove_meta_box( 'dashboard_site_health', 'dashboard', 'normal' );
	remove_action( 'welcome_panel', 'wp_welcome_panel' );
	$note = (string) ept_brand( 'wl_welcome' );
	if ( trim( wp_strip_all_tags( $note ) ) ) {
		wp_add_dashboard_widget( 'ept_welcome', 'Welcome to ' . get_bloginfo( 'name' ), function () use ( $note ) {
			echo wp_kses_post( $note );
		} );
	}
} );

/** Admin accent follows the brand colour (buttons, links, active menu). */
add_action( 'admin_head', function () {
	if ( ! ept_brand( 'wl_enable' ) ) {
		return;
	}
	$p = sanitize_hex_color( (string) ept_brand( 'brand_primary' ) ) ?: '#6d1a45';
	echo '<style id="ept-admin-brand">#adminmenu li.current a.menu-top,#adminmenu li.wp-has-current-submenu a.wp-has-current-submenu,#adminmenu .wp-has-current-submenu .wp-submenu-head{background:' . esc_attr( $p ) . '}#adminmenu li a:focus div.wp-menu-image:before,#adminmenu li.opensub div.wp-menu-image:before,#adminmenu li:hover div.wp-menu-image:before{color:#fff}</style>';
} );

/* -------------------------------------------------------------------------
 * Emails (filter provided by EP Events Core)
 * ---------------------------------------------------------------------- */

add_filter( 'epc_email_html', function ( string $html ) {
	if ( ! ept_brand( 'email_branding' ) ) {
		return $html;
	}
	$p      = sanitize_hex_color( (string) ept_brand( 'brand_primary' ) ) ?: '#6d1a45';
	$ink    = ept_contrast( $p );
	$logo   = ept_logo_url();
	$font   = ept_font_stack( (string) ept_brand( 'brand_font_body' ) );
	$head   = $logo
		? '<img src="' . esc_url( $logo ) . '" alt="' . esc_attr( get_bloginfo( 'name' ) ) . '" style="max-height:40px;width:auto;display:block">'
		: '<span style="font-size:18px;font-weight:700;color:' . esc_attr( $ink ) . '">' . esc_html( get_bloginfo( 'name' ) ) . '</span>';
	$footer = esc_html( ept_brand_text( 'email_footer' ) );
	return '<div style="background:#f4f3f1;padding:24px 12px;font-family:' . esc_attr( $font ) . '">'
		. '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:10px;overflow:hidden">'
		. '<tr><td style="background:' . esc_attr( $p ) . ';padding:18px 24px">' . $head . '</td></tr>'
		. '<tr><td style="padding:24px;font-size:15px;line-height:1.6;color:#1c1a1f">' . $html . '</td></tr>'
		. '<tr><td style="padding:16px 24px;border-top:1px solid #eeeae7;font-size:12px;color:#6b6570">' . $footer . '</td></tr>'
		. '</table></div>';
} );
