<?php
defined( 'ABSPATH' ) || exit;
get_header();
?>
<section class="ep-section ep-pad-lg">
	<div class="ep-container ep-container--narrow ep-center">
		<h1>Page not found</h1>
		<p>The page you are looking for may have ended or moved.</p>
		<p><a class="ep-btn ep-btn--solid" href="<?php echo esc_url( home_url( '/' ) ); ?>">Back to home</a></p>
	</div>
</section>
<?php
get_footer();
