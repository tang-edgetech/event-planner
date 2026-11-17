<?php
defined( 'ABSPATH' ) || exit;
$row    = $args['row'];
$source = $row['source'] ?? 'latest';
$query  = array(
	'post_type'           => 'post',
	'posts_per_page'      => (int) ( $row['count'] ?? 3 ) ?: 3,
	'ignore_sticky_posts' => true,
);
if ( 'category' === $source && ! empty( $row['category'] ) ) {
	$query['cat'] = (int) $row['category'];
} elseif ( 'manual' === $source ) {
	$query['post__in']       = array_map( 'intval', ept_list( $row['posts'] ?? array() ) ) ?: array( 0 );
	$query['orderby']        = 'post__in';
	$query['posts_per_page'] = -1;
}
$q = new WP_Query( $query );
if ( ! $q->have_posts() ) {
	return;
}
echo ept_section_open( 'posts', $args['s'] ); // phpcs:ignore
echo ept_section_head( $row ); // phpcs:ignore
?>
<div class="ep-cards ep-cols-3">
	<?php
	while ( $q->have_posts() ) :
		$q->the_post();
		get_template_part( 'template-parts/card', 'post' );
	endwhile;
	wp_reset_postdata();
	?>
</div>
<?php
echo ept_section_close(); // phpcs:ignore
