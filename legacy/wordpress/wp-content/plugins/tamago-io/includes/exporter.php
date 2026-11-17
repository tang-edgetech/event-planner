<?php
/**
 * Exporter: builds the JSON package (posts of any type with core fields, all meta, ACF values,
 * terms, comments, author; taxonomies with term meta; users; options; menus; ACF structure;
 * media index; lookup tables for remapping IDs) and writes it as JSON, ZIP, CSV or XLSX.
 *
 * Used by: Export tab, presets, WP-CLI `wp tamago-io export`, tests.
 */

defined( 'ABSPATH' ) || exit;

class TMG_IO_Exporter {

	/** Package being built. */
	private $pkg = array();

	/** Choices for this export. */
	private $c = array();

	/** IDs referenced by exported items, kind => [id => true]. */
	private $refs = array(
		'media' => array(),
		'post'  => array(),
		'term'  => array(),
		'user'  => array(),
	);

	public static function defaults(): array {
		return array(
			'types'         => array(),
			'status'        => array(),
			'date_from'     => '',
			'date_to'       => '',
			'author'        => 0,
			'terms'         => '',
			'ids'           => '',
			'limit'         => 0,
			'comments'      => 0,
			'taxonomies'    => array(),
			'users'         => 0,
			'user_roles'    => array(),
			'options'       => '',
			'options_pages' => array(),
			'menus'         => array(),
			'acf_structure' => array(),
			'format'        => 'json',
		);
	}

	/** Clean raw choices (form, preset, CLI). */
	public static function sanitize( array $raw ): array {
		$d   = self::defaults();
		$out = array();
		foreach ( $d as $k => $default ) {
			$v = $raw[ $k ] ?? $default;
			if ( is_array( $default ) ) {
				$v         = is_array( $v ) ? $v : preg_split( '/\s*,\s*/', (string) $v, -1, PREG_SPLIT_NO_EMPTY );
				$out[ $k ] = array_values( array_unique( array_map( fn( $s ) => sanitize_text_field( (string) $s ), $v ) ) );
			} elseif ( is_int( $default ) ) {
				$out[ $k ] = absint( $v );
			} elseif ( 'options' === $k ) {
				$out[ $k ] = sanitize_textarea_field( (string) $v );
			} else {
				$out[ $k ] = sanitize_text_field( (string) $v );
			}
		}
		$out['types']  = array_values( array_filter( $out['types'], 'post_type_exists' ) );
		$out['status'] = array_map( 'sanitize_key', $out['status'] );
		$out['format'] = in_array( $out['format'], array( 'json', 'zip', 'csv', 'xlsx' ), true ) ? $out['format'] : 'json';
		foreach ( array( 'date_from', 'date_to' ) as $k ) {
			$out[ $k ] = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $out[ $k ] ) ? $out[ $k ] : '';
		}
		return $out;
	}

	/** Post types the current user may export: type => label. */
	public static function exportable_types(): array {
		$out = array();
		foreach ( get_post_types( array(), 'objects' ) as $pt ) {
			if ( in_array( $pt->name, array( 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_global_styles', 'acf-field', 'acf-field-group', 'acf-post-type', 'acf-taxonomy', 'acf-ui-options-page' ), true ) ) {
				continue;
			}
			$cap = 'attachment' === $pt->name ? 'upload_files' : $pt->cap->edit_posts;
			if ( current_user_can( $cap ) ) {
				$out[ $pt->name ] = $pt->labels->name . ( $pt->public ? '' : ' (' . __( 'not public', 'tamago-io' ) . ')' );
			}
		}
		return $out;
	}

	/** Build the package. Items the user may not export are left out silently. */
	public static function build( array $choices ): array {
		$self    = new self();
		$self->c = self::sanitize( $choices );
		return $self->run();
	}

	private function run(): array {
		$this->pkg = TMG_IO_Files::empty_package();
		$allowed   = self::exportable_types();
		$types     = array_values( array_intersect( $this->c['types'], array_keys( $allowed ) ) );

		if ( $types ) {
			foreach ( $this->query_posts( $types ) as $post ) {
				$entry = $this->post_entry( $post );
				$entry = apply_filters( 'tmg_io_export_post', $entry, $post, $this->c );
				if ( is_array( $entry ) ) {
					$this->pkg['posts'][] = $entry;
				}
			}
		}
		foreach ( $this->c['taxonomies'] as $tax ) {
			$tx = get_taxonomy( $tax );
			if ( $tx && current_user_can( $tx->cap->manage_terms ) ) {
				$this->export_terms( $tax );
			}
		}
		if ( $this->c['users'] && current_user_can( 'list_users' ) ) {
			$this->export_users();
		}
		if ( TMG_IO_Permissions::can_site_settings() ) {
			$this->export_options();
			if ( $this->c['acf_structure'] ) {
				$this->pkg['acf_structure'] = TMG_IO_ACF::export_structure( $this->c['acf_structure'] );
			}
		}
		if ( $this->c['menus'] && current_user_can( 'edit_theme_options' ) ) {
			$this->export_menus();
		}
		$this->finish_refs();
		return apply_filters( 'tmg_io_package', $this->pkg, $this->c );
	}

	/* ---------------------------------------------------------------------
	 * Posts
	 * ------------------------------------------------------------------- */

	/** @return WP_Post[] */
	private function query_posts( array $types ): array {
		$status = $this->c['status'];
		if ( ! $status ) {
			$status = array_values( array_diff( get_post_stati(), array( 'trash', 'auto-draft' ) ) );
		}
		if ( in_array( 'attachment', $types, true ) && ! in_array( 'inherit', $status, true ) ) {
			$status[] = 'inherit';
		}
		$args = array(
			'post_type'        => $types,
			'post_status'      => $status,
			'posts_per_page'   => $this->c['limit'] ? $this->c['limit'] : -1,
			'orderby'          => array( 'post_type' => 'ASC', 'menu_order' => 'ASC', 'ID' => 'ASC' ),
			'suppress_filters' => true,
			'no_found_rows'    => true,
		);
		if ( $this->c['author'] ) {
			$args['author'] = $this->c['author'];
		}
		$date = array();
		if ( $this->c['date_from'] ) {
			$date['after'] = $this->c['date_from'] . ' 00:00:00';
		}
		if ( $this->c['date_to'] ) {
			$date['before'] = $this->c['date_to'] . ' 23:59:59';
		}
		if ( $date ) {
			$args['date_query'] = array( $date + array( 'inclusive' => true ) );
		}
		$ids = wp_parse_id_list( $this->c['ids'] );
		if ( $ids ) {
			$args['post__in'] = $ids;
		}
		// Terms filter: "taxonomy:slug, taxonomy:slug" (any of them).
		$tq = array();
		foreach ( preg_split( '/\s*,\s*/', $this->c['terms'], -1, PREG_SPLIT_NO_EMPTY ) as $pair ) {
			if ( false !== strpos( $pair, ':' ) ) {
				list( $tax, $slug ) = array_map( 'trim', explode( ':', $pair, 2 ) );
				if ( taxonomy_exists( $tax ) ) {
					$tq[ $tax ][] = sanitize_title( $slug );
				}
			}
		}
		if ( $tq ) {
			$args['tax_query'] = array( 'relation' => 'OR' ); // phpcs:ignore WordPress.DB.SlowDBQuery
			foreach ( $tq as $tax => $slugs ) {
				$args['tax_query'][] = array(
					'taxonomy' => $tax,
					'field'    => 'slug',
					'terms'    => $slugs,
				);
			}
		}
		$q = new WP_Query( $args );
		return array_filter( $q->posts, fn( $p ) => $p instanceof WP_Post && ( 'attachment' === $p->post_type ? current_user_can( 'upload_files' ) : current_user_can( 'read_post', $p->ID ) ) );
	}

	public function post_entry( WP_Post $p ): array {
		list( $meta, $multi, $raw ) = self::read_meta( 'post', $p->ID );
		$refs                       = TMG_IO_ACF::annotate( $meta );
		if ( isset( $meta['_thumbnail_id'] ) ) {
			$refs['_thumbnail_id'] = 'media';
		}
		$refs   = (array) apply_filters( 'tmg_io_meta_refs', $refs, $meta, 'post', $p );
		$author = get_userdata( (int) $p->post_author );
		$entry  = array(
			'id'             => $p->ID,
			'type'           => $p->post_type,
			'title'          => $p->post_title,
			'slug'           => $p->post_name,
			'status'         => $p->post_status,
			'date'           => $p->post_date,
			'date_gmt'       => $p->post_date_gmt,
			'modified'       => $p->post_modified,
			'content'        => $p->post_content,
			'excerpt'        => $p->post_excerpt,
			'parent'         => (int) $p->post_parent,
			'menu_order'     => (int) $p->menu_order,
			'guid'           => $p->guid,
			'comment_status' => $p->comment_status,
			'ping_status'    => $p->ping_status,
			'password'       => $p->post_password,
			'mime_type'      => $p->post_mime_type,
			'permalink'      => 'attachment' === $p->post_type ? (string) wp_get_attachment_url( $p->ID ) : (string) get_permalink( $p ),
			'author'         => $author ? array(
				'id'    => (int) $author->ID,
				'login' => $author->user_login,
				'email' => $author->user_email,
			) : null,
			'meta'           => $meta,
			'refs'           => $refs,
			'acf'            => TMG_IO_ACF::values( $p->ID ),
			'terms'          => array(),
		);
		if ( $multi ) {
			$entry['meta_multi'] = $multi;
		}
		if ( $raw ) {
			$entry['meta_raw'] = $raw;
		}
		foreach ( get_object_taxonomies( $p->post_type ) as $tax ) {
			$terms = wp_get_object_terms( $p->ID, $tax );
			if ( $terms && ! is_wp_error( $terms ) ) {
				foreach ( $terms as $t ) {
					$entry['terms'][ $tax ][] = $t->slug;
					$this->refs['term'][ $t->term_id ] = true;
				}
			}
		}
		if ( 'attachment' === $p->post_type ) {
			$this->refs['media'][ $p->ID ] = true;
		}
		if ( $p->post_parent ) {
			$this->refs['post'][ $p->post_parent ] = true;
		}
		$this->collect( $meta, $refs );
		if ( $this->c['comments'] ) {
			$entry['comments'] = $this->comments( $p->ID );
		}
		return $entry;
	}

	private function comments( int $post_id ): array {
		$out = array();
		foreach ( get_comments( array( 'post_id' => $post_id, 'status' => 'all', 'orderby' => 'comment_ID', 'order' => 'ASC' ) ) as $c ) {
			list( $meta, , ) = self::read_meta( 'comment', (int) $c->comment_ID );
			$user            = $c->user_id ? get_userdata( (int) $c->user_id ) : null;
			$out[]           = array(
				'id'       => (int) $c->comment_ID,
				'parent'   => (int) $c->comment_parent,
				'author'   => $c->comment_author,
				'email'    => $c->comment_author_email,
				'url'      => $c->comment_author_url,
				'date'     => $c->comment_date,
				'date_gmt' => $c->comment_date_gmt,
				'content'  => $c->comment_content,
				'approved' => $c->comment_approved,
				'type'     => $c->comment_type,
				'agent'    => $c->comment_agent,
				'user'     => $user ? $user->user_login : '',
				'meta'     => $meta,
				'refs'     => TMG_IO_ACF::annotate( $meta ),
			);
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Terms, users, options, menus
	 * ------------------------------------------------------------------- */

	private function export_terms( string $tax ): void {
		$terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false ) );
		if ( is_wp_error( $terms ) ) {
			return;
		}
		// Parents first so the importer can link them in one pass.
		$by_id = array();
		foreach ( $terms as $t ) {
			$by_id[ $t->term_id ] = $t;
		}
		$depth = function ( $t ) use ( $by_id ) {
			$d    = 0;
			$seen = array();
			while ( $t->parent && isset( $by_id[ $t->parent ] ) && ! isset( $seen[ $t->parent ] ) && $d < 50 ) {
				$seen[ $t->parent ] = true;
				$t                  = $by_id[ $t->parent ];
				++$d;
			}
			return $d;
		};
		usort( $terms, fn( $a, $b ) => array( $depth( $a ), $a->term_id ) <=> array( $depth( $b ), $b->term_id ) );
		foreach ( $terms as $t ) {
			list( $meta, $multi, $raw ) = self::read_meta( 'term', $t->term_id );
			$refs                       = (array) apply_filters( 'tmg_io_meta_refs', TMG_IO_ACF::annotate( $meta ), $meta, 'term', $t );
			$entry                      = array(
				'id'          => $t->term_id,
				'taxonomy'    => $t->taxonomy,
				'name'        => $t->name,
				'slug'        => $t->slug,
				'description' => $t->description,
				'parent'      => (int) $t->parent,
				'meta'        => $meta,
				'refs'        => $refs,
				'acf'         => TMG_IO_ACF::values( 'term_' . $t->term_id ),
			);
			if ( $multi ) {
				$entry['meta_multi'] = $multi;
			}
			if ( $raw ) {
				$entry['meta_raw'] = $raw;
			}
			$this->pkg['terms'][]             = $entry;
			$this->refs['term'][ $t->term_id ] = true;
			$this->collect( $meta, $refs );
		}
	}

	private function export_users(): void {
		$args = array( 'orderby' => 'ID' );
		if ( $this->c['user_roles'] ) {
			$args['role__in'] = $this->c['user_roles'];
		}
		foreach ( get_users( $args ) as $u ) {
			list( $meta, $multi, $raw ) = self::read_meta( 'user', $u->ID );
			foreach ( array_keys( $meta ) as $k ) {
				if ( self::is_private_user_meta( (string) $k ) ) {
					unset( $meta[ $k ] );
				}
			}
			$multi = array_diff_key( $multi, array_flip( array_filter( array_keys( $multi ), fn( $k ) => self::is_private_user_meta( (string) $k ) ) ) );
			$raw   = array_diff_key( $raw, array_flip( array_filter( array_keys( $raw ), fn( $k ) => self::is_private_user_meta( (string) $k ) ) ) );
			$refs  = (array) apply_filters( 'tmg_io_meta_refs', TMG_IO_ACF::annotate( $meta ), $meta, 'user', $u );
			$entry = array(
				'id'           => $u->ID,
				'login'        => $u->user_login,
				'email'        => $u->user_email,
				'display_name' => $u->display_name,
				'nicename'     => $u->user_nicename,
				'url'          => $u->user_url,
				'registered'   => $u->user_registered,
				'first_name'   => $u->first_name,
				'last_name'    => $u->last_name,
				'nickname'     => $u->nickname,
				'description'  => $u->description,
				'roles'        => array_values( $u->roles ),
				'meta'         => $meta,
				'refs'         => $refs,
				'acf'          => TMG_IO_ACF::values( 'user_' . $u->ID ),
			);
			if ( $multi ) {
				$entry['meta_multi'] = $multi;
			}
			if ( $raw ) {
				$entry['meta_raw'] = $raw;
			}
			$this->pkg['users'][]           = $entry;
			$this->refs['user'][ $u->ID ] = true;
			$this->collect( $meta, $refs );
		}
	}

	/** User meta that never leaves the site: sessions, capabilities internals, secrets, UI state. */
	public static function is_private_user_meta( string $key ): bool {
		global $wpdb;
		$exact = array( 'session_tokens', '_application_passwords', 'default_password_nag', 'first_name', 'last_name', 'nickname', 'description', 'community-events-location', 'wp_persisted_preferences' );
		if ( in_array( $key, $exact, true ) ) {
			return true;
		}
		if ( preg_match( '/(capabilities|user_level|user-settings|user-settings-time|dashboard_quick_press_last_post_id|persisted_preferences)$/', $key ) || 0 === strpos( $key, $wpdb->base_prefix ) && preg_match( '/_(capabilities|user_level)$/', $key ) ) {
			return true;
		}
		if ( preg_match( '/(password|passwd|secret|token|api_key|apikey|activation_key)/i', $key ) ) {
			return true;
		}
		return in_array( $key, (array) apply_filters( 'tmg_io_skip_meta_keys', array(), 'user' ), true );
	}

	/** Option names that are never exported or imported. */
	public static function protected_options(): array {
		$list = array( 'siteurl', 'home', 'active_plugins', 'active_sitewide_plugins', 'template', 'stylesheet', 'db_version', 'initial_db_version', 'cron', 'rewrite_rules', 'recently_activated', 'uninstall_plugins', 'auth_key', 'auth_salt', 'logged_in_key', 'logged_in_salt', 'nonce_key', 'nonce_salt', 'secure_auth_key', 'secure_auth_salt', 'admin_email', 'new_admin_email', 'users_can_register', 'default_role', 'upload_path', 'upload_url_path', 'tmg_io_db_version', 'fresh_site', 'can_compress_scripts', 'db_upgraded', 'finished_splitting_shared_terms', 'WPLANG', 'auto_core_update_notified', 'recovery_keys', 'recovery_mode_email_last_sent', 'acf_pro_license', 'acf_pro_license_status', 'acf_site_health' );
		global $wpdb;
		$list[] = $wpdb->prefix . 'user_roles';
		$list   = array_merge( $list, TMG_IO_Settings::lines( 'protected_options' ) );
		return array_values( array_unique( (array) apply_filters( 'tmg_io_protected_options', $list ) ) );
	}

	public static function is_protected_option( string $name ): bool {
		if ( preg_match( '/^_(site_)?transient_/', $name ) ) {
			return true;
		}
		foreach ( self::protected_options() as $p ) {
			if ( '*' === substr( $p, -1 ) ? 0 === strpos( $name, substr( $p, 0, -1 ) ) : $p === $name ) {
				return true;
			}
		}
		return false;
	}

	/** Option name patterns from the text box: exact names, or prefixes ending with * (or _). */
	public static function option_patterns( string $text ): array {
		$out = array();
		foreach ( preg_split( '/[\r\n,]+/', $text, -1, PREG_SPLIT_NO_EMPTY ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return $out;
	}

	private function export_options(): void {
		global $wpdb;
		$patterns = self::option_patterns( $this->c['options'] );
		$pages    = TMG_IO_ACF::options_pages();
		foreach ( $this->c['options_pages'] as $slug ) {
			if ( ! isset( $pages[ $slug ] ) ) {
				continue;
			}
			$pid = $pages[ $slug ]['post_id'];
			foreach ( array_keys( TMG_IO_ACF::options_page_fields( $slug ) ) as $name ) {
				$patterns[] = $pid . '_' . $name . '*';
				$patterns[] = '_' . $pid . '_' . $name . '*';
			}
		}
		if ( ! $patterns ) {
			return;
		}
		$where = array();
		$vals  = array();
		foreach ( array_unique( $patterns ) as $p ) {
			if ( '*' === substr( $p, -1 ) ) {
				$where[] = 'option_name LIKE %s';
				$vals[]  = $wpdb->esc_like( substr( $p, 0, -1 ) ) . '%';
			} elseif ( '_' === substr( $p, -1 ) ) {
				// "options_" style entries are prefixes too (as in the examples on screen).
				$where[] = 'option_name LIKE %s';
				$vals[]  = $wpdb->esc_like( $p ) . '%';
			} else {
				$where[] = 'option_name = %s';
				$vals[]  = $p;
			}
		}
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE " . implode( ' OR ', $where ) . ' ORDER BY option_name', $vals ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$flat = array();
		foreach ( $rows as $r ) {
			$name = (string) $r['option_name'];
			if ( self::is_protected_option( $name ) ) {
				continue;
			}
			list( $value, $ok ) = self::safe_unserialize( (string) $r['option_value'] );
			if ( $ok ) {
				$flat[ $name ] = $value;
			} else {
				$this->pkg['options_raw'][ $name ] = (string) $r['option_value'];
			}
		}
		$this->pkg['options']      = $flat;
		$this->pkg['options_refs'] = (array) apply_filters( 'tmg_io_meta_refs', TMG_IO_ACF::annotate( $flat ), $flat, 'option', null );
		$this->collect( $flat, $this->pkg['options_refs'] );
	}

	private function export_menus(): void {
		$locations = get_nav_menu_locations();
		$want      = $this->c['menus'];
		foreach ( wp_get_nav_menus() as $menu ) {
			if ( ! in_array( 'all', $want, true ) && ! in_array( (string) $menu->term_id, $want, true ) && ! in_array( $menu->slug, $want, true ) ) {
				continue;
			}
			$items = array();
			foreach ( (array) wp_get_nav_menu_items( $menu->term_id, array( 'post_status' => 'any' ) ) as $it ) {
				list( $meta, , ) = self::read_meta( 'post', (int) $it->ID );
				$meta            = array_filter( $meta, fn( $k ) => 0 !== strpos( (string) $k, '_menu_item_' ), ARRAY_FILTER_USE_KEY );
				$refs            = TMG_IO_ACF::annotate( $meta );
				$items[]         = array(
					'id'          => (int) $it->ID,
					'title'       => $it->post_title,
					'type'        => $it->type,
					'object'      => $it->object,
					'object_id'   => (int) $it->object_id,
					'url'         => $it->url,
					'parent'      => (int) $it->menu_item_parent,
					'order'       => (int) $it->menu_order,
					'target'      => $it->target,
					'classes'     => array_values( array_filter( (array) $it->classes ) ),
					'xfn'         => $it->xfn,
					'attr_title'  => $it->attr_title,
					'description' => $it->post_content,
					'status'      => $it->post_status,
					'meta'        => $meta,
					'refs'        => $refs,
				);
				if ( 'post_type' === $it->type ) {
					$this->refs['post'][ (int) $it->object_id ] = true;
				} elseif ( 'taxonomy' === $it->type ) {
					$this->refs['term'][ (int) $it->object_id ] = true;
				}
				$this->collect( $meta, $refs );
			}
			$this->pkg['menus'][] = array(
				'id'          => $menu->term_id,
				'name'        => $menu->name,
				'slug'        => $menu->slug,
				'description' => $menu->description,
				'locations'   => array_keys( array_filter( $locations, fn( $id ) => (int) $id === (int) $menu->term_id ) ),
				'items'       => $items,
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * References, lookup tables, media index
	 * ------------------------------------------------------------------- */

	/** Remember IDs referenced by annotated keys. */
	private function collect( array $flat, array $refs ): void {
		foreach ( $refs as $key => $kind ) {
			if ( ! isset( $this->refs[ $kind ] ) || ! array_key_exists( $key, $flat ) ) {
				continue;
			}
			foreach ( self::ids_in( $flat[ $key ] ) as $id ) {
				$this->refs[ $kind ][ $id ] = true;
			}
		}
	}

	/** Positive integer IDs inside a scalar, list or comma-separated string. */
	public static function ids_in( $v ): array {
		$out = array();
		if ( is_array( $v ) ) {
			foreach ( $v as $x ) {
				if ( is_numeric( $x ) && (int) $x > 0 ) {
					$out[] = (int) $x;
				}
			}
		} elseif ( is_numeric( $v ) && (int) $v > 0 ) {
			$out[] = (int) $v;
		} elseif ( is_string( $v ) && preg_match( '/^\d+(,\d+)+$/', $v ) ) {
			$out = array_map( 'intval', explode( ',', $v ) );
		}
		return $out;
	}

	private function finish_refs(): void {
		foreach ( array_keys( $this->refs['media'] ) as $id ) {
			$info = TMG_IO_Media::describe( (int) $id );
			if ( $info ) {
				$this->pkg['media'][ (string) $id ] = $info;
			}
		}
		foreach ( array_keys( $this->refs['post'] ) as $id ) {
			$p = get_post( (int) $id );
			if ( $p ) {
				$this->pkg['lookup']['posts'][ (string) $id ] = array(
					'type'  => $p->post_type,
					'slug'  => $p->post_name,
					'title' => $p->post_title,
					'guid'  => $p->guid,
				);
				if ( 'attachment' === $p->post_type && ! isset( $this->pkg['media'][ (string) $id ] ) ) {
					$info = TMG_IO_Media::describe( (int) $id );
					if ( $info ) {
						$this->pkg['media'][ (string) $id ] = $info;
					}
				}
			}
		}
		foreach ( array_keys( $this->refs['term'] ) as $id ) {
			$t = get_term( (int) $id );
			if ( $t && ! is_wp_error( $t ) ) {
				$this->pkg['lookup']['terms'][ (string) $id ] = array(
					'taxonomy' => $t->taxonomy,
					'slug'     => $t->slug,
					'name'     => $t->name,
					'parent'   => (int) $t->parent,
				);
				// Parents of referenced terms, so hierarchies can be rebuilt.
				$parent = (int) $t->parent;
				$guard  = 0;
				while ( $parent && ! isset( $this->pkg['lookup']['terms'][ (string) $parent ] ) && $guard++ < 20 ) {
					$pt = get_term( $parent );
					if ( ! $pt || is_wp_error( $pt ) ) {
						break;
					}
					$this->pkg['lookup']['terms'][ (string) $parent ] = array(
						'taxonomy' => $pt->taxonomy,
						'slug'     => $pt->slug,
						'name'     => $pt->name,
						'parent'   => (int) $pt->parent,
					);
					$parent = (int) $pt->parent;
				}
			}
		}
		foreach ( array_keys( $this->refs['user'] ) as $id ) {
			$u = get_userdata( (int) $id );
			if ( $u ) {
				$this->pkg['lookup']['users'][ (string) $id ] = array(
					'login' => $u->user_login,
					'email' => $u->user_email,
				);
			}
		}
		// Post authors.
		foreach ( $this->pkg['posts'] as $p ) {
			if ( ! empty( $p['author']['id'] ) ) {
				$this->pkg['lookup']['users'][ (string) $p['author']['id'] ] = array(
					'login' => $p['author']['login'],
					'email' => $p['author']['email'],
				);
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Raw meta, read safely
	 * ------------------------------------------------------------------- */

	/** Meta keys never exported or imported for an object type. */
	public static function skip_meta_keys( string $type ): array {
		$keys = array();
		if ( 'post' === $type ) {
			$keys = array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_encloseme', '_pingme', '_wp_trash_meta_status', '_wp_trash_meta_time', '_wp_trash_meta_comments_status', '_wp_desired_post_slug', '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes', '_tmg_io_src', '_tmg_io_sha1', '_oembed_*', '_wp_old_*' );
		}
		$keys = array_merge( $keys, TMG_IO_Settings::lines( 'skip_meta_keys' ) );
		return array_values( array_unique( (array) apply_filters( 'tmg_io_skip_meta_keys', $keys, $type ) ) );
	}

	public static function skip_meta( string $key, array $skip ): bool {
		foreach ( $skip as $s ) {
			if ( '*' === substr( (string) $s, -1 ) ? 0 === strpos( $key, substr( (string) $s, 0, -1 ) ) : $s === $key ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * All meta of an object straight from the database, unserialized without creating objects.
	 *
	 * @return array{0: array, 1: array, 2: array} single values, multi-value keys, raw (unsafe) values
	 */
	public static function read_meta( string $type, int $id ): array {
		global $wpdb;
		$table = _get_meta_table( $type );
		if ( ! $table ) {
			return array( array(), array(), array() );
		}
		$col  = 'user' === $type ? 'user_id' : $type . '_id';
		$id_c = 'user' === $type ? 'umeta_id' : 'meta_id';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$table} WHERE {$col} = %d ORDER BY {$id_c}", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$skip = self::skip_meta_keys( $type );
		$all  = array();
		foreach ( $rows as $r ) {
			$k = (string) $r['meta_key'];
			if ( self::skip_meta( $k, $skip ) ) {
				continue;
			}
			$all[ $k ][] = (string) $r['meta_value'];
		}
		$single = array();
		$multi  = array();
		$raw    = array();
		foreach ( $all as $k => $values ) {
			$decoded = array();
			$safe    = true;
			foreach ( $values as $v ) {
				list( $d, $ok ) = self::safe_unserialize( $v );
				$safe           = $safe && $ok;
				$decoded[]      = $d;
			}
			if ( ! $safe ) {
				$raw[ $k ] = $values;
			} elseif ( count( $decoded ) > 1 ) {
				$multi[ $k ] = $decoded;
			} else {
				$single[ $k ] = $decoded[0];
			}
		}
		return array( $single, $multi, $raw );
	}

	/**
	 * Unserialize without instantiating classes. Values holding objects are reported as unsafe
	 * (they are exported raw and written back byte for byte).
	 *
	 * @return array{0: mixed, 1: bool}
	 */
	public static function safe_unserialize( string $v ): array {
		if ( ! is_serialized( $v ) ) {
			return array( $v, true );
		}
		$d = @unserialize( trim( $v ), array( 'allowed_classes' => false ) ); // phpcs:ignore
		if ( false === $d && 'b:0;' !== trim( $v ) ) {
			return array( $v, false );
		}
		$has_object = false;
		$walk       = function ( $x ) use ( &$walk, &$has_object ) {
			if ( is_object( $x ) ) {
				$has_object = true;
			} elseif ( is_array( $x ) ) {
				foreach ( $x as $y ) {
					$walk( $y );
				}
			}
		};
		$walk( $d );
		return $has_object ? array( $v, false ) : array( $d, true );
	}

	/* ---------------------------------------------------------------------
	 * Writing
	 * ------------------------------------------------------------------- */

	/**
	 * Write a package in a format.
	 *
	 * @return array{path: string, ext: string, mime: string, format: string}
	 */
	public static function write( array $pkg, string $format ): array {
		if ( 'zip' === $format && ! class_exists( 'ZipArchive' ) ) {
			$format = 'json';
		}
		if ( 'xlsx' === $format && ! TMG_IO_Xlsx::available() ) {
			$format = 'csv';
		}
		if ( 'json' === $format ) {
			$path = TMG_IO_Storage::new_path( 'json', 'export' );
			file_put_contents( $path, wp_json_encode( $pkg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return array( 'path' => $path, 'ext' => 'json', 'mime' => 'application/json', 'format' => 'json' );
		}
		if ( 'zip' === $format ) {
			return array( 'path' => self::write_zip( $pkg ), 'ext' => 'zip', 'mime' => 'application/zip', 'format' => 'zip' );
		}
		$out           = TMG_IO_Files::write_sheets( TMG_IO_Flatten::sheets( $pkg, 'csv' !== $format ), $format );
		$out['format'] = $format;
		return $out;
	}

	/** ZIP: package.json plus media/<id>/<file> for every attachment whose file is on disk. */
	public static function write_zip( array $pkg ): string {
		$path = TMG_IO_Storage::new_path( 'zip', 'export' );
		$zip  = new ZipArchive();
		if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			throw new RuntimeException( 'Could not create the ZIP file.' );
		}
		foreach ( $pkg['media'] as $id => $info ) {
			$file = get_attached_file( (int) $id );
			if ( $file && is_readable( $file ) ) {
				$name                               = sanitize_file_name( wp_basename( $file ) );
				$entry                              = 'media/' . (int) $id . '/' . $name;
				$pkg['media'][ $id ]['file'] = $entry;
				$zip->addFile( $file, $entry );
			}
		}
		$zip->addFromString( 'package.json', (string) wp_json_encode( $pkg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR ) );
		$zip->close();
		return $path;
	}

	/** Suggested download name. */
	public static function filename( string $ext, string $label = 'export' ): string {
		$site = sanitize_title( (string) wp_parse_url( home_url(), PHP_URL_HOST ) . (string) wp_parse_url( home_url(), PHP_URL_PATH ) );
		return sanitize_file_name( $site . '-' . $label . '-' . wp_date( 'Ymd-His' ) . '.' . $ext );
	}

	/** Item counts of a package, for history and notices. */
	public static function counts( array $pkg ): array {
		return array(
			'posts'   => count( (array) ( $pkg['posts'] ?? array() ) ),
			'terms'   => count( (array) ( $pkg['terms'] ?? array() ) ),
			'users'   => count( (array) ( $pkg['users'] ?? array() ) ),
			'options' => count( (array) ( $pkg['options'] ?? array() ) ) + count( (array) ( $pkg['options_raw'] ?? array() ) ),
			'menus'   => count( (array) ( $pkg['menus'] ?? array() ) ),
			'acf'     => count( (array) ( $pkg['acf_structure'] ?? array() ) ),
			'media'   => count( (array) ( $pkg['media'] ?? array() ) ),
		);
	}

	/**
	 * Export and log in History. Returns the written file.
	 *
	 * @return array{path: string, ext: string, mime: string, format: string, filename: string, run: int}
	 */
	public static function export_logged( array $choices, string $label = '' ): array {
		$choices = self::sanitize( $choices );
		$pkg     = self::build( $choices );
		$file    = self::write( $pkg, $choices['format'] );
		$name    = self::filename( $file['ext'] );
		$run     = TMG_IO_Runs::create(
			array(
				'kind'    => 'export',
				'status'  => 'done',
				'source'  => $label ? $label : $name,
				'format'  => $file['format'],
				'summary' => self::counts( $pkg ),
				'options' => array( 'choices' => $choices ),
			)
		);
		TMG_IO_Runs::update( $run, array( 'finished_at' => current_time( 'mysql', true ) ) );
		return $file + array(
			'filename' => $name,
			'run'      => $run,
		);
	}
}
