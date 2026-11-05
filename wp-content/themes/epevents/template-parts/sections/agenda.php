<?php
defined( 'ABSPATH' ) || exit;
$row   = $args['row'];
$items = ept_list( $row['items'] ?? array() );
echo ept_section_open( 'agenda', $args['s'] ); // phpcs:ignore
echo ept_section_head( $row ); // phpcs:ignore
?>
<ol class="ep-agenda">
	<?php foreach ( $items as $it ) : ?>
		<li class="ep-agenda__item ep-reveal">
			<div class="ep-agenda__time"><?php echo esc_html( (string) $it['time'] ); ?></div>
			<div class="ep-agenda__body">
				<strong><?php echo esc_html( (string) $it['title'] ); ?></strong>
				<?php if ( ! empty( $it['description'] ) ) : ?>
					<p><?php echo wp_kses_post( $it['description'] ); ?></p>
				<?php endif; ?>
			</div>
		</li>
	<?php endforeach; ?>
</ol>
<?php
echo ept_section_close(); // phpcs:ignore
