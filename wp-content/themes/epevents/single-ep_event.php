<?php
defined( 'ABSPATH' ) || exit;
get_header();
while ( have_posts() ) :
	the_post();
	$id      = get_the_ID();
	$desktop = function_exists( 'get_field' ) ? get_field( 'banner_desktop' ) : null;
	$mobile  = function_exists( 'get_field' ) ? get_field( 'banner_mobile' ) : null;
	$date    = function_exists( 'get_field' ) ? (string) get_field( 'event_date_text' ) : '';
	$time    = function_exists( 'get_field' ) ? (string) get_field( 'event_time_text' ) : '';
	$venue   = function_exists( 'get_field' ) ? (string) get_field( 'venue' ) : '';
	?>
	<?php if ( $desktop || $mobile ) : ?>
		<section class="ep-event-banner">
			<picture>
				<?php if ( $mobile ) : ?><source media="(max-width: 767px)" srcset="<?php echo esc_url( $mobile['url'] ); ?>"><?php endif; ?>
				<img src="<?php echo esc_url( ( $desktop ?: $mobile )['url'] ); ?>" alt="<?php echo esc_attr( ( $desktop ?: $mobile )['alt'] ?: get_the_title() ); ?>" width="<?php echo (int) ( $desktop ?: $mobile )['width']; ?>" height="<?php echo (int) ( $desktop ?: $mobile )['height']; ?>">
			</picture>
		</section>
	<?php endif; ?>
	<section class="ep-event-form">
		<div class="ep-container ep-container--narrow">
			<div class="ep-event-card">
				<?php if ( ! function_exists( 'get_field' ) || false !== get_field( 'show_title' ) ) : ?>
					<h1 class="ep-event-card__title"><?php the_title(); ?></h1>
				<?php endif; ?>
				<?php if ( $date || $time || $venue ) : ?>
					<ul class="ep-event-meta">
						<?php if ( $date ) : ?><li><span>Date</span><?php echo ept_pretty_date( $date ); // phpcs:ignore ?></li><?php endif; ?>
						<?php if ( $time ) : ?><li><span>Time</span><?php echo esc_html( $time ); ?></li><?php endif; ?>
						<?php if ( $venue ) : ?><li><span>Venue</span><?php echo esc_html( $venue ); ?></li><?php endif; ?>
					</ul>
				<?php endif; ?>
				<?php echo class_exists( 'EPC_Form' ) ? EPC_Form::render( $id ) : ''; // phpcs:ignore -- escaped in renderer. ?>
			</div>
		</div>
	</section>
	<?php
endwhile;
get_footer();
