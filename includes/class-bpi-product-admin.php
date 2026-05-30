<?php
/**
 * Per-product branch inventory in WooCommerce admin.
 *
 * @package ORDDD_Branch_Pickup_Inventory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product edit screen meta box.
 */
class BPI_Product_Admin {

	/**
	 * Nonce action.
	 */
	const NONCE_ACTION = 'bpi_save_product_inventory';

	/**
	 * Init hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_box' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_product' ) );
		add_action( 'woocommerce_variation_options', array( __CLASS__, 'render_variation_fields' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Register product meta box.
	 *
	 * @return void
	 */
	public static function register_meta_box() {
		add_meta_box(
			'bpi-branch-inventory',
			BPI_PLUGIN_NAME,
			array( __CLASS__, 'render_meta_box' ),
			'product',
			'normal',
			'default'
		);
	}

	/**
	 * Enqueue admin styles on product screens.
	 *
	 * @param string $hook Admin page hook.
	 * @return void
	 */
	public static function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || 'product' !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style(
			'bpi-admin',
			BPI_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			BPI_VERSION
		);
	}

	/**
	 * Render meta box for simple / external products.
	 *
	 * @param WP_Post $post Product post.
	 * @return void
	 */
	public static function render_meta_box( $post ) {
		$product = wc_get_product( $post->ID );

		if ( ! $product ) {
			return;
		}

		wp_nonce_field( self::NONCE_ACTION, 'bpi_product_inventory_nonce' );

		if ( $product->is_type( 'variable' ) ) {
			self::render_variable_notice();
			return;
		}

		$sku = $product->get_sku();

		if ( '' === $sku ) {
			echo '<p class="bpi-product-panel__notice">';
			esc_html_e( 'Add a SKU to this product to manage branch pickup stock.', 'orddd-branch-pickup-inventory' );
			echo '</p>';
			return;
		}

		echo '<p class="description">';
		echo esc_html__( 'Override pickup availability per store for SKU:', 'orddd-branch-pickup-inventory' );
		echo ' <code>' . esc_html( $sku ) . '</code>. ';
		echo esc_html__( 'Save the product to apply changes. CSV merge imports will overwrite matching rows.', 'orddd-branch-pickup-inventory' );
		echo '</p>';

		self::render_branch_table( BPI_Inventory::get_product_availability( $product->get_id() ), 'bpi_branch' );
	}

	/**
	 * Notice for variable products.
	 *
	 * @return void
	 */
	private static function render_variable_notice() {
		echo '<p class="bpi-product-panel__notice">';
		esc_html_e( 'Branch pickup stock is managed per variation below (expand each variation).', 'orddd-branch-pickup-inventory' );
		echo '</p>';
	}

	/**
	 * Branch table on each variation row.
	 *
	 * @param int     $loop           Variation loop index.
	 * @param array   $variation_data Variation data.
	 * @param WP_Post $variation      Variation post.
	 * @return void
	 */
	public static function render_variation_fields( $loop, $variation_data, $variation ) {
		$product = wc_get_product( $variation->ID );

		if ( ! $product ) {
			return;
		}

		$sku = $product->get_sku();

		echo '<div class="bpi-variation-inventory form-row form-row-full">';
		echo '<h4>' . esc_html( BPI_PLUGIN_NAME ) . '</h4>';

		if ( '' === $sku ) {
			echo '<p class="description">' . esc_html__( 'Set a SKU on this variation to manage branch stock.', 'orddd-branch-pickup-inventory' ) . '</p>';
			echo '</div>';
			return;
		}

		printf(
			'<p class="description">%1$s <code>%2$s</code></p>',
			esc_html__( 'SKU:', 'orddd-branch-pickup-inventory' ),
			esc_html( $sku )
		);

		$field_prefix = 'bpi_branch_var[' . (int) $loop . ']';
		self::render_branch_table( BPI_Inventory::get_product_availability( $product->get_id() ), $field_prefix );
		echo '</div>';
	}

	/**
	 * Output editable branch rows.
	 *
	 * @param array<string, array<string, mixed>> $availability Branch rows.
	 * @param string                              $field_prefix   Form field name prefix.
	 * @return void
	 */
	private static function render_branch_table( $availability, $field_prefix ) {
		$branches = BPI_Branches::get_branches();

		if ( empty( $branches ) ) {
			echo '<p class="bpi-product-panel__notice">';
			esc_html_e( 'No ORDDD pickup locations found. Add branches in Order Delivery Date first.', 'orddd-branch-pickup-inventory' );
			echo '</p>';
			return;
		}

		if ( empty( $availability ) ) {
			$availability = array();
			foreach ( $branches as $branch_id => $branch ) {
				$availability[ $branch_id ] = array(
					'branch_id'  => $branch_id,
					'label'      => $branch['label'],
					'status'     => 'unknown',
					'qty'        => null,
					'updated_at' => '',
				);
			}
		}

		echo '<table class="widefat striped bpi-product-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Branch', 'orddd-branch-pickup-inventory' ) . '</th>';
		echo '<th>' . esc_html__( 'Availability', 'orddd-branch-pickup-inventory' ) . '</th>';
		echo '<th>' . esc_html__( 'Last updated', 'orddd-branch-pickup-inventory' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $availability as $branch_id => $row ) {
			$status     = $row['status'] ?? 'unknown';
			$updated_at = $row['updated_at'] ?? '';
			$name       = $field_prefix . '[' . esc_attr( $branch_id ) . ']';

			if ( 'low_stock' === $status ) {
				$status = 'in_stock';
			}

			echo '<tr>';
			echo '<td>' . esc_html( $row['label'] ) . '</td>';
			echo '<td>';
			echo '<select name="' . esc_attr( $name ) . '[status]">';
			self::render_status_options( $status );
			echo '</select>';
			echo '</td>';
			echo '<td>' . ( $updated_at ? esc_html( $updated_at ) : '<span aria-hidden="true">—</span>' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Status select options.
	 *
	 * @param string $selected Current status.
	 * @return void
	 */
	private static function render_status_options( $selected ) {
		$options = array(
			'unknown'      => __( '— Not set —', 'orddd-branch-pickup-inventory' ),
			'in_stock'     => __( 'Available', 'orddd-branch-pickup-inventory' ),
			'out_of_stock' => __( 'Not available', 'orddd-branch-pickup-inventory' ),
		);

		foreach ( $options as $value => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $selected, $value, false ),
				esc_html( $label )
			);
		}
	}

	/**
	 * Save simple product branch overrides.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	public static function save_product( $product_id ) {
		if ( ! isset( $_POST['bpi_product_inventory_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bpi_product_inventory_nonce'] ) ), self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_product', $product_id ) ) {
			return;
		}

		$product = wc_get_product( $product_id );

		if ( ! $product || $product->is_type( 'variable' ) ) {
			return;
		}

		$sku = $product->get_sku();

		if ( '' === $sku ) {
			return;
		}

		$posted = isset( $_POST['bpi_branch'] ) ? wp_unslash( $_POST['bpi_branch'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		self::save_branch_rows( $sku, is_array( $posted ) ? $posted : array() );
	}

	/**
	 * Save variation branch overrides.
	 *
	 * @param int $variation_id Variation ID.
	 * @param int $loop         Variation loop index.
	 * @return void
	 */
	public static function save_variation( $variation_id, $loop ) {
		if ( ! current_user_can( 'edit_product', $variation_id ) ) {
			return;
		}

		$product = wc_get_product( $variation_id );

		if ( ! $product ) {
			return;
		}

		$sku = $product->get_sku();

		if ( '' === $sku ) {
			return;
		}

		$all_variations = isset( $_POST['bpi_branch_var'] ) ? wp_unslash( $_POST['bpi_branch_var'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		if ( ! is_array( $all_variations ) || ! isset( $all_variations[ $loop ] ) ) {
			return;
		}

		self::save_branch_rows( $sku, $all_variations[ $loop ] );
	}

	/**
	 * Persist posted branch rows for one SKU.
	 *
	 * @param string $sku   Product SKU.
	 * @param array  $rows  Posted branch data keyed by branch_id.
	 * @return void
	 */
	private static function save_branch_rows( $sku, $rows ) {
		$branches = BPI_Branches::get_branches();

		foreach ( $branches as $branch_id => $branch ) {
			if ( ! isset( $rows[ $branch_id ] ) || ! is_array( $rows[ $branch_id ] ) ) {
				continue;
			}

			$row    = $rows[ $branch_id ];
			$status = isset( $row['status'] ) ? sanitize_key( $row['status'] ) : 'unknown';

			if ( 'unknown' === $status ) {
				BPI_Inventory::delete_row( $sku, $branch_id );
				continue;
			}

			BPI_Inventory::upsert_row( $sku, $branch_id, $status );
		}
	}
}
