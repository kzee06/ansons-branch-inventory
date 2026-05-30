<?php
/**
 * Admin screens and CSV upload.
 *
 * @package ORDDD_Branch_Pickup_Inventory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI.
 */
class BPI_Admin {

	/**
	 * Menu slug.
	 */
	const MENU_SLUG = 'bpi-branch-inventory';

	/**
	 * Init hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_init', array( 'BPI_Template_Exporter', 'maybe_download' ) );
	}

	/**
	 * Register WooCommerce submenu.
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_submenu_page(
			'woocommerce',
			BPI_PLUGIN_NAME,
			BPI_PLUGIN_NAME,
			'manage_woocommerce',
			self::MENU_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueue admin CSS on plugin page only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function enqueue_assets( $hook ) {
		if ( 'woocommerce_page_' . self::MENU_SLUG !== $hook ) {
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
	 * Handle form submissions then render page.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'orddd-branch-pickup-inventory' ) );
		}

		$notice = self::handle_post();

		$settings = BPI_Inventory::get_settings();
		$branches = BPI_Branches::get_branches();
		$store_map = get_option( BPI_Database::STORE_CODES_OPTION, array() );
		$import_log = get_option( BPI_Database::IMPORT_LOG_OPTION, array() );

		if ( ! is_array( $store_map ) ) {
			$store_map = array();
		}

		if ( ! is_array( $import_log ) ) {
			$import_log = array();
		}

		$changelog = BPI_Version::get_changelog();

		?>
		<div class="wrap bpi-admin">
			<div class="bpi-admin__header">
				<div class="bpi-admin__title-wrap">
					<h1><?php echo esc_html( BPI_PLUGIN_NAME ); ?></h1>
					<p class="bpi-admin__author">
						<?php
						printf(
							/* translators: %s: plugin author name */
							esc_html__( 'by %s', 'orddd-branch-pickup-inventory' ),
							esc_html( BPI_PLUGIN_AUTHOR )
						);
						?>
					</p>
				</div>
				<span class="bpi-version-badge">
					<?php
					printf(
						/* translators: %s: plugin version number */
						esc_html__( 'Version %s', 'orddd-branch-pickup-inventory' ),
						esc_html( BPI_Version::get_version() )
					);
					?>
				</span>
			</div>
			<p class="description">
				<?php
				printf(
					/* translators: %s: required plugin name */
					esc_html__( 'Import store stock from CSV to show pickup availability per branch. Requires %s and WooCommerce.', 'orddd-branch-pickup-inventory' ),
					esc_html( BPI_REQUIRED_ORDDD_NAME )
				);
				?>
			</p>

			<?php if ( ! ORDDD_Branch_Pickup_Inventory::instance()->is_orddd_active() ) : ?>
				<div class="notice notice-error inline">
					<p>
						<?php
						printf(
							/* translators: %s: required plugin name */
							esc_html__( 'Order Delivery Date Pro is not active. Pickup branches and checkout integration will not work until %s is enabled.', 'orddd-branch-pickup-inventory' ),
							esc_html( BPI_REQUIRED_ORDDD_NAME )
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
					<p><?php echo esc_html( $notice['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<div class="bpi-admin__grid">
				<div class="bpi-card">
					<h2><?php esc_html_e( 'Import CSV', 'orddd-branch-pickup-inventory' ); ?></h2>
					<form method="post" enctype="multipart/form-data">
						<?php wp_nonce_field( 'bpi_import_csv', 'bpi_import_nonce' ); ?>
						<input type="hidden" name="bpi_action" value="import_csv" />

						<p>
							<label for="bpi_csv_file"><strong><?php esc_html_e( 'CSV file', 'orddd-branch-pickup-inventory' ); ?></strong></label><br />
							<input type="file" name="bpi_csv_file" id="bpi_csv_file" accept=".csv,text/csv" required />
						</p>

						<p>
							<label for="bpi_import_mode"><strong><?php esc_html_e( 'Import mode', 'orddd-branch-pickup-inventory' ); ?></strong></label><br />
							<select name="bpi_import_mode" id="bpi_import_mode">
								<option value="merge"><?php esc_html_e( 'Merge — update matching rows, keep others', 'orddd-branch-pickup-inventory' ); ?></option>
								<option value="replace"><?php esc_html_e( 'Replace — delete all existing data first', 'orddd-branch-pickup-inventory' ); ?></option>
							</select>
						</p>

						<?php submit_button( __( 'Import CSV', 'orddd-branch-pickup-inventory' ) ); ?>
					</form>

					<p>
						<a class="button button-secondary" href="<?php echo esc_url( BPI_PLUGIN_URL . 'sample-import.csv' ); ?>" download>
							<?php esc_html_e( 'Download sample CSV', 'orddd-branch-pickup-inventory' ); ?>
						</a>
						<a class="button button-primary" href="<?php echo esc_url( BPI_Template_Exporter::get_download_url() ); ?>">
							<?php esc_html_e( 'Download WooCommerce SKU template', 'orddd-branch-pickup-inventory' ); ?>
						</a>
					</p>
					<p class="description">
						<?php
						printf(
							/* translators: 1: SKU count, 2: branch count, 3: total rows */
							esc_html__( 'SKU template includes %1$d WooCommerce SKUs × %2$d branches (%3$d rows). Every row starts as not_available — update to available where the item can be picked up at that store.', 'orddd-branch-pickup-inventory' ),
							count( BPI_Template_Exporter::get_woocommerce_skus() ),
							max( 1, count( BPI_Template_Exporter::get_branch_store_codes() ) ),
							BPI_Template_Exporter::count_template_rows()
						);
						?>
					</p>

					<h3><?php esc_html_e( 'CSV format', 'orddd-branch-pickup-inventory' ); ?></h3>
					<pre class="bpi-code">sku,store_code,status
WIDGET-001,MANILA,available
WIDGET-001,CEBU,not_available
WIDGET-002,MANILA,available</pre>
					<p class="description">
						<?php esc_html_e( 'Use store_code (mapped below) or branch_id (ORDDD row_id). Status must be available or not_available.', 'orddd-branch-pickup-inventory' ); ?>
					</p>
				</div>

				<div class="bpi-card">
					<h2><?php esc_html_e( 'Settings', 'orddd-branch-pickup-inventory' ); ?></h2>
					<form method="post">
						<?php wp_nonce_field( 'bpi_save_settings', 'bpi_settings_nonce' ); ?>
						<input type="hidden" name="bpi_action" value="save_settings" />

						<p>
							<label>
								<input type="checkbox" name="bpi_block_checkout" value="yes" <?php checked( $settings['block_checkout'], 'yes' ); ?> />
								<?php esc_html_e( 'Block checkout when cart items are not available at selected branch', 'orddd-branch-pickup-inventory' ); ?>
							</label>
						</p>

						<p>
							<label>
								<input type="checkbox" name="bpi_show_on_product" value="yes" <?php checked( $settings['show_on_product'], 'yes' ); ?> />
								<?php esc_html_e( 'Show branch availability on product pages', 'orddd-branch-pickup-inventory' ); ?>
							</label>
						</p>

						<?php submit_button( __( 'Save settings', 'orddd-branch-pickup-inventory' ) ); ?>
					</form>
				</div>

				<div class="bpi-card bpi-card--wide">
					<h2><?php esc_html_e( 'Store code mapping', 'orddd-branch-pickup-inventory' ); ?></h2>
					<p class="description">
						<?php esc_html_e( 'Map your POS store codes to ORDDD pickup branches so CSV exports can use familiar store names.', 'orddd-branch-pickup-inventory' ); ?>
					</p>

					<form method="post">
						<?php wp_nonce_field( 'bpi_save_store_map', 'bpi_store_map_nonce' ); ?>
						<input type="hidden" name="bpi_action" value="save_store_map" />

						<table class="widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Store code (from POS/CSV)', 'orddd-branch-pickup-inventory' ); ?></th>
									<th><?php esc_html_e( 'ORDDD branch', 'orddd-branch-pickup-inventory' ); ?></th>
									<th><?php esc_html_e( 'Branch ID (row_id)', 'orddd-branch-pickup-inventory' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php
								$rows = max( 3, count( $store_map ) + 1 );
								for ( $i = 0; $i < $rows; $i++ ) :
									$codes     = array_keys( $store_map );
									$code      = isset( $codes[ $i ] ) ? (string) $codes[ $i ] : '';
									$branch_id = $code ? (string) $store_map[ $code ] : '';
									?>
									<tr>
										<td>
											<input type="text" name="bpi_store_code[]" value="<?php echo esc_attr( $code ); ?>" placeholder="MANILA" />
										</td>
										<td>
											<select name="bpi_branch_id[]">
												<option value=""><?php esc_html_e( '— Select branch —', 'orddd-branch-pickup-inventory' ); ?></option>
												<?php foreach ( $branches as $id => $branch ) : ?>
													<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $branch_id, $id ); ?>>
														<?php echo esc_html( $branch['label'] ); ?>
													</option>
												<?php endforeach; ?>
											</select>
										</td>
										<td><code><?php echo esc_html( $branch_id ); ?></code></td>
									</tr>
								<?php endfor; ?>
							</tbody>
						</table>

						<?php submit_button( __( 'Save store mapping', 'orddd-branch-pickup-inventory' ) ); ?>
					</form>
				</div>

				<div class="bpi-card bpi-card--wide">
					<h2><?php esc_html_e( 'ORDDD branches reference', 'orddd-branch-pickup-inventory' ); ?></h2>
					<?php if ( empty( $branches ) ) : ?>
						<p><?php esc_html_e( 'No pickup locations found. Add branches in Order Delivery Date → Pickup Locations first.', 'orddd-branch-pickup-inventory' ); ?></p>
					<?php else : ?>
						<table class="widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Branch', 'orddd-branch-pickup-inventory' ); ?></th>
									<th><?php esc_html_e( 'branch_id (use in CSV)', 'orddd-branch-pickup-inventory' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $branches as $id => $branch ) : ?>
									<tr>
										<td><?php echo esc_html( $branch['label'] ); ?></td>
										<td><code><?php echo esc_html( $id ); ?></code></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>

				<div class="bpi-card">
					<h2><?php esc_html_e( 'Inventory status', 'orddd-branch-pickup-inventory' ); ?></h2>
					<ul>
						<li><strong><?php esc_html_e( 'Rows:', 'orddd-branch-pickup-inventory' ); ?></strong> <?php echo esc_html( (string) BPI_Inventory::count_rows() ); ?></li>
						<li><strong><?php esc_html_e( 'Last updated:', 'orddd-branch-pickup-inventory' ); ?></strong>
							<?php echo esc_html( BPI_Inventory::last_updated() ?: __( 'Never', 'orddd-branch-pickup-inventory' ) ); ?>
						</li>
					</ul>

					<?php if ( ! empty( $import_log ) ) : ?>
						<h3><?php esc_html_e( 'Last import', 'orddd-branch-pickup-inventory' ); ?></h3>
						<ul>
							<li><?php echo esc_html( sprintf( __( 'Time: %s', 'orddd-branch-pickup-inventory' ), $import_log['time'] ?? '' ) ); ?></li>
							<li><?php echo esc_html( sprintf( __( 'Imported: %d', 'orddd-branch-pickup-inventory' ), (int) ( $import_log['imported'] ?? 0 ) ) ); ?></li>
							<li><?php echo esc_html( sprintf( __( 'Skipped: %d', 'orddd-branch-pickup-inventory' ), (int) ( $import_log['skipped'] ?? 0 ) ) ); ?></li>
						</ul>
						<?php if ( ! empty( $import_log['errors'] ) ) : ?>
							<details>
								<summary><?php esc_html_e( 'View skipped row messages', 'orddd-branch-pickup-inventory' ); ?></summary>
								<ul class="bpi-error-list">
									<?php foreach ( $import_log['errors'] as $error ) : ?>
										<li><?php echo esc_html( $error ); ?></li>
									<?php endforeach; ?>
								</ul>
							</details>
						<?php endif; ?>
					<?php endif; ?>
				</div>

				<?php if ( ! empty( $changelog ) ) : ?>
				<div class="bpi-card bpi-card--wide">
					<h2><?php esc_html_e( 'Changelog', 'orddd-branch-pickup-inventory' ); ?></h2>
					<div class="bpi-changelog">
						<?php foreach ( $changelog as $version => $items ) : ?>
							<div class="bpi-changelog__release">
								<h3 class="bpi-changelog__version">
									<?php echo esc_html( $version ); ?>
									<?php if ( BPI_Version::get_version() === $version ) : ?>
										<span class="bpi-changelog__current"><?php esc_html_e( 'Current', 'orddd-branch-pickup-inventory' ); ?></span>
									<?php endif; ?>
								</h3>
								<?php if ( ! empty( $items ) ) : ?>
									<ul>
										<?php foreach ( $items as $item ) : ?>
											<li><?php echo esc_html( $item ); ?></li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Process admin POST actions.
	 *
	 * @return array{type: string, message: string}|null
	 */
	private static function handle_post() {
		if ( empty( $_POST['bpi_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return null;
		}

		$action = sanitize_key( wp_unslash( $_POST['bpi_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		switch ( $action ) {
			case 'import_csv':
				check_admin_referer( 'bpi_import_csv', 'bpi_import_nonce' );

				if ( empty( $_FILES['bpi_csv_file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
					return array(
						'type'    => 'error',
						'message' => __( 'Please choose a CSV file to upload.', 'orddd-branch-pickup-inventory' ),
					);
				}

				$mode   = isset( $_POST['bpi_import_mode'] ) ? sanitize_key( wp_unslash( $_POST['bpi_import_mode'] ) ) : 'merge'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$result = BPI_CSV_Importer::import_file( sanitize_text_field( wp_unslash( $_FILES['bpi_csv_file']['tmp_name'] ) ), $mode ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

				return array(
					'type'    => $result['success'] ? 'success' : 'error',
					'message' => $result['message'],
				);

			case 'save_settings':
				check_admin_referer( 'bpi_save_settings', 'bpi_settings_nonce' );

				$settings = array(
					'block_checkout'  => isset( $_POST['bpi_block_checkout'] ) ? 'yes' : 'no', // phpcs:ignore WordPress.Security.NonceVerification.Missing
					'show_on_product' => isset( $_POST['bpi_show_on_product'] ) ? 'yes' : 'no', // phpcs:ignore WordPress.Security.NonceVerification.Missing
				);

				update_option( BPI_Database::SETTINGS_OPTION, $settings );

				return array(
					'type'    => 'success',
					'message' => __( 'Settings saved.', 'orddd-branch-pickup-inventory' ),
				);

			case 'save_store_map':
				check_admin_referer( 'bpi_save_store_map', 'bpi_store_map_nonce' );

				$codes     = isset( $_POST['bpi_store_code'] ) ? (array) wp_unslash( $_POST['bpi_store_code'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$branch_ids = isset( $_POST['bpi_branch_id'] ) ? (array) wp_unslash( $_POST['bpi_branch_id'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$map       = array();

				foreach ( $codes as $index => $code ) {
					$code      = strtoupper( trim( sanitize_text_field( $code ) ) );
					$branch_id = isset( $branch_ids[ $index ] ) ? sanitize_text_field( $branch_ids[ $index ] ) : '';

					if ( '' === $code || '' === $branch_id ) {
						continue;
					}

					$map[ $code ] = $branch_id;
				}

				update_option( BPI_Database::STORE_CODES_OPTION, $map );

				return array(
					'type'    => 'success',
					'message' => __( 'Store code mapping saved.', 'orddd-branch-pickup-inventory' ),
				);
		}

		return null;
	}
}
