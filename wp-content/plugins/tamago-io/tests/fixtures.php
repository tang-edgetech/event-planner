<?php
/**
 * Fixtures and helpers for the self-test: ACF structure with every common field type, content,
 * media, terms, menu, options, user; site-independent snapshots; run helpers.
 *
 * Used by: tests/run.php.
 */

defined( 'ABSPATH' ) || exit;

function tmgt_check( string $name, bool $ok, $detail = null ): void {
	if ( $ok ) {
		++$GLOBALS['tmgt']['pass'];
		echo "PASS {$name}\n";
		return;
	}
	++$GLOBALS['tmgt']['fail'];
	echo "FAIL {$name}\n";
	if ( null !== $detail ) {
		echo '     ' . substr( is_string( $detail ) ? $detail : wp_json_encode( $detail, JSON_UNESCAPED_SLASHES ), 0, 3000 ) . "\n";
	}
}

/** Show the first difference between two values. */
function tmgt_diff( $a, $b, string $path = '' ): string {
	if ( is_array( $a ) && is_array( $b ) ) {
		foreach ( array_unique( array_merge( array_keys( $a ), array_keys( $b ) ) ) as $k ) {
			if ( ! array_key_exists( $k, $a ) || ! array_key_exists( $k, $b ) ) {
				return "{$path}/{$k} missing on one side: " . wp_json_encode( $a[ $k ] ?? null ) . ' vs ' . wp_json_encode( $b[ $k ] ?? null );
			}
			$d = tmgt_diff( $a[ $k ], $b[ $k ], "{$path}/{$k}" );
			if ( '' !== $d ) {
				return $d;
			}
		}
		return '';
	}
	return $a === $b ? '' : "{$path}: " . wp_json_encode( $a ) . ' vs ' . wp_json_encode( $b );
}

function tmgt_post( string $slug, string $type ) {
	$ids = get_posts( array( 'name' => $slug, 'post_type' => $type, 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids', 'suppress_filters' => true ) );
	return $ids ? get_post( $ids[0] ) : null;
}

function tmgt_media_id( string $title ): int {
	$ids = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'title' => $title, 'numberposts' => 1, 'fields' => 'ids' ) );
	return $ids ? (int) $ids[0] : 0;
}

/* -------------------------------------------------------------------------
 * Fixtures
 * ---------------------------------------------------------------------- */

function tmgt_structure(): array {
	$loc = fn( $param, $value ) => array( array( array( 'param' => $param, 'operator' => '==', 'value' => $value ) ) );
	$f   = fn( $key, $name, $type, $extra = array() ) => array_merge( array( 'key' => 'field_tmgt_' . $key, 'label' => ucwords( str_replace( '_', ' ', $name ) ), 'name' => $name, 'type' => $type ), $extra );
	return array(
		array(
			'key'          => 'post_type_tmgt_book',
			'title'        => 'TMGT Books',
			'post_type'    => 'tmgt_book',
			'labels'       => array( 'name' => 'TMGT Books', 'singular_name' => 'TMGT Book' ),
			'public'       => true,
			'show_ui'      => true,
			'show_in_rest' => true,
			'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields', 'comments' ),
			'taxonomies'   => array( 'tmgt_genre' ),
			'active'       => true,
		),
		array(
			'key'          => 'taxonomy_tmgt_genre',
			'title'        => 'TMGT Genres',
			'taxonomy'     => 'tmgt_genre',
			'object_type'  => array( 'tmgt_book', 'post' ),
			'labels'       => array( 'name' => 'TMGT Genres', 'singular_name' => 'TMGT Genre' ),
			'public'       => true,
			'hierarchical' => true,
			'show_ui'      => true,
			'active'       => true,
		),
		array(
			'key'          => 'ui_options_page_tmgt',
			'title'        => 'TMGT Settings',
			'page_title'   => 'TMGT Settings',
			'menu_slug'    => 'tmgt-settings',
			'parent_slug'  => 'none',
			'data_storage' => 'custom',
			'post_id'      => 'tmgt_opts',
			'active'       => true,
		),
		array(
			'key'      => 'group_tmgt_clone_src',
			'title'    => 'TMGT Clone Source',
			'location' => $loc( 'post_type', 'tmgt_nothing' ),
			'active'   => false,
			'fields'   => array( $f( 'cl_title', 'cl_title', 'text' ), $f( 'cl_image', 'cl_image', 'image', array( 'return_format' => 'id' ) ) ),
		),
		array(
			'key'      => 'group_tmgt_all',
			'title'    => 'TMGT All Fields',
			'location' => $loc( 'post_type', 'tmgt_book' ),
			'active'   => true,
			'fields'   => array(
				$f( 'text', 'text_f', 'text' ),
				$f( 'textarea', 'textarea_f', 'textarea' ),
				$f( 'wysiwyg', 'wysiwyg_f', 'wysiwyg' ),
				$f( 'number', 'number_f', 'number' ),
				$f( 'select', 'select_f', 'select', array( 'choices' => array( 'red' => 'Red', 'green' => 'Green' ) ) ),
				$f( 'checkbox', 'checkbox_f', 'checkbox', array( 'choices' => array( 'a' => 'Apple', 'b' => 'Banana', 'c' => 'Cherry' ) ) ),
				$f( 'tf', 'tf_f', 'true_false' ),
				$f( 'date', 'date_f', 'date_picker' ),
				$f( 'image', 'image_f', 'image', array( 'return_format' => 'id' ) ),
				$f( 'file', 'file_f', 'file', array( 'return_format' => 'id' ) ),
				$f( 'gallery', 'gallery_f', 'gallery' ),
				$f( 'po', 'po_f', 'post_object', array( 'post_type' => array( 'tmgt_book' ) ) ),
				$f( 'rel', 'rel_f', 'relationship', array( 'post_type' => array( 'tmgt_book', 'page' ) ) ),
				$f( 'tax', 'tax_f', 'taxonomy', array( 'taxonomy' => 'tmgt_genre', 'field_type' => 'checkbox', 'save_terms' => 0, 'load_terms' => 0 ) ),
				$f( 'user', 'user_f', 'user' ),
				$f( 'link', 'link_f', 'link' ),
				$f(
					'rep',
					'rep_f',
					'repeater',
					array(
						'sub_fields' => array(
							$f( 'rep_title', 'rep_title', 'text' ),
							$f( 'rep_image', 'rep_image', 'image', array( 'return_format' => 'id' ) ),
							$f( 'rep_rel', 'rep_rel', 'relationship', array( 'post_type' => array( 'tmgt_book', 'page' ) ) ),
						),
					)
				),
				$f( 'grp', 'grp_f', 'group', array( 'sub_fields' => array( $f( 'grp_text', 'grp_text', 'text' ), $f( 'grp_image', 'grp_image', 'image' ) ) ) ),
				$f(
					'flex',
					'flex_f',
					'flexible_content',
					array(
						'layouts' => array(
							'layout_tmgt_hero'  => array( 'key' => 'layout_tmgt_hero', 'name' => 'hero', 'label' => 'Hero', 'display' => 'block', 'sub_fields' => array( $f( 'hero_title', 'hero_title', 'text' ), $f( 'hero_image', 'hero_image', 'image' ) ) ),
							'layout_tmgt_quote' => array( 'key' => 'layout_tmgt_quote', 'name' => 'quote', 'label' => 'Quote', 'display' => 'block', 'sub_fields' => array( $f( 'quote_text', 'quote_text', 'textarea' ), $f( 'quote_post', 'quote_post', 'post_object', array( 'post_type' => array( 'tmgt_book' ) ) ) ) ),
						),
					)
				),
				$f( 'clone', 'clone_f', 'clone', array( 'clone' => array( 'group_tmgt_clone_src' ), 'display' => 'group', 'prefix_name' => 1, 'prefix_label' => 0 ) ),
			),
		),
		array(
			'key'      => 'group_tmgt_term',
			'title'    => 'TMGT Term Fields',
			'location' => $loc( 'taxonomy', 'tmgt_genre' ),
			'active'   => true,
			'fields'   => array( $f( 'term_image', 'term_image', 'image' ), $f( 'term_rel', 'term_rel', 'post_object', array( 'post_type' => array( 'tmgt_book' ) ) ) ),
		),
		array(
			'key'      => 'group_tmgt_opts',
			'title'    => 'TMGT Options Fields',
			'location' => $loc( 'options_page', 'tmgt-settings' ),
			'active'   => true,
			'fields'   => array(
				$f( 'opt_text', 'opt_text', 'text' ),
				$f( 'opt_image', 'opt_image', 'image' ),
				$f( 'opt_rel', 'opt_rel', 'relationship', array( 'post_type' => array( 'tmgt_book' ) ) ),
				$f( 'opt_rep', 'opt_rep', 'repeater', array( 'sub_fields' => array( $f( 'opt_rep_text', 'opt_rep_text', 'text' ), $f( 'opt_rep_img', 'opt_rep_img', 'image' ) ) ) ),
			),
		),
		array(
			'key'      => 'group_tmgt_user',
			'title'    => 'TMGT User Fields',
			'location' => $loc( 'user_form', 'all' ),
			'active'   => true,
			'fields'   => array( $f( 'user_img', 'user_img', 'image' ), $f( 'user_fav', 'user_fav', 'post_object', array( 'post_type' => array( 'tmgt_book' ) ) ) ),
		),
	);
}

function tmgt_import_structure(): void {
	foreach ( tmgt_structure() as $item ) {
		$pt       = TMG_IO_ACF::item_post_type( $item );
		$existing = TMG_IO_ACF::existing_item( $item, $pt );
		if ( $existing ) {
			$item['ID'] = $existing;
		}
		acf_import_internal_post_type( $item, $pt );
	}
	// Forget fields cached before the groups were (re)created, so clones see their source fields.
	foreach ( array( 'fields', 'field-groups', 'local-fields' ) as $store ) {
		if ( acf_get_store( $store ) ) {
			acf_get_store( $store )->reset();
		}
	}
	wp_cache_flush();
	TMG_IO_ACF::register_imported();
}

function tmgt_make_file( string $title, string $ext, array $rgb = array( 200, 30, 30 ) ): int {
	$tmp = wp_tempnam( $title );
	if ( 'png' === $ext ) {
		$im = imagecreatetruecolor( 40, 30 );
		imagefill( $im, 0, 0, imagecolorallocate( $im, $rgb[0], $rgb[1], $rgb[2] ) );
		imagestring( $im, 2, 2, 2, substr( $title, -5 ), imagecolorallocate( $im, 255, 255, 255 ) );
		imagepng( $im, $tmp );
		imagedestroy( $im );
	} else {
		file_put_contents( $tmp, "Tamago test document {$title}\n" );
	}
	$id = media_handle_sideload( array( 'name' => $title . '.' . $ext, 'tmp_name' => $tmp ), 0, $title );
	if ( is_wp_error( $id ) ) {
		echo 'Could not create media: ' . $id->get_error_message() . "\n";
		return 0;
	}
	update_post_meta( $id, '_wp_attachment_image_alt', 'Alt ' . $title );
	wp_update_post( array( 'ID' => $id, 'post_excerpt' => 'Caption ' . $title ) );
	return (int) $id;
}

/** Remove everything the fixtures and imports created (content, media, terms, menu, options, user, ACF structure). */
function tmgt_wipe( bool $structure = true ): void {
	global $wpdb;
	foreach ( array( 'tmgt_book', 'page', 'post' ) as $type ) {
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND (post_name LIKE %s OR post_title LIKE %s)", $type, 'tmgt-%', 'TMGT%' ) );
		foreach ( $ids as $id ) {
			wp_delete_post( (int) $id, true );
		}
	}
	$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND (post_title LIKE 'tmgt-%' OR post_title LIKE 'remote%')" );
	foreach ( $ids as $id ) {
		wp_delete_attachment( (int) $id, true );
	}
	foreach ( array( 'tmgt_genre', 'category' ) as $tax ) {
		if ( ! taxonomy_exists( $tax ) ) {
			continue;
		}
		foreach ( get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false ) ) as $t ) {
			if ( 'tmgt_genre' === $tax || 0 === strpos( $t->slug, 'tmgt' ) ) {
				wp_delete_term( $t->term_id, $tax );
			}
		}
	}
	foreach ( wp_get_nav_menus() as $m ) {
		if ( 0 === strpos( $m->slug, 'tmgt' ) ) {
			wp_delete_nav_menu( $m->term_id );
		}
	}
	delete_option( 'tmgt_plain_option' );
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'tmgt\_opts\_%' OR option_name LIKE '\_tmgt\_opts\_%'" );
	wp_cache_flush();
	foreach ( array( 'tmgt_user', 'tmgt_author', 'tmgt_usermgr', 'tmgt_evil' ) as $login ) {
		$u = get_user_by( 'login', $login );
		if ( $u ) {
			wp_delete_user( $u->ID );
		}
	}
	if ( $structure ) {
		// ACF disables its filters (clone, local …) when deleting and does not switch them back on.
		$filters = acf_get_filters();
		foreach ( tmgt_structure() as $item ) {
			$pt = TMG_IO_ACF::item_post_type( $item );
			$id = TMG_IO_ACF::existing_item( $item, $pt );
			if ( $id ) {
				'acf-field-group' === $pt ? acf_delete_field_group( $id ) : acf_delete_internal_post_type( $id, $pt );
			}
		}
		acf_set_filters( $filters );
	}
	wp_cache_flush();
}

/** Create all fixtures; returns their IDs. */
function tmgt_fixtures(): array {
	tmgt_import_structure();
	$x = array();

	$x['user'] = wp_insert_user( array( 'user_login' => 'tmgt_user', 'user_email' => 'tmgt_user@example.com', 'user_pass' => wp_generate_password(), 'role' => 'editor', 'first_name' => 'Tam', 'last_name' => 'Ago', 'display_name' => 'Tam Ago' ) );
	update_user_meta( $x['user'], 'tmgt_user_note', 'user note' );

	$x['img1'] = tmgt_make_file( 'tmgt-img-1', 'png', array( 200, 30, 30 ) );
	$x['img2'] = tmgt_make_file( 'tmgt-img-2', 'png', array( 30, 200, 30 ) );
	$x['img3'] = tmgt_make_file( 'tmgt-img-3', 'png', array( 30, 30, 200 ) );
	$x['doc']  = tmgt_make_file( 'tmgt-doc', 'txt' );

	$fic        = wp_insert_term( 'TMGT Fiction', 'tmgt_genre', array( 'slug' => 'tmgt-fiction', 'description' => 'Made up' ) );
	$x['fic']   = (int) $fic['term_id'];
	$fan        = wp_insert_term( 'TMGT Fantasy', 'tmgt_genre', array( 'slug' => 'tmgt-fantasy', 'parent' => $x['fic'] ) );
	$x['fan']   = (int) $fan['term_id'];
	$sci        = wp_insert_term( 'TMGT Science', 'tmgt_genre', array( 'slug' => 'tmgt-science' ) );
	$x['sci']   = (int) $sci['term_id'];
	$news       = wp_insert_term( 'TMGT News', 'category', array( 'slug' => 'tmgt-news' ) );
	$x['news']  = (int) $news['term_id'];
	update_term_meta( $x['fan'], 'tmgt_term_note', 'term note' );

	$x['a']      = wp_insert_post( array( 'post_type' => 'tmgt_book', 'post_title' => 'TMGT Book Alpha', 'post_name' => 'tmgt-book-alpha', 'post_status' => 'publish', 'post_content' => '<p>Alpha body <img class="wp-image-' . $x['img1'] . '" src="' . wp_get_attachment_url( $x['img1'] ) . '"></p>', 'post_excerpt' => 'Alpha excerpt', 'post_author' => $x['user'] ) );
	$x['b']      = wp_insert_post( array( 'post_type' => 'tmgt_book', 'post_title' => 'TMGT Book Beta', 'post_name' => 'tmgt-book-beta', 'post_status' => 'draft', 'post_content' => 'Beta body' ) );
	$x['parent'] = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'TMGT Parent Page', 'post_name' => 'tmgt-parent-page', 'post_status' => 'publish' ) );
	$x['child']  = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'TMGT Child Page', 'post_name' => 'tmgt-child-page', 'post_status' => 'publish', 'post_parent' => $x['parent'], 'menu_order' => 3 ) );
	$x['post']   = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'TMGT Post', 'post_name' => 'tmgt-post', 'post_status' => 'publish', 'post_category' => array( $x['news'] ) ) );

	set_post_thumbnail( $x['a'], $x['img1'] );
	wp_set_object_terms( $x['a'], array( $x['fan'], $x['sci'] ), 'tmgt_genre' );
	update_post_meta( $x['a'], 'tmgt_plain', 'plain value' );
	update_post_meta( $x['a'], 'tmgt_arr', array( 'a' => 1, 'b' => array( 2, 3 ) ) );
	add_post_meta( $x['a'], 'tmgt_multi', 'one' );
	add_post_meta( $x['a'], 'tmgt_multi', 'two' );

	$a = $x['a'];
	update_field( 'field_tmgt_text', 'Alpha text', $a );
	update_field( 'field_tmgt_textarea', "Line 1\nLine 2", $a );
	update_field( 'field_tmgt_wysiwyg', '<p><strong>Bold</strong> text</p>', $a );
	update_field( 'field_tmgt_number', 42.5, $a );
	update_field( 'field_tmgt_select', 'green', $a );
	update_field( 'field_tmgt_checkbox', array( 'a', 'c' ), $a );
	update_field( 'field_tmgt_tf', 1, $a );
	update_field( 'field_tmgt_date', '20240115', $a );
	update_field( 'field_tmgt_image', $x['img1'], $a );
	update_field( 'field_tmgt_file', $x['doc'], $a );
	update_field( 'field_tmgt_gallery', array( $x['img2'], $x['img3'] ), $a );
	update_field( 'field_tmgt_po', $x['b'], $a );
	update_field( 'field_tmgt_rel', array( $x['b'], $x['parent'] ), $a );
	update_field( 'field_tmgt_tax', array( $x['fan'], $x['sci'] ), $a );
	update_field( 'field_tmgt_user', $x['user'], $a );
	update_field( 'field_tmgt_link', array( 'title' => 'Example', 'url' => 'https://example.com/x', 'target' => '_blank' ), $a );
	update_field(
		'field_tmgt_rep',
		array(
			array( 'field_tmgt_rep_title' => 'Row 1', 'field_tmgt_rep_image' => $x['img2'], 'field_tmgt_rep_rel' => array( $x['b'] ) ),
			array( 'field_tmgt_rep_title' => 'Row 2', 'field_tmgt_rep_image' => $x['img3'], 'field_tmgt_rep_rel' => array( $x['parent'] ) ),
		),
		$a
	);
	update_field( 'field_tmgt_grp', array( 'field_tmgt_grp_text' => 'Group text', 'field_tmgt_grp_image' => $x['img1'] ), $a );
	update_field(
		'field_tmgt_flex',
		array(
			array( 'acf_fc_layout' => 'hero', 'field_tmgt_hero_title' => 'Hero', 'field_tmgt_hero_image' => $x['img3'] ),
			array( 'acf_fc_layout' => 'quote', 'field_tmgt_quote_text' => 'Quote', 'field_tmgt_quote_post' => $x['b'] ),
		),
		$a
	);
	update_field( 'field_tmgt_clone', array( 'cl_title' => 'Cloned', 'cl_image' => $x['img2'] ), $a );

	update_field( 'field_tmgt_text', 'Beta text', $x['b'] );
	update_field( 'field_tmgt_image', $x['img2'], $x['b'] );
	update_field( 'field_tmgt_po', $x['a'], $x['b'] );

	update_field( 'field_tmgt_term_image', $x['img3'], 'term_' . $x['fan'] );
	update_field( 'field_tmgt_term_rel', $x['a'], 'term_' . $x['fan'] );

	update_option( 'tmgt_plain_option', array( 'x' => 1, 'y' => 'two' ) );
	update_field( 'field_tmgt_opt_text', 'Option hello', 'tmgt_opts' );
	update_field( 'field_tmgt_opt_image', $x['img1'], 'tmgt_opts' );
	update_field( 'field_tmgt_opt_rel', array( $x['a'], $x['b'] ), 'tmgt_opts' );
	update_field( 'field_tmgt_opt_rep', array( array( 'field_tmgt_opt_rep_text' => 'O1', 'field_tmgt_opt_rep_img' => $x['img2'] ) ), 'tmgt_opts' );

	update_field( 'field_tmgt_user_img', $x['img3'], 'user_' . $x['user'] );
	update_field( 'field_tmgt_user_fav', $x['b'], 'user_' . $x['user'] );

	$c1 = wp_insert_comment( array( 'comment_post_ID' => $x['post'], 'comment_author' => 'Reader', 'comment_author_email' => 'reader@example.com', 'comment_content' => 'First!', 'comment_approved' => 1, 'comment_date' => '2024-02-01 10:00:00', 'comment_date_gmt' => '2024-02-01 10:00:00' ) );
	wp_insert_comment( array( 'comment_post_ID' => $x['post'], 'comment_parent' => $c1, 'comment_author' => 'Writer', 'comment_author_email' => 'writer@example.com', 'comment_content' => 'Reply', 'comment_approved' => 1, 'comment_date' => '2024-02-02 10:00:00', 'comment_date_gmt' => '2024-02-02 10:00:00' ) );

	$x['menu'] = wp_create_nav_menu( 'TMGT Menu' );
	$i1        = wp_update_nav_menu_item( $x['menu'], 0, array( 'menu-item-title' => 'Parent', 'menu-item-object' => 'page', 'menu-item-object-id' => $x['parent'], 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish' ) );
	wp_update_nav_menu_item( $x['menu'], 0, array( 'menu-item-title' => 'Child', 'menu-item-object' => 'page', 'menu-item-object-id' => $x['child'], 'menu-item-type' => 'post_type', 'menu-item-parent-id' => $i1, 'menu-item-status' => 'publish' ) );
	wp_update_nav_menu_item( $x['menu'], 0, array( 'menu-item-title' => 'Genre', 'menu-item-object' => 'tmgt_genre', 'menu-item-object-id' => $x['fic'], 'menu-item-type' => 'taxonomy', 'menu-item-status' => 'publish' ) );
	wp_update_nav_menu_item( $x['menu'], 0, array( 'menu-item-title' => 'About', 'menu-item-url' => home_url( '/about/' ), 'menu-item-type' => 'custom', 'menu-item-status' => 'publish' ) );
	return $x;
}

/* -------------------------------------------------------------------------
 * Snapshot in site-independent form (IDs as slugs / titles / logins)
 * ---------------------------------------------------------------------- */

function tmgt_portable( array $field, $raw ) {
	$type = $field['type'];
	$m    = fn( $id ) => is_numeric( $id ) && (int) $id > 0 ? (string) get_the_title( (int) $id ) : $id;
	switch ( $type ) {
		case 'image':
		case 'file':
			return $raw ? $m( $raw ) : $raw;
		case 'gallery':
			return array_map( $m, (array) $raw );
		case 'post_object':
		case 'relationship':
		case 'page_link':
			return array_map( fn( $id ) => is_numeric( $id ) ? get_post_field( 'post_name', (int) $id ) : $id, (array) $raw );
		case 'taxonomy':
			return array_map( fn( $id ) => ( $t = get_term( (int) $id ) ) && ! is_wp_error( $t ) ? $t->slug : '?', (array) $raw );
		case 'user':
			return array_map( fn( $id ) => ( $u = get_userdata( (int) $id ) ) ? $u->user_login : '?', (array) $raw );
		case 'repeater':
			$out = array();
			foreach ( (array) $raw as $row ) {
				$r = array();
				foreach ( $field['sub_fields'] as $sub ) {
					$r[ $sub['name'] ] = tmgt_portable( $sub, $row[ $sub['key'] ] ?? null );
				}
				$out[] = $r;
			}
			return $out;
		case 'group':
		case 'clone':
			$r = array();
			foreach ( $field['sub_fields'] as $sub ) {
				$r[ $sub['name'] ] = tmgt_portable( $sub, ( (array) $raw )[ $sub['key'] ] ?? null );
			}
			return $r;
		case 'flexible_content':
			$out = array();
			foreach ( (array) $raw as $row ) {
				$r = array( 'layout' => $row['acf_fc_layout'] ?? '' );
				foreach ( $field['layouts'] as $l ) {
					if ( $l['name'] === $r['layout'] ) {
						foreach ( $l['sub_fields'] as $sub ) {
							$r[ $sub['name'] ] = tmgt_portable( $sub, $row[ $sub['key'] ] ?? null );
						}
					}
				}
				$out[] = $r;
			}
			return $out;
	}
	return $raw;
}

function tmgt_acf( $acf_id ): array {
	$out = array();
	foreach ( (array) get_field_objects( $acf_id, false, true ) as $name => $f ) {
		$out[ $name ] = tmgt_portable( $f, $f['value'] );
	}
	ksort( $out );
	return $out;
}

function tmgt_book_snapshot( string $slug ): array {
	$p = tmgt_post( $slug, 'tmgt_book' );
	if ( ! $p ) {
		return array( 'missing' => $slug );
	}
	$multi = get_post_meta( $p->ID, 'tmgt_multi', false );
	sort( $multi );
	$terms = wp_get_object_terms( $p->ID, 'tmgt_genre', array( 'fields' => 'slugs' ) );
	sort( $terms );
	$thumb = get_post_thumbnail_id( $p->ID );
	return array(
		'title'   => $p->post_title,
		'status'  => $p->post_status,
		'excerpt' => $p->post_excerpt,
		'author'  => get_the_author_meta( 'user_login', (int) $p->post_author ),
		'acf'     => tmgt_acf( $p->ID ),
		'plain'   => get_post_meta( $p->ID, 'tmgt_plain', true ),
		'arr'     => get_post_meta( $p->ID, 'tmgt_arr', true ),
		'multi'   => $multi,
		'terms'   => $terms,
		'thumb'   => $thumb ? get_the_title( $thumb ) : '',
		'alt'     => $thumb ? get_post_meta( $thumb, '_wp_attachment_image_alt', true ) : '',
	);
}

function tmgt_snapshot(): array {
	$s           = array();
	$s['alpha']  = tmgt_book_snapshot( 'tmgt-book-alpha' );
	$s['beta']   = tmgt_book_snapshot( 'tmgt-book-beta' );
	$child       = tmgt_post( 'tmgt-child-page', 'page' );
	$s['child']  = $child ? array( 'parent' => get_post_field( 'post_name', $child->post_parent ), 'order' => $child->menu_order ) : 'missing';
	$post        = tmgt_post( 'tmgt-post', 'post' );
	$s['post']   = 'missing';
	if ( $post ) {
		$comments  = get_comments( array( 'post_id' => $post->ID, 'orderby' => 'comment_ID', 'order' => 'ASC' ) );
		$s['post'] = array(
			'cats'     => wp_get_object_terms( $post->ID, 'category', array( 'fields' => 'slugs' ) ),
			'comments' => array_map( fn( $c ) => array( $c->comment_content, $c->comment_parent ? get_comment( $c->comment_parent )->comment_content : '' ), $comments ),
		);
	}
	$fan        = get_term_by( 'slug', 'tmgt-fantasy', 'tmgt_genre' );
	$s['term']  = $fan ? array(
		'parent' => get_term( $fan->parent )->slug ?? '',
		'note'   => get_term_meta( $fan->term_id, 'tmgt_term_note', true ),
		'acf'    => tmgt_acf( 'term_' . $fan->term_id ),
		'desc'   => get_term_by( 'slug', 'tmgt-fiction', 'tmgt_genre' )->description ?? '',
	) : 'missing';
	$s['opt']   = array(
		'plain' => get_option( 'tmgt_plain_option' ),
		'acf'   => tmgt_acf( 'tmgt_opts' ),
	);
	$menu       = wp_get_nav_menu_object( 'tmgt-menu' );
	$s['menu']  = 'missing';
	if ( $menu ) {
		$items = array();
		$by    = array();
		foreach ( wp_get_nav_menu_items( $menu->term_id ) as $it ) {
			$by[ $it->ID ] = $it->title;
		}
		foreach ( wp_get_nav_menu_items( $menu->term_id ) as $it ) {
			$obj     = 'post_type' === $it->type ? get_post_field( 'post_name', (int) $it->object_id ) : ( 'taxonomy' === $it->type ? get_term( (int) $it->object_id )->slug : $it->url );
			$items[] = array( $it->title, $it->type, $obj, $by[ (int) $it->menu_item_parent ] ?? '' );
		}
		$s['menu'] = $items;
	}
	$u         = get_user_by( 'login', 'tmgt_user' );
	$s['user'] = $u ? array(
		'roles' => $u->roles,
		'name'  => $u->display_name,
		'first' => $u->first_name,
		'note'  => get_user_meta( $u->ID, 'tmgt_user_note', true ),
		'acf'   => tmgt_acf( 'user_' . $u->ID ),
	) : 'missing';
	return $s;
}

/* -------------------------------------------------------------------------
 * Helpers for runs
 * ---------------------------------------------------------------------- */

function tmgt_import( string $path, array $opts ): array {
	$parsed = TMG_IO_Files::read( $path );
	$w      = TMG_IO_Importer::work_from_parsed( $parsed, $path, $opts );
	$run    = TMG_IO_Importer::create_run( $w['work'], $opts, basename( $path ), (string) $parsed['type'], '' );
	TMG_IO_Importer::run_all( $run );
	return TMG_IO_Runs::get( $run );
}

function tmgt_errors( array $run ): array {
	return array_values( array_filter( (array) $run['report'], fn( $i ) => 'error' === $i['a'] ) );
}

function tmgt_count( array $run, string $section, string $action ): int {
	return (int) ( $run['summary']['counts'][ $section ][ $action ] ?? 0 );
}

/** Delete the posts a run created (from its report). */
function tmgt_delete_created( array $run ): void {
	foreach ( (array) $run['report'] as $i ) {
		if ( 'posts' === $i['s'] && 'created' === $i['a'] && (int) $i['n'] > 0 ) {
			wp_delete_post( (int) $i['n'], true );
		}
	}
}

function tmgt_keep( string $path ): string {
	$copy = TMG_IO_Storage::new_path( pathinfo( $path, PATHINFO_EXTENSION ), 'test' );
	copy( $path, $copy );
	$GLOBALS['tmgt']['files'][] = $copy;
	return $copy;
}

function tmgt_count_posts(): array {
	global $wpdb;
	return array(
		'posts'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ),
		'terms'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->terms}" ),
		'users'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ),
		'options' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name NOT LIKE '%transient%'" ),
		'meta'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta}" ),
	);
}

