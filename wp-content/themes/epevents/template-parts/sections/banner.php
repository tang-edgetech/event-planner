<?php
/**
 * Introduction banner: title, date/time/venue, countdown, CTA buttons, flexible background.
 */
defined( 'ABSPATH' ) || exit;
$row    = $args['row'];
$s      = $args['s'];
$pid    = (int) $args['post_id'];
$linked = (int) get_field( 'linked_event', $pid );
$title  = $row['title'] ?: get_the_title( $pid );
$date   = $row['date'] ?: ( $linked ? (string) get_field( 'event_date_text', $linked ) : '' );
$time   = $row['time'] ?: ( $linked ? (string) get_field( 'event_time_text', $linked ) : '' );
$venue  = $row['venue'] ?: ( $linked ? (string) get_field( 'venue', $linked ) : '' );
$count  = '';
if ( ! empty( $row['countdown'] ) ) {
	$count = $row['countdown_to'] ?: ( $linked ? (string) get_post_meta( $linked, 'event_start', true ) : '' );
}
$align  = 'left' === ( $row['text_align'] ?? 'center' ) ? 'left' : 'center';
$tag    = 0 === (int) $args['index'] ? 'h1' : 'h2';

echo ept_section_open( 'banner', $s, 'ep-banner ep-banner--' . esc_attr( $row['height'] ?? 'md' ) . ' is-' . $align ); // phpcs:ignore
?>
	<div class="ep-banner__content">
		<?php if ( ! empty( $row['logo']['ID'] ) ) : ?>
			<div class="ep-banner__logo ep-reveal"><?php echo wp_get_attachment_image( $row['logo']['ID'], 'medium_large', false, array( 'loading' => 'eager' ) ); ?></div>
		<?php endif; ?>
		<?php if ( ! empty( $row['eyebrow'] ) ) : ?>
			<p class="ep-banner__eyebrow ep-reveal"><?php echo esc_html( $row['eyebrow'] ); ?></p>
		<?php endif; ?>
		<<?php echo $tag; // phpcs:ignore ?> class="ep-banner__title ep-reveal"><?php echo esc_html( $title ); ?></<?php echo $tag; // phpcs:ignore ?>>
		<?php if ( ! empty( $row['description'] ) ) : ?>
			<div class="ep-banner__desc ep-prose ep-reveal"><?php echo wp_kses_post( $row['description'] ); ?></div>
		<?php endif; ?>
		<?php if ( $count ) : ?>
			<div class="ep-countdown ep-reveal" data-ep-countdown="<?php echo esc_attr( wp_date( 'c', ( new DateTime( $count, wp_timezone() ) )->getTimestamp() ) ); ?>" aria-live="polite">
				<?php foreach ( array( 'days' => 'Days', 'hours' => 'Hours', 'minutes' => 'Min', 'seconds' => 'Sec' ) as $k => $l ) : ?>
					<div><strong data-k="<?php echo esc_attr( $k ); ?>">--</strong><span><?php echo esc_html( $l ); ?></span></div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
		<?php if ( ! empty( $row['show_meta'] ) && ( $date || $time || $venue ) ) : ?>
			<ul class="ep-banner__meta ep-reveal">
				<?php if ( $date ) : ?>
					<li><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 2v2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-2V2h-2v2H9V2H7zm-2 8h14v10H5V10z"/></svg><span>Date</span><strong><?php echo ept_pretty_date( $date ); // phpcs:ignore ?></strong></li>
				<?php endif; ?>
				<?php if ( $time ) : ?>
					<li><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm1 5v4.6l3.7 2.2-1 1.7L11 12.6V7h2z"/></svg><span>Time</span><strong><?php echo esc_html( $time ); ?></strong></li>
				<?php endif; ?>
				<?php if ( $venue ) : ?>
					<li><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a7 7 0 0 0-7 7c0 5.2 7 13 7 13s7-7.8 7-13a7 7 0 0 0-7-7zm0 9.5A2.5 2.5 0 1 1 12 6.5a2.5 2.5 0 0 1 0 5z"/></svg><span>Venue</span><strong><?php echo esc_html( $venue ); ?></strong></li>
				<?php endif; ?>
			</ul>
		<?php endif; ?>
		<div class="ep-reveal"><?php echo ept_buttons( $row['buttons'] ?? array(), $align ); // phpcs:ignore ?></div>
	</div>
<?php
echo ept_section_close(); // phpcs:ignore
