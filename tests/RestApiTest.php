<?php
/**
 * Organization snapshot route tests.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Tests;

use WooOrgAccounts\Data\LocationRepository;
use WooOrgAccounts\Data\Member;
use WooOrgAccounts\Data\MemberRepository;
use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Data\OrganizationRepository;
use WooOrgAccounts\Membership\Context;
use WooOrgAccounts\Rest\OrganizationsController;
use WooOrgAccounts\Rest\RestApi;
use WooOrgAccounts\Roles;

/**
 * The read-only snapshot a till syncs from.
 */
class RestApiTest extends TestCase {

	/**
	 * The route, with its namespace.
	 *
	 * @var string
	 */
	const ROUTE = '/' . RestApi::REST_NAMESPACE . '/' . OrganizationsController::ROUTE;

	/**
	 * Fetch one page of the snapshot.
	 *
	 * @param array $params Query parameters.
	 * @param array $headers Request headers.
	 * @return \WP_REST_Response The response.
	 */
	private function fetch( array $params = array(), array $headers = array() ) {
		$request = new \WP_REST_Request( 'GET', self::ROUTE );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * The route is registered.
	 */
	public function testRouteIsRegistered() {
		$this->assertArrayHasKey( self::ROUTE, rest_get_server()->get_routes() );
	}

	/**
	 * The namespace keeps the prefix that lets a WooCommerce key authenticate against it.
	 *
	 * `WC_REST_Authentication` reads a consumer key and secret only for a request whose
	 * URI contains `wc/` or `wc-`; it never looks at any other route. Rename this
	 * namespace to something tidier and every route under it still registers, still
	 * resolves and answers every till on the shop floor with a 401, because the
	 * credentials they hold are not read at all.
	 *
	 * This is asserted rather than remembered because nothing else would fail: the suite
	 * signs a user in directly, which is not how a till reaches this route.
	 */
	public function testTheNamespaceKeepsWooCommercesAuthenticationPrefix() {
		$this->assertStringStartsWith( 'wc-', RestApi::REST_NAMESPACE );

		$request_uri = trailingslashit( rest_get_url_prefix() ) . RestApi::REST_NAMESPACE;

		$this->assertNotFalse( strpos( $request_uri, trailingslashit( rest_get_url_prefix() ) . 'wc-' ) );
	}

	/**
	 * A request from nobody is refused rather than answered with an empty list.
	 */
	public function testTheSnapshotIsRefusedWithoutPermission() {
		$this->make_organization();

		$response = $this->fetch();

		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * Holding this plugin's own capabilities is not enough to read every organization.
	 *
	 * They are granted from a membership and answer what somebody may do to their own
	 * organization. Listing all of them is a different question with a different answer.
	 */
	public function testAnOrganizationAdminCannotReadTheSnapshot() {
		$organization = $this->make_organization();
		$member       = $this->make_member( $organization, Member::ROLE_ADMIN );

		$this->act_as( $member );

		$this->assertTrue( current_user_can( Roles::MANAGE_ORGANIZATION ) );
		$this->assertSame( 403, $this->fetch()->get_status() );
	}

	/**
	 * An organization arrives with its members and its locations in the same payload.
	 */
	public function testOrganizationsCarryTheirMembersAndLocations() {
		$organization = $this->make_organization( array( 'name' => 'Acme GmbH' ) );
		$member       = $this->make_member( $organization, Member::ROLE_ADMIN );
		$location     = $this->make_location( $organization, array( 'name' => 'Warehouse North' ) );

		$this->act_as_shop_manager();

		$response = $this->fetch();
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $data );

		$this->assertSame( $organization->get_id(), $data[0]['id'] );
		$this->assertSame( 'Acme GmbH', $data[0]['name'] );
		$this->assertSame( 'Berlin', $data[0]['billing']['city'] );

		$this->assertCount( 1, $data[0]['members'] );
		$this->assertSame( $member->get_user_id(), $data[0]['members'][0]['user_id'] );

		$this->assertCount( 1, $data[0]['locations'] );
		$this->assertSame( $location->get_id(), $data[0]['locations'][0]['id'] );
		$this->assertSame( 'Hamburg', $data[0]['locations'][0]['city'] );
	}

	/**
	 * The purchase rule is answered by the shop, not left for the device to derive.
	 *
	 * A suspended organization is still listed — the counter needs to be able to say
	 * "this account is suspended" rather than "no such customer" — but nobody in it
	 * may order.
	 */
	public function testWhetherAMemberMayOrderIsDecidedByTheShop() {
		$active    = $this->make_organization( array( 'name' => 'Active GmbH' ) );
		$suspended = $this->make_organization(
			array(
				'name'   => 'Suspended GmbH',
				'status' => Organization::STATUS_SUSPENDED,
			)
		);

		$this->make_member( $active, Member::ROLE_MEMBER );
		$this->make_member( $suspended, Member::ROLE_MEMBER );

		$this->act_as_shop_manager();

		$data = $this->fetch()->get_data();
		$by   = $this->key_by_name( $data );

		$this->assertTrue( $by['Active GmbH']['members'][0]['can_place_orders'] );
		$this->assertSame( Organization::STATUS_SUSPENDED, $by['Suspended GmbH']['status'] );
		$this->assertFalse( $by['Suspended GmbH']['members'][0]['can_place_orders'] );
	}

	/**
	 * An inactive membership within an active organization may not order either.
	 */
	public function testAnInactiveMemberMayNotOrder() {
		$organization = $this->make_organization();

		$this->make_member( $organization, Member::ROLE_MEMBER, array( 'status' => Member::STATUS_INACTIVE ) );

		$this->act_as_shop_manager();

		$member = $this->fetch()->get_data()[0]['members'][0];

		$this->assertSame( Member::STATUS_INACTIVE, $member['status'] );
		$this->assertFalse( $member['can_place_orders'] );
	}

	/**
	 * A member with no access rows may use every location, and says so as "all".
	 *
	 * Sending the empty list the table actually holds would tell the device the
	 * opposite of the truth: that this member has nowhere to ship to.
	 */
	public function testUnrestrictedLocationAccessIsReportedAsAll() {
		$organization = $this->make_organization();

		$this->make_location( $organization );
		$this->make_member( $organization, Member::ROLE_MEMBER );

		$this->act_as_shop_manager();

		$this->assertSame( 'all', $this->fetch()->get_data()[0]['members'][0]['location_access'] );
	}

	/**
	 * A restricted member reports the locations they are held to.
	 */
	public function testRestrictedLocationAccessIsReportedAsAList() {
		$organization = $this->make_organization();
		$north        = $this->make_location( $organization, array( 'name' => 'North' ) );

		$this->make_location( $organization, array( 'name' => 'South' ) );

		$member = $this->make_member( $organization, Member::ROLE_MEMBER );

		MemberRepository::set_location_ids( $member->get_id(), array( $north->get_id() ) );

		$this->act_as_shop_manager();

		$data = $this->fetch()->get_data();

		$this->assertCount( 2, $data[0]['locations'] );
		$this->assertSame( array( $north->get_id() ), $data[0]['members'][0]['location_access'] );
	}

	/**
	 * The capability overrides and the tax ID never leave the site.
	 *
	 * The overrides are a diff against the role defaults, so on a device holding no
	 * defaults table they are not merely useless but misleading. The tax ID is the one
	 * regulated identifier in the table and nothing at the counter reads it.
	 */
	public function testTheSnapshotOmitsWhatTheDeviceMustNotDecideFrom() {
		$organization = $this->make_organization( array( 'tax_id' => 'DE123456789' ) );

		$this->make_member( $organization, Member::ROLE_MEMBER, array( 'capabilities' => wp_json_encode( array( Roles::PLACE_ORDERS => false ) ) ) );

		$this->act_as_shop_manager();

		$data = $this->fetch()->get_data();

		$this->assertArrayNotHasKey( 'tax_id', $data[0] );
		$this->assertArrayNotHasKey( 'capabilities', $data[0]['members'][0] );
		$this->assertStringNotContainsString( 'DE123456789', (string) wp_json_encode( $data ) );

		// The override still reached the answer the device does act on.
		$this->assertFalse( $data[0]['members'][0]['can_place_orders'] );
	}

	/**
	 * A membership whose user has been deleted is left out rather than sent nameless.
	 */
	public function testAMembershipWithNoUserIsOmitted() {
		require_once ABSPATH . 'wp-admin/includes/user.php';

		$organization = $this->make_organization();
		$member       = $this->make_member( $organization, Member::ROLE_MEMBER );

		wp_delete_user( $member->get_user_id() );
		MemberRepository::flush_cache();
		Context::flush();

		$this->act_as_shop_manager();

		$this->assertSame( array(), $this->fetch()->get_data()[0]['members'] );
	}

	/**
	 * Paging reports the totals and hands out every organization exactly once.
	 */
	public function testPagingCoversTheWholeSetWithoutRepeatingAnything() {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->make_organization( array( 'name' => 'Org ' . $i ) );
		}

		$this->act_as_shop_manager();

		$first = $this->fetch( array( 'per_page' => 2 ) );

		$this->assertSame( '5', $first->get_headers()['X-WP-Total'] );
		$this->assertSame( '3', $first->get_headers()['X-WP-TotalPages'] );

		$seen = array();

		for ( $page = 1; $page <= 3; $page++ ) {
			foreach ( $this->fetch(
				array(
					'per_page' => 2,
					'page'     => $page,
				)
			)->get_data() as $organization ) {
				$seen[] = $organization['id'];
			}
		}

		$this->assertCount( 5, $seen );
		$this->assertSame( $seen, array_unique( $seen ) );
	}

	/**
	 * A page beyond the end is an error, not an empty list that looks like a finished sync.
	 */
	public function testAPageBeyondTheEndIsAnError() {
		$this->make_organization();
		$this->act_as_shop_manager();

		$this->assertSame( 400, $this->fetch( array( 'page' => 9 ) )->get_status() );
	}

	/**
	 * Ordering is by ID, so a rename cannot move a row between pages mid-sync.
	 */
	public function testTheSnapshotIsOrderedByIdRatherThanName() {
		$zulu = $this->make_organization( array( 'name' => 'Zulu GmbH' ) );
		$alfa = $this->make_organization( array( 'name' => 'Alfa GmbH' ) );

		$this->act_as_shop_manager();

		$ids = array_column( $this->fetch()->get_data(), 'id' );

		$this->assertSame( array( $zulu->get_id(), $alfa->get_id() ), $ids );
	}

	/**
	 * An unchanged page is answered with a 304, so an interval sync costs no transfer.
	 */
	public function testAnUnchangedPageIsNotSentAgain() {
		$organization = $this->make_organization();

		$this->act_as_shop_manager();

		$first = $this->fetch();
		$etag  = $first->get_headers()['ETag'];

		$this->assertNotEmpty( $etag );

		$unchanged = $this->fetch( array(), array( 'If-None-Match' => $etag ) );

		$this->assertSame( 304, $unchanged->get_status() );
		$this->assertNull( $unchanged->get_data() );

		$organization->set( 'name', 'Renamed GmbH' );
		OrganizationRepository::save( $organization );

		$changed = $this->fetch( array(), array( 'If-None-Match' => $etag ) );

		$this->assertSame( 200, $changed->get_status() );
		$this->assertSame( 'Renamed GmbH', $changed->get_data()[0]['name'] );
	}

	/**
	 * A change to a member alone changes the page, even though members carry no date.
	 *
	 * This is the reason the route serves snapshots: with only `date_created` on the
	 * members table, a delta keyed on a timestamp would have reported nothing here.
	 */
	public function testAChangeBelowTheOrganizationStillChangesThePage() {
		$organization = $this->make_organization();
		$member       = $this->make_member( $organization, Member::ROLE_MEMBER );

		$this->act_as_shop_manager();

		$etag = $this->fetch()->get_headers()['ETag'];

		MemberRepository::delete( $member->get_id() );
		MemberRepository::flush_cache();
		Context::flush();

		$after = $this->fetch( array(), array( 'If-None-Match' => $etag ) );

		$this->assertSame( 200, $after->get_status() );
		$this->assertSame( array(), $after->get_data()[0]['members'] );
	}

	/**
	 * The batched reads answer for every organization asked about, including empty ones.
	 *
	 * A missing key and "has no members" would look the same to the caller, and an
	 * organization with no locations is the state that stops its members checking out —
	 * worth reporting rather than omitting.
	 */
	public function testBatchedReadsAnswerForEveryOrganizationAskedAbout() {
		$with    = $this->make_organization( array( 'name' => 'With' ) );
		$without = $this->make_organization( array( 'name' => 'Without' ) );

		$this->make_member( $with, Member::ROLE_MEMBER );
		$this->make_location( $with );

		$ids = array( $with->get_id(), $without->get_id() );

		$members   = MemberRepository::for_organizations( $ids );
		$locations = LocationRepository::for_organizations( $ids );

		$this->assertCount( 1, $members[ $with->get_id() ] );
		$this->assertSame( array(), $members[ $without->get_id() ] );

		$this->assertCount( 1, $locations[ $with->get_id() ] );
		$this->assertSame( array(), $locations[ $without->get_id() ] );

		$this->assertSame( array(), MemberRepository::for_organizations( array() ) );
		$this->assertSame( array(), LocationRepository::for_organizations( array() ) );
	}

	/**
	 * The status filter narrows the snapshot without changing its shape.
	 */
	public function testTheStatusFilterLimitsTheSnapshot() {
		$this->make_organization( array( 'name' => 'Active GmbH' ) );
		$this->make_organization(
			array(
				'name'   => 'Pending GmbH',
				'status' => Organization::STATUS_PENDING,
			)
		);

		$this->act_as_shop_manager();

		$response = $this->fetch( array( 'status' => Organization::STATUS_PENDING ) );
		$data     = $response->get_data();

		$this->assertSame( '1', $response->get_headers()['X-WP-Total'] );
		$this->assertCount( 1, $data );
		$this->assertSame( 'Pending GmbH', $data[0]['name'] );
	}

	/**
	 * A made-up status is refused rather than silently answered with everything.
	 */
	public function testAnUnknownStatusIsRefused() {
		$this->make_organization();
		$this->act_as_shop_manager();

		$this->assertSame( 400, $this->fetch( array( 'status' => 'imaginary' ) )->get_status() );
	}

	/**
	 * A page size beyond the cap is refused, not silently clamped.
	 *
	 * A device that asks for 10,000 and is quietly handed 200 believes its sync is
	 * complete when it holds a fraction of the data; a 400 is a bug it will notice.
	 */
	public function testAPageSizeBeyondTheCapIsRefused() {
		$this->make_organization();
		$this->act_as_shop_manager();

		$response = $this->fetch( array( 'per_page' => OrganizationsController::MAX_PER_PAGE + 1 ) );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * The billing block carries every WooCommerce billing field, present or empty.
	 *
	 * The device maps this straight onto an order's billing address, so a key that
	 * disappears when its value is empty would make the mapping conditional.
	 */
	public function testTheBillingBlockCarriesEveryWooCommerceBillingField() {
		$this->make_organization();
		$this->act_as_shop_manager();

		$billing = $this->fetch()->get_data()[0]['billing'];

		foreach ( Organization::BILLING_FIELDS as $field ) {
			$this->assertArrayHasKey( $field, $billing );
		}
	}

	/**
	 * Addresses arrive formatted for their country as well as as fields.
	 *
	 * WooCommerce's formatter puts the postcode before the city for a German address
	 * and after it for an American one; serving its output means the device never
	 * invents an envelope layout. Newlines, not the `<br/>` the web templates use —
	 * the consumer is an app.
	 */
	public function testAddressesArriveFormattedForTheirCountry() {
		$organization = $this->make_organization();

		$this->make_location( $organization );
		$this->act_as_shop_manager();

		$data = $this->fetch()->get_data()[0];

		$this->assertStringContainsString( '10115 Berlin', $data['billing_formatted'] );
		$this->assertStringContainsString( '20095 Hamburg', $data['locations'][0]['formatted'] );
		$this->assertStringNotContainsString( '<br', $data['billing_formatted'] );
	}

	/**
	 * The route describes itself, so a client can be written against the schema.
	 *
	 * Asked the way an OPTIONS request is answered — through the route's registered
	 * options in help context — so this fails if the schema callback ever falls off
	 * the route registration, not merely if the method stops returning an array.
	 */
	public function testTheRouteDescribesItsSchema() {
		$server = rest_get_server();
		$data   = $server->get_data_for_route( self::ROUTE, $server->get_routes()[ self::ROUTE ], 'help' );

		$this->assertSame( 'woap_organization', $data['schema']['title'] );
		$this->assertArrayHasKey( 'members', $data['schema']['properties'] );
		$this->assertArrayHasKey( 'locations', $data['schema']['properties'] );
	}

	/**
	 * Index a payload by organization name.
	 *
	 * @param array $data The payload.
	 * @return array Map of name to organization.
	 */
	private function key_by_name( array $data ) {
		$by_name = array();

		foreach ( $data as $organization ) {
			$by_name[ $organization['name'] ] = $organization;
		}

		return $by_name;
	}
}
