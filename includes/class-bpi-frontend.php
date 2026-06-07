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
	 * Enqueue frontend assets.
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

		if ( ! is_product() ) {
			return;
		}

		wp_enqueue_script(
			'bpi-product',
			BPI_PLUGIN_URL . 'assets/js/product.js',
			array(),
			BPI_VERSION,
			true
		);

		wp_localize_script(
			'bpi-product',
			'bpiProduct',
			array(
				'ajaxUrl' => WC_AJAX::get_endpoint( 'bpi_product_availability' ),
				'labels'  => array(
					'defaultHint' => __( 'Select to check pickup branches', 'orddd-branch-pickup-inventory' ),
					'loading'     => __( 'Loading availability…', 'orddd-branch-pickup-inventory' ),
					'noData'      => __( 'No branch availability has been imported for this product yet.', 'orddd-branch-pickup-inventory' ),
					'error'       => __( 'Could not load availability. Please try again.', 'orddd-branch-pickup-inventory' ),
					'note'        => __( 'Availability is based on current store stock and may change before pickup. Once your order is placed, your items are reserved at your chosen branch.', 'orddd-branch-pickup-inventory' ),
					'noneAvailable' => __( 'Not available for pickup at any branch.', 'orddd-branch-pickup-inventory' ),
					'showUnavailable' => __( 'Show %d branches not available', 'orddd-branch-pickup-inventory' ),
					'hideUnavailable' => __( 'Hide branches not available', 'orddd-branch-pickup-inventory' ),
				),
			)
		);
	}

	/**
	 * Render cache-friendly availability shell (loaded via AJAX on expand).
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

		printf(
			'<details class="bpi-product-availability" data-product-id="%1$d">',
			(int) $product->get_id()
		);
		echo '<summary class="bpi-product-availability__toggle">';
		echo '<span class="bpi-product-availability__toggle-text">' . esc_html__( 'Click & collect availability', 'orddd-branch-pickup-inventory' ) . '</span>';
		echo '<span class="bpi-product-availability__toggle-hint">' . esc_html__( 'Select to check pickup branches', 'orddd-branch-pickup-inventory' ) . '</span>';
		echo '</summary>';
		echo '<div class="bpi-product-availability__panel">';
		echo '<p class="bpi-product-availability__placeholder">' . esc_html__( 'Branch availability loads when you expand this section.', 'orddd-branch-pickup-inventory' ) . '</p>';
		echo '</div>';
		echo '</details>';
	}

	/**
	 * Build AJAX payload for one product.
	 *
	 * @param int $product_id Product ID.
	 * @return array<string, mixed>
	 */
	public static function get_availability_payload( $product_id ) {
		$availability = BPI_Inventory::get_product_availability( $product_id );
		$branches     = array();
		$listed_count = 0;
		$available_count = 0;

		foreach ( $availability as $row ) {
			if ( 'unknown' === $row['status'] ) {
				continue;
			}

			++$listed_count;

			if ( 'in_stock' === BPI_Inventory::normalize_status( $row['status'] ) ) {
				++$available_count;
			}

			$branches[] = array(
				'label'        => $row['label'],
				'status'       => BPI_Inventory::normalize_status( $row['status'] ),
				'status_label' => BPI_Inventory::status_label( $row['status'] ),
				'status_class' => BPI_Inventory::status_class( $row['status'] ),
			);
		}

		return array(
			'branches'      => $branches,
			'summary_hint'  => self::build_summary_hint( $available_count, $listed_count ),
			'listed_count'  => $listed_count,
			'available_count' => $available_count,
		);
	}

	/**
	 * Summary line for the closed dropdown state.
	 *
	 * @param int $available_count Branches marked available.
	 * @param int $listed_count    Branches with imported data.
	 * @return string
	 */
	private static function build_summary_hint( $available_count, $listed_count ) {
		if ( 0 === $listed_count ) {
			return __( 'No branch data imported yet', 'orddd-branch-pickup-inventory' );
		}

		if ( $available_count > 0 ) {
			return sprintf(
				/* translators: 1: available branch count, 2: total branch count */
				_n(
					'Available at %1$d of %2$d branch',
					'Available at %1$d of %2$d branches',
					$listed_count,
					'orddd-branch-pickup-inventory'
				),
				$available_count,
				$listed_count
			);
		}

		return sprintf(
			/* translators: %d: branch count */
			_n(
				'Not available at %d branch',
				'Not available at %d branches',
				$listed_count,
				'orddd-branch-pickup-inventory'
			),
			$listed_count
		);
	}

	/**
	 * AJAX: product availability JSON.
	 *
	 * @return void
	 */
	public static function ajax_product_availability() {
		$product_id = isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $product_id || 'publish' !== get_post_status( $product_id ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid product.', 'orddd-branch-pickup-inventory' ),
				),
				400
			);
		}

		$product = wc_get_product( $product_id );

		if ( ! $product || '' === $product->get_sku() ) {
			wp_send_json_success(
				array(
					'branches'        => array(),
					'summary_hint'    => self::build_summary_hint( 0, 0 ),
					'listed_count'    => 0,
					'available_count' => 0,
				)
			);
		}

		nocache_headers();

		wp_send_json_success( self::get_availability_payload( $product_id ) );
	}
}
