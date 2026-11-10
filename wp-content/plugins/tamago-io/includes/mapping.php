<?php
/**
 * Column-mapping import for CSV / Excel / plain JSON rows: targets for a post type (post fields,
 * taxonomies, custom fields, ACF fields by group), automatic guesses, row matching and the
 * type-aware writing of each mapped cell.
 *
 * Used by: Import tab (mapping step), importer (rows phase, edited spreadsheet cells), WP-CLI.
 */

defined( 'ABSPATH' ) || exit;

class TMG_IO_Mapping {

	/** Post fields that can be mapped. */
	public static function core_targets(): array {
		return array(
			'ID'             => __( 'ID (for matching)', 'tamago-io' ),
			'post_title'     => __( 'Title', 'tamago-io' ),
			'post_content'   => __( 'Content', 'tamago-io' ),
			'post_excerpt'   => __( 'Excerpt', 'tamago-io' ),
			'post_name'      => __( 'Slug', 'tamago-io' ),
			'post_status'    => __( 'Status', 'tamago-io' ),
			'post_date'      => __( 'Date', 'tamago-io' ),
			'post_author'    => __( 'Author (login, email or ID)', 'tamago-io' ),
			'post_parent'    => __( 'Parent (ID, slug or title)', 'tamago-io' ),
			'menu_order'     => __( 'Menu order', 'tamago-io' ),
			'comment_status' => __( 'Comments (open / closed)', 'tamago-io' ),
			'featured_image' => __( 'Featured image (URL or ID)', 'tamago-io' ),
		);
	}

	public static function sanitize_target( string $t ): string {
		$t = trim( $t );
		if ( '' === $t ) {
			return '';
		}
		if ( isset( self::core_targets()[ $t ] ) ) {
			return $t;
		}
		if ( preg_match( '/^(tax|acf|meta|src):([A-Za-z0-9_\-.]{1,190})$/', $t, $m ) ) {
			return $m[1] . ':' . $m[2];
		}
		return '';
	}

	/** Field types that hold no value. */
	private static function is_layout_field( array $f ): bool {
		return in_array( $f['type'] ?? '', array( 'tab', 'message', 'accordion' ), true );
	}

	/**
	 * Targets for a post type, grouped: label => [target => label].
	 */
	public static function targets( string $post_type ): array {
		global $wpdb;
		$groups                                          = array();
		$groups[ __( 'Post fields', 'tamago-io' ) ]      = self::core_targets();
		$tax                                             = array();
		foreach ( get_object_taxonomies( $post_type, 'objects' ) as $t ) {
			if ( 'post_format' === $t->name && ! current_theme_supports( 'post-formats' ) ) {
				continue;
			}
			/* translators: %s: taxonomy name */
			$tax[ 'tax:' . $t->name ] = sprintf( __( '%s (comma-separated, missing ones created)', 'tamago-io' ), $t->labels->name );
		}
		if ( $tax ) {
			$groups[ __( 'Taxonomies', 'tamago-io' ) ] = $tax;
		}
		$acf_names = array();
		foreach ( TMG_IO_ACF::groups_for_post_type( $post_type ) as $g ) {
			$list = array();
			foreach ( $g['fields'] as $f ) {
				if ( self::is_layout_field( $f ) ) {
					continue;
				}
				$list[ 'acf:' . $f['key'] ]   = ( $f['label'] ? $f['label'] : $f['name'] ) . ' (' . $f['name'] . ', ' . $f['type'] . ')';
				$acf_names[ $f['name'] ]      = true;
			}
			if ( $list ) {
				/* translators: %s: field group title */
				$groups[ sprintf( __( 'ACF: %s', 'tamago-io' ), $g['title'] ) ] = $list;
			}
		}
		$keys = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT m.meta_key FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.post_type = %s AND m.meta_key NOT LIKE %s ORDER BY m.meta_key LIMIT 200", $post_type, $wpdb->esc_like( '_' ) . '%' ) );
		$meta = array();
		foreach ( $keys as $k ) {
			if ( isset( $acf_names[ $k ] ) || preg_match( '/_\d+_/', $k ) ) {
				continue;
			}
			$meta[ 'meta:' . $k ] = $k;
		}
		if ( $meta ) {
			$groups[ __( 'Custom fields', 'tamago-io' ) ] = $meta;
		}
		return $groups;
	}

	/** Best target for a column header ('' = ignore). */
	public static function guess( string $header, string $post_type, array $groups = array() ): string {
		$h = trim( $header );
		if ( '' === $h || 0 === strpos( $h, TMG_IO_Flatten::JSON_COL ) ) {
			return '';
		}
		$groups = $groups ? $groups : self::targets( $post_type );
		if ( 0 === strpos( $h, 'acf:' ) || 0 === strpos( $h, 'meta:' ) || 0 === strpos( $h, 'tax:' ) || isset( self::core_targets()[ $h ] ) ) {
			$t = self::target_for_column( $h, $post_type );
			if ( '' !== $t ) {
				return $t;
			}
		}
		$n       = sanitize_title( remove_accents( $h ) );
		$aliases = array(
			'id'             => 'ID',
			'title'          => 'post_title',
			'name'           => 'post_title',
			'content'        => 'post_content',
			'body'           => 'post_content',
			'excerpt'        => 'post_excerpt',
			'summary'        => 'post_excerpt',
			'slug'           => 'post_name',
			'status'         => 'post_status',
			'date'           => 'post_date',
			'published'      => 'post_date',
			'author'         => 'post_author',
			'parent'         => 'post_parent',
			'order'          => 'menu_order',
			'image'          => 'featured_image',
			'featured-image' => 'featured_image',
			'thumbnail'      => 'featured_image',
			'categories'     => 'tax:category',
			'tags'           => 'tax:post_tag',
		);
		if ( isset( $aliases[ $n ] ) ) {
			$t = $aliases[ $n ];
			foreach ( $groups as $list ) {
				if ( isset( $list[ $t ] ) ) {
					return $t;
				}
			}
		}
		foreach ( $groups as $list ) {
			foreach ( $list as $target => $label ) {
				$name = (string) preg_replace( '/^(tax|meta):/', '', (string) $target );
				if ( 0 === strpos( (string) $target, 'acf:' ) ) {
					$f    = TMG_IO_ACF::field( substr( (string) $target, 4 ) );
					$name = $f ? (string) $f['name'] : $name;
					if ( $f && $n === sanitize_title( (string) $f['label'] ) ) {
						return (string) $target;
					}
				}
				if ( $n === sanitize_title( $name ) || $n === sanitize_title( (string) preg_replace( '/\s*\(.*$/', '', (string) $label ) ) ) {
					return (string) $target;
				}
			}
		}
		return '';
	}

	/** Target for a column of our own flat export (acf:name → acf:field_key). */
	public static function target_for_column( string $col, string $post_type ): string {
		if ( isset( self::core_targets()[ $col ] ) ) {
			return $col;
		}
		if ( 0 === strpos( $col, 'acf:' ) ) {
			$name = substr( $col, 4 );
			if ( 0 === strpos( $name, 'field_' ) && TMG_IO_ACF::field( $name ) ) {
				return 'acf:' . $name;
			}
			foreach ( TMG_IO_ACF::fields_for_post_type( $post_type ) as $key => $f ) {
				if ( $f['name'] === $name ) {
					return 'acf:' . $key;
				}
			}
			return self::sanitize_target( 'meta:' . $name );
		}
		return self::sanitize_target( $col );
	}

	/** The cell mapped to a target in a row ('' when not mapped). */
	private static function cell_for( array $map, array $record, callable $test ): ?string {
		foreach ( $map as $col => $target ) {
			if ( '' !== $target && $test( $target ) && array_key_exists( $col, $record ) ) {
				return TMG_IO_Files::unescape_cell( trim( (string) $record[ $col ] ) );
			}
		}
		return null;
	}

	/** Existing post for a row by the chosen match rule (0 when none). */
	public static function match_row( TMG_IO_Importer $imp, array $record ): int {
		$o    = $imp->opts();
		$map  = (array) $o['mapping'];
		$type = (string) $o['post_type'];
		switch ( $o['match'] ) {
			case 'id':
				$v = self::cell_for( $map, $record, fn( $t ) => 'ID' === $t );
				return null !== $v && ctype_digit( $v ) ? $imp->find_post_by( $type, 'id', (int) $v ) : 0;
			case 'slug':
				$v = self::cell_for( $map, $record, fn( $t ) => 'post_name' === $t );
				if ( null === $v || '' === $v ) {
					$v = (string) self::cell_for( $map, $record, fn( $t ) => 'post_title' === $t );
				}
				return '' !== $v ? $imp->find_post_by( $type, 'slug', sanitize_title( $v ) ) : 0;
			case 'meta':
				$key = (string) $o['match_key'];
				$v   = self::cell_for(
					$map,
					$record,
					function ( $t ) use ( $key ) {
						if ( 'meta:' . $key === $t ) {
							return true;
						}
						if ( 0 === strpos( $t, 'acf:' ) ) {
							$f = TMG_IO_ACF::field( substr( $t, 4 ) );
							return $f && $f['name'] === $key;
						}
						return false;
					}
				);
				return null !== $v && '' !== $v ? $imp->find_post_by( $type, 'meta', $v, $key ) : 0;
		}
		return 0;
	}

	/** Import one mapped row as a post. */
	public static function import_row( TMG_IO_Importer $imp, array $record, int $index ): void {
		$o     = $imp->opts();
		$type  = (string) $o['post_type'];
		$map   = (array) $o['mapping'];
		$rowno = $index + 2; // Spreadsheet row number (after the header).
		$pto   = get_post_type_object( $type );
		if ( ! $pto ) {
			/* translators: %s: post type */
			$imp->report( 'rows', $rowno, '', 'error', '', sprintf( __( 'Post type "%s" is not registered.', 'tamago-io' ), $type ) );
			return;
		}
		$core = array();
		$rest = array();
		foreach ( $map as $col => $target ) {
			if ( '' === $target || ! array_key_exists( $col, $record ) ) {
				continue;
			}
			$v = TMG_IO_Files::unescape_cell( (string) $record[ $col ] );
			if ( isset( self::core_targets()[ $target ] ) && 'featured_image' !== $target ) {
				$core[ $target ] = $v;
			} else {
				$rest[] = array( $target, $v );
			}
		}
		$title    = (string) ( $core['post_title'] ?? '' );
		$existing = 'copy' === $o['existing'] ? 0 : self::match_row( $imp, $record );
		if ( $existing && 'skip' === $o['existing'] ) {
			$imp->report( 'rows', $rowno, $title, 'skipped', $existing, __( 'Already exists.', 'tamago-io' ) );
			return;
		}
		if ( $existing && ! current_user_can( 'edit_post', $existing ) ) {
			$imp->report( 'rows', $rowno, $title, 'error', $existing, __( 'You are not allowed to edit this item.', 'tamago-io' ) );
			return;
		}
		if ( ! $existing && ! current_user_can( $pto->cap->create_posts ) ) {
			$imp->report( 'rows', $rowno, $title, 'error', '', __( 'You are not allowed to create this type of content.', 'tamago-io' ) );
			return;
		}
		$keep_empty = $existing && empty( $o['empty_missing'] );
		$args       = array( 'post_type' => $type );
		foreach ( $core as $target => $v ) {
			$v = trim( $v );
			if ( '' === $v && ( $keep_empty || ! $existing ) ) {
				continue;
			}
			switch ( $target ) {
				case 'post_title':
				case 'post_content':
				case 'post_excerpt':
					$args[ $target ] = $v;
					break;
				case 'post_name':
					$args['post_name'] = sanitize_title( $v );
					break;
				case 'post_status':
					$args['post_status'] = TMG_IO_Importer::allowed_status( $pto, strtolower( $v ), $imp );
					break;
				case 'post_date':
					$d = TMG_IO_Convert::date( $v, 'Y-m-d H:i:s' );
					if ( '' !== $d ) {
						$args['post_date'] = $d;
					}
					break;
				case 'post_author':
					$uid = TMG_IO_Convert::find_user( $v );
					if ( $uid && ( $uid === get_current_user_id() || current_user_can( $pto->cap->edit_others_posts ) ) ) {
						$args['post_author'] = $uid;
					} elseif ( '' !== $v ) {
						$imp->note( __( 'Author not found or not allowed; you are the author.', 'tamago-io' ) );
					}
					break;
				case 'post_parent':
					$args['post_parent'] = '' === $v ? 0 : TMG_IO_Convert::find_post( $v, array( $type ) );
					break;
				case 'menu_order':
					$args['menu_order'] = (int) $v;
					break;
				case 'comment_status':
					$args['comment_status'] = TMG_IO_Convert::bool( $v ) || 'open' === strtolower( $v ) ? 'open' : 'closed';
					break;
			}
		}
		if ( ! $existing ) {
			if ( ! isset( $args['post_status'] ) ) {
				$args['post_status'] = TMG_IO_Importer::allowed_status( $pto, 'publish', $imp );
			}
			if ( ! isset( $args['post_title'] ) && ! isset( $args['post_content'] ) ) {
				/* translators: %d: row number */
				$args['post_title'] = sprintf( __( 'Imported row %d', 'tamago-io' ), $rowno );
			}
			// A slug even for drafts (WordPress leaves it empty), unique, so the next import can match it.
			$slug = $args['post_name'] ?? sanitize_title( (string) ( $args['post_title'] ?? '' ) );
			if ( '' !== $slug ) {
				$args['post_name'] = wp_unique_post_slug( $slug, 0, 'publish', $type, (int) ( $args['post_parent'] ?? 0 ) );
			}
			if ( $o['keep_ids'] && ! empty( $core['ID'] ) && ctype_digit( trim( $core['ID'] ) ) && ! get_post( (int) $core['ID'] ) ) {
				$args['import_id'] = (int) $core['ID'];
			}
		} else {
			$args['ID'] = $existing;
		}
		$args = apply_filters( 'tmg_io_import_post', $args, array( 'row' => $record, 'type' => $type ), $o );
		if ( ! is_array( $args ) || ! $args ) {
			$imp->report( 'rows', $rowno, $title, 'skipped', $existing, __( 'Skipped by a filter.', 'tamago-io' ) );
			return;
		}
		$action = $existing ? 'updated' : 'created';
		if ( $imp->dry() ) {
			$imp->report( 'rows', $rowno, $title, $action, $existing ? $existing : '' );
			return;
		}
		$id = $existing ? wp_update_post( wp_slash( $args ), true ) : wp_insert_post( wp_slash( $args ), true );
		if ( is_wp_error( $id ) || ! $id ) {
			$imp->report( 'rows', $rowno, $title, 'error', '', is_wp_error( $id ) ? $id->get_error_message() : __( 'Could not be saved.', 'tamago-io' ) );
			return;
		}
		foreach ( $rest as list( $target, $v ) ) {
			if ( '' === trim( $v ) && $keep_empty ) {
				continue;
			}
			if ( '' === trim( $v ) && ! $existing ) {
				continue;
			}
			self::apply_target( $imp, (int) $id, $type, $target, $v );
		}
		do_action( 'tmg_io_imported_post', (int) $id, array( 'row' => $record, 'type' => $type ), $action, $o );
		$imp->report( 'rows', $rowno, $title ? $title : get_the_title( (int) $id ), $action, (int) $id );
	}

	/** Write one mapped cell to a post: taxonomy, custom field, ACF field or featured image. */
	public static function apply_target( TMG_IO_Importer $imp, int $post_id, string $post_type, string $target, string $value ): void {
		$value = TMG_IO_Files::unescape_cell( $value );
		if ( '' === $target || $imp->dry() ) {
			return;
		}
		if ( 'featured_image' === $target ) {
			if ( '' === trim( $value ) ) {
				delete_post_thumbnail( $post_id );
				return;
			}
			$mid = TMG_IO_Convert::media( $value, $imp );
			if ( $mid > 0 ) {
				set_post_thumbnail( $post_id, $mid );
			} else {
				$imp->note( __( 'Featured image not found or not downloaded.', 'tamago-io' ) );
			}
			return;
		}
		list( $kind, $name ) = array_pad( explode( ':', $target, 2 ), 2, '' );
		switch ( $kind ) {
			case 'tax':
				if ( ! is_object_in_taxonomy( $post_type, $name ) ) {
					return;
				}
				$tx = get_taxonomy( $name );
				if ( ! current_user_can( $tx->cap->assign_terms ) ) {
					$imp->note( __( 'Terms not assigned: not allowed.', 'tamago-io' ) );
					return;
				}
				$ids = array();
				foreach ( TMG_IO_Convert::tokens( $value ) as $t ) {
					$tid = TMG_IO_Convert::find_term( $t, $name, true, $imp );
					if ( $tid > 0 ) {
						$ids[] = $tid;
					} else {
						/* translators: %s: term */
						$imp->note( sprintf( __( 'Term "%s" not found and not created.', 'tamago-io' ), $t ) );
					}
				}
				wp_set_object_terms( $post_id, $ids, $name );
				return;
			case 'meta':
				if ( TMG_IO_Exporter::skip_meta( $name, TMG_IO_Exporter::skip_meta_keys( 'post' ) ) ) {
					return;
				}
				// Hidden (underscore) fields are internal data: only for users who manage the plugin.
				if ( is_protected_meta( $name, 'post' ) && ! current_user_can( TMG_IO_Permissions::MANAGE ) ) {
					/* translators: %s: meta key */
					$imp->note( sprintf( __( 'Hidden field "%s" needs the manage permission.', 'tamago-io' ), $name ) );
					return;
				}
				$d = TMG_IO_Convert::json( $value );
				update_post_meta( $post_id, $name, wp_slash( TMG_IO_Convert::clean_deep( null !== $d ? $d : $value ) ) );
				return;
			case 'acf':
				$field = TMG_IO_ACF::field( $name );
				if ( ! $field || ! function_exists( 'update_field' ) ) {
					/* translators: %s: field key */
					$imp->note( sprintf( __( 'ACF field %s not found.', 'tamago-io' ), $name ) );
					return;
				}
				update_field( $field['key'], TMG_IO_Convert::to_acf( $field, $value, $imp ), $post_id );
				return;
		}
	}
}
