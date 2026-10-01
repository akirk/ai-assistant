<?php
/**
 * Terms controller that requires authentication for REST reads.
 *
 * The core terms controller exposes every term of a `show_in_rest` taxonomy to
 * anonymous callers (it only gates `context=edit`). This subclass defers the
 * read checks to {@see Access} so an app's term names — trip titles, place
 * lists, tags — are no longer world-readable. Term create/update/delete keep
 * the core capability checks.
 *
 * @package WpApp
 */

namespace WpApp\Rest;

if ( class_exists( 'WpApp\Rest\Private_Terms_Controller' ) ) {
	return;
}

if ( ! class_exists( 'WP_REST_Terms_Controller' ) ) {
	return;
}

class Private_Terms_Controller extends \WP_REST_Terms_Controller {

	/**
	 * Require a capable/logged-in caller before listing terms.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return true|\WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		$gate = Access::guard_taxonomy_collection( $this->taxonomy, $request );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		return parent::get_items_permissions_check( $request );
	}

	/**
	 * Require a capable/logged-in caller before reading a single term.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return true|\WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		$gate = Access::guard_taxonomy_item( $this->taxonomy, $request );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		return parent::get_item_permissions_check( $request );
	}
}
