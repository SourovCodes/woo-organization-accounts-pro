<?php
/**
 * Managing people on an account from wp-admin.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Tests;

use WooOrgAccounts\Admin\Members;
use WooOrgAccounts\Admin\Notices;
use WooOrgAccounts\Data\Invitation;
use WooOrgAccounts\Data\InvitationRepository;
use WooOrgAccounts\Data\Member;
use WooOrgAccounts\Data\MemberRepository;
use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Roles;

/**
 * The screens that put somebody on an account and change what they may do there.
 *
 * Everything here was reachable over REST and unreachable from the shop's own dashboard,
 * which is the gap these screens close. The tests that matter most are the ones asserting
 * that this third caller behaves like the other two — the capability diff in particular,
 * which is where the same bug has now been possible three times.
 */
class AdminMembersTest extends TestCase {

	/**
	 * Act as somebody who runs the shop.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->act_as_shop_manager();

		Notices::flush();
	}

	/**
	 * Post to one of the member handlers and report where it redirected.
	 *
	 * @param string $method Handler method name.
	 * @param array  $fields Everything the form submits.
	 * @param array  $query  Anything the URL carries.
	 * @return string Redirect target.
	 */
	private function handle( $method, array $fields = array(), array $query = array() ) {
		$_POST    = $fields;
		$_GET     = $query;
		$_REQUEST = array_merge( $query, $fields );

		$throw = static function ( $location ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Carried to an assertion in a test, never rendered.
			throw new RedirectException( $location );
		};

		add_filter( 'wp_redirect', $throw );

		try {
			( new Members() )->{$method}();
		} catch ( RedirectException $redirect ) {
			return $redirect->location;
		} finally {
			remove_filter( 'wp_redirect', $throw );
		}

		$this->fail( 'The handler did not redirect.' );
	}

	/**
	 * Whatever a refused submission parked.
	 *
	 * @return \WP_Error The reasons.
	 */
	private function parked_errors() {
		$parked = get_transient( Notices::TRANSIENT . get_current_user_id() );

		$this->assertIsArray( $parked, 'The submission was accepted; it was expected to be refused.' );
		$this->assertNotEmpty( $parked['errors'], 'The submission was accepted; it was expected to be refused.' );

		$errors = new \WP_Error();

		foreach ( $parked['errors'] as $code => $messages ) {
			foreach ( (array) $messages as $message ) {
				$errors->add( $code, $message );
			}
		}

		return $errors;
	}

	/**
	 * The fields the member form submits, with the defaults filled in.
	 *
	 * @param Member $member    Who is being edited.
	 * @param array  $overrides What this submission changes.
	 * @return array The submission.
	 */
	private function form( Member $member, array $overrides = array() ) {
		$user = get_userdata( $member->get_user_id() );

		return array_merge(
			array(
				'woap_member_id'         => $member->get_id(),
				'woap_first_name'        => (string) $user->first_name,
				'woap_last_name'         => (string) $user->last_name,
				'woap_email'             => (string) $user->user_email,
				'woap_role'              => $member->get_role(),
				'woap_status'            => (string) $member->get( 'status' ),
				'woap_permissions_scope' => 'role',
				'woap_location_scope'    => 'all',
				'_wpnonce'               => wp_create_nonce( 'woap_admin_member_save_' . $member->get_id() ),
			),
			$overrides
		);
	}

	/**
	 * Promoting somebody to admin gives them an admin's permissions.
	 *
	 * The bug this is a port of produced an organization admin who could manage nothing:
	 * the form derived permission overrides from checkboxes drawn for the role the member
	 * held *before* the submission, so promoting an employee stored "everything off" as six
	 * overrides against the admin defaults. It has now been possible in three places, which
	 * is why the diff lives in one and this is asserted of every caller.
	 *
	 * @return void
	 */
	public function testPromotingToAdminGrantsTheAdminDefaults() {
		$organization = $this->make_organization();
		$member       = $this->make_member( $organization, Member::ROLE_MEMBER );

		$this->handle(
			'handle_save',
			$this->form( $member, array( 'woap_role' => Member::ROLE_ADMIN ) )
		);

		$saved = MemberRepository::find( $member->get_id() );

		$this->assertSame( Member::ROLE_ADMIN, $saved->get_role() );
		$this->assertSame(
			array(),
			$saved->get_capabilities(),
			'Following the role must store no overrides, or the member is pinned to the role they used to hold.'
		);
		$this->assertTrue(
			user_can( $saved->get_user_id(), Roles::MANAGE_MEMBERS ),
			'An organization admin must hold the admin defaults.'
		);
	}

	/**
	 * Only the permissions that differ from the role are stored.
	 *
	 * @return void
	 */
	public function testChoosingPermissionsStoresOnlyTheDifferences() {
		$organization = $this->make_organization();
		$member       = $this->make_member( $organization, Member::ROLE_MEMBER );

		$this->handle(
			'handle_save',
			$this->form(
				$member,
				array(
					'woap_permissions_scope' => 'custom',
					'woap_capabilities'      => array( Roles::PLACE_ORDERS, Roles::INVITE_MEMBERS ),
				)
			)
		);

		$saved = MemberRepository::find( $member->get_id() );

		/*
		 * A plain member already places orders, so ticking it changes nothing and is not an
		 * override. Inviting is the only difference from the role.
		 */
		$this->assertSame( array( Roles::INVITE_MEMBERS => true ), $saved->get_capabilities() );
	}

	/**
	 * The last active admin cannot be demoted.
	 *
	 * @return void
	 */
	public function testTheLastAdminCannotBeDemoted() {
		$organization = $this->make_organization();
		$member       = $this->make_member( $organization, Member::ROLE_ADMIN );

		$this->handle(
			'handle_save',
			$this->form( $member, array( 'woap_role' => Member::ROLE_MEMBER ) )
		);

		$this->assertNotEmpty( $this->parked_errors()->get_error_message( 'woap_role' ) );
		$this->assertSame(
			Member::ROLE_ADMIN,
			MemberRepository::find( $member->get_id() )->get_role(),
			'A refused submission must change nothing.'
		);
	}

	/**
	 * Restricting delivery access to nothing is a question, not a silent "all of them".
	 *
	 * The stored form of "every location" is an empty list, so the two answers are
	 * indistinguishable once stored. Ticking nothing under "only these" has to be refused.
	 *
	 * @return void
	 */
	public function testRestrictingToNoLocationsIsRefused() {
		$organization = $this->make_organization();
		$member       = $this->make_member( $organization );

		$this->make_location( $organization );

		$this->handle(
			'handle_save',
			$this->form( $member, array( 'woap_location_scope' => 'selected' ) )
		);

		$this->assertNotEmpty( $this->parked_errors()->get_error_message( 'woap_location_access' ) );
	}

	/**
	 * Delivery access is written when locations are chosen.
	 *
	 * @return void
	 */
	public function testChosenLocationsAreStored() {
		$organization = $this->make_organization();
		$member       = $this->make_member( $organization );
		$location     = $this->make_location( $organization );

		$this->make_location( $organization, array( 'name' => 'Warehouse North' ) );

		$this->handle(
			'handle_save',
			$this->form(
				$member,
				array(
					'woap_location_scope'  => 'selected',
					'woap_location_access' => array( $location->get_id() ),
				)
			)
		);

		$this->assertSame(
			array( $location->get_id() ),
			MemberRepository::location_ids( $member->get_id() )
		);
	}

	/**
	 * A name and an address are edited here, and land on the WordPress account.
	 *
	 * Neither is stored on the membership row, so a screen that wrote only to the row would
	 * be a field with no destination.
	 *
	 * @return void
	 */
	public function testEditingTheNameReachesTheWordPressAccount() {
		$organization = $this->make_organization();
		$member       = $this->make_member( $organization );

		$this->handle(
			'handle_save',
			$this->form(
				$member,
				array(
					'woap_first_name' => 'Gudrun',
					'woap_last_name'  => 'Steiner',
					'woap_email'      => 'gudrun@acme.test',
				)
			)
		);

		$user = get_userdata( $member->get_user_id() );

		$this->assertSame( 'Gudrun', $user->first_name );
		$this->assertSame( 'gudrun@acme.test', $user->user_email );
		$this->assertSame(
			'Gudrun Steiner',
			$user->display_name,
			'Every screen here prints display_name, so a rename that left it alone would be invisible.'
		);
	}

	/**
	 * Moving somebody onto an address that belongs to another account is refused.
	 *
	 * @return void
	 */
	public function testAnAddressBelongingToAnotherAccountIsRefused() {
		$mine   = $this->make_member( $this->make_organization( array( 'name' => 'Acme' ) ) );
		$theirs = $this->make_member( $this->make_organization( array( 'name' => 'Baumann KG' ) ) );

		$taken = get_userdata( $theirs->get_user_id() )->user_email;

		$this->handle( 'handle_save', $this->form( $mine, array( 'woap_email' => $taken ) ) );

		$this->assertNotEmpty(
			$this->parked_errors()->get_error_message( 'woap_email' ),
			'The refusal must be reported against the address field it is about, not only as a banner.'
		);
	}

	/**
	 * Inviting somebody issues an invitation rather than an account.
	 *
	 * @return void
	 */
	public function testInvitingSomebodyIssuesAnInvitation() {
		$organization = $this->make_organization();

		$this->handle(
			'handle_add',
			array(
				'woap_organization_id' => $organization->get_id(),
				'woap_email'           => 'new@acme.test',
				'woap_role'            => Member::ROLE_MEMBER,
				'woap_method'          => 'invite',
				'_wpnonce'             => wp_create_nonce( 'woap_admin_member_add_' . $organization->get_id() ),
			)
		);

		$invitations = InvitationRepository::for_organization( $organization->get_id() );

		$this->assertCount( 1, $invitations );
		$this->assertSame( 'new@acme.test', $invitations[0]->get_email() );
		$this->assertSame( Invitation::STATUS_PENDING, (string) $invitations[0]->get( 'status' ) );
		$this->assertNull(
			MemberRepository::find_by_user( 0 ),
			'An invitation creates no membership until it is accepted.'
		);
	}

	/**
	 * Creating an account makes the membership immediately.
	 *
	 * @return void
	 */
	public function testCreatingAnAccountMakesTheMembership() {
		$organization = $this->make_organization();

		$this->handle(
			'handle_add',
			array(
				'woap_organization_id' => $organization->get_id(),
				'woap_email'           => 'new@acme.test',
				'woap_first_name'      => 'Gudrun',
				'woap_role'            => Member::ROLE_MEMBER,
				'woap_method'          => 'create',
				'_wpnonce'             => wp_create_nonce( 'woap_admin_member_add_' . $organization->get_id() ),
			)
		);

		$user = get_user_by( 'email', 'new@acme.test' );

		$this->assertInstanceOf( \WP_User::class, $user );

		$member = MemberRepository::find_by_user( $user->ID );

		$this->assertInstanceOf( Member::class, $member );
		$this->assertSame( $organization->get_id(), $member->get_organization_id() );
		$this->assertEmpty(
			InvitationRepository::for_organization( $organization->get_id() ),
			'Creating an account outright sends no invitation.'
		);
	}

	/**
	 * Adding an address that already belongs to an organization is refused.
	 *
	 * @return void
	 */
	public function testAddingSomebodyElsesMemberIsRefused() {
		$organization = $this->make_organization( array( 'name' => 'Acme' ) );
		$theirs       = $this->make_member( $this->make_organization( array( 'name' => 'Baumann KG' ) ) );

		$taken = get_userdata( $theirs->get_user_id() )->user_email;

		$this->handle(
			'handle_add',
			array(
				'woap_organization_id' => $organization->get_id(),
				'woap_email'           => $taken,
				'woap_method'          => 'create',
				'_wpnonce'             => wp_create_nonce( 'woap_admin_member_add_' . $organization->get_id() ),
			)
		);

		$this->assertNotEmpty(
			$this->parked_errors()->get_error_message( 'woap_email' ),
			'The refusal must be reported against the address field it is about.'
		);
	}

	/**
	 * Removing somebody keeps their login and moves them to the customer role.
	 *
	 * @return void
	 */
	public function testRemovingSomebodyKeepsTheirLogin() {
		$organization = $this->make_organization();

		$this->make_member( $organization, Member::ROLE_ADMIN );

		$member  = $this->make_member( $organization );
		$user_id = $member->get_user_id();

		$this->handle(
			'handle_remove',
			array(),
			array(
				'member_id' => $member->get_id(),
				'_wpnonce'  => wp_create_nonce( 'woap_admin_member_remove_' . $member->get_id() ),
			)
		);

		$this->assertNull( MemberRepository::find( $member->get_id() ) );
		$this->assertInstanceOf( \WP_User::class, get_userdata( $user_id ) );
		$this->assertContains( 'customer', get_userdata( $user_id )->roles );
	}

	/**
	 * The last active admin cannot be removed either.
	 *
	 * @return void
	 */
	public function testTheLastAdminCannotBeRemoved() {
		$organization = $this->make_organization();
		$member       = $this->make_member( $organization, Member::ROLE_ADMIN );

		$this->handle(
			'handle_remove',
			array(),
			array(
				'member_id' => $member->get_id(),
				'_wpnonce'  => wp_create_nonce( 'woap_admin_member_remove_' . $member->get_id() ),
			)
		);

		$this->assertInstanceOf( Member::class, MemberRepository::find( $member->get_id() ) );
	}

	/**
	 * Somebody who runs the shop is never demoted out of wp-admin.
	 *
	 * `set_role()` replaces every role a user holds, so an administrator who joined an
	 * organization to see what a customer sees would be locked out of wp-admin by a routine
	 * membership edit. The account screens did exactly this until the service was extracted.
	 *
	 * @return void
	 */
	public function testShopStaffKeepTheirWordPressRole() {
		$organization = $this->make_organization();
		$member       = $this->make_member( $organization, Member::ROLE_MEMBER );

		$user = get_userdata( $member->get_user_id() );
		$user->set_role( 'administrator' );

		$this->handle(
			'handle_save',
			$this->form( $member, array( 'woap_role' => Member::ROLE_ADMIN ) )
		);

		$this->assertContains(
			'administrator',
			get_userdata( $member->get_user_id() )->roles,
			'Editing a membership must never demote somebody who runs the shop.'
		);
	}

	/**
	 * A membership whose WordPress account is gone can still be edited.
	 *
	 * Nothing hooks `deleted_user`, so this is an ordinary state rather than an anomaly —
	 * and it is the one this screen most needs to handle, because tidying the row up is why
	 * somebody opened it. Sending the identity fields unconditionally made every such save
	 * run `update_identity()` against an account that is not there and be refused for it,
	 * discarding a role or status change that never touched the account.
	 *
	 * @return void
	 */
	public function testAMembershipWithNoAccountCanStillBeEdited() {
		$organization = $this->make_organization();

		$this->make_member( $organization, Member::ROLE_ADMIN );

		$member  = $this->make_member( $organization, Member::ROLE_MEMBER );
		$user_id = $member->get_user_id();

		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $user_id );

		$this->handle(
			'handle_save',
			array(
				'woap_member_id'         => $member->get_id(),
				'woap_role'              => Member::ROLE_MEMBER,
				'woap_status'            => Member::STATUS_INACTIVE,
				'woap_permissions_scope' => 'role',
				'woap_location_scope'    => 'all',
				'_wpnonce'               => wp_create_nonce( 'woap_admin_member_save_' . $member->get_id() ),
			)
		);

		$this->assertSame(
			Member::STATUS_INACTIVE,
			(string) MemberRepository::find( $member->get_id() )->get( 'status' ),
			'A status change needs no user account, so losing the account must not refuse it.'
		);
	}

	/**
	 * An orphaned membership is listed, so somebody can find it and remove it.
	 *
	 * The list sorts by name by default, which took the users-table join — and an INNER one
	 * dropped exactly the rows the screen renders "(deleted account)" for. They were
	 * invisible everywhere they could have been cleaned up from.
	 *
	 * @return void
	 */
	public function testAnOrphanedMembershipIsStillListed() {
		$organization = $this->make_organization();

		$this->make_member( $organization, Member::ROLE_ADMIN );

		$member = $this->make_member( $organization, Member::ROLE_MEMBER );

		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $member->get_user_id() );

		$listed = array_map(
			static function ( Member $found ) {
				return $found->get_id();
			},
			MemberRepository::query( array( 'orderby' => 'name' ) )
		);

		$this->assertContains(
			$member->get_id(),
			$listed,
			'A membership whose account was deleted must still appear in an unsearched list.'
		);
	}

	/**
	 * A search by name still leaves the orphan out, which is the part that was right.
	 *
	 * @return void
	 */
	public function testAnOrphanedMembershipIsNotFoundByName() {
		$organization = $this->make_organization();
		$member       = $this->make_member( $organization, Member::ROLE_MEMBER );

		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $member->get_user_id() );

		$this->assertSame(
			array(),
			MemberRepository::query( array( 'search' => 'anything' ) ),
			'There is no name to match, so a search must not return it.'
		);
	}

	/**
	 * Nobody without the capability may write.
	 *
	 * @return void
	 */
	public function testAMemberCannotEditTheirOwnMembershipHere() {
		$organization = $this->make_organization();
		$member       = $this->make_member( $organization, Member::ROLE_ADMIN );

		$this->act_as( $member );

		$this->expectException( \WPDieException::class );

		$this->handle(
			'handle_save',
			$this->form( $member, array( 'woap_status' => Member::STATUS_INACTIVE ) )
		);
	}
}
