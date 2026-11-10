<?php
/**
 * Self-test (WP-CLI): builds ACF fixtures (every common field type on an ACF post type, an ACF
 * taxonomy, an ACF options page, a user, terms, a menu, media), then checks export → wipe →
 * import with remapped IDs, CSV / XLSX round-trips, column mapping, match / update / skip / copy,
 * dry runs, ACF's own import of our structure file, capability refusals and the file guards.
 *
 * Usage: wp eval-file wp-content/plugins/tamago-io/tests/run.php --user=<admin>
 * Prints "PASS name" / "FAIL name" and a total. Leaves the fixtures on the site.
 *
 * Used by: developers (before release).
 */

defined( 'ABSPATH' ) || exit;

if ( ! TMG_IO_ACF::is_pro() ) {
	echo "FAIL ACF Pro must be active for the full self-test\n";
	return;
}
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

$GLOBALS['tmgt'] = array( 'pass' => 0, 'fail' => 0, 'files' => array() );
require_once __DIR__ . '/fixtures.php';

/* =========================================================================
 * Run
 * ====================================================================== */

$admin     = wp_get_current_user();
global $wpdb;
$first_run = (int) $wpdb->get_var( "SELECT MAX(id) FROM " . TMG_IO_Runs::table() ); // phpcs:ignore
if ( ! $admin->exists() || ! current_user_can( 'manage_options' ) ) {
	echo "FAIL Run with --user=<administrator>\n";
	return;
}
TMG_IO_Permissions::grant_defaults();
$admin->get_role_caps();
wp_set_current_user( $admin->ID );

echo "Setting up fixtures…\n";
tmgt_wipe();
$x      = tmgt_fixtures();
$before = tmgt_snapshot();
tmgt_check( 'fixtures: book alpha has every ACF field', 20 <= count( $before['alpha']['acf'] ?? array() ), $before['alpha'] );
tmgt_check( 'fixtures: options page registered with custom post_id', isset( TMG_IO_ACF::options_pages()['tmgt-settings'] ) && 'tmgt_opts' === TMG_IO_ACF::options_pages()['tmgt-settings']['post_id'] );

/* ---- Export ------------------------------------------------------------ */
$choices = array(
	'types'         => array( 'tmgt_book', 'page', 'post' ),
	'ids'           => implode( ',', array( $x['a'], $x['b'], $x['parent'], $x['child'], $x['post'] ) ),
	'comments'      => 1,
	'taxonomies'    => array( 'tmgt_genre', 'category' ),
	'users'         => 1,
	'user_roles'    => array( 'editor' ),
	'options'       => 'tmgt_plain_option',
	'options_pages' => array( 'tmgt-settings' ),
	'menus'         => array( (string) $x['menu'] ),
	'acf_structure' => array( 'field_groups', 'post_types', 'taxonomies', 'options_pages' ),
	'format'        => 'zip',
);
$pkg     = TMG_IO_Exporter::build( $choices );
$alpha_e = null;
foreach ( $pkg['posts'] as $p ) {
	if ( 'tmgt-book-alpha' === $p['slug'] ) {
		$alpha_e = $p;
	}
}
tmgt_check( 'export: package format and sections', TMG_IO_Files::PACKAGE === $pkg['format'] && $alpha_e && $pkg['terms'] && $pkg['users'] && $pkg['menus'] && $pkg['options'] && $pkg['acf_structure'] );
$refs = (array) ( $alpha_e['refs'] ?? array() );
tmgt_check(
	'export: ID references found inside repeater, group, flexible content and clone',
	'media' === ( $refs['rep_f_0_rep_image'] ?? '' ) && 'post' === ( $refs['rep_f_0_rep_rel'] ?? '' ) && 'media' === ( $refs['grp_f_grp_image'] ?? '' ) && 'media' === ( $refs['flex_f_0_hero_image'] ?? '' ) && 'post' === ( $refs['flex_f_1_quote_post'] ?? '' ) && 'media' === ( $refs['clone_f_cl_image'] ?? '' ) && 'term' === ( $refs['tax_f'] ?? '' ) && 'user' === ( $refs['user_f'] ?? '' ) && 'media' === ( $refs['_thumbnail_id'] ?? '' ),
	$refs
);
tmgt_check( 'export: ACF values by name with key and raw value', 'field_tmgt_text' === ( $alpha_e['acf']['text_f']['key'] ?? '' ) && 'Alpha text' === ( $alpha_e['acf']['text_f']['value'] ?? '' ) );
tmgt_check( 'export: author as login and email', 'tmgt_user' === ( $alpha_e['author']['login'] ?? '' ) && 'tmgt_user@example.com' === ( $alpha_e['author']['email'] ?? '' ) );
tmgt_check( 'export: multi-value meta kept', array( 'one', 'two' ) === ( $alpha_e['meta_multi']['tmgt_multi'] ?? null ) );
$keys = array_column( $pkg['acf_structure'], 'key' );
tmgt_check( 'export: ACF structure has group, post type, taxonomy, options page', ! array_diff( array( 'group_tmgt_all', 'post_type_tmgt_book', 'taxonomy_tmgt_genre', 'ui_options_page_tmgt' ), $keys ), $keys );
$u_e = $pkg['users'][0] ?? array();
tmgt_check( 'export: users without passwords, sessions or capabilities', $u_e && ! isset( $u_e['pass'] ) && ! isset( $u_e['user_pass'] ) && ! array_filter( array_keys( (array) $u_e['meta'] ), fn( $k ) => preg_match( '/capabilities|user_level|session_tokens/', $k ) ) && in_array( 'editor', $u_e['roles'], true ) );
tmgt_check( 'export: options page values with references', 'Option hello' === ( $pkg['options']['tmgt_opts_opt_text'] ?? '' ) && 'post' === ( $pkg['options_refs']['tmgt_opts_opt_rel'] ?? '' ) && 'media' === ( $pkg['options_refs']['tmgt_opts_opt_rep_0_opt_rep_img'] ?? '' ) );
tmgt_check( 'export: protected options never exported', ! isset( TMG_IO_Exporter::build( array( 'options' => "siteurl\nactive_plugins\nauth_key\nblogname" ) )['options']['siteurl'] ) );
$zip_file = TMG_IO_Exporter::write( $pkg, 'zip' );
$zip      = tmgt_keep( $zip_file['path'] );
TMG_IO_Storage::delete( $zip_file['path'] );
$za = new ZipArchive();
$za->open( $zip );
$has_media = false !== $za->locateName( 'media/' . $x['img1'] . '/tmgt-img-1.png' );
$za->close();
tmgt_check( 'export: ZIP holds package.json and media files', $has_media );
$json_file = TMG_IO_Exporter::write( $pkg, 'json' );
$json      = tmgt_keep( $json_file['path'] );
TMG_IO_Storage::delete( $json_file['path'] );

/* ---- ACF's own import accepts our structure file --------------------- */
$acf_json = TMG_IO_ACF::export_structure( array() );
$acf_path = TMG_IO_Storage::new_path( 'json', 'acftest' );
file_put_contents( $acf_path, wp_json_encode( $acf_json ) );
$GLOBALS['tmgt']['files'][] = $acf_path;
$group_id_before = TMG_IO_ACF::existing_item( array( 'key' => 'group_tmgt_all' ), 'acf-field-group' );
$used_acf_tool   = false;
if ( ! class_exists( 'ACF_Admin_Tool_Import' ) && function_exists( 'acf_get_path' ) ) {
	foreach ( array( 'includes/admin/tools/class-acf-admin-tool.php', 'includes/admin/tools/class-acf-admin-tool-import.php' ) as $f ) {
		if ( file_exists( acf_get_path( $f ) ) && ( 'includes/admin/tools/class-acf-admin-tool.php' !== $f || ! class_exists( 'ACF_Admin_Tool' ) ) ) {
			if ( ! function_exists( 'acf_register_admin_tool' ) ) {
				function acf_register_admin_tool( $c ) {} // phpcs:ignore -- not needed outside the admin.
			}
			require_once acf_get_path( $f );
		}
	}
}
if ( ! function_exists( 'acf_add_admin_notice' ) ) {
	function acf_add_admin_notice( $text = '', $type = 'info' ) { // phpcs:ignore -- admin-only helper used by ACF's tool.
		$GLOBALS['tmgt']['acf_notice'] = array( $text, $type );
		return $text;
	}
}
if ( class_exists( 'ACF_Admin_Tool_Import' ) ) {
	$_FILES['acf_import_file'] = array( 'name' => 'acf-export.json', 'type' => 'application/json', 'tmp_name' => $acf_path, 'error' => 0, 'size' => filesize( $acf_path ) );
	$tool                      = new ACF_Admin_Tool_Import();
	ob_start();
	$tool->submit();
	ob_end_clean();
	unset( $_FILES['acf_import_file'] );
	$used_acf_tool = true;
}
$notice = (string) ( $GLOBALS['tmgt']['acf_notice'][0] ?? '' );
tmgt_check(
	'ACF structure export is accepted by ACF\'s own import tool' . ( $used_acf_tool ? '' : ' (tool class unavailable)' ),
	$used_acf_tool && false !== strpos( $notice, (string) count( $acf_json ) ) && TMG_IO_ACF::existing_item( array( 'key' => 'group_tmgt_all' ), 'acf-field-group' ) === $group_id_before,
	$notice
);
tmgt_check( 'ACF structure: values unchanged after ACF re-import', '' === tmgt_diff( $before, tmgt_snapshot() ), tmgt_diff( $before, tmgt_snapshot() ) );
$acf_read = TMG_IO_Files::read( $acf_path );
tmgt_check( 'ACF export file (ACF own format) is recognised by the importer', count( $acf_json ) === count( (array) ( $acf_read['package']['acf_structure'] ?? array() ) ) );

/* ---- Full round trip into a fresh state ------------------------------ */
$old_ids = array( 'a' => $x['a'], 'b' => $x['b'], 'img1' => $x['img1'] );
tmgt_wipe();
tmgt_check( 'wipe: content, structure and media are gone', ! tmgt_post( 'tmgt-book-alpha', 'tmgt_book' ) && ! TMG_IO_ACF::existing_item( array( 'key' => 'group_tmgt_all' ), 'acf-field-group' ) && ! tmgt_media_id( 'tmgt-img-1' ) );
$run = tmgt_import( $zip, array( 'existing' => 'skip', 'match' => 'slug' ) );
tmgt_check( 'zip import: finished without errors', 'done' === $run['status'] && ! tmgt_errors( $run ), tmgt_errors( $run ) );
tmgt_check( 'zip import: ACF structure recreated', TMG_IO_ACF::existing_item( array( 'key' => 'group_tmgt_all' ), 'acf-field-group' ) && TMG_IO_ACF::existing_item( array( 'key' => 'post_type_tmgt_book' ), 'acf-post-type' ) && TMG_IO_ACF::existing_item( array( 'key' => 'taxonomy_tmgt_genre' ), 'acf-taxonomy' ) && TMG_IO_ACF::existing_item( array( 'key' => 'ui_options_page_tmgt' ), 'acf-ui-options-page' ) );
$after = tmgt_snapshot();
tmgt_check( 'zip import: every value equal field by field (incl. nested ACF references)', '' === tmgt_diff( $before, $after ), tmgt_diff( $before, $after ) );
$na = tmgt_post( 'tmgt-book-alpha', 'tmgt_book' );
$nb = tmgt_post( 'tmgt-book-beta', 'tmgt_book' );
tmgt_check( 'zip import: IDs were remapped (new IDs differ, references point to the new ones)', $na && $nb && $na->ID !== $old_ids['a'] && (int) get_field( 'po_f', $na->ID, false ) === $nb->ID && (int) get_post_meta( $na->ID, 'rep_f_0_rep_rel', true )[0] === $nb->ID && tmgt_media_id( 'tmgt-img-1' ) !== $old_ids['img1'] );
$img1_new = tmgt_media_id( 'tmgt-img-1' );
tmgt_check( 'zip import: media came from the ZIP with alt and caption', $img1_new && 'Alt tmgt-img-1' === get_post_meta( $img1_new, '_wp_attachment_image_alt', true ) && 'Caption tmgt-img-1' === get_post_field( 'post_excerpt', $img1_new ) && file_exists( get_attached_file( $img1_new ) ) );
tmgt_check( 'zip import: image ID and URL inside content remapped', $na && false !== strpos( $na->post_content, 'wp-image-' . $img1_new ) && false !== strpos( $na->post_content, wp_get_attachment_url( $img1_new ) ) );

/* ---- Match / skip / update / copy ------------------------------------ */
$counts = tmgt_count_posts();
$run    = tmgt_import( $json, array( 'existing' => 'skip', 'match' => 'slug' ) );
tmgt_check( 'skip: re-import creates nothing', 0 === tmgt_count( $run, 'posts', 'created' ) && tmgt_count( $run, 'posts', 'skipped' ) >= 5 && $counts['posts'] === tmgt_count_posts()['posts'], $run['summary'] );
wp_update_post( array( 'ID' => $na->ID, 'post_title' => 'Changed locally' ) );
update_field( 'field_tmgt_text', 'Changed text', $na->ID );
update_post_meta( $na->ID, 'tmgt_extra_local', 'stay?' );
$run = tmgt_import( $json, array( 'existing' => 'update', 'match' => 'slug', 'sections' => array( 'posts', 'media', 'terms' ) ) );
tmgt_check( 'update: values restored, nothing created', 'TMGT Book Alpha' === get_post_field( 'post_title', $na->ID ) && 'Alpha text' === get_field( 'text_f', $na->ID ) && 0 === tmgt_count( $run, 'posts', 'created' ) && tmgt_count( $run, 'posts', 'updated' ) >= 5, $run['summary'] );
tmgt_check( 'update (keep missing): extra local field kept', 'stay?' === get_post_meta( $na->ID, 'tmgt_extra_local', true ) );
$run = tmgt_import( $json, array( 'existing' => 'update', 'match' => 'id', 'empty_missing' => 1, 'sections' => array( 'posts' ) ) );
tmgt_check( 'match by ID: old IDs do not exist here, so all five items are created', 5 === tmgt_count( $run, 'posts', 'created' ) && 0 === tmgt_count( $run, 'posts', 'updated' ), $run['summary'] );
tmgt_delete_created( $run );
// Books only from here: pages and posts would have no value for the match field.
$bf    = TMG_IO_Exporter::write( TMG_IO_Exporter::build( array( 'types' => array( 'tmgt_book' ) ) ), 'json' );
$books = tmgt_keep( $bf['path'] );
TMG_IO_Storage::delete( $bf['path'] );
update_post_meta( $na->ID, 'tmgt_extra_local2', 'not in the file' );
$run = tmgt_import( $books, array( 'existing' => 'update', 'match' => 'meta:text_f', 'empty_missing' => 1, 'sections' => array( 'posts' ) ) );
tmgt_check( 'match by ACF field + empty missing: matched and extra field removed', 2 === tmgt_count( $run, 'posts', 'updated' ) && 0 === tmgt_count( $run, 'posts', 'created' ) && '' === get_post_meta( $na->ID, 'tmgt_extra_local2', true ) && 'Alpha text' === get_field( 'text_f', $na->ID ), $run['summary'] );
$before_copy = tmgt_count_posts()['posts'];
$run         = tmgt_import( $books, array( 'existing' => 'copy', 'sections' => array( 'posts' ) ) );
$copy        = tmgt_post( 'tmgt-book-alpha-2', 'tmgt_book' );
tmgt_check( 'copy: duplicates created with new slugs and their own references', $copy && 2 === tmgt_count( $run, 'posts', 'created' ) && (int) get_field( 'po_f', $copy->ID, false ) !== $nb->ID && 'tmgt-book-beta-2' === get_post_field( 'post_name', (int) get_field( 'po_f', $copy->ID, false ) ), $copy ? array( get_field( 'po_f', $copy->ID, false ), $nb->ID, $run['summary'] ) : $run['summary'] );
tmgt_delete_created( $run );
tmgt_check( 'copy: cleanup back to the same number of posts', $before_copy === tmgt_count_posts()['posts'] );

/* ---- Dry run ---------------------------------------------------------- */
$counts = tmgt_count_posts();
$opt    = get_option( 'tmgt_plain_option' );
update_option( 'tmgt_plain_option', 'local' );
$run    = tmgt_import( $zip, array( 'existing' => 'copy', 'dry_run' => 1 ) );
$after  = tmgt_count_posts();
tmgt_check( 'dry run: nothing written', $counts['posts'] === $after['posts'] && $counts['terms'] === $after['terms'] && $counts['users'] === $after['users'] && $counts['meta'] === $after['meta'] && 'local' === get_option( 'tmgt_plain_option' ), array( $counts, $after ) );
tmgt_check( 'dry run: report says what would happen (copy)', $run['dry_run'] && 5 === tmgt_count( $run, 'posts', 'created' ) && 1 === tmgt_count( $run, 'menus', 'created' ) && tmgt_count( $run, 'options', 'skipped' ) >= 1, $run['summary'] );
$run   = tmgt_import( $zip, array( 'existing' => 'update', 'dry_run' => 1 ) );
$after = tmgt_count_posts();
tmgt_check( 'dry run: update mode reports updates and still writes nothing', 5 === tmgt_count( $run, 'posts', 'updated' ) && tmgt_count( $run, 'options', 'updated' ) >= 1 && $counts['meta'] === $after['meta'] && 'local' === get_option( 'tmgt_plain_option' ), $run['summary'] );
update_option( 'tmgt_plain_option', $opt );

/* ---- Batched, resumable steps ---------------------------------------- */
$parsed = TMG_IO_Files::read( $json );
$w      = TMG_IO_Importer::work_from_parsed( $parsed, $json );
$rid    = TMG_IO_Importer::create_run( $w['work'], array( 'existing' => 'skip', 'dry_run' => 1 ), 'batch test', 'json', '' );
update_option( 'tmg_io_settings', array_merge( (array) get_option( 'tmg_io_settings', array() ), array( 'batch_size' => 3 ) ) );
$steps = 0;
do {
	$st = TMG_IO_Importer::step( $rid );
	++$steps;
} while ( empty( $st['finished'] ) && empty( $st['error'] ) && $steps < 500 );
update_option( 'tmg_io_settings', array_merge( (array) get_option( 'tmg_io_settings', array() ), array( 'batch_size' => 25 ) ) );
tmgt_check( 'batches: run finishes over many small resumable steps', $steps > 5 && ! empty( $st['finished'] ) && $st['done'] === $st['total'], array( $steps, $st ) );

/* ---- CSV and XLSX round trips ---------------------------------------- */
$flat_choices = array( 'types' => array( 'tmgt_book' ), 'format' => 'xlsx' );
$xf           = TMG_IO_Exporter::write( TMG_IO_Exporter::build( $flat_choices ), 'xlsx' );
$xlsx         = tmgt_keep( $xf['path'] );
TMG_IO_Storage::delete( $xf['path'] );
$sheets = tmg_io_read_file( $xlsx );
$header = $sheets['tmgt_book'][0] ?? array();
tmgt_check( 'xlsx export: one sheet per post type with readable and _tmg_json columns', in_array( 'post_title', $header, true ) && in_array( 'acf:text_f', $header, true ) && in_array( 'tax:tmgt_genre', $header, true ) && in_array( '_tmg_json', $header, true ), $header );
$cf   = TMG_IO_Exporter::write( TMG_IO_Exporter::build( array( 'types' => array( 'tmgt_book' ), 'format' => 'csv' ) ), 'csv' );
$csv  = tmgt_keep( $cf['path'] );
tmgt_check( 'csv: one post type exports as a single CSV file', 'csv' === $cf['ext'] && 'csv' === TMG_IO_Files::sniff( $csv ) );
TMG_IO_Storage::delete( $cf['path'] );
$before_books = array( tmgt_book_snapshot( 'tmgt-book-alpha' ), tmgt_book_snapshot( 'tmgt-book-beta' ) );
foreach ( array( 'tmgt-book-alpha', 'tmgt-book-beta' ) as $slug ) {
	wp_delete_post( tmgt_post( $slug, 'tmgt_book' )->ID, true );
}
$run = tmgt_import( $csv, array( 'existing' => 'skip' ) );
tmgt_check( 'csv round trip: books recreated, all values equal', ! tmgt_errors( $run ) && '' === tmgt_diff( $before_books, array( tmgt_book_snapshot( 'tmgt-book-alpha' ), tmgt_book_snapshot( 'tmgt-book-beta' ) ) ), tmgt_errors( $run ) ? tmgt_errors( $run ) : tmgt_diff( $before_books, array( tmgt_book_snapshot( 'tmgt-book-alpha' ), tmgt_book_snapshot( 'tmgt-book-beta' ) ) ) );
foreach ( array( 'tmgt-book-alpha', 'tmgt-book-beta' ) as $slug ) {
	wp_delete_post( tmgt_post( $slug, 'tmgt_book' )->ID, true );
}
$run = tmgt_import( $xlsx, array( 'existing' => 'skip' ) );
tmgt_check( 'xlsx round trip: books recreated, all values equal', ! tmgt_errors( $run ) && '' === tmgt_diff( $before_books, array( tmgt_book_snapshot( 'tmgt-book-alpha' ), tmgt_book_snapshot( 'tmgt-book-beta' ) ) ), tmgt_errors( $run ) ? tmgt_errors( $run ) : tmgt_diff( $before_books, array( tmgt_book_snapshot( 'tmgt-book-alpha' ), tmgt_book_snapshot( 'tmgt-book-beta' ) ) ) );
// Edit cells in the workbook and import as update.
$rows = $sheets['tmgt_book'];
$h    = array_flip( $rows[0] );
foreach ( $rows as $i => $r ) {
	if ( 0 === $i ) {
		continue;
	}
	if ( 'tmgt-book-alpha' === $r[ $h['post_name'] ] ) {
		$rows[ $i ][ $h['acf:text_f'] ]      = 'Edited in Excel';
		$rows[ $i ][ $h['acf:checkbox_f'] ] = 'Banana, Cherry';
		$rows[ $i ][ $h['tax:tmgt_genre'] ] = 'TMGT Science';
	}
	if ( 'tmgt-book-beta' === $r[ $h['post_name'] ] ) {
		$rows[ $i ][ $h['post_title'] ] = 'Beta edited';
	}
}
$sheets['tmgt_book'] = $rows;
$edited              = tmg_io_xlsx_write( $sheets );
$GLOBALS['tmgt']['files'][] = $edited;
$run = tmgt_import( $edited, array( 'existing' => 'update' ) );
$a2  = tmgt_post( 'tmgt-book-alpha', 'tmgt_book' );
tmgt_check(
	'xlsx edits: changed cells applied, others kept',
	! tmgt_errors( $run ) && 'Edited in Excel' === get_field( 'text_f', $a2->ID ) && array( 'b', 'c' ) === get_field( 'checkbox_f', $a2->ID, false ) && array( 'tmgt-science' ) === wp_get_object_terms( $a2->ID, 'tmgt_genre', array( 'fields' => 'slugs' ) ) && 'Beta edited' === get_post_field( 'post_title', tmgt_post( 'tmgt-book-beta', 'tmgt_book' )->ID ) && 'Line 1' === substr( (string) get_field( 'textarea_f', $a2->ID ), 0, 6 ) && 2 === count( (array) get_field( 'gallery_f', $a2->ID, false ) ),
	array( get_field( 'text_f', $a2->ID ), get_field( 'checkbox_f', $a2->ID, false ), wp_get_object_terms( $a2->ID, 'tmgt_genre', array( 'fields' => 'slugs' ) ), tmgt_errors( $run ) )
);
// Long content is split over several JSON cells and survives.
$long_text = str_repeat( 'Long content ✓ ', 4000 );
wp_update_post( array( 'ID' => $a2->ID, 'post_content' => $long_text ) );
$lf     = TMG_IO_Exporter::write( TMG_IO_Exporter::build( array( 'types' => array( 'tmgt_book' ), 'ids' => (string) $a2->ID ) ), 'xlsx' );
$long   = tmgt_keep( $lf['path'] );
TMG_IO_Storage::delete( $lf['path'] );
$lhead  = tmg_io_read_file( $long )['tmgt_book'][0];
wp_update_post( array( 'ID' => $a2->ID, 'post_content' => 'short' ) );
$run    = tmgt_import( $long, array( 'existing' => 'update' ) );
tmgt_check( 'xlsx: text longer than an Excel cell survives (split _tmg_json columns)', in_array( '_tmg_json_2', $lhead, true ) && trim( $long_text ) === trim( get_post_field( 'post_content', $a2->ID ) ) );
// Restore everything (books were recreated, so terms, options and users point to them again).
$run = tmgt_import( $json, array( 'existing' => 'update' ) );
tmgt_check( 'restore: full update import puts every reference back', ! tmgt_errors( $run ) && '' === tmgt_diff( $before, tmgt_snapshot() ), tmgt_errors( $run ) ? tmgt_errors( $run ) : tmgt_diff( $before, tmgt_snapshot() ) );

/* ---- Column-mapping import ------------------------------------------- */
$src_dir = wp_upload_dir()['basedir'] . '/tmgt-src';
wp_mkdir_p( $src_dir );
$im = imagecreatetruecolor( 20, 20 );
imagefill( $im, 0, 0, imagecolorallocate( $im, 10, 120, 200 ) );
imagepng( $im, $src_dir . '/remote-photo.png' );
imagedestroy( $im );
$remote_url = wp_upload_dir()['baseurl'] . '/tmgt-src/remote-photo.png';
$img2_now   = tmgt_media_id( 'tmgt-img-2' );
$csv_text   = "Title,Body,Genres,Text F,Is True,Published On,Colour,Fruit,Photo,Related,Rows,Note,SKU,Formula\n"
	. '"TMGT Mapped One","<p>Hello</p>","TMGT Poetry, TMGT Fantasy","Mapped text",yes,15/01/2024,Green,"Apple, Cherry",' . $remote_url . ',"tmgt-book-alpha, TMGT Book Beta","[{""rep_title"":""R1"",""rep_image"":' . $img2_now . ',""rep_rel"":""tmgt-book-beta""}]","note 1",SKU-1,"\'=SUM(A1)"' . "\n"
	. '"TMGT Mapped Two","Second","","Text two",0,2024-03-05,red,b,,,"",,SKU-2,' . "\n";
$csv_path   = TMG_IO_Storage::new_path( 'csv', 'maptest' );
file_put_contents( $csv_path, $csv_text );
$GLOBALS['tmgt']['files'][] = $csv_path;
$parsed  = TMG_IO_Files::read( $csv_path );
$w       = TMG_IO_Importer::work_from_parsed( $parsed, $csv_path );
$groups  = TMG_IO_Mapping::targets( 'tmgt_book' );
$guessed = array();
foreach ( $w['headers'] as $hdr ) {
	$guessed[ $hdr ] = TMG_IO_Mapping::guess( $hdr, 'tmgt_book', $groups );
}
tmgt_check( 'mapping: targets list ACF fields of the post type', isset( $groups['ACF: TMGT All Fields']['acf:field_tmgt_rep'] ) && isset( $groups['Taxonomies']['tax:tmgt_genre'] ) );
tmgt_check( 'mapping: guesses title, content and ACF field by label', 'post_title' === $guessed['Title'] && 'post_content' === $guessed['Body'] && 'acf:field_tmgt_text' === $guessed['Text F'], $guessed );
$mapping = array(
	'Title'        => 'post_title',
	'Body'         => 'post_content',
	'Genres'       => 'tax:tmgt_genre',
	'Text F'   => 'acf:field_tmgt_text',
	'Is True'      => 'acf:field_tmgt_tf',
	'Published On' => 'acf:field_tmgt_date',
	'Colour'       => 'acf:field_tmgt_select',
	'Fruit'        => 'acf:field_tmgt_checkbox',
	'Photo'        => 'acf:field_tmgt_image',
	'Related'      => 'acf:field_tmgt_rel',
	'Rows'         => 'acf:field_tmgt_rep',
	'Note'         => 'meta:tmgt_note',
	'SKU'          => 'meta:tmgt_sku',
	'Formula'      => 'meta:tmgt_formula',
);
TMG_IO_Presets::save( TMG_IO_Presets::MAPPING, 'tmgt books', array( 'post_type' => 'tmgt_book', 'mapping' => $mapping ) );
tmgt_check( 'mapping template saved and loaded', $mapping === ( TMG_IO_Presets::get( TMG_IO_Presets::MAPPING, 'tmgt books' )['mapping'] ?? null ) );
$mopts = array( 'post_type' => 'tmgt_book', 'mapping' => $mapping, 'match' => 'meta:tmgt_sku', 'existing' => 'update', 'sections' => array() );
$run   = tmgt_import( $csv_path, $mopts );
$m1    = tmgt_post( 'tmgt-mapped-one', 'tmgt_book' );
$photo = $m1 ? (int) get_field( 'image_f', $m1->ID, false ) : 0;
$rep   = $m1 ? get_field( 'rep_f', $m1->ID, false ) : array();
tmgt_check( 'mapping import: rows created', 2 === tmgt_count( $run, 'rows', 'created' ) && ! tmgt_errors( $run ), $run['report'] );
tmgt_check(
	'mapping import: type-aware values (terms, true/false, date, select label, checkbox, relationship by slug and title)',
	$m1 && 'Mapped text' === get_field( 'text_f', $m1->ID ) && 1 === (int) get_field( 'tf_f', $m1->ID, false ) && '20240115' === get_field( 'date_f', $m1->ID, false ) && 'green' === get_field( 'select_f', $m1->ID, false ) && array( 'a', 'c' ) === get_field( 'checkbox_f', $m1->ID, false ) && array( 'tmgt-book-alpha', 'tmgt-book-beta' ) === array_map( fn( $i ) => get_post_field( 'post_name', (int) $i ), (array) get_field( 'rel_f', $m1->ID, false ) ) && array( 'tmgt-fantasy', 'tmgt-poetry' ) === ( function ( $t ) { sort( $t ); return $t; } )( wp_get_object_terms( $m1->ID, 'tmgt_genre', array( 'fields' => 'slugs' ) ) ),
	$m1 ? array( get_field( 'tf_f', $m1->ID, false ), get_field( 'date_f', $m1->ID, false ), get_field( 'select_f', $m1->ID, false ), get_field( 'checkbox_f', $m1->ID, false ), get_field( 'rel_f', $m1->ID, false ), wp_get_object_terms( $m1->ID, 'tmgt_genre', array( 'fields' => 'slugs' ) ) ) : 'missing'
);
tmgt_check( 'mapping import: image downloaded by URL (wp_safe_remote_get) and de-duplicated', $photo && $remote_url === get_post_meta( $photo, '_tmg_io_src', true ), $run['report'] );
tmgt_check( 'mapping import: repeater from JSON with nested image ID and relationship by slug', is_array( $rep ) && 'R1' === ( $rep[0]['field_tmgt_rep_title'] ?? '' ) && (int) ( $rep[0]['field_tmgt_rep_image'] ?? 0 ) === $img2_now && (int) ( ( (array) ( $rep[0]['field_tmgt_rep_rel'] ?? array() ) )[0] ?? 0 ) === tmgt_post( 'tmgt-book-beta', 'tmgt_book' )->ID, $rep );
tmgt_check( 'mapping import: plain custom field and formula escape undone', 'note 1' === get_post_meta( $m1->ID, 'tmgt_note', true ) && '=SUM(A1)' === get_post_meta( $m1->ID, 'tmgt_formula', true ) );
$csv2 = str_replace( 'TMGT Mapped Two', 'TMGT Mapped Two Renamed', $csv_text );
file_put_contents( $csv_path, $csv2 );
$run  = tmgt_import( $csv_path, $mopts );
tmgt_check( 'mapping import: second run matches by SKU and updates (no new posts, same image)', 0 === tmgt_count( $run, 'rows', 'created' ) && 2 === tmgt_count( $run, 'rows', 'updated' ) && 'TMGT Mapped Two Renamed' === get_the_title( tmgt_post( 'tmgt-mapped-two', 'tmgt_book' ) ) && $photo === (int) get_field( 'image_f', $m1->ID, false ), $run['summary'] );
$run = tmgt_import( $csv_path, array_merge( $mopts, array( 'existing' => 'skip' ) ) );
tmgt_check( 'mapping import: skip leaves matches alone', 2 === tmgt_count( $run, 'rows', 'skipped' ) );
$run = tmgt_import( $csv_path, array_merge( $mopts, array( 'dry_run' => 1, 'existing' => 'copy' ) ) );
tmgt_check( 'mapping import: dry run creates nothing', 2 === tmgt_count( $run, 'rows', 'created' ) && ! tmgt_post( 'tmgt-mapped-one-2', 'tmgt_book' ) );
$pv = TMG_IO_Importer::preview( TMG_IO_Importer::work_from_parsed( TMG_IO_Files::read( $csv_path ), $csv_path )['work'], $mopts );
tmgt_check( 'preview: counts matches for mapped rows', 2 === ( $pv['rows']['update'] ?? 0 ), $pv );
$pv = TMG_IO_Importer::preview( TMG_IO_Importer::work_from_parsed( TMG_IO_Files::read( $json ), $json )['work'], array( 'existing' => 'skip' ) );
tmgt_check( 'preview: package sections with would-skip counts', ( $pv['posts']['skip'] ?? 0 ) >= 5 && isset( $pv['acf'], $pv['terms'], $pv['users'], $pv['menus'], $pv['options'], $pv['media'] ), $pv );
foreach ( array( 'tmgt-mapped-one', 'tmgt-mapped-two' ) as $slug ) {
	$p = tmgt_post( $slug, 'tmgt_book' );
	if ( $p ) {
		wp_delete_post( $p->ID, true );
	}
}
wp_delete_attachment( $photo, true );
wp_delete_file( $src_dir . '/remote-photo.png' );
@rmdir( $src_dir ); // phpcs:ignore
$poetry = get_term_by( 'slug', 'tmgt-poetry', 'tmgt_genre' );
if ( $poetry ) {
	wp_delete_term( $poetry->term_id, 'tmgt_genre' );
}

/* ---- Capability refusals --------------------------------------------- */
$author = wp_insert_user( array( 'user_login' => 'tmgt_author', 'user_email' => 'tmgt_author@example.com', 'user_pass' => wp_generate_password(), 'role' => 'author' ) );
( new WP_User( $author ) )->add_cap( 'tmg_io_import' );
wp_set_current_user( $author );
$evil_pkg                     = TMG_IO_Files::empty_package();
$evil_pkg['site']             = 'https://other.example';
$evil_pkg['options']          = array( 'blogname' => 'Hacked', 'tmgt_plain_option' => 'hacked' );
$evil_pkg['acf_structure']    = array( array( 'key' => 'group_tmgt_evil', 'title' => 'Evil', 'fields' => array(), 'location' => array() ) );
$evil_pkg['users']            = array( array( 'id' => 900, 'login' => 'tmgt_evil', 'email' => 'tmgt_evil@example.com', 'roles' => array( 'administrator' ), 'pass' => 'secret', 'user_pass' => 'secret', 'meta' => array( 'wp_capabilities' => array( 'administrator' => true ) ) ) );
$evil_pkg['posts']            = array(
	array( 'id' => 901, 'type' => 'page', 'title' => 'TMGT Evil Page', 'slug' => 'tmgt-evil-page', 'status' => 'publish', 'content' => 'x', 'meta' => array(), 'terms' => array() ),
	array( 'id' => 902, 'type' => 'post', 'title' => 'TMGT Author Post', 'slug' => 'tmgt-author-post', 'status' => 'publish', 'content' => '<p>ok</p><script>alert(1)</script>', 'meta' => array( 'tmgt_html' => '<b>x</b><script>alert(2)</script>', '_tmgt_internal' => 'x', 'acf_ok' => 'y', '_acf_ok' => 'field_tmgt_text' ), 'terms' => array() ),
	array( 'id' => 903, 'type' => 'tmgt_book', 'title' => 'TMGT Book Alpha', 'slug' => 'tmgt-book-alpha', 'status' => 'publish', 'content' => 'overwrite', 'meta' => array(), 'terms' => array() ),
);
$evil_path = TMG_IO_Storage::new_path( 'json', 'evil' );
file_put_contents( $evil_path, wp_json_encode( $evil_pkg ) );
$GLOBALS['tmgt']['files'][] = $evil_path;
$run = tmgt_import( $evil_path, array( 'existing' => 'update' ) );
wp_set_current_user( $admin->ID );
$ap  = tmgt_post( 'tmgt-author-post', 'post' );
tmgt_check( 'caps: options refused without manage permission', 'hacked' !== get_option( 'tmgt_plain_option' ) && 'Hacked' !== get_option( 'blogname' ) && tmgt_count( $run, 'options', 'error' ) >= 1 );
tmgt_check( 'caps: ACF structure refused', ! TMG_IO_ACF::existing_item( array( 'key' => 'group_tmgt_evil' ), 'acf-field-group' ) && 1 === tmgt_count( $run, 'acf', 'error' ) );
tmgt_check( 'caps: users refused without create_users', ! get_user_by( 'login', 'tmgt_evil' ) && 1 === tmgt_count( $run, 'users', 'error' ) );
tmgt_check( 'caps: page refused for an author, others\' book not editable', ! tmgt_post( 'tmgt-evil-page', 'page' ) && 'overwrite' !== get_post_field( 'post_content', tmgt_post( 'tmgt-book-alpha', 'tmgt_book' )->ID ) );
tmgt_check( 'caps: hidden meta refused without manage permission, ACF references kept', $ap && '' === get_post_meta( $ap->ID, '_tmgt_internal', true ) && 'field_tmgt_text' === get_post_meta( $ap->ID, '_acf_ok', true ) );
tmgt_check( 'caps: own post allowed, raw HTML cleaned without unfiltered_html', $ap && false === strpos( $ap->post_content, '<script' ) && false === strpos( (string) get_post_meta( $ap->ID, 'tmgt_html', true ), '<script' ) && false !== strpos( (string) get_post_meta( $ap->ID, 'tmgt_html', true ), '<b>x</b>' ) );
if ( $ap ) {
	wp_delete_post( $ap->ID, true );
}
// A user manager may create users but never above their own role, and passwords are never imported.
add_role( 'tmgt_usermgr', 'TMGT user manager', array( 'read' => true, 'level_0' => true, 'list_users' => true, 'create_users' => true, 'promote_users' => true, 'edit_users' => true, 'tmg_io_import' => true ) );
$mgr = wp_insert_user( array( 'user_login' => 'tmgt_usermgr', 'user_email' => 'tmgt_usermgr@example.com', 'user_pass' => wp_generate_password(), 'role' => 'tmgt_usermgr' ) );
wp_set_current_user( $mgr );
$run  = tmgt_import( $evil_path, array( 'existing' => 'skip', 'sections' => array( 'users' ) ) );
wp_set_current_user( $admin->ID );
$evil = get_user_by( 'login', 'tmgt_evil' );
tmgt_check( 'caps: imported user never gets a role above the importer (administrator refused)', $evil && ! in_array( 'administrator', $evil->roles, true ) && ! user_can( $evil, 'manage_options' ), $evil ? $evil->roles : $run['report'] );
tmgt_check( 'caps: passwords never imported (random password set)', $evil && ! wp_check_password( 'secret', $evil->user_pass, $evil->ID ) );
remove_role( 'tmgt_usermgr' );
tmgt_check( 'caps: export refused without tmg_io_export (screen capability)', ! user_can( $author, 'tmg_io_export' ) );

/* ---- Guards: zip slip, formula injection, XXE ------------------------ */
tmgt_check(
	'zip slip: unsafe entry names refused',
	! TMG_IO_Files::safe_zip_entry( '../evil.php' ) && ! TMG_IO_Files::safe_zip_entry( 'media/../../evil.php' ) && ! TMG_IO_Files::safe_zip_entry( '/etc/passwd' ) && ! TMG_IO_Files::safe_zip_entry( 'C:/Windows/x' ) && ! TMG_IO_Files::safe_zip_entry( 'media\\..\\x' ) && TMG_IO_Files::safe_zip_entry( 'media/12/photo.jpg' )
);
$slip = TMG_IO_Storage::new_path( 'zip', 'slip' );
$z    = new ZipArchive();
$z->open( $slip, ZipArchive::CREATE );
$z->addFromString( '../../tmgt-evil.php', '<?php echo 1;' );
$z->addFromString( 'media/1/../../../tmgt-evil2.php', '<?php echo 2;' );
$sp                         = TMG_IO_Files::empty_package();
$sp['media']                = array( '1' => array( 'id' => 1, 'url' => '', 'file' => 'media/1/../../../tmgt-evil2.php', 'filename' => 'x.png' ) );
$z->addFromString( 'package.json', wp_json_encode( $sp ) );
$z->close();
$GLOBALS['tmgt']['files'][] = $slip;
$r1 = TMG_IO_Media::from_zip( $slip, 'media/1/../../../tmgt-evil2.php' );
$r2 = TMG_IO_Media::from_zip( $slip, '../../tmgt-evil.php' );
$run = tmgt_import( $slip, array( 'existing' => 'skip' ) );
tmgt_check( 'zip slip: entries escaping the folder are never written', is_wp_error( $r1 ) && is_wp_error( $r2 ) && ! file_exists( WP_CONTENT_DIR . '/tmgt-evil.php' ) && ! file_exists( ABSPATH . 'tmgt-evil2.php' ) && ! file_exists( dirname( TMG_IO_Storage::dir() ) . '/tmgt-evil2.php' ) && 1 === tmgt_count( $run, 'media', 'error' ) );
$csvs = TMG_IO_Files::csv_string( array( array( 'a', 'b', 'c', 'd', 'e', 'f' ), array( '=1+1', '+cmd', '-x', '@SUM(A1)', '-5', 'plain' ) ) );
tmgt_check( 'formula injection: CSV cells starting with = + - @ are prefixed', false !== strpos( $csvs, "'=1+1" ) && false !== strpos( $csvs, "'+cmd" ) && false !== strpos( $csvs, "'-x" ) && false !== strpos( $csvs, "'@SUM(A1)" ) && false !== strpos( $csvs, ',-5,' ) );
$xp   = tmg_io_xlsx_write( array( 'S' => array( array( 'h1', 'h2' ), array( '=HYPERLINK("x")', 'ok' ) ) ) );
$back = tmg_io_read_file( $xp );
TMG_IO_Storage::delete( $xp );
tmgt_check( 'formula injection: XLSX cells prefixed too', "'=HYPERLINK(\"x\")" === ( $back['S'][1][0] ?? '' ), $back );
$xxe = TMG_IO_Storage::new_path( 'xlsx', 'xxe' );
$z   = new ZipArchive();
$z->open( $xxe, ZipArchive::CREATE );
$z->addFromString( '[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>' );
$z->addFromString( 'xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="S" sheetId="1" r:id="rId1"/></sheets></workbook>' );
$z->addFromString( 'xl/sharedStrings.xml', '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY xxe SYSTEM "file:///' . str_replace( '\\', '/', ABSPATH ) . 'wp-config.php">]><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>&xxe;</t></si></sst>' );
$z->addFromString( 'xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c></row></sheetData></worksheet>' );
$z->close();
$GLOBALS['tmgt']['files'][] = $xxe;
$xxe_ok = false;
try {
	$data   = TMG_IO_Xlsx::read( $xxe );
	$xxe_ok = false === strpos( wp_json_encode( $data ), 'DB_NAME' );
} catch ( RuntimeException $e ) {
	$xxe_ok = true;
}
tmgt_check( 'XXE: xlsx with external entities is refused', $xxe_ok );
tmgt_check( 'upload check: content must match the extension', ( function () use ( $xxe ) {
	try {
		TMG_IO_Files::validate_upload( $xxe, 'data.csv', 100 );
		return false;
	} catch ( RuntimeException $e ) {
		return true;
	}
} )() );
tmgt_check( 'upload check: size limit', ( function () use ( $json ) {
	try {
		TMG_IO_Files::validate_upload( $json, 'a.json', 999 * MB_IN_BYTES );
		return false;
	} catch ( RuntimeException $e ) {
		return true;
	}
} )() );
tmgt_check( 'storage: folder is protected from web access', file_exists( TMG_IO_Storage::dir() . '.htaccess' ) && file_exists( TMG_IO_Storage::dir() . 'index.php' ) );
$resp = wp_remote_get( wp_upload_dir()['baseurl'] . '/tamago-io/' . basename( $json ) );
tmgt_check( 'storage: working files not downloadable over HTTP', ! is_wp_error( $resp ) && 403 === (int) wp_remote_retrieve_response_code( $resp ), is_wp_error( $resp ) ? $resp->get_error_message() : wp_remote_retrieve_response_code( $resp ) );

/* ---- Public helpers, sources, settings ------------------------------ */
$hp   = tmg_io_xlsx_write( array( 'Één' => array( array( 'Name', 'Count', 'Code' ), array( 'Zoë ✓', 3, '007' ) ), 'Two' => array( array( 'x' ), array( 'y' ) ) ) );
$hr   = tmg_io_read_file( $hp );
TMG_IO_Storage::delete( $hp );
tmgt_check( 'helpers: tmg_io_xlsx_write / tmg_io_read_file keep sheets, unicode, numbers and leading zeros', array( 'Zoë ✓', '3', '007' ) === ( $hr['Één'][1] ?? null ) && array( 'y' ) === ( $hr['Two'][1] ?? null ), $hr );
$seen = array();
add_filter(
	'tmg_io_sources',
	function ( $s ) use ( &$seen ) {
		$s['tmgt_src'] = array(
			'label'      => 'TMGT source',
			'capability' => 'manage_options',
			'formats'    => array( 'xlsx', 'csv' ),
			'fields'     => fn( $ctx ) => '<input name="choices[event]" value="5">',
			'export'     => fn( $c ) => array( 'People' => array( array( 'Name', 'Email' ), array( 'Ann', 'ann@example.com' ) ) ),
			'import'     => function ( $rows, $mapping, $opts ) use ( &$seen ) {
				$seen[] = array( $rows, $mapping, $opts );
				return array( 'created' => count( $rows ), 'updated' => 0, 'skipped' => 0, 'errors' => array( 1 => 'bad row' ) );
			},
			'columns'    => fn( $c ) => array( 'name' => 'Name', 'email' => 'Email' ),
		);
		return $s;
	}
);
$src     = TMG_IO_Sources::available( true );
$sheets  = isset( $src['tmgt_src'] ) ? TMG_IO_Sources::export( $src['tmgt_src'], array() ) : array();
tmgt_check( 'sources: registered through tmg_io_sources and exported as sheets', isset( $src['tmgt_src'] ) && 'Ann' === ( $sheets['People'][1][0] ?? '' ) && array( 'name' => 'Name', 'email' => 'Email' ) === TMG_IO_Sources::columns( $src['tmgt_src'], array() ) );
$rid = TMG_IO_Importer::create_run( array( 'rows' => array( array( 'Full name' => 'Bob', 'Mail' => 'bob@example.com' ), array( 'Full name' => 'Cy', 'Mail' => 'cy@example.com' ) ) ), array( 'source' => 'tmgt_src', 'mapping' => array( 'Full name' => 'src:name', 'Mail' => 'src:email' ), 'choices' => array( 'event' => '5' ) ), 'source test', 'csv', '' );
TMG_IO_Importer::run_all( $rid );
$sr = TMG_IO_Runs::get( $rid );
tmgt_check( 'sources: import receives rows, mapping and choices; report merged', 1 === count( $seen ) && 'Bob' === $seen[0][0][0]['Full name'] && 'name' === $seen[0][1]['Full name'] && '5' === $seen[0][2]['choices']['event'] && 2 === tmgt_count( $sr, 'source', 'created' ) && 1 === tmgt_count( $sr, 'source', 'error' ), array( $seen, $sr['summary'] ) );
$saved = TMG_IO_Settings::export_data();
TMG_IO_Settings::save( array( 'batch_size' => 7, 'remote_media' => 1 ) );
$err   = TMG_IO_Settings::import_data( $saved );
tmgt_check( 'settings: JSON export / import round trip (shape plugin / version / exported / settings)', '' === $err && 'tamago-io' === $saved['plugin'] && isset( $saved['version'], $saved['exported'], $saved['settings']['presets'] ) && (int) $saved['settings']['batch_size'] === (int) TMG_IO_Settings::get( 'batch_size' ) );
TMG_IO_Presets::save( TMG_IO_Presets::EXPORT, 'tmgt preset', $choices );
tmgt_check( 'presets: saved with all choices', array( 'tmgt_book', 'page', 'post' ) === ( TMG_IO_Presets::get( TMG_IO_Presets::EXPORT, 'tmgt preset' )['types'] ?? null ) );
TMG_IO_Presets::delete( TMG_IO_Presets::EXPORT, 'tmgt preset' );
TMG_IO_Presets::delete( TMG_IO_Presets::MAPPING, 'tmgt books' );
tmgt_check( 'permissions: registered through tamago_permissions', isset( apply_filters( 'tamago_permissions', array() )['io_import'] ) );

/* ---- Final state ------------------------------------------------------ */
tmgt_check( 'final: fixtures intact after all tests', '' === tmgt_diff( $before, tmgt_snapshot() ), tmgt_diff( $before, tmgt_snapshot() ) );
foreach ( array( 'tmgt_author', 'tmgt_usermgr', 'tmgt_evil' ) as $login ) {
	$u = get_user_by( 'login', $login );
	if ( $u ) {
		wp_delete_user( $u->ID );
	}
}
foreach ( $GLOBALS['tmgt']['files'] as $f ) {
	TMG_IO_Storage::delete( $f );
}
global $wpdb;
// Forget only the runs this test created.
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . TMG_IO_Runs::table() . ' WHERE id > %d', $first_run ) ); // phpcs:ignore

$p = $GLOBALS['tmgt']['pass'];
$f = $GLOBALS['tmgt']['fail'];
echo "\nTOTAL: {$p} passed, {$f} failed\n";
