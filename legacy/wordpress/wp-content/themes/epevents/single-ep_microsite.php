<?php
defined( 'ABSPATH' ) || exit;
get_header();
while ( have_posts() ) :
	the_post();
	echo '<div id="top"></div>';
	ept_render_sections( get_the_ID() );
	if ( function_exists( 'get_field' ) && ! get_field( 'sections' ) && current_user_can( 'edit_post', get_the_ID() ) ) {
		echo '<div class="ep-container ep-empty"><p>This microsite has no sections yet. <a href="' . esc_url( get_edit_post_link() ) . '">Add sections</a>.</p></div>';
	}
endwhile;
get_footer();
