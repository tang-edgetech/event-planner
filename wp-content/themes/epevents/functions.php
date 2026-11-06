<?php
/**
 * EP Events theme bootstrap.
 */

defined( 'ABSPATH' ) || exit;

define( 'EPT_VERSION', '1.3.0' );

require_once get_template_directory() . '/inc/template-helpers.php';
require_once get_template_directory() . '/inc/branding.php';

add_action( 'after_setup_theme', function () {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'custom-logo', array( 'height' => 80, 'width' => 240, 'flex-width' => true, 'flex-height' => true ) );
	add_theme_support( 'html5', array( 'search-form', 'gallery', 'caption', 'style', 'script' ) );
	add_theme_support( 'responsive-embeds' );
	register_nav_menus(
		array(
			'primary' => 'Primary menu',
			'footer'  => 'Footer menu',
		)
	);
	add_image_size( 'ep-card', 720, 480, true );
	add_image_size( 'ep-portrait', 480, 600, true );
} );

add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_style( 'ep-main', get_template_directory_uri() . '/assets/css/main.css', array(), EPT_VERSION );
	wp_enqueue_script( 'ep-main', get_template_directory_uri() . '/assets/js/main.js', array(), EPT_VERSION, array( 'strategy' => 'defer', 'in_footer' => true ) );
	// Form styles are needed on any page that may embed a form.
	if ( wp_style_is( 'epc-form', 'registered' ) ) {
		wp_enqueue_style( 'epc-form' );
	}
} );

add_action( 'wp_head', function () {
	echo '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
}, 1 );

/** Per-page accent / background colours for events & microsites. */
add_action( 'wp_head', function () {
	if ( ! is_singular( array( 'ep_event', 'ep_microsite' ) ) || ! function_exists( 'get_field' ) ) {
		return;
	}
	$id     = get_queried_object_id();
	$accent = get_field( 'accent_color', $id );
	$bg     = get_field( 'page_bg', $id );
	$vars   = '';
	if ( $accent ) {
		$vars .= '--ep-accent:' . sanitize_hex_color( $accent ) . ';--ep-accent-contrast:' . ept_contrast( $accent ) . ';';
	}
	if ( $bg ) {
		$vars .= '--ep-page:' . sanitize_hex_color( $bg ) . ';';
	}
	if ( $vars ) {
		echo '<style id="ep-page-vars">:root{' . esc_html( $vars ) . '}</style>' . "\n";
	}
	$css = get_field( 'custom_css', $id );
	if ( $css ) {
		echo '<style id="ep-custom-css">' . wp_strip_all_tags( $css ) . '</style>' . "\n"; // phpcs:ignore
	}
}, 20 );

/** Microsites & events are campaign pages: keep them out of search results by default listing. */
add_filter( 'body_class', function ( $classes ) {
	if ( is_singular( 'ep_microsite' ) ) {
		$classes[] = 'is-microsite';
		if ( function_exists( 'get_field' ) && get_field( 'animations', get_queried_object_id() ) !== false ) {
			$classes[] = 'has-animations';
		}
	}
	if ( is_singular( 'ep_event' ) ) {
		$classes[] = 'is-event';
	}
	return $classes;
} );

/** Allow editors to embed iframes in the HTML section; strip scripts for non-unfiltered users. */
function ept_html_section( string $code ): string {
	if ( current_user_can( 'unfiltered_html' ) || user_can( (int) get_post_field( 'post_author', get_the_ID() ), 'unfiltered_html' ) ) {
		return $code;
	}
	$allowed           = wp_kses_allowed_html( 'post' );
	$allowed['iframe'] = array( 'src' => true, 'width' => true, 'height' => true, 'style' => true, 'allow' => true, 'allowfullscreen' => true, 'loading' => true, 'frameborder' => true, 'title' => true );
	return wp_kses( $code, $allowed );
}
