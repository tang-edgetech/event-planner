<?php
defined( 'ABSPATH' ) || exit;
get_header();
$title = is_home() ? ( get_the_title( (int) get_option( 'page_for_posts' ) ) ?: 'News' ) : wp_strip_all_tags( get_the_archive_title() );
?>
<section class="ep-section ep-pad-md">
	<div class="ep-container">
		<header class="ep-section__head is-left">
			<h1 class="ep-section__title"><?php echo esc_html( $title ); ?></h1>
		</header>
		<?php if ( have_posts() ) : ?>
			<div class="ep-cards ep-cols-3">
				<?php
				while ( have_posts() ) :
					the_post();
					get_template_part( 'template-parts/card', 'post' );
				endwhile;
				?>
			</div>
			<div class="ep-pagination"><?php the_posts_pagination(); ?></div>
		<?php else : ?>
			<p>Nothing found.</p>
		<?php endif; ?>
	</div>
</section>
<?php
get_footer();
