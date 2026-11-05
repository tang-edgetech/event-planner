<?php
defined( 'ABSPATH' ) || exit;
$row    = $args['row'];
$images = ept_list( $row['images'] ?? array() );
if ( ! $images ) {
	return;
}
$style = $row['style'] ?? 'grid';
$cols  = (int) ( $row['columns'] ?? 3 );
echo ept_section_open( 'gallery', $args['s'] ); // phpcs:ignore
echo ept_section_head( $row ); // phpcs:ignore
?>
<div class="ep-gallery ep-gallery--<?php echo esc_attr( $style ); ?> ep-cols-<?php echo (int) $cols; ?>"<?php echo 'carousel' === $style ? ' data-ep-carousel' : ''; ?>>
	<?php foreach ( $images as $img ) : ?>
		<a class="ep-gallery__item ep-reveal" href="<?php echo esc_url( $img['url'] ); ?>" data-ep-lightbox="<?php echo esc_attr( 'g' . $args['index'] ); ?>" data-caption="<?php echo esc_attr( (string) $img['caption'] ); ?>">
			<?php echo wp_get_attachment_image( $img['ID'], 'masonry' === $style ? 'medium_large' : 'ep-card', false, array( 'loading' => 'lazy' ) ); ?>
		</a>
	<?php endforeach; ?>
</div>
<?php if ( 'carousel' === $style ) : ?>
	<div class="ep-carousel-nav">
		<button type="button" class="ep-carousel-prev" aria-label="Previous">&lsaquo;</button>
		<button type="button" class="ep-carousel-next" aria-label="Next">&rsaquo;</button>
	</div>
<?php endif; ?>
<?php
echo ept_section_close(); // phpcs:ignore
