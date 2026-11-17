<?php defined( 'ABSPATH' ) || exit; ?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="ep-skip" href="#ep-main">Skip to content</a>
<?php
if ( is_singular( 'ep_microsite' ) ) {
	get_template_part( 'template-parts/header', 'microsite' );
} elseif ( ! is_singular( 'ep_event' ) ) {
	get_template_part( 'template-parts/header', 'site' );
}
?>
<main id="ep-main" class="ep-main">
