<?php
defined( 'ABSPATH' ) || exit;
get_header();
if ( 'page' === get_option( 'show_on_front' ) && have_posts() ) {
	the_post();
	if ( trim( get_the_content() ) ) {
		echo '<section class="ep-section ep-pad-md"><div class="ep-container ep-prose">';
		the_content();
		echo '</div></section>';
	}
}
$sites = new WP_Query( array( 'post_type' => 'ep_microsite', 'posts_per_page' => 9 ) );
?>
<section class="ep-section ep-hero ep-bg-color is-dark ep-pad-lg" style="--sec-bg:var(--ep-accent)">
	<div class="ep-container ep-center">
		<h1 class="ep-hero__title"><?php bloginfo( 'name' ); ?></h1>
		<p class="ep-hero__lead"><?php bloginfo( 'description' ); ?></p>
	</div>
</section>
<section class="ep-section ep-pad-md">
	<div class="ep-container">
		<header class="ep-section__head is-left"><h2 class="ep-section__title">Events</h2></header>
		<?php if ( $sites->have_posts() ) : ?>
			<div class="ep-cards ep-cols-3">
				<?php
				while ( $sites->have_posts() ) :
					$sites->the_post();
					$linked = function_exists( 'get_field' ) ? (int) get_field( 'linked_event' ) : 0;
					$meta   = $linked ? trim( get_field( 'event_date_text', $linked ) . ( get_field( 'venue', $linked ) ? ' · ' . get_field( 'venue', $linked ) : '' ) ) : '';
					?>
					<article class="ep-card ep-reveal">
						<a class="ep-card__media" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true">
							<?php if ( has_post_thumbnail() ) : ?>
								<?php the_post_thumbnail( 'ep-card' ); ?>
							<?php else : ?>
								<span class="ep-card__placeholder"></span>
							<?php endif; ?>
						</a>
						<div class="ep-card__body">
							<?php if ( $meta ) : ?><span class="ep-card__meta"><?php echo esc_html( $meta ); ?></span><?php endif; ?>
							<h3 class="ep-card__title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
						</div>
					</article>
					<?php
				endwhile;
				wp_reset_postdata();
				?>
			</div>
		<?php else : ?>
			<p>No events published yet.</p>
		<?php endif; ?>
	</div>
</section>
<?php
get_footer();
