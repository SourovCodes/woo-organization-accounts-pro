<?php
/**
 * Organization enforcement on the WooCommerce orders route.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Tests;

use WooOrgAccounts\Checkout\OrderMeta;
use WooOrgAccounts\Data\Member;
use WooOrgAccounts\Data\MemberRepository;
use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Data\OrganizationRepository;
use WooOrgAccounts\Roles;

/**
 * A till creating orders on members' behalf through `/wc/v3/orders`.
 *
 * Every test authenticates the way a till does — as a user holding
 * `manage_woocommerce` — and asserts that the rules land on the order's *customer*.
 * That distinction is the whole class of bug here: the API user passes every gate,
 * so a rule accidentally applied to the asker instead of the member would pass each
 * of these tests' happy paths and refuse nothing, ever.
 */
class RestOrdersTest extends TestCase {

	/**
	 * The orders route.
	 *
	 * @var string
	 */
	const ROUTE = '/wc/v3/orders';

	/**
	 * A product to put on the orders.
	 *
	 * @var \WC_Product_Simple
	 */
	private $product;

	/**
	 * Create a product and sign in as the till.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->product = new \WC_Product_Simple();
		$this->product->set_name( 'Widget' );
		$this->product->set_regular_price( '10' );
		$this->product->save();

		$this->act_as_shop_manager();
	}

	/**
	 * POST an order, as the till would.
	 *
	 * The defaults deliberately carry a *wrong* billing address, so any test that
	 * asserts the organization's billing on the created order is also asserting that
	 * the posted one was discarded rather than merely absent.
	 *
	 * @param array $body Request body, merged over the defaults.
	 * @return \WP_REST_Response The response.
	 */
	private function post_order( array $body = array() ) {
		$request = new \WP_REST_Request( 'POST', self::ROUTE );

		$request->set_body_params(
			array_merge(
				array(
					'line_items'     => array(
						array(
							'product_id' => $this->product->get_id(),
							'quantity'   => 1,
						),
					),
					'shipping_lines' => array(
						array(
							'method_id'    => 'flat_rate',
							'method_title' => 'Flat rate',
							'total'        => '0',
						),
					),
					'billing'        => array(
						'first_name' => 'Wrong',
						'last_name'  => 'Address',
						'address_1'  => '1 Till Lane',
						'city'       => 'Nowhere',
						'postcode'   => '00000',
						'country'    => 'US',
					),
				),
				$body
			)
		);

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * PUT changes to an existing order.
	 *
	 * @param int   $order_id Order to update.
	 * @param array $body     Request body.
	 * @return \WP_REST_Response The response.
	 */
	private function put_order( $order_id, array $body ) {
		$request = new \WP_REST_Request( 'PUT', self::ROUTE . '/' . $order_id );

		$request->set_body_params( $body );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * How many orders exist in the shop.
	 *
	 * @return int Order count.
	 */
	private function order_count() {
		return count( wc_get_orders( array( 'limit' => -1, 'return' => 'ids' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	/**
	 * A till order is billed to the organization, shipped to the location and stamped.
	 *
	 * The posted billing address is discarded, not merged: the created order carries
	 * the organization's own, exactly as both checkouts would have written it.
	 */
	public function testATillOrderIsBilledToTheOrganizationAndStamped() {
		$organization = $this->make_organization();
		$member       = $this->make_member( $organization, Member::ROLE_MEMBER );
		$location     = $this->make_location( $organization );

		$response = $this->post_order(
			array(
				'customer_id'      => $member->get_user_id(),
				'woap_location_id' => $location->get_id(),
			)
		);

		$this->assertSame( 201, $response->get_status() );

		$order = wc_get_order( $response->get_data()['id'] );

		$this->assertSame( 'Ada', $order->get_billing_first_name() );
		$this->assertSame( 'Berlin', $order->get_billing_city() );
		$this->assertSame( 'DE', $order->get_billing_country() );

		$this->assertSame( 'Hamburg', $order->get_shipping_city() );
		$this->assertSame( 'Warehouse North', $order->get_shipping_company() );

		$this->assertSame( $organization->get_id(), OrderMeta::organization_id( $order ) );
		$this->assertSame( $organization->get_name(), OrderMeta::organization_name( $order ) );
		$this->assertSame( $location->get_id(), OrderMeta::location_id( $order ) );
		$this->assertSame( $location->get_name(), OrderMeta::location_name( $order ) );
		$this->assertSame( $member->get_user_id(), OrderMeta::member_user_id( $order ) );
	}

	/**
	 * A location with no company of its own ships under the organization's name.
	 *
	 * The same fallback the checkout applies, shared rather than duplicated: a parcel
	 * with no company on the label is one nobody at a loading bay recognises.
	 */
	public function testABlankLocationCompanyFallsBackToTheOrganizationName() {
		$organization = $this->make_organization( array( 'name' => 'Acme GmbH' ) );
		$member       = $this->make_member( $organization, Member::ROLE_MEMBER );
		$location     = $this->make_location( $organization, array( 'company' => '' ) );

		$response = $this->post_order(
			array(
				'customer_id'      => $member->get_user_id(),
				'woap_location_id' => $location->get_id(),
			)
		);

		$this->assertSame( 'Acme GmbH', wc_get_order( $response->get_data()['id'] )->get_shipping_company() );
	}

	/**
	 * The response reports the organization fields alongside WooCommerce's own.
	 */
	public function testTheResponseCarriesTheOrganizationFields() {
		$organization = $this->make_organization();
		$member       = $this->make_member( $organization, Member::ROLE_MEMBER );
		$location     = $this->make_location( $organization );

		$data = $this->post_order(
			array(
				'customer_id'      => $member->get_user_id(),
				'woap_location_id' => $location->get_id(),
			)
		)->get_data();

		$this->assertSame( $organization->get_id(), $data['woap_organization_id'] );
		$this->assertSame( $organization->get_name(), $data['woap_organization_name'] );
		$this->assertSame( $location->get_id(), $data['woap_location_id'] );
		$this->assertSame( $location->get_name(), $data['woap_location_name'] );
		$this->assertSame( $member->get_user_id(), $data['woap_member_user_id'] );
	}

	/**
	 * The gate asks about the member the order is for, not about the till's key.
	 *
	 * The API user holds `manage_woocommerce` and passes every capability check, so
	 * this only refuses if the rule was applied to the right person.
	 */
	public function testAMemberRefusedPurchaseCannotBeOrderedFor() {
		$organization = $this->make_organization();
		$member       = $this->make_member(
			$organization,
			Member::ROLE_MEMBER,
			array( 'capabilities' => wp_json_encode( array( Roles::PLACE_ORDERS => false ) ) )
		);

		$response = $this->post_order( array( 'customer_id' => $member->get_user_id() ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'woap_rest_cannot_purchase', $response->get_data()['code'] );
		$this->assertSame( 0, $this->order_count() );
	}

	/**
	 * A suspended organization cannot be ordered for, whoever is at the till.
	 */
	public function testASuspendedOrganizationCannotBeOrderedFor() {
		$organization = $this->make_organization( array( 'status' => Organization::STATUS_SUSPENDED ) );
		$member       = $this->make_member( $organization, Member::ROLE_MEMBER );

		$response = $this->post_order( array( 'customer_id' => $member->get_user_id() ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 0, $this->order_count() );
	}

	/**
	 * A pending organization is refused with the reason a person can act on.
	 */
	public function testAPendingOrganizationIsRefusedWithTheReason() {
		$organization = $this->make_organization( array( 'status' => Organization::STATUS_PENDING ) );
		$member       = $this->make_member( $organization, Member::ROLE_MEMBER );

		$response = $this->post_order( array( 'customer_id' => $member->get_user_id() ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertStringContainsString( 'awaiting approval', $response->get_data()['message'] );
	}

	/**
	 * A location belonging to another organization resolves to nothing.
	 *
	 * The cross-tenant case: the ID is real, the till is authenticated, and the answer
	 * is still no — with no order left behind.
	 */
	public function testALocationFromAnotherOrganizationIsRefused() {
		$organization = $this->make_organization( array( 'name' => 'Acme GmbH' ) );
		$other        = $this->make_organization( array( 'name' => 'Rivals AG' ) );
		$member       = $this->make_member( $organization, Member::ROLE_MEMBER );
		$theirs       = $this->make_location( $other );

		$response = $this->post_order(
			array(
				'customer_id'      => $member->get_user_id(),
				'woap_location_id' => $theirs->get_id(),
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'woap_rest_shipping_destination', $response->get_data()['code'] );
		$this->assertSame( 0, $this->order_count() );
	}

	/**
	 * The member's own access list binds the till exactly as it binds the member.
	 */
	public function testALocationOutsideTheMembersAccessListIsRefused() {
		$organization = $this->make_organization();
		$north        = $this->make_location( $organization, array( 'name' => 'North' ) );
		$south        = $this->make_location( $organization, array( 'name' => 'South' ) );
		$member       = $this->make_member( $organization, Member::ROLE_MEMBER );

		MemberRepository::set_location_ids( $member->get_id(), array( $north->get_id() ) );

		$response = $this->post_order(
			array(
				'customer_id'      => $member->get_user_id(),
				'woap_location_id' => $south->get_id(),
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 0, $this->order_count() );
	}

	/**
	 * An incomplete location is named rather than shipped to.
	 */
	public function testAnIncompleteLocationIsNamedRatherThanShippedTo() {
		$organization = $this->make_organization();
		$member       = $this->make_member( $organization, Member::ROLE_MEMBER );
		$location     = $this->make_location(
			$organization,
			array(
				'name'     => 'Halb Fertig',
				'postcode' => '',
			)
		);

		$response = $this->post_order(
			array(
				'customer_id'      => $member->get_user_id(),
				'woap_location_id' => $location->get_id(),
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertStringContainsString( 'Halb Fertig', $response->get_data()['message'] );
	}

	/**
	 * A one-off address needs the organization's own switch, exactly as at checkout.
	 */
	public function testAOneOffAddressNeedsTheOrganizationsPermission() {
		$organization = $this->make_organization( array( 'allow_custom_shipping' => false ) );
		$member       = $this->make_member( $organization, Member::ROLE_MEMBER );

		$this->make_location( $organization );

		$refused = $this->post_order( array( 'customer_id' => $member->get_user_id() ) );

		$this->assertSame( 400, $refused->get_status() );
		$this->assertSame( 'woap_rest_shipping_destination', $refused->get_data()['code'] );

		$organization->set( 'allow_custom_shipping', true );
		OrganizationRepository::save( $organization );
		\WooOrgAccounts\Membership\Context::flush();

		$shipping = array(
			'first_name' => 'One',
			'last_name'  => 'Off',
			'address_1'  => '5 Somewhere Else',
			'city'       => 'Bremen',
			'postcode'   => '28195',
			'country'    => 'DE',
			'phone'      => '+49 421 000000',
		);

		$allowed = $this->post_order(
			array(
				'customer_id' => $member->get_user_id(),
				'shipping'    => $shipping,
			)
		);

		$this->assertSame( 201, $allowed->get_status() );

		$order = wc_get_order( $allowed->get_data()['id'] );

		$this->assertSame( 'Bremen', $order->get_shipping_city() );
		$this->assertSame( 0, OrderMeta::location_id( $order ) );
		$this->assertSame( $organization->get_id(), OrderMeta::organization_id( $order ) );
	}

	/**
	 * A one-off address is validated the way the checkout validates one.
	 *
	 * `/wc/v3/orders` itself accepts any partial address, because staff creating
	 * orders by hand are trusted. A till taking a customer's delivery address at the
	 * counter is the checkout case: the same per-country rules run, so an address
	 * accepted here is one the shop's own checkout would have accepted.
	 */
	public function testAOneOffAddressIsValidatedPerCountry() {
		$organization = $this->make_organization();
		$member       = $this->make_member( $organization, Member::ROLE_MEMBER );

		$response = $this->post_order(
			array(
				'customer_id' => $member->get_user_id(),
				'shipping'    => array(
					'first_name' => 'One',
					'last_name'  => 'Off',
					'address_1'  => '5 Somewhere Else',
					'city'       => '',
					'postcode'   => '28195',
					'country'    => 'DE',
				),
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'woap_rest_shipping_address', $response->get_data()['code'] );
		$this->assertStringContainsString( 'required', $response->get_data()['message'] );
		$this->assertSame( 0, $this->order_count() );
	}

	/**
	 * A one-off address is normalised the way the checkout normalises one.
	 *
	 * A customer saying "California" has not made a mistake; the order stores the
	 * code, exactly as `WC_Checkout::validate_posted_data()` would have stored it.
	 */
	public function testAOneOffAddressIsNormalisedLikeTheCheckout() {
		$organization = $this->make_organization();
		$member       = $this->make_member( $organization, Member::ROLE_MEMBER );

		$response = $this->post_order(
			array(
				'customer_id' => $member->get_user_id(),
				'shipping'    => array(
					'first_name' => 'One',
					'last_name'  => 'Off',
					'address_1'  => '1 Sunset Blvd',
					'city'       => 'Los Angeles',
					'state'      => 'california',
					'postcode'   => '90210',
					'country'    => 'US',
					'phone'      => '+1 310 000 0000',
				),
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'CA', wc_get_order( $response->get_data()['id'] )->get_shipping_state() );
	}

	/**
	 * An order that needs no shipping needs no location.
	 *
	 * A walk-out sale at the counter has no parcel. needs_shipping_address() reads the
	 * order's shipping lines, so an order posted without any is not asked to name a
	 * destination — but it is still billed and stamped like every other.
	 */
	public function testAnOrderWithoutShippingNeedsNoLocation() {
		$organization = $this->make_organization( array( 'allow_custom_shipping' => false ) );
		$member       = $this->make_member( $organization, Member::ROLE_MEMBER );

		$response = $this->post_order(
			array(
				'customer_id'    => $member->get_user_id(),
				'shipping_lines' => array(),
			)
		);

		$this->assertSame( 201, $response->get_status() );

		$order = wc_get_order( $response->get_data()['id'] );

		$this->assertSame( 'Berlin', $order->get_billing_city() );
		$this->assertSame( $organization->get_id(), OrderMeta::organization_id( $order ) );
		$this->assertSame( 0, OrderMeta::location_id( $order ) );
	}

	/**
	 * An order for a customer with no membership is not this plugin's business.
	 *
	 * The same rule the order capabilities follow: no organization, no opinion. The
	 * posted billing address survives untouched and nothing is stamped.
	 */
	public function testAnOrderForANonMemberIsLeftAlone() {
		$customer = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$response = $this->post_order( array( 'customer_id' => $customer ) );

		$this->assertSame( 201, $response->get_status() );

		$order = wc_get_order( $response->get_data()['id'] );

		$this->assertSame( 'Nowhere', $order->get_billing_city() );
		$this->assertSame( 0, OrderMeta::organization_id( $order ) );
		$this->assertSame( 0, $response->get_data()['woap_organization_id'] );
	}

	/**
	 * A status update leaves the stamp, the billing and the delivery untouched.
	 */
	public function testAStatusUpdateLeavesTheOrderAlone() {
		$organization = $this->make_organization();
		$member       = $this->make_member( $organization, Member::ROLE_MEMBER );
		$location     = $this->make_location( $organization );

		$created = $this->post_order(
			array(
				'customer_id'      => $member->get_user_id(),
				'woap_location_id' => $location->get_id(),
			)
		)->get_data();

		$response = $this->put_order( $created['id'], array( 'status' => 'completed' ) );

		$this->assertSame( 200, $response->get_status() );

		$order = wc_get_order( $created['id'] );

		$this->assertSame( 'completed', $order->get_status() );
		$this->assertSame( 'Berlin', $order->get_billing_city() );
		$this->assertSame( $location->get_id(), OrderMeta::location_id( $order ) );
		$this->assertSame( $member->get_user_id(), OrderMeta::member_user_id( $order ) );
	}

	/**
	 * The shop may re-point delivery within the organization — and only within it.
	 *
	 * The member stamp survives the move: which employee placed the order is a fact
	 * about what happened, and moving the parcel does not change it.
	 */
	public function testDeliveryMovesWithinTheOrganizationAndNotAcrossIt() {
		$organization = $this->make_organization();
		$other        = $this->make_organization( array( 'name' => 'Rivals AG' ) );
		$member       = $this->make_member( $organization, Member::ROLE_MEMBER );
		$north        = $this->make_location( $organization, array( 'name' => 'North' ) );
		$south        = $this->make_location(
			$organization,
			array(
				'name' => 'South',
				'city' => 'Bremen',
			)
		);
		$theirs       = $this->make_location( $other );

		$created = $this->post_order(
			array(
				'customer_id'      => $member->get_user_id(),
				'woap_location_id' => $north->get_id(),
			)
		)->get_data();

		$moved = $this->put_order( $created['id'], array( 'woap_location_id' => $south->get_id() ) );

		$this->assertSame( 200, $moved->get_status() );

		$order = wc_get_order( $created['id'] );

		$this->assertSame( 'Bremen', $order->get_shipping_city() );
		$this->assertSame( $south->get_id(), OrderMeta::location_id( $order ) );
		$this->assertSame( 'South', OrderMeta::location_name( $order ) );
		$this->assertSame( $member->get_user_id(), OrderMeta::member_user_id( $order ) );

		$refused = $this->put_order( $created['id'], array( 'woap_location_id' => $theirs->get_id() ) );

		$this->assertSame( 400, $refused->get_status() );
		$this->assertSame( $south->get_id(), OrderMeta::location_id( wc_get_order( $created['id'] ) ) );
	}

	/**
	 * A non-organization order cannot be pointed at anybody's location.
	 */
	public function testMovingDeliveryOnANonOrganizationOrderIsRefused() {
		$organization = $this->make_organization();
		$location     = $this->make_location( $organization );
		$customer     = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$created = $this->post_order( array( 'customer_id' => $customer ) )->get_data();

		$response = $this->put_order( $created['id'], array( 'woap_location_id' => $location->get_id() ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'woap_rest_not_an_organization_order', $response->get_data()['code'] );
	}

	/**
	 * The order list filters to one organization.
	 */
	public function testTheOrderListFiltersToOneOrganization() {
		$acme   = $this->make_organization( array( 'name' => 'Acme GmbH' ) );
		$rivals = $this->make_organization( array( 'name' => 'Rivals AG' ) );

		$acme_member   = $this->make_member( $acme, Member::ROLE_MEMBER );
		$rivals_member = $this->make_member( $rivals, Member::ROLE_MEMBER );

		$this->make_location( $acme );
		$this->make_location( $rivals );

		$acme_order = $this->post_order(
			array(
				'customer_id'    => $acme_member->get_user_id(),
				'shipping_lines' => array(),
			)
		)->get_data()['id'];

		$this->post_order(
			array(
				'customer_id'    => $rivals_member->get_user_id(),
				'shipping_lines' => array(),
			)
		);

		$request = new \WP_REST_Request( 'GET', self::ROUTE );
		$request->set_query_params( array( 'woap_organization' => $acme->get_id() ) );

		$data = rest_get_server()->dispatch( $request )->get_data();

		$this->assertCount( 1, $data );
		$this->assertSame( $acme_order, $data[0]['id'] );
	}

	/**
	 * A till order lands inside the organization's own account screens.
	 *
	 * The seam test: our side — the stamp — and WooCommerce's side — who may open the
	 * order — asserted together, the lesson of the invalid-order bug. An organization
	 * admin who never placed this order can open it, because it is the organization's.
	 */
	public function testAnOrganizationAdminCanOpenATillOrder() {
		$organization = $this->make_organization();
		$admin        = $this->make_member( $organization, Member::ROLE_ADMIN );
		$member       = $this->make_member( $organization, Member::ROLE_MEMBER );
		$location     = $this->make_location( $organization );

		$order_id = $this->post_order(
			array(
				'customer_id'      => $member->get_user_id(),
				'woap_location_id' => $location->get_id(),
			)
		)->get_data()['id'];

		$this->act_as( $admin );

		$this->assertTrue( current_user_can( 'view_order', $order_id ) );
	}
}
