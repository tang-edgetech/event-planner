<?php
defined( 'ABSPATH' ) || exit;
get_header();
while ( have_posts() ) :
	the_post();
	?>
	<article class="ep-section ep-pad-md">
		<div class="ep-container ep-container--narrow">
			<h1><?php the_title(); ?></h1>
			<div class="ep-prose"><?php the_content(); ?></div>
		</div>
	</article>
	<?php
endwhile;
get_footer();
