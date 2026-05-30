<?php
/**
 * Read ORDDD pickup locations as branches.
 *
 * @package ORDDD_Branch_Pickup_Inventory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Branch helpers.
 */
class BPI_Branches {

	/**
	 * Get enabled ORDDD pickup locations keyed by row_id.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_branches() {
		$branches  = array();
		$locations = get_option( 'orddd_locations', array() );

		if ( ! is_array( $locations ) ) {
			return $branches;
		}

		foreach ( $locations as $location ) {
			if ( ! is_array( $location ) || empty( $location['row_id'] ) ) {
				continue;
			}

			if ( isset( $location['enabled'] ) && 'no' === $location['enabled'] ) {
				continue;
			}

			$row_id = (string) $location['row_id'];

			$branches[ $row_id ] = array(
				'row_id'   => $row_id,
				'label'    => self::format_label( $location ),
				'address1' => isset( $location['address_1'] ) ? $location['address_1'] : ( $location['address1'] ?? '' ),
				'city'     => $location['city'] ?? '',
				'state'    => $location['state'] ?? '',
				'postcode' => $location['postcode'] ?? '',
			);
		}

		return $branches;
	}

	/**
	 * Format a human-readable branch label.
	 *
	 * @param array<string, mixed> $location Location row.
	 * @return string
	 */
	public static function format_label( $location ) {
		if ( class_exists( 'orddd_locations' ) ) {
			return orddd_locations::orddd_get_formatted_address( $location, true );
		}

		$parts = array_filter(
			array(
				$location['address_1'] ?? ( $location['address1'] ?? '' ),
				$location['city'] ?? '',
				$location['postcode'] ?? '',
			)
		);

		return implode( ', ', $parts );
	}

	/**
	 * Resolve branch_id from ORDDD row_id or mapped store code.
	 *
	 * @param string $identifier Branch row_id or store code.
	 * @return string Empty string if not found.
	 */
	public static function resolve_branch_id( $identifier ) {
		$identifier = trim( (string) $identifier );

		if ( '' === $identifier ) {
			return '';
		}

		$branches = self::get_branches();

		if ( isset( $branches[ $identifier ] ) ) {
			return $identifier;
		}

		$map = get_option( BPI_Database::STORE_CODES_OPTION, array() );

		if ( ! is_array( $map ) ) {
			return '';
		}

		foreach ( $map as $store_code => $branch_id ) {
			if ( strcasecmp( (string) $store_code, $identifier ) === 0 && isset( $branches[ (string) $branch_id ] ) ) {
				return (string) $branch_id;
			}
		}

		return '';
	}

	/**
	 * Get branch label by row_id.
	 *
	 * @param string $branch_id Branch row_id.
	 * @return string
	 */
	public static function get_branch_label( $branch_id ) {
		$branches = self::get_branches();

		return $branches[ $branch_id ]['label'] ?? $branch_id;
	}
}
