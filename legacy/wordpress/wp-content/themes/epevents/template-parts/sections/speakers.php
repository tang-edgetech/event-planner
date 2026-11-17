<?php
defined( 'ABSPATH' ) || exit;
$row   = $args['row'];
$items = ept_list( $row['items'] ?? array() );
if ( ! $items ) {
	return;
}
$style = $row['style'] ?? 'cards';
echo ept_section_open( 'speakers', $args['s'] ); // phpcs:ignore
echo ept_section_head( $row ); // phpcs:ignore
?>
<div class="ep-people ep-people--<?php echo esc_attr( $style ); ?>">
	<?php foreach ( $items as $i => $p ) : ?>
		<?php
		$mid      = 'ep-bio-' . $args['index'] . '-' . $i;
		$bio      = trim( (string) ( $p['bio'] ?? '' ) );
		$name     = (string) ( $p['name'] ?? '' );
		$initial  = mb_substr( (string) preg_replace( '/^((Prof|Dr|Assoc|Dato|Datuk|Mr|Ms|Mrs)\.?\s*)+/i', '', $name ), 0, 1 );
		?>
		<article class="ep-person ep-reveal">
			<div class="ep-person__photo">
				<?php if ( ! empty( $p['photo']['ID'] ) ) : ?>
					<?php echo wp_get_attachment_image( $p['photo']['ID'], 'ep-portrait', false, array( 'loading' => 'lazy' ) ); ?>
				<?php else : ?>
					<span class="ep-person__initials"><?php echo esc_html( $initial ); ?></span>
				<?php endif; ?>
			</div>
			<div class="ep-person__body">
				<h3 class="ep-person__name"><?php echo esc_html( $name ); ?></h3>
				<?php if ( ! empty( $p['role'] ) ) : ?>
					<p class="ep-person__role"><?php echo esc_html( $p['role'] ); ?></p>
				<?php endif; ?>
				<?php if ( $bio && 'list' === $style ) : ?>
					<div class="ep-prose ep-person__bio"><?php echo wp_kses_post( $bio ); ?></div>
				<?php elseif ( $bio ) : ?>
					<button type="button" class="ep-link" data-ep-modal="<?php echo esc_attr( $mid ); ?>" data-ep-track="<?php echo esc_attr( 'Bio: ' . $name ); ?>">Read bio</button>
					<?php
					$role_html = ! empty( $p['role'] ) ? '<p class="ep-person__role">' . esc_html( $p['role'] ) . '</p>' : '';
					ept_modals( '<div class="ep-modal" id="' . esc_attr( $mid ) . '" role="dialog" aria-modal="true" hidden><div class="ep-modal__backdrop" data-ep-close></div><div class="ep-modal__box ep-modal__box--wide"><button type="button" class="ep-modal__close" data-ep-close aria-label="Close">&times;</button><h3>' . esc_html( $name ) . '</h3>' . $role_html . '<div class="ep-prose">' . wp_kses_post( $bio ) . '</div></div></div>' );
					?>
				<?php endif; ?>
			</div>
		</article>
	<?php endforeach; ?>
</div>
<?php
echo ept_section_close(); // phpcs:ignore
