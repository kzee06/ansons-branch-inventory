<?php
/**
 * Database schema and migrations.
 *
 * @package ORDDD_Branch_Pickup_Inventory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database handler.
 */
class BPI_Database {

	/**
	 * Table name without prefix.
	 */
	const TABLE = 'bpi_branch_inventory';

	/**
	 * Option key for store code mapping.
	 */
	const STORE_CODES_OPTION = 'bpi_store_code_map';

	/**
	 * Plugin settings option.
	 */
	const SETTINGS_OPTION = 'bpi_settings';

	/**
	 * Last import log option.
	 */
	const IMPORT_LOG_OPTION = 'bpi_last_import_log';

	/**
	 * Activate plugin — create table.
	 *
	 * @return void
	 */
	public static function activate() {
		global $wpdb;

		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			product_id bigint(20) unsigned NOT NULL DEFAULT 0,
			sku varchar(191) NOT NULL DEFAULT '',
			branch_id varchar(64) NOT NULL DEFAULT '',
			status varchar(32) NOT NULL DEFAULT 'out_of_stock',
			qty int(11) DEFAULT NULL,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY sku_branch (sku, branch_id),
			KEY product_branch (product_id, branch_id),
			KEY branch_id (branch_id)
		) {$charset_collate};";

		dbDelta( $sql );

		if ( false === get_option( self::SETTINGS_OPTION ) ) {
			add_option(
				self::SETTINGS_OPTION,
				array(
					'block_checkout'  => 'yes',
					'show_on_product' => 'yes',
				)
			);
		}

		if ( false === get_option( self::STORE_CODES_OPTION ) ) {
			add_option( self::STORE_CODES_OPTION, array() );
		}
	}

	/**
	 * Deactivate — nothing destructive.
	 *
	 * @return void
	 */
	public static function deactivate() {
		// Intentionally empty.
	}

	/**
	 * Full table name.
	 *
	 * @return string
	 */
	public static function get_table_name() {
		global $wpdb;

		return $wpdb->prefix . self::TABLE;
	}
}
