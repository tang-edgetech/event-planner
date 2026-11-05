<?php
defined( 'ABSPATH' ) || exit;
$row   = $args['row'];
$items = ept_list( $row['items'] ?? array() );
$names = array(
	'whatsapp' => 'WhatsApp',
	'phone'    => 'Phone',
	'email'    => 'Email',
	'link'     => 'Link',
);
$icons = array(
	'whatsapp' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm5.3 14.1c-.2.6-1.3 1.2-1.8 1.2-.5.1-1 .2-3.3-.7-2.8-1.1-4.6-4-4.7-4.2-.1-.2-1.1-1.5-1.1-2.8s.7-2 1-2.3c.2-.3.5-.3.7-.3h.5c.2 0 .4 0 .6.5l.8 2c.1.2.1.3 0 .5l-.4.5-.3.4c-.1.1-.3.3-.1.6.2.3.8 1.3 1.7 2.1 1.2 1 2.1 1.4 2.4 1.5.3.1.5.1.6-.1l.9-1c.2-.3.4-.2.6-.1l1.9.9c.3.1.5.2.5.3.1.2.1.7-.1 1.2z"/></svg>',
	'phone'    => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6.6 10.8a15.1 15.1 0 0 0 6.6 6.6l2.2-2.2c.3-.3.7-.4 1-.2 1.1.4 2.3.6 3.6.6.6 0 1 .4 1 1V20c0 .6-.4 1-1 1A17 17 0 0 1 3 4c0-.6.4-1 1-1h3.5c.6 0 1 .4 1 1 0 1.3.2 2.5.6 3.6.1.3 0 .7-.2 1l-2.3 2.2z"/></svg>',
	'email'    => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zm8 7.2L4 6.5V18h16V6.5l-8 4.7z"/></svg>',
	'link'     => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 3h7v7h-2V6.4l-9.3 9.3-1.4-1.4L17.6 5H14V3zM5 5h6v2H5v12h12v-6h2v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z"/></svg>',
);
echo ept_section_open( 'contact', $args['s'] ); // phpcs:ignore
echo ept_section_head( $row ); // phpcs:ignore
?>
<ul class="ep-contacts">
	<?php foreach ( $items as $c ) : ?>
		<?php
		$type = $c['type'] ?? 'link';
		$val  = trim( (string) ( $c['value'] ?? '' ) );
		$name = trim( (string) ( $c['name'] ?? '' ) );
		if ( 'whatsapp' === $type ) {
			$href = 'https://wa.me/' . preg_replace( '/\D/', '', $val );
		} elseif ( 'phone' === $type ) {
			$href = 'tel:' . preg_replace( '/[^\d+]/', '', $val );
		} elseif ( 'email' === $type ) {
			$href = 'mailto:' . sanitize_email( $val );
		} else {
			$href = $val;
		}
		$external = in_array( $type, array( 'link', 'whatsapp' ), true );
		?>
		<li class="ep-contact ep-reveal">
			<span class="ep-contact__icon"><?php echo $icons[ $type ] ?? ''; // phpcs:ignore ?></span>
			<span class="ep-contact__type"><?php echo esc_html( $names[ $type ] ?? '' ); ?></span>
			<a href="<?php echo esc_url( $href ); ?>"<?php echo $external ? ' target="_blank" rel="noopener"' : ''; ?> data-ep-track="<?php echo esc_attr( 'Contact ' . $type . ': ' . ( $name ?: $val ) ); ?>"><?php echo esc_html( $name ?: $val ); ?></a>
			<?php if ( $name && in_array( $type, array( 'email', 'phone', 'whatsapp' ), true ) ) : ?>
				<small><?php echo esc_html( $val ); ?></small>
			<?php endif; ?>
		</li>
	<?php endforeach; ?>
</ul>
<?php
echo ept_section_close(); // phpcs:ignore
