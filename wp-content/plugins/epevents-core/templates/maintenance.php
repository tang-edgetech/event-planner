<?php
/**
 * Fallback maintenance page (used when the active theme has no maintenance.php).
 */
defined( 'ABSPATH' ) || exit;
$c = EPC_Site_Access::content();
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
	<style>
		body.is-maintenance { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px; box-sizing: border-box; font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; background: #f6f4f0; color: #1d1b19; text-align: center; }
		.epc-maint { max-width: 560px; }
		.epc-maint h1 { font-size: clamp(1.8rem, 5vw, 2.6rem); margin: 0 0 .5em; }
		.epc-maint p { color: #5f5a55; line-height: 1.6; }
		.epc-maint a { color: inherit; }
	</style>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<main class="epc-maint">
	<h1><?php echo esc_html( $c['title'] ); ?></h1>
	<?php echo wp_kses_post( $c['message'] ); ?>
	<?php if ( $c['email'] ) : ?>
		<p><a href="mailto:<?php echo esc_attr( antispambot( $c['email'] ) ); ?>"><?php echo esc_html( antispambot( $c['email'] ) ); ?></a></p>
	<?php endif; ?>
</main>
<?php wp_footer(); ?>
</body>
</html>
