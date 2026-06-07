<?php
/**
 * Pickup availability column on the WooCommerce products list.
 *
 * @package ORDDD_Branch_Pickup_Inventory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Products list table column.
 */
class BPI_Product_List {

	/**
	 * Column key.
	 */
	const COLUMN = 'bpi_pickup_branches';

	/**
	 * Cached availability keyed by SKU.
	 *
	 * @var array<string, array{available: array<int, string>, unavailable: array<int, string>}>|null
	 */
	private static $cache = null;

	/**
	 * Init hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'manage_edit-product_columns', array( __CLASS__, 'add_column' ), 20 );
		add_action( 'manage_product_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_filter( 'manage_edit-product_sortable_columns', array( __CLASS__, 'sortable_columns' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Insert column after Stock when possible.
	 *
	 * @param array<string, string> $columns Existing columns.
	 * @return array<string, string>
	 */
	public static function add_column( $columns ) {
		$new      = array();
		$inserted = false;

		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;

			if ( in_array( $key, array( 'is_in_stock', 'product_tag' ), true ) ) {
				$new[ self::COLUMN ] = __( 'Pickup', 'orddd-branch-pickup-inventory' );
				$inserted            = true;
			}
		}

		if ( ! $inserted ) {
			$new[ self::COLUMN ] = __( 'Pickup', 'orddd-branch-pickup-inventory' );
		}

		return $new;
	}

	/**
	 * Column is not sortable (data lives outside post meta).
	 *
	 * @param array<string, string> $columns Sortable columns.
	 * @return array<string, string>
	 */
	public static function sortable_columns( $columns ) {
		unset( $columns[ self::COLUMN ] );

		return $columns;
	}

	/**
	 * Enqueue compact list styles on the products screen.
	 *
	 * @param string $hook Admin hook.
	 * @return void
	 */
	public static function enqueue_assets( $hook ) {
		if ( 'edit.php' !== $hook ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || 'product' !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style(
			'bpi-product-list',
			BPI_PLUGIN_URL . 'assets/css/product-list.css',
			array(),
			BPI_VERSION
		);
	}

	/**
	 * Render pickup availability for one product row.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Product ID.
	 * @return void
	 */
	public static function render_column( $column, $post_id ) {
		if ( self::COLUMN !== $column ) {
			return;
		}

		self::prime_cache();

		$product = wc_get_product( $post_id );

		if ( ! $product ) {
			echo '<span class="bpi-list-empty" aria-hidden="true">&mdash;</span>';
			return;
		}

		$sku = $product->get_sku();

		if ( '' === $sku ) {
			if ( $product->is_type( 'variable' ) ) {
				echo '<span class="bpi-list-muted">' . esc_html__( 'Variations', 'orddd-branch-pickup-inventory' ) . '</span>';
				return;
			}

			echo '<span class="bpi-list-empty" aria-hidden="true">&mdash;</span>';
			return;
		}

		$data = self::$cache[ $sku ] ?? array(
			'available'   => array(),
			'unavailable' => array(),
		);

		$available   = $data['available'];
		$has_records = ! empty( $available ) || ! empty( $data['unavailable'] );

		if ( ! $has_records ) {
			echo '<span class="bpi-list-muted" title="' . esc_attr__( 'No branch data imported yet for this SKU.', 'orddd-branch-pickup-inventory' ) . '">&mdash;</span>';
			return;
		}

		if ( empty( $available ) ) {
			echo '<span class="bpi-list-none" title="' . esc_attr__( 'Not available for pickup at any branch.', 'orddd-branch-pickup-inventory' ) . '">';
			esc_html_e( 'None', 'orddd-branch-pickup-inventory' );
			echo '</span>';
			return;
		}

		$visible = array_slice( $available, 0, 2 );
		$extra   = count( $available ) - count( $visible );
		$title   = implode( ', ', $available );

		echo '<div class="bpi-list-pills" title="' . esc_attr( $title ) . '">';

		foreach ( $visible as $label ) {
			echo '<span class="bpi-list-pill bpi-list-pill--yes">' . esc_html( $label ) . '</span>';
		}

		if ( $extra > 0 ) {
			printf(
				'<span class="bpi-list-pill bpi-list-pill--more">+%d</span>',
				(int) $extra
			);
		}

		echo '</div>';
	}

	/**
	 * Load availability for all SKUs on the current products page.
	 *
	 * @return void
	 */
	private static function prime_cache() {
		if ( null !== self::$cache ) {
			return;
		}

		self::$cache = array();

		global $wp_query;

		if ( empty( $wp_query->posts ) || ! is_array( $wp_query->posts ) ) {
			return;
		}

		$skus = array();

		foreach ( $wp_query->posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$product = wc_get_product( $post->ID );

			if ( ! $product ) {
				continue;
			}

			$sku = $product->get_sku();

			if ( '' !== $sku ) {
				$skus[] = $sku;
			}
		}

		if ( empty( $skus ) ) {
			return;
		}

		self::$cache = BPI_Inventory::get_pickup_summary_by_skus( $skus );
	}
}
