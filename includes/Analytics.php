<?php
/**
 * WooCommerce Analytics integration.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts;

defined( 'ABSPATH' ) || exit;

/**
 * Tells WooCommerce Analytics that this plugin's roles belong to customers.
 *
 * WooCommerce → Customers does not read the users table. It reads `wc_customer_lookup`,
 * and a user reaches that table only if one of their WordPress roles appears in a list
 * WooCommerce defaults to `array( 'customer' )`. Every account this plugin creates holds
 * `woap_org_admin` or `woap_member` instead — a member is a customer of the shop but
 * never WooCommerce's `customer` role, because the role is what carries the account
 * screens this plugin replaces. On a shop whose customers are *all* organizations that
 * default empties the screen completely, which is what it did here.
 *
 * The lookup table also backs the Customers report, the customer filter on every other
 * report and the customer CSV export, so all four recover together.
 *
 * **There are two filters rather than one, and a fix needs both**, because a user is
 * written into that table by two different paths:
 *
 * - `woocommerce_analytics_import_customer_roles` is the historical backfill. It is a
 *   `role__in` on a user query, so it decides which of the accounts that *already exist*
 *   are ever considered.
 * - `woocommerce_analytics_customer_roles` is the ongoing check, asked once per user as
 *   they are synced. This plugin creates accounts with `wp_insert_user()` rather than
 *   `wc_create_new_customer()`, so the `woocommerce_new_customer` path never fires for
 *   them; they arrive instead through the `wc_last_active` meta write that WooCommerce
 *   makes on the first request a signed-in member serves.
 *
 * Filtering only the first would import today's accounts and then never add another;
 * filtering only the second would leave every account that predates the fix invisible.
 *
 * **Accounts that predate the fix mostly heal themselves.** WooCommerce writes
 * `wc_last_active` on `wp_login` immediately, and again as a signed-in user browses the
 * store, and that write is one of the sync paths above — so a member appears the next
 * time they sign in, without anybody doing anything. Only accounts that never sign in
 * again stay missing, and those need one pass of Analytics → Settings → *Import
 * historical data*. There is no Status → Tools entry and no wp-cli command for this;
 * `ReportsSync::regenerate_report_data()` is what that button calls.
 */
class Analytics {

	/**
	 * Register the hooks.
	 *
	 * Not gated on `is_admin()`, even though the screen it feeds is an admin one. The
	 * ongoing sync runs from the `wc_last_active` meta write, which happens on the
	 * frontend request a member makes while signed in, and a filter that is not
	 * registered there would let that request record them as not a customer.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'woocommerce_analytics_customer_roles', array( $this, 'customer_roles' ) );
		add_filter( 'woocommerce_analytics_import_customer_roles', array( $this, 'customer_roles' ) );
	}

	/**
	 * Add the plugin's roles to a list of roles WooCommerce treats as customers.
	 *
	 * The incoming list is added to rather than replaced: a shop that still has ordinary
	 * `customer` accounts from before the plugin was installed keeps them, and another
	 * plugin filtering the same list is not undone by this one.
	 *
	 * @param mixed $roles List of role names, as passed by WooCommerce.
	 * @return string[] The list with this plugin's roles added.
	 */
	public function customer_roles( $roles ) {
		return array_values(
			array_unique(
				array_merge(
					(array) $roles,
					array( Roles::ROLE_ORG_ADMIN, Roles::ROLE_MEMBER )
				)
			)
		);
	}
}
