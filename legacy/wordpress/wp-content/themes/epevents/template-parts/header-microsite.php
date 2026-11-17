<?php
defined( 'ABSPATH' ) || exit;
$pid  = get_queried_object_id();
if ( function_exists( 'get_field' ) && false === get_field( 'show_nav', $pid ) ) {
	return;
}
$nav  = ept_section_nav( $pid );
$logo = function_exists( 'get_field' ) ? get_field( 'nav_logo', $pid ) : null;
$cta  = function_exists( 'get_field' ) ? get_field( 'nav_cta', $pid ) : null;
if ( ! $nav && ! $logo && ! $cta ) {
	return;
}
?>
<header class="ep-msnav" data-ep-sticky>
	<div class="ep-container ep-msnav__inner">
		<?php if ( $logo ) : ?>
			<a class="ep-brand" href="#top"><?php echo wp_get_attachment_image( $logo['ID'], 'medium', false, array( 'class' => 'ep-brand__logo' ) ); ?></a>
		<?php endif; ?>
		<?php if ( $nav || $cta ) : ?>
			<button class="ep-nav-toggle" aria-expanded="false" aria-controls="ep-msnav-menu"><span></span><span></span><span></span><span class="screen-reader-text">Menu</span></button>
			<nav id="ep-msnav-menu" class="ep-nav">
				<ul>
					<?php foreach ( $nav as $item ) : ?>
						<li><a href="<?php echo esc_attr( $item['href'] ); ?>"><?php echo esc_html( $item['label'] ); ?></a></li>
					<?php endforeach; ?>
					<?php if ( $cta && ! empty( $cta['url'] ) ) : ?>
						<li><a class="ep-btn ep-btn--solid ep-btn--sm" href="<?php echo esc_url( $cta['url'] ); ?>" <?php echo ! empty( $cta['target'] ) ? 'target="_blank" rel="noopener"' : ''; ?> data-ep-track="<?php echo esc_attr( $cta['title'] ); ?>"><?php echo esc_html( $cta['title'] ?: 'Register' ); ?></a></li>
					<?php endif; ?>
				</ul>
			</nav>
		<?php endif; ?>
	</div>
</header>
