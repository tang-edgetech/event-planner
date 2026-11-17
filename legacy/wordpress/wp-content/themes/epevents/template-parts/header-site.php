<?php defined( 'ABSPATH' ) || exit; ?>
<header class="ep-header">
	<div class="ep-container ep-header__inner">
		<a class="ep-brand" href="<?php echo esc_url( home_url( '/' ) ); ?>">
			<?php echo ept_logo_html(); // phpcs:ignore -- escaped in helper. ?>
		</a>
		<?php if ( has_nav_menu( 'primary' ) ) : ?>
			<button class="ep-nav-toggle" aria-expanded="false" aria-controls="ep-nav"><span></span><span></span><span></span><span class="screen-reader-text">Menu</span></button>
			<?php wp_nav_menu( array( 'theme_location' => 'primary', 'container' => 'nav', 'container_id' => 'ep-nav', 'container_class' => 'ep-nav', 'depth' => 2 ) ); ?>
		<?php endif; ?>
	</div>
</header>
