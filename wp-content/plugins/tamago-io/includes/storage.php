<?php
/**
 * Protected working folder (uploads/tamago-io) for uploaded files, run state and temporary
 * exports. Web access is denied (.htaccess, web.config, index.php); files are deleted after a run
 * and a daily cron removes anything older than a day.
 *
 * Used by: uploads, importer, exporter, WP-CLI.
 */

defined( 'ABSPATH' ) || exit;

class TMG_IO_Storage {

	public static function init(): void {
		add_action( 'tmg_io_cleanup', array( __CLASS__, 'cleanup' ) );
	}

	/** Absolute path of the protected folder (created on demand), with trailing slash. */
	public static function dir(): string {
		$up  = wp_upload_dir( null, false );
		$dir = trailingslashit( $up['basedir'] ) . 'tamago-io/';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$guards = array(
			'.htaccess'  => "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
			'web.config' => '<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>',
			'index.php'  => "<?php\n// Silence is golden.\n",
		);
		foreach ( $guards as $file => $body ) {
			if ( ! file_exists( $dir . $file ) ) {
				file_put_contents( $dir . $file, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
		return $dir;
	}

	/** A new unguessable file path inside the protected folder. */
	public static function new_path( string $ext, string $prefix = 'f' ): string {
		$ext = preg_replace( '/[^a-z0-9]/', '', strtolower( $ext ) );
		return self::dir() . sanitize_key( $prefix ) . '-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 16, false ) . ( $ext ? '.' . $ext : '' );
	}

	/** True when $path is a file inside the protected folder (never follow paths elsewhere). */
	public static function owns( string $path ): bool {
		$real = realpath( $path );
		$dir  = realpath( self::dir() );
		return $real && $dir && 0 === strpos( wp_normalize_path( $real ), trailingslashit( wp_normalize_path( $dir ) ) ) && is_file( $real );
	}

	public static function delete( string $path ): void {
		if ( '' !== $path && self::owns( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/** Remove working files older than a day, and old history rows. */
	public static function cleanup(): void {
		$dir = self::dir();
		foreach ( (array) glob( $dir . '*' ) as $file ) {
			$base = basename( (string) $file );
			if ( in_array( $base, array( '.htaccess', 'web.config', 'index.php' ), true ) || ! is_file( (string) $file ) ) {
				continue;
			}
			if ( filemtime( (string) $file ) < time() - DAY_IN_SECONDS ) {
				wp_delete_file( (string) $file );
			}
		}
		TMG_IO_Runs::prune( (int) TMG_IO_Settings::get( 'history_days' ) );
	}
}
