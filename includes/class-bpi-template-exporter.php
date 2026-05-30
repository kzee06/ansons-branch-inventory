<?php
/**
 * Export a CSV template of all WooCommerce SKUs × branches.
 *
 * @package ORDDD_Branch_Pickup_Inventory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SKU template exporter.
 */
class BPI_Template_Exporter {

	/**
	 * Default status placeholder in exported template rows.
	 */
	const DEFAULT_STATUS = 'not_available';

	/**
	 * Handle admin download request.
	 *
	 * @return void
	 */
	public static function maybe_download() {
		if ( ! is_admin() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( empty( $_GET['bpi_download'] ) || 'sku_template' !== $_GET['bpi_download'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		check_admin_referer( 'bpi_download_sku_template', 'bpi_template_nonce' );

		self::stream_csv();
		exit;
	}

	/**
	 * Build download URL for the SKU template.
	 *
	 * @return string
	 */
	public static function get_download_url() {
		return wp_nonce_url(
			add_query_arg(
				array(
					'bpi_download' => 'sku_template',
				),
				admin_url( 'admin.php?page=' . BPI_Admin::MENU_SLUG )
			),
			'bpi_download_sku_template',
			'bpi_template_nonce'
		);
	}

	/**
	 * Stream CSV to browser.
	 *
	 * @return void
	 */
	public static function stream_csv() {
		$rows     = self::get_template_rows();
		$filename = 'woocommerce-branch-inventory-template-' . gmdate( 'Y-m-d' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$output = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( false === $output ) {
			return;
		}

		fputcsv( $output, array( 'sku', 'store_code', 'status' ) );

		foreach ( $rows as $row ) {
			fputcsv( $output, $row );
		}

		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}

	/**
	 * Build template rows: every WooCommerce SKU × every branch.
	 *
	 * @return array<int, array<int, string>>
	 */
	public static function get_template_rows() {
		$rows     = array();
		$skus     = self::get_woocommerce_skus();
		$branches = self::get_branch_store_codes();

		foreach ( $skus as $sku ) {
			foreach ( $branches as $store_code ) {
				$rows[] = array( $sku, $store_code, self::DEFAULT_STATUS );
			}
		}

		return $rows;
	}

	/**
	 * Get all non-empty SKUs from published WooCommerce products and variations.
	 *
	 * @return array<int, string>
	 */
	public static function get_woocommerce_skus() {
		$skus = array();

		$product_ids = wc_get_products(
			array(
				'status'  => array( 'publish' ),
				'limit'   => -1,
				'return'  => 'ids',
				'orderby' => 'ID',
				'order'   => 'ASC',
			)
		);

		foreach ( $product_ids as $product_id ) {
			$product = wc_get_product( $product_id );

			if ( ! $product ) {
				continue;
			}

			self::collect_skus_from_product( $product, $skus );
		}

		$skus = array_values( array_unique( array_filter( $skus ) ) );
		sort( $skus, SORT_STRING );

		return $skus;
	}

	/**
	 * Collect SKUs from a product or its variations.
	 *
	 * @param WC_Product $product Product object.
	 * @param array      $skus    SKU list passed by reference.
	 * @return void
	 */
	private static function collect_skus_from_product( $product, &$skus ) {
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $child_id ) {
				$variation = wc_get_product( $child_id );

				if ( ! $variation || 'publish' !== $variation->get_status() ) {
					continue;
				}

				$sku = trim( (string) $variation->get_sku() );

				if ( '' !== $sku ) {
					$skus[] = $sku;
				}
			}
			return;
		}

		$sku = trim( (string) $product->get_sku() );

		if ( '' !== $sku ) {
			$skus[] = $sku;
		}
	}

	/**
	 * Store codes for template columns — mapped codes preferred, else branch_id.
	 *
	 * @return array<int, string>
	 */
	public static function get_branch_store_codes() {
		$branches  = BPI_Branches::get_branches();
		$store_map = get_option( BPI_Database::STORE_CODES_OPTION, array() );
		$codes     = array();

		if ( ! is_array( $store_map ) ) {
			$store_map = array();
		}

		$reverse_map = array();
		foreach ( $store_map as $store_code => $branch_id ) {
			$reverse_map[ (string) $branch_id ] = strtoupper( (string) $store_code );
		}

		foreach ( $branches as $branch_id => $branch ) {
			if ( isset( $reverse_map[ $branch_id ] ) ) {
				$codes[] = $reverse_map[ $branch_id ];
			} else {
				$codes[] = (string) $branch_id;
			}
		}

		return array_values( array_unique( $codes ) );
	}

	/**
	 * Count template rows without building full array.
	 *
	 * @return int
	 */
	public static function count_template_rows() {
		return count( self::get_woocommerce_skus() ) * max( 1, count( self::get_branch_store_codes() ) );
	}
}
