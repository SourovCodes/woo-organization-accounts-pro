<?php
/**
 * Deactivation routine.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts;

defined( 'ABSPATH' ) || exit;

/**
 * Runs once when the plugin is deactivated.
 */
final class Deactivator {

	/**
	 * Tidy up what only makes sense while the plugin is running.
	 *
	 * The tables, the settings and the roles are all left in place: a
	 * deactivate/reactivate cycle must not cost a shop its organizations, and a member
	 * whose WordPress role vanished would be locked out of an account they still own.
	 * Removal belongs in uninstall.php, and even there it is opt-in.
	 *
	 * The rewrite rules do have to go. The My Account endpoints stop being handled the
	 * moment the plugin does, and leaving the rules behind would leave those URLs
	 * resolving to a blank account page rather than to an honest 404.
	 *
	 * @return void
	 */
	public static function deactivate() {
		flush_rewrite_rules();

		/**
		 * Fires after the plugin has finished its deactivation routine.
		 *
		 * @since 0.1.0
		 */
		do_action( 'woo_org_accounts_deactivated' );
	}
}
