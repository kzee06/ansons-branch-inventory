<?php
/**
 * Plugin Name: Ansons Branch Inventory
 * Description: Branch pickup stock for click & collect. Requires Order Delivery Date Pro for WooCommerce and WooCommerce.
 * Version: 1.4.5
 * Author: Kristoffer Cheng
 * Author URI: https://github.com/kzee06
 * Requires Plugins: woocommerce
 * Text Domain: orddd-branch-pickup-inventory
 * Requires PHP: 7.4
 *
 * @package ORDDD_Branch_Pickup_Inventory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BPI_VERSION', '1.4.5' );
define( 'BPI_PLUGIN_NAME', 'Ansons Branch Inventory' );
define( 'BPI_PLUGIN_AUTHOR', 'Kristoffer Cheng' );
define( 'BPI_PLUGIN_AUTHOR_URI', 'https://github.com/kzee06' );
define( 'BPI_REQUIRED_ORDDD_PLUGIN', 'order-delivery-date/order_delivery_date.php' );
define( 'BPI_REQUIRED_ORDDD_NAME', 'Order Delivery Date Pro for WooCommerce' );
define( 'BPI_PLUGIN_FILE', __FILE__ );
define( 'BPI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BPI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once BPI_PLUGIN_DIR . 'includes/ansons-tools-menu.php';
require_once BPI_PLUGIN_DIR . 'includes/class-bpi-database.php';
require_once BPI_PLUGIN_DIR . 'includes/class-bpi-branches.php';
require_once BPI_PLUGIN_DIR . 'includes/class-bpi-inventory.php';
require_once BPI_PLUGIN_DIR . 'includes/class-bpi-csv-importer.php';
require_once BPI_PLUGIN_DIR . 'includes/class-bpi-template-exporter.php';
require_once BPI_PLUGIN_DIR . 'includes/class-bpi-version.php';
require_once BPI_PLUGIN_DIR . 'includes/class-bpi-admin.php';
require_once BPI_PLUGIN_DIR . 'includes/class-bpi-product-admin.php';
require_once BPI_PLUGIN_DIR . 'includes/class-bpi-product-list.php';
require_once BPI_PLUGIN_DIR . 'includes/class-bpi-frontend.php';
require_once BPI_PLUGIN_DIR . 'includes/class-bpi-checkout.php';

/**
 * Main plugin bootstrap.
 */
final class ORDDD_Branch_Pickup_Inventory {

	/**
	 * Singleton instance.
	 *
	 * @var ORDDD_Branch_Pickup_Inventory|null
	 */
	private static $instance = null;

	/**
	 * Get singleton.
	 *
	 * @return ORDDD_Branch_Pickup_Inventory
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		register_activation_hook( BPI_PLUGIN_FILE, array( 'BPI_Database', 'activate' ) );
		register_deactivation_hook( BPI_PLUGIN_FILE, array( 'BPI_Database', 'deactivate' ) );

		add_action( 'plugins_loaded', array( $this, 'init' ) );
		add_action( 'admin_notices', array( $this, 'dependency_notice' ) );
	}

	/**
	 * Load components when dependencies are met.
	 *
	 * @return void
	 */
	public function init() {
		if ( ! $this->dependencies_met() ) {
			return;
		}

		Ansons_Tools_Menu::boot();

		BPI_Admin::init();
		BPI_Product_Admin::init();
		BPI_Product_List::init();
		BPI_Frontend::init();
		BPI_Checkout::init();
	}

	/**
	 * Check WooCommerce and ORDDD are active.
	 *
	 * @return bool
	 */
	public function dependencies_met() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return false;
		}

		if ( ! $this->is_orddd_active() ) {
			return false;
		}

		return true;
	}

	/**
	 * Check if Order Delivery Date Pro is installed and active.
	 *
	 * @return bool
	 */
	public function is_orddd_active() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( is_plugin_active( BPI_REQUIRED_ORDDD_PLUGIN ) ) {
			return true;
		}

		return class_exists( 'order_delivery_date' ) || class_exists( 'orddd_locations' );
	}

	/**
	 * Admin notice when dependencies are missing.
	 *
	 * @return void
	 */
	public function dependency_notice() {
		if ( $this->dependencies_met() ) {
			return;
		}

		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			echo '<div class="notice notice-error"><p>';
			printf(
				/* translators: 1: plugin name, 2: woocommerce */
				esc_html__( '%1$s requires %2$s to be installed and active.', 'orddd-branch-pickup-inventory' ),
				esc_html( BPI_PLUGIN_NAME ),
				'WooCommerce'
			);
			echo '</p></div>';
			return;
		}

		if ( ! $this->is_orddd_active() ) {
			echo '<div class="notice notice-error"><p>';
			printf(
				/* translators: 1: plugin name, 2: required plugin name */
				esc_html__( '%1$s requires %2$s to be installed and active.', 'orddd-branch-pickup-inventory' ),
				esc_html( BPI_PLUGIN_NAME ),
				esc_html( BPI_REQUIRED_ORDDD_NAME )
			);
			echo '</p></div>';
		}
	}
}

ORDDD_Branch_Pickup_Inventory::instance();
