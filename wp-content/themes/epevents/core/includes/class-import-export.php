<?php
/**
 * Import / Export.
 *
 * - JSON "package": full-fidelity copy of events, microsites, posts/pages, applicants and settings,
 *   portable between any WordPress sites running this theme (media are re-downloaded, IDs remapped).
 * - CSV / Excel: flat sheets. Event & microsite sheets carry a `data_json` column so they round-trip too.
 * - Applicants can be imported from any CSV / Excel / JSON with a column-mapping step.
 */

defined( 'ABSPATH' ) || exit;

class EPC_Import_Export {

	const PKG = 'epevents-package';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 25 );
		add_action( 'admin_post_epc_export', array( __CLASS__, 'export' ) );
		add_action( 'admin_post_epc_import', array( __CLASS__, 'import_upload' ) );
		add_action( 'admin_post_epc_import_map', array( __CLASS__, 'import_mapped' ) );
	}

	public static function menu(): void {
		$cap = current_user_can( 'epc_export' ) ? 'epc_export' : 'epc_import';
		add_submenu_page( 'epc', 'Import / Export', 'Import / Export', $cap, 'epc-io', array( __CLASS__, 'render' ) );
	}

	/* =====================================================================
	 * Screen
	 * =================================================================== */

	public static function render(): void {
		$tab    = sanitize_key( $_GET['tab'] ?? ( current_user_can( 'epc_export' ) ? 'export' : 'import' ) ); // phpcs:ignore
		$base   = admin_url( 'admin.php?page=epc-io' );
		$events = get_posts( array( 'post_type' => EPC_Post_Types::EVENT, 'numberposts' => -1, 'post_status' => 'any' ) );
		?>
		<div class="wrap epc-io">
			<h1>Import / Export</h1>
			<?php
			$report = get_transient( 'epc_import_report_' . get_current_user_id() );
			if ( $report && isset( $_GET['done'] ) ) : // phpcs:ignore
				delete_transient( 'epc_import_report_' . get_current_user_id() );
				?>
				<div class="notice notice-success"><p><strong>Import finished.</strong> <?php echo esc_html( $report ); ?></p></div>
			<?php endif; ?>
			<nav class="nav-tab-wrapper">
				<?php if ( current_user_can( 'epc_export' ) || current_user_can( 'epc_export_applicants' ) ) : ?>
					<a class="nav-tab <?php echo 'export' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( $base . '&tab=export' ); ?>">Export</a>
				<?php endif; ?>
				<?php if ( current_user_can( 'epc_import' ) ) : ?>
					<a class="nav-tab <?php echo in_array( $tab, array( 'import', 'map' ), true ) ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( $base . '&tab=import' ); ?>">Import</a>
				<?php endif; ?>
			</nav>

			<?php if ( 'export' === $tab ) : ?>
				<?php if ( current_user_can( 'epc_export' ) ) : ?>
				<div class="epc-card">
					<h2>Full package (move content to another WordPress site)</h2>
					<p class="description">Everything needed to recreate events, forms, microsites and their images on another site running EP Events Core. Choose JSON for the most reliable import.</p>
					<form method="get" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="epc_export"><input type="hidden" name="type" value="package">
						<?php wp_nonce_field( 'epc_export', '_wpnonce', false ); ?>
						<p>
							<label><input type="checkbox" name="pt[]" value="<?php echo esc_attr( EPC_Post_Types::EVENT ); ?>" checked> Events &amp; forms</label>&nbsp;&nbsp;
							<label><input type="checkbox" name="pt[]" value="<?php echo esc_attr( EPC_Post_Types::MICROSITE ); ?>" checked> Microsites</label>&nbsp;&nbsp;
							<label><input type="checkbox" name="pt[]" value="post"> News / blog posts</label>&nbsp;&nbsp;
							<label><input type="checkbox" name="pt[]" value="page"> Pages</label>&nbsp;&nbsp;
							<label><input type="checkbox" name="settings" value="1" checked> Settings &amp; option lists</label>&nbsp;&nbsp;
							<?php if ( current_user_can( 'epc_export_applicants' ) ) : ?>
								<label><input type="checkbox" name="applicants" value="1"> Applicants (personal data)</label>
							<?php endif; ?>
						</p>
						<p>
							<button class="button button-primary" name="format" value="json">Download JSON</button>
							<button class="button" name="format" value="xlsx">Excel</button>
							<button class="button" name="format" value="csv">CSV (events only)</button>
						</p>
					</form>
				</div>
				<?php endif; ?>

				<?php if ( current_user_can( 'epc_export_applicants' ) ) : ?>
				<div class="epc-card">
					<h2>Applicants</h2>
					<form method="get" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="epc_export"><input type="hidden" name="type" value="applicants">
						<?php wp_nonce_field( 'epc_export', '_wpnonce', false ); ?>
						<table class="form-table">
							<tr><th>Event</th><td><select name="event"><option value="">All events (one column per field)</option>
								<?php foreach ( $events as $e ) : ?><option value="<?php echo (int) $e->ID; ?>"><?php echo esc_html( $e->post_title ); ?></option><?php endforeach; ?>
							</select></td></tr>
							<tr><th>Status</th><td><select name="status"><option value="">Any</option>
								<?php foreach ( epc_applicant_statuses() as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $l ); ?></option><?php endforeach; ?>
							</select></td></tr>
						</table>
						<p>
							<button class="button button-primary" name="format" value="xlsx">Excel</button>
							<button class="button" name="format" value="csv">CSV</button>
							<button class="button" name="format" value="json">JSON</button>
						</p>
					</form>
				</div>
				<?php endif; ?>

				<p class="description">WordPress' own <a href="<?php echo esc_url( admin_url( 'export.php' ) ); ?>">Tools → Export</a> (WXR) also includes Events and Microsites.</p>

			<?php elseif ( 'import' === $tab && current_user_can( 'epc_import' ) ) : ?>
				<div class="epc-card">
					<h2>Import a file</h2>
					<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="epc_import">
						<?php wp_nonce_field( 'epc_import' ); ?>
						<table class="form-table">
							<tr><th>File</th><td><input type="file" name="file" accept=".json,.csv,.xlsx,.txt" required><p class="description">JSON package, Excel (.xlsx) or CSV. Applicant lists from any system (old website, Google Forms, Excel) are supported — you will map the columns next.</p></td></tr>
							<tr><th>The file contains</th><td>
								<label><input type="radio" name="kind" value="auto" checked> Detect automatically (package / exported sheet)</label><br>
								<label><input type="radio" name="kind" value="applicants"> A list of applicants / registrations</label>
							</td></tr>
							<tr><th>Import applicants into</th><td><select name="event"><option value="">— choose event (for applicant lists) —</option>
								<?php foreach ( $events as $e ) : ?><option value="<?php echo (int) $e->ID; ?>"><?php echo esc_html( $e->post_title ); ?></option><?php endforeach; ?>
							</select></td></tr>
							<tr><th>If an item already exists</th><td>
								<label><input type="radio" name="existing" value="skip" checked> Skip it</label>&nbsp;&nbsp;
								<label><input type="radio" name="existing" value="update"> Update it</label>&nbsp;&nbsp;
								<label><input type="radio" name="existing" value="new"> Import as a copy</label>
								<p class="description">Matched by slug for events/microsites/posts.</p>
							</td></tr>
							<tr><th>Images</th><td><label><input type="checkbox" name="media" value="1" checked> Download images into this site's Media Library</label></td></tr>
						</table>
						<p><button class="button button-primary">Upload &amp; continue</button></p>
					</form>
				</div>

			<?php elseif ( 'map' === $tab && current_user_can( 'epc_import' ) ) : ?>
				<?php self::render_mapping(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_mapping(): void {
		$token = sanitize_key( $_GET['token'] ?? '' ); // phpcs:ignore
		$job   = get_transient( 'epc_import_' . $token );
		if ( ! $job ) {
			echo '<p>The upload has expired. Please upload again.</p>';
			return;
		}
		$event   = (int) $job['event'];
		$fields  = epc_get_data_fields( $event );
		$headers = $job['rows'][0] ?? array();
		$sample  = $job['rows'][1] ?? array();
		$norm    = fn( $s ) => sanitize_title( remove_accents( (string) $s ) );
		?>
		<div class="epc-card">
			<h2>Map columns → <?php echo esc_html( get_the_title( $event ) ); ?></h2>
			<p class="description"><?php echo (int) ( count( $job['rows'] ) - 1 ); ?> rows found. Unmapped columns are kept in the applicant record as extra data.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="epc_import_map">
				<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">
				<?php wp_nonce_field( 'epc_import_map' ); ?>
				<table class="widefat striped">
					<thead><tr><th>Column in file</th><th>Example</th><th>Save as</th></tr></thead>
					<tbody>
					<?php foreach ( $headers as $i => $h ) : ?>
						<?php
						$guess = '__extra';
						$hn    = $norm( $h );
						foreach ( $fields as $f ) {
							if ( $hn === $f['name'] || $hn === $norm( wp_strip_all_tags( $f['label'] ) ) ) {
								$guess = $f['name'];
							}
						}
						if ( in_array( $hn, array( 'status', 'applicant-status' ), true ) ) {
							$guess = '__status';
						}
						if ( in_array( $hn, array( 'date', 'registered', 'registered-at', 'submitted', 'submission-date', 'timestamp', 'created-at', 'post-date' ), true ) ) {
							$guess = '__date';
						}
						if ( in_array( $hn, array( 'id', 'applicant-id', 'event', 'event-id' ), true ) ) {
							$guess = '';
						}
						?>
						<tr>
							<td><strong><?php echo esc_html( $h ); ?></strong></td>
							<td class="epc-muted"><?php echo esc_html( mb_substr( (string) ( $sample[ $i ] ?? '' ), 0, 60 ) ); ?></td>
							<td><select name="map[<?php echo (int) $i; ?>]">
								<option value="" <?php selected( $guess, '' ); ?>>— ignore —</option>
								<option value="__extra" <?php selected( $guess, '__extra' ); ?>>Keep as extra data</option>
								<option value="__status" <?php selected( $guess, '__status' ); ?>>Applicant status</option>
								<option value="__date" <?php selected( $guess, '__date' ); ?>>Registration date</option>
								<optgroup label="Form fields">
								<?php foreach ( $fields as $f ) : ?>
									<option value="<?php echo esc_attr( $f['name'] ); ?>" <?php selected( $guess, $f['name'] ); ?>><?php echo esc_html( wp_strip_all_tags( $f['label'] ) . ' (' . $f['name'] . ')' ); ?></option>
								<?php endforeach; ?>
								</optgroup>
							</select></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p><label><input type="checkbox" name="skip_dupes" value="1" checked> Skip rows that duplicate a "unique" field (e.g. email / NRIC) already registered</label></p>
				<p><button class="button button-primary">Import applicants</button></p>
			</form>
		</div>
		<?php
	}

	/* =====================================================================
	 * Export
	 * =================================================================== */

	public static function export(): void {
		check_admin_referer( 'epc_export' );
		$type   = sanitize_key( $_GET['type'] ?? '' );
		$format = in_array( $_GET['format'] ?? '', array( 'json', 'csv', 'xlsx' ), true ) ? $_GET['format'] : 'json'; // phpcs:ignore
		$stamp  = wp_date( 'Ymd-His' );

		if ( 'applicants' === $type ) {
			if ( ! current_user_can( 'epc_export_applicants' ) ) {
				wp_die( 'Not allowed.', 'Not allowed', array( 'response' => 403, 'back_link' => true ) );
			}
			$event  = absint( $_GET['event'] ?? 0 );
			$status = sanitize_key( $_GET['status'] ?? '' );
			$name   = 'applicants-' . ( $event ? sanitize_title( get_the_title( $event ) ) : 'all' ) . '-' . $stamp;
			self::send_rows( self::applicant_rows( $event, $status ), $name, $format );
		}

		if ( 'package' === $type ) {
			if ( ! current_user_can( 'epc_export' ) ) {
				wp_die( 'Not allowed.', 'Not allowed', array( 'response' => 403, 'back_link' => true ) );
			}
			$pts      = array_intersect( (array) ( $_GET['pt'] ?? array() ), array( EPC_Post_Types::EVENT, EPC_Post_Types::MICROSITE, 'post', 'page' ) );
			$with_app = ! empty( $_GET['applicants'] ) && current_user_can( 'epc_export_applicants' );
			$pkg      = self::build_package( $pts, $with_app, ! empty( $_GET['settings'] ) );

			if ( 'json' === $format ) {
				nocache_headers();
				header( 'Content-Type: application/json; charset=utf-8' );
				header( 'Content-Disposition: attachment; filename="epevents-' . $stamp . '.json"' );
				echo wp_json_encode( $pkg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				exit;
			}

			// Sheets: one per post type (+ applicants), each row carrying data_json for round-trips.
			$sheets = array();
			foreach ( $pkg['posts'] as $p ) {
				$label              = get_post_type_object( $p['type'] )->labels->name ?? $p['type'];
				$sheets[ $label ][] = self::flat_post( $p, $pkg['media'] );
			}
			if ( 'csv' === $format ) {
				$first = reset( $sheets ) ?: array();
				self::send_rows( $first, 'epevents-' . $stamp, 'csv' );
			}
			if ( $with_app ) {
				$sheets['Applicants'] = self::applicant_rows( 0, '' );
			}
			$out = array();
			foreach ( $sheets as $name => $rows ) {
				$out[ $name ] = self::to_table( $rows );
			}
			self::send_xlsx( $out ?: array( 'Empty' => array( array( 'No data' ) ) ), 'epevents-' . $stamp );
		}
		wp_die( 'Unknown export.' );
	}

	/** Applicant rows with one column per form field (union across events). */
	public static function applicant_rows( int $event, string $status ): array {
		$meta = array();
		if ( $event ) {
			$meta[] = array( 'key' => '_epc_event_id', 'value' => $event );
		}
		if ( $status ) {
			$meta[] = array( 'key' => '_epc_status', 'value' => $status );
		}
		$ids = get_posts(
			array(
				'post_type'   => EPC_Post_Types::APPLICANT,
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_query'  => $meta, // phpcs:ignore
				'orderby'     => 'date',
				'order'       => 'ASC',
			)
		);

		// Column order: event's current fields first, then anything else found.
		$cols = array();
		if ( $event ) {
			foreach ( epc_get_data_fields( $event ) as $f ) {
				$cols[ $f['name'] ] = wp_strip_all_tags( $f['label'] ) ?: $f['name'];
			}
		}
		$rows       = array();
		$mask       = EPC_Features::mask_personal_data();
		$type_cache = array();
		foreach ( $ids as $id ) {
			$data   = (array) get_post_meta( $id, '_epc_data', true );
			$labels = (array) get_post_meta( $id, '_epc_labels', true );
			foreach ( $data as $k => $v ) {
				if ( ! isset( $cols[ $k ] ) ) {
					$cols[ $k ] = $labels[ $k ] ?? $k;
				}
			}
			$src    = (array) get_post_meta( $id, '_epc_source', true );
			$ev     = (int) get_post_meta( $id, '_epc_event_id', true );
			if ( $mask ) {
				// Same masking as on screen (email / phone / NRIC) for users who only see masked data.
				$types = $type_cache[ $ev ] ??= EPC_Features::field_types( $ev );
				foreach ( $data as $k => $v ) {
					if ( is_string( $v ) && isset( $types[ $k ] ) ) {
						$data[ $k ] = EPC_Features::mask( $v, $types[ $k ] );
					}
				}
			}
			$rows[] = array(
				'_base' => array(
					'Applicant ID' => $id,
					'Event'        => get_the_title( $ev ),
					'Event ID'     => $ev,
					'Status'       => get_post_meta( $id, '_epc_status', true ) ?: 'registered',
					'Registered'   => get_the_date( 'Y-m-d H:i:s', $id ),
				),
				'_data' => $data,
				'_src'  => $src,
			);
		}
		$out = array();
		foreach ( $rows as $r ) {
			$row = $r['_base'];
			foreach ( $cols as $k => $label ) {
				$v = $r['_data'][ $k ] ?? '';
				// Disambiguate duplicate labels.
				$key         = isset( $row[ $label ] ) ? $label . ' (' . $k . ')' : $label;
				$row[ $key ] = is_array( $v ) ? implode( ', ', $v ) : (string) $v;
			}
			$row['Source']   = $r['_src']['ref_domain'] ?? '';
			$row['UTM source'] = $r['_src']['utm_source'] ?? '';
			$row['UTM campaign'] = $r['_src']['utm_campaign'] ?? '';
			$out[] = $row;
		}
		return $out;
	}

	/** Build the portable JSON package. */
	public static function build_package( array $post_types, bool $with_applicants, bool $with_settings ): array {
		$pkg = array(
			'format'      => self::PKG,
			'version'     => 1,
			'plugin'      => EPC_VERSION, // key name kept so older packages and sites stay compatible
			'site'        => home_url(),
			'exported_at' => gmdate( 'c' ),
			'posts'       => array(),
			'media'       => array(),
			'applicants'  => array(),
			'settings'    => array(),
		);
		if ( $post_types ) {
			$posts = get_posts( array( 'post_type' => $post_types, 'numberposts' => -1, 'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future' ) ) );
			foreach ( $posts as $p ) {
				$pkg['posts'][] = self::serialize_post( $p, $pkg['media'] );
			}
		}
		if ( $with_applicants ) {
			foreach ( get_posts( array( 'post_type' => EPC_Post_Types::APPLICANT, 'numberposts' => -1 ) ) as $a ) {
				$pkg['applicants'][] = array(
					'id'     => $a->ID,
					'event'  => (int) get_post_meta( $a->ID, '_epc_event_id', true ),
					'status' => get_post_meta( $a->ID, '_epc_status', true ),
					'date'   => $a->post_date,
					'data'   => get_post_meta( $a->ID, '_epc_data', true ),
					'labels' => get_post_meta( $a->ID, '_epc_labels', true ),
					'source' => get_post_meta( $a->ID, '_epc_source', true ),
				);
			}
		}
		if ( $with_settings ) {
			$pkg['settings'] = array(
				'preset_lists' => function_exists( 'get_field' ) ? epc_list( get_field( 'epc_preset_lists', 'option', false ) ) : array(),
				'options'      => array_filter(
					array(
						'epc_mail_from_name'  => epc_setting( 'epc_mail_from_name', '' ),
						'epc_mail_from_email' => epc_setting( 'epc_mail_from_email', '' ),
						'epc_admin_emails'    => epc_setting( 'epc_admin_emails', '' ),
					)
				),
			);
			$pkg['settings']['preset_lists'] = array_map(
				fn( $r ) => array( 'name' => $r['field_epc_pl_name'] ?? '', 'slug' => $r['field_epc_pl_slug'] ?? '', 'options' => $r['field_epc_pl_options'] ?? '' ),
				$pkg['settings']['preset_lists']
			);
		}
		return $pkg;
	}

	/**
	 * Serialise a post + all meta, annotating ACF references (media, posts, terms) for remapping.
	 */
	public static function serialize_post( WP_Post $p, array &$media ): array {
		$meta = array();
		$refs = array();
		$all  = get_post_meta( $p->ID );
		foreach ( $all as $key => $values ) {
			if ( in_array( $key, array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_encloseme', '_pingme' ), true ) ) {
				continue;
			}
			$meta[ $key ] = maybe_unserialize( $values[0] );
		}
		foreach ( $meta as $key => $value ) {
			if ( '_thumbnail_id' === $key ) {
				$refs[ $key ] = 'media';
				self::collect_media( (array) $value, $media );
				continue;
			}
			$fkey = $meta[ '_' . $key ] ?? '';
			if ( ! is_string( $fkey ) || ! str_starts_with( $fkey, 'field_' ) || ! function_exists( 'acf_get_field' ) ) {
				continue;
			}
			$field = acf_get_field( $fkey );
			$type  = $field['type'] ?? '';
			if ( in_array( $type, array( 'image', 'file', 'gallery' ), true ) && $value ) {
				$refs[ $key ] = 'media';
				self::collect_media( (array) $value, $media );
			} elseif ( in_array( $type, array( 'post_object', 'relationship', 'page_link' ), true ) && $value ) {
				$refs[ $key ] = 'post';
			} elseif ( 'taxonomy' === $type && $value ) {
				$refs[ $key ] = 'term:' . ( $field['taxonomy'] ?? 'category' );
				$slugs        = array();
				foreach ( (array) $value as $tid ) {
					$term = get_term( (int) $tid );
					if ( $term && ! is_wp_error( $term ) ) {
						$slugs[ $tid ] = $term->slug;
					}
				}
				$meta[ $key ] = array( '__terms' => $slugs, 'value' => $value );
			}
		}
		$terms = array();
		foreach ( get_object_taxonomies( $p->post_type ) as $tax ) {
			$t = wp_get_object_terms( $p->ID, $tax, array( 'fields' => 'slugs' ) );
			if ( $t && ! is_wp_error( $t ) ) {
				$terms[ $tax ] = $t;
			}
		}
		// Images embedded in content.
		return array(
			'id'         => $p->ID,
			'type'       => $p->post_type,
			'title'      => $p->post_title,
			'slug'       => $p->post_name,
			'status'     => $p->post_status,
			'date'       => $p->post_date,
			'content'    => $p->post_content,
			'excerpt'    => $p->post_excerpt,
			'menu_order' => $p->menu_order,
			'permalink'  => get_permalink( $p ),
			'meta'       => $meta,
			'refs'       => $refs,
			'terms'      => $terms,
		);
	}

	private static function collect_media( array $ids, array &$media ): void {
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id && ! isset( $media[ $id ] ) && ( $url = wp_get_attachment_url( $id ) ) ) {
				$media[ $id ] = array(
					'url'   => $url,
					'title' => get_the_title( $id ),
					'alt'   => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
				);
			}
		}
	}

	/** Flatten a package post for spreadsheets. */
	private static function flat_post( array $p, array $media ): array {
		$m = $p['meta'];
		return array(
			'ID'           => $p['id'],
			'Type'         => $p['type'],
			'Title'        => $p['title'],
			'Slug'         => $p['slug'],
			'Status'       => $p['status'],
			'URL'          => $p['permalink'],
			'Event start'  => $m['event_start'] ?? '',
			'Venue'        => $m['venue'] ?? '',
			'Max capacity' => $m['capacity_max'] ?? '',
			'Registrations' => EPC_Post_Types::EVENT === $p['type'] ? EPC_Capacity::count( (int) $p['id'] ) : '',
			'data_json'    => wp_json_encode( array( 'post' => $p, 'media' => array_intersect_key( $media, array_flip( self::media_ids( $p ) ) ) ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
		);
	}

	private static function media_ids( array $p ): array {
		$ids = array();
		foreach ( $p['refs'] as $key => $kind ) {
			if ( 'media' === $kind ) {
				$ids = array_merge( $ids, array_map( 'intval', (array) $p['meta'][ $key ] ) );
			}
		}
		return $ids;
	}

	/** Associative rows → table (header + values). */
	public static function to_table( array $rows ): array {
		$cols = array();
		foreach ( $rows as $r ) {
			foreach ( array_keys( $r ) as $k ) {
				$cols[ $k ] = true;
			}
		}
		$cols  = array_keys( $cols );
		$table = array( $cols );
		foreach ( $rows as $r ) {
			$table[] = array_map( fn( $c ) => $r[ $c ] ?? '', $cols );
		}
		return $table;
	}

	/** Stream associative rows as CSV / XLSX / JSON and exit. */
	public static function send_rows( array $rows, string $basename, string $format ): void {
		nocache_headers();
		$basename = sanitize_file_name( $basename );
		if ( 'json' === $format ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="' . $basename . '.json"' );
			echo wp_json_encode( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			exit;
		}
		$table = self::to_table( $rows );
		if ( 'xlsx' === $format ) {
			self::send_xlsx( array( 'Data' => $table ), $basename );
		}
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $basename . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // BOM so Excel opens UTF-8 correctly.
		foreach ( $table as $row ) {
			// Guard against CSV formula injection.
			$row = array_map( fn( $v ) => is_string( $v ) && preg_match( '/^[=+\-@\t\r]/', $v ) && ! is_numeric( $v ) ? "'" . $v : $v, $row );
			fputcsv( $out, $row );
		}
		fclose( $out );
		exit;
	}

	private static function send_xlsx( array $sheets, string $basename ): void {
		$path = EPC_Xlsx::write( $sheets );
		nocache_headers();
		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $basename ) . '.xlsx"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path ); // phpcs:ignore
		wp_delete_file( $path );
		exit;
	}

	/* =====================================================================
	 * Import
	 * =================================================================== */

	/** Read any supported file into either a package or a table of rows. */
	public static function read_file( string $path, string $name ): array {
		$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( 'xlsx' === $ext ) {
			return array( 'rows' => EPC_Xlsx::read( $path ) );
		}
		$raw = (string) file_get_contents( $path ); // phpcs:ignore
		$raw = preg_replace( '/^\xEF\xBB\xBF/', '', $raw );
		if ( 'json' === $ext || in_array( ltrim( $raw )[0] ?? '', array( '{', '[' ), true ) ) {
			$json = json_decode( $raw, true );
			if ( is_array( $json ) && ( $json['format'] ?? '' ) === self::PKG ) {
				return array( 'package' => $json );
			}
			if ( is_array( $json ) ) {
				$list = array_is_list( $json ) ? $json : ( $json['data'] ?? $json['rows'] ?? $json['applicants'] ?? array( $json ) );
				$list = array_values( array_filter( (array) $list, 'is_array' ) );
				$flat = array_map( fn( $r ) => array_map( fn( $v ) => is_array( $v ) ? ( array_is_list( $v ) ? implode( ', ', $v ) : wp_json_encode( $v ) ) : $v, isset( $r['data'] ) && is_array( $r['data'] ) ? array_merge( array_diff_key( $r, array( 'data' => 1, 'labels' => 1 ) ), $r['data'] ) : $r ), $list );
				return array( 'rows' => self::to_table( $flat ) );
			}
			return array( 'error' => 'Invalid JSON file.' );
		}
		// CSV (auto-detect delimiter).
		$first = strtok( $raw, "\n" );
		$delim = ',';
		foreach ( array( ';', "\t", '|' ) as $d ) {
			if ( substr_count( (string) $first, $d ) > substr_count( (string) $first, $delim ) ) {
				$delim = $d;
			}
		}
		$rows = array();
		$fh   = fopen( 'php://memory', 'r+' );
		fwrite( $fh, $raw );
		rewind( $fh );
		while ( ( $r = fgetcsv( $fh, 0, $delim ) ) !== false ) {
			if ( array( null ) !== $r ) {
				$rows[] = $r;
			}
		}
		fclose( $fh );
		return array( 'rows' => $rows );
	}

	public static function import_upload(): void {
		check_admin_referer( 'epc_import' );
		if ( ! current_user_can( 'epc_import' ) ) {
			wp_die( 'Not allowed.', 'Not allowed', array( 'response' => 403, 'back_link' => true ) );
		}
		if ( empty( $_FILES['file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['file']['tmp_name'] ) ) {
			wp_die( 'No file uploaded.', '', array( 'back_link' => true ) );
		}
		@set_time_limit( 600 ); // phpcs:ignore
		$file  = self::read_file( $_FILES['file']['tmp_name'], sanitize_file_name( $_FILES['file']['name'] ) );
		$opts  = array(
			'existing' => in_array( $_POST['existing'] ?? '', array( 'skip', 'update', 'new' ), true ) ? $_POST['existing'] : 'skip',
			'media'    => ! empty( $_POST['media'] ),
		);
		$kind  = sanitize_key( $_POST['kind'] ?? 'auto' );
		$event = absint( $_POST['event'] ?? 0 );

		if ( ! empty( $file['error'] ) ) {
			wp_die( esc_html( $file['error'] ), '', array( 'back_link' => true ) );
		}

		if ( isset( $file['package'] ) ) {
			$report = self::import_package( $file['package'], $opts );
			self::finish( $report );
		}

		$rows   = $file['rows'] ?? array();
		$header = array_map( 'strval', $rows[0] ?? array() );
		$djcol  = array_search( 'data_json', $header, true );

		// Sheets exported by this site carry data_json → rebuild a package.
		if ( 'auto' === $kind && false !== $djcol ) {
			$pkg = array( 'format' => self::PKG, 'posts' => array(), 'media' => array(), 'applicants' => array(), 'settings' => array() );
			foreach ( array_slice( $rows, 1 ) as $r ) {
				$d = json_decode( (string) ( $r[ $djcol ] ?? '' ), true );
				if ( isset( $d['post'] ) ) {
					$pkg['posts'][] = $d['post'];
					$pkg['media']  += (array) ( $d['media'] ?? array() );
				}
			}
			self::finish( self::import_package( $pkg, $opts ) );
		}

		if ( count( $rows ) < 2 ) {
			wp_die( 'The file has no data rows.', '', array( 'back_link' => true ) );
		}
		if ( ! $event || EPC_Post_Types::EVENT !== get_post_type( $event ) ) {
			wp_die( 'Please choose the event to import these applicants into.', '', array( 'back_link' => true ) );
		}
		$token = strtolower( wp_generate_password( 20, false ) );
		set_transient( 'epc_import_' . $token, array( 'rows' => $rows, 'event' => $event, 'user' => get_current_user_id() ), HOUR_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=epc-io&tab=map&token=' . $token ) );
		exit;
	}

	public static function import_mapped(): void {
		check_admin_referer( 'epc_import_map' );
		if ( ! current_user_can( 'epc_import' ) ) {
			wp_die( 'Not allowed.', 'Not allowed', array( 'response' => 403, 'back_link' => true ) );
		}
		$token = sanitize_key( $_POST['token'] ?? '' );
		$job   = get_transient( 'epc_import_' . $token );
		if ( ! $job || (int) $job['user'] !== get_current_user_id() ) {
			wp_die( 'The upload has expired. Please upload the file again.', 'Expired', array( 'response' => 410, 'back_link' => true ) );
		}
		@set_time_limit( 600 ); // phpcs:ignore
		$map     = array_map( 'sanitize_key', (array) ( $_POST['map'] ?? array() ) );
		$event   = (int) $job['event'];
		$fields  = epc_get_data_fields( $event );
		$unique  = array_column( array_filter( $fields, fn( $f ) => $f['unique'] ), 'name' );
		$header  = $job['rows'][0];
		$skip    = ! empty( $_POST['skip_dupes'] );
		$done    = 0;
		$skipped = 0;

		foreach ( array_slice( $job['rows'], 1 ) as $r ) {
			if ( ! array_filter( $r, fn( $v ) => '' !== trim( (string) $v ) ) ) {
				continue;
			}
			$data   = array();
			$status = 'registered';
			$date   = '';
			foreach ( $r as $i => $v ) {
				$to = $map[ $i ] ?? '';
				$v  = sanitize_text_field( (string) $v );
				if ( '' === $to ) {
					continue;
				}
				if ( '__status' === $to ) {
					$s      = sanitize_key( strtolower( $v ) );
					$status = isset( epc_applicant_statuses()[ $s ] ) ? $s : $status;
				} elseif ( '__date' === $to ) {
					$ts   = is_numeric( $v ) && $v < 100000 ? ( (int) round( ( (float) $v - 25569 ) * 86400 ) ) : strtotime( $v ); // Excel serial or text date.
					$date = $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : '';
				} elseif ( '__extra' === $to ) {
					$data[ sanitize_title( (string) $header[ $i ] ) ?: 'col-' . $i ] = $v;
				} else {
					$data[ $to ] = $v;
				}
			}
			if ( $skip ) {
				foreach ( $unique as $u ) {
					if ( ! empty( $data[ $u ] ) && EPC_Form::exists( $event, $u, (string) $data[ $u ] ) ) {
						++$skipped;
						continue 2;
					}
				}
			}
			$labels = $fields;
			foreach ( $data as $k => $v ) {
				if ( ! in_array( $k, array_column( $fields, 'name' ), true ) ) {
					$labels[] = array( 'name' => $k, 'label' => (string) ( $header[ array_search( $k, array_map( fn( $h ) => sanitize_title( (string) $h ), $header ), true ) ] ?? $k ) );
				}
			}
			if ( EPC_Form::store( $event, $data, $labels, $status, array(), $date ) ) {
				++$done;
			}
		}
		delete_transient( 'epc_import_' . $token );
		self::finish( sprintf( '%d applicants imported into "%s"%s.', $done, get_the_title( $event ), $skipped ? ", {$skipped} duplicates skipped" : '' ) );
	}

	private static function finish( string $report ): void {
		set_transient( 'epc_import_report_' . get_current_user_id(), $report, 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=epc-io&tab=import&done=1' ) );
		exit;
	}

	/**
	 * Import a package: settings → posts (create / update / copy) → meta with remapped references → applicants.
	 */
	public static function import_package( array $pkg, array $opts ): string {
		$post_map  = array();
		$media_map = array();
		$counts    = array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'media' => 0, 'applicants' => 0 );
		$allowed   = array( EPC_Post_Types::EVENT, EPC_Post_Types::MICROSITE, 'post', 'page' );

		// Settings: only for users who may change settings, and never the mail server (its password cannot travel).
		$may_settings = current_user_can( 'epc_manage_settings' );
		if ( $may_settings && ! empty( $pkg['settings']['preset_lists'] ) && function_exists( 'update_field' ) ) {
			$current = array();
			foreach ( epc_list( get_field( 'epc_preset_lists', 'option' ) ) as $row ) {
				$current[ sanitize_title( $row['slug'] ?: $row['name'] ) ] = $row;
			}
			foreach ( $pkg['settings']['preset_lists'] as $row ) {
				$slug = sanitize_title( ( $row['slug'] ?? '' ) ?: ( $row['name'] ?? '' ) );
				if ( $slug && ( ! isset( $current[ $slug ] ) || 'update' === $opts['existing'] ) ) {
					$current[ $slug ] = array( 'name' => $row['name'], 'slug' => $slug, 'options' => $row['options'] );
				}
			}
			update_field( 'epc_preset_lists', array_values( $current ), 'option' );
		}
		foreach ( (array) ( $pkg['settings']['options'] ?? array() ) as $k => $v ) {
			if ( $may_settings && str_starts_with( (string) $k, 'epc_' ) && ! str_starts_with( (string) $k, 'epc_smtp_' ) && function_exists( 'update_field' ) ) {
				update_field( $k, $v, 'option' );
			}
		}

		$media = (array) ( $pkg['media'] ?? array() );
		$todo  = array();

		// Pass 1: create / match posts.
		foreach ( (array) ( $pkg['posts'] ?? array() ) as $p ) {
			if ( ! in_array( $p['type'] ?? '', $allowed, true ) ) {
				continue;
			}
			// Same rights as on screen: create / edit / publish this content type.
			$pto = get_post_type_object( $p['type'] );
			if ( ! $pto || ! current_user_can( $pto->cap->create_posts ) ) {
				++$counts['skipped'];
				continue;
			}
			$existing = get_page_by_path( $p['slug'], OBJECT, $p['type'] );
			if ( $existing && 'update' === $opts['existing'] && ! current_user_can( 'edit_post', $existing->ID ) ) {
				++$counts['skipped'];
				continue;
			}
			if ( $existing && 'skip' === $opts['existing'] ) {
				$post_map[ (int) $p['id'] ] = $existing->ID;
				++$counts['skipped'];
				continue;
			}
			$args = array(
				'post_type'    => $p['type'],
				'post_title'   => $p['title'],
				'post_status'  => in_array( $p['status'], array( 'publish', 'draft', 'pending', 'private', 'future' ), true ) && ( 'draft' === $p['status'] || current_user_can( $pto->cap->publish_posts ) ) ? $p['status'] : 'draft',
				'post_content' => $p['content'] ?? '',
				'post_excerpt' => $p['excerpt'] ?? '',
				'menu_order'   => (int) ( $p['menu_order'] ?? 0 ),
				'post_date'    => $p['date'] ?? '',
			);
			if ( $existing && 'update' === $opts['existing'] ) {
				$args['ID'] = $existing->ID;
				$new_id     = wp_update_post( wp_slash( $args ), true );
				++$counts['updated'];
			} else {
				$args['post_name'] = $p['slug'];
				$new_id            = wp_insert_post( wp_slash( $args ), true );
				++$counts['created'];
			}
			if ( is_wp_error( $new_id ) ) {
				continue;
			}
			$post_map[ (int) $p['id'] ] = (int) $new_id;
			$todo[]                     = array( $new_id, $p );
		}

		// Pass 2: meta with remapped references.
		foreach ( $todo as [ $new_id, $p ] ) {
			foreach ( (array) $p['meta'] as $key => $value ) {
				$key = (string) $key;
				// Internal flags and WordPress bookkeeping are not imported.
				if ( preg_match( '/^_(epc_|edit_|wp_old_|wp_trash_|encloseme|pingme)/', $key ) ) {
					continue;
				}
				// Custom HTML sections: same cleaning as when saved on screen.
				if ( is_string( $value ) && str_ends_with( $key, '_code' ) && ! current_user_can( 'unfiltered_html' ) ) {
					$value = epc_clean_embed_html( $value );
				}
				$ref = $p['refs'][ $key ] ?? '';
				if ( 'media' === $ref ) {
					$value = self::remap_list( $value, fn( $old ) => self::import_media( (int) $old, $media, $media_map, $opts, $counts ) );
				} elseif ( 'post' === $ref ) {
					$value = self::remap_list( $value, fn( $old ) => $post_map[ (int) $old ] ?? ( get_post( (int) $old ) ? (int) $old : 0 ) );
				} elseif ( str_starts_with( $ref, 'term:' ) && is_array( $value ) && isset( $value['__terms'] ) ) {
					$tax   = substr( $ref, 5 );
					$value = self::remap_list(
						$value['value'],
						function ( $old ) use ( $value, $tax ) {
							$slug = $value['__terms'][ $old ] ?? '';
							$term = $slug ? get_term_by( 'slug', $slug, $tax ) : false;
							return $term ? $term->term_id : 0;
						}
					);
				}
				update_post_meta( $new_id, $key, wp_slash( $value ) );
			}
			foreach ( (array) ( $p['terms'] ?? array() ) as $tax => $slugs ) {
				if ( taxonomy_exists( $tax ) ) {
					wp_set_object_terms( $new_id, $slugs, $tax );
				}
			}
		}

		// Applicants.
		foreach ( (array) ( $pkg['applicants'] ?? array() ) as $a ) {
			$event = $post_map[ (int) $a['event'] ] ?? 0;
			if ( ! $event ) {
				continue;
			}
			$labels = array();
			foreach ( (array) ( $a['labels'] ?? array() ) as $k => $l ) {
				$labels[] = array( 'name' => $k, 'label' => $l );
			}
			$id = EPC_Form::store( $event, (array) $a['data'], $labels, ( isset( epc_applicant_statuses()[ sanitize_key( (string) ( $a['status'] ?? '' ) ) ] ) ? sanitize_key( (string) $a['status'] ) : 'registered' ), array(), (string) ( $a['date'] ?? '' ) );
			if ( $id && ! empty( $a['source'] ) ) {
				update_post_meta( $id, '_epc_source', $a['source'] );
			}
			$counts['applicants'] += $id ? 1 : 0;
		}

		flush_rewrite_rules( false );
		return sprintf( '%d created, %d updated, %d skipped (already existed), %d images imported, %d applicants.', $counts['created'], $counts['updated'], $counts['skipped'], $counts['media'], $counts['applicants'] );
	}

	/** Apply $fn to a scalar ID, an array of IDs or a comma-separated list. */
	private static function remap_list( $value, callable $fn ) {
		if ( is_array( $value ) ) {
			return array_values( array_filter( array_map( $fn, $value ) ) );
		}
		if ( is_string( $value ) && str_contains( $value, ',' ) ) {
			return implode( ',', array_filter( array_map( $fn, explode( ',', $value ) ) ) );
		}
		return '' === $value || null === $value ? $value : ( $fn( $value ) ?: '' );
	}

	/** Download an image into the Media Library (deduplicated by source URL). */
	private static function import_media( int $old, array $media, array &$map, array $opts, array &$counts ): int {
		if ( isset( $map[ $old ] ) ) {
			return $map[ $old ];
		}
		$info = $media[ $old ] ?? $media[ (string) $old ] ?? null;
		if ( ! $info || empty( $info['url'] ) ) {
			return $map[ $old ] = 0;
		}
		$found = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_epc_src', 'meta_value' => $info['url'] ) ); // phpcs:ignore
		if ( $found ) {
			return $map[ $old ] = (int) $found[0];
		}
		$local = attachment_url_to_postid( $info['url'] );
		if ( $local ) {
			return $map[ $old ] = $local;
		}
		if ( empty( $opts['media'] ) ) {
			return $map[ $old ] = 0;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$tmp = download_url( $info['url'], 60 );
		if ( is_wp_error( $tmp ) ) {
			return $map[ $old ] = 0;
		}
		$id = media_handle_sideload( array( 'name' => wp_basename( wp_parse_url( $info['url'], PHP_URL_PATH ) ), 'tmp_name' => $tmp ), 0, $info['title'] ?? '' );
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $tmp );
			return $map[ $old ] = 0;
		}
		update_post_meta( $id, '_epc_src', $info['url'] );
		if ( ! empty( $info['alt'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $info['alt'] );
		}
		++$counts['media'];
		return $map[ $old ] = (int) $id;
	}
}
