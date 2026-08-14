<?php
/**
 * Tests for the write half of the plugin's own REST namespace.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Tests;

use WooOrgAccounts\Data\Invitation;
use WooOrgAccounts\Data\InvitationRepository;
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
 * The routes a back-office app reviews, approves and edits through.
 */
class RestWritesTest extends TestCase {

	/**
	 * The organizations route, with its namespace.
	 *
	 * @var string
	 */
	const ROUTE = '/' . RestApi::REST_NAMESPACE . '/' . OrganizationsController::ROUTE;

	/**
	 * A billing address the checkout would accept.
	 *
	 * @return array Address fields, keyed without a prefix.
	 */
	private function billing() {
		return array(
			'first_name' => 'Ada',
			'last_name'  => 'Byron',
			'company'    => 'Acme GmbH',
			'address_1'  => '1 Hauptstrasse',
			'city'       => 'Berlin',
			'postcode'   => '10115',
			'country'    => 'DE',
			'email'      => 'buy@acme.test',
			'phone'      => '+49 30 123456',
		);
	}

	/**
	 * A delivery address the checkout would accept.
	 *
	 * @return array Address fields, keyed without a prefix.
	 */
	private function delivery() {
		return array(
			'first_name' => 'Grace',
			'address_1'  => '9 Lagerweg',
			'city'       => 'Hamburg',
			'postcode'   => '20095',
			'country'    => 'DE',
		);
	}

	/**
	 * Dispatch a request with a JSON body.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route, with its namespace.
	 * @param array  $body   Request body.
	 * @return \WP_REST_Response The response.
	 */
	private function send( $method, $route, array $body = array() ) {
		$request = new \WP_REST_Request( $method, $route );

		if ( ! empty( $body ) ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * The locations route of one organization.
	 *
	 * @param Organization $organization The organization.
	 * @param int          $location_id  A location, or 0 for the collection.
	 * @return string Route.
	 */
	private function locations_route( Organization $organization, $location_id = 0 ) {
		$route = self::ROUTE . '/' . $organization->get_id() . '/locations';

		return $location_id > 0 ? $route . '/' . $location_id : $route;
	}

	/**
	 * The members route of one organization.
	 *
	 * @param Organization $organization The organization.
	 * @param int          $member_id    A member, or 0 for the collection.
	 * @return string Route.
	 */
	private function members_route( Organization $organization, $member_id = 0 ) {
		$route = self::ROUTE . '/' . $organization->get_id() . '/members';

		return $member_id > 0 ? $route . '/' . $member_id : $route;
	}

	/**
	 * Every write route is registered.
	 */
	public function testTheWriteRoutesAreRegistered() {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( self::ROUTE . '/(?P<id>[\d]+)', $routes );
		$this->assertArrayHasKey( self::ROUTE . '/(?P<id>[\d]+)/status', $routes );
		$this->assertArrayHasKey( self::ROUTE . '/(?P<organization_id>[\d]+)/locations', $routes );
		$this->assertArrayHasKey( self::ROUTE . '/(?P<organization_id>[\d]+)/members', $routes );
	}

	/**
	 * A write from nobody is refused.
	 */
	public function testWritingIsRefusedWithoutPermission() {
		$response = $this->send( 'POST', self::ROUTE, array( 'name' => 'Acme GmbH' ) );

		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * Holding this plugin's own capabilities is not permission to write here.
	 *
	 * They are granted from a membership and answer what somebody may do to their own
	 * organization. Approving one is a different question with a different answer, and
	 * an organization admin approving their own registration is the reason it has to be.
	 */
	public function testAnOrganizationAdminCannotWriteThroughThisNamespace() {
		$organization = $this->make_organization( array( 'status' => Organization::STATUS_PENDING ) );
		$member       = $this->make_member( $organization, Member::ROLE_ADMIN );

		$this->act_as( $member );

		$this->assertTrue( current_user_can( Roles::MANAGE_ORGANIZATION ) );

		$response = $this->send(
			'POST',
			self::ROUTE . '/' . $organization->get_id() . '/status',
			array( 'status' => Organization::STATUS_ACTIVE )
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame(
			Organization::STATUS_PENDING,
			OrganizationRepository::find( $organization->get_id() )->get_status()
		);
	}

	/**
	 * A new organization starts pending, so it goes through the shop's review.
	 */
	public function testACreatedOrganizationStartsPending() {
		$this->act_as_shop_manager();

		$response = $this->send(
			'POST',
			self::ROUTE,
			array(
				'name'    => 'Bauer & Söhne',
				'tax_id'  => 'DE123456789',
				'billing' => $this->billing(),
			)
		);

		$data = $response->get_data();

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'Bauer & Söhne', $data['name'] );
		$this->assertSame( Organization::STATUS_PENDING, $data['status'] );

		$stored = OrganizationRepository::find( $data['id'] );

		$this->assertSame( 'DE123456789', (string) $stored->get( 'tax_id' ) );
		$this->assertSame( '10115', $stored->get_billing_address()['postcode'] );
	}

	/**
	 * An organization written through the API reads back identically from the snapshot.
	 *
	 * The round trip rather than the fields one at a time: two code paths describing one
	 * organization differently is the failure this namespace is shaped to prevent, and
	 * it is not a failure any single-field assertion would notice.
	 */
	public function testAnOrganizationCreatedOverTheApiComesBackInTheSnapshot() {
		$this->act_as_shop_manager();

		$created = $this->send(
			'POST',
			self::ROUTE,
			array(
				'name'    => 'Bauer & Söhne',
				'billing' => $this->billing(),
			)
		)->get_data();

		$item = $this->send( 'GET', self::ROUTE . '/' . $created['id'] )->get_data();
		$page = $this->send( 'GET', self::ROUTE )->get_data();

		$this->assertSame( $created, $item );
		$this->assertSame( $created, $page[0] );
	}

	/**
	 * An address the checkout would reject is refused, and the field is named.
	 *
	 * A notice saying that *something* is wrong with a fourteen-field address is not a
	 * usable answer for a form, which is why the refusal carries a map of field to
	 * reason — in WordPress's own `params` shape, keyed by the path the client sent.
	 */
	public function testAnOrganizationIsRefusedWithAnAddressTheCheckoutWouldReject() {
		$this->act_as_shop_manager();

		$response = $this->send(
			'POST',
			self::ROUTE,
			array(
				'name'    => 'Bauer & Söhne',
				'billing' => array_merge( $this->billing(), array( 'postcode' => 'nonsense' ) ),
			)
		);

		$data = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'woap_rest_invalid_organization', $data['code'] );
		$this->assertArrayHasKey( 'billing.postcode', $data['data']['params'] );
		$this->assertStringNotContainsString( '<strong>', $data['data']['params']['billing.postcode'] );
		$this->assertSame( 0, OrganizationRepository::count() );
	}

	/**
	 * An organization with no name is refused, exactly as on the admin screen.
	 */
	public function testAnOrganizationIsRefusedWithoutAName() {
		$this->act_as_shop_manager();

		$response = $this->send(
			'POST',
			self::ROUTE,
			array(
				'name'    => '   ',
				'billing' => $this->billing(),
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertArrayHasKey( 'name', $response->get_data()['data']['params'] );
	}

	/**
	 * A shop that insists on a tax ID insists on it here too.
	 *
	 * One predicate, not three: a field registration demands and another screen lets you
	 * blank again is not a required field.
	 */
	public function testTheTaxIdIsRequiredWhenTheShopRequiresIt() {
		$this->set_setting( 'require_tax_id', true );
		$this->act_as_shop_manager();

		$response = $this->send(
			'POST',
			self::ROUTE,
			array(
				'name'    => 'Bauer & Söhne',
				'billing' => $this->billing(),
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertArrayHasKey( 'tax_id', $response->get_data()['data']['params'] );
	}

	/**
	 * An edit changes what it names and leaves everything else where it was.
	 */
	public function testAnEditIsPartial() {
		$organization = $this->make_organization( array( 'tax_id' => 'DE123456789' ) );

		$this->act_as_shop_manager();

		$response = $this->send(
			'PATCH',
			self::ROUTE . '/' . $organization->get_id(),
			array( 'name' => 'Acme Holding GmbH' )
		);

		$stored = OrganizationRepository::find( $organization->get_id() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Acme Holding GmbH', $stored->get_name() );
		$this->assertSame( 'DE123456789', (string) $stored->get( 'tax_id' ) );
		$this->assertSame( '10115', $stored->get_billing_address()['postcode'] );
	}

	/**
	 * A partial address is merged onto the stored one and validated whole.
	 *
	 * Whole rather than field by field, because which fields an address needs depends on
	 * its country: an edit that changes only the country would otherwise leave a US
	 * address with no state, having validated the one field it carried and nothing else.
	 */
	public function testAPartialAddressIsMergedAndValidatedWhole() {
		$this->act_as_shop_manager();

		$organization = $this->send(
			'POST',
			self::ROUTE,
			array(
				'name'    => 'Acme GmbH',
				'billing' => $this->billing(),
			)
		)->get_data();

		$rejected = $this->send(
			'PATCH',
			self::ROUTE . '/' . $organization['id'],
			array( 'billing' => array( 'postcode' => 'nonsense' ) )
		);

		$this->assertSame( 400, $rejected->get_status() );

		$accepted = $this->send(
			'PATCH',
			self::ROUTE . '/' . $organization['id'],
			array( 'billing' => array( 'city' => 'Potsdam' ) )
		);

		$stored = OrganizationRepository::find( $organization['id'] )->get_billing_address();

		$this->assertSame( 200, $accepted->get_status() );
		$this->assertSame( 'Potsdam', $stored['city'] );
		$this->assertSame( '1 Hauptstrasse', $stored['address_1'] );
		$this->assertSame( '10115', $stored['postcode'] );
	}

	/**
	 * An address stored incomplete is reported as incomplete when somebody edits it.
	 *
	 * The counterpart of validating the merged address whole, and the reason the whole
	 * is the right unit: a billing address missing a field the shop's checkout requires
	 * cannot bill, and the edit screen is where somebody can fix it. It is refused with
	 * the missing field named, rather than at a customer's checkout months later — which
	 * is what `AddressFields::missing()` exists for on the plugin's own screens.
	 *
	 * An edit that says nothing about the address is left alone, so a record like this
	 * can still be renamed.
	 */
	public function testAnIncompleteStoredAddressIsSurfacedOnEditRatherThanAtCheckout() {
		$organization = $this->make_organization();

		update_option( 'woocommerce_checkout_phone_field', 'required' );

		$this->act_as_shop_manager();

		$touching = $this->send(
			'PATCH',
			self::ROUTE . '/' . $organization->get_id(),
			array( 'billing' => array( 'city' => 'Potsdam' ) )
		);

		$this->assertSame( 400, $touching->get_status() );
		$this->assertArrayHasKey( 'billing.phone', $touching->get_data()['data']['params'] );

		$renaming = $this->send(
			'PATCH',
			self::ROUTE . '/' . $organization->get_id(),
			array( 'name' => 'Acme Holding GmbH' )
		);

		$this->assertSame( 200, $renaming->get_status() );
		$this->assertSame( 'Acme Holding GmbH', OrganizationRepository::find( $organization->get_id() )->get_name() );
	}

	/**
	 * An ordinary edit cannot approve an organization by carrying a status back.
	 */
	public function testAnEditRefusesToCarryTheStatus() {
		$organization = $this->make_organization( array( 'status' => Organization::STATUS_PENDING ) );

		$this->act_as_shop_manager();

		$response = $this->send(
			'PATCH',
			self::ROUTE . '/' . $organization->get_id(),
			array(
				'name'   => 'Acme Holding GmbH',
				'status' => Organization::STATUS_ACTIVE,
			)
		);

		$stored = OrganizationRepository::find( $organization->get_id() );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'woap_rest_status_has_its_own_route', $response->get_data()['code'] );
		$this->assertSame( Organization::STATUS_PENDING, $stored->get_status() );
		$this->assertSame( 'Acme GmbH', $stored->get_name() );
	}

	/**
	 * Approving fires the hook the emails and the sign-in gate hang off.
	 *
	 * The whole reason the status goes through the repository rather than the column:
	 * writing the column directly approves an account and tells nobody — not the
	 * customer waiting for the mail, and not `LoginGate`.
	 */
	public function testApprovingAnOrganizationFiresTheStatusHook() {
		$organization = $this->make_organization( array( 'status' => Organization::STATUS_PENDING ) );
		$fired        = array();

		add_action(
			'woo_org_accounts_organization_status_changed',
			static function ( $changed, $status, $previous ) use ( &$fired ) {
				$fired[] = array( $changed->get_id(), $status, $previous );
			},
			10,
			3
		);

		$this->act_as_shop_manager();

		$response = $this->send(
			'POST',
			self::ROUTE . '/' . $organization->get_id() . '/status',
			array( 'status' => Organization::STATUS_ACTIVE )
		);

		$data = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['changed'] );
		$this->assertSame( Organization::STATUS_ACTIVE, $data['organization']['status'] );
		$this->assertSame(
			array( array( $organization->get_id(), Organization::STATUS_ACTIVE, Organization::STATUS_PENDING ) ),
			$fired
		);
	}

	/**
	 * Approving an organization that is already approved is not an error, and is silent.
	 *
	 * Two people working the same review queue, or one person pressing the button twice,
	 * must not send the customer a second approval email.
	 */
	public function testApprovingTwiceChangesNothingAndSendsNothing() {
		$organization = $this->make_organization( array( 'status' => Organization::STATUS_ACTIVE ) );
		$fired        = 0;

		add_action(
			'woo_org_accounts_organization_status_changed',
			static function () use ( &$fired ) {
				++$fired;
			}
		);

		$this->act_as_shop_manager();

		$response = $this->send(
			'POST',
			self::ROUTE . '/' . $organization->get_id() . '/status',
			array( 'status' => Organization::STATUS_ACTIVE )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['changed'] );
		$this->assertSame( 0, $fired );
	}

	/**
	 * A status the plugin does not have is refused by the route's own schema.
	 */
	public function testAnUnknownStatusIsRefused() {
		$organization = $this->make_organization();

		$this->act_as_shop_manager();

		$response = $this->send(
			'POST',
			self::ROUTE . '/' . $organization->get_id() . '/status',
			array( 'status' => 'approved' )
		);

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * An organization that does not exist is a 404 rather than a silent no-op.
	 */
	public function testAnUnknownOrganizationIsNotFound() {
		$this->act_as_shop_manager();

		$response = $this->send( 'GET', self::ROUTE . '/4321' );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'woap_rest_organization_not_found', $response->get_data()['code'] );
	}

	/**
	 * A branch is added, and a blank company takes the organization's name.
	 *
	 * A parcel with no company on the label is one nobody at a loading bay recognises.
	 */
	public function testAddingALocationFallsBackToTheOrganizationName() {
		$organization = $this->make_organization( array( 'name' => 'Acme GmbH' ) );

		$this->act_as_shop_manager();

		$response = $this->send(
			'POST',
			$this->locations_route( $organization ),
			array_merge( $this->delivery(), array( 'name' => 'Warehouse North' ) )
		);

		$data = $response->get_data();

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'Warehouse North', $data['name'] );
		$this->assertSame( 'Acme GmbH', $data['company'] );
		$this->assertCount( 1, LocationRepository::for_organization( $organization->get_id() ) );
	}

	/**
	 * A delivery address needs no surname and no phone, whatever the shop asks a buyer for.
	 *
	 * The two relaxations exist because "Warehouse North" has no surname, and because a
	 * shop switching the phone field on must not make every address it has already saved
	 * undeliverable.
	 */
	public function testALocationNeedsNeitherSurnameNorPhone() {
		$organization = $this->make_organization();

		update_option( 'woocommerce_checkout_phone_field', 'required' );

		$this->act_as_shop_manager();

		$response = $this->send(
			'POST',
			$this->locations_route( $organization ),
			array_merge( $this->delivery(), array( 'name' => 'Warehouse North' ) )
		);

		$this->assertSame( 201, $response->get_status() );
	}

	/**
	 * A location the country would reject is refused, naming the field.
	 */
	public function testALocationIsRefusedWithAPostcodeItsCountryWouldReject() {
		$organization = $this->make_organization();

		$this->act_as_shop_manager();

		$response = $this->send(
			'POST',
			$this->locations_route( $organization ),
			array_merge(
				$this->delivery(),
				array(
					'name'     => 'Warehouse North',
					'postcode' => 'nonsense',
				)
			)
		);

		$data = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertArrayHasKey( 'postcode', $data['data']['params'] );
		$this->assertCount( 0, LocationRepository::for_organization( $organization->get_id() ) );
	}

	/**
	 * A location of another organization is not reachable under this one.
	 *
	 * The capability question and the belongs-to-this-organization question are separate,
	 * and answering only the first is the whole of cross-tenant access — even on a route
	 * only the shop's own staff can reach, because the ID comes from a client.
	 */
	public function testALocationOfAnotherOrganizationIsNotFound() {
		$ours   = $this->make_organization( array( 'name' => 'Acme GmbH' ) );
		$theirs = $this->make_organization( array( 'name' => 'Beta AG' ) );
		$their  = $this->make_location( $theirs, array( 'name' => 'Their Warehouse' ) );

		$this->act_as_shop_manager();

		$response = $this->send(
			'PATCH',
			$this->locations_route( $ours, $their->get_id() ),
			array( 'name' => 'Taken over' )
		);

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame(
			'Their Warehouse',
			LocationRepository::find( $their->get_id() )->get_name()
		);
	}

	/**
	 * Deleting a location takes it out of every member's access list as it goes.
	 */
	public function testDeletingALocationForgetsTheAccessPointingAtIt() {
		$organization = $this->make_organization();
		$north        = $this->make_location( $organization, array( 'name' => 'Warehouse North' ) );
		$member       = $this->make_member( $organization, Member::ROLE_MEMBER );

		MemberRepository::set_location_ids( $member->get_id(), array( $north->get_id() ) );

		$this->act_as_shop_manager();

		$response = $this->send( 'DELETE', $this->locations_route( $organization, $north->get_id() ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['deleted'] );
		$this->assertSame( 'Warehouse North', $data['previous']['name'] );
		$this->assertFalse( $data['organization_can_ship'] );
		$this->assertSame( array(), MemberRepository::location_ids( $member->get_id() ) );
	}

	/**
	 * Inviting somebody creates an invitation, and the token never leaves in a payload.
	 *
	 * It exists in plaintext for one function call and goes into one email; the row keeps
	 * only a digest. A response carrying it would put a working key to somebody's
	 * organization into every log the client writes.
	 */
	public function testInvitingSomebodyNeverReturnsTheToken() {
		$organization = $this->make_organization();

		$this->act_as_shop_manager();

		$response = $this->send(
			'POST',
			$this->members_route( $organization ),
			array(
				'email' => 'new@acme.test',
				'role'  => Member::ROLE_MEMBER,
			)
		);

		$data = $response->get_data();

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'new@acme.test', $data['email'] );
		$this->assertArrayNotHasKey( 'token', $data );
		$this->assertStringNotContainsString( 'token', (string) wp_json_encode( $data ) );

		$invitation = InvitationRepository::find_pending_for_email( $organization->get_id(), 'new@acme.test' );

		$this->assertInstanceOf( Invitation::class, $invitation );
		$this->assertSame( 0, MemberRepository::count_for_organization( $organization->get_id() ) );
	}

	/**
	 * An invitation cannot carry permissions, because there is nothing yet to apply them to.
	 *
	 * Refused rather than dropped: a client that sent them would otherwise believe an
	 * employee had been restricted when nothing of the sort had happened.
	 */
	public function testAnInvitationCannotCarryMembershipFields() {
		$organization = $this->make_organization();

		$this->act_as_shop_manager();

		$response = $this->send(
			'POST',
			$this->members_route( $organization ),
			array(
				'email'           => 'new@acme.test',
				'location_access' => 'all',
			)
		);

		$data = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'woap_rest_invitation_extras', $data['code'] );
		$this->assertArrayHasKey( 'location_access', $data['data']['params'] );
		$this->assertNull( InvitationRepository::find_pending_for_email( $organization->get_id(), 'new@acme.test' ) );
	}

	/**
	 * Creating an employee outright makes the account, the membership and the WordPress role.
	 */
	public function testCreatingAnEmployeeMakesTheAccountAndTheMembership() {
		$organization = $this->make_organization();

		$this->act_as_shop_manager();

		$response = $this->send(
			'POST',
			$this->members_route( $organization ),
			array(
				'email'      => 'karl@acme.test',
				'method'     => 'create',
				'role'       => Member::ROLE_MEMBER,
				'first_name' => 'Karl',
				'last_name'  => 'Schmidt',
			)
		);

		$data = $response->get_data();

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'karl@acme.test', $data['email'] );
		$this->assertSame( Member::ROLE_MEMBER, $data['role'] );
		$this->assertTrue( $data['capabilities_follow_role'] );

		$user = get_user_by( 'email', 'karl@acme.test' );

		$this->assertInstanceOf( \WP_User::class, $user );
		$this->assertContains( Roles::ROLE_MEMBER, $user->roles );
		$this->assertSame( $organization->get_id(), MemberRepository::find_by_user( $user->ID )->get_organization_id() );
	}

	/**
	 * An address that already has a WordPress account is joined up, not duplicated.
	 */
	public function testAnExistingAccountIsJoinedRatherThanDuplicated() {
		$organization = $this->make_organization();
		$user_id      = self::factory()->user->create(
			array(
				'role'       => 'customer',
				'user_email' => 'karl@acme.test',
			)
		);

		$this->act_as_shop_manager();

		$response = $this->send(
			'POST',
			$this->members_route( $organization ),
			array(
				'email'  => 'karl@acme.test',
				'method' => 'create',
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( $user_id, $response->get_data()['user_id'] );
		$this->assertCount( 1, get_users( array( 'search' => 'karl@acme.test' ) ) );
	}

	/**
	 * Somebody who can manage the shop keeps their WordPress role.
	 *
	 * `set_role()` replaces every role a user holds, so a shop manager who also buys on
	 * an organization's account would be demoted out of wp-admin by being added to one.
	 * Their capabilities within the organization come from the membership row regardless.
	 */
	public function testAddingAShopManagerLeavesTheirWordPressRoleAlone() {
		$organization = $this->make_organization();
		$user_id      = self::factory()->user->create(
			array(
				'role'       => 'administrator',
				'user_email' => 'owner@acme.test',
			)
		);

		$this->act_as_shop_manager();

		$response = $this->send(
			'POST',
			$this->members_route( $organization ),
			array(
				'email'  => 'owner@acme.test',
				'method' => 'create',
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$this->assertContains( 'administrator', get_userdata( $user_id )->roles );
		$this->assertNotNull( MemberRepository::find_by_user( $user_id ) );
	}

	/**
	 * Somebody who already belongs to an organization is never moved into another.
	 *
	 * The membership row is what every order they have placed is scoped by, and the
	 * column is UNIQUE — so the alternative to refusing is failing at the database, which
	 * is a worse way to be told.
	 */
	public function testAnAddressBelongingToAnotherOrganizationIsRefused() {
		$theirs = $this->make_organization( array( 'name' => 'Beta AG' ) );
		$ours   = $this->make_organization( array( 'name' => 'Acme GmbH' ) );
		$member = $this->make_member( $theirs, Member::ROLE_MEMBER );
		$email  = get_userdata( $member->get_user_id() )->user_email;

		$this->act_as_shop_manager();

		$response = $this->send(
			'POST',
			$this->members_route( $ours ),
			array(
				'email'  => $email,
				'method' => 'create',
			)
		);

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'woap_rest_already_member', $response->get_data()['code'] );
		$this->assertSame(
			$theirs->get_id(),
			MemberRepository::find_by_user( $member->get_user_id() )->get_organization_id()
		);
	}

	/**
	 * A name and an address are edited through the membership, like everything else.
	 *
	 * Neither is stored on the membership row, so this asserts the whole way down: the
	 * payload the route answers with, the WordPress account behind it, and the display
	 * name every screen in this plugin actually prints.
	 */
	public function testAMembersNameAndAddressAreEditedThroughTheMembership() {
		$organization = $this->make_organization();
		$member       = $this->make_member( $organization, Member::ROLE_MEMBER );

		$this->act_as_shop_manager();

		$response = $this->send(
			'PATCH',
			$this->members_route( $organization, $member->get_id() ),
			array(
				'first_name' => 'Karl',
				'last_name'  => 'Schmidt',
				'email'      => 'karl@acme.test',
			)
		);

		$data = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Karl', $data['first_name'] );
		$this->assertSame( 'Schmidt', $data['last_name'] );
		$this->assertSame( 'karl@acme.test', $data['email'] );
		$this->assertSame( 'Karl Schmidt', $data['name'] );

		$user = get_userdata( $member->get_user_id() );

		$this->assertSame( 'karl@acme.test', $user->user_email );
		$this->assertSame( 'Karl', $user->first_name );
		$this->assertSame( 'Schmidt', $user->last_name );
		$this->assertSame( 'Karl Schmidt', $user->display_name );
	}

	/**
	 * An edit that says nothing about the name does not blank it.
	 *
	 * The trap is WordPress's own: a declared argument default is filled into the request
	 * before the callback sees it, so `'default' => ''` on either name would empty the
	 * surname of everybody whose role was changed through this route.
	 */
	public function testAnEditThatSaysNothingAboutTheNameLeavesItAlone() {
		$organization = $this->make_organization();
		$member       = $this->make_member( $organization, Member::ROLE_MEMBER );

		wp_update_user(
			array(
				'ID'         => $member->get_user_id(),
				'first_name' => 'Karl',
				'last_name'  => 'Schmidt',
			)
		);

		$this->act_as_shop_manager();

		$response = $this->send(
			'PATCH',
			$this->members_route( $organization, $member->get_id() ),
			array( 'role' => Member::ROLE_ADMIN )
		);

		$user = get_userdata( $member->get_user_id() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Karl', $user->first_name );
		$this->assertSame( 'Schmidt', $user->last_name );
	}

	/**
	 * An address that already has an account cannot be moved onto another membership.
	 *
	 * And the refusal leaves the rest of the request unwritten: the account is changed
	 * before the membership row precisely so that the failure a client will actually meet
	 * happens while nothing has been written yet. A member promoted to admin by a request
	 * that was then refused would be the worst of both answers.
	 */
	public function testMovingAMemberOntoAnAddressThatIsTakenIsRefused() {
		$organization = $this->make_organization();
		$member       = $this->make_member( $organization, Member::ROLE_MEMBER );

		self::factory()->user->create(
			array(
				'role'       => 'customer',
				'user_email' => 'taken@acme.test',
			)
		);

		$this->act_as_shop_manager();

		$response = $this->send(
			'PATCH',
			$this->members_route( $organization, $member->get_id() ),
			array(
				'email' => 'taken@acme.test',
				'role'  => Member::ROLE_ADMIN,
			)
		);

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'woap_rest_email_taken', $response->get_data()['code'] );
		$this->assertSame( Member::ROLE_MEMBER, MemberRepository::find( $member->get_id() )->get_role() );
	}

	/**
	 * An address on another organization's account is the same refusal as adding one.
	 *
	 * It is the same rule — a person belongs to one organization at a time — so it is the
	 * same code and carries the same pointer to where they already are.
	 */
	public function testMovingAMemberOntoAnotherOrganizationsAddressIsRefused() {
		$theirs        = $this->make_organization( array( 'name' => 'Beta AG' ) );
		$ours          = $this->make_organization( array( 'name' => 'Acme GmbH' ) );
		$member        = $this->make_member( $ours, Member::ROLE_MEMBER );
		$theirs_member = $this->make_member( $theirs, Member::ROLE_MEMBER );

		$this->act_as_shop_manager();

		$response = $this->send(
			'PATCH',
			$this->members_route( $ours, $member->get_id() ),
			array( 'email' => get_userdata( $theirs_member->get_user_id() )->user_email )
		);

		$data = $response->get_data();

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'woap_rest_already_member', $data['code'] );
		$this->assertSame( $theirs->get_id(), $data['data']['organization_id'] );
	}

	/**
	 * A display name the shop set by hand survives a rename.
	 *
	 * The display name is derived, so an edit to the names has to move it or the rename
	 * would be invisible on every screen. It is only ever overwritten when it is still one
	 * of the values it could have been derived from — a shop that has written something
	 * else there has answered the question already.
	 */
	public function testADisplayNameSetByHandSurvivesARename() {
		$organization = $this->make_organization();
		$member       = $this->make_member( $organization, Member::ROLE_MEMBER );

		wp_update_user(
			array(
				'ID'           => $member->get_user_id(),
				'display_name' => 'Karl from the warehouse',
			)
		);

		$this->act_as_shop_manager();

		$response = $this->send(
			'PATCH',
			$this->members_route( $organization, $member->get_id() ),
			array(
				'first_name' => 'Karl',
				'last_name'  => 'Schmidt',
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Karl from the warehouse', $response->get_data()['name'] );
		$this->assertSame( 'Karl', get_userdata( $member->get_user_id() )->first_name );
	}

	/**
	 * An address WordPress would not accept is refused, and nothing is written.
	 */
	public function testAMemberIsRefusedAnAddressThatIsNotOne() {
		$organization = $this->make_organization();
		$member       = $this->make_member( $organization, Member::ROLE_MEMBER );
		$before       = get_userdata( $member->get_user_id() )->user_email;

		$this->act_as_shop_manager();

		$response = $this->send(
			'PATCH',
			$this->members_route( $organization, $member->get_id() ),
			array( 'email' => 'not-an-address' )
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
		$this->assertSame( $before, get_userdata( $member->get_user_id() )->user_email );
	}

	/**
	 * An employee entered by staff is listed under their name, not their address.
	 *
	 * The derivation is WordPress's own — `wp_insert_user()` builds a display name out of
	 * the two names, and falls back to the login only when there are none, which here would
	 * be the address. It is asserted because this plugin depends on it: `display_name` is
	 * what the members list, the organization orders list and wp-admin's order column all
	 * print, and an update re-derives nothing, which is why `update_identity()` has to.
	 */
	public function testACreatedEmployeeIsListedUnderTheirName() {
		$organization = $this->make_organization();

		$this->act_as_shop_manager();

		$response = $this->send(
			'POST',
			$this->members_route( $organization ),
			array(
				'email'      => 'karl@acme.test',
				'method'     => 'create',
				'first_name' => 'Karl',
				'last_name'  => 'Schmidt',
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'Karl Schmidt', $response->get_data()['name'] );
		$this->assertSame( 'Karl Schmidt', get_user_by( 'email', 'karl@acme.test' )->display_name );
	}

	/**
	 * Promoting somebody to admin gives them an admin's permissions.
	 *
	 * The overrides are a diff against the role's defaults, so a client sending back the
	 * map it read for the *old* role would store six overrides against the new one and
	 * produce an organization admin who can manage nothing. This is that bug, asked of
	 * this route.
	 */
	public function testPromotingToAdminGrantsTheAdminDefaults() {
		$organization = $this->make_organization();
		$member       = $this->make_member( $organization, Member::ROLE_MEMBER );

		$this->act_as_shop_manager();

		$response = $this->send(
			'PATCH',
			$this->members_route( $organization, $member->get_id() ),
			array(
				'role'         => Member::ROLE_ADMIN,
				'capabilities' => 'role_default',
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['capabilities_follow_role'] );
		$this->assertSame( array(), MemberRepository::find( $member->get_id() )->get_capabilities() );

		wp_set_current_user( $member->get_user_id() );
		Context::flush();

		$this->assertTrue( current_user_can( Roles::MANAGE_MEMBERS ) );
	}

	/**
	 * Only what differs from the role is stored, whatever the client sends.
	 *
	 * A map that agrees with the role in every particular stores nothing, so the member
	 * follows the role afterwards rather than being pinned to a copy of what it happened
	 * to say today.
	 */
	public function testPermissionsAreStoredAsADiffAgainstTheRole() {
		$organization = $this->make_organization();
		$member       = $this->make_member( $organization, Member::ROLE_MEMBER );

		$this->act_as_shop_manager();

		$agreeing = $this->send(
			'PATCH',
			$this->members_route( $organization, $member->get_id() ),
			array( 'capabilities' => Roles::role_capabilities( Member::ROLE_MEMBER ) )
		);

		$this->assertSame( 200, $agreeing->get_status() );
		$this->assertTrue( $agreeing->get_data()['capabilities_follow_role'] );
		$this->assertSame( array(), MemberRepository::find( $member->get_id() )->get_capabilities() );

		$differing = $this->send(
			'PATCH',
			$this->members_route( $organization, $member->get_id() ),
			array( 'capabilities' => array( Roles::PLACE_ORDERS => false ) )
		);

		$this->assertSame( 200, $differing->get_status() );
		$this->assertFalse( $differing->get_data()['capabilities_follow_role'] );
		$this->assertSame(
			array( Roles::PLACE_ORDERS => false ),
			MemberRepository::find( $member->get_id() )->get_capabilities()
		);
	}

	/**
	 * Refusing somebody the right to buy is a refusal the checkout itself honours.
	 *
	 * The capability written here and the rule the cart applies are two facts, and only
	 * the first is this route's. Asserting them together is what makes the write mean
	 * anything.
	 */
	public function testRefusingPlaceOrdersStopsTheMemberBuying() {
		$organization = $this->make_organization();
		$member       = $this->make_member( $organization, Member::ROLE_MEMBER );

		$this->act_as_shop_manager();

		$this->send(
			'PATCH',
			$this->members_route( $organization, $member->get_id() ),
			array( 'capabilities' => array( Roles::PLACE_ORDERS => false ) )
		);

		Context::flush();
		MemberRepository::flush_cache();

		$this->assertFalse( Context::can_purchase( $member->get_user_id() ) );
	}

	/**
	 * A capability this plugin does not define is refused rather than stored.
	 */
	public function testAnUnknownCapabilityIsRefused() {
		$organization = $this->make_organization();
		$member       = $this->make_member( $organization, Member::ROLE_MEMBER );

		$this->act_as_shop_manager();

		$response = $this->send(
			'PATCH',
			$this->members_route( $organization, $member->get_id() ),
			array( 'capabilities' => array( 'woap_do_anything' => true ) )
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertArrayHasKey( 'capabilities', $response->get_data()['data']['params'] );
		$this->assertSame( array(), MemberRepository::find( $member->get_id() )->get_capabilities() );
	}

	/**
	 * An empty list of locations is refused, because the stored form of it means the opposite.
	 *
	 * No rows means "every location". A client sending `[]` for "none" would get every
	 * location it was trying to take away, silently, which is why there is no way to say
	 * none at all — that is what the member's status is for.
	 */
	public function testAnEmptyLocationListIsRefused() {
		$organization = $this->make_organization();
		$this->make_location( $organization );
		$member = $this->make_member( $organization, Member::ROLE_MEMBER );

		$this->act_as_shop_manager();

		$response = $this->send(
			'PATCH',
			$this->members_route( $organization, $member->get_id() ),
			array( 'location_access' => array() )
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertArrayHasKey( 'location_access', $response->get_data()['data']['params'] );
	}

	/**
	 * Location access cannot be granted to another organization's address.
	 */
	public function testLocationAccessIsScopedToTheOrganization() {
		$organization = $this->make_organization( array( 'name' => 'Acme GmbH' ) );
		$theirs       = $this->make_organization( array( 'name' => 'Beta AG' ) );
		$their        = $this->make_location( $theirs );
		$member       = $this->make_member( $organization, Member::ROLE_MEMBER );

		$this->act_as_shop_manager();

		$response = $this->send(
			'PATCH',
			$this->members_route( $organization, $member->get_id() ),
			array( 'location_access' => array( $their->get_id() ) )
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( array(), MemberRepository::location_ids( $member->get_id() ) );
	}

	/**
	 * Restricting somebody to a location is reported back as the list, not as "all".
	 */
	public function testRestrictingAMemberToOneLocation() {
		$organization = $this->make_organization();
		$north        = $this->make_location( $organization, array( 'name' => 'Warehouse North' ) );
		$this->make_location( $organization, array( 'name' => 'Warehouse South' ) );
		$member = $this->make_member( $organization, Member::ROLE_MEMBER );

		$this->act_as_shop_manager();

		$response = $this->send(
			'PATCH',
			$this->members_route( $organization, $member->get_id() ),
			array( 'location_access' => array( $north->get_id() ) )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( $north->get_id() ), $response->get_data()['location_access'] );
	}

	/**
	 * An organization must keep an admin, whichever way the last one would be lost.
	 */
	public function testTheLastAdminCanBeNeitherDemotedNorRemoved() {
		$organization = $this->make_organization();
		$admin        = $this->make_member( $organization, Member::ROLE_ADMIN );

		$this->act_as_shop_manager();

		$demoted = $this->send(
			'PATCH',
			$this->members_route( $organization, $admin->get_id() ),
			array( 'role' => Member::ROLE_MEMBER )
		);

		$this->assertSame( 400, $demoted->get_status() );
		$this->assertArrayHasKey( 'role', $demoted->get_data()['data']['params'] );

		$suspended = $this->send(
			'PATCH',
			$this->members_route( $organization, $admin->get_id() ),
			array( 'status' => Member::STATUS_INACTIVE )
		);

		$this->assertSame( 400, $suspended->get_status() );

		$removed = $this->send( 'DELETE', $this->members_route( $organization, $admin->get_id() ) );

		$this->assertSame( 409, $removed->get_status() );
		$this->assertTrue( MemberRepository::find( $admin->get_id() )->is_admin() );
	}

	/**
	 * Removing somebody keeps their login and takes away the organization.
	 *
	 * Deleting a WordPress account because somebody changed jobs is not this plugin's
	 * decision; with no membership row they simply cannot buy on the account any more.
	 */
	public function testRemovingAMemberLeavesThemAnOrdinaryCustomer() {
		$organization = $this->make_organization();
		$this->make_member( $organization, Member::ROLE_ADMIN );
		$member = $this->make_member( $organization, Member::ROLE_MEMBER );

		$this->act_as_shop_manager();

		$response = $this->send( 'DELETE', $this->members_route( $organization, $member->get_id() ) );

		MemberRepository::flush_cache();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['deleted'] );
		$this->assertNull( MemberRepository::find_by_user( $member->get_user_id() ) );
		$this->assertContains( 'customer', get_userdata( $member->get_user_id() )->roles );
	}

	/**
	 * A member of another organization is not reachable under this one.
	 */
	public function testAMemberOfAnotherOrganizationIsNotFound() {
		$ours   = $this->make_organization( array( 'name' => 'Acme GmbH' ) );
		$theirs = $this->make_organization( array( 'name' => 'Beta AG' ) );
		$member = $this->make_member( $theirs, Member::ROLE_MEMBER );

		$this->act_as_shop_manager();

		$response = $this->send(
			'PATCH',
			$this->members_route( $ours, $member->get_id() ),
			array( 'role' => Member::ROLE_ADMIN )
		);

		$this->assertSame( 404, $response->get_status() );
		$this->assertFalse( MemberRepository::find( $member->get_id() )->is_admin() );
	}

	/**
	 * Permissions are reported where they are edited, and nowhere else.
	 *
	 * The snapshot is a copy a till syncs and carries on a counter; the permission
	 * configuration of every employee of every organization on the shop is no part of
	 * what it sells with. The members routes are where a screen edits them, so that is
	 * where they are sent.
	 */
	public function testPermissionsAreSentOnlyWhereTheyAreEdited() {
		$organization = $this->make_organization();
		$member       = $this->make_member( $organization, Member::ROLE_MEMBER );

		$this->act_as_shop_manager();

		$snapshot = $this->send( 'GET', self::ROUTE )->get_data();
		$members  = $this->send( 'GET', $this->members_route( $organization ) )->get_data();
		$one      = $this->send( 'GET', $this->members_route( $organization, $member->get_id() ) )->get_data();

		$this->assertArrayNotHasKey( 'capabilities', $snapshot[0]['members'][0] );
		$this->assertArrayHasKey( 'capabilities', $members[0] );
		$this->assertArrayHasKey( 'capabilities', $one );
		$this->assertTrue( $one['capabilities'][ Roles::PLACE_ORDERS ] );
		$this->assertFalse( $one['capabilities'][ Roles::MANAGE_MEMBERS ] );
	}
}
