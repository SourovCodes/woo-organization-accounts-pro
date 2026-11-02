<?php
/**
 * One-click approval tests.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Tests;

use WooOrgAccounts\Admin\ApprovalLink;
use WooOrgAccounts\Data\Member;
use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Data\OrganizationRepository;

/**
 * The approve link in the shop's new-registration email.
 *
 * It changes state from a GET, so every property that makes that safe is asserted: it
 * needs the key, the key is bound to one organization, it needs a signed-in user who
 * may approve, and it only ever approves a registration that is still waiting.
 */
class ApprovalLinkTest extends TestCase {

	/**
	 * A shop manager following the link approves the registration.
	 */
	public function testAShopManagerApprovesAPendingRegistration() {
		$organization = $this->make_organization( array( 'status' => Organization::STATUS_PENDING ) );
		$this->act_as_shop_manager();

		$result = ( new ApprovalLink() )->approve( $organization->get_id(), ApprovalLink::key( $organization->get_id() ) );

		$this->assertSame( 'approved', $result );
		$this->assertSame( Organization::STATUS_ACTIVE, OrganizationRepository::find( $organization->get_id() )->get_status() );
	}

	/**
	 * Without the right key nothing happens.
	 */
	public function testAWrongKeyApprovesNothing() {
		$organization = $this->make_organization( array( 'status' => Organization::STATUS_PENDING ) );
		$this->act_as_shop_manager();

		$this->assertSame( 'invalid', ( new ApprovalLink() )->approve( $organization->get_id(), 'not-the-key' ) );
		$this->assertSame( Organization::STATUS_PENDING, OrganizationRepository::find( $organization->get_id() )->get_status() );
	}

	/**
	 * The key for one registration does not approve another.
	 */
	public function testTheKeyIsBoundToOneOrganization() {
		$first  = $this->make_organization( array( 'status' => Organization::STATUS_PENDING ) );
		$second = $this->make_organization(
			array(
				'name'   => 'Beta AG',
				'status' => Organization::STATUS_PENDING,
			)
		);
		$this->act_as_shop_manager();

		$this->assertSame( 'invalid', ( new ApprovalLink() )->approve( $second->get_id(), ApprovalLink::key( $first->get_id() ) ) );
		$this->assertSame( Organization::STATUS_PENDING, OrganizationRepository::find( $second->get_id() )->get_status() );
	}

	/**
	 * A customer holding the email's link cannot approve themselves.
	 *
	 * Forwarded mail and shared inboxes happen. The key proves where the link came
	 * from, not who is following it.
	 */
	public function testSomebodyWhoMayNotApproveApprovesNothing() {
		$organization = $this->make_organization( array( 'status' => Organization::STATUS_PENDING ) );
		$this->act_as( $this->make_member( $organization, Member::ROLE_ADMIN ) );

		$this->assertSame( 'denied', ( new ApprovalLink() )->approve( $organization->get_id(), ApprovalLink::key( $organization->get_id() ) ) );
		$this->assertSame( Organization::STATUS_PENDING, OrganizationRepository::find( $organization->get_id() )->get_status() );
	}

	/**
	 * A decision already made is not overturned by an old link.
	 *
	 * The shop may have rejected the registration from the Approvals screen after the
	 * email went out; following the email's link afterwards must not undo that.
	 */
	public function testADecidedRegistrationIsLeftAlone() {
		$organization = $this->make_organization( array( 'status' => Organization::STATUS_REJECTED ) );
		$this->act_as_shop_manager();

		$this->assertSame( 'decided', ( new ApprovalLink() )->approve( $organization->get_id(), ApprovalLink::key( $organization->get_id() ) ) );
		$this->assertSame( Organization::STATUS_REJECTED, OrganizationRepository::find( $organization->get_id() )->get_status() );
	}

	/**
	 * The link points at the Approvals screen and carries the organization and its key.
	 */
	public function testTheUrlLandsOnTheApprovalsScreen() {
		$organization = $this->make_organization( array( 'status' => Organization::STATUS_PENDING ) );

		$url = ApprovalLink::url( $organization );

		$this->assertStringStartsWith( admin_url( 'admin.php' ), $url );
		$this->assertStringContainsString( 'page=woo-organization-accounts-approvals', $url );
		$this->assertStringContainsString( ApprovalLink::KEY_ARG . '=' . ApprovalLink::key( $organization->get_id() ), $url );
	}
}
