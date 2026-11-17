<?php
/**
 * Goals (named conversions): visiting a page (post ID or URL path pattern), a custom JS event
 * name, or a server-side event name. A matching hit is stamped with the goal key and value
 * and `tmg_reports_goal_completed` fires after insert.
 *
 * Used by: TMG_Reports_Recorder, Reports → Settings (Goals), tests.
 */

defined( 'ABSPATH' ) || exit;

class TMG_Reports_Goals {

	const TYPES = array( 'page', 'event', 'server' );

	/** Goals from settings plus any registered in code via `tmg_reports_goals`. Keyed by goal id. */
	public static function all(): array {
		$goals = array();
		foreach ( (array) TMG_Reports_Settings::get( 'goals' ) as $g ) {
			$goals[ $g['id'] ] = $g;
		}
		$extra = apply_filters( 'tmg_reports_goals', array() );
		foreach ( self::sanitize( is_array( $extra ) ? $extra : array() ) as $g ) {
			$goals[ $g['id'] ] = $goals[ $g['id'] ] ?? $g;
		}
		return $goals;
	}

	public static function sanitize( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$name  = mb_substr( sanitize_text_field( (string) ( $row['name'] ?? '' ) ), 0, 100 );
			$type  = (string) ( $row['type'] ?? '' );
			$match = mb_substr( sanitize_text_field( (string) ( $row['match'] ?? '' ) ), 0, 255 );
			if ( '' === $name || '' === $match || ! in_array( $type, self::TYPES, true ) ) {
				continue;
			}
			$id = substr( sanitize_key( (string) ( $row['id'] ?? '' ) ?: sanitize_title( $name ) ), 0, 64 );
			if ( '' === $id ) {
				$id = 'goal-' . substr( md5( $name ), 0, 8 );
			}
			$base = $id;
			$i    = 2;
			while ( isset( $out[ $id ] ) ) {
				$id = substr( $base, 0, 60 ) . '-' . $i++;
			}
			$out[ $id ] = array(
				'id'    => $id,
				'name'  => $name,
				'type'  => $type,
				'match' => $match,
				'value' => round( max( 0, (float) ( $row['value'] ?? 0 ) ), 2 ),
			);
		}
		return array_values( $out );
	}

	/** First goal matching a hit row, or null. */
	public static function match( array $row ): ?array {
		$type = (string) ( $row['event_type'] ?? '' );
		$name = strtolower( (string) ( $row['event_name'] ?? '' ) );
		foreach ( self::all() as $g ) {
			$m = strtolower( trim( $g['match'] ) );
			switch ( $g['type'] ) {
				case 'page':
					if ( 'pageview' !== $type ) {
						break;
					}
					if ( ctype_digit( $m ) ) {
						if ( (int) $m === (int) ( $row['post_id'] ?? 0 ) ) {
							return $g;
						}
					} elseif ( TMG_Reports_Targeting::path_matches( TMG_Reports_Targeting::relative_path( (string) strtok( (string) ( $row['path'] ?? '' ), '?' ) ), array( $m ) ) ) {
						return $g;
					}
					break;
				case 'event':
					if ( 'pageview' !== $type && 'server' !== $type && self::name_matches( $name, $m ) ) {
						return $g;
					}
					break;
				case 'server':
					if ( 'server' === $type && self::name_matches( $name, $m ) ) {
						return $g;
					}
					break;
			}
		}
		return null;
	}

	private static function name_matches( string $name, string $pattern ): bool {
		if ( '' === $name ) {
			return false;
		}
		return (bool) preg_match( '#^' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . '$#', $name );
	}

	public static function name( string $id ): string {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ]['name'] : $id;
	}
}
