<?php
/**
 * Member invitations.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Members;

use WooOrgAccounts\Admin\Settings;
use WooOrgAccounts\Data\Invitation;
use WooOrgAccounts\Data\InvitationRepository;
use WooOrgAccounts\Data\Member;
use WooOrgAccounts\Data\MemberRepository;
use WooOrgAccounts\Labels;
use WooOrgAccounts\Roles;

defined( 'ABSPATH' ) || exit;

/**
 * Issues, revokes and redeems invitations to join an organization.
 *
 * The security of the whole member flow is here, and it rests on four properties that
 * have to hold together:
 *
 * - **The token is a secret, and only the recipient has it.** It is generated once,
 *   put in one email, and stored only as a SHA-256 digest. Reading the database gives
 *   you no way in.
 * - **The invitation is bound to one organization and one email address.** Holding a
 *   token is not enough; the account redeeming it has to be the address it was sent
 *   to, so a forwarded email does not hand over a seat.
 * - **It works exactly once.** Redeeming marks the row accepted, and an accepted row
 *   is not acceptable again.
 * - **It can be withdrawn, and it can lapse.** Revoking is immediate, and an expiry
 *   is checked against the clock at redemption rather than trusted from the link.
 */
final class Invitations {

	/**
	 * Query variable an invitation link arrives on.
	 */
	const QUERY_VAR = 'woap_invite';

	/**
	 * Length of the raw token, in characters.
	 *
	 * Drawn from wp_generate_password()'s alphabet, so 32 characters is comfortably
	 * more entropy than a session cookie.
	 */
	const TOKEN_LENGTH = 32;

	/**
	 * Issue an invitation, or refresh the one that is already outstanding.
	 *
	 * Inviting the same address twice refreshes the existing row rather than adding a
	 * second one, so "revoke the invitation" always means the one that works.
	 *
	 * @param int    $organization_id Organization to invite into.
	 * @param string $email           Address to invite.
	 * @param string $role            One of the Member::ROLE_* constants.
	 * @param int    $invited_by      User issuing the invitation.
	 * @return array|\WP_Error {
	 *     The invitation and its one-time token, or an error.
	 *
	 *     @type Invitation $invitation The stored invitation.
	 *     @type string     $token      The raw token. This is the only time it exists.
	 * }
	 */
	public static function create( $organization_id, $email, $role, $invited_by = 0 ) {
		$organization_id = absint( $organization_id );
		$email           = sanitize_email( $email );
		$role            = ( Member::ROLE_ADMIN === $role ) ? Member::ROLE_ADMIN : Member::ROLE_MEMBER;

		if ( 0 === $organization_id ) {
			return new \WP_Error( 'woap_no_organization', __( 'No account was given to invite into.', 'woo-organization-accounts-pro' ) );
		}

		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'woap_invalid_email', __( 'That is not a valid email address.', 'woo-organization-accounts-pro' ) );
		}

		$existing_user = get_user_by( 'email', $email );

		if ( $existing_user instanceof \WP_User ) {
			$membership = MemberRepository::find_by_user( $existing_user->ID );

			if ( null !== $membership ) {
				return new \WP_Error(
					'woap_already_member',
					$membership->get_organization_id() === $organization_id
						? sprintf(
							/* translators: %s: the organization noun for the site's mode, for example "Company". */
							__( 'That address already belongs to this %s.', 'woo-organization-accounts-pro' ),
							Labels::organization()
						)
						: sprintf(
							/* translators: %s: the organization noun for the site's mode, for example "Company". */
							__( 'That address already belongs to another %s.', 'woo-organization-accounts-pro' ),
							Labels::organization()
						)
				);
			}
		}

		$token      = wp_generate_password( self::TOKEN_LENGTH, false );
		$invitation = InvitationRepository::find_pending_for_email( $organization_id, $email );

		if ( null === $invitation ) {
			$invitation = new Invitation();
			$invitation->set( 'organization_id', $organization_id );
			$invitation->set( 'email', $email );
		}

		$invitation->set( 'role', $role );
		$invitation->set( 'token_hash', InvitationRepository::hash_token( $token ) );
		$invitation->set( 'status', Invitation::STATUS_PENDING );
		$invitation->set( 'expires_at', self::expiry() );
		$invitation->set( 'invited_by', absint( $invited_by ) );

		if ( 0 === InvitationRepository::save( $invitation ) ) {
			return new \WP_Error( 'woap_invitation_failed', __( 'The invitation could not be saved. Please try again.', 'woo-organization-accounts-pro' ) );
		}

		/**
		 * Fires once an invitation has been issued and its token generated.
		 *
		 * The token is passed because this is the only moment it exists in plaintext;
		 * nothing can recover it afterwards. The invitation email is sent from here.
		 *
		 * @since 0.1.0
		 *
		 * @param Invitation $invitation The stored invitation.
		 * @param string     $token      The raw one-time token.
		 */
		do_action( 'woo_org_accounts_invitation_created', $invitation, $token );

		return array(
			'invitation' => $invitation,
			'token'      => $token,
		);
	}

	/**
	 * Withdraw an outstanding invitation.
	 *
	 * The row is kept rather than deleted so the organization keeps a record of who
	 * was invited and what became of it.
	 *
	 * @param Invitation $invitation Invitation to withdraw.
	 * @return bool True when it was withdrawn.
	 */
	public static function revoke( Invitation $invitation ) {
		if ( Invitation::STATUS_PENDING !== (string) $invitation->get( 'status' ) ) {
			return false;
		}

		$invitation->set( 'status', Invitation::STATUS_REVOKED );

		return InvitationRepository::save( $invitation ) > 0;
	}

	/**
	 * Redeem an invitation for a user.
	 *
	 * Every check is made here rather than at the screen that calls it, because there
	 * is more than one way in — an invited address that already has an account, and one
	 * that does not — and a check made in only one of them is a check that is not made.
	 *
	 * @param Invitation $invitation Invitation being redeemed.
	 * @param int        $user_id    User joining the organization.
	 * @return true|\WP_Error True on success.
	 */
	public static function accept( Invitation $invitation, $user_id ) {
		$user = get_user_by( 'id', absint( $user_id ) );

		if ( ! $user instanceof \WP_User ) {
			return new \WP_Error( 'woap_no_user', __( 'That account could not be found.', 'woo-organization-accounts-pro' ) );
		}

		if ( ! $invitation->is_acceptable() ) {
			return new \WP_Error( 'woap_invitation_unusable', self::rejection_message() );
		}

		if ( strtolower( $invitation->get_email() ) !== strtolower( $user->user_email ) ) {
			return new \WP_Error( 'woap_invitation_email_mismatch', self::rejection_message() );
		}

		if ( null !== MemberRepository::find_by_user( $user->ID ) ) {
			return new \WP_Error(
				'woap_already_member',
				sprintf(
					/* translators: %s: the organization noun for the site's mode, for example "Company". */
					__( 'This account already belongs to a %s.', 'woo-organization-accounts-pro' ),
					Labels::organization()
				)
			);
		}

		$member = new Member();
		$member->set_props(
			array(
				'organization_id' => $invitation->get_organization_id(),
				'user_id'         => $user->ID,
				'role'            => $invitation->get_role(),
				'status'          => Member::STATUS_ACTIVE,
			)
		);

		if ( 0 === MemberRepository::save( $member ) ) {
			return new \WP_Error( 'woap_join_failed', __( 'Joining failed. Please try again.', 'woo-organization-accounts-pro' ) );
		}

		$user->set_role( Roles::wordpress_role( $invitation->get_role() ) );

		$invitation->set( 'status', Invitation::STATUS_ACCEPTED );
		$invitation->set( 'date_accepted', current_time( 'mysql', true ) );
		InvitationRepository::save( $invitation );

		/**
		 * Fires after a user has joined an organization through an invitation.
		 *
		 * @since 0.1.0
		 *
		 * @param Member     $member     The new membership.
		 * @param Invitation $invitation The invitation that was redeemed.
		 */
		do_action( 'woo_org_accounts_invitation_accepted', $member, $invitation );

		return true;
	}

	/**
	 * The link an invitation email carries.
	 *
	 * It points at the registration page, whose shortcode shows the join form instead
	 * of the registration form when it sees a token. That keeps the whole invitation
	 * flow on a page the site owner can see and style, and needs no rewrite rule.
	 *
	 * @param string $token Raw one-time token.
	 * @return string URL.
	 */
	public static function accept_url( $token ) {
		$page_id = absint( Settings::get( 'registration_page_id', 0 ) );
		$base    = $page_id > 0 ? get_permalink( $page_id ) : '';

		if ( ! $base ) {
			$base = wc_get_page_permalink( 'myaccount' );
		}

		return add_query_arg( self::QUERY_VAR, rawurlencode( $token ), $base );
	}

	/**
	 * The one message every unusable invitation is refused with.
	 *
	 * Deliberately the same for a token that does not exist, one that expired, one that
	 * was revoked, one already used and one sent to a different address. Telling them
	 * apart would turn the link into a way of asking the site questions about
	 * invitations the visitor was never sent.
	 *
	 * @return string Translated message.
	 */
	public static function rejection_message() {
		return __( 'This invitation link is not valid. It may have expired, already been used, or been withdrawn. Ask for a new one.', 'woo-organization-accounts-pro' );
	}

	/**
	 * When a newly issued invitation should expire.
	 *
	 * @return string|null MySQL datetime in UTC, or null when invitations do not expire.
	 */
	private static function expiry() {
		$lifetime = Settings::invitation_lifetime();

		if ( $lifetime <= 0 ) {
			return null;
		}

		return gmdate( 'Y-m-d H:i:s', time() + $lifetime );
	}
}
