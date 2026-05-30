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

		$columns = self::normalize_header( $header );
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

		if ( ! isset( $columns['branch_id'] ) && ! isset( $columns['store_code'] ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return self::result( false, __( 'CSV must include either a branch_id or store_code column.', 'orddd-branch-pickup-inventory' ) );
		}

		if ( 'replace' === $mode ) {
			BPI_Inventory::truncate();
		}

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

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$log = array(
			'time'     => current_time( 'mysql' ),
			'mode'     => $mode,
			'imported' => $imported,
			'skipped'  => $skipped,
			'errors'   => array_slice( $errors, 0, 50 ),
		);

		update_option( BPI_Database::IMPORT_LOG_OPTION, $log );

		return self::result(
			true,
			sprintf(
				/* translators: 1: imported count, 2: skipped count */
				__( 'Import complete. %1$d rows saved, %2$d skipped.', 'orddd-branch-pickup-inventory' ),
				$imported,
				$skipped
			),
			$log
		);
	}

	/**
	 * Normalize CSV header keys.
	 *
	 * @param array<int, string> $header Header row.
	 * @return array<string, int>
	 */
	private static function normalize_header( $header ) {
		$columns = array();

		foreach ( $header as $index => $label ) {
			$key = strtolower( trim( (string) $label ) );
			$key = str_replace( array( ' ', '-' ), '_', $key );

			$aliases = array(
				'product_sku'  => 'sku',
				'store'        => 'store_code',
				'store_id'     => 'store_code',
				'branch'       => 'branch_id',
				'branch_code'  => 'store_code',
				'quantity'     => 'qty',
				'stock'        => 'qty',
				'availability' => 'status',
			);

			if ( isset( $aliases[ $key ] ) ) {
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
