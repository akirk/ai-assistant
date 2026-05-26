<?php
/**
 * Base App Abstract Class
 *
 * Provides a structured pattern for building WordPress applications with WpApp.
 * This follows the personal-crm pattern with separate storage and app instances.
 *
 * @package WpApp
 */

namespace WpApp;

if ( class_exists( 'WpApp\BaseApp' ) ) {
	return;
}

abstract class BaseApp {
	/**
	 * WpApp instance
	 *
	 * @var WpApp
	 */
	protected $app;

	/**
	 * Storage instance extending BaseStorage
	 *
	 * @var BaseStorage
	 */
	protected $storage;

	/**
	 * Initialize the application
	 *
	 * Call this method to set up routes, menu, database, and initialize WpApp.
	 */
	public function init() {
		$this->setup_database();
		$this->setup_routes();
		$this->setup_menu();

		$this->app->init();

		do_action( 'base_app_initialized', $this );
	}

	/**
	 * Set up database tables
	 *
	 * Use WordPress dbDelta() function to create/update tables.
	 * Tables should be created in the activation hook.
	 *
	 * Example:
	 *   global $wpdb;
	 *   require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	 *   $charset_collate = $wpdb->get_charset_collate();
	 *   $sql = "CREATE TABLE {$wpdb->prefix}my_table (
	 *       id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	 *       name varchar(255) NOT NULL,
	 *       PRIMARY KEY (id)
	 *   ) $charset_collate;";
	 *   dbDelta( $sql );
	 */
	abstract protected function setup_database();

	/**
	 * Set up application routes
	 *
	 * Define URL patterns and their corresponding templates.
	 *
	 * Example:
	 *   $this->app->route( '' );
	 *   $this->app->route( 'dashboard' );
	 *   $this->app->route( 'user/{user_id}' );
	 */
	abstract protected function setup_routes();

	/**
	 * Set up admin bar menu items
	 *
	 * Add navigation items to the WordPress admin bar.
	 *
	 * Example:
	 *   $this->app->add_menu_item( 'dashboard', 'Dashboard', home_url( '/my-app/dashboard' ) );
	 *   $this->app->add_user_menu_item( 'profile', 'My Profile', home_url( '/my-app/profile' ) );
	 */
	abstract protected function setup_menu();

	/**
	 * Get the WpApp instance
	 *
	 * @return WpApp
	 */
	public function get_app() {
		return $this->app;
	}

	/**
	 * Get the Storage instance
	 *
	 * @return BaseStorage|null
	 */
	public function get_storage() {
		return $this->storage;
	}
}
