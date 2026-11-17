<?php
/**
 * Editing content access rules: the "Who can view" meta box on every public post type and the
 * matching fields on term edit screens of public taxonomies.
 *
 * Screens: post editor (classic and block editor sidebar meta box), Posts → Categories / any public taxonomy term.
 */

defined( 'ABSPATH' ) || exit;

class TMG_Roles_Content_Admin {

	public static function init(): void {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post', array( __CLASS__, 'save_post' ), 10, 2 );
		add_action( 'admin_init', array( __CLASS__, 'term_hooks' ) );
	}

	public static function post_types(): array {
		return array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );
	}

	public static function taxonomies(): array {
		return array_values( get_taxonomies( array( 'public' => true ) ) );
	}

	public static function add_meta_box(): void {
		foreach ( self::post_types() as $type ) {
			add_meta_box( 'tmg-roles-access', __( 'Who can view', 'tamago-roles' ), array( __CLASS__, 'render_meta_box' ), $type, 'side', 'default' );
		}
	}

	/**
	 * Shared fields for a rule.
	 *
	 * @param string $name     Input name prefix.
	 * @param array  $rule     Current rule.
	 * @param bool   $inherit  Offer the "Default" choices.
	 */
	public static function rule_fields( string $name, array $rule, bool $inherit = true ): void {
		$modes   = TMG_Roles_Content_Access::modes();
		$actions = TMG_Roles_Content_Access::actions();
		if ( ! $inherit ) {
			unset( $modes['inherit'], $actions['inherit'] );
			$modes['everyone'] = __( 'Everyone (no restriction)', 'tamago-roles' );
		}
		$roles = TMG_Roles_Ranks::sorted_roles();
		?>
		<div class="tmg-roles-rule">
			<p><label><strong><?php esc_html_e( 'Who may view', 'tamago-roles' ); ?></strong><br>
				<select name="<?php echo esc_attr( $name ); ?>[mode]" class="tmg-roles-mode">
					<?php foreach ( $modes as $k => $l ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $rule['mode'], $k ); ?>><?php echo esc_html( $l ); ?></option>
					<?php endforeach; ?>
				</select></label></p>
			<fieldset class="tmg-roles-roles"><legend><?php esc_html_e( 'Roles', 'tamago-roles' ); ?></legend>
				<?php foreach ( $roles as $k => $l ) : ?>
					<label style="display:block"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[roles][]" value="<?php echo esc_attr( $k ); ?>" <?php checked( in_array( $k, (array) $rule['roles'], true ) ); ?>> <?php echo esc_html( $l ); ?></label>
				<?php endforeach; ?>
			</fieldset>
			<p><label><strong><?php esc_html_e( 'Everyone else gets', 'tamago-roles' ); ?></strong><br>
				<select name="<?php echo esc_attr( $name ); ?>[action]">
					<?php foreach ( $actions as $k => $l ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $rule['action'], $k ); ?>><?php echo esc_html( $l ); ?></option>
					<?php endforeach; ?>
				</select></label></p>
			<p><label><?php esc_html_e( 'Message (optional)', 'tamago-roles' ); ?><br><textarea name="<?php echo esc_attr( $name ); ?>[message]" rows="3" class="widefat"><?php echo esc_textarea( $rule['message'] ); ?></textarea></label></p>
			<p><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[show_excerpt]" value="1" <?php checked( ! empty( $rule['show_excerpt'] ) ); ?>> <?php esc_html_e( 'Show the excerpt above the message', 'tamago-roles' ); ?></label></p>
			<p><label><?php esc_html_e( 'Redirect URL', 'tamago-roles' ); ?><br><input type="url" class="widefat" name="<?php echo esc_attr( $name ); ?>[redirect]" value="<?php echo esc_attr( $rule['redirect'] ); ?>"></label></p>
		</div>
		<?php
	}

	public static function render_meta_box( WP_Post $post ): void {
		wp_nonce_field( 'tmg_roles_access_' . $post->ID, 'tmg_roles_access_nonce' );
		self::rule_fields( 'tmg_roles_access', TMG_Roles_Content_Access::post_rule( $post->ID ) );
		echo '<p class="description">' . esc_html__( 'People who can edit this item and administrators always see it.', 'tamago-roles' ) . '</p>';
	}

	public static function save_post( int $post_id, WP_Post $post ): void {
		if ( ! isset( $_POST['tmg_roles_access_nonce'] ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tmg_roles_access_nonce'] ) ), 'tmg_roles_access_' . $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$rule = TMG_Roles_Content_Access::sanitize_rule( (array) wp_unslash( $_POST['tmg_roles_access'] ?? array() ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized by sanitize_rule.
		self::store( 'post', $post_id, $rule );
	}

	/** Save (or remove, when it is all defaults) a post or term rule. */
	public static function store( string $kind, int $id, array $rule ): void {
		$empty = TMG_Roles_Content_Access::empty_rule();
		$old   = 'post' === $kind ? get_post_meta( $id, TMG_Roles_Content_Access::META, true ) : get_term_meta( $id, TMG_Roles_Content_Access::META, true );
		if ( $rule === $empty ) {
			if ( 'post' === $kind ) {
				delete_post_meta( $id, TMG_Roles_Content_Access::META );
			} else {
				delete_term_meta( $id, TMG_Roles_Content_Access::META );
			}
		} elseif ( 'post' === $kind ) {
			update_post_meta( $id, TMG_Roles_Content_Access::META, $rule );
		} else {
			update_term_meta( $id, TMG_Roles_Content_Access::META, $rule );
		}
		TMG_Roles_Content_Access::flush();
		if ( ( $old ? $old : $empty ) !== $rule ) {
			TMG_Roles_Logger::log( 'content_rule', $kind . ':' . $id, array( 'mode' => $rule['mode'], 'roles' => $rule['roles'], 'action' => $rule['action'] ) );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Terms                                                               */
	/* ------------------------------------------------------------------ */

	public static function term_hooks(): void {
		foreach ( self::taxonomies() as $tax ) {
			add_action( "{$tax}_edit_form_fields", array( __CLASS__, 'term_fields' ), 20, 2 );
			add_action( "edited_{$tax}", array( __CLASS__, 'save_term' ), 10, 1 );
		}
	}

	public static function term_fields( $term, $taxonomy ): void {
		if ( ! current_user_can( TMG_Roles::CAP_MANAGE ) ) {
			return;
		}
		?>
		<tr class="form-field">
			<th scope="row"><?php esc_html_e( 'Who can view posts in this term', 'tamago-roles' ); ?></th>
			<td>
				<?php wp_nonce_field( 'tmg_roles_term_' . $term->term_id, 'tmg_roles_term_nonce' ); ?>
				<?php self::rule_fields( 'tmg_roles_access', TMG_Roles_Content_Access::term_rule( (int) $term->term_id ) ); ?>
				<p class="description"><?php esc_html_e( 'Applies to posts in this term that have no rule of their own.', 'tamago-roles' ); ?></p>
			</td>
		</tr>
		<?php
	}

	public static function save_term( int $term_id ): void {
		if ( ! isset( $_POST['tmg_roles_term_nonce'] ) || ! current_user_can( TMG_Roles::CAP_MANAGE ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tmg_roles_term_nonce'] ) ), 'tmg_roles_term_' . $term_id ) ) {
			return;
		}
		$rule = TMG_Roles_Content_Access::sanitize_rule( (array) wp_unslash( $_POST['tmg_roles_access'] ?? array() ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		self::store( 'term', $term_id, $rule );
	}
}
