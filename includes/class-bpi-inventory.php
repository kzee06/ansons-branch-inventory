<?php
/**
 * Branch inventory lookups and persistence.
 *
 * @package ORDDD_Branch_Pickup_Inventory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Inventory service.
 */
class BPI_Inventory {

	/**
	 * Valid status slugs.
	 */
	const STATUSES = array( 'in_stock', 'out_of_stock' );

	/**
	 * Plugin settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_settings() {
		$defaults = array(
			'block_checkout'  => 'yes',
			'show_on_product' => 'yes',
		);

		$settings = get_option( BPI_Database::SETTINGS_OPTION, array() );

		return wp_parse_args( is_array( $settings ) ? $settings : array(), $defaults );
	}

	/**
	 * Normalize status string.
	 *
	 * @param string $status Raw status.
	 * @return string
	 */
	public static function normalize_status( $status ) {
		$status = strtolower( trim( (string) $status ) );
		$status = str_replace( array( ' ', '-' ), '_', $status );

		if ( 'unknown' === $status ) {
			return 'unknown';
		}

		$aliases = array(
			'instock'        => 'in_stock',
			'in'             => 'in_stock',
			'available'      => 'in_stock',
			'yes'            => 'in_stock',
			'y'              => 'in_stock',
			'1'              => 'in_stock',
			'low_stock'      => 'in_stock',
			'low'            => 'in_stock',
			'limited'        => 'in_stock',
			'outofstock'     => 'out_of_stock',
			'not_available'  => 'out_of_stock',
			'out'            => 'out_of_stock',
			'unavailable'    => 'out_of_stock',
			'no'             => 'out_of_stock',
			'n'              => 'out_of_stock',
			'0'              => 'out_of_stock',
		);

		if ( isset( $aliases[ $status ] ) ) {
			$status = $aliases[ $status ];
		}

		if ( in_array( $status, self::STATUSES, true ) ) {
			return $status;
		}

		return 'out_of_stock';
	}

	/**
	 * Upsert one inventory row.
	 *
	 * @param string   $sku       Product SKU.
	 * @param string   $branch_id ORDDD row_id.
	 * @param string   $status    Availability status.
	 * @return bool
	 */
	public static function upsert_row( $sku, $branch_id, $status ) {
		global $wpdb;

		$sku       = wc_clean( $sku );
		$branch_id = wc_clean( $branch_id );
		$status    = self::normalize_status( $status );
		$product   = wc_get_product( wc_get_product_id_by_sku( $sku ) );
		$product_id = $product ? $product->get_id() : 0;

		if ( '' === $sku || '' === $branch_id ) {
			return false;
		}

		$table = BPI_Database::get_table_name();
		$now   = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->replace(
			$table,
			array(
				'product_id' => $product_id,
				'sku'        => $sku,
				'branch_id'  => $branch_id,
				'status'     => $status,
				'qty'        => null,
				'updated_at' => $now,
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s' )
		);

		return false !== $result;
	}

	/**
	 * Remove one SKU/branch inventory row.
	 *
	 * @param string $sku       Product SKU.
	 * @param string $branch_id ORDDD row_id.
	 * @return bool
	 */
	public static function delete_row( $sku, $branch_id ) {
		global $wpdb;

		$sku       = wc_clean( $sku );
		$branch_id = wc_clean( $branch_id );

		if ( '' === $sku || '' === $branch_id ) {
			return false;
		}

		$table = BPI_Database::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->delete(
			$table,
			array(
				'sku'       => $sku,
				'branch_id' => $branch_id,
			),
			array( '%s', '%s' )
		);

		return false !== $result;
	}

	/**
	 * Delete all inventory rows.
	 *
	 * @return void
	 */
	public static function truncate() {
		global $wpdb;

		$table = BPI_Database::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}

	/**
	 * Get availability for a product across branches.
	 *
	 * @param int $product_id Product ID.
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_product_availability( $product_id ) {
		global $wpdb;

		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return array();
		}

		$sku = $product->get_sku();

		if ( '' === $sku ) {
			return array();
		}

		$table = BPI_Database::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT branch_id, status, qty, updated_at FROM {$table} WHERE sku = %s",
				$sku
			),
			ARRAY_A
		);

		$branches = BPI_Branches::get_branches();
		$result   = array();

		foreach ( $branches as $branch_id => $branch ) {
			$result[ $branch_id ] = array(
				'branch_id'  => $branch_id,
				'label'      => $branch['label'],
				'status'     => 'unknown',
				'qty'        => null,
				'updated_at' => '',
			);
		}

		foreach ( (array) $rows as $row ) {
			$branch_id = (string) $row['branch_id'];

			if ( ! isset( $result[ $branch_id ] ) ) {
				continue;
			}

			$result[ $branch_id ] = array(
				'branch_id'  => $branch_id,
				'label'      => $result[ $branch_id ]['label'],
				'status'     => $row['status'],
				'qty'        => null === $row['qty'] ? null : (int) $row['qty'],
				'updated_at' => $row['updated_at'],
			);
		}

		return $result;
	}

	/**
	 * Get availability for cart items at one branch.
	 *
	 * @param string $branch_id ORDDD row_id.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_cart_availability( $branch_id ) {
		$items = array();

		if ( ! WC()->cart ) {
			return $items;
		}

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product = $cart_item['data'];

			if ( ! $product || ! $product->exists() ) {
				continue;
			}

			$sku    = $product->get_sku();
			$status = 'unknown';

			if ( '' !== $sku ) {
				$status = self::get_sku_status_at_branch( $sku, $branch_id );
			}

			$items[] = array(
				'product_id'   => $product->get_id(),
				'name'         => $product->get_name(),
				'sku'          => $sku,
				'quantity'     => (int) $cart_item['quantity'],
				'status'       => self::normalize_status( $status ),
				'status_label' => self::status_label( $status ),
				'available'    => 'in_stock' === self::normalize_status( 'unknown' === $status ? 'unknown' : $status ),
			);
		}

		return $items;
	}

	/**
	 * Lookup SKU status at branch.
	 *
	 * @param string $sku       SKU.
	 * @param string $branch_id Branch row_id.
	 * @return string
	 */
	public static function get_sku_status_at_branch( $sku, $branch_id ) {
		global $wpdb;

		$table = BPI_Database::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$status = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT status FROM {$table} WHERE sku = %s AND branch_id = %s LIMIT 1",
				$sku,
				$branch_id
			)
		);

		return $status ? (string) $status : 'unknown';
	}

	/**
	 * Human label for status.
	 *
	 * @param string $status Status slug.
	 * @return string
	 */
	public static function status_label( $status ) {
		if ( 'low_stock' === $status ) {
			$status = 'in_stock';
		}

		$labels = array(
			'in_stock'     => __( 'Available', 'orddd-branch-pickup-inventory' ),
			'out_of_stock' => __( 'Not available', 'orddd-branch-pickup-inventory' ),
			'unknown'      => __( 'Availability unknown', 'orddd-branch-pickup-inventory' ),
		);

		return $labels[ $status ] ?? $labels['unknown'];
	}

	/**
	 * CSS class for status badge.
	 *
	 * @param string $status Status slug.
	 * @return string
	 */
	public static function status_class( $status ) {
		if ( 'low_stock' === $status ) {
			$status = 'in_stock';
		}

		$classes = array(
			'in_stock'     => 'bpi-status--in-stock',
			'out_of_stock' => 'bpi-status--out-of-stock',
			'unknown'      => 'bpi-status--unknown',
		);

		return $classes[ $status ] ?? 'bpi-status--unknown';
	}

	/**
	 * Count inventory rows.
	 *
	 * @return int
	 */
	public static function count_rows() {
		global $wpdb;

		$table = BPI_Database::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Last import timestamp across all rows.
	 *
	 * @return string
	 */
	public static function last_updated() {
		global $wpdb;

		$table = BPI_Database::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$updated = $wpdb->get_var( "SELECT MAX(updated_at) FROM {$table}" );

		return $updated ? (string) $updated : '';
	}
}
