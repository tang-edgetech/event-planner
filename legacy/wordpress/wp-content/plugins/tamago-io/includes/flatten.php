<?php
/**
 * Flat sheets for CSV / Excel: one sheet per post type plus Terms, Users, Options, Menus, ACF.
 * Readable columns (post_title, tax:category, acf:field, meta:key …) and a `_tmg_json` column
 * (split into `_tmg_json_2` … when longer than an Excel cell) carrying the full item, so flat
 * files round-trip. Cells edited in a spreadsheet override the JSON on import.
 *
 * Used by: exporter (CSV / XLSX), importer (reading flat files back), mapping (column names).
 */

defined( 'ABSPATH' ) || exit;

class TMG_IO_Flatten {

	const JSON_COL = '_tmg_json';

	/** JSON chunk length per cell (below Excel's 32,767 limit). */
	const CHUNK = 30000;

	/** Readable cells are cut to this length (the JSON keeps everything). */
	const CELL = 32000;

	/** Core columns ⇄ package keys. */
	const CORE = array(
		'ID'             => 'id',
		'post_type'      => 'type',
		'post_title'     => 'title',
		'post_name'      => 'slug',
		'post_status'    => 'status',
		'post_date'      => 'date',
		'post_author'    => 'author',
		'post_parent'    => 'parent',
		'menu_order'     => 'menu_order',
		'post_excerpt'   => 'excerpt',
		'post_content'   => 'content',
		'comment_status' => 'comment_status',
		'featured_image' => 'featured_image',
	);

	/** Source site written into every row, so each row stands alone. */
	private static $site = '';

	/**
	 * Package → sheets (sheet name => table). The About sheet (source site, date) is left out of
	 * CSV exports so a single post type stays a single CSV file; every row carries the site anyway.
	 */
	public static function sheets( array $pkg, bool $about_sheet = true ): array {
		$sheets     = array();
		self::$site = (string) ( $pkg['site'] ?? '' );
		$about  = array(
			'kind'     => 'about',
			'site'     => $pkg['site'] ?? '',
			'exported' => $pkg['exported'] ?? '',
			'plugin'   => $pkg['plugin'] ?? '',
			'acf'      => $pkg['acf'] ?? '',
		);
		$by_type = array();
		foreach ( (array) $pkg['posts'] as $p ) {
			$row                     = self::post_row( $p, (array) ( $pkg['lookup']['terms'] ?? array() ) );
			$by_type[ $p['type'] ][] = self::with_json( $row, array( 'kind' => 'post', 'item' => $p, 'h' => self::hashes( $row ) ) + self::subset( $pkg, $p ) );
		}
		foreach ( $by_type as $type => $rows ) {
			$sheets[ $type ] = TMG_IO_Files::to_table( $rows );
		}
		$simple = array(
			'Terms' => array(),
			'Users' => array(),
			'Menus' => array(),
			'ACF'   => array(),
		);
		foreach ( (array) $pkg['terms'] as $t ) {
			$simple['Terms'][] = self::with_json(
				array(
					'taxonomy'    => $t['taxonomy'],
					'name'        => $t['name'],
					'slug'        => $t['slug'],
					'parent'      => (string) ( $pkg['lookup']['terms'][ (string) $t['parent'] ]['slug'] ?? '' ),
					'description' => $t['description'],
				),
				array( 'kind' => 'term', 'item' => $t ) + self::subset( $pkg, $t )
			);
		}
		foreach ( (array) $pkg['users'] as $u ) {
			$simple['Users'][] = self::with_json(
				array(
					'user_login'   => $u['login'],
					'user_email'   => $u['email'],
					'display_name' => $u['display_name'],
					'first_name'   => $u['first_name'],
					'last_name'    => $u['last_name'],
					'roles'        => implode( ', ', (array) $u['roles'] ),
				),
				array( 'kind' => 'user', 'item' => $u ) + self::subset( $pkg, $u )
			);
		}
		foreach ( (array) $pkg['menus'] as $m ) {
			$simple['Menus'][] = self::with_json(
				array(
					'name'      => $m['name'],
					'slug'      => $m['slug'],
					'locations' => implode( ', ', (array) $m['locations'] ),
					'items'     => count( (array) $m['items'] ),
				),
				array( 'kind' => 'menu', 'item' => $m ) + self::subset( $pkg, $m )
			);
		}
		foreach ( (array) $pkg['acf_structure'] as $a ) {
			$simple['ACF'][] = self::with_json(
				array(
					'key'   => $a['key'] ?? '',
					'title' => TMG_IO_ACF::item_title( $a ),
				),
				array( 'kind' => 'acf', 'item' => $a )
			);
		}
		foreach ( $simple as $name => $rows ) {
			if ( $rows ) {
				$sheets[ $name ] = TMG_IO_Files::to_table( $rows );
			}
		}
		$opt_rows = array();
		foreach ( (array) $pkg['options'] as $name => $value ) {
			$refs       = array_intersect_key( (array) ( $pkg['options_refs'] ?? array() ), array( $name => 1 ) );
			$opt_rows[] = self::with_json(
				array(
					'option_name' => $name,
					'value'       => is_scalar( $value ) ? self::cut( (string) $value ) : self::cut( (string) wp_json_encode( $value ) ),
				),
				array(
					'kind'  => 'option',
					'name'  => $name,
					'value' => $value,
					'refs'  => $refs,
				) + self::subset_for_refs( $pkg, array( $name => $value ), $refs )
			);
		}
		foreach ( (array) ( $pkg['options_raw'] ?? array() ) as $name => $raw ) {
			$opt_rows[] = self::with_json(
				array(
					'option_name' => $name,
					'value'       => self::cut( (string) $raw ),
				),
				array(
					'kind' => 'option',
					'name' => $name,
					'raw'  => $raw,
				)
			);
		}
		if ( $opt_rows ) {
			$sheets['Options'] = TMG_IO_Files::to_table( $opt_rows );
		}
		if ( ! $about_sheet ) {
			return $sheets;
		}
		$sheets['About'] = TMG_IO_Files::to_table(
			array(
				self::with_json(
					array(
						'format'   => TMG_IO_Files::PACKAGE,
						'site'     => $about['site'],
						'exported' => $about['exported'],
					),
					$about
				),
			)
		);
		return $sheets;
	}

	/** Readable columns of a post. */
	public static function post_row( array $p, array $term_lookup = array() ): array {
		$row = array(
			'ID'             => $p['id'],
			'post_type'      => $p['type'],
			'post_title'     => self::cut( (string) $p['title'] ),
			'post_name'      => $p['slug'],
			'post_status'    => $p['status'],
			'post_date'      => $p['date'],
			'post_author'    => (string) ( $p['author']['login'] ?? '' ),
			'post_parent'    => $p['parent'] ? (string) $p['parent'] : '',
			'menu_order'     => (int) $p['menu_order'],
			'post_excerpt'   => self::cut( (string) $p['excerpt'] ),
			'post_content'   => self::cut( (string) $p['content'] ),
			'comment_status' => (string) ( $p['comment_status'] ?? '' ),
			'featured_image' => '',
		);
		$thumb = $p['meta']['_thumbnail_id'] ?? '';
		if ( $thumb ) {
			$row['featured_image'] = (string) wp_get_attachment_url( (int) $thumb );
		}
		$names_by = array();
		foreach ( $term_lookup as $t ) {
			$names_by[ $t['taxonomy'] . '|' . $t['slug'] ] = $t['name'];
		}
		foreach ( (array) $p['terms'] as $tax => $slugs ) {
			$names = array();
			foreach ( (array) $slugs as $slug ) {
				$names[] = $names_by[ $tax . '|' . $slug ] ?? $slug;
			}
			$row[ 'tax:' . $tax ] = implode( ', ', $names );
		}
		$acf_meta = array();
		foreach ( (array) ( $p['acf'] ?? array() ) as $name => $f ) {
			$field                     = TMG_IO_ACF::field( (string) ( $f['key'] ?? '' ) );
			$field                     = $field ? $field : array( 'type' => $f['type'] ?? 'text' );
			$row[ 'acf:' . $name ]     = self::cut( TMG_IO_Convert::format( $field, $f['value'] ?? null ) );
			$acf_meta[ (string) $name ] = true;
		}
		foreach ( (array) $p['meta'] as $k => $v ) {
			$k = (string) $k;
			// Plain custom fields only: not internal (_), not ACF values or their sub rows.
			if ( '' === $k || '_' === $k[0] || isset( $p['meta'][ '_' . $k ] ) || isset( $acf_meta[ $k ] ) ) {
				continue;
			}
			$row[ 'meta:' . $k ] = self::cut( is_scalar( $v ) || null === $v ? (string) $v : (string) wp_json_encode( $v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		}
		return $row;
	}

	private static function cut( string $s ): string {
		return mb_strlen( $s ) > self::CELL ? mb_substr( $s, 0, self::CELL ) : $s;
	}

	/** Append the JSON column(s). */
	private static function with_json( array $row, array $data ): array {
		if ( '' !== self::$site && ! isset( $data['site'] ) ) {
			$data['site'] = self::$site;
		}
		$json   = (string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR );
		$chunks = array();
		$len    = mb_strlen( $json );
		for ( $i = 0; $i < $len; $i += self::CHUNK ) {
			$chunks[] = mb_substr( $json, $i, self::CHUNK );
		}
		foreach ( $chunks as $n => $chunk ) {
			$row[ 0 === $n ? self::JSON_COL : self::JSON_COL . '_' . ( $n + 1 ) ] = $chunk;
		}
		return $row;
	}

	/** Media and lookup entries one item needs, so each row stands alone. */
	private static function subset( array $pkg, array $item ): array {
		$flat = (array) ( $item['meta'] ?? array() );
		$refs = (array) ( $item['refs'] ?? array() );
		$out  = self::subset_for_refs( $pkg, $flat, $refs );
		if ( ! empty( $item['parent'] ) ) {
			$table = isset( $item['taxonomy'] ) ? 'terms' : 'posts';
			if ( isset( $pkg['lookup'][ $table ][ (string) $item['parent'] ] ) ) {
				$out['lookup'][ $table ][ (string) $item['parent'] ] = $pkg['lookup'][ $table ][ (string) $item['parent'] ];
			}
		}
		if ( ! empty( $item['author']['id'] ) && isset( $pkg['lookup']['users'][ (string) $item['author']['id'] ] ) ) {
			$out['lookup']['users'][ (string) $item['author']['id'] ] = $pkg['lookup']['users'][ (string) $item['author']['id'] ];
		}
		if ( isset( $item['type'] ) && 'attachment' === $item['type'] && isset( $pkg['media'][ (string) $item['id'] ] ) ) {
			$out['media'][ (string) $item['id'] ] = $pkg['media'][ (string) $item['id'] ];
		}
		foreach ( (array) ( $item['terms'] ?? array() ) as $tax => $slugs ) {
			foreach ( (array) ( $pkg['lookup']['terms'] ?? array() ) as $id => $t ) {
				if ( $t['taxonomy'] === $tax && in_array( $t['slug'], (array) $slugs, true ) ) {
					$out['lookup']['terms'][ (string) $id ] = $t;
					$parent                                 = (string) $t['parent'];
					while ( $parent && isset( $pkg['lookup']['terms'][ $parent ] ) && ! isset( $out['lookup']['terms'][ $parent ] ) ) {
						$out['lookup']['terms'][ $parent ] = $pkg['lookup']['terms'][ $parent ];
						$parent                            = (string) $pkg['lookup']['terms'][ $parent ]['parent'];
					}
				}
			}
		}
		foreach ( (array) ( $item['items'] ?? array() ) as $mi ) {
			$table = 'post_type' === ( $mi['type'] ?? '' ) ? 'posts' : ( 'taxonomy' === ( $mi['type'] ?? '' ) ? 'terms' : '' );
			if ( $table && isset( $pkg['lookup'][ $table ][ (string) $mi['object_id'] ] ) ) {
				$out['lookup'][ $table ][ (string) $mi['object_id'] ] = $pkg['lookup'][ $table ][ (string) $mi['object_id'] ];
			}
			$sub = self::subset_for_refs( $pkg, (array) ( $mi['meta'] ?? array() ), (array) ( $mi['refs'] ?? array() ) );
			$out = array_replace_recursive( $out, $sub );
		}
		return $out;
	}

	private static function subset_for_refs( array $pkg, array $flat, array $refs ): array {
		$out   = array(
			'media'  => array(),
			'lookup' => array(
				'posts' => array(),
				'terms' => array(),
				'users' => array(),
			),
		);
		$table = array(
			'post' => 'posts',
			'term' => 'terms',
			'user' => 'users',
		);
		foreach ( $refs as $key => $kind ) {
			foreach ( TMG_IO_Exporter::ids_in( $flat[ $key ] ?? null ) as $id ) {
				if ( 'media' === $kind ) {
					if ( isset( $pkg['media'][ (string) $id ] ) ) {
						$out['media'][ (string) $id ] = $pkg['media'][ (string) $id ];
					}
				} elseif ( isset( $table[ $kind ], $pkg['lookup'][ $table[ $kind ] ][ (string) $id ] ) ) {
					$out['lookup'][ $table[ $kind ] ][ (string) $id ] = $pkg['lookup'][ $table[ $kind ] ][ (string) $id ];
				}
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Back to a package
	 * ------------------------------------------------------------------- */

	/** Does any sheet carry the JSON column? */
	public static function has_json( array $sheets ): bool {
		foreach ( $sheets as $rows ) {
			if ( in_array( self::JSON_COL, array_map( 'strval', (array) ( $rows[0] ?? array() ) ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Rebuild a package from flat sheets. Readable cells that differ from what the item would
	 * export are treated as edits and applied on import (`_edits`).
	 */
	public static function to_package( array $sheets ): array {
		$pkg         = TMG_IO_Files::empty_package();
		$pkg['site'] = ''; // Taken from the rows, never assumed to be this site.
		foreach ( $sheets as $rows ) {
			$header = array_map( 'strval', (array) ( $rows[0] ?? array() ) );
			$jcols  = array();
			foreach ( $header as $i => $h ) {
				if ( self::JSON_COL === $h || preg_match( '/^' . self::JSON_COL . '_(\d+)$/', $h ) ) {
					$n           = self::JSON_COL === $h ? 1 : (int) substr( $h, strlen( self::JSON_COL ) + 1 );
					$jcols[ $n ] = $i;
				}
			}
			if ( ! $jcols ) {
				continue;
			}
			ksort( $jcols );
			foreach ( array_slice( $rows, 1 ) as $row ) {
				$json = '';
				foreach ( $jcols as $i ) {
					$json .= (string) ( $row[ $i ] ?? '' );
				}
				$d = json_decode( $json, true );
				if ( ! is_array( $d ) || empty( $d['kind'] ) ) {
					continue;
				}
				if ( '' === $pkg['site'] && ! empty( $d['site'] ) ) {
					$pkg['site'] = (string) $d['site'];
				}
				$pkg['media'] += (array) ( $d['media'] ?? array() );
				foreach ( array( 'posts', 'terms', 'users' ) as $t ) {
					$pkg['lookup'][ $t ] += (array) ( $d['lookup'][ $t ] ?? array() );
				}
				$cells = array();
				foreach ( $header as $i => $h ) {
					if ( ! in_array( $i, $jcols, true ) ) {
						$cells[ $h ] = TMG_IO_Files::unescape_cell( (string) ( $row[ $i ] ?? '' ) );
					}
				}
				switch ( $d['kind'] ) {
					case 'about':
						$pkg['site']     = (string) ( $d['site'] ?? '' );
						$pkg['exported'] = (string) ( $d['exported'] ?? '' );
						break;
					case 'post':
						$item = (array) $d['item'];
						$edit = self::post_edits( (array) ( $d['h'] ?? array() ), $cells );
						if ( $edit ) {
							$item['_edits'] = $edit;
						}
						$pkg['posts'][] = $item;
						break;
					case 'term':
						$pkg['terms'][] = (array) $d['item'];
						break;
					case 'user':
						$pkg['users'][] = (array) $d['item'];
						break;
					case 'menu':
						$pkg['menus'][] = (array) $d['item'];
						break;
					case 'acf':
						$pkg['acf_structure'][] = (array) $d['item'];
						break;
					case 'option':
						$name = (string) ( $d['name'] ?? '' );
						if ( '' === $name ) {
							break;
						}
						if ( array_key_exists( 'raw', $d ) ) {
							$pkg['options_raw'][ $name ] = (string) $d['raw'];
						} else {
							$pkg['options'][ $name ] = $d['value'] ?? '';
							$pkg['options_refs']    += (array) ( $d['refs'] ?? array() );
						}
						break;
				}
			}
		}
		return $pkg;
	}

	/** Short hash per readable column, to spot cells edited in a spreadsheet. */
	public static function hashes( array $row ): array {
		$out = array();
		foreach ( $row as $col => $v ) {
			$out[ $col ] = substr( md5( self::norm( (string) $v ) ), 0, 10 );
		}
		return $out;
	}

	/** Normalise before comparing: spreadsheets turn "1.50" into "1.5" and change line endings. */
	private static function norm( string $v ): string {
		$v = trim( str_replace( "\r\n", "\n", $v ) );
		return is_numeric( $v ) ? (string) (float) $v : $v;
	}

	/**
	 * Cells that differ from the exported value → edits to apply (column => cell). Columns added
	 * in the spreadsheet count as edits when filled.
	 */
	private static function post_edits( array $hashes, array $cells ): array {
		$edits = array();
		foreach ( $cells as $col => $cell ) {
			if ( in_array( $col, array( 'ID', 'post_type' ), true ) ) {
				continue;
			}
			if ( isset( $hashes[ $col ] ) ) {
				if ( substr( md5( self::norm( $cell ) ), 0, 10 ) !== $hashes[ $col ] ) {
					$edits[ $col ] = $cell;
				}
			} elseif ( '' !== trim( $cell ) ) {
				$edits[ $col ] = $cell;
			}
		}
		return $edits;
	}
}
