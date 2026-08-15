<?php
/**
 * Withdrawing and re-sending invitations, from wp-admin.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Admin;

use WooOrgAccounts\Data\Invitation;
use WooOrgAccounts\Data\InvitationRepository;
use WooOrgAccounts\Members\Invitations;

defined( 'ABSPATH' ) || exit;

/**
 * The two things that can be done to an invitation that is already out.
 *
 * Issuing one lives on the add-somebody form, because inviting and creating an account are
 * the two answers to one question and belong on one screen. What is left is withdrawing and
 * re-sending, which are row actions.
 *
 * **A re-send is a replacement, not a second key to the same door.** `Invitations::create()`
 * called again for an address that already has a pending invitation replaces the token on
 * the existing row rather than adding a second one, so the link in the older email stops
 * working the moment the new one is sent. That is the whole reason this calls the service
 * rather than re-sending the stored row: the row holds only a SHA-256 of the token, so there
 * is nothing here that *could* re-send the original.
 */
class InvitationScreen {

	/**
	 * Capability required to use it.
	 */
	const CAPABILITY = 'manage_woocommerce';

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_post_woap_admin_invitation_resend', array( $this, 'handle_resend' ) );
		add_action( 'admin_post_woap_admin_invitation_revoke', array( $this, 'handle_revoke' ) );
	}

	/**
	 * A nonced URL that sends an invitation again.
	 *
	 * @param int $invitation_id Invitation ID.
	 * @return string URL.
	 */
	public static function resend_url( $invitation_id ) {
		return self::action_url( 'resend', $invitation_id );
	}

	/**
	 * A nonced URL that withdraws an invitation.
	 *
	 * @param int $invitation_id Invitation ID.
	 * @return string URL.
	 */
	public static function revoke_url( $invitation_id ) {
		return self::action_url( 'revoke', $invitation_id );
	}

	/**
	 * Build one of the two nonced URLs.
	 *
	 * @param string $action        resend or revoke.
	 * @param int    $invitation_id Invitation ID.
	 * @return string URL.
	 */
	private static function action_url( $action, $invitation_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'        => 'woap_admin_invitation_' . $action,
					'invitation_id' => absint( $invitation_id ),
				),
				admin_url( 'admin-post.php' )
			),
			'woap_admin_invitation_' . $action . '_' . absint( $invitation_id )
		);
	}

	/**
	 * Issue a fresh token and send it again.
	 *
	 * @return void
	 */
	public function handle_resend() {
		$invitation = $this->authorise( 'resend' );

		if ( Invitation::STATUS_PENDING !== (string) $invitation->get( 'status' ) ) {
			Notices::error( __( 'That invitation could not be sent again.', 'woo-organization-accounts-pro' ) );
			$this->go_back( $invitation );
		}

		$result = Invitations::create(
			$invitation->get_organization_id(),
			$invitation->get_email(),
			$invitation->get_role(),
			get_current_user_id()
		);

		if ( is_wp_error( $result ) ) {
			Notices::error( $result->get_error_message() );
			$this->go_back( $invitation );
		}

		Notices::success( __( 'Invitation sent again. The previous link no longer works.', 'woo-organization-accounts-pro' ) );
		$this->go_back( $invitation );
	}

	/**
	 * Withdraw an invitation.
	 *
	 * @return void
	 */
	public function handle_revoke() {
		$invitation = $this->authorise( 'revoke' );

		if ( ! Invitations::revoke( $invitation ) ) {
			Notices::error( __( 'That invitation could not be withdrawn.', 'woo-organization-accounts-pro' ) );
			$this->go_back( $invitation );
		}

		Notices::success( __( 'Invitation withdrawn.', 'woo-organization-accounts-pro' ) );
		$this->go_back( $invitation );
	}

	/**
	 * Check the nonce and the capability, and find the invitation.
	 *
	 * @param string $action resend or revoke.
	 * @return Invitation The invitation. Does not return when there is none.
	 */
	private function authorise( $action ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified by check_admin_referer() below, which needs the ID to build the action.
		$invitation_id = isset( $_GET['invitation_id'] ) ? absint( wp_unslash( $_GET['invitation_id'] ) ) : 0;

		check_admin_referer( 'woap_admin_invitation_' . $action . '_' . $invitation_id );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You are not allowed to do that.', 'woo-organization-accounts-pro' ),
				'',
				array( 'response' => 403 )
			);
		}

		$invitation = InvitationRepository::find( $invitation_id );

		if ( ! $invitation instanceof Invitation ) {
			Notices::error( __( 'That record no longer exists.', 'woo-organization-accounts-pro' ) );

			wp_safe_redirect( Organizations::list_url() );
			exit;
		}

		return $invitation;
	}

	/**
	 * Back to the invitations tab of the account it belongs to.
	 *
	 * @param Invitation $invitation The invitation.
	 * @return void
	 */
	private function go_back( Invitation $invitation ) {
		wp_safe_redirect( Organizations::tab_url( $invitation->get_organization_id(), 'invitations' ) );
		exit;
	}
}
