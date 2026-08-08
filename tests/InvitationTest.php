<?php
/**
 * Invitation tests.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Tests;

use WooOrgAccounts\Data\Invitation;
use WooOrgAccounts\Data\InvitationRepository;
use WooOrgAccounts\Data\Member;
use WooOrgAccounts\Data\MemberRepository;
use WooOrgAccounts\Members\Invitations;
use WooOrgAccounts\Roles;

/**
 * The four properties the whole member flow rests on: the token is a secret, the
 * invitation is bound to one organization and one address, it works once, and it can
 * be withdrawn or lapse.
 */
class InvitationTest extends TestCase {

	/**
	 * The raw token is never stored — only its digest.
	 */
	public function testTokenIsStoredHashed() {
		global $wpdb;

		$organization = $this->make_organization();
		$result       = Invitations::create( $organization->get_id(), 'bob@acme.test', Member::ROLE_MEMBER );

		$this->assertIsArray( $result );

		$token = $result['token'];
		$table = \WooOrgAccounts\Install::table( \WooOrgAccounts\Install::INVITATIONS );
		$row   = $wpdb->get_row( "SELECT token_hash FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from a class constant.

		$this->assertNotSame( $token, $row['token_hash'] );
		$this->assertSame( hash( 'sha256', $token ), $row['token_hash'] );
		$this->assertSame( 64, strlen( $row['token_hash'] ) );
	}

	/**
	 * The token resolves back to its invitation, and nothing else does.
	 */
	public function testLookupByToken() {
		$organization = $this->make_organization();
		$result       = Invitations::create( $organization->get_id(), 'bob@acme.test', Member::ROLE_MEMBER );

		$found = InvitationRepository::find_by_token( $result['token'] );

		$this->assertInstanceOf( Invitation::class, $found );
		$this->assertSame( 'bob@acme.test', $found->get_email() );
		$this->assertNull( InvitationRepository::find_by_token( 'not-a-token' ) );
		$this->assertNull( InvitationRepository::find_by_token( '' ) );
	}

	/**
	 * An invitation cannot be redeemed twice.
	 */
	public function testInvitationWorksOnlyOnce() {
		$organization = $this->make_organization();
		$result       = Invitations::create( $organization->get_id(), 'bob@acme.test', Member::ROLE_MEMBER );
		$user_id      = self::factory()->user->create( array( 'user_email' => 'bob@acme.test' ) );

		$this->assertTrue( Invitations::accept( $result['invitation'], $user_id ) );

		$reloaded = InvitationRepository::find( $result['invitation']->get_id() );

		$this->assertSame( Invitation::STATUS_ACCEPTED, $reloaded->get( 'status' ) );
		$this->assertFalse( $reloaded->is_acceptable() );

		$second = self::factory()->user->create( array( 'user_email' => 'bob2@acme.test' ) );

		$this->assertWPError( Invitations::accept( $reloaded, $second ) );
	}

	/**
	 * The invitation is bound to the address it was sent to.
	 */
	public function testInvitationIsBoundToItsEmail() {
		$organization = $this->make_organization();
		$result       = Invitations::create( $organization->get_id(), 'bob@acme.test', Member::ROLE_MEMBER );
		$mallory      = self::factory()->user->create( array( 'user_email' => 'mallory@evil.test' ) );

		$error = Invitations::accept( $result['invitation'], $mallory );

		$this->assertWPError( $error );
		$this->assertSame( 'woap_invitation_email_mismatch', $error->get_error_code() );
		$this->assertNull( MemberRepository::find_by_user( $mallory ) );
	}

	/**
	 * The address comparison ignores case, as email addresses do.
	 */
	public function testEmailComparisonIsCaseInsensitive() {
		$organization = $this->make_organization();
		$result       = Invitations::create( $organization->get_id(), 'Bob@Acme.test', Member::ROLE_MEMBER );
		$user_id      = self::factory()->user->create( array( 'user_email' => 'bob@acme.test' ) );

		$this->assertTrue( Invitations::accept( $result['invitation'], $user_id ) );
	}

	/**
	 * An expired invitation is refused, and reports itself as expired.
	 */
	public function testExpiredInvitationIsRefused() {
		$organization = $this->make_organization();
		$result       = Invitations::create( $organization->get_id(), 'bob@acme.test', Member::ROLE_MEMBER );
		$invitation   = $result['invitation'];

		$invitation->set( 'expires_at', gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );
		InvitationRepository::save( $invitation );

		$this->assertTrue( $invitation->is_expired() );
		$this->assertFalse( $invitation->is_acceptable() );
		$this->assertSame( 'Expired', $invitation->get_status_label() );

		$user_id = self::factory()->user->create( array( 'user_email' => 'bob@acme.test' ) );

		$this->assertWPError( Invitations::accept( $invitation, $user_id ) );
	}

	/**
	 * Zero days means the invitation never lapses.
	 */
	public function testZeroExpiryMeansNoExpiry() {
		$this->set_setting( 'invitation_expiry_days', 0 );

		$organization = $this->make_organization();
		$result       = Invitations::create( $organization->get_id(), 'bob@acme.test', Member::ROLE_MEMBER );

		$this->assertNull( $result['invitation']->get( 'expires_at' ) );
		$this->assertFalse( $result['invitation']->is_expired() );
	}

	/**
	 * A withdrawn invitation stops working but stays on the record.
	 */
	public function testRevokedInvitationIsRefused() {
		$organization = $this->make_organization();
		$result       = Invitations::create( $organization->get_id(), 'bob@acme.test', Member::ROLE_MEMBER );

		$this->assertTrue( Invitations::revoke( $result['invitation'] ) );
		$this->assertFalse( $result['invitation']->is_acceptable() );
		$this->assertFalse( Invitations::revoke( $result['invitation'] ), 'Revoking twice should do nothing.' );

		$user_id = self::factory()->user->create( array( 'user_email' => 'bob@acme.test' ) );

		$this->assertWPError( Invitations::accept( $result['invitation'], $user_id ) );
		$this->assertCount( 1, InvitationRepository::for_organization( $organization->get_id() ) );
	}

	/**
	 * Inviting the same address again replaces the token rather than adding a row.
	 */
	public function testReinvitingReplacesTheToken() {
		$organization = $this->make_organization();
		$first        = Invitations::create( $organization->get_id(), 'bob@acme.test', Member::ROLE_MEMBER );
		$second       = Invitations::create( $organization->get_id(), 'bob@acme.test', Member::ROLE_MEMBER );

		$this->assertSame( $first['invitation']->get_id(), $second['invitation']->get_id() );
		$this->assertNotSame( $first['token'], $second['token'] );
		$this->assertNull( InvitationRepository::find_by_token( $first['token'] ), 'The old token must stop working.' );
		$this->assertNotNull( InvitationRepository::find_by_token( $second['token'] ) );
		$this->assertCount( 1, InvitationRepository::for_organization( $organization->get_id() ) );
	}

	/**
	 * Somebody who already belongs to an organization cannot be invited to another.
	 */
	public function testExistingMemberCannotBeInvited() {
		$ours   = $this->make_organization();
		$theirs = $this->make_organization( array( 'name' => 'Rival Ltd' ) );
		$member = $this->make_member( $theirs );
		$user   = get_user_by( 'id', $member->get_user_id() );

		$error = Invitations::create( $ours->get_id(), $user->user_email, Member::ROLE_MEMBER );

		$this->assertWPError( $error );
		$this->assertSame( 'woap_already_member', $error->get_error_code() );
	}

	/**
	 * An invalid address is refused before anything is written.
	 */
	public function testInvalidEmailIsRefused() {
		$organization = $this->make_organization();

		$this->assertWPError( Invitations::create( $organization->get_id(), 'not-an-email', Member::ROLE_MEMBER ) );
		$this->assertCount( 0, InvitationRepository::for_organization( $organization->get_id() ) );
	}

	/**
	 * Redeeming gives the user their membership and the matching WordPress role.
	 */
	public function testAcceptingCreatesMembershipAndRole() {
		$organization = $this->make_organization();
		$result       = Invitations::create( $organization->get_id(), 'bob@acme.test', Member::ROLE_ADMIN );
		$user_id      = self::factory()->user->create( array( 'user_email' => 'bob@acme.test' ) );

		$this->assertTrue( Invitations::accept( $result['invitation'], $user_id ) );

		$member = MemberRepository::find_by_user( $user_id );

		$this->assertInstanceOf( Member::class, $member );
		$this->assertSame( $organization->get_id(), $member->get_organization_id() );
		$this->assertTrue( $member->is_admin() );
		$this->assertContains( Roles::ROLE_ORG_ADMIN, get_user_by( 'id', $user_id )->roles );
	}

	/**
	 * Every refusal reads the same, so the link cannot be used to ask questions.
	 */
	public function testRefusalsAreIndistinguishable() {
		$organization = $this->make_organization();
		$result       = Invitations::create( $organization->get_id(), 'bob@acme.test', Member::ROLE_MEMBER );
		$mallory      = self::factory()->user->create( array( 'user_email' => 'mallory@evil.test' ) );

		$mismatch = Invitations::accept( $result['invitation'], $mallory );

		Invitations::revoke( $result['invitation'] );
		$bob     = self::factory()->user->create( array( 'user_email' => 'bob@acme.test' ) );
		$revoked = Invitations::accept( $result['invitation'], $bob );

		$this->assertSame( Invitations::rejection_message(), $mismatch->get_error_message() );
		$this->assertSame( Invitations::rejection_message(), $revoked->get_error_message() );
	}

	/**
	 * Only this organization's invitations resolve when scoped to it.
	 */
	public function testInvitationsAreScopedToTheirOrganization() {
		$ours   = $this->make_organization();
		$theirs = $this->make_organization( array( 'name' => 'Rival Ltd' ) );
		$result = Invitations::create( $theirs->get_id(), 'bob@acme.test', Member::ROLE_MEMBER );

		$this->assertNull( InvitationRepository::find_for_organization( $result['invitation']->get_id(), $ours->get_id() ) );
		$this->assertNotNull( InvitationRepository::find_for_organization( $result['invitation']->get_id(), $theirs->get_id() ) );
		$this->assertCount( 0, InvitationRepository::for_organization( $ours->get_id() ) );
	}

	/**
	 * The link points at the registration page and carries the token.
	 */
	public function testAcceptUrl() {
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->set_setting( 'registration_page_id', $page_id );

		$url = Invitations::accept_url( 'abc123' );

		$this->assertStringContainsString( 'woap_invite=abc123', $url );
		$this->assertStringContainsString( (string) wp_parse_url( get_permalink( $page_id ), PHP_URL_PATH ), $url );
	}
}
