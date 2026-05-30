<?php
/**
 * Uninstall cleanup.
 *
 * @package ORDDD_Branch_Pickup_Inventory
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$table = $wpdb->prefix . 'bpi_branch_inventory';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

delete_option( 'bpi_settings' );
delete_option( 'bpi_store_code_map' );
delete_option( 'bpi_last_import_log' );
