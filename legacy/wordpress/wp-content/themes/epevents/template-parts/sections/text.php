<?php
defined( 'ABSPATH' ) || exit;
$row = $args['row'];
echo ept_section_open( 'text', $args['s'] ); // phpcs:ignore
echo ept_section_head( $row ); // phpcs:ignore
?>
<div class="ep-prose ep-reveal<?php echo '2' === (string) ( $row['columns'] ?? '1' ) ? ' ep-prose--2col' : ''; ?>"><?php echo wp_kses_post( $row['content'] ?? '' ); ?></div>
<?php
echo ept_section_close(); // phpcs:ignore
