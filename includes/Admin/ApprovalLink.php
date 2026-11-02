<?php
/**
 * One-click approval from the new-registration email.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Admin;

use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Data\OrganizationRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Lets the shop approve a registration straight from the email that announced it.
 *
 * The email cannot carry an ordinary approve link. `Organizations::status_url()` is a
 * nonce URL, and a nonce belongs to the user it was made for and lapses within a day:
 * one minted while a visitor registers belongs to nobody who could approve, so the
 * link would fail for everyone who received it.
 *
 * What the email carries instead is a key: an HMAC of the organization ID under the
 * site's auth salt. It proves the link came from this site, about this one
 * registration, and nothing in the database or the URL lets anyone make one for
 * another. The key is only half of it, though, and deliberately so:
 *
 * - **The link lands in wp-admin**, so whoever follows it has to be signed in, and
 *   signed in as somebody holding `Approvals::CAPABILITY`. Mail scanners and link
 *   previews that fetch every URL in a message carry no session; they reach the login
 *   screen and approve nothing.
 * - **It only ever approves a pending registration.** Once the shop has decided — by
 *   this link, the Approvals screen or anything else — the same link only reports that
 *   the decision has been made, so a second click, or a forwarded copy, changes nothing.
 *
 * Approving goes through `OrganizationRepository::set_status()`, the same call the
 * Approvals screen makes, so the customer's "approved" email and every other listener
 * behave exactly as if the button on that screen had been pressed.
 */
class ApprovalLink {

	/**
	 * Query argument carrying the organization ID.
	 */
	const ID_ARG = 'woap_approve';

	/**
	 * Query argument carrying the key.
	 */
	const KEY_ARG = 'woap_key';

	/**
	 * Query argument the outcome is reported back in.
	 */
	const RESULT_ARG = 'woap_approval';

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'handle' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
	}

	/**
	 * The one-click approval URL for an organization.
	 *
	 * @param Organization $organization The registration.
	 * @return string URL.
	 */
	public static function url( Organization $organization ) {
		return add_query_arg(
			array(
				self::ID_ARG  => $organization->get_id(),
				self::KEY_ARG => self::key( $organization->get_id() ),
			),
			Approvals::url()
		);
	}

	/**
	 * The key for an organization.
	 *
	 * @param int $organization_id Organization ID.
	 * @return string Hex HMAC.
	 */
	public static function key( $organization_id ) {
		return hash_hmac( 'sha256', 'woap-approve|' . absint( $organization_id ), wp_salt( 'auth' ) );
	}

	/**
	 * Approve when an approval link is followed.
	 *
	 * @return void
	 */
	public function handle() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The HMAC key stands in for a nonce, which an email cannot carry; it is verified below together with the capability.
		if ( ! isset( $_GET[ self::ID_ARG ], $_GET[ self::KEY_ARG ], $_GET['page'] ) || Approvals::PAGE_SLUG !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}

		$organization_id = absint( wp_unslash( $_GET[ self::ID_ARG ] ) );
		$key             = sanitize_text_field( wp_unslash( $_GET[ self::KEY_ARG ] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		wp_safe_redirect( add_query_arg( self::RESULT_ARG, $this->approve( $organization_id, $key ), Approvals::url() ) );
		exit;
	}

	/**
	 * Check the link and approve the registration it names.
	 *
	 * @param int    $organization_id Organization ID from the link.
	 * @param string $key             Key from the link.
	 * @return string What happened: approved, decided, invalid or denied.
	 */
	public function approve( $organization_id, $key ) {
		if ( ! current_user_can( Approvals::CAPABILITY ) ) {
			return 'denied';
		}

		if ( $organization_id <= 0 || ! hash_equals( self::key( $organization_id ), (string) $key ) ) {
			return 'invalid';
		}

		$organization = OrganizationRepository::find( $organization_id );

		if ( null === $organization ) {
			return 'invalid';
		}

		if ( Organization::STATUS_PENDING !== $organization->get_status() ) {
			return 'decided';
		}

		return OrganizationRepository::set_status( $organization_id, Organization::STATUS_ACTIVE ) ? 'approved' : 'invalid';
	}

	/**
	 * Report the outcome on the Approvals screen.
	 *
	 * @return void
	 */
	public function render_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only: it chooses which of four fixed messages to show.
		$result = isset( $_GET[ self::RESULT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::RESULT_ARG ] ) ) : '';

		$messages = array(
			'approved' => array( 'success', __( 'The registration has been approved. The customer has been emailed and can order now.', 'woo-organization-accounts-pro' ) ),
			'decided'  => array( 'info', __( 'This registration has already been decided, so the link changed nothing.', 'woo-organization-accounts-pro' ) ),
			'invalid'  => array( 'error', __( 'That approval link is not valid. Review the registration below instead.', 'woo-organization-accounts-pro' ) ),
			'denied'   => array( 'error', __( 'Your account cannot approve registrations.', 'woo-organization-accounts-pro' ) ),
		);

		if ( ! isset( $messages[ $result ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $messages[ $result ][0] ),
			esc_html( $messages[ $result ][1] )
		);
	}
}
