<?php
defined( 'ABSPATH' ) || exit;
get_header();
while ( have_posts() ) :
	the_post();
	?>
	<article class="ep-section ep-pad-md">
		<div class="ep-container ep-container--narrow">
			<header class="ep-article__head">
				<time class="ep-card__meta" datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
				<h1><?php the_title(); ?></h1>
			</header>
			<?php if ( has_post_thumbnail() ) : ?>
				<figure class="ep-article__media"><?php the_post_thumbnail( 'large' ); ?></figure>
			<?php endif; ?>
			<div class="ep-prose"><?php the_content(); ?></div>
		</div>
	</article>
	<?php
endwhile;
get_footer();
