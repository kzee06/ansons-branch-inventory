<?php
/**
 * CSV import handler.
 *
 * @package ORDDD_Branch_Pickup_Inventory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSV importer.
 */
class BPI_CSV_Importer {

	/**
	 * Required column (sku) plus one branch identifier column.
	 */
	const REQUIRED_COLUMNS = array( 'sku' );

	/**
	 * Import CSV file.
	 *
	 * @param string $file_path Absolute path to CSV.
	 * @param string $mode      replace|merge.
	 * @return array<string, mixed>
	 */
	public static function import_file( $file_path, $mode = 'merge' ) {
		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return self::result( false, __( 'Could not read the uploaded CSV file.', 'orddd-branch-pickup-inventory' ) );
		}

		$handle = fopen( $file_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( false === $handle ) {
			return self::result( false, __( 'Could not open the CSV file.', 'orddd-branch-pickup-inventory' ) );
		}

		$header = fgetcsv( $handle );

		if ( ! is_array( $header ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return self::result( false, __( 'CSV file is empty or invalid.', 'orddd-branch-pickup-inventory' ) );
		}

		$is_sap  = self::is_sap_format( $header );
		$columns = self::normalize_header( $header, $is_sap );
		$missing = array_diff( self::REQUIRED_COLUMNS, array_keys( $columns ) );

		if ( ! empty( $missing ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return self::result(
				false,
				sprintf(
					/* translators: %s: comma-separated column names */
					__( 'Missing required CSV columns: %s', 'orddd-branch-pickup-inventory' ),
					implode( ', ', $missing )
				)
			);
		}

		if ( $is_sap ) {
			if ( ! isset( $columns['store_code'] ) ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				return self::result( false, __( 'SAP CSV must include a WhsCode column (warehouse / location ID).', 'orddd-branch-pickup-inventory' ) );
			}

			if ( ! isset( $columns['sap_qty'] ) ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				return self::result( false, __( 'SAP CSV must include an Available column (stock quantity).', 'orddd-branch-pickup-inventory' ) );
			}
		} elseif ( ! isset( $columns['branch_id'] ) && ! isset( $columns['store_code'] ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return self::result( false, __( 'CSV must include either a branch_id or store_code column.', 'orddd-branch-pickup-inventory' ) );
		}

		if ( 'replace' === $mode ) {
			BPI_Inventory::truncate();
		}

		if ( $is_sap ) {
			$stats = self::import_sap_rows( $handle, $columns );
		} else {
			$stats = self::import_standard_rows( $handle, $columns );
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$log = array(
			'time'     => current_time( 'mysql' ),
			'mode'     => $mode,
			'format'   => $is_sap ? 'sap' : 'standard',
			'imported' => $stats['imported'],
			'skipped'  => $stats['skipped'],
			'ignored'  => $stats['ignored'],
			'errors'   => array_slice( $stats['errors'], 0, 50 ),
		);

		update_option( BPI_Database::IMPORT_LOG_OPTION, $log );

		if ( $is_sap ) {
			return self::result(
				true,
				sprintf(
					/* translators: 1: imported count, 2: ignored count (non-WooCommerce SKUs), 3: skipped count */
					__( 'SAP import complete. %1$d WooCommerce SKUs updated, %2$d rows ignored (SKU not in WooCommerce), %3$d skipped.', 'orddd-branch-pickup-inventory' ),
					$stats['imported'],
					$stats['ignored'],
					$stats['skipped']
				),
				$log
			);
		}

		return self::result(
			true,
			sprintf(
				/* translators: 1: imported count, 2: skipped count */
				__( 'Import complete. %1$d rows saved, %2$d skipped.', 'orddd-branch-pickup-inventory' ),
				$stats['imported'],
				$stats['skipped']
			),
			$log
		);
	}

	/**
	 * Import rows from a standard availability CSV.
	 *
	 * @param resource             $handle  Open CSV handle.
	 * @param array<string, int>   $columns Column map.
	 * @return array{imported: int, skipped: int, ignored: int, errors: array<int, string>}
	 */
	private static function import_standard_rows( $handle, $columns ) {
		$imported = 0;
		$skipped  = 0;
		$errors   = array();
		$row_num  = 1;

		while ( ( $row = fgetcsv( $handle ) ) !== false ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition
			++$row_num;

			if ( self::is_empty_row( $row ) ) {
				continue;
			}

			$data = self::map_row( $columns, $row );
			$sku  = trim( (string) ( $data['sku'] ?? '' ) );

			if ( '' === $sku ) {
				++$skipped;
				$errors[] = sprintf(
					/* translators: %d: row number */
					__( 'Row %d: missing SKU.', 'orddd-branch-pickup-inventory' ),
					$row_num
				);
				continue;
			}

			$branch_ref = '';

			if ( ! empty( $data['branch_id'] ) ) {
				$branch_ref = trim( (string) $data['branch_id'] );
			} elseif ( ! empty( $data['store_code'] ) ) {
				$branch_ref = trim( (string) $data['store_code'] );
			}

			$branch_id = BPI_Branches::resolve_branch_id( $branch_ref );

			if ( '' === $branch_id ) {
				++$skipped;
				$errors[] = sprintf(
					/* translators: 1: row number, 2: branch reference */
					__( 'Row %1$d: unknown branch "%2$s". Map store codes in settings or use a valid branch_id.', 'orddd-branch-pickup-inventory' ),
					$row_num,
					$branch_ref
				);
				continue;
			}

			$status = isset( $data['status'] ) ? (string) $data['status'] : '';

			if ( '' === trim( $status ) ) {
				++$skipped;
				$errors[] = sprintf(
					/* translators: %d: row number */
					__( 'Row %d: missing status (use available or not_available).', 'orddd-branch-pickup-inventory' ),
					$row_num
				);
				continue;
			}

			if ( BPI_Inventory::upsert_row( $sku, $branch_id, $status ) ) {
				++$imported;
			} else {
				++$skipped;
				$errors[] = sprintf(
					/* translators: %d: row number */
					__( 'Row %d: could not save row.', 'orddd-branch-pickup-inventory' ),
					$row_num
				);
			}
		}

		return array(
			'imported' => $imported,
			'skipped'  => $skipped,
			'ignored'  => 0,
			'errors'   => $errors,
		);
	}

	/**
	 * Import rows from an SAP inventory export CSV.
	 *
	 * Only WooCommerce SKUs are processed. Stock below the configured minimum is not available.
	 *
	 * @param resource             $handle  Open CSV handle.
	 * @param array<string, int>   $columns Column map.
	 * @return array{imported: int, skipped: int, ignored: int, errors: array<int, string>}
	 */
	private static function import_sap_rows( $handle, $columns ) {
		$skipped  = 0;
		$ignored  = 0;
		$errors   = array();
		$row_num  = 1;
		$pending  = array();

		while ( ( $row = fgetcsv( $handle ) ) !== false ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition
			++$row_num;

			if ( self::is_empty_row( $row ) ) {
				continue;
			}

			$data        = self::map_row( $columns, $row );
			$sku         = trim( (string) ( $data['sku'] ?? '' ) );
			$location_id = trim( (string) ( $data['store_code'] ?? '' ) );

			if ( '' === $sku ) {
				++$skipped;
				$errors[] = sprintf(
					/* translators: %d: row number */
					__( 'Row %d: missing Itemcode (SKU).', 'orddd-branch-pickup-inventory' ),
					$row_num
				);
				continue;
			}

			if ( ! wc_get_product_id_by_sku( $sku ) ) {
				++$ignored;
				continue;
			}

			if ( '' === $location_id ) {
				++$skipped;
				$errors[] = sprintf(
					/* translators: %d: row number */
					__( 'Row %d: missing WhsCode (location ID).', 'orddd-branch-pickup-inventory' ),
					$row_num
				);
				continue;
			}

			$branch_id = BPI_Branches::resolve_branch_id( $location_id );

			if ( '' === $branch_id ) {
				++$skipped;
				$errors[] = sprintf(
					/* translators: 1: row number, 2: SAP location ID */
					__( 'Row %1$d: unknown SAP location ID "%2$s". Map WhsCode values in Store code mapping below.', 'orddd-branch-pickup-inventory' ),
					$row_num,
					$location_id
				);
				continue;
			}

			$qty_raw = $data['sap_qty'] ?? '';
			$qty     = is_numeric( $qty_raw ) ? (int) floor( (float) $qty_raw ) : 0;
			$key     = $sku . '|' . $branch_id;

			if ( ! isset( $pending[ $key ] ) ) {
				$pending[ $key ] = array(
					'sku'       => $sku,
					'branch_id' => $branch_id,
					'qty'       => 0,
				);
			}

			$pending[ $key ]['qty'] += $qty;
		}

		$imported = 0;
		$min_stock = BPI_Inventory::get_sap_min_stock();

		foreach ( $pending as $entry ) {
			$status = $entry['qty'] >= $min_stock ? 'available' : 'not_available';

			if ( BPI_Inventory::upsert_row( $entry['sku'], $entry['branch_id'], $status, $entry['qty'] ) ) {
				++$imported;
			} else {
				++$skipped;
				$errors[] = sprintf(
					/* translators: 1: SKU, 2: branch ID */
					__( 'Could not save inventory for SKU "%1$s" at branch %2$s.', 'orddd-branch-pickup-inventory' ),
					$entry['sku'],
					$entry['branch_id']
				);
			}
		}

		return array(
			'imported' => $imported,
			'skipped'  => $skipped,
			'ignored'  => $ignored,
			'errors'   => $errors,
		);
	}

	/**
	 * Detect SAP inventory export format from header row.
	 *
	 * @param array<int, string> $header Header row.
	 * @return bool
	 */
	private static function is_sap_format( $header ) {
		$keys = array();

		foreach ( $header as $label ) {
			$key = self::normalize_header_key( (string) $label );

			if ( '' !== $key ) {
				$keys[] = $key;
			}
		}

		return in_array( 'itemcode', $keys, true )
			&& in_array( 'whscode', $keys, true )
			&& in_array( 'available', $keys, true );
	}

	/**
	 * Normalize one CSV header label to a canonical key.
	 *
	 * @param string $label Raw header label.
	 * @return string
	 */
	private static function normalize_header_key( $label ) {
		$key = strtolower( trim( $label ) );
		$key = str_replace( array( ' ', '-' ), '_', $key );

		return $key;
	}

	/**
	 * Normalize CSV header keys.
	 *
	 * @param array<int, string> $header Header row.
	 * @param bool               $is_sap Whether the file is SAP format.
	 * @return array<string, int>
	 */
	private static function normalize_header( $header, $is_sap = false ) {
		$columns = array();

		foreach ( $header as $index => $label ) {
			$key = self::normalize_header_key( (string) $label );

			$aliases = array(
				'product_sku'  => 'sku',
				'itemcode'     => 'sku',
				'item_code'    => 'sku',
				'store'        => 'store_code',
				'store_id'     => 'store_code',
				'branch'       => 'branch_id',
				'branch_code'  => 'store_code',
				'whscode'      => 'store_code',
				'whs_code'     => 'store_code',
				'location_id'  => 'store_code',
				'location'     => 'store_code',
				'quantity'     => 'qty',
				'stock'        => 'qty',
				'availability' => 'status',
			);

			if ( $is_sap && 'available' === $key ) {
				$key = 'sap_qty';
			} elseif ( isset( $aliases[ $key ] ) ) {
				$key = $aliases[ $key ];
			}

			if ( '' !== $key ) {
				$columns[ $key ] = (int) $index;
			}
		}

		return $columns;
	}

	/**
	 * Map CSV row to associative array.
	 *
	 * @param array<string, int> $columns Column map.
	 * @param array<int, string> $row     Data row.
	 * @return array<string, string>
	 */
	private static function map_row( $columns, $row ) {
		$data = array();

		foreach ( $columns as $key => $index ) {
			$data[ $key ] = isset( $row[ $index ] ) ? trim( (string) $row[ $index ] ) : '';
		}

		return $data;
	}

	/**
	 * Check if row is empty.
	 *
	 * @param array<int, string|null> $row CSV row.
	 * @return bool
	 */
	private static function is_empty_row( $row ) {
		foreach ( $row as $cell ) {
			if ( null !== $cell && '' !== trim( (string) $cell ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Build result array.
	 *
	 * @param bool   $success Success flag.
	 * @param string $message Message.
	 * @param array  $log     Optional log.
	 * @return array<string, mixed>
	 */
	private static function result( $success, $message, $log = array() ) {
		return array(
			'success' => $success,
			'message' => $message,
			'log'     => $log,
		);
	}
}
