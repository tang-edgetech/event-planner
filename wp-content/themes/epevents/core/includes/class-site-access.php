<?php
/**
 * Site access: "Direct links only" (default) shows a maintenance page on the homepage and on every
 * other public URL, while event forms (/event/…) and microsites (/e/…) stay reachable by their link.
 * Nothing lists them publicly: no homepage list, search, archives, feeds, sitemaps or public REST listing.
 *
 * Settings: Event Plus → Settings → Site access. Page: the theme's `maintenance.php` (a child theme can override it).
 */

defined( 'ABSPATH' ) || exit;

class EPC_Site_Access {

	public static function init(): void {
		add_action( 'template_redirect', array( __CLASS__, 'gate' ), 1 );
		add_filter( 'template_include', array( __CLASS__, 'template' ), 99 );
		add_filter( 'wp_sitemaps_enabled', array( __CLASS__, 'sitemaps' ) );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'rest' ), 10, 3 );
		add_action( 'pre_get_posts', array( __CLASS__, 'exclude_from_search' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 90 );
		// WordPress's "did you mean" redirects (/a → first slug starting with a) would reveal hidden links.
		add_filter( 'do_redirect_guess_404_permalink', fn( $guess ) => self::is_private() ? false : $guess );
	}

	public static function is_private(): bool {
		return 'public' !== epc_setting( 'epc_access_mode', 'private' );
	}

	/** Is the current front-end request allowed to show its normal content? */
	public static function allowed(): bool {
		if ( ! self::is_private() ) {
			return true;
		}
		if ( is_singular( array( EPC_Post_Types::EVENT, EPC_Post_Types::MICROSITE ) ) ) {
			// Only by its real link: /?p=123 style lookups would let anyone walk through every ID.
			return ! self::looked_up_by_id() || current_user_can( 'edit_post', get_queried_object_id() );
		}
		if ( is_singular( 'post' ) && epc_setting( 'epc_access_posts', true ) ) {
			return true; // News articles linked from microsite "News" sections.
		}
		if ( is_page() ) {
			$privacy = (int) get_option( 'wp_page_for_privacy_policy' );
			if ( ( $privacy && is_page( $privacy ) ) || epc_setting( 'epc_access_pages', false ) ) {
				return ! is_front_page();
			}
		}
		return false;
	}

	/** Was the post found by a numeric ID (?p=, ?page_id=, ?attachment_id=) rather than its link? */
	private static function looked_up_by_id(): bool {
		global $wp;
		foreach ( array( 'p', 'page_id', 'attachment_id' ) as $var ) {
			if ( ! empty( $wp->query_vars[ $var ] ) ) {
				return true;
			}
		}
		return false;
	}

	private static $maintenance = false;

	public static function gate(): void {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_robots() || is_favicon() ) {
			return;
		}
		if ( self::allowed() ) {
			return;
		}
		self::$maintenance = true;
		remove_action( 'template_redirect', 'redirect_canonical' ); // No redirects that reveal where an ID or slug leads.
		if ( is_feed() ) {
			wp_die( 'Not available.', 'Not available', array( 'response' => 404 ) );
		}
		// Real "not found" keeps its 404 code; everything else is a normal page that search engines skip.
		status_header( is_404() ? 404 : 200 );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );
		add_filter( 'wp_robots', fn( $r ) => array_merge( $r, array( 'noindex' => true, 'nofollow' => true ) ) );
		add_filter( 'body_class', fn( $c ) => array_merge( $c, array( 'is-maintenance' ) ) );
		add_filter( 'document_title_parts', fn( $t ) => array( 'title' => self::content()['title'], 'site' => get_bloginfo( 'name' ) ) );
	}

	public static function template( string $template ): string {
		if ( ! self::$maintenance ) {
			return $template;
		}
		return locate_template( 'maintenance.php' ) ?: get_template_directory() . '/maintenance.php';
	}

	/** Texts for the maintenance page (Settings → Site access). */
	public static function content(): array {
		return array(
			'title'   => (string) epc_setting( 'epc_maint_title', 'We will be back soon' ),
			'message' => (string) epc_setting( 'epc_maint_message', '<p>Our website is being updated. If you received a registration or event link, please open that link directly.</p>' ),
			'email'   => (string) epc_setting( 'epc_maint_email', '' ),
			'bg'      => (array) ( epc_setting( 'epc_maint_bg', array() ) ?: array() ),
		);
	}

	/** Sitemaps would list every event and microsite. */
	public static function sitemaps( bool $enabled ): bool {
		return self::is_private() ? false : $enabled;
	}

	/**
	 * Visitors cannot list or search content through the REST API (routes match case-insensitively,
	 * like WordPress itself). Staff signed in to Event Plus keep it for the editors.
	 */
	public static function rest( $result, WP_REST_Server $server, WP_REST_Request $request ) {
		if ( ! self::is_private() || null !== $result ) {
			return $result;
		}
		$route = $request->get_route();
		if ( preg_match( '#^/+wp/v2/+(ep_event|ep_microsite|search|media|users|posts|pages|comments|categories|tags|blocks|block-renderer)(/|$)#i', $route ) && ! current_user_can( 'epc_view_dashboard' ) ) {
			return new WP_Error( 'rest_no_route', 'No route was found matching the URL and request method.', array( 'status' => 404 ) );
		}
		return $result;
	}

	/** Events and microsites never appear in front-end search results. */
	public static function exclude_from_search( WP_Query $q ): void {
		if ( is_admin() || ! $q->is_main_query() || ! $q->is_search() ) {
			return;
		}
		$types = $q->get( 'post_type' );
		if ( empty( $types ) || 'any' === $types ) {
			$q->set( 'post_type', array( 'post', 'page' ) );
		}
	}

	public static function admin_bar( WP_Admin_Bar $bar ): void {
		if ( is_admin() || ! self::is_private() || ! current_user_can( 'epc_view_dashboard' ) ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'epc-access',
				'title' => '🔒 Direct links only',
				'href'  => current_user_can( 'epc_manage_settings' ) ? admin_url( 'admin.php?page=epc-settings' ) : false,
				'meta'  => array( 'title' => 'Visitors see the maintenance page everywhere except event forms and microsites opened by their link.' ),
			)
		);
	}
}
