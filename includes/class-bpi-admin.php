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
			Ansons_Tools_Menu::SLUG,
			BPI_PLUGIN_NAME,
			__( 'Branch Inventory', 'orddd-branch-pickup-inventory' ),
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
		if ( Ansons_Tools_Menu::SLUG . '_page_' . self::MENU_SLUG !== $hook ) {
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
					<p class="bpi-admin__tagline">
						<?php
						printf(
							/* translators: %s: required plugin name */
							esc_html__( 'Sync branch pickup availability from CSV. Works with SAP exports or a downloadable WooCommerce template. Requires %s.', 'orddd-branch-pickup-inventory' ),
							esc_html( BPI_REQUIRED_ORDDD_NAME )
						);
						?>
					</p>
					<p class="bpi-admin__author">
						<?php
						echo wp_kses(
							sprintf(
								/* translators: 1: author profile URL, 2: author name */
								__( 'by <a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>', 'orddd-branch-pickup-inventory' ),
								esc_url( BPI_PLUGIN_AUTHOR_URI ),
								esc_html( BPI_PLUGIN_AUTHOR )
							),
							array(
								'a' => array(
									'href'   => array(),
									'target' => array(),
									'rel'    => array(),
								),
							)
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

			<div class="bpi-stats">
				<div class="bpi-stat">
					<span class="bpi-stat__label"><?php esc_html_e( 'Inventory rows', 'orddd-branch-pickup-inventory' ); ?></span>
					<span class="bpi-stat__value"><?php echo esc_html( (string) BPI_Inventory::count_rows() ); ?></span>
				</div>
				<div class="bpi-stat">
					<span class="bpi-stat__label"><?php esc_html_e( 'Last updated', 'orddd-branch-pickup-inventory' ); ?></span>
					<span class="bpi-stat__value bpi-stat__value--sm"><?php echo esc_html( BPI_Inventory::last_updated() ?: __( 'Never', 'orddd-branch-pickup-inventory' ) ); ?></span>
				</div>
				<div class="bpi-stat">
					<span class="bpi-stat__label"><?php esc_html_e( 'Last import', 'orddd-branch-pickup-inventory' ); ?></span>
					<span class="bpi-stat__value bpi-stat__value--sm">
						<?php
						if ( ! empty( $import_log['imported'] ) || ! empty( $import_log['time'] ) ) {
							echo esc_html(
								sprintf(
									/* translators: 1: imported count, 2: import time */
									__( '%1$d rows · %2$s', 'orddd-branch-pickup-inventory' ),
									(int) ( $import_log['imported'] ?? 0 ),
									$import_log['time'] ?? ''
								)
							);
						} else {
							esc_html_e( 'No imports yet', 'orddd-branch-pickup-inventory' );
						}
						?>
					</span>
				</div>
				<div class="bpi-stat">
					<span class="bpi-stat__label"><?php esc_html_e( 'Pickup branches', 'orddd-branch-pickup-inventory' ); ?></span>
					<span class="bpi-stat__value"><?php echo esc_html( (string) count( $branches ) ); ?></span>
				</div>
			</div>

			<div class="bpi-admin__top">
				<div class="bpi-admin__main">
				<div class="bpi-card">
					<div class="bpi-card__head">
						<h2><?php esc_html_e( 'Import inventory', 'orddd-branch-pickup-inventory' ); ?></h2>
						<p class="bpi-card__subtitle">
							<?php esc_html_e( 'Choose how you prepare your CSV, then upload it below. Both formats use the same import button.', 'orddd-branch-pickup-inventory' ); ?>
						</p>
					</div>

					<div class="bpi-import-paths">
						<div class="bpi-path bpi-path--recommended">
							<span class="bpi-path__badge"><?php esc_html_e( 'Recommended', 'orddd-branch-pickup-inventory' ); ?></span>
							<h3 class="bpi-path__title"><?php esc_html_e( '1. SAP export', 'orddd-branch-pickup-inventory' ); ?></h3>
							<p class="bpi-path__text">
								<?php
								printf(
									/* translators: 1: minimum stock quantity, 2: same minimum for below threshold */
									esc_html__( 'Export inventory from SAP and upload the file here — no editing needed. Only WooCommerce SKUs are updated. Set the minimum stock below before importing: qty %1$d+ = Available, below %2$d = Not available.', 'orddd-branch-pickup-inventory' ),
									(int) BPI_Inventory::get_sap_min_stock(),
									(int) BPI_Inventory::get_sap_min_stock()
								);
								?>
							</p>
							<ol class="bpi-path__steps">
								<li><?php esc_html_e( 'Export CSV from SAP (Itemcode, WhsCode, Available)', 'orddd-branch-pickup-inventory' ); ?></li>
								<li><?php esc_html_e( 'Map WhsCode values to branches below (several codes can share one branch)', 'orddd-branch-pickup-inventory' ); ?></li>
								<li><?php esc_html_e( 'Upload the file using the form below', 'orddd-branch-pickup-inventory' ); ?></li>
							</ol>
							<pre class="bpi-code bpi-code--inline">Itemcode,Itemname,WhsCode,WhsName,Available</pre>
						</div>

						<div class="bpi-path">
							<h3 class="bpi-path__title"><?php esc_html_e( '2. WooCommerce template', 'orddd-branch-pickup-inventory' ); ?></h3>
							<p class="bpi-path__text">
								<?php esc_html_e( 'Download a pre-filled spreadsheet with all your WooCommerce SKUs and branches. Set each row to available or not_available, then upload it.', 'orddd-branch-pickup-inventory' ); ?>
							</p>
							<ol class="bpi-path__steps">
								<li><?php esc_html_e( 'Download the SKU template', 'orddd-branch-pickup-inventory' ); ?></li>
								<li><?php esc_html_e( 'Edit status per SKU and branch', 'orddd-branch-pickup-inventory' ); ?></li>
								<li><?php esc_html_e( 'Upload the saved CSV below', 'orddd-branch-pickup-inventory' ); ?></li>
							</ol>
							<div class="bpi-path__action">
								<a class="button button-secondary" href="<?php echo esc_url( BPI_Template_Exporter::get_download_url() ); ?>">
									<?php esc_html_e( 'Download SKU template', 'orddd-branch-pickup-inventory' ); ?>
								</a>
							</div>
							<p class="bpi-path__meta">
								<?php
								printf(
									/* translators: 1: SKU count, 2: branch count, 3: total rows */
									esc_html__( '%1$d SKUs × %2$d branches (%3$d rows). Defaults to not_available.', 'orddd-branch-pickup-inventory' ),
									count( BPI_Template_Exporter::get_woocommerce_skus() ),
									max( 1, count( BPI_Template_Exporter::get_branch_store_codes() ) ),
									BPI_Template_Exporter::count_template_rows()
								);
								?>
							</p>
							<pre class="bpi-code bpi-code--inline">sku,store_code,status</pre>
						</div>
					</div>

					<div class="bpi-upload">
						<form method="post" enctype="multipart/form-data" class="bpi-upload__form">
							<?php wp_nonce_field( 'bpi_import_csv', 'bpi_import_nonce' ); ?>
							<input type="hidden" name="bpi_action" value="import_csv" />

							<div class="bpi-upload__fields">
								<div class="bpi-field">
									<label for="bpi_sap_min_stock"><?php esc_html_e( 'Minimum stock for Available (SAP imports)', 'orddd-branch-pickup-inventory' ); ?></label>
									<input
										type="number"
										min="0"
										step="1"
										name="bpi_sap_min_stock"
										id="bpi_sap_min_stock"
										value="<?php echo esc_attr( (string) BPI_Inventory::get_sap_min_stock() ); ?>"
										required
									/>
									<p class="description">
										<?php esc_html_e( 'Set this before uploading. SAP rows with Available qty at or above this number are marked Available; below it = Not available. WooCommerce template CSVs ignore this and use the status column instead.', 'orddd-branch-pickup-inventory' ); ?>
									</p>
								</div>

								<div class="bpi-field">
									<label for="bpi_csv_file"><?php esc_html_e( 'CSV file', 'orddd-branch-pickup-inventory' ); ?></label>
									<input type="file" name="bpi_csv_file" id="bpi_csv_file" accept=".csv,text/csv" required />
								</div>

								<div class="bpi-field">
									<label for="bpi_import_mode"><?php esc_html_e( 'Import mode', 'orddd-branch-pickup-inventory' ); ?></label>
									<select name="bpi_import_mode" id="bpi_import_mode">
										<option value="merge"><?php esc_html_e( 'Merge — update matching rows, keep others', 'orddd-branch-pickup-inventory' ); ?></option>
										<option value="replace"><?php esc_html_e( 'Replace — clear all inventory first', 'orddd-branch-pickup-inventory' ); ?></option>
									</select>
								</div>

								<div class="bpi-field bpi-field--action">
									<?php submit_button( __( 'Upload & import', 'orddd-branch-pickup-inventory' ), 'primary', 'submit', false ); ?>
								</div>
							</div>
						</form>
					</div>
				</div>
				</div>

				<aside class="bpi-admin__sidebar">
				<div class="bpi-card">
					<div class="bpi-card__head">
						<h2><?php esc_html_e( 'Settings', 'orddd-branch-pickup-inventory' ); ?></h2>
						<p class="bpi-card__subtitle"><?php esc_html_e( 'Control how availability appears on your store.', 'orddd-branch-pickup-inventory' ); ?></p>
					</div>
					<form method="post">
						<?php wp_nonce_field( 'bpi_save_settings', 'bpi_settings_nonce' ); ?>
						<input type="hidden" name="bpi_action" value="save_settings" />

						<div class="bpi-toggle-list">
							<label class="bpi-toggle">
								<input type="checkbox" name="bpi_block_checkout" value="yes" <?php checked( $settings['block_checkout'], 'yes' ); ?> />
								<span class="bpi-toggle__body">
									<strong><?php esc_html_e( 'Block checkout', 'orddd-branch-pickup-inventory' ); ?></strong>
									<span><?php esc_html_e( 'Prevent orders when cart items are not available at the selected pickup branch.', 'orddd-branch-pickup-inventory' ); ?></span>
								</span>
							</label>

							<label class="bpi-toggle">
								<input type="checkbox" name="bpi_show_on_product" value="yes" <?php checked( $settings['show_on_product'], 'yes' ); ?> />
								<span class="bpi-toggle__body">
									<strong><?php esc_html_e( 'Show on product pages', 'orddd-branch-pickup-inventory' ); ?></strong>
									<span><?php esc_html_e( 'Display branch availability on each product page.', 'orddd-branch-pickup-inventory' ); ?></span>
								</span>
							</label>
						</div>

						<?php submit_button( __( 'Save settings', 'orddd-branch-pickup-inventory' ) ); ?>
					</form>
				</div>

				<?php if ( ! empty( $import_log ) ) : ?>
				<div class="bpi-card bpi-card--compact">
					<div class="bpi-card__head">
						<h2><?php esc_html_e( 'Last import', 'orddd-branch-pickup-inventory' ); ?></h2>
					</div>
					<ul class="bpi-import-summary bpi-import-summary--compact">
						<li>
							<strong><?php echo esc_html( (string) (int) ( $import_log['imported'] ?? 0 ) ); ?></strong>
							<?php esc_html_e( 'Imported', 'orddd-branch-pickup-inventory' ); ?>
						</li>
						<?php if ( ! empty( $import_log['ignored'] ) ) : ?>
						<li>
							<strong><?php echo esc_html( (string) (int) $import_log['ignored'] ); ?></strong>
							<?php esc_html_e( 'Ignored', 'orddd-branch-pickup-inventory' ); ?>
						</li>
						<?php endif; ?>
						<li>
							<strong><?php echo esc_html( (string) (int) ( $import_log['skipped'] ?? 0 ) ); ?></strong>
							<?php esc_html_e( 'Skipped', 'orddd-branch-pickup-inventory' ); ?>
						</li>
					</ul>
					<p class="bpi-import-meta">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: format label, 2: import time */
								__( '%1$s · %2$s', 'orddd-branch-pickup-inventory' ),
								'sap' === ( $import_log['format'] ?? '' ) ? 'SAP' : __( 'Template', 'orddd-branch-pickup-inventory' ),
								$import_log['time'] ?? ''
							)
						);
						?>
					</p>
					<?php if ( ! empty( $import_log['errors'] ) ) : ?>
						<details class="bpi-details">
							<summary><?php esc_html_e( 'Skipped row messages', 'orddd-branch-pickup-inventory' ); ?></summary>
							<div class="bpi-details__body">
								<ul class="bpi-error-list">
									<?php foreach ( $import_log['errors'] as $error ) : ?>
										<li><?php echo esc_html( $error ); ?></li>
									<?php endforeach; ?>
								</ul>
							</div>
						</details>
					<?php endif; ?>
				</div>
				<?php endif; ?>
				</aside>
			</div>

			<div class="bpi-admin__stack">
				<div class="bpi-card">
					<div class="bpi-card__head">
						<h2><?php esc_html_e( 'Store code mapping', 'orddd-branch-pickup-inventory' ); ?></h2>
						<p class="bpi-card__subtitle">
							<?php esc_html_e( 'Map SAP WhsCode values (e.g. 110, 120) to pickup branches. Multiple WhsCodes can point to the same branch — stock from all mapped codes is combined during SAP import.', 'orddd-branch-pickup-inventory' ); ?>
						</p>
					</div>

					<form method="post">
						<?php wp_nonce_field( 'bpi_save_store_map', 'bpi_store_map_nonce' ); ?>
						<input type="hidden" name="bpi_action" value="save_store_map" />

						<table class="widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Store code / SAP WhsCode', 'orddd-branch-pickup-inventory' ); ?></th>
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
											<input type="text" name="bpi_store_code[]" value="<?php echo esc_attr( $code ); ?>" placeholder="110" />
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

				<div class="bpi-card">
					<details class="bpi-details">
						<summary><?php esc_html_e( 'ORDDD branches reference', 'orddd-branch-pickup-inventory' ); ?></summary>
						<div class="bpi-details__body">
							<?php if ( empty( $branches ) ) : ?>
								<p><?php esc_html_e( 'No pickup locations found. Add branches in Order Delivery Date → Pickup Locations first.', 'orddd-branch-pickup-inventory' ); ?></p>
							<?php else : ?>
								<table class="widefat striped">
									<thead>
										<tr>
											<th><?php esc_html_e( 'Branch', 'orddd-branch-pickup-inventory' ); ?></th>
											<th><?php esc_html_e( 'branch_id (use in template CSV)', 'orddd-branch-pickup-inventory' ); ?></th>
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
					</details>
				</div>

				<?php if ( ! empty( $changelog ) ) : ?>
				<div class="bpi-card">
					<details class="bpi-details">
						<summary><?php esc_html_e( 'Changelog', 'orddd-branch-pickup-inventory' ); ?></summary>
						<div class="bpi-details__body">
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
					</details>
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

				if ( isset( $_POST['bpi_sap_min_stock'] ) && '' !== trim( (string) wp_unslash( $_POST['bpi_sap_min_stock'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
					BPI_Inventory::set_sap_min_stock( wp_unslash( $_POST['bpi_sap_min_stock'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
				}

				$mode   = isset( $_POST['bpi_import_mode'] ) ? sanitize_key( wp_unslash( $_POST['bpi_import_mode'] ) ) : 'merge'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$result = BPI_CSV_Importer::import_file( sanitize_text_field( wp_unslash( $_FILES['bpi_csv_file']['tmp_name'] ) ), $mode ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

				return array(
					'type'    => $result['success'] ? 'success' : 'error',
					'message' => $result['message'],
				);

			case 'save_settings':
				check_admin_referer( 'bpi_save_settings', 'bpi_settings_nonce' );

				$current  = BPI_Inventory::get_settings();
				$settings = array(
					'block_checkout'  => isset( $_POST['bpi_block_checkout'] ) ? 'yes' : 'no', // phpcs:ignore WordPress.Security.NonceVerification.Missing
					'show_on_product' => isset( $_POST['bpi_show_on_product'] ) ? 'yes' : 'no', // phpcs:ignore WordPress.Security.NonceVerification.Missing
					'sap_min_stock'   => $current['sap_min_stock'],
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
					$code      = trim( sanitize_text_field( $code ) );
					$branch_id = isset( $branch_ids[ $index ] ) ? sanitize_text_field( $branch_ids[ $index ] ) : '';

					if ( '' === $code || '' === $branch_id ) {
						continue;
					}

					// Preserve numeric SAP WhsCode values; uppercase text store codes.
					if ( ! is_numeric( $code ) ) {
						$code = strtoupper( $code );
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
