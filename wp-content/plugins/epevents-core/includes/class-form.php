<?php
/**
 * Registration form: rendering, validation and submission (no third-party form plugin).
 */

defined( 'ABSPATH' ) || exit;

class EPC_Form {

	const OTHER = '__other';

	public static function init(): void {
		add_shortcode( 'ep_event_form', array( __CLASS__, 'shortcode' ) );
		add_action( 'admin_post_nopriv_epc_register', array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_epc_register', array( __CLASS__, 'handle' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	public static function register_assets(): void {
		wp_register_script( 'epc-form', EPC_URL . 'assets/js/form.js', array(), EPC_VERSION, true );
		wp_register_style( 'epc-form', EPC_URL . 'assets/css/form.css', array(), EPC_VERSION );
	}

	public static function shortcode( $atts ): string {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts );
		return self::render( (int) $atts['id'] ?: get_the_ID() );
	}

	/* ---------------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------------- */

	public static function render( int $event_id ): string {
		$event = get_post( $event_id );
		if ( ! $event || EPC_Post_Types::EVENT !== $event->post_type ) {
			return '';
		}
		wp_enqueue_script( 'epc-form' );
		wp_enqueue_style( 'epc-form' );

		// Thank-you state (non-JS fallback redirect back with ?epc_done=1).
		if ( isset( $_GET['epc_done'] ) && (int) $_GET['epc_done'] === $event_id ) { // phpcs:ignore
			$msg = get_field( 'confirmation_message', $event_id ) ?: '<h3>Thank you!</h3><p>Your registration has been received.</p>';
			return '<div class="epc-form-wrap"><div class="epc-success">' . wp_kses_post( $msg ) . '</div></div>';
		}

		$state = EPC_Capacity::state( $event_id );
		if ( ! in_array( $state['state'], array( 'open', 'waitlist' ), true ) ) {
			return '<div class="epc-form-wrap">' . self::closed_message( $event_id, $state['state'] ) . '</div>';
		}

		$fields   = epc_get_form_fields( $event_id );
		$patterns = EPC_Validator::js_patterns();
		$uid      = 'epc-form-' . $event_id;

		ob_start();
		?>
		<div class="epc-form-wrap" id="<?php echo esc_attr( $uid ); ?>-wrap">
			<?php if ( 'waitlist' === $state['state'] ) : ?>
				<div class="epc-notice epc-notice--warn"><?php echo wp_kses_post( get_field( 'waitlist_message', $event_id ) ?: 'This event is currently full. You may still register and you will be placed on the waiting list.' ); ?></div>
			<?php endif; ?>
			<?php
			$intro = get_field( 'form_intro', $event_id );
			if ( $intro ) {
				echo '<div class="epc-form-intro">' . wp_kses_post( $intro ) . '</div>';
			}
			?>
			<form class="epc-form" id="<?php echo esc_attr( $uid ); ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" novalidate>
				<input type="hidden" name="action" value="epc_register">
				<input type="hidden" name="epc_event" value="<?php echo (int) $event_id; ?>">
				<input type="hidden" name="epc_ts" value="<?php echo esc_attr( (string) time() ); ?>">
				<input type="hidden" name="epc_visitor" value="">
				<input type="hidden" name="epc_source" value="">
				<div class="epc-hp" aria-hidden="true"><label>Leave empty<input type="text" name="epc_hp" tabindex="-1" autocomplete="off"></label></div>
				<div class="epc-grid">
					<?php
					foreach ( $fields as $field ) {
						echo self::render_field( $field, $uid, $patterns ); // phpcs:ignore -- escaped inside.
					}
					?>
				</div>
				<div class="epc-form-errors" role="alert" hidden></div>
				<div class="epc-actions">
					<button type="submit" class="epc-submit"><?php echo esc_html( get_field( 'submit_label', $event_id ) ?: 'Submit' ); ?></button>
				</div>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	public static function closed_message( int $event_id, string $state ): string {
		if ( 'full' === $state ) {
			$title = get_field( 'full_title', $event_id ) ?: 'Application Full';
			$body  = get_field( 'full_message', $event_id ) ?: '<p>We have reached the maximum registration capacity and are no longer able to accept registrations. Thank you for your interest.</p>';
		} elseif ( 'not_open' === $state ) {
			$open  = get_field( 'reg_open_at', $event_id );
			$title = 'Registration opens soon';
			$body  = '<p>Registration will open on ' . esc_html( $open ? date_i18n( 'j F Y, g:ia', strtotime( $open ) ) : 'a later date' ) . '.</p>';
		} else {
			$title = get_field( 'closed_title', $event_id ) ?: 'Registration Closed';
			$body  = get_field( 'closed_message', $event_id ) ?: '<p>Registration for this event is now closed.</p>';
		}
		$title = epc_replace_placeholders( (string) $title, array(), $event_id );
		$body  = epc_replace_placeholders( (string) $body, array(), $event_id );
		return '<div class="epc-closed epc-closed--' . esc_attr( $state ) . '"><h2>' . esc_html( $title ) . '</h2>' . wp_kses_post( $body ) . '</div>';
	}

	private static function render_field( array $f, string $uid, array $patterns ): string {
		$id    = $uid . '-' . $f['name'];
		$name  = $f['name'];
		$req   = $f['required'] ? ' required' : '';
		$star  = $f['required'] ? ' <span class="epc-req" aria-hidden="true">*</span>' : '';
		$label = wp_kses_post( $f['label'] );
		$ph    = esc_attr( $f['placeholder'] );
		$def   = $f['default'];
		$cond  = $f['conditions'] && $f['conditions']['field'] ? ' data-cond="' . esc_attr( wp_json_encode( $f['conditions'] ) ) . '"' : '';
		$width = in_array( $f['width'], array( '100', '66', '50', '33', '25' ), true ) ? $f['width'] : '100';
		$help  = $f['help'] ? '<small class="epc-help">' . wp_kses_post( $f['help'] ) . '</small>' : '';
		$open  = '<div class="epc-field epc-field--' . esc_attr( $f['type'] ) . ' epc-w-' . $width . '" data-name="' . esc_attr( $name ) . '"' . $cond . '>';
		$err   = '<span class="epc-error" hidden></span>';

		switch ( $f['type'] ) {
			case 'heading':
				return '<div class="epc-field epc-field--heading epc-w-100"' . $cond . '><h3>' . esc_html( $f['label'] ) . '</h3>' . ( $f['content'] ? '<div>' . wp_kses_post( $f['content'] ) . '</div>' : '' ) . '</div>';

			case 'html':
				return '<div class="epc-field epc-field--html epc-w-' . $width . '"' . $cond . '>' . wp_kses_post( $f['content'] ) . '</div>';

			case 'hidden':
				return '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $def ) . '">';

			case 'textarea':
				return $open . '<label for="' . esc_attr( $id ) . '">' . $label . $star . '</label><textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" placeholder="' . $ph . '" rows="4"' . $req . '>' . esc_textarea( $def ) . '</textarea>' . $help . $err . '</div>';

			case 'select':
			case 'salutation':
			case 'country':
				$html = $open . '<label for="' . esc_attr( $id ) . '">' . $label . $star . '</label><select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . $req . '><option value="">' . esc_html( $f['placeholder'] ?: '— Select —' ) . '</option>';
				foreach ( $f['options'] as $o ) {
					$html .= '<option value="' . esc_attr( $o ) . '"' . selected( $def, $o, false ) . '>' . esc_html( $o ) . '</option>';
				}
				if ( $f['allow_other'] ) {
					$html .= '<option value="' . self::OTHER . '">Others</option>';
				}
				$html .= '</select>' . self::other_input( $f, $id ) . $help . $err . '</div>';
				return $html;

			case 'radio':
			case 'checkboxes':
				$type  = 'radio' === $f['type'] ? 'radio' : 'checkbox';
				$iname = 'radio' === $f['type'] ? $name : $name . '[]';
				$html  = $open . '<fieldset><legend>' . $label . $star . '</legend><div class="epc-choices">';
				$opts  = $f['options'];
				if ( $f['allow_other'] ) {
					$opts[] = self::OTHER;
				}
				foreach ( $opts as $k => $o ) {
					$oid   = $id . '-' . $k;
					$text  = self::OTHER === $o ? 'Others' : $o;
					$html .= '<label class="epc-choice" for="' . esc_attr( $oid ) . '"><input type="' . $type . '" id="' . esc_attr( $oid ) . '" name="' . esc_attr( $iname ) . '" value="' . esc_attr( $o ) . '"' . ( 'radio' === $type ? $req : '' ) . checked( $def, $o, false ) . '> <span>' . esc_html( $text ) . '</span></label>';
				}
				$html .= '</div></fieldset>' . self::other_input( $f, $id ) . $help . $err . '</div>';
				return $html;

			case 'consent':
				$text = $f['content'] ?: $f['label'];
				return '<div class="epc-field epc-field--consent epc-w-100" data-name="' . esc_attr( $name ) . '"' . $cond . '><label class="epc-choice" for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="Agreed"' . $req . '> <span>' . wp_kses_post( $text ) . $star . '</span></label>' . $help . $err . '</div>';

			default:
				$type  = array(
					'email'  => 'email',
					'phone'  => 'tel',
					'number' => 'number',
					'date'   => 'date',
				)[ $f['type'] ] ?? 'text';
				$extra = '';
				if ( 'nric' === $f['type'] ) {
					$extra = ' inputmode="numeric" autocomplete="off" pattern="' . esc_attr( $patterns['nric'][ $f['format'] ] ?? '' ) . '" data-format="' . esc_attr( $f['format'] ) . '"';
					$ph    = $ph ?: ( 'my' === $f['format'] ? 'e.g. 900101-14-5678' : '' );
				} elseif ( 'phone' === $f['type'] ) {
					$extra = ' autocomplete="tel" pattern="' . esc_attr( $patterns['phone'][ $f['format'] ] ?? '' ) . '" data-format="' . esc_attr( $f['format'] ) . '"';
					$ph    = $ph ?: ( 'my' === $f['format'] ? 'e.g. 012-345 6789' : 'e.g. +60 12-345 6789' );
				} elseif ( 'email' === $f['type'] ) {
					$extra = ' autocomplete="email"';
				}
				return $open . '<label for="' . esc_attr( $id ) . '">' . $label . $star . '</label><input type="' . $type . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $def ) . '" placeholder="' . $ph . '"' . $req . $extra . '>' . $help . $err . '</div>';
		}
	}

	private static function other_input( array $f, string $id ): string {
		if ( ! $f['allow_other'] ) {
			return '';
		}
		return '<input type="text" class="epc-other" id="' . esc_attr( $id ) . '-other" name="' . esc_attr( $f['name'] ) . '__other" placeholder="Please specify" hidden disabled>';
	}

	/* ---------------------------------------------------------------------
	 * Submission
	 * ------------------------------------------------------------------- */

	public static function handle(): void {
		$event_id = absint( $_POST['epc_event'] ?? 0 );
		$ajax     = ! empty( $_SERVER['HTTP_X_REQUESTED_WITH'] ) || ! empty( $_POST['epc_ajax'] );

		$result = self::process( $event_id, wp_unslash( $_POST ) );

		if ( $ajax ) {
			wp_send_json( $result, $result['ok'] ? 200 : 422 );
		}
		if ( $result['ok'] ) {
			wp_safe_redirect( $result['redirect'] ?: add_query_arg( 'epc_done', $event_id, get_permalink( $event_id ) ) );
			exit;
		}
		wp_die( wp_kses_post( implode( '<br>', array_merge( array( $result['message'] ), array_values( $result['errors'] ) ) ) ), 'Registration error', array( 'back_link' => true ) );
	}

	/**
	 * Validate and store a submission.
	 *
	 * @return array{ok:bool,message:string,errors:array,redirect:string,html:string}
	 */
	public static function process( int $event_id, array $post ): array {
		$fail = fn( string $msg, array $errors = array() ) => array( 'ok' => false, 'message' => $msg, 'errors' => $errors, 'redirect' => '', 'html' => '' );

		$event = get_post( $event_id );
		if ( ! $event || EPC_Post_Types::EVENT !== $event->post_type || 'publish' !== $event->post_status ) {
			return $fail( 'This event is not available.' );
		}
		// Basic spam protection: honeypot + minimum fill time.
		if ( ! empty( $post['epc_hp'] ) || ( time() - (int) ( $post['epc_ts'] ?? 0 ) ) < 3 ) {
			return $fail( 'Submission rejected. Please try again.' );
		}
		if ( ! EPC_Capacity::is_accepting( $event_id ) ) {
			return $fail( trim( wp_strip_all_tags( str_replace( '</h2>', '</h2> ', self::closed_message( $event_id, EPC_Capacity::state( $event_id )['state'] ) ) ) ) );
		}

		$fields = epc_get_data_fields( $event_id );
		$data   = array();
		$errors = array();

		// First pass: raw values (needed to evaluate conditions).
		$raw = array();
		foreach ( $fields as $f ) {
			$v = $post[ $f['name'] ] ?? '';
			if ( is_array( $v ) ) {
				$v = array_map( 'sanitize_text_field', $v );
			} else {
				$v = 'textarea' === $f['type'] ? sanitize_textarea_field( (string) $v ) : sanitize_text_field( (string) $v );
			}
			$raw[ $f['name'] ] = $v;
		}

		foreach ( $fields as $f ) {
			$name = $f['name'];
			if ( ! self::is_visible( $f, $raw ) ) {
				continue;
			}
			$v     = $raw[ $name ];
			$empty = is_array( $v ) ? ! array_filter( $v, 'strlen' ) : '' === trim( $v );

			if ( $empty ) {
				if ( $f['required'] ) {
					$errors[ $name ] = ( 'consent' === $f['type'] ) ? 'You must agree to continue.' : sprintf( '%s is required.', wp_strip_all_tags( $f['label'] ) ?: 'This field' );
				}
				$data[ $name ] = $f['type'] === 'checkboxes' ? array() : '';
				continue;
			}

			// "Others – please specify".
			$other_text = sanitize_text_field( (string) ( $post[ $name . '__other' ] ?? '' ) );
			if ( $f['allow_other'] ) {
				if ( is_array( $v ) && in_array( self::OTHER, $v, true ) ) {
					$v = array_map( fn( $x ) => self::OTHER === $x ? 'Others: ' . $other_text : $x, $v );
					if ( '' === $other_text ) {
						$errors[ $name ] = 'Please specify "Others".';
					}
				} elseif ( self::OTHER === $v ) {
					if ( '' === $other_text ) {
						$errors[ $name ] = 'Please specify "Others".';
					}
					$v = 'Others: ' . $other_text;
				}
			}

			switch ( $f['type'] ) {
				case 'email':
					[ $ok, $v, $msg ] = EPC_Validator::email( $v );
					break;
				case 'phone':
					[ $ok, $v, $msg ] = EPC_Validator::phone( $v, $f['format'] ?: 'my' );
					break;
				case 'nric':
					[ $ok, $v, $msg ] = EPC_Validator::nric( $v, $f['format'] ?: 'my' );
					break;
				case 'number':
					[ $ok, $v, $msg ] = EPC_Validator::number( $v );
					break;
				case 'date':
					[ $ok, $v, $msg ] = EPC_Validator::date( $v );
					break;
				case 'select':
				case 'radio':
				case 'salutation':
				case 'country':
					$ok  = in_array( $v, $f['options'], true ) || ( $f['allow_other'] && str_starts_with( $v, 'Others: ' ) );
					$msg = 'Please choose a valid option.';
					break;
				case 'checkboxes':
					$ok  = ! array_diff( array_filter( $v, fn( $x ) => ! str_starts_with( $x, 'Others: ' ) ), $f['options'] );
					$msg = 'Please choose valid options.';
					break;
				default:
					$ok  = true;
					$msg = '';
			}
			if ( ! $ok && ! isset( $errors[ $name ] ) ) {
				$errors[ $name ] = $msg;
			}

			if ( $ok && $f['unique'] && self::exists( $event_id, $name, is_array( $v ) ? implode( ', ', $v ) : $v ) ) {
				$errors[ $name ] = sprintf( 'This %s has already been registered for this event.', strtolower( wp_strip_all_tags( $f['label'] ) ) ?: 'value' );
			}
			$data[ $name ] = $v;
		}

		if ( $errors ) {
			return $fail( 'Please correct the highlighted fields.', $errors );
		}

		// Serialise capacity check + insert to avoid overbooking on concurrent submissions.
		global $wpdb;
		$lock = 'epc_event_' . $event_id;
		$wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock ) );
		$state = EPC_Capacity::state( $event_id );
		if ( ! in_array( $state['state'], array( 'open', 'waitlist' ), true ) ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
			return $fail( trim( wp_strip_all_tags( str_replace( '</h2>', '</h2> ', self::closed_message( $event_id, $state['state'] ) ) ) ) );
		}
		$status       = 'waitlist' === $state['state'] ? 'waitlist' : ( get_field( 'default_status', $event_id ) ?: 'registered' );
		$applicant_id = self::store( $event_id, $data, $fields, $status, $post );
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );

		if ( ! $applicant_id ) {
			return $fail( 'Sorry, we could not save your registration. Please try again.' );
		}

		do_action( 'epc_applicant_registered', $applicant_id, $event_id, $data );
		EPC_Mailer::registered( $applicant_id );

		$message  = get_field( 'confirmation_message', $event_id ) ?: '<h3>Thank you!</h3><p>Your registration for {event_title} has been received.</p>';
		$redirect = 'redirect' === get_field( 'confirmation_type', $event_id ) ? (string) get_field( 'redirect_url', $event_id ) : '';
		if ( 'waitlist' === $status ) {
			$message = get_field( 'waitlist_confirmation', $event_id ) ?: '<h3>You are on the waiting list</h3><p>We will contact you if a seat becomes available.</p>';
			$redirect = '';
		}

		return array(
			'ok'       => true,
			'message'  => 'Registered',
			'errors'   => array(),
			'redirect' => $redirect,
			'html'     => wp_kses_post( epc_replace_placeholders( $message, $data, $event_id ) ),
		);
	}

	/** Evaluate a field's display condition against submitted values. */
	public static function is_visible( array $f, array $values ): bool {
		$c = $f['conditions'];
		if ( ! $c || ! $c['field'] ) {
			return true;
		}
		$actual = $values[ $c['field'] ] ?? '';
		$list   = is_array( $actual ) ? $actual : array( $actual );
		$target = strtolower( trim( $c['value'] ) );
		$hit    = in_array( $target, array_map( fn( $x ) => strtolower( trim( (string) $x ) ), $list ), true );
		switch ( $c['operator'] ) {
			case 'is_not':
				return ! $hit;
			case 'not_empty':
				return (bool) array_filter( $list, 'strlen' );
			case 'empty':
				return ! array_filter( $list, 'strlen' );
			case 'contains':
				return '' !== $target && str_contains( strtolower( implode( ' ', $list ) ), $target );
			default:
				return $hit;
		}
	}

	public static function exists( int $event_id, string $name, string $value ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = '_epc_event_id' AND e.meta_value = %d
				 INNER JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = %s AND v.meta_value = %s
				 LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_epc_status'
				 WHERE p.post_type = %s AND p.post_status = 'publish' AND ( s.meta_value IS NULL OR s.meta_value <> 'cancelled' ) LIMIT 1",
				$event_id,
				'f_' . $name,
				$value,
				EPC_Post_Types::APPLICANT
			)
		);
	}

	/**
	 * Persist an applicant. Also used by the importer.
	 */
	public static function store( int $event_id, array $data, array $fields, string $status = 'registered', array $context = array(), string $date = '' ): int {
		$title = self::applicant_title( $data );
		$args  = array(
			'post_type'   => EPC_Post_Types::APPLICANT,
			'post_status' => 'publish',
			'post_title'  => $title ?: 'Applicant',
		);
		if ( $date ) {
			$args['post_date'] = $date;
		}
		$id = wp_insert_post( $args, true );
		if ( is_wp_error( $id ) ) {
			return 0;
		}
		$labels = array();
		foreach ( $fields as $f ) {
			$labels[ $f['name'] ] = wp_strip_all_tags( $f['label'] ) ?: $f['name'];
		}
		update_post_meta( $id, '_epc_event_id', $event_id );
		update_post_meta( $id, '_epc_status', $status );
		update_post_meta( $id, '_epc_data', $data );
		update_post_meta( $id, '_epc_labels', $labels );
		foreach ( $data as $k => $v ) {
			update_post_meta( $id, 'f_' . $k, is_array( $v ) ? implode( ', ', $v ) : $v );
		}
		if ( ! empty( $context['epc_visitor'] ) ) {
			update_post_meta( $id, '_epc_visitor', sanitize_key( $context['epc_visitor'] ) );
		}
		if ( ! empty( $context['epc_source'] ) ) {
			$src = json_decode( (string) $context['epc_source'], true );
			if ( is_array( $src ) ) {
				update_post_meta( $id, '_epc_source', array_map( 'sanitize_text_field', array_slice( $src, 0, 10 ) ) );
			}
		}
		if ( ! $date ) {
			update_post_meta( $id, '_epc_ip_hash', wp_hash( epc_client_ip() ) );
		}
		return (int) $id;
	}

	public static function applicant_title( array $data ): string {
		$parts = array();
		foreach ( array( 'salutation', 'fullname', 'full-name', 'name', 'first-name', 'last-name' ) as $k ) {
			if ( ! empty( $data[ $k ] ) && is_string( $data[ $k ] ) ) {
				$parts[] = $data[ $k ];
			}
		}
		if ( count( $parts ) <= 1 ) {
			foreach ( $data as $k => $v ) {
				if ( is_string( $v ) && ( str_contains( $k, 'name' ) ) && ! in_array( $v, $parts, true ) ) {
					$parts[] = $v;
					break;
				}
			}
		}
		if ( ! $parts && ! empty( $data['email'] ) ) {
			$parts[] = $data['email'];
		}
		return trim( (string) preg_replace( '/\bOthers: /', '', implode( ' ', $parts ) ) );
	}

}
