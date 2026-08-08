<?php
/**
 * Checkout enforcement tests.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Tests;

use WooOrgAccounts\Checkout\BillingLock;
use WooOrgAccounts\Checkout\Gate;
use WooOrgAccounts\Checkout\OrderMeta;
use WooOrgAccounts\Checkout\ShippingSelector;
use WooOrgAccounts\Data\Member;
use WooOrgAccounts\Data\MemberRepository;
use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Membership\Context;

/**
 * Who may check out, what they are billed, and where it goes.
 */
class CheckoutTest extends TestCase {

	/**
	 * Guest checkout is off and cannot be switched on.
	 */
	public function testGuestCheckoutIsOff() {
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );

		$this->assertSame( 'no', get_option( 'woocommerce_enable_guest_checkout' ) );
	}

	/**
	 * Registration at checkout and in My Account is off too.
	 */
	public function testWooCommerceRegistrationIsOff() {
		update_option( 'woocommerce_enable_myaccount_registration', 'yes' );
		update_option( 'woocommerce_enable_signup_and_login_from_checkout', 'yes' );

		$this->assertSame( 'no', get_option( 'woocommerce_enable_myaccount_registration' ) );
		$this->assertSame( 'no', get_option( 'woocommerce_enable_signup_and_login_from_checkout' ) );
	}

	/**
	 * A signed-out visitor may not check out.
	 */
	public function testGuestMayNotPurchase() {
		wp_set_current_user( 0 );

		$this->assertFalse( Context::can_purchase() );
		$this->assertNotSame( '', Context::purchase_blocked_reason() );
	}

	/**
	 * A signed-in customer with no organization may not check out.
	 */
	public function testCustomerWithoutOrganizationMayNotPurchase() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );
		Context::flush();

		$this->assertFalse( Context::can_purchase() );
	}

	/**
	 * Each non-active organization status blocks the checkout, with its own message.
	 */
	public function testOnlyActiveOrganizationsMayPurchase() {
		$statuses = array(
			Organization::STATUS_PENDING,
			Organization::STATUS_SUSPENDED,
			Organization::STATUS_REJECTED,
		);

		foreach ( $statuses as $status ) {
			$organization = $this->make_organization( array( 'status' => $status ) );
			$this->act_as( $this->make_member( $organization ) );

			$this->assertFalse( Context::can_purchase(), $status . ' should block the checkout.' );
			$this->assertNotSame( '', Context::purchase_blocked_reason() );
		}

		$organization = $this->make_organization();
		$this->act_as( $this->make_member( $organization ) );

		$this->assertTrue( Context::can_purchase() );
		$this->assertSame( '', Context::purchase_blocked_reason() );
	}

	/**
	 * An inactive member of an active organization may not check out.
	 */
	public function testInactiveMemberMayNotPurchase() {
		$organization = $this->make_organization();
		$this->act_as( $this->make_member( $organization, Member::ROLE_MEMBER, array( 'status' => Member::STATUS_INACTIVE ) ) );

		$this->assertFalse( Context::can_purchase() );
	}

	/**
	 * A member whose right to buy was revoked may not check out.
	 */
	public function testMemberWithoutPlaceOrdersMayNotPurchase() {
		$organization = $this->make_organization();
		$member       = $this->make_member( $organization );

		$member->set_capabilities( array( \WooOrgAccounts\Roles::PLACE_ORDERS => false ) );
		MemberRepository::save( $member );
		$this->act_as( $member );

		$this->assertFalse( Context::can_purchase() );
	}

	/**
	 * The classic checkout's validation pass refuses a blocked customer.
	 */
	public function testClassicValidationRefusesBlockedCustomer() {
		wp_set_current_user( 0 );

		$errors = new \WP_Error();
		( new Gate() )->block_classic_checkout( array(), $errors );

		$this->assertTrue( $errors->has_errors() );
		$this->assertSame( 'woap_not_permitted', $errors->get_error_code() );
	}

	/**
	 * The Store API cart reports the same refusal.
	 */
	public function testStoreApiCartRefusesBlockedCustomer() {
		wp_set_current_user( 0 );

		$errors = ( new Gate() )->block_store_api_cart( array(), WC()->cart );

		$this->assertCount( 1, $errors );
		$this->assertSame( 'woap_not_permitted', $errors[0]->get_error_code() );
	}

	/**
	 * A permitted customer is not refused anywhere.
	 */
	public function testGatePassesAPermittedCustomer() {
		$this->act_as( $this->make_member( $this->make_organization() ) );

		$errors = new \WP_Error();
		( new Gate() )->block_classic_checkout( array(), $errors );

		$this->assertFalse( $errors->has_errors() );
		$this->assertSame( array(), ( new Gate() )->block_store_api_cart( array(), WC()->cart ) );
	}

	/**
	 * A forged billing address in the POST is discarded.
	 */
	public function testForgedBillingIsDiscarded() {
		$organization = $this->make_organization();
		$this->act_as( $this->make_member( $organization ) );

		$posted = ( new BillingLock() )->replace_posted_data(
			array(
				'billing_first_name' => 'Mallory',
				'billing_address_1'  => '666 Evil Road',
				'billing_city'       => 'Nowhere',
				'billing_country'    => 'US',
				'billing_postcode'   => '00000',
			)
		);

		$this->assertSame( 'Ada', $posted['billing_first_name'] );
		$this->assertSame( '1 Hauptstrasse', $posted['billing_address_1'] );
		$this->assertSame( 'Berlin', $posted['billing_city'] );
		$this->assertSame( 'DE', $posted['billing_country'] );
		$this->assertSame( '10115', $posted['billing_postcode'] );
	}

	/**
	 * The order is billed to the organization however it was created.
	 */
	public function testOrderIsBilledToTheOrganization() {
		$organization = $this->make_organization();
		$this->act_as( $this->make_member( $organization ) );

		$order = new \WC_Order();
		$order->set_billing_first_name( 'Mallory' );
		$order->set_billing_city( 'Nowhere' );

		( new BillingLock() )->apply_to_order( $order, array() );

		$this->assertSame( 'Ada', $order->get_billing_first_name() );
		$this->assertSame( 'Berlin', $order->get_billing_city() );
		$this->assertSame( 'DE', $order->get_billing_country() );
	}

	/**
	 * Editing the organization's address later does not rewrite an existing order.
	 */
	public function testBillingOnAnOrderIsASnapshot() {
		$organization = $this->make_organization();
		$this->act_as( $this->make_member( $organization ) );

		$order = new \WC_Order();
		( new BillingLock() )->apply_to_order( $order, array() );
		$order->save();

		$organization->set_billing_address( array( 'city' => 'Munich' ) );
		\WooOrgAccounts\Data\OrganizationRepository::save( $organization );

		$this->assertSame( 'Berlin', wc_get_order( $order->get_id() )->get_billing_city() );
	}

	/**
	 * A location of the member's own organization resolves.
	 */
	public function testOwnLocationResolves() {
		$organization = $this->make_organization();
		$this->act_as( $this->make_member( $organization ) );
		$location = $this->make_location( $organization );

		$resolved = ShippingSelector::resolve_location( (string) $location->get_id() );

		$this->assertNotNull( $resolved );
		$this->assertSame( $location->get_id(), $resolved->get_id() );
		$this->assertSame( '', ShippingSelector::destination_error( (string) $location->get_id() ) );
	}

	/**
	 * A location belonging to another organization does not.
	 */
	public function testForeignLocationIsRefused() {
		$ours   = $this->make_organization();
		$theirs = $this->make_organization( array( 'name' => 'Rival Ltd' ) );

		$this->act_as( $this->make_member( $ours ) );
		$this->make_location( $ours );
		$theirs_location = $this->make_location( $theirs, array( 'name' => 'Rival Depot' ) );

		$this->assertNull( ShippingSelector::resolve_location( (string) $theirs_location->get_id() ) );
		$this->assertNotSame( '', ShippingSelector::destination_error( (string) $theirs_location->get_id() ) );
	}

	/**
	 * A location the member has no access to does not resolve either.
	 */
	public function testLocationOutsideTheMembersAccessIsRefused() {
		$organization = $this->make_organization();
		$member       = $this->make_member( $organization );
		$north        = $this->make_location( $organization );
		$south        = $this->make_location( $organization, array( 'name' => 'Warehouse South' ) );

		MemberRepository::set_location_ids( $member->get_id(), array( $north->get_id() ) );
		$this->act_as( $member );

		$this->assertNotNull( ShippingSelector::resolve_location( (string) $north->get_id() ) );
		$this->assertNull( ShippingSelector::resolve_location( (string) $south->get_id() ) );
	}

	/**
	 * A one-off address is allowed only when the organization permits it.
	 */
	public function testCustomShippingRespectsTheOrganizationSetting() {
		$organization = $this->make_organization( array( 'allow_custom_shipping' => true ) );
		$this->act_as( $this->make_member( $organization ) );
		$this->make_location( $organization );

		$this->assertSame( '', ShippingSelector::destination_error( ShippingSelector::CUSTOM ) );

		$strict = $this->make_organization(
			array(
				'name'                  => 'Strict AG',
				'allow_custom_shipping' => false,
			)
		);
		$this->act_as( $this->make_member( $strict ) );
		$this->make_location( $strict, array( 'name' => 'Strict Depot' ) );

		$this->assertNotSame( '', ShippingSelector::destination_error( ShippingSelector::CUSTOM ) );
	}

	/**
	 * With no locations and no one-off addresses there is nowhere to ship, and the
	 * message says so rather than asking for a choice that does not exist.
	 */
	public function testNoLocationsAndNoCustomIsExplained() {
		$organization = $this->make_organization( array( 'allow_custom_shipping' => false ) );
		$this->act_as( $this->make_member( $organization ) );

		$message = ShippingSelector::destination_error( '' );

		$this->assertStringContainsString( 'Branches', $message );
	}

	/**
	 * The chosen location replaces whatever shipping address was posted.
	 */
	public function testPostedShippingIsReplacedByTheLocation() {
		$organization = $this->make_organization();
		$this->act_as( $this->make_member( $organization ) );
		$location = $this->make_location( $organization );

		$_POST[ ShippingSelector::FIELD ] = (string) $location->get_id();

		$posted = ( new ShippingSelector() )->replace_posted_data( array( 'shipping_address_1' => 'Somewhere else' ) );

		$this->assertSame( '9 Lagerweg', $posted['shipping_address_1'] );
		$this->assertSame( 'Hamburg', $posted['shipping_city'] );
		$this->assertSame( 'Warehouse North', $posted['shipping_company'] );
		$this->assertTrue( $posted['ship_to_different_address'] );
	}

	/**
	 * The rules an address is judged by come from the destination, not the request.
	 *
	 * WooCommerce builds the checkout fieldset from the *posted* country. This plugin
	 * then replaces the posted address with the location's — so without reshaping the
	 * fieldset too, a German location submitted from a form carrying
	 * `shipping_country=US` was validated under US rules and refused for having no
	 * state. A real order failed exactly this way before the fix.
	 */
	public function testShippingFieldsFollowTheDestinationNotTheRequest() {
		$organization = $this->make_organization();
		$this->act_as( $this->make_member( $organization ) );
		$location = $this->make_location( $organization );

		$_POST[ ShippingSelector::FIELD ] = (string) $location->get_id();
		$_POST['shipping_country']        = 'US';

		$fields = ( new ShippingSelector() )->shape_shipping_fields(
			array( 'shipping' => WC()->countries->get_address_fields( 'US', 'shipping_' ) )
		);

		$this->assertTrue(
			empty( $fields['shipping']['shipping_state']['required'] ),
			'A German destination must not be asked for a state because the request said US.'
		);
		$this->assertSame( 'Hamburg', $fields['shipping']['shipping_city']['default'] );
	}

	/**
	 * Billing is judged by the organization's country, not the request's.
	 */
	public function testBillingFieldsFollowTheOrganization() {
		$organization = $this->make_organization();
		$this->act_as( $this->make_member( $organization ) );

		$fields = ( new BillingLock() )->lock_fields(
			array( 'billing' => WC()->countries->get_address_fields( 'US', 'billing_' ) )
		);

		$this->assertSame( 'Berlin', $fields['billing']['billing_city']['default'] );
		$this->assertSame( 'DE', $fields['billing']['billing_country']['default'] );
	}

	/**
	 * A location missing a required field cannot be delivered to.
	 *
	 * A location can be stored incomplete — saved before the plugin validated
	 * addresses, or moved to a country with stricter rules. Left alone, WooCommerce
	 * refuses the order with "Shipping Last name is a required field", which asks the
	 * customer to fix a field they cannot see and do not own.
	 */
	public function testIncompleteLocationIsRefusedByName() {
		$organization = $this->make_organization();
		$this->act_as( $this->make_member( $organization ) );
		$location = $this->make_location( $organization, array( 'last_name' => '' ) );

		$message = ShippingSelector::destination_error( (string) $location->get_id() );

		$this->assertNotSame( '', $message );
		$this->assertStringContainsString( 'Warehouse North', $message, 'The message must name the location.' );
		$this->assertStringContainsString( 'Last name', $message, 'The message must name what is missing.' );
	}

	/**
	 * A complete location is not refused.
	 */
	public function testCompleteLocationIsAccepted() {
		$organization = $this->make_organization();
		$this->act_as( $this->make_member( $organization ) );
		$location = $this->make_location( $organization );

		$this->assertSame( '', ShippingSelector::destination_error( (string) $location->get_id() ) );
	}

	/**
	 * A blocked customer is told on the cart, not after pressing "Proceed to checkout".
	 */
	public function testTheCartSaysWhyItCannotBeCheckedOut() {
		wp_set_current_user( 0 );
		wc_clear_notices();

		( new Gate() )->block_cart();

		$notices = wc_get_notices( 'error' );

		$this->assertCount( 1, $notices );
		$this->assertStringContainsString( 'logged in', $notices[0]['notice'] );

		// The same message twice reads as a malfunction, and both hooks can fire.
		( new Gate() )->block_cart();

		$this->assertCount( 1, wc_get_notices( 'error' ) );

		wc_clear_notices();
	}

	/**
	 * The order records the organization, the location and the member.
	 */
	public function testOrderCarriesTheOrganization() {
		$organization = $this->make_organization();
		$member       = $this->make_member( $organization );
		$user_id      = $this->act_as( $member );
		$location     = $this->make_location( $organization );

		$_POST[ ShippingSelector::FIELD ] = (string) $location->get_id();

		$order = new \WC_Order();
		( new ShippingSelector() )->apply_to_order( $order, array() );
		$order->save();

		$saved = wc_get_order( $order->get_id() );

		$this->assertSame( $organization->get_id(), OrderMeta::organization_id( $saved ) );
		$this->assertSame( 'Acme GmbH', OrderMeta::organization_name( $saved ) );
		$this->assertSame( $location->get_id(), OrderMeta::location_id( $saved ) );
		$this->assertSame( 'Warehouse North', OrderMeta::location_name( $saved ) );
		$this->assertSame( $user_id, OrderMeta::member_user_id( $saved ) );
		$this->assertSame( 'Hamburg', $saved->get_shipping_city() );
	}

	/**
	 * A one-off address leaves no location on the order.
	 */
	public function testCustomAddressLeavesNoLocation() {
		$organization = $this->make_organization();
		$this->act_as( $this->make_member( $organization ) );

		$_POST[ ShippingSelector::FIELD ] = ShippingSelector::CUSTOM;

		$order = new \WC_Order();
		( new ShippingSelector() )->apply_to_order( $order, array() );
		$order->save();

		$saved = wc_get_order( $order->get_id() );

		$this->assertSame( $organization->get_id(), OrderMeta::organization_id( $saved ) );
		$this->assertSame( 0, OrderMeta::location_id( $saved ) );
	}

	/**
	 * The order name survives the organization being renamed.
	 */
	public function testOrganizationNameOnAnOrderIsASnapshot() {
		$organization = $this->make_organization();
		$this->act_as( $this->make_member( $organization ) );

		$order = new \WC_Order();
		( new ShippingSelector() )->apply_to_order( $order, array() );
		$order->save();

		$organization->set( 'name', 'Acme Holdings AG' );
		\WooOrgAccounts\Data\OrganizationRepository::save( $organization );

		$this->assertSame( 'Acme GmbH', OrderMeta::organization_name( wc_get_order( $order->get_id() ) ) );
	}
}
