<?php
/**
 * Capability and isolation tests.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Tests;

use WooOrgAccounts\Data\Member;
use WooOrgAccounts\Data\MemberRepository;
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
