<?php
/**
 * WooCommerce Analytics integration tests.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Tests;

use WooOrgAccounts\Analytics;
use WooOrgAccounts\Roles;

/**
 * Whether WooCommerce Analytics counts this plugin's roles as customers.
 *
 * WooCommerce → Customers reads `wc_customer_lookup`, and a role missing from these two
 * lists never reaches that table. On a shop whose customers are all organizations, that
 * is the whole screen.
 */
class AnalyticsTest extends TestCase {

	/**
	 * Both of the plugin's roles are treated as customer roles.
	 */
	public function testBothRolesAreAddedToTheCustomerRoles() {
		$roles = ( new Analytics() )->customer_roles( array( 'customer' ) );

		$this->assertContains( Roles::ROLE_ORG_ADMIN, $roles );
		$this->assertContains( Roles::ROLE_MEMBER, $roles );
	}

	/**
	 * WooCommerce's own roles, and any another plugin has added, are kept.
	 *
	 * The list is added to rather than replaced, so a shop that still holds ordinary
	 * `customer` accounts from before this plugin was installed does not lose them from
	 * the report the moment it is activated.
	 */
	public function testTheIncomingRolesAreKept() {
		$roles = ( new Analytics() )->customer_roles( array( 'customer', 'subscriber' ) );

		$this->assertContains( 'customer', $roles );
		$this->assertContains( 'subscriber', $roles );
	}

	/**
	 * A role already in the list is not added a second time.
	 *
	 * Both filtered values end up in a `role__in` user query, where a duplicate is a
	 * needlessly larger `IN` clause rather than a wrong answer — but the list is also
	 * passed on to whatever filters after this, so it is kept clean.
	 */
	public function testRolesAreNotDuplicated() {
		$roles = ( new Analytics() )->customer_roles( array( 'customer', Roles::ROLE_MEMBER ) );

		$this->assertSame( array_unique( $roles ), $roles );
		$this->assertSame( array_values( $roles ), $roles );
	}

	/**
	 * Both filters are registered, and both are needed.
	 *
	 * `woocommerce_analytics_import_customer_roles` decides which existing accounts the
	 * historical backfill considers; `woocommerce_analytics_customer_roles` decides
	 * whether a user being synced now is a customer at all. Filtering one and not the
	 * other leaves either every existing account invisible or every future one.
	 */
	public function testBothFiltersAreRegistered() {
		$analytics = new Analytics();
		$analytics->register();

		$this->assertNotFalse(
			has_filter( 'woocommerce_analytics_customer_roles', array( $analytics, 'customer_roles' ) )
		);
		$this->assertNotFalse(
			has_filter( 'woocommerce_analytics_import_customer_roles', array( $analytics, 'customer_roles' ) )
		);
	}
}
