<?php
defined( 'ABSPATH' ) || exit;
$row   = $args['row'];
$event = (int) ( $row['event'] ?? 0 );
if ( ! $event || ! class_exists( 'EPC_Form' ) ) {
	return;
}
echo ept_section_open( 'form', $args['s'] ); // phpcs:ignore
echo ept_section_head( $row ); // phpcs:ignore
?>
<div class="ep-event-card ep-reveal"><?php echo EPC_Form::render( $event ); // phpcs:ignore -- escaped in renderer. ?></div>
<?php
echo ept_section_close(); // phpcs:ignore
