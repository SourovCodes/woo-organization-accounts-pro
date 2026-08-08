<?php
/**
 * Membership and location tests.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Tests;

use WooOrgAccounts\Data\Location;
use WooOrgAccounts\Data\LocationRepository;
use WooOrgAccounts\Data\Member;
use WooOrgAccounts\Data\MemberRepository;
use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Membership\Context;

/**
 * Memberships, locations and the context that resolves them.
 */
class MembershipTest extends TestCase {

	/**
	 * A user's membership is found by their ID.
	 */
	public function testFindByUser() {
		$organization = $this->make_organization();
		$member       = $this->make_member( $organization );

		$found = MemberRepository::find_by_user( $member->get_user_id() );

		$this->assertInstanceOf( Member::class, $found );
		$this->assertSame( $organization->get_id(), $found->get_organization_id() );
	}

	/**
	 * A user with no membership resolves to null, not to an empty membership.
	 */
	public function testUserWithoutMembership() {
		$user_id = self::factory()->user->create();

		$this->assertNull( MemberRepository::find_by_user( $user_id ) );
		$this->assertNull( MemberRepository::find_by_user( 0 ) );
	}

	/**
	 * The memoised lookup is dropped when a membership is written.
	 */
	public function testMemoIsInvalidatedOnSave() {
		$organization = $this->make_organization();
		$member       = $this->make_member( $organization );

		MemberRepository::find_by_user( $member->get_user_id() );

		$member->set( 'role', Member::ROLE_ADMIN );
		MemberRepository::save( $member );

		$this->assertTrue( MemberRepository::find_by_user( $member->get_user_id() )->is_admin() );
	}

	/**
	 * A member of another organization does not resolve when scoped to this one.
	 */
	public function testFindForOrganizationRefusesForeignMembers() {
		$ours   = $this->make_organization();
		$theirs = $this->make_organization( array( 'name' => 'Rival Ltd' ) );
		$member = $this->make_member( $theirs );

		$this->assertNull( MemberRepository::find_for_organization( $member->get_id(), $ours->get_id() ) );
		$this->assertNotNull( MemberRepository::find_for_organization( $member->get_id(), $theirs->get_id() ) );
	}

	/**
	 * The member list is scoped, and can be narrowed by role and status.
	 */
	public function testForOrganizationScopesAndFilters() {
		$ours   = $this->make_organization();
		$theirs = $this->make_organization( array( 'name' => 'Rival Ltd' ) );

		$this->make_member( $ours, Member::ROLE_ADMIN );
		$this->make_member( $ours );
		$this->make_member( $ours, Member::ROLE_MEMBER, array( 'status' => Member::STATUS_INACTIVE ) );
		$this->make_member( $theirs, Member::ROLE_ADMIN );

		$this->assertCount( 3, MemberRepository::for_organization( $ours->get_id() ) );
		$this->assertCount( 1, MemberRepository::for_organization( $ours->get_id(), array( 'role' => Member::ROLE_ADMIN ) ) );
		$this->assertCount( 2, MemberRepository::for_organization( $ours->get_id(), array( 'status' => Member::STATUS_ACTIVE ) ) );
		$this->assertSame( 3, MemberRepository::count_for_organization( $ours->get_id() ) );
		$this->assertSame( 1, MemberRepository::count_for_organization( $theirs->get_id() ) );
	}

	/**
	 * The last active admin is recognised as the last one.
	 */
	public function testHasOtherAdmin() {
		$organization = $this->make_organization();
		$first        = $this->make_member( $organization, Member::ROLE_ADMIN );

		$this->assertFalse( MemberRepository::has_other_admin( $organization->get_id(), $first->get_id() ) );

		$second = $this->make_member( $organization, Member::ROLE_ADMIN );

		$this->assertTrue( MemberRepository::has_other_admin( $organization->get_id(), $first->get_id() ) );

		$second->set( 'status', Member::STATUS_INACTIVE );
		MemberRepository::save( $second );

		$this->assertFalse( MemberRepository::has_other_admin( $organization->get_id(), $first->get_id() ) );
	}

	/**
	 * Location access is stored, read back and cleared with the member.
	 */
	public function testLocationAccess() {
		$organization = $this->make_organization();
		$member       = $this->make_member( $organization );
		$north        = $this->make_location( $organization );
		$south        = $this->make_location( $organization, array( 'name' => 'Warehouse South' ) );

		$this->assertSame( array(), MemberRepository::location_ids( $member->get_id() ) );

		MemberRepository::set_location_ids( $member->get_id(), array( $north->get_id() ) );

		$this->assertSame( array( $north->get_id() ), MemberRepository::location_ids( $member->get_id() ) );

		MemberRepository::delete( $member->get_id() );

		$this->assertSame( array(), MemberRepository::location_ids( $member->get_id() ) );
		$this->assertNotNull( LocationRepository::find( $south->get_id() ), 'Removing a member must not delete locations.' );
	}

	/**
	 * A member with no access list may use every location; one with a list is held to it.
	 */
	public function testLocationsForMember() {
		$organization = $this->make_organization();
		$member       = $this->make_member( $organization );
		$north        = $this->make_location( $organization );
		$this->make_location( $organization, array( 'name' => 'Warehouse South' ) );

		$this->assertCount( 2, LocationRepository::for_member( $member ) );

		MemberRepository::set_location_ids( $member->get_id(), array( $north->get_id() ) );

		$restricted = LocationRepository::for_member( $member );

		$this->assertCount( 1, $restricted );
		$this->assertSame( $north->get_id(), $restricted[0]->get_id() );
	}

	/**
	 * Deleting a location takes its access rows with it.
	 */
	public function testDeletingLocationClearsAccess() {
		$organization = $this->make_organization();
		$member       = $this->make_member( $organization );
		$location     = $this->make_location( $organization );

		MemberRepository::set_location_ids( $member->get_id(), array( $location->get_id() ) );
		LocationRepository::delete( $location->get_id() );

		$this->assertSame( array(), MemberRepository::location_ids( $member->get_id() ) );
	}

	/**
	 * Only one location per organization can be the default.
	 */
	public function testOnlyOneDefaultLocation() {
		$organization = $this->make_organization();
		$first        = $this->make_location( $organization, array( 'is_default' => true ) );
		$second       = $this->make_location(
			$organization,
			array(
				'name'       => 'Warehouse South',
				'is_default' => true,
			)
		);

		$this->assertFalse( LocationRepository::find( $first->get_id() )->is_default() );
		$this->assertTrue( LocationRepository::find( $second->get_id() )->is_default() );
	}

	/**
	 * The default location sorts first.
	 */
	public function testDefaultLocationSortsFirst() {
		$organization = $this->make_organization();
		$this->make_location( $organization, array( 'name' => 'Aardvark Depot' ) );
		$default = $this->make_location(
			$organization,
			array(
				'name'       => 'Zulu Depot',
				'is_default' => true,
			)
		);

		$locations = LocationRepository::for_organization( $organization->get_id() );

		$this->assertSame( $default->get_id(), $locations[0]->get_id() );
	}

	/**
	 * A location is a WooCommerce shipping address, copied rather than derived.
	 */
	public function testShippingAddressShape() {
		$organization = $this->make_organization();
		$location     = $this->make_location( $organization );

		$address = $location->get_shipping_address();

		$this->assertSame( 'Grace', $address['first_name'] );
		$this->assertSame( 'Hopper', $address['last_name'] );
		$this->assertSame( 'Warehouse North', $address['company'] );
		$this->assertSame( 'Hamburg', $address['city'] );
		$this->assertSame( 'DE', $address['country'] );
		$this->assertSame( '+49 40 123456', $address['phone'] );

		// Every WooCommerce shipping field is present, so an order can take it whole.
		$this->assertSame( Location::ADDRESS_FIELDS, array_keys( $address ) );
	}

	/**
	 * The first and last name are separate fields, so neither can eat the other.
	 *
	 * The earlier schema stored one "contact name" and split it on whitespace at
	 * checkout. A contact called "Grace" reached the courier with no surname, and one
	 * called "Mary Jane Watson" reached them as "Mary" plus "Jane Watson".
	 */
	public function testContactNamesAreIndependentFields() {
		$organization = $this->make_organization();

		$one_word = $this->make_location(
			$organization,
			array(
				'first_name' => 'Grace',
				'last_name'  => '',
			)
		);

		$this->assertSame( 'Grace', $one_word->get_shipping_address()['first_name'] );
		$this->assertSame( '', $one_word->get_shipping_address()['last_name'] );
		$this->assertSame( 'Grace', $one_word->get_contact_name() );

		$three_words = $this->make_location(
			$organization,
			array(
				'name'       => 'Warehouse South',
				'first_name' => 'Mary Jane',
				'last_name'  => 'Watson',
			)
		);

		$this->assertSame( 'Mary Jane', $three_words->get_shipping_address()['first_name'] );
		$this->assertSame( 'Watson', $three_words->get_shipping_address()['last_name'] );
		$this->assertSame( 'Mary Jane Watson', $three_words->get_contact_name() );
	}

	/**
	 * The context resolves the organization behind the signed-in user.
	 */
	public function testContextResolvesOrganization() {
		$organization = $this->make_organization();
		$member       = $this->make_member( $organization, Member::ROLE_ADMIN );

		$this->act_as( $member );

		$this->assertSame( $organization->get_id(), Context::organization_id() );
		$this->assertInstanceOf( Organization::class, Context::organization() );
		$this->assertTrue( Context::is_organization_admin() );
	}

	/**
	 * An inactive member does not administer anything.
	 */
	public function testInactiveAdminIsNotAnAdmin() {
		$organization = $this->make_organization();
		$member       = $this->make_member( $organization, Member::ROLE_ADMIN, array( 'status' => Member::STATUS_INACTIVE ) );

		$this->act_as( $member );

		$this->assertFalse( Context::is_organization_admin() );
	}
}
