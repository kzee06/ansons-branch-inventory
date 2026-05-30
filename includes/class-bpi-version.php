<?php
/**
 * Plugin version and changelog helpers.
 *
 * @package ORDDD_Branch_Pickup_Inventory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Version and changelog utilities.
 */
class BPI_Version {

	/**
	 * Get the plugin version string.
	 *
	 * @return string
	 */
	public static function get_version() {
		return BPI_VERSION;
	}

	/**
	 * Parse changelog.txt into version => bullet list entries.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function get_changelog() {
		$file = BPI_PLUGIN_DIR . 'changelog.txt';

		if ( ! is_readable( $file ) ) {
			return array();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$contents = file_get_contents( $file );

		if ( false === $contents || '' === trim( $contents ) ) {
			return array();
		}

		$entries  = array();
		$current  = '';
		$lines    = preg_split( '/\r\n|\r|\n/', $contents );

		foreach ( $lines as $line ) {
			$line = trim( $line );

			if ( '' === $line ) {
				continue;
			}

			if ( preg_match( '/^=\s*(.+?)\s*=$/', $line, $matches ) ) {
				$current = trim( $matches[1] );
				if ( ! isset( $entries[ $current ] ) ) {
					$entries[ $current ] = array();
				}
				continue;
			}

			if ( '' === $current ) {
				continue;
			}

			if ( 0 === strpos( $line, '* ' ) ) {
				$entries[ $current ][] = substr( $line, 2 );
			}
		}

		return $entries;
	}
}
