<?php
/**
 * Checkout integration with ORDDD pickup location selector.
 *
 * @package ORDDD_Branch_Pickup_Inventory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checkout hooks.
 */
class BPI_Checkout {

	/**
	 * Init hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'orddd_after_checkout_delivery_date', array( __CLASS__, 'render_checkout_panel' ), 15 );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_branch_stock' ), 25, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
		add_action( 'wc_ajax_bpi_cart_availability', array( __CLASS__, 'ajax_cart_availability' ) );
		add_action( 'wc_ajax_nopriv_bpi_cart_availability', array( __CLASS__, 'ajax_cart_availability' ) );
	}

	/**
	 * Enqueue checkout JS.
	 *
	 * @return void
	 */
	public static function enqueue_scripts() {
		if ( ! is_checkout() && ! is_cart() ) {
			return;
		}

		wp_enqueue_script(
			'bpi-checkout',
			BPI_PLUGIN_URL . 'assets/js/checkout.js',
			array( 'jquery' ),
			BPI_VERSION,
			true
		);

		wp_localize_script(
			'bpi-checkout',
			'bpiCheckout',
			array(
				'ajaxUrl'  => WC_AJAX::get_endpoint( 'bpi_cart_availability' ),
				'nonce'    => wp_create_nonce( 'bpi_frontend' ),
				'labels'   => array(
					'title'       => __( 'Pickup availability at this branch', 'orddd-branch-pickup-inventory' ),
					'loading'     => __( 'Checking availability…', 'orddd-branch-pickup-inventory' ),
					'selectBranch'=> __( 'Select a pickup branch to see item availability.', 'orddd-branch-pickup-inventory' ),
					'allAvailable'=> __( 'All items are available for pickup at this branch.', 'orddd-branch-pickup-inventory' ),
					'unavailable' => __( 'Some items are not available at this branch.', 'orddd-branch-pickup-inventory' ),
				),
			)
		);
	}

	/**
	 * Output checkout availability container after ORDDD fields.
	 *
	 * @return void
	 */
	public static function render_checkout_panel() {
		if ( 'on' !== get_option( 'orddd_enable_pickup_locations', '' ) ) {
			return;
		}

		echo '<div id="bpi-checkout-availability" class="bpi-checkout-availability" aria-live="polite"></div>';
	}

	/**
	 * Validate cart against selected branch.
	 *
	 * @param array    $data   Posted checkout data.
	 * @param WP_Error $errors Checkout errors.
	 * @return void
	 */
	public static function validate_branch_stock( $data, $errors ) {
		$settings = BPI_Inventory::get_settings();

		if ( 'yes' !== $settings['block_checkout'] ) {
			return;
		}

		if ( ! self::is_pickup_checkout( $data ) ) {
			return;
		}

		$branch_id = self::get_selected_branch( $data );

		if ( '' === $branch_id || 'select_location' === $branch_id ) {
			return;
		}

		$items = BPI_Inventory::get_cart_availability( $branch_id );

		foreach ( $items as $item ) {
			if ( ! $item['available'] ) {
				$branch_label = BPI_Branches::get_branch_label( $branch_id );
				$errors->add(
					'bpi_branch_stock',
					sprintf(
						/* translators: 1: product name, 2: branch name */
						__( '%1$s is not available for pickup at %2$s. Please choose another branch or delivery.', 'orddd-branch-pickup-inventory' ),
						$item['name'],
						$branch_label
					)
				);
			}
		}
	}

	/**
	 * AJAX cart availability for selected branch.
	 *
	 * @return void
	 */
	public static function ajax_cart_availability() {
		check_ajax_referer( 'bpi_frontend', 'security' );

		$branch_id = isset( $_GET['branch_id'] ) ? sanitize_text_field( wp_unslash( $_GET['branch_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' === $branch_id || 'select_location' === $branch_id ) {
			wp_send_json_success(
				array(
					'items'         => array(),
					'all_available' => false,
					'message'       => 'select_branch',
				)
			);
		}

		$items         = BPI_Inventory::get_cart_availability( $branch_id );
		$all_available = true;

		foreach ( $items as $item ) {
			if ( ! $item['available'] ) {
				$all_available = false;
				break;
			}
		}

		wp_send_json_success(
			array(
				'items'         => $items,
				'all_available' => $all_available,
				'branch_label'  => BPI_Branches::get_branch_label( $branch_id ),
			)
		);
	}

	/**
	 * Detect pickup checkout from posted data.
	 *
	 * @param array $data Checkout data.
	 * @return bool
	 */
	private static function is_pickup_checkout( $data ) {
		if ( isset( $data['orddd_order_type'] ) && 'pickup' === $data['orddd_order_type'] ) {
			return true;
		}

		if ( ! empty( $data['shipping_method'] ) && is_array( $data['shipping_method'] ) ) {
			foreach ( $data['shipping_method'] as $method ) {
				if ( false !== strpos( (string) $method, 'local_pickup' ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Extract selected ORDDD branch from checkout data.
	 *
	 * @param array $data Checkout data.
	 * @return string
	 */
	private static function get_selected_branch( $data ) {
		if ( ! empty( $data['orddd_locations_0'] ) ) {
			return sanitize_text_field( $data['orddd_locations_0'] );
		}

		if ( ! empty( $data['orddd_locations'] ) ) {
			return sanitize_text_field( $data['orddd_locations'] );
		}

		foreach ( $data as $key => $value ) {
			if ( 0 === strpos( $key, 'orddd_locations_' ) && '' !== $value && 'select_location' !== $value ) {
				return sanitize_text_field( $value );
			}
		}

		return '';
	}
}
