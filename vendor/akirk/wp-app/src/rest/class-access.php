<?php
/**
 * REST read-access gate for app-owned post types and taxonomies.
 *
 * WordPress core serves any *published* post of a `show_in_rest` post type, and
 * every term of a `show_in_rest` taxonomy, to anonymous callers — it keys off
 * `show_in_rest` alone, never off `public`/`publicly_queryable`. A WpApp app
 * that gates its front end with `require_login`/`require_capability` therefore
 * still leaks its content over `/wp/v2/<type>` unless the REST layer is gated
 * too.
 *
 * Declare the app's REST-backed types with {@see Access::protect_post_type()} /
 * {@see Access::protect_taxonomy()}. Access records the capability each one needs
 * and, via `register_post_type_args`/`register_taxonomy_args`, swaps in the gated
 * controller ({@see Private_Posts_Controller}/{@see Private_Terms_Controller}) for
 * any declared type whose own `register_post_type()`/`register_taxonomy()` call
 * already sets `show_in_rest => true` — so that call needs no
 * `rest_controller_class` of its own. Declaring a type never turns
 * `show_in_rest` on by itself: a type that doesn't ask for REST support stays
 * fully closed, exactly as if it had never been declared. Call `protect_*`
 * *before* the matching `register_*` (both run on `init`).
 *
 * Single-item reads are checked with the object id, so a WordPress *meta*
 * capability (e.g. `read_post`, or a custom `read_my_thing` mapped via
 * `map_meta_cap`) is honoured per object — ownership and share-token rules that
 * the app already expresses in its capability mapping apply to REST too. A
 * collection has no single id, so it is checked with a separate, coarser
 * capability (defaults to the item capability; pass an explicit collection
 * capability when the item capability is a meta cap that needs an object).
 *
 * Anonymous reads get a 401; a logged-in user without the capability gets a 403;
 * the block editor (which reads as a logged-in user) keeps working. Deliberate
 * public reads (e.g. a share-token link) can also opt back in via the
 * `wp_app_rest_public_read` filter.
 *
 * @package WpApp
 */

namespace WpApp\Rest;

if ( class_exists( 'WpApp\Rest\Access' ) ) {
	return;
}

class Access {
	/**
	 * Post type => [ 'item' => cap|null, 'collection' => cap|null ].
	 *
	 * @var array<string,array<string,string|null>>
	 */
	private static $post_type_caps = [];

	/**
	 * Taxonomy => [ 'item' => cap|null, 'collection' => cap|null ].
	 *
	 * @var array<string,array<string,string|null>>
	 */
	private static $taxonomy_caps = [];

	/**
	 * Whether the register_*_args filters have been attached.
	 *
	 * @var bool
	 */
	private static $filters_hooked = false;

	/**
	 * Gate REST reads of a post type and return the controller class to use.
	 *
	 * @param string      $post_type             Post type key.
	 * @param string|null $capability            Capability required to read a single
	 *                                            item. Checked with the object id, so a
	 *                                            meta cap is honoured per object. Null
	 *                                            requires only a logged-in user.
	 * @param string|null $collection_capability Capability required to read the
	 *                                            collection (no id). Defaults to
	 *                                            $capability; pass a coarser primitive
	 *                                            cap when $capability is a meta cap.
	 * @return string Controller class name.
	 */
	public static function protect_post_type( $post_type, $capability = null, $collection_capability = null ) {
		self::$post_type_caps[ $post_type ] = self::normalize_caps( $capability, $collection_capability );
		self::ensure_controllers_loaded();
		self::hook_filters();

		return Private_Posts_Controller::class;
	}

	/**
	 * Gate REST reads of a taxonomy and return the controller class to use.
	 *
	 * @param string      $taxonomy              Taxonomy key.
	 * @param string|null $capability            Capability required to read a single term.
	 * @param string|null $collection_capability Capability required to read the term list.
	 * @return string Controller class name.
	 */
	public static function protect_taxonomy( $taxonomy, $capability = null, $collection_capability = null ) {
		self::$taxonomy_caps[ $taxonomy ] = self::normalize_caps( $capability, $collection_capability );
		self::ensure_controllers_loaded();
		self::hook_filters();

		return Private_Terms_Controller::class;
	}

	/**
	 * Normalize item/collection capabilities.
	 *
	 * @param string|null $capability            Item capability.
	 * @param string|null $collection_capability Collection capability (defaults to item).
	 * @return array{item:string|null,collection:string|null}
	 */
	private static function normalize_caps( $capability, $collection_capability ) {
		return [
			'item'       => $capability,
			'collection' => ( null !== $collection_capability ) ? $collection_capability : $capability,
		];
	}

	/**
	 * Attach the argument filters that inject the gated controllers.
	 */
	private static function hook_filters() {
		if ( self::$filters_hooked || ! function_exists( 'add_filter' ) ) {
			return;
		}

		add_filter( 'register_post_type_args', [ __CLASS__, 'filter_post_type_args' ], 10, 2 );
		add_filter( 'register_taxonomy_args', [ __CLASS__, 'filter_taxonomy_args' ], 10, 2 );
		self::$filters_hooked = true;
	}

	/**
	 * Inject the gated controller for a protected post type that already
	 * asks for REST support.
	 *
	 * Never turns `show_in_rest` on: that decision belongs to the
	 * `register_post_type()` call. A type declared here but registered
	 * without `show_in_rest => true` stays fully closed to core REST, same
	 * as if it had never been declared — declaring it only matters once the
	 * app also opts the type into REST itself.
	 *
	 * @param array  $args      Post type arguments.
	 * @param string $post_type Post type key.
	 * @return array
	 */
	public static function filter_post_type_args( $args, $post_type ) {
		if ( ! array_key_exists( $post_type, self::$post_type_caps ) || empty( $args['show_in_rest'] ) ) {
			return $args;
		}

		if ( empty( $args['rest_controller_class'] ) ) {
			self::ensure_controllers_loaded();
			$args['rest_controller_class'] = Private_Posts_Controller::class;
		}

		return $args;
	}

	/**
	 * Inject the gated controller for a protected taxonomy that already asks
	 * for REST support. See {@see self::filter_post_type_args()} for why
	 * `show_in_rest` itself is never turned on here.
	 *
	 * @param array  $args     Taxonomy arguments.
	 * @param string $taxonomy Taxonomy key.
	 * @return array
	 */
	public static function filter_taxonomy_args( $args, $taxonomy ) {
		if ( ! array_key_exists( $taxonomy, self::$taxonomy_caps ) || empty( $args['show_in_rest'] ) ) {
			return $args;
		}

		if ( empty( $args['rest_controller_class'] ) ) {
			self::ensure_controllers_loaded();
			$args['rest_controller_class'] = Private_Terms_Controller::class;
		}

		return $args;
	}

	/**
	 * Item read-permission gate for a protected post type (checked with the id).
	 *
	 * @param string           $post_type Post type key.
	 * @param \WP_REST_Request $request   Current request.
	 * @return true|\WP_Error
	 */
	public static function guard_post_type_item( $post_type, $request ) {
		$caps = self::caps_for( self::$post_type_caps, $post_type );
		return self::guard( $caps['item'], $post_type, $request, self::request_object_id( $request ) );
	}

	/**
	 * Collection read-permission gate for a protected post type (no id).
	 *
	 * @param string           $post_type Post type key.
	 * @param \WP_REST_Request $request   Current request.
	 * @return true|\WP_Error
	 */
	public static function guard_post_type_collection( $post_type, $request ) {
		$caps = self::caps_for( self::$post_type_caps, $post_type );
		return self::guard( $caps['collection'], $post_type, $request, null );
	}

	/**
	 * Item read-permission gate for a protected taxonomy (checked with the id).
	 *
	 * @param string           $taxonomy Taxonomy key.
	 * @param \WP_REST_Request $request  Current request.
	 * @return true|\WP_Error
	 */
	public static function guard_taxonomy_item( $taxonomy, $request ) {
		$caps = self::caps_for( self::$taxonomy_caps, $taxonomy );
		return self::guard( $caps['item'], $taxonomy, $request, self::request_object_id( $request ) );
	}

	/**
	 * Collection read-permission gate for a protected taxonomy (no id).
	 *
	 * @param string           $taxonomy Taxonomy key.
	 * @param \WP_REST_Request $request  Current request.
	 * @return true|\WP_Error
	 */
	public static function guard_taxonomy_collection( $taxonomy, $request ) {
		$caps = self::caps_for( self::$taxonomy_caps, $taxonomy );
		return self::guard( $caps['collection'], $taxonomy, $request, null );
	}

	/**
	 * Item capability registered for a post type (or null for login-only).
	 *
	 * @param string $post_type Post type key.
	 * @return string|null
	 */
	public static function capability_for_post_type( $post_type ) {
		return self::caps_for( self::$post_type_caps, $post_type )['item'];
	}

	/**
	 * Item capability registered for a taxonomy (or null for login-only).
	 *
	 * @param string $taxonomy Taxonomy key.
	 * @return string|null
	 */
	public static function capability_for_taxonomy( $taxonomy ) {
		return self::caps_for( self::$taxonomy_caps, $taxonomy )['item'];
	}

	/**
	 * Look up the item/collection caps for a registered object.
	 *
	 * @param array  $registry Post-type or taxonomy registry.
	 * @param string $key      Object key.
	 * @return array{item:string|null,collection:string|null}
	 */
	private static function caps_for( $registry, $key ) {
		return isset( $registry[ $key ] ) ? $registry[ $key ] : [
			'item'       => null,
			'collection' => null,
		];
	}

	/**
	 * Resolve the object id from a single-item request, or null for a collection.
	 *
	 * @param \WP_REST_Request $request Current request.
	 * @return int|null
	 */
	private static function request_object_id( $request ) {
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_param' ) ) {
			return null;
		}

		$id = $request->get_param( 'id' );
		return $id ? (int) $id : null;
	}

	/**
	 * Shared gate: allow the read for a capable/logged-in caller (or a
	 * deliberate public opt-in), deny everyone else.
	 *
	 * @param string|null      $capability  Required capability, or null for login-only.
	 * @param string           $object_name Post type or taxonomy key (for the filter).
	 * @param \WP_REST_Request $request     Current request.
	 * @param int|null         $object_id   Object id for a single-item read, else null.
	 * @return true|\WP_Error
	 */
	private static function guard( $capability, $object_name, $request, $object_id = null ) {
		/**
		 * Allow an otherwise-gated app object to be read anonymously.
		 *
		 * Return true to permit the read — e.g. when the request carries a
		 * valid share token. Default false keeps app data private.
		 *
		 * @param bool             $allow       Whether to allow the anonymous read.
		 * @param string           $object_name Post type or taxonomy key.
		 * @param \WP_REST_Request $request     The REST request.
		 */
		if ( apply_filters( 'wp_app_rest_public_read', false, $object_name, $request ) ) {
			return true;
		}

		if ( $capability ) {
			$allowed = ( null !== $object_id )
				? current_user_can( $capability, $object_id )
				: current_user_can( $capability );
			if ( $allowed ) {
				return true;
			}
		} elseif ( is_user_logged_in() ) {
			return true;
		}

		return new \WP_Error(
			'wp_app_rest_forbidden',
			__( 'Authentication is required to read this data.', 'wp-app' ),
			[ 'status' => rest_authorization_required_code() ]
		);
	}

	/**
	 * Require the gated controller classes.
	 *
	 * They extend core REST controller classes that only exist once WordPress
	 * has loaded, so we defer the require until an app registers a protected
	 * type — which happens on `init`, after wp-settings.php has loaded the
	 * parent classes. Guarded so duplicate vendored copies do not redeclare.
	 */
	private static function ensure_controllers_loaded() {
		if ( ! class_exists( __NAMESPACE__ . '\Private_Posts_Controller', false ) ) {
			require_once __DIR__ . '/class-private-posts-controller.php';
		}
		if ( ! class_exists( __NAMESPACE__ . '\Private_Terms_Controller', false ) ) {
			require_once __DIR__ . '/class-private-terms-controller.php';
		}
	}

	/**
	 * Post types declared as app-owned via protect_post_type().
	 *
	 * @return string[] Post type keys.
	 */
	public static function get_protected_post_types() {
		return array_keys( self::$post_type_caps );
	}

	/**
	 * Reset all registrations (test helper).
	 */
	public static function reset() {
		self::$post_type_caps = [];
		self::$taxonomy_caps  = [];
		self::$filters_hooked = false;
	}
}
