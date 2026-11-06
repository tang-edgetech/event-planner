<?php
/**
 * Template helpers: section wrappers (backgrounds), buttons, modals, colour maths.
 */

defined( 'ABSPATH' ) || exit;

/** ACF returns false/null/'' for empty repeaters & galleries; normalise to an array. */
function ept_list( $value ): array {
	return is_array( $value ) ? $value : array();
}

/** Readable text colour (#fff / #111) for a background colour. */
function ept_contrast( ?string $hex ): string {
	return ept_is_dark( $hex ) ? '#ffffff' : '#141414';
}

function ept_is_dark( ?string $color ): bool {
	if ( ! $color ) {
		return false;
	}
	if ( preg_match( '/rgba?\((\d+)\s*,\s*(\d+)\s*,\s*(\d+)/', $color, $m ) ) {
		[ $r, $g, $b ] = array( (int) $m[1], (int) $m[2], (int) $m[3] );
	} else {
		$hex = ltrim( $color, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) ) {
			return false;
		}
		[ $r, $g, $b ] = array( hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) );
	}
	$lum = ( 0.2126 * $r + 0.7152 * $g + 0.0722 * $b ) / 255;
	return $lum < 0.55;
}

/** Accent colour of the current event / microsite, else the brand primary. */
function ept_accent(): string {
	$own = ( function_exists( 'get_field' ) && is_singular( array( 'ep_event', 'ep_microsite' ) ) ) ? (string) get_field( 'accent_color', get_queried_object_id() ) : '';
	return $own ?: ( function_exists( 'ept_brand' ) ? (string) ept_brand( 'brand_primary' ) : '#6d1a45' );
}

/** Modals collected while rendering, printed in the footer. */
function ept_modals( ?string $add = null ): string {
	static $modals = array();
	if ( null !== $add ) {
		$modals[] = $add;
		return '';
	}
	return implode( "\n", $modals );
}

/**
 * Open a <section> with background, text scheme, spacing and anchor applied.
 */
function ept_section_open( string $layout, array $s, string $extra = '' ): string {
	$type    = $s['bg_type'] ?? 'none';
	$style   = array();
	$classes = array( 'ep-section', 'ep-section--' . $layout, 'ep-pad-' . ( $s['padding'] ?? 'md' ), 'ep-bg-' . $type );
	$dark    = false;
	$media   = '';

	if ( in_array( $type, array( 'color', 'gradient', 'image', 'video' ), true ) && ! empty( $s['bg_color'] ) ) {
		$style[] = '--sec-bg:' . $s['bg_color'];
		$dark    = ept_is_dark( $s['bg_color'] );
	} elseif ( 'color' === $type ) {
		// No colour picked: use the page / brand accent.
		$style[] = '--sec-bg:var(--ep-accent)';
		$dark    = ept_is_dark( ept_accent() );
	}
	if ( 'gradient' === $type ) {
		$c2      = $s['bg_color2'] ?: '#000000';
		$style[] = '--sec-gradient:linear-gradient(' . (int) ( $s['bg_angle'] ?? 135 ) . 'deg,' . ( $s['bg_color'] ?: '#ffffff' ) . ',' . $c2 . ')';
		$dark    = ept_is_dark( $s['bg_color'] ) && ept_is_dark( $c2 );
	}
	if ( 'image' === $type && ! empty( $s['bg_image']['url'] ) ) {
		$style[] = '--sec-img:url(' . esc_url_raw( $s['bg_image']['url'] ) . ')';
		$style[] = '--sec-img-m:url(' . esc_url_raw( $s['bg_image_mobile']['url'] ?? $s['bg_image']['url'] ) . ')';
		$classes[] = 'ep-fit-' . ( $s['bg_fit'] ?? 'cover' );
		$dark      = true;
	}
	if ( 'video' === $type && ! empty( $s['bg_video'] ) ) {
		$media = '<video class="ep-section__video" autoplay muted loop playsinline src="' . esc_url( $s['bg_video'] ) . '"></video>';
		$dark  = true;
	}
	if ( in_array( $type, array( 'image', 'video' ), true ) && ! empty( $s['overlay'] ) ) {
		$media  .= '<div class="ep-section__overlay" style="background:' . esc_attr( $s['overlay'] ) . '"></div>';
		// A strong overlay decides the text colour; a faint one leaves the image default (light text).
		$alpha = preg_match( '/rgba\([^)]*,\s*([\d.]+)\s*\)/', $s['overlay'], $m ) ? (float) $m[1] : 1.0;
		if ( $alpha >= 0.4 ) {
			$dark = ept_is_dark( $s['overlay'] );
		}
	}

	$scheme = $s['text_scheme'] ?? 'auto';
	if ( 'custom' === $scheme && ! empty( $s['text_color'] ) ) {
		$style[] = '--sec-text:' . $s['text_color'];
	} elseif ( 'light' === $scheme || ( 'auto' === $scheme && $dark ) ) {
		$classes[] = 'is-dark';
	} elseif ( 'dark' === $scheme ) {
		$classes[] = 'is-light';
	}

	if ( $extra ) {
		$classes[] = $extra;
	}
	$id = ! empty( $s['anchor'] ) ? ' id="' . esc_attr( sanitize_title( $s['anchor'] ) ) . '"' : '';
	$st = $style ? ' style="' . esc_attr( implode( ';', $style ) ) . '"' : '';

	$container = 'ep-container ep-container--' . ( $s['container'] ?? 'default' );
	return '<section class="' . esc_attr( implode( ' ', $classes ) ) . '"' . $id . $st . '>' . $media . '<div class="' . esc_attr( $container ) . '">';
}

function ept_section_close(): string {
	return '</div></section>';
}

/** Heading + intro block shared by many sections. */
function ept_section_head( array $row ): string {
	$h     = trim( (string) ( $row['heading'] ?? '' ) );
	$intro = (string) ( $row['intro'] ?? '' );
	if ( '' === $h && '' === trim( wp_strip_all_tags( $intro ) ) ) {
		return '';
	}
	$align = 'left' === ( $row['align'] ?? 'center' ) ? 'is-left' : 'is-center';
	$out   = '<header class="ep-section__head ' . $align . ' ep-reveal">';
	if ( ! empty( $row['eyebrow'] ) ) {
		$out .= '<p class="ep-eyebrow">' . esc_html( $row['eyebrow'] ) . '</p>';
	}
	if ( $h ) {
		$out .= '<h2 class="ep-section__title">' . esc_html( $h ) . '</h2>';
	}
	if ( trim( wp_strip_all_tags( $intro ) ) ) {
		$out .= '<div class="ep-section__intro ep-prose">' . wp_kses_post( $intro ) . '</div>';
	}
	return $out . '</header>';
}

/** Render the buttons repeater. */
function ept_buttons( $buttons, string $align = 'center' ): string {
	$buttons = ept_list( $buttons );
	if ( ! $buttons ) {
		return '';
	}
	$out = '<div class="ep-buttons is-' . esc_attr( $align ) . '">';
	foreach ( $buttons as $i => $b ) {
		$label = trim( (string) ( $b['label'] ?? '' ) );
		if ( '' === $label ) {
			continue;
		}
		$cls    = 'ep-btn ep-btn--' . esc_attr( $b['style'] ?? 'outline' );
		$action = $b['action'] ?? 'url';
		$track  = ' data-ep-track="' . esc_attr( $label ) . '"';

		if ( 'modal' === $action ) {
			$mid   = 'ep-modal-' . wp_unique_id();
			$out  .= '<button type="button" class="' . $cls . '" data-ep-modal="' . esc_attr( $mid ) . '"' . $track . '>' . esc_html( $label ) . '</button>';
			$links = '';
			foreach ( ept_list( $b['modal_links'] ?? array() ) as $l ) {
				if ( ! empty( $l['url'] ) ) {
					$links .= '<a class="ep-btn ep-btn--solid" href="' . esc_url( $l['url'] ) . '" target="_blank" rel="noopener" data-ep-track="' . esc_attr( $l['label'] ) . '">' . esc_html( $l['label'] ) . '</a>';
				}
			}
			ept_modals( '<div class="ep-modal" id="' . esc_attr( $mid ) . '" role="dialog" aria-modal="true" aria-labelledby="' . esc_attr( $mid ) . '-t" hidden><div class="ep-modal__backdrop" data-ep-close></div><div class="ep-modal__box"><button type="button" class="ep-modal__close" data-ep-close aria-label="Close">×</button><h3 id="' . esc_attr( $mid ) . '-t">' . esc_html( $b['modal_title'] ?: $label ) . '</h3><div class="ep-modal__links">' . $links . '</div></div></div>' );
			continue;
		}

		if ( 'event' === $action ) {
			$url = ! empty( $b['event'] ) ? get_permalink( (int) $b['event'] ) : '';
		} else {
			$url = (string) ( $b['url'] ?? '' );
		}
		if ( ! $url ) {
			continue;
		}
		$target = ! empty( $b['new_tab'] ) ? ' target="_blank" rel="noopener"' : '';
		$out   .= '<a class="' . $cls . '" href="' . esc_url( $url ) . '"' . $target . $track . '>' . esc_html( $label ) . '</a>';
	}
	return $out . '</div>';
}

/** Ordinal date like 13<sup>th</sup> September 2026 (keeps the original site's style). */
function ept_pretty_date( string $text ): string {
	return preg_replace( '/\b(\d{1,2})(st|nd|rd|th)\b/i', '$1<sup>$2</sup>', esc_html( $text ) );
}

/** Render a microsite / page's flexible sections. */
function ept_render_sections( int $post_id ): void {
	if ( ! function_exists( 'get_field' ) ) {
		return;
	}
	$sections = get_field( 'sections', $post_id );
	if ( ! $sections ) {
		return;
	}
	foreach ( $sections as $index => $row ) {
		$layout = $row['acf_fc_layout'] ?? '';
		$s      = ept_list( $row['settings'] ?? array() );
		if ( ! empty( $s['hidden'] ) ) {
			continue;
		}
		$file = locate_template( 'template-parts/sections/' . sanitize_file_name( $layout ) . '.php' );
		if ( $file ) {
			get_template_part( 'template-parts/sections/' . $layout, null, array( 'row' => $row, 's' => $s, 'post_id' => $post_id, 'index' => $index ) );
		}
	}
}

/** Menu items from sections that have a menu label. */
function ept_section_nav( int $post_id ): array {
	$items = array();
	foreach ( ept_list( function_exists( 'get_field' ) ? get_field( 'sections', $post_id ) : array() ) as $row ) {
		$s = ept_list( $row['settings'] ?? array() );
		if ( ! empty( $s['nav_label'] ) && empty( $s['hidden'] ) ) {
			$items[] = array( 'label' => $s['nav_label'], 'href' => '#' . sanitize_title( $s['anchor'] ?: $s['nav_label'] ) );
		}
	}
	return $items;
}

/** Ensure sections with a menu label get an anchor even if none was typed. */
add_filter( 'acf/format_value/key=field_epc_ms_sections', function ( $value ) {
	foreach ( (array) $value as $i => $row ) {
		if ( ! empty( $row['settings']['nav_label'] ) && empty( $row['settings']['anchor'] ) ) {
			$value[ $i ]['settings']['anchor'] = sanitize_title( $row['settings']['nav_label'] );
		}
	}
	return $value;
}, 20 );
