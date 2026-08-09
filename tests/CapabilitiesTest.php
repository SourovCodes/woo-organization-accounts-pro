<?php
/**
 * Capability and isolation tests.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Tests;

use WooOrgAccounts\Capabilities;
use WooOrgAccounts\Checkout\OrderMeta;
use WooOrgAccounts\Data\Member;
use WooOrgAccounts\Data\MemberRepository;
use WooOrgAccounts\Frontend\MyAccount;
use WooOrgAccounts\Frontend\OrderDetails;
use WooOrgAccounts\Guard;
use WooOrgAccounts\Roles;

/**
 * What each role may do, and the fact that none of it crosses an organization.
 */
class CapabilitiesTest extends TestCase {

	/**
	 * An organization admin holds every capability the plugin defines.
	 */
	public function testAdminHoldsEverything() {
		$organization = $this->make_organization();
		$this->act_as( $this->make_member( $organization, Member::ROLE_ADMIN ) );

		foreach ( Roles::capabilities() as $capability ) {
			$this->assertTrue( current_user_can( $capability ), $capability . ' should be granted to an admin.' );
		}
	}

	/**
	 * An ordinary member may buy and nothing else.
	 */
	public function testMemberMayOnlyBuy() {
		$organization = $this->make_organization();
		$this->act_as( $this->make_member( $organization ) );

		$this->assertTrue( current_user_can( Roles::PLACE_ORDERS ) );
		$this->assertFalse( current_user_can( Roles::MANAGE_MEMBERS ) );
		$this->assertFalse( current_user_can( Roles::MANAGE_BILLING ) );
		$this->assertFalse( current_user_can( Roles::VIEW_ORGANIZATION_ORDERS ) );
	}

	/**
	 * A per-member override layers over the role.
	 */
	public function testPerMemberOverrideGrants() {
		$organization = $this->make_organization();
		$member       = $this->make_member( $organization );

		$member->set_capabilities( array( Roles::VIEW_ORGANIZATION_ORDERS => true ) );
		MemberRepository::save( $member );
		$this->act_as( $member );

		$this->assertTrue( current_user_can( Roles::VIEW_ORGANIZATION_ORDERS ) );
		$this->assertFalse( current_user_can( Roles::MANAGE_MEMBERS ) );
	}

	/**
	 * An override can take a capability away from an admin too.
	 */
	public function testPerMemberOverrideRevokes() {
		$organization = $this->make_organization();
		$member       = $this->make_member( $organization, Member::ROLE_ADMIN );

		$member->set_capabilities( array( Roles::MANAGE_BILLING => false ) );
		MemberRepository::save( $member );
		$this->act_as( $member );

		$this->assertFalse( current_user_can( Roles::MANAGE_BILLING ) );
		$this->assertTrue( current_user_can( Roles::MANAGE_MEMBERS ) );
	}

	/**
	 * Overrides survive a round trip through the database as booleans.
	 */
	public function testOverridesRoundTrip() {
		$organization = $this->make_organization();
		$member       = $this->make_member( $organization );

		$member->set_capabilities( array( Roles::INVITE_MEMBERS => true ) );
		MemberRepository::save( $member );

		$loaded = MemberRepository::find( $member->get_id() );

		$this->assertSame( array( Roles::INVITE_MEMBERS => true ), $loaded->get_capabilities() );
	}

	/**
	 * A user with no membership holds none of the plugin's capabilities, whatever
	 * WordPress role they happen to have.
	 */
	public function testUserWithoutMembershipHoldsNothing() {
		$user_id = self::factory()->user->create( array( 'role' => Roles::ROLE_ORG_ADMIN ) );
		wp_set_current_user( $user_id );

		foreach ( Roles::capabilities() as $capability ) {
			$this->assertFalse( current_user_can( $capability ), $capability . ' must not survive without a membership.' );
		}
	}

	/**
	 * An inactive membership grants nothing — including the right to buy.
	 */
	public function testInactiveMemberHoldsNothing() {
		$organization = $this->make_organization();
		$member       = $this->make_member( $organization, Member::ROLE_ADMIN, array( 'status' => Member::STATUS_INACTIVE ) );

		$this->act_as( $member );

		$this->assertFalse( current_user_can( Roles::PLACE_ORDERS ) );
		$this->assertFalse( current_user_can( Roles::MANAGE_MEMBERS ) );
	}

	/**
	 * A removed member loses the capabilities immediately.
	 */
	public function testRemovingAMemberRevokesEverything() {
		$organization = $this->make_organization();
		$member       = $this->make_member( $organization, Member::ROLE_ADMIN );
		$user_id      = $this->act_as( $member );

		$this->assertTrue( current_user_can( Roles::PLACE_ORDERS ) );

		MemberRepository::delete( $member->get_id() );
		\WooOrgAccounts\Membership\Context::flush();
		wp_set_current_user( $user_id );

		$this->assertFalse( current_user_can( Roles::PLACE_ORDERS ) );
	}

	/**
	 * Anyone who can manage the shop can act on any organization in it.
	 */
	public function testShopManagerBypassesScoping() {
		$organization = $this->make_organization();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertTrue( current_user_can( Roles::MANAGE_MEMBERS ) );
		$this->assertTrue( Guard::can( $organization->get_id(), Roles::MANAGE_MEMBERS ) );
	}

	/**
	 * An admin of one organization cannot act on another.
	 */
	public function testGuardRefusesAnotherOrganization() {
		$ours   = $this->make_organization();
		$theirs = $this->make_organization( array( 'name' => 'Rival Ltd' ) );

		$this->act_as( $this->make_member( $ours, Member::ROLE_ADMIN ) );

		$this->assertTrue( Guard::can( $ours->get_id(), Roles::MANAGE_MEMBERS ) );
		$this->assertFalse( Guard::can( $theirs->get_id(), Roles::MANAGE_MEMBERS ) );
	}

	/**
	 * The guard refuses a missing organization rather than falling through.
	 */
	public function testGuardRefusesNoOrganization() {
		$this->act_as( $this->make_member( $this->make_organization(), Member::ROLE_ADMIN ) );

		$this->assertFalse( Guard::can( 0, Roles::MANAGE_MEMBERS ) );
		$this->assertFalse( Guard::can( 999999, Roles::MANAGE_MEMBERS ) );
	}

	/**
	 * A signed-out visitor is refused everything.
	 */
	public function testGuestIsRefused() {
		$organization = $this->make_organization();
		wp_set_current_user( 0 );

		$this->assertFalse( Guard::can( $organization->get_id(), Roles::PLACE_ORDERS ) );
	}

	/**
	 * Both WordPress roles exist, and carry no plugin capabilities of their own.
	 *
	 * The capabilities come from the membership row, resolved at runtime. A role
	 * carrying them as well would be a second answer that could disagree.
	 */
	public function testWordPressRolesCarryNoPluginCapabilities() {
		foreach ( array( Roles::ROLE_ORG_ADMIN, Roles::ROLE_MEMBER ) as $role_name ) {
			$role = get_role( $role_name );

			$this->assertNotNull( $role, $role_name . ' is not registered.' );
			$this->assertArrayHasKey( 'read', $role->capabilities );

			foreach ( Roles::capabilities() as $capability ) {
				$this->assertArrayNotHasKey( $capability, $role->capabilities );
			}
		}
	}

	/**
	 * An order placed by one member can be opened by an admin of the same organization.
	 *
	 * `woap_view_organization_orders` is answered by this plugin; the page the list links
	 * to is WooCommerce's, and it asks `view_order`, which WooCommerce grants only to the
	 * order's own customer. Holding the capability and being able to reach what it
	 * describes are two facts, and this asserts the second.
	 */
	public function testAdminCanOpenAnotherMembersOrder() {
		$organization = $this->make_organization();
		$buyer        = $this->make_member( $organization );
		$admin        = $this->make_member( $organization, Member::ROLE_ADMIN );

		$order = $this->make_organization_order( $organization, $buyer );

		$this->act_as( $admin );

		$this->assertTrue( current_user_can( 'view_order', $order->get_id() ) );
	}

	/**
	 * A member without the capability cannot open somebody else's order.
	 */
	public function testMemberCannotOpenAnotherMembersOrder() {
		$organization = $this->make_organization();
		$buyer        = $this->make_member( $organization );
		$other        = $this->make_member( $organization );

		$order = $this->make_organization_order( $organization, $buyer );

		$this->act_as( $other );

		$this->assertFalse( current_user_can( 'view_order', $order->get_id() ) );
	}

	/**
	 * An admin of another organization cannot open it, capability or not.
	 *
	 * This is the cross-tenant question, and it is separate from the capability one:
	 * they hold `woap_view_organization_orders` for their own organization.
	 */
	public function testAdminOfAnotherOrganizationCannotOpenTheOrder() {
		$ours   = $this->make_organization();
		$theirs = $this->make_organization( array( 'name' => 'Rival Ltd' ) );

		$order = $this->make_organization_order( $ours, $this->make_member( $ours ) );

		$this->act_as( $this->make_member( $theirs, Member::ROLE_ADMIN ) );

		$this->assertTrue( current_user_can( Roles::VIEW_ORGANIZATION_ORDERS ) );
		$this->assertFalse( current_user_can( 'view_order', $order->get_id() ) );
	}

	/**
	 * A member can still open their own order.
	 *
	 * WooCommerce's own rule grants this, and the plugin's filter only ever adds to
	 * `view_order`. A filter that answered in both directions would take this away from
	 * every member who is not an admin.
	 */
	public function testMemberCanStillOpenTheirOwnOrder() {
		$organization = $this->make_organization();
		$buyer        = $this->make_member( $organization );

		$order = $this->make_organization_order( $organization, $buyer );

		$this->act_as( $buyer );

		$this->assertFalse( current_user_can( Roles::VIEW_ORGANIZATION_ORDERS ) );
		$this->assertTrue( current_user_can( 'view_order', $order->get_id() ) );
	}

	/**
	 * An order belonging to no organization is left to WooCommerce entirely.
	 */
	public function testOrderWithoutAnOrganizationIsNotGranted() {
		$organization = $this->make_organization();

		$order = new \WC_Order();
		$order->set_status( 'processing' );
		$order->save();

		$this->act_as( $this->make_member( $organization, Member::ROLE_ADMIN ) );

		$this->assertFalse( current_user_can( 'view_order', $order->get_id() ) );
	}

	/**
	 * A suspended admin cannot open the organization's orders.
	 */
	public function testInactiveAdminCannotOpenTheOrder() {
		$organization = $this->make_organization();
		$buyer        = $this->make_member( $organization );

		$order = $this->make_organization_order( $organization, $buyer );

		$this->act_as(
			$this->make_member( $organization, Member::ROLE_ADMIN, array( 'status' => Member::STATUS_INACTIVE ) )
		);

		$this->assertFalse( current_user_can( 'view_order', $order->get_id() ) );
	}

	/**
	 * Every order the organization orders screen links to can be opened by its reader.
	 *
	 * The bug this guards against was not a wrong answer from either side. The list was
	 * right, the capability was right, and the screen still offered a link to a page
	 * that answered "Invalid order" — because nothing asserted the two together. Written
	 * as an invariant over the whole list rather than one order, so a future row type
	 * that this plugin lists but WooCommerce would refuse fails here too.
	 */
	public function testEveryListedOrderIsOpenableByTheReader() {
		$organization = $this->make_organization();
		$admin        = $this->make_member( $organization, Member::ROLE_ADMIN );

		$this->make_organization_order( $organization, $this->make_member( $organization ) );
		$this->make_organization_order( $organization, $this->make_member( $organization ) );
		$this->make_organization_order( $organization, $admin );

		$this->act_as( $admin );

		$listed = MyAccount::organization_orders( $organization->get_id() );

		$this->assertCount( 3, $listed['orders'] );

		foreach ( $listed['orders'] as $order ) {
			$this->assertTrue(
				current_user_can( 'view_order', $order->get_id() ),
				sprintf( 'Order %d is listed and linked but cannot be opened.', $order->get_id() )
			);
		}
	}

	/**
	 * An admin has the same control over an employee's order as over their own.
	 *
	 * The whole set, not only `view_order`: an order belongs to the organization, and
	 * which employee happened to place it is a detail of how it got there.
	 */
	public function testAdminHasFullControlOfAnotherMembersOrder() {
		$organization = $this->make_organization();
		$buyer        = $this->make_member( $organization );
		$admin        = $this->make_member( $organization, Member::ROLE_ADMIN );

		$order = $this->make_organization_order( $organization, $buyer );

		$this->act_as( $admin );

		foreach ( array( 'view_order', 'order_again', 'cancel_order', 'pay_for_order' ) as $capability ) {
			$this->assertTrue(
				current_user_can( $capability, $order->get_id() ),
				$capability . ' should be granted over an organization order.'
			);
		}
	}

	/**
	 * Paying additionally needs the right to buy.
	 *
	 * Paying is spending, and `woap_place_orders` is this plugin's expression of who may
	 * spend. Without this, revoking somebody's right to buy would still leave them able
	 * to settle the organization's unpaid orders — and the checkout gate would not catch
	 * it, because the pay flow validates the order key rather than running the cart
	 * hooks the gate is attached to.
	 */
	public function testPayingNeedsThePlaceOrdersCapability() {
		$organization = $this->make_organization();
		$buyer        = $this->make_member( $organization );
		$admin        = $this->make_member( $organization, Member::ROLE_ADMIN );

		$admin->set_capabilities( array( Roles::PLACE_ORDERS => false ) );
		MemberRepository::save( $admin );

		$order = $this->make_organization_order( $organization, $buyer );

		$this->act_as( $admin );

		$this->assertFalse( current_user_can( 'pay_for_order', $order->get_id() ) );
		$this->assertTrue( current_user_can( 'view_order', $order->get_id() ) );
		$this->assertTrue( current_user_can( 'cancel_order', $order->get_id() ) );
	}

	/**
	 * None of the order capabilities cross an organization.
	 *
	 * `download_file` is checked with a download rather than an order ID, because that
	 * is what WooCommerce hands its own rule — passing an ID makes WooCommerce itself
	 * fatal on `$download->get_user_id()`, long before this plugin is consulted.
	 */
	public function testNoOrderCapabilityCrossesAnOrganization() {
		$ours   = $this->make_organization();
		$theirs = $this->make_organization( array( 'name' => 'Rival Ltd' ) );

		$buyer = $this->make_member( $ours );
		$order = $this->make_organization_order( $ours, $buyer );

		$download = new \WC_Customer_Download();
		$download->set_user_id( $buyer->get_user_id() );
		$download->set_order_id( $order->get_id() );

		$this->act_as( $this->make_member( $theirs, Member::ROLE_ADMIN ) );

		foreach ( Capabilities::order_capabilities() as $capability ) {
			$subject = ( 'download_file' === $capability ) ? $download : $order->get_id();

			$this->assertFalse(
				current_user_can( $capability, $subject ),
				$capability . ' must not cross an organization.'
			);
		}
	}

	/**
	 * A downloadable file bought by one member can be fetched by an admin.
	 *
	 * `download_file` is handed a download rather than an order, so the order has to be
	 * reached through it. The order page renders a download button per item, so an admin
	 * who can open the order can see the button — which is what makes this reachable
	 * rather than a capability nobody can use.
	 */
	public function testAdminCanDownloadAnotherMembersFile() {
		$organization = $this->make_organization();
		$buyer        = $this->make_member( $organization );
		$admin        = $this->make_member( $organization, Member::ROLE_ADMIN );

		$order = $this->make_organization_order( $organization, $buyer );

		$download = new \WC_Customer_Download();
		$download->set_user_id( $buyer->get_user_id() );
		$download->set_order_id( $order->get_id() );

		$this->act_as( $admin );

		$this->assertTrue( current_user_can( 'download_file', $download ) );
	}

	/**
	 * An admin sees the addresses on an order somebody else placed.
	 *
	 * WooCommerce prints the billing and delivery addresses only when the order's
	 * customer is the viewer, decided by a bare comparison inside `order-details.php`
	 * with no filter on it. On an account whose whole point is that goods go to
	 * organization locations, those were the two facts most worth checking and the two
	 * that were missing.
	 */
	public function testAdminSeesTheAddressesOnAnotherMembersOrder() {
		$organization = $this->make_organization();
		$buyer        = $this->make_member( $organization );
		$admin        = $this->make_member( $organization, Member::ROLE_ADMIN );

		$order = $this->make_organization_order( $organization, $buyer );

		$this->act_as( $admin );

		$this->assertStringContainsString( 'woocommerce-column--billing-address', $this->customer_details_for( $order ) );
	}

	/**
	 * The block is not printed twice on the viewer's own order.
	 *
	 * WooCommerce renders it itself in that case, so this must stand down or every
	 * address appears twice.
	 */
	public function testTheAddressesAreNotDuplicatedOnAnOwnOrder() {
		$organization = $this->make_organization();
		$buyer        = $this->make_member( $organization );

		$order = $this->make_organization_order( $organization, $buyer );

		$this->act_as( $buyer );

		$this->assertSame( '', $this->customer_details_for( $order ) );
	}

	/**
	 * An admin of another organization is shown nothing.
	 */
	public function testAddressesAreNotShownAcrossOrganizations() {
		$ours   = $this->make_organization();
		$theirs = $this->make_organization( array( 'name' => 'Rival Ltd' ) );

		$order = $this->make_organization_order( $ours, $this->make_member( $ours ) );

		$this->act_as( $this->make_member( $theirs, Member::ROLE_ADMIN ) );

		$this->assertSame( '', $this->customer_details_for( $order ) );
	}

	/**
	 * An order belonging to no organization is left to WooCommerce.
	 */
	public function testAddressesAreNotAddedToANonOrganizationOrder() {
		$organization = $this->make_organization();

		$order = new \WC_Order();
		$order->set_status( 'processing' );
		$order->save();

		$this->act_as( $this->make_member( $organization, Member::ROLE_ADMIN ) );

		$this->assertSame( '', $this->customer_details_for( $order ) );
	}

	/**
	 * What the plugin adds to the foot of an order for the current user.
	 *
	 * @param \WC_Order $order Order being shown.
	 * @return string Rendered markup, empty when the plugin adds nothing.
	 */
	private function customer_details_for( \WC_Order $order ) {
		ob_start();
		( new OrderDetails() )->render_customer_details( $order );

		return (string) ob_get_clean();
	}

	/**
	 * Create an order stamped with an organization and the member who placed it.
	 *
	 * @param \WooOrgAccounts\Data\Organization $organization Organization the order belongs to.
	 * @param Member                            $buyer        Member who placed it.
	 * @return \WC_Order The saved order.
	 */
	private function make_organization_order( $organization, Member $buyer ) {
		$order = new \WC_Order();

		$order->set_customer_id( $buyer->get_user_id() );
		$order->update_meta_data( OrderMeta::ORGANIZATION_ID, $organization->get_id() );
		$order->update_meta_data( OrderMeta::MEMBER_USER_ID, $buyer->get_user_id() );

		/*
		 * A shipping line, because WooCommerce shows the address columns only when
		 * `needs_shipping_address()` is true — and that reads the order's shipping
		 * methods, not its products. An order without one falls back to printing the
		 * billing email alone.
		 */
		$shipping = new \WC_Order_Item_Shipping();
		$shipping->set_method_title( 'Flat rate' );
		$shipping->set_method_id( 'flat_rate' );
		$shipping->set_total( '5' );

		$order->add_item( $shipping );

		/*
		 * A real organization order carries the organization's billing address and a
		 * location's delivery address, written by BillingLock and ShippingSelector.
		 * Without them WooCommerce's customer-details block prints an email and no
		 * address columns, and a test asserting the addresses appear would be asserting
		 * against a shape no order on a live site has.
		 */
		$order->set_address(
			array(
				'first_name' => 'Ada',
				'last_name'  => 'Byron',
				'company'    => $organization->get_name(),
				'address_1'  => '1 Rechnungsweg',
				'city'       => 'Berlin',
				'postcode'   => '10115',
				'country'    => 'DE',
			),
			'billing'
		);

		$order->set_address(
			array(
				'first_name' => 'Grace',
				'last_name'  => 'Hopper',
				'company'    => $organization->get_name(),
				'address_1'  => '9 Lagerweg',
				'city'       => 'Hamburg',
				'postcode'   => '20095',
				'country'    => 'DE',
			),
			'shipping'
		);

		$order->set_status( 'processing' );
		$order->save();

		return $order;
	}

	/**
	 * Every capability has a label for the permissions screen.
	 */
	public function testEveryCapabilityHasALabel() {
		$labels = Roles::labels();

		foreach ( Roles::capabilities() as $capability ) {
			$this->assertArrayHasKey( $capability, $labels );
			$this->assertNotSame( '', $labels[ $capability ] );
		}
	}
}
