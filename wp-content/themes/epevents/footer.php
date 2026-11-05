<?php defined( 'ABSPATH' ) || exit; ?>
</main>
<?php
$footer_text = ( is_singular( array( 'ep_microsite', 'ep_event' ) ) && function_exists( 'get_field' ) ) ? get_field( 'footer_text', get_queried_object_id() ) : '';
?>
<footer class="ep-footer">
	<div class="ep-container ep-footer__inner">
		<?php if ( ! is_singular( array( 'ep_microsite', 'ep_event' ) ) && has_nav_menu( 'footer' ) ) : ?>
			<?php wp_nav_menu( array( 'theme_location' => 'footer', 'container' => 'nav', 'container_class' => 'ep-footer__nav', 'depth' => 1 ) ); ?>
		<?php endif; ?>
		<?php if ( ! is_singular( array( 'ep_microsite', 'ep_event' ) ) ) : ?>
			<a class="ep-footer__brand" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo ept_logo_html( true, 'ep-footer__logo' ); // phpcs:ignore -- escaped in helper. ?></a>
		<?php endif; ?>
		<p><?php echo esc_html( $footer_text ?: ept_brand_text( 'brand_copyright' ) ); ?></p>
	</div>
</footer>
<?php echo ept_modals(); // phpcs:ignore -- escaped when built. ?>
<?php wp_footer(); ?>
</body>
</html>
