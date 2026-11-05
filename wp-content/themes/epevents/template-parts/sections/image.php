<?php
defined( 'ABSPATH' ) || exit;
$row = $args['row'];
$img = $row['image'] ?? null;
if ( empty( $img['url'] ) ) {
	return;
}
$mob     = $row['image_mobile'] ?? null;
$source  = ! empty( $mob['url'] ) ? '<source media="(max-width: 767px)" srcset="' . esc_url( $mob['url'] ) . '">' : '';
$picture = '<picture>' . $source . '<img src="' . esc_url( $img['url'] ) . '" alt="' . esc_attr( $img['alt'] ?: $img['title'] ) . '" width="' . (int) $img['width'] . '" height="' . (int) $img['height'] . '" loading="lazy"></picture>';
echo ept_section_open( 'image', $args['s'] ); // phpcs:ignore
?>
<figure class="ep-figure ep-reveal">
	<?php if ( ! empty( $row['link'] ) ) : ?>
		<a href="<?php echo esc_url( $row['link'] ); ?>" data-ep-track="<?php echo esc_attr( 'Image: ' . $img['title'] ); ?>"><?php echo $picture; // phpcs:ignore ?></a>
	<?php else : ?>
		<?php echo $picture; // phpcs:ignore ?>
	<?php endif; ?>
	<?php if ( ! empty( $row['caption'] ) ) : ?>
		<figcaption><?php echo esc_html( $row['caption'] ); ?></figcaption>
	<?php endif; ?>
</figure>
<?php
echo ept_section_close(); // phpcs:ignore
