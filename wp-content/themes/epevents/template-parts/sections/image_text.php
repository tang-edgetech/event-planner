<?php
defined( 'ABSPATH' ) || exit;
$row = $args['row'];
$pos = 'right' === ( $row['image_position'] ?? 'left' ) ? 'is-right' : 'is-left';
echo ept_section_open( 'image-text', $args['s'] ); // phpcs:ignore
?>
<div class="ep-split <?php echo esc_attr( $pos ); ?> ep-split--<?php echo esc_attr( (string) ( $row['image_ratio'] ?? '50' ) ); ?>">
	<div class="ep-split__media ep-reveal">
		<?php if ( ! empty( $row['image']['ID'] ) ) : ?>
			<?php echo wp_get_attachment_image( $row['image']['ID'], 'large', false, array( 'loading' => 'lazy' ) ); ?>
		<?php endif; ?>
	</div>
	<div class="ep-split__body ep-reveal">
		<?php if ( ! empty( $row['heading'] ) ) : ?>
			<h2 class="ep-section__title"><?php echo esc_html( $row['heading'] ); ?></h2>
		<?php endif; ?>
		<div class="ep-prose"><?php echo wp_kses_post( $row['content'] ?? '' ); ?></div>
		<?php echo ept_buttons( $row['buttons'] ?? array(), 'left' ); // phpcs:ignore ?>
	</div>
</div>
<?php
echo ept_section_close(); // phpcs:ignore
