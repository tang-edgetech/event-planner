<?php
/**
 * Content access tab: default rule per public post type, the default "restricted" message,
 * and overviews of terms and items that carry their own rules.
 *
 * Screens: Users → Roles & Permissions → Content access.
 */

defined( 'ABSPATH' ) || exit;

class TMG_Roles_Screen_Content {

	public static function init(): void {
		add_action( 'admin_post_tmg_roles_content', array( __CLASS__, 'handle_save' ) );
	}

	public static function render(): void {
		$defaults = (array) TMG_Roles_Settings::get( 'content_defaults' );
		?>
		<p class="description"><?php esc_html_e( 'Who may view content on the front end. An item\'s own rule (the "Who can view" box in the editor) wins; otherwise rules of its terms apply (all must pass); otherwise the default of its post type below. Restricted items are also hidden from archives, search, feeds, the REST API and sitemaps for those who may not view them. People who can edit an item and administrators always see it.', 'tamago-roles' ); ?></p>
		<?php TMG_Roles_Admin_Screen::form_open( 'tmg_roles_content' ); ?>
			<h2><?php esc_html_e( 'Defaults per post type', 'tamago-roles' ); ?></h2>
			<div class="tmg-roles-columns">
			<?php foreach ( TMG_Roles_Content_Admin::post_types() as $type ) : ?>
				<?php
				$obj  = get_post_type_object( $type );
				$rule = isset( $defaults[ $type ] ) ? TMG_Roles_Content_Access::sanitize_rule( (array) $defaults[ $type ] ) : array_merge( TMG_Roles_Content_Access::empty_rule(), array( 'mode' => 'everyone', 'action' => 'login' ) );
				?>
				<div class="tmg-card">
					<h3><?php echo esc_html( $obj ? $obj->labels->name : $type ); ?> <code><?php echo esc_html( $type ); ?></code></h3>
					<?php TMG_Roles_Content_Admin::rule_fields( 'defaults[' . $type . ']', $rule, false ); ?>
				</div>
			<?php endforeach; ?>
			</div>
			<h2><?php esc_html_e( 'Default message', 'tamago-roles' ); ?></h2>
			<p><textarea name="content_message" rows="3" class="large-text" <?php disabled( TMG_Roles_Settings::is_locked( 'content_message' ) ); ?>><?php echo esc_textarea( (string) TMG_Roles_Settings::get( 'content_message' ) ); ?></textarea></p>
			<?php echo TMG_Roles_Admin_Screen::locked_note( 'content_message' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<p class="description"><?php esc_html_e( 'Shown when the action is "Show a message" and the item has no message of its own. Empty = a built-in text with a login link.', 'tamago-roles' ); ?></p>
			<p class="submit"><button class="button button-primary"><?php esc_html_e( 'Save content access', 'tamago-roles' ); ?></button></p>
		</form>

		<h2><?php esc_html_e( 'Terms with rules', 'tamago-roles' ); ?></h2>
		<?php
		$terms = TMG_Roles_Content_Access::term_rules();
		if ( ! $terms ) {
			echo '<p class="description">' . esc_html__( 'None. Edit a category, tag or other public term to add one.', 'tamago-roles' ) . '</p>';
		} else {
			echo '<ul class="ul-disc">';
			foreach ( $terms as $id => $rule ) {
				$term = get_term( $id );
				if ( $term && ! is_wp_error( $term ) ) {
					echo '<li><a href="' . esc_url( (string) get_edit_term_link( $term ) ) . '">' . esc_html( $term->name ) . '</a> <code>' . esc_html( $term->taxonomy ) . '</code> — ' . esc_html( self::describe( $rule ) ) . '</li>';
				}
			}
			echo '</ul>';
		}
		?>
		<h2><?php esc_html_e( 'Items with their own rule', 'tamago-roles' ); ?></h2>
		<?php
		$posts = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => 100,
				'meta_key'       => TMG_Roles_Content_Access::META, // phpcs:ignore WordPress.DB.SlowDBQuery
				'tmg_roles_skip' => true,
			)
		);
		if ( ! $posts ) {
			echo '<p class="description">' . esc_html__( 'None.', 'tamago-roles' ) . '</p>';
		} else {
			echo '<ul class="ul-disc">';
			foreach ( $posts as $p ) {
				echo '<li><a href="' . esc_url( (string) get_edit_post_link( $p->ID ) ) . '">' . esc_html( get_the_title( $p ) ) . '</a> <code>' . esc_html( $p->post_type ) . '</code> — ' . esc_html( self::describe( TMG_Roles_Content_Access::post_rule( $p->ID ) ) ) . '</li>';
			}
			echo '</ul>';
		}
		echo '<p class="description">' . esc_html__( 'Menu items: set "Visible to" on each item in Appearance → Menus. Inside content: [tamago_restrict roles="editor,author" logged_in="yes"]…[/tamago_restrict].', 'tamago-roles' ) . '</p>';
	}

	private static function describe( array $rule ): string {
		$modes   = TMG_Roles_Content_Access::modes();
		$actions = TMG_Roles_Content_Access::actions();
		$out     = $modes[ $rule['mode'] ] ?? $rule['mode'];
		if ( 'roles' === $rule['mode'] ) {
			$out .= ' (' . implode( ', ', $rule['roles'] ) . ')';
		}
		return $out . ' · ' . ( $actions[ $rule['action'] ] ?? $rule['action'] );
	}

	public static function handle_save(): void {
		TMG_Roles_Admin_Screen::guard( 'tmg_roles_content' );
		// phpcs:disable WordPress.Security.NonceVerification -- guarded above.
		$in  = (array) wp_unslash( $_POST['defaults'] ?? array() );
		$msg = wp_kses_post( wp_unslash( $_POST['content_message'] ?? '' ) );
		// phpcs:enable
		$defaults = array();
		foreach ( TMG_Roles_Content_Admin::post_types() as $type ) {
			if ( isset( $in[ $type ] ) ) {
				$defaults[ $type ] = TMG_Roles_Content_Access::sanitize_rule( (array) $in[ $type ] );
			}
		}
		TMG_Roles_Settings::update( array( 'content_defaults' => $defaults, 'content_message' => $msg ), 'content_defaults' );
		TMG_Roles_Content_Access::flush();
		TMG_Roles_Admin_Screen::back( 'content', array(), __( 'Content access saved.', 'tamago-roles' ) );
	}
}
