<?php defined( 'ABSPATH' ) || exit; ?>
<article class="ep-card ep-reveal">
	<a class="ep-card__media" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true">
		<?php if ( has_post_thumbnail() ) : ?>
			<?php the_post_thumbnail( 'ep-card', array( 'loading' => 'lazy' ) ); ?>
		<?php else : ?>
			<span class="ep-card__placeholder"></span>
		<?php endif; ?>
	</a>
	<div class="ep-card__body">
		<time class="ep-card__meta" datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
		<h3 class="ep-card__title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
		<p><?php echo esc_html( wp_trim_words( get_the_excerpt(), 22 ) ); ?></p>
	</div>
</article>
