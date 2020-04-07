<?php

namespace Automattic\VIP\Search\Queue;

class Schema {
	const TABLE_SUFFIX = 'vip_search_index_queue';

	const DB_VERSION        = 1;
	const DB_VERSION_OPTION = 'vip_search_queue_db_version';
	const TABLE_CREATE_LOCK = 'vip_search_queue_creating_table';

	public function init() {
		$this->setup_hooks();
	}

	public function setup_hooks() {
		// Create tables during installation.
		add_action( 'wp_install', array( $this, 'create_table_during_install' ) );
		add_action( 'wpmu_new_blog', array( $this, 'create_tables_during_multisite_install' ) );

		// Remove table when a multisite subsite is deleted.
		add_filter( 'wpmu_drop_tables', array( $this, 'remove_multisite_table' ) );

		if ( ! $this->is_installed() ) {
			// In limited circumstances, try creating the table.
			add_action( 'shutdown', array( $this, 'maybe_create_table_on_shutdown' ) );
		}
	}

	public function is_installed() {
		$db_version = (int) get_option( self::DB_VERSION_OPTION );

		return version_compare( $db_version, 0, '>' );
	}

	/**
	 * Create table during initial install
	 */
	public function create_table_during_install() {
		if ( 'wp_install' !== current_action() ) {
			return;
		}

		$this->_prepare_table();
	}

	/**
	 * Create table when new subsite is added to a multisite
	 *
	 * @param int $bid Blog ID.
	 */
	public function create_tables_during_multisite_install( $bid ) {
		switch_to_blog( $bid );

		if ( ! self::is_installed() ) {
			$this->_prepare_table();
		}

		restore_current_blog();
	}

	/**
	 * When deleting a subsite from a multisite instance, include the plugin's table
	 *
	 * Core only drops its tables
	 *
	 * @param  array $tables_to_drop Array of prefixed table names to drop.
	 * @return array
	 */
	public function remove_multisite_table( $tables_to_drop ) {
		$tables_to_drop[] = $this->get_table_name();

		return $tables_to_drop;
	}

	/**
	 * For certain requests, create the table on shutdown
	 * Does not include front-end requests
	 */
	public function maybe_create_table_on_shutdown() {
		if ( ! is_admin() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		$this->prepare_table();
	}

	/**
	 * Create table in non-setup contexts, with some protections
	 */
	public function prepare_table() {
		// Table installed and current version
		if ( self::is_installed() ) {
			return;
		}

		// Limit chance of race conditions when creating table.
		$create_lock_set = wp_cache_add( self::TABLE_CREATE_LOCK, 1, null, 1 * \MINUTE_IN_SECONDS );

		if ( false === $create_lock_set ) {
			return;
		}

		$this->_prepare_table();
	}

	/**
	 * Create the plugin's DB table when necessary
	 */
	protected function _prepare_table() {
		// Use Core's method of creating/updating tables.
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . '/wp-admin/includes/upgrade.php';
		}

		global $wpdb;

		$table_name = $this->get_table_name();

		// Define schema and create the table.
		$schema = "CREATE TABLE `{$table_name}` (
			`id` bigint(20) NOT NULL AUTO_INCREMENT,
			`object_id` int(11) DEFAULT NULL COMMENT 'WP object ID',
			`object_type` varchar(45) DEFAULT NULL COMMENT 'WP object type',
			`priority` int(11) DEFAULT NULL COMMENT 'Relative priority for this item compared to others (of any object_type)',
			`start_time` datetime DEFAULT NULL COMMENT 'Datetime when the item can be indexed (but not before) - used for debouncing',
			`status` varchar(45) NOT NULL COMMENT 'Status of the indexing job',
			`queued_time` datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			UNIQUE KEY `unique_object_and_status` (`object_id`,`object_type`,`status`)
		) ENGINE=InnoDB AUTO_INCREMENT=39 DEFAULT CHARSET=utf8\n";

		dbDelta( $schema, true );

		// Confirm that the table was created, and set the option to prevent further updates.
		$table_count = count( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) );

		if ( 1 === $table_count ) {
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		}
	}

	/**
	 * Build appropriate table name for this install
	 */
	public function get_table_name() {
		global $wpdb;

		return $wpdb->prefix . self::TABLE_SUFFIX;
	}
}
