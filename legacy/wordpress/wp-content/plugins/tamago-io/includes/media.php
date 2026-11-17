<?php
/**
 * Media import: from a ZIP package (entries read by name, never extracted as a whole) or by
 * re-downloading the original URL (http/https only, wp_safe_remote_get, size and type limits).
 * De-duplicates by original URL (`_tmg_io_src`), file hash (`_tmg_io_sha1`) and local URL.
 *
 * Used by: importer (media phase), conversions (image / file / gallery by URL), mapping (featured image).
 */

defined( 'ABSPATH' ) || exit;

class TMG_IO_Media {

	/** Export description of one attachment. */
	public static function describe( int $id, bool $hash = true ): ?array {
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return null;
		}
		$url  = (string) wp_get_attachment_url( $id );
		$file = (string) get_attached_file( $id );
		$sha1 = (string) get_post_meta( $id, '_tmg_io_sha1', true );
		if ( $hash && '' === $sha1 && $file && is_readable( $file ) && filesize( $file ) < 200 * MB_IN_BYTES ) {
			$sha1 = (string) sha1_file( $file );
		}
		return array(
			'id'          => $id,
			'url'         => $url,
			'filename'    => wp_basename( $file ? $file : (string) wp_parse_url( $url, PHP_URL_PATH ) ),
			'title'       => $post->post_title,
			'alt'         => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'caption'     => $post->post_excerpt,
			'description' => $post->post_content,
			'mime'        => $post->post_mime_type,
			'date'        => $post->post_date,
			'parent'      => (int) $post->post_parent,
			'sha1'        => $sha1,
			'src'         => (string) get_post_meta( $id, '_tmg_io_src', true ),
		);
	}

	/** An attachment on this site that is the same file (0 when none). */
	public static function find_existing( array $info ): int {
		global $wpdb;
		$urls = array_filter( array( (string) ( $info['src'] ?? '' ), (string) ( $info['url'] ?? '' ) ) );
		foreach ( $urls as $url ) {
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT pm.post_id FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_tmg_io_src' AND pm.meta_value = %s AND p.post_type = 'attachment' LIMIT 1", $url ) );
			if ( $id ) {
				return $id;
			}
		}
		if ( ! empty( $info['sha1'] ) ) {
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT pm.post_id FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_tmg_io_sha1' AND pm.meta_value = %s AND p.post_type = 'attachment' LIMIT 1", (string) $info['sha1'] ) );
			if ( $id ) {
				return $id;
			}
		}
		foreach ( $urls as $url ) {
			$id = attachment_url_to_postid( $url );
			if ( $id ) {
				return (int) $id;
			}
		}
		return 0;
	}

	/**
	 * Import one attachment described by the package: from the ZIP when it carries the file,
	 * otherwise from its URL when downloads are allowed.
	 *
	 * @return int|WP_Error
	 */
	public static function import( array $info, string $zip, bool $download ) {
		$tmp  = '';
		$name = sanitize_file_name( (string) ( $info['filename'] ?? '' ) );
		if ( '' !== $zip && ! empty( $info['file'] ) ) {
			$tmp = self::from_zip( $zip, (string) $info['file'] );
			if ( is_wp_error( $tmp ) ) {
				return $tmp;
			}
		}
		if ( '' === $tmp ) {
			if ( ! $download ) {
				return new WP_Error( 'tmg_io_no_download', __( 'Not in the file and media download is off.', 'tamago-io' ) );
			}
			$tmp = self::download( (string) ( $info['url'] ?? '' ) );
			if ( is_wp_error( $tmp ) ) {
				return $tmp;
			}
		}
		if ( '' === $name ) {
			$name = sanitize_file_name( wp_basename( (string) wp_parse_url( (string) ( $info['url'] ?? '' ), PHP_URL_PATH ) ) ) ?: 'file';
		}
		$sha1 = (string) sha1_file( $tmp );
		$same = self::find_existing( array( 'sha1' => $sha1 ) );
		if ( $same ) {
			wp_delete_file( $tmp );
			return $same;
		}
		return self::sideload( $tmp, $name, $info, $sha1 );
	}

	/**
	 * Download a URL into the Media Library (de-duplicated by URL).
	 *
	 * @return int|WP_Error
	 */
	public static function from_url( string $url, array $info = array() ) {
		$existing = self::find_existing( array( 'url' => $url ) );
		if ( $existing ) {
			return $existing;
		}
		$info['url'] = $url;
		return self::import( $info, '', true );
	}

	/**
	 * Copy one ZIP entry (media/<id>/<name>) to a temporary file.
	 *
	 * @return string|WP_Error
	 */
	public static function from_zip( string $zip_path, string $entry ) {
		if ( ! class_exists( 'ZipArchive' ) || ! TMG_IO_Files::safe_zip_entry( $entry ) || ! preg_match( '#^media/[0-9]+/[^/]+$#', $entry ) ) {
			return new WP_Error( 'tmg_io_zip_path', __( 'Unsafe or invalid media path in the ZIP.', 'tamago-io' ) );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new WP_Error( 'tmg_io_zip', __( 'The ZIP file could not be opened.', 'tamago-io' ) );
		}
		$stat = $zip->statName( $entry );
		$max  = (int) TMG_IO_Settings::get( 'media_max_mb' ) * MB_IN_BYTES;
		if ( ! $stat ) {
			$zip->close();
			return new WP_Error( 'tmg_io_zip_missing', __( 'Media file missing from the ZIP.', 'tamago-io' ) );
		}
		if ( $stat['size'] > $max ) {
			$zip->close();
			return new WP_Error( 'tmg_io_too_big', __( 'Media file is larger than the size limit.', 'tamago-io' ) );
		}
		$in  = $zip->getStream( $entry );
		$tmp = TMG_IO_Storage::new_path( 'tmp', 'media' );
		$out = fopen( $tmp, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $in || ! $out ) {
			$zip->close();
			return new WP_Error( 'tmg_io_zip_read', __( 'Media file could not be read from the ZIP.', 'tamago-io' ) );
		}
		$copied = 0;
		while ( ! feof( $in ) ) {
			$chunk   = (string) fread( $in, 65536 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$copied += strlen( $chunk );
			if ( $copied > $max ) {
				break;
			}
			fwrite( $out, $chunk ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$zip->close();
		if ( $copied > $max ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'tmg_io_too_big', __( 'Media file is larger than the size limit.', 'tamago-io' ) );
		}
		return $tmp;
	}

	/**
	 * Download a URL to a temporary file: http/https only, safe request (no private hosts other
	 * than this site), size limit.
	 *
	 * @return string|WP_Error
	 */
	public static function download( string $url ) {
		$url = esc_url_raw( trim( $url ), array( 'http', 'https' ) );
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return new WP_Error( 'tmg_io_url', __( 'Invalid or unsafe media URL.', 'tamago-io' ) );
		}
		if ( ! TMG_IO_Settings::get( 'remote_media' ) ) {
			return new WP_Error( 'tmg_io_remote_off', __( 'Media downloads are switched off in the settings.', 'tamago-io' ) );
		}
		$max = (int) TMG_IO_Settings::get( 'media_max_mb' ) * MB_IN_BYTES;
		$tmp = TMG_IO_Storage::new_path( 'tmp', 'media' );
		$res = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 60,
				'stream'              => true,
				'filename'            => $tmp,
				'limit_response_size' => $max + 1,
				'redirection'         => 3,
			)
		);
		if ( is_wp_error( $res ) ) {
			wp_delete_file( $tmp );
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code ) {
			wp_delete_file( $tmp );
			/* translators: %d: HTTP status */
			return new WP_Error( 'tmg_io_http', sprintf( __( 'Download failed (HTTP %d).', 'tamago-io' ), $code ) );
		}
		clearstatcache( true, $tmp );
		if ( ! file_exists( $tmp ) || filesize( $tmp ) > $max || 0 === filesize( $tmp ) ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'tmg_io_too_big', __( 'Downloaded file is empty or larger than the size limit.', 'tamago-io' ) );
		}
		return $tmp;
	}

	/**
	 * Add a temporary file to the Media Library (WordPress checks the real type against the
	 * allowed upload types).
	 *
	 * @return int|WP_Error
	 */
	private static function sideload( string $tmp, string $name, array $info, string $sha1 ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$check = wp_check_filetype_and_ext( $tmp, $name );
		if ( empty( $check['type'] ) ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'tmg_io_type', __( 'File type not allowed on this site.', 'tamago-io' ) );
		}
		if ( ! empty( $check['proper_filename'] ) ) {
			$name = $check['proper_filename'];
		}
		$post_data = array();
		foreach ( array( 'title' => 'post_title', 'caption' => 'post_excerpt', 'description' => 'post_content' ) as $k => $col ) {
			if ( isset( $info[ $k ] ) && '' !== (string) $info[ $k ] ) {
				$post_data[ $col ] = TMG_IO_Convert::clean_html( (string) $info[ $k ] );
			}
		}
		$id = media_handle_sideload(
			array(
				'name'     => $name,
				'tmp_name' => $tmp,
			),
			0,
			null,
			$post_data
		);
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $tmp );
			return $id;
		}
		if ( ! empty( $info['url'] ) ) {
			update_post_meta( $id, '_tmg_io_src', esc_url_raw( (string) $info['url'] ) );
		}
		update_post_meta( $id, '_tmg_io_sha1', $sha1 );
		if ( ! empty( $info['alt'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( (string) $info['alt'] ) );
		}
		return (int) $id;
	}

	/** Update the texts of an existing attachment (update mode). */
	public static function update_texts( int $id, array $info ): void {
		$args = array( 'ID' => $id );
		foreach ( array( 'title' => 'post_title', 'caption' => 'post_excerpt', 'description' => 'post_content' ) as $k => $col ) {
			if ( isset( $info[ $k ] ) ) {
				$args[ $col ] = (string) $info[ $k ];
			}
		}
		wp_update_post( wp_slash( $args ) );
		if ( isset( $info['alt'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( (string) $info['alt'] ) );
		}
	}
}
