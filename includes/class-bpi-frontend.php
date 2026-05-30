<?php
/**
 * Product page availability display.
 *
 * @package ORDDD_Branch_Pickup_Inventory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frontend display.
 */
class BPI_Frontend {

	/**
	 * Init hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'render_product_availability' ), 25 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wc_ajax_bpi_product_availability', array( __CLASS__, 'ajax_product_availability' ) );
		add_action( 'wc_ajax_nopriv_bpi_product_availability', array( __CLASS__, 'ajax_product_availability' ) );
	}

	/**
	 * Enqueue frontend styles.
	 *
	 * @return void
	 */
	public static function enqueue_assets() {
		if ( ! is_product() && ! is_checkout() && ! is_cart() ) {
			return;
		}

		wp_enqueue_style(
			'bpi-frontend',
			BPI_PLUGIN_URL . 'assets/css/frontend.css',
			array(),
			BPI_VERSION
		);
	}

	/**
	 * Render availability table on product page.
	 *
	 * @return void
	 */
	public static function render_product_availability() {
		$settings = BPI_Inventory::get_settings();

		if ( 'yes' !== $settings['show_on_product'] ) {
			return;
		}

		global $product;

		if ( ! $product instanceof WC_Product || '' === $product->get_sku() ) {
			return;
		}

		$availability = BPI_Inventory::get_product_availability( $product->get_id() );

		if ( empty( $availability ) ) {
			return;
		}

		$has_data = false;

		foreach ( $availability as $row ) {
			if ( 'unknown' !== $row['status'] ) {
				$has_data = true;
				break;
			}
		}

		if ( ! $has_data ) {
			return;
		}

		echo '<div class="bpi-product-availability">';
		echo '<h3 class="bpi-product-availability__title">' . esc_html__( 'Click & collect availability', 'orddd-branch-pickup-inventory' ) . '</h3>';
		echo '<ul class="bpi-branch-list">';

		foreach ( $availability as $row ) {
			if ( 'unknown' === $row['status'] ) {
				continue;
			}

			printf(
				'<li class="bpi-branch-list__item"><span class="bpi-branch-list__name">%1$s</span> <span class="bpi-status %2$s">%3$s</span></li>',
				esc_html( $row['label'] ),
				esc_attr( BPI_Inventory::status_class( $row['status'] ) ),
				esc_html( BPI_Inventory::status_label( $row['status'] ) )
			);
		}

		echo '</ul>';
		echo '<p class="bpi-product-availability__note">' . esc_html__( 'Stock levels are updated from store inventory imports and may change before pickup.', 'orddd-branch-pickup-inventory' ) . '</p>';
		echo '</div>';
	}

	/**
	 * AJAX: product availability JSON.
	 *
	 * @return void
	 */
	public static function ajax_product_availability() {
		check_ajax_referer( 'bpi_frontend', 'security' );

		$product_id = isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		wp_send_json_success( BPI_Inventory::get_product_availability( $product_id ) );
	}
}
