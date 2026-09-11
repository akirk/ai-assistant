<?php
/**
 * Base Storage Class
 *
 * Abstract base class for database storage with common functionality.
 * Provides wpdb access, schema management, and utility methods for WordPress applications.
 *
 * Child classes should implement get_schema() to define their database tables.
 * Call create_tables() in your plugin activation hook to create/update tables.
 *
 * @package WpApp
 */

namespace WpApp;

if ( class_exists( 'WpApp\BaseStorage' ) ) {
    return;
}

abstract class BaseStorage {
	/**
	 * WordPress database instance
	 *
	 * @var \wpdb
	 */
	protected $wpdb;

	/**
	 * Constructor
	 *
	 * @param \wpdb|null $wpdb_instance WordPress database instance. If null, uses global $wpdb.
	 */
	public function __construct( $wpdb_instance = null ) {
		global $wpdb;

		if ( $wpdb_instance ) {
			$this->wpdb = $wpdb_instance;
		} else {
			$this->wpdb = $wpdb;
		}
	}

	/**
	 * Get the database schema.
	 *
	 * Child classes return their tables keyed by unprefixed table name, with
	 * the column and key definitions as the value; create_tables() wraps each
	 * in CREATE TABLE with the site's charset and prefix:
	 *
	 *     return [
	 *         'my_items' => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	 *             title varchar(255) NOT NULL,
	 *             PRIMARY KEY  (id)",
	 *     ];
	 *
	 * @return array<string,string> Column definitions keyed by table name.
	 */
	abstract protected function get_schema();

	/**
	 * Create or update database tables using dbDelta
	 *
	 * This method should be called in your plugin activation hook.
	 * It will create tables if they don't exist, or update them if the schema changed.
	 *
	 * @return string[] Array of strings containing text info about the upgrade.
	 */
	public function create_tables() {
		$schema          = $this->get_schema();
		$queries         = [];
		$charset_collate = $this->wpdb->get_charset_collate();

		foreach ( $schema as $table_name => $columns ) {
			$full_table_name = $this->wpdb->prefix . $table_name;
			$queries[]       = "CREATE TABLE $full_table_name (\n$columns\n) $charset_collate;";
		}

		return $this->dbdelta( $queries );
	}

	/**
	 * Execute dbDelta to create or update tables
	 *
	 * @param string[] $queries SQL queries to execute.
	 * @param bool            $execute Whether to execute the queries (default true).
	 * @return string[] Array of strings containing text info about the upgrade.
	 */
	public function dbdelta( $queries, $execute = true ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		return dbDelta( $queries, $execute );
	}

	/**
	 * Get wpdb instance for custom queries
	 *
	 * @return \wpdb
	 */
	public function get_wpdb() {
		return $this->wpdb;
	}

	/**
	 * Get table name with WordPress prefix
	 *
	 * @param string $table_name Table name without prefix.
	 * @return string Full table name with prefix.
	 */
	protected function get_table_name( $table_name ) {
		return $this->wpdb->prefix . $table_name;
	}
}
