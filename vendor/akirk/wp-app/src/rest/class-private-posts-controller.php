<?php
/**
 * Posts controller that requires authentication for REST reads.
 *
 * Identical to the core posts controller except that the read-permission
 * checks defer to {@see Access}, so anonymous callers can no longer pull
 * published posts of an app-owned type over `/wp/v2/<type>`. Create/update/
 * delete keep the core capability checks unchanged, and the block editor —
 * which reads as a logged-in user — is unaffected.
 *
 * @package WpApp
 */

namespace WpApp\Rest;

if ( class_exists( 'WpApp\Rest\Private_Posts_Controller' ) ) {
	return;
}

// Parent is loaded by wp-settings.php before plugins, so this is safe at
// plugin-load time; guard anyway for non-standard boot orders.
if ( ! class_exists( 'WP_REST_Posts_Controller' ) ) {
	return;
}

class Private_Posts_Controller extends \WP_REST_Posts_Controller {

	/**
	 * Require a capable/logged-in caller before listing items.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return true|\WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		$gate = Access::guard_post_type_collection( $this->post_type, $request );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		return parent::get_items_permissions_check( $request );
	}

	/**
	 * Require a capable/logged-in caller before reading a single item.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return true|\WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		$gate = Access::guard_post_type_item( $this->post_type, $request );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		return parent::get_item_permissions_check( $request );
	}
}
