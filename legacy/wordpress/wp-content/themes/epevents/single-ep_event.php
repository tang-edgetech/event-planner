<?php
/**
 * Event registration page: optional banner, then event details beside the form (or the form alone).
 */
defined( 'ABSPATH' ) || exit;
get_header();
while ( have_posts() ) :
	the_post();
	$id      = get_the_ID();
	$has_acf     = function_exists( 'get_field' );
	$desktop = $has_acf ? get_field( 'banner_desktop' ) : null;
	$mobile  = $has_acf ? get_field( 'banner_mobile' ) : null;
	$date    = $has_acf ? (string) get_field( 'event_date_text' ) : '';
	$time    = $has_acf ? (string) get_field( 'event_time_text' ) : '';
	$venue   = $has_acf ? (string) get_field( 'venue' ) : '';
	$map     = $has_acf ? (string) get_field( 'venue_map' ) : '';
	$summary = $has_acf ? (string) get_field( 'summary' ) : '';
	$layout  = $has_acf && 'single' === get_field( 'page_layout' ) ? 'single' : 'split';
	$title   = ! $has_acf || false !== get_field( 'show_title' );
	$state   = class_exists( 'EPC_Capacity' ) ? EPC_Capacity::state( $id ) : array( 'state' => 'open', 'remaining' => null );
	$labels  = array(
		'open'     => 'Registration open',
		'waitlist' => 'Waiting list open',
		'full'     => 'Application full',
		'closed'   => 'Registration closed',
		'not_open' => 'Opening soon',
	);
	$status  = $labels[ $state['state'] ] ?? '';
	if ( 'open' === $state['state'] && null !== $state['remaining'] && $state['remaining'] <= 20 && $state['remaining'] > 0 ) {
		$status = sprintf( _n( '%d seat left', '%d seats left', $state['remaining'] ), $state['remaining'] );
	}
	?>
	<?php if ( $desktop || $mobile ) : ?>
		<section class="ep-event-banner">
			<picture>
				<?php if ( $mobile ) : ?><source media="(max-width: 767px)" srcset="<?php echo esc_url( $mobile['url'] ); ?>"><?php endif; ?>
				<img src="<?php echo esc_url( ( $desktop ?: $mobile )['url'] ); ?>" alt="<?php echo esc_attr( ( $desktop ?: $mobile )['alt'] ?: get_the_title() ); ?>" width="<?php echo (int) ( $desktop ?: $mobile )['width']; ?>" height="<?php echo (int) ( $desktop ?: $mobile )['height']; ?>" fetchpriority="high">
			</picture>
		</section>
	<?php endif; ?>
	<section class="ep-event-form ep-event-form--<?php echo esc_attr( $layout ); ?>">
		<div class="ep-container <?php echo 'single' === $layout ? 'ep-container--narrow' : ''; ?>">
			<div class="ep-event-layout">
				<?php if ( 'split' === $layout ) : ?>
					<aside class="ep-event-aside">
						<?php if ( $status ) : ?>
							<span class="ep-status ep-status--<?php echo esc_attr( $state['state'] ); ?>"><?php echo esc_html( $status ); ?></span>
						<?php endif; ?>
						<?php if ( $title ) : ?>
							<h1 class="ep-event-aside__title"><?php the_title(); ?></h1>
						<?php endif; ?>
						<?php if ( $date || $time || $venue ) : ?>
							<dl class="ep-event-facts">
								<?php if ( $date ) : ?>
									<div><dt><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 2v2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-2V2h-2v2H9V2H7zm-2 8h14v10H5V10z"/></svg>Date</dt><dd><?php echo ept_pretty_date( $date ); // phpcs:ignore ?></dd></div>
								<?php endif; ?>
								<?php if ( $time ) : ?>
									<div><dt><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm1 5v4.6l3.7 2.2-1 1.7L11 12.6V7h2z"/></svg>Time</dt><dd><?php echo esc_html( $time ); ?></dd></div>
								<?php endif; ?>
								<?php if ( $venue ) : ?>
									<div><dt><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a7 7 0 0 0-7 7c0 5.2 7 13 7 13s7-7.8 7-13a7 7 0 0 0-7-7zm0 9.5A2.5 2.5 0 1 1 12 6.5a2.5 2.5 0 0 1 0 5z"/></svg>Venue</dt><dd><?php echo esc_html( $venue ); ?>
										<?php if ( $map ) : ?><br><a href="<?php echo esc_url( $map ); ?>" target="_blank" rel="noopener" data-ep-track="View map">View map</a><?php endif; ?></dd></div>
								<?php endif; ?>
							</dl>
						<?php endif; ?>
						<?php if ( trim( wp_strip_all_tags( $summary ) ) ) : ?>
							<div class="ep-event-aside__about ep-prose"><?php echo wp_kses_post( $summary ); ?></div>
						<?php endif; ?>
						<?php do_action( 'ept_event_aside', $id ); ?>
					</aside>
				<?php endif; ?>
				<div class="ep-event-card">
					<?php if ( 'single' === $layout ) : ?>
						<?php if ( $title ) : ?>
							<h1 class="ep-event-card__title"><?php the_title(); ?></h1>
						<?php endif; ?>
						<?php if ( $date || $time || $venue ) : ?>
							<ul class="ep-event-meta">
								<?php if ( $date ) : ?><li><span>Date</span><?php echo ept_pretty_date( $date ); // phpcs:ignore ?></li><?php endif; ?>
								<?php if ( $time ) : ?><li><span>Time</span><?php echo esc_html( $time ); ?></li><?php endif; ?>
								<?php if ( $venue ) : ?><li><span>Venue</span><?php echo esc_html( $venue ); ?></li><?php endif; ?>
							</ul>
						<?php endif; ?>
					<?php elseif ( in_array( $state['state'], array( 'open', 'waitlist' ), true ) ) : ?>
						<h2 class="ep-event-card__heading"><?php echo 'waitlist' === $state['state'] ? 'Join the waiting list' : 'Registration form'; ?></h2>
						<p class="ep-event-card__note">Fields marked <span class="epc-req">*</span> are required.</p>
					<?php endif; ?>
					<?php echo class_exists( 'EPC_Form' ) ? EPC_Form::render( $id ) : ''; // phpcs:ignore -- escaped in renderer. ?>
				</div>
			</div>
		</div>
	</section>
	<?php
endwhile;
get_footer();
