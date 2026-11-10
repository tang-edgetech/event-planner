<?php
/**
 * ACF support (free and Pro, optional): edition detection, field lookup, reference detection
 * in flat meta (works inside repeaters, groups, flexible content and clones because ACF stores
 * every sub value as its own meta row with a `_name` → field key reference), field values,
 * options pages, and ACF structure export / import in ACF's own JSON format.
 *
 * Used by: exporter, importer, mapping, admin screens.
 */

defined( 'ABSPATH' ) || exit;

class TMG_IO_ACF {

	/** Our structure keys → ACF internal post types. */
	const STRUCTURE = array(
		'field_groups'  => 'acf-field-group',
		'post_types'    => 'acf-post-type',
		'taxonomies'    => 'acf-taxonomy',
		'options_pages' => 'acf-ui-options-page',
	);

	private static $field_cache = array();

	public static function active(): bool {
		return function_exists( 'acf_get_field' ) && function_exists( 'get_field_objects' );
	}

	public static function is_pro(): bool {
		return self::active() && ( ( function_exists( 'acf_is_pro' ) && acf_is_pro() ) || defined( 'ACF_PRO' ) );
	}

	/** '' (no ACF), 'free' or 'pro'. */
	public static function edition(): string {
		if ( ! self::active() ) {
			return '';
		}
		return self::is_pro() ? 'pro' : 'free';
	}

	/** ACF 6.1+ stores post types, taxonomies and options pages as internal post types. */
	public static function has_internal_types(): bool {
		return function_exists( 'acf_get_internal_post_type_posts' ) && function_exists( 'acf_import_internal_post_type' );
	}

	/**
	 * Structure kinds this site offers, key => label.
	 */
	public static function structure_kinds(): array {
		if ( ! self::active() ) {
			return array();
		}
		$out = array( 'field_groups' => __( 'Field groups', 'tamago-io' ) );
		if ( self::has_internal_types() ) {
			if ( post_type_exists( 'acf-post-type' ) ) {
				$out['post_types'] = __( 'Post types', 'tamago-io' );
			}
			if ( post_type_exists( 'acf-taxonomy' ) ) {
				$out['taxonomies'] = __( 'Taxonomies', 'tamago-io' );
			}
			if ( self::is_pro() && post_type_exists( 'acf-ui-options-page' ) ) {
				$out['options_pages'] = __( 'Options pages', 'tamago-io' );
			}
		}
		return $out;
	}

	/**
	 * A field by key, including clone sub fields stored as "field_clone_field_sub".
	 */
	public static function field( string $key ): ?array {
		if ( ! self::active() || 0 !== strpos( $key, 'field_' ) ) {
			return null;
		}
		if ( array_key_exists( $key, self::$field_cache ) ) {
			return self::$field_cache[ $key ];
		}
		$field = acf_get_field( $key );
		if ( ! $field && false !== strpos( substr( $key, 6 ), '_field_' ) ) {
			$last  = substr( $key, strrpos( $key, '_field_' ) + 1 );
			$field = acf_get_field( $last );
		}
		self::$field_cache[ $key ] = $field ? $field : null;
		return self::$field_cache[ $key ];
	}

	/** media | post | term | user | '' — what kind of ID a field stores. */
	public static function ref_kind( array $field ): string {
		switch ( $field['type'] ?? '' ) {
			case 'image':
			case 'file':
			case 'gallery':
				return 'media';
			case 'post_object':
			case 'relationship':
			case 'page_link':
				return 'post';
			case 'taxonomy':
				return 'term';
			case 'user':
				return 'user';
		}
		return '';
	}

	/**
	 * Which keys of a flat meta / options array hold IDs, found through ACF's `_name` → field key
	 * references. Also covers sub fields of repeaters, groups, flexible content and clones.
	 *
	 * @param array $flat key => value (post / term / user meta, or options).
	 * @return array key => kind
	 */
	public static function annotate( array $flat ): array {
		$refs = array();
		if ( ! self::active() ) {
			return $refs;
		}
		foreach ( $flat as $k => $v ) {
			$k   = (string) $k;
			$ref = $flat[ '_' . $k ] ?? null;
			if ( ! is_string( $ref ) || 0 !== strpos( $ref, 'field_' ) || '' === $v || null === $v || false === $v ) {
				continue;
			}
			$field = self::field( $ref );
			$kind  = $field ? self::ref_kind( $field ) : '';
			if ( $kind ) {
				$refs[ $k ] = $kind;
			}
		}
		return $refs;
	}

	/**
	 * Forget values ACF cached in this request: the importer writes meta directly, so later
	 * get_field() calls in the same request (WP-CLI, hooks) must read them fresh.
	 */
	public static function flush_values(): void {
		self::$field_cache = array();
		if ( function_exists( 'acf_get_store' ) ) {
			foreach ( array( 'values' ) as $name ) {
				$store = acf_get_store( $name );
				if ( $store ) {
					$store->reset();
				}
			}
		}
	}

	/** ACF fields with values on an object, name => [key, type, value (raw)]. */
	public static function values( $acf_id ): array {
		if ( ! self::active() ) {
			return array();
		}
		$objects = get_field_objects( $acf_id, false, true );
		$out     = array();
		foreach ( (array) $objects as $name => $f ) {
			if ( ! is_array( $f ) || empty( $f['key'] ) ) {
				continue;
			}
			$out[ $name ] = array(
				'key'   => $f['key'],
				'type'  => $f['type'],
				'value' => $f['value'] ?? null,
			);
		}
		return $out;
	}

	/** Field groups that apply to a post type: [ [title, fields[]] ... ]. */
	public static function groups_for_post_type( string $post_type ): array {
		if ( ! self::active() ) {
			return array();
		}
		$out = array();
		foreach ( (array) acf_get_field_groups( array( 'post_type' => $post_type ) ) as $g ) {
			$out[] = array(
				'key'    => $g['key'],
				'title'  => $g['title'],
				'fields' => (array) acf_get_fields( $g ),
			);
		}
		return $out;
	}

	/** Top-level fields that apply to a post type, key => field. */
	public static function fields_for_post_type( string $post_type ): array {
		$out = array();
		foreach ( self::groups_for_post_type( $post_type ) as $g ) {
			foreach ( $g['fields'] as $f ) {
				$out[ $f['key'] ] = $f;
			}
		}
		return $out;
	}

	/** ACF options pages, menu_slug => [title, post_id]. Pro only. */
	public static function options_pages(): array {
		if ( ! function_exists( 'acf_get_options_pages' ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) acf_get_options_pages() as $slug => $page ) {
			$pid = $page['post_id'] ?? 'options';
			if ( is_numeric( $pid ) ) {
				continue; // Values stored on a post travel with that post.
			}
			$out[ $slug ] = array(
				'title'   => (string) ( $page['page_title'] ?? $slug ),
				'post_id' => (string) $pid,
			);
		}
		return $out;
	}

	/** Top-level field names shown on an options page. */
	public static function options_page_fields( string $slug ): array {
		if ( ! self::active() ) {
			return array();
		}
		$out = array();
		foreach ( (array) acf_get_field_groups( array( 'options_page' => $slug ) ) as $g ) {
			foreach ( (array) acf_get_fields( $g ) as $f ) {
				$out[ $f['name'] ] = $f;
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Structure in ACF's own export format
	 * ------------------------------------------------------------------- */

	public static function looks_like_acf_export( array $json ): bool {
		$items = isset( $json['key'] ) ? array( $json ) : $json;
		if ( ! $items || ! array_is_list( $items ) ) {
			return false;
		}
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['key'] ) || ! is_string( $item['key'] ) || ! preg_match( '/^(group|post_type|taxonomy|ui_options_page)_/', $item['key'] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Export structure items. $kinds: list of our keys (field_groups, post_types …); empty = all available.
	 * The result is exactly what ACF → Tools → Export produces, so ACF's import accepts it.
	 */
	public static function export_structure( array $kinds = array() ): array {
		if ( ! self::active() ) {
			return array();
		}
		$available = self::structure_kinds();
		$kinds     = $kinds ? array_intersect( $kinds, array_keys( $available ) ) : array_keys( $available );
		$json      = array();
		foreach ( $kinds as $kind ) {
			$pt = self::STRUCTURE[ $kind ];
			if ( self::has_internal_types() ) {
				foreach ( (array) acf_get_internal_post_type_posts( $pt ) as $post ) {
					if ( 'acf-field-group' === $pt ) {
						$post['fields'] = acf_get_fields( $post );
					}
					$json[] = acf_prepare_internal_post_type_for_export( $post, $pt );
				}
			} elseif ( 'acf-field-group' === $pt ) {
				foreach ( (array) acf_get_field_groups() as $group ) {
					$group['fields'] = acf_get_fields( $group );
					$json[]          = acf_prepare_field_group_for_export( $group );
				}
			}
		}
		return $json;
	}

	/** ACF internal post type for an exported item (by its key prefix). */
	public static function item_post_type( array $item ): string {
		$key = (string) ( $item['key'] ?? '' );
		if ( function_exists( 'acf_determine_internal_post_type' ) ) {
			$pt = acf_determine_internal_post_type( $key );
			if ( $pt ) {
				return (string) $pt;
			}
		}
		foreach ( array( 'group_' => 'acf-field-group', 'post_type_' => 'acf-post-type', 'taxonomy_' => 'acf-taxonomy', 'ui_options_page_' => 'acf-ui-options-page' ) as $prefix => $pt ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				return $pt;
			}
		}
		return '';
	}

	/** Existing post ID of a structure item (0 if new). */
	public static function existing_item( array $item, string $pt ): int {
		$key = (string) ( $item['key'] ?? '' );
		if ( function_exists( 'acf_get_internal_post_type_post' ) ) {
			$post = acf_get_internal_post_type_post( $key, $pt );
			return $post ? (int) $post->ID : 0;
		}
		if ( 'acf-field-group' === $pt && function_exists( 'acf_get_field_group_post' ) ) {
			$post = acf_get_field_group_post( $key );
			return $post ? (int) $post->ID : 0;
		}
		return 0;
	}

	/**
	 * Import one structure item the same way ACF's own import tool does.
	 *
	 * @return array{action: string, id: int, message: string}
	 */
	public static function import_item( array $item, string $existing_mode, bool $dry ): array {
		$pt = self::item_post_type( $item );
		if ( ! $pt || ! post_type_exists( $pt ) ) {
			return array( 'action' => 'error', 'id' => 0, 'message' => __( 'Not supported by the ACF version on this site.', 'tamago-io' ) );
		}
		$existing = self::existing_item( $item, $pt );
		if ( $existing && 'skip' === $existing_mode ) {
			return array( 'action' => 'skipped', 'id' => $existing, 'message' => __( 'Already exists.', 'tamago-io' ) );
		}
		if ( $dry ) {
			return array( 'action' => $existing ? 'updated' : 'created', 'id' => $existing, 'message' => '' );
		}
		// Keys are identities in ACF, so "copy" updates as well.
		if ( $existing ) {
			$item['ID'] = $existing;
		} else {
			unset( $item['ID'] );
		}
		if ( function_exists( 'acf_import_internal_post_type' ) ) {
			$item = acf_import_internal_post_type( $item, $pt );
		} else {
			$item = acf_import_field_group( $item );
		}
		self::$field_cache = array();
		return array( 'action' => $existing ? 'updated' : 'created', 'id' => (int) ( $item['ID'] ?? 0 ), 'message' => '' );
	}

	/** Register post types / taxonomies / options pages imported in this request. */
	public static function register_imported(): void {
		if ( ! function_exists( 'acf_get_internal_post_type_instance' ) ) {
			return;
		}
		$calls = array(
			'acf-post-type'       => 'register_post_types',
			'acf-taxonomy'        => 'register_taxonomies',
			'acf-ui-options-page' => 'register_ui_options_pages',
		);
		foreach ( $calls as $pt => $method ) {
			$instance = acf_get_internal_post_type_instance( $pt );
			if ( $instance && method_exists( $instance, $method ) ) {
				$instance->$method();
			}
		}
	}

	/** Human title of a structure item. */
	public static function item_title( array $item ): string {
		return (string) ( $item['title'] ?? $item['page_title'] ?? $item['key'] ?? '' );
	}
}
