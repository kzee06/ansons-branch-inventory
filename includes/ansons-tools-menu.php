<?php
/**
 * Shared "Ansons Tools" admin menu.
 *
 * Bundled identically in every Ansons custom plugin. The class_exists() guard
 * means whichever plugin loads first defines the class and registers the parent
 * menu; the others simply reuse it. The "Ansons Tools" top-level menu is created
 * only once, and each plugin attaches its own submenu beneath it.
 *
 * This mirrors the way YITH's shared framework groups all of their plugins under
 * a single top-level menu.
 *
 * To add another Ansons plugin to the menu:
 *   1. Copy this file into the plugin and require it.
 *   2. Call Ansons_Tools_Menu::boot() once on load.
 *   3. Register the plugin page with:
 *        add_submenu_page( Ansons_Tools_Menu::SLUG, ... );
 *      on admin_menu at the default priority (10).
 *
 * @package AnsonsTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Ansons_Tools_Menu' ) ) {

	/**
	 * Registers and owns the shared "Ansons Tools" parent menu.
	 */
	class Ansons_Tools_Menu {

		/**
		 * Shared parent menu slug. Every Ansons plugin attaches submenus here.
		 */
		const SLUG = 'ansons-tools';

		/**
		 * Capability required to see the parent menu.
		 *
		 * manage_woocommerce is held by administrators and shop managers, so the
		 * menu is visible to all relevant staff. Individual submenus still gate
		 * themselves with their own capabilities.
		 */
		const CAPABILITY = 'manage_woocommerce';

		/**
		 * Whether boot() has already wired up its hooks this request.
		 *
		 * @var bool
		 */
		protected static $booted = false;

		/**
		 * Register the parent-menu hook. Safe to call from multiple plugins.
		 */
		public static function boot() {
			if ( self::$booted ) {
				return;
			}
			self::$booted = true;

			// Priority 9 so the parent exists before plugins add submenus at 10.
			add_action( 'admin_menu', array( __CLASS__, 'register_parent' ), 9 );

			// Late priority: after every plugin has added its submenu, make the
			// parent open the first tool instead of a separate landing page.
			add_action( 'admin_menu', array( __CLASS__, 'collapse_landing' ), 9999 );
		}

		/**
		 * Register the top-level menu once.
		 */
		public static function register_parent() {
			if ( ! empty( $GLOBALS['admin_page_hooks'][ self::SLUG ] ) ) {
				return;
			}

			add_menu_page(
				__( 'Ansons Tools', 'ansons-tools' ),
				__( 'Ansons Tools', 'ansons-tools' ),
				self::CAPABILITY,
				self::SLUG,
				array( __CLASS__, 'render_landing' ),
				'dashicons-admin-tools',
				58
			);
		}

		/**
		 * Remove the duplicate "Ansons Tools" landing item and point the parent
		 * menu at its first accessible submenu, so clicking the top-level item
		 * opens the first tool directly.
		 */
		public static function collapse_landing() {
			global $menu, $submenu;

			if ( empty( $submenu[ self::SLUG ] ) || ! is_array( $submenu[ self::SLUG ] ) ) {
				return;
			}

			$items = array_values( $submenu[ self::SLUG ] );

			// Prefer the first submenu the current user can actually access.
			$target_index = null;
			foreach ( $items as $i => $item ) {
				if ( ! empty( $item[2] ) && ! empty( $item[1] ) && current_user_can( $item[1] ) ) {
					$target_index = $i;
					break;
				}
			}
			if ( null === $target_index ) {
				$target_index = 0;
			}

			$target      = $items[ $target_index ];
			$target_slug = $target[2];

			// Move the target to the front so its slug matches the parent slug
			// and WordPress does not render a separate duplicate link.
			unset( $items[ $target_index ] );
			array_unshift( $items, $target );

			$submenu[ $target_slug ] = $items;
			if ( $target_slug !== self::SLUG ) {
				unset( $submenu[ self::SLUG ] );
			}

			if ( is_array( $menu ) ) {
				foreach ( $menu as $key => $item ) {
					if ( isset( $item[2] ) && self::SLUG === $item[2] ) {
						$menu[ $key ][2] = $target_slug;
						break;
					}
				}
			}
		}

		/**
		 * Fallback page for direct access to the parent slug. Not linked in the
		 * menu once collapse_landing() has run.
		 */
		public static function render_landing() {
			echo '<div class="wrap"><h1>' . esc_html__( 'Ansons Tools', 'ansons-tools' ) . '</h1></div>';
		}
	}
}
