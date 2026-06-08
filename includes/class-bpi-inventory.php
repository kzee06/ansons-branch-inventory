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
			'sap_min_stock'   => 2,
		);

		$settings = get_option( BPI_Database::SETTINGS_OPTION, array() );

		return wp_parse_args( is_array( $settings ) ? $settings : array(), $defaults );
	}

	/**
	 * Minimum SAP Available qty treated as in stock (configurable in admin).
	 *
	 * @return int
	 */
	public static function get_sap_min_stock() {
		$settings = self::get_settings();

		return max( 0, (int) $settings['sap_min_stock'] );
	}

	/**
	 * Save the SAP minimum stock threshold.
	 *
	 * @param int|string $value Minimum quantity.
	 * @return void
	 */
	public static function set_sap_min_stock( $value ) {
		$settings                  = self::get_settings();
		$settings['sap_min_stock'] = max( 0, (int) $value );
		update_option( BPI_Database::SETTINGS_OPTION, $settings );
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
	 * Normalize a SKU/itemcode to a loose match key.
	 *
	 * Strips a leading asterisk and leading zeros so that SAP itemcodes
	 * (e.g. 056000161918) can match WooCommerce SKUs stored with the leading
	 * zero dropped (56000161918) or with a leading marker (*123…).
	 *
	 * @param string $sku Raw SKU or itemcode.
	 * @return string Normalized key (empty if nothing usable remains).
	 */
	public static function normalize_sku_key( $sku ) {
		$key = strtoupper( trim( (string) $sku ) );
		$key = ltrim( $key, '*' );
		$key = ltrim( $key, '0' );

		return $key;
	}

	/**
	 * Build a loose SKU lookup: normalized key => actual WooCommerce SKU.
	 *
	 * Keys that map to more than one distinct WooCommerce SKU are dropped so a
	 * fuzzy match can never resolve to the wrong product.
	 *
	 * @return array<string, string>
	 */
	public static function build_sku_index() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$rows = $wpdb->get_col(
			"SELECT pm.meta_value
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = '_sku'
				AND pm.meta_value <> ''
				AND p.post_type IN ( 'product', 'product_variation' )
				AND p.post_status = 'publish'"
		);

		$index     = array();
		$ambiguous = array();

		foreach ( (array) $rows as $sku ) {
			$sku = (string) $sku;
			$key = self::normalize_sku_key( $sku );

			if ( '' === $key ) {
				continue;
			}

			if ( isset( $index[ $key ] ) ) {
				if ( $index[ $key ] !== $sku ) {
					$ambiguous[ $key ] = true;
				}
				continue;
			}

			$index[ $key ] = $sku;
		}

		foreach ( array_keys( $ambiguous ) as $key ) {
			unset( $index[ $key ] );
		}

		return $index;
	}

	/**
	 * Resolve a SAP itemcode to a real WooCommerce SKU.
	 *
	 * Tries an exact SKU match first, then falls back to a loose match
	 * (ignoring a leading asterisk / leading zeros) via the prebuilt index.
	 *
	 * @param string                $sap_sku SAP itemcode.
	 * @param array<string, string> $index   Loose SKU index from build_sku_index().
	 * @return string Matching WooCommerce SKU, or empty string if none.
	 */
	public static function resolve_sap_sku_to_wc( $sap_sku, $index ) {
		$sap_sku = wc_clean( (string) $sap_sku );

		if ( '' === $sap_sku ) {
			return '';
		}

		if ( wc_get_product_id_by_sku( $sap_sku ) ) {
			return $sap_sku;
		}

		$key = self::normalize_sku_key( $sap_sku );

		if ( '' !== $key && isset( $index[ $key ] ) ) {
			return $index[ $key ];
		}

		return '';
	}

	/**
	 * Upsert one inventory row.
	 *
	 * @param string   $sku       Product SKU.
	 * @param string   $branch_id ORDDD row_id.
	 * @param string   $status    Availability status.
	 * @param int|null $qty       Optional stock quantity from import.
	 * @return bool
	 */
	public static function upsert_row( $sku, $branch_id, $status, $qty = null ) {
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
				'qty'        => null === $qty ? null : (int) $qty,
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

			$available_elsewhere = '' !== $sku ? self::get_available_branch_labels_for_sku( $sku ) : array();

			$items[] = array(
				'product_id'          => $product->get_id(),
				'name'                => $product->get_name(),
				'sku'                 => $sku,
				'quantity'            => (int) $cart_item['quantity'],
				'status'              => self::normalize_status( $status ),
				'status_label'        => self::get_cart_status_label( $status, $available_elsewhere ),
				'status_class'        => self::status_class( $status ),
				'available'           => 'in_stock' === self::normalize_status( 'unknown' === $status ? 'unknown' : $status ),
				'available_elsewhere' => $available_elsewhere,
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
	 * Short branch labels where a SKU is available for pickup.
	 *
	 * @param string $sku Product SKU.
	 * @return array<int, string>
	 */
	public static function get_available_branch_labels_for_sku( $sku ) {
		global $wpdb;

		$sku = wc_clean( $sku );

		if ( '' === $sku ) {
			return array();
		}

		$table = BPI_Database::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT branch_id FROM {$table} WHERE sku = %s AND status = %s",
				$sku,
				'in_stock'
			),
			ARRAY_A
		);

		$labels = array();

		foreach ( (array) $rows as $row ) {
			$label = BPI_Branches::get_short_label( (string) $row['branch_id'] );

			if ( '' !== $label ) {
				$labels[] = $label;
			}
		}

		return array_values( array_unique( $labels ) );
	}

	/**
	 * Cart line status label with branch context.
	 *
	 * @param string               $status_at_branch Status at selected branch.
	 * @param array<int, string>   $available_elsewhere Branch labels with stock.
	 * @return string
	 */
	public static function get_cart_status_label( $status_at_branch, $available_elsewhere ) {
		if ( 'in_stock' === self::normalize_status( $status_at_branch ) ) {
			return self::status_label( 'in_stock' );
		}

		if ( ! empty( $available_elsewhere ) && 'unknown' === $status_at_branch ) {
			return __( 'Not at this branch', 'orddd-branch-pickup-inventory' );
		}

		return self::status_label( $status_at_branch );
	}

	/**
	 * Branch labels where every cart SKU is available for pickup.
	 *
	 * @param string $exclude_branch_id Optional branch row_id to omit (e.g. current selection).
	 * @return array<int, string>
	 */
	public static function get_branches_where_cart_is_fully_available( $exclude_branch_id = '' ) {
		if ( ! WC()->cart ) {
			return array();
		}

		$skus = array();

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product = $cart_item['data'];

			if ( ! $product || ! $product->exists() ) {
				continue;
			}

			$sku = $product->get_sku();

			if ( '' === $sku ) {
				return array();
			}

			$skus[] = $sku;
		}

		$skus = array_values( array_unique( $skus ) );

		if ( empty( $skus ) ) {
			return array();
		}

		$exclude_branch_id = wc_clean( (string) $exclude_branch_id );
		$branches          = BPI_Branches::get_branches();
		$eligible          = array();

		foreach ( $branches as $branch_id => $branch ) {
			if ( '' !== $exclude_branch_id && $branch_id === $exclude_branch_id ) {
				continue;
			}

			$all_available = true;

			foreach ( $skus as $sku ) {
				$status = self::get_sku_status_at_branch( $sku, $branch_id );

				if ( 'in_stock' !== self::normalize_status( $status ) ) {
					$all_available = false;
					break;
				}
			}

			if ( ! $all_available ) {
				continue;
			}

			$label = BPI_Branches::get_short_label( $branch_id );

			if ( '' !== $label ) {
				$eligible[] = $label;
			}
		}

		return array_values( array_unique( $eligible ) );
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
			'unknown'      => __( 'Not imported', 'orddd-branch-pickup-inventory' ),
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

	/**
	 * Pickup summaries for many SKUs (products list batch load).
	 *
	 * @param array<int, string> $skus Product SKUs.
	 * @return array<string, array{available: array<int, string>, unavailable: array<int, string>}>
	 */
	public static function get_pickup_summary_by_skus( $skus ) {
		global $wpdb;

		$skus = array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $sku ) {
							return wc_clean( (string) $sku );
						},
						$skus
					)
				)
			)
		);

		$result = array();

		foreach ( $skus as $sku ) {
			$result[ $sku ] = array(
				'available'   => array(),
				'unavailable' => array(),
			);
		}

		if ( empty( $skus ) ) {
			return $result;
		}

		$table        = BPI_Database::get_table_name();
		$placeholders = implode( ', ', array_fill( 0, count( $skus ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT sku, branch_id, status FROM {$table} WHERE sku IN ({$placeholders})",
				...$skus
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$sku       = (string) $row['sku'];
			$branch_id = (string) $row['branch_id'];
			$status    = self::normalize_status( (string) $row['status'] );
			$label     = BPI_Branches::get_short_label( $branch_id );

			if ( '' === $label || ! isset( $result[ $sku ] ) ) {
				continue;
			}

			if ( 'in_stock' === $status ) {
				$result[ $sku ]['available'][] = $label;
			} else {
				$result[ $sku ]['unavailable'][] = $label;
			}
		}

		return $result;
	}
}
