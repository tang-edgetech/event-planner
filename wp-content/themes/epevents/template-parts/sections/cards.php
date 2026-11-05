<?php
defined( 'ABSPATH' ) || exit;
$row   = $args['row'];
$items = ept_list( $row['items'] ?? array() );
echo ept_section_open( 'cards', $args['s'] ); // phpcs:ignore
echo ept_section_head( $row ); // phpcs:ignore
?>
<div class="ep-cards ep-cols-<?php echo (int) ( $row['columns'] ?? 3 ); ?>">
	<?php foreach ( $items as $c ) : ?>
		<?php $link = $c['link'] ?? null; ?>
		<article class="ep-card ep-reveal">
			<?php if ( ! empty( $c['image']['ID'] ) ) : ?>
				<div class="ep-card__media"><?php echo wp_get_attachment_image( $c['image']['ID'], 'ep-card', false, array( 'loading' => 'lazy' ) ); ?></div>
			<?php endif; ?>
			<div class="ep-card__body">
				<?php if ( ! empty( $c['title'] ) ) : ?>
					<h3 class="ep-card__title"><?php echo esc_html( $c['title'] ); ?></h3>
				<?php endif; ?>
				<div class="ep-prose"><?php echo wp_kses_post( $c['content'] ?? '' ); ?></div>
				<?php if ( ! empty( $link['url'] ) ) : ?>
					<a class="ep-link" href="<?php echo esc_url( $link['url'] ); ?>"<?php echo ! empty( $link['target'] ) ? ' target="_blank" rel="noopener"' : ''; ?> data-ep-track="<?php echo esc_attr( $link['title'] ?: $c['title'] ); ?>"><?php echo esc_html( $link['title'] ?: 'Learn more' ); ?> &rarr;</a>
				<?php endif; ?>
			</div>
		</article>
	<?php endforeach; ?>
</div>
<?php
echo ept_section_close(); // phpcs:ignore
