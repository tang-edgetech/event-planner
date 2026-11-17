<?php
/**
 * Maintenance page shown by the core (core/access/site-access.php) in "Direct links only" mode (homepage and every
 * other public URL except event forms and microsites). Styled by Appearance → Branding.
 */
defined( 'ABSPATH' ) || exit;
$c  = class_exists( 'EPC_Site_Access' ) ? EPC_Site_Access::content() : array( 'title' => 'We will be back soon', 'message' => '', 'email' => '', 'bg' => array() );
$bg = ! empty( $c['bg']['url'] ) ? $c['bg']['url'] : '';
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<main class="ep-maint<?php echo $bg ? ' has-image' : ''; ?>"<?php echo $bg ? ' style="--maint-img:url(' . esc_url( $bg ) . ')"' : ''; ?>>
	<div class="ep-maint__card">
		<div class="ep-maint__logo"><?php echo ept_logo_html( false, 'ep-maint__img' ); // phpcs:ignore -- escaped in helper. ?></div>
		<p class="ep-eyebrow">Website update</p>
		<h1 class="ep-maint__title"><?php echo esc_html( $c['title'] ); ?></h1>
		<div class="ep-maint__text ep-prose"><?php echo wp_kses_post( $c['message'] ); ?></div>
		<?php if ( $c['email'] ) : ?>
			<p class="ep-maint__contact">Questions? <a href="mailto:<?php echo esc_attr( antispambot( $c['email'] ) ); ?>"><?php echo esc_html( antispambot( $c['email'] ) ); ?></a></p>
		<?php endif; ?>
	</div>
	<p class="ep-maint__foot"><?php echo esc_html( ept_brand_text( 'brand_copyright' ) ); ?></p>
</main>
<?php wp_footer(); ?>
</body>
</html>
