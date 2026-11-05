<?php
defined( 'ABSPATH' ) || exit;
$row    = $args['row'];
$images = ept_list( $row['images'] ?? array() );
if ( ! $images ) {
	return;
}
echo ept_section_open( 'logos', $args['s'] ); // phpcs:ignore
echo ept_section_head( $row ); // phpcs:ignore
?>
<div class="ep-logos">
	<?php foreach ( $images as $img ) : ?>
		<div class="ep-logos__item ep-reveal"><?php echo wp_get_attachment_image( $img['ID'], 'medium', false, array( 'loading' => 'lazy' ) ); ?></div>
	<?php endforeach; ?>
</div>
<?php
echo ept_section_close(); // phpcs:ignore
